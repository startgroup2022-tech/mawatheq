<?php
/**
 * POST /api/v1/device-token.php  { token, platform?: "android"|"ios"|"web", action?: "register"|"unregister" }
 *
 * يسجّل رمز دفع الجهاز لحساب المستخدم أو المحامي. الرمز فريد (UNIQUE) فيُحدَّث صف الجهاز
 * بدل تكراره، ويُربط بالحساب المصدَّق عليه فقط — لا يمكن تسجيل جهاز لحساب آخر.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_features.php';

$auth = api_authenticate($pdo);
if (!in_array($auth['type'], ['client', 'lawyer'], true)) api_error('this endpoint is for account holders', 403);
$type = (string)$auth['type'];
$id = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('method not allowed', 405);

$in = api_input();
$token = trim((string)($in['token'] ?? ''));
if ($token === '') api_error('token is required');
$platform = (string)($in['platform'] ?? 'android');

if (($in['action'] ?? 'register') === 'unregister') {
    device_token_unregister($pdo, $type, $id, $token);
    api_json(['ok' => true, 'devices' => device_token_count($pdo, $type, $id)]);
}

if (!device_token_register($pdo, $type, $id, $token, $platform)) {
    api_error('could not register the device');
}
api_json(['ok' => true, 'devices' => device_token_count($pdo, $type, $id)]);
