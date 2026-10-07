<?php
declare(strict_types=1);

namespace GPC;

/**
 * Lightweight bot check for the public form, invisible to people:
 * the page hands out a signed timestamp, and a booking must arrive at least a couple of
 * seconds after the page loaded (scripts that post directly don't have a valid token).
 * Paired with a hidden "website" honeypot field that people never fill in.
 */
final class FormToken
{
    private const MIN_SECONDS = 2;
    private const MAX_AGE = 14 * 86400;

    public static function issue(): string
    {
        $t = (string) time();
        return $t . '.' . Util::sign('form|' . $t);
    }

    public static function check(array $in): void
    {
        if (trim((string) ($in['website'] ?? '')) !== '') {
            throw new AppError('Your reservation could not be submitted.', 400, null, 'spam');
        }
        $parts = explode('.', (string) ($in['form_token'] ?? ''));
        $valid = count($parts) === 2 && ctype_digit($parts[0]) && hash_equals(Util::sign('form|' . $parts[0]), $parts[1]);
        $age = $valid ? time() - (int) $parts[0] : -1;
        if (!$valid || $age > self::MAX_AGE) {
            throw new AppError('This page has been open for a while. Please refresh and try again.', 400, null, 'stale_form');
        }
        if ($age < self::MIN_SECONDS) {
            throw new AppError('That was quick! Please wait a moment and submit again.', 400, null, 'too_fast');
        }
    }
}
