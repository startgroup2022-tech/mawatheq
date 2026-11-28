<?php
/**
 * Download a draft as an Arabic PDF.
 *
 * Same three gates as download-draft-docx.php, deliberately duplicated rather than
 * factored out: the export paths must be individually auditable, and a shared helper
 * that gets the tenant scope wrong would expose every draft at once.
 *
 *  1. tenant_owner_where() scopes the row to this lawyer or firm.
 *  2. Content must exist.
 *  3. An internal review that is still open blocks export — the same rule the page
 *     applies, so a draft cannot be extracted while a partner is reviewing it.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/pdf.php';

$id = (int)($_GET['id'] ?? 0);
$scope = tenant_owner_where($pdo, 'draft_documents');
$stmt = $pdo->prepare('SELECT * FROM draft_documents WHERE id=? AND ' . $scope['sql']);
$stmt->execute(array_merge([$id], $scope['params']));
$draft = $stmt->fetch();

if (!$draft || !$draft['generated_content'] || (!empty($draft['internal_review_started_at']) && $draft['approval_stage'] !== 'approved')) {
    header('Location: /lawyer/drafting.php');
    exit;
}

if (!mawatheq_pdf_fonts_available()) {
    http_response_code(503);
    exit(is_rtl()
        ? 'تصدير PDF غير متاح على هذه الاستضافة (ملفات الخطوط العربية غير موجودة).'
        : 'PDF export is unavailable on this host (the bundled Arabic fonts are missing).');
}

$body = pdf_text((string)$draft['generated_content']);
$body .= '<p style="margin-top:18pt;font-size:8.5pt;color:#7A8794">'
    . pdf_e(is_rtl()
        ? 'مسودة أولية من منصة مواثيق — تحتاج مراجعة محامٍ مرخص قبل الإرسال أو الاستخدام أو التوقيع.'
        : 'Preliminary draft from Mawatheq — requires review by a licensed lawyer before sending, use, or signature.')
    . '</p>';

try {
    $mpdf = mawatheq_pdf_render($body, [
        'title' => (string)$draft['title'],
        'date'  => date('Y-m-d'),
    ]);
} catch (Throwable $e) {
    error_log('draft pdf failed: ' . $e->getMessage());
    http_response_code(503);
    exit(is_rtl() ? 'تعذّر إنشاء ملف PDF.' : 'Could not generate the PDF.');
}

$filename = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string)$draft['title']) . '.pdf';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
mawatheq_pdf_download($mpdf, $filename);
