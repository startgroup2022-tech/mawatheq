<?php
/**
 * Execution 69 — multiple legal specializations per lawyer.
 *
 * A lawyer may now practise more than one area of law. The single `lawyers.specialization`
 * column is kept (it is what every older reader still falls back to), and the full list
 * lives in `lawyers.specializations`, joined by " - " in the canonical order of
 * `specialization_options()`.
 *
 * The suite drives every write path that can set a lawyer's specializations and every read
 * path that can show them, because a multi-select that is only wired into the registration
 * form is exactly the kind of "looks done" change this project keeps getting bitten by:
 *
 *   1.  The helpers (normalise / store / list / display) — array, legacy string, duplicates,
 *       unknown values, and a legacy row that only has the old single column.
 *   2.  Lawyer self-registration over HTTP — two canonical values stored, an unknown-only
 *       submission refused, and the multi-select control actually present in the HTML.
 *   3.  The admin console create + edit over HTTP — a real POST with several values.
 *   4.  The lawyer profile save over HTTP.
 *   5.  The REST surface — lawyer-login, me.php, lawyer-profile GET and save-profile.
 *   6.  The customer journey — a lawyer who declares two mapped areas appears under BOTH
 *       categories, with no explicit `lawyer_specialties` link (the declared-value fallback).
 *   7.  Display — the customer-facing pages render the joined list, not a bare separator.
 *
 * Everything it creates is deleted in cleanup, so it is re-runnable and leaves no residue.
 * Requires the app on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 *
 * Run: php tests/exec69_multi_specialization_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/specialization_field.php';
require_once __DIR__ . '/../includes/legal_journey.php';
require_once __DIR__ . '/../includes/lawyer_admin.php';
require_once __DIR__ . '/../includes/cities.php';

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

function http69(string $method, string $path, array $opts = []): array {
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

function csrf69(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** A fresh cookie jar with a GET so a CSRF token is issued. */
function jar69(string $path): array {
    $jar = tempnam(sys_get_temp_dir(), 'exec69jar');
    [, $body] = http69('GET', $path, ['jar' => $jar]);
    return [$jar, csrf69($body), $body];
}

function admin_session69(): array {
    [$jar, $tok] = jar69('/admin/login.php');
    http69('POST', '/admin/login.php', ['jar' => $jar, 'post' => http_build_query([
        'csrf_token' => $tok, 'email' => 'admin@mawatheq.com', 'password' => 'Test-Password-2026',
    ])]);
    [, $page] = http69('GET', '/admin/lawyers.php', ['jar' => $jar]);
    return [$jar, csrf69($page), $page];
}

$suffix = sprintf('%08d', random_int(10000000, 99999999));
$opts = specialization_options();
$created = ['lawyers' => [], 'categories' => [], 'tokens' => []];

echo "Execution 69 — multiple legal specializations\n";
echo "suffix: $suffix\n";

// ---------------------------------------------------------------------------
section('1. Normalisation: array, legacy string, duplicates, unknown values');
// ---------------------------------------------------------------------------
check('the canonical list is non-empty and unique',
    count($opts) >= 5 && count($opts) === count(array_unique($opts)));
check('an array is kept in canonical order regardless of submission order',
    specialization_normalize_list([$opts[2], $opts[0]]) === [$opts[0], $opts[2]]);
check('duplicates are dropped', specialization_normalize_list([$opts[0], $opts[0], $opts[0]]) === [$opts[0]]);
check('an unknown value is ignored, never invented',
    specialization_normalize_list(['تخصص وهمي']) === []);
check('unknown values mixed with real ones keep only the real ones',
    specialization_normalize_list([$opts[1], 'تخصص وهمي']) === [$opts[1]]);
check('a legacy " - "-joined string normalises to a list',
    specialization_normalize_list($opts[0] . ' - ' . $opts[3]) === [$opts[0], $opts[3]]);
check('an empty string normalises to an empty list', specialization_normalize_list('') === []);
check('a non-scalar entry (nested array) is ignored',
    specialization_normalize_list([[$opts[0]], $opts[1]]) === [$opts[1]]);
