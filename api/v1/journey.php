<?php
/**
 * GET /api/v1/journey.php — رحلة حجز الاستشارة (الموقع وتطبيق الجوال).
 *
 * The shared backend for the customer consultation journey. The website and the
 * mobile app call this same endpoint, so the journey cannot drift between them.
 *
 * Public (no token): choosing a specialty and a lawyer is browsing, exactly like
 * the public lawyer directory. Nothing private is returned — only approved lawyers
 * who genuinely provide the specialty.
 *
 *   ?action=specialties                     → legal specialties, as cards
 *   ?action=issue-types&category_id=N       → plain-language issue types
 *   ?action=lawyers&category_id=N           → approved lawyers providing specialty N,
 *                                             narrowed by the booking filters
 *                                             (country, city, experience, min_fee,
 *                                             max_fee, available_only, q)
 *   ?action=filters                         → the filter vocabulary (countries, cities
 *                                             per country, experience bands)
 *   ?action=lawyer&lawyer_id=N              → one lawyer's full comparison profile
 *   ?action=availability&lawyer_id=N        → real open slots for the coming days
 *
 * CONFIDENTIALITY
 *   No response from this endpoint contains the internal classification's name or
 *   taxonomy vocabulary. Every customer-facing string is emitted through
 *   legal_journey_customer_text(); the internal classification is available only to
 *   the lawyer/admin endpoints, never here.
 *
 * A specialty with no lawyer behind it returns an empty list and `alternative: true`,
 * so the client shows a clear alternative instead of unrelated lawyers.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/legal_journey.php';

$action = (string)($_GET['action'] ?? 'specialties');

try {
    switch ($action) {
        case 'specialties':
            $q = trim((string)($_GET['q'] ?? ''));
            $rows = legal_journey_specialties($pdo, $q);
            api_json([
                'ok'          => true,
                'specialties' => $rows,
                'ready'       => legal_journey_ready($pdo),
            ]);
            break;

        case 'issue-types':
            $cid = (int)($_GET['category_id'] ?? 0);
            if ($cid <= 0) api_error('category_id is required');
            $q = trim((string)($_GET['q'] ?? ''));
            api_json([
                'ok'          => true,
                'issue_types' => legal_journey_issue_types($pdo, $cid, $q),
                // Offered alongside the real list so the customer is never blocked.
                'not_sure'    => legal_journey_not_sure_option(),
            ]);
            break;

        case 'lawyers':
            $cid = (int)($_GET['category_id'] ?? 0);
            if ($cid <= 0) api_error('category_id is required');
            // Every professional filter (country, city, experience band, fee range,
            // availability) goes through the one normaliser, so the app and the website
            // filter identically. `available_only` is sent as `1`/`true`/`on`.
            $filters = legal_journey_filter_params($_GET);
            $lawyers = legal_journey_lawyers_for_specialty($pdo, $cid, $filters);
            // Present each lawyer's consultation price with the tenant's currency so the app
            // never formats money itself. `fee_display` is the one authoritative payable amount
            // (`pricing.total`), not the raw hourly rate — the card must advertise exactly what
            // the gateway will charge (owner decision, Execution 66).
            require_once __DIR__ . '/../../includes/currency.php';
            require_once __DIR__ . '/../../includes/consultation_pricing.php';
            foreach ($lawyers as &$lw) {
                $breakdown = $lw['pricing'] ?? consultation_pricing_for_lawyer($pdo, $lw);
                $lw['fee_display'] = !empty($breakdown['has_price'])
                    ? money_text($breakdown['total'])
                    : null;
                $lw['duration_minutes'] = (int)($breakdown['duration_minutes'] ?? 30);
                $lw['pricing'] = $breakdown;
            }
            unset($lw);
            $specialtyHasLawyers = count(legal_journey_lawyers_for_specialty($pdo, $cid, [])) > 0;
            api_json([
                'ok'            => true,
                'lawyers'       => $lawyers,
                'count'         => count($lawyers),
                'matched'       => count($lawyers) > 0,
                'filters'       => $filters,
                'filtered_out'  => count($lawyers) === 0 && $specialtyHasLawyers,
                'alternative'   => (count($lawyers) === 0 && !$specialtyHasLawyers)
                    ? legal_journey_no_match_alternative($pdo, $cid) : null,
            ]);
            break;

        case 'filters':
            // The vocabulary the client renders, read from the same constants the server
            // filters with — so an option the app shows can never be one the backend
            // silently ignores.
            api_json(['ok' => true] + legal_journey_filter_options($pdo));
            break;

        case 'lawyer':
            $lid = (int)($_GET['lawyer_id'] ?? 0);
            if ($lid <= 0) api_error('lawyer_id is required');
            $lawyer = legal_journey_lawyer_profile($pdo, $lid);
            if (!$lawyer) api_error('lawyer not found', 404);
            api_json(['ok' => true, 'lawyer' => $lawyer]);
            break;

        case 'availability':
            $lid = (int)($_GET['lawyer_id'] ?? 0);
            if ($lid <= 0) api_error('lawyer_id is required');
            api_json([
                'ok'           => true,
                'availability' => legal_journey_availability_summary(
                    $pdo, $lid, $_GET['date'] ?? null, (int)($_GET['days'] ?? 7), (int)($_GET['max_slots'] ?? 6)
                ),
            ]);
            break;

        default:
            api_error('unknown action');
    }
} catch (Throwable $e) {
    error_log('api/v1/journey: ' . $e->getMessage());
    api_error('حدث خطأ في الخادم. يرجى المحاولة لاحقاً.', 500);
}
