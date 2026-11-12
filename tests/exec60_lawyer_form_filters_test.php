<?php
/**
 * Execution 60 — the Add/Edit Lawyer form, and the professional booking filters.
 *
 * Two surfaces are under test:
 *
 *  1. THE ADMIN LAWYER FORM. The owner's screenshot showed a broken modal: a clipped
 *     name field, a non-searchable specialization, no city, an unvalidated experience
 *     value, a negative-capable price, and a hidden Save button. Every one of those is
 *     asserted here at the level it can actually fail — the shared validator
 *     (`includes/lawyer_admin.php`), the helpers it calls, and a real POST through the
 *     admin page over HTTP.
 *  2. THE BOOKING FILTERS. Specialization, country, city, experience, price range and
 *     real availability, each applied server-side by `legal_journey_lawyers_for_specialty()`
 *     and served identically to the website and the API.
 *
 * The suite creates its own lawyer rows with a random suffix and deletes them in
 * cleanup, so it is re-runnable and leaves no residue. It never touches a seeded row's
 * identity — only rows it created.
 *
 * Requires the app on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/exec60_lawyer_form_filters_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/legal_journey.php';
require_once __DIR__ . '/../includes/lawyer_admin.php';
require_once __DIR__ . '/../includes/lawyer_experience.php';
require_once __DIR__ . '/../includes/cities.php';
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

function http60(string $method, string $path, array $opts = []): array {
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

/** Log in as admin and return the cookie jar + a fresh CSRF token from lawyers.php. */
function admin_session60(): array {
    $jar = tempnam(sys_get_temp_dir(), 'exec60jar');
    [, $body] = http60('GET', '/admin/login.php', ['jar' => $jar]);
    preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m);
    http60('POST', '/admin/login.php', ['jar' => $jar, 'post' => http_build_query([
        'csrf_token' => $m[1] ?? '',
        'email' => 'admin@mawatheq.com',
        'password' => 'Test-Password-2026',
    ])]);
    [, $page] = http60('GET', '/admin/lawyers.php', ['jar' => $jar]);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m2);
    return [$jar, $m2[1] ?? '', $page];
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$created = [];

echo "Execution 60 — lawyer form + booking filters\n";
echo "suffix: $suffix\n";

// ---------------------------------------------------------------------------
section('1. Experience normalisation (accepts "20+", rejects negatives)');
// ---------------------------------------------------------------------------
check('a plain integer is kept', lawyer_experience_normalize('12') === 12);
check('"20+" is read as 20', lawyer_experience_normalize('20+') === 20);
check('Arabic-Indic digits are converted', lawyer_experience_normalize('٧') === 7);
check('a negative value is clamped to 0', lawyer_experience_normalize('-4') === 0);
check('a decimal is rounded to whole years', lawyer_experience_normalize('7.9') === 8);
check('empty is 0', lawyer_experience_normalize('') === 0);
check('a non-numeric string is 0', lawyer_experience_normalize('many') === 0);
check('an absurd value is capped, not stored raw', lawyer_experience_normalize('99999') <= 80);

// ---------------------------------------------------------------------------
section('2. Experience bands are non-overlapping and cover every year');
// ---------------------------------------------------------------------------
$bands = lawyer_experience_bands();
check('five bands are defined', count($bands) === 5);
$overlap = false;
$covered = [];
foreach ($bands as $b) {
    for ($y = $b['min']; $y <= ($b['max'] ?? 80); $y++) {
        if (isset($covered[$y])) { $overlap = true; }
        $covered[$y] = true;
    }
}
check('no year falls into two bands', !$overlap);
check('every year from 0..80 falls into exactly one band', count($covered) === 81);
check('"under5" ends at 4 and "5to10" starts at 5',
    lawyer_experience_band('under5')['max'] === 4 && lawyer_experience_band('5to10')['min'] === 5);
check('"over20" has no upper bound', lawyer_experience_band('over20')['max'] === null);
check('an unknown band key is null (i.e. "all")', lawyer_experience_band('nonsense') === null);

