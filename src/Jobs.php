<?php
declare(strict_types=1);

namespace GPC;

/** Background work, run every few minutes by cron (bin/cron.php). */
final class Jobs
{
    public static function run(): array
    {
        Settings::set('cron_last_run', Time::nowDb());
        $expired = self::expirePending();
        $reminders = self::queueReminders();
        $followups = self::queueFollowups();
        [$sent, $failed] = Mailer::sendDue(100);
        RateLimit::prune();
        return compact('reminders', 'followups', 'expired', 'sent', 'failed');
    }

    /** Requests nobody approved before their start time are released. */
    public static function expirePending(): int
    {
        $rows = Db::all("SELECT id FROM bookings WHERE status = 'pending' AND start_utc <= ?", [Time::nowDb()]);
        $count = 0;
        $claim = static fn (int $id): bool => (bool) Db::run(
            "UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = 'system', cancel_reason = 'Not approved before the start time', updated_at = ?
              WHERE id = ? AND status = 'pending'",
            [Time::nowDb(), Time::nowDb(), $id]
        )->rowCount();
        foreach ($rows as $row) {
            if (!$claim((int) $row['id'])) {
                continue;
            }
            // Release every room of that date together, with one email.
            $released = [(int) $row['id']];
            foreach (Bookings::occasionOf(Bookings::find((int) $row['id'])) as $r) {
                if ($r['id'] !== (int) $row['id'] && $r['status'] === 'pending' && $claim($r['id'])) {
                    $released[] = $r['id'];
                }
            }
            foreach ($released as $id) {
                Audit::log($id, 'system', 'expired', 'Not approved before the start time');
            }
            Notify::expired(array_map([Bookings::class, 'find'], $released));
            $count += count($released);
        }
        return $count;
    }

    /** Queue "starts in about an hour" reminders. */
    public static function queueReminders(): int
    {
        if (!Settings::get('reminder_enabled')) {
            return 0;
        }
        $minutes = (int) Settings::get('reminder_minutes');
        $now = Time::now();
        $rows = Db::all(
            "SELECT id FROM bookings
              WHERE status = 'confirmed' AND kind = 'reservation' AND notify = 1 AND email IS NOT NULL
                AND reminder_sent_at IS NULL AND start_utc > ? AND start_utc <= ?",
            [$now->format('Y-m-d H:i:s'), $now->modify("+$minutes minutes")->format('Y-m-d H:i:s')]
        );
        $count = 0;
        // Claim first so overlapping cron runs can't double-send.
        $claim = static fn (int $id): bool => (bool) Db::run('UPDATE bookings SET reminder_sent_at = ? WHERE id = ? AND reminder_sent_at IS NULL', [Time::nowDb(), $id])->rowCount();
        foreach ($rows as $row) {
            if (!$claim((int) $row['id'])) {
                continue;
            }
            $b = Bookings::find((int) $row['id']);
            $occasion = array_merge([$b], self::claimSiblings($b, $claim));
            // Booked only moments before it starts? The confirmation is reminder enough.
            $lead = strtotime($b['start_utc'] . ' UTC') - strtotime($b['created_at'] . ' UTC');
            if ($lead < ($minutes + 15) * 60) {
                continue;
            }
            Notify::reminder($occasion);
            $count++;
        }
        return $count;
    }

    /** Queue the after-use "please leave the room as you found it" note. */
    public static function queueFollowups(): int
    {
        if (!Settings::get('followup_enabled')) {
            return 0;
        }
        $offset = (int) Settings::get('followup_offset_minutes');
        $now = Time::now();
        // Send when end + offset has passed, but not for anything that ended long ago (e.g. after an outage).
        $rows = Db::all(
            "SELECT id FROM bookings
              WHERE status = 'confirmed' AND kind = 'reservation' AND notify = 1 AND email IS NOT NULL
                AND followup_sent_at IS NULL AND end_utc <= ? AND end_utc > ? AND created_at < end_utc",
            [
                $now->modify(($offset >= 0 ? '-' : '+') . abs($offset) . ' minutes')->format('Y-m-d H:i:s'),
                $now->modify('-3 hours')->format('Y-m-d H:i:s'),
            ]
        );
        $count = 0;
        $claim = static fn (int $id): bool => (bool) Db::run('UPDATE bookings SET followup_sent_at = ? WHERE id = ? AND followup_sent_at IS NULL', [Time::nowDb(), $id])->rowCount();
        foreach ($rows as $row) {
            if (!$claim((int) $row['id'])) {
                continue;
            }
            $b = Bookings::find((int) $row['id']);
            Notify::followup(array_merge([$b], self::claimSiblings($b, $claim)));
            $count++;
        }
        return $count;
    }

    /**
     * The other rooms booked with $b for the same date and time, claimed with $claim, so one
     * email covers them all. Returns the rows claimed.
     */
    private static function claimSiblings(array $b, callable $claim): array
    {
        if (!$b['series_id']) {
            return [];
        }
        $siblings = [];
        foreach (Bookings::occasionOf($b) as $r) {
            if ($r['id'] !== $b['id'] && $r['status'] === 'confirmed' && $r['notify'] && $claim($r['id'])) {
                $siblings[] = $r;
            }
        }
        return $siblings;
    }

    /** Minutes since cron last ran, or null if it never has. */
    public static function minutesSinceLastRun(): ?int
    {
        $last = (string) Settings::get('cron_last_run');
        if ($last === '') {
            return null;
        }
        return (int) floor((Time::now()->getTimestamp() - strtotime($last . ' UTC')) / 60);
    }
}
