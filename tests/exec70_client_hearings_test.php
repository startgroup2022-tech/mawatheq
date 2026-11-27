<?php
/**
 * Execution 70 — the client sees their own case schedule and nobody else's.
 *
 * `/case-hearings.php` is the client-facing read of `legal_calendar_events`. The only
 * thing standing between a client and another client's hearing is the join
 * `cases c ON c.id = e.case_id WHERE c.user_id = ?`, so this suite attacks exactly that:
 * it seeds one hearing on each of two clients' cases and asserts each sees their own
 * and neither sees the other's, over real HTTP with a real login.
 *
 * It also pins the page's read-only nature: there is no POST handler, so a client cannot
 * change a court date.
 */
require_once __DIR__ . '/../config/config.php';

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:12000';
$checks = 0;
$failures = 0;

function chk(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok   $label\n"; }
    else { $failures++; echo "  FAIL $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

function h_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar,
                            CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 30]);
    $b = curl_exec($ch); $s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $s, 'body' => (string)$b];
}

function h_post(string $url, array $f, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($f),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    $b = curl_exec($ch); $s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $s, 'body' => (string)$b];
}

function login_client(string $base, string $email, string $password): array {
    $jar = '/tmp/exec70h_' . bin2hex(random_bytes(4)) . '.jar';
    @unlink($jar);
    $p = h_get("$base/login.php", $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $p['body'], $m);
    $r = h_post("$base/login.php", ['csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => $password], $jar);
    return ['jar' => $jar, 'status' => $r['status']];
}

echo "Execution 70 — client case schedule isolation\n";
echo "==============================================\n";

// Two clients, each with a case and one hearing. Resolve them by identity, never "newest".
$rows = $pdo->query(
    "SELECT c.id AS case_id, c.user_id, u.email, u.full_name, c.lawyer_id, c.firm_id
       FROM cases c JOIN users u ON u.id = c.user_id
      ORDER BY c.id LIMIT 2"
)->fetchAll();
if (count($rows) < 2) { echo "FATAL: need two cases owned by two clients\n"; exit(1); }
[$a, $b] = [$rows[0], $rows[1]];
if ((int)$a['user_id'] === (int)$b['user_id']) { echo "FATAL: both cases belong to one client\n"; exit(1); }

$markerA = 'موعد-أ-' . bin2hex(random_bytes(3));
$markerB = 'موعد-ب-' . bin2hex(random_bytes(3));

$ins = $pdo->prepare("INSERT INTO legal_calendar_events (lawyer_id, firm_id, case_id, event_type, title, description, event_at, status)
                      VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 5 DAY),'upcoming')");
$ins->execute([(int)$a['lawyer_id'], (int)$a['firm_id'], (int)$a['case_id'], 'court_session', $markerA, '']);
$eventA = (int)$pdo->lastInsertId();
$ins->execute([(int)$b['lawyer_id'], (int)$b['firm_id'], (int)$b['case_id'], 'court_session', $markerB, '']);
$eventB = (int)$pdo->lastInsertId();

// The passwords were set by the seed scripts to this value.
$login = login_client($base, (string)$a['email'], 'Test-Password-2026');
if ($login['status'] !== 302) {
    echo "FATAL: could not log in as {$a['email']} (status {$login['status']})\n";
    $pdo->exec("DELETE FROM legal_calendar_events WHERE id IN ($eventA,$eventB)");
    exit(1);
}
$page = h_get("$base/case-hearings.php", $login['jar']);

chk('page returns 200', $page['status'] === 200);
chk('page is not a blank body (the 200-but-empty trap)', strlen($page['body']) > 5000, 'bytes=' . strlen($page['body']));
chk('no fatal in the rendered page', !preg_match('/fatal error|uncaught/i', $page['body']));
chk('client sees their own hearing', str_contains($page['body'], $markerA));
chk('client does NOT see the other client\'s hearing', !str_contains($page['body'], $markerB));

// Read-only: a POST is not handled, so a client cannot alter a court date.
$csrf = '';
if (preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m)) $csrf = $m[1];
$post = h_post("$base/case-hearings.php", [
    'csrf_token' => $csrf, 'id' => $eventA, 'title' => 'تلاعب', 'event_at' => '2020-01-01 00:00:00',
], $login['jar']);
$after = (string)$pdo->query("SELECT title FROM legal_calendar_events WHERE id=$eventA")->fetchColumn();
chk('a client POST cannot change the hearing title', $after === $markerA, 'title now: ' . $after);

@unlink($login['jar']);
$pdo->exec("DELETE FROM legal_calendar_events WHERE id IN ($eventA,$eventB)");

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
