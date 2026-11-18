<?php
/**
 * POST /api/v1/auth/login.php
 * body: { "email": "...", "password": "...", "device_label": "iPhone 15" }
 * response: { ok, token, user: {...} }
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/auth_security.php';

$in = api_input();
$email = isset($in['email']) && is_string($in['email']) ? strtolower(trim($in['email'])) : '';
$password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
$deviceLabel = isset($in['device_label']) && is_string($in['device_label']) ? mb_substr(trim($in['device_label']), 0, 150, 'UTF-8') : null;

if (!$email || !$password) api_error('email and password are required');
if (strlen($email) > 254 || strlen($password) > 4096 || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('invalid email, password, or input length');
if (auth_rate_limit_remaining($pdo, 'client', $email) > 0) api_error('too many attempts; wait 15 minutes and try again', 429);

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

// A stable machine code rather than prose, so the app can answer in the user's
// language. Wrong credentials stay generic; the states below are only named once
// the password has already been verified.
if (!$user || !password_verify($password, $user['password_hash'])) {
    auth_rate_limit_failure($pdo, 'client', $email);
    api_error('invalid_credentials', 401);
}
if (empty($user['email_verified'])) {
    api_error('email_not_verified', 403);
}
if (($user['status'] ?? '') !== 'active') {
    api_error('account_not_approved', 403);
}
auth_rate_limit_clear($pdo, 'client', $email);

$token = api_issue_token($pdo, 'client', (int)$user['id'], $deviceLabel);

api_json([
    'ok' => true,
    'token' => $token,
    'user' => [
        'id' => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'account_type' => $user['account_type'],
        'preferred_lang' => $user['preferred_lang'],
    ],
]);
