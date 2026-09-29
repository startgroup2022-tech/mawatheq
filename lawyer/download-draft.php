<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM draft_documents WHERE id=? AND lawyer_id=?');
$stmt->execute([$id, $_SESSION['lawyer_id']]);
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
