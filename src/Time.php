<?php
declare(strict_types=1);

namespace GPC;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Times are stored in UTC and shown in the building's local time zone.
 * The API speaks local wall-clock time ("2026-10-13", "14:30") so the calendar
 * always reflects the building, whatever time zone a visitor's phone is set to.
 */
final class Time
{
    /** Tests can freeze "now" by setting this. */
    public static ?DateTimeImmutable $frozenNow = null;

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone((string) (Settings::get('timezone') ?: 'America/New_York'));
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozenNow ?? new DateTimeImmutable('now', self::utc());
    }

    public static function nowDb(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function nowLocal(): DateTimeImmutable
    {
        return self::now()->setTimezone(self::tz());
    }

    public static function today(): string
    {
        return self::nowLocal()->format('Y-m-d');
    }

    public static function isDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** "14:30" → 870. Accepts "24:00" as 1440. Returns null when invalid. */
    public static function toMinutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($i > 59 || $h > 24 || ($h === 24 && $i !== 0)) {
            return null;
        }
        return $h * 60 + $i;
    }

    public static function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Local date + minutes after midnight → UTC DB string. */
    public static function localToUtc(string $date, int $minutes): string
    {
        $local = new DateTimeImmutable($date . ' 00:00:00', self::tz());
        // Set the wall-clock time (not "+N minutes"), so DST days still match what the person picked.
        $local = $minutes >= 1440
            ? $local->modify('+1 day')
            : $local->setTime(intdiv($minutes, 60), $minutes % 60);
        return $local->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    public static function utcToLocal(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, self::utc()))->setTimezone(self::tz());
    }

    /** UTC [start, end) bounds for a range of local dates (inclusive). */
    public static function dayBoundsUtc(string $fromDate, string $toDate): array
    {
        return [self::localToUtc($fromDate, 0), self::localToUtc($toDate, 1440)];
    }

    /**
     * Local presentation of a stored booking: date, HH:MM start/end and minutes.
     * A booking ending at midnight reports end "24:00" on its own date.
     */
    public static function localParts(string $startUtc, string $endUtc): array
    {
        $start = self::utcToLocal($startUtc);
        $end = self::utcToLocal($endUtc);
        $date = $start->format('Y-m-d');
        $startMin = (int) $start->format('G') * 60 + (int) $start->format('i');
        $endMin = (int) $end->format('G') * 60 + (int) $end->format('i');
        if ($end->format('Y-m-d') !== $date) {
            $endMin = 1440;
        }
        return [
            'date'      => $date,
            'start'     => self::fromMinutes($startMin),
            'end'       => self::fromMinutes($endMin),
            'start_min' => $startMin,
            'end_min'   => $endMin,
        ];
    }

    public static function weekday(string $date): int
    {
        return (int) (new DateTimeImmutable($date))->format('w'); // 0 = Sunday
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    // ---- Human formatting (emails, admin) ----

    public static function fmtTime(int $minutes): string
    {
        $minutes %= 1440;
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        return $m === 0 ? "$h12:00 $suffix" : sprintf('%d:%02d %s', $h12, $m, $suffix);
    }

    public static function fmtRange(int $start, int $end): string
    {
        return self::fmtTime($start) . '–' . self::fmtTime($end);
    }

    public static function fmtDate(string $date, bool $withYear = false): string
    {
        $d = new DateTimeImmutable($date);
        $sameYear = $d->format('Y') === self::nowLocal()->format('Y');
        return $d->format(($withYear || !$sameYear) ? 'l, F j, Y' : 'l, F j');
    }

    public static function fmtDuration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $parts = [];
        if ($h) {
            $parts[] = $h . ' hr';
        }
        if ($m) {
            $parts[] = $m . ' min';
        }
        return implode(' ', $parts) ?: '0 min';
    }
}
