<?php
/**
 * Grove Park Collective — Room Reservations configuration.
 *
 * Copy this file to config/config.php and fill it in. config.php is ignored by git
 * so credentials never end up in the repository.
 *
 * Values can also come from environment variables (GPC_DB_HOST etc.), but on a
 * standard LAMP server simply edit the values below.
 */

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

return [
    // Public address of the app, no trailing slash. Used for links inside emails.
    // e.g. 'https://www.58dwell.com/book' or 'https://reserve.groveparkcollective.com'
    'base_url' => $env('GPC_BASE_URL', 'http://localhost:8080'),

    // Long random string used to sign admin logins and form tokens.
    // Generate one with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Changing it signs every administrator out.
    'app_secret' => $env('GPC_APP_SECRET', 'CHANGE-ME-to-a-long-random-string'),

    // Show detailed PHP errors in API responses. Keep false in production.
    'debug' => (bool) $env('GPC_DEBUG', false),

    'db' => [
        'host'     => $env('GPC_DB_HOST', 'localhost'),
        'port'     => (int) $env('GPC_DB_PORT', 3306),
        'name'     => $env('GPC_DB_NAME', 'gpc_reserve'),
        'user'     => $env('GPC_DB_USER', 'gpc_reserve'),
        'password' => $env('GPC_DB_PASSWORD', ''),
    ],

    'mail' => [
        // Sent with PHPMailer (bundled in lib/PHPMailer).
        // 'smtp' (recommended), 'mail' (PHP mail()/sendmail) or 'log' (write to storage/logs/mail.log, for testing)
        'transport'  => $env('GPC_MAIL_TRANSPORT', 'log'),
        'host'       => $env('GPC_SMTP_HOST', 'smtp-relay.gmail.com'),
        'port'       => (int) $env('GPC_SMTP_PORT', 587),
        'encryption' => $env('GPC_SMTP_ENCRYPTION', 'tls'),   // 'tls' (STARTTLS, port 587), 'ssl' (port 465) or ''
        'username'   => $env('GPC_SMTP_USERNAME', ''),
        'password'   => $env('GPC_SMTP_PASSWORD', ''),
        'from_email' => $env('GPC_MAIL_FROM', 'reservations@groveparkcollective.com'),
        'from_name'  => $env('GPC_MAIL_FROM_NAME', 'Grove Park Collective'),
    ],

    // Set to true only when the app sits behind a reverse proxy / tunnel you control
    // (Cloudflare, a load balancer). Lets rate limiting see real visitor IPs.
    'trust_proxy_headers' => (bool) $env('GPC_TRUST_PROXY', false),
];
