<?php
/**
 * Currency test — exercises the real currency module (includes/currency.php) against the
 * configured database. Covers the default, the settings round-trip, precision per currency,
 * formatting helpers, the finance-core integration, and the gateway currency guard.
 *
 * Run: php tests/currency_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/finance.php';
require_once __DIR__ . '/../includes/ngenius.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$original = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='site_currency'")->fetchColumn();

echo "== default and normalisation ==\n";
check('CURRENCY_DEFAULT is SAR', CURRENCY_DEFAULT === 'SAR');
check('currency_normalize falls back to SAR', currency_normalize('bogus') === 'SAR');
check('currency_normalize uppercases', currency_normalize('  bhd ') === 'BHD');
check('currency_is_valid rejects unknown', !currency_is_valid('XYZ'));

echo "\n== precision per currency ==\n";
check('SAR has 2 decimals', currency_decimals('SAR') === 2);
check('BHD keeps 3 decimals', currency_decimals('BHD') === 3);
check('currency_step for SAR is 0.01', currency_step('SAR') === '0.01');
check('currency_step for BHD is 0.001', currency_step('BHD') === '0.001');

echo "\n== formatting ==\n";
check('currency_number groups and pads', currency_number(1250.5, 'SAR') === '1,250.50');
check('currency_raw has no separators', currency_raw(1250.5, 'SAR') === '1250.50');
check('money_text carries the symbol', money_text(1250.5, 'SAR') === '1,250.50 ر.س');
check('money wraps the value for LTR isolation', str_contains(money(1250.5, 'SAR'), 'num-ltr'));
check('money escapes nothing unexpected', !str_contains(money(1250.5, 'SAR'), '<script'));

echo "\n== settings round-trip drives every helper ==\n";
try {
    $pdo->prepare("UPDATE site_settings SET setting_value='BHD' WHERE setting_key='site_currency'")->execute();
    currency_forget_cache();
    check('currency_code reads the saved setting', currency_code() === 'BHD');
    check('currency_decimals follows the setting', currency_decimals() === 3);
    check('money uses the setting symbol', str_contains(money(10), 'د.ب'));
    check('currency_number follows the setting', currency_number(10) === '10.000');

    $pdo->prepare("UPDATE site_settings SET setting_value='SAR' WHERE setting_key='site_currency'")->execute();
    currency_forget_cache();
    check('currency_code returns to SAR', currency_code() === 'SAR');
    check('currency_decimals returns to 2', currency_decimals() === 2);
} finally {
    $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=\'site_currency\'')->execute([$original ?: 'SAR']);
    currency_forget_cache();
}

echo "\n== finance core integration ==\n";
check('finance_currency matches the setting', finance_currency() === currency_code());
check('finance_round uses 2 decimals for SAR', finance_round(10.555) === 10.56);
check('finance_round honours an explicit 3-decimal currency', finance_round(10.5555, 'BHD') === 10.556);

echo "\n== gateway currency guard ==\n";
check('gateway default currency is SAR', NGENIUS_DEFAULT_CURRENCY === 'SAR');
check('unconfigured outlet defaults to SAR', ngenius_currency([]) === 'SAR');
check('gateway accepts SAR by default', ngenius_supports_currency([], 'SAR'));
check('gateway refuses a non-configured currency', !ngenius_supports_currency([], 'BHD'));
check('gateway accepts the configured currency', ngenius_supports_currency(['ngenius_currency' => 'BHD'], 'BHD'));
check('gateway refuses an unknown currency', !ngenius_supports_currency([], 'XYZ'));
check('gateway refuses null', !ngenius_supports_currency([], null));
check('SAR minor units are halalas (100)', ngenius_minor_units('SAR') === 100);
check('BHD minor units are fils (1000)', ngenius_minor_units('BHD') === 1000);

echo "\n== end-to-end: invoice stored and paid in SAR ==\n";
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $pwd = password_hash('Test-Password-123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'approved',1)")
        ->execute(["Cur Lawyer $suffix", "cur-lawyer-$suffix@test.local", $pwd]);
    $lawyerId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
        ->execute(["Cur Client $suffix", "cur-client-$suffix@test.local", $pwd]);
    $clientId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO invoices (lawyer_id,user_id,description,amount,currency,status,is_draft,subtotal) VALUES (?,?,?,?,?,?,0,?)')
        ->execute([$lawyerId, $clientId, "Cur invoice $suffix", 1250.500, finance_currency(), 'unpaid', 1250.500]);
    $invoiceId = (int)$pdo->lastInsertId();

    $stored = $pdo->prepare('SELECT amount,currency FROM invoices WHERE id=?');
    $stored->execute([$invoiceId]);
    $row = $stored->fetch();
    check('new invoice is denominated in SAR', $row['currency'] === 'SAR');

    $result = finance_record_payment($pdo, $invoiceId, 250.555, ['method' => 'cash']);
    check('payment recorded', !empty($result['ok']));
    $pay = $pdo->prepare('SELECT amount,currency FROM invoice_payments WHERE invoice_id=? ORDER BY id DESC LIMIT 1');
    $pay->execute([$invoiceId]);
    $p = $pay->fetch();
    check('payment rounded to 2 decimals', (string)$p['amount'] === '250.560');
    check('payment carries the invoice currency', $p['currency'] === 'SAR');
} finally {
    $pdo->rollBack();
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
