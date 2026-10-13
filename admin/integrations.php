<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/integrations.php';

$newToken = null;   // raw token, shown once after issue
$newSecret = null;  // raw webhook secret, shown once after create
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue_token') {
        $scopes = array_map('strval', (array)($_POST['scopes'] ?? []));
        $firmId = !empty($_POST['firm_id']) ? (int)$_POST['firm_id'] : null;
        $res = integration_token_issue($pdo, $firmId, (string)($_POST['label'] ?? ''), $scopes);
        if ($res['ok']) {
            $newToken = $res['token'];
            log_action($pdo, 'إصدار رمز تكامل خارجي: ' . ($_POST['label'] ?? ''), 'integration_token', (int)$res['id']);
        } else {
            $notice = ['type' => 'danger', 'text' => $res['error']];
        }
    } elseif ($action === 'revoke_token') {
        $id = (int)($_POST['token_id'] ?? 0);
        if (integration_token_revoke($pdo, $id)) {
            log_action($pdo, 'إلغاء رمز تكامل خارجي', 'integration_token', $id);
            $notice = ['type' => 'confirmed', 'text' => 'تم إلغاء الرمز.'];
        }
    } elseif ($action === 'create_webhook') {
        $types = array_map('strval', (array)($_POST['event_types'] ?? []));
        $firmId = !empty($_POST['firm_id']) ? (int)$_POST['firm_id'] : null;
        $res = integration_webhook_create($pdo, $firmId, (string)($_POST['label'] ?? ''), (string)($_POST['target_url'] ?? ''), $types);
        if ($res['ok']) {
            $newSecret = $res['secret'];
            log_action($pdo, 'إنشاء Webhook خارجي: ' . ($_POST['label'] ?? ''), 'integration_webhook', (int)$res['id']);
        } else {
            $notice = ['type' => 'danger', 'text' => $res['error']];
        }
    } elseif ($action === 'delete_webhook') {
        $id = (int)($_POST['webhook_id'] ?? 0);
        if (integration_webhook_delete($pdo, $id)) {
            log_action($pdo, 'حذف Webhook خارجي', 'integration_webhook', $id);
            $notice = ['type' => 'confirmed', 'text' => 'تم حذف الـ Webhook.'];
        }
    } elseif ($action === 'drain') {
        $r = integration_deliver_pending($pdo, 50);
        $notice = ['type' => 'confirmed', 'text' => sprintf('تمت محاولة %d تسليماً: %d ناجح، %d فاشل.', $r['attempted'], $r['delivered'], $r['failed'])];
    }
}

$activeAdmin = 'integrations';
require_once __DIR__ . '/includes/admin_header.php';

