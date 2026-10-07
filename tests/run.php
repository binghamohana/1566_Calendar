<?php
/**
 * Integration tests against a real MySQL/MariaDB database.
 *
 *   GPC_DB_NAME=gpc_test php tests/run.php
 *
 * WARNING: empties every table in the configured database. Never point it at production.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\App;
use GPC\AppError;
use GPC\Bookings;
use GPC\Db;
use GPC\FormToken;
use GPC\Ics;
use GPC\Jobs;
use GPC\Mailer;
use GPC\Settings;
use GPC\Spaces;
use GPC\Time;

if (!str_contains((string) App::config('db.name'), 'test')) {
    exit("Refusing to run: database name must contain 'test' (set GPC_DB_NAME=gpc_test).\n");
}
App::setConfig('mail.transport', 'log');

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m $name\n";
    } else {
        $failed++;
        echo "  \033[31m✗ $name\033[0m" . ($detail ? " — $detail" : '') . "\n";
    }
}
function expectError(callable $fn, ?string $code = null, ?string $field = null): ?AppError
{
    try {
        $fn();
    } catch (AppError $e) {
        if (($code === null || $e->errorCode === $code) && ($field === null || $e->field === $field)) {
            return $e;
        }
        echo "      (got error code={$e->errorCode} field={$e->field}: {$e->getMessage()})\n";
        return null;
    }
    return null;
}
function freeze(string $utc): void
{
    Time::$frozenNow = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
}
function reset_db(): void
{
    $pdo = Db::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['bookings', 'emails', 'settings', 'audit_log', 'rate_events', 'admins', 'spaces'] as $t) {
        $pdo->exec("TRUNCATE TABLE $t");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    Settings::flush();
    $now = '2026-10-01 00:00:00';
    foreach ([['Upstairs Conference Room', 'upstairs'], ['Downstairs Conference Room', 'downstairs'], ['Upstairs Lounge + Kitchenette', 'lounge']] as $i => [$name, $slug]) {
        Db::insert('spaces', ['slug' => $slug, 'name' => $name, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
    }
    Db::insert('admins', ['email' => 'boss@example.com', 'name' => 'Boss', 'password_hash' => 'x', 'created_at' => $now]);
}
function book(array $over = [], string $ip = '10.0.0.1'): array
{
    return Bookings::createPublic($over + [
        'space_id' => 1, 'date' => '2026-10-13', 'start' => '13:00', 'end' => '14:30',
        'name' => 'Sam Rivera', 'email' => 'sam@acme.com', 'title' => 'Client review', 'company' => 'Acme',
    ], $ip)[0];
}

// Schema
$schema = file_get_contents(GPC_ROOT . '/sql/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $schema)))) as $sql) {
    Db::pdo()->exec($sql);
}
$admin = ['id' => 1, 'email' => 'boss@example.com'];

// ---------------------------------------------------------------------------
echo "\nCreating reservations\n";
reset_db();
freeze('2026-10-07 16:00:00'); // Wed Oct 7, 12:00 PM in Atlanta

$a = book();
check('creates a reservation', $a['status'] === 'confirmed' && $a['start'] === '13:00' && $a['end'] === '14:30');
check('stores UTC (1:00 PM EDT = 17:00 UTC)', $a['start_utc'] === '2026-10-13 17:00:00');
check('issues a 48-char manage token and a 6-char reference', strlen($a['manage_token']) === 48 && strlen($a['ref']) === 6);

check('rejects an overlapping booking in the same space', expectError(fn () => book(['start' => '14:00', 'end' => '15:00']), 'conflict') !== null);
check('rejects a booking fully inside another', expectError(fn () => book(['start' => '13:30', 'end' => '14:00']), 'conflict') !== null);
check('rejects a booking that wraps another', expectError(fn () => book(['start' => '12:00', 'end' => '16:00']), 'conflict') !== null);
$adj = book(['start' => '14:30', 'end' => '15:00']);
check('allows back-to-back bookings (2:30 end = 2:30 start)', $adj['start'] === '14:30');
$other = book(['space_id' => 2]);
check('allows the same time in a different space', $other['space_id'] === 2);

check('rejects end before start', expectError(fn () => book(['start' => '16:00', 'end' => '15:00']), null, 'end') !== null);
check('rejects an invalid email', expectError(fn () => book(['date' => '2026-10-14', 'email' => 'sam@acme']), null, 'email') !== null);
check('rejects a missing name', expectError(fn () => book(['date' => '2026-10-14', 'name' => '  ']), null, 'name') !== null);
check('rejects a time in the past', expectError(fn () => book(['date' => '2026-10-07', 'start' => '09:00', 'end' => '10:00']), null, 'start') !== null);
$walkUp = book(['date' => '2026-10-07', 'start' => '11:45', 'end' => '13:00', 'space_id' => 3]);
check('allows a walk-up booking that started a few minutes ago', $walkUp['start'] === '11:45');
check('rejects bookings beyond the booking window', expectError(fn () => book(['date' => '2027-06-01']), null, 'date') !== null);
check('rejects bookings longer than the maximum', expectError(fn () => book(['date' => '2026-10-15', 'start' => '06:00', 'end' => '19:00']), null, 'end') !== null);
check('rejects times off the 15-minute grid', expectError(fn () => book(['date' => '2026-10-15', 'start' => '10:05', 'end' => '11:00']), null, 'start') !== null);

// Idempotency
$rid = 'test-request-0001-abcdef';
[$first, $dup1] = Bookings::createPublic(['space_id' => 1, 'date' => '2026-10-16', 'start' => '09:00', 'end' => '10:00', 'name' => 'Pat', 'email' => 'pat@x.com', 'request_id' => $rid], '10.0.0.2');
[$second, $dup2] = Bookings::createPublic(['space_id' => 1, 'date' => '2026-10-16', 'start' => '09:00', 'end' => '10:00', 'name' => 'Pat', 'email' => 'pat@x.com', 'request_id' => $rid], '10.0.0.2');
check('double-submitting the same form returns the original booking', !$dup1 && $dup2 && $first['id'] === $second['id']);
check('…and does not create a second row', (int) Db::value('SELECT COUNT(*) FROM bookings WHERE request_id = ?', [$rid]) === 1);

// ---------------------------------------------------------------------------
echo "\nChanging and cancelling\n";
check('rejects moving a booking onto an occupied time', expectError(fn () => Bookings::updateByOwner($adj, ['space_id' => 1, 'date' => '2026-10-13', 'start' => '14:00', 'end' => '15:00', 'name' => 'Sam']), 'conflict') !== null);
[$moved, $didMove] = Bookings::updateByOwner($adj, ['space_id' => 1, 'date' => '2026-10-13', 'start' => '15:00', 'end' => '16:00', 'name' => 'Sam Rivera', 'title' => 'Moved']);
check('moves a booking to a free time', $didMove && $moved['start'] === '15:00' && $moved['title'] === 'Moved');
[$same, $didMove2] = Bookings::updateByOwner($moved, ['space_id' => 1, 'date' => '2026-10-13', 'start' => '15:00', 'end' => '16:00', 'name' => 'Sam Rivera', 'title' => 'Renamed']);
check('editing details without moving does not conflict with itself', !$didMove2 && $same['title'] === 'Renamed');
check('owner cannot change the email on a booking', $same['email'] === 'sam@acme.com');
$cancelled = Bookings::cancelByOwner($a);
check('cancels a booking', $cancelled['status'] === 'cancelled');
$rebook = book(['start' => '13:00', 'end' => '14:00', 'email' => 'jo@acme.com', 'name' => 'Jo'], '10.0.0.3');
check('cancelled time is immediately bookable again', $rebook['status'] === 'confirmed');
check('cannot cancel twice', expectError(fn () => Bookings::cancelByOwner(Bookings::find($a['id']))) !== null);
check('cannot modify a cancelled booking', expectError(fn () => Bookings::updateByOwner(Bookings::find($a['id']), ['space_id' => 1, 'date' => '2026-10-20', 'start' => '09:00', 'end' => '10:00', 'name' => 'Sam'])) !== null);

// ---------------------------------------------------------------------------
echo "\nAvailability rules\n";
Db::update('spaces', ['hours' => json_encode(['0' => null, '1' => ['09:00', '17:00'], '2' => ['09:00', '17:00'], '3' => ['09:00', '17:00'], '4' => ['09:00', '17:00'], '5' => ['09:00', '17:00'], '6' => ['10:00', '14:00']])], 'id = 2', []);
check('rejects a booking on a closed day (Sunday)', expectError(fn () => book(['space_id' => 2, 'date' => '2026-10-18', 'start' => '10:00', 'end' => '11:00'], '10.0.1.1'), null, 'date') !== null);
check('rejects a booking outside opening hours', expectError(fn () => book(['space_id' => 2, 'date' => '2026-10-19', 'start' => '16:30', 'end' => '17:30'], '10.0.1.1'), null, 'start') !== null);
check('accepts a booking inside opening hours', book(['space_id' => 2, 'date' => '2026-10-19', 'start' => '16:00', 'end' => '17:00'], '10.0.1.1')['status'] === 'confirmed');
Db::update('spaces', ['is_active' => 0], 'id = 3', []);
check('rejects bookings for a disabled space', expectError(fn () => book(['space_id' => 3, 'date' => '2026-10-20'], '10.0.1.1'), null, 'space_id') !== null);
Db::update('spaces', ['is_active' => 1], 'id = 3', []);

Settings::set('allowed_email_domains', 'acme.com, tenant.org');
check('email domain allow-list blocks other domains', expectError(fn () => book(['date' => '2026-10-21', 'email' => 'x@gmail.com'], '10.0.1.2'), null, 'email') !== null);
check('email domain allow-list accepts tenant domains', book(['date' => '2026-10-21', 'email' => 'x@tenant.org'], '10.0.1.2')['status'] === 'confirmed');
Settings::set('allowed_email_domains', '');

Settings::set('rate_limit_per_hour', 2);
book(['date' => '2026-10-22', 'start' => '08:00', 'end' => '09:00'], '10.9.9.9');
book(['date' => '2026-10-22', 'start' => '09:00', 'end' => '10:00'], '10.9.9.9');
check('rate limit stops a flood from one IP address', expectError(fn () => book(['date' => '2026-10-22', 'start' => '10:00', 'end' => '11:00'], '10.9.9.9'), 'rate_limited') !== null);
Settings::set('rate_limit_per_hour', 12);

Settings::set('max_upcoming_per_email', 2);
check('per-person limit on upcoming reservations', expectError(fn () => book(['date' => '2026-10-23', 'email' => 'sam@acme.com'], '10.0.1.3'), null, 'email') !== null);
Settings::set('max_upcoming_per_email', 25);

// ---------------------------------------------------------------------------
echo "\nPrivacy\n";
$pub = Bookings::publicView($rebook);
check('public view never includes email, notes or token', !array_key_exists('email', $pub) && !array_key_exists('notes', $pub) && !array_key_exists('manage_token', $pub));
check('default label shows first name only', $pub['label'] === 'Reserved — Jo');
Settings::set('public_show_name', 'none');
check('"none" shows just Reserved', Bookings::publicLabel($rebook) === 'Reserved');
Settings::set('public_show_name', 'full');
Settings::set('public_show_company', true);
Settings::set('public_show_title', true);
check('full name + company + title when enabled', Bookings::publicLabel(book(['date' => '2026-10-26', 'name' => 'Ana Li', 'title' => 'Standup'], '10.0.2.1')) === 'Standup — Ana Li (Acme)');
check('a private booking always shows just Reserved', Bookings::publicLabel(book(['date' => '2026-10-27', 'is_private' => true], '10.0.2.1')) === 'Reserved');
Settings::set('public_show_name', 'first');
Settings::set('public_show_company', false);
Settings::set('public_show_title', false);

// ---------------------------------------------------------------------------
echo "\nAdmin tools\n";
$err = expectError(fn () => Bookings::adminCreate([
    'kind' => 'reservation', 'space_id' => 1, 'date' => '2026-10-13', 'start' => '13:00', 'end' => '14:00',
    'repeat' => 'weekly', 'repeat_until' => '2026-11-10', 'name' => 'Weekly Team', 'email' => 'team@acme.com',
], $admin), 'conflict');
// Oct 13 and Oct 27 at 1 PM are already taken; Oct 20, Nov 3 and Nov 10 are free.
check('recurring booking reports conflicts instead of double booking', $err !== null && count($err->extra['conflicts']) === 2 && $err->extra['creatable'] === 3);
$series = Bookings::adminCreate([
    'kind' => 'reservation', 'space_id' => 1, 'date' => '2026-10-13', 'start' => '13:00', 'end' => '14:00',
    'repeat' => 'weekly', 'repeat_until' => '2026-11-10', 'name' => 'Weekly Team', 'email' => 'team@acme.com', 'skip_conflicts' => true,
], $admin);
check('…and can skip the conflicting dates', count($series['bookings']) === 3 && count($series['skipped']) === 2 && $series['series_id']);
$cancelledSeries = Bookings::adminCancel($series['bookings'][1]['id'], 'following', 'Room renovation', $admin);
check('cancel "this and following" in a series', count($cancelledSeries) === 2 && Bookings::find($series['bookings'][0]['id'])['status'] === 'confirmed');

$block = Bookings::adminCreate(['kind' => 'block', 'space_ids' => [1, 2, 3], 'date' => '2026-11-26', 'all_day' => true, 'title' => 'Thanksgiving'], $admin);
check('block a whole day across every space', count($block['bookings']) === 3 && $block['bookings'][0]['end'] === '24:00');
check('public bookings are refused on a blocked day', expectError(fn () => book(['date' => '2026-11-26', 'space_id' => 3], '10.0.3.1'), 'conflict') !== null);
check('blocks show as "Unavailable" publicly', Bookings::publicView($block['bookings'][0])['label'] === 'Unavailable · Thanksgiving');
$adminOverride = Bookings::adminCreate(['kind' => 'reservation', 'space_id' => 2, 'date' => '2026-10-18', 'start' => '07:00', 'end' => '23:00', 'name' => 'Big Event'], $admin);
check('admins can book outside public rules (closed Sunday, 16 hours)', count($adminOverride['bookings']) === 1);
check('admin edits still cannot double book', expectError(fn () => Bookings::adminUpdate($adminOverride['bookings'][0]['id'], ['space_id' => 1, 'date' => '2026-10-13', 'start' => '13:00', 'end' => '14:00', 'name' => 'Big Event'], $admin), 'conflict') !== null);
$deleted = Bookings::adminDelete($block['bookings'][0]['id'], 'one', $admin);
check('admin can delete a booking outright', $deleted === 1 && Bookings::find($block['bookings'][0]['id']) === null);
$monthly = Bookings::adminCreate(['kind' => 'block', 'space_ids' => [3], 'date' => '2026-10-31', 'start' => '09:00', 'end' => '10:00', 'repeat' => 'monthly', 'repeat_until' => '2027-03-31'], $admin);
check('monthly repeats skip months without that date (31st)', array_column($monthly['bookings'], 'date') === ['2026-10-31', '2026-12-31', '2027-01-31', '2027-03-31']);

// ---------------------------------------------------------------------------
echo "\nAutomated emails\n";
reset_db();
freeze('2026-10-07 16:00:00');
$r1 = book(['date' => '2026-10-08', 'start' => '14:00', 'end' => '15:00'], '10.1.0.1'); // booked a day ahead
freeze('2026-10-08 17:05:00'); // 1:05 PM local, 55 min before
$r2 = book(['date' => '2026-10-08', 'start' => '14:00', 'end' => '15:00', 'space_id' => 2], '10.1.0.1'); // booked 55 min before
$res = Jobs::run();
check('reminder queued about an hour before', $res['reminders'] === 1);
check('no reminder for a booking made minutes before it starts', Db::value("SELECT COUNT(*) FROM emails WHERE type = 'reminder' AND booking_id = ?", [$r2['id']]) == 0);
check('reminder text matches the brief', str_contains((string) Db::value("SELECT body_text FROM emails WHERE type = 'reminder'"), 'Reminder: You have the Upstairs Conference Room reserved today from 2:00 PM–3:00 PM.'));
check('reminders are only sent once', Jobs::run()['reminders'] === 0);
check('queued emails were delivered (log transport)', (int) Db::value("SELECT COUNT(*) FROM emails WHERE status = 'sent'") === 1);
freeze('2026-10-08 18:50:00'); // 2:50 PM, before the end
check('no after-use email before the end', Jobs::run()['followups'] === 0);
freeze('2026-10-08 19:02:00'); // 3:02 PM, just after the end
check('after-use email sent once the reservation ends', Jobs::run()['followups'] === 2 && Jobs::run()['followups'] === 0);
check('after-use email includes the cleanup message', str_contains((string) Db::value("SELECT body_text FROM emails WHERE type = 'followup' LIMIT 1"), 'wipe down/erase the dry-erase boards'));
freeze('2026-10-08 23:00:00');
$c = book(['date' => '2026-10-09', 'start' => '09:00', 'end' => '10:00', 'space_id' => 3], '10.1.0.2');
Bookings::cancelByOwner($c);
freeze('2026-10-09 12:30:00');
$res = Jobs::run();
check('no reminder or after-use email for cancelled bookings', $res['reminders'] === 0 && $res['followups'] === 0);

// Failure + retry
App::setConfig('mail.transport', 'smtp');
App::setConfig('mail.host', '127.0.0.1');
App::setConfig('mail.port', 1); // nothing listens here
$failId = GPC\Notify::test('someone@example.com');
Mailer::sendNow([$failId]);
$row = Db::one('SELECT * FROM emails WHERE id = ?', [$failId]);
check('a failed send stays queued for retry with the error recorded', $row['status'] === 'pending' && (int) $row['attempts'] === 1 && $row['last_error'] !== null);
App::setConfig('mail.transport', 'log');
Mailer::retry($failId);
check('retry delivers once email works again', Db::value('SELECT status FROM emails WHERE id = ?', [$failId]) === 'sent');

// ---------------------------------------------------------------------------
echo "\nCalendar files, time zones, form protection\n";
$ics = Ics::forBooking($r1, Spaces::find(1));
check('.ics has an event in UTC', str_contains($ics, 'BEGIN:VEVENT') && str_contains($ics, 'DTSTART:20261008T180000Z'));
check('.ics lines are folded at 75 octets', max(array_map('strlen', explode("\r\n", $ics))) <= 75);
check('fall-back DST day: 9 AM EST = 14:00 UTC', Time::localToUtc('2026-11-01', 540) === '2026-11-01 14:00:00');
check('spring-forward DST day: 9 AM EDT = 13:00 UTC', Time::localToUtc('2027-03-14', 540) === '2027-03-14 13:00:00');
check('midnight end is shown as 24:00 on the same day', Time::localParts('2026-10-13 04:00:00', '2026-10-14 04:00:00')['end'] === '24:00');
Time::$frozenNow = null;
check('form token: honeypot catches bots', expectError(fn () => FormToken::check(['website' => 'http://spam', 'form_token' => FormToken::issue()]), 'spam') !== null);
check('form token: instant submissions are refused', expectError(fn () => FormToken::check(['form_token' => FormToken::issue()]), 'too_fast') !== null);
check('form token: forged tokens are refused', expectError(fn () => FormToken::check(['form_token' => (time() - 60) . '.forged']), 'stale_form') !== null);
$old = (time() - 30) . '.' . GPC\Util::sign('form|' . (time() - 30));
check('form token: a normal submission passes', (function () use ($old) { FormToken::check(['form_token' => $old]); return true; })());

// ---------------------------------------------------------------------------
echo "\nConcurrency: 12 people grab the same slot at the same instant\n";
reset_db();
freeze('2026-10-07 16:00:00');
$racers = 12;
$go = microtime(true) + 1.0;
$pids = [];
for ($i = 0; $i < $racers; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        Db::reset(); // each process needs its own connection
        Settings::flush();
        time_sleep_until($go);
        try {
            Bookings::createPublic([
                'space_id' => 1, 'date' => '2026-10-13', 'start' => $i % 2 ? '10:00' : '10:30', 'end' => '11:30',
                'name' => "Racer $i", 'email' => "racer$i@example.com",
            ], "10.5.0.$i");
            exit(0);
        } catch (AppError $e) {
            exit($e->errorCode === 'conflict' ? 3 : 4);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(5);
        }
    }
    $pids[] = $pid;
}
$codes = [];
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    $codes[] = pcntl_wexitstatus($status);
}
Db::reset();
$counts = array_count_values($codes);
check('exactly one succeeded, the rest were told it was taken', ($counts[0] ?? 0) === 1 && ($counts[3] ?? 0) === $racers - 1, json_encode($counts));
check('database holds exactly one confirmed booking', (int) Db::value("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'") === 1);

echo "\n" . ($failed ? "\033[31m$failed failed\033[0m, " : '') . "$passed passed\n";
exit($failed ? 1 : 0);
