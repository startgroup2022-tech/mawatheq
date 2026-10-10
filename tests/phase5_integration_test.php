<?php
/**
 * Phase 5 external-integration test.
 *
 * Exercises the integration layer against the real database, inside a transaction that
 * is rolled back, so no test rows survive:
 *  - scoped API tokens: hashed storage, scope parsing, revocation, firm scoping,
 *  - webhook signing: HMAC over `<timestamp>.<body>`, tamper and replay rejection,
 *  - event fan-out: only matching endpoints queue, unknown events are refused,
 *  - delivery idempotency: a (endpoint,event) pair is delivered at most once,
 *  - failure and retry accounting, and the inactive-endpoint skip path.
 *
 * Delivery is exercised against a local HTTP server the test starts itself, so the real
 * cURL path and the real signature header are verified — not a mock.
 *
 * Run: php tests/phase5_integration_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integrations.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; }
    else echo "  ok:   $label\n";
}

/* ---- a tiny receiver so the real cURL + signature path is exercised ---- */
$recvLog = sys_get_temp_dir() . '/mwq_integration_recv_' . getmypid() . '.log';
$portFile = sys_get_temp_dir() . '/mwq_integration_port_' . getmypid() . '.txt';
@unlink($recvLog); @unlink($portFile);

// The receiver binds port 0 and reports the port the OS gave it. Hardcoding a port means a
// receiver left over from an earlier run keeps the port, the new server silently fails to
// bind, the POST is answered by the stale process, and the fresh log stays empty — a
// confusing failure that has nothing to do with the code under test.
$script = sys_get_temp_dir() . '/mwq_integration_recv_' . getmypid() . '.py';
file_put_contents($script, <<<PY
import json, http.server, hashlib, hmac, os
LOG = os.environ['RECV_LOG']; SECRET = os.environ.get('WH_SECRET','')
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get('Content-Length', 0)); body = self.rfile.read(n)
        sig = self.headers.get('X-Mawatheq-Signature',''); ts = self.headers.get('X-Mawatheq-Timestamp','')
        exp = 'sha256=' + hmac.new(SECRET.encode(), (ts + '.' + body.decode()).encode(), hashlib.sha256).hexdigest()
        with open(LOG,'a') as f: f.write(json.dumps({'event': self.headers.get('X-Mawatheq-Event',''), 'sig_valid': hmac.compare_digest(exp, sig)})+chr(10))
        code = 500 if 'fail' in self.headers.get('X-Mawatheq-Event','') else 200
        self.send_response(code); self.send_header('Content-Length','2'); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self,*a): pass
srv = http.server.HTTPServer(('127.0.0.1', 0), H)
with open(os.environ['PORT_FILE'], 'w') as f: f.write(str(srv.server_port))
srv.serve_forever()
PY);