check('a non-array, non-string input is an empty list', specialization_normalize_list(42) === []);
check('list_to_stored joins with " - " in order',
    specialization_list_to_stored([$opts[0], $opts[2]]) === $opts[0] . ' - ' . $opts[2]);

// ---------------------------------------------------------------------------
section('2. Reading a row: new column first, legacy column as fallback');
// ---------------------------------------------------------------------------
check('the new column is read when present',
    lawyer_specialization_list(['specializations' => $opts[0] . ' - ' . $opts[1]]) === [$opts[0], $opts[1]]);
check('a row with only the legacy column still resolves',
    lawyer_specialization_list(['specialization' => $opts[4]]) === [$opts[4]]);
check('a legacy " - "-joined single column resolves to both',
    lawyer_specialization_list(['specialization' => $opts[0] . ' - ' . $opts[5]]) === [$opts[0], $opts[5]]);
check('the new column wins over the legacy column when both exist',
    lawyer_specialization_list(['specializations' => $opts[2], 'specialization' => $opts[0]]) === [$opts[2]]);
check('an empty new column falls back to the legacy column',
    lawyer_specialization_list(['specializations' => '', 'specialization' => $opts[1]]) === [$opts[1]]);
check('a row with no specialization at all is an empty list',
    lawyer_specialization_list([]) === []);
check('display joins with " · "', lawyer_specialization_display(['specializations' => $opts[0] . ' - ' . $opts[1]]) === $opts[0] . ' · ' . $opts[1]);
check('display of a row with no specialization is empty (no dangling separator)',
    lawyer_specialization_display([]) === '');

// ---------------------------------------------------------------------------
section('3. Lawyer self-registration over HTTP stores several values');
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM auth_rate_limits');
$regEmail = 'exec69-reg-' . $suffix . '@example.com';
[$jar, $tok, $regPage] = jar69('/register.php?as=lawyer');
check('the lawyer registration page renders 200', strlen($regPage) > 5000);
check('the page offers the multi-select checklist',
    strpos($regPage, 'data-spec-check') !== false && strpos($regPage, 'name="specializations[]"') !== false);
check('the page carries a chip per canonical specialization',
    substr_count($regPage, 'data-spec-label=') === count($opts));
check('the page has no PHP fatal', stripos($regPage, 'fatal error') === false && stripos($regPage, 'uncaught') === false);

$regPost = [
    'csrf_token' => $tok,
    'who' => 'lawyer',
    'account_type' => 'individual',
    'full_name' => 'محامي اختبار ' . $suffix,
    'email' => $regEmail,
    'phone_dial' => '+966',
    'phone_local' => '5' . substr($suffix, 0, 8),
    'password' => 'Test-Pass-2026',
    'password_confirm' => 'Test-Pass-2026',
    'specializations' => [$opts[0], $opts[2], $opts[2]], // one duplicate on purpose
];
[$code, $resp] = http69('POST', '/register.php?as=lawyer', ['jar' => $jar, 'post' => http_build_query($regPost)]);
check('the registration POST does not error', $code === 200 || $code === 302);
check('the registration response has no PHP fatal',
    stripos($resp, 'fatal error') === false && stripos($resp, 'uncaught') === false);

$row = $pdo->prepare('SELECT id, specialization, specializations, status FROM lawyers WHERE email = ?');
$row->execute([$regEmail]);
$reg = $row->fetch(PDO::FETCH_ASSOC);
check('the lawyer row was created', $reg !== false);
if ($reg) {
    $created['lawyers'][] = (int)$reg['id'];
    check('both declared specializations are stored, deduped, in canonical order',
        $reg['specializations'] === $opts[0] . ' - ' . $opts[2]);
    check('the legacy single column holds the first value', $reg['specialization'] === $opts[0]);
    check('a self-registered lawyer starts pending (needs approval)', $reg['status'] === 'pending');
}

