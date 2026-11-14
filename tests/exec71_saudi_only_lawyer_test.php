<?php
/**
 * Execution 71 — the platform onboards Saudi lawyers only.
 *
 * A lawyer's country is no longer a choice: the admin form, the lawyer's own profile and
 * both write APIs store Saudi Arabia and ignore anything else, so a crafted request cannot
 * file a lawyer outside the supported jurisdiction. This suite proves that at every level
 * it can fail:
 *
 *   1.  The helpers — `lawyer_country_from_input()` resolves to SA for any input, and the
 *       country picker renders exactly one option.
 *   2.  The admin validator — a POST that names another country still stores SA, and the
 *       dependent city list is the Saudi list.
 *   3.  The admin create/edit over HTTP — a real POST naming Bahrain stores SA and a Saudi
 *       city, and the modal HTML offers only Saudi Arabia.
 *   4.  The lawyer profile page over HTTP — the country field is read-only Saudi Arabia and
 *       a crafted POST cannot move the row.
 *   5.  The REST surface — `save-profile` with a foreign `jurisdiction` still stores SA.
 *   6.  The migration — `upgrade_v75.sql` normalises a pre-existing non-Saudi row.
 *
 * Everything it creates is deleted in cleanup, so it is re-runnable and leaves no residue.
 * Requires the app on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 *
 * Run: php tests/exec71_saudi_only_lawyer_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/country.php';
require_once __DIR__ . '/../includes/cities.php';
require_once __DIR__ . '/../includes/lawyer_admin.php';
require_once __DIR__ . '/../includes/specialization_field.php';

$base = 'http://127.0.0.1:8080';
$checks = 0;
$failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n$t\n"; }

function http71(string $method, string $path, array $opts = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $cfg = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (!empty($opts['headers'])) { $cfg[CURLOPT_HTTPHEADER] = $opts['headers']; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    curl_setopt_array($ch, $cfg);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf71(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** A fresh cookie jar with a GET so a CSRF token is issued. */
function jar71(string $path): array {
    $jar = tempnam(sys_get_temp_dir(), 'exec71jar');
    [, $body] = http71('GET', $path, ['jar' => $jar]);
    return [$jar, csrf71($body), $body];
}

function admin_session71(): array {
    [$jar, $tok] = jar71('/admin/login.php');
    http71('POST', '/admin/login.php', ['jar' => $jar, 'post' => http_build_query([
        'csrf_token' => $tok, 'email' => 'admin@mawatheq.com', 'password' => 'Test-Password-2026',
    ])]);
    [, $page] = http71('GET', '/admin/lawyers.php', ['jar' => $jar]);
    return [$jar, csrf71($page), $page];
}

$suffix = sprintf('%08d', random_int(10000000, 99999999));
$opts = specialization_options();
$created = ['lawyers' => [], 'tokens' => []];

echo "Execution 71 — Saudi-only lawyer country policy\n";
echo "suffix: $suffix\n";

// ---------------------------------------------------------------------------
section('1. The policy helpers');
// ---------------------------------------------------------------------------
check('the only supported lawyer country is SA', LAWYER_COUNTRY_ONLY === 'SA');
check('SA is supported', lawyer_country_is_supported('SA'));
check('BH is not supported', !lawyer_country_is_supported('BH'));
check('an unknown code is not supported', !lawyer_country_is_supported('ZZ'));

$r = lawyer_country_from_input('BH', 'البحرين');
check('a posted foreign code resolves to SA', $r['code'] === 'SA' && $r['jurisdiction'] === 'السعودية');
$r = lawyer_country_from_input('AE', null);
check('a posted foreign code with no jurisdiction resolves to SA', $r['code'] === 'SA');
$r = lawyer_country_from_input('', 'الإمارات');
check('a legacy foreign jurisdiction name resolves to SA', $r['code'] === 'SA' && $r['jurisdiction'] === 'السعودية');
$r = lawyer_country_from_input(null, null);
check('a blank input resolves to SA', $r['code'] === 'SA');
$r = lawyer_country_from_input(['x'], ['y']);
check('a malformed input does not raise and resolves to SA', $r['code'] === 'SA');

// The country picker offers exactly one option.
ob_start();
render_lawyer_country_select('country_code', 'AE', true, 'lawyer_country');
$selectHtml = ob_get_clean();
check('the picker renders exactly one option', substr_count($selectHtml, '<option') === 1);
check('the picker option is SA and pre-selected', strpos($selectHtml, 'value="SA" selected') !== false);
check('the picker carries no other country', strpos($selectHtml, 'value="BH"') === false && strpos($selectHtml, 'value="AE"') === false);

// ---------------------------------------------------------------------------
section('2. The admin validator fixes the country and the city');
// ---------------------------------------------------------------------------
$goodPost = [
    'full_name' => 'محامي اختبار ' . $suffix,
    'email' => 'exec71-' . $suffix . '@example.com',
    'phone_dial' => '+966',
    'phone_local' => '512345678',
    'password' => 'Test-Pass-2026',
    'specialization' => $opts[0],
    'years_experience' => '5',
    'hourly_rate' => '300',
    'city' => 'الرياض',
];