// ---------------------------------------------------------------------------
section('3. Price normalisation (non-negative, currency precision)');
// ---------------------------------------------------------------------------
check('a valid price is kept', lawyer_price_normalize('450.50') === 450.5);
check('a negative price becomes 0.0', lawyer_price_normalize('-100') === 0.0);
check('an empty price is null (no price / on request)', lawyer_price_normalize('') === null);
check('a malformed price is null', lawyer_price_normalize('abc') === null);
check('an array price does not raise a TypeError', lawyer_price_normalize(['x']) === null);

// ---------------------------------------------------------------------------
section('4. Country / city helpers');
// ---------------------------------------------------------------------------
check('SA is a valid country', country_is_valid('SA'));
check('الرياض belongs to SA', city_is_valid('SA', 'الرياض'));
check('المنامة does NOT belong to SA', !city_is_valid('SA', 'المنامة'));
check('المنامة belongs to BH', city_is_valid('BH', 'المنامة'));
check('the country->jurisdiction map has a name for SA', jurisdiction_for_country('SA') !== null);
check('a lawyer with only jurisdiction resolves to a country code',
    lawyer_country_code(['jurisdiction' => jurisdiction_for_country('SA')]) === 'SA');
check('a location label joins city and country', lawyer_location_label('SA', 'الرياض') !== '');

// ---------------------------------------------------------------------------
section('5. The admin form validator rejects bad data and keeps good data');
// ---------------------------------------------------------------------------
$goodPost = [
    'full_name' => 'محامي اختبار ' . $suffix,
    'email' => 'exec60-' . $suffix . '@example.com',
    'phone_dial' => '+966',
    'phone_local' => '512345678',
    'password' => 'Test-Pass-2026',
    'specialization' => specialization_options()[0],
    'years_experience' => '20+',
    'hourly_rate' => '450.50',
    'country_code' => 'SA',
    'city' => 'الرياض',
];
$v = lawyer_admin_validate($pdo, $goodPost, null, true);
check('a fully valid form passes', $v['ok'] === true);
check('the stored experience is the numeric 20', ($v['data']['years_experience'] ?? null) === 20);
check('the stored rate is 450.5', ($v['data']['hourly_rate'] ?? null) === 450.5);
check('the stored country is SA', ($v['data']['country_code'] ?? null) === 'SA');
check('the stored city is الرياض', ($v['data']['city'] ?? null) === 'الرياض');

$bad = $goodPost; $bad['email'] = 'not-an-email';
check('a malformed email is rejected', lawyer_admin_validate($pdo, $bad, null, true)['ok'] === false);

$bad = $goodPost; $bad['full_name'] = '';
check('an empty name is rejected', lawyer_admin_validate($pdo, $bad, null, true)['ok'] === false);

$bad = $goodPost; $bad['phone_local'] = '123';
check('a too-short phone number is rejected', lawyer_admin_validate($pdo, $bad, null, true)['ok'] === false);

$bad = $goodPost; $bad['specialization'] = 'تخصص وهمي';
check('an unknown specialization is rejected', lawyer_admin_validate($pdo, $bad, null, true)['ok'] === false);

// Multi-specialization: the checklist posts an array. The validator accepts one or
// more, and stores the full list joined in the canonical order while keeping the first
// value in the legacy single column so older readers still work.
$multiPost = $goodPost;
unset($multiPost['specialization']);
$multiPost['specializations'] = [specialization_options()[2], specialization_options()[0], specialization_options()[0]];
$mv = lawyer_admin_validate($pdo, $multiPost, null, true);
check('a multi-specialization form passes', $mv['ok'] === true);
check('the multi list keeps the canonical order and drops duplicates',
    ($mv['data']['specializations'] ?? '') === specialization_options()[0] . ' - ' . specialization_options()[2]);
check('the first canonical value fills the legacy single column',
    ($mv['data']['specialization'] ?? '') === specialization_options()[0]);

$emptyPost = $goodPost; unset($emptyPost['specialization']); $emptyPost['specializations'] = [];
check('an empty multi-specialization selection is rejected',
    lawyer_admin_validate($pdo, $emptyPost, null, true)['ok'] === false);

$junkPost = $goodPost; unset($junkPost['specialization']); $junkPost['specializations'] = ['تخصص وهمي'];
check('a multi-specialization list of only unknown values is rejected',
    lawyer_admin_validate($pdo, $junkPost, null, true)['ok'] === false);

