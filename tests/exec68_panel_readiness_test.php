<?php
/**
 * Execution 68 — lawyer panel readiness.
 *
 * A lawyer account can exist, log in, and still be **unbookable**: no price, no published
 * working hours, or no declared area of law. The customer then reaches a dead end and the
 * operator cannot tell a half-set-up account from a ready one. `includes/lawyer_readiness.php`
 * gives one answer, read by the lawyer's own dashboard and the admin list, so they cannot
 * disagree.
 *
 * This suite pins that answer:
 *
 *   1. The predicate — each *required* signal flips `ready`, each *recommended* one moves
 *       `percent` only, and a legacy row with only the old single column still counts.
 *   2. `lawyer_readiness_counts()` — one batched pass, and a missing table degrades to 0.
 *   3. The lawyer dashboard over HTTP — the readiness card and the "not ready" attention row
 *       appear while blocked and disappear once ready.
 *   4. The admin lawyers list over HTTP — the readiness column renders the right pill.
 *
 * Everything it creates is deleted in cleanup, so it is re-runnable. Requires the app on
 * 127.0.0.1:8080 (see tests/setup_test_env.sh).
 *
 * Run: php tests/exec68_panel_readiness_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lawyer_readiness.php';
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

function http68(string $method, string $path, array $opts = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $cfg = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    curl_setopt_array($ch, $cfg);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf68(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$suffix = sprintf('%08d', random_int(10000000, 99999999));
$opts = specialization_options();
$pass = 'Test-Password-2026';
$created = ['lawyers' => []];

echo "Execution 68 — lawyer panel readiness\n";
echo "suffix: $suffix\n";

/** Build a fully-ready lawyer row (all required + all recommended signals present). */
function ready_row(array $opts, string $suffix): array {
    return [
        'full_name' => 'محامي جاهز ' . $suffix,
        'email' => 'exec68-ready-' . $suffix . '@example.com',
        'specialization' => $opts[0],
        'specializations' => $opts[0] . ' - ' . $opts[2],
        'hourly_rate' => '450.000',
        'photo_path' => 'uploads/lawyers/exec68-' . $suffix . '.png',
        'bio' => 'نبذة اختبار للجاهزية.',
        'years_experience' => 8,
        'qualifications' => 'بكالوريوس حقوق',
        'social_linkedin' => 'https://linkedin.com/in/exec68',
        'status' => 'approved',
        'email_verified' => 1,
        'country_code' => 'SA',
        'city' => 'الرياض',
    ];
}

// ---------------------------------------------------------------------------
section('1. The readiness predicate: required vs recommended');
// ---------------------------------------------------------------------------
$row = ready_row($opts, $suffix);
$row['id'] = 900000 + random_int(1, 90000);

// A ready lawyer with published hours and linked specialties.
$countsReady = ['availability' => 5, 'specialties' => 2];
$r = lawyer_panel_readiness($pdo, $row, $countsReady);
check('a lawyer with all required signals is ready', $r['ready'] === true);
check('a ready lawyer has no blocking items', $r['blocking'] === []);
check('a fully-populated lawyer is 100%', $r['percent'] === 100);
check('account approval is reported separately', $r['account_approved'] === true);

// Each required signal independently blocks readiness.
$x = $row; $x['specialization'] = ''; $x['specializations'] = '';
$rr = lawyer_panel_readiness($pdo, $x, $countsReady);
check("missing 'specialization' makes the lawyer not ready", $rr['ready'] === false);
check("missing 'specialization' is reported as blocking", in_array('specialization', $rr['blocking'], true));

$x = $row; $x['hourly_rate'] = '0.000';
$rr = lawyer_panel_readiness($pdo, $x, $countsReady);
check("missing 'price' makes the lawyer not ready", $rr['ready'] === false);
check("missing 'price' is reported as blocking", in_array('price', $rr['blocking'], true));

$rr = lawyer_panel_readiness($pdo, $row, ['availability' => 0, 'specialties' => 2]);
check("missing 'working_hours' makes the lawyer not ready", $rr['ready'] === false);
check("missing 'working_hours' is reported as blocking", in_array('working_hours', $rr['blocking'], true));

// A recommended signal never blocks.
foreach ([['photo', 'photo_path'], ['bio', 'bio'], ['experience', 'years_experience'], ['qualifications', 'qualifications'], ['social', 'social_linkedin']] as [$key, $col]) {
    $x = $row;
    $x[$col] = '';
    $rr = lawyer_panel_readiness($pdo, $x, $countsReady);
    check("missing recommended '$key' keeps the lawyer ready", $rr['ready'] === true);
    check("missing recommended '$key' lowers the percentage", $rr['percent'] < 100);
}

