<?php
/**
 * Edit one employee's permissions (Execution 34).
 *
 * Renders the grantable modules with a checkbox per action, saves the selection, and shows
 * what the account currently holds. Only `super_admin` may open it: `staff-edit.php` is
 * mapped to the `staff` module, which is marked `grantable = 0`.
 *
 * The form posts a module => actions map. `admin_staff_set_permissions()` is the authority on
 * what is actually stored: it drops non-grantable modules and unknown actions, so posting
 * `finance[delete]` or `staff[view]` stores nothing rather than creating a row that looks
 * like access.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/admin_permissions.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/admin_staff_service.php';
require_once __DIR__ . '/includes/admin_ui.php';

$activeAdmin = 'staff';
$pageTitle = adm('صلاحيات الموظف', 'Employee permissions');

$staffId = (int)($_GET['id'] ?? $_POST['staff_id'] ?? 0);
$target = $staffId > 0 ? admin_staff_account($pdo, $staffId) : null;

if ($target === null || (string)$target['role'] !== 'staff') {
    require_once __DIR__ . '/includes/admin_header.php';
    echo '<div class="app-content"><div class="card"><p>' . h(adm('حساب الموظف غير موجود.', 'That staff account does not exist.')) . '</p>'
       . '<a class="btn btn-outline" href="/admin/staff.php">' . h(adm('رجوع', 'Back')) . '</a></div></div>';
    require_once __DIR__ . '/includes/admin_footer.php';
    exit;
}

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['perm'] ?? [];
    if (!is_array($posted)) $posted = [];

    // Normalize into module => list<action>. Only strings are accepted, so a nested array
    // cannot reach the permission writer.
    $grants = [];
    foreach ($posted as $moduleKey => $actions) {
        if (!is_string($moduleKey) || !is_array($actions)) continue;
        $clean = [];
        foreach ($actions as $actionKey) {
            if (is_string($actionKey)) $clean[] = $actionKey;
        }
        if ($clean) $grants[$moduleKey] = $clean;
    }

    $stored = admin_staff_set_permissions($pdo, $staffId, $grants, (int)$_SESSION['admin_id']);
    admin_staff_audit($pdo, 'admin.staff_permissions_changed', $staffId, (string)$target['full_name'], (int)$_SESSION['admin_id']);
    $notice = adm("تم حفظ الصلاحيات ($stored منحة).", "Permissions saved ($stored grants).");
}

$current = admin_staff_permissions($pdo, $staffId);
$modules = admin_staff_module_catalogue($pdo);
$actions = admin_staff_actions();

// Sensitive modules are listed separately, and read-only. Showing them explains what the
// employee does NOT have; offering them as checkboxes would suggest they could be granted.
$grantableModules = array_values(array_filter($modules, fn($m) => (int)$m['grantable'] === 1));
$blockedModules = array_values(array_filter($modules, fn($m) => (int)$m['grantable'] !== 1));

require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="pg-head">
    <div>
      <h1><?= h(adm('صلاحيات الموظف', 'Employee permissions')) ?></h1>
      <p><?= h($target['full_name']) ?> · <span class="aff-mono"><?= h($target['email']) ?></span></p>
    </div>
    <div class="pg-tools">
      <a class="btn btn-outline" href="/admin/staff.php"><?= h(adm('رجوع إلى الموظفين', 'Back to staff')) ?></a>
    </div>
  </div>

  <?php if ($notice): ?><div class="form-alert success"><p><?= h($notice) ?></p></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><p><?= h($error) ?></p></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="staff_id" value="<?= $staffId ?>">

    <div class="card" style="margin-block-end:18px">
      <div class="card-h"><h3><?= h(adm('الوحدات القابلة للمنح', 'Grantable modules')) ?></h3>
        <small><?= h(adm('اتركها فارغة لمنع الوصول بالكامل', 'Leave all unchecked to deny everything')) ?></small></div>
      <div style="overflow-x:auto">
      <table class="admin-table">
        <thead><tr>
          <th><?= h(adm('الوحدة', 'Module')) ?></th>
          <?php foreach ($actions as $key => $label): ?>
            <th style="text-align:center"><?= h(adm($label['ar'], $label['en'])) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($grantableModules as $module):
            $mk = (string)$module['module_key'];
            $held = $current[$mk] ?? []; ?>
          <tr>
            <td>
              <b><?= h(adm($module['label_ar'], $module['label_en'])) ?></b>
              <?php if (!empty($module['description_ar'])): ?>
                <br><small style="color:var(--muted)"><?= h(adm($module['description_ar'], $module['description_en'] ?? '')) ?></small>
              <?php endif; ?>
            </td>
            <?php foreach ($actions as $key => $label): ?>
              <td style="text-align:center">
                <input type="checkbox" name="perm[<?= h($mk) ?>][]" value="<?= h($key) ?>"
                       <?= in_array($key, $held, true) ? 'checked' : '' ?>
                       aria-label="<?= h($mk . ' ' . $label['en']) ?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p style="margin:12px 0 0;color:var(--muted);font-size:.88rem">
        <?= h(adm('«عرض» تفتح الصفحة للقراءة فقط. «تعديل» و«إنشاء» و«حذف» و«اعتماد» و«تصدير» تُفحص عند كل طلب POST.',
                     '"View" opens the page read-only. "Edit", "Create", "Delete", "Approve" and "Export" are checked on every POST request.')) ?>
      </p>
    </div>

    <div class="card" style="margin-block-end:18px">
      <div class="card-h"><h3><?= h(adm('وحدات محظورة دائماً', 'Always blocked')) ?></h3></div>
      <p style="margin:0 0 12px;color:var(--muted);font-size:.9rem">
        <?= h(adm('هذه الوحدات لا يمكن منحها لأي موظف مقيّد، ولا حتى بطلب مُعدَّل يدوياً. صلاحيات لوحة التحكم لا تفتحها.',
                     'These modules cannot be granted to any restricted employee, not even by a hand-crafted request. Dashboard access does not open them.')) ?>
      </p>
      <ul style="columns:2;gap:20px;margin:0;padding-inline-start:20px;line-height:1.9">
        <?php foreach ($blockedModules as $module): ?>
          <li><?= h(adm($module['label_ar'], $module['label_en'])) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <button class="btn btn-primary" type="submit"><?= h(adm('حفظ الصلاحيات', 'Save permissions')) ?></button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