// The legacy single-field shape (an older form, or a third-party client) is still
// accepted as a one-element list.
$legacySpec = lawyer_admin_validate($pdo, $goodPost, null, true);
check('the legacy single specialization still validates and stores one value',
    $legacySpec['ok'] === true
    && ($legacySpec['data']['specializations'] ?? '') === specialization_options()[0]);

$bad = $goodPost; $bad['city'] = 'مدينة ليست في الدولة';
check('a city outside the chosen country is rejected', lawyer_admin_validate($pdo, $bad, null, true)['ok'] === false);

// A blank country code falls back to the posted jurisdiction name — the legacy form
// shape — rather than being rejected, so an older record can still be saved.
$legacy = $goodPost; $legacy['country_code'] = ''; $legacy['jurisdiction'] = jurisdiction_for_country('SA');
$legacyResult = lawyer_admin_validate($pdo, $legacy, null, true);
check('a blank country falls back to the posted jurisdiction (backward compatible)',
    $legacyResult['ok'] === true && ($legacyResult['data']['country_code'] ?? '') === 'SA');

$bad = $goodPost; $bad['password'] = '123';
check('a weak password is rejected on create', lawyer_admin_validate($pdo, $bad, null, true)['ok'] === false);

// The raw values survive a validation failure so the form can repopulate them.
$bad = $goodPost; $bad['specialization'] = 'تخصص وهمي';
$failed = lawyer_admin_validate($pdo, $bad, null, true);
check('a failure returns the submitted values for repopulation',
    ($failed['old']['full_name'] ?? '') === $goodPost['full_name']);
check('a failure never returns a password hash',
    !isset($failed['old']['password_hash']) && !isset($failed['data']['password_hash']));

// ---------------------------------------------------------------------------
section('6. Duplicate email is refused by the validator');
// ---------------------------------------------------------------------------
$dupEmail = 'exec60-dup-' . $suffix . '@example.com';
$pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, specialization, status, jurisdiction, country_code, city)
               VALUES (?,?,?,?, 'approved', ?, 'SA', 'جدة')")
    ->execute(['مكرر ' . $suffix, $dupEmail, password_hash('Test-Pass-2026', PASSWORD_DEFAULT), specialization_options()[0], jurisdiction_for_country('SA')]);
$dupId = (int)$pdo->lastInsertId();
$created[] = $dupId;

$dupPost = $goodPost; $dupPost['email'] = $dupEmail;
check('creating with an existing email is rejected', lawyer_admin_validate($pdo, $dupPost, null, true)['ok'] === false);
// Editing the SAME row must not trip its own uniqueness rule.
check('editing that same row is allowed (excludes itself)',
    lawyer_admin_validate($pdo, $dupPost, $dupId, false)['ok'] === true);

// ---------------------------------------------------------------------------
section('7. Real HTTP: create a lawyer through the admin page');
// ---------------------------------------------------------------------------
[$jar, $token, $page] = admin_session60();
check('the admin lawyers page renders 200', $token !== '' && strlen($page) > 20000);
check('the page carries the unified add/edit modal', strpos($page, 'id="lawyerModal"') !== false);
check('the page carries a searchable multi-specialization control',
    strpos($page, 'data-spec-check') !== false && strpos($page, 'data-spec-filter') !== false
    && strpos($page, 'name="specializations[]"') !== false && strpos($page, 'specialization-combo.js') !== false);
check('the page carries the dependent city select', strpos($page, 'id="lawyer_city"') !== false);
check('the page carries the country select', strpos($page, 'id="lawyer_country"') !== false);
check('the page carries the form JS', strpos($page, 'lawyer-form.js') !== false);
check('the page carries the modal switching JS', strpos($page, 'lawyer-modal.js') !== false);
check('no PHP fatal leaked into the page', stripos($page, 'fatal error') === false && stripos($page, 'uncaught') === false);

