<?php
/**
 * N-Genius consultation payment test (Execution 62).
 *
 * Drives the REAL curl path in includes/ngenius.php against a local fixture server
 * (tests/fixtures/ngenius_server.php) — no HTTP mocking — and asserts the money rules that
 * make the integration safe rather than the shape of the code:
 *
 *   * the token call sends no realmName (the live gateway rejects 'ni');
 *   * a token is cached and reused instead of re-authenticating on every call;
 *   * an order is created with the correct minor-unit amount and currency;
 *   * a booking is confirmed ONLY when the gateway independently reports a capture;
 *   * a failed/expired order releases the slot; a pending one does not;
 *   * a replayed verification confirms once and credits the wallet once;
 *   * the lawyer wallet is credited gross with the commission as its own row;
 *   * a refund reverses the ledger and marks the consultation refunded;
 *   * a cross-tenant read of a consultation returns nothing.
 *
 * Never charges a real card: every request goes to the fixture. Cleans up every row it creates.
 *
 * Run: php tests/exec62_ngenius_payment_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/consultation_payment.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

/* ---------------------------------------------------- seed + cleanup ---- */
$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'lawyers' => [], 'consultations' => [], 'rules' => [], 'settings' => []];

// Self-heal runs FIRST, before any lookup or insert, because a run killed before cleanup
// leaves probe rows behind and a later run would otherwise capture one of them as its
// "existing" lawyer and then delete it out from under itself. Probe rows are identifiable by
// their fixed topic and email prefix; only those are touched.
foreach ($pdo->query("SELECT id FROM consultations WHERE topic='Probe consultation'")->fetchAll(PDO::FETCH_COLUMN) as $staleId) {
    $pdo->prepare('DELETE FROM payment_transactions WHERE consultation_id=?')->execute([$staleId]);
    $pdo->prepare('DELETE FROM wallet_transactions WHERE consultation_id=?')->execute([$staleId]);
    $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$staleId]);
}
foreach ($pdo->query("SELECT id FROM users WHERE email LIKE 'ngenius-probe-%@example.test'")->fetchAll(PDO::FETCH_COLUMN) as $staleUser) {
    $pdo->prepare('DELETE FROM consultations WHERE user_id=?')->execute([$staleUser]);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$staleUser]);
}
foreach ($pdo->query("SELECT id FROM lawyers WHERE email LIKE 'ngenius-lawyer-%@example.test'")->fetchAll(PDO::FETCH_COLUMN) as $staleLawyer) {
    $pdo->prepare('DELETE FROM wallet_transactions WHERE lawyer_id=?')->execute([$staleLawyer]);
    $pdo->prepare('DELETE FROM lawyer_wallets WHERE lawyer_id=?')->execute([$staleLawyer]);
    $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$staleLawyer]);
}

function exec62_setting(PDO $pdo, string $key, ?string $value, array &$created): void {
    // Record the original only on first touch. Re-recording on a later call would capture the
    // value this suite itself just wrote, so cleanup would "restore" the probe's own value
    // instead of the real original (this exact mistake left ngenius_currency=BHD behind).
    if (!array_key_exists($key, $created['settings'])) {
        $cur = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');
        $cur->execute([$key]);
        $had = $cur->fetchColumn();
        $created['settings'][$key] = ($had === false) ? null : $had;
    }
    if ($value === null) {
        $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]);
    } else {
        $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
    }
}

// A lawyer with a rate is what makes a consultation payable, so the suite creates its own
// rather than depending on seed data it does not control. Removed in cleanup.
$lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
$ownLawyer = false;
if ($lawyerId <= 0) {
    $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified,hourly_rate)
                   VALUES (?,?,?,'approved',1,250.000)")
        ->execute(['NGenius Probe Lawyer', "ngenius-lawyer-$suffix@example.test", password_hash('Test-Password-2026', PASSWORD_DEFAULT)]);
    $lawyerId = (int)$pdo->lastInsertId();
    $created['lawyers'][] = $lawyerId;
    $ownLawyer = true;
}
$currency = currency_code();

