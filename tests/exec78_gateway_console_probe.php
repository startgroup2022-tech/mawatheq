<?php
/**
 * Execution 78 — the operator can read a payment failure from the console.
 *
 * The N-Genius diagnostics layer distinguishes a named cause (a rejected key, a currency
 * mismatch) from an unnamed catch-all, and a customer who sees a named cause is told to
 * contact support rather than retry forever. That distinction is wasted if the console
 * shows only the machine code, so the recent-attempts table now carries the same written
 * explanation the by-reference lookup produces.
 *
 * Drives the real page through Apache with a real admin session; a column added to the
 * markup but never rendered passes `php -l` and fails here.
 *
 * Usage: php tests/exec78_gateway_console_probe.php
 */

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://localhost:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/gateway_diagnostics.php';

$checks = 0; $fail = 0;
function check(string $label, $got, $want = true): void {
    global $checks, $fail;
    $checks++;
    if ($got === $want) { echo "  ok:   $label\n"; return; }
    $fail++;
    $g = is_bool($got) ? var_export($got, true) : (string)$got;
    $w = is_bool($want) ? var_export($want, true) : (string)$want;
    echo "  FAIL: $label — got [$g], want [$w]\n";
}

function http_login(string $base, string $path, string $email, string $pass, string $jar): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $html = curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', (string)$html, $m);
    $token = $m[1] ?? '';
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'email' => $email, 'password' => $pass]),
    ]);
    $body = curl_exec($ch);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$token, $loc, (string)$body];
}

function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}

echo "Execution 78 — payment diagnostics in the admin console\n\n";

// The login throttle counts failed attempts per account and IP; a previous run leaves it
// full and the next login silently fails.
try { $pdo->exec('DELETE FROM auth_rate_limits'); } catch (Throwable $e) {}

$sfx = bin2hex(random_bytes(3));
// A real reference is exactly 8 hex characters; gateway_diag_find() rejects anything else,
// so a fixture with a letter outside A-F would test nothing.
$ref1 = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$ref2 = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// --- 1. The two codes an operator actually meets -------------------------------
echo "[1] seed a named cause and its explanation\n";
gateway_diag_record($pdo, [
    'correlation_id' => $ref1, 'gateway' => 'ngenius', 'environment' => 'production',
    'operation' => 'package_payment', 'stage' => 'token', 'http_status' => 401, 'ok' => false,
    'error_code' => 'badTokenRequest', 'detail' => 'HTTP 401 unauthorized: invalid API key',
]);
gateway_diag_record($pdo, [
    'correlation_id' => $ref2, 'gateway' => 'ngenius', 'environment' => 'sandbox',
    'operation' => 'consultation_payment', 'stage' => 'currency', 'ok' => false,
    'error_code' => 'unsupported_currency', 'detail' => 'site SAR != outlet BHD',
]);
$rows = gateway_diag_find($pdo, $ref1);
check('the seeded row is findable by reference', count($rows) >= 1);
$why = gateway_diag_reason('badTokenRequest');
check('a rejected key has a written Arabic explanation', ($why['ar'] ?? '') !== '');
check('that explanation mentions the API key', str_contains((string)($why['ar'] ?? ''), 'مفتاح'), true);
$why2 = gateway_diag_reason('unsupported_currency');
check('a currency mismatch has its own explanation', ($why2['ar'] ?? '') !== ($why['ar'] ?? ''));

// --- 2. The console renders it ------------------------------------------------
echo "[2] the console page\n";
$jar = sys_get_temp_dir() . "/e78gw-$sfx.cookies";
@unlink($jar);
[, $loc, ] = http_login($base, '/admin/login.php', 'admin@mawatheq.com', 'Test-Password-2026', $jar);
check('admin logs in', str_contains($loc, 'admin'));

// Try the page that hosts the diagnostics table. admin/gateways.php is the documented
// surface; if it redirects (a role gate), the probe says so instead of asserting silently.
[$code, $body] = http_get($base . '/admin/gateways.php', $jar);
check('the gateways console returns 200', $code, 200);
check('the page has no PHP error', !preg_match('/Fatal error|Parse error|Uncaught|SQLSTATE/i', $body));
check('the page renders the diagnostics table', str_contains($body, 'correlation_id') || str_contains($body, strtoupper($ref1)) || str_contains($body, $ref1));
check('the recent-attempts header has an explanation column',
    str_contains($body, 'الشرح') || str_contains($body, 'Explanation'), true);
check('the seeded reference is listed', str_contains($body, $ref1), true);
check('the written explanation is rendered, not just the code',
    str_contains($body, 'مفتاح') || str_contains($body, 'API key'), true);

// --- 3. The reference lookup agrees -------------------------------------------
echo "[3] the by-reference view agrees with the list\n";
$lookupWhy = gateway_diag_reason((string)($rows[0]['error_code'] ?? ''));
check('lookup and list read the same explanation map', ($lookupWhy['ar'] ?? '') === ($why['ar'] ?? ''));

// --- 4. Cleanup ----------------------------------------------------------------
echo "[4] cleanup\n";
$del = $pdo->prepare('DELETE FROM gateway_diagnostics WHERE correlation_id IN (?,?)');
$del->execute([$ref1, $ref2]);
check('seeded rows removed', (int)$pdo->query("SELECT COUNT(*) FROM gateway_diagnostics WHERE correlation_id IN ('$ref1','$ref2')")->fetchColumn() === 0);
@unlink($jar);

echo "\n{$checks} checks, {$fail} failure(s)\n";
exit($fail === 0 ? 0 : 1);
