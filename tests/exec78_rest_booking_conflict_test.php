<?php
/**
 * Execution 78 — the mobile/API booking path refuses a double booking, and says so in the
 * sentence the app shows.
 *
 * booking_slot_is_bookable() is shared by booking.php and api/v1/consultations.php, but the
 * *response* differs: the web form re-renders with an Arabic sentence, the API returns a
 * status code. The Flutter client keys on 409 (api_exception.dart maps 400/409/422 to a
 * validation error), so a 500 or a 200-with-failure would reach the customer as an unexpected
 * server error or as a silent no-op. That is a different bug from "the guard is not wired",
 * and only an HTTP test sees it.
 *
 * Everything this creates is removed in cleanup.
 */

require_once __DIR__ . '/../config/config.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $failures++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

const API = 'http://127.0.0.1:8080/api/v1/consultations.php';

function api_post(string $token, array $body): array {
    $ch = curl_init(API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

$token = null;
$createdIds = [];
$topic = 'exec78 rest conflict ' . bin2hex(random_bytes(3));

try {
    $pdo->exec('DELETE FROM auth_rate_limits');

    $userId = (int)$pdo->query("SELECT id FROM users WHERE email='client-a@example.com'")->fetchColumn();
    check('the seeded client exists', $userId > 0);
    $lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' AND email_verified=1 ORDER BY id LIMIT 1")->fetchColumn();
    check('an approved lawyer exists', $lawyerId > 0);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token,user_type,user_id,device_label,expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 1 DAY))')
        ->execute([hash('sha256', $token), 'client', $userId, 'exec78 rest conflict']);

    // A weekday far enough ahead that 'in_past' cannot mask the overlap verdict.
    $d = new DateTime('+50 days', new DateTimeZone('Asia/Riyadh'));
    while ((int)$d->format('w') === 5) { $d->modify('+1 day'); }
    $date = $d->format('Y-m-d');

    echo "== 1. the first booking is accepted ==\n";
    $body = ['lawyer_id' => $lawyerId, 'consultation_type' => 'video', 'date' => $date, 'time' => '15:00', 'topic' => $topic];
    [$c1, $j1, $r1] = api_post($token, $body);
    check('the first booking returns 201', $c1 === 201, "got $c1: " . substr($r1, 0, 120));
    check('it reports success', ($j1['ok'] ?? false) === true);
    if (!empty($j1['consultation_id'])) { $createdIds[] = (int)$j1['consultation_id']; }

    echo "\n== 2. the identical second booking is refused with 409, not 500 ==\n";
    [$c2, $j2, $r2] = api_post($token, $body);
    check('the duplicate returns 409', $c2 === 409, "got $c2: " . substr($r2, 0, 160));
    check('the body is not ok', ($j2['ok'] ?? true) === false);
    $msg = strtolower((string)($j2['error'] ?? ''));
    check('the message names the slot, so the app can show it', str_contains($msg, 'slot'), $msg);
    check('the message is English API copy, not an HTML page', !str_contains($r2, '<html'));

    echo "\n== 3. only one row exists for that slot ==\n";
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE lawyer_id=? AND topic=?');
    $stmt->execute([$lawyerId, $topic]);
    $count = (int)$stmt->fetchColumn();
    check('exactly one consultation was created', $count === 1, "count=$count");

    echo "\n== 4. an overlapping start (not just an identical one) is also refused ==\n";
    // The default consultation length is 30 minutes, so 15:15 overlaps 15:00-15:30.
    [$c3, $j3] = api_post($token, ['lawyer_id' => $lawyerId, 'consultation_type' => 'video', 'date' => $date, 'time' => '15:15', 'topic' => $topic]);
    check('15:15 inside a 15:00 booking returns 409', $c3 === 409, "got $c3");
    check('it is reported as a slot conflict', str_contains(strtolower((string)($j3['error'] ?? '')), 'slot'));

    echo "\n== 5. a different lawyer at the same time is unaffected ==\n";
    $other = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' AND email_verified=1 AND id <> $lawyerId ORDER BY id LIMIT 1")->fetchColumn();
    if ($other > 0) {
        [$c4, $j4, $r4] = api_post($token, ['lawyer_id' => $other, 'consultation_type' => 'video', 'date' => $date, 'time' => '15:00', 'topic' => $topic]);
        check('another lawyer can take 15:00', $c4 === 201, "got $c4: " . substr($r4, 0, 100));
        if (!empty($j4['consultation_id'])) { $createdIds[] = (int)$j4['consultation_id']; }
    } else {
        check('a second approved lawyer exists to compare against', false, 'only one approved lawyer');
    }

    echo "\n== 6. the authorization is real (no token means no booking) ==\n";
    $ch = curl_init(API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    curl_exec($ch);
    $noAuth = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    check('without a token the endpoint answers 401', $noAuth === 401, "got $noAuth");
} catch (Throwable $e) {
    $checks++;
    $failures++;
    echo "  FAIL: unexpected error — " . $e->getMessage() . "\n";
} finally {
    foreach ($createdIds as $id) {
        $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$id]);
    }
    $pdo->prepare("DELETE FROM consultations WHERE topic=?")->execute([$topic]);
    $pdo->prepare("DELETE FROM api_tokens WHERE device_label='exec78 rest conflict'")->execute();
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 78 REST booking conflict: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