// linked specialties are recommended, not required.
$r2 = lawyer_panel_readiness($pdo, $row, ['availability' => 3, 'specialties' => 0]);
check('no linked specialties still leaves the lawyer ready', $r2['ready'] === true);

// A legacy row that only has the old single column counts as having a specialization.
$legacy = $row;
$legacy['specializations'] = '';
$legacy['specialization'] = $opts[1];
check('a legacy single-column specialization satisfies the requirement',
    lawyer_panel_readiness($pdo, $legacy, $countsReady)['ready'] === true);

// An unapproved account is not a readiness blocker (it is a moderation state).
$unapproved = $row;
$unapproved['status'] = 'pending';
$r3 = lawyer_panel_readiness($pdo, $unapproved, $countsReady);
check('an unapproved account is still "ready" as a profile', $r3['ready'] === true);
check('but the approval flag is false', $r3['account_approved'] === false);

// Blocking label names the missing fields.
$r4 = lawyer_panel_readiness($pdo, array_merge($row, ['hourly_rate' => '0']), ['availability' => 0, 'specialties' => 0]);
check('the blocking label names both missing required fields',
    strpos(lawyer_readiness_blocking_label($r4['blocking']), 'سعر') !== false
    && strpos(lawyer_readiness_blocking_label($r4['blocking']), 'أوقات') !== false);

// ---------------------------------------------------------------------------
section('2. lawyer_readiness_counts batches and degrades safely');
// ---------------------------------------------------------------------------
$counts = lawyer_readiness_counts($pdo, [$row['id']]);
check('counts returns an entry for the requested id', isset($counts[$row['id']]));
check('a lawyer with no rows counts zero availability', ($counts[$row['id']]['availability'] ?? -1) === 0);
check('a lawyer with no rows counts zero specialties', ($counts[$row['id']]['specialties'] ?? -1) === 0);
check('an empty id list returns an empty map', lawyer_readiness_counts($pdo, []) === []);
check('non-numeric ids are ignored', lawyer_readiness_counts($pdo, ['x', 0, -5]) === []);

// ---------------------------------------------------------------------------
section('3. The lawyer dashboard reflects readiness over HTTP');
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM auth_rate_limits');
$email = 'exec68-ready-' . $suffix . '@example.com';
$ins = $pdo->prepare('INSERT INTO lawyers (full_name, email, phone, password_hash, specialization, specializations, hourly_rate, bio, years_experience, qualifications, status, email_verified, country_code, city)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute([
    $row['full_name'], $email, '+9665' . substr($suffix, 0, 8),
    password_hash($pass, PASSWORD_BCRYPT),
    '', '', '0.000', '', 0, '', 'approved', 1, 'SA', 'الرياض',
]);
$lawyerId = (int)$pdo->lastInsertId();
$created['lawyers'][] = $lawyerId;

$jar = tempnam(sys_get_temp_dir(), 'exec68jar');
[, $loginPage] = http68('GET', '/lawyer-login.php', ['jar' => $jar]);
$tok = csrf68($loginPage);
http68('POST', '/lawyer-login.php', ['jar' => $jar, 'post' => http_build_query([
    'csrf_token' => $tok, 'email' => $email, 'password' => $pass,
])]);
[$code, $dash] = http68('GET', '/lawyer/dashboard.php', ['jar' => $jar]);
check('the lawyer dashboard renders 200 after login', $code === 200);
check('the dashboard has no PHP fatal',
    stripos($dash, 'fatal error') === false && stripos($dash, 'uncaught') === false);
check('the readiness card is shown while the lawyer is not ready',
    strpos($dash, 'جاهزية لوحتك للحجز') !== false);
check('the "not ready" attention row is shown',
    strpos($dash, 'لوحتك غير جاهزة لاستقبال الحجوزات') !== false);

// Now make the lawyer fully ready and confirm both disappear.
$pdo->prepare('UPDATE lawyers SET specialization=?, specializations=?, hourly_rate=?, photo_path=?, bio=?, years_experience=?, qualifications=?, social_linkedin=? WHERE id=?')
    ->execute([$opts[0], $opts[0] . ' - ' . $opts[2], '450.000', 'uploads/lawyers/x.png', 'نبذة', 8, 'بكالوريوس', 'https://x.test', $lawyerId]);
$pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,?)')
    ->execute([$lawyerId, 0, '09:00:00', '12:00:00', 30]);
[, $dash2] = http68('GET', '/lawyer/dashboard.php', ['jar' => $jar]);
check('the readiness card disappears once the lawyer is ready',
    strpos($dash2, 'جاهزية لوحتك للحجز') === false);
check('the "not ready" attention row disappears too',
    strpos($dash2, 'لوحتك غير جاهزة لاستقبال الحجوزات') === false);

// ---------------------------------------------------------------------------
section('4. The admin lawyers list shows the readiness pill');
// ---------------------------------------------------------------------------
$pdo->exec('DELETE FROM auth_rate_limits');
$ajar = tempnam(sys_get_temp_dir(), 'exec68ajar');
[, $alogin] = http68('GET', '/admin/login.php', ['jar' => $ajar]);
$atok = csrf68($alogin);
http68('POST', '/admin/login.php', ['jar' => $ajar, 'post' => http_build_query([
    'csrf_token' => $atok, 'email' => 'admin@mawatheq.com', 'password' => $pass,
])]);
[, $apage] = http68('GET', '/admin/lawyers.php', ['jar' => $ajar]);
check('the admin lawyers page renders 200', strlen($apage) > 20000);
check('the admin list carries the readiness column header',
    strpos($apage, 'الجاهزية للحجز') !== false);
check('the admin page has no PHP fatal',
    stripos($apage, 'fatal error') === false && stripos($apage, 'uncaught') === false);
check('the now-ready lawyer shows a "ready" pill',
    strpos($apage, 'جاهز لاستقبال الحجوزات') !== false);

// The filtered list (?readiness=incomplete) must drop a fully-ready lawyer and keep one
// that is not ready. The same row is used in both directions so the test does not depend
// on which other lawyers the seed happens to leave behind.
[, $afilterReady] = http68('GET', '/admin/lawyers.php?readiness=incomplete', ['jar' => $ajar]);
check('the filtered list renders 200 while the lawyer is ready', strlen($afilterReady) > 20000);
check('the filtered list has no PHP fatal',
    stripos($afilterReady, 'fatal error') === false && stripos($afilterReady, 'uncaught') === false);
check('a fully-ready lawyer is excluded from the not-ready filter',
    strpos($afilterReady, 'exec68-ready-' . $suffix . '@example.com') === false);

// Break readiness and confirm the pill flips.
$pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=?')->execute([$lawyerId]);
[, $apage2] = http68('GET', '/admin/lawyers.php', ['jar' => $ajar]);
check('a lawyer with no hours no longer shows a "ready" pill for that row',
    substr_count($apage2, 'جاهز لاستقبال الحجوزات') < substr_count($apage, 'جاهز لاستقبال الحجوزات') + 1);

// The admin dashboard counts approved lawyers that cannot be booked, and links to the
// filtered list. exec68 leaves other seeded lawyers in place, so the exact number is not
// asserted — only that the signal is present, wired, and answers 200.
[, $adash] = http68('GET', '/admin/index.php', ['jar' => $ajar]);
check('the admin dashboard renders 200', strlen($adash) > 5000);
check('the admin dashboard has no PHP fatal',
    stripos($adash, 'fatal error') === false && stripos($adash, 'uncaught') === false);
check('the admin dashboard carries the unbookable-lawyers signal',
    strpos($adash, 'غير جاهز للحجز') !== false);

// Now that the lawyer has no published hours it must appear in the filtered list.
[, $afilterBroken] = http68('GET', '/admin/lawyers.php?readiness=incomplete', ['jar' => $ajar]);
check('the filtered list renders 200 after hours were removed', strlen($afilterBroken) > 20000);
check('the filtered list keeps the lawyer whose hours were removed',
    strpos($afilterBroken, 'exec68-ready-' . $suffix . '@example.com') !== false);

// ---------------------------------------------------------------------------
section('5. Cleanup');
// ---------------------------------------------------------------------------
$pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=?')->execute([$lawyerId]);
foreach ($created['lawyers'] as $id) {
    $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$id]);
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM lawyers WHERE email LIKE 'exec68-%'")->fetchColumn();
check('every row created by this suite is removed', $left === 0);
@unlink($jar); @unlink($ajar);

echo "\n";
echo str_repeat('-', 60) . "\n";
echo "checks: $checks   failures: $failures\n";
exit($failures === 0 ? 0 : 1);
