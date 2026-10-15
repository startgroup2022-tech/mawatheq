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
require_once __DIR__ . '/../../includes/schema_guard.php';
header('Content-Type: application/json; charset=utf-8');

// A database that was built from an older base and never received every
// upgrade_v*.sql makes a REST endpoint throw on a missing column. In production
// display_errors is off, so the mobile app received a bare 500 with an empty body
// and the failure was invisible. Repair the known additive columns up front, and
// answer with parseable JSON (never a blank body) if anything still goes wrong.
//
// Repair is opt-in (MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL=1). Off by default so an
// ordinary API request cannot alter the schema as a side effect; the exception
// handler below still turns a missing column into parseable JSON rather than a
// blank 500, and database/repair/00_preflight_check.php reports the drift.
if (getenv('MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL') === '1') {
    schema_ensure_column($pdo, 'lawyers', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'");
    schema_ensure_column($pdo, 'law_categories', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'");
}

set_exception_handler(function (Throwable $e): void {
    error_log('api/v1 uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal server error'], JSON_UNESCAPED_UNICODE);
    exit;
});
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

    // A scoped integration token is checked first. It grants read access to exactly the
    // firm resources it was issued for — it can never act as a logged-in human.
    require_once __DIR__ . '/../../includes/integrations.php';
    $integration = integration_token_find($pdo, $token);
    if ($integration) {
        api_integration_gate($integration);
        integration_token_touch($pdo, (int)$integration['id']);
        return ['type' => 'integration', 'id' => 0, 'firm_id' => $integration['firm_id'] !== null ? (int)$integration['firm_id'] : null, 'integration' => $integration];
    }

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
    // Upgrade a legacy plaintext token to its hash on first use. The WHERE clause repeats
    // the plaintext condition so the write only touches a row that is still plaintext —
    // otherwise a concurrent request could rewrite an already-hashed row and the token
    // would stop matching on the next call.
    if (hash_equals($token, (string)$row['token'])) {
        $pdo->prepare('UPDATE api_tokens SET token=? WHERE id=? AND token=?')
            ->execute([hash('sha256', $token), $row['id'], $token]);
    }
    $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return ['type' => $row['user_type'], 'id' => (int)$row['user_id'], 'token_id'=>(int)$row['id']];
}

/**
 * Endpoints an integration token may call at all, mapped to the scope each one needs.
 * Everything not listed is closed to integration tokens, so a leaked token cannot reach
 * account, notification, or AI endpoints. Enforced centrally from api_authenticate() rather
 * than per endpoint file, so a new endpoint added later is closed by default — the scope for
 * an allowed endpoint is declared here and nowhere else.
 */
function api_integration_endpoint_scopes(): array {
    return [
        'cases.php'     => 'cases:read',
        'invoices.php'  => 'invoices:read',
        'documents.php' => 'documents:read',
        'calendar.php'  => 'calendar:read',
    ];
}

/** Refuse an integration token on any endpoint outside the allow-list above. */
function api_integration_gate(array $integration): void {
    $endpoint = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $map = api_integration_endpoint_scopes();
    if (!isset($map[$endpoint])) {
        api_error('forbidden: this token cannot access ' . $endpoint, 403);
    }
    if (!integration_scope_allows($integration['scopes'] ?? '', $map[$endpoint])) {
        api_error('forbidden: this token lacks the "' . $map[$endpoint] . '" scope', 403);
    }
}
