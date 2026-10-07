<?php
declare(strict_types=1);

namespace GPC;

final class Audit
{
    public static function log(?int $bookingId, string $actor, string $action, array|string|null $details = null): void
    {
        Db::insert('audit_log', [
            'booking_id' => $bookingId,
            'actor'      => mb_substr($actor, 0, 190),
            'action'     => $action,
            'details'    => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            'ip'         => PHP_SAPI === 'cli' ? null : Http::ip(),
            'created_at' => Time::nowDb(),
        ]);
    }

    public static function forBooking(int $bookingId): array
    {
        return Db::all('SELECT actor, action, details, created_at FROM audit_log WHERE booking_id = ? ORDER BY id', [$bookingId]);
    }
}