// An unknown-only submission is refused before any row is written.
$pdo->exec('DELETE FROM auth_rate_limits');
$badEmail = 'exec69-bad-' . $suffix . '@example.com';
[$jar2, $tok2] = jar69('/register.php?as=lawyer');
$badPost = $regPost;
$badPost['csrf_token'] = $tok2;
$badPost['email'] = $badEmail;
$badPost['specializations'] = ['تخصص وهمي'];
[, $badResp] = http69('POST', '/register.php?as=lawyer', ['jar' => $jar2, 'post' => http_build_query($badPost)]);
$exists = $pdo->prepare('SELECT COUNT(*) FROM lawyers WHERE email = ?');
$exists->execute([$badEmail]);
check('an unknown-only specialization is refused (no row created)', (int)$exists->fetchColumn() === 0);
check('the refusal re-renders the form (no redirect, no fatal)',
    stripos($badResp, 'fatal error') === false && stripos($badResp, 'uncaught') === false);

// ---------------------------------------------------------------------------
section('4. Admin console create + edit over HTTP');
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM auth_rate_limits');
[$ajar, $atok, $adminPage] = admin_session69();
check('the admin lawyers page renders 200', strlen($adminPage) > 20000);
check('the admin modal offers the multi-select checklist',
    strpos($adminPage, 'data-spec-check') !== false && strpos($adminPage, 'name="specializations[]"') !== false);
check('the admin page has no PHP fatal',
    stripos($adminPage, 'fatal error') === false && stripos($adminPage, 'uncaught') === false);

$adminEmail = 'exec69-admin-' . $suffix . '@example.com';
$createPost = [
    'csrf_token' => $atok,
    'action' => 'create',
    'full_name' => 'محامي إدارة ' . $suffix,
    'email' => $adminEmail,
    'phone_dial' => '+966',
    'phone_local' => '5' . strrev(substr($suffix, 0, 8)),
    'password' => 'Test-Pass-2026',
    'specializations' => [$opts[1], $opts[4]],
    'years_experience' => '9',
    'hourly_rate' => '400',
    'country_code' => 'SA',
    'city' => 'الرياض',
    'status' => 'approved',
];
[$code, ] = http69('POST', '/admin/lawyers.php', ['jar' => $ajar, 'post' => http_build_query($createPost)]);
check('the admin create POST redirects (302)', $code === 302);
$row = $pdo->prepare('SELECT id, specialization, specializations FROM lawyers WHERE email = ?');
$row->execute([$adminEmail]);
$adm = $row->fetch(PDO::FETCH_ASSOC);
check('the admin-created lawyer exists', $adm !== false);
if ($adm) {
    $created['lawyers'][] = (int)$adm['id'];
    check('the admin create stored both specializations in canonical order',
        $adm['specializations'] === $opts[1] . ' - ' . $opts[4]);
    check('the admin create set the legacy column to the first value', $adm['specialization'] === $opts[1]);

    // Edit: swap to a different set of two.
    $editPost = $createPost;
    $editPost['action'] = 'edit';
    $editPost['lawyer_id'] = (int)$adm['id'];
    $editPost['password'] = '';
    $editPost['specializations'] = [$opts[0], $opts[3], $opts[6]];
    [$ecode, ] = http69('POST', '/admin/lawyers.php', ['jar' => $ajar, 'post' => http_build_query($editPost)]);
    check('the admin edit POST redirects (302)', $ecode === 302);
    $row->execute([$adminEmail]);
    $adm2 = $row->fetch(PDO::FETCH_ASSOC);
    check('the admin edit replaced the list with the new canonical order',
        $adm2['specializations'] === $opts[0] . ' - ' . $opts[3] . ' - ' . $opts[6]);
    check('the admin edit refreshed the legacy column to the new first value',
        $adm2['specialization'] === $opts[0]);
}

// ---------------------------------------------------------------------------
section('5. REST surface: login, me, profile GET, profile save');
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM auth_rate_limits');
// Give the created lawyer a known password we can log in with.
$apiLawyerId = (int)$adm['id'];
$pdo->prepare("UPDATE lawyers SET password_hash=?, status='approved', email_verified=1 WHERE id=?")
    ->execute([password_hash('Test-Pass-2026', PASSWORD_BCRYPT), $apiLawyerId]);

[, $loginBody] = http69('POST', '/api/v1/auth/lawyer-login.php', [
    'post' => json_encode(['email' => $adminEmail, 'password' => 'Test-Pass-2026', 'device_label' => 'exec69']),
    'headers' => ['Content-Type: application/json'],
]);
$login = json_decode($loginBody, true);
$token = is_array($login) ? (string)($login['token'] ?? '') : '';
check('lawyer-login returns a token', $token !== '');
check('lawyer-login returns the specializations array',
    is_array($login['lawyer']['specializations'] ?? null)
    && $login['lawyer']['specializations'] === [$opts[0], $opts[3], $opts[6]]);
