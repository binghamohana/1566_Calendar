<?php
declare(strict_types=1);

namespace GPC;

/** The words in every automated email. Each method queues a message and returns its outbox id. */
final class Notify
{
    public static function confirmation(array $b, int $seriesCount = 1, bool $justApproved = false): ?int
    {
        if (!self::emailable($b)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $paragraphs = ['Hi ' . Util::firstName($b['name']) . ', your reservation is confirmed. Here are the details:'];
        if ($justApproved) {
            $paragraphs[0] = 'Hi ' . Util::firstName($b['name']) . ', good news — building management approved your email address, so your reservation is confirmed. From now on, your reservations are confirmed instantly.';
        }
        if ($seriesCount > 1) {
            $paragraphs[] = "This is a repeating reservation ($seriesCount dates). The first one is below — you’ll get a reminder before each.";
        }
        $body = EmailTemplate::render([
            'preheader'  => $space['name'] . ' · ' . self::when($b),
            'heading'    => 'Your reservation is confirmed.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($b, $space),
            'notes'      => [[
                'title' => 'Need to make a change?',
                'body'  => 'Use “Manage reservation” to change the time, switch rooms or cancel — no password needed. '
                    . 'Keep this email: the button is your private link to this reservation.',
            ]],
            'buttons'    => self::buttons($b, true),
        ]);
        return Mailer::queue('confirmation', $b['email'], $b['name'], 'Confirmed: ' . $space['name'] . ' — ' . self::shortWhen($b), $body, $b['id'], Ics::forBooking($b, $space));
    }

    public static function updated(array $b): ?int
    {
        if (!self::emailable($b)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $body = EmailTemplate::render([
            'preheader'  => 'Now: ' . $space['name'] . ' · ' . self::when($b),
            'heading'    => 'Your reservation has been updated.',
            'paragraphs' => ['Hi ' . Util::firstName($b['name']) . ', here are the new details. The old time has been released for others.'],
            'details'    => self::details($b, $space),
            'buttons'    => self::buttons($b, true),
        ]);
        return Mailer::queue('updated', $b['email'], $b['name'], 'Updated: ' . $space['name'] . ' — ' . self::shortWhen($b), $body, $b['id'], Ics::forBooking($b, $space));
    }

    public static function cancelled(array $b, bool $byManagement = false, int $seriesCount = 1): ?int
    {
        if (!self::emailable($b, true)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $paragraphs = [
            $byManagement
                ? 'Hi ' . Util::firstName($b['name']) . ', Grove Park Collective management has cancelled the reservation below.'
                : 'Hi ' . Util::firstName($b['name']) . ', your reservation has been cancelled and the time is open for others again.',
        ];
        if ($seriesCount > 1) {
            $paragraphs[] = "This also cancels the following $seriesCount dates in this repeating reservation.";
        }
        if (!empty($b['cancel_reason'])) {
            $paragraphs[] = 'Note from management: ' . $b['cancel_reason'];
        }
        $body = EmailTemplate::render([
            'preheader'  => $space['name'] . ' · ' . self::when($b),
            'heading'    => 'Reservation cancelled.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($b, $space),
            'buttons'    => ['Make a new reservation' => App::url('')],
        ]);
        return Mailer::queue('cancelled', $b['email'], $b['name'], 'Cancelled: ' . $space['name'] . ' — ' . self::shortWhen($b), $body, $b['id']);
    }

    /** To the tenant: their request is held while the building manager approves their email. */
    public static function pendingReceived(array $b): ?int
    {
        if (!self::emailable($b, true) || $b['status'] !== 'pending') {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $body = EmailTemplate::render([
            'preheader'  => 'Awaiting approval · ' . $space['name'] . ' · ' . self::when($b),
            'heading'    => 'Request received.',
            'paragraphs' => [
                'Hi ' . Util::firstName($b['name']) . ', thanks for your reservation request.',
                Settings::get('org_name') . ' approves each new email address once. We’ve asked building management to approve '
                    . $b['email'] . ' — you’ll get a confirmation email as soon as they do. Your time is held for you until then.',
            ],
            'details'    => self::details($b, $space) + ['Status' => 'Awaiting approval'],
            'buttons'    => ['View or cancel request' => self::manageUrl($b)],
        ]);
        return Mailer::queue('pending', $b['email'], $b['name'], 'Request received: ' . $space['name'] . ' — ' . self::shortWhen($b), $body, $b['id']);
    }

    /** To the building manager: a new email address wants to book. */
    public static function approvalRequest(array $access): array
    {
        $bookings = EmailAccess::pendingBookings($access['pattern']);
        $notes = [];
        foreach ($bookings as $b) {
            $space = Spaces::find($b['space_id']);
            $notes[] = ['title' => $space['name'], 'body' => self::when($b) . ($b['title'] ? "\n" . $b['title'] : '')];
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

    /** After an approval or decline, tell each affected tenant. Returns outbox ids. */
    public static function decisionEmails(array $bookings, bool $approved, string $reason = ''): array
    {
        $ids = [];
        foreach ($bookings as $b) {
            $ids[] = $approved ? self::confirmation($b, 1, true) : self::declined($b, $reason);
        }
        return array_filter($ids);
    }

    public static function declined(array $b, string $reason = ''): ?int
    {
        if (!self::emailable($b, true)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $paragraphs = ['Hi ' . Util::firstName($b['name']) . ', building management wasn’t able to approve this reservation request, so the time has been released.'];
        if (trim($reason) !== '') {
            $paragraphs[] = 'Note from management: ' . $reason;
        }
        $paragraphs[] = 'If you think this is a mistake, please reply to this email or contact management.';
        $body = EmailTemplate::render([
            'heading'    => 'Request not approved.',
            'paragraphs' => $paragraphs,
            'details'    => self::details($b, $space),
        ]);
        return Mailer::queue('declined', $b['email'], $b['name'], 'Not approved: ' . $space['name'] . ' — ' . self::shortWhen($b), $body, $b['id']);
    }

    public static function expired(array $b): ?int
    {
        if (!self::emailable($b, true)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $body = EmailTemplate::render([
            'heading'    => 'Request expired.',
            'paragraphs' => [
                'Hi ' . Util::firstName($b['name']) . ', building management didn’t get to your request before it was due to start, so the time has been released.',
                'They’ll still review your email address. Once it’s approved you can book instantly.',
            ],
            'details'    => self::details($b, $space),
            'buttons'    => ['Make a new reservation' => App::url('')],
        ]);
        return Mailer::queue('expired', $b['email'], $b['name'], 'Request expired: ' . $space['name'] . ' — ' . self::shortWhen($b), $body, $b['id']);
    }

    public static function reminder(array $b): ?int
    {
        if (!self::emailable($b)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $day = $b['date'] === Time::today() ? 'today' : 'on ' . Time::fmtDate($b['date']);
        $notes = [];
        if ($space['instructions']) {
            $notes[] = ['title' => 'About the ' . $space['name'], 'body' => $space['instructions']];
        }
        if (Settings::get('building_instructions')) {
            $notes[] = ['title' => 'Building information', 'body' => (string) Settings::get('building_instructions')];
        }
        $body = EmailTemplate::render([
            'preheader'  => "You have the {$space['name']} $day at " . Time::fmtTime($b['start_min']),
            'heading'    => 'See you soon.',
            'paragraphs' => ["Reminder: You have the {$space['name']} reserved $day from " . Time::fmtRange($b['start_min'], $b['end_min']) . '.'],
            'details'    => self::details($b, $space),
            'notes'      => $notes,
            'buttons'    => ['Manage reservation' => self::manageUrl($b)],
            'footer'     => 'Plans changed? Cancel with the button above so someone else can use the space.' . "\n" . EmailTemplate::footer(),
        ]);
        return Mailer::queue('reminder', $b['email'], $b['name'], "Reminder: {$space['name']} $day at " . Time::fmtTime($b['start_min']), $body, $b['id']);
    }

    public static function followup(array $b): ?int
    {
        if (!self::emailable($b)) {
            return null;
        }
        $space = Spaces::find($b['space_id']);
        $message = $space['cleanup_message'] ?: (string) Settings::get('cleanup_message');
        $body = EmailTemplate::render([
            'preheader'  => 'A quick courtesy note for the next person',
            'heading'    => "Thanks for using the {$space['name']}.",
            'paragraphs' => array_values(array_filter(array_map('trim', explode("\n\n", $message)))),
            'buttons'    => ['Book again' => App::url('#/space/' . $space['slug'])],
        ]);
        return Mailer::queue('followup', $b['email'], $b['name'], "Thanks for using the {$space['name']}", $body, $b['id']);
    }

    /** Email someone links to manage all of their upcoming reservations. */
    public static function links(string $email, array $bookings): int
    {
        $rows = [];
        $buttons = [];
        foreach ($bookings as $b) {
            $space = Spaces::find($b['space_id']);
            $rows[] = ['title' => $space['name'], 'body' => self::when($b) . ($b['title'] ? "\n" . $b['title'] : '') . "\nManage: " . self::manageUrl($b)];
        }
        $buttons['Open the calendar'] = App::url('');
        $body = EmailTemplate::render([
            'heading'    => 'Your upcoming reservations',
            'paragraphs' => ['Here are private links to change or cancel each of your upcoming reservations.'],
            'notes'      => $rows,
            'buttons'    => $buttons,
        ]);
        return Mailer::queue('links', $email, null, 'Your upcoming reservations at ' . Settings::get('org_name'), $body);
    }

    /** Copy management on new bookings / cancellations, if turned on in Settings. */
    public static function admins(array $b, string $event): array
    {
        $key = $event === 'cancelled' ? 'admin_notify_cancel' : 'admin_notify_new';
        $to = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) Settings::get('admin_notify_emails'))));
        if (!Settings::get($key) || !$to) {
            return [];
        }
        $space = Spaces::find($b['space_id']);
        $details = self::details($b, $space) + array_filter([
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
                $ids[] = Mailer::queue('admin_' . $event, $address, null, "$heading: {$space['name']} — " . self::shortWhen($b) . ' (' . $b['name'] . ')', $body, $b['id']);
            }
        }
        return $ids;
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

    private static function details(array $b, array $space): array
    {
        $details = [
            'Space' => $space['name'] . ($space['location'] && stripos($space['name'], $space['location']) === false ? "\n" . $space['location'] : ''),
            'Date'  => Time::fmtDate($b['date']),
            'Time'  => Time::fmtRange($b['start_min'], $b['end_min']) . ' (' . Time::fmtDuration($b['end_min'] - $b['start_min']) . ')',
        ];
        if ($b['title']) {
            $details['Title'] = $b['title'];
        }
        $details['Reference'] = $b['ref'];
        return $details;
    }

    private static function when(array $b): string
    {
        return Time::fmtDate($b['date']) . ', ' . Time::fmtRange($b['start_min'], $b['end_min']);
    }

    private static function shortWhen(array $b): string
    {
        return (new \DateTimeImmutable($b['date']))->format('D, M j') . ', ' . Time::fmtRange($b['start_min'], $b['end_min']);
    }
}
