<?php
require_once __DIR__ . '/bootstrap.php';
$auth = api_authenticate($pdo);
$input = api_input();
$label = isset($input['device_label']) ? mb_substr(trim((string)$input['device_label']),0,150,'UTF-8') : null;
$pdo->beginTransaction();
try {
    $token = api_issue_token($pdo,$auth['type'],$auth['id'],$label);
    $stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=? AND revoked_at IS NULL');
    $stmt->execute([$auth['token_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('API token rotation failed: '.$e->getMessage());
    api_error('Unable to rotate token',500);
}
api_json(['ok'=>true,'token'=>$token,'expires_in'=>31536000]);
