<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use GPC\Page;
use GPC\Settings;

try {
    GPC\Http::securityHeaders();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $org = (string) Settings::get('org_name');
    $timezone = (string) Settings::get('timezone');
} catch (Throwable $e) {
    Page::setupError($e);
    return;
}
$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$a = static fn ($path) => '../' . Page::asset($path);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Admin · <?= $e($org) ?></title>
<link rel="icon" href="<?= $e($a('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= $e($a('assets/css/gpc.css')) ?>">
<link rel="stylesheet" href="<?= $e($a('assets/css/admin.css')) ?>">
</head>
<body class="admin">
<div id="admin-app">
  <div class="admin-loading"><span class="spinner"></span></div>
</div>
<script type="application/json" id="boot"><?= json_encode(['org_name' => $org, 'timezone' => $timezone, 'brand_html' => Page::brandName($org)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= $e($a('assets/vendor/jquery-3.7.1.min.js')) ?>"></script>
<script src="<?= $e($a('assets/js/common.js')) ?>"></script>
<script src="<?= $e($a('assets/js/calendar.js')) ?>"></script>
<script src="<?= $e($a('assets/js/admin.js')) ?>"></script>
</body>
</html>
