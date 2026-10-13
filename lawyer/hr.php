<?php
/**
 * HR workspace: employees, attendance, leave, schedules and management reports.
 *
 * Owner/partner manage; a plain lawyer sees the roster and their own branch's operational
 * view. Every write goes through includes/hr.php, which resolves the firm from the session
 * and enforces branch visibility. No salary or payroll data is collected anywhere here.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/branch_access.php';
require_once __DIR__ . '/../includes/hr.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$role = (string)(tenant_current($pdo)['role'] ?? '');
$error = null; $success = null;

if (!$firmId) {
    $activeLawyer = 'hr';
    require_once __DIR__ . '/includes/lawyer_header.php';
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'الموارد البشرية' : 'Human resources') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm', is_rtl() ? 'أنشئ مكتباً أولاً.' : 'Create a firm first.') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

$canManage = in_array($role, ['owner', 'partner'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        // Attendance self-service: a member may check themselves in/out; everything else is
        // restricted to owner/partner (or an explicit calendar.manage grant).
        if (in_array($action, ['check_in_self', 'check_out_self'], true)) {
            $empRow = $pdo->prepare('SELECT id FROM hr_employees WHERE firm_id=? AND lawyer_id=? LIMIT 1');
            $empRow->execute([$firmId, $lawyerId]);
            $selfEmp = (int)($empRow->fetchColumn() ?: 0);
            if (!$selfEmp) throw new RuntimeException(is_rtl() ? 'لا يوجد سجل موظف مرتبط بحسابك.' : 'No employee record is linked to your account.');
            $ok = $action === 'check_in_self' ? hr_attendance_check_in($pdo, $selfEmp, null, 'self') : hr_attendance_check_out($pdo, $selfEmp, null);
            $success = $ok ? (is_rtl() ? 'تم التسجيل.' : 'Recorded.') : (is_rtl() ? 'تعذّر التسجيل.' : 'Could not record.');
        } else {
            if (!$canManage && !tenant_can($pdo, 'calendar.manage')) {
                throw new RuntimeException(is_rtl() ? 'هذه العملية متاحة لمالك المكتب أو شريك.' : 'Only an owner or partner may do this.');
            }
            if ($action === 'add_employee') {
                hr_employee_create($pdo, [
                    'full_name' => $_POST['full_name'] ?? '', 'job_title' => $_POST['job_title'] ?? '',
                    'department_id' => $_POST['department_id'] ?? null, 'branch_id' => $_POST['branch_id'] ?? null,
                    'employment_type' => $_POST['employment_type'] ?? 'full_time',
                    'hire_date' => $_POST['hire_date'] ?? '', 'work_email' => $_POST['work_email'] ?? '',
                    'work_phone' => $_POST['work_phone'] ?? '', 'lawyer_id' => $_POST['lawyer_id'] ?? null,
                ]);
                $success = is_rtl() ? 'تمت إضافة الموظف.' : 'Employee added.';
            } elseif ($action === 'record_attendance') {
                hr_attendance_record($pdo, (int)$_POST['employee_id'], (string)$_POST['work_date'], (string)$_POST['attendance_status'],
                    ($_POST['check_in'] ?? '') !== '' ? $_POST['work_date'] . ' ' . $_POST['check_in'] . ':00' : null,
                    ($_POST['check_out'] ?? '') !== '' ? $_POST['work_date'] . ' ' . $_POST['check_out'] . ':00' : null,
                    $_POST['note'] ?? null);
                $success = is_rtl() ? 'تم تسجيل الحضور.' : 'Attendance recorded.';
            } elseif ($action === 'set_balance') {
                hr_leave_balance_set($pdo, (int)$_POST['employee_id'], (string)$_POST['leave_type'], (float)$_POST['entitled'], (int)($_POST['year'] ?? date('Y')));
                $success = is_rtl() ? 'تم تعديل الرصيد.' : 'Balance updated.';
            } elseif ($action === 'request_leave') {
                $r = hr_leave_request_create($pdo, (int)$_POST['employee_id'], (string)$_POST['leave_type'], (string)$_POST['start_date'], (string)$_POST['end_date'], $_POST['reason'] ?? null);
                if (empty($r['ok'])) throw new RuntimeException(is_rtl() ? 'تعذّر إنشاء الطلب.' : 'Could not create the request.');
                $success = $r['overdraw']
                    ? (is_rtl() ? 'تم إنشاء الطلب لكنه يتجاوز الرصيد المتبقي.' : 'Request created, but it exceeds the remaining balance.')
                    : (is_rtl() ? 'تم إنشاء طلب الإجازة.' : 'Leave request created.');
            } elseif ($action === 'decide_leave') {
                $r = hr_leave_request_decide($pdo, (int)$_POST['request_id'], (string)$_POST['decision'], $_POST['note'] ?? null);
                if (empty($r['ok'])) throw new RuntimeException(is_rtl() ? 'تعذّر تسجيل القرار (' . $r['error'] . ').' : 'Could not record the decision (' . $r['error'] . ').');
                $success = is_rtl() ? 'تم تسجيل قرار الإجازة.' : 'Leave decision recorded.';
            } elseif ($action === 'add_schedule') {
                hr_schedule_create($pdo, (int)$_POST['employee_id'], [
                    'title' => $_POST['title'] ?? '', 'schedule_type' => $_POST['schedule_type'] ?? 'shift',
                    'start_at' => $_POST['start_at'] ?? date('Y-m-d H:i:s'), 'end_at' => $_POST['end_at'] ?? '',
                    'location' => $_POST['location'] ?? '', 'branch_id' => $_POST['branch_id'] ?? null,
                ]);
                $success = is_rtl() ? 'تمت إضافة الجدول.' : 'Schedule added.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$activeLawyer = 'hr';
require_once __DIR__ . '/includes/lawyer_header.php';

$employees = hr_employee_list($pdo);
$monthFrom = date('Y-m-01');
$monthTo = date('Y-m-t');
$attendance = hr_attendance_list($pdo, ['from' => date('Y-m-d', strtotime('-7 days')), 'limit' => 200]);
$leaves = hr_leave_request_list($pdo);
$schedules = hr_schedule_list($pdo, ['from' => date('Y-m-d'), 'limit' => 100]);
$today = hr_attendance_today_summary($pdo);
$absence = hr_absence_report($pdo, $monthFrom, $monthTo);
$workload = hr_lawyer_workload_report($pdo, $monthFrom, $monthTo);
$branches = branch_access_firm_branches($pdo);
$departments = $pdo->prepare('SELECT id, name_ar, name_en FROM firm_departments WHERE firm_id=? AND status=\'active\' ORDER BY name_ar');
$departments->execute([$firmId]);
$departments = $departments->fetchAll();
$pendingLeave = count(array_filter($leaves, fn($l) => $l['status'] === 'pending'));
$members = $pdo->prepare("SELECT l.id, l.full_name FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE m.firm_id=? AND m.status='active' ORDER BY l.full_name");
$members->execute([$firmId]);
$members = $members->fetchAll();
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'الموارد البشرية والحضور' : 'HR & attendance',
      is_rtl() ? 'الموظفون، الحضور والانصراف، الإجازات، الجداول، ومؤشرات الأداء التشغيلية.' : 'Employees, attendance, leave, schedules and operational indicators.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <div class="grid-3">
    <?= ui_stat(is_rtl() ? 'الموظفون النشطون' : 'Active employees', (string)count($employees), 'teal') ?>
    <?= ui_stat(is_rtl() ? 'حاضر اليوم' : 'Present today', (string)($today['present'] + $today['remote']), 'teal') ?>
    <?= ui_stat(is_rtl() ? 'غائب اليوم' : 'Absent today', (string)$today['absent'], 'red') ?>
    <?= ui_stat(is_rtl() ? 'طلبات إجازة معلّقة' : 'Pending leave', (string)$pendingLeave, 'gold') ?>
  </div>

  <?php
  // A self-service employee links to this lawyer's own account.
  $selfStmt = $pdo->prepare('SELECT id, full_name FROM hr_employees WHERE firm_id=? AND lawyer_id=? LIMIT 1');
  $selfStmt->execute([$firmId, $lawyerId]);
  $self = $selfStmt->fetch();
  if ($self): ?>
    <section class="dash-card">
      <h2><?= is_rtl() ? 'تسجيل حضورك' : 'Your attendance' ?></h2>
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <button type="submit" name="action" value="check_in_self" class="btn btn-primary btn-sm"><?= is_rtl() ? 'تسجيل حضور' : 'Check in' ?></button>
        <button type="submit" name="action" value="check_out_self" class="btn btn-outline btn-sm"><?= is_rtl() ? 'تسجيل انصراف' : 'Check out' ?></button>
      </form>
    </section>
  <?php endif; ?>

  <!-- ============ Employees ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الموظفون' : 'Employees' ?></h2>
    <?php if ($employees): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الاسم' : 'Name' ?></th><th><?= is_rtl() ? 'المسمى' : 'Title' ?></th><th><?= is_rtl() ? 'القسم' : 'Department' ?></th><th><?= is_rtl() ? 'الفرع' : 'Branch' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th></tr></thead>
        <tbody>
          <?php foreach ($employees as $e): ?>
            <tr>
              <td><?= ui_e($e['full_name']) ?></td>
              <td><?= ui_e((string)($e['job_title'] ?? '—')) ?></td>
              <td><?= ui_e((string)($e['department_name_ar'] ?? '—')) ?></td>
              <td><?= ui_e((string)($e['branch_name_ar'] ?? '')) ?: (is_rtl() ? 'على مستوى المكتب' : 'Firm-wide') ?></td>
              <td><?= ui_badge($e['employment_status'] === 'active' ? 'success' : 'neutral', hr_employment_status_label((string)$e['employment_status'], is_rtl())) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا يوجد موظفون' : 'No employees') ?><?php endif; ?>

    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة موظف' : 'Add an employee' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_employee">
        <div class="grid-2">
          <label><?= is_rtl() ? 'الاسم الكامل' : 'Full name' ?><input type="text" name="full_name" maxlength="180" required></label>
          <label><?= is_rtl() ? 'المسمى الوظيفي' : 'Job title' ?><input type="text" name="job_title" maxlength="150"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'القسم' : 'Department' ?><select name="department_id"><option value=""><?= is_rtl() ? 'بدون' : 'None' ?></option>
            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= ui_e($d['name_ar']) ?></option><?php endforeach; ?>
          </select></label>
          <label><?= is_rtl() ? 'نوع التوظيف' : 'Employment type' ?>
            <select name="employment_type">
              <option value="full_time"><?= is_rtl() ? 'دوام كامل' : 'Full time' ?></option>
              <option value="part_time"><?= is_rtl() ? 'دوام جزئي' : 'Part time' ?></option>
              <option value="contract"><?= is_rtl() ? 'بعقد' : 'Contract' ?></option>
              <option value="intern"><?= is_rtl() ? 'متدرب' : 'Intern' ?></option>
            </select>
          </label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'تاريخ التعيين' : 'Hire date' ?><input type="date" name="hire_date"></label>
          <label><?= is_rtl() ? 'حساب محامٍ مرتبط (اختياري)' : 'Linked lawyer account (optional)' ?><select name="lawyer_id"><option value=""><?= is_rtl() ? 'بدون' : 'None' ?></option>
            <?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ui_e($m['full_name']) ?></option><?php endforeach; ?>
          </select></label>
        </div>
        <?php if ($branches): ?>
          <label><?= is_rtl() ? 'الفرع' : 'Branch' ?><select name="branch_id"><option value=""><?= is_rtl() ? 'على مستوى المكتب' : 'Firm-wide' ?></option>
            <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ui_e($b['name_ar']) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
        <div class="grid-2">
          <label><?= is_rtl() ? 'البريد الوظيفي' : 'Work email' ?><input type="email" name="work_email" maxlength="150"></label>
          <label><?= is_rtl() ? 'الهاتف' : 'Phone' ?><input type="text" name="work_phone" maxlength="30"></label>
        </div>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إضافة' : 'Add' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Attendance ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'سجل الحضور (7 أيام)' : 'Attendance (last 7 days)' ?></h2>
    <?php if ($attendance): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الموظف' : 'Employee' ?></th><th><?= is_rtl() ? 'التاريخ' : 'Date' ?></th><th><?= is_rtl() ? 'الحضور' : 'In' ?></th><th><?= is_rtl() ? 'الانصراف' : 'Out' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th></tr></thead>
        <tbody>
          <?php foreach ($attendance as $a): ?>
            <tr>
              <td><?= ui_e($a['full_name']) ?></td>
              <td><?= ui_e(ui_date($a['work_date'])) ?></td>
              <td><?= ui_e($a['check_in'] ? substr((string)$a['check_in'], 11, 5) : '—') ?></td>
              <td><?= ui_e($a['check_out'] ? substr((string)$a['check_out'], 11, 5) : '—') ?></td>
              <td><?= ui_badge($a['status'] === 'present' ? 'success' : ($a['status'] === 'absent' ? 'danger' : 'neutral'), hr_attendance_status_label((string)$a['status'], is_rtl())) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد سجلات' : 'No records') ?><?php endif; ?>

    <?php if ($canManage && $employees): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'تسجيل حضور يدوي' : 'Record attendance manually' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="record_attendance">
        <div class="grid-2">
          <label><?= is_rtl() ? 'الموظف' : 'Employee' ?><select name="employee_id" required>
            <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= ui_e($e['full_name']) ?></option><?php endforeach; ?>
          </select></label>
          <label><?= is_rtl() ? 'التاريخ' : 'Date' ?><input type="date" name="work_date" value="<?= ui_e(date('Y-m-d')) ?>" required></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'الحالة' : 'Status' ?>
            <select name="attendance_status">
              <option value="present"><?= is_rtl() ? 'حاضر' : 'Present' ?></option>
              <option value="late"><?= is_rtl() ? 'متأخر' : 'Late' ?></option>
              <option value="absent"><?= is_rtl() ? 'غائب' : 'Absent' ?></option>
              <option value="remote"><?= is_rtl() ? 'عن بُعد' : 'Remote' ?></option>
              <option value="holiday"><?= is_rtl() ? 'عطلة' : 'Holiday' ?></option>
            </select>
          </label>
          <label><?= is_rtl() ? 'ملاحظة' : 'Note' ?><input type="text" name="note" maxlength="500"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'وقت الحضور' : 'Check-in' ?><input type="time" name="check_in"></label>
          <label><?= is_rtl() ? 'وقت الانصراف' : 'Check-out' ?><input type="time" name="check_out"></label>
        </div>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'تسجيل' : 'Record' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Leave ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الإجازات' : 'Leave' ?></h2>
    <?php if ($leaves): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الموظف' : 'Employee' ?></th><th><?= is_rtl() ? 'النوع' : 'Type' ?></th><th><?= is_rtl() ? 'من' : 'From' ?></th><th><?= is_rtl() ? 'إلى' : 'To' ?></th><th><?= is_rtl() ? 'الأيام' : 'Days' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
        <tbody>
          <?php foreach ($leaves as $l): ?>
            <tr>
              <td><?= ui_e($l['full_name']) ?></td>
              <td><?= ui_e(hr_leave_type_label((string)$l['leave_type'], is_rtl())) ?></td>
              <td><?= ui_e(ui_date($l['start_date'])) ?></td>
              <td><?= ui_e(ui_date($l['end_date'])) ?></td>
              <td><?= ui_e(number_format((float)$l['days'], 1)) ?></td>
              <td><?= ui_badge($l['status'] === 'approved' ? 'success' : ($l['status'] === 'rejected' ? 'danger' : 'neutral'), $l['status']) ?></td>
              <td>
                <?php if ($canManage && $l['status'] === 'pending'): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="decide_leave">
                    <input type="hidden" name="request_id" value="<?= (int)$l['id'] ?>">
                    <button type="submit" name="decision" value="approved" class="btn btn-primary btn-sm"><?= is_rtl() ? 'اعتماد' : 'Approve' ?></button>
                    <button type="submit" name="decision" value="rejected" class="btn btn-outline btn-sm"><?= is_rtl() ? 'رفض' : 'Reject' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد طلبات' : 'No requests') ?><?php endif; ?>

    <?php if ($canManage && $employees): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'طلب إجازة' : 'New leave request' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="request_leave">
        <div class="grid-2">
          <label><?= is_rtl() ? 'الموظف' : 'Employee' ?><select name="employee_id" required>
            <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= ui_e($e['full_name']) ?></option><?php endforeach; ?>
          </select></label>
          <label><?= is_rtl() ? 'نوع الإجازة' : 'Leave type' ?><select name="leave_type">
            <?php foreach (hr_leave_types() as $lt): ?><option value="<?= ui_e($lt) ?>"><?= ui_e(hr_leave_type_label($lt, is_rtl())) ?></option><?php endforeach; ?>
          </select></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'من تاريخ' : 'From' ?><input type="date" name="start_date" required></label>
          <label><?= is_rtl() ? 'إلى تاريخ' : 'To' ?><input type="date" name="end_date" required></label>
        </div>
        <label><?= is_rtl() ? 'السبب' : 'Reason' ?><input type="text" name="reason" maxlength="500"></label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إنشاء الطلب' : 'Create request' ?></button>
      </form>

      <h3 class="firm-subhead"><?= is_rtl() ? 'ضبط رصيد الإجازات' : 'Set a leave balance' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="set_balance">
        <div class="grid-2">
          <label><?= is_rtl() ? 'الموظف' : 'Employee' ?><select name="employee_id" required>
            <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= ui_e($e['full_name']) ?></option><?php endforeach; ?>
          </select></label>
          <label><?= is_rtl() ? 'النوع' : 'Type' ?><select name="leave_type">
            <?php foreach (hr_leave_types() as $lt): ?><option value="<?= ui_e($lt) ?>"><?= ui_e(hr_leave_type_label($lt, is_rtl())) ?></option><?php endforeach; ?>
          </select></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'الرصيد (أيام)' : 'Entitlement (days)' ?><input type="number" step="0.5" min="0" name="entitled" value="30" required></label>
          <label><?= is_rtl() ? 'السنة' : 'Year' ?><input type="number" name="year" value="<?= (int)date('Y') ?>" min="2000" max="2100"></label>
        </div>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'حفظ الرصيد' : 'Save balance' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Schedules ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'جداول العمل' : 'Work schedules' ?></h2>
    <?php if ($schedules): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الموظف' : 'Employee' ?></th><th><?= is_rtl() ? 'العنوان' : 'Title' ?></th><th><?= is_rtl() ? 'النوع' : 'Type' ?></th><th><?= is_rtl() ? 'يبدأ' : 'Starts' ?></th><th><?= is_rtl() ? 'الموقع' : 'Location' ?></th></tr></thead>
        <tbody>
          <?php foreach ($schedules as $s): ?>
            <tr>
              <td><?= ui_e($s['full_name']) ?></td>
              <td><?= ui_e($s['title']) ?></td>
              <td><?= ui_e((string)$s['schedule_type']) ?></td>
              <td><?= ui_e(ui_date($s['start_at'], true)) ?></td>
              <td><?= ui_e((string)($s['location'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد جداول' : 'No schedules') ?><?php endif; ?>

    <?php if ($canManage && $employees): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة جدول' : 'Add a schedule' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_schedule">
        <div class="grid-2">
          <label><?= is_rtl() ? 'الموظف' : 'Employee' ?><select name="employee_id" required>
            <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= ui_e($e['full_name']) ?></option><?php endforeach; ?>
          </select></label>
          <label><?= is_rtl() ? 'النوع' : 'Type' ?><select name="schedule_type">
            <option value="shift"><?= is_rtl() ? 'وردية' : 'Shift' ?></option>
            <option value="court"><?= is_rtl() ? 'جلسة' : 'Court' ?></option>
            <option value="duty"><?= is_rtl() ? 'مناوبة' : 'Duty' ?></option>
            <option value="remote"><?= is_rtl() ? 'عن بُعد' : 'Remote' ?></option>
            <option value="training"><?= is_rtl() ? 'تدريب' : 'Training' ?></option>
          </select></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'العنوان' : 'Title' ?><input type="text" name="title" maxlength="255" required></label>
          <label><?= is_rtl() ? 'الموقع' : 'Location' ?><input type="text" name="location" maxlength="255"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'يبدأ في' : 'Starts at' ?><input type="datetime-local" name="start_at" required></label>
          <label><?= is_rtl() ? 'ينتهي في' : 'Ends at' ?><input type="datetime-local" name="end_at"></label>
        </div>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إضافة' : 'Add' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Reports ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'مؤشرات الأداء (هذا الشهر)' : 'Indicators (this month)' ?></h2>
    <?php if ($absence): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الموظف' : 'Employee' ?></th><th><?= is_rtl() ? 'غياب' : 'Absent' ?></th><th><?= is_rtl() ? 'تأخير' : 'Late' ?></th><th><?= is_rtl() ? 'إجازة' : 'Leave' ?></th><th><?= is_rtl() ? 'دقائق العمل' : 'Worked minutes' ?></th></tr></thead>
        <tbody>
          <?php foreach ($absence as $a): ?>
            <tr>
              <td><?= ui_e($a['full_name']) ?></td>
              <td><?= (int)$a['absent_days'] ?></td>
              <td><?= (int)$a['late_days'] ?></td>
              <td><?= (int)$a['leave_days'] ?></td>
              <td><?= (int)$a['worked_minutes'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد بيانات' : 'No data') ?><?php endif; ?>

    <h3 class="firm-subhead"><?= is_rtl() ? 'عبء العمل للمحامين' : 'Lawyer workload' ?></h3>
    <?php if ($workload): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'المحامي' : 'Lawyer' ?></th><th><?= is_rtl() ? 'قضايا مفتوحة' : 'Open cases' ?></th><th><?= is_rtl() ? 'إجمالي القضايا' : 'Total cases' ?></th><th><?= is_rtl() ? 'دقائق مسجّلة' : 'Logged minutes' ?></th></tr></thead>
        <tbody>
          <?php foreach ($workload as $w): ?>
            <tr>
              <td><?= ui_e($w['full_name']) ?></td>
              <td><?= (int)$w['open_cases'] ?></td>
              <td><?= (int)$w['total_cases'] ?></td>
              <td><?= (int)$w['logged_minutes'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد بيانات' : 'No data') ?><?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php';
