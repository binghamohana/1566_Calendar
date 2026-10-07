<?php
declare(strict_types=1);

namespace GPC;

/** iCalendar (.ics) output: "Add to calendar" files and subscribable feeds for staff. */
final class Ics
{
    public static function forBooking(array $b, array $space): string
    {
        $summary = ($b['title'] ? $b['title'] . ' — ' : '') . $space['name'];
        $description = 'Reservation ' . $b['ref'] . ' at ' . Settings::get('org_name') . '.';
        if ($b['manage_token']) {
            $description .= "\nChange or cancel: " . App::url('manage.php?t=' . $b['manage_token']);
        }
        return self::wrap([self::event($b, $summary, $space['name'], $description)], 'PUBLISH');
    }

    /** Feed for Google Calendar / Outlook subscriptions. Includes names, so it is protected by a secret key. */
    public static function feed(array $bookings, array $spacesById, string $calendarName): string
    {
        $events = [];
        foreach ($bookings as $b) {
            $space = $spacesById[$b['space_id']] ?? ['name' => 'Space'];
            if ($b['kind'] === 'block') {
                $summary = 'Blocked' . ($b['title'] ? ': ' . $b['title'] : '');
                $description = 'Blocked by management.';
            } else {
                $who = trim(($b['name'] ?? '') . ($b['company'] ? ' (' . $b['company'] . ')' : ''));
                $summary = ($b['title'] ? $b['title'] . ' — ' : '') . ($who ?: 'Reserved');
                $description = "Reserved by $who" . ($b['email'] ? " <{$b['email']}>" : '') . "\nRef " . $b['ref']
                    . ($b['notes'] ? "\nNotes: " . $b['notes'] : '');
            }
            if ($b['status'] === 'pending') {
                $summary = 'Pending approval: ' . $summary;
            }
            if (count($spacesById) > 1) {
                $summary = '[' . ($space['short_name'] ?: $space['name']) . '] ' . $summary;
            }
            $events[] = self::event($b, $summary, $space['name'], $description);
        }
        return self::wrap($events, 'PUBLISH', $calendarName);
    }

    private static function event(array $b, string $summary, string $location, string $description): array
    {
        $fmt = static fn (string $utc) => (new \DateTimeImmutable($utc, Time::utc()))->format('Ymd\THis\Z');
        $host = parse_url(App::baseUrl(), PHP_URL_HOST) ?: 'groveparkcollective.local';
        return [
            'BEGIN:VEVENT',
            'UID:' . $b['ref'] . '@' . $host,
            'DTSTAMP:' . $fmt($b['updated_at']),
            'SEQUENCE:' . max(0, intdiv(strtotime($b['updated_at'] . ' UTC') - strtotime($b['created_at'] . ' UTC'), 60)),
            'DTSTART:' . $fmt($b['start_utc']),
            'DTEND:' . $fmt($b['end_utc']),
            'SUMMARY:' . self::escape($summary),
            'LOCATION:' . self::escape($location . ', ' . Settings::get('org_name')),
            'DESCRIPTION:' . self::escape($description),
            'STATUS:' . (['cancelled' => 'CANCELLED', 'pending' => 'TENTATIVE'][$b['status']] ?? 'CONFIRMED'),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    private static function wrap(array $events, string $method, ?string $name = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Grove Park Collective//Reservations//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
        ];
        if ($name !== null) {
            $lines[] = 'X-WR-CALNAME:' . self::escape($name);
            $lines[] = 'X-WR-TIMEZONE:' . Settings::get('timezone');
            $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT15M';
            $lines[] = 'X-PUBLISHED-TTL:PT15M';
        }
        foreach ($events as $event) {
            array_push($lines, ...$event);
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets must be folded (RFC 5545 §3.1). */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }
        return $out . "\r\n " . $current;
    }
}
