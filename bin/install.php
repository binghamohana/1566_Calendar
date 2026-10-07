<?php
/**
 * One-time setup (safe to re-run): creates tables, adds the three launch spaces,
 * and creates the first administrator.
 *
 *   php bin/install.php
 *   php bin/install.php --admin-email=you@example.com --admin-name="Your Name" --admin-password='…'   (non-interactive)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\Auth;
use GPC\Db;
use GPC\Settings;
use GPC\Time;
use GPC\Util;

$opts = getopt('', ['admin-email:', 'admin-name:', 'admin-password:', 'no-seed']);

echo "Grove Park Collective — Room Reservations setup\n\n";

// 1. Database tables
try {
    Db::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\nCheck the 'db' section of config/config.php.\n");
    exit(1);
}
$schema = file_get_contents(GPC_ROOT . '/sql/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $schema)))) as $statement) {
    Db::pdo()->exec($statement);
}
foreach (GPC\Migrations::run() as $step) {
    echo "✓ Updated: $step\n";
}
echo "✓ Database tables are ready\n";

// 2. Launch spaces (only when there are none yet)
if (!isset($opts['no-seed']) && (int) Db::value('SELECT COUNT(*) FROM spaces') === 0) {
    $now = Time::nowDb();
    $spaces = [
        [
            'slug' => 'upstairs-conference-room', 'name' => 'Upstairs Conference Room', 'short_name' => 'Upstairs Conf.',
            'location' => 'Upstairs', 'capacity' => 10, 'color' => '#3E5C4A',
            'amenities' => 'Display screen, Whiteboard, Wi-Fi',
            'description' => 'Our shared conference room upstairs — a calm, private spot for meetings, client calls and focused team sessions.',
        ],
        [
            'slug' => 'downstairs-conference-room', 'name' => 'Downstairs Conference Room', 'short_name' => 'Downstairs Conf.',
            'location' => 'Downstairs', 'capacity' => 8, 'color' => '#3F5873',
            'amenities' => 'Display screen, Whiteboard, Wi-Fi',
            'description' => 'The shared conference room on the main level — easy to reach for client meetings and quick get-togethers.',
        ],
        [
            'slug' => 'upstairs-lounge', 'name' => 'Upstairs Lounge + Kitchenette', 'short_name' => 'Lounge',
            'location' => 'Upstairs', 'capacity' => 25, 'color' => '#A35F3D',
            'amenities' => 'Kitchenette, Lounge seating, Wi-Fi',
            'description' => 'A relaxed lounge with kitchenette — great for team gatherings, celebrations, workshops and events.',
        ],
    ];
    foreach ($spaces as $i => $s) {
        Db::insert('spaces', $s + ['sort_order' => $i + 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }
    echo "✓ Added the three launch spaces\n";
}

// 3. Secrets / internal settings
if ((string) Settings::get('ics_feed_key') === '') {
    Settings::set('ics_feed_key', Util::token(16));
}

// 4. First administrator
if ((int) Db::value('SELECT COUNT(*) FROM admins') === 0) {
    $email = $opts['admin-email'] ?? null;
    $name = $opts['admin-name'] ?? null;
    $password = $opts['admin-password'] ?? null;
    if ($email === null) {
        echo "\nCreate the first administrator account.\n";
        $email = readline('Email: ');
        $name = readline('Name: ');
        echo 'Password (10+ characters): ';
        system('stty -echo 2>/dev/null');
        $password = trim((string) fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
    }
    try {
        Auth::createAdmin((string) $email, (string) ($name ?? ''), (string) $password);
        echo "✓ Administrator $email created\n";
    } catch (GPC\AppError $e) {
        fwrite(STDERR, '✗ ' . $e->getMessage() . "\n  Re-run php bin/install.php to try again.\n");
        exit(1);
    }
}

$secret = (string) GPC\App::config('app_secret');
if ($secret === '' || str_starts_with($secret, 'CHANGE-ME')) {
    echo "\n! Set 'app_secret' in config/config.php before going live:\n  php -r \"echo bin2hex(random_bytes(32)), PHP_EOL;\"\n";
}
echo "\nDone. Next: add the cron job (see docs/DEPLOYMENT.md) and open " . GPC\App::baseUrl() . "/admin/\n";
