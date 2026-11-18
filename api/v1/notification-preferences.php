<?php
/**
 * GET  /api/v1/notification-preferences.php   → قنوات الإشعار (in_app/email/push)
 * POST /api/v1/notification-preferences.php   { channel, enabled }
 *
 * لكل من العميل والمحامي. تُحفظ التفضيلات لكل حساب، وتُحترم فعليًا عند الإرسال:
 * إيقاف قناة يمنع الإرسال عليها، لا يغيّر شكل المفتاح فقط.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_features.php';

$auth = api_authenticate($pdo);
if (!in_array($auth['type'], ['client', 'lawyer'], true)) api_error('this endpoint is for account holders', 403);
$type = (string)$auth['type'];
$id = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_json([
        'ok'       => true,
        'channels' => notification_preferences_payload($pdo, $type, $id),
        'devices'  => device_token_count($pdo, $type, $id),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    $channel = (string)($in['channel'] ?? '');
    if (!in_array($channel, notification_channels_catalog(), true)) api_error('unknown channel');
    if (!array_key_exists('enabled', $in)) api_error('enabled is required');
    notification_preference_set($pdo, $type, $id, $channel, !empty($in['enabled']));
    api_json(['ok' => true, 'channels' => notification_preferences_payload($pdo, $type, $id)]);
}

api_error('method not allowed', 405);