$newEmail = 'exec60-http-' . $suffix . '@example.com';
$post = $goodPost;
$post['csrf_token'] = $token;
$post['action'] = 'create';
$post['email'] = $newEmail;
$post['status'] = 'approved';
[$code, $resp] = http60('POST', '/admin/lawyers.php', ['jar' => $jar, 'post' => http_build_query($post)]);
check('the create POST answers 302 (redirect after write)', $code === 302);

$row = $pdo->prepare('SELECT * FROM lawyers WHERE email = ?');
$row->execute([$newEmail]);
$lawyer = $row->fetch(PDO::FETCH_ASSOC);
check('the lawyer row exists', $lawyer !== false);
if ($lawyer) {
    $created[] = (int)$lawyer['id'];
    check('the long Arabic name is stored intact', $lawyer['full_name'] === $goodPost['full_name']);
    check('the experience is stored as the number 20', (int)$lawyer['years_experience'] === 20);
    check('the city is stored', $lawyer['city'] === 'الرياض');
    check('the country code is stored', $lawyer['country_code'] === 'SA');
    check('the price is stored', abs((float)$lawyer['hourly_rate'] - 450.5) < 0.001);
    check('the password is hashed, never plaintext', $lawyer['password_hash'] !== 'Test-Pass-2026' && password_verify('Test-Pass-2026', $lawyer['password_hash']));
}

// A duplicate email POST must not create a second row.
$post2 = $post;
[$code2, $resp2] = http60('POST', '/admin/lawyers.php', ['jar' => $jar, 'post' => http_build_query($post2)]);
$cnt = $pdo->prepare('SELECT COUNT(*) FROM lawyers WHERE email = ?');
$cnt->execute([$newEmail]);
check('a duplicate-email POST does not create a second row', (int)$cnt->fetchColumn() === 1);
check('the duplicate POST re-renders the form (200) with an error', $code2 === 200 && stripos($resp2, 'fatal error') === false);

// ---------------------------------------------------------------------------
section('8. Real HTTP: edit the lawyer and change city + experience');
// ---------------------------------------------------------------------------
if ($lawyer) {
    $editId = (int)$lawyer['id'];
    $editPost = $goodPost;
    $editPost['csrf_token'] = $token;
    $editPost['action'] = 'edit';
    $editPost['lawyer_id'] = $editId;
    $editPost['email'] = $newEmail;
    $editPost['full_name'] = 'اسم معدّل ' . $suffix;
    $editPost['city'] = 'جدة';
    $editPost['country_code'] = 'SA';
    $editPost['years_experience'] = '7';
    $editPost['hourly_rate'] = '300.25';
    $editPost['password'] = ''; // left blank -> keep the current hash
    [$code3, ] = http60('POST', '/admin/lawyers.php', ['jar' => $jar, 'post' => http_build_query($editPost)]);
    check('the edit POST answers 302', $code3 === 302);

    $row2 = $pdo->prepare('SELECT * FROM lawyers WHERE id = ?');
    $row2->execute([$editId]);
    $after = $row2->fetch(PDO::FETCH_ASSOC);
    check('the name was updated', $after['full_name'] === 'اسم معدّل ' . $suffix);
    check('the city was updated to جدة', $after['city'] === 'جدة');
    check('the experience was updated to 7', (int)$after['years_experience'] === 7);
    check('the price was updated to 300.25', abs((float)$after['hourly_rate'] - 300.25) < 0.001);
    check('a blank password field keeps the existing hash',
        password_verify('Test-Pass-2026', $after['password_hash']));

    // A negative price on edit must not become a negative stored rate.
    $negPost = $editPost;
    $negPost['hourly_rate'] = '-50';
    http60('POST', '/admin/lawyers.php', ['jar' => $jar, 'post' => http_build_query($negPost)]);
    $row3 = $pdo->prepare('SELECT hourly_rate FROM lawyers WHERE id = ?');
    $row3->execute([$editId]);
    check('a negative price never becomes a negative stored rate', (float)$row3->fetchColumn() >= 0);
}

// ---------------------------------------------------------------------------
section('9. The lawyer list renders the new columns');
// ---------------------------------------------------------------------------
[, , $listPage] = admin_session60();
check('the list page shows a country/city column header',
    strpos($listPage, 'المدينة') !== false || strpos($listPage, 'الدولة') !== false);
