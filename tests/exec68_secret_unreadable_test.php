<?php
/**
 * Execution 68 — an unreadable stored API key must be diagnosed as such, not as "no gateway".
 *
 * The production report was «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»
 * The diagnostics layer exists to turn that sentence into a cause, but it distinguished two
 * very different faults only in the admin *probe* (gateway_diagnostics.php), not on the path
 * a customer actually takes:
 *
 *   - key never entered  -> decrypts to '' -> no_enabled_gateway  ("enter the outlet/key")
 *   - key entered but unreadable under the current MAWATHEQ_ENCRYPTION_KEY
 *                        -> decrypts to '' -> no_enabled_gateway  ("enter the outlet/key")
 *
 * They are the same empty string after decryption, so `ngenius_is_enabled()` cannot tell
 * them apart, and the operator was told to enter a key that was already stored. The fix is
 * `ngenius_key_unreadable()`, consulted by both real payment paths before they report
 * `no_enabled_gateway`.
 *
 * This suite corrupts the ciphertext of a value it encrypts itself and asserts the code that
 * comes back. It restores the original setting in `finally`, so a run against a configured
 * database leaves it exactly as it found it — including the case where the key was empty.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/package_payment.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $failures++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$originalRow = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='ngenius_api_key'")->fetchColumn();
$originalEnabled = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='ngenius_enabled'")->fetchColumn();
$originalOutlet = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='ngenius_outlet_ref'")->fetchColumn();
$createdOrder = 0;

$setSetting = function (string $key, string $value) use ($pdo): void {
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
};

try {
    echo "== 0. the helper exists and reads the status it claims to ==\n";
    check('ngenius_key_unreadable() exists', function_exists('ngenius_key_unreadable'));

    $setSetting('ngenius_enabled', '1');
    $setSetting('ngenius_outlet_ref', '00000000-0000-0000-0000-0000000000aa');

    // An enabled gateway with a *readable* key must not be flagged unreadable.
    $good = secret_encrypt_value('readable-key-value');
    $setSetting('ngenius_api_key', $good);
    $sGood = ngenius_settings($pdo);
    check('a readable key is not flagged unreadable', ngenius_key_unreadable($sGood) === false,
        'status was ' . ($sGood['_secret_status']['ngenius_api_key'] ?? '?'));
    check('a readable key counts as enabled', ngenius_is_enabled($sGood) === true);

    echo "\n== 1. corrupt ciphertext is detected, and is NOT read as 'enabled' ==\n";
    // Flip one character in the base64 body so it cannot decrypt under any key, while keeping
    // the stored shape valid enough that the status reader runs.
    $mid = (int)floor(strlen($good) / 2);
    $bad = substr($good, 0, $mid) . ($good[$mid] === 'A' ? 'B' : 'A') . substr($good, $mid + 1);
    $setSetting('ngenius_api_key', $bad);
    $sBad = ngenius_settings($pdo);
    $badStatus = (string)($sBad['_secret_status']['ngenius_api_key'] ?? '?');
    check('the unreadable status is reported', in_array($badStatus, ['wrong_key', 'corrupt', 'no_key', 'no_openssl'], true),
        "status=$badStatus");
    check('an unreadable key makes ngenius_is_enabled() false (the old behaviour)',
        ngenius_is_enabled($sBad) === false, 'this is why the code collapsed to no_enabled_gateway');
    check('but ngenius_key_unreadable() is true (the new signal)',
        ngenius_key_unreadable($sBad) === true, "status=$badStatus");

    echo "\n== 2. the package payment path reports secret_unreadable, not no_enabled_gateway ==\n";
    $planId = (int)$pdo->query("SELECT id FROM plans WHERE status='active' AND price_monthly > 0 ORDER BY id LIMIT 1")->fetchColumn();
    $userId = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    check('a paid plan exists to order', $planId > 0);
    check('a user exists to order for', $userId > 0);

    if ($planId > 0 && $userId > 0) {
        $created = package_order_create($pdo, $userId, $planId, 'monthly');
        check('the order was created', !empty($created['ok']), json_encode($created ?? [], JSON_UNESCAPED_UNICODE));
        if (!empty($created['ok'])) {
            $createdOrder = (int)$created['order_id'];
            $r = package_payment_start($pdo, $created['order'], [
                'customer_email' => 'probe@example.com', 'language' => 'ar',
            ]);
            check('the payment is refused', empty($r['ok']));
            check('the code names the unreadable secret',
                ($r['error'] ?? '') === 'secret_unreadable',
                'got ' . var_export($r['error'] ?? null, true));
            check('a correlation id is returned', !empty($r['correlation_id']));
            $reason = gateway_diag_reason((string)($r['error'] ?? ''));
            check('the cause tells the operator to re-enter the key',
                str_contains($reason['ar'], 'أعد إدخال') || str_contains($reason['en'], 'Re-enter'),
                $reason['en']);
            check('the cause does NOT say the key is missing',
                !str_contains($reason['en'], 'missing'),
                $reason['en']);

            // The stored row must hold the right code too, so a support reference resolves to it.
            if (!empty($r['correlation_id'])) {
                $rows = gateway_diag_find($pdo, (string)$r['correlation_id']);
                check('a diagnostics row was recorded for the reference', $rows !== []);
                $codes = array_column($rows, 'error_code');
                check('the recorded row carries secret_unreadable', in_array('secret_unreadable', $codes, true),
                    implode(',', array_map('strval', $codes)));
            }
        }
    }

    echo "\n== 3. a plainly disabled gateway still says no_enabled_gateway ==\n";
    $setSetting('ngenius_enabled', '0');
    $setSetting('ngenius_api_key', $good);
    $sOff = ngenius_settings($pdo);
    check('an off gateway is not "unreadable"', ngenius_key_unreadable($sOff) === false,
        'a disabled gateway must keep its own code, not be blamed on the key');
    if ($planId > 0 && $userId > 0) {
        $created2 = package_order_create($pdo, $userId, $planId, 'monthly');
        if (!empty($created2['ok'])) {
            $r2 = package_payment_start($pdo, $created2['order'], ['customer_email' => 'probe@example.com']);
            check('a disabled gateway reports no_enabled_gateway', ($r2['error'] ?? '') === 'no_enabled_gateway',
                'got ' . var_export($r2['error'] ?? null, true));
            $pdo->prepare('DELETE FROM package_orders WHERE id=?')->execute([(int)$created2['order_id']]);
        }
    }
} catch (Throwable $e) {
    $checks++;
    $failures++;
    echo "  FAIL: unexpected error — " . $e->getMessage() . "\n";
} finally {
    // Restore every setting this suite touched, including the "was absent" case.
    foreach ([
        'ngenius_api_key' => $originalRow,
        'ngenius_enabled' => $originalEnabled,
        'ngenius_outlet_ref' => $originalOutlet,
    ] as $key => $value) {
        if ($value === false || $value === null) {
            $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]);
        } else {
            $setSetting($key, (string)$value);
        }
    }
    if ($createdOrder > 0) {
        $pdo->prepare('DELETE FROM package_orders WHERE id=?')->execute([$createdOrder]);
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 68 secret diagnosis: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
