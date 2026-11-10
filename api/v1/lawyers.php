<?php
/**
 * GET /api/v1/lawyers.php
 *   ?jurisdiction=..&specialization=..&category_id=..&q=..
 *   &country=..&city=..&experience=..&min_fee=..&max_fee=..&available_only=1
 * لا يحتاج مصادقة (تصفح عام، مثل الموقع تماماً).
 * response: { ok, lawyers: [...] }
 *
 * `category_id` narrows to approved lawyers who actually provide that legal specialty
 * (the journey's "show me lawyers for my problem"). It is additive: a caller that does
 * not send it gets exactly the previous behaviour.
 *
 * Execution 60 added the same professional filters the journey uses — country, city,
 * experience band, fee range and real availability. They are applied by the shared
 * `legal_journey_lawyers_for_specialty()`, so the website and the app cannot disagree
 * about which lawyer matches. Without `category_id` the filters are applied to the
 * plain directory query below, so the directory honours them too.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/legal_journey.php';
require_once __DIR__ . '/../../includes/cities.php';
require_once __DIR__ . '/../../includes/lawyer_experience.php';

// The filters both branches read, normalised once.
$filters = legal_journey_filter_params($_GET);

// True when the caller actually asked for a narrowing — used to distinguish "no lawyer
// for this specialty at all" from "your filters excluded every match".
$filtersActive = $filters['country'] !== ''
    || $filters['city'] !== ''
    || $filters['experience'] !== ''
    || $filters['min_fee'] !== null
    || $filters['max_fee'] !== null
    || $filters['available_only']
    || $filters['q'] !== '';

$categoryId = (int)($_GET['category_id'] ?? 0);
if ($categoryId > 0 && legal_journey_ready($pdo)) {
    // Only the lawyers linked to this specialty, with the same comparison fields the
    // journey shows. A specialty with no lawyer behind it returns an empty list.
    $lawyers = legal_journey_lawyers_for_specialty($pdo, $categoryId, [
        'jurisdiction'   => $_GET['jurisdiction'] ?? '',
        'q'              => $_GET['q'] ?? '',
        'country'        => $filters['country'],
        'city'           => $filters['city'],
        'experience'     => $filters['experience'],
        'min_fee'        => $_GET['min_fee'] ?? '',
        'max_fee'        => $_GET['max_fee'] ?? '',
        'available_only' => $filters['available_only'],
    ]);
    // Present the consultation price with the tenant's currency, exactly as journey.php
    // does, so the app's directory and its journey cannot show two different numbers for
    // the same lawyer.
    require_once __DIR__ . '/../../includes/currency.php';
    require_once __DIR__ . '/../../includes/consultation_pricing.php';
    foreach ($lawyers as &$lw) {
        $breakdown = $lw['pricing'] ?? consultation_pricing_for_lawyer($pdo, $lw);
        $lw['pricing'] = $breakdown;
        $lw['fee_display'] = !empty($breakdown['has_price']) ? money_text($breakdown['total']) : null;
        $lw['duration_minutes'] = (int)($breakdown['duration_minutes'] ?? 30);
    }
    unset($lw);
    api_json([
        'ok'           => true,
        'lawyers'      => $lawyers,
        'count'        => count($lawyers),
        'filtered_out' => count($lawyers) === 0 && $filtersActive,
    ]);
}

$sql = "SELECT id, full_name, specialization, years_experience, hourly_rate, rating, bio, photo_path, jurisdiction, country_code, city
          FROM lawyers WHERE status='approved'";
$params = [];

if (!empty($_GET['jurisdiction'])) { $sql .= ' AND jurisdiction = ?'; $params[] = $_GET['jurisdiction']; }
if (!empty($_GET['specialization'])) { $sql .= ' AND specialization = ?'; $params[] = $_GET['specialization']; }
if ($filters['country'] !== '') {
    $name = jurisdiction_for_country($filters['country']);
    $sql .= ' AND (country_code = ? OR jurisdiction = ?)';
    $params[] = $filters['country'];
    $params[] = $name ?? '';
}
if ($filters['city'] !== '')       { $sql .= ' AND city = ?'; $params[] = $filters['city']; }
if ($filters['min_fee'] !== null)  { $sql .= ' AND hourly_rate IS NOT NULL AND hourly_rate >= ?'; $params[] = $filters['min_fee']; }
if ($filters['max_fee'] !== null)  { $sql .= ' AND (hourly_rate IS NULL OR hourly_rate <= ?)'; $params[] = $filters['max_fee']; }
if ($filters['experience'] !== '') {
    $band = lawyer_experience_band($filters['experience']);
    $sql .= ' AND years_experience >= ?';
    $params[] = $band['min'];
    if ($band['max'] !== null) { $sql .= ' AND years_experience <= ?'; $params[] = $band['max']; }
}
if (!empty($_GET['q'])) { $sql .= ' AND full_name LIKE ?'; $params[] = '%' . $_GET['q'] . '%'; }

$sql .= ' ORDER BY rating DESC LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// `available_only` is a real filter, not a badge: a lawyer with no open slot is removed.
if ($filters['available_only']) {
    $today = date('Y-m-d');
    $rows = array_values(array_filter($rows, function ($lw) use ($pdo, $today) {
        return (legal_journey_availability_summary($pdo, (int)$lw['id'], $today)['slot_count'] ?? 0) > 0;
    }));
}

// Normalise the location fields the same way the journey does, so both surfaces agree.
require_once __DIR__ . '/../../includes/consultation_pricing.php';
require_once __DIR__ . '/../../includes/currency.php';
$rows = array_map(function ($lw) use ($pdo) {
    $lw['id'] = (int)$lw['id'];
    $lw['years_experience'] = (int)$lw['years_experience'];
    $lw['hourly_rate'] = $lw['hourly_rate'] !== null ? (float)$lw['hourly_rate'] : null;
    $lw['rating'] = $lw['rating'] !== null ? (float)$lw['rating'] : null;
    $lw['country_code'] = lawyer_country_code($lw);
    $lw['city'] = ($lw['city'] ?? '') !== '' ? (string)$lw['city'] : null;
    $lw['location'] = lawyer_location_label($lw['country_code'], $lw['city']);
    // The ONE payable amount and the length it prices, computed by the shared module so
    // the directory card shows exactly what the gateway will charge — never a raw hourly
    // rate presented as the consultation price.
    $breakdown = consultation_pricing_for_lawyer($pdo, $lw);
    $lw['pricing'] = $breakdown;
    $lw['fee_display'] = !empty($breakdown['has_price']) ? money_text($breakdown['total']) : null;
    $lw['duration_minutes'] = (int)($breakdown['duration_minutes'] ?? 30);
    return $lw;
}, $rows);

// Report the count the same way the specialty branch does, so the website and the
// app can render "N lawyers" from either endpoint without special-casing.
api_json([
    'ok'           => true,
    'lawyers'      => $rows,
    'count'        => count($rows),
    'filtered_out' => count($rows) === 0 && $filtersActive,
]);
