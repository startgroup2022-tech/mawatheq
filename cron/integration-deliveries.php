<?php
/**
 * Drain queued outbound integration webhooks.
 *
 * Run from cPanel Cron Jobs (recommended every 5 minutes):
 *   php /home/CPANEL_USER/public_html/cron/integration-deliveries.php
 *
 * Events are queued synchronously when they happen, but delivered here so a slow or
 * dead partner endpoint can never stall a user's request. Each delivery is idempotent
 * per (endpoint, event), so re-running this cron is always safe.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integrations.php';

$limit = 100;
$result = integration_deliver_pending($pdo, $limit);
fwrite(STDOUT, sprintf(
    "[%s] integration deliveries: attempted=%d delivered=%d failed=%d\n",
    date('Y-m-d H:i:s'), $result['attempted'], $result['delivered'], $result['failed']
));
