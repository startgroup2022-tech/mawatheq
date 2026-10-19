<?php
/**
 * Restricted administrative staff (Execution 34).
 *
 * The main administrator creates employee accounts here and decides, per module, what each
 * one may do. Only `super_admin` may open this page — it is registered to the `staff` module
 * in admin_permissions.php, which is marked `grantable = 0`, so no employee can ever reach it
 * however their permissions are set.
 *
 * TWO RULES THIS PAGE ENFORCES IN ITS OWN RIGHT
 *
 *   1. An employee cannot create another administrator. Account creation is limited to
 *      super_admin by the guard, and `admin_staff_create()` additionally refuses to write a
 *      role other than `staff`. Privilege escalation therefore needs a super_admin session,
 *      not a crafted POST.
 *   2. Only grantable modules are offered. The catalogue marks confidential areas
 *      (case files, client records, finance, AI keys, security, backups, audit, permissions)
 *      as non-grantable, and `admin_staff_set_permissions()` drops them even if they are
 *      posted by hand.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/admin_permissions.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/auth_security.php';
// adm() is defined in admin_ui.php and is normally pulled in by admin_header.php. This page
// needs it for its title and notices before the header renders.
require_once __DIR__ . '/includes/admin_ui.php';

$activeAdmin = 'staff';
$pageTitle = adm('الموظفون والصلاحيات', 'Staff & permissions');

$notice = null;
$error = null;

/** Load the staff lifecycle helpers. Kept here rather than in a shared include because only
 *  this page and its editor call them. */
