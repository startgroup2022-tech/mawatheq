<?php
/**
 * Execution 46 — lawyer visibility in the customer journey.
 *
 * THE BUG THIS PINS
 *   The journey required an explicit `lawyer_specialties` row. That table ships EMPTY
 *   on purpose (linking a lawyer to an expertise is an operator action), so every
 *   approved lawyer was invisible and every specialty reported `lawyer_count: 0`. The
 *   customer was told no lawyer existed while the lawyer was listed in the public
 *   directory — the two surfaces disagreed, and the directory was right.
 *
 * THE RULE
 *   A lawyer's categories come from an explicit `lawyer_specialties` row when one
 *   exists; only when they have none does their own declared `specialization` stand in,
 *   mapped through `legal_journey_specialization_categories()`. An explicit link always
 *   wins. A specialization outside the controlled list contributes nothing — an
 *   unmapped value is silence, never a guess. Both readers (the specialty list's count
 *   and the lawyer lookup) derive from one index, so they can never disagree.
 *
 * Every case seeds its own lawyer rows with a random suffix and removes them in
 * cleanup, so the suite is re-runnable and does not depend on seed data.
 *
 * Requires the app on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/exec46_lawyer_visibility_test.php
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

function http46(string $path): array {
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

$suffix = bin2hex(random_bytes(4));
$seeded = [];

/** Insert an approved lawyer with a declared specialization, and remember the id. */
function seed_lawyer(PDO $pdo, string $suffix, string $specialization): int {
    global $seeded;
    $email = "exec46-$suffix-" . count($seeded) . '@example.test';
    $pdo->prepare(
        "INSERT INTO lawyers (full_name, email, password_hash, specialization, status, email_verified)
         VALUES (?,?,?,?,'approved',1)"
    )->execute(["Exec46 $suffix", $email, password_hash('x', PASSWORD_DEFAULT), $specialization]);
    $id = (int)$pdo->lastInsertId();
    $seeded[] = $id;
    return $id;
}

echo "Execution 46 — lawyer visibility in the journey\n";
echo str_repeat('-', 60) . "\n";

if (!legal_journey_ready($pdo)) {
    echo "SKIP: the journey tables are absent (apply upgrade_v59.sql then upgrade_v63.sql).\n";
    exit(0);
}

$catByName = [];
foreach ($pdo->query("SELECT id, name_ar FROM najiz_categories WHERE is_active = 1")->fetchAll() as $r) {
    $catByName[trim((string)$r['name_ar'])] = (int)$r['id'];
}
$criminal = $catByName['الدعاوى الجزائية'] ?? 0;
$family   = $catByName['الأحوال الشخصية'] ?? 0;

if ($criminal <= 0 || $family <= 0) {
    echo "SKIP: the classification is not imported (run the legal classifications import).\n";
    exit(0);
}