// A POST that names Bahrain and a Bahraini city must not store either.
$foreign = $goodPost; $foreign['country_code'] = 'BH'; $foreign['city'] = 'المنامة';
$v = lawyer_admin_validate($pdo, $foreign, null, true);
check('a foreign country with a foreign city is rejected (the city is not Saudi)', $v['ok'] === false);

// The same foreign country with a Saudi city stores SA, never BH.
$foreign = $goodPost; $foreign['country_code'] = 'BH'; $foreign['city'] = 'جدة';
$v = lawyer_admin_validate($pdo, $foreign, null, true);
check('a foreign country code is ignored and SA is stored', $v['ok'] === true && ($v['data']['country_code'] ?? '') === 'SA');
check('the stored jurisdiction name is Saudi Arabia', ($v['data']['jurisdiction'] ?? '') === 'السعودية');

// A legacy foreign jurisdiction name is likewise ignored.
$legacy = $goodPost; $legacy['country_code'] = ''; $legacy['jurisdiction'] = 'الإمارات'; $legacy['city'] = 'جدة';
$v = lawyer_admin_validate($pdo, $legacy, null, true);
check('a legacy foreign jurisdiction name is ignored and SA is stored',
    $v['ok'] === true && ($v['data']['country_code'] ?? '') === 'SA');

// ---------------------------------------------------------------------------
section('3. Real HTTP: the admin create stores Saudi Arabia');
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM auth_rate_limits');
[$ajar, $atok, $apage] = admin_session71();
check('the admin lawyers page renders 200', strlen($apage) > 20000);
check('the modal country select offers only Saudi Arabia',
    (bool)preg_match('/id="lawyer_country"[^>]*>.*?<option value="SA"[^>]*>.*?<\/select>/s', $apage)
    && substr_count((string)preg_match('/id="lawyer_country".*?<\/select>/s', $apage, $mm) ? $mm[0] : '', '<option') === 1);

$newEmail = 'exec71-http-' . $suffix . '@example.com';
$post = $goodPost;
$post['email'] = $newEmail;
$post['csrf_token'] = $atok;
$post['action'] = 'create';
$post['country_code'] = 'BH';   // crafted: claim Bahrain
$post['city'] = 'جدة';          // a Saudi city, so the write is valid
[$code, ] = http71('POST', '/admin/lawyers.php', ['jar' => $ajar, 'post' => http_build_query($post)]);
check('the create POST answers 302', $code === 302);

$row = $pdo->prepare('SELECT * FROM lawyers WHERE email = ?');
$row->execute([$newEmail]);
$lawyer = $row->fetch(PDO::FETCH_ASSOC);
check('the lawyer row exists', $lawyer !== false);
if ($lawyer) {
    $created['lawyers'][] = (int)$lawyer['id'];
    check('a crafted foreign country is stored as SA', $lawyer['country_code'] === 'SA');
    check('the jurisdiction name is Saudi Arabia', $lawyer['jurisdiction'] === 'السعودية');
    check('the Saudi city is kept', $lawyer['city'] === 'جدة');
}

// ---------------------------------------------------------------------------
section('4. Real HTTP: the lawyer profile page is read-only Saudi Arabia');
// ---------------------------------------------------------------------------
if ($lawyer) {
    $lid = (int)$lawyer['id'];
    // Give the seeded lawyer a login by setting a known password.
    $pdo->prepare("UPDATE lawyers SET status='approved', email_verified=1, password_hash=? WHERE id=?")
        ->execute([password_hash('Test-Pass-2026', PASSWORD_BCRYPT), $lid]);
    $pdo->exec('DELETE FROM auth_rate_limits');

    [$ljar, $ltok, $lpage] = jar71('/lawyer-login.php');
    http71('POST', '/lawyer-login.php', ['jar' => $ljar, 'post' => http_build_query([
        'csrf_token' => $ltok, 'email' => $newEmail, 'password' => 'Test-Pass-2026',
    ])]);
    [$pc, $profileHtml] = http71('GET', '/lawyer/profile.php', ['jar' => $ljar]);
    check('the lawyer profile page renders 200', $pc === 200 && strlen($profileHtml) > 1000);
    check('the country field is a read-only Saudi value',
        strpos($profileHtml, 'value="' . country_name('SA') . '"') !== false && strpos($profileHtml, 'readonly') !== false);
    check('the profile page does not offer a country <select>',
        (bool)preg_match('/<select[^>]*name="jurisdiction"/', $profileHtml) === false);

    // A crafted POST claiming another country must not move the row.
    $ptok = csrf71($profileHtml);
    $savePost = [
        'csrf_token' => $ptok,
        'full_name' => 'اسم معدّل ' . $suffix,
        'phone_dial' => '+966',
        'phone_local' => '512345678',
        'specializations' => [$opts[0]],
        'years_experience' => '6',
        'hourly_rate' => '350',
        'jurisdiction' => 'البحرين',   // crafted
    ];
    [$sc, ] = http71('POST', '/lawyer/profile.php', ['jar' => $ljar, 'post' => http_build_query($savePost)]);
    check('the profile save answers 200', $sc === 200);
    $after = $pdo->prepare('SELECT jurisdiction, country_code FROM lawyers WHERE id = ?');
    $after->execute([$lid]);
    $afterRow = $after->fetch(PDO::FETCH_ASSOC);
    check('a crafted jurisdiction on the profile save is ignored', ($afterRow['jurisdiction'] ?? '') === 'السعودية');
}

