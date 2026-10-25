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

// Site timezone. The platform is Saudi-first, so dates and times are computed in
// Riyadh rather than in whatever zone the server happens to run in — an unset PHP
// timezone silently follows the host, which shifted an invoice or an audit entry
// by three hours depending on the machine. Sits with the other deployment-level
// defaults (SITE_URL, SITE_NAME_*) and is overridable per install via the
// MAWATHEQ_TIMEZONE environment variable, like the database credentials.
if (!defined('SITE_TIMEZONE')) define('SITE_TIMEZONE', getenv('MAWATHEQ_TIMEZONE') ?: 'Asia/Riyadh');
date_default_timezone_set(SITE_TIMEZONE);

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
// When this session was established, used to discard sessions that predate a password
// change. Set once, then carried for the life of the session; the route guards compare
// it against the account's session_invalidated_at (see account_session_is_stale()).
$_SESSION['mawatheq_session_started_at'] ??= $now;
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/currency.php';
// Composer autoloader: optional, because a deployment that never enables SAML SSO does not
// need vendor/. Code that depends on it checks class_exists() rather than assuming it loaded.
$mawatheqAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($mawatheqAutoload)) require_once $mawatheqAutoload;

// The client document library links to a case via `documents.case_id` (upgrade_v47).
// A cPanel install can run this code against a database that predates that migration;
// the guard can self-heal the additive column so the client and admin document views
// never die with an unexplained 500. Same pattern as the jurisdiction column.
//
// Self-healing is off by default. Running DDL from an ordinary page request means the
// database is modified by whatever HTTP request happens to arrive first, concurrently,
// with no ordering against the migration files and no record of what was applied. That
// is what made the original failure hard to reason about: the schema changed as a side
// effect of browsing. The owner opts in during a deploy by setting
// MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL=1; otherwise a drifted database is surfaced by
// database/repair/00_preflight_check.php and fixed by the migration files, deliberately.
require_once __DIR__ . '/../includes/schema_guard.php';
if (getenv('MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL') === '1') {
    schema_ensure_column($pdo, 'documents', 'case_id', 'INT UNSIGNED DEFAULT NULL');
}

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
  // SAML endpoints are exempt because the IdP POSTs to them from its own origin and cannot
  // carry our CSRF token. Authenticity there is the XML signature (verified in sso/acs.php),
  // not a session token — the same reasoning as the payment webhooks above.
  $csrfExemptPaths = ['/ngenius-webhook.php', '/sadad-webhook.php', '/sso/acs.php', '/sso/sls.php'];

$isBearerApi = strpos($requestPath, '/api/v1/') === 0;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$isBearerApi && !in_array($requestPath, $csrfExemptPaths, true)) {
    // A body larger than post_max_size is discarded by PHP before any script runs, so $_POST
    // arrives empty and the token check below can never pass. Answering "Invalid request"
    // there sends the user hunting for a form bug; the real cause is the size. Detecting it
    // here means every upload form in the app reports the honest reason.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'code' => 'request_too_large',
            'error' => 'حجم الطلب تجاوز الحد المسموح على الخادم. ارفع الملفات واحداً واحداً أو صغّر حجمها.',
            'error_en' => 'The request exceeded the server size limit. Upload files one at a time or reduce their size.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

// Affiliate referral capture (Execution 31). Runs after the session is open and
// after the lead source is recorded, because a referral is a stricter, consent-aware
// form of the same idea and the two must not fight over the request.
//
// Guarded and fail-soft: the affiliate tables are absent on a database that has not
// run upgrade_v57.sql, and a referral that cannot be recorded must never stop a page
// from rendering. affiliate_track_request() returns early when there is no ?ref= and
// no visitor cookie, which is the overwhelmingly common case, so the cost on the hot
// path is two string checks.
if (is_file(__DIR__ . '/../includes/affiliate_tracking.php')) {
    try {
        require_once __DIR__ . '/../includes/affiliate_tracking.php';
        affiliate_track_request($pdo);
    } catch (Throwable $affiliateTrackError) {
        error_log('Affiliate tracking failed: ' . $affiliateTrackError->getMessage());
    }
}

/**
 * Append a content-derived version to a same-origin asset URL.
 *
 * Static assets are served with long cache lifetimes, and there is no build step that
 * renames them. After a stylesheet fix the browser therefore kept serving the old bytes
 * and the site looked unchanged — the "we deployed but nothing moved" report. The file's
 * mtime is the version, so any edit changes the URL and the browser fetches it once.
 *
 * Fails soft: if the file cannot be stat-ed (a partial upload, a read-permission problem)
 * the plain path is returned. A missing version only costs a cache revalidation; a fatal
 * here would cost the page.
 */
if (!function_exists('asset_url')) {
    function asset_url(string $path): string {
        $path = '/' . ltrim($path, '/');
        $absolute = __DIR__ . '/..' . $path;
        $mtime = @filemtime($absolute);
        if ($mtime === false) return $path;
        return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $mtime;
    }
}