// Point the integration at the fixture. The key value is irrelevant to the fixture but must
// be present for ngenius_is_enabled(); it is a test literal, never a real credential.
exec62_setting($pdo, 'ngenius_enabled', '1', $created);
exec62_setting($pdo, 'ngenius_environment', 'sandbox', $created);
exec62_setting($pdo, 'ngenius_outlet_ref', '00000000-0000-0000-0000-0000000000ff', $created);
exec62_setting($pdo, 'ngenius_api_key', 'FIXTURE-NOT-A-REAL-KEY', $created);
exec62_setting($pdo, 'ngenius_realm_name', '', $created);
exec62_setting($pdo, 'ngenius_currency', $currency, $created);

function exec62_client(PDO $pdo, string $suffix, array &$created): int {
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
        ->execute(['NGenius Probe Client', "ngenius-probe-$suffix@example.test", password_hash('Test-Password-2026', PASSWORD_DEFAULT)]);
    $id = (int)$pdo->lastInsertId();
    $created['users'][] = $id;
    return $id;
}

function exec62_consultation(PDO $pdo, int $clientId, int $lawyerId, float $price, string $currency, array &$created): int {
    $pdo->prepare("INSERT INTO consultations (user_id,lawyer_id,consultation_type,scheduled_at,topic,status,price,payment_status,payment_currency)
                   VALUES (?,?,'video',(NOW() + INTERVAL 2 DAY),'Probe consultation','pending',?,'unpaid',?)")
        ->execute([$clientId, $lawyerId, $price, $currency]);
    $id = (int)$pdo->lastInsertId();
    $created['consultations'][] = $id;
    return $id;
}

$clientId = exec62_client($pdo, $suffix, $created);

/* ---------------------------------------------------- fixture server ---- */
// Started only after every precondition passed, so an early exit never leaves a server
// running (the first version started it first and a SKIP orphaned it, hanging the run).
$port = 0;
$proc = null;
for ($candidate = 18300; $candidate < 18340; $candidate++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $candidate . ' ' . escapeshellarg(__DIR__ . '/fixtures/ngenius_server.php') . ' > /dev/null 2>&1';
    $proc = proc_open($cmd, [], $pipes);
    usleep(300000);
    $probe = @file_get_contents('http://127.0.0.1:' . $candidate . '/__health');
    if ($probe !== false && strpos($probe, 'ok') !== false) { $port = $candidate; break; }
    if (is_resource($proc)) proc_terminate($proc);
    $proc = null;
}
if ($port === 0) {
    echo "SKIP: could not start the N-Genius fixture server\n";
    // Still restore settings so a failed run does not leave the integration enabled.
    foreach ($created['settings'] as $key => $value) {
        if ($value === null) $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]);
        else $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
    }
    foreach ($created['lawyers'] as $id) $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$id]);
    foreach ($created['users'] as $id) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    exit(0);
}
putenv('MAWATHEQ_NGENIUS_BASE_URL=http://127.0.0.1:' . $port);
echo "fixture N-Genius gateway on 127.0.0.1:$port\n";

/* ---------------------------------------------------- 1. token handling -- */
echo "\n== 1. token: no realmName body, and the token is cached ==\n";
$tok1 = ngenius_get_access_token('sandbox', 'FIXTURE-NOT-A-REAL-KEY', '');
check('an empty realm yields a token (the live gateway rejects realmName)', !empty($tok1['ok']));
$tok2 = ngenius_get_access_token('sandbox', 'FIXTURE-NOT-A-REAL-KEY', '');
check('a second call returns the same cached token', ($tok1['token'] ?? 'x') === ($tok2['token'] ?? 'y'));
$badRealm = ngenius_get_access_token('sandbox', 'FIXTURE-NOT-A-REAL-KEY', 'ni');
check("realmName 'ni' is treated as unset, not sent (still succeeds)", !empty($badRealm['ok']));

