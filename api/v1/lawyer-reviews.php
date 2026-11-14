<?php
/**
 * GET  /api/v1/lawyer-reviews.php?lawyer_id=..      → ملخّص + قائمة تقييمات محامٍ
 * GET  /api/v1/lawyer-reviews.php?reviewable=1       → استشارات العميل القابلة للتقييم
 * POST /api/v1/lawyer-reviews.php { consultation_id, rating, comment? }
 *                                                     → إنشاء تقييم موثّق
 * POST /api/v1/lawyer-reviews.php { report_id }       → الإبلاغ عن تقييم
 *
 * التقييم موثّق: لا يُقبل إلا لاستشارة مكتملة يملكها العميل، ومرة واحدة فقط لكل استشارة.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_features.php';

// The published review list is public reference data (like lawyers.php), so a guest can
// read a lawyer's ratings. Only the account-scoped actions authenticate.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['reviewable'])) {
    $lawyerId = (int)($_GET['lawyer_id'] ?? 0);
    if ($lawyerId <= 0) api_error('lawyer_id is required');
    if (!lawyer_is_public($pdo, $lawyerId)) api_error('lawyer not found', 404);
    api_json([
        'ok'      => true,
        'summary' => review_summary_for_lawyer($pdo, $lawyerId),
        'reviews' => reviews_for_lawyer($pdo, $lawyerId, (int)($_GET['limit'] ?? 20)),
    ]);
}

$auth = api_authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // `reviewable=1`: the client's completed consultations they can still rate.
    if ($auth['type'] !== 'client') api_error('this action is for clients', 403);
    api_json(['ok' => true, 'consultations' => reviewable_consultations($pdo, (int)$auth['id'])]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth['type'] !== 'client') api_error('this endpoint is for clients', 403);
    $clientId = (int)$auth['id'];
    $in = api_input();

    if (!empty($in['report_id'])) {
        review_report($pdo, (int)$in['report_id'], $clientId);
        api_json(['ok' => true]);
    }

    $consultationId = (int)($in['consultation_id'] ?? 0);
    $rating = (int)($in['rating'] ?? 0);
    if ($consultationId <= 0) api_error('consultation_id is required');
    if ($rating < 1 || $rating > 5) api_error('rating must be between 1 and 5');

    $res = review_create($pdo, $clientId, $consultationId, $rating, $in['comment'] ?? null);
    if (!$res['ok']) {
        $codes = ['not_found' => 404, 'not_completed' => 409, 'already_reviewed' => 409];
        api_error($res['error'], $codes[$res['error']] ?? 400);
    }
    // The lawyer is resolved from the consultation, never from the request.
    $lw = $pdo->prepare('SELECT lawyer_id FROM consultations WHERE id=?');
    $lw->execute([$consultationId]);
    $lawyerId = (int)$lw->fetchColumn();
    api_json(['ok' => true, 'id' => $res['id'], 'summary' => review_summary_for_lawyer($pdo, $lawyerId)]);
}

api_error('method not allowed', 405);
