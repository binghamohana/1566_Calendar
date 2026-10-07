<?php
declare(strict_types=1);

namespace GPC;

/**
 * Administrator sign-in using a signed, HttpOnly cookie (no server-side session files to expire
 * or clean up). Changing a password bumps session_version, which signs out every device.
 */
final class Auth
{
    private const COOKIE = 'gpc_admin';
    private const LIFETIME = 14 * 86400;

    private static ?array $admin = null;
    private static bool $loaded = false;

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $ip = Http::ip();
        if (RateLimit::count("login:$ip", 900) >= 10 || RateLimit::count('login:' . $email, 900) >= 10) {
            throw new AppError('Too many sign-in attempts. Please wait 15 minutes and try again.', 429);
        }
        $admin = Db::one('SELECT * FROM admins WHERE email = ? AND is_active = 1', [$email]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            RateLimit::hit("login:$ip", PHP_INT_MAX, 900);
            RateLimit::hit('login:' . $email, PHP_INT_MAX, 900);
            throw new AppError('That email and password don’t match an administrator account.', 401, 'password');
        }
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $admin['id']]);
        }
        Db::update('admins', ['last_login_at' => Time::nowDb()], 'id = :id', ['id' => $admin['id']]);
        RateLimit::clear('login:' . $email);
        self::issueCookie($admin);
        self::$admin = $admin;
        self::$loaded = true;
        return $admin;
    }

    public static function logout(): void
    {
        self::setCookie('', time() - 3600);
        self::$admin = null;
    }

    public static function current(): ?array
    {
        if (self::$loaded) {
            return self::$admin;
        }
        self::$loaded = true;
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        $parts = explode('.', $cookie);
        if (count($parts) !== 2 || !hash_equals(Util::sign('admin|' . $parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode(Util::base64urlDecode($parts[0]), true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return null;
        }
        $admin = Db::one('SELECT * FROM admins WHERE id = ? AND is_active = 1', [(int) ($data['id'] ?? 0)]);
        if (!$admin || (int) $admin['session_version'] !== (int) ($data['v'] ?? -1)) {
            return null;
        }
        return self::$admin = $admin;
    }

    public static function require(): array
    {
        $admin = self::current();
        if (!$admin) {
            throw new AppError('Please sign in.', 401, null, 'unauthenticated');
        }
        return $admin;
    }

    /** Token the admin app sends back in the X-CSRF-Token header on every change. */
    public static function csrfToken(): string
    {
        return Util::sign('csrf|' . ($_COOKIE[self::COOKIE] ?? ''));
    }

    public static function checkCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals(self::csrfToken(), $sent)) {
            throw new AppError('Your session has expired. Please refresh the page.', 403, null, 'csrf');
        }
    }

    public static function issueCookie(array $admin): void
    {
        $payload = Util::base64url(json_encode([
            'id'  => (int) $admin['id'],
            'v'   => (int) $admin['session_version'],
            'exp' => time() + self::LIFETIME,
        ]));
        $value = $payload . '.' . Util::sign('admin|' . $payload);
        self::setCookie($value, time() + self::LIFETIME);
        $_COOKIE[self::COOKIE] = $value; // so csrfToken() matches in this same response
    }

    private static function setCookie(string $value, int $expires): void
    {
        // Scope the cookie to the admin folder (e.g. /book/admin/) so other apps on the domain never see it.
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $path,
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function hashPassword(string $password): string
    {
        if (mb_strlen($password) < 10) {
            throw new AppError('Passwords need at least 10 characters.', 400, 'password');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function createAdmin(string $email, string $name, string $password): int
    {
        $email = strtolower(trim($email));
        if (!Util::isValidEmail($email)) {
            throw new AppError('Please enter a valid email address.', 400, 'email');
        }
        if (Db::value('SELECT id FROM admins WHERE email = ?', [$email])) {
            throw new AppError('An administrator with that email already exists.', 400, 'email');
        }
        $name = Util::cleanText($name, 120);
        return Db::insert('admins', [
            'email'         => $email,
            'name'          => $name !== '' ? $name : $email,
            'password_hash' => self::hashPassword($password),
            'created_at'    => Time::nowDb(),
        ]);
    }

    public static function setPassword(int $adminId, string $password): void
    {
        Db::run(
            'UPDATE admins SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
            [self::hashPassword($password), $adminId]
        );
    }
}
