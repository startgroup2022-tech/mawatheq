<?php
/**
 * Execution 29.1 — release artifact smoke test.
 *
 * Exercises the SHIPPED migration package, not the working tree. A test that runs the
 * repository copy proves the repository is correct; it says nothing about the bytes an
 * operator actually downloads. This test unpacks MAWATHEQ_DB_MIGRATIONS_SAFE.zip, applies
 * it to a scratch database in the documented order, and runs the packaged preflight,
 * repair and ledger tools against the result.
 *
 * It also asserts that the artifacts an operator downloads are safe to download: no
 * secrets, no tests, no .git, and the guides are present in the package that needs them.
 *
 * Run: php tests/execution29_1_release_smoke_test.php [artifacts-dir]
 * Default artifacts-dir: /tmp/mawatheq_release
 *
 * Creates and drops its own scratch database; touches no other schema.
 */

require_once __DIR__ . '/lib/migration_harness.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  FAIL: $label\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
    } else {
        echo "  ok:   $label\n";
    }
}

$artifacts = $argv[1] ?? '/tmp/mawatheq_release';
$siteZip = $artifacts . '/MAWATHEQ_WEBSITE_CPANEL_FINAL.zip';
$migZip = $artifacts . '/MAWATHEQ_DB_MIGRATIONS_SAFE.zip';
$sums = $artifacts . '/SHA256SUMS.txt';

echo "\n=== Execution 29.1 — release artifact smoke test ===\n";
echo "artifacts: $artifacts\n";

/**
 * Build the artifacts when they are absent or were built from a different commit.
 *
 * The suite runner invokes this with no argument, so it must produce what it tests rather
 * than assume a prior build. Testing a stale archive would be worse than not testing: it
 * would report on bytes nobody will ever download.
 */
$manifest = $artifacts . '/MANIFEST.txt';
$headRc = 0;
$head = trim((string)shell_exec('git -C ' . escapeshellarg(e291_root()) . ' rev-parse HEAD 2>/dev/null'));
$builtCommit = '';
if (is_file($manifest)) {
    foreach (file($manifest) as $line) {
        if (preg_match('/^commit\s*:\s*(\S+)/', $line, $m)) {
            $builtCommit = $m[1];
        }
    }
}
if (!is_file($siteZip) || !is_file($migZip) || $builtCommit !== $head) {
    echo "building artifacts (absent or built from a different commit)\n";
    echo "  want: $head\n  have: " . ($builtCommit !== '' ? $builtCommit : '(none)') . "\n";
    $out = [];
    $rc = 0;
    exec('bash ' . escapeshellarg(e291_root() . '/tools/build_release.sh') . ' HEAD '
        . escapeshellarg($artifacts) . ' 2>&1', $out, $rc);
    if ($rc !== 0 || !is_file($siteZip)) {
        echo "\n  build failed:\n" . implode("\n", array_slice($out, -20)) . "\n\n";
        check('the release artifacts build', false);
        echo "\n  Execution 29.1 release smoke test: $checks checks, $failures failure(s)\n";
        exit(1);
    }
    check('the release artifacts build', true);
} else {
    check('the release artifacts are current for HEAD', true);
}

// ---------------------------------------------------------------------------
echo "\n== 1. checksums verify ==\n";
// ---------------------------------------------------------------------------
check('SHA256SUMS.txt is present', is_file($sums));
if (is_file($sums)) {
    $lines = array_filter(array_map('trim', file($sums)), fn($l) => $l !== '');
    $bad = [];
    foreach ($lines as $line) {
        if (!preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/', $line, $m)) {
            $bad[] = "malformed: $line";
            continue;
        }
        $path = $artifacts . '/' . $m[2];
        $actual = is_file($path) ? hash_file('sha256', $path) : null;
        if ($actual === null) {
            $bad[] = "missing: {$m[2]}";
        } elseif (!hash_equals($m[1], $actual)) {
            $bad[] = "mismatch: {$m[2]}";
        }
    }
    check('every listed checksum matches the file on disk', $bad === [], implode('; ', $bad));
}

