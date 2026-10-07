<?php
/**
 * Administrator accounts from the command line (handy if everyone is locked out).
 *   php bin/admin.php list
 *   php bin/admin.php create  email@example.com "Full Name"
 *   php bin/admin.php password email@example.com
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\Auth;
use GPC\Db;

$cmd = $argv[1] ?? 'help';
$askPassword = static function (): string {
    echo 'New password (10+ characters): ';
    system('stty -echo 2>/dev/null');
    $p = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
    return $p;
};

try {
    switch ($cmd) {
        case 'list':
            foreach (Db::all('SELECT email, name, is_active, last_login_at FROM admins ORDER BY email') as $a) {
                printf("%-40s %-25s %s  last login: %s\n", $a['email'], $a['name'], $a['is_active'] ? 'active ' : 'disabled', $a['last_login_at'] ?? 'never');
            }
            break;
        case 'create':
            Auth::createAdmin($argv[2] ?? '', $argv[3] ?? '', $askPassword());
            echo "Created.\n";
            break;
        case 'password':
            $id = Db::value('SELECT id FROM admins WHERE email = ?', [strtolower($argv[2] ?? '')]);
            if (!$id) {
                exit("No administrator with that email.\n");
            }
            Auth::setPassword((int) $id, $askPassword());
            Db::run('UPDATE admins SET is_active = 1 WHERE id = ?', [$id]);
            GPC\RateLimit::clear('login:' . strtolower($argv[2]));
            echo "Password updated (other sessions signed out).\n";
            break;
        default:
            echo "Usage:\n  php bin/admin.php list\n  php bin/admin.php create email \"Name\"\n  php bin/admin.php password email\n";
    }
} catch (GPC\AppError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
