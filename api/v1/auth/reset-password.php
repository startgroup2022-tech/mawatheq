<?php
/**
 * POST /api/v1/auth/reset-password.php
 * body: { token, password, password_confirm, account_type? (client|lawyer, default client) }
 * response: { ok: true } | { ok: false, error: <code> } (HTTP 410 for an invalid/expired
 * token, 422 for a weak/too-long/mismatched password)
 *
 * Consumes the same single-use token the emailed link carries, so the app's native
 * reset screen (opened by the reset link via App Links) and the website's
 * reset-password.php behave identically. On success every API token issued to the
 * account before the reset is revoked, so a stolen session cannot outlive the change.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/auth_security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('method not allowed', 405);
}

$in = api_input();
$token = isset($in['token']) && is_string($in['token']) ? trim($in['token']) : '';
$password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
$confirm = isset($in['password_confirm']) && is_string($in['password_confirm']) ? $in['password_confirm'] : '';
$accountType = isset($in['account_type']) && is_string($in['account_type']) ? $in['account_type'] : 'client';
if (!in_array($accountType, ['client', 'lawyer'], true)) {
    api_error('account_type must be client or lawyer');
}

$result = consume_password_reset($pdo, $accountType, $token, $password, $confirm);
if (!$result['ok']) {
    $code = $result['error'] === 'invalid_token' ? 410 : 422; // 410 Gone: link invalid/expired
    api_error($result['error'], $code);
}

api_json(['ok' => true, 'message' => 'Password changed. You can sign in now.']);
