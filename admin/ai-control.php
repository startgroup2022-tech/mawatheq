<?php
/**
 * admin/ai-control.php — AI control & privacy (Phase 3)
 *
 * One screen that answers, for the platform and every tenant:
 *   - What is the AI privacy posture (local only / external disabled / allowed)?
 *   - What actually left the platform, when, and for which feature?
 *   - How much AI was used, and at what token/cost (NULL when the provider reports nothing)?
 *   - Which tenant/user agents exist, and what tools can they call?
 *
 * Read-only over the audit tables, plus an edit action for a tenant's privacy policy. Every
 * change is written to the audit log. Nothing here fabricates a token count or a cost.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/ai_gateway.php';
require_once __DIR__ . '/../includes/audit_log.php';

$message = null; $error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'save_policy') {
            $tenantType = (string)($_POST['tenant_type'] ?? '');
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $mode = (string)($_POST['privacy_mode'] ?? '');
            $categories = array_map('strval', (array)($_POST['categories'] ?? []));
            $features = array_filter(array_map('trim', explode(',', (string)($_POST['external_features'] ?? ''))));
            if (!ai_tenant_settings_tenant_type_is_valid($tenantType)) throw new RuntimeException('نوع المستأجر غير صالح.');
            if ($tenantType !== 'platform' && $tenantId <= 0) throw new RuntimeException('معرّف المستأجر مطلوب.');
            if (!ai_tenant_save_policy($pdo, $tenantType, $tenantId, $mode, $categories, $features, 'admin', (int)$_SESSION['admin_id'])) {
                throw new RuntimeException('تعذّر حفظ سياسة الخصوصية.');
            }
            log_action($pdo, 'تحديث سياسة خصوصية AI: ' . $tenantType . '/' . $tenantId, 'ai_tenant_policy', $tenantId ?: null);
            $message = 'تم حفظ سياسة الخصوصية لهذا المستأجر.';
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (Throwable $e) {
        error_log('AI control action failed: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'تعذّر تنفيذ الإجراء.';
    }
}

$platformPolicy = ai_tenant_policy($pdo, 'platform', 0);

// Tenants: firms plus solo lawyers, with their current policy and 30-day usage.
$tenants = [];
$firms = $pdo->query('SELECT id, name FROM law_firms ORDER BY name LIMIT 500')->fetchAll();
foreach ($firms as $f) $tenants[] = ['tenant_type' => 'firm', 'tenant_id' => (int)$f['id'], 'label' => $f['name'] ?: ('#' . $f['id'])];
$solo = $pdo->query("SELECT l.id, l.full_name FROM lawyers l WHERE NOT EXISTS (SELECT 1 FROM law_firm_members m WHERE m.lawyer_id=l.id AND m.status='active') ORDER BY l.full_name LIMIT 500")->fetchAll();
foreach ($solo as $s) $tenants[] = ['tenant_type' => 'lawyer', 'tenant_id' => (int)$s['id'], 'label' => $s['full_name'] ?: ('#' . $s['id'])];

$policyByKey = [];
foreach ($pdo->query("SELECT * FROM ai_tenant_settings") as $row) $policyByKey[$row['tenant_type'] . ':' . $row['tenant_id']] = $row;

$usageByTenant = [];
foreach ($pdo->query("SELECT tenant_type, tenant_id, COUNT(*) c, SUM(COALESCE(input_tokens,0)+COALESCE(output_tokens,0)) tokens, SUM(COALESCE(estimated_cost,0)) cost FROM ai_usage_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY tenant_type, tenant_id") as $row) {
    $usageByTenant[$row['tenant_type'] . ':' . $row['tenant_id']] = $row;
}

$transmissions = $pdo->query("SELECT * FROM ai_external_transmissions ORDER BY id DESC LIMIT 60")->fetchAll();
$usageRecent = $pdo->query("SELECT * FROM ai_usage_events ORDER BY id DESC LIMIT 60")->fetchAll();
$agentCount = (int)$pdo->query('SELECT COUNT(*) FROM ai_user_agents')->fetchColumn();
$agentActivity = $pdo->query("SELECT a.tool_name, a.outcome, a.confirmation_state, a.created_at, u.name AS agent_name FROM ai_agent_activity_log a LEFT JOIN ai_user_agents u ON u.id=a.agent_id ORDER BY a.id DESC LIMIT 40")->fetchAll();

$activeAdmin = 'ai-control';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar"><h2>🛡️ التحكم في AI والخصوصية</h2></div>
  <?php if ($message): ?><div class="form-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="dash-card">
    <h3>سياسة المنصة</h3>
    <p class="field-hint">تنطبق على أدوات الذكاء الاصطناعي على مستوى المنصة (مكتبة الأنظمة، تحليل مستندات العملاء، وكلاء الإدارة). الوضع الحالي: <strong><?= htmlspecialchars(ai_privacy_mode_label($platformPolicy['privacy_mode'])) ?></strong></p>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_policy">
      <input type="hidden" name="tenant_type" value="platform">
      <input type="hidden" name="tenant_id" value="0">
      <div class="field"><label>وضع الخصوصية</label><div class="select-wrap"><select name="privacy_mode" class="pro-select">
        <?php foreach (AI_PRIVACY_MODES as $mode): ?><option value="<?= $mode ?>" <?= $platformPolicy['privacy_mode'] === $mode ? 'selected' : '' ?>><?= htmlspecialchars(ai_privacy_mode_label($mode)) ?></option><?php endforeach; ?>
      </select></div></div>
      <div class="field"><label>فئات البيانات المسموح إرسالها</label><div style="display:flex;flex-wrap:wrap;gap:12px">
        <?php foreach (AI_DATA_CATEGORIES as $key => $label): ?><label><input type="checkbox" name="categories[]" value="<?= $key ?>" <?= in_array($key, $platformPolicy['allowed_data_categories'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($label) ?></label><?php endforeach; ?>
      </div></div>
      <div class="field"><label>الميزات المسموحة (اتركه فارغاً للسماح بالكل)</label><input type="text" name="external_features" value="<?= htmlspecialchars(implode(',', $platformPolicy['external_features'])) ?>" placeholder="legal_core,document-ai,admin-agent"></div>
      <button class="btn btn-primary">حفظ سياسة المنصة</button>
    </form>
  </div>

  <div class="dash-card">
    <h3>سياسات المستأجرين <span class="count-badge"><?= count($tenants) ?></span></h3>
    <p class="field-hint">كل مكتب أو محامٍ منفرد له سياسته الخاصة. الافتراضي للحسابات الجديدة هو تعطيل الإرسال الخارجي حتى يوافق صاحب الحساب صراحةً.</p>
    <div class="table-wrap"><table class="admin-table">
      <thead><tr><th>المستأجر</th><th>النوع</th><th>الوضع</th><th>آخر 30 يوماً (استدعاءات)</th><th>الرموز</th><th>التكلفة التقديرية</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tenants as $t): $key = $t['tenant_type'] . ':' . $t['tenant_id']; $policy = $policyByKey[$key] ?? null; $usage = $usageByTenant[$key] ?? null; ?>
        <tr>
          <td><?= htmlspecialchars($t['label']) ?></td>
          <td><?= $t['tenant_type'] === 'firm' ? 'مكتب' : 'محامٍ منفرد' ?></td>
          <td><?= htmlspecialchars(ai_privacy_mode_label($policy['privacy_mode'] ?? 'external_disabled')) ?></td>
          <td><?= (int)($usage['c'] ?? 0) ?></td>
          <td><?= $usage && $usage['tokens'] !== null ? (int)$usage['tokens'] : '—' ?></td>
          <td><?= $usage && $usage['cost'] !== null ? number_format((float)$usage['cost'], 4) : '—' ?></td>
          <td>
            <details><summary class="field-hint">تعديل</summary>
            <form method="post" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="save_policy">
              <input type="hidden" name="tenant_type" value="<?= $t['tenant_type'] ?>">
              <input type="hidden" name="tenant_id" value="<?= (int)$t['tenant_id'] ?>">
              <div class="field"><label>الوضع</label><div class="select-wrap"><select name="privacy_mode" class="pro-select">
                <?php foreach (AI_PRIVACY_MODES as $mode): ?><option value="<?= $mode ?>" <?= ($policy['privacy_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars(ai_privacy_mode_label($mode)) ?></option><?php endforeach; ?>
              </select></div></div>
              <div class="field"><label>فئات البيانات</label><div style="display:flex;flex-wrap:wrap;gap:10px">
                <?php $allowed = $policy ? explode(',', (string)$policy['allowed_data_categories']) : ['user_prompt']; foreach (AI_DATA_CATEGORIES as $ck => $cl): ?><label><input type="checkbox" name="categories[]" value="<?= $ck ?>" <?= in_array($ck, $allowed, true) ? 'checked' : '' ?>> <?= htmlspecialchars($cl) ?></label><?php endforeach; ?>
              </div></div>
              <div class="field"><label>الميزات المسموحة (فارغ = الكل)</label><input type="text" name="external_features" value="<?= htmlspecialchars($policy['external_features'] ?? '') ?>"></div>
              <button class="btn btn-outline btn-sm">حفظ</button>
            </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="dash-card">
    <h3>سجل الإرسال الخارجي (آخر 60)</h3>
    <p class="field-hint">كل سطر يعني أن بيانات (أو محاولة إرسال بيانات) خرجت من المنصة إلى مزود AI. لا يُسجَّل أي محتوى أو مفاتيح هنا — فقط الفئة والحجم والنتيجة.</p>
    <div class="table-wrap"><table class="admin-table">
      <thead><tr><th>الوقت</th><th>المستأجر</th><th>الميزة</th><th>الفئة</th><th>المزود</th><th>الحجم</th><th>النتيجة</th></tr></thead>
      <tbody>
      <?php foreach ($transmissions as $tx): ?>
        <tr>
          <td><?= htmlspecialchars($tx['created_at']) ?></td>
          <td><?= htmlspecialchars($tx['tenant_type']) ?><?= $tx['tenant_id'] ? '/' . (int)$tx['tenant_id'] : '' ?></td>
          <td><?= htmlspecialchars($tx['feature']) ?></td>
          <td><?= htmlspecialchars(AI_DATA_CATEGORIES[$tx['data_category']] ?? $tx['data_category']) ?></td>
          <td><?= htmlspecialchars((string)$tx['provider']) ?></td>
          <td><?= $tx['payload_bytes'] !== null ? number_format((int)$tx['payload_bytes']) . ' بايت' : '—' ?></td>
          <td><span class="status-pill status-<?= $tx['outcome'] === 'allowed' ? 'confirmed' : 'cancelled' ?>"><?= htmlspecialchars($tx['outcome']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$transmissions): ?><tr><td colspan="7"><div class="empty-state"><p>لا يوجد سجل إرسال خارجي بعد.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="dash-card">
    <h3>سجل الاستخدام (آخر 60)</h3>
    <p class="field-hint">الرموز والتكلفة تظهر فقط عندما يُرجعها المزود؛ الخلايا الفارغة تعني «غير متاح» وليست صفراً.</p>
    <div class="table-wrap"><table class="admin-table">
      <thead><tr><th>الوقت</th><th>المستأجر</th><th>الميزة</th><th>النموذج</th><th>رموز الدخل</th><th>رموز الخرج</th><th>التكلفة</th><th>الحالة</th></tr></thead>
      <tbody>
      <?php foreach ($usageRecent as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['created_at']) ?></td>
          <td><?= htmlspecialchars($u['tenant_type']) ?><?= $u['tenant_id'] ? '/' . (int)$u['tenant_id'] : '' ?></td>
          <td><?= htmlspecialchars($u['feature']) ?></td>
          <td><?= htmlspecialchars((string)$u['model']) ?></td>
          <td><?= $u['input_tokens'] !== null ? (int)$u['input_tokens'] : '—' ?></td>
          <td><?= $u['output_tokens'] !== null ? (int)$u['output_tokens'] : '—' ?></td>
          <td><?= $u['estimated_cost'] !== null ? number_format((float)$u['estimated_cost'], 6) : '—' ?></td>
          <td><?= $u['success'] ? '✅' : ('⚠️ ' . htmlspecialchars((string)$u['error_message'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$usageRecent): ?><tr><td colspan="8"><div class="empty-state"><p>لا يوجد استخدام مسجّل بعد.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="dash-card">
    <h3>وكلاء المستخدمين <span class="count-badge"><?= $agentCount ?></span></h3>
    <p class="field-hint">وكلاء مقيّدون بأدوات محددة. الأدوات الكتابية (إنشاء مسودة/موعد/فاتورة) لا تنفّذ إلا بموافقة بشرية صريحة على نفس الأداة.</p>
    <div class="table-wrap"><table class="admin-table">
      <thead><tr><th>الوقت</th><th>الوكيل</th><th>الأداة</th><th>الموافقة</th><th>النتيجة</th></tr></thead>
      <tbody>
      <?php foreach ($agentActivity as $a): ?>
        <tr>
          <td><?= htmlspecialchars($a['created_at']) ?></td>
          <td><?= htmlspecialchars((string)($a['agent_name'] ?? '—')) ?></td>
          <td><?= htmlspecialchars($a['tool_name']) ?></td>
          <td><?= htmlspecialchars($a['confirmation_state']) ?></td>
          <td><span class="status-pill status-<?= $a['outcome'] === 'success' ? 'confirmed' : 'cancelled' ?>"><?= htmlspecialchars($a['outcome']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$agentActivity): ?><tr><td colspan="5"><div class="empty-state"><p>لا يوجد نشاط لوكلاء المستخدمين بعد.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
