<?php
/**
 * GET  /api/v1/availability-alerts.php            → تنبيهات العميل
 * POST /api/v1/availability-alerts.php { lawyer_id, action: "subscribe"|"cancel" }
 *
 * «أبلغني عند توفّر موعد». لا يُحجز شيء تلقائيًا؛ الإشعار داخل المنصة فقط، ويُرسل مرة
 * واحدة لكل فتحة موعد (التنبيه يُقفل إلى fulfilled بعد الإرسال).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_features.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') api_error('this endpoint is for clients', 403);
$clientId = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_json(['ok' => true, 'alerts' => availability_alerts_for_client($pdo, $clientId)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    $lawyerId = (int)($in['lawyer_id'] ?? 0);
    if ($lawyerId <= 0) api_error('lawyer_id is required');
    $action = (string)($in['action'] ?? 'subscribe');

    if ($action === 'cancel') {
        $res = availability_alert_cancel($pdo, $clientId, $lawyerId);
        api_json(['ok' => true, 'status' => $res['status']]);
    }
    $res = availability_alert_subscribe($pdo, $clientId, $lawyerId);
    if (!$res['ok']) api_error($res['error'] ?? 'error', 404);
    api_json(['ok' => true, 'status' => $res['status']]);
}

api_error('method not allowed', 405);
