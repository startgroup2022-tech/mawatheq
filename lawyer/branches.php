<?php
/**
 * Branch access management.
 *
 * Owner/partner assign members to branches and grant cross-branch access. The assignment
 * model is opt-in: a member with no branch assignment is firm-wide (see
 * includes/branch_access.php for why). This page makes the current state visible and lets an
 * owner tighten it.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/branch_access.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$role = (string)(tenant_current($pdo)['role'] ?? '');
$canManage = in_array($role, ['owner', 'partner'], true);
$error = null; $success = null;

if ($firmId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    if (!$canManage) { http_response_code(403); $error = is_rtl() ? 'هذه العملية متاحة لمالك المكتب أو شريك فقط.' : 'Only an owner or partner may do this.'; }
    else {
        $action = (string)($_POST['action'] ?? '');
        $target = (int)($_POST['lawyer_id'] ?? 0);
        try {
            if ($action === 'set_home_branch') {
                $br = ($_POST['branch_id'] ?? '') !== '' ? (int)$_POST['branch_id'] : null;
                if (!branch_access_set_member_branch($pdo, $target, $br)) throw new RuntimeException(is_rtl() ? 'تعذّر تحديث الفرع.' : 'Could not update the branch.');
                $success = is_rtl() ? 'تم تحديث فرع العضو.' : 'Member branch updated.';
            } elseif ($action === 'grant') {
                if (!branch_access_grant($pdo, $target, (int)$_POST['branch_id'], (string)($_POST['level'] ?? 'member'))) throw new RuntimeException(is_rtl() ? 'تعذّر منح الصلاحية.' : 'Could not grant access.');
                $success = is_rtl() ? 'تم منح الصلاحية.' : 'Access granted.';
            } elseif ($action === 'revoke') {
                branch_access_revoke($pdo, $target, (int)$_POST['branch_id']);
                $success = is_rtl() ? 'تم سحب الصلاحية.' : 'Access revoked.';
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$activeLawyer = 'branches';
require_once __DIR__ . '/includes/lawyer_header.php';

if (!$firmId) {
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'الفروع والصلاحيات' : 'Branches & access') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

$branches = branch_access_firm_branches($pdo);
$branchesById = [];
foreach ($branches as $b) { $branchesById[(int)$b['id']] = (string)$b['name_ar']; }
$memberStmt = $pdo->prepare("SELECT l.id, l.full_name, m.member_role, m.branch_id FROM law_firm_members m
                             JOIN lawyers l ON l.id=m.lawyer_id WHERE m.firm_id=? AND m.status='active' ORDER BY l.full_name");
$memberStmt->execute([$firmId]);
$members = $memberStmt->fetchAll();
$grants = [];
$gStmt = $pdo->prepare('SELECT lawyer_id, branch_id, access_level FROM member_branch_access WHERE firm_id=?');
$gStmt->execute([$firmId]);
foreach ($gStmt->fetchAll() as $g) { $grants[(int)$g['lawyer_id']][(int)$g['branch_id']] = $g['access_level']; }
$ctx = branch_access_context($pdo);
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'الفروع والصلاحيات' : 'Branches & access',
      is_rtl() ? 'تحديد فرع كل عضو ومنح صلاحيات الفروع الأخرى. العضو غير المحدد يعمل على مستوى المكتب كاملاً.' : 'Assign each member a branch and grant access to others. A member with no assignment works firm-wide.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <?php if (!$ctx['firm_wide']): ?>
    <div class="form-alert"><?= is_rtl() ? 'أنت مقيّد حالياً بفرع/فروع محددة.' : 'You are currently restricted to a specific branch set.' ?></div>
  <?php endif; ?>

  <?php if (!$branches): ?>
    <?= ui_empty_state(is_rtl() ? 'لا توجد فروع' : 'No branches', is_rtl() ? 'أضف الفروع من صفحة موقع المكتب.' : 'Add branches from the firm website page.') ?>
  <?php else: ?>
  <section class="dash-card">
    <h2><?= is_rtl() ? 'أعضاء المكتب' : 'Firm members' ?></h2>
    <table class="admin-table">
      <thead><tr><th><?= is_rtl() ? 'العضو' : 'Member' ?></th><th><?= is_rtl() ? 'الدور' : 'Role' ?></th><th><?= is_rtl() ? 'الفرع الأساسي' : 'Home branch' ?></th><th><?= is_rtl() ? 'صلاحيات إضافية' : 'Additional access' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($members as $m): ?>
          <?php $homeBranch = $m['branch_id'] !== null ? (int)$m['branch_id'] : null; ?>
          <tr>
            <td><?= ui_e($m['full_name']) ?></td>
            <td><?= ui_e((string)$m['member_role']) ?></td>
            <td><?= $homeBranch === null ? '<span class="muted">' . (is_rtl() ? 'على مستوى المكتب' : 'Firm-wide') . '</span>' : ui_e((string)($branchesById[$homeBranch] ?? $homeBranch)) ?></td>
            <td>
              <?php foreach ($branches as $b): if (isset($grants[(int)$m['id']][(int)$b['id']])): ?>
                <span class="badge"><?= ui_e($b['name_ar']) ?> · <?= ui_e($grants[(int)$m['id']][(int)$b['id']]) ?></span>
              <?php endif; endforeach; ?>
            </td>
            <td>
              <?php if ($canManage): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="set_home_branch">
                  <input type="hidden" name="lawyer_id" value="<?= (int)$m['id'] ?>">
                  <select name="branch_id"><option value=""><?= is_rtl() ? 'على مستوى المكتب' : 'Firm-wide' ?></option>
                    <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $homeBranch === (int)$b['id'] ? 'selected' : '' ?>><?= ui_e($b['name_ar']) ?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-outline btn-sm"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
                </form>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="grant">
                  <input type="hidden" name="lawyer_id" value="<?= (int)$m['id'] ?>">
                  <select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ui_e($b['name_ar']) ?></option><?php endforeach; ?></select>
                  <select name="level"><option value="member"><?= is_rtl() ? 'عضو' : 'Member' ?></option><option value="manager"><?= is_rtl() ? 'مدير فرع' : 'Manager' ?></option></select>
                  <button type="submit" class="btn btn-primary btn-sm"><?= is_rtl() ? 'منح' : 'Grant' ?></button>
                </form>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="lawyer_id" value="<?= (int)$m['id'] ?>">
                  <select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ui_e($b['name_ar']) ?></option><?php endforeach; ?></select>
                  <button type="submit" class="btn btn-danger btn-sm"><?= is_rtl() ? 'سحب' : 'Revoke' ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php';