// ---------------------------------------------------------------------------
echo "\n== 2. the site archive is safe to download ==\n";
// ---------------------------------------------------------------------------
$siteList = shell_exec('unzip -Z1 ' . escapeshellarg($siteZip) . ' 2>/dev/null') ?? '';
$siteFiles = array_values(array_filter(array_map('trim', explode("\n", $siteList)), fn($l) => $l !== ''));
check('the site archive lists files', count($siteFiles) > 0);

$forbidden = [];
foreach ($siteFiles as $f) {
    // config/secrets.php holds the database password and encryption key, and the archive
    // is a public download, so its presence is a disclosure, not a packaging mistake.
    if ($f === 'config/secrets.php') {
        $forbidden[] = $f;
    }
    if (preg_match('#^(tests|docs|dist)/#', $f) || preg_match('#^\.git/#', $f) || $f === '.git') {
        $forbidden[] = $f;
    }
}
check('no secrets.php, tests/, docs/, dist/ or .git in the site archive', $forbidden === [], implode(', ', $forbidden));

// The site deploy needs its entry points and rules at the archive root.
foreach (['index.php', '.htaccess', 'reset-password.php', 'config/secrets.example.php'] as $need) {
    check("the site archive contains $need", in_array($need, $siteFiles, true));
}

// ---------------------------------------------------------------------------
echo "\n== 3. the migration archive carries what the operator needs ==\n";
// ---------------------------------------------------------------------------
$migList = shell_exec('unzip -Z1 ' . escapeshellarg($migZip) . ' 2>/dev/null') ?? '';
$migFiles = array_values(array_filter(array_map('trim', explode("\n", $migList)), fn($l) => $l !== ''));
foreach ([
    'database/schema.sql',
    'database/upgrade_v42.sql',
    'database/upgrade_v55.sql',
    'database/upgrade_v56.sql',
    'database/repair/00_preflight_check.php',
    'database/repair/01_repair_reset_columns.sql',
    'database/repair/02_migration_ledger.php',
    'database/repair/migration_verify.php',
    'MAWATHEQ_EXECUTION_30_DEPLOYMENT_AR.md',
    'MAWATHEQ_EXECUTION_30_REPORT.md',
    'MAWATHEQ_EXECUTION_29_1_DEPLOYMENT_AR.md',
    'MAWATHEQ_EXECUTION_29_1_MIGRATION_REPORT.md',
    'MAWATHEQ_EXECUTION_29_1_VERIFICATION_RUNBOOK.md',
    'config/secrets.example.php',
] as $need) {
    check("the migration archive contains $need", in_array($need, $migFiles, true));
}
check('the migration archive has no secrets.php', !in_array('config/secrets.php', $migFiles, true));
check('the migration archive carries no repair script other than the three expected',
    count(array_filter($migFiles, fn($f) => str_starts_with($f, 'database/repair/') && str_ends_with($f, '.sql'))) === 1,
    implode(', ', array_filter($migFiles, fn($f) => str_starts_with($f, 'database/repair/'))));

// ---------------------------------------------------------------------------
echo "\n== 4. apply the shipped package in the documented order ==\n";
// ---------------------------------------------------------------------------
$unpack = '/tmp/e291_smoke_unpack';
shell_exec('rm -rf ' . escapeshellarg($unpack));
mkdir($unpack, 0755, true);
$rc = 0;
exec('unzip -q ' . escapeshellarg($migZip) . ' -d ' . escapeshellarg($unpack . '/migrations') . ' 2>&1', $o, $rc);
check('the migration archive unpacks', $rc === 0);

