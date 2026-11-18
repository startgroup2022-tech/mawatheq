<?php
/**
 * Execution 65 — the customer account surfaces over real HTTP.
 *
 * Everything goes through Apache with a real Bearer token, because the defects this
 * exists to catch are the ones a lint cannot see:
 *
 *   - an endpoint that reads its `action` only from the query string, so the app's
 *     POST body is ignored and every write answers "unknown action";
 *   - a transaction history that merges four tables and leaks another customer's row;
 *   - a ticket or appointment id that is not the caller's returning 200;
 *   - a consent key outside the allow-list being stored anyway.
 *
 * Usage: php tests/exec65_account_surfaces_probe.php
 * Needs the app on 127.0.0.1:8080 (or MAWATHEQ_BASE_URL).
 */
$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$failures = 0;
$checks = 0;

function check(string $label, $got, $want = true): void {
    global $checks, $failures;
    $checks++;
    if ($got === $want) { echo "  ok:   $label\n"; return; }
    $failures++;
    $g = is_bool($got) ? var_export($got, true) : (is_scalar($got) ? (string)$got : gettype($got));
    $w = is_bool($want) ? var_export($want, true) : (string)$want;
    echo "  FAIL: $label — got [$g], want [$w]\n";
}

/** Raw JSON request. Returns [status, decoded]. */
function api(string $base, string $path, ?string $token = null, string $method = 'GET', ?array $body = null): array {
    $ch = curl_init($base . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ];
    if ($token !== null) $opts[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    return [$code, is_array($decoded) ? $decoded : ['__raw' => substr($raw, 0, 400)]];
}

function api_login(string $base, PDO $pdo, string $path, string $email): string {
    // The limiter counts successful logins too, so a second run inside the window
    // would report a throttle and look like a regression.
    $pdo->exec('DELETE FROM auth_rate_limits');
    [$code, $js] = api($base, $path, null, 'POST', ['email' => $email, 'password' => 'Test-Password-2026']);
    if ($code !== 200 || empty($js['token'])) {
        fwrite(STDERR, "login failed for $email: HTTP $code " . json_encode($js) . "\n");
        exit(2);
    }
    return (string)$js['token'];
}

echo "Execution 65 — customer account surfaces HTTP probe\n";
echo "==================================================\n\n";

$clientAId = (int)$pdo->query("SELECT id FROM users WHERE email='client-a@example.com'")->fetchColumn();
$clientBId = (int)$pdo->query("SELECT id FROM users WHERE email='client-b@example.com'")->fetchColumn();
if ($clientAId === 0 || $clientBId === 0) {
    fwrite(STDERR, "fixture missing: run tests/seed_phase6.php first\n");
    exit(2);
}
echo "fixture: client-a=user $clientAId, client-b=user $clientBId\n\n";

$tokenA = api_login($base, $pdo, '/api/v1/auth/login.php', 'client-a@example.com');
$tokenB = api_login($base, $pdo, '/api/v1/auth/login.php', 'client-b@example.com');
$lawyerToken = api_login($base, $pdo, '/api/v1/auth/lawyer-login.php', 'owner-a@example.com');

// ---------------------------------------------------------------------------
echo "1. Auth and role isolation\n";
// ---------------------------------------------------------------------------
[$code] = api($base, '/api/v1/support.php?action=tickets');
check('an anonymous request is refused support tickets', $code, 401);
[$code] = api($base, '/api/v1/transactions.php');
check('an anonymous request is refused the transaction history', $code, 401);
[$code] = api($base, '/api/v1/privacy.php?action=consents');
check('an anonymous request is refused consent preferences', $code, 401);
[$code] = api($base, '/api/v1/transactions.php', $lawyerToken);
check('a lawyer token is refused the customer transaction history', $code, 403);
[$code, $js] = api($base, '/api/v1/support.php?action=settings');
check('support settings are public', $code, 200);
check('support settings carry the channel list', is_array($js['channels'] ?? null));
[$code, $js] = api($base, '/api/v1/privacy.php?action=info');
check('privacy info is public', $code, 200);
check('privacy info carries the policy URL', !empty($js['privacy_url']));

// ---------------------------------------------------------------------------
echo "\n2. Support ticket lifecycle\n";
// ---------------------------------------------------------------------------
[$code, $js] = api($base, '/api/v1/support.php', $tokenA, 'POST', [
    'action' => 'create-ticket',
    'subject' => 'استفسار تجريبي Exec65',
    'body' => 'هذا نص تجريبي للتحقق من دورة حياة التذكرة.',
    'category_id' => 1,
]);
check('a ticket is created', $code, 200);
$ticketId = (int)($js['ticket_id'] ?? 0);
check('the ticket id is returned', $ticketId > 0);
check('the ticket number is returned', !empty($js['ticket_number']));

[$code, $js] = api($base, '/api/v1/support.php?action=ticket&id=' . $ticketId, $tokenA);
check('the owner reads the ticket', $code, 200);
check('the opening message is present', count($js['messages'] ?? []) === 1);

[$code] = api($base, '/api/v1/support.php?action=ticket&id=' . $ticketId, $tokenB);
check('another customer cannot read the ticket', $code, 404);

[$code] = api($base, '/api/v1/support.php', $tokenB, 'POST', [
    'action' => 'reply-ticket', 'id' => $ticketId, 'body' => 'محاولة رد من حساب آخر.',
]);
check('another customer cannot reply to the ticket', $code, 404);

[$code] = api($base, '/api/v1/support.php', $tokenA, 'POST', [
    'action' => 'reply-ticket', 'id' => $ticketId, 'body' => 'رد تجريبي من العميل.',
]);
check('the owner replies', $code, 200);
[$code, $js] = api($base, '/api/v1/support.php?action=ticket&id=' . $ticketId, $tokenA);
check('the reply is in the thread', count($js['messages'] ?? []) === 2);

[$code] = api($base, '/api/v1/support.php', $tokenA, 'POST', ['action' => 'close-ticket', 'id' => $ticketId]);
check('the owner closes the ticket', $code, 200);
[$code, $js] = api($base, '/api/v1/support.php?action=ticket&id=' . $ticketId, $tokenA);
check('the ticket is closed', $js['ticket']['status'] ?? '', 'closed');

[$code] = api($base, '/api/v1/support.php', $tokenA, 'POST', ['action' => 'reopen-ticket', 'id' => $ticketId]);
check('the owner reopens the ticket', $code, 200);
[$code, $js] = api($base, '/api/v1/support.php?action=ticket&id=' . $ticketId, $tokenA);
check('the ticket is open again', $js['ticket']['status'] ?? '', 'open');

// ---------------------------------------------------------------------------
echo "\n3. Consent preferences (the POST body carries the action)\n";
// ---------------------------------------------------------------------------
[$code, $js] = api($base, '/api/v1/privacy.php', $tokenA, 'POST',
    ['action' => 'set-consent', 'key' => 'analytics', 'granted' => true]);
check('a consent is set from the JSON body action', $code, 200);
check('the response echoes the consent', (bool)($js['granted'] ?? false));

[$code, $js] = api($base, '/api/v1/privacy.php?action=consents', $tokenA);
$granted = [];
foreach ($js['consents'] ?? [] as $c) $granted[$c['key']] = (bool)$c['granted'];
check('the consent is stored', $granted['analytics'] ?? false);
check('an unset consent defaults to not granted', $granted['product_updates'] ?? true, false);

[$code] = api($base, '/api/v1/privacy.php', $tokenA, 'POST',
    ['action' => 'set-consent', 'key' => 'evil_key', 'granted' => true]);
check('an unknown consent key is refused', $code, 400);

[$code] = api($base, '/api/v1/privacy.php', $tokenA, 'POST',
    ['action' => 'set-consent', 'key' => 'analytics', 'granted' => false]);
check('a consent is revoked', $code, 200);

// ---------------------------------------------------------------------------
echo "\n4. Transaction history\n";
// ---------------------------------------------------------------------------
[$code, $js] = api($base, '/api/v1/transactions.php', $tokenA);
check('the transaction history is returned', $code, 200);
check('the history carries a summary', isset($js['summary']['count']));
check('the type list is present', in_array('consultation', $js['types'] ?? [], true));
[$code, $js] = api($base, '/api/v1/transactions.php?type=consultation&status=paid', $tokenA);
check('the history accepts filters', $code, 200);
check('filtering never returns another customer\'s rows', (function (array $rows) use ($pdo, $clientBId): bool {
    // Every row must belong to client A. The merge is per-user by construction; this
    // asserts it from the outside.
    foreach ($rows as $r) {
        if (($r['type'] ?? '') === 'consultation') {
            $id = (int)($r['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT user_id FROM consultations WHERE id=?');
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() === $clientBId) return false;
        }
    }
    return true;
})($js['transactions'] ?? []));

// ---------------------------------------------------------------------------
echo "\n5. Support appointments\n";
// ---------------------------------------------------------------------------
$pdo->exec("INSERT INTO support_appointment_slots (starts_at, ends_at, capacity, is_active)
    VALUES (DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY) + INTERVAL 30 MINUTE, 2, 1)");
$slotId = (int)$pdo->lastInsertId();
check('a slot fixture is created', $slotId > 0);

[$code, $js] = api($base, '/api/v1/support.php?action=slots', $tokenA);
$found = false;
foreach ($js['slots'] ?? [] as $s) if ((int)$s['id'] === $slotId) $found = true;
check('the open slot is offered', $found);
check('the slot reports its remaining capacity', (function (array $slots) use ($slotId): bool {
    foreach ($slots as $s) if ((int)$s['id'] === $slotId) return (int)$s['remaining'] === 2;
    return false;
})($js['slots'] ?? []));

[$code, $js] = api($base, '/api/v1/support.php', $tokenA, 'POST',
    ['action' => 'book-appointment', 'slot_id' => $slotId, 'subject' => 'طلب موعد تجريبي']);
check('an appointment is booked', $code, 200);
$appointmentId = (int)($js['id'] ?? 0);
check('the appointment reference is returned', !empty($js['reference']));

[$code] = api($base, '/api/v1/support.php', $tokenA, 'POST',
    ['action' => 'book-appointment', 'slot_id' => $slotId, 'subject' => 'مكرر']);
check('a duplicate booking is refused', $code, 409);

[$code] = api($base, '/api/v1/support.php', $tokenB, 'POST',
    ['action' => 'cancel-appointment', 'id' => $appointmentId]);
check('another customer cannot cancel the appointment', $code, 404);

[$code] = api($base, '/api/v1/support.php', $tokenA, 'POST',
    ['action' => 'cancel-appointment', 'id' => $appointmentId]);
check('the owner cancels the appointment', $code, 200);

// ---------------------------------------------------------------------------
echo "\n6. Invoice detail scoping\n";
// ---------------------------------------------------------------------------
$invoiceA = (int)$pdo->query("SELECT id FROM invoices WHERE user_id=$clientAId AND is_draft=0 ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($invoiceA > 0) {
    [$code] = api($base, '/api/v1/invoice.php?id=' . $invoiceA, $tokenA);
    check('the owner reads their invoice', $code, 200);
    [$code] = api($base, '/api/v1/invoice.php?id=' . $invoiceA, $tokenB);
    check('another customer cannot read the invoice', $code, 404);
} else {
    echo "  skip: no invoice fixture for client-a\n";
}

// ---------------------------------------------------------------------------
echo "\n7. Cleanup\n";
// ---------------------------------------------------------------------------
$pdo->prepare('DELETE FROM support_appointments WHERE id=?')->execute([$appointmentId]);
$pdo->prepare('DELETE FROM support_appointment_slots WHERE id=?')->execute([$slotId]);
$pdo->prepare('DELETE FROM support_ticket_messages WHERE ticket_id=?')->execute([$ticketId]);
$pdo->prepare('DELETE FROM support_tickets WHERE id=?')->execute([$ticketId]);
$pdo->prepare("DELETE FROM user_consents WHERE user_type='client' AND user_id=?")->execute([$clientAId]);
check('the ticket is removed', (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE id=$ticketId")->fetchColumn(), 0);
check('the slot is removed', (int)$pdo->query("SELECT COUNT(*) FROM support_appointment_slots WHERE id=$slotId")->fetchColumn(), 0);
check('the consent rows are removed', (int)$pdo->query("SELECT COUNT(*) FROM user_consents WHERE user_id=$clientAId")->fetchColumn(), 0);

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAILURE(S)") . " — $checks checks\n";
exit($failures === 0 ? 0 : 1);
