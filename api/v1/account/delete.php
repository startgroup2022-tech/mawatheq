<?php
/**
 * POST /api/v1/account/delete.php
 * headers: Authorization: Bearer {token}
 * body: { "password": "...", "confirm": "DELETE" }
 * response: { ok: true } | { ok: false, error, blockers? }
 *
 * Google Play requires an in-app route to delete an account, so the mobile client
 * calls this from the account screen. The work happens in includes/account_deletion.php
 * so the web route and this one cannot drift.
 *
 * The caller must send the account's own password. A valid Bearer token is not
 * sufficient: a token found on a shared device must not be able to destroy the
 * account it belongs to.
 *
 * `confirm` must be the literal string DELETE (case-insensitive). It is a second,
 * independent guard against an accidental tap firing the request.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/account_deletion.php';

$auth = api_authenticate($pdo);
$in = api_input();

$password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
$confirm  = isset($in['confirm']) && is_string($in['confirm']) ? strtoupper(trim($in['confirm'])) : '';

if ($confirm !== 'DELETE') {
    api_error('يرجى تأكيد الحذف بكتابة كلمة DELETE.', 400);
}
if ($password === '') {
    api_error('يرجى إدخال كلمة المرور للتأكيد.', 400);
}

$type = $auth['type'] === 'lawyer' ? 'lawyer' : 'client';
$result = account_delete($pdo, $type, (int)$auth['id'], $password);

if (!$result['ok']) {
    switch ($result['error']) {
        case 'wrong_password':
            api_error('كلمة المرور غير صحيحة.', 401);
            break;
        case 'has_open_work':
            api_json([
                'ok' => false,
                'error' => 'has_open_work',
                'message' => 'لا يمكن حذف الحساب الآن لوجود أعمال قائمة.',
                'blockers' => $result['blockers'] ?? [],
            ], 409);
            break;
        case 'account_not_found':
            api_error('الحساب غير موجود.', 404);
            break;
        default:
            api_error('تعذّر حذف الحساب. يرجى المحاولة لاحقاً أو التواصل مع الدعم.', 500);
    }
}

api_json(['ok' => true, 'deleted' => true]);
