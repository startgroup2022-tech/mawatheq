<?php
/**
 * Execution 73 — account-deletion HTTP probe.
 *
 * Drives the public /account-deletion page and the admin console through a real Apache
 * + session, because the failures this is meant to catch are HTTP-level ones:
 *
 *   * the public page must answer 200 **without a login** and must not redirect to the
 *     home page or to a sign-in screen (that is the whole Google Play requirement);
 *   * it must render in both Arabic (RTL) and English (LTR);
 *   * a request submitted over HTTP must be recorded and return a tracking number;
 *   * the admin console must list it, and executing a verified request must anonymise
 *     the account while keeping its invoice.
 *
 * Usage: php tests/exec73_account_deletion_http_probe.php   (needs the app on :8080)
 */
$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://localhost:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/account_deletion.php';
require_once $root . '/includes/account_deletion_requests.php';

$failures = 0;
$checks = 0;
function check(string $label, $got, $want = true): void {
    global $checks, $failures;
    $checks++;
    if ($got === $want) { echo "  ok:   $label\n"; return; }
    $failures++;
    $g = is_bool($got) ? var_export($got, true) : (string)$got;
    $w = is_bool($want) ? var_export($want, true) : (string)$want;
    echo "  FAIL: $label — got [$g], want [$w]\n";
}
function http_get(string $url, ?string $jar = null, bool $follow = false): array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => true];
    if ($jar) { $o[CURLOPT_COOKIEFILE] = $jar; $o[CURLOPT_COOKIEJAR] = $jar; }
    curl_setopt_array($ch, $o);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($resp, $hsize), $loc, substr($resp, 0, $hsize)];
}
function http_post(string $url, array $fields, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_FOLLOWLOCATION => false]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function csrf_of(string $body): string { preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m); return $m[1] ?? ''; }

if (!account_deletion_requests_ready($pdo)) {
    echo "SKIP: account_deletion_requests table missing — apply database/upgrade_v77.sql\n";
    exit(0);
}

$sfx   = bin2hex(random_bytes(4));
$email = "adrprobe-$sfx@example.test";
$pw    = 'Test-Password-2026';
$jar   = sys_get_temp_dir() . "/adr_probe_$sfx.cookies";

$lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
if ($lawyerId <= 0) { echo "SKIP: no approved lawyer to own the probe invoice\n"; exit(0); }

$pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
    ->execute(['ADR HTTP Probe', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoices (user_id, lawyer_id, invoice_number, description, amount, currency, status, issue_date)
               VALUES (?,?,?,?, 50.000, 'SAR', 'paid', CURDATE())")
    ->execute([$userId, $lawyerId, 'ADRHTTP-' . $sfx, 'HTTP probe invoice']);
$invoiceId = (int)$pdo->lastInsertId();

$createdRefs = [];
$cleanup = function () use ($pdo, $userId, $invoiceId, $createdRefs, $jar) {
    foreach ($createdRefs as $rid) {
        $pdo->prepare('DELETE FROM account_deletion_events WHERE request_id=?')->execute([$rid]);
        $pdo->prepare('DELETE FROM account_deletion_requests WHERE id=?')->execute([$rid]);
    }
    $pdo->prepare('DELETE FROM api_tokens WHERE user_id=? AND user_type=?')->execute([$userId, 'client']);
    $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$invoiceId]);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    @unlink($jar);
};

echo "Execution 73 account-deletion HTTP probe\n";
echo "=========================================\n\n";

echo "== 1. the public page is reachable with no login (Google Play requirement) ==\n";
[$code, $body, $loc, $headers] = http_get($base . '/account-deletion.php');
check('account-deletion.php returns 200 with no session', $code, 200);
check('it does not redirect (no Location header)', $loc === '');
check('it is not the homepage', strpos($body, 'حذف الحساب') !== false && strpos($body, 'name="identifier"') !== false);
check('it names the platform', strpos($body, 'مواثيق') !== false);
check('it carries a real request form', strpos($body, 'name="identifier"') !== false && strpos($body, 'name="action" value="request"') !== false);
check('it discloses retained data', strpos($body, 'تُحفظ') !== false || strpos($body, 'Retained') !== false);
check('it is served with the CSP header', stripos($headers, 'content-security-policy') !== false);

echo "== 2. the clean URL /account-deletion resolves to the same page ==\n";
[$code2, $body2] = http_get($base . '/account-deletion');
check('/account-deletion returns 200', $code2, 200);
check('/account-deletion serves the deletion page', strpos($body2, 'name="identifier"') !== false);
[$code2s] = http_get($base . '/account-deletion/');
check('/account-deletion/ (trailing slash) returns 200', $code2s, 200);

