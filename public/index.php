<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    GPC\Page::publicShell();
} catch (Throwable $e) {
    GPC\Page::setupError($e);
}