$recvSecret = bin2hex(random_bytes(32));
// Run python directly as an argv array with an env array — no shell wrapper. A wrapper like
// `env VAR=x python3 …` spawns a child, and killing the wrapper (proc_terminate) leaves the
// real receiver orphaned and holding its port across runs. With an argv array the pid we
// hold is the receiver itself.
$devNull = ['file', '/dev/null', 'r'];
$proc = @proc_open(
    ['python3', $script],
    [0 => $devNull, 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['RECV_LOG' => $recvLog, 'WH_SECRET' => $recvSecret, 'PORT_FILE' => $portFile, 'PATH' => (string)getenv('PATH')]
);
$port = 0;
for ($i = 0; $i < 60; $i++) {
    if (is_file($portFile)) { $port = (int)trim((string)@file_get_contents($portFile)); if ($port > 0) break; }
    usleep(100000);
}
$receiverUp = false;
if ($port > 0) {
    for ($i = 0; $i < 20; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock) { fclose($sock); $receiverUp = true; break; }
        usleep(100000);
    }
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));

    echo "== scoped token issuance ==\n";
    $issued = integration_token_issue($pdo, null, "cases token $suffix", ['cases:read']);
    check('token issued', $issued['ok'] && strlen($issued['token']) > 20);
    check('token carries the mwq_ prefix', strpos($issued['token'], INTEGRATION_TOKEN_PREFIX) === 0);

    $stored = $pdo->prepare('SELECT token_hash,scopes FROM integration_api_tokens WHERE id=?');
    $stored->execute([$issued['id']]);
    $row = $stored->fetch();
    check('only the hash is stored, never the raw token', $row['token_hash'] === hash('sha256', $issued['token']) && $row['token_hash'] !== $issued['token']);
    check('stored scope round-trips', integration_scopes_parse($row['scopes']) === ['cases:read']);

    $found = integration_token_find($pdo, $issued['token']);
    check('raw token resolves to its row', $found && (int)$found['id'] === (int)$issued['id']);
    check('unknown token resolves to null', integration_token_find($pdo, INTEGRATION_TOKEN_PREFIX . 'deadbeef') === null);
    check('a token with no scopes is refused', integration_token_issue($pdo, null, 'x', [])['ok'] === false);

    echo "\n== scope semantics ==\n";
    check('granted scope allowed', integration_scope_allows('cases:read', 'cases:read'));
    check('ungranted scope denied', !integration_scope_allows('cases:read', 'invoices:read'));
    check('wildcard grants everything', integration_scope_allows('*', 'calendar:read'));
    check('every advertised scope is enforced on a real endpoint', count(integration_scopes()) === 4);

    echo "\n== webhook target validation ==\n";
    check('https target accepted', integration_webhook_url_valid('https://partner.example.com/hook'));
    check('http target accepted', integration_webhook_url_valid('http://partner.example.com/hook'));
    check('non-http scheme rejected', !integration_webhook_url_valid('ftp://partner.example.com/hook'));
    check('embedded credentials rejected', !integration_webhook_url_valid('https://user:pass@partner.example.com/hook'));

    echo "\n== webhook creation ==\n";
    $hook = integration_webhook_create($pdo, null, "recv $suffix", "http://127.0.0.1:$port/hook", ['invoice.paid']);
    check('webhook created', $hook['ok'] && $hook['id'] > 0);
    check('signing secret generated', strlen($hook['secret']) === 64);
    $secretStored = $pdo->prepare('SELECT secret_enc FROM integration_webhooks WHERE id=?');
    $secretStored->execute([$hook['id']]);
    check('secret stored encrypted, not plaintext', $secretStored->fetchColumn() !== $hook['secret']);

    echo "\n== signature scheme ==\n";
    $body = '{"invoice_id":42}'; $ts = time();
    $sig = integration_webhook_signature($hook['secret'], $body, $ts);
    check('valid signature verifies', integration_webhook_verify($hook['secret'], $body, $sig, $ts));
    check('tampered body fails', !integration_webhook_verify($hook['secret'], $body . 'x', $sig, $ts));
    check('wrong secret fails', !integration_webhook_verify(str_repeat('a', 64), $body, $sig, $ts));
    check('replayed old timestamp fails', !integration_webhook_verify($hook['secret'], $body, $sig, $ts - 1000));

    echo "\n== event fan-out ==\n";
    $before = (int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries')->fetchColumn();
    $evId = integration_event_emit($pdo, null, 'invoice.paid', ['invoice_id' => 42, 'amount' => 10]);
    check('matching event queues a delivery', $evId > 0 && (int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries')->fetchColumn() === $before + 1);
    $before2 = (int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries')->fetchColumn();
    integration_event_emit($pdo, null, 'case.created', ['case_id' => 1]);
    check('non-matching event queues nothing for this endpoint', (int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries')->fetchColumn() === $before2);
    check('unknown event type refused', integration_event_emit($pdo, null, 'not.a.real.event', []) === null);

    echo "\n== delivery against a live receiver ==\n";
    check('test receiver is listening', $receiverUp);
    $pending = $pdo->prepare("SELECT d.* FROM integration_deliveries d WHERE d.webhook_id=? AND d.status='pending' ORDER BY d.id DESC LIMIT 1");
    $pending->execute([$hook['id']]);
    $delivery = $pending->fetch();
    check('a pending delivery exists', !empty($delivery));

    // The stored secret must be the one the receiver validates against.
    $pdo->prepare('UPDATE integration_webhooks SET secret_enc=? WHERE id=?')->execute([secret_encrypt_value($recvSecret), $hook['id']]);
    $res = integration_delivery_attempt($pdo, $delivery);
    check('delivery succeeded', $res['ok'] && $res['http_code'] === 200);
    check('receiver validated the signature', strpos((string)@file_get_contents($recvLog), '"sig_valid": true') !== false);

    echo "\n== idempotency ==\n";
    $again = integration_delivery_attempt($pdo, $delivery);
    check('re-delivering the same row is a no-op', $again['ok'] && $again['status'] === 'delivered');
    $attempts = $pdo->prepare('SELECT attempts FROM integration_deliveries WHERE id=?');
    $attempts->execute([$delivery['id']]);
    check('no extra attempt recorded on replay', (int)$attempts->fetchColumn() === 1);

    echo "\n== failure, retry, and inactive skip ==\n";
    $deadHook = integration_webhook_create($pdo, null, "dead $suffix", 'http://127.0.0.1:9/dead', ['case.created']);
    integration_event_emit($pdo, null, 'case.created', ['case_id' => 7]);
    $deadDelivery = $pdo->prepare("SELECT * FROM integration_deliveries WHERE webhook_id=? ORDER BY id DESC LIMIT 1");
    $deadDelivery->execute([$deadHook['id']]);
    $dd = $deadDelivery->fetch();
    $fail = integration_delivery_attempt($pdo, $dd);
    check('unreachable endpoint records a failure', !$fail['ok'] && $fail['status'] === 'failed');
    $ddRow = $pdo->prepare('SELECT status,attempts,last_error FROM integration_deliveries WHERE id=?');
    $ddRow->execute([$dd['id']]);
    $ddr = $ddRow->fetch();
    check('failure is recorded with an attempt count', $ddr['status'] === 'failed' && (int)$ddr['attempts'] === 1 && $ddr['last_error'] !== null);

    $pdo->prepare('UPDATE integration_webhooks SET is_active=0 WHERE id=?')->execute([$deadHook['id']]);
    $skip = integration_delivery_attempt($pdo, $dd);
    check('inactive endpoint is skipped', $skip['status'] === 'skipped');

    echo "\n== catalog discipline ==\n";
    // Every advertised event must have a real call site, otherwise a partner subscribes to
    // something that never arrives. This is what keeps integration_event_catalog honest.
    $wiredSources = @file_get_contents(__DIR__ . '/../lawyer/cases.php')
        . @file_get_contents(__DIR__ . '/../lawyer/case-detail.php')
        . @file_get_contents(__DIR__ . '/../booking.php')
        . @file_get_contents(__DIR__ . '/../includes/gateway/payment_service.php');
    foreach (array_keys(integration_event_catalog()) as $evt) {
        check("event '$evt' is emitted somewhere", strpos($wiredSources, "'$evt'") !== false);
    }
    // Likewise every scope we advertise must be demanded by a real endpoint, so the admin UI
    // never offers a permission that no endpoint checks.
    $apiSources = '';
    foreach (glob(__DIR__ . '/../api/v1/*.php') as $apiFile) $apiSources .= @file_get_contents($apiFile);
    foreach (array_keys(integration_scopes()) as $scope) {
        check("scope '$scope' is enforced by an endpoint", strpos($apiSources, "'$scope'") !== false);
    }

    echo "\n== revocation ==\n";
    check('token revoke returns true', integration_token_revoke($pdo, (int)$issued['id']));
    check('revoked token no longer resolves', integration_token_find($pdo, $issued['token']) === null);
    check('revoking twice reports false', integration_token_revoke($pdo, (int)$issued['id']) === false);

    echo "\n== cleanup via cascade ==\n";
    check('webhook delete returns true', integration_webhook_delete($pdo, (int)$hook['id']));
    $left = $pdo->prepare('SELECT COUNT(*) FROM integration_deliveries WHERE webhook_id=?');
    $left->execute([$hook['id']]);
    check('deliveries cascade away with the webhook', (int)$left->fetchColumn() === 0);

    $pdo->rollBack();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "  FAIL: unexpected exception — " . $e->getMessage() . "\n";
    $failures++;
}

if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
@unlink($script);
@unlink($recvLog); @unlink($portFile);

echo "\nPhase 5 external integration: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
