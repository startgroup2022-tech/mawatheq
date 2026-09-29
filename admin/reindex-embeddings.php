<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/semantic_search.php';
require_once __DIR__ . '/../includes/audit_log.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'طلب غير صالح.']);
    exit;
}

$settings = embedding_load_settings($pdo);
if ($settings['embedding_provider'] !== 'openai' || empty($settings['embedding_api_key'])) {
    echo json_encode(['ok' => false, 'message' => 'اضبط مزوّد المتجهات ومفتاح API أولاً.']);
    exit;
}

$started = microtime(true);
$totalProcessed = 0; $totalFailed = 0;

// دفعة مواد نظامية (limited batch, تفادياً لانتهاء مهلة الاستضافة المشتركة)
$lawResult = law_articles_index_embeddings_batch($pdo, 40);
$totalProcessed += $lawResult['processed']; $totalFailed += $lawResult['failed'];

// دفعة مصادر معرفية بدون متجه (نأخذ حتى 15 مصدر بالاستدعاء الواحد؛ كل مصدر قد يحوي عدة مقاطع)
$stmt = $pdo->prepare("SELECT id FROM knowledge_sources WHERE status='active' AND processing_status='completed' AND embedding_status IN ('pending','not_configured','failed') LIMIT 15");
$stmt->execute();
$sourceIds = array_column($stmt->fetchAll(), 'id');
foreach ($sourceIds as $sourceId) {
    try { knowledge_index_embeddings($pdo, (int)$sourceId); $totalProcessed++; }
    catch (Throwable $e) { $totalFailed++; error_log('Manual reindex failed for source ' . $sourceId . ': ' . $e->getMessage()); }
}

$lawRemaining = (int)$pdo->query('SELECT COUNT(*) FROM law_articles WHERE embedding IS NULL')->fetchColumn();
$knowledgeRemaining = (int)$pdo->query("SELECT COUNT(*) FROM knowledge_sources WHERE status='active' AND processing_status='completed' AND embedding_status IN ('pending','not_configured','failed')")->fetchColumn();

try {
    $pdo->prepare('INSERT INTO embedding_reindex_log (run_by,scope,processed,failed,started_at,finished_at) VALUES (?,?,?,?,FROM_UNIXTIME(?),NOW())')
        ->execute([$_SESSION['admin_id'] ?? null, 'law_articles', $lawResult['processed'], $lawResult['failed'], $started]);
    $pdo->prepare('INSERT INTO embedding_reindex_log (run_by,scope,processed,failed,started_at,finished_at) VALUES (?,?,?,?,FROM_UNIXTIME(?),NOW())')
        ->execute([$_SESSION['admin_id'] ?? null, 'knowledge', count($sourceIds), $totalFailed, $started]);
} catch (Throwable $e) { error_log('embedding_reindex_log insert failed: ' . $e->getMessage()); }

log_action($pdo, 'إعادة فهرسة متجهات يدوية (' . $totalProcessed . ' عنصر، فشل ' . $totalFailed . ')');

echo json_encode([
    'ok' => true,
    'processed' => $totalProcessed,
    'failed' => $totalFailed,
    'law_remaining' => $lawRemaining,
    'knowledge_remaining' => $knowledgeRemaining,
]);
