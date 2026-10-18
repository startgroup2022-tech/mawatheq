<?php
/**
 * GET  /api/v1/consultations.php   → قائمة استشاراتي (عميل أو محامٍ حسب الرمز)
 * POST /api/v1/consultations.php   → حجز استشارة جديدة (عميل فقط)
 *   body: { lawyer_id, consultation_type: text|voice|video, date: "YYYY-MM-DD", time: "HH:MM", topic }
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/tenant.php';

$auth = api_authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($auth['type'] === 'client') {
        $stmt = $pdo->prepare('SELECT c.*, l.full_name AS lawyer_name, l.photo_path AS lawyer_photo FROM consultations c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.scheduled_at DESC');
        $stmt->execute([$auth['id']]);
    } else {
        $scope = tenant_owner_where_for_lawyer($pdo, $auth['id'], 'c');
        $stmt = $pdo->prepare('SELECT c.*, u.full_name AS client_name, u.phone AS client_phone FROM consultations c JOIN users u ON u.id=c.user_id WHERE ' . $scope['sql'] . ' ORDER BY c.scheduled_at DESC');
        $stmt->execute($scope['params']);
    }
    api_json(['ok' => true, 'consultations' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth['type'] !== 'client') api_error('only clients can book consultations', 403);

    $in = api_input();
    $lawyerId = (int)($in['lawyer_id'] ?? 0);
    $type = in_array($in['consultation_type'] ?? '', ['text','voice','video'], true) ? $in['consultation_type'] : 'text';
    $date = $in['date'] ?? '';
    $time = $in['time'] ?? '';
    $topic = trim($in['topic'] ?? '');

    if (!$lawyerId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        api_error('lawyer_id, date (YYYY-MM-DD) and time (HH:MM) are required');
    }

    $lawyerStmt = $pdo->prepare("SELECT hourly_rate FROM lawyers WHERE id=? AND status='approved'");
    $lawyerStmt->execute([$lawyerId]);
    $lawyer = $lawyerStmt->fetch();
    if (!$lawyer) api_error('lawyer not found', 404);

    $availCount = $pdo->prepare('SELECT COUNT(*) c FROM lawyer_availability WHERE lawyer_id=?');
    $availCount->execute([$lawyerId]);
    $hasAvailability = (int)$availCount->fetch()['c'] > 0;

    if ($hasAvailability) {
        $validSlots = get_available_slots($pdo, $lawyerId, $date);
        if (!in_array($time, $validSlots, true)) {
            api_error('this time slot is no longer available', 409);
        }
    }

    $scheduledAt = $date . ' ' . $time . ':00';
    $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, consultation_type, scheduled_at, topic, price) VALUES (?,?,?,?,?,?)')
        ->execute([$auth['id'], $lawyerId, $type, $scheduledAt, $topic, $lawyer['hourly_rate']]);
    $consultationId = (int)$pdo->lastInsertId();

    notify($pdo, 'lawyer', $lawyerId, 'طلب حجز استشارة جديد', ($topic ?: '') . ' — ' . $scheduledAt, '/lawyer/consultations.php');

    api_json(['ok' => true, 'consultation_id' => $consultationId], 201);
}

api_error('method not allowed', 405);
