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
 *   ?action=lawyers&category_id=N           → approved lawyers providing specialty N
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
            $lawyers = legal_journey_lawyers_for_specialty($pdo, $cid, [
                'jurisdiction' => $_GET['jurisdiction'] ?? '',
                'max_fee'      => $_GET['max_fee'] ?? '',
                'q'            => $_GET['q'] ?? '',
            ]);
            // Present each lawyer's fee with the tenant's currency so the app never
            // formats money itself.
            require_once __DIR__ . '/../../includes/currency.php';
            foreach ($lawyers as &$lw) {
                $lw['fee_display'] = $lw['hourly_rate'] !== null
                    ? currency_number($lw['hourly_rate']) . ' ' . currency_symbol()
                    : null;
            }
            unset($lw);
            api_json([
                'ok'          => true,
                'lawyers'     => $lawyers,
                'matched'     => count($lawyers) > 0,
                'alternative' => count($lawyers) === 0 ? legal_journey_no_match_alternative($pdo, $cid) : null,
            ]);
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
