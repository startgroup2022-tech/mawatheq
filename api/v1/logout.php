<?php
/**
 * POST /api/v1/logout.php
 * headers: Authorization: Bearer {token}
 * response: { ok: true }
 *
 * Ends the current device's session by revoking exactly the token that authenticated this
 * request. A client that only deletes the token locally leaves it valid on the server
 * until it expires, so anyone who had captured it keeps access — this closes that gap.
 *
 * Revokes only the caller's own token: a shared device must not be able to sign the
 * account out everywhere by accident. `revoke-token.php` already exists for that and
 * behaves identically for the current token, so this endpoint is the documented,
 * discoverable name the mobile client uses. It is deliberately idempotent — logging out
 * twice, or with an already-revoked token, answers 200 rather than an error, so a client
 * retrying after a dropped connection does not surface a spurious failure.
 *
 * An integration token has no session to end and is refused rather than silently ignored.
 */
require_once __DIR__ . '/bootstrap.php';

$auth = api_authenticate($pdo);

if (($auth['type'] ?? '') === 'integration') {
    api_error('integration tokens are not sessions; revoke them from the admin console', 403);
}

$stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=? AND user_type=? AND user_id=? AND revoked_at IS NULL');
$stmt->execute([$auth['token_id'], $auth['type'], $auth['id']]);

api_json(['ok' => true, 'message' => 'signed out']);
