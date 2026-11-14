<?php
/**
 * GET /api/v1/account/deletion-status.php
 * headers: Authorization: Bearer {token}
 * response: { ok: true, has_request: bool, request: { reference, status, status_label, verification, requested_at } | null }
 *
 * Google Play requires the app to show the user the status of their account-deletion
 * request. The in-app deletion (`/api/v1/account/delete.php`) is immediate, but a user
 * may also have started a request on the public website; this returns the latest such
 * request for the signed-in account so the app can show where it stands.
 *
 * Only public-safe fields are returned: the request reference, its status, whether
 * ownership was verified, and when it was made. The identifier and the linked account id
 * are never exposed — the caller already knows their own account, and echoing the
 * identifier would let a shared device read the registered address from the response.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/account_deletion.php';
require_once __DIR__ . '/../../../includes/account_deletion_requests.php';

$auth = api_authenticate($pdo);
if ($auth['type'] === 'integration') {
    api_error('لا تملك صلاحية الوصول إلى هذا العنصر.', 403);
}

$type = $auth['type'] === 'lawyer' ? 'lawyer' : 'client';
$accountId = (int)$auth['id'];

if (!account_deletion_requests_ready($pdo)) {
    // The migration has not been applied on this deployment; report "no request" rather
    // than erroring, so the app screen still renders.
    api_json(['ok' => true, 'has_request' => false, 'request' => null]);
}

try {
    $stmt = $pdo->prepare("SELECT reference, status, verification_status, requested_at
        FROM account_deletion_requests
        WHERE account_type=? AND account_id=?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$type, $accountId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    api_json(['ok' => true, 'has_request' => false, 'request' => null]);
}

if (!$row) {
    api_json(['ok' => true, 'has_request' => false, 'request' => null]);
}

$label = account_deletion_public_status($row);
api_json([
    'ok' => true,
    'has_request' => true,
    'request' => [
        'reference'   => (string)$row['reference'],
        'status'      => (string)$row['status'],
        'status_ar'   => $label['ar'],
        'status_en'   => $label['en'],
        'verified'    => (string)$row['verification_status'] === 'verified',
        'requested_at' => (string)$row['requested_at'],
    ],
]);
