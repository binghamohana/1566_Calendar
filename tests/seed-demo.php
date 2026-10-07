<?php
/**
 * Fill a DEVELOPMENT database with realistic sample reservations for the next two weeks.
 *   php tests/seed-demo.php
 * Refuses to run unless config 'debug' is true, so it can't touch production by accident.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\Bookings;
use GPC\Db;
use GPC\Time;

if (!GPC\App::config('debug')) {
    exit("Refusing: only runs when 'debug' => true in config.\n");
}
Db::run("DELETE FROM bookings WHERE source = 'admin' AND notes = 'demo'");
$admin = Db::one('SELECT * FROM admins ORDER BY id LIMIT 1');
$people = [
    ['Sam Rivera', 'sam@rivera-design.com', 'Rivera Design'], ['Priya Shah', 'priya@northpoint.co', 'Northpoint'],
    ['Marcus Bell', 'marcus@bellandco.com', 'Bell & Co.'], ['Jenna Ortiz', 'jenna@ortizlaw.com', 'Ortiz Law'],
    ['Daniel Kim', 'dkim@kimstudio.com', 'Kim Studio'], ['Alex Turner', 'alex@groveventures.com', 'Grove Ventures'],
];
$titles = ['Client meeting', 'Team standup', 'Quarterly planning', 'Interview', 'Strategy session', 'Workshop', 'Board prep', null, null];
$today = Time::today();
mt_srand(7);
$made = 0;
for ($d = 0; $d < 14; $d++) {
    $date = Time::addDays($today, $d);
    $dow = Time::weekday($date);
    foreach ([1, 2, 3] as $space) {
        $n = ($dow === 0 || $dow === 6) ? mt_rand(0, 1) : mt_rand(1, 4);
        $cursor = 8 * 60 + mt_rand(0, 4) * 30;
        for ($i = 0; $i < $n; $i++) {
            $len = [30, 60, 60, 90, 120][mt_rand(0, 4)];
            if ($space === 3) $len = [60, 120, 180][mt_rand(0, 2)];
            if ($cursor + $len > 20 * 60) break;
            $p = $people[mt_rand(0, count($people) - 1)];
            try {
                Bookings::adminCreate([
                    'kind' => 'reservation', 'space_id' => $space, 'date' => $date,
                    'start' => Time::fromMinutes($cursor), 'end' => Time::fromMinutes($cursor + $len),
                    'name' => $p[0], 'email' => $p[1], 'company' => $p[2], 'title' => $titles[mt_rand(0, count($titles) - 1)],
                    'notify' => false, 'notes' => 'demo',
                ], $admin);
                $made++;
            } catch (GPC\AppError $e) {
            }
            $cursor += $len + mt_rand(1, 5) * 30;
        }
    }
}
// One building-wide block for flavor
try {
    Bookings::adminCreate(['kind' => 'block', 'space_ids' => [3], 'date' => Time::addDays($today, 2), 'start' => '17:00', 'end' => '21:00', 'title' => 'Community happy hour', 'notes' => 'demo'], $admin);
} catch (GPC\AppError $e) {
}
Db::run("UPDATE bookings SET source = 'admin', notes = 'demo' WHERE notes = 'demo' OR notes IS NULL AND kind = 'block'");
echo "Created $made demo reservations.\n";
