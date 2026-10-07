<?php
/** Administrator JSON API. Everything except sign-in requires a signed admin cookie + CSRF header on changes. */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use GPC\App;
use GPC\AppError;
use GPC\Audit;
use GPC\Auth;
use GPC\Bookings;
use GPC\Db;
use GPC\Http;
use GPC\Jobs;
use GPC\Mailer;
use GPC\Notify;
use GPC\Settings;
use GPC\Spaces;
use GPC\Stats;
use GPC\Time;
use GPC\Util;

Http::securityHeaders();

Http::handle(function (): void {
    $action = (string) ($_GET['action'] ?? '');
    $method = Http::method();
    $in = Http::input();

    $adminView = static fn (array $a) => [
        'id' => (int) $a['id'], 'email' => $a['email'], 'name' => $a['name'],
        'is_active' => (bool) $a['is_active'], 'last_login_at' => $a['last_login_at'],
    ];

    // ---- Sign in / out (no session needed) ----
    if ($method === 'POST' && $action === 'login') {
        $admin = Auth::login((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
        Http::json(['ok' => true, 'admin' => $adminView($admin), 'csrf' => Auth::csrfToken()]);
        return;
    }
    if ($method === 'POST' && $action === 'logout') {
        Auth::logout();
        Http::json(['ok' => true]);
        return;
    }

    $admin = Auth::require();
    if ($method === 'POST') {
        Auth::checkCsrf();
    }
    $actor = 'admin:' . $admin['email'];

    switch ("$method $action") {
        case 'GET me':
            Http::json([
                'ok'       => true,
                'admin'    => $adminView($admin),
                'csrf'     => Auth::csrfToken(),
                'spaces'   => array_map([Spaces::class, 'adminView'], Spaces::all()),
                'settings' => Settings::editable(),
                'today'    => Time::today(),
                'now_min'  => (int) Time::nowLocal()->format('G') * 60 + (int) Time::nowLocal()->format('i'),
            ]);
            return;

        case 'GET dashboard':
            Http::json(['ok' => true] + Stats::dashboard());
            return;

        // ---- Reservations ----
        case 'GET calendar':
            $from = (string) ($_GET['from'] ?? '');
            $to = (string) ($_GET['to'] ?? $from);
            if (!Time::isDate($from) || !Time::isDate($to) || $to < $from || $to > Time::addDays($from, 42)) {
                throw new AppError('Invalid date range.');
            }
            Http::json([
                'ok'       => true,
                'today'    => Time::today(),
                'now_min'  => (int) Time::nowLocal()->format('G') * 60 + (int) Time::nowLocal()->format('i'),
                'bookings' => array_map([Bookings::class, 'adminView'], Bookings::inRange($from, $to)),
            ]);
            return;

        case 'GET bookings':
            [$rows, $total] = searchBookings($_GET);
            Http::json(['ok' => true, 'bookings' => array_map([Bookings::class, 'adminView'], $rows), 'total' => $total]);
            return;

        case 'GET export':
            [$rows] = searchBookings($_GET, 5000);
            $spaces = array_column(Spaces::all(), 'name', 'id');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reservations-' . Time::today() . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Type', 'Status', 'Space', 'Date', 'Start', 'End', 'Hours', 'Title', 'Name', 'Email', 'Company', 'Notes', 'Source', 'Created (UTC)', 'Cancelled (UTC)']);
            foreach ($rows as $b) {
                $csvSafe = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v; // stop spreadsheet formula injection
                fputcsv($out, array_map($csvSafe, [
                    $b['ref'], $b['kind'], $b['status'], $spaces[$b['space_id']] ?? '', $b['date'], $b['start'], $b['end'],
                    round(($b['end_min'] - $b['start_min']) / 60, 2), $b['title'], $b['name'], $b['email'], $b['company'],
                    $b['notes'], $b['source'], $b['created_at'], $b['cancelled_at'],
                ]));
            }
            fclose($out);
            return;

        case 'GET booking':
            $booking = Bookings::find((int) ($_GET['id'] ?? 0));
            if (!$booking) {
                throw new AppError('Booking not found.', 404);
            }
            $createdBy = $booking['created_by_admin'] ? Db::value('SELECT name FROM admins WHERE id = ?', [$booking['created_by_admin']]) : null;
            Http::json([
                'ok'      => true,
                'booking' => Bookings::adminView($booking) + [
                    'created_by_name' => $createdBy,
                    'manage_url'      => $booking['manage_token'] ? Notify::manageUrl($booking) : null,
                    'series_count'    => $booking['series_id'] ? (int) Db::value("SELECT COUNT(*) FROM bookings WHERE series_id = ? AND status = 'confirmed'", [$booking['series_id']]) : 0,
                ],
                'history' => Audit::forBooking($booking['id']),
                'emails'  => Db::all('SELECT id, type, to_email, subject, status, attempts, last_error, sent_at, created_at FROM emails WHERE booking_id = ? ORDER BY id', [$booking['id']]),
            ]);
            return;

        case 'POST booking_create':
            $result = Bookings::adminCreate($in, $admin);
            $emailIds = [];
            $first = $result['bookings'][0];
            if ($first['kind'] === 'reservation' && $first['notify']) {
                $emailIds[] = Notify::confirmation($first, count($result['bookings']));
            }
            Http::jsonAndFinish([
                'ok'      => true,
                'created' => count($result['bookings']),
                'skipped' => $result['skipped'],
                'booking' => Bookings::adminView($first),
            ], 201);
            Mailer::sendNow($emailIds);
            return;

        case 'POST booking_update':
            [$booking, $moved] = Bookings::adminUpdate((int) ($in['id'] ?? 0), $in, $admin);
            $emailIds = [];
            if ($moved && $booking['status'] === 'confirmed' && filter_var($in['send_update'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                $emailIds[] = Notify::updated($booking);
            }
            Http::jsonAndFinish(['ok' => true, 'booking' => Bookings::adminView($booking)]);
            Mailer::sendNow($emailIds);
            return;

        case 'POST booking_cancel':
            $cancelled = Bookings::adminCancel((int) ($in['id'] ?? 0), (string) ($in['scope'] ?? 'one'), (string) ($in['reason'] ?? ''), $admin);
            $emailIds = [];
            if ($cancelled && filter_var($in['notify'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                $emailIds[] = Notify::cancelled($cancelled[0], true, count($cancelled));
            }
            Http::jsonAndFinish(['ok' => true, 'cancelled' => count($cancelled)]);
            Mailer::sendNow($emailIds);
            return;

        case 'POST booking_delete':
            $deleted = Bookings::adminDelete((int) ($in['id'] ?? 0), (string) ($in['scope'] ?? 'one'), $admin);
            Http::json(['ok' => true, 'deleted' => $deleted]);
            return;

        // ---- Spaces ----
        case 'GET spaces':
            Http::json(['ok' => true, 'spaces' => array_map([Spaces::class, 'adminView'], Spaces::all())]);
            return;

        case 'POST space_save':
            $id = isset($in['id']) && $in['id'] !== '' ? (int) $in['id'] : null;
            $space = Spaces::save($in, $id);
            Audit::log(null, $actor, $id ? 'space_updated' : 'space_created', $space['name']);
            Http::json(['ok' => true, 'space' => Spaces::adminView($space)]);
            return;

        case 'POST space_delete':
            $space = Spaces::find((int) ($in['id'] ?? 0));
            Spaces::delete((int) ($in['id'] ?? 0));
            Audit::log(null, $actor, 'space_deleted', $space['name'] ?? '');
            Http::json(['ok' => true]);
            return;

        case 'POST space_reorder':
            Spaces::reorder((array) ($in['ids'] ?? []));
            Http::json(['ok' => true]);
            return;

        case 'POST space_photo':
            $space = Spaces::savePhoto((int) ($_POST['id'] ?? 0), $_FILES['photo'] ?? []);
            Http::json(['ok' => true, 'space' => Spaces::adminView($space)]);
            return;

        case 'POST space_photo_remove':
            Spaces::removePhoto((int) ($in['id'] ?? 0));
            Http::json(['ok' => true]);
            return;

        // ---- Settings ----
        case 'GET settings':
            Http::json(['ok' => true, 'settings' => Settings::editable(), 'feeds' => feedUrls(), 'health' => Stats::health(),
                'mail' => ['transport' => App::config('mail.transport'), 'from' => App::config('mail.from_email'), 'host' => App::config('mail.host')]]);
            return;

        case 'POST settings_save':
            Settings::saveMany($in);
            Audit::log(null, $actor, 'settings_updated', implode(', ', array_keys($in)));
            Http::json(['ok' => true, 'settings' => Settings::editable()]);
            return;

        case 'POST feed_key_reset':
            Settings::set('ics_feed_key', Util::token(16));
            Audit::log(null, $actor, 'feed_key_reset');
            Http::json(['ok' => true, 'feeds' => feedUrls()]);
            return;

        // ---- Email ----
        case 'GET emails':
            $status = (string) ($_GET['status'] ?? '');
            $where = in_array($status, ['pending', 'sent', 'failed'], true) ? 'WHERE status = ?' : '';
            $rows = Db::all(
                "SELECT id, booking_id, type, to_email, subject, status, attempts, last_error, send_after, sent_at, created_at
                   FROM emails $where ORDER BY id DESC LIMIT 200",
                $where ? [$status] : []
            );
            Http::json(['ok' => true, 'emails' => $rows, 'health' => Stats::health()]);
            return;

        case 'GET email':
            $row = Db::one('SELECT id, to_email, subject, body_text, status, last_error FROM emails WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
            if (!$row) {
                throw new AppError('Email not found.', 404);
            }
            Http::json(['ok' => true, 'email' => $row]);
            return;

        case 'POST email_retry':
            Mailer::retry((int) ($in['id'] ?? 0));
            Http::json(['ok' => true, 'email' => Db::one('SELECT id, status, last_error FROM emails WHERE id = ?', [(int) ($in['id'] ?? 0)])]);
            return;

        case 'POST email_test':
            $to = trim((string) ($in['to'] ?? $admin['email']));
            if (!Util::isValidEmail($to)) {
                throw new AppError('Please enter a valid email address.', 400, 'to');
            }
            $id = Notify::test($to);
            Mailer::sendNow([$id]);
            Http::json(['ok' => true, 'email' => Db::one('SELECT id, status, last_error FROM emails WHERE id = ?', [$id])]);
            return;

        case 'POST run_jobs':
            Http::json(['ok' => true, 'result' => Jobs::run()]);
            return;

        // ---- Administrators ----
        case 'GET admins':
            Http::json(['ok' => true, 'admins' => array_map($adminView, Db::all('SELECT * FROM admins ORDER BY name')), 'me' => (int) $admin['id']]);
            return;

        case 'POST admin_save':
            $id = (int) ($in['id'] ?? 0);
            if ($id) {
                $active = filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
                if ($id === (int) $admin['id'] && !$active) {
                    throw new AppError('You can’t deactivate your own account.');
                }
                Db::update('admins', [
                    'name'      => Util::cleanText((string) ($in['name'] ?? ''), 120) ?: 'Administrator',
                    'is_active' => $active ? 1 : 0,
                ], 'id = :id', ['id' => $id]);
                if (!$active) {
                    Db::run('UPDATE admins SET session_version = session_version + 1 WHERE id = ?', [$id]);
                }
                if (($in['password'] ?? '') !== '') {
                    Auth::setPassword($id, (string) $in['password']);
                }
                Audit::log(null, $actor, 'admin_updated', (string) $id);
            } else {
                $id = Auth::createAdmin((string) ($in['email'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['password'] ?? ''));
                Audit::log(null, $actor, 'admin_created', (string) ($in['email'] ?? ''));
            }
            Http::json(['ok' => true]);
            return;

        case 'POST admin_delete':
            $id = (int) ($in['id'] ?? 0);
            if ($id === (int) $admin['id']) {
                throw new AppError('You can’t remove your own account.');
            }
            Db::run('DELETE FROM admins WHERE id = ?', [$id]);
            Audit::log(null, $actor, 'admin_deleted', (string) $id);
            Http::json(['ok' => true]);
            return;

        case 'POST password':
            if (!password_verify((string) ($in['current'] ?? ''), $admin['password_hash'])) {
                throw new AppError('Your current password is incorrect.', 400, 'current');
            }
            Auth::setPassword((int) $admin['id'], (string) ($in['new'] ?? ''));
            Auth::issueCookie(Db::one('SELECT * FROM admins WHERE id = ?', [$admin['id']]));
            Http::json(['ok' => true, 'csrf' => Auth::csrfToken()]);
            return;

        default:
            throw new AppError('Not found.', 404);
    }
});

function searchBookings(array $q, int $limit = 50): array
{
    $where = ['1 = 1'];
    $params = [];
    $text = trim((string) ($q['q'] ?? ''));
    if ($text !== '') {
        $like = '%' . addcslashes($text, '%_\\') . '%';
        $where[] = '(name LIKE ? OR email LIKE ? OR title LIKE ? OR company LIKE ? OR ref LIKE ? OR notes LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($q['space_id'])) {
        $where[] = 'space_id = ?';
        $params[] = (int) $q['space_id'];
    }
    if (in_array($q['status'] ?? '', ['confirmed', 'cancelled'], true)) {
        $where[] = 'status = ?';
        $params[] = $q['status'];
    }
    if (in_array($q['kind'] ?? '', ['reservation', 'block'], true)) {
        $where[] = 'kind = ?';
        $params[] = $q['kind'];
    }
    $when = (string) ($q['when'] ?? 'upcoming');
    $order = 'start_utc DESC';
    if ($when === 'upcoming') {
        $where[] = 'end_utc > ?';
        $params[] = Time::nowDb();
        $order = 'start_utc ASC';
    } elseif ($when === 'past') {
        $where[] = 'end_utc <= ?';
        $params[] = Time::nowDb();
    }
    if (Time::isDate((string) ($q['from'] ?? ''))) {
        $where[] = 'start_utc >= ?';
        $params[] = Time::localToUtc($q['from'], 0);
        $order = 'start_utc ASC';
    }
    if (Time::isDate((string) ($q['to'] ?? ''))) {
        $where[] = 'start_utc < ?';
        $params[] = Time::localToUtc($q['to'], 1440);
    }
    $sqlWhere = implode(' AND ', $where);
    $total = (int) Db::value("SELECT COUNT(*) FROM bookings WHERE $sqlWhere", $params);
    $page = max(1, (int) ($q['page'] ?? 1));
    $offset = ($page - 1) * $limit;
    $rows = Db::all("SELECT id FROM bookings WHERE $sqlWhere ORDER BY $order LIMIT $limit OFFSET $offset", $params);
    return [array_map(static fn ($r) => Bookings::find((int) $r['id']), $rows), $total];
}

function feedUrls(): array
{
    $key = (string) Settings::get('ics_feed_key');
    if ($key === '') {
        $key = Util::token(16);
        Settings::set('ics_feed_key', $key);
    }
    $feeds = [['name' => 'All spaces', 'url' => App::url('ics.php?key=' . $key)]];
    foreach (Spaces::all() as $s) {
        $feeds[] = ['name' => $s['name'], 'url' => App::url('ics.php?key=' . $key . '&space=' . $s['slug'])];
    }
    return $feeds;
}
