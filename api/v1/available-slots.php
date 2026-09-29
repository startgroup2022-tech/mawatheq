<?php
/**
 * GET /api/v1/available-slots.php?lawyer_id=1&date=2026-09-25
 * لا يحتاج مصادقة.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/availability.php';

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);
$date = $_GET['date'] ?? '';

if (!$lawyerId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    api_error('lawyer_id and date (YYYY-MM-DD) are required');
}

api_json(['ok' => true, 'slots' => get_available_slots($pdo, $lawyerId, $date)]);
