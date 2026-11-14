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

// No specialty: the general directory, filtered by the SAME shared search the website's
// consultations page uses. One function means the website and the app cannot return
// different lawyers for the same filters.
$rows = legal_journey_directory_search($pdo, $_GET);

// Present the consultation price with the tenant's currency, exactly as journey.php
// does, so the app's directory and its journey cannot show two different numbers for
// the same lawyer.
require_once __DIR__ . '/../../includes/currency.php';
foreach ($rows as &$lw) {
    $lw['fee_display'] = $lw['price_label'];
}
unset($lw);

// Report the count the same way the specialty branch does, so the website and the
// app can render "N lawyers" from either endpoint without special-casing.
api_json([
    'ok'           => true,
    'lawyers'      => $rows,
    'count'        => count($rows),
    'filtered_out' => count($rows) === 0 && $filtersActive,
]);
