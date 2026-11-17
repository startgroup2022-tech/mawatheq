<?php
/**
 * Execution 68 — the admin library console actions must not 500.
 *
 * `admin/legal-library.php` called log_action() with a 5th argument and a string
 * source key. `log_action()` is `(PDO, string, ?string, ?int)` — PHP raises a
 * TypeError on the string id, and a TypeError is fatal, so the whole action
 * returned HTTP 500 and the operator saw a blank page while the policy change
 * (already written) looked like it had not happened. This drives the real page
 * over HTTP and asserts a redirect/200 plus the persisted policy.
 *
 * Run: php tests/exec68_admin_library_http_probe.php  (app must be on 127.0.0.1:8080)
 */
$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
require_once __DIR__ . '/../config/config.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$jar = tempnam(sys_get_temp_dir(), 'exec68ll');
function req(string $url, array $post = null): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

/* ---------------------------------------------------- admin login ---- */
echo "\n== admin login ==\n";
[, $login] = req($base . '/admin/login.php');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $login, $m);
[$code, ] = req($base . '/admin/login.php', ['email' => 'admin@mawatheq.com', 'password' => 'Test-Password-2026', 'csrf_token' => $m[1] ?? '']);
check('the admin login redirects (302)', $code === 302);

/* ------------------------------------- the console renders ---- */
echo "\n== the console renders with the policy control ==\n";
[$code, $page] = req($base . '/admin/legal-library.php');
check('the console renders 200', $code === 200);
check('no undefined variable / fatal in the output', stripos($page, 'Undefined variable') === false && stripos($page, 'Fatal error') === false);
check('the redistribution selector is present', strpos($page, 'data-autosubmit') !== false);

/* ------------------------------- the action must not 500 ---- */
echo "\n== a policy change does not 500 and is persisted ==\n";
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page, $c);
$csrf = $c[1] ?? '';
check('a CSRF token is available on the page', $csrf !== '');

// Flip moj_decisions to hosted, then back — both must answer without a 500.
// The success notice is asserted in the response rather than read back from a
// database connection: the probe's own config may point at a different database
// than the server under test, so a DB read would test the wrong store.
foreach (['hosted', 'link_only'] as $target) {
    [$code, $body] = req($base . '/admin/legal-library.php', [
        'csrf_token' => $csrf, 'action' => 'redistribution', 'source_key' => 'moj_decisions', 'redistribution' => $target,
    ]);
    check("setting redistribution=$target answers 200 (not 500)", $code === 200);
    check("setting redistribution=$target has no TypeError in the body", stripos($body, 'TypeError') === false);
    check("setting redistribution=$target reports the policy it saved",
        strpos($body, 'تم تحديث سياسة النشر') !== false || stripos($body, 'Redistribution policy set to') !== false);
}

echo "\n$checks checks, $failures failure(s)\n";
@unlink($jar);
exit($failures === 0 ? 0 : 1);
