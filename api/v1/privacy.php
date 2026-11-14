<?php
/**
 * /api/v1/privacy.php — خصوصية البيانات (يحتاج توكن)
 *
 * Backs the account screen's privacy section: the customer's consent preferences,
 * the policy/data links, and the deletion-workflow description. Account deletion
 * itself lives at /api/v1/account/delete.php and is not duplicated here.
 *
 *   GET  ?action=consents   → my consent preferences (defaults to not-granted)
 *   GET  ?action=info       → policy/data URLs + deletion consequences (public text)
 *   POST action=set-consent { key, granted }
 *
 * The consent keys are a fixed allow-list. An unknown key is refused rather than
 * stored, so the table cannot accumulate keys nothing reads.
 */
require_once __DIR__ . '/bootstrap.php';

/** The consent preferences the app can set, with their labels. */
function privacy_consent_keys(): array {
    return [
        'analytics'          => ['ar' => 'تحليلات الاستخدام', 'en' => 'Usage analytics'],
        'product_updates'    => ['ar' => 'تحديثات المنتج والعروض', 'en' => 'Product updates and offers'],
        'ai_processing'      => ['ar' => 'معالجة المستندات بالذكاء الاصطناعي', 'en' => 'AI document processing'],
    ];
}

$action = (string)($_GET['action'] ?? 'consents');
$site = rtrim(defined('SITE_URL') ? SITE_URL : 'https://mawatheq.com', '/');

// The policy text and links are the same for everyone; no token is needed to read
// them, and Google Play's listing links straight to the policy page.
if ($action === 'info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    api_json([
        'ok' => true,
        'privacy_url' => $site . '/privacy.php',
        'terms_url'   => $site . '/terms.php',
        'account_url' => $site . '/account.php',
        // Stated plainly, because a customer about to delete an account must know
        // what survives: financial/legal records tied to other parties are kept
        // without the personal data, exactly as privacy.php promises.
        'deletion' => [
            'deletes'  => ['الاسم', 'البريد الإلكتروني', 'الجوال', 'كلمة المرور', 'أجهزة الدخول'],
            'keeps'    => ['الفواتير الصادرة', 'الاستشارات المكتملة', 'السجلات المالية'],
        ],
    ]);
}

$auth = api_authenticate($pdo);
if ($auth['type'] === 'integration') api_error('لا تملك صلاحية الوصول إلى هذا العنصر.', 403);
$userType = $auth['type'] === 'lawyer' ? 'lawyer' : 'client';
$userId = (int)$auth['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT consent_key, granted FROM user_consents WHERE user_type=? AND user_id=?');
    $stmt->execute([$userType, $userId]);
    $stored = [];
    foreach ($stmt->fetchAll() as $row) $stored[$row['consent_key']] = (bool)(int)$row['granted'];

    $keys = privacy_consent_keys();
    $out = [];
    foreach ($keys as $key => $labels) {
        // Absent means "not granted", never "granted by default".
        $out[] = ['key' => $key, 'label_ar' => $labels['ar'], 'label_en' => $labels['en'],
                  'granted' => $stored[$key] ?? false];
    }
    api_json(['ok' => true, 'consents' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    // Accept the action from the JSON body as well as the query string, matching
    // support.php. The app posts `action` in the body, so a query-only read made
    // every consent write answer "unknown action" while the endpoint looked fine.
    $action = (string)($in['action'] ?? $action);
    if ($action !== 'set-consent') api_error('unknown action', 400);

    $key = (string)($in['key'] ?? '');
    $granted = !empty($in['granted']) ? 1 : 0;
    if (!array_key_exists($key, privacy_consent_keys())) api_error('مفتاح موافقة غير معروف.', 400);

    $pdo->prepare('INSERT INTO user_consents (user_type, user_id, consent_key, granted, granted_at, revoked_at)
        VALUES (?,?,?,?, ' . ($granted ? 'NOW()' : 'NULL') . ', ' . ($granted ? 'NULL' : 'NOW()') . ')
        ON DUPLICATE KEY UPDATE granted=VALUES(granted), granted_at=VALUES(granted_at), revoked_at=VALUES(revoked_at)')
        ->execute([$userType, $userId, $key, $granted]);

    api_json(['ok' => true, 'key' => $key, 'granted' => (bool)$granted]);
}

api_error('method not allowed', 405);
