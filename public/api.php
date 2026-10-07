<?php
/**
 * Public JSON API used by the booking page and the "manage reservation" page.
 * Never returns email addresses or private details, except to the holder of a booking's manage link.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\AppError;
use GPC\Bookings;
use GPC\Db;
use GPC\FormToken;
use GPC\Http;
use GPC\Mailer;
use GPC\Notify;
use GPC\RateLimit;
use GPC\Time;
use GPC\Util;

Http::securityHeaders();

Http::handle(function (): void {
    $action = (string) ($_GET['action'] ?? '');
    $in = Http::input();

    if (Http::method() === 'POST' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        throw new AppError('Unsupported request.', 415);
    }

    $ownerBooking = static function (array $source): array {
        $booking = Bookings::findByToken((string) ($source['t'] ?? ''));
        if (!$booking || $booking['kind'] !== 'reservation') {
            throw new AppError('We couldn’t find that reservation. The link may be incomplete — try opening it again from your confirmation email.', 404, null, 'not_found');
        }
        return $booking;
    };

    switch (Http::method() . ' ' . $action) {
        case 'GET bookings':
            $from = (string) ($_GET['from'] ?? '');
            $to = (string) ($_GET['to'] ?? $from);
            if (!Time::isDate($from) || !Time::isDate($to) || $to < $from || $to > Time::addDays($from, 42)) {
                throw new AppError('Invalid date range.');
            }
            Http::json([
                'ok'       => true,
                'today'    => Time::today(),
                'now_min'  => (int) Time::nowLocal()->format('G') * 60 + (int) Time::nowLocal()->format('i'),
                'bookings' => array_map([Bookings::class, 'publicView'], Bookings::inRange($from, $to)),
            ]);
            return;

        case 'POST book':
            FormToken::check($in);
            [$booking, $duplicate] = Bookings::createPublic($in, Http::ip());
            $emailIds = [];
            if (!$duplicate) {
                $emailIds[] = Notify::confirmation($booking);
                $emailIds = array_merge($emailIds, Notify::admins($booking, 'new'));
            }
            Http::jsonAndFinish(['ok' => true, 'duplicate' => $duplicate, 'booking' => Bookings::ownerView($booking)], $duplicate ? 200 : 201);
            Mailer::sendNow($emailIds);
            return;

        case 'GET recover':
            // After a refresh mid-booking, the page asks whether its last submission went through.
            $booking = Bookings::findByRequestId((string) ($_GET['request_id'] ?? ''));
            Http::json(['ok' => true, 'booking' => $booking ? Bookings::ownerView($booking) : null]);
            return;

        case 'GET manage':
            Http::json(['ok' => true, 'booking' => Bookings::ownerView($ownerBooking($_GET))]);
            return;

        case 'POST update':
            [$booking, $moved] = Bookings::updateByOwner($ownerBooking($in), $in);
            $emailIds = $moved ? [Notify::updated($booking)] : [];
            Http::jsonAndFinish(['ok' => true, 'booking' => Bookings::ownerView($booking), 'moved' => $moved]);
            Mailer::sendNow($emailIds);
            return;

        case 'POST cancel':
            $booking = Bookings::cancelByOwner($ownerBooking($in));
            $emailIds = array_merge([Notify::cancelled($booking)], Notify::admins($booking, 'cancelled'));
            Http::jsonAndFinish(['ok' => true, 'booking' => Bookings::ownerView($booking)]);
            Mailer::sendNow($emailIds);
            return;

        case 'POST send_links':
            // "Lost your confirmation email?" — sends manage links for upcoming reservations.
            $email = strtolower(trim((string) ($in['email'] ?? '')));
            if (!Util::isValidEmail($email)) {
                throw new AppError('Please enter a valid email address.', 400, 'email');
            }
            $ip = Http::ip();
            if (!RateLimit::hit("links:$ip", 10, 3600) || !RateLimit::hit("links:$email", 3, 3600)) {
                throw new AppError('We’ve already sent a few emails. Please check your inbox (and spam folder) or try again later.', 429);
            }
            $rows = Db::all(
                "SELECT id FROM bookings WHERE email = ? AND status = 'confirmed' AND kind = 'reservation' AND end_utc > ? ORDER BY start_utc LIMIT 25",
                [$email, Time::nowDb()]
            );
            $emailId = null;
            if ($rows) {
                $emailId = Notify::links($email, array_map(static fn ($r) => Bookings::find((int) $r['id']), $rows));
            }
            // Same answer either way, so this can't be used to discover who has bookings.
            Http::jsonAndFinish(['ok' => true]);
            Mailer::sendNow([$emailId]);
            return;

        default:
            throw new AppError('Not found.', 404);
    }
});
