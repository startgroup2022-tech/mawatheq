<?php
/**
 * Execution 12 — AI provider connectivity, configuration consistency, and diagnostics.
 *
 * The inference checks run against a local mock OpenAI-compatible server
 * (php -S 127.0.0.1:11434 /tmp/mockai/router.php). That server is a MOCK: it proves the
 * client code speaks the OpenAI schema correctly, and nothing at all about whether the
 * owner's real model is reachable. Real-provider verification requires the production host.
 *
 * Run: php tests/execution12_ai_connectivity_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/ai_resilience.php';
require_once __DIR__ . '/../includes/secret_crypto.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$MOCK = getenv('MAWATHEQ_MOCK_AI') ?: 'http://127.0.0.1:11434/v1';
$restore = [];

// ===========================================================================
// 1. Endpoint normalisation
// ===========================================================================
echo "\n== endpoint normalisation ==\n";
check('a plain http base is accepted', ai_endpoint_normalize('http://127.0.0.1:11434/v1') === 'http://127.0.0.1:11434/v1');
check('a trailing slash is trimmed', ai_endpoint_normalize('https://ai.example.com/v1/') === 'https://ai.example.com/v1');
check('an https base is accepted', ai_endpoint_normalize('https://ai.example.com/v1') === 'https://ai.example.com/v1');
check('an empty endpoint is rejected', ai_endpoint_normalize('') === null);
check('a scheme-less host is rejected', ai_endpoint_normalize('127.0.0.1:11434') === null);
check('a non-http scheme is rejected', ai_endpoint_normalize('ftp://ai.example.com') === null);
check('a path is joined onto the base', ai_openai_endpoint_url('http://127.0.0.1:11434/v1', 'chat/completions') === 'http://127.0.0.1:11434/v1/chat/completions');
check('an invalid base yields no url', ai_openai_endpoint_url('nonsense', 'chat/completions') === null);

// ===========================================================================
// 2. The single "is the chat provider usable" definition
// ===========================================================================
echo "\n== ai_settings_configured (single source of truth) ==\n";
$localNoKey = ['ai_provider' => 'openai_compatible', 'ai_api_key' => '', 'ai_endpoint' => 'http://127.0.0.1:11434/v1', 'ai_enabled' => '1'];
$localNoEndpoint = ['ai_provider' => 'openai_compatible', 'ai_api_key' => '', 'ai_endpoint' => '', 'ai_enabled' => '1'];
$openaiNoKey = ['ai_provider' => 'openai', 'ai_api_key' => '', 'ai_enabled' => '1'];
$openaiWithKey = ['ai_provider' => 'openai', 'ai_api_key' => 'sk-test', 'ai_enabled' => '1'];
$disabled = $localNoKey; $disabled['ai_enabled'] = '0';

check('a self-hosted provider with an endpoint and NO key is configured', ai_settings_configured($localNoKey) === true);
check('a self-hosted provider without an endpoint is NOT configured', ai_settings_configured($localNoEndpoint) === false);
check('a hosted provider without a key is NOT configured', ai_settings_configured($openaiNoKey) === false);
check('a hosted provider with a key is configured', ai_settings_configured($openaiWithKey) === true);
check('the ai_enabled switch turns everything off', ai_settings_configured($disabled) === false);

// The shared predicate every feature page must use. It exists so a self-hosted provider
// with no credential is never mistaken for an unconfigured one.
check('ai_chat_provider_configured() exists', function_exists('ai_chat_provider_configured'));
if (function_exists('ai_chat_provider_configured')) {
    check('the shared predicate agrees with ai_settings_configured for a keyless local provider',
        ai_chat_provider_configured($localNoKey) === ai_settings_configured($localNoKey));
    check('the shared predicate returns true for the keyless local provider',
        ai_chat_provider_configured($localNoKey) === true);
}

// The vision path (file/image upload) genuinely needs a hosted provider; the predicate must
// separate "not configured" from "configured but cannot read files", never conflate them.
check('ai_vision_provider_configured() exists', function_exists('ai_vision_provider_configured'));
if (function_exists('ai_vision_provider_configured')) {
    check('vision is false for a keyless local provider even though chat is configured',
        ai_chat_provider_configured($localNoKey) === true && ai_vision_provider_configured($localNoKey) === false);
    check('vision is true for a configured hosted provider',
        ai_vision_provider_configured(['ai_provider' => 'openai', 'ai_api_key' => 'sk-test', 'ai_enabled' => '1']) === true);
}

// ===========================================================================
// 3. The defect: feature pages must not hand-roll a key-requiring check
// ===========================================================================
echo "\n== feature pages use the shared predicate (no ad-hoc key check) ==\n";
$callSites = [
    'lawyer/drafting.php', 'lawyer/dashboard.php', 'lawyer/case-detail.php',
    'api/ai_chat.php', 'api/upload_document.php', 'api/v1/documents.php',
    'includes/legal_ai.php', 'includes/evidence_intelligence.php',
    'includes/court_order_extraction.php', 'includes/contract_comparison.php',
    'includes/ai_workflow.php', 'admin/ai-orchestration.php',
];
$adHoc = 0; $usingHelper = 0;
foreach ($callSites as $rel) {
    $src = file_get_contents(__DIR__ . '/../' . $rel);
    if (preg_match('/empty\(\$settings\[\'ai_api_key\'\]\)/', $src)) { $adHoc++; echo "    still ad-hoc: $rel\n"; }
    if (str_contains($src, 'ai_chat_provider_configured')) $usingHelper++;
}
check('no feature page requires an API key via an ad-hoc empty() check', $adHoc === 0);
check('every feature page routes through the shared predicate', $usingHelper === count($callSites));

// ===========================================================================
// 4. Real HTTP against the mock (proves the client, not the model)
// ===========================================================================
echo "\n== openai-compatible client against the mock server ==\n";
$mockUp = false;
$ch = curl_init($MOCK . '/models');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$probe = curl_exec($ch);
$mockUp = ($probe !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
curl_close($ch);
check('the mock server is up (start: php -S 127.0.0.1:11434 /tmp/mockai/router.php)', $mockUp);

if ($mockUp) {
    $r = call_openai_compatible_raw($MOCK, '', 'mock-legal-model', 'You are a test.', 'مرحبا', 32);
    check('a keyless request to the mock succeeds', !empty($r['ok']));
    check('the mock reply is parsed out of choices[0].message.content', str_contains((string)($r['text'] ?? ''), 'خادم الاختبار'));
    check('token usage is read from the response, not fabricated', ($r['input_tokens'] ?? 0) === 12 && ($r['output_tokens'] ?? 0) === 5);

    $dispatched = ai_provider_chat(['ai_provider' => 'openai_compatible', 'ai_api_key' => '', 'ai_model' => 'mock-legal-model', 'ai_endpoint' => $MOCK], 'sys', 'ping', 32);
    check('ai_provider_chat dispatches openai_compatible to the endpoint', !empty($dispatched['ok']));

    $test = test_ai_connection('openai_compatible', '', 'mock-legal-model', $MOCK);
    check('the admin Test Connection succeeds against a reachable keyless provider', !empty($test['ok']));

    $bad = test_ai_connection('openai_compatible', '', 'mock-legal-model', 'not-a-url');
    check('Test Connection refuses an invalid endpoint', empty($bad['ok']));
    check('the refusal explains the endpoint, not a missing key', str_contains((string)$bad['message'], 'http'));
}

// ===========================================================================
// 5. An unreachable provider is NOT an unconfigured provider
// ===========================================================================
echo "\n== unreachable != unconfigured ==\n";
$dead = ['ai_provider' => 'openai_compatible', 'ai_api_key' => '', 'ai_model' => 'x', 'ai_endpoint' => 'http://127.0.0.1:9/v1', 'ai_enabled' => '1'];
check('an unreachable but valid provider is still "configured"', ai_settings_configured($dead) === true);
$deadCall = ai_provider_chat($dead, 'sys', 'ping', 16, null, 5);
check('calling an unreachable provider fails', empty($deadCall['ok']));
check('the failure carries a connection error for the admin', !empty($deadCall['error']));

// ===========================================================================
// 6. Resilience knobs are actually loaded (they were saved but never read)
// ===========================================================================
echo "\n== resilience settings are loaded ==\n";
foreach (['ai_timeout_seconds' => '47', 'ai_max_retries' => '3', 'ai_rate_limit_per_hour' => '55'] as $k => $v) {
    $restore[$k] = (string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='" . $k . "'")->fetchColumn();
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, $v]);
}
$loaded = ai_load_settings($pdo);
check('ai_timeout_seconds reaches the settings array', ($loaded['ai_timeout_seconds'] ?? null) === '47');
check('ai_max_retries reaches the settings array', ($loaded['ai_max_retries'] ?? null) === '3');
check('ai_rate_limit_per_hour reaches the settings array', ($loaded['ai_rate_limit_per_hour'] ?? null) === '55');
check('ai_request_timeout() reflects the saved value', ai_request_timeout($loaded) === 47);
check('ai_max_retries() reflects the saved value', ai_max_retries($loaded) === 3);
check('ai_rate_limit_per_hour() reflects the saved value', ai_rate_limit_per_hour($loaded) === 55);

// ===========================================================================
// 7. Admin diagnostics: precise blocking reason, no secret leakage
// ===========================================================================
echo "\n== admin diagnostics ==\n";
check('ai_diagnostics() exists', function_exists('ai_diagnostics'));
if (function_exists('ai_diagnostics')) {
    $SECRET = 'sk-test-REDACT-9f8e7d6c5b4a';
    $d1 = ai_diagnostics($pdo, ['ai_provider' => '', 'ai_api_key' => '', 'ai_enabled' => '1']);
    check('an empty provider reports configured=false', ($d1['configured'] ?? true) === false);
    check('the blocking reason names the missing provider', ($d1['blocking'] ?? '') === 'no_provider');
    check('the diagnostics never contain the api key', !str_contains(json_encode($d1), $SECRET));

    $d2 = ai_diagnostics($pdo, ['ai_provider' => 'openai_compatible', 'ai_api_key' => $SECRET, 'ai_model' => 'm', 'ai_endpoint' => 'http://127.0.0.1:9/v1', 'ai_enabled' => '1']);
    check('a reachable-config-but-dead provider reports configured=true', ($d2['configured'] ?? false) === true);
    check('the blocking reason is reachability, not configuration', ($d2['blocking'] ?? '') === 'unreachable');
    check('the diagnostics never contain the api key (dead endpoint)', !str_contains(json_encode($d2), $SECRET));

    $d3 = ai_diagnostics($pdo, ['ai_provider' => 'openai_compatible', 'ai_api_key' => $SECRET, 'ai_model' => 'mock-legal-model', 'ai_endpoint' => $MOCK, 'ai_enabled' => '1']);
    check('a live provider reports reachable=true', ($d3['reachable'] ?? false) === true);
    check('a live provider reports blocking=null', array_key_exists('blocking', $d3) && $d3['blocking'] === null);
    check('the diagnostics never contain the api key (live)', !str_contains(json_encode($d3), $SECRET));

    $d4 = ai_diagnostics($pdo, ['ai_provider' => 'openai', 'ai_api_key' => $SECRET, 'ai_model' => 'gpt-4o-mini', 'ai_enabled' => '1']);
    check('the key presence is reported as a boolean, never the value', ($d4['credentials_present'] ?? false) === true && !str_contains(json_encode($d4), $SECRET));
}

// ===========================================================================
// cleanup
// ===========================================================================
foreach ($restore as $k => $v) {
    if ($v === '' || $v === false) $pdo->prepare("DELETE FROM site_settings WHERE setting_key=?")->execute([$k]);
    else $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$v, $k]);
}

echo "\n  Execution 12 AI connectivity: {$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
