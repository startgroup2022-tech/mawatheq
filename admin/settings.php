<?php
$activeAdmin = 'settings';
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/ngenius.php';
// The in-app calling module is optional in the same way smtp.php is: a deployment
// that has not shipped `includes/calls.php` (or has not run upgrade_v67) must still
// render this page. The card below is only drawn when the module is present.
$callsModuleFile = __DIR__ . '/../includes/calls.php';
$callsModuleAvailable = is_file($callsModuleFile);
if ($callsModuleAvailable) {
    require_once $callsModuleFile;
    $callsModuleAvailable = function_exists('call_provider_settings');
}
// The SMTP transport is an optional module: a deployment that did not ship `smtp.php`
// must still render this page and keep sending mail through the legacy mail() path.
$smtpModuleFile = __DIR__ . '/../includes/smtp.php';
$mailModuleAvailable = false;
if (is_file($smtpModuleFile)) {
    require_once $smtpModuleFile;
    $mailModuleAvailable = function_exists('smtp_settings');
}
$saved = false; $pwError = null; $secretError=null; $mailTestResult=null;

// Fallbacks so the page still renders (and reports the legacy transport) when the
// optional `includes/smtp.php` module is not present in the deployment.
if (!$mailModuleAvailable) {
    if (!function_exists('smtp_settings')) {
        function smtp_settings(PDO $pdo): array { return []; }
    }
    if (!function_exists('smtp_configured')) {
        function smtp_configured(array $settings): bool { return false; }
    }
    if (!function_exists('mail_diagnostics')) {
        function mail_diagnostics(PDO $pdo): array {
            $sendmailPath = (string)ini_get('sendmail_path');
            $binary = trim(strtok($sendmailPath, ' ') ?: '');
            return [
                'transport' => 'mail', 'smtp_enabled' => false, 'host' => '', 'port' => 0,
                'secure' => 'none', 'username_set' => false, 'password_set' => false,
                'from_email' => defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : '',
                'from_name' => defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'Mawatheq',
                'reachable' => null,
                'error' => ($binary === '' || !is_file($binary)) ? 'PHP mail() has no local sendmail binary to hand off to' : null,
            ];
        }
    }
}

