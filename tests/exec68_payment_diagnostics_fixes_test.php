<?php
/**
 * Execution 68 — the reference the customer reads must be actionable.
 *
 * A production customer saw "تعذّر بدء عملية الدفع الإلكتروني ... الرقم المرجعي: B7A81366"
 * and there was no way to answer "what happened to B7A81366?". Three defects made that
 * reference a dead end, and this suite pins the fix for each:
 *
 *   1. `includes/consultation_payment.php` calls gateway_normalize_state() /
 *      gateway_is_paid_state() but never loads includes/gateway/contract.php. A consumer
 *      that includes only ngenius.php + consultation_payment.php (the paid-consultation
 *      return page and the webhook confirm path do exactly that) died with "Call to
 *      undefined function" the moment a payment was verified.
 *   2. There was no lookup by reference: the console only listed the last N rows, so an
 *      attempt older than a few minutes could not be found even with the id in hand.
 *   3. When upgrade_v72.sql was not applied the reference was still shown to the customer
 *      but recorded nowhere — not the database, not the log.
 *
 * Run: php tests/exec68_payment_diagnostics_fixes_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

// If a previous interrupted run left the table renamed, put it back before we start.
try { $pdo->exec('RENAME TABLE gateway_diagnostics_exec68_bak TO gateway_diagnostics'); } catch (Throwable $e) {}

/* ------------------------------------------------------------ 1 ---- */
echo "\n== 1. the paid-consultation path loads the gateway contract ==\n";

// consultation_payment.php is included by consultation-payment-return.php and
// ngenius-webhook.php without the gateway bootstrap, and it calls the state helpers.
// It must therefore pull in contract.php itself (directly, or through ngenius.php).
$src = file_get_contents(__DIR__ . '/../includes/consultation_payment.php');
check('consultation_payment.php requires ngenius.php', strpos($src, "ngenius.php") !== false);
$ngeniusSrc = file_get_contents(__DIR__ . '/../includes/ngenius.php');
check('ngenius.php requires gateway/contract.php, so any ngenius consumer has the state helpers',
    strpos($ngeniusSrc, "gateway/contract.php") !== false);

// Load the closure the return page uses, in a fresh process, and assert the functions are
// defined — this is the exact "Call to undefined function" that used to happen.
$probeScript = tempnam(sys_get_temp_dir(), 'gwd68') . '.php';
file_put_contents($probeScript, '<?php
session_start();
require ' . var_export(__DIR__ . '/../config/config.php', true) . ';
$_SESSION["user_id"] = 1; $_SESSION["user_name"] = "x";
require ' . var_export(__DIR__ . '/../includes/auth_guard.php', true) . ';
require ' . var_export(__DIR__ . '/../includes/consultation_payment.php', true) . ';
echo (function_exists("gateway_normalize_state") && function_exists("gateway_is_paid_state")) ? "OK" : "MISSING";
');
$closureOut = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeScript) . ' 2>&1');
check('the return-page closure defines gateway_normalize_state() and gateway_is_paid_state()',
    strpos($closureOut, 'OK') !== false);
@unlink($probeScript);

/* ------------------------------------------------------------ 2 ---- */
echo "\n== 2. a reference can be looked up ==\n";

check('gateway_diag_find() exists', function_exists('gateway_diag_find'));
check('gateway_diag_reason() exists', function_exists('gateway_diag_reason'));
check('a malformed reference returns no rows', gateway_diag_find($pdo, 'not-a-ref') === []);
check('a too-short reference returns no rows', gateway_diag_find($pdo, 'ABC') === []);

if (gateway_diag_schema_ready($pdo)) {
    $recorded = gateway_diag_failure($pdo, 'exec68_lookup', 'config', 'unsupported_currency',
        'site currency SAR != outlet BHD', 'sandbox');
    $corr = (string)$recorded['correlation_id'];
    check('a recorded failure returns an 8-hex reference', (bool)preg_match('/^[A-F0-9]{8}$/', $corr));

    $found = gateway_diag_find($pdo, $corr);
    check('the reference is found by exact lookup', count($found) >= 1);
    check('the lookup returns the right row', ($found[0]['correlation_id'] ?? '') === $corr);
    check('the lookup is case-insensitive (lowercase input)', gateway_diag_find($pdo, strtolower($corr)) !== []);

    $reason = gateway_diag_reason('unsupported_currency');
    check('the reason explains the code in Arabic and English',
        isset($reason['ar'], $reason['en']) && $reason['ar'] !== '' && $reason['en'] !== '');
    check('an unknown code still yields a reason, not a crash',
        (gateway_diag_reason('totally_unknown_code')['en'] ?? '') !== '');

    $pdo->prepare('DELETE FROM gateway_diagnostics WHERE correlation_id=?')->execute([$corr]);
} else {
    echo "  (gateway_diagnostics table absent — skipping the DB lookup round trip)\n";
}

