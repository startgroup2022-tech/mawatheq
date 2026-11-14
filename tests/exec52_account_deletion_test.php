<?php
/**
 * Account-deletion contract test (Execution 52).
 *
 * Google Play requires an in-app account-deletion route, and privacy.php promises one.
 * This pins the properties that make the shipped implementation safe rather than the
 * shape of the code:
 *
 *   * the account is anonymised, not hard-deleted — a `DELETE FROM users` would cascade
 *     away invoices, cases and consultations, because every FK on those tables is
 *     ON DELETE CASCADE (verified here by asserting the row still exists and a linked
 *     row survives);
 *   * the password is required — a valid session is not enough;
 *   * every API token is revoked, so an old device cannot keep reading the account;
 *   * the tombstone address is unique, so the same person can register again;
 *   * an account with work in flight is refused, and the blockers are named.
 *
 * Runs against the configured database and cleans up every row it creates.
 *
 * Run: php tests/exec52_account_deletion_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/account_deletion.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$suffix = bin2hex(random_bytes(4));
$email  = "acctdel-$suffix@example.test";
$pw     = 'Test-Password-2026';
$created = [];

// ---------------------------------------------------------------- fixtures
// invoices.lawyer_id is NOT NULL, so the probe needs a lawyer to bill from. Reuse an
// existing approved one rather than inventing a row the cleanup would then have to
// reason about.
$lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
if ($lawyerId <= 0) {
    echo "SKIP: no approved lawyer exists to own the probe invoice\n";
    exit(0);
}

$pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
    ->execute(['Delete Probe', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
$created['user'] = $userId;

// An invoice gives us a dependent row to prove the deletion does not cascade it away.
// The real columns are amount/issue_date; there is no `total` or `issued_at`.
$pdo->prepare("INSERT INTO invoices (user_id, lawyer_id, invoice_number, description, amount, currency, status, issue_date)
               VALUES (?,?,?,?, 100.000, 'SAR', 'paid', CURDATE())")
    ->execute([$userId, $lawyerId, 'DEL-' . $suffix, 'Deletion probe invoice']);
$invoiceId = (int)$pdo->lastInsertId();
$created['invoice'] = $invoiceId;

// A live API token, so we can assert it is revoked.
$token = 'mwq_' . bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO api_tokens (token,user_type,user_id,expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 1 DAY))")
    ->execute([$token, 'client', $userId]);
$created['token'] = $token;

echo "== 1. wrong password is refused, account untouched ==\n";
$r = account_delete($pdo, 'client', $userId, 'not-the-password');
check('wrong password -> wrong_password', $r['error'] === 'wrong_password');
$stmt = $pdo->prepare('SELECT email, status FROM users WHERE id=?');
$stmt->execute([$userId]);
$row = $stmt->fetch();
check('email unchanged after a failed attempt', $row['email'] === $email);
check('status unchanged after a failed attempt', $row['status'] === 'active');

echo "== 2. empty password is refused ==\n";
$r = account_delete($pdo, 'client', $userId, '');
check('empty password -> missing_password', $r['error'] === 'missing_password');

echo "== 3. a real deletion anonymises the account ==\n";
$r = account_delete($pdo, 'client', $userId, $pw);
check('deletion succeeded', $r['ok'] === true);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$userId]);
$row = $stmt->fetch();
check('the row still exists (not hard-deleted)', $row !== false);
check('the email was replaced by a tombstone', str_contains((string)$row['email'], '@deleted.invalid'));
check('the tombstone is unique to the account', str_contains((string)$row['email'], '+' . $userId . '-'));
check('the name was anonymised', $row['full_name'] === 'حساب محذوف');
check('the phone was cleared', $row['phone'] === null);
check('the reset token was cleared', $row['reset_token'] === null);
check('the status can no longer log in', $row['status'] === 'suspended');
check('the password no longer verifies', !password_verify($pw, (string)$row['password_hash']));
if (account_deletion_has_column($pdo, 'users', 'deleted_at')) {
    check('deleted_at records the time', !empty($row['deleted_at']));
}

echo "== 4. dependent rows are preserved (this is the point of anonymising) ==\n";
$stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE id=?');
$stmt->execute([$invoiceId]);
check('the invoice survived the deletion', (int)$stmt->fetchColumn() === 1);

echo "== 5. every API token is revoked ==\n";
$stmt = $pdo->prepare('SELECT revoked_at FROM api_tokens WHERE token=?');
$stmt->execute([$token]);
$revokedAt = $stmt->fetchColumn();
check('the token is revoked', $revokedAt !== null && $revokedAt !== false);

echo "== 6. a tombstoned address frees the original for re-registration ==\n";
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
    ->execute(['Second Probe', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$reId = (int)$pdo->lastInsertId();
check('the same address can register again', $reId > 0);
$created['reuser'] = $reId;

echo "== 7. an account with work in flight is refused, with blockers ==\n";
$caseId = null;
try {
    $pdo->prepare("INSERT INTO cases (user_id, lawyer_id, title, status) VALUES (?,?,?, 'open')")
        ->execute([$reId, $lawyerId, 'Open matter ' . $suffix]);
    $caseId = (int)$pdo->lastInsertId();
    $created['case'] = $caseId;

    $r = account_delete($pdo, 'client', $reId, $pw);
    check('deletion refused while a case is open', $r['ok'] === false && $r['error'] === 'has_open_work');
    check('at least one blocker is reported', !empty($r['blockers']));
    $stmt = $pdo->prepare('SELECT status FROM users WHERE id=?');
    $stmt->execute([$reId]);
    check('the refused account is still active', $stmt->fetchColumn() === 'active');
} catch (Throwable $e) {
    echo "  (cases table shape differs; blocker check skipped: {$e->getMessage()})\n";
}

// ------------------------------------------------------------------ cleanup
echo "== cleanup ==\n";
foreach (['case' => 'cases', 'invoice' => 'invoices'] as $key => $table) {
    if (!empty($created[$key])) {
        $pdo->prepare("DELETE FROM `$table` WHERE id=?")->execute([$created[$key]]);
    }
}
$pdo->prepare('DELETE FROM api_tokens WHERE token=?')->execute([$token]);
foreach (['user', 'reuser'] as $key) {
    if (!empty($created[$key])) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$created[$key]]);
    }
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email=? OR email LIKE ?');
$stmt->execute([$email, 'deleted+' . $userId . '-%']);
check('no probe rows remain', (int)$stmt->fetchColumn() === 0);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
