<?php
declare(strict_types=1);

namespace GPC;

/** Shared HTML shell for the public pages (booking page and manage-reservation page). */
final class Page
{
    public static function asset(string $path): string
    {
        $file = GPC_ROOT . '/public/' . $path;
        return $path . '?v=' . (is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1');
    }

    public static function publicShell(?string $manageToken = null): void
    {
        Http::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        if ($manageToken !== null) {
            header('X-Robots-Tag: noindex, nofollow');
        }

        $s = Settings::all();
        $boot = [
            'org_name'      => $s['org_name'],
            'tagline'       => $s['tagline'],
            'intro'         => $s['intro'],
            'contact_email' => $s['contact_email'],
            'contact_phone' => $s['contact_phone'],
            'timezone'      => $s['timezone'],
            'base_url'      => App::baseUrl(),
            'today'         => Time::today(),
            'now_min'       => (int) Time::nowLocal()->format('G') * 60 + (int) Time::nowLocal()->format('i'),
            'form_token'    => FormToken::issue(),
            'rules'         => [
                'time_increment'       => (int) $s['time_increment'],
                'min_duration_minutes' => $s['min_duration_minutes'],
                'max_duration_minutes' => $s['max_duration_minutes'],
                'max_days_ahead'       => $s['max_days_ahead'],
                'require_approval'     => $s['require_approval'],
            ],
            'spaces'        => array_map([Spaces::class, 'publicView'], Spaces::all(true)),
            'manage_token'  => $manageToken,
            'logo'          => self::asset('assets/img/brand/logo.svg'),
        ];
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $title = $manageToken ? 'Your reservation' : $s['tagline'];
        $contact = array_filter([$s['contact_email'], $s['contact_phone']]);
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($title . ' · ' . $s['org_name']) ?></title>
<meta name="description" content="<?= $e($s['intro']) ?>">
<meta name="theme-color" content="#F6F3EE">
<meta name="format-detection" content="telephone=no">
<?= self::headIcons() ?>
<link rel="preload" href="assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/instrument-serif-latin-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $e(self::asset('assets/css/gpc.css')) ?>">
</head>
<body class="public">
<header class="topbar">
  <a class="brand" href="./#/"><?= self::lockup($s['org_name']) ?></a>
  <nav class="topnav" aria-label="Main">
    <a href="./#/" data-nav="home" class="keep">Spaces</a>
    <a href="./#/calendar/<?= $e(Time::today()) ?>" data-nav="calendar" class="keep">Calendar</a>
    <button type="button" class="js-find hide-sm">My reservations</button>
  </nav>
</header>
<main id="app" aria-live="polite"></main>
<footer class="site-footer">
  <div class="wrap">
    <span><?= $e($s['org_name']) ?> · Atlanta</span>
    <span>
      <?php if ($contact): ?>Questions? <?= $e(implode(' · ', $contact)) ?> · <?php endif; ?>
      <a href="#" class="js-find">Find my reservations</a>
    </span>
  </div>
</footer>
<noscript><div class="wrap"><div class="alert alert-warn">Please turn on JavaScript to reserve a space.</div></div></noscript>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= $e(self::asset('assets/vendor/jquery-3.7.1.min.js')) ?>"></script>
<script src="<?= $e(self::asset('assets/js/common.js')) ?>"></script>
<script src="<?= $e(self::asset('assets/js/calendar.js')) ?>"></script>
<script src="<?= $e(self::asset('assets/js/booking-form.js')) ?>"></script>
<script src="<?= $e(self::asset('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
    }

    /** Favicon + home-screen icon tags. $prefix is "../" for pages in subfolders (admin). */
    public static function headIcons(string $prefix = ''): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return '<link rel="icon" href="' . $e($prefix . self::asset('assets/img/brand/favicon.svg')) . '" type="image/svg+xml">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $e($prefix . self::asset('assets/img/brand/apple-touch-icon.png')) . '">';
    }

    /** The horizontal logo (tree + GROVE PARK / COLLECTIVE) used in headers. */
    public static function lockup(string $alt, string $prefix = '', bool $white = false): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $file = $white ? 'assets/img/brand/lockup-white.svg' : 'assets/img/brand/lockup.svg';
        return '<img class="brand-lockup" src="' . $e($prefix . self::asset($file)) . '" alt="' . $e($alt) . '" width="132" height="40">';
    }

    public static function setupError(\Throwable $e): void
    {
        App::log('Page error: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable</title>'
            . '<body style="font-family:system-ui,sans-serif;background:#F6F3EE;color:#1D2420;display:grid;place-items:center;min-height:100vh;margin:0">'
            . '<div style="max-width:420px;padding:24px;text-align:center"><h1 style="font-weight:500">Reservations are temporarily unavailable</h1>'
            . '<p>Please try again in a few minutes. If this keeps happening, let building management know.</p>'
            . (App::config('debug') ? '<pre style="text-align:left;white-space:pre-wrap;font-size:12px">' . htmlspecialchars($e->getMessage()) . '</pre>' : '')
            . '</div></body>';
    }
}