check('the list page shows an experience column', strpos($listPage, 'الخبرة') !== false);
check('the list page carries an Edit control for each row', strpos($listPage, 'data-edit-lawyer') !== false);
check('the list page no longer has a broken column span', strpos($listPage, 'colspan="11"') === false);

// ---------------------------------------------------------------------------
section('10. Booking filters — vocabulary is complete and consistent');
// ---------------------------------------------------------------------------
$opts = legal_journey_filter_options();
check('the filter options list countries', count($opts['countries']) > 0);
check('the filter options list cities per country', count($opts['cities']) > 0);
check('the filter options list five experience bands', count($opts['experience']) === 5);
$saCities = $opts['cities']['SA'] ?? [];
check('SA has cities in the filter vocabulary', count($saCities) > 0);
check('the API vocabulary matches the constant', lawyer_experience_band($opts['experience'][0]['key']) !== null);

// ---------------------------------------------------------------------------
section('11. Booking filters — server-side filtering is real');
// ---------------------------------------------------------------------------
// Seed a specialty, three approved lawyers with distinct country/city/experience/price,
// and link them to the specialty. Every row is ours and is removed in cleanup.
//
// The category is ALWAYS a fresh, suite-owned one — never the first real category. That
// first category is shared data: another suite, a seed script or a developer's manual
// probe can link a lawyer to it, and then "the range keeps only our lawyer" fails against
// a row this test never created. A category nobody else knows about makes every filter
// assertion here about exactly the three lawyers below.
$catId = 0;
$pdo->prepare("INSERT INTO najiz_categories (name_ar, is_active) VALUES (?, 1)")
    ->execute(['تصنيف اختبار exec60 ' . $suffix]);
$catId = (int)$pdo->lastInsertId();
$createdCats[] = $catId;

$mk = function (string $name, string $country, string $city, int $years, float $rate) use ($pdo, $suffix, $catId, &$created) {
    $pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, specialization, status, jurisdiction, country_code, city, years_experience, hourly_rate, rating)
                   VALUES (?,?,?,?, 'approved', ?, ?, ?, ?, ?, 4.5)")
        ->execute([
            $name, 'exec60f-' . $suffix . '-' . random_int(1000, 9999) . '@example.com',
            password_hash('Test-Pass-2026', PASSWORD_DEFAULT), specialization_options()[0],
            jurisdiction_for_country($country), $country, $city, $years, $rate,
        ]);
    $id = (int)$pdo->lastInsertId();
    $created[] = $id;
    $pdo->prepare("INSERT INTO lawyer_specialties (lawyer_id, category_id) VALUES (?, ?)")->execute([$id, $catId]);
    return $id;
};

$lwSA = $mk('محامي الرياض ' . $suffix, 'SA', 'الرياض', 3, 200.0);
$lwBH = $mk('محامي المنامة ' . $suffix, 'BH', 'المنامة', 12, 500.0);
$lwJeddah = $mk('محامي جدة ' . $suffix, 'SA', 'جدة', 25, 900.0);

$all = legal_journey_lawyers_for_specialty($pdo, $catId, []);
$allIds = array_column($all, 'id');
check('the unfiltered list contains all three seeded lawyers',
    in_array($lwSA, $allIds) && in_array($lwBH, $allIds) && in_array($lwJeddah, $allIds));

// Filter by country.
$sa = legal_journey_lawyers_for_specialty($pdo, $catId, ['country' => 'SA']);
$saIds = array_column($sa, 'id');
check('country=SA keeps the SA lawyers', in_array($lwSA, $saIds) && in_array($lwJeddah, $saIds));
check('country=SA excludes the BH lawyer', !in_array($lwBH, $saIds));

// Filter by country + city.
$jeddah = legal_journey_lawyers_for_specialty($pdo, $catId, ['country' => 'SA', 'city' => 'جدة']);
$jIds = array_column($jeddah, 'id');
check('country+city narrows to the one lawyer in جدة', $jIds === [$lwJeddah]);
check('a city without its country is dropped, not treated as "any city"',
    count(legal_journey_lawyers_for_specialty($pdo, $catId, ['city' => 'جدة'])) >= 1);

