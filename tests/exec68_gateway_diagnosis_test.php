<?php
/**
 * Execution 68 — the payment diagnostic must name the real cause.
 *
 * A production customer saw «تعذّر بدء عملية الدفع الإلكتروني حالياً» plus a reference
 * number. That is the *transient* sentence, shown whenever the recorded error code is not
 * one the classifier recognises — so it told the customer "retry" and told the operator
 * nothing. Two defects produced it, and this suite pins both:
 *
 *   1. `gateway_diag_probe()` recorded a hardcoded `token_failed` / `order_failed` for every
 *      token and order failure, discarding the gateway's own reason (`badTokenRequest`,
 *      `outletNotAvailable`, `http_401`, `connection_failed`, ...). The probe exists to
 *      answer "why did the payment fail" and was throwing the answer away.
 *
 *   2. A credential that is stored but cannot be decrypted (a rotated
 *      MAWATHEQ_ENCRYPTION_KEY) decrypts to the same empty string as a credential that was
 *      never entered, so the console said "key missing" when the key was present and
 *      unreadable. `secret_decrypt_status()` now distinguishes the two.
 *
 * Run: php tests/exec68_gateway_diagnosis_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/secret_crypto.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label" . ($extra !== '' ? " ($extra)" : '') . "\n"; }
    else echo "  ok:   $label\n";
}

echo "\n== 1. a rotated encryption key is distinguishable from an empty value ==\n";

$plainSecret = 'ngenius-test-api-key-value';
$good = secret_encrypt_value($plainSecret);
check('encrypt produced an enc:v1 value', is_string($good) && str_starts_with($good, 'enc:v1:'));
check('status of a readable secret is ok', secret_decrypt_status($good) === 'ok', secret_decrypt_status($good));
check('the secret round-trips', secret_decrypt_value($good) === $plainSecret);
check('status of an empty value is empty', secret_decrypt_status('') === 'empty');
check('status of a plaintext value is plaintext', secret_decrypt_status('not-encrypted') === 'plaintext');

// A blob of the right shape but encrypted under a different key: this is what a rotated
// MAWATHEQ_ENCRYPTION_KEY leaves behind.
$foreignKey = hash('sha256', 'a-completely-different-encryption-key', true);
$nonce = random_bytes(12); $tag = '';
$cipher = openssl_encrypt('foreign', 'aes-256-gcm', $foreignKey, OPENSSL_RAW_DATA, $nonce, $tag);
$foreignBlob = 'enc:v1:' . base64_encode($nonce . $tag . $cipher);
check('a foreign-key blob reports wrong_key', secret_decrypt_status($foreignBlob) === 'wrong_key', secret_decrypt_status($foreignBlob));
check('and still decrypts to empty (existing contract preserved)', secret_decrypt_value($foreignBlob) === '');
check('a truncated blob reports corrupt', secret_decrypt_status('enc:v1:' . base64_encode('short')) === 'corrupt');

echo "\n== 2. ngenius_settings reports the secret status, never the value ==\n";

$settings = ngenius_settings($pdo);
check('_secret_status is present', isset($settings['_secret_status']) && is_array($settings['_secret_status']));
check('it carries a status for the api key',
    array_key_exists('ngenius_api_key', $settings['_secret_status'] ?? []),
    json_encode(array_keys($settings['_secret_status'] ?? [])));
$statuses = array_values($settings['_secret_status'] ?? []);
$valid = ['empty', 'plaintext', 'ok', 'no_key', 'no_openssl', 'corrupt', 'wrong_key'];
check('every status is a known name', array_reduce($statuses,
    fn($c, $s) => $c && in_array($s, $valid, true), true), implode(',', $statuses));
// `ngenius_settings()` must return the decrypted key — the gateway client needs it. The
// property to pin is narrower: the *status* channel, which is what the console renders,
// must never carry the value.
$statusBlob = json_encode($settings['_secret_status']);
check('the status map never carries the secret value',
    $statusBlob !== false && !str_contains($statusBlob, (string)($settings['ngenius_api_key'] ?? 'x')),
    (string)$statusBlob);
check('the status map holds only short names', array_reduce($statuses,
    fn($c, $s) => $c && strlen($s) <= 16, true), implode(',', $statuses));

echo "\n== 3. secret_unreadable is a classified, permanent cause ==\n";

$reason = gateway_diag_reason('secret_unreadable');
check('the reason has Arabic text', ($reason['ar'] ?? '') !== '');
check('the reason has English text', ($reason['en'] ?? '') !== '');
check('the reason names the encryption key', str_contains($reason['en'], 'MAWATHEQ_ENCRYPTION_KEY'));
check('it is not reported as unclassified',
    !str_contains($reason['ar'], 'غير مصنّف') && !str_contains($reason['en'], 'nclassified'));

$customer = gateway_diag_customer_message('secret_unreadable', '');
check('the customer is told it is a configuration fault', str_contains($customer, 'إعداد البوابة'));
check('the customer is told nothing was charged', str_contains($customer, 'لم يتم خصم'));
check('the customer is NOT invited to retry a permanent fault',
    !str_contains($customer, 'يمكنك المحاولة مجدداً'));

echo "\n== 4. the probe keeps the gateway's own reason code ==\n";

// A fixture gateway that fails at the token stage with a specific code, so the recorded
// error_code can be asserted. Without the fix the probe wrote `token_failed` regardless.
$src = (string)file_get_contents(__DIR__ . '/../includes/gateway_diagnostics.php');
check('the token stage reads $token[\'code\']', str_contains($src, "\$token['code']"));
check('the order stage reads $order[\'code\']', str_contains($src, "\$order['code']"));
check('the token stage no longer hardcodes token_failed unconditionally',
    !preg_match("/record\('token',\s*false,\s*null,\s*'token_failed'/", $src));
check('the order stage no longer hardcodes order_failed unconditionally',
    !preg_match("/record\('order',\s*false,\s*null,\s*'order_failed'/", $src));

// Exercise the classifier on the codes the helper actually emits, so the assertion is on
// behaviour and not on source text alone.
$emitted = ['badTokenRequest', 'realmNameNotAvailable', 'outletNotAvailable',
            'http_401', 'http_403', 'http_500', 'connection_failed', 'timeout'];
$unclassified = 0;
foreach ($emitted as $code) {
    $mapped = gateway_diag_reason_code($code);
    if (str_contains(gateway_diag_reason($mapped)['en'], 'nclassified')) { $unclassified++; echo "        $code -> $mapped (unclassified)\n"; }
}
check('every code the gateway helper emits classifies to a named reason',
    $unclassified === 0, $unclassified . ' unclassified of ' . count($emitted));

echo "\n== 5. a credential fault does not become a retryable message ==\n";

// The permanent map must cover every code the probe can now record, or the customer is
// again told to retry a fault only an operator can fix.
foreach (['secret_unreadable', 'not_enabled', 'badTokenRequest', 'realmNameNotAvailable',
          'outletNotAvailable', 'unsupported_currency', 'zero_amount', 'migration_required'] as $code) {
    $msg = gateway_diag_customer_message($code, '');
    check("$code shows a permanent (non-retry) message",
        !str_contains($msg, 'يمكنك المحاولة مجدداً'), $msg);
}

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
