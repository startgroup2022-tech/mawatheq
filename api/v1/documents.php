<?php
/**
 * GET  /api/v1/documents.php          → قائمة مستنداتي وحالتها/نتائج تحليلها
 * GET  /api/v1/documents.php?id=5     → تفاصيل مستند واحد (نتائج + كيانات مستخرجة)
 * POST /api/v1/documents.php          → رفع مستند جديد (multipart/form-data, حقل "document")
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/ai_provider.php';
require_once __DIR__ . '/../../includes/plan_limits.php';
require_once __DIR__ . '/../../includes/upload_validation.php';

$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') === 'integration') {
    // Firm documents are reached through the cases that belong to the firm. A token
    // scoped to a single firm never sees another firm's documents.
    $firmId = $auth['firm_id'];
    $firmClause = $firmId === null ? '1=1' : 'c.firm_id = ?';
    $docId = (int)($_GET['id'] ?? 0);
    if ($docId) {
        $stmt = $pdo->prepare('SELECT d.id,d.original_name,d.file_type,d.document_type,d.status,d.summary,d.created_at FROM documents d JOIN cases c ON c.id=d.case_id WHERE d.id=? AND ' . $firmClause);
        $stmt->execute(array_merge([$docId], $firmId === null ? [] : [$firmId]));
        $doc = $stmt->fetch();
        if (!$doc) api_error('document not found', 404);
        api_json(['ok' => true, 'document' => $doc]);
    }
    $stmt = $pdo->prepare('SELECT d.id,d.original_name,d.file_type,d.document_type,d.status,d.summary,d.created_at FROM documents d JOIN cases c ON c.id=d.case_id WHERE ' . $firmClause . ' ORDER BY d.created_at DESC');
    $stmt->execute($firmId === null ? [] : [$firmId]);
    api_json(['ok' => true, 'documents' => $stmt->fetchAll()]);
}
if ($auth['type'] !== 'client') api_error('only clients can manage documents here', 403);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $docId = (int)($_GET['id'] ?? 0);

    if ($docId) {
        $stmt = $pdo->prepare('SELECT id,original_name,file_type,document_type,status,summary,contract_analysis_json,created_at FROM documents WHERE id=? AND user_id=?');
        $stmt->execute([$docId, $auth['id']]);
        $doc = $stmt->fetch();
        if (!$doc) api_error('document not found', 404);

        $findings = $pdo->prepare('SELECT type, content FROM document_findings WHERE document_id=? ORDER BY type, sort_order');
        $findings->execute([$docId]);

        $entities = $pdo->prepare('SELECT entity_type, value FROM document_entities WHERE document_id=?');
        $entities->execute([$docId]);

        api_json(['ok' => true, 'document' => $doc, 'findings' => $findings->fetchAll(), 'entities' => $entities->fetchAll()]);
    }

    $stmt = $pdo->prepare('SELECT id, original_name, file_type, document_type, status, summary, created_at FROM documents WHERE user_id=? ORDER BY created_at DESC');
    $stmt->execute([$auth['id']]);
    api_json(['ok' => true, 'documents' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['ai_consent'] ?? '') !== '1') api_error('explicit ai_consent=1 is required before document analysis');
    $quota = check_document_upload_allowed($pdo, $auth['id']);
    if (!$quota['allowed']) api_error($quota['message'], 403);

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        api_error('no file uploaded (field name must be "document")');
    }

    $file = $_FILES['document'];
    $validation = validate_legal_upload($file);
    $ext = $validation['extension'];
    if (!$validation['ok']) {
        api_error('invalid file (allowed: pdf, png, jpg — max ' . MAX_UPLOAD_SIZE_MB . 'MB)');
    }

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $storedName = bin2hex(random_bytes(20)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) api_error('failed to store the uploaded file', 500);

    $documentType = (($_POST['document_type'] ?? 'general') === 'contract') ? 'contract' : 'general';
    $stmt = $pdo->prepare('INSERT INTO documents (user_id, original_name, stored_path, file_type, document_type, status) VALUES (?,?,?,?,?,?)');
    $originalName = basename(str_replace('\\', '/', (string)$file['name']));
    $stmt->execute([$auth['id'], $originalName, 'uploads/documents/' . $storedName, $ext, $documentType, 'processing']);
    $docId = (int)$pdo->lastInsertId();

    $settings = ai_load_settings($pdo);
    $aiEnabled = ai_chat_provider_configured($settings);

    if (!$aiEnabled) {
        $pdo->prepare("UPDATE documents SET status='pending', summary=? WHERE id=?")
            ->execute(['بانتظار تفعيل مزوّد الذكاء الاصطناعي من لوحة تحكم الإدارة لتحليل هذا المستند تلقائياً.', $docId]);
    } elseif (!ai_vision_provider_configured($settings)) {
        // Chat works, but this provider cannot read a file directly.
        $pdo->prepare("UPDATE documents SET status='pending', summary=? WHERE id=?")
            ->execute(['المزوّد المفعّل لا يدعم تحليل الملفات مباشرة (PDF/صور)؛ يتطلب ذلك OpenAI أو Anthropic.', $docId]);
    } else {
        $mediaTypes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        $base64 = base64_encode(file_get_contents(UPLOAD_DIR . $storedName));
        require_once __DIR__ . '/../../includes/ai_gateway.php';
        $aiTenant = ai_gateway_tenant_context($pdo, 'client', (int)$auth['id']);
        $result = analyze_document_with_ai($settings['ai_provider'], $settings['ai_api_key'], $settings['ai_model'] ?? '', $base64, $mediaTypes[$ext], true, $documentType, [
            'pdo' => $pdo, 'settings' => $settings, 'tenant' => $aiTenant,
            'feature' => 'document-ai', 'data_category' => 'document_text',
            'actor_id' => (int)$auth['id'], 'actor_type' => 'client',
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

    api_json(['ok' => true, 'document_id' => $docId], 201);
}

api_error('method not allowed', 405);