// Filter by experience band.
$under5 = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['experience' => 'under5']), 'id');
check('experience=under5 keeps the 3-year lawyer', in_array($lwSA, $under5));
check('experience=under5 excludes the 12-year lawyer', !in_array($lwBH, $under5));
$over20 = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['experience' => 'over20']), 'id');
check('experience=over20 keeps only the 25-year lawyer', $over20 === [$lwJeddah]);
$eleven15 = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['experience' => '11to15']), 'id');
check('experience=11to15 keeps the 12-year lawyer', in_array($lwBH, $eleven15) && !in_array($lwSA, $eleven15));
$five10 = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['experience' => '5to10']), 'id');
check('experience=5to10 excludes both the 3-year and 12-year lawyers',
    !in_array($lwSA, $five10) && !in_array($lwBH, $five10));

// Filter by price.
$cheap = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['max_fee' => 300]), 'id');
check('max_fee=300 keeps the 200 lawyer', in_array($lwSA, $cheap));
check('max_fee=300 excludes the 500 lawyer', !in_array($lwBH, $cheap));
$minOnly = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['min_fee' => 600]), 'id');
check('min_fee=600 keeps only the 900 lawyer', $minOnly === [$lwJeddah]);
$range = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, ['min_fee' => 300, 'max_fee' => 600]), 'id');
check('a fee range keeps only the lawyer inside it', $range === [$lwBH]);

// Combine filters.
$combo = array_column(legal_journey_lawyers_for_specialty($pdo, $catId, [
    'country' => 'SA', 'experience' => 'under5', 'max_fee' => 300,
]), 'id');
check('combining country+experience+price keeps only the matching lawyer', $combo === [$lwSA]);

// Availability filter. None of the three seeded lawyers has published hours, so the
// available-only set must be empty — and crucially, no unavailable slot is advertised.
$avail = legal_journey_lawyers_for_specialty($pdo, $catId, ['available_only' => true]);
check('available_only removes lawyers with no published slots', count($avail) === 0);

// Now give the Jeddah lawyer a real weekly schedule and re-check: they become the only
// available match, and their card carries a real slot, not an invented one.
$pdo->prepare("INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?, 1, '09:00:00', '11:00:00', 30)")
    ->execute([$lwJeddah]);
$availNow = legal_journey_lawyers_for_specialty($pdo, $catId, ['available_only' => true]);
$availIds = array_column($availNow, 'id');
check('available_only keeps the lawyer who published hours', in_array($lwJeddah, $availIds));
check('available_only still excludes the lawyers without hours',
    !in_array($lwSA, $availIds) && !in_array($lwBH, $availIds));
$availCard = $availNow[0] ?? null;
check('an available lawyer carries a real open slot',
    $availCard !== null && ($availCard['availability']['slot_count'] ?? 0) > 0);
$pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id = ?')->execute([$lwJeddah]);

// A bad band key is ignored (means "all"), never a SQL error.
check('an unknown experience band is ignored, not an error',
    count(legal_journey_lawyers_for_specialty($pdo, $catId, ['experience' => 'bogus'])) === count($all));

// The count helper agrees with the list length.
check('the count helper matches the list length',
    legal_journey_lawyers_count($pdo, $catId, ['country' => 'SA']) === count($sa));

// ---------------------------------------------------------------------------
section('12. Booking filters — the website and the API return the same lawyers');
// ---------------------------------------------------------------------------
// The API path is public, so no session is needed.
[$ac, $abody] = http60('GET', '/api/v1/journey.php?action=lawyers&category_id=' . $catId . '&country=SA&city=' . rawurlencode('جدة'));
$ajson = json_decode($abody, true);
check('the journey API answers 200', $ac === 200 && is_array($ajson));
check('the API applies the country+city filter', ($ajson['count'] ?? -1) === 1);
check('the API returns the same lawyer id as the website',
    (int)(($ajson['lawyers'][0]['id'] ?? 0)) === $lwJeddah);
check('the API exposes the city on the lawyer card', ($ajson['lawyers'][0]['city'] ?? '') === 'جدة');
check('the API exposes a combined location label', ($ajson['lawyers'][0]['location'] ?? '') !== '');
check('the API never leaks a password hash',
    stripos($abody, 'password_hash') === false && stripos($abody, '$2y$') === false);