/** حفظ آمن لأي مفتاح إعداد: يحدّث القيمة إن وُجد المفتاح، وإلا يُنشئه (يمنع فشل الحفظ الصامت). */
function save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settings') {
    foreach (['whatsapp_number','contact_email','site_name_ar','site_name_en'] as $key) {
        save_setting($pdo, $key, $_POST[$key] ?? '');
    }
    $newCurrency = currency_normalize($_POST['site_currency'] ?? '');
    save_setting($pdo, 'site_currency', $newCurrency);
    currency_forget_cache();
    log_action($pdo, 'تحديث إعدادات الموقع العامة');
    $saved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_settings') {
    $provider=in_array($_POST['ai_provider'] ?? '',['','openai','anthropic','openai_compatible'],true)?($_POST['ai_provider'] ?? ''):'';
    save_setting($pdo,'ai_provider',$provider); save_setting($pdo,'ai_model',trim($_POST['ai_model'] ?? ''));
    $newKey=trim($_POST['ai_api_key'] ?? '');
    if (isset($_POST['revoke_ai_key'])) { if(!secret_store_setting($pdo,'ai_api_key',''))$secretError='ضع مفتاح MAWATHEQ_ENCRYPTION_KEY بطول 32 حرفاً أو أكثر في config/secrets.php أولاً.'; }
    elseif($newKey!=='') { if(strlen($newKey)>500 || !secret_store_setting($pdo,'ai_api_key',$newKey))$secretError='تعذر تشفير المفتاح. اضبط MAWATHEQ_ENCRYPTION_KEY وتأكد من توفر OpenSSL.'; }
    foreach(['ai_backup_model','ai_max_tokens','ai_temperature','ai_timeout','ai_retries','ai_rate_limit'] as $key) if(isset($_POST[$key])) save_setting($pdo,$key,trim($_POST[$key]));
    // A self-hosted OpenAI-compatible base URL is validated before it is stored, so a
    // malformed value can never be saved and then pointed at an unintended host.
    if(isset($_POST['ai_endpoint'])){
        $ep=trim((string)$_POST['ai_endpoint']);
        if($ep!=='' && ai_endpoint_normalize($ep)===null)$secretError='عنوان المزوّد المحلي غير صالح؛ يجب أن يبدأ بـ http:// أو https:// ويتضمن مضيفاً صحيحاً.';
        else save_setting($pdo,'ai_endpoint',$ep);
    }
    save_setting($pdo, 'ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
    if(!$secretError){log_action($pdo,'تحديث إعدادات مزوّد الذكاء الاصطناعي (' . $provider . ')');$saved=true;}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'embedding_settings') {
    $embProvider = in_array($_POST['embedding_provider']??'', ['openai','openai_compatible','disabled'], true) ? $_POST['embedding_provider'] : '';
    $embModel = trim((string)($_POST['embedding_model'] ?? 'text-embedding-3-small'));
    if (!preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/',$embModel)) $embModel='text-embedding-3-small';
    save_setting($pdo,'embedding_provider',$embProvider); save_setting($pdo,'embedding_model',$embModel);
    if(isset($_POST['embedding_endpoint'])){
        $eep=trim((string)$_POST['embedding_endpoint']);
        if($eep!=='' && ai_endpoint_normalize($eep)===null)$secretError='عنوان خدمة المتجهات غير صالح؛ يجب أن يبدأ بـ http:// أو https://.';
        else save_setting($pdo,'embedding_endpoint',$eep);
    }
    $newEmbKey=trim((string)($_POST['embedding_api_key'] ?? ''));
    if (isset($_POST['revoke_embedding_key'])) { save_setting($pdo,'embedding_provider','disabled'); if(!secret_store_setting($pdo,'embedding_api_key',''))$secretError='اضبط MAWATHEQ_ENCRYPTION_KEY في config/secrets.php أولاً.'; }
    elseif($newEmbKey!=='') { if(strlen($newEmbKey)>500 || !secret_store_setting($pdo,'embedding_api_key',$newEmbKey))$secretError='تعذر تشفير مفتاح المتجهات؛ تحقق من مفتاح التشفير وOpenSSL.'; }
    if(!$secretError){log_action($pdo,'تحديث إعدادات مزوّد المتجهات');$saved=true;}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ngenius_settings') {
    save_setting($pdo, 'ngenius_environment', in_array($_POST['ngenius_environment'] ?? '', ['sandbox','production'], true) ? $_POST['ngenius_environment'] : 'sandbox');
    save_setting($pdo, 'ngenius_outlet_ref', trim($_POST['ngenius_outlet_ref'] ?? ''));
    $gatewayKey=trim($_POST['ngenius_api_key'] ?? ''); $webhookSecret=trim($_POST['ngenius_webhook_secret'] ?? '');
    if(isset($_POST['revoke_ngenius_keys'])) { secret_store_setting($pdo,'ngenius_api_key',''); secret_store_setting($pdo,'ngenius_webhook_secret',''); }
    else {
        if($gatewayKey!=='' && !secret_store_setting($pdo,'ngenius_api_key',$gatewayKey))$secretError='تعذر تشفير بيانات الدفع؛ اضبط مفتاح التشفير أولاً.';
        if($webhookSecret!=='' && !secret_store_setting($pdo,'ngenius_webhook_secret',$webhookSecret))$secretError='تعذر تشفير بيانات الدفع؛ اضبط مفتاح التشفير أولاً.';
    }
    save_setting($pdo, 'ngenius_realm_name', trim($_POST['ngenius_realm_name'] ?? 'ni'));
    $gatewayCurrency = currency_normalize($_POST['ngenius_currency'] ?? NGENIUS_DEFAULT_CURRENCY);
    save_setting($pdo, 'ngenius_currency', $gatewayCurrency);
    save_setting($pdo, 'ngenius_enabled', isset($_POST['ngenius_enabled']) ? '1' : '0');
    log_action($pdo, 'تحديث إعدادات بوابة الدفع N-Genius (' . ($_POST['ngenius_environment'] ?? '') . ')');
    if(!$secretError)$saved = true;
}

// In-app calling (voice/video). The TURN credential is stored encrypted, exactly
// like the gateway secrets above, so a database dump does not leak it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'call_settings' && $callsModuleAvailable) {
    save_setting($pdo, 'call_enabled', isset($_POST['call_enabled']) ? '1' : '0');
    save_setting($pdo, 'call_provider', in_array($_POST['call_provider'] ?? '', ['webrtc','jitsi'], true) ? $_POST['call_provider'] : 'webrtc');
    save_setting($pdo, 'call_stun_urls', trim((string)($_POST['call_stun_urls'] ?? '')));
    save_setting($pdo, 'call_turn_urls', trim((string)($_POST['call_turn_urls'] ?? '')));
    save_setting($pdo, 'call_turn_username', trim((string)($_POST['call_turn_username'] ?? '')));
    $turnCredential = trim((string)($_POST['call_turn_credential'] ?? ''));
    if (isset($_POST['revoke_call_turn_credential'])) {
        secret_store_setting($pdo, 'call_turn_credential', '');
    } elseif ($turnCredential !== '') {
        if (!secret_store_setting($pdo, 'call_turn_credential', $turnCredential)) {
            $secretError = 'تعذر تشفير بيانات TURN؛ اضبط MAWATHEQ_ENCRYPTION_KEY أولاً.';
        }
    }
    save_setting($pdo, 'call_ring_timeout_seconds', (string)max(10, min(180, (int)($_POST['call_ring_timeout_seconds'] ?? 45))));
    save_setting($pdo, 'call_max_duration_minutes', (string)max(5, min(480, (int)($_POST['call_max_duration_minutes'] ?? 120))));
    if (!$secretError) {
        log_action($pdo, 'تحديث إعدادات الاتصال الصوتي والمرئي');
        $saved = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    if (strlen($_POST['new_password'] ?? '') < 8) {
        $pwError = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
    } else {
        $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([$hash, $_SESSION['admin_id']]);
        log_action($pdo, 'تغيير كلمة مرور حساب الإدارة الخاص به');
        $saved = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mailer_settings') {
    save_setting($pdo, 'smtp_enabled', isset($_POST['smtp_enabled']) ? '1' : '0');
    save_setting($pdo, 'smtp_host', trim($_POST['smtp_host'] ?? ''));
    save_setting($pdo, 'smtp_port', (string)max(0, (int)($_POST['smtp_port'] ?? 0)));
    save_setting($pdo, 'smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['ssl','tls','none'], true) ? $_POST['smtp_secure'] : 'tls');
    save_setting($pdo, 'smtp_username', trim($_POST['smtp_username'] ?? ''));
    save_setting($pdo, 'smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
    save_setting($pdo, 'smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
    $smtpPassword = trim($_POST['smtp_password'] ?? '');
    if (isset($_POST['revoke_smtp_password'])) {
        secret_store_setting($pdo, 'smtp_password', '');
    } elseif ($smtpPassword !== '' && !secret_store_setting($pdo, 'smtp_password', $smtpPassword)) {
        $secretError = 'تعذر تشفير كلمة مرور البريد؛ اضبط مفتاح MAWATHEQ_ENCRYPTION_KEY في config/secrets.php أولاً.';
    }
    log_action($pdo, 'تحديث إعدادات إرسال البريد');
    if (!$secretError) $saved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mailer_test') {
    $target = trim($_POST['test_recipient'] ?? '');
    if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
        $mailTestResult = ['ok' => false, 'message' => 'أدخل بريداً إلكترونياً صحيحاً لاختبار الإرسال.'];
    } else {
        require_once __DIR__ . '/../includes/mailer.php';
        // Render through the same template used for real verification mail, so a
        // successful test exercises the true production path end to end.
        $html = render_mail_template(
            is_rtl() ? 'اختبار إرسال البريد' : 'Mail delivery test',
            is_rtl()
                ? '<p>هذه رسالة اختبار من لوحة تحكم مواثيق. إن وصلتك، فإرسال البريد يعمل بنجاح.</p>'
                : '<p>This is a test message from the Mawatheq admin panel. If you received it, outbound mail is working.</p>'
        );
        $subject = is_rtl() ? 'اختبار إرسال البريد - مواثيق' : 'Mawatheq mail delivery test';
        $ok = send_mail($target, $subject, $html);
        $mailTestResult = ['ok' => $ok, 'message' => $ok
            ? 'تم الإرسال بنجاح عبر ' . (mail_diagnostics($pdo)['transport'] === 'smtp' ? 'SMTP' : 'mail()') . ' — تحقق من صندوق الوارد والبريد المزعج.'
            : 'فشل الإرسال. السبب: ' . (mail_last_error() ?: 'غير معروف')];
    }
}

$settings = [];
foreach ($pdo->query('SELECT * FROM site_settings')->fetchAll() as $s) $settings[$s['setting_key']] = $s['setting_value'];
$aiSecureSettings=ai_load_settings($pdo); $settings=array_merge($settings,$aiSecureSettings);
$embSecureSettings=embedding_load_settings($pdo); $settings['embedding_provider']=$embSecureSettings['embedding_provider']; $settings['embedding_model']=$embSecureSettings['embedding_model'];
  $embKeyConfigured=($embSecureSettings['embedding_provider']==='openai'&&!empty($embSecureSettings['embedding_api_key']));
  $settings['embedding_endpoint']=$embSecureSettings['embedding_endpoint'] ?? ($settings['embedding_endpoint'] ?? '');
  $embLocalConfigured=($embSecureSettings['embedding_provider']==='openai_compatible'&&ai_endpoint_normalize($settings['embedding_endpoint'])!==null);
$embKnowledgePending=(int)$pdo->query("SELECT COUNT(*) FROM knowledge_sources WHERE status='active' AND processing_status='completed' AND embedding_status IN ('pending','not_configured','failed')")->fetchColumn();
$embLawPending=(int)$pdo->query('SELECT COUNT(*) FROM law_articles WHERE embedding IS NULL')->fetchColumn();
$ngeniusSecureSettings=ngenius_settings($pdo); $aiKeyConfigured=!empty($settings['ai_api_key']); $ngeniusKeyConfigured=!empty($ngeniusSecureSettings['ngenius_api_key']);
$settings['ngenius_api_key']=''; $settings['ngenius_webhook_secret']='';
$aiEnabledFlag = ($settings['ai_enabled'] ?? '1') !== '0';
  $aiConfigured = ai_settings_configured($settings);
$aiActive = $aiConfigured && $aiEnabledFlag;

$ngeniusConfigured = !empty($settings['ngenius_outlet_ref']) && $ngeniusKeyConfigured;
$ngeniusActive = ngenius_is_enabled($ngeniusSecureSettings);

$mailSettings = smtp_settings($pdo);
$mailDiag = mail_diagnostics($pdo);
$mailConfigured = smtp_configured($mailSettings);
$settings['smtp_password'] = '';

// Calling state. `call_provider_state()` is the single predicate the API also uses,
// so the badge here and the app's behaviour cannot disagree.
$callSettings = $callsModuleAvailable ? call_provider_settings($pdo) : null;
$callState = $callsModuleAvailable ? call_provider_state($pdo) : null;
$callTurnCredentialConfigured = $callsModuleAvailable && ($callSettings['call_turn_credential'] ?? '') !== '';
?>
<div class="app-content">
  <div class="pg-head"><div><h1>الإعدادات</h1><p>إعدادات المنصة، مزوّد الذكاء الاصطناعي، بوابة الدفع، وحسابك الشخصي</p></div></div>
  <?php if ($saved): ?><div class="form-alert success">تم الحفظ بنجاح — التغييرات سارية فوراً على النظام.</div><?php endif; ?>
  <?php if ($secretError): ?><div class="form-alert error"><?= htmlspecialchars($secretError) ?></div><?php endif; ?>

  <form class="dash-card" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="settings">
    <h3 style="margin-bottom:14px">إعدادات الموقع العامة</h3>
    <div class="grid-2">
      <div class="field"><label>اسم الموقع (عربي)</label><input type="text" name="site_name_ar" value="<?= htmlspecialchars($settings['site_name_ar'] ?? '') ?>"></div>
      <div class="field"><label>اسم الموقع (إنجليزي)</label><input type="text" name="site_name_en" value="<?= htmlspecialchars($settings['site_name_en'] ?? '') ?>"></div>
      <div class="field"><label>رقم واتساب (بدون +)</label><input type="text" name="whatsapp_number" value="<?= htmlspecialchars($settings['whatsapp_number'] ?? '') ?>"></div>
      <div class="field"><label>بريد التواصل</label><input type="email" name="contact_email" value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>"></div>
      <div class="field"><label>عملة الموقع</label>
        <select name="site_currency">
          <?php foreach (currency_options() as $code => $meta): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= currency_code() === $code ? 'selected' : '' ?>>
              <?= htmlspecialchars($meta['ar'] . ' (' . $meta['symbol_ar'] . ') — ' . $code) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="field-hint">تُستخدم في الأسعار والفواتير وكل المبالغ المعروضة على الموقع. العملة الافتراضية: ريال سعودي (SAR).</small>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">حفظ الإعدادات</button>
  </form>

  <form class="dash-card" method="post" id="mailerForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="mailer_settings">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <h3 style="margin:0">إرسال البريد (تفعيل الحساب واستعادة كلمة المرور)</h3>
      <span class="status-pill status-<?= $mailConfigured ? 'confirmed' : 'pending' ?>">
        <?= $mailConfigured ? '✓ SMTP موثّق' : '— mail() المحلي' ?>
      </span>
    </div>
    <p style="font-size:.85rem;margin-bottom:16px">
      ترسل المنصة رسائل تفعيل الحساب وإعادة تعيين كلمة المرور من بريد النظام. اضبط الإرسال عبر SMTP
      الخاص بمزوّد بريدك (نفس المزوّد الذي يملك سجلات SPF/DKIM لمجال موقعك) لضمان وصول الرسائل بدل
      الاعتماد على <code>mail()</code> المحلي الذي كثيراً ما يفشل بصمت على الاستضافة المشتركة.
      بيانات SMTP تجدها عند مزوّد البريد؛ وإن كان البريد مستضافاً في cPanel فاستخدم الخادم الذي
      يظهر في <b>Email Accounts ← Connect Devices</b>.
    </p>

    <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:700;font-size:.9rem">
      <input type="checkbox" name="smtp_enabled" value="1" <?= ($mailSettings['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
      إرسال الرسائل عبر SMTP الموثّق
    </label>

    <div class="grid-2">
      <div class="field">
        <label>خادم SMTP</label>
        <input type="text" name="smtp_host" value="<?= htmlspecialchars($mailSettings['smtp_host'] ?? '') ?>" placeholder="mail.mawatheq.com">
      </div>
      <div class="field">
        <label>المنفذ</label>
        <input type="number" name="smtp_port" value="<?= htmlspecialchars((string)($mailSettings['smtp_port'] ?? '587')) ?>" min="1" max="65535" placeholder="587">
      </div>
      <div class="field">
        <label>نوع التشفير</label>
        <div class="select-wrap"><select name="smtp_secure" class="pro-select">
          <?php $sec = $mailSettings['smtp_secure'] ?? 'tls'; ?>
          <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>STARTTLS (منفذ 587 — الأكثر استخداماً)</option>
          <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL/TLS (منفذ 465)</option>
          <option value="none" <?= $sec === 'none' ? 'selected' : '' ?>>بدون تشفير (خادم داخلي فقط)</option>
        </select></div>
      </div>
      <div class="field">
        <label>اسم المستخدم (البريد الكامل)</label>
        <input type="text" name="smtp_username" value="<?= htmlspecialchars($mailSettings['smtp_username'] ?? '') ?>" placeholder="info@mawatheq.com">
      </div>
      <div class="field">
        <label>كلمة مرور البريد</label>
        <input type="password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= ($mailSettings['smtp_password'] ?? '') !== '' ? 'محفوظة — أدخل قيمة جديدة للتدوير' : 'أدخل كلمة مرور البريد' ?>">
        <?php if(($mailSettings['smtp_password'] ?? '') !== ''): ?><label><input type="checkbox" name="revoke_smtp_password" value="1"> حذف كلمة المرور المحفوظة</label><?php endif; ?>
      </div>
      <div class="field">
        <label>بريد المُرسِل (From)</label>
        <input type="email" name="smtp_from_email" value="<?= htmlspecialchars($mailSettings['smtp_from_email'] ?? (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '')) ?>" placeholder="info@mawatheq.com">
        <p class="field-hint">يفضّل أن يكون نفس اسم مستخدم SMTP ليطابق نطاق SPF/DKIM.</p>
      </div>
      <div class="field">
        <label>اسم المُرسِل الظاهر</label>
        <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($mailSettings['smtp_from_name'] ?? 'مواثيق') ?>">
      </div>
    </div>

    <div class="dash-card" style="background:var(--surface-2,#f7f8fa);margin:6px 0 16px">
      <h4 style="margin:0 0 10px">تشخيص إرسال البريد</h4>
      <table class="table" style="width:100%">
        <tr><th style="text-align:start">وسيلة الإرسال الحالية</th><td><?= $mailDiag['transport'] === 'smtp' ? 'SMTP الموثّق' : 'mail() المحلي (sendmail/Exim)' ?></td></tr>
        <?php if ($mailDiag['transport'] === 'smtp'): ?>
          <tr><th style="text-align:start">الخادم</th><td><?= htmlspecialchars($mailDiag['host'] . ':' . $mailDiag['port'] . ' (' . $mailDiag['secure'] . ')') ?></td></tr>
          <tr><th style="text-align:start">بيانات الدخول</th><td><?= $mailDiag['username_set'] ? 'اسم مستخدم محفوظ' : 'بلا اسم مستخدم' ?> — <?= $mailDiag['password_set'] ? 'كلمة مرور محفوظة' : 'بلا كلمة مرور' ?></td></tr>
          <tr><th style="text-align:start">إمكانية الوصول للخادم</th><td><?= $mailDiag['reachable'] ? '✓ الخادم قابل للوصول' : ('✗ ' . htmlspecialchars((string)$mailDiag['error'])) ?></td></tr>
        <?php else: ?>
          <tr><th style="text-align:start">تسليم sendmail المحلي</th><td><?= $mailDiag['error'] ? ('⚠ ' . htmlspecialchars((string)$mailDiag['error'])) : 'المتاح' ?></td></tr>
        <?php endif; ?>
        <tr><th style="text-align:start">بريد المُرسِل الفعلي</th><td><?= htmlspecialchars($mailDiag['from_email']) ?> (<?= htmlspecialchars($mailDiag['from_name']) ?>)</td></tr>
      </table>
      <?php if ($mailDiag['transport'] !== 'smtp'): ?>
        <p class="field-hint" style="margin-top:10px">إن لم تصل رسائل التفعيل، فعّل SMTP أعلاه. تأكد أيضاً من سجلات SPF وDKIM للمجال (لمنع تصنيف الرسائل كبريد مزعج) ومن مجلد الرسائل غير المرغوبة.</p>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">حفظ إعدادات البريد</button>
    </div>
  </form>

  <form class="dash-card" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="mailer_test">
    <h3 style="margin-bottom:6px">إرسال رسالة اختبار</h3>
    <p style="font-size:.85rem;margin-bottom:14px">أرسل رسالة فعلية للتأكد من وصول البريد قبل تفعيل التسجيل للعملاء. تُستخدم نفس وسيلة الإرسال المحفوظة أعلاه.</p>
    <?php if ($mailTestResult !== null): ?>
      <div class="form-alert <?= $mailTestResult['ok'] ? 'success' : 'error' ?>"><?= htmlspecialchars($mailTestResult['message']) ?></div>
    <?php endif; ?>
    <div class="field" style="max-width:420px">
      <label>البريد المستلِم للاختبار</label>
      <input type="email" name="test_recipient" placeholder="you@example.com" required>
    </div>
    <button class="btn btn-outline" type="submit">إرسال رسالة اختبار</button>
  </form>

  <form class="dash-card" method="post" id="aiForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="ai_settings">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <h3 style="margin:0">ربط مزوّد الذكاء الاصطناعي</h3>
      <span class="status-pill status-<?= $aiActive ? 'confirmed' : 'pending' ?>">
        <?= $aiActive ? '✓ مفعّل ويعمل' : ($aiConfigured ? '⏸ موقوف يدوياً' : '— غير مربوط بعد') ?>
      </span>
    </div>
    <p style="font-size:.85rem;margin-bottom:16px">المزوّدان المدعومان حالياً بالمشروع فقط: <strong>Anthropic (Claude)</strong> و<strong>OpenAI (GPT)</strong>. لا تُستخدم أي خدمة أخرى. الصق مفتاح API الخاص بك واحفظ ليعمل المساعد فوراً.</p>
    <div class="form-alert" style="background:#EAF6F9;color:#1D6E82;font-size:.82rem">
      💡 لتقليل تكلفة التوكن: المساعد يبحث أولاً في مكتبة الأنظمة المحلية مجاناً، وإن وجد مادة ذات صلة يرسلها كسياق مختصر للنموذج بدل ترك النموذج "يبحث" بحرية.
    </div>

    <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:700;font-size:.9rem">
      <input type="checkbox" name="ai_enabled" value="1" <?= $aiEnabledFlag ? 'checked' : '' ?>>
      تفعيل التكامل (يمكنك إيقافه مؤقتاً هنا دون حذف المفتاح)
    </label>

    <div class="grid-2">
      <div class="field">
        <label>المزوّد</label>
        <div class="select-wrap">
        <select name="ai_provider" id="aiProvider" class="pro-select" onchange="syncModelOptions()">
          <option value="" <?= empty($settings['ai_provider']) ? 'selected' : '' ?>>— اختر —</option>
          <option value="anthropic" <?= ($settings['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude)</option>
          <option value="openai" <?= ($settings['ai_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI (GPT)</option>
          <option value="openai_compatible" <?= ($settings['ai_provider'] ?? '') === 'openai_compatible' ? 'selected' : '' ?>>نموذج محلي / متوافق مع OpenAI (vLLM · Ollama · llama.cpp)</option>
        </select>
        </div>
        <small class="field-hint">المزوّد المحلي لا يحتاج مفتاحاً ولا تُرسل بياناته خارج المنصة؛ يكفي ضبط عنوان الخادم أدناه.</small>
      </div>
      <div class="field">
        <label>نموذج جاهز (اختياري)</label>
        <div class="select-wrap">
        <select id="aiModelPreset" class="pro-select" onchange="document.getElementById('aiModelInput').value = this.value">
          <option value="">— اختر نموذجاً جاهزاً أو اكتب اسماً مخصصاً بالأسفل —</option>
          <optgroup label="Anthropic (Claude)" id="grp-anthropic">
            <option value="claude-3-5-haiku-latest">Claude 3.5 Haiku (أسرع وأرخص)</option>
            <option value="claude-3-5-sonnet-latest">Claude 3.5 Sonnet (متوازن)</option>
            <option value="claude-3-opus-latest">Claude 3 Opus (الأقوى)</option>
          </optgroup>
          <optgroup label="OpenAI (GPT)" id="grp-openai">
            <option value="gpt-4o-mini">GPT-4o mini (أسرع وأرخص)</option>
            <option value="gpt-4o">GPT-4o (متوازن)</option>
          </optgroup>
        </select>
        </div>
      </div>
    </div>
    <div class="field">
      <label>اسم النموذج المستخدم فعلياً</label>
      <input type="text" name="ai_model" id="aiModelInput" value="<?= htmlspecialchars($settings['ai_model'] ?? '') ?>" placeholder="مثال: claude-3-5-haiku-latest أو gpt-4o-mini">
    </div>
    <div class="field">
      <label>مفتاح API</label>
      <input type="password" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?= $aiKeyConfigured ? 'مفتاح محفوظ — أدخل قيمة جديدة للتدوير' : 'أدخل مفتاح API' ?>">
      <?php if($aiKeyConfigured): ?><label><input type="checkbox" name="revoke_ai_key" value="1"> إلغاء وحذف المفتاح المحفوظ</label><?php endif; ?>
      <small>المفتاح محفوظ مشفراً ولا يُعاد عرضه. أدخل مفتاحاً جديداً لتدويره. يتطلب ذلك MAWATHEQ_ENCRYPTION_KEY في config/secrets.php.</small>
    </div>
    <div class="field">
      <label>عنوان المزوّد المحلي / المتوافق مع OpenAI (اختياري)</label>
      <input type="text" name="ai_endpoint" dir="ltr" value="<?= htmlspecialchars($settings['ai_endpoint'] ?? '') ?>" placeholder="http://127.0.0.1:11434/v1">
      <small>يُستخدم فقط عند اختيار «نموذج محلي / متوافق مع OpenAI». أدخل العنوان الأساسي لخدمة v1 (vLLM · Ollama · llama.cpp). لا يُرسل أي مفتاح إلى خادم محلي.</small>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">حفظ</button>
      <button class="btn btn-outline" type="button" id="testBtn" onclick="testConnection()">اختبار الاتصال</button>
      <span id="testResult" style="font-size:.85rem"></span>
    </div>
    <p style="font-size:.78rem;color:var(--text-muted);margin-top:10px">بدون تفعيل، يستمر المساعد بالعمل بردود مبنية على مكتبة الأنظمة المحلية فقط (مجاني بالكامل) حتى لا يتوقف الموقع. زر "اختبار الاتصال" يختبر آخر إعدادات محفوظة فعلياً — احفظ أولاً بعد أي تعديل ثم اختبر.</p>
  </form>
  <form class="dash-card" method="post" id="embForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <input type="hidden" name="action" value="embedding_settings">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">

      <h3 style="margin:0">البحث الدلالي (Semantic Search / Embeddings)</h3>

      <span class="status-pill status-<?= $embKeyConfigured ? 'confirmed' : 'pending' ?>">

        <?= $embKeyConfigured ? '✓ مفعّل' : '— غير مربوط بعد' ?>

      </span>

    </div>

    <p style="font-size:.85rem;margin-bottom:16px">يحوّل نصوص مكتبة الأنظمة والمعرفة إلى متجهات (Embeddings) لإيجاد المحتوى ذي الصلة حتى لو اختلفت صياغة سؤال العميل عن نص المصدر تماماً — مكمّل للبحث بالكلمات المفتاحية وليس بديلاً عنه. المزوّد المدعوم حالياً: <strong>OpenAI</strong> فقط (نموذج <code>text-embedding-3-small</code> افتراضياً).</p>

    <div class="grid-2">

      <div class="field">

        <label>المزوّد</label>

        <div class="select-wrap">

        <select name="embedding_provider" class="pro-select">

          <option value="disabled" <?= ($settings['embedding_provider']??'') === 'disabled' ? 'selected' : '' ?>>— إيقاف البحث الدلالي —</option>
          <option value="" <?= empty($settings['embedding_provider']) ? 'selected' : '' ?>>استخدم مفتاح دردشة OpenAI إن كان متاحاً</option>
          <option value="openai" <?= ($settings['embedding_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
          <option value="openai_compatible" <?= ($settings['embedding_provider'] ?? '') === 'openai_compatible' ? 'selected' : '' ?>>خادم متجهات محلي / متوافق مع OpenAI</option>

        </select>

        </div>

      </div>

      <div class="field">

        <label>اسم نموذج المتجهات</label>

        <input type="text" name="embedding_model" value="<?= htmlspecialchars($settings['embedding_model'] ?? 'text-embedding-3-small') ?>" placeholder="text-embedding-3-small">

      </div>

    </div>
    <div class="field">
      <label>عنوان خدمة المتجهات المحلية (اختياري)</label>
      <input type="text" name="embedding_endpoint" dir="ltr" value="<?= htmlspecialchars($settings['embedding_endpoint'] ?? '') ?>" placeholder="http://127.0.0.1:11434/v1">
      <small>يُستخدم عند اختيار «خادم متجهات محلي». إن تُرك فارغاً يُستخدم عنوان المزوّد المحلي للدردشة نفسه.</small>
    </div>

    <div class="field">

      <label>مفتاح API لمزوّد المتجهات</label>

      <input type="password" name="embedding_api_key" value="" autocomplete="new-password" placeholder="<?= $embKeyConfigured ? 'مفتاح محفوظ — أدخل قيمة جديدة للتدوير' : 'أدخل مفتاح OpenAI API' ?>">

      <?php if($embKeyConfigured): ?><label><input type="checkbox" name="revoke_embedding_key" value="1"> إلغاء وحذف المفتاح المحفوظ</label><?php endif; ?>

      <small>إن تُرك فارغاً وكان مزوّد الدردشة أعلاه OpenAI بمفتاح محفوظ، يُستخدم نفس المفتاح تلقائياً للمتجهات.</small>

    </div>

    <button class="btn btn-primary" type="submit">حفظ إعدادات المتجهات</button>

    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border,#eee)">

      <p style="font-size:.85rem;margin-bottom:10px">

        مواد نظامية بدون متجه: <strong><?= $embLawPending ?></strong> — مصادر معرفة بدون متجه: <strong><?= $embKnowledgePending ?></strong>

      </p>

      <button class="btn btn-outline" type="button" id="reindexBtn" onclick="runReindex()" <?= $embKeyConfigured ? '' : 'disabled' ?>>إعادة فهرسة المتجهات الآن</button>

      <span id="reindexResult" style="font-size:.85rem;margin-inline-start:8px"></span>

      <p style="font-size:.78rem;color:var(--text-muted);margin-top:8px">تُعالج كل ضغطة دفعة محدودة (لتفادي انتهاء مهلة الاستضافة المشتركة) — كرّر الضغط حتى يصل العدادان أعلاه للصفر بعد تحديث الصفحة.</p>

    </div>

  </form>



  <div class="dash-card"><h3>المصادقة الثنائية للإدارة</h3><p>اربط تطبيق مصادقة برمز TOTP لحماية تسجيل دخول لوحة التحكم.</p><a class="btn btn-outline" href="/admin/two-factor.php">إعداد المصادقة الثنائية</a></div>
  <script>
  function syncModelOptions(){
    var provider = document.getElementById('aiProvider').value;
    document.getElementById('grp-anthropic').style.display = provider === 'anthropic' ? '' : 'none';
    document.getElementById('grp-openai').style.display = provider === 'openai' ? '' : 'none';
    // The local provider is named by the operator, so no preset group applies; the free
    // text model field is the source of truth for it.
    var preset = document.getElementById('aiModelPreset');
    if (preset) { var local = provider === 'openai_compatible'; preset.disabled = local; if (local) preset.value = ''; }
  }
  syncModelOptions();

  async function runReindex(){

    var btn = document.getElementById('reindexBtn');

    var out = document.getElementById('reindexResult');

    btn.disabled = true; var original = btn.textContent; btn.textContent = 'جارٍ الفهرسة...';

    out.style.color = 'var(--text-muted)'; out.textContent = '';

    try {

      const res = await fetch('/admin/reindex-embeddings.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'csrf_token=<?= urlencode(csrf_token()) ?>'});

      const data = await res.json();

      if (data.ok) {

        out.style.color = 'var(--success)';

        out.textContent = 'تمت معالجة ' + data.processed + ' — المتبقي: مواد ' + data.law_remaining + '، مصادر ' + data.knowledge_remaining + '.';

      } else {

        out.style.color = 'var(--danger)'; out.textContent = data.message || 'فشلت إعادة الفهرسة.';

      }

    } catch(e) { out.style.color = 'var(--danger)'; out.textContent = 'تعذّر الاتصال بالخادم.'; }

    btn.disabled = false; btn.textContent = original;

  }



  async function testConnection(){
    var btn = document.getElementById('testBtn');
    var out = document.getElementById('testResult');
    btn.disabled = true; btn.textContent = 'جارٍ الاختبار...';
    out.style.color = 'var(--text-muted)'; out.textContent = '';
    try {
      const res = await fetch('/admin/ai_test.php');
      const data = await res.json();
      out.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
      out.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
    } catch(e) {
      out.style.color = 'var(--danger)';
      out.textContent = '✗ تعذّر تنفيذ الاختبار.';
    }
    btn.disabled = false; btn.textContent = 'اختبار الاتصال';
  }
  </script>

  <form class="dash-card" method="post" id="ngeniusForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="ngenius_settings">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <h3 style="margin:0">بوابة الدفع السعودية (N-Genius — سداد / مدى / فيزا / ماستركارد)</h3>
      <span class="status-pill status-<?= $ngeniusActive ? 'confirmed' : 'pending' ?>">
        <?= $ngeniusActive ? '✓ مفعّلة' : ($ngeniusConfigured ? '⏸ موقوفة يدوياً' : '— غير مربوطة بعد') ?>
      </span>
    </div>
    <p style="font-size:.85rem;margin-bottom:16px">اربط حساب N-Genius الخاص بكم ليتمكن العملاء من دفع الفواتير مباشرة ببطاقتهم (مدى/فيزا/ماستركارد) من الموقع والتطبيق. بيانات الاتصال (Outlet Reference ومفتاح API) تجدونها من بوابة N-Genius: Settings ← Integrations ← Service Accounts، و Settings ← Organizational Hierarchy للـ Outlet Reference.</p>

    <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:700;font-size:.9rem">
      <input type="checkbox" name="ngenius_enabled" value="1" <?= ($settings['ngenius_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
      تفعيل الدفع الإلكتروني عبر N-Genius
    </label>

    <div class="grid-2">
      <div class="field">
        <label>البيئة</label>
        <div class="select-wrap"><select name="ngenius_environment" class="pro-select">
          <option value="sandbox" <?= ($settings['ngenius_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>تجريبية (Sandbox)</option>
          <option value="production" <?= ($settings['ngenius_environment'] ?? '') === 'production' ? 'selected' : '' ?>>حقيقية (Production — أموال فعلية)</option>
        </select></div>
      </div>
      <div class="field">
        <label>Outlet Reference</label>
        <input type="text" name="ngenius_outlet_ref" value="<?= htmlspecialchars($settings['ngenius_outlet_ref'] ?? '') ?>" placeholder="مثال: 5edab6d7-5946-43f4-b8c7-06b29c272bdd">
      </div>
      <div class="field">
        <label>Service Account API Key</label>
        <input type="password" name="ngenius_api_key" value="" autocomplete="new-password" placeholder="<?= $ngeniusKeyConfigured ? 'مفتاح محفوظ — أدخل قيمة جديدة للتدوير' : 'أدخل Service Account API Key' ?>">
      </div>
      <div class="field">
        <label>Realm Name</label>
        <input type="text" name="ngenius_realm_name" value="<?= htmlspecialchars($settings['ngenius_realm_name'] ?? 'ni') ?>" placeholder="ni">
        <p class="field-hint">القيمة الافتراضية "ni" — إن لم تعمل، تأكد من القيمة الصحيحة مع دعم N-Genius.</p>
      </div>
      <div class="field">
        <label>عملة التسوية لدى البوابة</label>
        <div class="select-wrap"><select name="ngenius_currency" class="pro-select">
          <?php foreach (currency_options() as $code => $opt): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= ngenius_currency($ngeniusSecureSettings) === $code ? 'selected' : '' ?>><?= htmlspecialchars($code . ' — ' . $opt['ar']) ?></option>
          <?php endforeach; ?>
        </select></div>
        <p class="field-hint">يجب أن تطابق عملة حساب التاجر/Outlet لدى N-Genius. لن تُقبل الفواتير بعملة مختلفة عبر البوابة.</p>
      </div>
    </div>
    <div class="field">
      <label>Webhook Secret Header (اختياري لكن مستحسن)</label>
      <input type="password" name="ngenius_webhook_secret" value="" autocomplete="new-password" placeholder="<?= !empty($ngeniusSecureSettings['ngenius_webhook_secret']) ? 'سرّ محفوظ — أدخل قيمة جديدة للتدوير' : 'قيمة سرّية عشوائية' ?>">
      <?php if($ngeniusKeyConfigured || !empty($ngeniusSecureSettings['ngenius_webhook_secret'])): ?><label><input type="checkbox" name="revoke_ngenius_keys" value="1"> إلغاء مفاتيح الدفع والـWebhook المحفوظة</label><?php endif; ?>
      <p class="field-hint">أنشئ Webhook من بوابة N-Genius (Settings ← Integrations ← Webhooks) على الرابط: <code><?= rtrim(SITE_URL,'/') ?>/ngenius-webhook.php</code>، وضع نفس القيمة هنا وبحقل "Header Value" هناك باسم الترويسة <code>X-Webhook-Token</code>، لتأكيد أن الإشعار فعلاً من N-Genius.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">حفظ</button>
      <button class="btn btn-outline" type="button" id="ngeniusTestBtn" onclick="testNgenius()">اختبار الاتصال</button>
      <span id="ngeniusTestResult" style="font-size:.85rem"></span>
    </div>
  </form>
  <script>
  async function testNgenius(){
    var btn = document.getElementById('ngeniusTestBtn');
    var out = document.getElementById('ngeniusTestResult');
    btn.disabled = true; btn.textContent = 'جارٍ الاختبار...';
    out.textContent = '';
    try {
      const res = await fetch('/admin/payment-test.php');
      const data = await res.json();
      out.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
      out.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
    } catch(e) {
      out.style.color = 'var(--danger)';
      out.textContent = '✗ تعذّر تنفيذ الاختبار.';
    }
    btn.disabled = false; btn.textContent = 'اختبار الاتصال';
  }
  </script>

  <?php if ($callsModuleAvailable): ?>
  <form class="dash-card" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="call_settings">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <h3>الاتصال الصوتي والمرئي داخل التطبيق</h3>
      <?php
        $callBadgeClass = $callState['available'] ? 'status-approved' : 'status-pending';
        $callBadgeText = $callState['available'] ? 'مهيّأ' : 'غير مهيّأ';
      ?>
      <span class="status-badge <?= $callBadgeClass ?>"><?= htmlspecialchars($callBadgeText) ?></span>
    </div>
    <p style="font-size:.85rem;margin-bottom:14px"><?= htmlspecialchars($callState['message']) ?></p>

    <div class="field">
      <label><input type="checkbox" name="call_enabled" value="1" <?= ($callSettings['call_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> تفعيل الاتصال الصوتي والمرئي</label>
    </div>
    <div class="field">
      <label>نوع مزوّد الاتصال</label>
      <select name="call_provider">
        <option value="webrtc" <?= ($callSettings['call_provider'] ?? 'webrtc') === 'webrtc' ? 'selected' : '' ?>>WebRTC مباشر بين الطرفين (افتراضي)</option>
        <option value="jitsi" <?= ($callSettings['call_provider'] ?? '') === 'jitsi' ? 'selected' : '' ?>>Jitsi Meet</option>
      </select>
      <small>«WebRTC مباشر» يربط الطرفين دون وسيط؛ يتطلب خادم TURN للشبكات المقيّدة. «Jitsi Meet» يستخدم غرفة خارجية مضمّنة.</small>
    </div>
    <div class="field">
      <label>عناوين STUN (سطر لكل عنوان)</label>
      <textarea name="call_stun_urls" dir="ltr" rows="2" placeholder="stun:stun.l.google.com:19302"><?= htmlspecialchars($callSettings['call_stun_urls'] ?? '') ?></textarea>
      <small>يكفي STUN للشبكات العادية. لا يحمل أي بيانات اعتماد.</small>
    </div>
    <div class="field">
      <label>عناوين TURN (سطر لكل عنوان)</label>
      <textarea name="call_turn_urls" dir="ltr" rows="2" placeholder="turn:turn.example.com:3478"><?= htmlspecialchars($callSettings['call_turn_urls'] ?? '') ?></textarea>
      <small>مطلوب للمكالمات عبر شبكات الاتصالات المقيّدة (Symmetric NAT). بدون TURN قد تفشل الوسائط بين بعض الشبكات — وهذا قيد موثّق وليس عطلاً.</small>
    </div>
    <div class="grid-2">
      <div class="field">
        <label>اسم مستخدم TURN</label>
        <input type="text" name="call_turn_username" dir="ltr" value="<?= htmlspecialchars($callSettings['call_turn_username'] ?? '') ?>">
      </div>
      <div class="field">
        <label>كلمة مرور TURN</label>
        <input type="password" name="call_turn_credential" value="" autocomplete="new-password" placeholder="<?= $callTurnCredentialConfigured ? 'محفوظة — أدخل قيمة جديدة للتدوير' : 'أدخل كلمة المرور' ?>">
        <?php if ($callTurnCredentialConfigured): ?><label><input type="checkbox" name="revoke_call_turn_credential" value="1"> إلغاء وحذف كلمة المرور المحفوظة</label><?php endif; ?>
        <small>محفوظة مشفّرة ولا يُعاد عرضها.</small>
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label>مهلة الرنين (ثانية)</label>
        <input type="number" name="call_ring_timeout_seconds" min="10" max="180" value="<?= (int)($callSettings['call_ring_timeout_seconds'] ?? 45) ?>">
      </div>
      <div class="field">
        <label>أقصى مدة للمكالمة (دقيقة)</label>
        <input type="number" name="call_max_duration_minutes" min="5" max="480" value="<?= (int)($callSettings['call_max_duration_minutes'] ?? 120) ?>">
      </div>
    </div>
    <button class="btn btn-primary" type="submit">حفظ إعدادات الاتصال</button>
  </form>
  <?php endif; ?>

  <form class="dash-card" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <h3 style="margin-bottom:14px">تغيير كلمة مرور المدير</h3>
    <?php if ($pwError): ?><div class="form-alert error"><?= htmlspecialchars($pwError) ?></div><?php endif; ?>
    <div class="field"><label>كلمة مرور جديدة</label><input type="password" name="new_password" minlength="8" required></div>
    <button class="btn btn-outline" type="submit">تحديث كلمة المرور</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
