<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$consultationId = (int)($_REQUEST['consultation_id'] ?? 0);
if (!$consultationId) { http_response_code(400); echo json_encode(['error' => 'invalid_request']); exit; }

// تحديد الدور (عميل أو محامٍ) والتأكد من ملكية الاستشارة
$role = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT id FROM consultations WHERE id=? AND user_id=?');
    $stmt->execute([$consultationId, $_SESSION['user_id']]);
    if ($stmt->fetch()) $role = 'client';
}
if (!$role && !empty($_SESSION['lawyer_id'])) {
    $stmt = $pdo->prepare('SELECT id FROM consultations WHERE id=? AND lawyer_id=?');
    $stmt->execute([$consultationId, $_SESSION['lawyer_id']]);
    if ($stmt->fetch()) $role = 'lawyer';
}
if (!$role) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    if ($message === '') { http_response_code(400); echo json_encode(['error' => 'empty']); exit; }
    $pdo->prepare('INSERT INTO consultation_messages (consultation_id, sender_type, message) VALUES (?,?,?)')
        ->execute([$consultationId, $role, $message]);
}

$afterId = (int)($_GET['after_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM consultation_messages WHERE consultation_id=? AND id > ? ORDER BY id ASC');
$stmt->execute([$consultationId, $afterId]);
$messages = $stmt->fetchAll();

echo json_encode(['role' => $role, 'messages' => $messages]);