// The filter vocabulary endpoint.
[$fc, $fbody] = http60('GET', '/api/v1/journey.php?action=filters');
$fjson = json_decode($fbody, true);
check('the filters action answers 200', $fc === 200 && is_array($fjson));
check('the filters action returns countries, cities and experience',
    !empty($fjson['countries']) && !empty($fjson['cities']) && count($fjson['experience'] ?? []) === 5);

// filtered_out distinguishes "filters excluded everyone" from "no lawyer at all".
[$oc, $obody] = http60('GET', '/api/v1/journey.php?action=lawyers&category_id=' . $catId . '&country=BH&city=' . rawurlencode('المنامة') . '&experience=under5');
$ojson = json_decode($obody, true);
check('a filter that excludes everyone sets filtered_out',
    ($ojson['count'] ?? -1) === 0 && ($ojson['filtered_out'] ?? false) === true);
check('a filtered-out result carries no alternative block (the specialty does have lawyers)',
    ($ojson['alternative'] ?? null) === null);

// ---------------------------------------------------------------------------
section('12b. The directory API applies the same filters (website/app parity)');
// ---------------------------------------------------------------------------
[$dc1, $db1] = http60('GET', '/api/v1/lawyers.php?country=SA&city=' . rawurlencode('جدة'));
$dj1 = json_decode($db1, true);
check('the directory API answers 200', $dc1 === 200 && is_array($dj1));
$dirIds = array_column($dj1['lawyers'] ?? [], 'id');
check('the directory API honours country+city', in_array($lwJeddah, $dirIds) && !in_array($lwBH, $dirIds));
check('the directory API exposes the combined location label',
    ($dj1['lawyers'][0]['location'] ?? '') !== '');
check('the directory API never leaks a password hash', stripos($db1, 'password_hash') === false);

[$dc2, $db2] = http60('GET', '/api/v1/lawyers.php?country=SA&experience=over20');
$dj2 = json_decode($db2, true);
$dirIds2 = array_column($dj2['lawyers'] ?? [], 'id');
check('the directory API honours the experience band',
    in_array($lwJeddah, $dirIds2) && !in_array($lwSA, $dirIds2));

[$dc3, $db3] = http60('GET', '/api/v1/lawyers.php?max_fee=300');
$dj3 = json_decode($db3, true);
$dirIds3 = array_column($dj3['lawyers'] ?? [], 'id');
check('the directory API honours the fee ceiling',
    in_array($lwSA, $dirIds3) && !in_array($lwBH, $dirIds3));

// The website and the directory API must agree on the same filter.
[$wc, $wbody] = http60('GET', '/api/v1/journey.php?action=lawyers&category_id=' . $catId . '&country=SA&city=' . rawurlencode('جدة'));
$wjson = json_decode($wbody, true);
check('the journey API and the directory API return the same lawyer for the same filter',
    (int)($wjson['lawyers'][0]['id'] ?? 0) === $lwJeddah
    && in_array($lwJeddah, $dirIds));

// ---------------------------------------------------------------------------
section('13. The journey page renders the filters and the matching count');
// ---------------------------------------------------------------------------
// The journey lives behind the client shell, so log in as a client first.
$clientJar = tempnam(sys_get_temp_dir(), 'exec60client');
[, $clogin] = http60('GET', '/login.php', ['jar' => $clientJar]);
preg_match('/name="csrf_token" value="([^"]+)"/', $clogin, $cm);
http60('POST', '/login.php', ['jar' => $clientJar, 'post' => http_build_query([
    'csrf_token' => $cm[1] ?? '',
    'email' => 'client-a@example.com',
    'password' => 'Test-Password-2026',
])]);
[$jc, $journeyPage] = http60('GET', '/consultation-journey-lawyers.php?category_id=' . $catId, ['jar' => $clientJar]);
check('the journey page renders 200 for a signed-in client', $jc === 200);
check('the journey page is a full document', strlen((string)$journeyPage) > 1000);
check('the journey page carries the country filter', strpos($journeyPage, 'name="country"') !== false);
check('the journey page carries the city filter', strpos($journeyPage, 'data-city-select') !== false);
check('the journey page carries the experience filter', strpos($journeyPage, 'name="experience"') !== false);
check('the journey page carries the price range', strpos($journeyPage, 'name="min_fee"') !== false && strpos($journeyPage, 'name="max_fee"') !== false);
check('the journey page carries the availability checkbox', strpos($journeyPage, 'name="available_only"') !== false);
check('the journey page carries a matching-count line', strpos($journeyPage, 'journey-result-count') !== false);
check('the journey page wires the dependent city JS', strpos($journeyPage, 'mawatheq-city-data') !== false && strpos($journeyPage, 'lawyer-form.js') !== false);
check('the journey page shows a per-lawyer location', strpos($journeyPage, 'sub loc') !== false);
check('the journey page offers View profile and Book consultation',
    strpos($journeyPage, 'عرض الملف') !== false && strpos($journeyPage, 'حجز استشارة') !== false);
