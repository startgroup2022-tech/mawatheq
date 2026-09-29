<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/notifications.php';

if (!empty($_SESSION['user_id'])) {
    mark_all_notifications_read($pdo, 'client', (int)$_SESSION['user_id']);
    $back = '/ai-assistant.php';
} elseif (!empty($_SESSION['lawyer_id'])) {
    mark_all_notifications_read($pdo, 'lawyer', (int)$_SESSION['lawyer_id']);
    $back = '/lawyer/dashboard.php';
} elseif (!empty($_SESSION['admin_id'])) {
    mark_all_notifications_read($pdo, 'admin', (int)$_SESSION['admin_id']);
    $back = '/admin/index.php';
} else {
    $back = '/login.php';
}

$referer = $_SERVER['HTTP_REFERER'] ?? $back;
header('Location: ' . $referer);