try {
    /* ---------------------------------------------------------------------
     * 1. A declared specialization alone makes a lawyer discoverable
     * ------------------------------------------------------------------- */
    section('1. Declared specialization → discoverable without an explicit link');

    $criminalLawyer = seed_lawyer($pdo, $suffix, 'قانون جنائي');
    legal_journey_flush_cache();

    $lawyers = legal_journey_lawyers_for_specialty($pdo, $criminal);
    $ids = array_map(fn($l) => (int)$l['id'], $lawyers);
    check('a criminal-law lawyer is returned for the criminal specialty', in_array($criminalLawyer, $ids, true));

    $listed = array_values(array_filter(legal_journey_specialties($pdo), fn($s) => (int)$s['id'] === $criminal));
    check('the specialty list counts the lawyer', $listed && (int)$listed[0]['lawyer_count'] >= 1);

    [$code, $body] = http46("/api/v1/journey.php?action=lawyers&category_id=$criminal");
    $api = json_decode($body, true);
    check('the journey API reports matched:true', ($api['matched'] ?? false) === true);
    check('the journey API returns the seeded lawyer',
        in_array($criminalLawyer, array_map(fn($l) => (int)$l['id'], $api['lawyers'] ?? []), true));

    // The count and the list must agree: the exact disagreement that made "0 lawyers"
    // look correct while a lawyer sat in the directory.
    check('the reported count matches the returned list length',
        (int)$listed[0]['lawyer_count'] === count(legal_journey_lawyers_for_specialty($pdo, $criminal)));

    /* ---------------------------------------------------------------------
     * 2. An explicit link always beats the declared specialization
     * ------------------------------------------------------------------- */
    section('2. An explicit lawyer_specialties link wins over the declared value');

    $pdo->prepare('INSERT INTO lawyer_specialties (lawyer_id, category_id) VALUES (?,?)')
        ->execute([$criminalLawyer, $family]);
    legal_journey_flush_cache();

    $familyIds = array_map(fn($l) => (int)$l['id'], legal_journey_lawyers_for_specialty($pdo, $family));
    check('the lawyer appears under the explicitly linked specialty', in_array($criminalLawyer, $familyIds, true));

    $criminalIds = array_map(fn($l) => (int)$l['id'], legal_journey_lawyers_for_specialty($pdo, $criminal));
    check('the declared specialization no longer adds a second category', !in_array($criminalLawyer, $criminalIds, true));

    /* ---------------------------------------------------------------------
     * 3. An unmapped specialization is silence, never a guess
     * ------------------------------------------------------------------- */
    section('3. An unmapped specialization contributes nothing');

    $unknown = seed_lawyer($pdo, $suffix, 'تخصص غير معروف ' . $suffix);
    legal_journey_flush_cache();

    $appears = false;
    foreach (legal_journey_specialties($pdo) as $s) {
        if (in_array($unknown, array_map(fn($l) => (int)$l['id'],
            legal_journey_lawyers_for_specialty($pdo, (int)$s['id'])), true)) {
            $appears = true;
            break;
        }
    }
    check('a lawyer with an unmapped specialization appears in no specialty', !$appears);

    /* ---------------------------------------------------------------------
     * 4. Only approved lawyers are ever matched
     * ------------------------------------------------------------------- */
    section('4. Only approved lawyers are matched');

    $pending = seed_lawyer($pdo, $suffix, 'قانون تجاري');
    $pdo->prepare("UPDATE lawyers SET status='pending' WHERE id=?")->execute([$pending]);
    legal_journey_flush_cache();

    $commercial = $catByName['الدعاوى التجارية'] ?? 0;
    $commercialIds = array_map(fn($l) => (int)$l['id'], legal_journey_lawyers_for_specialty($pdo, $commercial));
    check('a pending lawyer is not matched', !in_array($pending, $commercialIds, true));

    /* ---------------------------------------------------------------------
     * 5. The mapping targets real category names, not hardcoded ids
     * ------------------------------------------------------------------- */
    section('5. The specialization map resolves against the live category names');

    $map = legal_journey_specialization_categories();
    $unresolved = [];
    foreach ($map as $spec => $names) {
        foreach ($names as $n) {
            if (!isset($catByName[$n])) $unresolved[] = "$spec → $n";
        }
    }
    check('every mapped category name exists in the classification', $unresolved === []);
    check('the map covers the platform\'s specialization dropdown', count($map) >= 13);
} finally {
    // Cleanup: remove exactly the rows this run created.
    if ($seeded) {
        $ph = implode(',', array_fill(0, count($seeded), '?'));
        $pdo->prepare("DELETE FROM lawyer_specialties WHERE lawyer_id IN ($ph)")->execute($seeded);
        $pdo->prepare("DELETE FROM lawyers WHERE id IN ($ph)")->execute($seeded);
    }
    legal_journey_flush_cache();
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "checks: $checks   failures: $failures\n";
exit($failures === 0 ? 0 : 1);
