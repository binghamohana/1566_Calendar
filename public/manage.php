<?php
/** Opened from the "Manage reservation" link in emails: manage.php?t=<private token> */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$token = (string) ($_GET['t'] ?? '');
try {
    GPC\Page::publicShell(preg_match('/^[a-f0-9]{48}$/', $token) ? $token : 'invalid');
} catch (Throwable $e) {
    GPC\Page::setupError($e);
}
