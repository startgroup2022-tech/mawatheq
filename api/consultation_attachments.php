<?php
/**
 * Consultation attachments — browser upload endpoint (session-authenticated).
 *
 * GET  ?draft_token=...            → the staged files for this booking form
 * GET  ?consultation_id=...        → the stored attachments of a consultation
 * POST action=stage                → store one picked file (multipart, field "file")
 * POST action=attach               → store one file against an existing consultation
 * POST action=discard              → remove one staged file the user changed their mind about
 * POST action=remove               → remove one stored attachment
 *
 * One file per request, deliberately. A multi-file <input> posts them all in a single
 * request, which makes a real per-file progress bar impossible (the browser reports only
 * the whole request) and makes a partial failure ambiguous. Uploading one at a time lets
 * the form show each file's own progress and its own error, which is what the brief asks
 * for, and it means one rejected file does not discard the others.
 *
 * CSRF is enforced by config.php for every session-backed POST, and this endpoint repeats
 * the check because it writes files. The token travels as a form field, which works for
 * multipart/form-data.
 *
 * Files staged here have no consultation yet. They are bound to one by booking.php when
 * the form is submitted, using the draft token. See includes/consultation_attachments.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/consultation_attachments.php';

header('Content-Type: application/json; charset=utf-8');

/** Answer and stop. */
function ca_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** The attachment fields a browser is allowed to see. Never leaks stored paths. */
function ca_public_row(array $row): array {
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['original_name'],
        'size' => (int)$row['file_size'],
        'size_label' => consultation_attachment_format_size((int)$row['file_size']),
        'category' => (string)$row['file_category'],
        'extension' => (string)$row['file_type'],
        'mime' => (string)$row['mime_type'],
        'previewable' => consultation_attachment_preview_mime($row['mime_type'] ?? null) !== null,
    ];
}

if (empty($_SESSION['user_id']) && empty($_SESSION['lawyer_id'])) {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('انتهت الجلسة. يرجى تسجيل الدخول من جديد.', 'Your session has ended. Please sign in again.')], 401);
}

// The booking form is a client surface, so a client session is the default. A lawyer
// session is accepted for the "attach to an existing consultation" action, and only
// through consultation_attachment_authorize(), which applies the tenant scope.
$actor = !empty($_SESSION['user_id'])
    ? ['type' => 'client', 'id' => (int)$_SESSION['user_id']]
    : ['type' => 'lawyer', 'id' => (int)$_SESSION['lawyer_id']];
$limits = consultation_attachment_limits($pdo);

// A request whose body exceeded post_max_size arrives with $_POST and $_FILES both empty.
// Reporting "no file" there would be a lie that sends the user hunting for a client-side
// bug, so the actual cause is named.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $contentLength > 0 && empty($_POST) && empty($_FILES)) {
    ca_json([
        'ok' => false,
        'error' => consultation_attachment_msg(
            'حجم الطلب تجاوز الحد المسموح على الخادم. ارفع الملفات واحداً واحداً أو صغّر حجمها.',
            'The request exceeded the server size limit. Upload files one at a time or reduce their size.'
        ),
        'code' => 'request_too_large',
    ], 413);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $draftToken = (string)($_GET['draft_token'] ?? '');
    if ($draftToken !== '') {
        if (!preg_match('/^[a-f0-9]{64}$/', $draftToken)) {
            ca_json(['ok' => false, 'error' => consultation_attachment_msg('طلب غير صالح.', 'Invalid request.')], 400);
        }
        $rows = consultation_attachment_staged($pdo, $draftToken, $actor['id']);
        ca_json(['ok' => true, 'files' => array_map('ca_public_row', $rows), 'limits' => $limits]);
    }

    $consultationId = (int)($_GET['consultation_id'] ?? 0);
    $consultation = consultation_attachment_authorize($pdo, $consultationId, $actor);
    if (!$consultation) {
        ca_json(['ok' => false, 'error' => consultation_attachment_msg('الاستشارة غير موجودة.', 'Consultation not found.')], 404);
    }
    $rows = consultation_attachment_list($pdo, $consultationId);
    ca_json([
        'ok' => true,
        'files' => array_map('ca_public_row', $rows),
        'can_upload' => consultation_attachment_can_upload($consultation, $actor),
        'limits' => $limits,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('طريقة غير مدعومة.', 'Method not allowed.')], 405);
}

if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('طلب غير صالح.', 'Invalid request.')], 403);
}

$action = (string)($_POST['action'] ?? 'stage');

if ($action === 'discard') {
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $ok = $attachmentId > 0 && consultation_attachment_discard_staged($pdo, $attachmentId, $actor['id']);
    ca_json($ok
        ? ['ok' => true]
        : ['ok' => false, 'error' => consultation_attachment_msg('تعذّر حذف الملف.', 'The file could not be removed.')], $ok ? 200 : 404);
}

