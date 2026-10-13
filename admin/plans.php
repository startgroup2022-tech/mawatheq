<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';

$error = null; $success = null;
$editingPlan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
    if ($action === 'save_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $data = [
            'name_ar' => trim($_POST['name_ar'] ?? ''),
            'name_en' => trim($_POST['name_en'] ?? ''),
            'description_ar' => trim($_POST['description_ar'] ?? ''),
            'description_en' => trim($_POST['description_en'] ?? ''),
            'price_monthly' => (float)($_POST['price_monthly'] ?? 0),
            'price_yearly' => ($_POST['price_yearly'] ?? '') !== '' ? (float)$_POST['price_yearly'] : null,
            'ai_queries_limit' => ($_POST['ai_queries_limit'] ?? '') !== '' ? (int)$_POST['ai_queries_limit'] : null,
            'document_analysis_limit' => ($_POST['document_analysis_limit'] ?? '') !== '' ? (int)$_POST['document_analysis_limit'] : null,
            'consultations_included' => (int)($_POST['consultations_included'] ?? 0),
            'is_corporate' => isset($_POST['is_corporate']) ? 1 : 0,
            'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
            'status' => in_array($_POST['status'] ?? '', ['active','hidden'], true) ? $_POST['status'] : 'active',
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($data['name_ar'] === '' || $data['name_en'] === '') {
            $error = 'اسم الباقة (عربي وإنجليزي) مطلوب.';
        } else {
            if ($planId) {
                $pdo->prepare('UPDATE plans SET name_ar=?,name_en=?,description_ar=?,description_en=?,price_monthly=?,price_yearly=?,ai_queries_limit=?,document_analysis_limit=?,consultations_included=?,is_corporate=?,is_featured=?,status=?,sort_order=? WHERE id=?')
                    ->execute(array_merge(array_values($data), [$planId]));
                log_action($pdo, "تعديل باقة '" . $data['name_ar'] . "'", 'plan', $planId);
                $success = 'تم تحديث الباقة بنجاح. الحدود والمزايا الجديدة سارية فوراً على جميع المشتركين الحاليين بهذه الباقة.';
            } else {
                $pdo->prepare('INSERT INTO plans (name_ar,name_en,description_ar,description_en,price_monthly,price_yearly,ai_queries_limit,document_analysis_limit,consultations_included,is_corporate,is_featured,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_values($data));
                log_action($pdo, "إضافة باقة جديدة '" . $data['name_ar'] . "'", 'plan', (int)$pdo->lastInsertId());
                $success = 'تمت إضافة الباقة بنجاح.';
            }
        }
    } elseif ($action === 'toggle_status') {
        $planId = (int)$_POST['plan_id'];
        $pdo->prepare("UPDATE plans SET status = IF(status='active','hidden','active') WHERE id=?")->execute([$planId]);
        log_action($pdo, 'تفعيل/تعطيل باقة #' . $planId, 'plan', $planId);
        header('Location: /admin/plans.php'); exit;
    } elseif ($action === 'delete_plan') {
        $planId = (int)$_POST['plan_id'];
        $count = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE plan_id=?');
        $count->execute([$planId]);
        if ((int)$count->fetch()['c'] > 0) {
            $error = 'لا يمكن حذف هذه الباقة لوجود مشتركين فيها حالياً. عطّلها بدلاً من ذلك، أو انقل المشتركين لباقة أخرى أولاً.';
        } else {
            $pdo->prepare('DELETE FROM plans WHERE id=?')->execute([$planId]);
            log_action($pdo, 'حذف باقة #' . $planId, 'plan', $planId);
            $success = 'تم حذف الباقة.';
        }
    } elseif ($action === 'add_feature') {
        $planId = (int)$_POST['plan_id'];
        $fAr = trim($_POST['feature_ar'] ?? '');
        $fEn = trim($_POST['feature_en'] ?? '');
        if ($fAr && $fEn) {
            $pdo->prepare('INSERT INTO plan_features (plan_id, feature_ar, feature_en, sort_order) VALUES (?,?,?,0)')->execute([$planId, $fAr, $fEn]);
        }
        header('Location: /admin/plans.php'); exit;
    } elseif ($action === 'delete_feature') {
        $pdo->prepare('DELETE FROM plan_features WHERE id=?')->execute([(int)$_POST['feature_id']]);
        header('Location: /admin/plans.php'); exit;
    }
    } catch (PDOException $e) {
        error_log('Admin plan action failed: ' . $e->getMessage());
        $error = 'تعذر حفظ التغيير. تحقق من ترقية جدول الباقات، ثم راجع سجل أخطاء PHP في cPanel.';
        $editingPlan = isset($planId) ? (int)$planId : null;
    }

    if ($error) {
        $editingPlan = $planId ?? null;
    }
}

$plans = $pdo->query('SELECT * FROM plans ORDER BY sort_order, price_monthly')->fetchAll();
$featsStmt = $pdo->prepare('SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order, id');
$subsStmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE plan_id=?');

