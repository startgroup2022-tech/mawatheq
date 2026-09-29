<?php
/**
 * ============================================================
 * نقطة تحميل مشتركة لكل نقاط الواجهة البرمجية (REST API) v1.
 * يستخدمها تطبيق الجوال (Flutter) بدلاً من صفحات HTML المخصصة للمتصفح.
 * المصادقة: Bearer Token عبر ترويسة Authorization، وليس جلسة PHP.
 * ============================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lang/strings.php';
header('Content-Type: application/json; charset=utf-8');
$origin=$_SERVER['HTTP_ORIGIN'] ?? '';
$corsRaw=defined('API_CORS_ORIGINS')?API_CORS_ORIGINS:(getenv('MAWATHEQ_API_CORS_ORIGINS') ?: 'https://mawatheq.com,https://www.mawatheq.com');
$corsOrigins=array_values(array_filter(array_map('trim',explode(',',$corsRaw))));
if($origin!=='' && in_array($origin,$corsOrigins,true)) { header('Access-Control-Allow-Origin: '.$origin); header('Vary: Origin'); }
elseif($origin!=='' && ($_SERVER['REQUEST_METHOD'] ?? '')==='OPTIONS') { http_response_code(403); exit; }
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function api_json($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $code = 400): void {
    api_json(['ok' => false, 'error' => $message], $code);
}

function api_input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

/** يولّد رمز دخول جديد ويخزّنه، صالح لمدة سنة. */
function api_issue_token(PDO $pdo, string $userType, int $userId, ?string $deviceLabel = null): string {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $token), $userType, $userId, $deviceLabel]);
    return $token;
}

/**
 * يتحقق من رمز الدخول (Bearer Token) بترويسة Authorization، ويرجع بيانات
 * المستخدم المصادَق عليه، أو يوقف الطلب برمز 401 إن كان الرمز مفقوداً/غير صالح.
 * @return array{type:string, id:int}
 */
function api_authenticate(PDO $pdo): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        api_error('unauthorized: missing bearer token', 401);
    }
    $token = $m[1];
    // Accept existing plaintext tokens during the upgrade window; newly issued tokens are stored hashed.
    $stmt = $pdo->prepare('SELECT * FROM api_tokens WHERE token IN (?,?) AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
    $stmt->execute([hash('sha256', $token), $token]);
    $row = $stmt->fetch();
    if (!$row) {
        api_error('unauthorized: invalid or expired token', 401);
    }
    $table = $row['user_type'] === 'lawyer' ? 'lawyers' : 'users';
    $account = $pdo->prepare("SELECT status,email_verified FROM {$table} WHERE id=?");
    $account->execute([(int)$row['user_id']]);
    $profile = $account->fetch();
    if (!$profile || empty($profile['email_verified']) || ($row['user_type'] === 'client' && $profile['status'] !== 'active') || ($row['user_type'] === 'lawyer' && $profile['status'] !== 'approved')) {
        api_error('account is inactive or email is not verified', 403);
    }
    if (hash_equals($token, $row['token'])) {
        $pdo->prepare('UPDATE api_tokens SET token=? WHERE id=?')->execute([hash('sha256', $token), $row['id']]);
    }
    $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return ['type' => $row['user_type'], 'id' => (int)$row['user_id'], 'token_id'=>(int)$row['id']];
}
