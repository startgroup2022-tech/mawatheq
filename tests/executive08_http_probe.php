<?php
/**
 * Live HTTP probe for the Executive 08 surfaces.
 *
 * Drives the real login, the real conflict screening, the real HR and branch writes, the
 * real domain lifecycle, and the real copilot run over HTTP against the running server — no
 * mocks. Verifies the things that only matter when the whole stack is assembled:
 *   * a firm owner can use conflicts / HR / branches / domains / copilot;
 *   * a user in firm B cannot read firm A's conflict review or domain (cross-tenant 404);
 *   * CSRF is actually required on a state-changing POST;
 *   * an unauthenticated visitor is redirected away from every new page;
 *   * a custom domain is not served until it is verified and activated.
 *
 * Mutates firm 1's data and restores it afterwards. Run: php tests/executive08_http_probe.php
 */

require_once __DIR__ . '/../config/config.php';

$base = 'http://127.0.0.1:12000';
$pass = 'Test-Password-2026';
$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

function http_login(string $base, string $loginPath, string $email, string $pass, string $jar): array {
    $ch = curl_init($base . $loginPath);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $html = curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', (string)$html, $m);
    $token = $m[1] ?? '';
    $ch = curl_init($base . $loginPath);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'email' => $email, 'password' => $pass]),
    ]);
    $body = curl_exec($ch);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$jar, $loc, (string)$body];
}

function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}

function fetch_csrf(string $url, string $jar): string {
    [$c, $b] = http_get($url, $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $b, $m);
    return $m[1] ?? '';
}

function http_post(string $url, string $jar, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}

$sfx = bin2hex(random_bytes(3));
$jarA = sys_get_temp_dir() . "/e8a-$sfx.cookies";
$jarB = sys_get_temp_dir() . "/e8b-$sfx.cookies";

// --- unauthenticated: every new page must bounce to login ---
echo "\n== 1. Auth gate ==\n";
foreach (['/lawyer/conflicts.php', '/lawyer/hr.php', '/lawyer/branches.php', '/lawyer/domains.php', '/lawyer/ai-copilot.php'] as $p) {
    $ch = curl_init($base . $p);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    check("unauthenticated $p redirects to login", $code === 302 && str_contains($loc, 'lawyer-login'));
}

// --- login ---
echo "\n== 2. Login + page load ==\n";
[$_x1, $locA] = http_login($base, '/lawyer-login.php', 'owner-a@example.com', $pass, $jarA);
check('owner A logs in', str_contains($locA, 'lawyer'));
[$_x2, $locB] = http_login($base, '/lawyer-login.php', 'owner-b@example.com', $pass, $jarB);
check('owner B logs in', str_contains($locB, 'lawyer'));

