<?php
/**
 * POST /api/v1/auth/lawyer-login.php
 * body: { email, password, device_label }
 * response: { ok, token, lawyer: {...} }
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/auth_security.php';

$in = api_input();
$email = isset($in['email']) && is_string($in['email']) ? strtolower(trim($in['email'])) : '';
$password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
$deviceLabel = isset($in['device_label']) && is_string($in['device_label']) ? mb_substr(trim($in['device_label']), 0, 150, 'UTF-8') : null;

if (!$email || !$password) api_error('email and password are required');
if (strlen($email) > 254 || strlen($password) > 4096 || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('invalid email, password, or input length');
if (auth_rate_limit_remaining($pdo, 'lawyer', $email) > 0) api_error('too many attempts; wait 15 minutes and try again', 429);

$stmt = $pdo->prepare('SELECT * FROM lawyers WHERE email = ?');
$stmt->execute([$email]);
$lawyer = $stmt->fetch();

if (!$lawyer || !password_verify($password, $lawyer['password_hash']) || empty($lawyer['email_verified']) || ($lawyer['status'] ?? '') !== 'approved') {
    auth_rate_limit_failure($pdo, 'lawyer', $email);
    api_error('invalid email or password', 401);
}
auth_rate_limit_clear($pdo, 'lawyer', $email);

$token = api_issue_token($pdo, 'lawyer', (int)$lawyer['id'], $deviceLabel);

api_json([
    'ok' => true,
    'token' => $token,
    'lawyer' => [
        'id' => (int)$lawyer['id'],
        'full_name' => $lawyer['full_name'],
        'email' => $lawyer['email'],
        'specialization' => $lawyer['specialization'],
        'rating' => (float)$lawyer['rating'],
        'photo_path' => $lawyer['photo_path'],
    ],
]);