if ($action === 'remove') {
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $row = $attachmentId > 0 ? consultation_attachment_find($pdo, $attachmentId) : null;
    if (!$row || ($row['status'] ?? '') !== 'stored' || empty($row['consultation_id'])) {
        ca_json(['ok' => false, 'error' => consultation_attachment_msg('المرفق غير موجود.', 'Attachment not found.')], 404);
    }
    $consultation = consultation_attachment_authorize($pdo, (int)$row['consultation_id'], $actor);
    if (!$consultation || !consultation_attachment_can_delete($row, $consultation, $actor)) {
        ca_json(['ok' => false, 'error' => consultation_attachment_msg('لا تملك صلاحية حذف هذا المرفق.', 'You may not remove this attachment.')], 403);
    }
    $ok = consultation_attachment_delete($pdo, $row, $actor);
    if ($ok) {
        require_once __DIR__ . '/../includes/audit_log.php';
        log_action($pdo, 'حذف مرفق استشارة: ' . (string)$row['original_name'], 'consultation_attachment', $attachmentId);
    }
    ca_json($ok ? ['ok' => true] : ['ok' => false, 'error' => consultation_attachment_msg('تعذّر حذف المرفق.', 'The attachment could not be removed.')], $ok ? 200 : 500);
}

// Storing against an EXISTING consultation (the detail dashboards), as opposed to
// staging against a booking form that has not been submitted yet.
if ($action === 'attach') {
    $consultationId = (int)($_POST['consultation_id'] ?? 0);
    $consultation = consultation_attachment_authorize($pdo, $consultationId, $actor);
    if (!$consultation) {
        ca_json(['ok' => false, 'error' => consultation_attachment_msg('الاستشارة غير موجودة.', 'Consultation not found.')], 404);
    }
    if (!consultation_attachment_can_upload($consultation, $actor)) {
        ca_json(['ok' => false, 'error' => consultation_attachment_msg('لا يمكن إضافة مرفقات لهذه الاستشارة في حالتها الحالية.', 'Attachments cannot be added to this consultation in its current state.')], 403);
    }
    if (!isset($_FILES['file'])) {
        ca_json(['ok' => false, 'error' => consultation_attachment_msg('لم يتم اختيار أي ملف.', 'No file was selected.')], 400);
    }
    $incoming = (int)($_FILES['file']['size'] ?? 0);
    $quota = consultation_attachment_quota_check($pdo, $consultationId, (int)$consultation['user_id'], $incoming, $limits);
    if (!$quota['allowed']) {
        ca_json(['ok' => false, 'error' => $quota['message'], 'code' => 'quota'], 422);
    }
    $result = consultation_attachment_store($pdo, $consultationId, $consultation['firm_id'] !== null ? (int)$consultation['firm_id'] : null, $_FILES['file'], $actor, $limits);
    if (!$result['ok']) {
        ca_json(['ok' => false, 'error' => $result['error'], 'code' => 'invalid'], 422);
    }
    require_once __DIR__ . '/../includes/audit_log.php';
    log_action($pdo, 'إرفاق ملف باستشارة: ' . (string)$result['attachment']['original_name'], 'consultation_attachment', (int)$result['attachment']['id']);
    ca_json(['ok' => true, 'file' => ca_public_row($result['attachment'])], 201);
}

if ($action !== 'stage') {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('إجراء غير معروف.', 'Unknown action.')], 400);
}

$draftToken = (string)($_POST['draft_token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $draftToken)) {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('طلب غير صالح.', 'Invalid request.')], 400);
}
// Draft staging belongs to the booking form, which only a client uses. A lawyer attaches
// through action=attach against a consultation they own.
if ($actor['type'] !== 'client') {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('طلب غير صالح.', 'Invalid request.')], 403);
}
if (!isset($_FILES['file'])) {
    ca_json(['ok' => false, 'error' => consultation_attachment_msg('لم يتم اختيار أي ملف.', 'No file was selected.')], 400);
}

// Cap the number of files a single draft may accumulate, so the form cannot be used to
// fill the disk by leaving uploads staged without ever booking.
$staged = consultation_attachment_staged($pdo, $draftToken, $actor['id']);
if (count($staged) >= $limits['max_files']) {
    ca_json([
        'ok' => false,
        'error' => consultation_attachment_msg(
            'وصلت للحد الأقصى لعدد المرفقات (' . $limits['max_files'] . ' ملفات).',
            'You have reached the maximum of ' . $limits['max_files'] . ' attachments.'
        ),
        'code' => 'max_files',
    ], 422);
}

$incoming = (int)($_FILES['file']['size'] ?? 0);
$quota = consultation_attachment_quota_check($pdo, 0, $actor['id'], $incoming, $limits);
if (!$quota['allowed']) {
    ca_json(['ok' => false, 'error' => $quota['message'], 'code' => 'quota'], 422);
}

$result = consultation_attachment_stage($pdo, $draftToken, $_FILES['file'], $actor, $limits);
if (!$result['ok']) {
    ca_json(['ok' => false, 'error' => $result['error'], 'code' => 'invalid'], 422);
}

require_once __DIR__ . '/../includes/audit_log.php';
log_action($pdo, 'إرفاق ملف باستشارة (مسودة): ' . (string)$result['attachment']['original_name'], 'consultation_attachment', (int)$result['attachment']['id']);

ca_json([
    'ok' => true,
    'file' => ca_public_row($result['attachment']),
    'used' => count($staged) + 1,
    'max_files' => $limits['max_files'],
], 201);
