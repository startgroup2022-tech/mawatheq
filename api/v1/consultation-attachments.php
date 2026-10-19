<?php
/**
 * REST v1 — consultation attachments and voice-note transcription (mobile clients).
 *
 * GET  /api/v1/consultation-attachments.php?consultation_id=12   → metadata for a consultation
 * GET  /api/v1/consultation-attachments.php?draft_token=...      → staged uploads for an unsubmitted form
 * GET  /api/v1/consultation-attachments.php?id=34                → one attachment's metadata
 * GET  /api/v1/consultation-attachments.php?id=34&download=1     → the file bytes
 * POST /api/v1/consultation-attachments.php                      → upload (multipart, field "file")
 *        body fields: consultation_id  → attach to an existing consultation
 *                     draft_token      → stage for a consultation that does not exist yet
 * POST /api/v1/consultation-attachments.php  action=transcribe   → transcribe a voice note
 *        body fields: attachment_id, consent=1, language=ar
 * DELETE /api/v1/consultation-attachments.php?id=34              → remove a stored or staged file
 *
 * Authentication is the existing Bearer token (api_authenticate), so a client token and a
 * lawyer token both work and are scoped exactly as the web app scopes them: a client sees
 * only their own consultations, a lawyer only their tenant's. The authorization helper is
 * the same one the web routes use, so the two can never disagree.
 *
 * Draft staging is what makes the mobile "one unified form" possible. The customer records
 * audio and picks documents BEFORE the consultation row exists; those files are stored
 * against an unguessable draft token and bound to the consultation by the booking POST.
 * A staged file is unreachable by anyone but the uploader: the claim checks both the token
 * and the uploader's identity.
 *
 * Backward compatibility: the consultation objects /api/v1/consultations.php returns are
 * unchanged, and every pre-existing parameter on this endpoint still behaves as before.
 * The new fields are additive.
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

/**
 * Attachment metadata as the mobile client expects it. Never includes a stored path.
 *
 * Transcription fields are included so the client can render "transcribing…", the
 * transcript, or the reason it is unavailable without a second request. The transcript
 * text itself is only emitted when it is actually readable, which is the same rule that
 * governs the audio bytes.
 */
function api_ca_payload(array $row, array $actor): array {
    $readable = consultation_attachment_can_read_transcription($row, $actor);
    return [
        'id' => (int)$row['id'],
        'consultation_id' => $row['consultation_id'] !== null ? (int)$row['consultation_id'] : null,
        'draft_token' => $row['draft_token'] !== null ? (string)$row['draft_token'] : null,
        'status' => (string)($row['status'] ?? 'stored'),
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
        'transcription' => [
            'status' => (string)($row['transcription_status'] ?? 'none'),
            'available' => $readable,
            'language' => $row['transcription_language'] !== null ? (string)$row['transcription_language'] : null,
            'engine' => $row['transcription_engine'] !== null ? (string)$row['transcription_engine'] : null,
            'error' => $row['transcription_error'] !== null ? (string)$row['transcription_error'] : null,
            'consented_at' => $row['transcription_consent_at'] !== null ? (string)$row['transcription_consent_at'] : null,
            'text' => $readable ? (string)$row['transcription_text'] : null,
        ],
    ];
}

/** Every field the client needs to build the upload UI, without a second call. */
function api_ca_limits(array $limits): array {
    return [
        'document_mb' => (int)$limits['document_mb'],
        'audio_mb' => (int)$limits['audio_mb'],
        'video_mb' => (int)$limits['video_mb'],
        'max_files' => (int)$limits['max_files'],
        'allowed_extensions' => array_keys(consultation_attachment_catalog()),
        'audio_extensions' => ['mp3', 'wav', 'm4a', 'ogg'],
        'image_extensions' => ['png', 'jpg', 'jpeg'],
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

        api_json(['ok' => true, 'attachment' => api_ca_payload($row, $actor)]);
    }

    // Staged uploads for a form that has not been submitted yet. Only the uploader can
    // list them, and the draft token is validated as a 64-hex string so a malformed value
    // is rejected before it reaches a query.
    $draftToken = (string)($_GET['draft_token'] ?? '');
    if ($draftToken !== '') {
        if ($actor['type'] !== 'client') api_error('forbidden', 403);
        if (!preg_match('/^[a-f0-9]{64}$/', $draftToken)) api_error('invalid draft token');
        $rows = consultation_attachment_staged($pdo, $draftToken, $actor['id']);
        api_json([
            'ok' => true,
            'attachments' => array_map(fn($r) => api_ca_payload($r, $actor), $rows),
            'limits' => api_ca_limits($limits),
        ]);
    }

    $consultationId = (int)($_GET['consultation_id'] ?? 0);
    $consultation = consultation_attachment_authorize($pdo, $consultationId, $actor);
    if (!$consultation) api_error('consultation not found', 404);

    $rows = consultation_attachment_list($pdo, $consultationId);
    api_json([
        'ok' => true,
        'attachments' => array_map(fn($r) => api_ca_payload($r, $actor), $rows),
        'can_upload' => consultation_attachment_can_upload($consultation, $actor),
        'limits' => api_ca_limits($limits),
    ]);
}

