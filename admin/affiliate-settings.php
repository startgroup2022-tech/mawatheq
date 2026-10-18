<?php
/**
 * Programme settings console (Execution 31).
 *
 * The knobs that decide whether the programme runs at all, how long an attribution
 * lasts, and what a payout needs before it can be approved. Two of these are safety
 * switches rather than preferences:
 *
 *   - `affiliate_program_enabled` is off by default. Turning it on is the act that
 *     starts recording commissions, so the form says so plainly.
 *   - `affiliate_dual_approval_threshold` is the amount at or above which two
 *     different administrators must approve a payout. Setting it to zero disables
 *     that control; the form warns rather than silently accepting it.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';

$error = null;
$success = null;

$numericKeys = [
    'affiliate_attribution_days' => [1, 730],
    'affiliate_cookie_days' => [1, 730],
    'affiliate_default_hold_days' => [0, 365],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = [
        'affiliate_program_enabled' => isset($_POST['affiliate_program_enabled']) ? '1' : '0',
        'affiliate_require_consent' => isset($_POST['affiliate_require_consent']) ? '1' : '0',
        'affiliate_attribution_days' => (string)(int)($_POST['affiliate_attribution_days'] ?? 30),
        'affiliate_cookie_days' => (string)(int)($_POST['affiliate_cookie_days'] ?? 90),
        'affiliate_default_hold_days' => (string)(int)($_POST['affiliate_default_hold_days'] ?? 30),
        'affiliate_attribution_model' => in_array($_POST['affiliate_attribution_model'] ?? '', ['first_touch', 'last_touch'], true) ? $_POST['affiliate_attribution_model'] : 'first_touch',
        'affiliate_payout_currency' => currency_normalize((string)($_POST['affiliate_payout_currency'] ?? 'BHD')),
        'affiliate_min_payout' => (string)($_POST['affiliate_min_payout'] ?? '50.000'),
        'affiliate_dual_approval_threshold' => (string)($_POST['affiliate_dual_approval_threshold'] ?? '500.000'),
        'affiliate_default_commission_percent' => (string)($_POST['affiliate_default_commission_percent'] ?? '10.000'),
    ];

    foreach (['affiliate_min_payout', 'affiliate_dual_approval_threshold', 'affiliate_default_commission_percent'] as $moneyKey) {
        if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $input[$moneyKey])) {
            $error = 'قيمة غير صالحة: ' . $moneyKey;
            break;
        }
    }

    if ($error === null) {
        try {
            $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach ($input as $k => $v) $stmt->execute([$k, $v]);
            affiliate_settings_reload();
            log_action($pdo, 'تحديث إعدادات برنامج الشركاء', 'affiliate_settings', 0);
            $success = 'تم حفظ الإعدادات.';
        } catch (Throwable $e) {
            $error = 'تعذّر حفظ الإعدادات.';
        }
    }
}

$settings = affiliate_settings($pdo);

$activeAdmin = 'affiliate-settings';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>إعدادات برنامج الشركاء</h2>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if (!$settings['program_enabled']): ?>
    <div class="form-alert">البرنامج معطّل حالياً. لن تُسجَّل أي عمولة أو إحالة حتى يتم تفعيله من هذا النموذج.</div>
  <?php endif; ?>

  <form method="post" class="dash-card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <h3>التشغيل</h3>
    <div class="grid-2">
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="affiliate_program_enabled" value="1" <?= $settings['program_enabled'] ? 'checked' : '' ?>>
        تفعيل البرنامج (بدء تسجيل العمولات)
      </label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="affiliate_require_consent" value="1" <?= $settings['require_consent'] ? 'checked' : '' ?>>
        اشتراط الموافقة قبل التتبع
      </label>
    </div>

    <h3 style="margin-top:18px">الإحالة</h3>
    <div class="grid-2">
      <label>نموذج الإحالة
        <select name="affiliate_attribution_model">
          <option value="first_touch" <?= $settings['attribution_model'] === 'first_touch' ? 'selected' : '' ?>>أول لمسة</option>
          <option value="last_touch" <?= $settings['attribution_model'] === 'last_touch' ? 'selected' : '' ?>>آخر لمسة</option>
        </select>
      </label>
      <label>مدة الإحالة (أيام)
        <input type="number" name="affiliate_attribution_days" min="1" max="730" value="<?= (int)$settings['attribution_days'] ?>">
      </label>
      <label>مدة الكوكيز (أيام)
        <input type="number" name="affiliate_cookie_days" min="1" max="730" value="<?= (int)$settings['cookie_days'] ?>">
      </label>
      <label>مدة الحجز الافتراضية (أيام)
        <input type="number" name="affiliate_default_hold_days" min="0" max="365" value="<?= (int)$settings['hold_days'] ?>">
      </label>
    </div>

    <h3 style="margin-top:18px">المالية</h3>
    <div class="grid-2">
      <label>عملة الصرف
        <input type="text" name="affiliate_payout_currency" value="<?= htmlspecialchars((string)$settings['payout_currency']) ?>" maxlength="3">
      </label>
      <label>الحد الأدنى للصرف
        <input type="text" name="affiliate_min_payout" value="<?= htmlspecialchars((string)$settings['min_payout']) ?>">
      </label>
      <label>حد الاعتماد المزدوج
        <input type="text" name="affiliate_dual_approval_threshold" value="<?= htmlspecialchars((string)$settings['dual_approval_threshold']) ?>">
      </label>
      <label>نسبة العمولة الافتراضية (%)
        <input type="text" name="affiliate_default_commission_percent" value="<?= htmlspecialchars((string)$settings['affiliate_default_commission_percent']) ?>">
      </label>
    </div>

    <p style="font-size:12.5px;color:var(--muted);margin:14px 0 0">
      قيمة صفرية لحد الاعتماد المزدوج تلغي اشتراط معتمدين اثنين. اتركه مرتفعاً إلا إذا كان لديك سبب تشغيلي واضح.
    </p>

    <button class="btn btn-primary" type="submit" style="margin-top:14px">حفظ الإعدادات</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
