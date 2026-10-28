<?php
declare(strict_types=1);

namespace GPC;

use DateTimeImmutable;

/**
 * The words in every automated email. Each method queues a message and returns its outbox id.
 *
 * A reservation can cover several rooms and dates (one booking row per room per date). Emails
 * describe all the rows they are given in one message, so booking two rooms every Saturday means
 * one confirmation, and one reminder and one after-use note per Saturday — not one per room.
 */
final class Notify
{
    /** Longest list of dates written out in an email before "…and N more". */
    private const MAX_LISTED_DATES = 16;

    /** Everything confirmed in $b's reservation, in one email. */
    public static function confirmation(array $b, bool $justApproved = false): ?int
    {
        $rows = self::inGroup($b, ['confirmed']);
        if (!$rows || !self::emailable($rows[0])) {
            return null;
        }
        $first = self::firstUpcoming($rows);
        $count = count(Bookings::byOccasion($rows));
        $hi = 'Hi ' . Util::firstName($first['name']) . ', ';
        $paragraphs = [$justApproved
            ? $hi . 'good news — building management approved your email address, so your reservation is confirmed. From now on, your reservations are confirmed instantly.'
            : $hi . 'your reservation is confirmed. Here are the details:'];
        if ($count > 1) {
            $paragraphs[] = "This reservation repeats on $count dates. You’ll get a reminder before each one.";
        }
        $body = EmailTemplate::render([
            'preheader'  => self::roomTitle($rows) . ' · ' . self::when($rows),
            'heading'    => 'Your reservation is confirmed.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($rows),
            'notes'      => [[
                'title' => 'Need to make a change?',
                'body'  => ($count > 1
                    ? 'Use “Manage reservation” to change the time or rooms for a date, or to cancel one date or all of them — no password needed. '
                    : 'Use “Manage reservation” to change the time, switch rooms or cancel — no password needed. ')
                    . 'Keep this email: the button is your private link to this reservation.',
            ]],
            'buttons'    => self::buttons($first, true),
        ]);
        return Mailer::queue('confirmation', $first['email'], $first['name'], 'Confirmed: ' . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows), $body, $first['id'], Ics::forBookings($rows));
    }

    /** One date of a reservation was moved or its rooms changed. */
    public static function updated(array $b): ?int
    {
        $rows = array_values(array_filter(Bookings::occasionOf($b), static fn ($r) => $r['status'] === 'confirmed'));
        if (!$rows || !self::emailable($rows[0])) {
            return null;
        }
        $paragraphs = ['Hi ' . Util::firstName($b['name']) . ', here are the new details. Anything you no longer need has been released for others.'];
        if (count(Bookings::byOccasion(self::inGroup($b, ['confirmed', 'pending']))) > 1) {
            $paragraphs[] = 'Your other dates are unchanged.';
        }
        $body = EmailTemplate::render([
            'preheader'  => 'Now: ' . self::roomTitle($rows) . ' · ' . self::when($rows),
            'heading'    => 'Your reservation has been updated.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($rows),
            'buttons'    => self::buttons($rows[0], true),
        ]);
        return Mailer::queue('updated', $b['email'], $b['name'], 'Updated: ' . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows), $body, $rows[0]['id'], Ics::forBookings($rows));
    }

    /** $rows were just cancelled (all from one reservation). */
    public static function cancelled(array $rows, bool $byManagement = false): ?int
    {
        $rows = array_values(array_filter($rows));
        if (!$rows || !self::emailable($rows[0], true)) {
            return null;
        }
        $b = $rows[0];
        $count = count(Bookings::byOccasion($rows));
        $partOfMore = count(Bookings::byOccasion(Bookings::groupOf($b))) > $count;
        $hi = 'Hi ' . Util::firstName($b['name']) . ', ';
        $what = $count > 1 ? "these $count dates of your reservation" : ($partOfMore ? 'this date of your reservation' : 'your reservation');
        $paragraphs = [$byManagement
            ? $hi . Settings::get('org_name') . ' management has cancelled ' . ($count > 1 || $partOfMore ? "$what." : 'the reservation below.')
            : $hi . "$what " . ($count > 1 ? 'have' : 'has') . ' been cancelled and the time is open for others again.'];
        $stillBooked = array_filter(self::inGroup($b, ['confirmed', 'pending']), static fn ($r) => $r['end_utc'] > Time::nowDb());
        $cancelledTimes = array_column($rows, 'start_utc');
        $sameDate = array_filter($stillBooked, static fn ($r) => in_array($r['start_utc'], $cancelledTimes, true));
        $left = count(Bookings::byOccasion(array_filter($stillBooked, static fn ($r) => !in_array($r['start_utc'], $cancelledTimes, true))));
        if ($sameDate) {
            $paragraphs[] = 'Still reserved at the same time: ' . Util::andList(array_column(Bookings::spacesOf($sameDate), 'name')) . '.';
        }
        if ($left > 0) {
            $paragraphs[] = $left === 1 ? 'Your other date is still reserved.' : "Your other $left dates are still reserved.";
        }
        if (!empty($b['cancel_reason']) && $byManagement) {
            $paragraphs[] = 'Note from management: ' . $b['cancel_reason'];
        }
        $body = EmailTemplate::render([
            'preheader'  => self::roomTitle($rows) . ' · ' . self::when($rows),
            'heading'    => $count > 1 ? 'Dates cancelled.' : ($partOfMore ? 'Date cancelled.' : 'Reservation cancelled.'),
            'paragraphs' => $paragraphs,
            'details'    => self::details($rows),
            'buttons'    => ['Make a new reservation' => App::url('')],
        ]);
        return Mailer::queue('cancelled', $b['email'], $b['name'], 'Cancelled: ' . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows), $body, $b['id']);
    }

    /** To the tenant: their request is held while the building manager approves their email. */
    public static function pendingReceived(array $b): ?int
    {
        $rows = self::inGroup($b, ['pending']);
        if (!$rows || !self::emailable($rows[0], true)) {
            return null;
        }
        $first = $rows[0];
        $body = EmailTemplate::render([
            'preheader'  => 'Awaiting approval · ' . self::roomTitle($rows) . ' · ' . self::when($rows),
            'heading'    => 'Request received.',
            'paragraphs' => [
                'Hi ' . Util::firstName($first['name']) . ', thanks for your reservation request.',
                Settings::get('org_name') . ' approves each new email address once. We’ve asked building management to approve '
                    . $first['email'] . ' — you’ll get a confirmation email as soon as they do. Your time is held for you until then.',
            ],
            'details'    => self::details($rows) + ['Status' => 'Awaiting approval'],
            'buttons'    => ['View or cancel request' => self::manageUrl($first)],
        ]);
        return Mailer::queue('pending', $first['email'], $first['name'], 'Request received: ' . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows), $body, $first['id']);
    }

    /** To the building manager: a new email address wants to book. */
    public static function approvalRequest(array $access): array
    {
        $notes = [];
        foreach (self::groups(EmailAccess::pendingBookings($access['pattern'])) as $rows) {
            $notes[] = ['title' => self::roomTitle($rows), 'body' => self::whenLines($rows) . ($rows[0]['title'] ? "\n" . $rows[0]['title'] : '')];
        }
        $details = array_filter([
            'Name'    => (string) $access['name'],
            'Email'   => $access['pattern'],
            'Company' => (string) $access['company'],
        ]);
        $body = EmailTemplate::render([
            'preheader'  => $access['pattern'] . ' is waiting for approval',
            'heading'    => 'New email address to approve',
            'paragraphs' => ['Someone who isn’t on the approved list yet asked to reserve a space. Their time is held until you decide.'],
            'details'    => $details,
            'notes'      => $notes,
            'buttons'    => ['Review & approve' => App::url('approve.php?t=' . $access['token'])],
            'footer'     => 'Approving adds this address to the approved list, so their future reservations are confirmed instantly. '
                . 'You can also manage the list in Admin → Approved emails.' . "\n" . EmailTemplate::footer(),
        ]);
        $ids = [];
        foreach (EmailAccess::approverEmails() as $to) {
            $ids[] = Mailer::queue('approval_request', $to, null, 'Approval needed: ' . ($access['name'] ? $access['name'] . ' (' . $access['pattern'] . ')' : $access['pattern']), $body);
        }
        return $ids;
    }

    /** After an approval or decline, tell each affected tenant: one email per reservation. Returns outbox ids. */
    public static function decisionEmails(array $bookings, bool $approved, string $reason = ''): array
    {
        $ids = [];
        foreach (self::groups($bookings) as $rows) {
            $ids[] = $approved ? self::confirmation($rows[0], true) : self::declined($rows, $reason);
        }
        return array_values(array_filter($ids));
    }

    /** $rows: one reservation's bookings that were just declined. */
    public static function declined(array $rows, string $reason = ''): ?int
    {
        if (!$rows || !self::emailable($rows[0], true)) {
            return null;
        }
        $b = $rows[0];
        $paragraphs = ['Hi ' . Util::firstName($b['name']) . ', building management wasn’t able to approve this reservation request, so the time has been released.'];
        if (trim($reason) !== '') {
            $paragraphs[] = 'Note from management: ' . $reason;
        }
        $paragraphs[] = 'If you think this is a mistake, please reply to this email or contact management.';
        $body = EmailTemplate::render([
            'heading'    => 'Request not approved.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($rows),
        ]);
        return Mailer::queue('declined', $b['email'], $b['name'], 'Not approved: ' . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows), $body, $b['id']);
    }

    /** $rows: the rooms of one date that were released because nobody approved them in time. */
    public static function expired(array $rows): ?int
    {
        if (!$rows || !self::emailable($rows[0], true)) {
            return null;
        }
        $b = $rows[0];
        $paragraphs = [
            'Hi ' . Util::firstName($b['name']) . ', building management didn’t get to your request before it was due to start, so the time has been released.',
            'They’ll still review your email address. Once it’s approved you can book instantly.',
        ];
        $left = count(Bookings::byOccasion(self::inGroup($b, ['pending'])));
        if ($left > 0) {
            $paragraphs[1] = ($left === 1 ? 'Your other date is still held for you' : "Your other $left dates are still held for you")
                . ' and will be confirmed once your email address is approved.';
        }
        $body = EmailTemplate::render([
            'heading'    => 'Request expired.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($rows),
            'buttons'    => ['Make a new reservation' => App::url('')],
        ]);
        return Mailer::queue('expired', $b['email'], $b['name'], 'Request expired: ' . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows), $body, $b['id']);
    }

    /** $rows: the rooms of one date. */
    public static function reminder(array $rows): ?int
    {
        if (!$rows || !self::emailable($rows[0])) {
            return null;
        }
        $b = $rows[0];
        $spaces = Bookings::spacesOf($rows);
        $names = Util::andList(array_column($spaces, 'name'));
        $day = $b['date'] === Time::today() ? 'today' : 'on ' . Time::fmtDate($b['date']);
        $notes = [];
        foreach ($spaces as $space) {
            if (!empty($space['instructions'])) {
                $notes[] = ['title' => 'About the ' . $space['name'], 'body' => $space['instructions']];
            }
        }
        if (Settings::get('building_instructions')) {
            $notes[] = ['title' => 'Building information', 'body' => (string) Settings::get('building_instructions')];
        }
        $body = EmailTemplate::render([
            'preheader'  => "You have the $names $day at " . Time::fmtTime($b['start_min']),
            'heading'    => 'See you soon.',
            'paragraphs' => ["Reminder: You have the $names reserved $day from " . Time::fmtRange($b['start_min'], $b['end_min']) . '.'],
            'details'    => self::details($rows),
            'notes'      => $notes,
            'buttons'    => ['Manage reservation' => self::manageUrl($b)],
            'footer'     => 'Plans changed? Cancel with the button above so someone else can use the space.' . "\n" . EmailTemplate::footer(),
        ]);
        return Mailer::queue('reminder', $b['email'], $b['name'], 'Reminder: ' . self::roomTitle($rows) . " $day at " . Time::fmtTime($b['start_min']), $body, $b['id']);
    }

    /** $rows: the rooms of one date that just ended. */
    public static function followup(array $rows): ?int
    {
        if (!$rows || !self::emailable($rows[0])) {
            return null;
        }
        $b = $rows[0];
        $spaces = Bookings::spacesOf($rows);
        $message = (string) Settings::get('cleanup_message');
        foreach ($spaces as $space) {
            if (!empty($space['cleanup_message'])) {
                $message = $space['cleanup_message'];
                break;
            }
        }
        $names = Util::andList(array_column($spaces, 'name'));
        $body = EmailTemplate::render([
            'preheader'  => 'A quick courtesy note for the next person',
            'heading'    => "Thanks for using the $names.",
            'paragraphs' => array_values(array_filter(array_map('trim', explode("\n\n", $message)))),
            'buttons'    => ['Book again' => App::url('#/space/' . ($spaces[0]['slug'] ?? ''))],
        ]);
        return Mailer::queue('followup', $b['email'], $b['name'], "Thanks for using the $names", $body, $b['id']);
    }

    /** Email someone links to manage all of their upcoming reservations. */
    public static function links(string $email, array $bookings): int
    {
        $rows = [];
        foreach (self::groups($bookings) as $group) {
            $first = self::firstUpcoming($group);
            $rows[] = ['title' => self::roomTitle($group), 'body' => self::whenLines($group) . ($first['title'] ? "\n" . $first['title'] : '') . "\nManage: " . self::manageUrl($first)];
        }
        $body = EmailTemplate::render([
            'heading'    => 'Your upcoming reservations',
            'paragraphs' => ['Here are private links to change or cancel each of your upcoming reservations.'],
            'notes'      => $rows,
            'buttons'    => ['Open the calendar' => App::url('')],
        ]);
        return Mailer::queue('links', $email, null, 'Your upcoming reservations at ' . Settings::get('org_name'), $body);
    }

    /** Copy management on new bookings / cancellations, if turned on in Settings. $rows: one reservation's bookings. */
    public static function admins(array $rows, string $event): array
    {
        $key = $event === 'cancelled' ? 'admin_notify_cancel' : 'admin_notify_new';
        $to = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) Settings::get('admin_notify_emails'))));
        $rows = array_values(array_filter($rows));
        if (!Settings::get($key) || !$to || !$rows) {
            return [];
        }
        $b = $rows[0];
        $details = self::details($rows) + array_filter([
            'Name'    => (string) $b['name'],
            'Email'   => (string) $b['email'],
            'Company' => (string) $b['company'],
            'Notes'   => (string) $b['notes'],
        ]);
        $heading = $event === 'cancelled' ? 'Reservation cancelled' : 'New reservation';
        $body = EmailTemplate::render([
            'heading' => $heading,
            'details' => $details,
            'buttons' => ['Open admin' => App::url('admin/#/booking/' . $b['id'])],
        ]);
        $ids = [];
        foreach ($to as $address) {
            if (Util::isValidEmail($address)) {
                $ids[] = Mailer::queue('admin_' . $event, $address, null, "$heading: " . self::roomTitle($rows) . ' — ' . self::subjectWhen($rows) . ' (' . $b['name'] . ')', $body, $b['id']);
            }
        }
        return $ids;
    }

    /** Bookings as one line per reservation, for approval screens: [['rooms', 'when', 'title'], ...]. */
    public static function summaries(array $bookings): array
    {
        $out = [];
        foreach (self::groups($bookings) as $rows) {
            $out[] = ['rooms' => self::roomTitle($rows), 'when' => self::whenLines($rows), 'title' => (string) $rows[0]['title'], 'space_id' => $rows[0]['space_id']];
        }
        return $out;
    }

    public static function test(string $to): int
    {
        $body = EmailTemplate::render([
            'heading'    => 'Email is working.',
            'paragraphs' => ['This is a test message from the ' . Settings::get('org_name') . ' reservation system. If you can read this, confirmation and reminder emails will be delivered too.'],
        ]);
        return Mailer::queue('test', $to, null, 'Test email from ' . Settings::get('org_name') . ' reservations', $body);
    }

    // ------------------------------------------------------------------ helpers

    private static function emailable(array $b, bool $allowCancelled = false): bool
    {
        return $b['kind'] === 'reservation'
            && $b['email']
            && $b['notify']
            && ($allowCancelled || $b['status'] === 'confirmed');
    }

    /** $b's reservation (every room and date booked with it) in the given statuses, earliest first. */
    private static function inGroup(array $b, array $statuses): array
    {
        return array_values(array_filter(Bookings::groupOf($b), static fn ($r) => in_array($r['status'], $statuses, true)));
    }

    /** Split bookings into reservations (rows booked together). */
    private static function groups(array $bookings): array
    {
        $groups = [];
        foreach (array_filter($bookings) as $b) {
            $groups[$b['series_id'] ? 's' . $b['series_id'] . '|' . $b['email'] : '#' . $b['id']][] = $b;
        }
        foreach ($groups as &$rows) {
            usort($rows, static fn ($x, $y) => [$x['start_utc'], $x['id']] <=> [$y['start_utc'], $y['id']]);
        }
        return array_values($groups);
    }

    /** The first of these bookings that hasn't ended (else the first), for the manage link. */
    private static function firstUpcoming(array $rows): array
    {
        foreach ($rows as $r) {
            if ($r['end_utc'] > Time::nowDb()) {
                return $r;
            }
        }
        return $rows[0];
    }

    public static function manageUrl(array $b): string
    {
        return App::url('manage.php?t=' . $b['manage_token']);
    }

    private static function buttons(array $b, bool $withCalendar): array
    {
        $buttons = ['Manage reservation' => self::manageUrl($b)];
        if ($withCalendar) {
            $buttons['View calendar'] = App::url('#/space/' . (Spaces::find($b['space_id'])['slug'] ?? '') . '/' . $b['date']);
        }
        return $buttons;
    }

    /** "Upstairs Conference Room + Downstairs Conference Room" */
    private static function roomTitle(array $rows): string
    {
        return implode(' + ', array_column(Bookings::spacesOf($rows), 'name'));
    }

    private static function details(array $rows): array
    {
        $spaces = Bookings::spacesOf($rows);
        $occasions = Bookings::byOccasion($rows);
        $first = reset($occasions)[0];
        $lines = array_map(
            static fn ($s) => $s['name'] . (!empty($s['location']) && stripos($s['name'], $s['location']) === false ? (count($spaces) > 1 ? ' (' . $s['location'] . ')' : "\n" . $s['location']) : ''),
            $spaces
        );
        $details = [count($spaces) > 1 ? 'Spaces' : 'Space' => implode("\n", $lines)];
        if (count($occasions) === 1) {
            $details['Date'] = Time::fmtDate($first['date']);
            $details['Time'] = self::timeText($first);
        } else {
            $details['Dates'] = self::dateLines($occasions, !self::sameTime($occasions), !self::sameRooms($occasions));
            if (self::sameTime($occasions)) {
                $details['Time'] = self::timeText($first);
            }
        }
        if ($first['title']) {
            $details['Title'] = $first['title'];
        }
        $details['Reference'] = $first['ref'];
        return $details;
    }

    private static function timeText(array $b): string
    {
        return Time::fmtRange($b['start_min'], $b['end_min']) . ' (' . Time::fmtDuration($b['end_min'] - $b['start_min']) . ')';
    }

    private static function sameTime(array $occasions): bool
    {
        return count(array_unique(array_map(static fn ($o) => $o[0]['start_min'] . '-' . $o[0]['end_min'], $occasions))) === 1;
    }

    private static function sameRooms(array $occasions): bool
    {
        return count(array_unique(array_map(static function ($o) {
            $ids = array_column($o, 'space_id');
            sort($ids);
            return implode(',', $ids);
        }, $occasions))) === 1;
    }

    /** One line per date: "Sat, Nov 7" (+ time and rooms when they differ between dates). */
    private static function dateLines(array $occasions, bool $withTime, bool $withRooms): string
    {
        $lines = [];
        foreach ($occasions as $rows) {
            if (count($lines) === self::MAX_LISTED_DATES) {
                $lines[] = '…and ' . (count($occasions) - self::MAX_LISTED_DATES) . ' more';
                break;
            }
            $b = $rows[0];
            $lines[] = self::shortDate($b['date'])
                . ($withTime ? ' · ' . Time::fmtRange($b['start_min'], $b['end_min']) : '')
                . ($withRooms ? ' · ' . self::roomTitle($rows) : '');
        }
        return implode("\n", $lines);
    }

    /** For notes: the dates (and the time, when every date has the same one). */
    private static function whenLines(array $rows): string
    {
        $occasions = Bookings::byOccasion($rows);
        if (count($occasions) === 1) {
            return self::when($rows);
        }
        $same = self::sameTime($occasions);
        return ($same ? Time::fmtRange($rows[0]['start_min'], $rows[0]['end_min']) . ', ' . count($occasions) . " dates:\n" : '')
            . self::dateLines($occasions, !$same, false);
    }

    /** "Saturday, November 7, 10:00 AM–2:00 PM", or "12 dates from Saturday, November 7" */
    private static function when(array $rows): string
    {
        $occasions = Bookings::byOccasion($rows);
        $b = reset($occasions)[0];
        if (count($occasions) === 1) {
            return Time::fmtDate($b['date']) . ', ' . Time::fmtRange($b['start_min'], $b['end_min']);
        }
        return count($occasions) . ' dates from ' . Time::fmtDate($b['date']);
    }

    /** "Sat, Nov 7, 10:00 AM–2:00 PM", or "Saturdays from Nov 7 (12 dates)" */
    private static function subjectWhen(array $rows): string
    {
        $occasions = Bookings::byOccasion($rows);
        $b = reset($occasions)[0];
        if (count($occasions) === 1) {
            return self::shortDate($b['date']) . ', ' . Time::fmtRange($b['start_min'], $b['end_min']);
        }
        $weekdays = array_unique(array_map(static fn ($o) => Time::weekday($o[0]['date']), $occasions));
        $from = (new DateTimeImmutable($b['date']))->format('M j');
        return count($weekdays) === 1
            ? (new DateTimeImmutable($b['date']))->format('l') . "s from $from (" . count($occasions) . ' dates)'
            : count($occasions) . " dates from $from";
    }

    private static function shortDate(string $date): string
    {
        $d = new DateTimeImmutable($date);
        return $d->format($d->format('Y') === Time::nowLocal()->format('Y') ? 'D, M j' : 'D, M j, Y');
    }
}