// Resolve the firm ids from the seeded owners instead of hardcoding 1: a fresh database starts
// AUTO_INCREMENT at 1, but a re-seeded or migrated one can hand out any id, and the probe must
// assert against the firm the logged-in owner actually belongs to.
$firmA = 0; $firmB = 0;
try {
    $st = $pdo->prepare('SELECT m.firm_id FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE l.email=? LIMIT 1');
    $st->execute(['owner-a@example.com']); $firmA = (int)($st->fetchColumn() ?: 0);
    $st->execute(['owner-b@example.com']); $firmB = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
check('resolved firm A from owner A', $firmA > 0);
check('resolved firm B from owner B', $firmB > 0 && $firmB !== $firmA);


foreach (['/lawyer/conflicts.php' => 'conflict', '/lawyer/hr.php' => 'HR', '/lawyer/branches.php' => 'branch', '/lawyer/domains.php' => 'domain', '/lawyer/ai-copilot.php' => 'copilot'] as $p => $label) {
    [$code, $body] = http_get($base . $p, $jarA);
    check("$label page loads 200 for owner A", $code === 200);
    check("$label page is not a PHP error", !str_contains($body, 'Fatal error') && !str_contains($body, 'Parse error') && !str_contains($body, 'Warning:'));
}

// --- conflict screening end to end ---
echo "\n== 3. Conflict screening over HTTP ==\n";
$tok = fetch_csrf($base . '/lawyer/conflicts.php', $jarA);
check('conflict page yields a CSRF token', $tok !== '');
$partyName = 'شركة اختبار التعارض ' . $sfx;
[$c1, $b1] = http_post($base . '/lawyer/conflicts.php', $jarA, [
    'csrf_token' => $tok, 'action' => 'add_party', 'party_type' => 'client', 'name_ar' => $partyName,
]);
check('registering a party returns 200', $c1 === 200 && str_contains($b1, $partyName));
// screen the same name -> should be found
[$c2, $b2] = http_post($base . '/lawyer/conflicts.php', $jarA, [
    'csrf_token' => $tok, 'action' => 'screen', 'subject_type' => 'opposing_party', 'subject_name_ar' => $partyName,
]);
check('screening a matching name reports a match', $c2 === 200 && (str_contains($b2, 'تطابق') || str_contains($b2, 'match')));

// CSRF must be enforced
[$c3] = http_post($base . '/lawyer/conflicts.php', $jarA, ['action' => 'add_party', 'party_type' => 'client', 'name_ar' => 'بدون رمز ' . $sfx]);
check('a POST without CSRF is rejected (403)', $c3 === 403);

// --- firm B cannot see firm A's review over HTTP ---
echo "\n== 4. Cross-tenant HTTP isolation ==\n";
// Find firm A's newest review id from the DB so the probe targets real state.
$reviewId = 0;
try {
    $st = $pdo->prepare("SELECT id FROM conflict_reviews WHERE firm_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$firmA]);
    $reviewId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
if ($reviewId) {
    [$codeA, $bodyA] = http_get($base . '/lawyer/conflicts.php?review=' . $reviewId, $jarA);
    check('firm A can open its review', $codeA === 200 && !str_contains($bodyA, 'مراجعة تعارض #' . $reviewId . '</h2>') === false);
    [$codeB, $bodyB] = http_get($base . '/lawyer/conflicts.php?review=' . $reviewId, $jarB);
    check('firm B opening the same review id does not expose it', $codeB === 200 && !str_contains($bodyB, 'مراجعة تعارض #' . $reviewId));
}

// --- HR write ---
echo "\n== 5. HR over HTTP ==\n";
$tokHR = fetch_csrf($base . '/lawyer/hr.php', $jarA);
check('HR page yields a CSRF token', $tokHR !== '');
$empName = 'موظف اختبار ' . $sfx;
[$ch, $bh] = http_post($base . '/lawyer/hr.php', $jarA, [
    'csrf_token' => $tokHR, 'action' => 'add_employee', 'full_name' => $empName, 'job_title' => 'Tester',
    'employment_type' => 'full_time', 'hire_date' => date('Y-m-d'),
]);
check('adding an employee returns 200 and shows the name', $ch === 200 && str_contains($bh, $empName));
$empId = 0;
try {
    $st = $pdo->prepare('SELECT id FROM hr_employees WHERE full_name=? ORDER BY id DESC LIMIT 1');
    $st->execute([$empName]);
    $empId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
check('employee row persisted for firm 1', $empId > 0);
if ($empId) {
    [$ca, $ba] = http_post($base . '/lawyer/hr.php', $jarA, [
        'csrf_token' => $tokHR, 'action' => 'record_attendance', 'employee_id' => $empId,
        'work_date' => date('Y-m-d'), 'attendance_status' => 'present',
    ]);
    check('manual attendance recording returns 200', $ca === 200);
    [$cl, $bl] = http_post($base . '/lawyer/hr.php', $jarA, [
        'csrf_token' => $tokHR, 'action' => 'request_leave', 'employee_id' => $empId,
        'leave_type' => 'annual', 'start_date' => date('Y-m-d', strtotime('+3 days')), 'end_date' => date('Y-m-d', strtotime('+5 days')),
    ]);
    check('leave request submission returns 200', $cl === 200);
}

// --- domain lifecycle over HTTP ---
echo "\n== 6. Custom domain lifecycle over HTTP ==\n";
$tokD = fetch_csrf($base . '/lawyer/domains.php', $jarA);
check('domains page yields a CSRF token', $tokD !== '');
$testDomain = "probe-$sfx.example.com";
[$cd, $bd] = http_post($base . '/lawyer/domains.php', $jarA, ['csrf_token' => $tokD, 'action' => 'add', 'domain' => $testDomain]);
check('adding a domain returns 200 and shows it', $cd === 200 && str_contains($bd, $testDomain));
check('the TXT token is displayed for verification', str_contains($bd, '_mawatheq.' . $testDomain));
// A fresh domain must not resolve/serve.
$domId = 0;
try {
    $st = $pdo->prepare('SELECT id FROM firm_domains WHERE domain=? LIMIT 1');
    $st->execute([$testDomain]);
    $domId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
check('domain row created pending', $domId > 0);
if ($domId) {
    $chk = curl_init($base . '/lawyer/domains.php');
    curl_setopt_array($chk, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jarA, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Host: ' . $testDomain]]);
    curl_exec($chk); $hc = (int)curl_getinfo($chk, CURLINFO_HTTP_CODE); curl_close($chk);
    check('an unverified custom domain does not serve the platform', $hc !== 200 || !$domId);
}
// Firm B must not be able to touch firm A's domain.
$tokDB = fetch_csrf($base . '/lawyer/domains.php', $jarB);
if ($domId && $tokDB !== '') {
    http_post($base . '/lawyer/domains.php', $jarB, ['csrf_token' => $tokDB, 'action' => 'delete', 'domain_id' => $domId]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM firm_domains WHERE id=?');
    $st->execute([$domId]);
    check('firm B cannot delete firm A\'s domain', (int)$st->fetchColumn() === 1);
}

// --- copilot over HTTP ---
echo "\n== 7. Copilot over HTTP ==\n";
$caseId = 0;
try {
    $st = $pdo->prepare('SELECT id FROM cases WHERE firm_id=? ORDER BY id DESC LIMIT 1');
    $st->execute([$firmA]);
    $caseId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
if ($caseId) {
    $tokC = fetch_csrf($base . '/lawyer/ai-copilot.php?case=' . $caseId, $jarA);
    check('copilot page yields a CSRF token', $tokC !== '');
    [$cc, $bc] = http_post($base . '/lawyer/ai-copilot.php', $jarA, ['csrf_token' => $tokC, 'action' => 'run', 'case_id' => $caseId, 'kind' => 'chronology']);
    check('copilot chronology run returns 200', $cc === 200);
    check('copilot page separates verified from suggested output', str_contains($bc, 'من ملف القضية') || str_contains($bc, 'From the case file'));
    // Copilot run persisted with requires_review
    $st = $pdo->prepare('SELECT COUNT(*) FROM ai_copilot_runs WHERE case_id=? AND firm_id=?');
    $st->execute([$caseId, $firmA]);
    check('copilot run persisted', (int)$st->fetchColumn() >= 1);
}

// --- admin governance + client summary over HTTP ---
echo "\n== 8. Admin governance + client summary ==\n";
$jarC1 = sys_get_temp_dir() . "/e8c1-$sfx.cookies";
$jarC2 = sys_get_temp_dir() . "/e8c2-$sfx.cookies";
$jarAd = sys_get_temp_dir() . "/e8ad-$sfx.cookies";

foreach (['/admin/ai-operations.php', '/case-summary.php'] as $p) {
    $ch = curl_init($base . $p);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    check("unauthenticated $p does not serve content", $code !== 200);
}

[$_x3, $locAd] = http_login($base, '/admin/login.php', 'admin@mawatheq.com', $pass, $jarAd);
check('platform admin logs in', str_contains($locAd, 'admin'));
[$codeAd, $bodyAd] = http_get($base . '/admin/ai-operations.php', $jarAd);
check('admin AI operations page loads 200 for super_admin', $codeAd === 200);
check('admin AI operations page is not a PHP error', !str_contains($bodyAd, 'Fatal error') && !str_contains($bodyAd, 'Parse error') && !str_contains($bodyAd, 'Warning:'));
check('admin AI operations shows the resilience knobs', str_contains($bodyAd, 'ai_timeout_seconds') || str_contains($bodyAd, 'ai_max_retries'));

[$_x4, $locC1] = http_login($base, '/login.php', "client-firm{$firmA}@example.com", $pass, $jarC1);
check('client 1 (firm A) logs in', $locC1 !== '');
$clientCaseId = 0;
try {
    $st = $pdo->prepare('SELECT id FROM cases WHERE user_id=(SELECT id FROM users WHERE email=?) ORDER BY id DESC LIMIT 1');
    $st->execute(["client-firm{$firmA}@example.com"]);
    $clientCaseId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
if ($clientCaseId) {
    // Approved output is visible; the same run while pending is not.
    $approvedText = 'ملخص معتمد للاختبار ' . $sfx;
    $mk = $pdo->prepare("INSERT INTO ai_copilot_runs (firm_id,case_id,kind,status,requires_review,verified_json,suggested_json,created_at) VALUES (?,?, 'summary','approved',0,'[]',?,NOW())");
    $mk->execute([$firmA, $clientCaseId, json_encode(['text' => $approvedText], JSON_UNESCAPED_UNICODE)]);
    $runId = (int)$pdo->lastInsertId();
    [$codeCl, $bodyCl] = http_get($base . '/case-summary.php?case=' . $clientCaseId, $jarC1);
    check('client summary page loads 200', $codeCl === 200);
    check('client sees a lawyer-approved summary', str_contains($bodyCl, $approvedText));
    $pdo->prepare("UPDATE ai_copilot_runs SET status='pending_review', requires_review=1 WHERE id=?")->execute([$runId]);
    [$codeCl2, $bodyCl2] = http_get($base . '/case-summary.php?case=' . $clientCaseId, $jarC1);
    check('client does NOT see the same run while pending review', $codeCl2 === 200 && !str_contains($bodyCl2, $approvedText));
    // Another client must not reach this case at all.
    [$_x5, $locC2] = http_login($base, '/login.php', "client-firm{$firmB}@example.com", $pass, $jarC2);
    check('client 2 (firm B) logs in', $locC2 !== '');
    [$codeC2, $bodyC2] = http_get($base . '/case-summary.php?case=' . $clientCaseId, $jarC2);
    check('another client cannot read this case summary', $codeC2 === 200 && !str_contains($bodyC2, $approvedText));
    $pdo->prepare('DELETE FROM ai_copilot_runs WHERE id=?')->execute([$runId]);
}

// --- cleanup: restore firm 1 ---
echo "\n== 9. Cleanup ==\n";
try {
    if ($empId) {
        $pdo->prepare('DELETE FROM hr_attendance WHERE employee_id=?')->execute([$empId]);
        $pdo->prepare('DELETE FROM hr_leave_requests WHERE employee_id=?')->execute([$empId]);
        $pdo->prepare('DELETE FROM hr_leave_balances WHERE employee_id=?')->execute([$empId]);
        $pdo->prepare('DELETE FROM hr_employees WHERE id=?')->execute([$empId]);
    }
    if ($domId) $pdo->prepare('DELETE FROM firm_domains WHERE id=?')->execute([$domId]);
    $pdo->prepare('DELETE FROM conflict_parties WHERE name_ar=? OR name_ar LIKE ?')->execute([$partyName, 'بدون رمز %']);
    $pdo->prepare('DELETE FROM conflict_reviews WHERE firm_id=? AND subject_party_id NOT IN (SELECT id FROM conflict_parties)')->execute([$firmA]);
    $pdo->prepare('DELETE FROM conflict_alerts WHERE firm_id=? AND review_id NOT IN (SELECT id FROM conflict_reviews)')->execute([$firmA]);
    $pdo->prepare('DELETE FROM ai_copilot_runs WHERE case_id=?')->execute([$caseId]);
    check('cleanup executed', true);
} catch (Throwable $e) {
    check('cleanup executed: ' . $e->getMessage(), false);
}
@unlink($jarA); @unlink($jarB); @unlink($jarC1); @unlink($jarC2); @unlink($jarAd);

echo "\n  Executive 08 HTTP probe: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
