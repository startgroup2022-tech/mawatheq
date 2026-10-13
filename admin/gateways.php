<?php
/**
 * Admin → Payments → SADAD.
 *
 * The ONLY place SADAD is configured. Operators set environment + credentials here; the code
 * never needs editing to move Sandbox → Live. Secrets are stored encrypted and shown masked.
 */
$activeAdmin = 'gateways';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
$pageTitle = is_rtl() ? 'بوابات الدفع — SADAD' : 'Payment gateways — SADAD';
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';
require_once __DIR__ . '/includes/gateway_ui.php';

$scopeRaw = $_GET['tenant'] ?? $_POST['tenant_scope'] ?? 'platform';
[$tenantType, $tenantId] = gateway_ui_tenant_scope($scopeRaw, $pdo);
$scopeValue = $tenantType === 'platform' ? 'platform' : $tenantType . ':' . $tenantId;

$saved = false; $error = null; $envWarning = false; $envCleared = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sadad_settings') {
    $previous = sadad_settings($pdo, $tenantType, $tenantId);
    $input = $_POST;
    $newEnv = in_array($input['sadad_environment'] ?? '', ['sandbox', 'live'], true) ? $input['sadad_environment'] : 'sandbox';
    $input['sadad_environment'] = $newEnv;
    $input['sadad_currency'] = currency_normalize($input['sadad_currency'] ?? 'SAR');
    $input['sadad_enabled'] = isset($_POST['sadad_enabled']) ? '1' : '0';
    $input['sadad_auth_mode'] = in_array($input['sadad_auth_mode'] ?? '', ['none','basic','bearer','api_key','oauth2_client_credentials'], true) ? $input['sadad_auth_mode'] : 'none';
    $input['sadad_webhook_signature_algo'] = in_array($input['sadad_webhook_signature_algo'] ?? '', ['hmac_sha256','hmac_sha512','none'], true) ? $input['sadad_webhook_signature_algo'] : 'hmac_sha256';
    $input['sadad_amount_unit'] = in_array($input['sadad_amount_unit'] ?? '', ['major','minor'], true) ? $input['sadad_amount_unit'] : 'major';
    // Preserve runtime state fields that are not part of the form.
    $input['sadad_last_connection_ok'] = $previous['sadad_last_connection_ok'] ?? '';
    $input['sadad_last_connection_at'] = $previous['sadad_last_connection_at'] ?? '';
    $input['sadad_last_success_at'] = $previous['sadad_last_success_at'] ?? '';

    $result = sadad_save_settings($pdo, $input, $tenantType, $tenantId);
    if ($result === true) {
        log_action($pdo, 'تحديث إعدادات SADAD (' . $newEnv . ', ' . $scopeValue . ')');
        $saved = true;
        if (($previous['sadad_environment'] ?? 'sandbox') !== $newEnv && $newEnv === 'live') $envWarning = true;
        if (($previous['sadad_environment'] ?? 'sandbox') !== $newEnv && trim((string)($_POST['sadad_api_key'] ?? '')) === '' && trim((string)($_POST['sadad_api_secret'] ?? '')) === '') $envCleared = true;
    } else {
        $error = is_rtl()
            ? 'تعذّر الحفظ: اضبط MAWATHEQ_ENCRYPTION_KEY (32 حرفاً+) في config/secrets.php لتشفير المفاتيح.'
            : 'Save failed: set MAWATHEQ_ENCRYPTION_KEY (32+ chars) in config/secrets.php to encrypt credentials.';
    }
}

$config = sadad_settings($pdo, $tenantType, $tenantId);
$gateway = new SadadGateway($config);
$missing = sadad_missing_keys($config);
$connected = !empty($config['sadad_last_connection_ok']) ? (int)$config['sadad_last_connection_ok'] === 1 : null;
$lastCheck = $config['sadad_last_connection_at'] ?? '';

