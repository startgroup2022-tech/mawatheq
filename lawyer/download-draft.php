<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/tenant.php';

$id = (int)($_GET['id'] ?? 0);
$scope = tenant_owner_where($pdo, 'draft_documents');
$stmt = $pdo->prepare('SELECT * FROM draft_documents WHERE id=? AND ' . $scope['sql']);
$stmt->execute(array_merge([$id], $scope['params']));
$draft = $stmt->fetch();

if (!$draft || !$draft['generated_content'] || (!empty($draft['internal_review_started_at']) && $draft['approval_stage'] !== 'approved')) {
    header('Location: /lawyer/drafting.php'); exit;
}

$filename = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $draft['title']) . '.txt';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // BOM لضمان ظهور العربي صحيحاً بمفكرة الملاحظات
echo $draft['generated_content'];
