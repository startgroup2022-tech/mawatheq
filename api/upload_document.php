<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/plan_limits.php';
require_once __DIR__ . '/../includes/upload_validation.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// تطبيق حد الباقة الفعلي على عدد تحليلات المستندات شهرياً (إن كانت الباقة تحدد ذلك)
$quota = check_document_upload_allowed($pdo, (int)$_SESSION['user_id']);
if (!$quota['allowed']) {
    header('Location: /documents.php?error=plan_limit&msg=' . urlencode($quota['message']));
    exit;
}

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    header('Location: /documents.php');
    exit;
}
if (($_POST['ai_consent'] ?? '') !== '1') {
    header('Location: /documents.php?error=ai_consent');
    exit;
}

$file = $_FILES['document'];
$documentType = (($_POST['document_type'] ?? 'general') === 'contract') ? 'contract' : 'general';
$validation = validate_legal_upload($file);
$ext = $validation['extension'];
if (!$validation['ok']) {
    header('Location: /documents.php?error=invalid_file');
    exit;
}

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
$storedName = bin2hex(random_bytes(20)) . '.' . $ext;
$destination = UPLOAD_DIR . $storedName;
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    header('Location: /documents.php?error=upload_failed');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO documents (user_id, original_name, stored_path, file_type, document_type, status) VALUES (?,?,?,?,?,?)');
$originalName = basename(str_replace('\\', '/', (string)$file['name']));
$stmt->execute([$_SESSION['user_id'], $originalName, 'uploads/documents/' . $storedName, $ext, $documentType, 'processing']);
$docId = $pdo->lastInsertId();

// Audit the upload (metadata only — never the file contents) and tell the client's
// lawyers that a new document is available for their cases.
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
log_action($pdo, 'رفع مستند: ' . $originalName, 'document', (int)$docId);
$lawyerRecipients = $pdo->prepare('SELECT DISTINCT lawyer_id FROM cases WHERE user_id=?');
$lawyerRecipients->execute([(int)$_SESSION['user_id']]);
foreach ($lawyerRecipients->fetchAll(PDO::FETCH_COLUMN) as $caseLawyerId) {
    notify($pdo, 'lawyer', (int)$caseLawyerId, is_rtl() ? 'رفع العميل مستنداً جديداً' : 'Client uploaded a new document', $originalName, '/lawyer/cases.php');
}

/**
 * ============================================================
 * تحليل المستند الفعلي: نرسل الملف نفسه (PDF أو صورة) لمزوّد الذكاء
 * الاصطناعي المفعّل من لوحة التحكم (Anthropic/OpenAI — لا يوجد أي
 * مزوّد آخر مُخترع هنا). إن لم يوجد مزوّد مفعّل، نضع حالة صريحة
 * "بانتظار التفعيل" بدل نتائج وهمية.
 * ============================================================
 */
$settings = ai_load_settings($pdo);
$aiEnabled = !empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0';
$isArabic = current_lang() === 'ar';

if (!$aiEnabled) {
    $pdo->prepare("UPDATE documents SET status='pending', summary=? WHERE id=?")->execute([
        $isArabic
            ? 'بانتظار تفعيل مزوّد الذكاء الاصطناعي من لوحة تحكم الإدارة (الإعدادات) لتحليل هذا المستند تلقائياً.'
            : 'Awaiting AI provider activation from the admin settings to analyze this document automatically.',
        $docId
    ]);
} else {
    $mediaTypes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    $base64 = base64_encode(file_get_contents($destination));
    require_once __DIR__ . '/includes/ai_gateway.php';
    $aiTenant = ai_gateway_tenant_context($pdo, 'client', (int)$_SESSION['user_id']);
    $result = analyze_document_with_ai($settings['ai_provider'], $settings['ai_api_key'], $settings['ai_model'] ?? '', $base64, $mediaTypes[$ext], $isArabic, $documentType, [
        'pdo' => $pdo, 'settings' => $settings, 'tenant' => $aiTenant,
        'feature' => 'document-ai', 'data_category' => 'document_text',
        'actor_id' => (int)$_SESSION['user_id'], 'actor_type' => 'client',
    ]);

    if (!$result['ok']) {
        $pdo->prepare("UPDATE documents SET status='failed', summary=? WHERE id=?")->execute([$result['error'], $docId]);
    } else {
        $findStmt = $pdo->prepare('INSERT INTO document_findings (document_id, type, content, sort_order) VALUES (?,?,?,?)');
        foreach ($result['risks'] as $i => $r) $findStmt->execute([$docId, 'risk', $r, $i]);
        foreach ($result['recommendations'] as $i => $r) $findStmt->execute([$docId, 'recommendation', $r, $i]);

        $entityStmt = $pdo->prepare('INSERT INTO document_entities (document_id, entity_type, value) VALUES (?,?,?)');
        foreach ($result['entities']['dates'] as $v) $entityStmt->execute([$docId, 'date', $v]);
        foreach ($result['entities']['amounts'] as $v) $entityStmt->execute([$docId, 'amount', $v]);
        foreach ($result['entities']['parties'] as $v) $entityStmt->execute([$docId, 'party', $v]);

        $contractJson = $result['contract_analysis'] ? json_encode($result['contract_analysis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $pdo->prepare("UPDATE documents SET status='completed', summary=?, contract_analysis_json=? WHERE id=?")->execute([$result['summary'], $contractJson, $docId]);
    }
}

header('Location: /document-analysis.php?id=' . $docId);
