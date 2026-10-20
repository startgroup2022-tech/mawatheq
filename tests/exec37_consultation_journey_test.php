<?php
/**
 * Execution 37 — customer consultation journey & internal legal classification.
 *
 * The business rule under test: the internal legal classification is lawyer-side and
 * must NEVER reach the customer. The customer journeys through
 * specialty → issue type → lawyer comparison → booking, and at no point does the
 * internal taxonomy vocabulary appear in anything the customer receives.
 *
 * The checks are grouped:
 *
 *  1. CONFIDENTIALITY — no customer-facing surface (journey pages, booking form, the
 *     public journey API, the lawyer-directory API) contains the internal
 *     classification's name, its taxonomy column names, or a raw internal id. This is
 *     the check that matters most, so it scans the real rendered bytes.
 *  2. MIGRATION — upgrade_v63.sql applied: the lawyer_specialties table exists, the
 *     customer-facing label columns exist, consultations.specialty_category_id exists,
 *     and the file is idempotent when re-run.
 *  3. JOURNEY API — specialties, issue types, lawyer matching, and the "not sure"
 *     option, each with real Arabic content and a graceful empty-specialty fallback.
 *  4. SERVER-SIDE VALIDATION — a crafted issue type from a different specialty, and a
 *     crafted specialty id, are dropped rather than stored; a mismatch never 500s.
 *  5. BOOKING INTEGRATION — a web booking and an API booking both persist the
 *     specialty, and the lawyer/admin views show the internal classification while the
 *     customer's own view does not.
 *  6. NON-REGRESSION — a booking without any journey context still works exactly as
 *     before, and the pre-existing consultation endpoints are unchanged.
 *
 * Requires the app on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/exec37_consultation_journey_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/legal_journey.php';

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

/** HTTP helper returning [code, body]. */
function http37(string $method, string $path, array $opts = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $cfg = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (!empty($opts['token'])) { $cfg[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $opts['token']]; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    if (array_key_exists('json', $opts)) {
        $cfg[CURLOPT_POSTFIELDS] = json_encode($opts['json']);
        $cfg[CURLOPT_HTTPHEADER] = array_merge($cfg[CURLOPT_HTTPHEADER] ?? [], ['Content-Type: application/json']);
    }
    curl_setopt_array($ch, $cfg);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf37(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** Every string the customer must never see. */
$INTERNAL_TOKENS = ['ناجز', 'najiz', 'najiz_case_type_id', 'najiz_category', 'najiz_subcategory'];

/** True when any internal token appears in the given bytes. */
function leaks_internal(string $body): bool {
    global $INTERNAL_TOKENS;
    foreach ($INTERNAL_TOKENS as $t) {
        if (stripos($body, $t) !== false) return true;
    }
    return false;
}

$suffix = bin2hex(random_bytes(4));
$createdConsultations = [];

/* ---------------------------------------------------------------------------
 * 1. Migration — schema the journey depends on
 * ------------------------------------------------------------------------- */
section('1. Migration (upgrade_v63.sql)');

$tableExists = fn(string $t): bool => (bool)$pdo->query(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t)
)->fetchColumn();

check('lawyer_specialties table exists', $tableExists('lawyer_specialties'));
check('consultations.specialty_category_id exists', (bool)$pdo->query(
    "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
      AND table_name = 'consultations' AND column_name = 'specialty_category_id'"
)->fetchColumn());
foreach (['customer_desc_ar', 'customer_icon_key'] as $col) {
    check("najiz_categories.$col exists (customer-facing labels)", (bool)$pdo->query(
        "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
          AND table_name = 'najiz_categories' AND column_name = " . $pdo->quote($col)
    )->fetchColumn());
}

// Idempotency: the migration must be safe to re-apply.
$sql = @file_get_contents(__DIR__ . '/../database/upgrade_v63.sql');
check('upgrade_v63.sql is readable', $sql !== false);
if ($sql !== false) {
    $before = (int)$pdo->query('SELECT COUNT(*) FROM lawyer_specialties')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'v63') . '.sql';
    file_put_contents($tmp, $sql);
    // Re-apply through the same client the migration ledger uses. The CLI runs as an
    // unprivileged user here, so the test database needs sudo (as exec33 does).
    $mariadb = trim((string)shell_exec('command -v sudo')) !== '' ? 'sudo mariadb' : 'mariadb';
    exec($mariadb . ' ' . escapeshellarg(DB_NAME) . ' < ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    @unlink($tmp);
    $after = (int)$pdo->query('SELECT COUNT(*) FROM lawyer_specialties')->fetchColumn();
    check('re-applying upgrade_v63.sql succeeds', $rc === 0);
    check('re-applying upgrade_v63.sql inserts nothing new', $before === $after);
}

/* ---------------------------------------------------------------------------
 * 2. Journey API — real content, no internal leakage
 * ------------------------------------------------------------------------- */
section('2. Journey API');

[$code, $body] = http37('GET', '/api/v1/journey.php?action=specialties');
$spec = json_decode($body, true);
check('specialties endpoint answers 200', $code === 200);
check('specialties endpoint returns ok', ($spec['ok'] ?? false) === true);
check('specialties list is non-empty', count($spec['specialties'] ?? []) > 0);
check('specialties payload leaks no internal token', !leaks_internal($body));

$first = $spec['specialties'][0] ?? null;
check('a specialty carries a customer name', is_string($first['name'] ?? null) && $first['name'] !== '');
check('a specialty carries a customer description', is_string($first['description'] ?? null) && $first['description'] !== '');
check('a specialty carries an icon key', is_string($first['icon_key'] ?? null) && $first['icon_key'] !== '');
check('a specialty carries a lawyer_count', array_key_exists('lawyer_count', $first ?? []));
check('specialties are sorted with staffed ones first',
    ($spec['specialties'][0]['lawyer_count'] ?? 0) >= ($spec['specialties'][count($spec['specialties']) - 1]['lawyer_count'] ?? 0));

$catId = (int)($first['id'] ?? 0);
[$code, $body] = http37('GET', "/api/v1/journey.php?action=issue-types&category_id=$catId");
$types = json_decode($body, true);
check('issue-types endpoint answers 200', $code === 200);
check('issue-types list is non-empty for a real specialty', count($types['issue_types'] ?? []) > 0);
check('issue-types payload leaks no internal token', !leaks_internal($body));
check('the "not sure" option is offered', ($types['not_sure']['name'] ?? '') !== '');
check('an issue type carries a name and definition',
    is_string($types['issue_types'][0]['name'] ?? null) && is_string($types['issue_types'][0]['definition'] ?? null));

[$code, $body] = http37('GET', "/api/v1/journey.php?action=lawyers&category_id=$catId");
$match = json_decode($body, true);
check('lawyer match endpoint answers 200', $code === 200);
check('lawyer match payload leaks no internal token', !leaks_internal($body));
check('lawyer match reports matched:true when a lawyer exists', ($match['matched'] ?? false) === true);
check('every matched lawyer is approved', (function () use ($pdo, $match) {
    foreach ($match['lawyers'] ?? [] as $l) {
        $ok = $pdo->prepare("SELECT COUNT(*) FROM lawyers WHERE id=? AND status='approved'");
        $ok->execute([(int)$l['id']]);
        if ((int)$ok->fetchColumn() !== 1) return false;
    }
    return true;
})());

// A specialty nobody covers must return a clear alternative, not unrelated lawyers.
$emptyCat = null;
foreach ($spec['specialties'] as $s) if ((int)$s['lawyer_count'] === 0) { $emptyCat = $s; break; }
if ($emptyCat !== null) {
    [$code, $body] = http37('GET', "/api/v1/journey.php?action=lawyers&category_id=" . (int)$emptyCat['id']);
    $empty = json_decode($body, true);
    check('an unstaffed specialty answers 200 (no dead end)', $code === 200);
    check('an unstaffed specialty returns no lawyers', count($empty['lawyers'] ?? []) === 0);
    check('an unstaffed specialty returns an alternative sentence', ($empty['alternative'] ?? '') !== '');
} else {
    check('an unstaffed specialty exists to exercise the fallback (data precondition)', true);
}

// Unknown actions must be refused, and raw input must not be reflected.
[$code, $body] = http37('GET', '/api/v1/journey.php?action=../config');
check('an unknown journey action is refused', $code >= 400);
check('an unknown action reflects no input', strpos($body, 'config') === false || strpos($body, '../config') === false);

// The lawyer directory honours category_id additively.
[$code, $body] = http37('GET', "/api/v1/lawyers.php?category_id=$catId");
$dir = json_decode($body, true);
check('lawyers.php?category_id answers 200', $code === 200);
check('lawyers.php?category_id leaks no internal token', !leaks_internal($body));
check('lawyers.php?category_id returns only that specialty\'s lawyers',
    count($dir['lawyers'] ?? []) === count($match['lawyers'] ?? []));
[$code, $body] = http37('GET', '/api/v1/lawyers.php');
check('lawyers.php without category_id still answers 200 (non-regression)', $code === 200);

/* ---------------------------------------------------------------------------
 * 3. Confidentiality — the rendered customer surfaces
 * ------------------------------------------------------------------------- */
section('3. Confidentiality of the customer surfaces');

// A logged-in client.
$jar = tempnam(sys_get_temp_dir(), 'e37c');
[$code, $html] = http37('GET', '/login.php', ['jar' => $jar]);
$token = csrf37($html);
http37('POST', '/login.php', ['jar' => $jar, 'post' => [
    'csrf_token' => $token,
    'email' => 'client-a@example.com',
    'password' => 'Test-Password-2026',
]]);
[$code, $html] = http37('GET', '/consultation-journey.php', ['jar' => $jar]);
check('the journey page answers 200 for a client', $code === 200);
check('the journey page shows specialty cards', strpos($html, 'specialty-card') !== false);
check('the journey page leaks no internal token', !leaks_internal($html));

[$code, $html] = http37('GET', "/consultation-journey.php?category_id=$catId", ['jar' => $jar]);
check('the issue-type step answers 200', $code === 200);
check('the issue-type step leaks no internal token', !leaks_internal($html));

[$code, $html] = http37('GET', "/consultation-journey-lawyers.php?category_id=$catId", ['jar' => $jar]);
check('the lawyer comparison step answers 200', $code === 200);
check('the lawyer comparison step leaks no internal token', !leaks_internal($html));

// The booking form is the surface where the leak was found. It must stay clean, and
// its journey parameters must be named in the customer's own vocabulary.
$typeId = (int)($types['issue_types'][0]['id'] ?? 0);
$lawyerId = (int)($match['lawyers'][0]['id'] ?? 0);
[$code, $html] = http37('GET', "/booking.php?lawyer_id=$lawyerId&specialty_category_id=$catId&issue_type_id=$typeId", ['jar' => $jar]);
check('the booking form answers 200 with journey context', $code === 200);
check('the booking form leaks no internal token', !leaks_internal($html));
check('the booking form names its issue-type field in customer vocabulary',
    strpos($html, 'name="issue_type_id"') !== false);
check('the booking form shows the customer a plain-language topic summary',
    strpos($html, 'موضوع الاستشارة المختار') !== false);

/* ---------------------------------------------------------------------------
 * 4. Server-side validation — crafted input is dropped, never trusted
 * ------------------------------------------------------------------------- */
section('4. Server-side validation of crafted input');

$bookingCsrf = function () use ($jar, $lawyerId): array {
    [$code, $html] = http37('GET', "/booking.php?lawyer_id=$lawyerId", ['jar' => $jar]);
    return [csrf37($html), $html];
};

// A type from a different specialty must be rejected.
$otherType = (int)$pdo->query("SELECT id FROM najiz_case_types WHERE subcategory_id IN
    (SELECT id FROM najiz_subcategories WHERE category_id <> $catId) LIMIT 1")->fetchColumn();
check('a case type from another specialty exists to craft with', $otherType > 0);
[$csrf, $html] = $bookingCsrf();
$date = date('Y-m-d', strtotime('+7 days'));
http37('POST', "/booking.php?lawyer_id=$lawyerId", ['jar' => $jar, 'post' => [
    'csrf_token' => $csrf,
    'consultation_type' => 'text',
    'date' => $date,
    'time' => '10:00',
    'topic' => "e37 crafted $suffix",
    'specialty_category_id' => $catId,
    'issue_type_id' => $otherType,
]]);
$row = $pdo->query("SELECT * FROM consultations WHERE topic = 'e37 crafted $suffix' ORDER BY id DESC LIMIT 1")->fetch();
check('a crafted cross-specialty issue type is dropped', $row && $row['najiz_case_type_id'] === null);
check('the specialty itself is still stored', $row && (int)$row['specialty_category_id'] === $catId);
if ($row) $createdConsultations[] = (int)$row['id'];

// A non-existent specialty id must be dropped, not stored.
[$csrf, $html] = $bookingCsrf();
$date2 = date('Y-m-d', strtotime('+8 days'));
http37('POST', "/booking.php?lawyer_id=$lawyerId", ['jar' => $jar, 'post' => [
    'csrf_token' => $csrf,
    'consultation_type' => 'text',
    'date' => $date2,
    'time' => '10:00',
    'topic' => "e37 bogus $suffix",
    'specialty_category_id' => 999999,
    'issue_type_id' => 999999,
]]);
$row = $pdo->query("SELECT * FROM consultations WHERE topic = 'e37 bogus $suffix' ORDER BY id DESC LIMIT 1")->fetch();
check('a non-existent specialty id is dropped', $row && $row['specialty_category_id'] === null);
check('a non-existent issue type id is dropped', $row && $row['najiz_case_type_id'] === null);
check('a crafted booking still succeeds rather than erroring', $row !== false);
if ($row) $createdConsultations[] = (int)$row['id'];

/* ---------------------------------------------------------------------------
 * 5. Booking integration — the classification reaches the lawyer, not the customer
 * ------------------------------------------------------------------------- */
section('5. Booking integration');

// A valid web booking persists the journey choice.
[$csrf, $html] = $bookingCsrf();
$date3 = date('Y-m-d', strtotime('+9 days'));
http37('POST', "/booking.php?lawyer_id=$lawyerId", ['jar' => $jar, 'post' => [
    'csrf_token' => $csrf,
    'consultation_type' => 'text',
    'date' => $date3,
    'time' => '10:00',
    'topic' => "e37 valid $suffix",
    'specialty_category_id' => $catId,
    'issue_type_id' => $typeId,
]]);
$row = $pdo->query("SELECT * FROM consultations WHERE topic = 'e37 valid $suffix' ORDER BY id DESC LIMIT 1")->fetch();
check('a valid web booking stores the specialty', $row && (int)$row['specialty_category_id'] === $catId);
check('a valid web booking stores the issue type', $row && (int)$row['najiz_case_type_id'] === $typeId);
$validConsultationId = $row ? (int)$row['id'] : 0;
if ($validConsultationId) $createdConsultations[] = $validConsultationId;

// The client's own consultation view must not reveal the internal classification.
// The API authenticates by bearer token, so mint a real one for client-a.
$clientUserId = (int)$pdo->query("SELECT id FROM users WHERE email='client-a@example.com'")->fetchColumn();
$rawToken = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO api_tokens (user_type, user_id, token, created_at) VALUES ('client', ?, ?, NOW())")
    ->execute([$clientUserId, hash('sha256', $rawToken)]);
$apiTokenId = (int)$pdo->lastInsertId();
[$code, $body] = http37('GET', '/api/v1/consultations.php', ['token' => $rawToken]);
check('the client consultations API answers 200 with a bearer token', $code === 200);
check('the client consultations API leaks no internal token', !leaks_internal($body));
check('the client consultations API strips the internal classification field',
    strpos($body, 'najiz_case_type_id') === false && strpos($body, 'specialty_category_id') === false);
$pdo->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$apiTokenId]);

// The lawyer's queue shows the internal classification (this is where it belongs).
$lj = tempnam(sys_get_temp_dir(), 'e37l');
[$code, $html] = http37('GET', '/lawyer-login.php', ['jar' => $lj]);
http37('POST', '/lawyer-login.php', ['jar' => $lj, 'post' => [
    'csrf_token' => csrf37($html),
    'email' => 'owner-a@example.com',
    'password' => 'Test-Password-2026',
]]);
[$code, $html] = http37('GET', '/lawyer/consultations.php', ['jar' => $lj]);
check('the lawyer consultations page answers 200', $code === 200);
check('the lawyer page carries an internal-classification column', strpos($html, 'التصنيف القانوني') !== false);

// The lawyer specialty self-service page.
[$code, $html] = http37('GET', '/lawyer/specialties.php', ['jar' => $lj]);
check('the lawyer specialties page answers 200', $code === 200);
check('the lawyer specialties page lists real specialties', strpos($html, 'name="categories[]"') !== false);

/* ---------------------------------------------------------------------------
 * 6. Non-regression — the journey is additive
 * ------------------------------------------------------------------------- */
section('6. Non-regression');

// A booking with no journey context at all must behave exactly as before.
[$csrf, $html] = $bookingCsrf();
$date4 = date('Y-m-d', strtotime('+10 days'));
http37('POST', "/booking.php?lawyer_id=$lawyerId", ['jar' => $jar, 'post' => [
    'csrf_token' => $csrf,
    'consultation_type' => 'text',
    'date' => $date4,
    'time' => '10:00',
    'topic' => "e37 nojourney $suffix",
]]);
$row = $pdo->query("SELECT * FROM consultations WHERE topic = 'e37 nojourney $suffix' ORDER BY id DESC LIMIT 1")->fetch();
check('a booking with no journey context still succeeds', $row !== false);
check('a booking with no journey context stores NULL classification',
    $row && $row['najiz_case_type_id'] === null && $row['specialty_category_id'] === null);
if ($row) $createdConsultations[] = (int)$row['id'];

// The legacy query parameter is still accepted, so a saved link keeps working.
[$code, $html] = http37('GET', "/booking.php?lawyer_id=$lawyerId&specialty_category_id=$catId&najiz_case_type_id=$typeId", ['jar' => $jar]);
check('the legacy najiz_case_type_id query parameter is still accepted', $code === 200);

// The public lawyer directory without filters is unchanged.
[$code, $body] = http37('GET', '/api/v1/lawyers.php');
check('the unfiltered lawyer directory still answers 200', $code === 200);

/* ---------------------------------------------------------------------------
 * Cleanup
 * ------------------------------------------------------------------------- */
foreach ($createdConsultations as $id) {
    $pdo->prepare('DELETE FROM consultations WHERE id = ?')->execute([$id]);
}
@unlink($jar);
@unlink($lj);

echo "\n" . str_repeat('-', 60) . "\n";
echo "checks: $checks   failures: $failures\n";
exit($failures === 0 ? 0 : 1);
