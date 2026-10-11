<?php
/**
 * AI operations and governance (Executive 08).
 *
 * Platform-level view of the legal AI stack: operational health and usage, resilience knobs,
 * the privacy gate, and the human-review backlog a platform admin needs to keep clean.
 *
 * Writes are super-admin only and limited to the resilience knobs, which are the same
 * site_settings keys the gateway already reads (ai_max_retries, ai_timeout_seconds,
 * ai_rate_limit_per_hour) — so a value entered here takes effect on the next call with no
 * redeploy.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/ai_resilience.php';
require_once __DIR__ . '/../includes/ai_copilot.php';
require_once __DIR__ . '/../includes/branch_access.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/secret_crypto.php';
require_once __DIR__ . '/../includes/ui.php';

$activeAdmin = 'ai-operations';
$saved = false; $error = null;
$settings = ai_load_settings($pdo);
function ai_ops_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_resilience') {
    if (($_SESSION['admin_role'] ?? '') !== 'super_admin') { http_response_code(403); exit('Forbidden'); }
    $timeout = max(5, min(120, (int)($_POST['ai_timeout_seconds'] ?? 25)));
    $retries = max(0, min(4, (int)($_POST['ai_max_retries'] ?? 2)));
    $rate = max(1, min(100000, (int)($_POST['ai_rate_limit_per_hour'] ?? 120)));
    ai_ops_setting($pdo, 'ai_timeout_seconds', (string)$timeout);
    ai_ops_setting($pdo, 'ai_max_retries', (string)$retries);
    ai_ops_setting($pdo, 'ai_rate_limit_per_hour', (string)$rate);
    log_action($pdo, 'تحديث إعدادات مرونة الذكاء الاصطناعي (timeout=' . $timeout . ', retries=' . $retries . ', rate=' . $rate . ')', 'site_settings', 0);
    $saved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'health_check') {
    if (($_SESSION['admin_role'] ?? '') !== 'super_admin') { http_response_code(403); exit('Forbidden'); }
    $r = ai_provider_health_probe($pdo, (string)($settings['ai_provider'] ?? ''), (string)($settings['ai_api_key'] ?? ''), (string)($settings['ai_model'] ?? ''), (string)($settings['ai_endpoint'] ?? ''), (int)($_SESSION['admin_id'] ?? 0));
    log_action($pdo, 'فحص صحة مزوّد الذكاء الاصطناعي', 'ai_provider_health', null);
    $saved = true;
}

require_once __DIR__ . '/includes/admin_header.php';

$health = [];
try { $health = ai_provider_status_snapshot($pdo); } catch (Throwable $e) {}
$diag = null;
if (isset($_GET['diag']) && $_GET['diag'] === '1') {
    try { $diag = ai_diagnostics($pdo, $settings, true); } catch (Throwable $e) { $diag = null; }
}
$diagLabels = [
    'disabled' => is_rtl() ? 'التكامل موقوف من المفتاح العام (ai_enabled = 0).' : 'Integration is switched off (ai_enabled = 0).',
    'no_provider' => is_rtl() ? 'لم يُختر أي مزوّد بعد.' : 'No provider has been selected yet.',
    'provider_unsupported' => is_rtl() ? 'المزوّد المختار غير مدعوم في هذا الإصدار.' : 'The selected provider is not supported by this build.',
    'no_endpoint' => is_rtl() ? 'المزوّد المحلي يحتاج عنوان خادم صالحاً (http/https).' : 'The self-hosted provider needs a valid endpoint (http/https).',
    'no_credentials' => is_rtl() ? 'مزوّد مستضاف يحتاج مفتاح API محفوظاً.' : 'A hosted provider needs a saved API key.',
    'unreachable' => is_rtl() ? 'الإعداد سليم لكن الخادم غير قابل للوصول من هذا المضيف.' : 'Configuration is valid, but the server is unreachable from this host.',
];
$usageTotal = 0; $usage24h = 0; $usage7d = 0; $usageByProvider = []; $localOnlyTenants = 0;
try {
    $usageTotal = (int)$pdo->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn();
    $usage24h = (int)$pdo->query("SELECT COUNT(*) FROM ai_usage_events WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
    $usage7d = (int)$pdo->query("SELECT COUNT(*) FROM ai_usage_events WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
    $usageByProvider = $pdo->query("SELECT provider, COUNT(*) AS n, COALESCE(SUM(success=0),0) AS fails FROM ai_usage_events WHERE created_at >= (NOW() - INTERVAL 7 DAY) GROUP BY provider ORDER BY n DESC")->fetchAll();
} catch (Throwable $e) {}
try { $localOnlyTenants = (int)$pdo->query("SELECT COUNT(*) FROM ai_tenant_settings WHERE privacy_mode IN ('local_only','external_disabled')")->fetchColumn(); } catch (Throwable $e) {}
$transmissions = [];
try {
    $transmissions = $pdo->query("SELECT * FROM ai_external_transmissions ORDER BY id DESC LIMIT 20")->fetchAll();
} catch (Throwable $e) {}
$pendingReviews = 0; $copilotByStatus = [];
try {
    $copilotByStatus = $pdo->query("SELECT status, COUNT(*) AS n FROM ai_copilot_runs GROUP BY status ORDER BY n DESC")->fetchAll();
    $pendingReviews = (int)$pdo->query("SELECT COUNT(*) FROM ai_copilot_runs WHERE requires_review=1 AND status='pending_review'")->fetchColumn();
} catch (Throwable $e) {}
$recentCopilot = [];
try {
    $recentCopilot = $pdo->query("SELECT r.id, r.kind, r.status, r.requires_review, r.citation_integrity_ok, r.created_at, c.title AS case_title
                                  FROM ai_copilot_runs r LEFT JOIN cases c ON c.id=r.case_id ORDER BY r.id DESC LIMIT 15")->fetchAll();
} catch (Throwable $e) {}
$conflictOpen = 0; $conflictCritical = 0;
try {
    $conflictOpen = (int)$pdo->query("SELECT COUNT(*) FROM conflict_reviews WHERE status IN ('open','under_review')")->fetchColumn();
    $conflictCritical = (int)$pdo->query("SELECT COUNT(*) FROM conflict_reviews WHERE status IN ('open','under_review') AND severity IN ('critical','high')")->fetchColumn();
} catch (Throwable $e) {}
$domainStats = ['active' => 0, 'pending' => 0];
try {
    $domainStats['active'] = (int)$pdo->query("SELECT COUNT(*) FROM firm_domains WHERE status='active'")->fetchColumn();
    $domainStats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM firm_domains WHERE status IN ('pending','verification_pending','verified')")->fetchColumn();
} catch (Throwable $e) {}

$platformPrivacy = 'external_disabled';
try {
    $pp = $pdo->query("SELECT privacy_mode FROM ai_tenant_settings WHERE tenant_type='platform' AND tenant_id=0")->fetchColumn();
    if ($pp) $platformPrivacy = (string)$pp;
} catch (Throwable $e) {}
?>
<div class="app-content">
  <?= ui_page_head(is_rtl() ? 'عمليات وحكامة الذكاء الاصطناعي' : 'AI operations & governance', is_rtl() ? 'صحة المزوّد، الاستخدام، إعدادات المرونة، بوابة الخصوصية، وقائمة المراجعة البشرية.' : 'Provider health, usage, resilience settings, the privacy gate, and the human-review backlog.', [], 'h1') ?>
  <?php if ($saved): ?><div class="form-alert success"><?= is_rtl() ? 'تم الحفظ وطُبّق فوراً على الطلبات التالية.' : 'Saved and effective on the next call.' ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>

  <div class="grid-3">
    <?= ui_stat(is_rtl() ? 'طلبات (24 ساعة)' : 'Calls (24h)', (string)$usage24h, 'navy') ?>
    <?= ui_stat(is_rtl() ? 'طلبات (7 أيام)' : 'Calls (7d)', (string)$usage7d, 'teal') ?>
    <?= ui_stat(is_rtl() ? 'إجمالي الطلبات' : 'Total calls', (string)$usageTotal, 'gold') ?>
    <?= ui_stat(is_rtl() ? 'مسودات بانتظار مراجعة بشرية' : 'Pending human review', (string)$pendingReviews, 'gold') ?>
    <?= ui_stat(is_rtl() ? 'مراجعات تعارض مفتوحة' : 'Open conflict reviews', (string)$conflictOpen, 'gold') ?>
    <?= ui_stat(is_rtl() ? 'تعارضات عالية الخطورة' : 'High-severity conflicts', (string)$conflictCritical, 'gold') ?>
  </div>

          <!-- ============ AI connectivity diagnostics ============ -->
        <section class="dash-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
            <h2><?= is_rtl() ? 'تشخيص اتصال الذكاء الاصطناعي' : 'AI connectivity diagnostics' ?></h2>
            <a class="btn btn-outline btn-sm" href="/admin/ai-operations.php?diag=1"><?= is_rtl() ? 'فحص الإعداد والوصول الآن' : 'Check config & reachability now' ?></a>
          </div>
          <p style="font-size:.85rem;margin-bottom:12px">
            <?= is_rtl()
              ? 'يعرض هذا القسم شرط الإعداد الذي يمنع التفعيل، ويفصل بين «غير مهيّأ» و«غير قابل للوصول». لا يعرض أي مفاتيح أو أسرار.'
              : 'This shows which configuration condition is blocking activation, and separates "not configured" from "not reachable". No keys or secrets are ever shown.' ?>
          </p>
          <?php if ($diag === null): ?>
            <?= ui_empty_state(is_rtl() ? 'لم يُجرَ الفحص بعد — اضغط «فحص الإعداد والوصول الآن».' : 'Not checked yet — press "Check config & reachability now".') ?>
          <?php else: ?>
            <?php $diagBadge = $diag['blocking'] === null ? 'success' : (($diag['blocking'] === 'unreachable') ? 'failed' : 'pending'); ?>
            <p style="margin-bottom:10px">
              <?= ui_badge($diagBadge, $diag['blocking'] === null
                  ? (is_rtl() ? 'جاهز — الاستدعاء الحقيقي نجح' : 'Ready — a real inference call succeeded')
                  : ($diagLabels[$diag['blocking']] ?? $diag['blocking'])) ?>
            </p>
            <table class="admin-table">
              <tbody>
                <tr><th><?= is_rtl() ? 'التكامل مفعّل' : 'Integration enabled' ?></th><td><?= ui_badge($diag['enabled'] ? 'success' : 'failed', $diag['enabled'] ? (is_rtl() ? 'نعم' : 'Yes') : (is_rtl() ? 'لا' : 'No')) ?></td></tr>
                <tr><th><?= is_rtl() ? 'المزوّد مختار' : 'Provider selected' ?></th><td><?= ui_badge($diag['provider_selected'] ? 'success' : 'failed', $diag['provider_selected'] ? ui_e($diag['provider']) : (is_rtl() ? 'لا' : 'No')) ?></td></tr>
                <tr><th><?= is_rtl() ? 'اسم النموذج محدّد' : 'Model name set' ?></th><td><?= ui_badge($diag['model_set'] ? 'success' : 'pending', $diag['model_set'] ? (is_rtl() ? 'نعم' : 'Yes') : (is_rtl() ? 'لا — سيُستخدم الافتراضي' : 'No — default will be used')) ?></td></tr>
                <tr><th><?= is_rtl() ? 'العنوان (endpoint) محدّد' : 'Endpoint set' ?></th><td><?= ui_badge($diag['endpoint_set'] ? 'success' : ($diag['provider'] === 'openai_compatible' ? 'failed' : 'neutral'), $diag['endpoint_set'] ? (is_rtl() ? 'نعم' : 'Yes') : (is_rtl() ? 'لا' : 'No')) ?></td></tr>
                <tr><th><?= is_rtl() ? 'المفتاح مطلوب / محفوظ' : 'Credentials required / present' ?></th><td><?= $diag['credentials_required'] ? ui_badge($diag['credentials_present'] ? 'success' : 'failed', $diag['credentials_present'] ? (is_rtl() ? 'محفوظ' : 'Present') : (is_rtl() ? 'غير محفوظ' : 'Missing')) : ui_badge('neutral', is_rtl() ? 'غير مطلوب لهذا المزوّد' : 'Not required for this provider') ?></td></tr>
                <tr><th><?= is_rtl() ? 'الإعداد صالح للاستخدام' : 'Configuration usable' ?></th><td><?= ui_badge($diag['configured'] ? 'success' : 'failed', $diag['configured'] ? (is_rtl() ? 'نعم' : 'Yes') : (is_rtl() ? 'لا' : 'No')) ?></td></tr>
                <tr><th><?= is_rtl() ? 'قابل للوصول (طلب حقيقي)' : 'Reachable (real request)' ?></th><td><?= ui_badge($diag['reachable'] ? 'success' : 'failed', $diag['reachable'] ? (is_rtl() ? 'نعم' : 'Yes') : (is_rtl() ? 'لا' : 'No')) ?></td></tr>
                <tr><th><?= is_rtl() ? 'الشرط المانع للتفعيل' : 'Blocking condition' ?></th><td><?= $diag['blocking'] === null ? ui_badge('success', is_rtl() ? 'لا يوجد' : 'None') : ui_e($diagLabels[$diag['blocking']] ?? $diag['blocking']) ?></td></tr>
                <?php if (!empty($diag['detail'])): ?>
                  <tr><th><?= is_rtl() ? 'تفاصيل الخطأ (مُنقّاة)' : 'Error detail (redacted)' ?></th><td><?= ui_e((string)$diag['detail']) ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>

        <!-- ============ Resilience settings ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'إعدادات المرونة' : 'Resilience settings' ?></h2>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="ai_resilience">
      <div class="grid-3">
        <label><?= is_rtl() ? 'مهلة الطلب (ثوانٍ)' : 'Request timeout (s)' ?><input type="number" name="ai_timeout_seconds" min="5" max="120" value="<?= (int)ai_request_timeout($settings) ?>"></label>
        <label><?= is_rtl() ? 'عدد المحاولات الإضافية' : 'Extra retries' ?><input type="number" name="ai_max_retries" min="0" max="4" value="<?= (int)ai_max_retries($settings) ?>"></label>
        <label><?= is_rtl() ? 'حد الطلبات/ساعة' : 'Calls per hour' ?><input type="number" name="ai_rate_limit_per_hour" min="1" max="100000" value="<?= (int)ai_rate_limit_per_hour($settings) ?>"></label>
      </div>
      <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
    </form>
  </section>

  <!-- ============ Provider health ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'صحة المزوّد' : 'Provider health' ?></h2>
    <form method="post" class="inline-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="health_check">
      <button type="submit" class="btn btn-outline btn-sm"><?= is_rtl() ? 'إجراء فحص الآن' : 'Probe now' ?></button>
    </form>
    <?php if ($health): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'المزود' : 'Provider' ?></th><th><?= is_rtl() ? 'الموديل' : 'Model' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'زمن الاستجابة' : 'Latency' ?></th><th><?= is_rtl() ? 'آخر فحص' : 'Last probe' ?></th><th><?= is_rtl() ? 'الخطأ' : 'Error' ?></th></tr></thead>
        <tbody>
          <?php foreach ($health as $h): ?>
            <tr>
              <td><?= ui_e((string)$h['provider']) ?></td>
              <td><?= ui_e((string)$h['model']) ?></td>
              <td><?= ui_badge((int)$h['ok'] === 1 ? 'success' : 'danger', (int)$h['ok'] === 1 ? (is_rtl() ? 'سليم' : 'OK') : (is_rtl() ? 'متوقف' : 'Down')) ?></td>
              <td><?= (int)$h['latency_ms'] ?> ms</td>
              <td><?= ui_e(ui_date($h['created_at'], true)) ?></td>
              <td><?= ui_e((string)($h['error_message'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد قياسات بعد' : 'No measurements yet') ?><?php endif; ?>

    <?php if ($usageByProvider): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'الاستخدام حسب المزود (7 أيام)' : 'Usage by provider (7d)' ?></h3>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'المزود' : 'Provider' ?></th><th><?= is_rtl() ? 'الطلبات' : 'Calls' ?></th><th><?= is_rtl() ? 'الفشل' : 'Failures' ?></th></tr></thead>
        <tbody>
          <?php foreach ($usageByProvider as $u): ?>
            <tr><td><?= ui_e((string)$u['provider']) ?></td><td><?= (int)$u['n'] ?></td><td><?= (int)$u['fails'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- ============ Privacy gate ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'بوابة الخصوصية' : 'Privacy gate' ?></h2>
    <p class="muted"><?= is_rtl() ? 'المكاتب في وضع محلي/خارجي معطّل لا تُرسل بياناتها لأي مزود خارجي.' : 'Tenants in local-only / external-disabled mode never send data to an external provider.' ?></p>
    <p><?= ui_badge($platformPrivacy === 'external_allowed' ? 'success' : 'pending', ai_privacy_mode_label($platformPrivacy, is_rtl())) ?></p>
    <div class="grid-2">
      <?= ui_stat(is_rtl() ? 'مكاتب مقيّدة (محلي فقط)' : 'Restricted tenants (local only)', (string)$localOnlyTenants, 'teal') ?>
      <?= ui_stat(is_rtl() ? 'عمليات إرسال خارجي مسجّلة' : 'Logged external transmissions', (string)count($transmissions), 'navy') ?>
    </div>
    <?php if ($transmissions): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'المكتب' : 'Firm' ?></th><th><?= is_rtl() ? 'المزود' : 'Provider' ?></th><th><?= is_rtl() ? 'المهمة' : 'Task' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'التاريخ' : 'When' ?></th></tr></thead>
        <tbody>
          <?php foreach ($transmissions as $t): ?>
            <tr>
              <td><?= ui_e((string)($t['firm_id'] ?? '—')) ?></td>
              <td><?= ui_e((string)($t['provider'] ?? '')) ?></td>
              <td><?= ui_e((string)($t['feature'] ?? $t['data_category'] ?? '')) ?></td>
              <td><?= ui_badge(($t['outcome'] ?? '') === 'allowed' ? 'success' : 'danger', (string)($t['outcome'] ?? '')) ?></td>
              <td><?= ui_e(ui_date($t['created_at'] ?? null, true)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- ============ Human review backlog ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'المراجعة البشرية' : 'Human review' ?></h2>
    <p class="muted"><?= is_rtl() ? 'كل مخرجات AI تُخزَّن بحالة «يحتاج مراجعة» حتى يعتمدها محامٍ. هذه القائمة للمتابعة، والاعتماد يتم من مكتب المحامي.' : 'Every AI output is stored as needs-review until a lawyer approves it. This list is for oversight; approval happens in the lawyer workspace.' ?></p>
    <?php if ($copilotByStatus): ?>
      <div class="grid-3">
        <?php foreach ($copilotByStatus as $s): ?>
          <?= ui_stat((string)$s['status'], (string)$s['n'], $s['status'] === 'pending_review' ? 'red' : 'teal') ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($recentCopilot): ?>
      <table class="admin-table">
        <thead><tr><th>#</th><th><?= is_rtl() ? 'القضية' : 'Case' ?></th><th><?= is_rtl() ? 'المهمة' : 'Task' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'الاستشهادات' : 'Citations' ?></th><th><?= is_rtl() ? 'التاريخ' : 'When' ?></th></tr></thead>
        <tbody>
          <?php foreach ($recentCopilot as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= ui_e((string)($r['case_title'] ?? '—')) ?></td>
              <td><?= ui_e(ai_copilot_kind_label((string)$r['kind'], is_rtl())) ?></td>
              <td><?= ui_badge($r['status'] === 'approved' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'neutral'), (string)$r['status']) ?></td>
              <td><?= ui_badge((int)$r['citation_integrity_ok'] === 1 ? 'success' : 'danger', (int)$r['citation_integrity_ok'] === 1 ? (is_rtl() ? 'سليمة' : 'Verified') : (is_rtl() ? 'غير مؤكدة' : 'Unverified')) ?></td>
              <td><?= ui_e(ui_date($r['created_at'], true)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد تشغيلات' : 'No runs') ?><?php endif; ?>
  </section>

  <!-- ============ Platform rollup ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'ملخص المنصة' : 'Platform rollup' ?></h2>
    <div class="grid-2">
      <?= ui_stat(is_rtl() ? 'نطاقات مخصصة مفعّلة' : 'Active custom domains', (string)$domainStats['active'], 'teal') ?>
      <?= ui_stat(is_rtl() ? 'نطاقات قيد التحقق' : 'Domains pending', (string)$domainStats['pending'], 'gold') ?>
    </div>
  </section>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php';
