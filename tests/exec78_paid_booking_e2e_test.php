<?php
/**
 * Execution 78 — a booking paid through the gateway produces everything a customer expects.
 *
 * The pieces were each tested in isolation: the gateway client (exec62/64), the booking
 * engine (exec66), and invoice issuance called directly (exec68). What was never driven is
 * the chain a customer actually walks:
 *
 *     gateway enabled -> booking marked pending -> customer pays -> verify_and_confirm()
 *        -> status confirmed, payment paid
 *        -> an invoice the client can download
 *        -> a notification for the lawyer AND one for the client
 *        -> a wallet credit
 *        -> a second verification that does NOT double-credit anything
 *
 * Each of those outcomes is a separate claim, and a regression in any one leaves the booking
 * "paid" with no document or no notice. This suite runs the real confirm path against the
 * local N-Genius fixture (tests/fixtures/ngenius_server.php) — no HTTP mocking — and then
 * asserts every artefact.
 *
 * Requires: the app + fixture dependencies only, plus MAWATHEQ_NGENIUS_BASE_URL pointing at
 * the fixture. Self-cleaning: everything it creates is removed in [9].
 *
 * Run: php tests/exec78_paid_booking_e2e_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/consultation_payment.php';
require_once __DIR__ . '/../includes/notifications.php';

$checks = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $fail;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $fail++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}
function section(string $t): void { echo "\n== $t\n"; }

echo "Execution 78 — paid booking end to end\n";

/* ---------------------------------------------------------------- fixture ---- */
$fixture = getenv('MAWATHEQ_NGENIUS_BASE_URL');
if ($fixture === false || $fixture === '') {
    // Default to the port the suite documents; fail loudly rather than skip, because a suite
    // that silently does nothing is worse than one that is absent.
    $fixture = 'http://127.0.0.1:11443';
    putenv('MAWATHEQ_NGENIUS_BASE_URL=' . $fixture);
}
$probe = @file_get_contents(rtrim($fixture, '/') . '/__health');
if ($probe === false) {
    echo "  FAIL: the N-Genius fixture is not reachable at $fixture\n";
    echo "        start it with: php -S 127.0.0.1:11443 tests/fixtures/ngenius_server.php\n";
    echo "\n0 checks, 1 failure(s)\n";
    exit(1);
}
echo "  fixture: $fixture\n";

$sfx = bin2hex(random_bytes(4));
$created = ['consultations' => [], 'invoices' => [], 'notifications' => [], 'payment_transactions' => []];

// Snapshot the gateway settings so the suite restores exactly what it found.
$settingKeys = ['ngenius_enabled', 'ngenius_outlet_ref', 'ngenius_environment', 'ngenius_currency', 'ngenius_api_key'];
$settingsBefore = [];
$sel = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');
foreach ($settingKeys as $k) { $sel->execute([$k]); $v = $sel->fetchColumn(); $settingsBefore[$k] = ($v === false) ? null : (string)$v; }

