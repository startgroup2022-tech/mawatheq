<?php
/**
 * POST /api/v1/auth/forgot-password.php
 * body: { email, account_type? (client|lawyer, default client) }
 * response: { ok: true, message }  — always 200, whether or not the address exists.
 *
 * The response never reveals whether an account exists, and never returns the reset
 * token. Delivery happens only over the account's registered email; the link lands
 * on the website's reset page, which the app also opens natively via Android App
 * Links (see apps/mobile). Either way the same single-use token is consumed by
 * consume_password_reset().
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/auth_security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('method not allowed', 405);
}

$in = api_input();
$email = isset($in['email']) && is_string($in['email']) ? strtolower(trim($in['email'])) : '';
$accountType = isset($in['account_type']) && is_string($in['account_type']) ? $in['account_type'] : 'client';
if (!in_array($accountType, ['client', 'lawyer'], true)) {
    api_error('account_type must be client or lawyer');
}
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('a valid email is required');
}

// Throttle by account bucket + IP, same limits as the website's forgot-password page.
if (auth_rate_limit_remaining($pdo, 'password-reset-' . $accountType, $email) > 0) {
    api_error('too many requests; try again in 15 minutes', 429);
}
auth_rate_limit_failure($pdo, 'password-reset-' . $accountType, $email);

$cfg = password_reset_account_config($accountType);
$stmt = $pdo->prepare("SELECT id, full_name FROM {$cfg['table']} WHERE email = ?");
$stmt->execute([$email]);
$account = $stmt->fetch();
if ($account) {
    issue_password_reset($pdo, $accountType, (int)$account['id'], $email, (string)$account['full_name']);
}

api_json([
    'ok' => true,
    'message' => 'If that email is registered, a reset link has been sent. It is valid for 1 hour.',
]);
