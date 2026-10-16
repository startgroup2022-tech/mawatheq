<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/tenant.php';
$evidenceId = (int)($_GET['id'] ?? 0);
// case_evidence has no lawyer_id column of its own — it inherits its tenant from the
// parent case, so scope by the case subquery (same rule the evidence list uses).
$caseScope = tenant_owner_where($pdo, 'c');
$stmt = $pdo->prepare('SELECT e.* FROM case_evidence e JOIN cases c ON c.id=e.case_id WHERE e.id=? AND ' . $caseScope['sql'] . ' LIMIT 1');
$stmt->execute(array_merge([$evidenceId], $caseScope['params']));
$item = $stmt->fetch();
if (!$item || !$item['stored_name'] || !preg_match('/^[a-f0-9]{40}\.(pdf|png|jpg|jpeg)$/', $item['stored_name'])) {
    http_response_code(404); exit('Not found');
}
$path = EVIDENCE_UPLOAD_DIR . $item['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('Not found'); }
$types = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
$ext = strtolower(pathinfo($item['stored_name'], PATHINFO_EXTENSION));
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="evidence-' . $evidenceId . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
