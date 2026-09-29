<?php
/**
 * GET /api/v1/calendar.php → lawyer agenda and legal deadlines
 * POST /api/v1/calendar.php → create a legal calendar event
 * POST with {action: complete, event_id} → complete an owned event
 */
require_once __DIR__ . '/bootstrap.php';
$auth = api_authenticate($pdo);
if ($auth['type'] !== 'lawyer') api_error('lawyer account required', 403);
$lawyerId = (int)$auth['id'];
$allowedTypes = ['court_session','appeal_deadline','contract_expiry','contract_renewal','government_deadline','other'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT e.id,e.case_id,e.event_type,e.title,e.description,e.event_at,e.status,c.title AS case_title
        FROM legal_calendar_events e LEFT JOIN cases c ON c.id=e.case_id
        WHERE e.lawyer_id=? AND e.status<>'cancelled' AND e.event_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
        ORDER BY e.event_at ASC LIMIT 500");
    $stmt->execute([$lawyerId]);
    api_json(['ok'=>true,'events'=>$stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('method not allowed', 405);
$input = api_input();
if (($input['action'] ?? '') === 'complete') {
    $eventId = (int)($input['event_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE legal_calendar_events SET status='completed' WHERE id=? AND lawyer_id=? AND status='upcoming'");
    $stmt->execute([$eventId,$lawyerId]);
    if (!$stmt->rowCount()) api_error('event not found or already completed',404);
    api_json(['ok'=>true]);
}
$title = trim((string)($input['title'] ?? ''));
$type = (string)($input['event_type'] ?? 'other');
$rawDate = trim((string)($input['event_at'] ?? ''));
$eventDate = DateTime::createFromFormat('!Y-m-d\\TH:i', $rawDate);
$caseId = (int)($input['case_id'] ?? 0);
if ($title === '' || strlen($title)>255 || !in_array($type,$allowedTypes,true) || !$eventDate || $eventDate->format('Y-m-d\\TH:i') !== $rawDate) {
    api_error('invalid event details',422);
}
if ($caseId) {
    $check = $pdo->prepare('SELECT id FROM cases WHERE id=? AND lawyer_id=?');
    $check->execute([$caseId,$lawyerId]);
    if (!$check->fetchColumn()) api_error('case not found',404);
}
$stmt=$pdo->prepare('INSERT INTO legal_calendar_events (lawyer_id,case_id,event_type,title,description,event_at) VALUES (?,?,?,?,?,?)');
$stmt->execute([$lawyerId,$caseId?:null,$type,$title,trim((string)($input['description']??''))?:null,$eventDate->format('Y-m-d H:i:00')]);
api_json(['ok'=>true,'event_id'=>(int)$pdo->lastInsertId()],201);