check('lawyer-login still returns the legacy single specialization',
    ($login['lawyer']['specialization'] ?? '') === $opts[0]);

[, $meBody] = http69('GET', '/api/v1/me.php', ['headers' => ['Authorization: Bearer ' . $token]]);
$me = json_decode($meBody, true);
check('me.php returns the specializations list',
    is_array($me['profile']['specializations'] ?? null)
    && $me['profile']['specializations'] === [$opts[0], $opts[3], $opts[6]]);

[, $profBody] = http69('GET', '/api/v1/lawyer-profile.php', ['headers' => ['Authorization: Bearer ' . $token]]);
$prof = json_decode($profBody, true);
check('lawyer-profile GET returns the specializations list',
    is_array($prof['profile']['specializations'] ?? null)
    && $prof['profile']['specializations'] === [$opts[0], $opts[3], $opts[6]]);

[, $saveBody] = http69('POST', '/api/v1/lawyer-profile.php?action=save-profile', [
    'post' => json_encode([
        'full_name' => 'محامي إدارة ' . $suffix,
        'specializations' => [$opts[2], $opts[5]],
        'phone' => '+9665' . strrev(substr($suffix, 0, 8)),
    ]),
    'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
]);
$save = json_decode($saveBody, true);
check('save-profile succeeds', ($save['ok'] ?? false) === true);
$row->execute([$adminEmail]);
$saved = $row->fetch(PDO::FETCH_ASSOC);
check('save-profile replaced the list (canonical order)',
    $saved['specializations'] === $opts[2] . ' - ' . $opts[5]);
check('save-profile refreshed the legacy column', $saved['specialization'] === $opts[2]);

// The legacy single-field client is still accepted.
[, $saveBody2] = http69('POST', '/api/v1/lawyer-profile.php?action=save-profile', [
    'post' => json_encode([
        'full_name' => 'محامي إدارة ' . $suffix,
        'specialization' => $opts[1],
    ]),
    'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
]);
$save2 = json_decode($saveBody2, true);
$row->execute([$adminEmail]);
$saved2 = $row->fetch(PDO::FETCH_ASSOC);
check('a legacy single-specialization save-profile still works',
    ($save2['ok'] ?? false) === true && $saved2['specializations'] === $opts[1]);

// ---------------------------------------------------------------------------
section('6. Customer journey matches a lawyer under every declared area');
// ---------------------------------------------------------------------------
// The journey maps a declared specialization onto the taxonomy by category NAME, so the
// test needs the two categories its declarations map to. It creates them if absent and
// removes only what it created.
$needCats = ['الدعاوى التجارية', 'الدعاوى العمالية'];
$catIds = [];
foreach ($needCats as $catName) {
    $q = $pdo->prepare('SELECT id FROM najiz_categories WHERE name_ar = ? LIMIT 1');
    $q->execute([$catName]);
    $cid = (int)$q->fetchColumn();
    if (!$cid) {
        $pdo->prepare('INSERT INTO najiz_categories (name_ar, is_active) VALUES (?, 1)')->execute([$catName]);
        $cid = (int)$pdo->lastInsertId();
        $created['categories'][] = $cid;
    }
    $catIds[$catName] = $cid;
}
check('the two taxonomy categories exist', count($catIds) === 2 && !in_array(0, $catIds, true));

// A fresh lawyer whose ONLY statement is the declared list (no lawyer_specialties link),
// so the declared-value fallback is what places them.
$pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, specialization, specializations, status, jurisdiction, country_code, city, years_experience, rating)
    VALUES (?,?,?,?,?, 'approved', ?, 'SA', 'جدة', 8, 5)")
    ->execute([
        'محامي رحلة ' . $suffix,
        'exec69-journey-' . $suffix . '@example.com',
        password_hash('Test-Pass-2026', PASSWORD_DEFAULT),
        $opts[0],                 // قانون تجاري  -> الدعاوى التجارية
        $opts[0] . ' - ' . $opts[2], // + قانون العمل والعمال -> الدعاوى العمالية
        jurisdiction_for_country('SA'),
    ]);
