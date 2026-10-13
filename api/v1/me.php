<?php
/**
 * GET /api/v1/me.php
 * headers: Authorization: Bearer {token}
 * response: { ok, type: "client"|"lawyer", profile: {...}, role, firm_id, firm_name, permissions }
 *
 * `role`, `firm_id`, `firm_name` and `permissions` come from the same tenant tables the web
 * app uses (includes/tenant.php). The mobile client uses them only to decide what to show;
 * every endpoint still enforces the capability server-side. A solo lawyer reports role
 * "solo" with their full practice capabilities; a firm member reports their membership role
 * and effective capabilities.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';

$auth = api_authenticate($pdo);

if ($auth['type'] === 'client') {
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, account_type, preferred_lang, created_at FROM users WHERE id=?');
    $stmt->execute([$auth['id']]);
    api_json([
        'ok' => true,
        'type' => 'client',
        'profile' => $stmt->fetch(),
        'role' => 'client',
        'firm_id' => null,
        'firm_name' => null,
        'permissions' => [],
    ]);
}

$lawyerId = (int)$auth['id'];
$stmt = $pdo->prepare('SELECT id, full_name, email, phone, specialization, years_experience, hourly_rate, rating, bio, photo_path, jurisdiction FROM lawyers WHERE id=?');
$stmt->execute([$lawyerId]);
$profile = $stmt->fetch();

$ctx = tenant_context_for_lawyer($pdo, $lawyerId);
$firmName = null;
if ($ctx['is_firm']) {
    $firm = $pdo->prepare('SELECT COALESCE(display_name, name) FROM law_firms WHERE id=?');
    $firm->execute([$ctx['firm_id']]);
    $firmName = $firm->fetchColumn() ?: null;
}

api_json([
    'ok' => true,
    'type' => 'lawyer',
    'profile' => $profile,
    'role' => $ctx['is_firm'] ? ($ctx['role'] ?? 'lawyer') : 'solo',
    'firm_id' => $ctx['firm_id'],
    'firm_name' => $firmName,
    'permissions' => tenant_permissions_for_lawyer($pdo, $lawyerId),
]);
