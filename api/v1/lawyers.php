<?php
/**
 * GET /api/v1/lawyers.php?jurisdiction=..&specialization=..&q=..
 * لا يحتاج مصادقة (تصفح عام، مثل الموقع تماماً).
 * response: { ok, lawyers: [...] }
 */
require_once __DIR__ . '/bootstrap.php';

$sql = "SELECT id, full_name, specialization, years_experience, hourly_rate, rating, bio, photo_path, jurisdiction FROM lawyers WHERE status='approved'";
$params = [];

if (!empty($_GET['jurisdiction'])) { $sql .= ' AND jurisdiction = ?'; $params[] = $_GET['jurisdiction']; }
if (!empty($_GET['specialization'])) { $sql .= ' AND specialization = ?'; $params[] = $_GET['specialization']; }
if (!empty($_GET['q'])) { $sql .= ' AND full_name LIKE ?'; $params[] = '%' . $_GET['q'] . '%'; }

$sql .= ' ORDER BY rating DESC LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

api_json(['ok' => true, 'lawyers' => $stmt->fetchAll()]);
