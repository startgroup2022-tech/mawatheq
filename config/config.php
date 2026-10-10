<?php
/**
 * إعدادات الاتصال بقاعدة البيانات
 * عدّل القيم التالية بعد رفع المشروع على استضافة cPanel:
 * غالباً تكون بيانات قاعدة البيانات على شكل: username_dbname / username_dbuser
 */

// Put deployment-only credentials in config/secrets.php (never publish that file).
$secretsFile = __DIR__ . '/secrets.php';
if (is_file($secretsFile)) require_once $secretsFile;
if (!defined('DB_HOST')) define('DB_HOST', getenv('MAWATHEQ_DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('MAWATHEQ_DB_NAME') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('MAWATHEQ_DB_USER') ?: '');
if (!defined('DB_PASS')) define('DB_PASS', getenv('MAWATHEQ_DB_PASS') ?: '');

if (!defined('SITE_URL')) define('SITE_URL', getenv('MAWATHEQ_SITE_URL') ?: 'https://mawatheq.com');
if (!defined('SITE_NAME_AR')) define('SITE_NAME_AR', 'مواثيق');
if (!defined('SITE_NAME_EN')) define('SITE_NAME_EN', 'Mawatheq');

// البريد المرسل منه رسائل النظام (تفعيل الحساب، استعادة كلمة المرور...)
if (!defined('MAIL_FROM_EMAIL')) define('MAIL_FROM_EMAIL', getenv('MAWATHEQ_MAIL_FROM') ?: 'info@mawatheq.com');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'مواثيق');

// إعدادات مزوّد الذكاء الاصطناعي أصبحت تُدار من لوحة التحكم (الإعدادات)
// وتُخزَّن في جدول site_settings — لم تعد تُقرأ من هنا.
define('AI_API_KEY', '');
define('AI_API_PROVIDER', '');

// رفع الملفات
define('MAX_UPLOAD_SIZE_MB', 15);
define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('EVIDENCE_UPLOAD_DIR', __DIR__ . '/../uploads/evidence/');
define('LAWYER_PHOTO_DIR', __DIR__ . '/../uploads/lawyer-photos/');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('الخدمة غير متاحة مؤقتاً. يرجى المحاولة لاحقاً.');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle timeout for browser sessions; rotate the identifier after expiry.
$now=time();
if (!empty($_SESSION['_last_activity']) && $now-(int)$_SESSION['_last_activity']>1800) {
    $_SESSION=[]; session_regenerate_id(true);
}
$_SESSION['_last_activity']=$now;
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/currency.php';

// The client document library links to a case via `documents.case_id` (upgrade_v47).
// A cPanel install can run this code against a database that predates that migration;
// the guard self-heals the additive column so the client and admin document views
// never die with an unexplained 500. Same pattern as the jurisdiction column.
require_once __DIR__ . '/../includes/schema_guard.php';
schema_ensure_column($pdo, 'documents', 'case_id', 'INT UNSIGNED DEFAULT NULL');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), payment=()');
    header('Cache-Control: no-store, private');
    // Content-Security-Policy: allows same-origin assets plus the payment gateway iframe/redirect
    // and the AI/API hosts the app calls from the browser. Adjust script-src if new inline scripts
    // or third-party widgets are added; test thoroughly before tightening further (e.g. removing
    // 'unsafe-inline') since the current templates rely on inline <script>/<style> in places.
    if (!defined('CSP_REPORT_ONLY')) define('CSP_REPORT_ONLY', false);
    $cspDirectives = [
        "default-src 'self'",
        "img-src 'self' data: https:",
        "font-src 'self' data: https://fonts.gstatic.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "script-src 'self' 'unsafe-inline'",
        "connect-src 'self' https://api.anthropic.com https://api.openai.com",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "frame-src 'self' https://meet.jit.si",
        "form-action 'self' https://*.ngenius-payments.com",
        "object-src 'none'",
    ];
    $cspHeaderName = CSP_REPORT_ONLY ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    header($cspHeaderName . ': ' . implode('; ', $cspDirectives));
    if (($_SERVER['HTTPS'] ?? '')==='on' || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https')) header('Strict-Transport-Security: max-age=31536000');
}

// SameSite cookies plus a same-origin check block cross-site browser POSTs.
// The Bearer-token REST API (/api/v1/...) and the payment provider webhooks authenticate
// requests themselves, so they are exempt: they are server-to-server calls that cannot carry
// a CSRF token. The browser-facing /api/ helpers (ai_chat, upload_document, ...) run on the
// PHP session cookie, so they must be protected like any other page POST.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$csrfExemptPaths = ['/ngenius-webhook.php', '/sadad-webhook.php'];
$isBearerApi = strpos($requestPath, '/api/v1/') === 0;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$isBearerApi && !in_array($requestPath, $csrfExemptPaths, true)) {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source !== '') {
        $sourceHost = strtolower((string)parse_url($source, PHP_URL_HOST));
        $requestHost = strtolower((string)preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($sourceHost === '' || $requestHost === '' || !hash_equals($requestHost, $sourceHost)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

if (strpos($requestPath,'/admin/')===0 || strpos($requestPath,'/lawyer/')===0 || in_array($requestPath,['/account.php','/ai-assistant.php','/documents.php','/document-analysis.php','/consultations.php','/consultation-room.php','/case-tasks.php','/invoices.php','/draft-reviews.php'],true)) {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

// تتبع مصدر الزائر (حملة / قناة / صفحة الدخول) — أول لمسة فقط، لعرضها لاحقاً في لوحة التحكم
require_once __DIR__ . '/../includes/lead_source.php';
capture_lead_source();
