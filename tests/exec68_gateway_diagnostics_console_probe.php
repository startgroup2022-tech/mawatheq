<?php
/**
 * Execution 68 — the operator half of the payment diagnostics loop, over real HTTP.
 *
 * The customer is told a reference. That reference is only useful if the operator can
 * enter it and see the attempt, so this probe asserts the whole chain:
 *
 *   1. a failing payment records a `gateway_diagnostics` row with a stage and reason;
 *   2. the customer banner carries that same reference;
 *   3. an admin can search the reference on `admin/gateways.php?ref=` and see the reason;
 *   4. a currency mismatch (the failure actually recorded on production) is surfaced
 *      *before* a customer hits it, with both currency codes named.
 *
 *   php tests/exec68_gateway_diagnostics_console_probe.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok:   $l\n"; }
    else { $fail++; echo "  FAIL: $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function http(string $url, $post = null, ?string $jar = null): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 0,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) {
        curl_setopt($c, CURLOPT_POST, 1);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $b = (string)curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $loc = (string)curl_getinfo($c, CURLINFO_REDIRECT_URL);
    curl_close($c);
    return [$code, $b, $loc];
}
function admin_login(string $base, string $jar): bool {
    [$c, $b] = http("$base/admin/login.php", null, $jar);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $b, $m)) return false;
    [$c] = http("$base/admin/login.php",
        ['email' => 'admin@mawatheq.com', 'password' => 'Test-Password-2026', 'csrf_token' => $m[1]], $jar);
    return $c === 302;
}

$pdo->exec('DELETE FROM auth_rate_limits');
// Snapshot every setting this probe touches so it restores what it found (a row that
// did not exist is deleted, not set to a guessed default).
$touched = ['ngenius_currency', 'ngenius_enabled', 'ngenius_outlet_ref'];
$prior = [];
foreach ($touched as $k) {
    $v = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key=" . $pdo->quote($k))->fetchColumn();
    $prior[$k] = $v === false ? null : (string)$v;
}
function setting_put(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$key, $value]);
}

echo "\n== 1. a failing payment records a diagnosable row ==\n";
// Provoke a real, permanent failure through the service layer (not a hand-written row).
$before = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM gateway_diagnostics")->fetchColumn();
$fail1 = gateway_diag_failure($pdo, 'probe', 'config', 'unsupported_currency',
    'site currency SAR != outlet BHD', 'sandbox');
chk('the failure helper returns a correlation id', !empty($fail1['correlation_id']));
$ref = (string)($fail1['correlation_id'] ?? '');
chk('the correlation id has the documented shape', (bool)preg_match('/^[A-F0-9]{8}$/', $ref), $ref);
$rows = gateway_diag_find($pdo, $ref);
chk('the row is findable by its own reference', count($rows) > 0);
chk('the row records the stage', (string)($rows[0]['stage'] ?? '') === 'config', json_encode($rows[0] ?? []));
chk('the row records the reason code',
    (string)($rows[0]['error_code'] ?? '') === 'unsupported_currency', json_encode($rows[0] ?? []));
chk('a new row was actually written',
    (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM gateway_diagnostics")->fetchColumn() > $before);

echo "\n== 2. the customer message names a cause and carries the reference ==\n";
$msg = gateway_diag_customer_message('unsupported_currency', $ref, true);
chk('the sentence is not the generic transient one', !str_contains($msg, 'يمكنك المحاولة مجدداً'), $msg);
chk('the sentence names the currency problem', str_contains($msg, 'عملة'), $msg);
chk('the sentence carries the reference', str_contains($msg, $ref), $msg);

echo "\n== 3. an admin can look the reference up in the console ==\n";
$jar = tempnam(sys_get_temp_dir(), 'exec68adm');
try {
    chk('admin signs in', admin_login($base, $jar));
    [$c, $page] = http("$base/admin/gateways.php?ref=$ref", null, $jar);
    chk('the console renders', $c === 200, (string)$c);
    chk('the console shows the reference searched for', str_contains($page, $ref));
    chk('the console shows the reason code', str_contains($page, 'unsupported_currency'));
    chk('the console shows the recorded detail', str_contains($page, 'BHD') || str_contains($page, 'SAR'));
} finally {
    @unlink($jar);
}

echo "\n== 4. a currency mismatch is warned about before a customer hits it ==\n";
try {
    // Site is SAR; set the outlet to BHD, the mismatch actually recorded on production.
    setting_put($pdo, 'ngenius_currency', 'BHD');
    setting_put($pdo, 'ngenius_enabled', '1');
    // The warning only renders for a *configured* gateway, so give it an outlet.
    setting_put($pdo, 'ngenius_outlet_ref', 'probe-outlet');
    $jar2 = tempnam(sys_get_temp_dir(), 'exec68adm2');
    try {
        admin_login($base, $jar2);
        [$c, $page] = http("$base/admin/gateways.php", null, $jar2);
        chk('the gateways console renders', $c === 200, (string)$c);
        chk('it names the outlet currency', str_contains($page, 'BHD'));
        chk('it names the site currency', str_contains($page, 'SAR'));
        chk('it says payments will be refused', str_contains($page, 'سيُرفض') || str_contains($page, 'refused'));
    } finally {
        @unlink($jar2);
    }
} finally {
    foreach ($prior as $k => $v) {
        if ($v === null) {
            $pdo->prepare("DELETE FROM site_settings WHERE setting_key=?")->execute([$k]);
        } else {
            setting_put($pdo, $k, $v);
        }
    }
}

echo "\n----------------------------------------\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . ": $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
