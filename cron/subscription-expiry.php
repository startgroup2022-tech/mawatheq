<?php
// Run daily from cPanel Cron Jobs:
//   php /home/CPANEL_USER/public_html/cron/subscription-expiry.php
//
// A paid package writes `users.plan_id`, which is the entitlement every gated feature reads.
// Nothing used to take it back at `subscriptions.ends_at`, so a monthly plan bought once
// unlocked its limits permanently. This sweep ends the due terms and reverts the plan only
// where the user still holds the one that expired.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/plan_limits.php';
require_once __DIR__ . '/../includes/package_payment.php';

$res = subscriptions_expire_due($pdo);
fwrite(STDOUT, 'Subscriptions expired: ' . $res['expired'] . ', plans reverted: ' . $res['downgraded'] . PHP_EOL);