// ---------------------------------------------------------------------------
section('5. REST: save-profile ignores a foreign jurisdiction');
// ---------------------------------------------------------------------------
if ($lawyer) {
    $lid = (int)$lawyer['id'];
    $pdo->exec('DELETE FROM auth_rate_limits');
    [$rjar, $rtok, ] = jar71('/lawyer-login.php');
    // Use the REST login to obtain a bearer token.
    [, $loginBody] = http71('POST', '/api/v1/auth/lawyer-login.php', [
        'headers' => ['Content-Type: application/json'],
        'post' => json_encode(['email' => $newEmail, 'password' => 'Test-Pass-2026', 'device_label' => 'exec71']),
    ]);
    $loginJson = json_decode((string)$loginBody, true);
    $token = (string)($loginJson['token'] ?? '');
    check('the REST lawyer login returns a token', $token !== '');
    if ($token !== '') {
        [$sc, $body] = http71('POST', '/api/v1/lawyer-profile.php?action=save-profile', [
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            'post' => json_encode([
                'full_name' => 'REST اسم ' . $suffix,
                'specialization' => $opts[0],
                'jurisdiction' => 'الإمارات',   // crafted
                'country_code' => 'AE',
            ]),
        ]);
        $json = json_decode((string)$body, true);
        check('the REST save-profile succeeds', $sc === 200 && ($json['ok'] ?? false) === true);
        $row2 = $pdo->prepare('SELECT jurisdiction, country_code FROM lawyers WHERE id = ?');
        $row2->execute([$lid]);
        $row2 = $row2->fetch(PDO::FETCH_ASSOC);
        check('a crafted REST jurisdiction is ignored', ($row2['jurisdiction'] ?? '') === 'السعودية');
    }
}

// ---------------------------------------------------------------------------
section('6. The migration normalises a pre-existing non-Saudi row');
// ---------------------------------------------------------------------------
// Seed a row that looks like it predates the policy, then apply the migration the way
// the deploy does and assert the row is corrected.
$pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, specialization, status, email_verified, jurisdiction, country_code, city)
               VALUES (?,?,?,?, 'approved', 1, 'الإمارات', 'AE', 'دبي')")
    ->execute(['محامي قديم ' . $suffix, 'exec71-legacy-' . $suffix . '@example.com', password_hash('x', PASSWORD_BCRYPT), $opts[0]]);
$legacyId = (int)$pdo->lastInsertId();
$created['lawyers'][] = $legacyId;

$migrationSql = (string)file_get_contents(__DIR__ . '/../database/upgrade_v75.sql');
// The file is plain statements (no DELIMITER blocks), so a single exec applies it.
$pdo->exec($migrationSql);
$mig = $pdo->prepare('SELECT jurisdiction, country_code FROM lawyers WHERE id = ?');
$mig->execute([$legacyId]);
$migRow = $mig->fetch(PDO::FETCH_ASSOC);
check('the migration moves the row to Saudi Arabia', ($migRow['jurisdiction'] ?? '') === 'السعودية');
check('the migration moves the country code to SA', ($migRow['country_code'] ?? '') === 'SA');

// Re-running is a no-op (idempotent).
$pdo->exec($migrationSql);
$mig->execute([$legacyId]);
$migRow2 = $mig->fetch(PDO::FETCH_ASSOC);
check('re-running the migration is a no-op', ($migRow2['jurisdiction'] ?? '') === 'السعودية' && ($migRow2['country_code'] ?? '') === 'SA');

// ---------------------------------------------------------------------------
section('7. Cleanup');
// ---------------------------------------------------------------------------
foreach ($created['lawyers'] as $lid) {
    $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id = ?')->execute([$lid]);
    $pdo->prepare('DELETE FROM lawyers WHERE id = ?')->execute([$lid]);
}
$left = $pdo->prepare('SELECT COUNT(*) FROM lawyers WHERE email LIKE ?');
$left->execute(['exec71-%' . $suffix . '%']);
check('every seeded lawyer was removed', (int)$left->fetchColumn() === 0);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures > 0 ? 1 : 0);