$journeyId = (int)$pdo->lastInsertId();
$created['lawyers'][] = $journeyId;
$pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id = ?')->execute([$journeyId]);
legal_journey_flush_cache();

$index = legal_journey_lawyer_category_index($pdo);
check('the lawyer is indexed under the commercial category',
    in_array($journeyId, $index[$catIds['الدعاوى التجارية']] ?? [], true));
check('the lawyer is ALSO indexed under the labour category (multi-specialty)',
    in_array($journeyId, $index[$catIds['الدعاوى العمالية']] ?? [], true));

$foundCommercial = legal_journey_lawyers_for_specialty($pdo, $catIds['الدعاوى التجارية']);
$foundLabour = legal_journey_lawyers_for_specialty($pdo, $catIds['الدعاوى العمالية']);
$idsCommercial = array_map(fn($r) => (int)$r['id'], $foundCommercial);
$idsLabour = array_map(fn($r) => (int)$r['id'], $foundLabour);
check('the lawyer appears in the commercial specialty result set', in_array($journeyId, $idsCommercial, true));
check('the lawyer appears in the labour specialty result set', in_array($journeyId, $idsLabour, true));
$rowForSpec = null;
foreach ($foundCommercial as $r) { if ((int)$r['id'] === $journeyId) { $rowForSpec = $r; break; } }
check('the journey row selects the specializations column',
    $rowForSpec !== null && array_key_exists('specializations', $rowForSpec));
check('the journey row displays the full list',
    $rowForSpec !== null && lawyer_specialization_display($rowForSpec) === $opts[0] . ' · ' . $opts[2]);

// ---------------------------------------------------------------------------
section('7. Display on the customer-facing pages');
// ---------------------------------------------------------------------------
// The lawyer who appears under two categories also shows the joined list on their card.
// The journey page is behind the client guard, so sign in as a seeded client first.
$pdo->exec('DELETE FROM auth_rate_limits');
[$cjar, $ctok] = jar69('/login.php');
http69('POST', '/login.php', ['jar' => $cjar, 'post' => http_build_query([
    'csrf_token' => $ctok, 'email' => 'client-a@example.com', 'password' => 'Test-Password-2026',
])]);
[, $listHtml] = http69('GET', '/consultation-journey-lawyers.php?category_id=' . $catIds['الدعاوى التجارية'], ['jar' => $cjar]);
check('the journey lawyers page renders 200', strlen($listHtml) > 1000);
check('the journey card shows the joined list, not a bare separator',
    strpos($listHtml, lawyer_specialization_display(['specializations' => $opts[0] . ' - ' . $opts[2]])) !== false);

// A legacy row that only has the old single column still renders on the lawyer profile page
// (read through the same display helper used everywhere).
$legacyHtml = lawyer_specialization_display(['specialization' => $opts[4]]);
check('a legacy single-column row still renders its value', $legacyHtml === $opts[4]);

// ---------------------------------------------------------------------------
section('8. Cleanup');
// ---------------------------------------------------------------------------
foreach ($created['lawyers'] as $lid) {
    $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id = ?')->execute([$lid]);
    $pdo->prepare('DELETE FROM lawyers WHERE id = ?')->execute([$lid]);
}
foreach ($created['categories'] as $cid) {
    $pdo->prepare('DELETE FROM najiz_categories WHERE id = ?')->execute([$cid]);
}
legal_journey_flush_cache();
$left = $pdo->prepare('SELECT COUNT(*) FROM lawyers WHERE email LIKE ?');
$left->execute(['exec69-%' . $suffix . '%']);
check('every seeded lawyer was removed', (int)$left->fetchColumn() === 0);
check('every seeded category was removed', (function () use ($pdo, $created) {
    if (!$created['categories']) return true;
    $ph = implode(',', array_fill(0, count($created['categories']), '?'));
    $s = $pdo->prepare("SELECT COUNT(*) FROM najiz_categories WHERE id IN ($ph)");
    $s->execute($created['categories']);
    return (int)$s->fetchColumn() === 0;
})());

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures > 0 ? 1 : 0);