/* ------------------------------------------------------------ 3 ---- */
echo "\n== 3. a reference is never a dead end when the table is missing ==\n";

// The regression: with upgrade_v72.sql unapplied, gateway_diag_record() was a silent no-op
// while the caller still showed the customer a reference. It must now write to the error
// log so the id is findable. gateway_diag_schema_ready() caches per process, so this must be
// exercised in a fresh process with the table genuinely absent.
if (gateway_diag_schema_ready($pdo)) {
    $tmpScript = tempnam(sys_get_temp_dir(), 'gwd68log') . '.php';
    $logFile = tempnam(sys_get_temp_dir(), 'gwd68out');
    file_put_contents($tmpScript, '<?php
require ' . var_export(__DIR__ . '/../config/config.php', true) . ';
require ' . var_export(__DIR__ . '/../includes/gateway_diagnostics.php', true) . ';
gateway_diag_failure($pdo, "exec68_nolog", "config", "no_enabled_gateway", "simulated missing table", "sandbox");
echo "done";
');

    $hidden = false;
    try { $pdo->exec('RENAME TABLE gateway_diagnostics TO gateway_diagnostics_exec68_bak'); $hidden = true; } catch (Throwable $e) {}
    if ($hidden) {
        @shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_log=' . escapeshellarg($logFile)
            . ' ' . escapeshellarg($tmpScript) . ' > /dev/null 2>&1');
        $pdo->exec('RENAME TABLE gateway_diagnostics_exec68_bak TO gateway_diagnostics');
        $logged = is_file($logFile) ? (string)file_get_contents($logFile) : '';
        check('with the table absent, the failure is still written to the error log',
            strpos($logged, 'gateway_diag[') !== false && strpos($logged, 'no_enabled_gateway') !== false);
        check('the log line names the missing migration', strpos($logged, 'upgrade_v72.sql') !== false);
    } else {
        echo "  (could not hide the table in this environment — skipping)\n";
    }
    @unlink($tmpScript);
    @unlink($logFile);
} else {
    echo "  (table already absent — the fallback path is the live path; log check skipped)\n";
}

/* ------------------------------------------------------------ 4 ---- */
echo "\n== 4. the probe compares the site currency to the outlet currency ==\n";

// The probe used to charge in the outlet currency directly, so it reported "ok" while every
// real package order (denominated in the SITE currency) failed with unsupported_currency —
// a check that could not fail. It must now include a currency stage.
$probeSrc = file_get_contents(__DIR__ . '/../includes/gateway_diagnostics.php');
check('the probe compares currency_code() to the outlet currency',
    strpos($probeSrc, 'currency_code()') !== false && strpos($probeSrc, "record('currency'") !== false);

if (function_exists('gateway_diag_probe') && gateway_diag_schema_ready($pdo)) {
    $keys = ['ngenius_enabled','ngenius_environment','ngenius_outlet_ref','ngenius_api_key','ngenius_currency'];
    $saved = [];
    foreach ($keys as $k) {
        $saved[$k] = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key=" . $pdo->quote($k))->fetchColumn();
    }
    $set = function (string $k, string $v) use ($pdo) {
        $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, $v]);
    };
    $set('ngenius_enabled', '1');
    $set('ngenius_environment', 'sandbox');
    $set('ngenius_outlet_ref', 'TEST-OUTLET');
    $set('ngenius_api_key', 'FIXTURE-NOT-A-REAL-KEY');
    // Pick an outlet currency that is guaranteed to differ from the site currency.
    $mismatch = strtoupper(currency_code()) === 'BHD' ? 'SAR' : 'BHD';
    $set('ngenius_currency', $mismatch);

    $probe = gateway_diag_probe($pdo, ['dry_run' => true]);
    check('a mismatched outlet currency fails the probe at the currency stage', ($probe['verdict'] ?? '') === 'currency');
    $sawCurrencyStage = false;
    foreach (($probe['stages'] ?? []) as $st) { if (($st['stage'] ?? '') === 'currency' && empty($st['ok'])) $sawCurrencyStage = true; }
    check('the probe reports an explicit failing currency stage', $sawCurrencyStage);

    foreach ($saved as $k => $v) {
        if ($v === false) $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$k]);
        else $set($k, (string)$v);
    }
} else {
    echo "  (probe or table unavailable — skipping the live currency check)\n";
}

