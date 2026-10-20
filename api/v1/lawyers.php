<?php
/**
 * GET /api/v1/lawyers.php?jurisdiction=..&specialization=..&category_id=..&q=..
 * لا يحتاج مصادقة (تصفح عام، مثل الموقع تماماً).
 * response: { ok, lawyers: [...] }
 *
 * `category_id` narrows to approved lawyers who actually provide that legal specialty
 * (the journey's "show me lawyers for my problem"). It is additive: a caller that does
 * not send it gets exactly the previous behaviour.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/legal_journey.php';

$categoryId = (int)($_GET['category_id'] ?? 0);
if ($categoryId > 0 && legal_journey_ready($pdo)) {
    // Only the lawyers linked to this specialty, with the same comparison fields the
    // journey shows. A specialty with no lawyer behind it returns an empty list.
    $lawyers = legal_journey_lawyers_for_specialty($pdo, $categoryId, [
        'jurisdiction' => $_GET['jurisdiction'] ?? '',
        'q'            => $_GET['q'] ?? '',
    ]);
    api_json(['ok' => true, 'lawyers' => $lawyers]);
}

$sql = "SELECT id, full_name, specialization, years_experience, hourly_rate, rating, bio, photo_path, jurisdiction FROM lawyers WHERE status='approved'";
$params = [];

if (!empty($_GET['jurisdiction'])) { $sql .= ' AND jurisdiction = ?'; $params[] = $_GET['jurisdiction']; }
if (!empty($_GET['specialization'])) { $sql .= ' AND specialization = ?'; $params[] = $_GET['specialization']; }
if (!empty($_GET['q'])) { $sql .= ' AND full_name LIKE ?'; $params[] = '%' . $_GET['q'] . '%'; }

$sql .= ' ORDER BY rating DESC LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

api_json(['ok' => true, 'lawyers' => $stmt->fetchAll()]);
