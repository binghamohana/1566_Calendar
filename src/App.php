<?php
declare(strict_types=1);

namespace GPC;

final class App
{
    private static array $config = [];

    public static function boot(): void
    {
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        $file = GPC_ROOT . '/config/config.php';
        if (!is_file($file)) {
            // Tests (and servers configured purely through environment variables) use the sample.
            $file = GPC_ROOT . '/config/config.sample.php';
        }
        self::$config = require $file;

        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        if (is_writable(GPC_ROOT . '/storage/logs')) {
            ini_set('error_log', GPC_ROOT . '/storage/logs/php-error.log');
        }
    }

    /** Read a config value with dot notation, e.g. App::config('db.host'). */
    public static function config(string $key, $default = null)
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Override config at runtime (tests only). */
    public static function setConfig(string $key, $value): void
    {
        $ref = &self::$config;
        foreach (explode('.', $key) as $part) {
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function secret(): string
    {
        $secret = (string) self::config('app_secret', '');
        if ($secret === '' || str_starts_with($secret, 'CHANGE-ME')) {
            if (PHP_SAPI !== 'cli' && !self::config('debug')) {
                throw new \RuntimeException('app_secret is not configured in config/config.php');
            }
            return 'insecure-development-secret';
        }
        return $secret;
    }

    public static function baseUrl(): string
    {
        return rtrim((string) self::config('base_url', ''), '/');
    }

    public static function url(string $path = ''): string
    {
        return self::baseUrl() . '/' . ltrim($path, '/');
    }

    public static function log(string $message): void
    {
        error_log('[gpc] ' . $message);
    }
}
