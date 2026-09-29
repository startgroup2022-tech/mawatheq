<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/docx_export.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM draft_documents WHERE id=? AND lawyer_id=?');
$stmt->execute([$id, $_SESSION['lawyer_id']]);
$draft = $stmt->fetch();

// نفس شرط download-draft.php: يُمنع التصدير أثناء انتظار مراجعة داخلية لم تُعتمد بعد.
if (!$draft || !$draft['generated_content'] || (!empty($draft['internal_review_started_at']) && $draft['approval_stage'] !== 'approved')) {
    header('Location: /lawyer/drafting.php'); exit;
}

$footer = is_rtl()
    ? 'مسودة أولية من منصة مواثيق — تحتاج مراجعة محامٍ مرخص قبل الإرسال أو الاستخدام أو التوقيع. لا تُعتمد كمستند نهائي.'
    : 'Preliminary draft from Mawatheq — requires review by a licensed lawyer before sending, use, or signature. Not a final document.';

$docx = build_simple_docx($draft['title'], $draft['generated_content'], $footer, $draft['language'] !== 'en');
if ($docx === null) {
    http_response_code(503);
    exit(is_rtl() ? 'تصدير Word غير متاح على هذه الاستضافة (يلزم تفعيل امتداد ZipArchive في PHP).' : 'Word export is unavailable on this host (the PHP ZipArchive extension must be enabled).');
}

$filename = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $draft['title']) . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . strlen($docx));
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $docx;
