<?php
/**
 * GET  /api/v1/contact-requests.php               → العميل: طلباته المُرسلة
 *      /api/v1/contact-requests.php (محامي)        → صندوق المحامي الوارد
 * POST /api/v1/contact-requests.php (عميل) { lawyer_id, subject?, message }
 * POST /api/v1/contact-requests.php (محامي) { request_id } → تعليم كمقروء
 *
 * لا تُكشف بيانات تواصل المحامي الخاصة أبداً؛ الطلب يبقى داخل المنصة.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_features.php';

$auth = api_authenticate($pdo);
$isLawyer = $auth['type'] === 'lawyer';
$accountId = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($isLawyer) {
        api_json([
            'ok'       => true,
            'unread'   => contact_requests_unread_count($pdo, $accountId),
            'requests' => contact_requests_for_lawyer($pdo, $accountId),
        ]);
    }
    api_json(['ok' => true, 'requests' => contact_requests_for_client($pdo, $accountId)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    if ($isLawyer) {
        $requestId = (int)($in['request_id'] ?? 0);
        if ($requestId <= 0) api_error('request_id is required');
        contact_request_mark_read($pdo, $accountId, $requestId);
        api_json(['ok' => true, 'unread' => contact_requests_unread_count($pdo, $accountId)]);
    }
    $lawyerId = (int)($in['lawyer_id'] ?? 0);
    if ($lawyerId <= 0) api_error('lawyer_id is required');
    $res = contact_request_create($pdo, $accountId, $lawyerId, $in['subject'] ?? '', (string)($in['message'] ?? ''));
    if (!$res['ok']) {
        api_error($res['error'] ?? 'error', ($res['error'] ?? '') === 'not_found' ? 404 : 422);
    }
    api_json(['ok' => true, 'id' => $res['id']]);
}

api_error('method not allowed', 405);