require_once __DIR__ . '/../includes/admin_staff_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $actorId = (int)$_SESSION['admin_id'];

    if ($action === 'create') {
        $result = admin_staff_create($pdo, [
            'full_name' => (string)($_POST['full_name'] ?? ''),
            'email'     => (string)($_POST['email'] ?? ''),
            'password'  => (string)($_POST['password'] ?? ''),
            'confirm'   => (string)($_POST['password_confirm'] ?? ''),
        ], $actorId);
        if ($result['ok']) {
            $notice = adm('تم إنشاء حساب الموظف. لم تُمنح أي صلاحية بعد — امنحها من زر «الصلاحيات».',
                          'Staff account created. No permissions were granted — use "Permissions" to assign them.');
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'deactivate' || $action === 'activate') {
        $result = admin_staff_set_active($pdo, $staffId, $action === 'activate', $actorId);
        if ($result['ok']) {
            $notice = $action === 'activate'
                ? adm('تم تفعيل الحساب.', 'Account activated.')
                : adm('تم إيقاف الحساب وإبطال جلساته الحالية.', 'Account deactivated and its active sessions revoked.');
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'revoke_sessions') {
        $result = admin_staff_revoke_sessions($pdo, $staffId, $actorId);
        if ($result['ok']) {
            $notice = adm('تم إبطال جميع جلسات الموظف. سيتعين عليه تسجيل الدخول من جديد.',
                          'All of the employee\'s sessions were revoked. They must sign in again.');
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'reset_access') {
        $result = admin_staff_reset_access($pdo, $staffId, (string)($_POST['new_password'] ?? ''),
                                            (string)($_POST['new_password_confirm'] ?? ''), $actorId);
        if ($result['ok']) {
            $notice = adm('تم تعيين كلمة مرور جديدة وإبطال الجلسات.', 'New password set and sessions revoked.');
        } else {
            $error = $result['error'];
        }
    }
}

$staffList = admin_staff_list($pdo);
$modules = admin_staff_module_catalogue($pdo);
$grantableCount = count(admin_staff_grantable_modules($pdo));
$blockedCount = count($modules) - $grantableCount;

// How many grants each account holds, so the list shows at a glance who can do what.
$grantCounts = [];
foreach ($staffList as $member) {
    $grantCounts[(int)$member['id']] = count(admin_staff_permissions($pdo, (int)$member['id']));
}

require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="pg-head">
    <div>
      <h1><?= h(adm('الموظفون والصلاحيات', 'Staff & permissions')) ?></h1>
      <p><?= h(adm('حسابات إدارية محدودة الوصول: أنشئ الحساب، ثم امنح كل وحدة على حدة ما تحتاجه فقط.',
                   'Restricted administrative accounts: create the account, then grant each module exactly what it needs.')) ?></p>
    </div>
  </div>

  <?php if ($notice): ?><div class="form-alert success"><p><?= h($notice) ?></p></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><p><?= h($error) ?></p></div><?php endif; ?>

  <div class="card" style="margin-block-end:18px">
    <div class="card-h"><h3><?= h(adm('كيف تعمل الصلاحيات', 'How permissions work')) ?></h3></div>
    <p style="line-height:1.9;margin:0">
      <?= h(adm(
        "الحساب الجديد لا يملك أي صلاحية: لا يرى سوى لوحة تحكم فارغة تطلب إسناد الوحدات. المنح يتم صراحةً لكل وحدة ولكل إجراء (عرض، إنشاء، تعديل، حذف، اعتماد، تصدير).",
        'A new account holds no permissions: it sees only an empty dashboard asking for modules to be assigned. Access is granted explicitly, per module and per action (view, create, edit, delete, approve, export).')) ?>
    </p>
    <p style="line-height:1.9;margin:10px 0 0;color:var(--muted)">
      <?= h(adm(
        "$blockedCount وحدة حساسة لا يمكن منحها لأي موظف مقيّد: ملفات القضايا، بيانات العملاء، الاستشارات، المالية، المدفوعات، مفاتيح الذكاء الاصطناعي، إعدادات النظام، الأمان، النسخ الاحتياطي، سجل التدقيق، والصلاحيات نفسها.",
        "$blockedCount sensitive modules can never be granted to a restricted employee: case files, client records, consultations, finance, payments, AI keys, system settings, security, backups, the audit log, and permissions themselves.")) ?>
    </p>
  </div>

  <div class="card" style="margin-block-end:18px">
    <div class="card-h"><h3><?= h(adm('إنشاء حساب موظف', 'Create a staff account')) ?></h3></div>
    <form method="post" class="aff-grid" style="display:grid;gap:14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label for="staff-name"><?= h(adm('الاسم الكامل', 'Full name')) ?></label>
        <input id="staff-name" type="text" name="full_name" required maxlength="150" autocomplete="off">
      </div>
      <div class="field">
        <label for="staff-email"><?= h(adm('البريد الإلكتروني', 'Email')) ?></label>
        <input id="staff-email" type="email" name="email" required maxlength="150" autocomplete="off">
      </div>
      <div class="field">
        <label for="staff-pass"><?= h(adm('كلمة المرور', 'Password')) ?></label>
        <input id="staff-pass" type="password" name="password" required minlength="10" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="staff-pass2"><?= h(adm('تأكيد كلمة المرور', 'Confirm password')) ?></label>
        <input id="staff-pass2" type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
      </div>
      <div class="field" style="align-self:end">
        <button class="btn btn-primary" type="submit"><?= h(adm('إنشاء الحساب', 'Create account')) ?></button>
      </div>
    </form>
    <p style="margin:10px 0 0;color:var(--muted);font-size:.88rem">
      <?= h(adm('يُنشأ الحساب بدور «موظف بصلاحيات محددة» فقط. لا يمكن إنشاء مدير عام من هذه الصفحة.',
                   'The account is always created with the "restricted staff" role. No administrator can be created from this page.')) ?>
    </p>
  </div>

  <div class="card">
    <div class="card-h"><h3><?= h(adm('حسابات الموظفين', 'Staff accounts')) ?></h3><small><?= count($staffList) ?></small></div>
    <?php if (!$staffList): ?>
      <p class="empty" style="padding:20px;text-align:center"><?= h(adm('لا توجد حسابات موظفين بعد.', 'No staff accounts yet.')) ?></p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="admin-table">
      <thead><tr>
        <th><?= h(adm('الموظف', 'Employee')) ?></th>
        <th><?= h(adm('البريد', 'Email')) ?></th>
        <th><?= h(adm('الحالة', 'Status')) ?></th>
        <th><?= h(adm('الصلاحيات', 'Permissions')) ?></th>
        <th><?= h(adm('آخر دخول', 'Last sign-in')) ?></th>
        <th><?= h(adm('إجراءات', 'Actions')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($staffList as $member): $mid = (int)$member['id']; $active = (int)$member['is_active'] === 1; ?>
        <tr>
          <td><b><?= h($member['full_name']) ?></b></td>
          <td class="aff-mono"><?= h($member['email']) ?></td>
          <td>
            <?php if ($active): ?>
              <span class="pill ok"><?= h(adm('نشط', 'Active')) ?></span>
            <?php else: ?>
              <span class="pill warn"><?= h(adm('موقوف', 'Disabled')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= (int)($grantCounts[$mid] ?? 0) ?> <?= h(adm('صلاحية', 'grants')) ?></td>
          <td><small><?= $member['last_login_at'] ? h(date('Y-m-d H:i', strtotime($member['last_login_at']))) : '—' ?></small></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="btn btn-outline btn-sm" href="/admin/staff-edit.php?id=<?= $mid ?>"><?= h(adm('الصلاحيات', 'Permissions')) ?></a>

              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="staff_id" value="<?= $mid ?>">
                <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>">
                <button class="btn btn-outline btn-sm" type="submit"><?= $active ? h(adm('إيقاف', 'Disable')) : h(adm('تفعيل', 'Enable')) ?></button>
              </form>

              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="staff_id" value="<?= $mid ?>">
                <input type="hidden" name="action" value="revoke_sessions">
                <button class="btn btn-outline btn-sm" type="submit"><?= h(adm('إبطال الجلسات', 'Revoke sessions')) ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-block-start:18px">
    <div class="card-h"><h3><?= h(adm('إعادة تعيين وصول موظف', 'Reset an employee\'s access')) ?></h3></div>
    <form method="post" style="display:grid;gap:14px;max-width:520px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="reset_access">
      <div class="field">
        <label for="reset-staff"><?= h(adm('الموظف', 'Employee')) ?></label>
        <select id="reset-staff" name="staff_id" required>
          <option value=""><?= h(adm('— اختر —', '— select —')) ?></option>
          <?php foreach ($staffList as $member): ?>
            <option value="<?= (int)$member['id'] ?>"><?= h($member['full_name']) ?> (<?= h($member['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="reset-p1"><?= h(adm('كلمة المرور الجديدة', 'New password')) ?></label>
        <input id="reset-p1" type="password" name="new_password" required minlength="10" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="reset-p2"><?= h(adm('تأكيد كلمة المرور', 'Confirm password')) ?></label>
        <input id="reset-p2" type="password" name="new_password_confirm" required minlength="10" autocomplete="new-password">
      </div>
      <div><button class="btn btn-primary" type="submit"><?= h(adm('تعيين وإبطال الجلسات', 'Set and revoke sessions')) ?></button></div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