if ($method === 'DELETE') {
    // DELETE with the id in the query string, so a client can remove an attachment without
    // having to construct a body. api_input() also accepts JSON for clients that send one.
    $in = api_input();
    $attachmentId = (int)($_GET['id'] ?? ($in['id'] ?? 0));
    $row = $attachmentId > 0 ? consultation_attachment_find($pdo, $attachmentId) : null;
    if (!$row) api_error('attachment not found', 404);

    // A staged file has no consultation to authorize against; its authorization is the
    // uploader identity plus the staged status, which consultation_attachment_discard_staged
    // enforces in the WHERE clause.
    if (($row['status'] ?? '') === 'staged') {
        if (!consultation_attachment_discard_staged($pdo, $attachmentId, $actor['id'])) {
            api_error('attachment not found', 404);
        }
        api_json(['ok' => true]);
    }

    if (($row['status'] ?? '') !== 'stored' || empty($row['consultation_id'])) {
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
// Transcription (action=transcribe)
// ------------------------------------------------------------
// Deliberately gated on an explicit consent flag in the request. The brief requires that a
// recording is never sent to an external provider without disclosure and consent, so the
// client must have shown the disclosure and the customer must have accepted it before this
// action is reachable at all. The consent event is recorded whether or not the provider
// call then succeeds, because the consent itself is the auditable fact.
$in = api_input();
if ((string)($in['action'] ?? '') === 'transcribe') {
    require_once __DIR__ . '/../../includes/ai_provider.php';
    require_once __DIR__ . '/../../includes/ai_gateway.php';
    require_once __DIR__ . '/../../includes/secret_crypto.php';

    $attachmentId = (int)($in['attachment_id'] ?? $in['id'] ?? 0);
    $row = $attachmentId > 0 ? consultation_attachment_find($pdo, $attachmentId) : null;
    if (!$row || ($row['status'] ?? '') !== 'stored' || empty($row['consultation_id'])) {
        api_error('attachment not found', 404);
    }
    $consultation = consultation_attachment_authorize($pdo, (int)$row['consultation_id'], $actor);
    if (!$consultation) api_error('attachment not found', 404);

    // Only the customer who submitted the recording may send it for transcription. A lawyer
    // can read a transcript that exists, but must not be able to cause the client's audio to
    // leave the platform — that decision belongs to the person whose voice it is.
    if ($actor['type'] !== 'client' || (int)$row['uploaded_by_id'] !== $actor['id']) {
        api_error('forbidden: only the client who submitted this recording may transcribe it', 403);
    }
    if (!consultation_attachment_is_audio($row)) {
        api_error('only audio recordings can be transcribed', 422);
    }
    if (empty($in['consent']) || (string)$in['consent'] === '0') {
        api_error('consent is required before a recording can be sent for transcription', 422);
    }

    // Idempotent: a client that retries a transcription it already got back is answered from
    // the stored result instead of paying for the same call twice.
    if ((string)($row['transcription_status'] ?? 'none') === 'ready'
        && trim((string)($row['transcription_text'] ?? '')) !== '') {
        api_json(['ok' => true, 'attachment' => api_ca_payload($row, $actor), 'reused' => true]);
    }

    $path = consultation_attachment_absolute_path($row);
    if ($path === null) api_error('the file is no longer available', 410);

    $language = (string)($in['language'] ?? 'ar');
    if (!preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $language)) $language = 'ar';

    consultation_attachment_record_transcription_consent($pdo, $attachmentId, $actor['id']);
    consultation_attachment_set_transcription($pdo, $attachmentId, 'processing', null, null, null, $language);

    $settings = ai_load_settings($pdo);
    $provider = consultation_attachment_transcription_provider($settings);
    if ($provider === null) {
        consultation_attachment_set_transcription($pdo, $attachmentId, 'failed', null,
            consultation_attachment_msg('لا يوجد مزوّد ذكاء اصطناعي مهيّأ لتحويل الصوت إلى نص.',
                'No AI provider configured for speech-to-text.'));
        api_error('speech-to-text is not available: no transcription provider is configured', 503);
    }

    // The tenant's own privacy policy decides whether this recording may leave the platform.
    // The actor here is always a client, so the tenant is the platform policy row — the same
    // context the client AI assistant uses. A denial is reported as `declined`, not `failed`:
    // nothing broke, the policy said no.
    $aiTenant = ai_gateway_tenant_context($pdo, 'client', $actor['id']);
    $gate = ai_gateway_authorize($pdo, $settings, $aiTenant, 'consultation-transcription', 'client_information',
        $actor['id'], 'client');

    if (!$gate['allowed']) {
        consultation_attachment_set_transcription($pdo, $attachmentId, 'declined', null,
            ai_deny_message((string)($gate['reason'] ?? 'denied')));
        api_error('transcription was declined by the platform AI policy: ' . (string)($gate['reason'] ?? 'denied'), 403);
    }

    $started = microtime(true);
    $result = consultation_attachment_transcribe_audio($settings, $path, $language);
    $durationMs = (int)round((microtime(true) - $started) * 1000);

    ai_gateway_record(
        $pdo, $settings, $aiTenant, 'consultation-transcription', 'client_information',
        $actor['id'], 'client', $result['ok'], null, null, (int)($row['file_size'] ?? 0),
        $result['ok'] ? null : 'transcription_failed', $durationMs,
        'explicit_consent', null
    );

    if (!$result['ok']) {
        consultation_attachment_set_transcription($pdo, $attachmentId, 'failed', null,
            (string)$result['error'], $provider, $language);
        api_error((string)$result['error'], 502);
    }

    consultation_attachment_set_transcription($pdo, $attachmentId, 'ready',
        (string)$result['text'], null, $provider, $language);

    require_once __DIR__ . '/../../includes/audit_log.php';
    log_action($pdo, 'تحويل تسجيل صوتي إلى نص: ' . (string)$row['original_name'], 'consultation_attachment', $attachmentId);

    $fresh = consultation_attachment_find($pdo, $attachmentId);
    api_json(['ok' => true, 'attachment' => api_ca_payload($fresh, $actor)]);
}

// ------------------------------------------------------------
// Upload — staged (draft_token) or direct (consultation_id)
// ------------------------------------------------------------
// A body over post_max_size reaches PHP with $_POST and $_FILES empty. Say so, because
// "no file uploaded" would send a mobile developer looking in the wrong place.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    api_error('the request exceeded the server size limit; upload files one at a time', 413);
}
if (!isset($_FILES['file'])) {
    api_error('no file uploaded (field name must be "file")');
}

