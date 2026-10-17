<?php
/**
 * Consultation attachment download — the ONLY way to read an attachment file.
 *
 * The file lives outside the web root, so there is no public URL to guess; this route is
 * the single door, and it re-checks authorization on every request rather than trusting a
 * token embedded in a URL. A signed link would be the alternative, but a signed link
 * cannot be revoked when a consultation is reassigned or a lawyer leaves the firm, and it
 * leaks access through browser history, proxies and referrers.
 *
 * Authorization, in order:
 *   1. The caller must be a signed-in client, lawyer or admin.
 *   2. The attachment row must exist and be `stored` (not staged, not deleted).
 *   3. The caller must own the consultation — a client by user_id, a lawyer through their
 *      tenant (firm-wide or solo), an admin through the console RBAC map. A lawyer from an
 *      unrelated firm is refused even with a correct attachment id.
 *   4. The file must resolve inside the private storage root, and its name must match the
 *      generated pattern. This is the check that makes a tampered `stored_path` harmless.
 *
 * ?inline=1 requests an inline preview and is honoured only for a small allow-list of
 * formats that are safe to render in the origin (consultation_attachment_preview_mime).
 * Everything else — including anything unrecognised — is sent as a download with
 * X-Content-Type-Options: nosniff, so a mislabelled file cannot be interpreted as script.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/consultation_attachments.php';

/** Refuse without revealing whether the attachment exists. */
function ca_download_deny(int $code = 404): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($code === 403 ? 'Forbidden' : 'Not found');
}

// ------------------------------------------------------------
// 1) Identify the caller.
// ------------------------------------------------------------
$actor = null;
if (!empty($_SESSION['admin_id'])) {
    // The console route decides which admin roles may manage attachments at all. The role
    // is re-read from the database so a demoted operator loses access immediately.
    require_once __DIR__ . '/includes/admin_rbac.php';
    if (!admin_can_access($pdo, 'consultation-attachments.php')) ca_download_deny(403);
    $actor = ['type' => 'admin', 'id' => (int)$_SESSION['admin_id']];
} elseif (!empty($_SESSION['lawyer_id'])) {
    $actor = ['type' => 'lawyer', 'id' => (int)$_SESSION['lawyer_id']];
} elseif (!empty($_SESSION['user_id'])) {
    $actor = ['type' => 'client', 'id' => (int)$_SESSION['user_id']];
} else {
    // A browser GET with no session: send the user to sign in rather than a bare 404.
    header('Location: /login.php');
    exit;
}

$attachmentId = (int)($_GET['id'] ?? 0);
if ($attachmentId <= 0) ca_download_deny(400);

// ------------------------------------------------------------
// 2) The row must exist and be a live attachment.
// ------------------------------------------------------------
$row = consultation_attachment_find($pdo, $attachmentId);
if (!$row || ($row['status'] ?? '') !== 'stored' || empty($row['consultation_id'])) {
    ca_download_deny(404);
}

// ------------------------------------------------------------
// 3) The caller must own the consultation.
// ------------------------------------------------------------
$consultation = consultation_attachment_authorize($pdo, (int)$row['consultation_id'], $actor);
if (!$consultation) ca_download_deny(404);

// A firm-tagged attachment must match the caller's tenant. consultation_attachment_authorize
// already scopes a lawyer through their tenant, so this is a second, cheap assertion of the
// same invariant: if the two ever disagreed, the request is refused rather than served.
if ($actor['type'] === 'lawyer' && $row['firm_id'] !== null) {
    require_once __DIR__ . '/includes/tenant.php';
    $firmId = tenant_firm_id($pdo);
    if ($firmId === null || (int)$firmId !== (int)$row['firm_id']) ca_download_deny(404);
}

// ------------------------------------------------------------
// 4) Resolve the file inside the private storage root.
// ------------------------------------------------------------
$path = consultation_attachment_absolute_path($row);
if ($path === null) {
    // The row is live but the file is gone. 410 tells the client the reference was valid
    // and is not any more, which is more actionable than a plain 404.
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Gone');
}
$extension = strtolower((string)$row['file_type']);
if (!preg_match('/^[a-f0-9]{40}\.' . preg_quote($extension, '/') . '$/', basename($path))) {
    ca_download_deny(404);
}

// ------------------------------------------------------------
// 5) Send it.
// ------------------------------------------------------------
$inline = (string)($_GET['inline'] ?? '') === '1';
$previewMime = consultation_attachment_preview_mime($row['mime_type'] ?? null);
$sendInline = $inline && $previewMime !== null;

$size = (int)filesize($path);
// The stored name is random and the row is immutable, so the content is stable and can be
// cached privately by the browser; `private` keeps it out of shared proxies.
$etag = '"' . substr((string)($row['checksum'] ?? hash_file('sha256', $path)), 0, 32) . '"';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$displayName = consultation_attachment_safe_name((string)$row['original_name']);
$fallbackName = 'consultation-' . (int)$row['consultation_id'] . '-attachment-' . $attachmentId . '.' . $extension;

header('Content-Type: ' . ($sendInline ? $previewMime : 'application/octet-stream'));
header('Content-Length: ' . $size);
if ($sendInline) {
    // filename is still supplied so "save as" produces a sensible name.
    header('Content-Disposition: inline; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
} else {
    // attachment forces a save and, combined with nosniff, prevents the browser from
    // rendering a file whose real type disagrees with its declared type.
    header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
}

consultation_attachment_record_download($pdo, $attachmentId);

// Stream in chunks so a 100 MB video does not have to be held in memory.
$handle = fopen($path, 'rb');
if ($handle === false) ca_download_deny(404);
while (!feof($handle)) {
    $chunk = fread($handle, 262144);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($handle);
exit;
