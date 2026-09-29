<?php
/**
 * POST /api/v1/auth/register.php
 * body: { full_name, email, password, phone (E.164 e.g. +9665xxxxxxxx), account_type }
 * response: { ok, verification_required, email_sent, user: {...} }; token is issued after email confirmation and login.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/plan_limits.php';
require_once __DIR__ . '/../../../includes/auth_security.php';

$in = api_input();
$fullName = isset($in['full_name']) && is_string($in['full_name']) ? trim($in['full_name']) : '';
$email = isset($in['email']) && is_string($in['email']) ? strtolower(trim($in['email'])) : '';
$password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
$phone = isset($in['phone']) && is_string($in['phone']) ? trim($in['phone']) : '';
$accountType = isset($in['account_type']) && is_string($in['account_type']) ? $in['account_type'] : 'individual';

if (!$fullName || !$email || strlen($password) < 8 || !in_array($accountType, ['individual','company'], true)) {
    api_error('full_name, email and valid account_type are required; password must be at least 8 characters');
}
if (strlen($fullName) > 180 || strlen($email) > 254 || strlen($password) > 72) {
    api_error('name/email/password exceeds the allowed length');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('invalid email format');
}
if ($phone !== '' && !preg_match('/^\\+[1-9][0-9]{6,14}$/D', $phone)) {
    api_error('phone must be in E.164 format, for example +9665xxxxxxxx');
}
if (auth_rate_limit_remaining($pdo, 'register-client', $email) > 0) api_error('too many sign-up requests; wait 15 minutes', 429);

$stmt = $pdo->prepare('SELECT email FROM users WHERE email=? UNION SELECT email FROM lawyers WHERE email=? LIMIT 1');
$stmt->execute([$email, $email]);
if ($stmt->fetch()) {
    api_error('email already registered', 409);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$planId = default_plan_id($pdo);
$pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash, account_type, preferred_lang, plan_id, email_verified) VALUES (?,?,?,?,?,?,?,0)')
    ->execute([$fullName, $email, $phone ?: null, $hash, $accountType, 'ar', $planId]);
$userId = (int)$pdo->lastInsertId();
auth_rate_limit_failure($pdo, 'register-client', $email);

$mailSent = create_email_verification($pdo, 'client', $userId, $email, $fullName);

api_json([
    'ok' => true,
    'verification_required' => true,
    'email_sent' => $mailSent,
    'message' => $mailSent ? 'Please verify your email before signing in.' : 'Account created; email delivery failed. Request a new verification link from the website.',
    'user' => ['id' => $userId, 'full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'account_type' => $accountType],
], 202);
