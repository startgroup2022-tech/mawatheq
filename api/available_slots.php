<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/availability.php';
header('Content-Type: application/json; charset=utf-8');

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);
$date = $_GET['date'] ?? '';

if (!$lawyerId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['slots' => []]);
    exit;
}

echo json_encode(['slots' => get_available_slots($pdo, $lawyerId, $date)]);
