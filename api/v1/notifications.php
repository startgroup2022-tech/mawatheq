<?php
/**
 * GET  /api/v1/notifications.php               → قائمة الإشعارات + عدد غير المقروء
 * POST /api/v1/notifications.php  { "mark_all_read": true }
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/notifications.php';

$auth = api_authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_json([
        'ok' => true,
        'unread_count' => unread_notifications_count($pdo, $auth['type'], $auth['id']),
        'notifications' => get_notifications($pdo, $auth['type'], $auth['id'], 50),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    if (!empty($in['mark_all_read'])) {
        mark_all_notifications_read($pdo, $auth['type'], $auth['id']);
        api_json(['ok' => true]);
    }
    api_error('unsupported action');
}

api_error('method not allowed', 405);
