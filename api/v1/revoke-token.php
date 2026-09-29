<?php
require_once __DIR__ . '/bootstrap.php';
$auth = api_authenticate($pdo);
$stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=? AND user_type=? AND user_id=? AND revoked_at IS NULL');
$stmt->execute([$auth['token_id'],$auth['type'],$auth['id']]);
api_json(['ok'=>true,'message'=>'token revoked']);
