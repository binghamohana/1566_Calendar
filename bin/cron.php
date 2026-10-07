<?php
// Background tasks: reminders, after-use emails, email retries, housekeeping.
// Run every 5 minutes, e.g. in /etc/cron.d/gpc-reserve:
//   */5 * * * * www-data php /var/www/gpc-reserve/bin/cron.php >> /var/www/gpc-reserve/storage/logs/cron.log 2>&1
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

$lock = fopen(GPC_ROOT . '/storage/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still going
}

try {
    $r = GPC\Jobs::run();
    if ($r['reminders'] || $r['followups'] || $r['sent'] || $r['failed']) {
        printf("[%s] reminders=%d followups=%d sent=%d failed=%d\n", gmdate('Y-m-d H:i:s'), $r['reminders'], $r['followups'], $r['sent'], $r['failed']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . '] cron error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
