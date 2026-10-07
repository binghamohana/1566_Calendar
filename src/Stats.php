<?php
declare(strict_types=1);

namespace GPC;

/** Numbers for the admin dashboard. */
final class Stats
{
    public static function dashboard(): array
    {
        $today = Time::today();
        $todayDt = new \DateTimeImmutable($today);
        $weekStart = $todayDt->modify('monday this week')->format('Y-m-d');
        $weekEnd = Time::addDays($weekStart, 6);
        $monthStart = $todayDt->modify('first day of this month')->format('Y-m-d');
        $monthEnd = $todayDt->modify('last day of this month')->format('Y-m-d');

        $spaces = Spaces::all();
        $week = self::summarize($weekStart, $weekEnd, $spaces);
        $month = self::summarize($monthStart, $monthEnd, $spaces);

        $now = Time::nowDb();
        $upcoming = array_map(
            static fn ($r) => Bookings::adminView(Bookings::find((int) $r['id'])),
            Db::all("SELECT id FROM bookings WHERE status = 'confirmed' AND kind = 'reservation' AND end_utc > ? ORDER BY start_utc LIMIT 8", [$now])
        );
        $cancellations = array_map(
            static fn ($r) => Bookings::adminView(Bookings::find((int) $r['id'])),
            Db::all("SELECT id FROM bookings WHERE status = 'cancelled' AND kind = 'reservation' ORDER BY cancelled_at DESC LIMIT 6")
        );

        return [
            'today'          => $today,
            'week'           => $week + ['from' => $weekStart, 'to' => $weekEnd],
            'month'          => $month + ['from' => $monthStart, 'to' => $monthEnd, 'label' => $todayDt->format('F')],
            'today_count'    => (int) Db::value(
                "SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND kind = 'reservation' AND start_utc >= ? AND start_utc < ?",
                Time::dayBoundsUtc($today, $today)
            ),
            'upcoming'       => $upcoming,
            'approvals'      => array_values(array_filter(EmailAccess::listAll(), static fn ($r) => $r['status'] === 'pending')),
            'cancellations'  => $cancellations,
            'health'         => self::health(),
        ];
    }

    /** Reservation count, booked hours and per-space hours for a date range. */
    private static function summarize(string $from, string $to, array $spaces): array
    {
        [$fromUtc, $toUtc] = Time::dayBoundsUtc($from, $to);
        $rows = Db::all(
            "SELECT space_id, COUNT(*) AS n, SUM(TIMESTAMPDIFF(MINUTE, start_utc, end_utc)) AS minutes
               FROM bookings
              WHERE status = 'confirmed' AND kind = 'reservation' AND start_utc >= ? AND start_utc < ?
              GROUP BY space_id",
            [$fromUtc, $toUtc]
        );
        $bySpace = [];
        foreach ($spaces as $s) {
            $bySpace[$s['id']] = ['space_id' => $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'count' => 0, 'hours' => 0.0];
        }
        $count = 0;
        $minutes = 0;
        foreach ($rows as $r) {
            $count += (int) $r['n'];
            $minutes += (int) $r['minutes'];
            if (isset($bySpace[$r['space_id']])) {
                $bySpace[$r['space_id']]['count'] = (int) $r['n'];
                $bySpace[$r['space_id']]['hours'] = round((int) $r['minutes'] / 60, 1);
            }
        }
        $bySpace = array_values($bySpace);
        usort($bySpace, static fn ($a, $b) => $b['hours'] <=> $a['hours']);
        return [
            'count'      => $count,
            'hours'      => round($minutes / 60, 1),
            'by_space'   => $bySpace,
            'top_space'  => ($bySpace && $bySpace[0]['hours'] > 0) ? $bySpace[0]['name'] : null,
            'cancelled'  => (int) Db::value(
                "SELECT COUNT(*) FROM bookings WHERE status = 'cancelled' AND kind = 'reservation' AND start_utc >= ? AND start_utc < ?",
                [$fromUtc, $toUtc]
            ),
        ];
    }

    public static function health(): array
    {
        $cron = Jobs::minutesSinceLastRun();
        return [
            'mail_transport'   => App::config('mail.transport'),
            'cron_minutes_ago' => $cron,
            'cron_ok'          => $cron !== null && $cron <= 15,
            'emails_failed'    => (int) Db::value("SELECT COUNT(*) FROM emails WHERE status = 'failed' AND created_at > ?", [Time::now()->modify('-14 days')->format('Y-m-d H:i:s')]),
            'emails_pending'   => (int) Db::value("SELECT COUNT(*) FROM emails WHERE status = 'pending'"),
            'approvals_pending' => EmailAccess::pendingCount(),
        ];
    }
}
