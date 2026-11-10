<?php
/**
 * GET /api/available_slots.php?lawyer_id=1&date=2026-09-25   → {slots:[...]}   (unchanged)
 * GET /api/available_slots.php?lawyer_id=1&month=2026-09     → adds {days:{...},working_weekdays,has_hours}
 * GET /api/available_slots.php?lawyer_id=1&next=3            → adds {next_slots:[{date,time}]}
 *
 * The `date` contract is preserved byte-for-byte: an existing caller that sends lawyer_id+date
 * still receives exactly {slots:[...]}. The month/next shapes are additive, so the website can
 * draw a real calendar without a second endpoint and without breaking the old one.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/availability.php';
header('Content-Type: application/json; charset=utf-8');

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);
$date = (string)($_GET['date'] ?? '');
$month = (string)($_GET['month'] ?? '');
$next = (int)($_GET['next'] ?? 0);

if ($lawyerId <= 0) {
    echo json_encode(['slots' => []]);
    exit;
}

// The month calendar — a real availability map, not a guess.
if ($month !== '') {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(['slots' => [], 'days' => [], 'working_weekdays' => [], 'has_hours' => false]);
        exit;
    }
    echo json_encode(get_month_availability($pdo, $lawyerId, $month), JSON_UNESCAPED_UNICODE);
    exit;
}

// Nearest free slots across the coming days.
if ($next > 0) {
    $next = min($next, 10);
    echo json_encode(['slots' => [], 'next_slots' => get_next_available_slots($pdo, $lawyerId, $next)], JSON_UNESCAPED_UNICODE);
    exit;
}

// The original single-day contract.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['slots' => []]);
    exit;
}

echo json_encode(['slots' => get_available_slots($pdo, $lawyerId, $date)]);
