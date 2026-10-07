<?php
declare(strict_types=1);

namespace GPC;

/** Sliding-window counters stored in MySQL; old rows are pruned by the cron job. */
final class RateLimit
{
    /** Record a hit; returns false (without recording) when the bucket is already full. */
    public static function hit(string $bucket, int $max, int $windowSeconds): bool
    {
        if (self::count($bucket, $windowSeconds) >= $max) {
            return false;
        }
        Db::insert('rate_events', ['bucket' => mb_substr($bucket, 0, 190), 'created_at' => Time::nowDb()]);
        return true;
    }

    public static function count(string $bucket, int $windowSeconds): int
    {
        $since = Time::now()->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        return (int) Db::value(
            'SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at > ?',
            [mb_substr($bucket, 0, 190), $since]
        );
    }

    public static function clear(string $bucket): void
    {
        Db::run('DELETE FROM rate_events WHERE bucket = ?', [$bucket]);
    }

    public static function prune(): void
    {
        Db::run('DELETE FROM rate_events WHERE created_at < ?', [Time::now()->modify('-2 days')->format('Y-m-d H:i:s')]);
    }
}