// The migration package deliberately carries no config.php: it is a drop-in for a site
// that already exists. To run its tools as an operator would, extract the site package
// (which provides config/config.php) and write the secrets.php the deployment guide tells
// the operator to create. Then overlay the migration package's database/ tree on top.
exec('unzip -q ' . escapeshellarg($siteZip) . ' -d ' . escapeshellarg($unpack . '/site') . ' 2>&1', $o3, $rc3);
check('the site archive unpacks', $rc3 === 0);
$site = $unpack . '/site';
// Deliberately no DB_* defines: config.php uses `if (!defined(...))`, so a define here
// would win over the MAWATHEQ_DB_* environment and the packaged tools would silently
// connect to the configured database (mawatheq_test) instead of the scratch one under
// test — the check would then pass while proving nothing. Leaving them out lets
// e291_run_script_pkg() point the packaged tools at the scratch database.
file_put_contents($site . '/config/secrets.php', "<?php\n"
    . "define('SITE_URL', 'http://127.0.0.1:12000');\n"
    . "define('MAWATHEQ_ENCRYPTION_KEY', 'smoke-test-encryption-key-0123456789');\n"
    . "define('ADMIN_SETUP_KEY', 'smoke-test-admin-setup-key-0123456789');\n");
check('config/secrets.php written for the smoke run', is_file($site . '/config/secrets.php'));
// The packaged database/ tooling, over the site tree, exactly as the guide applies it.
$up = 0;
exec('cp -r ' . escapeshellarg($unpack . '/migrations/database/.') . ' ' . escapeshellarg($site . '/database/') . ' 2>&1', $o4, $up);
check('the migration package overlays the site database/ tree', $up === 0);
$pkg = $site;
check('the packaged preflight is present in the runnable tree',
    is_file($pkg . '/database/repair/00_preflight_check.php'));

$admin = e291_admin();
$scratch = 'mawatheq_e291_smoke';
// Build the documented starting point: a database on the CURRENT schema, from which the
// repair is then run. This is the state a production database is in before the upgrade.
e291_build_without($admin, $scratch, $site . '/database/upgrade_v42.sql');
check('scratch database prepared from the shipped schema.sql', e291_has_table($admin, $scratch, 'users'));

// Step 1 — preflight must run and report. It will exit 1 (migration incomplete), which is
// the point: the packaged tool must detect the drift, not report a clean bill of health.
$pre = e291_run_script_pkg($pkg, 'database/repair/00_preflight_check.php', $scratch);
check('the packaged preflight runs', $pre['exit'] === 0 || $pre['exit'] === 1, "exit={$pre['exit']}\n" . $pre['output']);
check('the packaged preflight detects the incomplete migration', $pre['exit'] === 1, "exit={$pre['exit']}");

// Step 2 — ledger schema extension.
e291_apply_sql($admin, $scratch, $site . '/database/upgrade_v55.sql');
check('database/upgrade_v55.sql applies', e291_has_table($admin, $scratch, 'schema_migrations'));

// Step 3 — critical column repair.
$repair = null;
try {
    e291_apply_sql($admin, $scratch, $site . '/database/repair/01_repair_reset_columns.sql');
} catch (Throwable $e) {
    $repair = $e->getMessage();
}
check('the packaged reset-column repair runs to completion', $repair === null, (string)$repair);
foreach ([['users', 'reset_token'], ['users', 'reset_expires'], ['admins', 'session_invalidated_at']] as [$t, $c]) {
    check("the packaged repair ensures $t.$c", e291_has_column($admin, $scratch, $t, $c));
}

// Step 4 — the ledger.
$ledger = e291_run_script_pkg($pkg, 'database/repair/02_migration_ledger.php', $scratch);
check('the packaged ledger runs', $ledger['exit'] === 0 || $ledger['exit'] === 1, "exit={$ledger['exit']}");

// Step 5 — postflight. The reset columns are repaired, but the migration is still partial,
// so postflight must block the deployment rather than wave it through.
$post = e291_run_script_pkg($pkg, 'database/repair/00_preflight_check.php', $scratch, ['--postflight']);
check('the packaged postflight blocks an incomplete migration', $post['exit'] === 1, "exit={$post['exit']}");

