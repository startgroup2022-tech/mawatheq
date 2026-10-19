<?php
/**
 * Saudi Arabia default configuration — regression test.
 *
 * The platform is Saudi-first: country SA, dialling code +966, currency SAR,
 * language ar, layout RTL, timezone Asia/Riyadh, jurisdiction Saudi Arabia. This
 * suite pins each of those defaults so a future edit cannot quietly reintroduce a
 * Bahrain default (country BH, currency BHD, dialling +973) or drop the timezone.
 *
 * Two halves:
 *   * the PHP defaults the code reads at runtime;
 *   * upgrade_v61.sql, which aligns the schema defaults with the code — checked on
 *     a scratch database built from the real schema, so it also proves the file is
 *     safe on an install that never ran it.
 *
 * Run: php tests/saudi_defaults_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/country.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/jurisdiction.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/lib/migration_harness.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

// ===========================================================================
echo "== currency default ==\n";
// ===========================================================================
check('CURRENCY_DEFAULT is SAR', CURRENCY_DEFAULT === 'SAR');
check('an unknown currency falls back to SAR', currency_normalize('BHD-typo') === 'SAR');
check('BHD is still a supported currency (list entry, not a default)',
    currency_is_valid('BHD') && currency_is_valid('SAR'));
check('SAR is the platform currency', currency_code() === 'SAR');

// ===========================================================================
echo "\n== country default ==\n";
// ===========================================================================
check('COUNTRY_DEFAULT is SA', COUNTRY_DEFAULT === 'SA');
check('an unknown country falls back to SA', country_normalize('ZZ') === 'SA');
check('an empty country falls back to SA', country_normalize('') === 'SA');
check('a lower-case code is normalised', country_normalize('sa') === 'SA');
check('a valid non-default country is preserved', country_normalize('AE') === 'AE');
check('Saudi Arabia is the first option offered',
    array_key_first(country_options()) === 'SA');
check('Bahrain is still offered (list entry, not a default)',
    isset(country_options()['BH']));
check('country_name resolves in the active language', country_name('SA') !== '');

// ===========================================================================
echo "\n== dialling code ==\n";
// ===========================================================================
$dial = phone_countries();
check('+966 is the first dialling code offered', $dial[0]['dial'] === '+966');
check('the first dialling code is Saudi Arabia', $dial[0]['name_en'] === 'Saudi Arabia');
check('+973 is still offered (list entry, not a default)',
    in_array('+973', array_column($dial, 'dial'), true));

// ===========================================================================
echo "\n== language and direction ==\n";
// ===========================================================================
unset($_SESSION['lang']);
check('the default language is Arabic', current_lang() === 'ar');
check('Arabic renders right-to-left', is_rtl() === true);

// ===========================================================================
echo "\n== timezone ==\n";
// ===========================================================================
check('the site timezone is Asia/Riyadh', date_default_timezone_get() === 'Asia/Riyadh');
check('SITE_TIMEZONE is defined', defined('SITE_TIMEZONE') && SITE_TIMEZONE === 'Asia/Riyadh');
// A Riyadh timestamp must be ahead of UTC, never behind it.
check('the configured zone is UTC+03:00',
    (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->getOffset() === 3 * 3600);

// ===========================================================================
echo "\n== jurisdiction ==\n";
// ===========================================================================
check('Saudi Arabia is the first jurisdiction', jurisdiction_options()[0] === 'السعودية');

// ===========================================================================
echo "\n== affiliate programme defaults ==\n";
// ===========================================================================
$settings = affiliate_settings_defaults();
check('the payout currency default is SAR',
    currency_normalize($settings['affiliate_payout_currency'] ?? '') === 'SAR');

// ===========================================================================
echo "\n== upgrade_v61.sql ==\n";
// ===========================================================================
$v61 = e291_root() . '/database/upgrade_v61.sql';
check('database/upgrade_v61.sql exists', is_file($v61));

// A database where v61 has NOT run. schema.sql is the consolidated base shape; the
// affiliate tables arrive with upgrade_v57.sql, so the scratch database is built the
// way a real deployment is — schema, then every upgrade in order, stopping before v61.
// That leaves the original BH/BHD defaults in place, which is the state production is
// in before this migration.
$db = 'mawatheq_v61_' . bin2hex(random_bytes(4));
$admin = e291_admin();
try {
    e291_build_fresh($admin, $db);
    foreach (e291_upgrade_files() as $file) {
        if (basename($file) === 'upgrade_v61.sql') continue;
        e291_apply_sql($admin, $db, $file);
    }

    $defaultOf = function (string $table, string $column) use ($admin, $db): ?string {
        $s = $admin->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $s->execute([$db, $table, $column]);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    };

    check('before v61 the schema default is the old one (BH)',
        strtoupper((string)$defaultOf('affiliates', 'country')) === "'BH'");

    // An affiliate recorded under the old default, which must survive untouched.
    $admin->exec("USE `$db`");
    $admin->exec("INSERT INTO affiliates (code,email,full_name,affiliate_type,status,compliance_status,payout_currency,country)
                  VALUES ('V61KEEP','v61keep@example.test','V61 Keep','individual','pending','not_reviewed','BHD','BH')");

    e291_apply_sql($admin, $db, $v61);

    $moved = [
        ['affiliates', 'country', 'SA'],
        ['affiliates', 'payout_currency', 'SAR'],
        ['affiliate_applications', 'country', 'SA'],
        ['affiliate_commission_plans', 'currency', 'SAR'],
        ['affiliate_conversions', 'currency', 'SAR'],
        ['affiliate_commissions', 'currency', 'SAR'],
        ['affiliate_ledger_entries', 'currency', 'SAR'],
        ['affiliate_payout_requests', 'currency', 'SAR'],
        ['affiliate_payouts', 'currency', 'SAR'],
        ['affiliate_daily_stats', 'currency', 'SAR'],
    ];
    $allMoved = true;
    foreach ($moved as [$t, $c, $want]) {
        if (strtoupper(trim((string)$defaultOf($t, $c), "'")) !== $want) {
            $allMoved = false;
            echo "        not moved: $t.$c = " . $defaultOf($t, $c) . "\n";
        }
    }
    check('every column default moved to SA/SAR', $allMoved);

    // The whole point of the file: defaults move, stored data does not.
    $keep = $admin->query("SELECT country, payout_currency FROM affiliates WHERE code='V61KEEP'")->fetch();
    check('an existing BH/BHD row is not rewritten',
        $keep && $keep['country'] === 'BH' && $keep['payout_currency'] === 'BHD');

    // The seeded programme default moves, an operator's own choice does not.
    $payout = $admin->query("SELECT setting_value FROM site_settings WHERE setting_key='affiliate_payout_currency'")->fetchColumn();
    check('the seeded payout currency default moved to SAR', strtoupper((string)$payout) === 'SAR');

    $admin->exec("UPDATE site_settings SET setting_value='USD' WHERE setting_key='affiliate_payout_currency'");
    e291_apply_sql($admin, $db, $v61);
    $payout = $admin->query("SELECT setting_value FROM site_settings WHERE setting_key='affiliate_payout_currency'")->fetchColumn();
    check('an operator override (USD) survives a re-run', strtoupper((string)$payout) === 'USD');

    $ledger = $admin->query("SELECT state, requirements_total, requirements_present FROM schema_migrations WHERE filename='upgrade_v61.sql'")->fetch();
    check('the ledger records the migration as verified',
        $ledger && $ledger['state'] === 'verified');
    check('the ledger records full evidence (10/10)',
        $ledger && (int)$ledger['requirements_present'] === 10 && (int)$ledger['requirements_total'] === 10);

    // Idempotency: a second clean run must not error or duplicate.
    $before = (int)$admin->query("SELECT COUNT(*) FROM schema_migrations WHERE filename='upgrade_v61.sql'")->fetchColumn();
    e291_apply_sql($admin, $db, $v61);
    $after = (int)$admin->query("SELECT COUNT(*) FROM schema_migrations WHERE filename='upgrade_v61.sql'")->fetchColumn();
    check('re-running the migration is a no-op', $before === 1 && $after === 1);
} catch (Throwable $e) {
    check('upgrade_v61.sql applied without error', false);
    echo '        ' . $e->getMessage() . "\n";
} finally {
    try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {}
}

// ===========================================================================
echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
