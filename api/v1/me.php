<?php
/**
 * GET /api/v1/me.php
 * headers: Authorization: Bearer {token}
 * response: { ok, type: "client"|"lawyer", profile: {...} }
 */
require_once __DIR__ . '/bootstrap.php';
$auth = api_authenticate($pdo);

if ($auth['type'] === 'client') {
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, account_type, preferred_lang, created_at FROM users WHERE id=?');
    $stmt->execute([$auth['id']]);
    api_json(['ok' => true, 'type' => 'client', 'profile' => $stmt->fetch()]);
} else {
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, specialization, years_experience, hourly_rate, rating, bio, photo_path, jurisdiction FROM lawyers WHERE id=?');
    $stmt->execute([$auth['id']]);
    api_json(['ok' => true, 'type' => 'lawyer', 'profile' => $stmt->fetch()]);
}
