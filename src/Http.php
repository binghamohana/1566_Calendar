<?php
declare(strict_types=1);

namespace GPC;

final class Http
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return App::config('trust_proxy_headers')
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** JSON body (or form fields) as an array. */
    public static function input(): array
    {
        static $input = null;
        if ($input === null) {
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($type, 'application/json')) {
                $raw = file_get_contents('php://input', false, null, 0, 1_000_000);
                $decoded = json_decode((string) $raw, true);
                $input = is_array($decoded) ? $decoded : [];
            } else {
                $input = $_POST;
            }
        }
        return $input;
    }

    public static function ip(): string
    {
        if (App::config('trust_proxy_headers')) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = trim(explode(',', $_SERVER[$header])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Send the JSON response and close the connection, so slow work (sending email)
     * can continue without making the visitor wait.
     */
    public static function jsonAndFinish($data, int $status = 200): void
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        ignore_user_abort(true);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1'); // compression would buffer the response
        }
        header('Content-Length: ' . strlen((string) $body));
        header('Connection: close');
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    /** Wrap an API entry point: converts errors into JSON responses. */
    public static function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (AppError $e) {
            self::json(array_merge([
                'ok'    => false,
                'error' => $e->getMessage(),
                'field' => $e->field,
                'code'  => $e->errorCode,
            ], $e->extra), $e->status);
        } catch (\Throwable $e) {
            App::log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            self::json([
                'ok'    => false,
                'error' => App::config('debug') ? $e->getMessage() : 'Something went wrong on our end. Please try again in a moment.',
                'code'  => 'server_error',
            ], 500);
        }
    }
}