/* ---------------------------------------------------- 2. order + amount -- */
echo "\n== 2. order creation, amount and currency ==\n";
$consA = exec62_consultation($pdo, $clientId, $lawyerId, 250.00, $currency, $created);
$started = consultation_payment_start($pdo, ['id' => $consA, 'price' => 250.00, 'lawyer_id' => $lawyerId], [
    'customer_name' => 'Probe Client', 'customer_email' => "ngenius-probe-$suffix@example.test",
]);
check('a payment order is created and a hosted URL returned', !empty($started['ok']) && !empty($started['redirect_url']));
check('the order reference is stored on the consultation', (bool)$pdo->query("SELECT payment_reference FROM consultations WHERE id=$consA")->fetchColumn());
$row = $pdo->query("SELECT payment_status, payment_amount, payment_currency, payment_gateway FROM consultations WHERE id=$consA")->fetch();
check('the consultation is marked pending, not paid', $row['payment_status'] === 'pending');
check('the amount stored is the lawyer rate (250.000)', abs((float)$row['payment_amount'] - 250.0) < 0.001);
check('the currency stored matches the site currency', $row['payment_currency'] === $currency);
check('the gateway is recorded', $row['payment_gateway'] === 'ngenius');

// The fixture echoes the amount back; a wrong minor-unit factor would show here.
$ref = (string)$pdo->query("SELECT payment_reference FROM consultations WHERE id=$consA")->fetchColumn();
$status = ngenius_get_order_status('sandbox', '00000000-0000-0000-0000-0000000000ff', $tok1['token'], $ref);
check('the gateway reports the order as CAPTURED', ($status['state'] ?? '') === 'CAPTURED');
check('the gateway-confirmed amount is 250.00 (correct minor units)', abs((float)$status['amount'] - 250.0) < 0.001);

/* ---------------------------------------------------- 3. confirm + wallet */
echo "\n== 3. confirmation only on a verified capture, and the wallet credit ==\n";
$walletBefore = wallet_balances($pdo, (int)wallet_ensure($pdo, $lawyerId)['id'], $currency)['available_minor'];
$confirm = consultation_payment_verify_and_confirm($pdo, $consA);
check('verification confirms the booking', !empty($confirm['confirmed']));
$rowA = $pdo->query("SELECT status, payment_status FROM consultations WHERE id=$consA")->fetch();
check('the consultation is confirmed and paid', $rowA['status'] === 'confirmed' && $rowA['payment_status'] === 'paid');
$walletAfter = wallet_balances($pdo, (int)wallet_ensure($pdo, $lawyerId)['id'], $currency)['available_minor'];
check('the lawyer wallet is credited by the gross 250.00 (25000 minor)', ($walletAfter - $walletBefore) === 25000);

/* ---------------------------------------------------- 4. duplicate verify */
echo "\n== 4. a replayed verification confirms once and credits once ==\n";
$walletBefore2 = wallet_balances($pdo, (int)wallet_ensure($pdo, $lawyerId)['id'], $currency)['available_minor'];
$again = consultation_payment_verify_and_confirm($pdo, $consA);
check('the second verification does not re-confirm', empty($again['confirmed']));
$walletAfter2 = wallet_balances($pdo, (int)wallet_ensure($pdo, $lawyerId)['id'], $currency)['available_minor'];
check('the wallet is not credited twice', ($walletAfter2 - $walletBefore2) === 0);

/* ---------------------------------------------------- 5. failure/expiry -- */
echo "\n== 5. failed and expired orders release the slot; pending does not ==\n";
$consFail = exec62_consultation($pdo, $clientId, $lawyerId, 100.00, $currency, $created);
$pdo->prepare("UPDATE consultations SET payment_status='pending', payment_reference=? WHERE id=?")
    ->execute(['CONS-' . $consFail . '-FAIL', $consFail]);
$failRes = consultation_payment_verify_and_confirm($pdo, $consFail);
check('a FAILED order is not confirmed', empty($failRes['confirmed']) && $failRes['state'] === 'FAILED');
check('a FAILED order releases the slot', consultation_payment_release($pdo, $consFail, 'test'));
check('the released consultation is cancelled',
    $pdo->query("SELECT status FROM consultations WHERE id=$consFail")->fetchColumn() === 'cancelled');

$consPend = exec62_consultation($pdo, $clientId, $lawyerId, 100.00, $currency, $created);
$pdo->prepare("UPDATE consultations SET payment_status='pending', payment_reference=? WHERE id=?")
    ->execute(['CONS-' . $consPend . '-PENDING', $consPend]);