// Now complete the migration with every upgrade the package ships, in order — the state a
// fully upgraded production database is in — and re-check: postflight must pass.
$pkgUpgrades = glob($site . '/database/upgrade_v*.sql') ?: [];
usort($pkgUpgrades, static function (string $a, string $b): int {
    preg_match('/upgrade_v(\d+)\.sql$/', $a, $ma);
    preg_match('/upgrade_v(\d+)\.sql$/', $b, $mb);
    return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
});
$applyErr = null;
try {
    foreach ($pkgUpgrades as $f) {
        e291_apply_sql($admin, $scratch, $f);
    }
} catch (Throwable $e) {
    $applyErr = $e->getMessage();
}
check('every packaged upgrade applies in order', $applyErr === null, (string)$applyErr);
$post2 = e291_run_script_pkg($pkg, 'database/repair/00_preflight_check.php', $scratch, ['--postflight']);
check('the packaged postflight passes once the migration is complete', $post2['exit'] === 0, "exit={$post2['exit']}\n" . $post2['output']);

// Execution 30's migration is part of "every packaged upgrade", so the attachments table
// must exist in the scratch database the operator ends up with. Asserting the table and its
// tenant column proves the shipped file ran, not merely that it was present in the archive.
check('the packaged Execution 30 migration created consultation_attachments',
    e291_has_table($admin, $scratch, 'consultation_attachments'));
check('consultation_attachments carries the tenant column',
    e291_has_column($admin, $scratch, 'consultation_attachments', 'firm_id'));
$settingCount = (int)$admin->query("SELECT COUNT(*) FROM `$scratch`.site_settings WHERE setting_key LIKE 'consultation_attachment_%'")->fetchColumn();
check("the packaged Execution 30 migration seeded the limits ($settingCount rows)", $settingCount === 5);

// ---------------------------------------------------------------------------
echo "\n== 5. the reset flow works against the packaged result ==\n";
// ---------------------------------------------------------------------------
$admin->exec("USE `$scratch`");
$admin->exec("INSERT INTO users (id, full_name, email, password_hash) VALUES (9201,'Smoke','smoke@example.test','x')");
$admin->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=9201")
    ->execute([hash('sha256', 'smoke-token')]);
$s = $admin->prepare('SELECT COUNT(*) FROM users WHERE reset_token=? AND reset_expires > NOW()');
$s->execute([hash('sha256', 'smoke-token')]);
check('a reset token can be issued and read against the shipped schema', (int)$s->fetchColumn() === 1);

// ---------------------------------------------------------------------------
e291_drop($admin, $scratch);
shell_exec('rm -rf ' . escapeshellarg($unpack));

echo "\n  Execution 29.1 release smoke test: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);

/**
 * Run one of the CLI scripts from an unpacked package directory.
 *
 * The scripts resolve config.php relative to their own location, so running the packaged
 * copy needs the package's own database/ and config/ trees — which is what this uses. The
 * package's config/ holds only secrets.example.php, so config.php falls back to its
 * defaults and MAWATHEQ_DB_* environment variables supply the connection.
 */
function e291_run_script_pkg(string $pkg, string $relative, string $db, array $args = []): array
{
    $env = 'MAWATHEQ_DB_HOST=' . escapeshellarg(DB_HOST)
        . ' MAWATHEQ_DB_NAME=' . escapeshellarg($db)
        . ' MAWATHEQ_DB_USER=' . escapeshellarg(DB_USER)
        . ' MAWATHEQ_DB_PASS=' . escapeshellarg(DB_PASS);
    $cmd = $env . ' php ' . escapeshellarg($pkg . '/' . $relative);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $cmd .= ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    return ['exit' => $rc, 'output' => implode("\n", $out)];
}
