<?php
/**
 * GET  /api/v1/documents.php          → قائمة مستنداتي وحالتها/نتائج تحليلها
 * GET  /api/v1/documents.php?id=5     → تفاصيل مستند واحد (نتائج + كيانات مستخرجة)
 * POST /api/v1/documents.php          → رفع مستند جديد (multipart/form-data, حقل "document")
 *
 * Clients see the documents they uploaded. Lawyers reach documents through the cases in
 * their tenant: a firm member sees the documents attached to their firm's cases, a solo
 * practitioner sees those on their own cases. Uploading as a lawyer requires the
 * documents.upload capability and a case_id inside the tenant — a lawyer can never attach
 * a document to another firm's case.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/ai_provider.php';
require_once __DIR__ . '/../../includes/plan_limits.php';
require_once __DIR__ . '/../../includes/upload_validation.php';
require_once __DIR__ . '/../../includes/tenant.php';

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

if ($auth['type'] === 'lawyer') {
    api_documents_lawyer($pdo, $auth);
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

    api_documents_analyse($pdo, $docId, UPLOAD_DIR . $storedName, $ext, $documentType, [
        'actor_id' => (int)$auth['id'], 'actor_type' => 'client',
    ]);

    api_json(['ok' => true, 'document_id' => $docId], 201);
}

api_error('method not allowed', 405);

/**
 * Run the AI analysis for a freshly stored document and record the outcome.
 *
 * Shared by the client and lawyer upload paths so the consent/quota/pending semantics stay
 * identical. An unconfigured or vision-incapable provider leaves the document in an honest
 * `pending` state with an explanatory summary — never a fabricated result.
 */
function api_documents_analyse(PDO $pdo, int $docId, string $absolutePath, string $ext, string $documentType, array $actor): void {
    $settings = ai_load_settings($pdo);
    if (!ai_chat_provider_configured($settings)) {
        $pdo->prepare("UPDATE documents SET status='pending', summary=? WHERE id=?")
            ->execute(['بانتظار تفعيل مزوّد الذكاء الاصطناعي من لوحة تحكم الإدارة لتحليل هذا المستند تلقائياً.', $docId]);
        return;
    }
    if (!ai_vision_provider_configured($settings)) {
        $pdo->prepare("UPDATE documents SET status='pending', summary=? WHERE id=?")
            ->execute(['المزوّد المفعّل لا يدعم تحليل الملفات مباشرة (PDF/صور)؛ يتطلب ذلك OpenAI أو Anthropic.', $docId]);
        return;
    }

    $mediaTypes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    $base64 = base64_encode(file_get_contents($absolutePath));
    require_once __DIR__ . '/../../includes/ai_gateway.php';
    $aiTenant = ai_gateway_tenant_context($pdo, $actor['actor_type'], (int)$actor['actor_id']);
    $result = analyze_document_with_ai($settings['ai_provider'], $settings['ai_api_key'], $settings['ai_model'] ?? '', $base64, $mediaTypes[$ext] ?? 'application/pdf', true, $documentType, [
        'pdo' => $pdo, 'settings' => $settings, 'tenant' => $aiTenant,
        'feature' => 'document-ai', 'data_category' => 'document_text',
        'actor_id' => (int)$actor['actor_id'], 'actor_type' => $actor['actor_type'],
    ]);

    if (!$result['ok']) {
        $pdo->prepare("UPDATE documents SET status='failed', summary=? WHERE id=?")->execute([$result['error'], $docId]);
        return;
    }

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

/**
 * Lawyer document access. Reads are scoped to the lawyer's tenant through the case the
 * document belongs to; writes additionally require the documents.upload capability and a
 * case_id the lawyer actually owns (or that belongs to their firm).
 */
function api_documents_lawyer(PDO $pdo, array $auth): void {
    $lawyerId = (int)$auth['id'];
    $scope = tenant_owner_where_for_lawyer($pdo, $lawyerId, 'c');
    $docId = (int)($_GET['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($docId) {
            $stmt = $pdo->prepare('SELECT d.id,d.original_name,d.file_type,d.document_type,d.status,d.summary,d.contract_analysis_json,d.created_at FROM documents d JOIN cases c ON c.id=d.case_id WHERE d.id=? AND ' . $scope['sql']);
            $stmt->execute(array_merge([$docId], $scope['params']));
            $doc = $stmt->fetch();
            if (!$doc) api_error('document not found', 404);
            $findings = $pdo->prepare('SELECT type, content FROM document_findings WHERE document_id=? ORDER BY type, sort_order');
            $findings->execute([$docId]);
            $entities = $pdo->prepare('SELECT entity_type, value FROM document_entities WHERE document_id=?');
            $entities->execute([$docId]);
            api_json(['ok' => true, 'document' => $doc, 'findings' => $findings->fetchAll(), 'entities' => $entities->fetchAll()]);
        }
        $stmt = $pdo->prepare('SELECT d.id,d.original_name,d.file_type,d.document_type,d.status,d.summary,d.created_at,c.id AS case_id,c.title AS case_title FROM documents d JOIN cases c ON c.id=d.case_id WHERE ' . $scope['sql'] . ' ORDER BY d.created_at DESC');
        $stmt->execute($scope['params']);
        api_json(['ok' => true, 'documents' => $stmt->fetchAll()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('method not allowed', 405);

    if (!tenant_can_for_lawyer($pdo, $lawyerId, 'documents.upload')) {
        api_error('forbidden: your role cannot upload documents', 403);
    }
    if (($_POST['ai_consent'] ?? '') !== '1') {
        api_error('explicit ai_consent=1 is required before document analysis');
    }

    $caseId = (int)($_POST['case_id'] ?? 0);
    if (!$caseId) api_error('case_id is required when uploading as a lawyer');
    $own = $pdo->prepare('SELECT c.id,c.user_id FROM cases c WHERE c.id=? AND ' . $scope['sql']);
    $own->execute(array_merge([$caseId], $scope['params']));
    $caseRow = $own->fetch();
    if (!$caseRow) api_error('case not found in your tenant', 404);

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        api_error('no file uploaded (field name must be "document")');
    }
    $validation = validate_legal_upload($_FILES['document']);
    if (!$validation['ok']) {
        api_error('invalid file (allowed: pdf, png, jpg — max ' . MAX_UPLOAD_SIZE_MB . 'MB)');
    }

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $ext = $validation['extension'];
    $storedName = bin2hex(random_bytes(20)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['document']['tmp_name'], UPLOAD_DIR . $storedName)) {
        api_error('failed to store the uploaded file', 500);
    }

    $documentType = (($_POST['document_type'] ?? 'general') === 'contract') ? 'contract' : 'general';
    $originalName = basename(str_replace('\\', '/', (string)$_FILES['document']['name']));
    $pdo->prepare('INSERT INTO documents (user_id, case_id, original_name, stored_path, file_type, document_type, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([(int)$caseRow['user_id'], $caseId, $originalName, 'uploads/documents/' . $storedName, $ext, $documentType, 'processing']);
    $newDocId = (int)$pdo->lastInsertId();

    api_documents_analyse($pdo, $newDocId, UPLOAD_DIR . $storedName, $ext, $documentType, [
        'actor_id' => $lawyerId, 'actor_type' => 'lawyer',
    ]);
    api_json(['ok' => true, 'document_id' => $newDocId], 201);
}