/* ------------------------------------------------------------ 5 ---- */
echo "\n== 5. the console renders the lookup form and the result ==\n";

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
$appUp = false;
$probe = @curl_init($base . '/login.php');
if ($probe) {
    curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    $appUp = curl_exec($probe) !== false;
    curl_close($probe);
}
if (!$appUp) {
    echo "  SKIP: app not reachable on $base\n";
} else {
    $adminEmail = (string)$pdo->query("SELECT email FROM admins WHERE totp_enabled=0 ORDER BY id LIMIT 1")->fetchColumn();
    if ($adminEmail === '') {
        echo "  SKIP: no admin without TOTP to log in as\n";
    } else {
        $cookie = curl_share_init();
        curl_share_setopt($cookie, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
        $req = function (string $path, ?array $post = null) use ($base, $cookie): array {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SHARE => $cookie, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => '']);
            if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
            $b = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, $b];
        };
        [, $loginPage] = $req('/admin/login.php');
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage, $m);
        [$loginCode, ] = $req('/admin/login.php', ['email' => $adminEmail, 'password' => 'Test-Password-2026', 'csrf_token' => $m[1] ?? '']);
        check('the admin login succeeds', $loginCode === 302);

        [$formCode, $formPage] = $req('/admin/gateways.php');
        check('the gateways page renders with the reference lookup form',
            $formCode === 200 && strpos($formPage, 'name="ref"') !== false && stripos($formPage, 'Undefined variable') === false);

        // Look up a reference that does not exist: the page must say so, not blank out.
        [$missCode, $missPage] = $req('/admin/gateways.php?ref=DEADBEEF');
        check('an unknown reference renders the not-found notice',
            $missCode === 200 && (strpos($missPage, 'لا يوجد سجلّ بهذا الرقم المرجعي') !== false || stripos($missPage, 'No diagnostics row has this reference') !== false));

        // Look up a reference that does exist: the page must show the row and its reason.
        if (gateway_diag_schema_ready($pdo)) {
            $rec = gateway_diag_failure($pdo, 'exec68_http', 'token', 'token_failed', 'HTTP 401 badTokenRequest', 'sandbox');
            $corr = (string)$rec['correlation_id'];
            [$hitCode, $hitPage] = $req('/admin/gateways.php?ref=' . $corr);
            check('a known reference renders its row', $hitCode === 200 && strpos($hitPage, $corr) !== false);
            check('the row is explained in plain language', strpos($hitPage, 'رفضت البوابة طلب رمز الدخول') !== false || stripos($hitPage, 'rejected the access-token') !== false);
            $pdo->prepare('DELETE FROM gateway_diagnostics WHERE correlation_id=?')->execute([$corr]);
        }

        // A currency mismatch must be shown BEFORE a customer hits it, not only in the failure log.
        $savedCfg = [];
        foreach (['ngenius_enabled','ngenius_outlet_ref','ngenius_api_key','ngenius_currency'] as $k) {
            $savedCfg[$k] = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key=" . $pdo->quote($k))->fetchColumn();
        }
        $put = function (string $k, string $v) use ($pdo) {
            $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, $v]);
        };
        $put('ngenius_enabled', '1');
        $put('ngenius_outlet_ref', 'TEST-OUTLET');
        $put('ngenius_api_key', 'FIXTURE-NOT-A-REAL-KEY');
        $put('ngenius_currency', strtoupper(currency_code()) === 'BHD' ? 'SAR' : 'BHD');
        [, $mismatchPage] = $req('/admin/gateways.php');
        check('a currency mismatch is warned about on the console before a customer sees it',
            strpos($mismatchPage, 'لا تطابق عملة الموقع') !== false || stripos($mismatchPage, 'does not match the site currency') !== false);
        foreach ($savedCfg as $k => $v) {
            if ($v === false) $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$k]);
            else $put($k, (string)$v);
        }
    }
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