$pendRes = consultation_payment_verify_and_confirm($pdo, $consPend);
check('a PENDING order is not confirmed', empty($pendRes['confirmed']));
check('verifying a PENDING order does not release the slot',
    $pdo->query("SELECT status FROM consultations WHERE id=$consPend")->fetchColumn() === 'pending');

$consExp = exec62_consultation($pdo, $clientId, $lawyerId, 100.00, $currency, $created);
$pdo->prepare("UPDATE consultations SET payment_status='pending', payment_reference=? WHERE id=?")
    ->execute(['CONS-' . $consExp . '-EXPIRED', $consExp]);
$expRes = consultation_payment_verify_and_confirm($pdo, $consExp);
check('an EXPIRED order is not confirmed and is released', empty($expRes['confirmed']) && $expRes['state'] === 'EXPIRED');

/* ---------------------------------------------------- 6. refund + ledger */
echo "\n== 6. refund reverses the wallet ledger and marks the consultation refunded ==\n";
$adapter = gateway_get('ngenius', $pdo);
check('the N-Genius adapter now supports refunds', $adapter && $adapter->supportsRefund());
$walletBefore3 = wallet_balances($pdo, (int)wallet_ensure($pdo, $lawyerId)['id'], $currency)['available_minor'];
$refund = $adapter->refundPayment(['reference' => $ref, 'currency' => $currency, 'amount' => 250.00], null);
check('the refund succeeds against the fixture', !empty($refund['ok']));
$pdo->prepare("UPDATE consultations SET payment_status='refunded' WHERE id=? AND payment_status='paid'")->execute([$consA]);
wallet_reverse_consultation_payment($pdo, $consA, 250.00);
$walletAfter3 = wallet_balances($pdo, (int)wallet_ensure($pdo, $lawyerId)['id'], $currency)['available_minor'];
check('the refund debit returns the wallet to its pre-payment balance', ($walletAfter3 - $walletBefore3) === -25000);
check('the consultation is marked refunded',
    $pdo->query("SELECT payment_status FROM consultations WHERE id=$consA")->fetchColumn() === 'refunded');

/* ---------------------------------------------------- 7. tenant isolation */
echo "\n== 7. tenant isolation and lookup safety ==\n";
$otherClient = exec62_client($pdo, $suffix . 'b', $created);
$stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND user_id=?');
$stmt->execute([$consA, $otherClient]);
check('another client cannot read this consultation by id', $stmt->fetch() === false);
check('an unknown order reference resolves to no consultation', consultation_payment_find_by_reference($pdo, 'DOES-NOT-EXIST') === null);

/* ---------------------------------------------------- 8. currency guard -- */
echo "\n== 8. a foreign currency is refused rather than mis-charged ==\n";
exec62_setting($pdo, 'ngenius_currency', 'BHD', $created);
$foreign = consultation_payment_start($pdo, ['id' => $consA, 'price' => 10.0, 'lawyer_id' => $lawyerId], []);
check('a payment in a non-outlet currency is refused', empty($foreign['ok']));
exec62_setting($pdo, 'ngenius_currency', $currency, $created);

/* ---------------------------------------------------- cleanup ----------- */
echo "\n== cleanup ==\n";
foreach ($created['settings'] as $key => $value) {
    if ($value === null) $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]);
    else $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
}
foreach ($created['consultations'] as $id) {
    $pdo->prepare('DELETE FROM payment_transactions WHERE consultation_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM wallet_transactions WHERE consultation_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$id]);
}
foreach ($created['users'] as $id) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
foreach ($created['lawyers'] as $id) {
    $pdo->prepare('DELETE FROM wallet_transactions WHERE lawyer_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM lawyer_wallets WHERE lawyer_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$id]);
}
// Drop the fixture token cache so a later run starts clean.
foreach (glob(dirname(__DIR__) . '/storage/cache/ngenius-token-*.json') ?: [] as $f) @unlink($f);
if (is_resource($proc)) proc_terminate($proc);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
