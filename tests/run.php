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
    foreach (['bookings', 'emails', 'settings', 'audit_log', 'rate_events', 'admins', 'spaces', 'email_access'] as $t) {
        $pdo->exec("TRUNCATE TABLE $t");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    Settings::flush();
    $now = '2026-10-01 00:00:00';
    foreach ([['Upstairs Conference Room', 'upstairs'], ['Downstairs Conference Room', 'downstairs'], ['Upstairs Lounge + Kitchenette', 'lounge']] as $i => [$name, $slug]) {
        Db::insert('spaces', ['slug' => $slug, 'name' => $name, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
    }
    Db::insert('admins', ['email' => 'boss@example.com', 'name' => 'Boss', 'password_hash' => 'x', 'created_at' => $now]);
    Settings::set('require_approval', false); // the approval section turns it on
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
GPC\Migrations::run();
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
[$cancelled] = Bookings::cancelByOwner($a);
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
echo "\nApproval of new email addresses\n";
reset_db();
freeze('2026-10-07 16:00:00');
Settings::set('require_approval', true);
Settings::set('approval_emails', 'manager@gpc.example');
check('normalizes entries', GPC\EmailAccess::normalize(' Jane@Acme.com ') === 'jane@acme.com'
    && GPC\EmailAccess::normalize('ACME.com') === '@acme.com' && GPC\EmailAccess::normalize('@acme.com') === '@acme.com'
    && GPC\EmailAccess::normalize('not an email') === null);
$added = GPC\EmailAccess::add(['@acme.com', 'solo@freelance.com', 'nonsense'], 'boss');
check('adds addresses and domains, reports invalid ones', count($added['added']) === 2 && $added['invalid'] === ['nonsense']);
$ok1 = book(['date' => '2026-10-20', 'start' => '09:00', 'end' => '10:00', 'email' => 'sam@acme.com'], '10.2.0.1');
$ok2 = book(['date' => '2026-10-20', 'start' => '10:00', 'end' => '11:00', 'email' => 'kim@mail.acme.com'], '10.2.0.1');
$ok3 = book(['date' => '2026-10-20', 'start' => '11:00', 'end' => '12:00', 'email' => 'solo@freelance.com'], '10.2.0.1');
$ok4 = book(['date' => '2026-10-20', 'start' => '12:00', 'end' => '13:00', 'email' => 'boss@example.com'], '10.2.0.1');
check('approved address, approved domain (and subdomain) and admins book instantly',
    $ok1['status'] === 'confirmed' && $ok2['status'] === 'confirmed' && $ok3['status'] === 'confirmed' && $ok4['status'] === 'confirmed');

[$p1, , $req1] = Bookings::createPublic(['space_id' => 2, 'date' => '2026-10-20', 'start' => '09:00', 'end' => '10:00', 'name' => 'Nia New', 'email' => 'nia@newco.com', 'company' => 'NewCo'], '10.2.0.2');
check('unknown address → pending, with a new approval request', $p1['status'] === 'pending' && $req1 && $req1['status'] === 'pending' && strlen($req1['token']) === 48);
check('a pending request holds its time slot', expectError(fn () => book(['space_id' => 2, 'date' => '2026-10-20', 'start' => '09:30', 'end' => '10:30', 'email' => 'sam@acme.com'], '10.2.0.1'), 'conflict') !== null);
check('the public calendar marks it pending (no email shown)', Bookings::publicView($p1)['pending'] === true && !isset(Bookings::publicView($p1)['email']));
[$p2, , $req2] = Bookings::createPublic(['space_id' => 2, 'date' => '2026-10-21', 'start' => '09:00', 'end' => '10:00', 'name' => 'Nia New', 'email' => 'nia@newco.com'], '10.2.0.2');
check('a second booking joins the same request (manager not emailed twice)', $p2['status'] === 'pending' && $req2 === null);
Bookings::createPublic(['space_id' => 2, 'date' => '2026-10-22', 'start' => '09:00', 'end' => '10:00', 'name' => 'Nia New', 'email' => 'nia@newco.com'], '10.2.0.2');
check('at most 3 requests can wait at once', expectError(fn () => Bookings::createPublic(['space_id' => 2, 'date' => '2026-10-23', 'start' => '09:00', 'end' => '10:00', 'name' => 'Nia', 'email' => 'nia@newco.com'], '10.2.0.2'), null, 'email') !== null);
$mailIds = GPC\Notify::approvalRequest($req1);
check('the approval request goes to the configured manager', (string) Db::value('SELECT to_email FROM emails WHERE id = ?', [$mailIds[0]]) === 'manager@gpc.example'
    && str_contains((string) Db::value('SELECT body_text FROM emails WHERE id = ?', [$mailIds[0]]), 'approve.php?t=' . $req1['token']));
check('no reminders are sent for pending requests', (function () { freeze('2026-10-20 12:30:00'); $n = Jobs::queueReminders(); freeze('2026-10-07 16:00:00'); return $n === 1; })());

$approved = GPC\EmailAccess::approve(GPC\EmailAccess::findByToken($req1['token']), 'boss');
check('approving confirms every waiting reservation', count($approved['confirmed']) === 3 && Bookings::find($p1['id'])['status'] === 'confirmed');
check('…and adds the address to the approved list', GPC\EmailAccess::isApproved('nia@newco.com') && GPC\EmailAccess::findByToken($req1['token']) === null);
check('…so the next booking is confirmed instantly', book(['date' => '2026-10-27', 'email' => 'nia@newco.com'], '10.2.0.2')['status'] === 'confirmed');

[, , $reqJ] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-20', 'start' => '09:00', 'end' => '10:00', 'name' => 'Jo', 'email' => 'jo@bigco.com'], '10.2.0.3');
$approvedDomain = GPC\EmailAccess::approve($reqJ, 'boss', true);
check('approving “everyone at @bigco.com” approves colleagues too', $approvedDomain['pattern'] === '@bigco.com' && GPC\EmailAccess::isApproved('bob@bigco.com'));
[$w1] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-23', 'start' => '09:00', 'end' => '10:00', 'name' => 'Ann', 'email' => 'ann@teamco.com'], '10.2.0.7');
[$w2, , $reqW2] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-23', 'start' => '10:00', 'end' => '11:00', 'name' => 'Ben', 'email' => 'ben@teamco.com'], '10.2.0.7');
$teamApproved = GPC\EmailAccess::approve($reqW2, 'boss', true);
check('approving a whole company also confirms colleagues already waiting', count($teamApproved['confirmed']) === 2
    && Bookings::find($w1['id'])['status'] === 'confirmed' && GPC\EmailAccess::findByPattern('ann@teamco.com')['status'] === 'approved');
[$w3] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-24', 'start' => '09:00', 'end' => '10:00', 'name' => 'Cy', 'email' => 'cy@later.co'], '10.2.0.8');
$viaList = GPC\EmailAccess::add(['@later.co'], 'boss');
check('adding @domain to the list confirms anyone from it already waiting', count($viaList['confirmed']) === 1 && Bookings::find($w3['id'])['status'] === 'confirmed');
[, , $reqG] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-20', 'start' => '10:00', 'end' => '11:00', 'name' => 'G', 'email' => 'g.user@gmail.com'], '10.2.0.4');
$approvedG = GPC\EmailAccess::approve($reqG, 'boss', true);
check('…but never a public domain like gmail.com', $approvedG['pattern'] === 'g.user@gmail.com' && !GPC\EmailAccess::isApproved('someone.else@gmail.com'));

[$sp, , $reqS] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-21', 'start' => '09:00', 'end' => '10:00', 'name' => 'Spam', 'email' => 'spam@bad.example'], '10.2.0.5');
$declined = GPC\EmailAccess::decline($reqS, 'boss', 'Not a tenant');
check('declining cancels and releases the held time', count($declined) === 1 && Bookings::find($sp['id'])['status'] === 'cancelled'
    && Bookings::find($sp['id'])['cancel_reason'] === 'Not a tenant'
    && book(['space_id' => 3, 'date' => '2026-10-21', 'start' => '09:00', 'end' => '10:00', 'email' => 'sam@acme.com'], '10.2.0.1')['status'] === 'confirmed');
[$sp2, , $reqS2] = Bookings::createPublic(['space_id' => 3, 'date' => '2026-10-22', 'start' => '09:00', 'end' => '10:00', 'name' => 'Spam', 'email' => 'spam@bad.example'], '10.2.0.5');
check('a declined address that tries again needs approval again', $sp2['status'] === 'pending' && $reqS2 !== null);
check('a pending entry cannot simply be removed', expectError(fn () => GPC\EmailAccess::remove((int) $reqS2['id'], 'boss')) !== null);

freeze('2026-10-22 13:05:00'); // 9:05 AM local on the 22nd — the spam request has started, unapproved
$res = Jobs::run();
check('unanswered requests are released when they start', $res['expired'] === 1 && Bookings::find($sp2['id'])['status'] === 'cancelled'
    && (int) Db::value("SELECT COUNT(*) FROM emails WHERE type = 'expired' AND booking_id = ?", [$sp2['id']]) === 1);
Settings::set('require_approval', false);
check('turning approval off lets anyone book instantly again', book(['date' => '2026-10-28', 'email' => 'anyone@else.example'], '10.2.0.6')['status'] === 'confirmed');

Db::run("INSERT INTO settings (name, value) VALUES ('allowed_email_domains', 'tenant.org, other.org')");
GPC\Migrations::run();
check('upgrade moves the old allowed-domains setting into the approved list', GPC\EmailAccess::isApproved('a@tenant.org') && GPC\EmailAccess::isApproved('b@other.org')
    && Db::value("SELECT COUNT(*) FROM settings WHERE name = 'allowed_email_domains'") == 0);

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
echo "\nSeveral rooms at once, and weekly repeats\n";
reset_db();
freeze('2026-10-07 16:00:00'); // Wed Oct 7, 12:00 PM in Atlanta
$lee = ['name' => 'Lee Park', 'email' => 'lee@acme.com', 'title' => 'Saturday workshop', 'company' => 'Acme'];
$lastEmail = static fn (string $type) => Db::one('SELECT * FROM emails WHERE type = ? ORDER BY id DESC LIMIT 1', [$type]);

[$m] = Bookings::createPublic($lee + ['space_ids' => [2, 1], 'date' => '2026-10-10', 'start' => '10:00', 'end' => '14:00'], '10.3.0.1');
$mGroup = Bookings::groupOf($m);
check('books two rooms in one go', count($mGroup) === 2 && $mGroup[0]['series_id'] && $mGroup[0]['series_id'] === $mGroup[1]['series_id']
    && array_column($mGroup, 'space_id') === [1, 2] && Bookings::statusOf($mGroup) === 'confirmed');
$mView = Bookings::ownerView($m);
check('the manage view shows both rooms in booking-page order', $mView['space_name'] === 'Upstairs Conference Room + Downstairs Conference Room'
    && $mView['space_ids'] === [1, 2] && count($mView['occasions']) === 1 && count($mView['mine']) === 2);

book(['space_id' => 3, 'date' => '2026-10-10', 'start' => '14:00', 'end' => '15:00', 'email' => 'kim@other.com', 'name' => 'Kim'], '10.3.0.9');
$err = expectError(fn () => Bookings::createPublic($lee + ['space_ids' => [2, 3], 'date' => '2026-10-10', 'start' => '14:00', 'end' => '15:00'], '10.3.0.1'), 'conflict');
check('if one room is taken, nothing is booked (and the message names the room)', $err !== null && str_contains($err->getMessage(), 'Upstairs Lounge')
    && (int) Db::value("SELECT COUNT(*) FROM bookings WHERE email = 'lee@acme.com'") === 2);

Db::update('spaces', ['hours' => json_encode(['0' => null, '1' => ['09:00', '17:00'], '2' => ['09:00', '17:00'], '3' => ['09:00', '17:00'], '4' => ['09:00', '17:00'], '5' => ['09:00', '17:00'], '6' => ['10:00', '14:00']])], 'id = 2', []);
check('every room’s opening hours apply', expectError(fn () => Bookings::createPublic($lee + ['space_ids' => [1, 2], 'date' => '2026-10-17', 'start' => '13:00', 'end' => '15:00'], '10.3.0.1'), null, 'start') !== null);
Db::update('spaces', ['hours' => null], 'id = 2', []);

$repeat = $lee + ['space_ids' => [1, 2], 'date' => '2026-10-17', 'start' => '10:00', 'end' => '14:00', 'repeat' => 'weekly'];
check('repeats can reach 12 weeks ahead, not further', expectError(fn () => Bookings::createPublic($repeat + ['repeat_until' => '2027-01-02'], '10.3.0.1'), null, 'repeat_until') !== null);
check('a repeat needs an end date at least a week later', expectError(fn () => Bookings::createPublic($repeat + ['repeat_until' => '2026-10-20'], '10.3.0.1'), null, 'repeat_until') !== null);
Settings::set('repeat_max_weeks', 0);
check('repeats can be turned off in Settings', expectError(fn () => Bookings::createPublic($repeat + ['repeat_until' => '2026-11-14'], '10.3.0.1'), null, 'repeat') !== null);
Settings::set('repeat_max_weeks', 12);

book(['space_id' => 2, 'date' => '2026-10-31', 'start' => '10:00', 'end' => '11:00', 'email' => 'kim@other.com', 'name' => 'Kim'], '10.3.0.9');
$err = expectError(fn () => Bookings::createPublic($repeat + ['repeat_until' => '2026-12-26'], '10.3.0.1'), 'conflict');
check('a repeat lists the taken dates instead of booking around them silently', $err !== null && count($err->extra['conflicts']) === 1
    && $err->extra['conflicts'][0]['date'] === '2026-10-31' && $err->extra['creatable'] === 10 && $err->extra['total'] === 11);
check('…without revealing who has them', $err !== null && !array_filter($err->extra['conflicts'], static fn ($c) => isset($c['name']) || isset($c['label']) || isset($c['email'])));
[$s1] = Bookings::createPublic($repeat + ['repeat_until' => '2026-12-26', 'skip_conflicts' => true], '10.3.0.1');
$series = Bookings::groupOf($s1);
check('…and can book every other Saturday through Dec 26, both rooms each', count($series) === 20 && count(Bookings::byOccasion($series)) === 10
    && !in_array('2026-10-31', array_column($series, 'date'), true) && end($series)['date'] === '2026-12-26');
check('the manage view lists all 10 dates', count(Bookings::ownerView($s1)['occasions']) === 10);

Settings::set('max_upcoming_per_email', 12); // Lee has 11 upcoming dates (22 rooms): Oct 10 + 10 Saturdays
check('the upcoming limit counts dates, not rooms', Bookings::createPublic($lee + ['space_ids' => [1, 2], 'date' => '2026-10-12', 'start' => '09:00', 'end' => '10:00'], '10.3.0.1')[0]['status'] === 'confirmed');
Settings::set('max_upcoming_per_email', 13);
check('…and refuses a repeat that would go over it', expectError(fn () => Bookings::createPublic($lee + ['space_id' => 3, 'date' => '2026-10-13', 'start' => '09:00', 'end' => '10:00', 'repeat' => 'weekly', 'repeat_until' => '2026-10-20'], '10.3.0.1'), null, 'repeat_until') !== null);
Settings::set('max_upcoming_per_email', 25);

$cid = GPC\Notify::confirmation($s1);
$conf = Db::one('SELECT * FROM emails WHERE id = ?', [$cid]);
check('one confirmation covers every room and date', $conf !== null && substr_count((string) $conf['ics'], 'BEGIN:VEVENT') === 20
    && $conf['subject'] === 'Confirmed: Upstairs Conference Room + Downstairs Conference Room — Saturdays from Oct 17 (10 dates)'
    && str_contains($conf['body_text'], 'Sat, Oct 24') && str_contains($conf['body_text'], 'Sat, Dec 26') && !str_contains($conf['body_text'], 'Sat, Oct 31'));

freeze('2026-10-17 13:05:00'); // Sat 9:05 AM, 55 minutes before
check('one reminder per date, naming both rooms', Jobs::queueReminders() === 1
    && str_contains($lastEmail('reminder')['body_text'], 'Reminder: You have the Upstairs Conference Room and Downstairs Conference Room reserved today from 10:00 AM–2:00 PM.'));
freeze('2026-10-17 18:01:00'); // 2:01 PM, just after the end
check('one after-use note per date', Jobs::queueFollowups() === 1 && str_contains($lastEmail('followup')['subject'], 'Upstairs Conference Room and Downstairs Conference Room'));
freeze('2026-10-18 16:00:00');

$rowOn = static function (string $date, int $space) use ($s1): array {
    foreach (Bookings::groupOf($s1) as $r) {
        if ($r['date'] === $date && $r['space_id'] === $space) {
            return $r;
        }
    }
    throw new RuntimeException("no row on $date in space $space");
};
$edit = ['date' => '2026-10-24', 'start' => '10:00', 'end' => '14:00', 'name' => 'Lee Park', 'title' => 'Saturday workshop', 'company' => 'Acme'];
[, $changed] = Bookings::updateByOwner($rowOn('2026-10-24', 1), $edit + ['space_ids' => [1]]);
check('a date can drop a room; other dates keep both', $changed && $rowOn('2026-10-24', 2)['status'] === 'cancelled' && $rowOn('2026-10-24', 1)['status'] === 'confirmed'
    && $rowOn('2026-11-07', 2)['status'] === 'confirmed');
Bookings::updateByOwner($rowOn('2026-10-24', 1), ['start' => '11:00', 'end' => '15:00', 'space_ids' => [1, 3]] + $edit);
$o24 = Bookings::ownerView($rowOn('2026-10-24', 2)); // the removed room's link still leads to that date
check('…can move and add a room (all rooms move together)', $o24['space_name'] === 'Upstairs Conference Room + Upstairs Lounge + Kitchenette' && $o24['start'] === '11:00'
    && $rowOn('2026-10-24', 3)['status'] === 'confirmed' && $rowOn('2026-10-24', 2)['start'] === '11:00');
$was = $rowOn('2026-10-24', 2)['id'];
Bookings::updateByOwner($rowOn('2026-10-24', 3), ['start' => '11:00', 'end' => '15:00', 'space_ids' => [1, 2, 3], 'title' => 'Renamed workshop'] + $edit);
check('…and re-adding a room reuses its booking, so old links keep working', $rowOn('2026-10-24', 2)['id'] === $was && $rowOn('2026-10-24', 2)['status'] === 'confirmed');
check('a title change applies to every date', $rowOn('2026-12-26', 2)['title'] === 'Renamed workshop');
check('only the changed date moved', $rowOn('2026-11-07', 1)['start'] === '10:00' && count(Bookings::occasionOf($rowOn('2026-11-07', 1))) === 2);
GPC\Notify::updated($rowOn('2026-10-24', 1));
check('the update email lists the date’s rooms and says other dates are unchanged', str_contains($lastEmail('updated')['subject'], 'Upstairs Conference Room + Downstairs Conference Room + Upstairs Lounge + Kitchenette — Sat, Oct 24, 11:00 AM–3:00 PM')
    && str_contains($lastEmail('updated')['body_text'], 'Your other dates are unchanged.'));
book(['space_id' => 3, 'date' => '2026-11-07', 'start' => '09:00', 'end' => '11:00', 'email' => 'kim@other.com', 'name' => 'Kim'], '10.3.0.9');
check('adding a room that is taken changes nothing', expectError(fn () => Bookings::updateByOwner($rowOn('2026-11-07', 1), ['date' => '2026-11-07', 'space_ids' => [1, 2, 3]] + $edit), 'conflict') !== null
    && count(array_filter(Bookings::occasionOf($rowOn('2026-11-07', 1)), [Bookings::class, 'isActive'])) === 2);

[$b14, $gone] = Bookings::cancelByOwner($rowOn('2026-11-14', 2));
check('cancel one date: both rooms released, the rest kept', count($gone) === 2 && $b14['status'] === 'cancelled' && $rowOn('2026-11-21', 1)['status'] === 'confirmed');
GPC\Notify::cancelled($gone);
check('…with one email that says the other dates stand', str_contains($lastEmail('cancelled')['body_text'], 'Your other 8 dates are still reserved.'));
[, $rest] = Bookings::cancelByOwner($rowOn('2026-11-21', 1), 'all');
check('cancel all remaining dates', count(Bookings::byOccasion($rest)) === 8 && !array_filter(Bookings::groupOf($s1), static fn ($r) => Bookings::isActive($r) && $r['end_utc'] > Time::nowDb()));
check('…and nothing is left to cancel', expectError(fn () => Bookings::cancelByOwner($rowOn('2026-12-26', 1), 'all')) !== null);
check('dates that already happened stay as they were', $rowOn('2026-10-17', 1)['status'] === 'confirmed');

$adminMulti = Bookings::adminCreate(['kind' => 'reservation', 'space_ids' => [1, 2], 'date' => '2026-11-07', 'start' => '15:00', 'end' => '16:00',
    'repeat' => 'weekly', 'repeat_until' => '2026-11-21', 'name' => 'Board', 'email' => 'board@acme.com'], $admin);
check('admins can reserve several rooms at once too', count($adminMulti['bookings']) === 6 && count(Bookings::byOccasion($adminMulti['bookings'])) === 3);
check('admins can cancel one space, or every space on a date', count(Bookings::adminCancel($adminMulti['bookings'][0]['id'], 'one', '', $admin)) === 1
    && count(Bookings::adminCancel($adminMulti['bookings'][2]['id'], 'date', '', $admin)) === 2
    && Bookings::find($adminMulti['bookings'][4]['id'])['status'] === 'confirmed');
GPC\Notify::cancelled([Bookings::find($adminMulti['bookings'][0]['id'])], true);
check('…and the email says what is still reserved', str_contains($lastEmail('cancelled')['body_text'], 'Still reserved at the same time: Downstairs Conference Room.')
    && str_contains($lastEmail('cancelled')['body_text'], 'Your other date is still reserved.'));

echo "\nApproval and expiry for repeats\n";
Settings::set('require_approval', true);
[$p1, , $reqN] = Bookings::createPublic(['space_ids' => [1, 2], 'date' => '2026-11-03', 'start' => '09:00', 'end' => '10:00', 'repeat' => 'weekly', 'repeat_until' => '2026-11-17', 'name' => 'Nia New', 'email' => 'nia@newco.com'], '10.4.0.1');
check('a new address’s repeat is held as one request', $p1['status'] === 'pending' && $reqN !== null && count(Bookings::groupOf($p1)) === 6);
check('…which counts once toward the 3 waiting requests',
    Bookings::createPublic(['space_id' => 3, 'date' => '2026-11-04', 'start' => '09:00', 'end' => '10:00', 'name' => 'Nia New', 'email' => 'nia@newco.com'], '10.4.0.1')[0]['status'] === 'pending');
$before = (int) Db::value('SELECT COUNT(*) FROM emails');
GPC\Notify::pendingReceived($p1);
GPC\Notify::approvalRequest($reqN);
$ask = $lastEmail('approval_request');
check('one “request received” email and one approval email describing it', (int) Db::value('SELECT COUNT(*) FROM emails') === $before + 2
    && stripos($ask['body_text'], 'Upstairs Conference Room + Downstairs Conference Room') !== false && str_contains($ask['body_text'], '3 dates'));

freeze('2026-11-03 14:05:00'); // 9:05 AM Nov 3 (EST): the first date started, still unapproved
$res = Jobs::run();
check('an unapproved date is released when it starts, both rooms, one email', $res['expired'] === 2
    && (int) Db::value("SELECT COUNT(*) FROM emails WHERE type = 'expired'") === 1
    && str_contains($lastEmail('expired')['body_text'], 'Your other 2 dates are still held'));
$confirmedBefore = (int) Db::value("SELECT COUNT(*) FROM emails WHERE type = 'confirmation'");
$ok = GPC\EmailAccess::approve(GPC\EmailAccess::findByToken($reqN['token']), 'boss');
$ids = GPC\Notify::decisionEmails($ok['confirmed'], true);
check('approving confirms the rest, one email per reservation', count($ok['confirmed']) === 5 && count($ids) === 2
    && (int) Db::value("SELECT COUNT(*) FROM emails WHERE type = 'confirmation'") === $confirmedBefore + 2);
check('the approval page lists one line per reservation', count(GPC\Notify::summaries($ok['confirmed'])) === 2);
Settings::set('require_approval', false);
freeze('2026-10-07 16:00:00');

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
