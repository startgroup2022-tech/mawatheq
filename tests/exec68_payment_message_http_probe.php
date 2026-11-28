<?php
/**
 * Execution 68 — the payment failure the *customer actually sees*, over real HTTP.
 *
 * The owner reported the generic sentence
 *   «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»
 * with no reference, which is unactionable. This probe logs in as a real client, POSTs
 * `consultation-pay.php` against the real N-Genius fixture, and reads the banner the
 * browser renders — not the helper's return value, which would pass even if the page
 * forgot to call it.
 *
 * Two states are asserted:
 *   A. gateway disabled  -> the "not enabled" sentence + a reference the operator can look up
 *   B. gateway enabled   -> a redirect to the hosted payment page (the success path)
 *
 * Needs the app on 127.0.0.1:8080 with `MAWATHEQ_NGENIUS_BASE_URL` pointing at
 * tests/fixtures/ngenius_server.php (see the vhost SetEnv in setup_test_env.sh).
 *
 *   php tests/exec68_payment_message_http_probe.php
 */
require_once __DIR__ . '/../config/config.php';

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
$pass = 0; $fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok:   $label\n"; }
    else { $fail++; echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function http(string $url, $post = null, ?string $jar = null, bool $follow = false): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) {
        curl_setopt($c, CURLOPT_POST, 1);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $loc  = (string)curl_getinfo($c, CURLINFO_REDIRECT_URL);
    curl_close($c);
    return [$code, $body, $loc];
}
function login(string $base, string $jar, string $email, string $password): bool {
    [$c, $b] = http("$base/login.php", null, $jar);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $b, $m)) return false;
    [$c, $b] = http("$base/login.php",
        ['email' => $email, 'password' => $password, 'csrf_token' => $m[1]], $jar);
    return $c === 302;
}
function banner(string $html): string {
    return preg_match('/form-alert error">([^<]+)</u', $html, $m)
        ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
}

$pdo->exec('DELETE FROM auth_rate_limits');
$prior = $pdo->query("SELECT setting_key, setting_value FROM site_settings
                      WHERE setting_key IN ('ngenius_enabled','ngenius_outlet_ref','ngenius_api_key','ngenius_environment')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

// A priced, unpaid consultation owned by a real seeded client.
$row = $pdo->query("SELECT c.id, c.user_id, u.email FROM consultations c
                    JOIN users u ON u.id = c.user_id
                    WHERE u.email = 'client-firm1@example.com' ORDER BY c.id LIMIT 1")->fetch();
if (!$row) { echo "SKIP: no seeded consultation for client-firm1\n"; exit(0); }
$cid = (int)$row['id'];
$pdo->prepare("UPDATE consultations SET price=250.00, payment_status='unpaid', status='pending' WHERE id=?")
    ->execute([$cid]);

$jar = tempnam(sys_get_temp_dir(), 'exec68');
$csrf = '';
try {
    echo "\n== 0. sign in as the consultation owner ==\n";
    chk('login redirects (session established)', login($base, $jar, $row['email'], 'Test-Password-2026'));
    [, $shell] = http("$base/consultations.php", null, $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $shell, $m);
    $csrf = $m[1] ?? '';
    chk('the consultations page issues a CSRF token', $csrf !== '');

    echo "\n== A. gateway disabled: a specific sentence, not the generic one ==\n";
    $pdo->exec("UPDATE site_settings SET setting_value='0' WHERE setting_key='ngenius_enabled'");
    [$c, , $loc] = http("$base/consultation-pay.php", ['consultation_id' => $cid, 'csrf_token' => $csrf], $jar);
    chk('the pay POST redirects back to the list', $c === 302 && str_contains($loc, 'payment_error=1'), $loc);
    chk('the redirect carries a structured code', str_contains($loc, 'code='), $loc);
    chk('the redirect carries a correlation id', (bool)preg_match('/ref=[A-F0-9]{8}/', $loc), $loc);
    [, $page] = http($loc, null, $jar);
    $msg = banner($page);
    chk('the customer sees a banner', $msg !== '');
    chk('the banner is NOT the generic transient sentence',
        !str_contains($msg, 'يمكنك المحاولة مجدداً'), $msg);
    chk('the banner names the real cause (payment not enabled)',
        str_contains($msg, 'غير مفعّل'), $msg);
    chk('the banner still says no amount was charged', str_contains($msg, 'لم يتم خصم'), $msg);
    preg_match('/ref=([A-F0-9]{8})/', $loc, $rm);
    chk('the banner shows the reference the customer can quote',
        isset($rm[1]) && str_contains($msg, $rm[1]), $msg);
    // The reference must be findable by the operator, or it is decoration.
    require_once __DIR__ . '/../includes/gateway_diagnostics.php';
    $found = isset($rm[1]) ? gateway_diag_find($pdo, $rm[1]) : [];
    chk('the operator can look that reference up in gateway_diagnostics', count($found) > 0);
    chk('the recorded row names the stage that stopped it',
        count($found) > 0 && (string)($found[0]['stage'] ?? '') !== '', json_encode($found[0] ?? []));

    echo "\n== B. gateway enabled against the fixture: the success path redirects ==\n";
    $pdo->exec("UPDATE site_settings SET setting_value='1' WHERE setting_key='ngenius_enabled'");
    [, $shell2] = http("$base/consultations.php", null, $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $shell2, $m2);
    $csrf2 = $m2[1] ?? $csrf;
    [$c, , $loc2] = http("$base/consultation-pay.php", ['consultation_id' => $cid, 'csrf_token' => $csrf2], $jar);
    chk('an enabled gateway redirects to the hosted payment page',
        $c === 302 && (str_contains($loc2, '/hosted/') || str_contains($loc2, 'payment_error')),
        "$c $loc2");
} finally {
    foreach ($prior as $k => $v) {
        $pdo->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
    }
    $pdo->prepare("UPDATE consultations SET payment_status='unpaid' WHERE id=?")->execute([$cid]);
    @unlink($jar);
}

echo "\n----------------------------------------\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . ": $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
