<?php
/**
 * ============================================================
 * مواثيق — فحص جاهزية الإنتاج (CLI فقط، للقراءة فقط).
 * ============================================================
 * يتحقق من أن السيرفر الذي نُشر عليه الكود يطابق النسخة المطلوبة: الإضافات،
 * الأعمدة، الجداول، الصلاحيات، والأسرار. لا يعدّل أي شيء ولا يطبع أي سر.
 *
 * الاستخدام على السيرفر:
 *   php tools/production_check.php
 * رمز الخروج 0 = كل الفحوص الحرجة ناجحة، 1 = يوجد فشل حرج.
 *
 * مهم: شغّله بعد كل تحديث. أشهر عطل إنتاجي هو قاعدة بيانات لم تُطبَّق عليها
 * الترقيات، فيرجع كل مسار مصادَق بـ Bearer خطأ 500 بدل 401 (العمود المفقود
 * يكسر استعلام api_tokens). هذا الفحص يكتشف ذلك قبل أن يراه المستخدم.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once __DIR__ . '/../config/config.php';

$fail = 0;
$warn = 0;

function line(string $label, string $status, string $detail = ''): void
{
    $tag = $status === 'OK' ? 'OK  ' : ($status === 'WARN' ? 'WARN' : 'FAIL');
    printf("  [%s] %-34s %s\n", $tag, $label, $detail);
}

function check(bool $ok, string $label, string $detail = '', bool $critical = true): void
{
    global $fail, $warn;
    if ($ok) {
        line($label, 'OK', $detail);
    } elseif ($critical) {
        $fail++;
        line($label, 'FAIL', $detail);
    } else {
        $warn++;
        line($label, 'WARN', $detail);
    }
}

echo "مواثيق — فحص جاهزية الإنتاج\n";
echo "التاريخ: " . date('Y-m-d H:i:s') . " | PHP: " . PHP_VERSION . " | SAPI: " . PHP_SAPI . "\n\n";

// 1) PHP extensions -------------------------------------------------------
echo "الإضافات (PHP extensions):\n";
foreach (['pdo_mysql', 'fileinfo', 'curl', 'mbstring', 'openssl', 'json'] as $ext) {
    check(extension_loaded($ext), $ext);
}
echo "\n";

// 2) Database connection --------------------------------------------------
echo "قاعدة البيانات:\n";
check(isset($pdo) && $pdo instanceof PDO, 'اتصال PDO', '', true);
$dbName = '';
try {
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    check($dbName !== '', 'اسم قاعدة البيانات', $dbName);
} catch (Throwable $e) {
    check(false, 'اسم قاعدة البيانات', 'تعذّر الاستعلام');
}

// 3) Critical columns the current code depends on -------------------------
// Each entry is [table, column]. Missing any of these breaks a live code path.
$requiredColumns = [
    ['api_tokens', 'revoked_at'],       // api_authenticate(): bearer requests 500 without it
    ['api_tokens', 'expires_at'],
    ['api_tokens', 'last_used_at'],
    ['users', 'email_verified'],
    ['users', 'preferred_lang'],
    ['users', 'account_type'],
    ['lawyers', 'jurisdiction'],
    ['law_categories', 'jurisdiction'],
    ['lawyers', 'email_verified'],
    ['audit_logs', 'actor_type'],
    ['invoices', 'firm_id'],
    ['cases', 'firm_id'],
    ['consultations', 'firm_id'],
    ['payment_transactions', 'gateway_transaction_id'],
    ['payment_transactions', 'currency'],
    ['case_analyses', 'structured_json'],
    ['documents', 'case_id'],
];
echo "\nأعمدة حرجة (schema drift):\n";
$missing = [];
foreach ($requiredColumns as [$table, $column]) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if (!$exists) {
        $missing[] = "$table.$column";
    }
    check($exists, "$table.$column");
}

// 4) Required tables ------------------------------------------------------
$requiredTables = [
    'admins', 'users', 'lawyers', 'law_firms', 'law_firm_members', 'cases', 'documents',
    'consultations', 'invoices', 'invoice_payments', 'plans', 'subscriptions', 'api_tokens',
    'auth_rate_limits', 'audit_logs', 'payment_transactions', 'tenant_gateway_settings',
    'site_settings', 'notifications', 'legal_calendar_events',
];
echo "\nجداول مطلوبة:\n";
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    check((int)$stmt->fetchColumn() > 0, $table);
}

// 4a) CRM module tables ---------------------------------------------------
// admin/crm.php shows a "needs a database upgrade" notice instead of a blank page
// when any of these is missing, so they are warnings rather than critical failures.
// upgrade_v40.sql creates the four crm_* tables; contact_leads comes from the base
// schema (schema.sql) and must already exist.
$crmTables = ['contact_leads', 'crm_companies', 'crm_opportunities', 'crm_activities', 'crm_tasks'];
echo "\nجداول وحدة CRM:\n";
$crmMissing = [];
foreach ($crmTables as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    $ok = (int)$stmt->fetchColumn() > 0;
    if (!$ok) { $crmMissing[] = $table; }
    check($ok, $table, '', false);
}
if ($crmMissing) {
    echo "  إصلاح: استورد database/upgrade_v40.sql (ينشئ جداول crm_*).\n";
    if (in_array('contact_leads', $crmMissing, true)) {
        echo "  contact_leads من المخطط الأساسي: استورد database/schema.sql.\n";
    }
}

// 4b) Additive-column repair script ---------------------------------------
// Every entry here is a purely additive column (ALTER TABLE ... ADD COLUMN) from a
// migration, so re-creating a missing one can never overwrite data. When any is
// absent we print the exact statements that repair it — what a cPanel install needs
// when it was built from an older base and v12 (or similar) was never applied. The
// script never drops or alters an existing column.
$repairable = [
    ['lawyers', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'"],
    ['law_categories', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'"],
];
$repairSql = [];
foreach ($repairable as [$table, $column, $definition]) {
    if (in_array("$table.$column", $missing, true)) {
        $repairSql[] = "ALTER TABLE `$table` ADD COLUMN `$column` $definition;";
    }
}
if ($repairSql) {
    echo "\nأعمدة قابلة للإصلاح (إضافية فقط — لا حذف ولا تعديل):\n";
    foreach ($repairSql as $sql) {
        echo "  " . $sql . "\n";
    }
}

// 5) Effective environment ------------------------------------------------
echo "\nالبيئة:\n";
$siteUrl = defined('SITE_URL') ? SITE_URL : '';
check(str_starts_with($siteUrl, 'https://'), 'SITE_URL عبر HTTPS', $siteUrl);
check(ini_get('display_errors') === '0' || ini_get('display_errors') === '' || ini_get('display_errors') === false, 'display_errors معطّل', 'display_errors=' . var_export(ini_get('display_errors'), true));
check(defined('ADMIN_SETUP_KEY') && strlen((string)ADMIN_SETUP_KEY) >= 32, 'ADMIN_SETUP_KEY مضبوط (>=32)', 'موجود');
check(defined('MAWATHEQ_ENCRYPTION_KEY') && strlen((string)MAWATHEQ_ENCRYPTION_KEY) >= 32, 'MAWATHEQ_ENCRYPTION_KEY مضبوط (>=32)', 'موجود');

// 6) Secrets file permissions --------------------------------------------
echo "\nملف الأسرار والصلاحيات:\n";
$secrets = __DIR__ . '/../config/secrets.php';
if (is_file($secrets)) {
    $perms = fileperms($secrets) & 0777;
    check($perms === 0600, 'config/secrets.php = 600', sprintf('الصلاحيات الحالية %04o', $perms), false);
    check(is_readable($secrets), 'config/secrets.php قابل للقراءة من PHP', 'نعم');
} else {
    check(false, 'config/secrets.php موجود', 'استخدم config/secrets.example.php', true);
}
$uploads = dirname(__DIR__) . '/uploads';
if (is_dir($uploads)) {
    $writable = is_writable($uploads);
    check($writable, 'uploads/ قابلة للكتابة (مطلوب للرفع)', '', false);
}

// 6b) Web-readable assets. Apache serves these as a user other than the file
// owner, so a 600/660 stylesheet or include returns 403 (CSS) or blanks the
// page (PHP include) while every page still reports HTTP 200. Only
// config/secrets.php is allowed to stay 600.
echo "\nصلاحيات الملفات القابلة للقراءة عبر الويب:\n";
$root = dirname(__DIR__);
$unreadable = [];
foreach (['assets/css', 'assets/js', 'admin/assets', 'includes', 'admin/includes', 'lawyer/includes'] as $rel) {
    $dir = $root . '/' . $rel;
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getFilename() === 'secrets.php') { continue; }
        if (($file->getPerms() & 0044) !== 0044) { $unreadable[] = $rel . '/' . $file->getFilename(); }
    }
}
check(!$unreadable, 'ملفات CSS/JS/الوحدات قابلة للقراءة (644)',
    $unreadable ? 'صلاحيات غير قابلة للقراءة: ' . implode(', ', array_slice($unreadable, 0, 8)) : 'نعم');
if ($unreadable) {
    echo "  إصلاح: chmod 644 على الملفات المذكورة (الصلاحية 600/660 تعني 403 لملفات CSS).\n";
}


// 7) First admin exists ---------------------------------------------------
echo "\nحساب المدير:\n";
$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
check($adminCount > 0, 'يوجد حساب مدير واحد على الأقل', "العدد: $adminCount");

// Summary -----------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
if ($fail === 0) {
    echo "النتيجة: كل الفحوص الحرجة ناجحة" . ($warn ? " (مع $warn تحذيرات)" : '') . "\n";
    if ($missing) {
        echo "أعمدة ناقصة (تحذير): " . implode(', ', $missing) . "\n";
    }
    exit(0);
}
echo "النتيجة: $fail فحص حرج فاشل — النظام غير جاهز للإنتاج.\n";
if ($missing) {
    echo "الأعمدة الناقصة: " . implode(', ', $missing) . "\n";
    echo "طبّق الترقيات المطلوبة: mysql < db> < database/upgrade_v27.sql (وما يليها حسب الحاجة).\n";
}
exit(1);
