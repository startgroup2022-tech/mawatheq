<?php
/**
 * REST v1 — consultation attachments (mobile clients).
 *
 * GET  /api/v1/consultation-attachments.php?consultation_id=12   → metadata for a consultation
 * GET  /api/v1/consultation-attachments.php?id=34                → one attachment's metadata
 * GET  /api/v1/consultation-attachments.php?id=34&download=1     → the file bytes
 * POST /api/v1/consultation-attachments.php                      → upload (multipart, field "file")
 *        body fields: consultation_id, file
 *
 * Authentication is the existing Bearer token (api_authenticate), so a client token and a
 * lawyer token both work and are scoped exactly as the web app scopes them: a client sees
 * only their own consultations, a lawyer only their tenant's. The authorization helper is
 * the same one the web routes use, so the two can never disagree.
 *
 * Backward compatibility: this is a NEW endpoint. Nothing in the existing
 * /api/v1/consultations.php contract changed, so a mobile build that predates this feature
 * keeps working unchanged. The consultation objects that endpoint returns are untouched;
 * attachment metadata is fetched from here.
 *
 * Error shape matches the rest of v1: {"ok":false,"error":"..."} with a meaningful status,
 * so a mobile client handles upload errors the same way it already handles every other
 * v1 error.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/consultation_attachments.php';

$auth = api_authenticate($pdo);

// An integration token is a read-only machine credential scoped to firm resources. It is
// deliberately not allowed to read consultation attachments: those are client files, and the
// integration allow-list in bootstrap.php does not include this endpoint. Naming it here
// gives a clear error instead of a bare 403 from the generic gate.
if (($auth['type'] ?? '') === 'integration') {
    api_error('forbidden: integration tokens cannot access consultation attachments', 403);
}

if (!in_array($auth['type'], ['client', 'lawyer'], true)) {
    api_error('forbidden', 403);
}
$actor = ['type' => $auth['type'], 'id' => (int)$auth['id']];
$limits = consultation_attachment_limits($pdo);

/** Attachment metadata as the mobile client expects it. Never includes a stored path. */
function api_ca_payload(array $row): array {
    return [
        'id' => (int)$row['id'],
        'consultation_id' => $row['consultation_id'] !== null ? (int)$row['consultation_id'] : null,
        'name' => (string)$row['original_name'],
        'category' => (string)$row['file_category'],
        'extension' => (string)$row['file_type'],
        'mime' => (string)$row['mime_type'],
        'size' => (int)$row['file_size'],
        'size_label' => consultation_attachment_format_size((int)$row['file_size']),
        'uploaded_by' => (string)$row['uploaded_by_type'],
        'download_count' => (int)$row['download_count'],
        'created_at' => (string)$row['created_at'],
        'previewable' => consultation_attachment_preview_mime($row['mime_type'] ?? null) !== null,
        // A stable, authenticated URL rather than a public one. The token is supplied by the
        // client in the Authorization header on the follow-up request.
        'download_path' => '/api/v1/consultation-attachments.php?id=' . (int)$row['id'] . '&download=1',
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $attachmentId = (int)($_GET['id'] ?? 0);

    if ($attachmentId > 0) {
        $row = consultation_attachment_find($pdo, $attachmentId);
        if (!$row || ($row['status'] ?? '') !== 'stored' || empty($row['consultation_id'])) {
            api_error('attachment not found', 404);
        }
        $consultation = consultation_attachment_authorize($pdo, (int)$row['consultation_id'], $actor);
        if (!$consultation) api_error('attachment not found', 404);

        if ((string)($_GET['download'] ?? '') === '1') {
            $path = consultation_attachment_absolute_path($row);
            if ($path === null) api_error('the file is no longer available', 410);

            $extension = strtolower((string)$row['file_type']);
            if (!preg_match('/^[a-f0-9]{40}\.' . preg_quote($extension, '/') . '$/', basename($path))) {
                api_error('attachment not found', 404);
            }

            consultation_attachment_record_download($pdo, $attachmentId);

            // Sent as an attachment with an octet-stream type and nosniff, exactly like the
            // web route: the mobile client saves it, and a mislabelled file is never
            // interpreted as something executable.
            $displayName = consultation_attachment_safe_name((string)$row['original_name']);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . (int)filesize($path));
            header('Content-Disposition: attachment; filename="attachment-' . $attachmentId . '.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            $handle = fopen($path, 'rb');
            if ($handle === false) api_error('the file is no longer available', 410);
            while (!feof($handle)) {
                $chunk = fread($handle, 262144);
                if ($chunk === false) break;
                echo $chunk;
                flush();
            }
            fclose($handle);
            exit;
        }

        api_json(['ok' => true, 'attachment' => api_ca_payload($row)]);
    }

    $consultationId = (int)($_GET['consultation_id'] ?? 0);
    $consultation = consultation_attachment_authorize($pdo, $consultationId, $actor);
    if (!$consultation) api_error('consultation not found', 404);

    $rows = consultation_attachment_list($pdo, $consultationId);
    api_json([
        'ok' => true,
        'attachments' => array_map('api_ca_payload', $rows),
        'can_upload' => consultation_attachment_can_upload($consultation, $actor),
        'limits' => [
            'document_mb' => (int)$limits['document_mb'],
            'audio_mb' => (int)$limits['audio_mb'],
            'video_mb' => (int)$limits['video_mb'],
            'max_files' => (int)$limits['max_files'],
            'allowed_extensions' => array_keys(consultation_attachment_catalog()),
        ],
    ]);
}

if ($method === 'DELETE') {
    // DELETE with the id in the query string, so a client can remove an attachment without
    // having to construct a body. api_input() also accepts JSON for clients that send one.
    $in = api_input();
    $attachmentId = (int)($_GET['id'] ?? ($in['id'] ?? 0));
    $row = $attachmentId > 0 ? consultation_attachment_find($pdo, $attachmentId) : null;
    if (!$row || ($row['status'] ?? '') !== 'stored' || empty($row['consultation_id'])) {
        api_error('attachment not found', 404);
    }
    $consultation = consultation_attachment_authorize($pdo, (int)$row['consultation_id'], $actor);
    if (!$consultation || !consultation_attachment_can_delete($row, $consultation, $actor)) {
        api_error('forbidden: you may not remove this attachment', 403);
    }
    if (!consultation_attachment_delete($pdo, $row, $actor)) {
        api_error('the attachment could not be removed', 500);
    }
    api_json(['ok' => true]);
}

if ($method !== 'POST') {
    api_error('method not allowed', 405);
}

// ------------------------------------------------------------
// Upload
// ------------------------------------------------------------
$consultationId = (int)($_POST['consultation_id'] ?? 0);
$consultation = consultation_attachment_authorize($pdo, $consultationId, $actor);
if (!$consultation) api_error('consultation not found', 404);
if (!consultation_attachment_can_upload($consultation, $actor)) {
    api_error('attachments cannot be added to this consultation in its current state', 403);
}

// A body over post_max_size reaches PHP with $_POST and $_FILES empty. Say so, because
// "no file uploaded" would send a mobile developer looking in the wrong place.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    api_error('the request exceeded the server size limit; upload files one at a time', 413);
}
if (!isset($_FILES['file'])) {
    api_error('no file uploaded (field name must be "file")');
}

$quota = consultation_attachment_quota_check($pdo, $consultationId, (int)$consultation['user_id'], (int)($_FILES['file']['size'] ?? 0), $limits);
if (!$quota['allowed']) api_error($quota['message'], 422);

$result = consultation_attachment_store(
    $pdo, $consultationId,
    $consultation['firm_id'] !== null ? (int)$consultation['firm_id'] : null,
    $_FILES['file'], $actor, $limits
);
if (!$result['ok']) api_error((string)$result['error'], 422);

require_once __DIR__ . '/../../includes/audit_log.php';
log_action($pdo, 'إرفاق ملف باستشارة (API): ' . (string)$result['attachment']['original_name'], 'consultation_attachment', (int)$result['attachment']['id']);

api_json(['ok' => true, 'attachment' => api_ca_payload($result['attachment'])], 201);
