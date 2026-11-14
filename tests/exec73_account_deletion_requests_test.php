<?php
/**
 * Account-deletion **requests** contract test (Execution 73).
 *
 * Google Play's Data safety form requires a public, no-login account-deletion route and a
 * documented workflow behind it. This pins the properties that make the shipped
 * implementation safe rather than the shape of the code:
 *
 *   * an unknown identifier never reveals whether an account exists (same shape returned);
 *   * ownership is proven by a single-use, time-limited token — an unverified request is
 *     never executable;
 *   * execution anonymises the account (never a hard delete), keeps the invoice, and
 *     revokes every API token, exactly as the in-app path does;
 *   * the identifier is stored only as a hash, never in plaintext for lookup;
 *   * a verified request is closed and its audit trail records the steps.
 *
 * Runs against the configured database and cleans up every row it creates.
 *
 * Run: php tests/exec73_account_deletion_requests_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/account_deletion.php';
require_once __DIR__ . '/../includes/account_deletion_requests.php';
require_once __DIR__ . '/../includes/audit_log.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

if (!account_deletion_requests_ready($pdo)) {
    echo "SKIP: account_deletion_requests table missing — apply database/upgrade_v77.sql\n";
    exit(0);
}

$suffix = bin2hex(random_bytes(4));
$email  = "adr-$suffix@example.test";
$phone  = '+9665' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$pw     = 'Test-Password-2026';

// invoices.lawyer_id is NOT NULL; reuse an approved lawyer rather than inventing one.
$lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
if ($lawyerId <= 0) {
    echo "SKIP: no approved lawyer exists to own the probe invoice\n";
    exit(0);
}

// ---------------------------------------------------------------- fixtures
$pdo->prepare("INSERT INTO users (full_name,email,phone,password_hash,status,email_verified) VALUES (?,?,?,?, 'active',1)")
    ->execute(['ADR Probe', $email, $phone, password_hash($pw, PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO invoices (user_id, lawyer_id, invoice_number, description, amount, currency, status, issue_date)
               VALUES (?,?,?,?, 100.000, 'SAR', 'paid', CURDATE())")
    ->execute([$userId, $lawyerId, 'ADR-' . $suffix, 'Deletion-request probe invoice']);
$invoiceId = (int)$pdo->lastInsertId();

$token = 'mwq_' . bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO api_tokens (token,user_type,user_id,expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 1 DAY))")
    ->execute([$token, 'client', $userId]);

$createdRefs = [];
$cleanup = function () use ($pdo, $userId, $invoiceId, $createdRefs) {
    foreach ($createdRefs as $rid) {
        $pdo->prepare('DELETE FROM account_deletion_events WHERE request_id=?')->execute([$rid]);
        $pdo->prepare('DELETE FROM account_deletion_requests WHERE id=?')->execute([$rid]);
    }
    $pdo->prepare('DELETE FROM api_tokens WHERE user_id=? AND user_type=?')->execute([$userId, 'client']);
    $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$invoiceId]);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
};

echo "== 1. identifier normalisation and hashing ==\n";
check('email normalised to lower-case', account_deletion_normalize_identifier('  A@B.COM ') === 'a@b.com');
check('phone normalised to digits', account_deletion_normalize_identifier('+966 55-123-4567') === '966551234567');
check('identifier kind: email', account_deletion_identifier_kind('a@b.com') === 'email');
check('identifier kind: phone', account_deletion_identifier_kind('0551234567') === 'phone');
check('hash is 64 hex', preg_match('/^[a-f0-9]{64}$/', account_deletion_identifier_hash('a@b.com')) === 1);
check('hash does not contain the plaintext', strpos(account_deletion_identifier_hash($email), $email) === false);

echo "== 2. account lookup by email and by phone ==\n";
$byEmail = account_deletion_find_accounts($pdo, $email);
check('email lookup finds exactly the seeded client', count($byEmail) === 1 && (int)$byEmail[0]['account_id'] === $userId && $byEmail[0]['account_type'] === 'client');
$byPhone = account_deletion_find_accounts($pdo, $phone);
check('phone lookup finds the seeded client', count($byPhone) >= 1 && (int)$byPhone[0]['account_id'] === $userId);
check('unknown identifier finds nothing', account_deletion_find_accounts($pdo, 'nobody-' . $suffix . '@example.test') === []);

echo "== 3. request creation ==\n";
$r1 = account_deletion_request_start($pdo, $email, 'probe');
check('request created', $r1['ok'] === true && $r1['reference'] !== null);
check('request matched the account', $r1['matched'] === true);
$createdRefs[] = (int)$r1['request_id'];
$row = account_deletion_request_get($pdo, $r1['reference']);
check('row stored with unverified status', $row && $row['verification_status'] === 'unverified' && $row['status'] === 'pending');
check('row stored identifier hash, not plaintext', $row && $row['identifier_hash'] === account_deletion_identifier_hash($email));
check('a token was issued (hash stored)', $row && !empty($row['verify_token_hash']));

echo "== 4. unknown identifier: no leak, no token ==\n";
$r2 = account_deletion_request_start($pdo, 'ghost-' . $suffix . '@example.test', '');
check('unknown identifier still returns ok (no enumeration)', $r2['ok'] === true && $r2['reference'] !== null);
check('unknown identifier reports matched=false', $r2['matched'] === false);
$createdRefs[] = (int)$r2['request_id'];
$row2 = account_deletion_request_get($pdo, $r2['reference']);
check('unknown identifier has no account linked', $row2 && empty($row2['account_id']));
check('unknown identifier has no verification token', $row2 && empty($row2['verify_token_hash']));

echo "== 5. verification is single-use and time-limited ==\n";
$tok = bin2hex(random_bytes(32));
$pdo->prepare('UPDATE account_deletion_requests SET verify_token_hash=?, verify_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?')
    ->execute([hash('sha256', $tok), (int)$r1['request_id']]);
$v = account_deletion_verify($pdo, $tok);
check('valid token verifies the request', $v['ok'] === true && $v['reference'] === $r1['reference']);
$rowAfter = account_deletion_request_get($pdo, $r1['reference']);
check('verified status persisted', $rowAfter['verification_status'] === 'verified');
check('token cleared after use', empty($rowAfter['verify_token_hash']));
$v2 = account_deletion_verify($pdo, $tok);
check('the same token cannot be used twice', $v2['ok'] === false);

echo "== 6. expired / malformed tokens are refused ==\n";
$expTok = bin2hex(random_bytes(32));
$pdo->prepare('UPDATE account_deletion_requests SET verify_token_hash=?, verify_expires=DATE_SUB(NOW(), INTERVAL 1 MINUTE), verification_status=\'unverified\' WHERE id=?')
    ->execute([hash('sha256', $expTok), (int)$r2['request_id']]);
check('expired token refused', account_deletion_verify($pdo, $expTok)['ok'] === false);
check('malformed token refused', account_deletion_verify($pdo, 'not-a-token')['ok'] === false);

echo "== 7. execution requires verification ==\n";
$r3 = account_deletion_request_start($pdo, $email, 'unverified probe');
$createdRefs[] = (int)$r3['request_id'];
$ex = account_deletion_execute($pdo, $r3['reference'], 1, 'probe');
check('unverified request is not executable', $ex['ok'] === false && $ex['error'] === 'not_verified');

echo "== 8. execution of a verified request ==\n";
$ex2 = account_deletion_execute($pdo, $r1['reference'], 1, 'probe run');
check('verified request executes', $ex2['ok'] === true);

$stillThere = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id=$userId")->fetchColumn();
check('account row still exists (anonymised, not hard-deleted)', $stillThere === 1);
$urow = $pdo->query("SELECT full_name,email,password_hash,status FROM users WHERE id=$userId")->fetch();
check('name anonymised', $urow['full_name'] !== 'ADR Probe');
check('email replaced with tombstone', strpos((string)$urow['email'], 'deleted+') === 0);
check('account suspended', $urow['status'] === 'suspended');
check('password hash unusable', !password_verify($pw, (string)$urow['password_hash']));

$invStill = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE id=$invoiceId")->fetchColumn();
check('the issued invoice is preserved', $invStill === 1);
$tokRevoked = (int)$pdo->query("SELECT COUNT(*) FROM api_tokens WHERE user_id=$userId AND user_type='client' AND revoked_at IS NULL")->fetchColumn();
check('every API token revoked', $tokRevoked === 0);

$done = account_deletion_request_get($pdo, $r1['reference']);
check('request marked completed', $done['status'] === 'completed');
check('retention summary recorded', !empty($done['retention_summary']));
check('processed_by recorded', (int)$done['processed_by_admin_id'] === 1);

$events = account_deletion_request_events($pdo, (int)$r1['request_id']);
$names = array_column($events, 'event');
check('audit trail has created + verified + completed', in_array('created', $names, true) && in_array('verified', $names, true) && in_array('completed', $names, true));

echo "== 9. rejection ==\n";
$rej = account_deletion_reject($pdo, $r3['reference'], 1, 'could not verify');
check('request can be rejected', $rej['ok'] === true);
check('rejected status persisted', account_deletion_request_get($pdo, $r3['reference'])['status'] === 'rejected');

echo "== 10. public status text ==\n";
$st = account_deletion_public_status(['status' => 'completed', 'verification_status' => 'verified']);
check('public status has AR and EN', isset($st['ar'], $st['en']));

echo "== 11. a deleted account is not re-deletable and re-registration is possible ==\n";
$re = account_delete($pdo, 'client', $userId, $pw);
check('re-deleting an anonymised account is refused', $re['ok'] === false);
// The original address was freed by the anonymisation (it is now a tombstone), so the
// same person can register again with it. That is the property the unique index must
// preserve.
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
    ->execute(['Re-registered', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$reregId = (int)$pdo->lastInsertId();
check('the original address can register again after deletion', $reregId > 0);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$reregId]);

$cleanup();
echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
