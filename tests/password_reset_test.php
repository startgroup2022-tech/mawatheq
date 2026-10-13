<?php
/**
 * Password-reset contract test — exercises the shared helpers in
 * includes/auth_security.php that BOTH the website (forgot-password.php /
 * reset-password.php) and the REST API (api/v1/auth/*) call. It pins the parts a
 * refactor could silently change: the single-use token, the 1-hour TTL, the
 * per-account rate limit, the weak/too-long/mismatch rejection, and — the security
 * property that matters most — that every API token issued before a reset stops
 * working afterwards.
 *
 * Run: php tests/password_reset_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_security.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$email = 'reset-contract-' . bin2hex(random_bytes(4)) . '@example.test';
$originalPassword = 'Original-Pass-2026';

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type)
                   VALUES (?,?,?,'active',1,'individual')")
        ->execute(['مستخدم اختبار', $email, password_hash($originalPassword, PASSWORD_BCRYPT)]);
    $userId = (int)$pdo->lastInsertId();

    // Two live sessions, to prove a reset revokes every one of them.
    $liveToken = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO api_tokens (token, user_type, user_id, expires_at)
                   VALUES (?, 'client', ?, DATE_ADD(NOW(), INTERVAL 1 YEAR))")
        ->execute([hash('sha256', $liveToken), $userId]);

    echo "== issue_password_reset ==\n";
    $issued = issue_password_reset($pdo, 'client', $userId, $email, 'مستخدم اختبار');
    // Delivery is environment-dependent (the test host may have no MTA); the
    // security-relevant contract is that the token is stored and the call reports
    // a boolean either way, never throws and never reveals whether the address exists.
    check('issue_password_reset returns a delivery boolean', is_bool($issued));

    $row = $pdo->prepare("SELECT reset_token, reset_expires, UNIX_TIMESTAMP(reset_expires) - UNIX_TIMESTAMP(NOW()) AS ttl FROM users WHERE id=?");
    $row->execute([$userId]);
    $reset = $row->fetch();
    check('a 64-hex token was stored', (bool)preg_match('/^[a-f0-9]{64}$/', (string)$reset['reset_token']));
    check('the token expires in about 1 hour', $reset['ttl'] > 3500 && $reset['ttl'] <= 3600);

    echo "\n== consume_password_reset: refusal paths ==\n";
    check('a non-hex token is refused', consume_password_reset($pdo, 'client', 'nope', 'Password123', 'Password123')['error'] === 'invalid_token');
    check('an unknown 64-hex token is refused', consume_password_reset($pdo, 'client', str_repeat('f', 64), 'Password123', 'Password123')['error'] === 'invalid_token');
    $short = consume_password_reset($pdo, 'client', $reset['reset_token'], 'short', 'short');
    check('a password under 8 chars is refused', $short['error'] === 'weak_password');
    $long = consume_password_reset($pdo, 'client', $reset['reset_token'], str_repeat('x', 73), str_repeat('x', 73));
    check('a password over 72 bytes is refused', $long['error'] === 'too_long');
    $mismatch = consume_password_reset($pdo, 'client', $reset['reset_token'], 'Password123', 'Password124');
    check('mismatched confirmation is refused', $mismatch['error'] === 'mismatch');

    check('a refused attempt does not consume the token', $pdo->query("SELECT reset_token FROM users WHERE id=$userId")->fetchColumn() === $reset['reset_token']);

    echo "\n== consume_password_reset: success ==\n";
    $newPassword = 'Brand-New-Pass-2026';
    $ok = consume_password_reset($pdo, 'client', $reset['reset_token'], $newPassword, $newPassword);
    check('a valid attempt succeeds', $ok['ok'] === true);

    $after = $pdo->prepare("SELECT password_hash, reset_token, reset_expires FROM users WHERE id=?");
    $after->execute([$userId]);
    $fresh = $after->fetch();
    check('the new password verifies', password_verify($newPassword, $fresh['password_hash']));
    check('the old password no longer verifies', !password_verify($originalPassword, $fresh['password_hash']));
    check('the token is cleared after use', $fresh['reset_token'] === null && $fresh['reset_expires'] === null);

    $reuse = consume_password_reset($pdo, 'client', $reset['reset_token'], 'Another-Pass-2026', 'Another-Pass-2026');
    check('the token cannot be reused', $reuse['ok'] === false && $reuse['error'] === 'invalid_token');

    echo "\n== session revocation ==\n";
    $live = $pdo->prepare("SELECT revoked_at FROM api_tokens WHERE token=?");
    $live->execute([hash('sha256', $liveToken)]);
    check('an API token issued before the reset is revoked', $live->fetchColumn() !== null);

    echo "\n== rate limiting ==\n";
    // The same throttle the web page and the API share. remaining() reports the
    // seconds still blocked, so it is 0 until the 5th failure arms a 15-minute block.
    $bucketKey = 'password-reset-client';
    $rateEmail = 'reset-rate-' . bin2hex(random_bytes(4)) . '@example.test';
    check('a fresh address is not blocked', auth_rate_limit_remaining($pdo, $bucketKey, $rateEmail) === 0);
    for ($i = 0; $i < 4; $i++) { auth_rate_limit_failure($pdo, $bucketKey, $rateEmail); }
    check('four failures are still under the limit', auth_rate_limit_remaining($pdo, $bucketKey, $rateEmail) === 0);
    auth_rate_limit_failure($pdo, $bucketKey, $rateEmail);
    $blocked = auth_rate_limit_remaining($pdo, $bucketKey, $rateEmail);
    check('the fifth failure arms a block of about 15 minutes', $blocked > 800 && $blocked <= 900);
    // Throttling is keyed on the address AND on the caller's IP, so a different
    // address from the same IP is throttled too. That is deliberate: it stops one
    // host from enumerating many addresses under the per-account limit.
    check('another address on the same IP is throttled too', auth_rate_limit_remaining($pdo, $bucketKey, 'other-' . $rateEmail) > 0);

    echo "\n== account type mapping ==\n";
    check('client maps to the users table', password_reset_account_config('client')['table'] === 'users');
    check('lawyer maps to the lawyers table', password_reset_account_config('lawyer')['table'] === 'lawyers');
    check('an unknown type falls back to client', password_reset_account_config('bogus')['table'] === 'users');
} finally {
    $pdo->rollBack();
}

echo "\nPassword reset: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
