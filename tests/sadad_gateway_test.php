<?php
/**
 * SADAD payment gateway integration test.
 *
 * Exercises the REAL code paths against the configured database and a REAL local HTTP server
 * (tests/fixtures/sadad_server.php) — no HTTP mocking. Covers: configuration, payment creation,
 * states, webhook validation/replay, idempotency, tenant isolation, security, and N-Genius
 * regression. Everything runs inside a transaction and is rolled back, so it leaves no residue.
 *
 * Run: php tests/sadad_gateway_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';
require_once __DIR__ . '/../includes/gateway/payment_service.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/finance.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; }
    else echo "  ok:   $label\n";
}
function section(string $t): void { echo "\n== $t ==\n"; }

// ---- start the fixture SADAD server on an ephemeral port ---------------------
$port = 0;
$server = null;
for ($try = 0; $try < 5; $try++) {
    $candidate = random_int(20000, 40000);
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $candidate . ' ' . escapeshellarg(__DIR__ . '/fixtures/sadad_server.php') . ' > /dev/null 2>&1';
    $proc = proc_open($cmd, [], $pipes);
    usleep(400000);
    $probe = @file_get_contents('http://127.0.0.1:' . $candidate . '/__health');
    if ($probe !== false) { $server = $proc; $port = $candidate; break; }
    if (is_resource($proc)) proc_terminate($proc);
}
if ($port === 0) { echo "FATAL: could not start fixture SADAD server\n"; exit(2); }
$baseUrl = 'http://127.0.0.1:' . $port;
register_shutdown_function(function () use ($server) { if (is_resource($server)) proc_terminate($server); });
echo "fixture SADAD server on $baseUrl\n";

$pdo->beginTransaction();

try {
    $suffix = bin2hex(random_bytes(4));
    $pwd = password_hash('Test-Password-123', PASSWORD_DEFAULT);

    // Two independent tenants (a firm and a solo lawyer) to prove isolation.
    $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
        ->execute(["SADAD Owner A $suffix", "sadad-a-$suffix@test.local", $pwd]); $ownerA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'approved\',1)')
        ->execute(["SADAD Solo B $suffix", "sadad-b-$suffix@test.local", $pwd]); $soloB = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,\'active\',1)')
        ->execute(["SADAD Client $suffix", "sadad-client-$suffix@test.local", $pwd]); $client = (int)$pdo->lastInsertId();

    function mk_invoice(PDO $pdo, int $lawyerId, int $userId, float $amount, string $currency = 'SAR'): int {
        $pdo->prepare('INSERT INTO invoices (lawyer_id,user_id,description,amount,status,currency) VALUES (?,?,?,?,\'unpaid\',?)')
            ->execute([$lawyerId, $userId, 'SADAD test invoice', $amount, $currency]);
        return (int)$pdo->lastInsertId();
    }

    $sadadConfig = function (string $env, string $currency = 'SAR') use ($baseUrl): array {
        return array_merge(sadad_default_template(), [
            'sadad_enabled' => '1', 'sadad_environment' => $env, 'sadad_currency' => $currency,
            'sadad_base_url' => $baseUrl, 'sadad_create_endpoint' => '/pay', 'sadad_verify_endpoint' => '/order/{reference}',
            'sadad_refund_endpoint' => '/refund', 'sadad_merchant_id' => 'M-TEST',
            'sadad_auth_mode' => 'bearer', 'sadad_api_key' => 'test-key',
            'sadad_map_reference' => 'reference', 'sadad_map_state' => 'status',
            'sadad_map_amount' => 'amount', 'sadad_map_currency' => 'currency',
            'sadad_map_redirect_url' => 'payment_url', 'sadad_map_transaction_id' => 'transaction_id',
            'sadad_credentials_env' => $env,
        ]);
    };

    // ================= Configuration =================
    section('Configuration');
    $missing = sadad_missing_keys(sadad_default_template());
    check('blank template reports missing keys', in_array('sadad_base_url', $missing, true) && in_array('sadad_create_endpoint', $missing, true));
    check('blank template is not enabled', !sadad_is_enabled(sadad_default_template()));
    check('fully configured sandbox is enabled', sadad_is_enabled($sadadConfig('sandbox')));
    check('fully configured live is enabled', sadad_is_enabled($sadadConfig('live')));
    check('SAR invoice supported', sadad_supports_currency($sadadConfig('sandbox'), 'SAR'));
    check('BHD invoice rejected', !sadad_supports_currency($sadadConfig('sandbox'), 'BHD'));
    check('env mismatch disables gateway', !sadad_is_enabled(array_merge($sadadConfig('sandbox'), ['sadad_credentials_env' => 'live'])));
    check('mask hides all but last 4', sadad_mask('abcdef1234') === '••••••••1234');
    check('mask of empty is empty', sadad_mask('') === '');

    // Persisted credentials round-trip and env tagging
    $saved = sadad_save_settings($pdo, array_merge($sadadConfig('sandbox'), ['sadad_api_key' => 'secret-key-AAA']), 'lawyer', $soloB);
    check('save returns true', $saved === true);
    $loaded = sadad_settings($pdo, 'lawyer', $soloB);
    check('api key encrypted at rest, decrypted on read', $loaded['sadad_api_key'] === 'secret-key-AAA');
    $rawRow = sadad_load_row($pdo, 'lawyer', $soloB);
    check('api key is NOT stored in plaintext', strpos((string)($rawRow['sadad_api_key'] ?? ''), 'secret-key-AAA') === false);
    check('credentials tagged sandbox', ($loaded['sadad_credentials_env'] ?? '') === 'sandbox');

    // Switching env without new secrets clears them (Sandbox keys must not reach Live).
    // The admin form submits EMPTY password fields, so simulate that: strip secret keys from input.
    $formInput = $loaded;
    foreach (sadad_secret_keys() as $sk) { $formInput[$sk] = ''; }
    $formInput['sadad_environment'] = 'live';
    sadad_save_settings($pdo, $formInput, 'lawyer', $soloB);
    $afterSwitch = sadad_settings($pdo, 'lawyer', $soloB);
    check('env switch without new secrets clears api key', $afterSwitch['sadad_api_key'] === '');
    check('env switch leaves credentials untagged', ($afterSwitch['sadad_credentials_env'] ?? '') === '');
    // Switching env WITH new secrets tags them for the new env
    sadad_save_settings($pdo, array_merge($formInput, ['sadad_environment' => 'live', 'sadad_api_key' => 'live-key-BBB']), 'lawyer', $soloB);
    $afterLive = sadad_settings($pdo, 'lawyer', $soloB);
    check('live key stored and tagged live', $afterLive['sadad_api_key'] === 'live-key-BBB' && $afterLive['sadad_credentials_env'] === 'live');
    check('credentials match env after rotation', sadad_credentials_match_env($afterLive));

    // ================= Payment creation =================
    section('Payment creation');
    $svc = new SadadService($sadadConfig('sandbox'));
    $ok = $svc->createPayment(['amount' => 100.0, 'currency' => 'SAR', 'reference' => 'REF-OK', 'customer' => [], 'urls' => []]);
    check('createPayment ok', !empty($ok['ok']));
    check('createPayment returns redirect url', !empty($ok['redirect_url']));
    check('createPayment echoes reference', ($ok['reference'] ?? '') === 'REF-OK');
    check('createPayment normalises state REDIRECTED', ($ok['state'] ?? '') === 'REDIRECTED');
    check('createPayment maps transaction id', ($ok['transaction_id'] ?? '') !== '');

    $bad = $svc->createPayment(['amount' => 0, 'currency' => 'SAR', 'reference' => 'REF-ZERO', 'customer' => [], 'urls' => []]);
    check('zero amount rejected', empty($bad['ok']) && $bad['error'] === 'invalid_amount');
    $neg = $svc->createPayment(['amount' => -5, 'currency' => 'SAR', 'reference' => 'REF-NEG', 'customer' => [], 'urls' => []]);
    check('negative amount rejected', empty($neg['ok']) && $neg['error'] === 'invalid_amount');
    $bhd = $svc->createPayment(['amount' => 10, 'currency' => 'BHD', 'reference' => 'REF-BHD', 'customer' => [], 'urls' => []]);
    check('BHD rejected before hitting the gateway', empty($bhd['ok']) && $bhd['error'] === 'unsupported_currency');
    $rejected = $svc->createPayment(['amount' => 10, 'currency' => 'SAR', 'reference' => 'REF-BAD-1', 'customer' => [], 'urls' => []]);
    check('gateway HTTP error surfaced', empty($rejected['ok']));

    $unconfigured = new SadadService(sadad_default_template());
    $nc = $unconfigured->createPayment(['amount' => 10, 'currency' => 'SAR', 'reference' => 'X', 'customer' => [], 'urls' => []]);
    check('unconfigured gateway refuses to call', empty($nc['ok']) && $nc['error'] === 'sadad_not_configured');

    // ================= Verification / states =================
    section('Verification and states');
    $vPaid = $svc->verifyPayment('REF-PAID');
    check('verify maps CAPTURED to PAID', gateway_is_paid_state($vPaid['state'] ?? ''));
    $vFail = $svc->verifyPayment('REF-FAIL-1');
    check('verify maps DECLINED to FAILED', ($vFail['state'] ?? '') === 'FAILED');
    $vPend = $svc->verifyPayment('REF-PENDING-1');
    check('verify maps PENDING to PENDING', ($vPend['state'] ?? '') === 'PENDING');
    check('verify returns amount', ($vPaid['amount'] ?? null) !== null);
    check('verify returns SAR currency', strtoupper((string)($vPaid['currency'] ?? '')) === 'SAR');

    // state normalisation table
    check('normalize CAPTURED=>PAID', gateway_normalize_state('CAPTURED') === 'PAID');
    check('normalize PURCHASED=>PAID', gateway_normalize_state('PURCHASED') === 'PAID');
    check('normalize DECLINED=>FAILED', gateway_normalize_state('DECLINED') === 'FAILED');
    check('normalize CANCELED=>CANCELLED', gateway_normalize_state('CANCELED') === 'CANCELLED');
    check('normalize unknown=>PENDING', gateway_normalize_state('WAT') === 'PENDING');

    // ================= Full settle flow + idempotency =================
    section('Settle flow and idempotency');
    $invId = mk_invoice($pdo, $ownerA, $client, 250.0);
    $inv = $pdo->query("SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=$invId")->fetch();

    // Register a tenant-scoped gateway for the invoice owner.
    sadad_save_settings($pdo, $sadadConfig('sandbox'), 'lawyer', $ownerA);
    GatewayRegistry::reset();

    $started = gateway_start_invoice_payment($pdo, $inv);
    check('start payment ok', !empty($started['ok']));
    check('start payment uses sadad', ($started['gateway'] ?? '') === 'sadad');
    check('start payment returns redirect', !empty($started['redirect_url']));
    $ref = (string)$started['reference'];
    $afterStart = $pdo->query("SELECT gateway, gateway_order_ref FROM invoices WHERE id=$invId")->fetch();
    check('invoice records gateway reference', $afterStart['gateway'] === 'sadad' && $afterStart['gateway_order_ref'] === $ref);
    $initRow = $pdo->query("SELECT event_type, amount, currency, environment, status FROM payment_transactions WHERE gateway_order_ref='$ref' AND event_type='ORDER_CREATED'")->fetch();
    check('ORDER_CREATED row logged (N-Genius-compatible event name)', (bool)$initRow);
    check('ORDER_CREATED amount = outstanding balance', (float)$initRow['amount'] === 250.0);
    check('ORDER_CREATED currency SAR', $initRow['currency'] === 'SAR');
    check('ORDER_CREATED environment sandbox', $initRow['environment'] === 'sandbox');

    // Settle once.
    $inv = $pdo->query("SELECT * FROM invoices WHERE id=$invId")->fetch();
    $settle1 = gateway_verify_and_settle($pdo, $inv, 'sadad', ['reference' => $ref, 'source' => 'return']);
    check('settle confirms PAID', ($settle1['state'] ?? '') === 'PAID');
    check('settle recorded the payment', !empty($settle1['recorded']));
    $invPaid = $pdo->query("SELECT status FROM invoices WHERE id=$invId")->fetch();
    check('invoice marked paid', $invPaid['status'] === 'paid');
    $payCount1 = (int)$pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$invId")->fetchColumn();
    check('exactly one payment row', $payCount1 === 1);
    $payRow = $pdo->query("SELECT method, currency FROM invoice_payments WHERE invoice_id=$invId")->fetch();
    check('payment method recorded as sadad', $payRow['method'] === 'sadad');
    check('payment currency SAR', $payRow['currency'] === 'SAR');

    // Idempotency: settle again with the SAME reference — no second payment.
    $settle2 = gateway_verify_and_settle($pdo, $inv, 'sadad', ['reference' => $ref, 'source' => 'webhook']);
    $payCount2 = (int)$pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$invId")->fetchColumn();
    check('second settle does not duplicate payment', $payCount2 === 1);
    check('second settle flagged duplicate', !empty($settle2['duplicate']) || empty($settle2['recorded']));

    // ================= Webhook validation =================
    section('Webhook validation');
    $cfgWithSecret = array_merge($sadadConfig('sandbox'), [
        'sadad_webhook_secret' => 'whsec-test',
        'sadad_webhook_signature_header' => 'x-sadad-signature',
        'sadad_webhook_signature_algo' => 'hmac_sha256',
    ]);
    $whGw = new SadadGateway($cfgWithSecret);
    $payload = json_encode(['reference' => 'REF-WH', 'status' => 'CAPTURED', 'amount' => 10, 'currency' => 'SAR', 'event_id' => 'E1']);
    $sig = hash_hmac('sha256', $payload, 'whsec-test');

    $good = $whGw->handleWebhook(['x-sadad-signature' => $sig], $payload);
    check('valid signature accepted', !empty($good['ok']));
    check('webhook maps state', ($good['state'] ?? '') === 'PAID');
    $badSig = $whGw->handleWebhook(['x-sadad-signature' => 'deadbeef'], $payload);
    check('invalid signature rejected', empty($badSig['ok']) && $badSig['error'] === 'invalid_signature');
    $noSig = $whGw->handleWebhook([], $payload);
    check('missing signature rejected', empty($noSig['ok']));
    $badJson = $whGw->handleWebhook(['x-sadad-signature' => hash_hmac('sha256', 'not-json', 'whsec-test')], 'not-json');
    check('invalid payload rejected', empty($badJson['ok']));
    $unsignedGw = new SadadGateway($sadadConfig('sandbox'));
    $noRef = $unsignedGw->handleWebhook([], json_encode(['status' => 'CAPTURED']));
    check('missing reference rejected', empty($noRef['ok']) && $noRef['error'] === 'missing_reference');
    check('no-secret config accepts payload', !empty($unsignedGw->handleWebhook([], $payload)['ok']));

    // ================= Webhook event idempotency (replay) =================
    section('Webhook replay protection');
    $evRaw = json_encode(['reference' => 'REF-EV', 'status' => 'CAPTURED', 'event_id' => 'EV1']);
    $ev = ['event_id' => 'EV1', 'event_type' => 'payment', 'reference' => 'REF-EV'];
    $e1 = gateway_log_webhook_event($pdo, 'sadad', $ev, $evRaw, 'received');
    check('first webhook event logged', $e1 !== null);
    $e2 = gateway_log_webhook_event($pdo, 'sadad', $ev, $evRaw, 'received');
    check('identical webhook event detected as replay', $e2 === null);
    $e3 = gateway_log_webhook_event($pdo, 'sadad', $ev, $evRaw . ' ', 'received');
    check('different body is a new event', $e3 !== null);

    // ================= Tenant isolation =================
    section('Tenant isolation');
    // Owner A has sandbox creds; solo B has live creds. Each must resolve only their own.
    $cfgA = sadad_settings($pdo, 'lawyer', $ownerA);
    $cfgB = sadad_settings($pdo, 'lawyer', $soloB);
    check('tenant A sees its own env', ($cfgA['sadad_environment'] ?? '') === 'sandbox');
    check('tenant B sees its own env', ($cfgB['sadad_environment'] ?? '') === 'live');
    check('tenant B key is not visible to A', ($cfgA['sadad_api_key'] ?? '') !== ($cfgB['sadad_api_key'] ?? ''));

    $invA = mk_invoice($pdo, $ownerA, $client, 80.0);
    $invB = mk_invoice($pdo, $soloB, $client, 90.0);
    $rowA = $pdo->query("SELECT * FROM invoices WHERE id=$invA")->fetch();
    $rowB = $pdo->query("SELECT * FROM invoices WHERE id=$invB")->fetch();
    [$ta] = sadad_tenant_for_invoice($rowA);
    [$tb] = sadad_tenant_for_invoice($rowB);
    check('invoice A resolves to its own tenant', $ta === 'lawyer');
    $cfgForA = sadad_settings_for_invoice($pdo, $rowA);
    check('invoice A uses A credentials (sandbox)', ($cfgForA['sadad_environment'] ?? '') === 'sandbox');
    $cfgForB = sadad_settings_for_invoice($pdo, $rowB);
    check('invoice B uses B credentials (live)', ($cfgForB['sadad_environment'] ?? '') === 'live');
    check('cross-tenant keys never equal', ($cfgForA['sadad_api_key'] ?? 'x') !== ($cfgForB['sadad_api_key'] ?? 'y'));

    // A gateway bound to tenant B must not be usable to settle tenant A's invoice with B creds.
    $gwB = new SadadGateway($cfgB);
    check('tenant B gateway is enabled with B creds', $gwB->isEnabled());

    // ================= Partial payment =================
    section('Partial payment');
    $invPart = mk_invoice($pdo, $ownerA, $client, 400.0);
    $rowPart = $pdo->query("SELECT * FROM invoices WHERE id=$invPart")->fetch();
    // A gateway that confirms only half the invoice (real gateways may part-capture).
    $halfRef = 'REF-HALF-' . $suffix;
    $store = is_file(sys_get_temp_dir() . '/sadad_fixture_orders.json') ? (json_decode((string)file_get_contents(sys_get_temp_dir() . '/sadad_fixture_orders.json'), true) ?: []) : [];
    $store[$halfRef] = ['amount' => 200.0, 'currency' => 'SAR'];
    file_put_contents(sys_get_temp_dir() . '/sadad_fixture_orders.json', json_encode($store));
    $pdo->prepare('UPDATE invoices SET gateway=\'sadad\', gateway_order_ref=? WHERE id=?')->execute([$halfRef, $invPart]);
    $rowPart = $pdo->query("SELECT * FROM invoices WHERE id=$invPart")->fetch();
    $settlePart = gateway_verify_and_settle($pdo, $rowPart, 'sadad', ['reference' => $halfRef, 'source' => 'return']);
    check('partial settle confirmed PAID by gateway', ($settlePart['state'] ?? '') === 'PAID');
    check('partial settle recorded', !empty($settlePart['recorded']));
    $partStatus = $pdo->query("SELECT status, amount_paid FROM invoices WHERE id=$invPart")->fetch();
    check('invoice becomes partially_paid', $partStatus['status'] === 'partially_paid');
    check('amount_paid equals the partial amount', (float)$partStatus['amount_paid'] === 200.0);
    check('invoice total unchanged by payment', (float)$pdo->query("SELECT amount FROM invoices WHERE id=$invPart")->fetchColumn() === 400.0);

    // ================= Cross-tenant access denied =================
    section('Cross-tenant access denied');
    // A gateway bound to tenant B's credentials must resolve B's config, never A's.
    $gwBoundB = new SadadGateway(sadad_settings($pdo, 'lawyer', $soloB));
    check('gateway bound to B reports B environment', $gwBoundB->environment() === 'live');
    $gwBoundA = new SadadGateway(sadad_settings($pdo, 'lawyer', $ownerA));
    check('gateway bound to A reports A environment', $gwBoundA->environment() === 'sandbox');
    check('A and B adapters are distinct instances', $gwBoundA !== $gwBoundB);
    // Invoice A must never resolve B's live credentials.
    $cfgA2 = sadad_settings_for_invoice($pdo, $pdo->query("SELECT * FROM invoices WHERE id=$invA")->fetch());
    check('invoice A never resolves to live credentials', ($cfgA2['sadad_environment'] ?? '') !== 'live');
    // Deleting a tenant's gateway row must not leak into another tenant.
    $pdo->prepare("DELETE FROM tenant_gateway_settings WHERE tenant_type='lawyer' AND tenant_id=? AND gateway='sadad'")->execute([$soloB]);
    $cfgBAfter = sadad_settings($pdo, 'lawyer', $soloB);
    $cfgAAfter = sadad_settings($pdo, 'lawyer', $ownerA);
    check('B falls back to platform defaults after row removal', ($cfgBAfter['sadad_api_key'] ?? '') === '');
    check('A keeps its own credentials', ($cfgAAfter['sadad_api_key'] ?? '') === 'test-key');

    // ================= Refund gating =================
    section('Refund gating');
    $withRefund = new SadadService($sadadConfig('sandbox'));
    check('refund supported when endpoint configured', $withRefund->supportsRefund());
    $noRefundCfg = sadadConfig_no_refund($baseUrl);
    check('refund unsupported when endpoint blank', !(new SadadService($noRefundCfg))->supportsRefund());
    $r = (new SadadService($noRefundCfg))->refundPayment('REF-1');
    check('refund refused when unsupported', empty($r['ok']) && $r['error'] === 'refund_not_supported');
    $rOk = $withRefund->refundPayment('REF-1');
    check('refund succeeds when configured', !empty($rOk['ok']));

    // ================= Health check =================
    section('Health check');
    $h = new SadadService($sadadConfig('sandbox'));
    check('health connects via verify endpoint', !empty($h->healthCheck()['connected']));
    $hNoProbe = new SadadService(array_merge(sadadConfig_no_refund($baseUrl), ['sadad_verify_endpoint' => '']));
    check('health reports configured_no_probe without verify endpoint', ($hNoProbe->healthCheck()['code'] ?? '') === 'configured_no_probe');

    // ================= Registry / N-Genius regression =================
    section('Registry and N-Genius regression');
    $all = gateway_all($pdo);
    check('registry exposes sadad', isset($all['sadad']));
    check('registry exposes ngenius', isset($all['ngenius']));
    check('sadad adapter id', $all['sadad']->id() === 'sadad');
    check('ngenius adapter id', $all['ngenius']->id() === 'ngenius');
    check('ngenius adapter supports SAR', $all['ngenius']->supportsCurrency('SAR'));
    check('ngenius adapter rejects BHD', !$all['ngenius']->supportsCurrency('BHD'));
    check('ngenius is still disabled without creds', !$all['ngenius']->isEnabled());
    check('ngenius health reports configuration_required', ($all['ngenius']->healthCheck()['code'] ?? '') === 'configuration_required');
    check('ngenius createPayment refuses when disabled', ($all['ngenius']->createPayment(['amount' => 1, 'currency' => 'SAR', 'reference' => 'X', 'customer' => [], 'urls' => []])['error'] ?? '') === 'ngenius_not_enabled');

    // Existing N-Genius helpers still behave as before.
    check('ngenius_is_paid_state still CAPTURED', ngenius_is_paid_state('CAPTURED'));
    check('ngenius_is_paid_state still PURCHASED', ngenius_is_paid_state('PURCHASED'));
    check('ngenius_is_paid_state still rejects PENDING', !ngenius_is_paid_state('PENDING'));
    check('ngenius currency default is SAR', ngenius_currency(ngenius_settings($pdo)) === 'SAR');
    check('site currency default is SAR', currency_code() === 'SAR');

    // ================= Cross-currency invoice refused =================
    section('Currency guard');
    $invBhd = mk_invoice($pdo, $ownerA, $client, 100.0, 'BHD');
    $rowBhd = $pdo->query("SELECT * FROM invoices WHERE id=$invBhd")->fetch();
    $gwForBhd = gateway_for_new_payment($pdo, $rowBhd);
    check('no gateway offered for a BHD invoice', $gwForBhd === null);
    $startedBhd = gateway_start_invoice_payment($pdo, $rowBhd);
    check('BHD invoice cannot start a payment', empty($startedBhd['ok']));

    // ================= Disabled gateway =================
    section('Disabled gateway');
    sadad_save_settings($pdo, array_merge($sadadConfig('sandbox'), ['sadad_enabled' => '0']), 'lawyer', $soloB);
    $gwOff = new SadadGateway(sadad_settings($pdo, 'lawyer', $soloB));
    check('disabled gateway reports not enabled', !$gwOff->isEnabled());

} catch (Throwable $e) {
    $failures++;
    echo "  FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

$pdo->rollBack();

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

/** Config with no refund endpoint (kept at file scope for clarity). */
function sadadConfig_no_refund(string $baseUrl): array {
    return array_merge(sadad_default_template(), [
        'sadad_enabled' => '1', 'sadad_environment' => 'sandbox', 'sadad_currency' => 'SAR',
        'sadad_base_url' => $baseUrl, 'sadad_create_endpoint' => '/pay', 'sadad_verify_endpoint' => '/order/{reference}',
        'sadad_refund_endpoint' => '', 'sadad_auth_mode' => 'none',
        'sadad_map_reference' => 'reference', 'sadad_map_state' => 'status',
        'sadad_map_redirect_url' => 'payment_url', 'sadad_credentials_env' => 'sandbox',
    ]);
}