$draftToken = (string)($_POST['draft_token'] ?? '');
$consultationId = (int)($_POST['consultation_id'] ?? 0);

if ($draftToken !== '') {
    // Stage: the consultation does not exist yet. Only a client books, so only a client
    // stages. The token is unguessable and the claim also checks the uploader identity.
    if ($actor['type'] !== 'client') api_error('forbidden', 403);
    if (!preg_match('/^[a-f0-9]{64}$/', $draftToken)) api_error('invalid draft token');

    // Cap the number of files one draft may accumulate, so the form cannot be used to fill
    // the disk by leaving uploads staged without ever submitting.
    $staged = consultation_attachment_staged($pdo, $draftToken, $actor['id']);
    if (count($staged) >= (int)$limits['max_files']) {
        api_error('you have reached the maximum of ' . (int)$limits['max_files'] . ' attachments', 422);
    }

    $quota = consultation_attachment_quota_check($pdo, 0, $actor['id'], (int)($_FILES['file']['size'] ?? 0), $limits);
    if (!$quota['allowed']) api_error($quota['message'], 422);

    $result = consultation_attachment_stage($pdo, $draftToken, $_FILES['file'], $actor, $limits);
    if (!$result['ok']) api_error((string)$result['error'], 422);

    require_once __DIR__ . '/../../includes/audit_log.php';
    log_action($pdo, 'إرفاق ملف باستشارة (مسودة جوال): ' . (string)$result['attachment']['original_name'], 'consultation_attachment', (int)$result['attachment']['id']);

    api_json([
        'ok' => true,
        'attachment' => api_ca_payload($result['attachment'], $actor),
        'used' => count($staged) + 1,
        'max_files' => (int)$limits['max_files'],
    ], 201);
}

$consultation = consultation_attachment_authorize($pdo, $consultationId, $actor);
if (!$consultation) api_error('consultation not found', 404);
if (!consultation_attachment_can_upload($consultation, $actor)) {
    api_error('attachments cannot be added to this consultation in its current state', 403);
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

api_json(['ok' => true, 'attachment' => api_ca_payload($result['attachment'], $actor)], 201);
