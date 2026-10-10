<?php
/**
 * Live HTTP integration-token authorization probe.
 * Issues scoped tokens, drives real /api/v1/ endpoints, prints the matrix, cleans up.
 * Run: php tests/phase5_integration_http_probe.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integrations.php';

$base = 'http://127.0.0.1:8080';
$firmId = (int)$pdo->query('SELECT id FROM law_firms ORDER BY id LIMIT 1')->fetchColumn();

$hit = function (string $token, string $path) use ($base): int {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"], CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
};

$tokens = [];
$mk = function (string $label, array $scopes) use ($pdo, $firmId, &$tokens): string {
    $t = integration_token_issue($pdo, $firmId, $label, $scopes);
    $tokens[] = (int)$t['id'];
    return (string)$t['token'];
};

$casesOnly = $mk('probe-cases', ['cases:read']);
$allScopes = $mk('probe-all', ['cases:read', 'invoices:read', 'documents:read', 'calendar:read']);

$endpoints = ['cases.php', 'invoices.php', 'documents.php', 'calendar.php', 'me.php', 'notifications.php', 'ai-chat.php'];
$labels = ['cases:read only', 'all read scopes'];

$fail = 0;
$expect = [
    'cases:read only' => ['cases.php' => 200, 'invoices.php' => 403, 'documents.php' => 403, 'calendar.php' => 403,
                          'me.php' => 403, 'notifications.php' => 403, 'ai-chat.php' => 403],
    'all read scopes' => ['cases.php' => 200, 'invoices.php' => 200, 'documents.php' => 200, 'calendar.php' => 200,
                          'me.php' => 403, 'notifications.php' => 403, 'ai-chat.php' => 403],
];

foreach ([$casesOnly, $allScopes] as $i => $tok) {
    $label = $labels[$i];
    echo "== token: $label ==\n";
    foreach ($endpoints as $ep) {
        $code = $hit($tok, "/api/v1/$ep");
        $want = $expect[$label][$ep];
        $ok = $code === $want;
        if (!$ok) $fail++;
        printf("  %-18s -> %d (want %d) %s\n", $ep, $code, $want, $ok ? 'ok' : 'FAIL');
    }
}

echo "== wildcard is refused at issuance ==\n";
$wild = integration_token_issue($pdo, $firmId, 'probe-wild', ['*']);
$ok = $wild['ok'] === false && $wild['token'] === null;
if (!$ok) $fail++;
printf("  issue with ['*'] -> ok=%s (want false) %s\n", var_export($wild['ok'], true), $ok ? 'ok' : 'FAIL');

echo "== revoked token ==\n";
integration_token_revoke($pdo, $tokens[0], $firmId);
$code = $hit($casesOnly, '/api/v1/cases.php');
$ok = $code === 401;
if (!$ok) $fail++;
printf("  cases.php with revoked token -> %d (want 401) %s\n", $code, $ok ? 'ok' : 'FAIL');

// cleanup
foreach ($tokens as $id) { try { integration_token_revoke($pdo, $id, $firmId); } catch (Throwable $e) {} }
$pdo->prepare('DELETE FROM integration_api_tokens WHERE label IN (?,?)')->execute(['probe-cases', 'probe-all']);
echo "\nHTTP integration probe: " . ($fail === 0 ? 'all expectations met' : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
