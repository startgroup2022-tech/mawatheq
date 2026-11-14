<?php
// Run hourly from cPanel Cron Jobs:
//   php /home/CPANEL_USER/public_html/cron/availability-alerts.php
//
// «أبلغني عند توفّر موعد». Each run asks every lawyer with an active, un-notified alert
// whether they now have an open slot in the next two weeks. Only then is the waiting
// customer told, and the alert is closed — so one opening produces one message, not one
// per sweep. Nothing is booked: the notification deep-links to the lawyer's profile, and
// the customer decides.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/customer_features.php';

// Every lawyer who has at least one active alert waiting.
$lawyers = $pdo->query(
    "SELECT DISTINCT lawyer_id FROM lawyer_availability_alerts
     WHERE status='active' AND notified_at IS NULL"
)->fetchAll(PDO::FETCH_COLUMN);

$notified = 0;
foreach ($lawyers as $lawyerId) {
    try {
        $notified += availability_alerts_dispatch($pdo, (int)$lawyerId);
    } catch (Throwable $e) {
        error_log('Availability alert dispatch failed for lawyer ' . (int)$lawyerId . ': ' . $e->getMessage());
    }
}

fwrite(STDOUT, 'Availability alerts notified: ' . $notified . PHP_EOL);