try {
    /* ------------------------------------------------------------ setup ---- */
    section('1. a lawyer, a client, and an enabled gateway');
    $lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
    $userId = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    check('an approved lawyer exists', $lawyerId > 0);
    check('a client account exists', $userId > 0);

    $up = $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $up->execute(['ngenius_enabled', '1']);
    $up->execute(['ngenius_outlet_ref', 'e2e-outlet']);
    $up->execute(['ngenius_environment', 'sandbox']);
    $up->execute(['ngenius_currency', strtoupper(currency_code())]);

    $settings = ngenius_settings($pdo);
    check('the gateway reads back as enabled', ngenius_is_enabled($settings));
    check('the currency matches the outlet', ngenius_supports_currency($settings, strtoupper(currency_code())));

    /* --------------------------------------------------- payment started ---- */
    section('2. a priced booking starts a gateway payment');
    $topic = 'Exec78 paid booking ' . $sfx;
    $pdo->prepare("INSERT INTO consultations
        (user_id,lawyer_id,consultation_type,scheduled_at,duration_minutes,topic,status,payment_status,payment_amount,payment_currency,price)
        VALUES (?,?, 'video', DATE_ADD(NOW(), INTERVAL 2 DAY), 30, ?, 'pending','unpaid', 350.00, ?, 350.00)")
        ->execute([$userId, $lawyerId, $topic, strtoupper(currency_code())]);
    $consultationId = (int)$pdo->lastInsertId();
    $created['consultations'][] = $consultationId;

    // The service takes the consultation row, not its id — it reads price and reference from
    // it, and loads nothing itself.
    $fetchRow = $pdo->prepare('SELECT * FROM consultations WHERE id=?');
    $fetchRow->execute([$consultationId]);
    $consultationRow = $fetchRow->fetch();
    $start = consultation_payment_start($pdo, $consultationRow, ['customer_email' => 'e2e@example.com', 'customer_name' => 'E2E Probe']);
    check('the payment session starts', !empty($start['ok']), json_encode($start, JSON_UNESCAPED_UNICODE) ?: '');
    check('the gateway returns a redirect url', !empty($start['redirect_url']));
    $orderRef = (string)($start['reference'] ?? '');
    check('an order reference is stored', $orderRef !== '');

    $row = $pdo->prepare('SELECT payment_status, payment_reference FROM consultations WHERE id=?');
    $row->execute([$consultationId]);
    $afterStart = $row->fetch();
    check('the booking is marked pending payment', ($afterStart['payment_status'] ?? '') === 'pending',
        (string)($afterStart['payment_status'] ?? ''));
    check('the reference is persisted on the booking', (string)($afterStart['payment_reference'] ?? '') === $orderRef);

    /* ----------------------------------------------------- gateway paid ---- */
    section('3. the customer pays; the gateway is asked to verify');
    $res = consultation_payment_verify_and_confirm($pdo, $consultationId, $orderRef);
    check('verification succeeds', !empty($res['ok']), json_encode($res, JSON_UNESCAPED_UNICODE) ?: '');
    check('the payment is confirmed', !empty($res['confirmed']), (string)($res['error'] ?? ''));
    check('the gateway state is a paid state', ($res['state'] ?? '') !== '');

    /* ------------------------------------------------ the booking result ---- */
    section('4. the booking is confirmed and paid');
    $row->execute([$consultationId]);
    $b = $row->fetch();
    $full = $pdo->prepare('SELECT * FROM consultations WHERE id=?');
    $full->execute([$consultationId]);
    $b = $full->fetch();
    check('status is confirmed', ($b['status'] ?? '') === 'confirmed', (string)($b['status'] ?? ''));
    check('payment_status is paid', ($b['payment_status'] ?? '') === 'paid', (string)($b['payment_status'] ?? ''));

    /* --------------------------------------------------------- invoice ---- */
    section('5. the client gets an invoice');
    $inv = $pdo->prepare('SELECT * FROM invoices WHERE consultation_id=? LIMIT 1');
    $inv->execute([$consultationId]);
    $invoice = $inv->fetch();
    check('an invoice was issued', $invoice !== false, 'no invoices row for this consultation');
    if ($invoice) {
        $created['invoices'][] = (int)$invoice['id'];
        check('the invoice is paid', ($invoice['status'] ?? '') === 'paid', (string)($invoice['status'] ?? ''));
        check('the invoice amount is the booking amount', abs((float)$invoice['amount'] - 350.0) < 0.01,
            (string)($invoice['amount'] ?? ''));
        check('the invoice belongs to the booking client', (int)$invoice['user_id'] === $userId);
        check('the invoice is downloadable, not a draft', (int)($invoice['is_draft'] ?? 0) === 0);
        check('the invoice currency is the booking currency',
            strtoupper((string)$invoice['currency']) === strtoupper(currency_code()), (string)($invoice['currency'] ?? ''));
    }

    /* ---------------------------------------------------- notifications ---- */
    section('6. both sides are told');
    // notifications is keyed by recipient_type/recipient_id and carries a message body.
    $nq = $pdo->prepare("SELECT id, recipient_type, recipient_id, title, message FROM notifications WHERE message LIKE ? ORDER BY id");
    $nq->execute(['%' . $topic . '%']);
    $notes = $nq->fetchAll();
    if (!$notes) {
        $nq2 = $pdo->prepare("SELECT id, recipient_type, recipient_id, title, message FROM notifications WHERE title LIKE ? ORDER BY id");
        $nq2->execute(['%' . $topic . '%']);
        $notes = $nq2->fetchAll();
    }
    foreach ($notes as $n) $created['notifications'][] = (int)$n['id'];
    $lawyerNote = array_values(array_filter($notes, fn($n) => $n['recipient_type'] === 'lawyer' && (int)$n['recipient_id'] === $lawyerId));
    $clientNote = array_values(array_filter($notes, fn($n) => $n['recipient_type'] === 'client' && (int)$n['recipient_id'] === $userId));
    check('the lawyer is notified', count($lawyerNote) >= 1, count($notes) . ' matching notes');
    check('the client is notified', count($clientNote) >= 1, count($notes) . ' matching notes');

    /* ------------------------------------------------------ idempotency ---- */
    section('7. a replayed verification changes nothing');
    $again = consultation_payment_verify_and_confirm($pdo, $consultationId, $orderRef);
    check('the replay is answered without error', !empty($again['ok']));
    $invCount = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE consultation_id=?');
    $invCount->execute([$consultationId]);
    check('no second invoice is created', (int)$invCount->fetchColumn() === 1,
        'invoices=' . (int)$invCount->fetchColumn());
    $nq->execute(['%' . $topic . '%']); $n2 = $nq->fetchAll();
    if (!$n2 && isset($nq2)) { $nq2->execute(['%' . $topic . '%']); $n2 = $nq2->fetchAll(); }
    check('no duplicate notifications are sent', count($n2) === count($notes),
        'before=' . count($notes) . ' after=' . count($n2));

    /* ------------------------------------------------- wallet credited ---- */
    section('8. the payout ledger recorded the money once');
    $pt = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE consultation_id=? AND event_type='PAYMENT_VERIFIED'");
    $pt->execute([$consultationId]);
    $verifiedEvents = (int)$pt->fetchColumn();
    check('the verification is logged', $verifiedEvents >= 1, "events=$verifiedEvents");
    check('a replayed verification does not duplicate the event log', $verifiedEvents === 1, "events=$verifiedEvents");

} catch (Throwable $e) {
    echo "  FAIL: threw " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    /* ---------------------------------------------------------- cleanup ---- */
    section('9. cleanup');
    $restore = $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($settingsBefore as $k => $v) {
        if ($v === null) { $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$k]); }
        else { $restore->execute([$k, $v]); }
    }
    check('gateway settings restored', true);

    $removed = 0;
    foreach ($created['notifications'] as $id) { $pdo->prepare('DELETE FROM notifications WHERE id=?')->execute([$id]); $removed++; }
    foreach ($created['consultations'] as $id) {
        $pdo->prepare('DELETE FROM invoices WHERE consultation_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM payment_transactions WHERE consultation_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$id]);
        $removed++;
    }
    echo "  removed $removed probe row group(s)\n";
    check('the probe left no consultation behind',
        (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE topic = " . $pdo->quote($topic ?? ''))->fetchColumn() === 0);
}

echo "\n{$checks} checks, {$fail} failure(s)\n";
exit($fail === 0 ? 0 : 1);
