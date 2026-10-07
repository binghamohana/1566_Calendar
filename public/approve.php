<?php
/**
 * Opened from the "Review & approve" button in an approval-request email: approve.php?t=<token>.
 * Shows the request and asks for an explicit click — opening the link alone changes nothing,
 * so email link scanners can't approve anyone by accident.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use GPC\EmailAccess;
use GPC\Mailer;
use GPC\Notify;
use GPC\Page;
use GPC\Spaces;
use GPC\Time;

$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

try {
    GPC\Http::securityHeaders();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    $token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
    $access = EmailAccess::findByToken($token);
    $result = null;
    $emailIds = [];

    if ($access && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (($_POST['decision'] ?? '') === 'approve') {
            $r = EmailAccess::approve($access, 'manager (email link)', !empty($_POST['whole_domain']));
            $emailIds = Notify::decisionEmails($r['confirmed'], true);
            $result = ['approved', $r['pattern'], count($r['confirmed'])];
        } elseif (($_POST['decision'] ?? '') === 'decline') {
            $reason = (string) ($_POST['reason'] ?? '');
            $cancelled = EmailAccess::decline($access, 'manager (email link)', $reason);
            $emailIds = Notify::decisionEmails($cancelled, false, $reason);
            $result = ['declined', $access['pattern'], count($cancelled)];
        }
    }
    $bookings = $access && !$result ? EmailAccess::pendingBookings($access['pattern']) : [];
    $org = (string) GPC\Settings::get('org_name');
} catch (Throwable $ex) {
    Page::setupError($ex);
    return;
}

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Approval request · <?= $e($org) ?></title>
<link rel="icon" href="<?= $e(Page::asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= $e(Page::asset('assets/css/gpc.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="./"><img src="<?= $e(Page::asset('assets/img/logo-mark.svg')) ?>" alt=""><span class="brand-name"><?= Page::brandName($org) ?></span></a>
</header>
<main class="wrap manage">
  <div class="manage-card" style="max-width:640px">
<?php if ($result && $result[0] === 'approved'): ?>
    <span class="eyebrow">Approved</span>
    <h1>Done.</h1>
    <p class="manage-when"><strong><?= $e($result[1]) ?></strong> is now on the approved list<?= str_starts_with($result[1], '@') ? ' (everyone at that domain)' : '' ?>, so future reservations are confirmed instantly.</p>
    <p><?= $result[2] ? $e($result[2] . ' waiting reservation' . ($result[2] === 1 ? ' was' : 's were') . ' confirmed, and a confirmation email is on its way.') : 'They had no reservations waiting.' ?></p>
<?php elseif ($result): ?>
    <span class="eyebrow">Declined</span>
    <h1>Request declined.</h1>
    <p class="manage-when"><?= $result[2] ? $e($result[2] . ' held reservation' . ($result[2] === 1 ? ' was' : 's were') . ' released, and ' . $result[1] . ' has been told by email.') : 'There were no held reservations to release.' ?></p>
<?php elseif (!$access): ?>
    <span class="eyebrow">Approval request</span>
    <h1>Already handled.</h1>
    <p class="manage-when">This request was already approved or declined, or the link is incomplete. You can review every address in <a href="admin/#/approvals">Admin → Approved emails</a>.</p>
<?php else: ?>
    <span class="eyebrow">Approval request</span>
    <h1><?= $e($access['name'] ?: $access['pattern']) ?></h1>
    <p class="manage-when"><?= $e(implode(' · ', array_filter([$access['pattern'], $access['company']]))) ?></p>
    <p class="muted small">Asked <?= $e(Time::utcToLocal((string) $access['requested_at'])->format('l, F j \a\t g:i A')) ?>. Approving adds this address to the approved list; future reservations from it are confirmed instantly.</p>
    <?php if ($bookings): ?>
    <div class="ticket" style="margin:18px 0 22px">
      <?php foreach ($bookings as $b): $sp = Spaces::find($b['space_id']); ?>
      <div class="ticket-row"><span><?= $e($sp['name'] ?? '') ?></span><strong><?= $e(Time::fmtDate($b['date']) . ', ' . Time::fmtRange($b['start_min'], $b['end_min'])) ?><?= $b['title'] ? '<br><span class="muted" style="width:auto;font-weight:400">' . $e($b['title']) . '</span>' : '' ?></strong></div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="alert alert-info" style="margin:16px 0">They currently have no reservations waiting (they may have cancelled). You can still approve the address for the future.</div>
    <?php endif; ?>
    <form method="post" style="margin-bottom:22px">
      <input type="hidden" name="t" value="<?= $e($token) ?>">
      <input type="hidden" name="decision" value="approve">
      <?php $domain = EmailAccess::domainOf($access['pattern']); if (!EmailAccess::isPublicDomain($domain)): ?>
      <label class="check" style="margin-bottom:14px"><input type="checkbox" name="whole_domain" value="1"> <span>Also approve everyone at <strong>@<?= $e($domain) ?></strong> (their whole company)</span></label>
      <?php endif; ?>
      <button class="btn" type="submit">Approve <?= $e($access['pattern']) ?></button>
    </form>
    <form method="post" style="border-top:1px solid var(--line-soft);padding-top:18px">
      <input type="hidden" name="t" value="<?= $e($token) ?>">
      <input type="hidden" name="decision" value="decline">
      <div class="field"><label for="reason">Reason <span class="opt">(optional — included in the email to them)</span></label><input class="input" id="reason" name="reason" maxlength="255"></div>
      <button class="btn btn-danger-ghost" type="submit">Decline</button>
    </form>
<?php endif; ?>
  </div>
</main>
</body>
</html>
<?php
// Send the page first, then the emails, so the manager isn't kept waiting.
$html = (string) ob_get_clean();
header('Content-Length: ' . strlen($html));
echo $html;
if ($emailIds) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    Mailer::sendNow($emailIds);
}
