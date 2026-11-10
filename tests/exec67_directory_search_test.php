<?php
/**
 * Execution 67 — the professional lawyer directory search.
 *
 * The website's consultations page and the mobile app must return the same lawyers for
 * the same filters. Both now call legal_journey_directory_search(), the single
 * implementation this suite drives directly: specialization, country, city (dependent on
 * country), experience band, fee range and real availability — combined, server-side.
 *
 * Run: php tests/exec67_directory_search_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/legal_journey.php';
require_once __DIR__ . '/../includes/lawyer_experience.php';
require_once __DIR__ . '/../includes/currency.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$suffix = bin2hex(random_bytes(3));
$ids = [];
function seed_lawyer(PDO $pdo, array $a): int {
    $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified,jurisdiction,country_code,city,specialization,years_experience,hourly_rate)
                   VALUES (?,?,?,'approved',1,?,?,?,?,?,?)")
        ->execute([
            $a['name'], $a['email'], password_hash('x', PASSWORD_BCRYPT),
            $a['jurisdiction'], $a['country_code'], $a['city'],
            $a['specialization'], $a['years_experience'], $a['hourly_rate'],
        ]);
    return (int)$pdo->lastInsertId();
}

echo "\n== seed four lawyers with distinct, checkable facts ==\n";
$ids[] = seed_lawyer($pdo, ['name' => "A $suffix", 'email' => "a-$suffix@ex.test", 'jurisdiction' => 'السعودية', 'country_code' => 'SA', 'city' => 'الرياض',  'specialization' => 'تجاري', 'years_experience' => 3,  'hourly_rate' => 100]);
$ids[] = seed_lawyer($pdo, ['name' => "B $suffix", 'email' => "b-$suffix@ex.test", 'jurisdiction' => 'السعودية', 'country_code' => 'SA', 'city' => 'جدة',    'specialization' => 'جنائي', 'years_experience' => 8,  'hourly_rate' => 300]);
$ids[] = seed_lawyer($pdo, ['name' => "C $suffix", 'email' => "c-$suffix@ex.test", 'jurisdiction' => 'السعودية', 'country_code' => 'SA', 'city' => 'الرياض',  'specialization' => 'تجاري', 'years_experience' => 13, 'hourly_rate' => 500]);
$ids[] = seed_lawyer($pdo, ['name' => "D $suffix", 'email' => "d-$suffix@ex.test", 'jurisdiction' => 'الإمارات', 'country_code' => 'AE', 'city' => 'دبي',    'specialization' => 'تجاري', 'years_experience' => 25, 'hourly_rate' => 900]);
check('four lawyers seeded', count($ids) === 4);

$names = fn(array $rows) => array_map(fn($r) => $r['full_name'], $rows);
$has = fn(array $rows, string $name) => in_array($name, $names($rows), true);

echo "\n== 1. filter by specialization ==\n";
$r = legal_journey_directory_search($pdo, ['specialization' => 'جنائي', 'q' => $suffix]);
check('specialization narrows to the one criminal lawyer', count($r) === 1 && $has($r, "B $suffix"));

echo "\n== 2. filter by country ==\n";
$r = legal_journey_directory_search($pdo, ['country' => 'AE', 'q' => $suffix]);
check('country=AE returns only the Emirati lawyer', count($r) === 1 && $has($r, "D $suffix"));

echo "\n== 3. city depends on country ==\n";
$r = legal_journey_directory_search($pdo, ['country' => 'SA', 'city' => 'الرياض', 'q' => $suffix]);
check('SA + الرياض returns the two Riyadh lawyers', count($r) === 2 && $has($r, "A $suffix") && $has($r, "C $suffix"));
$r = legal_journey_directory_search($pdo, ['country' => 'SA', 'city' => 'دبي', 'q' => $suffix]);
check('a city outside the country is dropped, not matched', count($r) === 3);
$r = legal_journey_directory_search($pdo, ['city' => 'الرياض', 'q' => $suffix]);
check('a city without a country is dropped', count($r) === 4);

echo "\n== 4. experience bands are non-overlapping ==\n";
$r = legal_journey_directory_search($pdo, ['experience' => 'under5', 'q' => $suffix]);
check('under5 returns only 3 years', count($r) === 1 && $has($r, "A $suffix"));
$r = legal_journey_directory_search($pdo, ['experience' => '5to10', 'q' => $suffix]);
check('5to10 returns only 8 years', count($r) === 1 && $has($r, "B $suffix"));
$r = legal_journey_directory_search($pdo, ['experience' => '11to15', 'q' => $suffix]);
check('11to15 returns only 13 years', count($r) === 1 && $has($r, "C $suffix"));
$r = legal_journey_directory_search($pdo, ['experience' => '16to20', 'q' => $suffix]);
check('16to20 returns nobody', count($r) === 0);
$r = legal_journey_directory_search($pdo, ['experience' => 'over20', 'q' => $suffix]);
check('over20 returns only 25 years', count($r) === 1 && $has($r, "D $suffix"));
check('the bands leave no gap and do not overlap',
    lawyer_experience_band_for(4) === 'under5' && lawyer_experience_band_for(5) === '5to10'
    && lawyer_experience_band_for(10) === '5to10' && lawyer_experience_band_for(11) === '11to15'
    && lawyer_experience_band_for(20) === '16to20' && lawyer_experience_band_for(21) === 'over20');

echo "\n== 5. fee range ==\n";
$r = legal_journey_directory_search($pdo, ['min_fee' => 400, 'q' => $suffix]);
check('min_fee=400 returns the two pricier lawyers', count($r) === 2);
$r = legal_journey_directory_search($pdo, ['max_fee' => 200, 'q' => $suffix]);
check('max_fee=200 returns only the cheapest', count($r) === 1 && $has($r, "A $suffix"));
$r = legal_journey_directory_search($pdo, ['min_fee' => 300, 'max_fee' => 500, 'q' => $suffix]);
check('a range returns only lawyers inside it', count($r) === 2 && !$has($r, "A $suffix") && !$has($r, "D $suffix"));
$r = legal_journey_directory_search($pdo, ['min_fee' => 500, 'max_fee' => 100, 'q' => $suffix]);
check('a reversed range is normalised, not dropped', count($r) === 3);

echo "\n== 6. combining filters ==\n";
$r = legal_journey_directory_search($pdo, ['country' => 'SA', 'city' => 'الرياض', 'specialization' => 'تجاري', 'experience' => '11to15', 'q' => $suffix]);
check('combined filters return exactly the one match', count($r) === 1 && $has($r, "C $suffix"));
$r = legal_journey_directory_search($pdo, ['country' => 'AE', 'specialization' => 'تجاري', 'experience' => '11to15', 'q' => $suffix]);
check('combined filters that cannot all hold return empty', count($r) === 0);

echo "\n== 7. availability is real, not a badge ==\n";
$day = (int)date('w'); // 0=Sunday
$pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,?)')
    ->execute([$ids[0], $day, '09:00:00', '12:00:00', 30]);
$r = legal_journey_directory_search($pdo, ['available_only' => 1, 'q' => $suffix]);
check('available_only keeps the lawyer with published hours', $has($r, "A $suffix"));
check('available_only drops every lawyer without hours', count($r) === 1);
$av = legal_journey_availability_summary($pdo, $ids[0]);
check('the availability summary reports real slots', $av['has_published_hours'] && $av['slot_count'] >= 1);
$avNone = legal_journey_availability_summary($pdo, $ids[1]);
check('a lawyer with no hours advertises none', !$avNone['has_published_hours'] && $avNone['slot_count'] === 0);

echo "\n== 8. the card carries the price the gateway will charge ==\n";
$r = legal_journey_directory_search($pdo, ['q' => "C $suffix"]);
check('the matching card is hydrated with a price breakdown', count($r) === 1 && isset($r[0]['pricing']));
check('the card states a location label', !empty($r[0]['location']));
check('the card states a duration', (int)$r[0]['duration_minutes'] > 0);

echo "\n== 9. unapproved lawyers never appear ==\n";
$pdo->prepare("UPDATE lawyers SET status='pending' WHERE id=?")->execute([$ids[0]]);
$r = legal_journey_directory_search($pdo, ['q' => $suffix]);
check('a pending lawyer is excluded from the directory', !$has($r, "A $suffix"));
$pdo->prepare("UPDATE lawyers SET status='approved' WHERE id=?")->execute([$ids[0]]);

echo "\n== 10. the specialization vocabulary keeps legacy stored values findable ==\n";
$legacyId = seed_lawyer($pdo, ['name' => "E $suffix", 'email' => "e-$suffix@ex.test", 'jurisdiction' => 'السعودية', 'country_code' => 'SA', 'city' => 'الرياض', 'specialization' => 'تجاري', 'years_experience' => 4, 'hourly_rate' => 120]);
$ids[] = $legacyId;
$vocab = legal_journey_specialization_vocabulary($pdo);
check('the vocabulary offers the canonical label', in_array('قانون تجاري', $vocab, true));
check('the vocabulary also offers the stored legacy synonym', in_array('تجاري', $vocab, true));
$r = legal_journey_directory_search($pdo, ['specialization' => 'تجاري', 'q' => $suffix]);
check('a lawyer stored with the legacy synonym is findable by it', $has($r, "E $suffix"));
$r = legal_journey_directory_search($pdo, ['specialization' => 'قانون تجاري', 'q' => $suffix]);
check('the canonical label does not falsely match the legacy row', !$has($r, "E $suffix"));

echo "\n== cleanup ==\n";
$ph = implode(',', array_fill(0, count($ids), '?'));
$pdo->prepare("DELETE FROM lawyer_availability WHERE lawyer_id IN ($ph)")->execute($ids);
$pdo->prepare("DELETE FROM lawyers WHERE id IN ($ph)")->execute($ids);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