echo "== 3. both languages render ==\n";
[$codeAr, $bodyAr] = http_get($base . '/account-deletion.php');
check('Arabic page is RTL', strpos($bodyAr, 'dir="rtl"') !== false);
[$codeEn, $bodyEn] = http_get($base . '/account-deletion.php?lang=en');
check('English page is LTR', strpos($bodyEn, 'dir="ltr"') !== false);
check('English copy present', strpos($bodyEn, 'Submit deletion request') !== false);

echo "== 4. submit a request over HTTP (no login) ==\n";
[$c, $formBody] = http_get($base . '/account-deletion.php', $jar);
$csrf = csrf_of($formBody);
check('got a CSRF token from the public form', $csrf !== '');
[$pc, $pbody] = http_post($base . '/account-deletion.php', ['csrf_token' => $csrf, 'action' => 'request', 'identifier' => $email, 'ack' => '1'], $jar);
check('POST returns 200', $pc, 200);
preg_match('/DEL-[A-F0-9]{6,8}/', $pbody, $m);
$ref = $m[0] ?? '';
check('a tracking reference was returned', $ref !== '');
$row = account_deletion_request_get($pdo, $ref);
check('the request was recorded', $row !== null);
if ($row) { $createdRefs[] = (int)$row['id']; }
check('it is linked to the seeded account', $row && (int)$row['account_id'] === $userId);
check('it starts unverified', $row && $row['verification_status'] === 'unverified');

echo "== 5. a POST without a CSRF token is refused ==\n";
[$nc] = http_post($base . '/account-deletion.php', ['action' => 'request', 'identifier' => $email, 'ack' => '1'], $jar);
check('missing CSRF token -> 403', $nc, 403);

echo "== 6. the admin console lists and can act on the request ==\n";
// Log in as a super_admin the probe creates itself. Depending on the seeded admin's
// password would make the console assertions skip (or worse, pass vacuously) on a
// database whose seed differs from the documented one — a probe must stand on its own.
$pdo->exec('DELETE FROM auth_rate_limits');
$admJar = sys_get_temp_dir() . "/adr_probe_admin_$sfx.cookies";
$admEmail = "adr-admin-$sfx@example.test";
$admPass = 'Test-Admin-2026';
$pdo->prepare("INSERT INTO admins (email,password_hash,full_name,role,totp_enabled) VALUES (?,?,?,'super_admin',0)")
    ->execute([$admEmail, password_hash($admPass, PASSWORD_DEFAULT), 'ADR Probe Admin ' . $sfx]);
$admId = (int)$pdo->lastInsertId();

[$lc, $lbody] = http_get($base . '/admin/login.php', $admJar);
$acsrf = csrf_of($lbody);
[$lc2, $lbody2] = http_post($base . '/admin/login.php', ['csrf_token' => $acsrf, 'email' => $admEmail, 'password' => $admPass], $admJar);
check('admin login accepted', $lc2 === 302);
[$ac, $abody] = http_get($base . '/admin/account-deletions.php', $admJar);
check('console page returns 200', $ac, 200);
check('console lists the new request', strpos($abody, $ref) !== false);
[$dc, $dbody] = http_get($base . '/admin/account-deletions.php?ref=' . urlencode($ref), $admJar);
check('detail view returns 200', $dc, 200);
check('detail shows the audit trail', strpos($dbody, 'created') !== false);

// Verify the request (as the emailed link would), then execute it from the console.
$tok = bin2hex(random_bytes(32));
$pdo->prepare('UPDATE account_deletion_requests SET verify_token_hash=?, verify_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE reference=?')
    ->execute([hash('sha256', $tok), $ref]);
[$vc, $vbody] = http_get($base . '/account-deletion.php?verify=' . urlencode($tok));
check('verification link confirms ownership', $vc === 200 && strpos($vbody, 'تم تأكيد ملكية الحساب') !== false);

$dcsrf = csrf_of($dbody);
[$ec, $ebody] = http_post($base . '/admin/account-deletions.php', ['csrf_token' => $dcsrf, 'action' => 'execute', 'reference' => $ref, 'note' => 'probe execute'], $admJar);
check('execute POST returns 200', $ec, 200);
$after = account_deletion_request_get($pdo, $ref);
check('request is completed', $after && $after['status'] === 'completed');
$anonymised = (string)$pdo->query("SELECT email FROM users WHERE id=$userId")->fetchColumn();
check('account email anonymised', strpos($anonymised, 'deleted+') === 0);
check('invoice preserved', (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE id=$invoiceId")->fetchColumn() === 1);
@unlink($admJar);
$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$admId]);

$cleanup();
echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
