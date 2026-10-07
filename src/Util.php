<?php
declare(strict_types=1);

namespace GPC;

final class Util
{
    /** Trim, strip control characters and cap the length of user-supplied text. */
    public static function cleanText(?string $value, int $max, bool $multiline = false): string
    {
        $value = (string) $value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $pattern = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $value = preg_replace($pattern, $multiline ? '' : ' ', $value) ?? '';
        if ($multiline) {
            $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
        }
        $value = trim($value);
        return mb_substr($value, 0, $max);
    }

    public static function nullable(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    public static function token(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Short, unambiguous reference code such as "K7M3QX". */
    public static function ref(int $length = 6): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    public static function sign(string $data): string
    {
        return self::base64url(hash_hmac('sha256', $data, App::secret(), true));
    }

    public static function firstName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        return preg_split('/\s+/u', $name)[0];
    }

    public static function isValidEmail(string $email): bool
    {
        return strlen($email) <= 190
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/@[^.@]+(\.[^.@]+)+$/', $email) === 1;
    }

    public static function emailDomainAllowed(string $email): bool
    {
        $list = trim((string) Settings::get('allowed_email_domains'));
        if ($list === '') {
            return true;
        }
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach (preg_split('/[\s,;]+/', strtolower($list)) as $allowed) {
            $allowed = ltrim($allowed, '@');
            if ($allowed !== '' && ($domain === $allowed || str_ends_with($domain, '.' . $allowed))) {
                return true;
            }
        }
        return false;
    }
}