$tokens = integration_tokens_list($pdo, null);
$webhooks = integration_webhooks_list($pdo, null);
$deliveries = integration_deliveries_recent($pdo, 30, null);
$firms = [];
try { $firms = $pdo->query('SELECT id,name FROM law_firms ORDER BY name')->fetchAll(); } catch (Throwable $e) {}
$scopeCatalog = integration_scopes();
$eventCatalog = integration_event_catalog();
$pendingCount = 0;
try { $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM integration_deliveries WHERE status IN ('pending','failed','delivering')")->fetchColumn(); } catch (Throwable $e) {}
?>
<div class="app-content">
  <div class="toolbar">
    <h2>التكاملات الخارجية <span class="count-badge"><?= count($tokens) + count($webhooks) ?></span></h2>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="drain">
      <button class="btn btn-outline" type="submit">تشغيل التسليم الآن<?= $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?></button>
    </form>
  </div>

  <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px">
    هذه الصفحة تتيح ربط أنظمة خارجية بمواثيق بشكل آمن: رموز API محدودة الصلاحيات للقراءة فقط أو الكتابة،
    و Webhooks صادرة موقّعة تتيح للأنظمة الأخرى (محاسبة، لوحة تحكم، تطبيق جوال) أن تتلقى الأحداث فوراً.
    لا تُرسل أي بيانات خارج المنصة إلا إلى العناوين التي تُضاف هنا، وبموافقة صريحة من كل مكتب.
  </p>

  <?php if ($notice): ?>
    <div class="status-pill status-<?= htmlspecialchars($notice['type']) ?>" style="display:inline-block;margin-bottom:14px"><?= htmlspecialchars($notice['text']) ?></div>
  <?php endif; ?>

  <?php if ($newToken): ?>
    <div class="dash-card" style="border:2px solid var(--success);margin-bottom:16px">
      <h3 style="margin-bottom:8px">رمز API الجديد — انسخه الآن</h3>
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:10px">يُعرض هذا الرمز مرة واحدة فقط ولا يمكن استعادته لاحقاً. أرسله إلى النظام الخارجي عبر قناة آمنة.</p>
      <input type="text" dir="ltr" readonly value="<?= htmlspecialchars($newToken) ?>" onclick="this.select()" style="width:100%;font-family:monospace">
    </div>
  <?php endif; ?>

  <?php if ($newSecret): ?>
    <div class="dash-card" style="border:2px solid var(--success);margin-bottom:16px">
      <h3 style="margin-bottom:8px">مفتاح توقيع الـ Webhook — انسخه الآن</h3>
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:10px">
        أدخل هذا المفتاح في النظام المستقبِل للتحقق من التوقيع. التوقيع يُرسل في الترويسة
        <code dir="ltr">X-Mawatheq-Signature: sha256=HMAC(secret, timestamp + "." + body)</code>.
      </p>
      <input type="text" dir="ltr" readonly value="<?= htmlspecialchars($newSecret) ?>" onclick="this.select()" style="width:100%;font-family:monospace">
    </div>
  <?php endif; ?>

  <!-- API tokens -->
  <div class="table-wrap" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--border)">
      <h3 style="margin:0">رموز API المحدودة الصلاحيات</h3>
      <button class="btn btn-primary" type="button" onclick="openModal('issueTokenModal')">+ رمز جديد</button>
    </div>
    <table class="admin-table">
      <thead><tr><th>التسمية</th><th>المكتب</th><th>الصلاحيات</th><th>آخر استخدام</th><th>الحالة</th><th></th></tr></thead>
      <tbody>
        <?php if (!$tokens): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text-muted)">لا توجد رموز بعد.</td></tr>
        <?php else: foreach ($tokens as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['label']) ?></td>
            <td><?= $t['firm_id'] ? '#' . (int)$t['firm_id'] : '<span style="color:var(--text-muted)">كل المنصة</span>' ?></td>
            <td dir="ltr" style="font-size:.8rem"><?= htmlspecialchars(implode(', ', integration_scopes_parse($t['scopes']))) ?></td>
            <td><?= $t['last_used_at'] ? date('Y-m-d H:i', strtotime($t['last_used_at'])) : '—' ?></td>
            <td><span class="status-pill status-<?= $t['is_active'] && !$t['revoked_at'] ? 'confirmed' : 'cancelled' ?>"><?= $t['is_active'] && !$t['revoked_at'] ? 'نشط' : 'ملغى' ?></span></td>
            <td>
              <?php if ($t['is_active'] && !$t['revoked_at']): ?>
                <form method="post" onsubmit="return confirm('إلغاء هذا الرمز نهائياً؟')" style="margin:0">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="action" value="revoke_token">
                  <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                  <button class="icon-btn danger" type="submit" title="إلغاء">✕</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Webhooks -->
  <div class="table-wrap" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--border)">
      <h3 style="margin:0">Webhooks الصادرة</h3>
      <button class="btn btn-primary" type="button" onclick="openModal('createWebhookModal')">+ Webhook جديد</button>
    </div>
    <table class="admin-table">
      <thead><tr><th>التسمية</th><th>العنوان</th><th>الأحداث</th><th>الحالة</th><th></th></tr></thead>
      <tbody>
        <?php if (!$webhooks): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text-muted)">لا توجد Webhooks بعد.</td></tr>
        <?php else: foreach ($webhooks as $w): ?>
          <tr>
            <td><?= htmlspecialchars($w['label']) ?></td>
            <td dir="ltr" style="font-size:.8rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($w['target_url']) ?></td>
            <td dir="ltr" style="font-size:.8rem"><?= htmlspecialchars($w['event_types']) ?></td>
            <td><span class="status-pill status-<?= $w['is_active'] ? 'confirmed' : 'pending' ?>"><?= $w['is_active'] ? 'نشط' : 'موقوف' ?></span></td>
            <td>
              <form method="post" onsubmit="return confirm('حذف هذا الـ Webhook وكل سجلات تسليمه؟')" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_webhook">
                <input type="hidden" name="webhook_id" value="<?= (int)$w['id'] ?>">
                <button class="icon-btn danger" type="submit" title="حذف">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Delivery log -->
  <div class="table-wrap">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border)"><h3 style="margin:0">سجل التسليم</h3></div>
    <table class="admin-table">
      <thead><tr><th>#</th><th>الحدث</th><th>الوجهة</th><th>الحالة</th><th>HTTP</th><th>المحاولات</th><th>التاريخ</th></tr></thead>
      <tbody>
        <?php if (!$deliveries): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">لا يوجد تسليم بعد.</td></tr>
        <?php else: foreach ($deliveries as $d): ?>
          <tr>
            <td><?= (int)$d['id'] ?></td>
            <td dir="ltr" style="font-size:.8rem"><?= htmlspecialchars($d['event_type']) ?></td>
            <td dir="ltr" style="font-size:.8rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($d['target_url']) ?></td>
            <td><span class="status-pill status-<?= $d['status'] === 'delivered' ? 'confirmed' : ($d['status'] === 'failed' ? 'danger' : 'pending') ?>"><?= htmlspecialchars($d['status']) ?></span></td>
            <td><?= $d['http_code'] !== null ? (int)$d['http_code'] : '—' ?></td>
            <td><?= (int)$d['attempts'] ?></td>
            <td><?= date('Y-m-d H:i', strtotime($d['created_at'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Issue token modal -->
<div class="modal-backdrop" id="issueTokenModal">
  <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <button type="button" class="modal-close" onclick="closeModal('issueTokenModal')">✕</button>
    <input type="hidden" name="action" value="issue_token">
    <h3 style="margin-bottom:14px">إصدار رمز API جديد</h3>
    <div class="grid-2">
      <div class="field"><label>التسمية</label><input type="text" name="label" placeholder="نظام المحاسبة" required></div>
      <div class="field"><label>المكتب (اختياري)</label>
        <select name="firm_id"><option value="">كل المنصة</option>
          <?php foreach ($firms as $f): ?><option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>الصلاحيات</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <?php foreach ($scopeCatalog as $key => $label): ?>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400">
            <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($key) ?>"> <span dir="ltr"><?= htmlspecialchars($key) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="btn btn-primary btn-block" type="submit">إصدار الرمز</button>
  </form>
</div>

<!-- Create webhook modal -->
<div class="modal-backdrop" id="createWebhookModal">
  <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <button type="button" class="modal-close" onclick="closeModal('createWebhookModal')">✕</button>
    <input type="hidden" name="action" value="create_webhook">
    <h3 style="margin-bottom:14px">إنشاء Webhook صادر</h3>
    <div class="grid-2">
      <div class="field"><label>التسمية</label><input type="text" name="label" placeholder="نظام المحاسبة" required></div>
      <div class="field"><label>المكتب (اختياري)</label>
        <select name="firm_id"><option value="">كل المنصة</option>
          <?php foreach ($firms as $f): ?><option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>عنوان الوجهة</label><input type="text" name="target_url" dir="ltr" placeholder="https://partner.example.com/hooks/mawatheq" required>
      <small>رابط http أو https عام. تُوقَّع كل حزمة بمفتاح خاص يُنشأ تلقائياً ويُعرض مرة واحدة.</small>
    </div>
    <div class="field"><label>الأحداث</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <?php foreach ($eventCatalog as $key => $label): ?>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400">
            <input type="checkbox" name="event_types[]" value="<?= htmlspecialchars($key) ?>"> <span dir="ltr" style="font-size:.8rem"><?= htmlspecialchars($key) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <small>اتركها فارغة لاستقبال كل الأحداث.</small>
    </div>
    <button class="btn btn-primary btn-block" type="submit">إنشاء</button>
  </form>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
