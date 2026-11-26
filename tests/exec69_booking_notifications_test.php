<?php
/**
 * Execution 69 — a booking decision must reach the customer.
 *
 * The booking flow notified the lawyer when a request arrived, and the *paid* confirm path
 * notified both sides. Everything else was silent: a lawyer pressing «تأكيد» or «إلغاء» on an
 * unpaid booking changed the row and told nobody, so the customer kept seeing "pending" while
 * the appointment was cancelled underneath them. Cancellation is the case that matters most —
 * the slot is released and the customer would otherwise turn up.
 *
 * This suite drives the real page over HTTP as the lawyer and asserts the notification row
 * the customer reads. Requires the app on 127.0.0.1:8080.
 *
 * Run: php tests/exec69_booking_notifications_test.php
 */
$BASE = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/consultation_payment.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** A cookie-jar login helper returning the jar path, or null on failure. */
function e69_login(string $base, string $email, string $password, string $path, string $fieldEmail = 'email'): ?string {
    $jar = tempnam(sys_get_temp_dir(), 'e69jar');
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string)$body, $m)) return null;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $m[1], $fieldEmail => $email, 'password' => $password]),
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 302 ? $jar : null;
}

function e69_post(string $base, string $jar, string $path, array $fields): int {
    // Fetch a fresh CSRF token from the target page itself.
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) $fields['csrf_token'] = $m[1];

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

echo "target: $BASE\n";
$pdo->exec('DELETE FROM auth_rate_limits');

// ---- fixtures: a lawyer, a client, and one pending booking between them -------------
$suffix = bin2hex(random_bytes(4));
$pwd = password_hash('E69-Notify-2026', PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified,jurisdiction)
               VALUES (?,?,?,'approved',1,'السعودية')")
    ->execute(['Exec69 Notify Lawyer', "exec69-nl-$suffix@example.test", $pwd]);
$lawyerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,account_type,preferred_lang,email_verified,status)
               VALUES (?,?,?,'individual','ar',1,'active')")
    ->execute(['Exec69 Notify Client', "exec69-nc-$suffix@example.test", $pwd]);
$clientId = (int)$pdo->lastInsertId();

$makeBooking = function () use ($pdo, $lawyerId, $clientId): int {
    $pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, scheduled_at, duration_minutes,
                       consultation_type, topic, status, price, payment_status)
                   VALUES (?,?,?,'30','text','موضوع اختبار','pending',0,'unpaid')")
        ->execute([$clientId, $lawyerId, date('Y-m-d H:i:s', strtotime('+3 days 10:00'))]);
    return (int)$pdo->lastInsertId();
};

echo "\n== 1. a lawyer confirming a booking notifies the customer ==\n";
$bookingId = $makeBooking();
$jar = e69_login($BASE, "exec69-nl-$suffix@example.test", 'E69-Notify-2026', '/lawyer-login.php', 'email');
check('the lawyer can sign in', $jar !== null);
if ($jar !== null) {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId")->fetchColumn();
    $code = e69_post($BASE, $jar, '/lawyer/consultations.php', [
        'action' => 'change_status', 'consultation_id' => $bookingId, 'status' => 'confirmed',
    ]);
    check('the status change is accepted', $code === 302, "HTTP $code");
    check('the booking is now confirmed',
        $pdo->query("SELECT status FROM consultations WHERE id=$bookingId")->fetchColumn() === 'confirmed');

    $after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId")->fetchColumn();
    check('the customer received a notification', $after === $before + 1, "$before -> $after");
    $title = (string)$pdo->query("SELECT title FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('the notification is about the confirmation', strpos($title, 'تأكيد') !== false || stripos($title, 'confirmed') !== false, $title);
}

echo "\n== 2. a lawyer cancelling a booking tells the customer, not just strangers ==\n";
$bookingId2 = $makeBooking();
if ($jar !== null) {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId")->fetchColumn();
    $code = e69_post($BASE, $jar, '/lawyer/consultations.php', [
        'action' => 'change_status', 'consultation_id' => $bookingId2, 'status' => 'cancelled',
    ]);
    check('the cancellation is accepted', $code === 302, "HTTP $code");
    check('the booking is now cancelled',
        $pdo->query("SELECT status FROM consultations WHERE id=$bookingId2")->fetchColumn() === 'cancelled');
    $after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId")->fetchColumn();
    check('the customer received a cancellation notification', $after === $before + 1, "$before -> $after");
    $title = (string)$pdo->query("SELECT title FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('the notification names the cancellation', strpos($title, 'أُلغي') !== false || stripos($title, 'cancelled') !== false, $title);
}

echo "\n== 3. an unpaid priced booking still cannot be confirmed silently ==\n";
$pricedId = 0;
$pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, scheduled_at, duration_minutes,
                   consultation_type, topic, status, price, payment_status)
               VALUES (?,?,?,'30','text','موضوع مدفوع','pending',350,'unpaid')")
    ->execute([$clientId, $lawyerId, date('Y-m-d H:i:s', strtotime('+4 days 11:00'))]);
$pricedId = (int)$pdo->lastInsertId();
if ($jar !== null) {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId")->fetchColumn();
    e69_post($BASE, $jar, '/lawyer/consultations.php', [
        'action' => 'change_status', 'consultation_id' => $pricedId, 'status' => 'confirmed',
    ]);
    check('the unpaid booking stays pending',
        $pdo->query("SELECT status FROM consultations WHERE id=$pricedId")->fetchColumn() === 'pending');
    $after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId")->fetchColumn();
    check('no confirmation notification is sent for a refused confirmation', $after === $before, "$before -> $after");
}

echo "\n== cleanup ==\n";
$ids = array_filter([$bookingId ?? 0, $bookingId2 ?? 0, $pricedId]);
if ($ids) $pdo->exec('DELETE FROM consultations WHERE id IN (' . implode(',', $ids) . ')');
$pdo->exec("DELETE FROM notifications WHERE recipient_type='client' AND recipient_id=$clientId");
$pdo->exec("DELETE FROM users WHERE id=$clientId");
$pdo->exec("DELETE FROM lawyers WHERE id=$lawyerId");
if ($jar) @unlink($jar);
echo "cleaned\n";

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
