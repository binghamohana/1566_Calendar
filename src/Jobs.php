<?php
declare(strict_types=1);

namespace GPC;

/** Background work, run every few minutes by cron (bin/cron.php). */
final class Jobs
{
    public static function run(): array
    {
        Settings::set('cron_last_run', Time::nowDb());
        $reminders = self::queueReminders();
        $followups = self::queueFollowups();
        [$sent, $failed] = Mailer::sendDue(100);
        RateLimit::prune();
        return compact('reminders', 'followups', 'sent', 'failed');
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
        foreach ($rows as $row) {
            // Claim first so overlapping cron runs can't double-send.
            if (!Db::run('UPDATE bookings SET reminder_sent_at = ? WHERE id = ? AND reminder_sent_at IS NULL', [Time::nowDb(), $row['id']])->rowCount()) {
                continue;
            }
            $b = Bookings::find((int) $row['id']);
            // Booked only moments before it starts? The confirmation is reminder enough.
            $lead = strtotime($b['start_utc'] . ' UTC') - strtotime($b['created_at'] . ' UTC');
            if ($lead < ($minutes + 15) * 60) {
                continue;
            }
            Notify::reminder($b);
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
        foreach ($rows as $row) {
            if (!Db::run('UPDATE bookings SET followup_sent_at = ? WHERE id = ? AND followup_sent_at IS NULL', [Time::nowDb(), $row['id']])->rowCount()) {
                continue;
            }
            Notify::followup(Bookings::find((int) $row['id']));
            $count++;
        }
        return $count;
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
