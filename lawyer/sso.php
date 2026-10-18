<?php
/**
 * Enterprise SSO (SAML 2.0) configuration for the firm workspace.
 *
 * Owner/partner only. Shows the SP metadata URL and ACS URL the firm's IT team registers with
 * their IdP, and takes the IdP entity id, SSO URL and signing certificate. The page states
 * plainly when the SAML library is unavailable rather than pretending SSO works.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/sso.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$role = (string)(tenant_current($pdo)['role'] ?? '');
$canManage = in_array($role, ['owner', 'partner'], true);
$error = null; $success = null;

if ($firmId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    if (!$canManage) {
        http_response_code(403);
        $error = is_rtl() ? 'هذه العملية متاحة لمالك المكتب أو شريك فقط.' : 'Only an owner or partner may do this.';
    } else {
        $r = sso_save_config($pdo, $firmId, $_POST, $lawyerId);
        if (empty($r['ok'])) {
            $msgs = [
                'idp_sso_url_must_be_https' => is_rtl() ? 'يجب أن يبدأ رابط الدخول بـ https.' : 'The SSO URL must start with https.',
                'idp_cert_not_pem' => is_rtl() ? 'شهادة المُعرِّف ليست بصيغة PEM.' : 'The IdP certificate is not in PEM format.',
            ];
            $error = $msgs[$r['error']] ?? (is_rtl() ? 'تعذّر الحفظ.' : 'Could not save.');
        } else {
            $success = is_rtl() ? 'تم حفظ إعدادات الدخول الموحّد.' : 'SSO settings saved.';
        }
    }
}

$activeLawyer = 'sso';
require_once __DIR__ . '/includes/lawyer_header.php';

if (!$firmId) {
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'الدخول الموحّد' : 'Single sign-on') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

$config = sso_load_config($pdo, $firmId);
$libOk = sso_library_available();
$ready = $config ? sso_config_ready($config) : ['ok' => false, 'reason' => 'no_config'];
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $host;
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'الدخول الموحّد (SAML 2.0)' : 'Single sign-on (SAML 2.0)',
      is_rtl() ? 'اربط مساحة عمل المكتب بمزوّد الهوية الخاص بالمؤسسة.' : 'Connect the firm workspace to the organisation\'s identity provider.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <?php if (!$libOk): ?>
    <div class="form-alert error"><?= is_rtl()
        ? 'مكتبة SAML غير مثبّتة على هذا السيرفر، لذا لا يمكن تشغيل الدخول الموحّد. شغّل composer install ثم أعد المحاولة.'
        : 'The SAML library is not installed on this server, so single sign-on cannot run. Run composer install and try again.' ?></div>
  <?php endif; ?>

  <section class="dash-card">
    <h2><?= is_rtl() ? 'بيانات مواثيق (Service Provider)' : 'Mawatheq identity (Service Provider)' ?></h2>
    <p><?= is_rtl() ? 'سلّم هذه القيم إلى فريق تقنية المعلومات لتسجيل المنصة لدى مزوّد الهوية.' : 'Hand these values to the IT team to register this platform with the identity provider.' ?></p>
    <table class="admin-table">
      <tbody>
        <tr><th>ACS URL</th><td><code><?= ui_e($base . '/sso/acs.php?firm=' . $firmId) ?></code></td></tr>
        <tr><th><?= is_rtl() ? 'رابط الميتاداتا' : 'Metadata URL' ?></th><td><code><?= ui_e($base . '/sso/metadata.php?firm=' . $firmId) ?></code></td></tr>
        <tr><th>Entity ID</th><td><code><?= ui_e(trim((string)($config['sp_entity_id'] ?? '')) ?: $base . '/sso/metadata.php') ?></code></td></tr>
      </tbody>
    </table>
  </section>

  <section class="dash-card">
    <h2><?= is_rtl() ? 'إعدادات مزوّد الهوية' : 'Identity provider settings' ?></h2>
    <?php if ($config && !empty($config['enabled'])): ?>
      <p><?= is_rtl() ? 'الحالة:' : 'Status:' ?>
        <?= ui_badge(!empty($ready['ok']) ? 'success' : 'danger', !empty($ready['ok'])
            ? (is_rtl() ? 'مكتمل ومُفعّل' : 'Complete and enabled')
            : (is_rtl() ? 'ناقص: ' : 'Incomplete: ') . ui_e((string)$ready['reason'])) ?></p>
    <?php endif; ?>

    <?php if ($canManage): ?>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <label class="check-row"><input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>> <?= is_rtl() ? 'تفعيل الدخول الموحّد لهذا المكتب' : 'Enable SSO for this firm' ?></label>
        <label><?= is_rtl() ? 'الاسم المعروض' : 'Display name' ?><input type="text" name="display_name" maxlength="190" value="<?= ui_e((string)($config['display_name'] ?? '')) ?>" placeholder="Okta / Entra ID"></label>
        <label>IdP Entity ID<input type="text" name="idp_entity_id" maxlength="500" value="<?= ui_e((string)($config['idp_entity_id'] ?? '')) ?>" placeholder="https://idp.example.com/metadata"></label>
        <label>IdP SSO URL (https)<input type="url" name="idp_sso_url" maxlength="500" value="<?= ui_e((string)($config['idp_sso_url'] ?? '')) ?>" placeholder="https://idp.example.com/sso"></label>
        <label>IdP SLO URL<input type="url" name="idp_slo_url" maxlength="500" value="<?= ui_e((string)($config['idp_slo_url'] ?? '')) ?>"></label>
        <label><?= is_rtl() ? 'شهادة التوقيع (PEM)' : 'Signing certificate (PEM)' ?><textarea name="idp_x509_cert" rows="6" placeholder="-----BEGIN CERTIFICATE-----"><?= ui_e((string)($config['idp_x509_cert'] ?? '')) ?></textarea></label>
        <label><?= is_rtl() ? 'النطاقات المسموحة (مفصولة بفواصل)' : 'Allowed email domains (comma-separated)' ?><input type="text" name="allowed_domains" maxlength="500" value="<?= ui_e((string)($config['allowed_domains'] ?? '')) ?>" placeholder="firm.example.com"></label>
        <label><?= is_rtl() ? 'خاصية البريد' : 'Email attribute' ?><input type="text" name="attribute_email" maxlength="120" value="<?= ui_e((string)($config['attribute_email'] ?? 'email')) ?>"></label>
        <label><?= is_rtl() ? 'خاصية الاسم' : 'Name attribute' ?><input type="text" name="attribute_name" maxlength="120" value="<?= ui_e((string)($config['attribute_name'] ?? 'displayName')) ?>"></label>
        <label class="check-row"><input type="checkbox" name="require_signed_response" value="1" <?= (!isset($config['require_signed_response']) || !empty($config['require_signed_response'])) ? 'checked' : '' ?>> <?= is_rtl() ? 'اشتراط توقيع الرد (موصى به)' : 'Require a signed response (recommended)' ?></label>
        <label class="check-row"><input type="checkbox" name="jit_provisioning" value="1" <?= !empty($config['jit_provisioning']) ? 'checked' : '' ?>> <?= is_rtl() ? 'إنشاء الحساب تلقائياً بصلاحية «محامي» عند أول دخول' : 'Create the account automatically as "lawyer" on first sign-in' ?></label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
      </form>
    <?php else: ?>
      <p><?= is_rtl() ? 'عرض فقط: التعديل متاح لمالك المكتب أو شريك.' : 'Read-only: only an owner or partner may change these settings.' ?></p>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php';