$activeAdmin = 'plans';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>الباقات <span class="count-badge"><?= count($plans) ?></span></h2>
    <button type="button" class="btn btn-primary" onclick="openModal('planModal-new')">+ إضافة باقة</button>
  </div>

  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if (empty($plans)): ?>
    <div class="dash-card" style="text-align:center;color:var(--text-muted)">لا توجد باقات بعد. أضف أول باقة لتفعيل ربطها بحسابات العملاء.</div>
  <?php endif; ?>

  <?php foreach ($plans as $plan):
    $featsStmt->execute([$plan['id']]); $feats = $featsStmt->fetchAll();
    $subsStmt->execute([$plan['id']]); $subs = (int)$subsStmt->fetch()['c'];
    $isFree = (float)$plan['price_monthly'] === 0.0;
  ?>
  <div class="dash-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <h3 style="margin-bottom:4px">
          <?= htmlspecialchars($plan['name_ar']) ?>
          <span style="color:var(--text-muted);font-weight:400;font-size:.85rem">(<?= htmlspecialchars($plan['name_en']) ?>)</span>
          <?php if ($plan['is_featured']): ?><span class="status-pill status-confirmed">مميزة</span><?php endif; ?>
          <?php if ($plan['is_corporate']): ?><span class="status-pill status-pending">مؤسسات</span><?php endif; ?>
        </h3>
        <p style="margin:0;color:var(--text-muted);font-size:.85rem">
          <?= $isFree ? 'مجانية' : money_text($plan['price_monthly']) . '/شهر' ?>
          <?= $plan['price_yearly'] ? ' · ' . money_text($plan['price_yearly']) . '/سنة' : '' ?>
          · <?= $plan['ai_queries_limit'] === null ? 'استفسارات AI غير محدودة' : $plan['ai_queries_limit'] . ' استفسار AI/شهر' ?>
          · <?= $plan['document_analysis_limit'] === null ? 'تحليل مستندات غير محدود' : $plan['document_analysis_limit'] . ' تحليل مستند/شهر' ?>
        </p>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="count-badge">👤 <?= $subs ?> مشترك</span>
        <span class="status-pill status-<?= $plan['status']==='active' ? 'confirmed' : 'pending' ?>"><?= $plan['status']==='active' ? 'مفعّلة' : 'معطّلة' ?></span>
      </div>
    </div>

    <div style="margin-top:14px">
      <strong style="font-size:.85rem">المزايا المدرجة:</strong>
      <ul style="margin:8px 0 0;padding-inline-start:20px;font-size:.88rem;color:var(--text-muted)">
        <?php foreach ($feats as $f): ?>
          <li style="display:flex;align-items:center;justify-content:space-between;gap:8px;max-width:420px">
            <span><?= htmlspecialchars($f['feature_ar']) ?></span>
            <form method="post" onsubmit="return confirm('حذف هذه الميزة؟')" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_feature"><input type="hidden" name="feature_id" value="<?= $f['id'] ?>">
              <button type="submit" class="icon-btn danger" style="font-size:.72rem;padding:2px 6px">✕</button>
            </form>
          </li>
        <?php endforeach; ?>
        <?php if (empty($feats)): ?><li>لا توجد مزايا مدرجة بعد.</li><?php endif; ?>
      </ul>
      <form method="post" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_feature"><input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
        <input type="text" name="feature_ar" placeholder="ميزة جديدة (عربي)" style="flex:1;min-width:140px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
        <input type="text" name="feature_en" placeholder="Feature (English)" style="flex:1;min-width:140px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
        <button class="btn btn-outline btn-sm" type="submit">+ إضافة</button>
      </form>
    </div>

    <div style="display:flex;gap:8px;margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
      <button type="button" class="btn btn-outline btn-sm" onclick="openModal('planModal-<?= $plan['id'] ?>')">تعديل</button>
      <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
        <button class="btn btn-outline btn-sm" type="submit"><?= $plan['status']==='active' ? 'تعطيل' : 'تفعيل' ?></button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('حذف هذه الباقة نهائياً؟')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
        <button class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger)" type="submit">حذف</button>
      </form>
    </div>
  </div>

  <!-- نافذة تعديل هذه الباقة -->
  <div class="modal-backdrop<?= $editingPlan === (int)$plan['id'] ? ' is-open' : '' ?>" id="planModal-<?= $plan['id'] ?>">
    <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="button" class="modal-close" onclick="closeModal('planModal-<?= $plan['id'] ?>')">✕</button>
      <input type="hidden" name="action" value="save_plan"><input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
      <h3 style="margin-bottom:14px">تعديل باقة: <?= htmlspecialchars($plan['name_ar']) ?></h3>
      <?php include __DIR__ . '/includes/plan_form_fields.php'; ?>
      <button class="btn btn-primary btn-block" type="submit">حفظ التعديلات</button>
    </form>
  </div>
  <?php endforeach; ?>

  <!-- نافذة إضافة باقة جديدة -->
  <div class="modal-backdrop<?= $editingPlan === 0 ? ' is-open' : '' ?>" id="planModal-new">
    <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="button" class="modal-close" onclick="closeModal('planModal-new')">✕</button>
      <input type="hidden" name="action" value="save_plan"><input type="hidden" name="plan_id" value="0">
      <h3 style="margin-bottom:14px">إضافة باقة جديدة</h3>
      <?php $plan = ['name_ar'=>'','name_en'=>'','description_ar'=>'','description_en'=>'','price_monthly'=>0,'price_yearly'=>'','ai_queries_limit'=>'','document_analysis_limit'=>'','consultations_included'=>0,'is_corporate'=>0,'is_featured'=>0,'status'=>'active','sort_order'=>count($plans)]; ?>
      <?php include __DIR__ . '/includes/plan_form_fields.php'; ?>
      <button class="btn btn-primary btn-block" type="submit">إضافة الباقة</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