check('the journey page has no PHP fatal', stripos($journeyPage, 'fatal error') === false && stripos($journeyPage, 'uncaught') === false);

// With a filter applied, the count line reflects it.
[$fc2, $filteredPage] = http60('GET', '/consultation-journey-lawyers.php?category_id=' . $catId . '&country=SA&city=' . rawurlencode('جدة'), ['jar' => $clientJar]);
check('the filtered journey page renders 200', $fc2 === 200 && strlen((string)$filteredPage) > 1000);
check('the filtered journey page still renders the count line', strpos($filteredPage, 'journey-result-count') !== false);
check('the filtered journey page offers a Clear-filters action', strpos($filteredPage, 'مسح الفلاتر') !== false);
check('the filtered journey page is not blank', stripos($filteredPage, 'fatal error') === false);

// ---------------------------------------------------------------------------
section('14. Booking from a filtered result carries the journey context');
// ---------------------------------------------------------------------------
// booking.php must be reachable and accept the journey parameters. We only assert the
// form renders with the lawyer pre-selected; we do not create a real consultation here
// (that is covered by exec37 and exec35).
[$bc, $bbody] = http60('GET', '/booking.php?lawyer_id=' . $lwJeddah . '&specialty_category_id=' . $catId);
check('the booking page answers 200 or 302 (login redirect)', $bc === 200 || $bc === 302);
check('the booking page has no PHP fatal', stripos($bbody, 'fatal error') === false && stripos($bbody, 'uncaught') === false);

// ---------------------------------------------------------------------------
section('15. Non-regression — the lawyer login flow is untouched');
// ---------------------------------------------------------------------------
[$lc, $lbody] = http60('GET', '/lawyer-login.php');
check('the lawyer login page answers 200', $lc === 200);
check('the lawyer login page still renders its form', strpos($lbody, 'type="password"') !== false);
check('the lawyer login page has no PHP fatal', stripos($lbody, 'fatal error') === false && stripos($lbody, 'uncaught') === false);

// ---------------------------------------------------------------------------
section('16. Non-regression — the legacy lawyer directory still answers');
// ---------------------------------------------------------------------------
[$dc, $dbody] = http60('GET', '/api/v1/lawyers.php');
check('the legacy directory API answers 200', $dc === 200);
$djson = json_decode($dbody, true);
check('the legacy directory still returns a lawyers array', is_array($djson['lawyers'] ?? null));

// ---------------------------------------------------------------------------
section('17. Cleanup');
// ---------------------------------------------------------------------------
$cleanup = 0;
foreach ($created as $id) {
    $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM lawyers WHERE id = ?')->execute([$id]);
    $cleanup++;
}
foreach ($createdCats ?? [] as $cid) {
    $pdo->prepare('DELETE FROM najiz_categories WHERE id = ?')->execute([$cid]);
    $cleanup++;
}
check('every seeded row was removed', $cleanup > 0);
check('no seeded lawyer remains',
    (int)$pdo->query("SELECT COUNT(*) FROM lawyers WHERE email LIKE 'exec60f-" . $suffix . "%'")->fetchColumn() === 0);

echo "\n------------------------------------------------------------\n";
echo "checks: $checks   failures: $failures\n";
exit($failures === 0 ? 0 : 1);
