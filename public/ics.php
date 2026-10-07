<?php
/**
 * Calendar files.
 *   ics.php?t=<manage token>             → "Add to calendar" file for one reservation
 *   ics.php?key=<feed key>[&space=<slug>] → subscribable feed for staff (Google Calendar → "From URL")
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\Bookings;
use GPC\Ics;
use GPC\Settings;
use GPC\Spaces;
use GPC\Time;

$send = static function (string $body, string $filename, bool $download): void {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header(($download ? 'Content-Disposition: attachment; ' : 'Content-Disposition: inline; ') . 'filename="' . $filename . '"');
    echo $body;
};

$fail = static function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
};

try {
    if (isset($_GET['t'])) {
        $booking = Bookings::findByToken((string) $_GET['t']);
        if (!$booking || $booking['kind'] !== 'reservation') {
            $fail(404, 'Reservation not found.');
            return;
        }
        $send(Ics::forBooking($booking, Spaces::find($booking['space_id'])), 'reservation-' . $booking['ref'] . '.ics', true);
        return;
    }

    $key = (string) Settings::get('ics_feed_key');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        $fail(403, 'Invalid calendar feed key.');
        return;
    }
    $spaces = [];
    foreach (Spaces::all() as $s) {
        $spaces[$s['id']] = $s;
    }
    $only = null;
    if (!empty($_GET['space'])) {
        foreach ($spaces as $s) {
            if ($s['slug'] === $_GET['space']) {
                $only = $s;
            }
        }
        if (!$only) {
            $fail(404, 'Space not found.');
            return;
        }
    }
    $bookings = Bookings::inRange(Time::addDays(Time::today(), -30), Time::addDays(Time::today(), 365), $only['id'] ?? null);
    $name = Settings::get('org_name') . ' — ' . ($only['name'] ?? 'All spaces');
    $send(Ics::feed($bookings, $only ? [$only['id'] => $only] : $spaces, $name), 'gpc-' . ($only['slug'] ?? 'all') . '.ics', false);
} catch (Throwable $e) {
    GPC\App::log('ics: ' . $e->getMessage());
    $fail(500, 'Calendar temporarily unavailable.');
}