$secretConfigured = [
    'sadad_api_key' => trim((string)($config['sadad_api_key'] ?? '')) !== '',
    'sadad_api_secret' => trim((string)($config['sadad_api_secret'] ?? '')) !== '',
    'sadad_password' => trim((string)($config['sadad_password'] ?? '')) !== '',
    'sadad_webhook_secret' => trim((string)($config['sadad_webhook_secret'] ?? '')) !== '',
];
$env = $config['sadad_environment'] ?? 'sandbox';
$statusLabel = $gateway->isEnabled() ? ($env === 'live' ? '🟢 مفعّلة (إنتاج)' : '🟢 مفعّلة (تجريبية)') : '🔴 غير مفعّلة';
?>
<div class="app-content">
  <div class="pg-head"><div>
    <h1><?= is_rtl() ? 'بوابة الدفع SADAD' : 'SADAD Payment Gateway' ?></h1>
    <p><?= is_rtl() ? 'إعداد وتشغيل تكامل SADAD من لوحة التحكم — لا حاجة لتعديل الكود للانتقال من التجريبية إلى الإنتاج.' : 'Configure and operate the SADAD integration from the console — no code change needed to go Sandbox → Live.' ?></p>
  </div></div>

  <?php if ($saved): ?><div class="form-alert success"><?= is_rtl() ? 'تم حفظ إعدادات SADAD.' : 'SADAD settings saved.' ?></div><?php endif; ?>
  <?php if ($envCleared): ?><div class="form-alert error"><?= is_rtl() ? 'تم تبديل البيئة بدون إدخال مفاتيح جديدة، لذلك أُفرغت المفاتيح المحفوظة لتفادي استخدام مفاتيح Sandbox على Live (أو العكس). أدخل مفاتيح البيئة الجديدة ثم احفظ.' : 'The environment was switched without new credentials, so the stored keys were cleared to prevent using Sandbox keys on Live (or vice versa). Enter the new environment\'s credentials and save.' ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($envWarning): ?><div class="form-alert error"><?= is_rtl() ? '⚠️ أنت الآن على بيئة الإنتاج (Live). عمليات الدفع القادمة قد تكون حقيقية.' : '⚠️ You are now on Live. Upcoming payments may be real.' ?></div><?php endif; ?>

  <form method="get" class="dash-card" style="padding:14px 18px">
    <div class="field" style="max-width:420px;margin:0">
      <label><?= is_rtl() ? 'نطاق الإعدادات (الشركة/المحامي)' : 'Settings scope (firm/lawyer)' ?></label>
      <div class="select-wrap"><select name="tenant" class="pro-select" onchange="this.form.submit()">
        <option value="platform" <?= $scopeValue === 'platform' ? 'selected' : '' ?>><?= is_rtl() ? 'المنصة (افتراضي)' : 'Platform (default)' ?></option>
        <?php foreach (gateway_ui_tenant_options($pdo) as $val => $label): ?>
          <option value="<?= htmlspecialchars($val) ?>" <?= $scopeValue === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select></div>
      <p class="field-hint"><?= is_rtl() ? 'لكل شركة/محامٍ مفاتيح SADAD مستقلة. تُستخدم مفاتيح صاحب الفاتورة فقط عند الدفع.' : 'Each firm/lawyer has independent SADAD credentials. Only the invoice owner\'s credentials are used at payment time.' ?></p>
    </div>
  </form>

  <div class="dash-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <h3 style="margin:0"><?= is_rtl() ? 'الحالة' : 'Status' ?></h3>
  <?php if ($gateway->isEnabled()): ?><span class="status-pill status-confirmed">✓ <?= is_rtl() ? 'مفعّلة' : 'Enabled' ?></span><?php else: ?><span class="status-pill status-pending">— <?= is_rtl() ? 'غير مفعّلة' : 'Disabled' ?></span><?php endif; ?>
      <a class="btn btn-outline" href="/admin/gateway-transactions.php?gateway=sadad"><?= is_rtl() ? 'العمليات' : 'Transactions' ?></a>
    </div>
    <div class="grid-2" style="margin-top:14px">
      <div><small><?= is_rtl() ? 'البيئة' : 'Environment' ?></small><div><b><?= $env === 'live' ? (is_rtl() ? 'إنتاج (Live)' : 'Live') : (is_rtl() ? 'تجريبية (Sandbox)' : 'Sandbox') ?></b></div></div>
      <div><small><?= is_rtl() ? 'العملة' : 'Currency' ?></small><div><b><?= htmlspecialchars($config['sadad_currency'] ?? 'SAR') ?></b></div></div>
      <div><small><?= is_rtl() ? 'حالة الاتصال' : 'Connection status' ?></small><div><b>
        <?php if ($missing): ?><?= is_rtl() ? '⚠️ يتطلب إعداداً' : '⚠️ Configuration required' ?>
        <?php elseif ($connected === true): ?><?= is_rtl() ? '✓ متصل' : '✓ Connected' ?>
        <?php elseif ($connected === false): ?><?= is_rtl() ? '✗ غير متصل' : '✗ Not connected' ?>
        <?php else: ?><?= is_rtl() ? '— لم يُختبر بعد' : '— Not tested yet' ?><?php endif; ?>
      </b></div></div>
      <div><small><?= is_rtl() ? 'آخر فحص اتصال' : 'Last connection check' ?></small><div><b><?= htmlspecialchars($lastCheck !== '' ? $lastCheck : '—') ?></b></div></div>
      <div><small><?= is_rtl() ? 'آخر عملية ناجحة' : 'Last successful transaction' ?></small><div><b><?= htmlspecialchars(($config['sadad_last_success_at'] ?? '') !== '' ? $config['sadad_last_success_at'] : '—') ?></b></div></div>
    </div>
    <?php if ($missing): ?><p class="field-hint" style="margin-top:10px"><?= is_rtl() ? 'حقول ناقصة: ' : 'Missing fields: ' ?><code><?= htmlspecialchars(implode(', ', $missing)) ?></code></p><?php endif; ?>
  </div>

  <form class="dash-card" method="post" id="sadadForm" onsubmit="return sadadConfirmEnv()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="sadad_settings">
    <input type="hidden" name="tenant_scope" value="<?= htmlspecialchars($scopeValue) ?>">
    <h3 style="margin-bottom:6px"><?= is_rtl() ? 'بيانات الاعتماد والإعدادات' : 'Credentials & configuration' ?></h3>
    <p style="font-size:.85rem;margin-bottom:16px"><?= is_rtl() ? 'أدخل بيانات حساب التاجر من SADAD. الروابط (endpoints) تُنسخ كما وردت في وثائق حسابك الرسمية — لا يفترض النظام أي رابط من تلقاء نفسه.' : 'Enter the merchant values from your SADAD account. Endpoint paths are copied verbatim from your official merchant documentation — the system never assumes any endpoint on its own.' ?></p>
    <?php if (!sadad_credentials_match_env($config) && trim((string)($config['sadad_api_key'] ?? '')) !== ''): ?>
      <div class="form-alert error" style="font-size:.85rem"><?= is_rtl() ? '⚠️ المفاتيح المحفوظة لا تطابق البيئة الحالية — لن تُستخدم حتى تُدخل مفاتيح البيئة الصحيحة.' : '⚠️ Stored credentials do not match the current environment — they will not be used until the correct environment\'s credentials are entered.' ?></div>
    <?php endif; ?>

    <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:700;font-size:.9rem">
      <input type="checkbox" name="sadad_enabled" value="1" <?= ($config['sadad_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
      <?= is_rtl() ? 'تفعيل الدفع عبر SADAD' : 'Enable SADAD payments' ?>
    </label>

    <div class="grid-2">
      <div class="field">
        <label><?= is_rtl() ? 'البيئة' : 'Environment' ?></label>
        <div class="select-wrap"><select name="sadad_environment" class="pro-select" id="sadadEnv">
          <option value="sandbox" <?= $env === 'sandbox' ? 'selected' : '' ?>><?= is_rtl() ? 'تجريبية (Sandbox)' : 'Sandbox' ?></option>
          <option value="live" <?= $env === 'live' ? 'selected' : '' ?>><?= is_rtl() ? 'إنتاج (Live — أموال فعلية)' : 'Live (real money)' ?></option>
        </select></div>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'العملة' : 'Currency' ?></label>
        <div class="select-wrap"><select name="sadad_currency" class="pro-select">
          <?php foreach (currency_options() as $code => $opt): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= ($config['sadad_currency'] ?? 'SAR') === $code ? 'selected' : '' ?>><?= htmlspecialchars($code . ' — ' . $opt['ar']) ?></option>
          <?php endforeach; ?>
        </select></div>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'Merchant ID' : 'Merchant ID' ?></label>
        <input type="text" name="sadad_merchant_id" value="<?= htmlspecialchars($config['sadad_merchant_id'] ?? '') ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'Terminal / Outlet ID (إن وُجد)' : 'Terminal / Outlet ID (if required)' ?></label>
        <input type="text" name="sadad_terminal_id" value="<?= htmlspecialchars($config['sadad_terminal_id'] ?? '') ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'API Base URL' : 'API Base URL' ?></label>
        <input type="text" name="sadad_base_url" value="<?= htmlspecialchars($config['sadad_base_url'] ?? '') ?>" placeholder="SADAD_API_BASE_URL">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'نوع المصادقة' : 'Auth mode' ?></label>
        <div class="select-wrap"><select name="sadad_auth_mode" class="pro-select">
          <?php foreach (['none','basic','bearer','api_key','oauth2_client_credentials'] as $mode): ?>
            <option value="<?= $mode ?>" <?= ($config['sadad_auth_mode'] ?? 'none') === $mode ? 'selected' : '' ?>><?= $mode ?></option>
          <?php endforeach; ?>
        </select></div>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'API Key' : 'API Key' ?></label>
        <input type="password" name="sadad_api_key" value="" autocomplete="new-password" placeholder="<?= $secretConfigured['sadad_api_key'] ? (is_rtl() ? 'محفوظ — أدخل قيمة جديدة للتدوير' : 'Stored — enter new to rotate') : 'API Key' ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'API Secret' : 'API Secret' ?></label>
        <input type="password" name="sadad_api_secret" value="" autocomplete="new-password" placeholder="<?= $secretConfigured['sadad_api_secret'] ? (is_rtl() ? 'محفوظ — أدخل قيمة جديدة للتدوير' : 'Stored — enter new to rotate') : 'API Secret' ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'Username (إن وُجد)' : 'Username (if required)' ?></label>
        <input type="text" name="sadad_username" value="<?= htmlspecialchars($config['sadad_username'] ?? '') ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'Password (إن وُجد)' : 'Password (if required)' ?></label>
        <input type="password" name="sadad_password" value="" autocomplete="new-password" placeholder="<?= $secretConfigured['sadad_password'] ? (is_rtl() ? 'محفوظ — أدخل قيمة جديدة للتدوير' : 'Stored — enter new to rotate') : 'Password' ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'Webhook Secret' : 'Webhook Secret' ?></label>
        <input type="password" name="sadad_webhook_secret" value="" autocomplete="new-password" placeholder="<?= $secretConfigured['sadad_webhook_secret'] ? (is_rtl() ? 'محفوظ — أدخل قيمة جديدة للتدوير' : 'Stored — enter new to rotate') : 'Webhook Secret' ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'ترويسة توقيع الويب هوك' : 'Webhook signature header' ?></label>
        <input type="text" name="sadad_webhook_signature_header" value="<?= htmlspecialchars($config['sadad_webhook_signature_header'] ?? '') ?>" placeholder="e.g. X-SADAD-Signature">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'خوارزمية التوقيع' : 'Signature algorithm' ?></label>
        <div class="select-wrap"><select name="sadad_webhook_signature_algo" class="pro-select">
          <?php foreach (['hmac_sha256','hmac_sha512','none'] as $algo): ?>
            <option value="<?= $algo ?>" <?= ($config['sadad_webhook_signature_algo'] ?? 'hmac_sha256') === $algo ? 'selected' : '' ?>><?= $algo ?></option>
          <?php endforeach; ?>
        </select></div>
      </div>
    </div>

    <h4 style="margin:18px 0 8px"><?= is_rtl() ? 'مسارات العمليات (Endpoints — من وثائق SADAD الرسمية)' : 'Operation endpoints (from official SADAD docs)' ?></h4>
    <div class="grid-2">
      <div class="field"><label><?= is_rtl() ? 'إنشاء الدفع' : 'Create payment' ?></label><input type="text" name="sadad_create_endpoint" value="<?= htmlspecialchars($config['sadad_create_endpoint'] ?? '') ?>" placeholder="SADAD_PAYMENT_ENDPOINT"></div>
      <div class="field"><label><?= is_rtl() ? 'التحقق من الدفع' : 'Verify payment' ?></label><input type="text" name="sadad_verify_endpoint" value="<?= htmlspecialchars($config['sadad_verify_endpoint'] ?? '') ?>" placeholder="SADAD_VERIFY_ENDPOINT"></div>
      <div class="field"><label><?= is_rtl() ? 'الاسترداد (إن كان مدعوماً)' : 'Refund (if supported)' ?></label><input type="text" name="sadad_refund_endpoint" value="<?= htmlspecialchars($config['sadad_refund_endpoint'] ?? '') ?>" placeholder="—"></div>
      <div class="field"><label><?= is_rtl() ? 'نقطة رمز OAuth (عند استخدامها)' : 'OAuth token endpoint (when used)' ?></label><input type="text" name="sadad_oauth_token_endpoint" value="<?= htmlspecialchars($config['sadad_oauth_token_endpoint'] ?? '') ?>" placeholder="—"></div>
    </div>

    <h4 style="margin:18px 0 8px"><?= is_rtl() ? 'خريطة حقول الاستجابة (JSON paths)' : 'Response field mapping (JSON paths)' ?></h4>
    <p class="field-hint" style="margin-bottom:10px"><?= is_rtl() ? 'حدّد المسار الذي يظهر فيه كل حقل في استجابة SADAD (مثال: data.payment.status).' : 'Point each field at the path where it appears in the SADAD response (e.g. data.payment.status).' ?></p>
    <div class="grid-2">
      <?php
      $maps = [
          'sadad_map_reference' => is_rtl() ? 'المرجع' : 'Reference',
          'sadad_map_state' => is_rtl() ? 'الحالة' : 'State',
          'sadad_map_amount' => is_rtl() ? 'المبلغ' : 'Amount',
          'sadad_map_currency' => is_rtl() ? 'العملة' : 'Currency',
          'sadad_map_redirect_url' => is_rtl() ? 'رابط الدفع' : 'Redirect URL',
          'sadad_map_transaction_id' => is_rtl() ? 'رقم العملية' : 'Transaction ID',
          'sadad_map_event_id' => is_rtl() ? 'معرّف الحدث' : 'Event ID',
          'sadad_map_event_type' => is_rtl() ? 'نوع الحدث' : 'Event type',
      ];
      foreach ($maps as $key => $label): ?>
        <div class="field"><label><?= htmlspecialchars($label) ?></label><input type="text" name="<?= $key ?>" value="<?= htmlspecialchars($config[$key] ?? '') ?>"></div>
      <?php endforeach; ?>
    </div>

    <div class="grid-2">
      <div class="field">
        <label><?= is_rtl() ? 'وحدة المبلغ المُرسل' : 'Amount unit sent' ?></label>
        <div class="select-wrap"><select name="sadad_amount_unit" class="pro-select">
          <option value="major" <?= ($config['sadad_amount_unit'] ?? 'major') === 'major' ? 'selected' : '' ?>><?= is_rtl() ? 'وحدات رئيسية (مثال: 12.50 ريال)' : 'Major units (e.g. 12.50 SAR)' ?></option>
          <option value="minor" <?= ($config['sadad_amount_unit'] ?? '') === 'minor' ? 'selected' : '' ?>><?= is_rtl() ? 'أصغر وحدة (هللة ×100)' : 'Minor units (halalas ×100)' ?></option>
        </select></div>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'مهلة الاتصال (ثانية)' : 'Timeout (seconds)' ?></label>
        <input type="number" name="sadad_timeout" min="5" max="60" value="<?= (int)($config['sadad_timeout'] ?? 20) ?>">
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
      <button class="btn btn-outline" type="button" onclick="sadadTestConnection()"><?= is_rtl() ? 'اختبار الاتصال' : 'Test connection' ?></button>
      <span id="sadadTestResult" style="font-size:.85rem"></span>
    </div>
  </form>

  <div class="dash-card">
    <h3 style="margin-bottom:10px"><?= is_rtl() ? 'الروابط (URLs)' : 'URLs' ?></h3>
    <div class="grid-2">
      <?php
      $urls = [
          ['Callback', rtrim(SITE_URL, '/') . '/sadad-webhook.php'],
          ['Webhook', sadad_webhook_url()],
          ['Return', sadad_return_url()],
          ['Cancel', sadad_cancel_url()],
      ];
      foreach ($urls as [$label, $url]): ?>
        <div class="field">
          <label><?= htmlspecialchars($label) ?></label>
          <div style="display:flex;gap:6px">
            <input type="text" value="<?= htmlspecialchars($url) ?>" readonly>
            <button type="button" class="btn btn-outline" onclick="sadadCopy(this, '<?= htmlspecialchars($url) ?>')"><?= is_rtl() ? 'نسخ' : 'Copy' ?></button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="dash-card">
    <h3 style="margin-bottom:10px"><?= is_rtl() ? 'اختبار عملية دفع (Sandbox)' : 'Sandbox payment test' ?></h3>
    <div class="form-alert" id="sadadLiveWarn" style="display:<?= $env === 'live' ? 'block' : 'none' ?>;background:#FDECEC;color:#B42318;font-size:.85rem">
      <?= is_rtl() ? '⚠️ أنت تستخدم بيئة الإنتاج. أي عملية دفع قد تكون حقيقية. لن يُنفّذ أي دفع حقيقي تلقائياً.' : '⚠️ You are on Live. Any payment may be real. No real payment is executed automatically.' ?>
    </div>
    <div class="grid-2">
      <div class="field"><label><?= is_rtl() ? 'المبلغ' : 'Amount' ?></label><input type="number" id="testAmount" step="0.01" min="0.01" value="1.00"></div>
      <div class="field"><label><?= is_rtl() ? 'اسم العميل' : 'Customer name' ?></label><input type="text" id="testName" value="Test Customer"></div>
      <div class="field"><label><?= is_rtl() ? 'بريد العميل' : 'Customer email' ?></label><input type="email" id="testEmail" value="test@example.com"></div>
      <div class="field"><label><?= is_rtl() ? 'هاتف العميل' : 'Customer phone' ?></label><input type="text" id="testPhone" value="0500000000"></div>
      <div class="field"><label><?= is_rtl() ? 'رقم الفاتورة (اختياري)' : 'Invoice # (optional)' ?></label><input type="number" id="testInvoice" placeholder="—"></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button class="btn btn-primary" type="button" onclick="sadadTestPayment()" <?= $gateway->isEnabled() ? '' : 'disabled' ?>><?= is_rtl() ? 'بدء الدفع التجريبي' : 'Start test payment' ?></button>
      <span id="sadadTestPayResult" style="font-size:.85rem"></span>
    </div>
    <p class="field-hint"><?= is_rtl() ? 'لا يُنشئ هذا الزر دفعاً حقيقياً في بيئة الإنتاج؛ فهو يتحقق من الإعدادات فقط ويعرض النتيجة.' : 'On Live this button does not create a real payment; it only validates configuration and reports the result.' ?></p>
  </div>

  <div class="dash-card">
    <h3 style="margin-bottom:10px"><?= is_rtl() ? 'فحص صحة البوابة' : 'Gateway health' ?></h3>
    <table class="admin-table">
      <thead><tr><th>Gateway</th><th><?= is_rtl() ? 'الاعتماد' : 'Credentials' ?></th><th><?= is_rtl() ? 'البيئة' : 'Environment' ?></th><th><?= is_rtl() ? 'الاتصال' : 'Connectivity' ?></th><th><?= is_rtl() ? 'الويب هوك' : 'Webhook' ?></th><th><?= is_rtl() ? 'العملة' : 'Currency' ?></th></tr></thead>
      <tbody>
        <?php
        $nconfig = ngenius_settings($pdo);
        $nConfigured = !empty($nconfig['ngenius_outlet_ref']) && !empty($nconfig['ngenius_api_key']);
        $nWebhook = !empty($nconfig['ngenius_webhook_secret']);
        $sWebhook = $secretConfigured['sadad_webhook_secret'];
        ?>
        <tr>
          <td><b>SADAD</b></td>
          <td><?= $missing ? '✗' : '✓' ?></td>
          <td><?= $env === 'live' ? 'Live' : 'Sandbox' ?></td>
          <td><?= $connected === true ? '✓' : ($connected === false ? '✗' : '—') ?></td>
          <td><?= $sWebhook ? '✓' : '—' ?></td>
          <td><?= htmlspecialchars($config['sadad_currency'] ?? 'SAR') ?></td>
        </tr>
        <tr>
          <td><b>N-Genius</b></td>
          <td><?= $nConfigured ? '✓' : '—' ?></td>
          <td><?= ($nconfig['ngenius_environment'] ?? 'sandbox') === 'production' ? 'Live' : 'Sandbox' ?></td>
          <td>—</td>
          <td><?= $nWebhook ? '✓' : '—' ?></td>
          <td><?= htmlspecialchars(ngenius_currency($nconfig)) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<script>
var sadadOriginalEnv = '<?= htmlspecialchars($env) ?>';
function sadadConfirmEnv(){
  var sel = document.getElementById('sadadEnv');
  if (!sel) return true;
  var next = sel.value;
  if (next !== sadadOriginalEnv && next === 'live') {
    return confirm('<?= is_rtl() ? "تحذير: أنت على وشك التبديل إلى بيئة الإنتاج (Live). قد تُنفَّذ عمليات دفع حقيقية. هل أنت متأكد؟" : "Warning: you are about to switch to Live. Real payments may be processed. Are you sure?" ?>');
  }
  if (next !== sadadOriginalEnv) {
    return confirm('<?= is_rtl() ? "تبديل البيئة سيفرغ المفاتيح المحفوظة إن لم تُدخل مفاتيح جديدة. المتابعة؟" : "Switching environment clears stored keys unless new credentials are entered. Continue?" ?>');
  }
  return true;
}
function sadadCopy(btn, value){
  navigator.clipboard.writeText(value).then(function(){
    var t = btn.textContent; btn.textContent = '✓'; setTimeout(function(){ btn.textContent = t; }, 1200);
  });
}
async function sadadTestConnection(){
  var out = document.getElementById('sadadTestResult');
  out.style.color = 'var(--muted)'; out.textContent = 'جارٍ الاختبار...';
  try {
    var res = await fetch('/admin/sadad-test.php?tenant=<?= urlencode($scopeValue) ?>');
    var data = await res.json();
    out.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
    out.textContent = (data.ok ? '✓ ' : '✗ ') + (data.message || '');
  } catch(e){ out.style.color = 'var(--danger)'; out.textContent = '✗ تعذّر تنفيذ الاختبار.'; }
}
async function sadadTestPayment(){
  var out = document.getElementById('sadadTestPayResult');
  out.style.color = 'var(--muted)'; out.textContent = 'جارٍ التنفيذ...';
  var body = new URLSearchParams({
    csrf_token: '<?= htmlspecialchars(csrf_token()) ?>',
    tenant: '<?= htmlspecialchars($scopeValue) ?>',
    amount: document.getElementById('testAmount').value,
    name: document.getElementById('testName').value,
    email: document.getElementById('testEmail').value,
    phone: document.getElementById('testPhone').value,
    invoice: document.getElementById('testInvoice').value
  });
  try {
    var res = await fetch('/admin/sadad-test.php', { method:'POST', body: body });
    var data = await res.json();
    out.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
    out.textContent = (data.ok ? '✓ ' : '✗ ') + (data.message || '');
  } catch(e){ out.style.color = 'var(--danger)'; out.textContent = '✗ تعذّر تنفيذ الاختبار.'; }
}
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
