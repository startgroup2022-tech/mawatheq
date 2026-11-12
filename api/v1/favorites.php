<?php
/**
 * GET  /api/v1/favorites.php            → قائمة المحامين المفضّلين
 * POST /api/v1/favorites.php  { lawyer_id, action: "toggle"|"add"|"remove" }
 *                             → { ok, favorited: bool }
 *
 * مخصّص للعميل فقط. كل كتابة تخصّ الحساب نفسه، ولا يستطيع عميل لمس مفضّلة غيره.
 *
 * The directory and journey cards can paint the heart from `favorite_ids` (a light
 * endpoint variant: `?ids=1` returns only the ids), so a list page does not need to
 * fetch the full hydrated cards just to know what is saved.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_features.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') api_error('this endpoint is for clients', 403);
$clientId = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['ids'])) {
        api_json(['ok' => true, 'ids' => favorite_ids_for_client($pdo, $clientId)]);
    }
    api_json(['ok' => true, 'lawyers' => favorites_for_client($pdo, $clientId)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    $lawyerId = (int)($in['lawyer_id'] ?? 0);
    if ($lawyerId <= 0) api_error('lawyer_id is required');
    if (!lawyer_is_public($pdo, $lawyerId)) api_error('lawyer not found', 404);

    $action = (string)($in['action'] ?? 'toggle');
    if ($action === 'add') {
        favorite_add($pdo, $clientId, $lawyerId);
        api_json(['ok' => true, 'favorited' => true]);
    }
    if ($action === 'remove') {
        favorite_remove($pdo, $clientId, $lawyerId);
        api_json(['ok' => true, 'favorited' => false]);
    }
    $state = favorite_toggle($pdo, $clientId, $lawyerId);
    api_json(['ok' => true, 'favorited' => $state]);
}

api_error('method not allowed', 405);
