<?php
/**
 * Health check for the server. Run it as the web user so file permissions are tested correctly:
 *
 *   sudo -u www-data php bin/check.php
 *   sudo -u www-data php bin/check.php --send-test=you@example.com   (also sends a test email)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

$problems = 0;
$line = static function (bool $ok, string $text, string $fix = '') use (&$problems): void {
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $text . "\n";
    if (!$ok) {
        $problems++;
        if ($fix !== '') {
            echo "      → $fix\n";
        }
    }
};
$warn = static function (string $text): void {
    echo "  \033[33m!\033[0m $text\n";
};

echo "\nPHP\n";
$line(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION, 'PHP 8.1 or newer is required.');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'gd'] as $ext) {
    $line(extension_loaded($ext), "extension $ext", "sudo apt install php8.1-" . ($ext === 'pdo_mysql' ? 'mysql' : $ext));
}
if (extension_loaded('gd')) {
    $gd = gd_info();
    $line(!empty($gd['JPEG Support']), 'gd can read/write JPEG (space photos)');
}
if (!extension_loaded('exif')) {
    $warn('exif not loaded — phone photos may appear rotated (optional).');
}
if (PHP_VERSION_ID < 80100) {
    exit("\nFix PHP first.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\App;

echo "\nConfiguration\n";
$line(is_file(GPC_ROOT . '/config/config.php'), 'config/config.php exists', 'cp config/config.sample.php config/config.php and fill it in.');
$secret = (string) App::config('app_secret');
$line(strlen($secret) >= 32 && !str_starts_with($secret, 'CHANGE-ME'), 'app_secret is set', 'php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  and paste it into config.php');
$base = App::baseUrl();
$line($base !== '' && !str_contains($base, 'localhost'), "base_url is $base", 'Set base_url to the public address, e.g. https://www.58dwell.com/book');
if (!str_starts_with($base, 'https://')) {
    $warn('base_url is not https:// — links in emails will not be encrypted.');
}
if (App::config('debug')) {
    $warn("debug is on — turn it off in production ('debug' => false).");
}

echo "\nDatabase\n";
try {
    GPC\Db::pdo();
    $line(true, 'connected to MySQL database "' . App::config('db.name') . '"');
    $tables = array_column(GPC\Db::all('SHOW TABLES'), 'Tables_in_' . App::config('db.name'));
    $missing = array_diff(['spaces', 'bookings', 'emails', 'settings', 'admins', 'audit_log', 'rate_events'], $tables);
    $line(!$missing, 'tables present' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''), 'php bin/install.php');
    if (!$missing) {
        $line((int) GPC\Db::value('SELECT COUNT(*) FROM admins WHERE is_active = 1') > 0, 'at least one administrator', 'php bin/admin.php create you@example.com "Your Name"');
        $spaces = (int) GPC\Db::value('SELECT COUNT(*) FROM spaces WHERE is_active = 1');
        $line($spaces > 0, "$spaces bookable space(s)");
        $mins = GPC\Jobs::minutesSinceLastRun();
        $line($mins !== null && $mins <= 15, 'background tasks (cron) ' . ($mins === null ? 'have never run' : "last ran $mins min ago"), 'Add the cron job from docs/DEPLOYMENT.md (step 7).');
    }
} catch (Throwable $e) {
    $line(false, 'database connection: ' . $e->getMessage(), "Check the 'db' section of config/config.php.");
}

echo "\nFiles (running as " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user()) . ")\n";
foreach (['storage/logs', 'public/uploads/spaces'] as $dir) {
    $line(is_dir(GPC_ROOT . "/$dir") && is_writable(GPC_ROOT . "/$dir"), "$dir is writable", "sudo chown -R www-data:www-data " . GPC_ROOT . "/$dir");
}

echo "\nEmail (PHPMailer)\n";
$transport = (string) App::config('mail.transport');
if ($transport === 'log') {
    $warn("mail transport is 'log' — emails are written to storage/logs/mail.log, not sent.");
} else {
    $line($transport !== 'smtp' || (string) App::config('mail.host') !== '', "transport $transport" . ($transport === 'smtp' ? ' via ' . App::config('mail.host') . ':' . App::config('mail.port') : ''));
    $line(GPC\Util::isValidEmail((string) App::config('mail.from_email')), 'from address ' . App::config('mail.from_email'));
}
$opts = getopt('', ['send-test:']);
if (!empty($opts['send-test'])) {
    $id = GPC\Notify::test((string) $opts['send-test']);
    GPC\Mailer::sendNow([$id]);
    $row = GPC\Db::one('SELECT status, last_error FROM emails WHERE id = ?', [$id]);
    $line($row['status'] === 'sent', 'test email to ' . $opts['send-test'] . ': ' . $row['status'] . ($row['last_error'] ? ' — ' . $row['last_error'] : ''));
}

echo $problems ? "\n\033[31m$problems problem(s) found.\033[0m\n" : "\n\033[32mAll good.\033[0m\n";
exit($problems ? 1 : 0);
