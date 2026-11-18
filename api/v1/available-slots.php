<?php
/**
 * GET /api/v1/available-slots.php?lawyer_id=1&date=2026-09-25   → {ok,slots:[...]}  (unchanged)
 * GET /api/v1/available-slots.php?lawyer_id=1&month=2026-09    → adds {days,working_weekdays,has_hours}
 * GET /api/v1/available-slots.php?lawyer_id=1&next=3           → adds {next_slots:[{date,time}]}
 *
 * لا يحتاج مصادقة. عقد `date` محفوظ كما هو؛ الأشكال الجديدة إضافية فقط.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/availability.php';

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);
$date = (string)($_GET['date'] ?? '');
$month = (string)($_GET['month'] ?? '');
$next = (int)($_GET['next'] ?? 0);

if ($lawyerId <= 0) {
    api_error('lawyer_id is required');
}

if ($month !== '') {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        api_error('month must be YYYY-MM');
    }
    $m = get_month_availability($pdo, $lawyerId, $month);
    api_json(['ok' => true, 'slots' => [], 'days' => $m['days'],
        'working_weekdays' => $m['working_weekdays'], 'has_hours' => $m['has_hours'], 'month' => $m['month']]);
}

if ($next > 0) {
    api_json(['ok' => true, 'slots' => [], 'next_slots' => get_next_available_slots($pdo, $lawyerId, min($next, 10))]);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    api_error('lawyer_id and date (YYYY-MM-DD) are required');
}

api_json(['ok' => true, 'slots' => get_available_slots($pdo, $lawyerId, $date)]);
