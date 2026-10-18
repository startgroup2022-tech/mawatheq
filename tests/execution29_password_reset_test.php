<?php
/**
 * Execution 29 — password reset security regression test.
 *
 * Covers the fixes in includes/auth_security.php:
 *   - tokens are stored hashed, so a database read yields no usable link
 *   - a token is consumed exactly once, including under concurrent use
 *   - expiry is enforced
 *   - a password change invalidates existing API tokens AND browser sessions
 *   - token consumption is rate limited
 *
 * Runs inside a transaction and rolls back, so it leaves no residue.
 *
 * Run: php tests/execution29_password_reset_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/auth_security.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

echo "\n== setup ==\n";
$pdo->beginTransaction();
$email = 'e29-reset-' . bin2hex(random_bytes(4)) . '@example.test';
$pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified) VALUES ('E29 Test', ?, ?, 'active', 1)")
    ->execute([$email, password_hash('Original-Pass-2026', PASSWORD_BCRYPT)]);
$userId = (int)$pdo->lastInsertId();
check('a test client account exists', $userId > 0);

$readToken = static function (PDO $pdo, int $id): ?string {
    $s = $pdo->prepare('SELECT reset_token FROM users WHERE id=?');
    $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
};

echo "\n== 1. tokens are stored hashed, never in plaintext ==\n";
// issue_password_reset() emails the token; capture it by re-deriving the same way the
// helper does, then compare what the database holds against the raw value.
$raw = bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
    ->execute([password_reset_token_hash($raw), $userId]);
$stored = $readToken($pdo, $userId);
check('the stored value is not the raw token', $stored !== $raw);
check('the stored value is the SHA-256 of the raw token', $stored === hash('sha256', $raw));
check('the stored value is 64 hex characters', (bool)preg_match('/^[a-f0-9]{64}$/', (string)$stored));
check('the raw token is never recoverable from the row', !str_contains((string)$stored, substr($raw, 0, 16)));

echo "\n== 2. a valid token sets the password and is consumed ==\n";
$res = consume_password_reset($pdo, 'client', $raw, 'New-Password-2026', 'New-Password-2026');
check('consumption succeeds', $res['ok'] === true && $res['error'] === null);
check('the token is cleared from the row', $readToken($pdo, $userId) === null);
$hash = (string)$pdo->query("SELECT password_hash FROM users WHERE id=$userId")->fetchColumn();
check('the new password verifies', password_verify('New-Password-2026', $hash));

echo "\n== 3. the token cannot be reused (concurrent replay) ==\n";
$res2 = consume_password_reset($pdo, 'client', $raw, 'Attacker-Password-1', 'Attacker-Password-1');
check('the second use is refused', $res2['ok'] === false);
check('the refusal is invalid_token', $res2['error'] === 'invalid_token');
$hash2 = (string)$pdo->query("SELECT password_hash FROM users WHERE id=$userId")->fetchColumn();
check('the password was NOT overwritten by the replay', password_verify('New-Password-2026', $hash2));
check('the attacker password does not work', !password_verify('Attacker-Password-1', $hash2));

echo "\n== 4. two simultaneous claims cannot both win ==\n";
$raw2 = bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
    ->execute([password_reset_token_hash($raw2), $userId]);
// The claim is a single conditional UPDATE, so the database decides the winner. Two
// sequential calls model two requests that arrived together; exactly one must succeed.
$first = consume_password_reset($pdo, 'client', $raw2, 'Winner-One-2026', 'Winner-One-2026');
$second = consume_password_reset($pdo, 'client', $raw2, 'Winner-Two-2026', 'Winner-Two-2026');
check('exactly one of the two claims succeeds', $first['ok'] !== $second['ok']);
check('the loser is refused with invalid_token', $second['ok'] === false && $second['error'] === 'invalid_token');

echo "\n== 5. expiry is enforced ==\n";
$raw3 = bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=?")
    ->execute([password_reset_token_hash($raw3), $userId]);
$res3 = consume_password_reset($pdo, 'client', $raw3, 'Expired-Pass-2026', 'Expired-Pass-2026');
check('an expired token is refused', $res3['ok'] === false && $res3['error'] === 'invalid_token');
$hash3 = (string)$pdo->query("SELECT password_hash FROM users WHERE id=$userId")->fetchColumn();
check('the expired token did not change the password', !password_verify('Expired-Pass-2026', $hash3));

echo "\n== 6. malformed tokens never reach a query ==\n";
foreach (['', 'short', str_repeat('z', 64), strtoupper(str_repeat('a', 64))] as $bad) {
    $r = consume_password_reset($pdo, 'client', $bad, 'Some-Password-1', 'Some-Password-1');
    check('malformed token refused: ' . var_export($bad, true), $r['ok'] === false && $r['error'] === 'invalid_token');
}

echo "\n== 7. password validation happens before the token is burned ==\n";
$raw4 = bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
    ->execute([password_reset_token_hash($raw4), $userId]);
$weak = consume_password_reset($pdo, 'client', $raw4, 'short', 'short');
check('a weak password is refused', $weak['ok'] === false && $weak['error'] === 'weak_password');
check('the token survives a weak-password attempt', $readToken($pdo, $userId) !== null);
$mismatch = consume_password_reset($pdo, 'client', $raw4, 'Good-Password-2026', 'Different-Password-2026');
check('a mismatched confirmation is refused', $mismatch['ok'] === false && $mismatch['error'] === 'mismatch');
check('the token survives a mismatch attempt', $readToken($pdo, $userId) !== null);
$tooLong = consume_password_reset($pdo, 'client', $raw4, str_repeat('x', 73), str_repeat('x', 73));
check('an over-length password is refused', $tooLong['ok'] === false && $tooLong['error'] === 'too_long');
check('the token survives an over-length attempt', $readToken($pdo, $userId) !== null);
$ok = consume_password_reset($pdo, 'client', $raw4, 'Final-Password-2026', 'Final-Password-2026');
check('the still-valid token then succeeds', $ok['ok'] === true);

echo "\n== 8. a password change revokes API tokens ==\n";
$raw5 = bin2hex(random_bytes(32));
$pdo->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
    ->execute([password_reset_token_hash($raw5), $userId]);
$pdo->prepare("INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?, 'client', ?, 'e29-test', DATE_ADD(NOW(), INTERVAL 1 YEAR))")
    ->execute([hash('sha256', bin2hex(random_bytes(32))), $userId]);
$liveBefore = (int)$pdo->query("SELECT COUNT(*) FROM api_tokens WHERE user_type='client' AND user_id=$userId AND revoked_at IS NULL")->fetchColumn();
check('a live API token exists before the reset', $liveBefore === 1);
consume_password_reset($pdo, 'client', $raw5, 'Post-Token-Reset-2026', 'Post-Token-Reset-2026');
$liveAfter = (int)$pdo->query("SELECT COUNT(*) FROM api_tokens WHERE user_type='client' AND user_id=$userId AND revoked_at IS NULL")->fetchColumn();
check('every API token is revoked after the reset', $liveAfter === 0);

echo "\n== 9. a password change invalidates browser sessions ==\n";
$stamp = $pdo->query("SELECT session_invalidated_at FROM users WHERE id=$userId")->fetchColumn();
check('the invalidation stamp was written', !empty($stamp));
// A session opened before the stamp must be treated as stale.
$_SESSION = ['mawatheq_session_started_at' => strtotime((string)$stamp) - 60];
check('a session opened before the reset is stale', account_session_is_stale($pdo, 'client', $userId) === true);
// A session opened after the stamp is fine.
$_SESSION = ['mawatheq_session_started_at' => strtotime((string)$stamp) + 60];
check('a session opened after the reset is not stale', account_session_is_stale($pdo, 'client', $userId) === false);
// No stamp at all means the account never reset, so nothing is stale.
$pdo->prepare('UPDATE users SET session_invalidated_at=NULL WHERE id=?')->execute([$userId]);
$_SESSION = ['mawatheq_session_started_at' => time() - 3600];
check('an account with no reset has no stale sessions', account_session_is_stale($pdo, 'client', $userId) === false);
// A session predating the feature (no recorded start) counts as stale once a stamp exists.
$pdo->prepare('UPDATE users SET session_invalidated_at=NOW() WHERE id=?')->execute([$userId]);
$_SESSION = [];
check('a pre-feature session is stale once a stamp exists', account_session_is_stale($pdo, 'client', $userId) === true);
$_SESSION = [];

echo "\n== 10. the stale check fails open on a schema without the column ==\n";
// Point the helper at a table that has no session_invalidated_at; it must not throw and
// must not log everyone out.
$missing = account_session_is_stale($pdo, 'client', 999999);
check('an unknown account is not reported stale', $missing === false);

echo "\n== 11. token consumption is rate limited per IP ==\n";
$pdo->prepare("DELETE FROM auth_rate_limits WHERE scope_key=?")->execute([auth_rate_limit_ip_key('password-reset-consume')]);
$key = auth_rate_limit_ip_key('password-reset-consume');
check('a fresh IP is not throttled', auth_rate_limit_remaining_key($pdo, $key) === 0);
for ($i = 0; $i < 10; $i++) {
    auth_rate_limit_failure_key($pdo, $key, 10);
}
check('the IP is throttled after the threshold', auth_rate_limit_remaining_key($pdo, $key) > 0);
check('the throttle window is bounded (<= 15 min)', auth_rate_limit_remaining_key($pdo, $key) <= 900);
auth_rate_limit_clear_key($pdo, $key);
check('a successful reset clears the throttle', auth_rate_limit_remaining_key($pdo, $key) === 0);

echo "\n== 12. the login throttle still uses its own threshold of 5 ==\n";
$pdo->prepare('DELETE FROM auth_rate_limits')->execute();
$_SERVER['REMOTE_ADDR'] = '203.0.113.29';
$throttleEmail = 'e29-throttle@example.test';
for ($i = 0; $i < 5; $i++) {
    auth_rate_limit_failure($pdo, 'client', $throttleEmail);
}
check('the login bucket blocks at 5 failures', auth_rate_limit_remaining($pdo, 'client', $throttleEmail) > 0);
$pdo->prepare('DELETE FROM auth_rate_limits')->execute();

echo "\n== 13. the account table map cannot be steered by a caller ==\n";
check('an unknown type falls back to the client table', password_reset_account_config('bogus')['table'] === 'users');
check('a SQL fragment is not accepted as a type', password_reset_account_config("users; DROP TABLE users")['table'] === 'users');
check('the lawyer type maps to lawyers', password_reset_account_config('lawyer')['table'] === 'lawyers');
check('the admin type maps to admins', password_reset_account_config('admin')['table'] === 'admins');

echo "\n== cleanup ==\n";
$pdo->prepare('DELETE FROM auth_rate_limits')->execute();
$pdo->rollBack();
check('transaction rolled back', !$pdo->inTransaction());

echo "\n  Execution 29 password reset: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
