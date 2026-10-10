<?php
/**
 * Conflict-of-interest register and review queue.
 *
 * Firm members register the parties they know about, screen a matter, and work the review
 * queue. The decision form is only rendered for an authorized decider, but the authority is
 * re-checked server-side by conflict_review_decide() — the UI is a convenience, not the
 * control.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/branch_access.php';
require_once __DIR__ . '/../includes/conflict_engine.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$role = (string)(tenant_current($pdo)['role'] ?? '');
$error = null; $success = null;
$viewReviewId = (int)($_GET['review'] ?? 0);

if (!$firmId) {
    $activeLawyer = 'conflicts';
    require_once __DIR__ . '/includes/lawyer_header.php';
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'التعارض في المصالح' : 'Conflict of interest', is_rtl() ? 'فحص التعارض متاح للمكاتب القانونية.' : 'Conflict screening is for law firms.') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm', is_rtl() ? 'أنشئ مكتباً أولاً.' : 'Create a firm first.') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!tenant_can($pdo, 'cases.edit') && !in_array($role, ['owner', 'partner'], true)) {
            throw new RuntimeException(is_rtl() ? 'لا تملك صلاحية تعديل سجل التعارض.' : 'You cannot modify the conflict register.');
        }
        if ($action === 'add_party') {
            conflict_party_create($pdo, [
                'party_type' => $_POST['party_type'] ?? 'other',
                'name_ar' => $_POST['name_ar'] ?? '', 'name_en' => $_POST['name_en'] ?? '',
                'id_number' => $_POST['id_number'] ?? '', 'commercial_register' => $_POST['commercial_register'] ?? '',
                'country' => $_POST['country'] ?? '', 'notes' => $_POST['notes'] ?? '',
                'branch_id' => $_POST['branch_id'] ?? null,
            ]);
            $success = is_rtl() ? 'تم تسجيل الطرف.' : 'Party registered.';
        } elseif ($action === 'archive_party') {
            conflict_party_archive($pdo, (int)$_POST['party_id']);
            $success = is_rtl() ? 'تمت أرشفة الطرف.' : 'Party archived.';
        } elseif ($action === 'screen') {
            $subjects = [[
                'party_type' => $_POST['subject_type'] ?? 'opposing_party',
                'name_ar' => $_POST['subject_name_ar'] ?? '', 'name_en' => $_POST['subject_name_en'] ?? '',
                'id_number' => $_POST['subject_id_number'] ?? '', 'commercial_register' => $_POST['subject_cr'] ?? '',
            ]];
            if (trim((string)($subjects[0]['name_ar'] . $subjects[0]['name_en'])) === '') {
                throw new RuntimeException(is_rtl() ? 'أدخل اسم الطرف المطلوب فحصه.' : 'Enter the party name to screen.');
            }
            $res = conflict_screen_matter($pdo, $subjects, [
                'case_id' => !empty($_POST['case_id']) ? (int)$_POST['case_id'] : null,
                'branch_id' => $_POST['branch_id'] ?? null,
            ]);
            $success = $res['matches'] > 0
                ? sprintf(is_rtl() ? 'تم العثور على %d تطابق محتمل — راجع قائمة المراجعات.' : 'Found %d potential match(es) — see the review queue.', $res['matches'])
                : (is_rtl() ? 'لم يُعثر على تعارض مسجّل.' : 'No registered conflict found.');
        } elseif ($action === 'assign') {
            $ok = conflict_review_assign($pdo, (int)$_POST['review_id'], (int)$_POST['reviewer_id']);
            $success = $ok ? (is_rtl() ? 'تم إسناد المراجعة.' : 'Review assigned.') : null;
            if (!$ok) throw new RuntimeException(is_rtl() ? 'تعذّر الإسناد.' : 'Assignment failed.');
        } elseif ($action === 'decide') {
            $r = conflict_review_decide($pdo, (int)$_POST['review_id'], (string)$_POST['decision'], [
                'note' => $_POST['note'] ?? '',
                'override_reason' => $_POST['override_reason'] ?? '',
                'override_approved_by' => !empty($_POST['override_approved_by']) ? (int)$_POST['override_approved_by'] : 0,
            ]);
            if (empty($r['ok'])) {
                $msgs = [
                    'override_reason_required' => is_rtl() ? 'التجاوز يتطلب سبباً مكتوباً.' : 'An override requires a written reason.',
                    'approver_required' => is_rtl() ? 'التجاوز يتطلب موافقة مالك أو شريك.' : 'An override requires an owner/partner approval.',
                    'approver_not_authorized' => is_rtl() ? 'المعتمِد المحدد ليس مالكاً أو شريكاً في المكتب.' : 'The selected approver is not an owner/partner of this firm.',
                    'not_found' => is_rtl() ? 'المراجعة غير موجودة.' : 'Review not found.',
                ];
                throw new RuntimeException($msgs[$r['error']] ?? (is_rtl() ? 'تعذّر تسجيل القرار.' : 'Could not record the decision.'));
            }
            $success = is_rtl() ? 'تم تسجيل القرار في سجل التدقيق.' : 'Decision recorded in the audit trail.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$activeLawyer = 'conflicts';
require_once __DIR__ . '/includes/lawyer_header.php';

$parties = conflict_party_list($pdo, ['q' => $_GET['pq'] ?? '', 'party_type' => $_GET['pt'] ?? '']);
$reviews = conflict_review_list($pdo, ['status' => $_GET['rs'] ?? '', 'severity' => $_GET['rv'] ?? '', 'open_only' => ($_GET['open'] ?? '') === '1' ? 1 : 0]);
$viewReview = $viewReviewId ? conflict_review_get($pdo, $viewReviewId) : null;
$trail = $viewReview ? conflict_audit_trail($pdo, $viewReviewId) : [];
$reviewers = conflict_firm_reviewers($pdo);
$branches = branch_access_firm_branches($pdo);
$canManage = in_array($role, ['owner', 'partner'], true) || tenant_can($pdo, 'cases.edit');

$firmCaseStmt = $pdo->prepare('SELECT id, title FROM cases WHERE firm_id=? ORDER BY created_at DESC LIMIT 200');
$firmCaseStmt->execute([$firmId]);
$firmCases = $firmCaseStmt->fetchAll();
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'التعارض في المصالح' : 'Conflict of interest',
      is_rtl() ? 'سجّل الأطراف التي يعرفها المكتب، افحص القضية الجديدة، واحسم المراجعات بسجل تدقيق كامل.' : 'Register the parties the firm knows, screen a new matter, and resolve reviews with a full audit trail.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <div class="grid-3">
    <?= ui_stat(is_rtl() ? 'مراجعات مفتوحة' : 'Open reviews', (string)conflict_case_has_open($pdo, (int)($firmCases[0]['id'] ?? 0)), 'red') ?>
    <?= ui_stat(is_rtl() ? 'أطراف مسجّلة' : 'Registered parties', (string)count($parties), 'teal') ?>
    <?= ui_stat(is_rtl() ? 'تنبيهات غير مقروءة' : 'Unread alerts', (string)conflict_alerts_unread_count($pdo), 'amber') ?>
  </div>

  <?php if ($viewReview): ?>
    <!-- ============ Review detail + decision ============ -->
    <section class="dash-card" id="review">
      <h2><?= is_rtl() ? 'مراجعة تعارض #' : 'Conflict review #' ?><?= (int)$viewReview['id'] ?></h2>
      <?php
        $sevClass = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'muted'][$viewReview['severity']] ?? 'muted';
        $stClass = in_array($viewReview['status'], ['approved', 'cleared_with_override', 'closed'], true) ? 'active' : (in_array($viewReview['status'], ['open', 'under_review'], true) ? 'pending' : 'danger');
      ?>
      <p>
        <?= ui_badge($stClass, conflict_status_label((string)$viewReview['status'], is_rtl())) ?>
        <?= ui_badge($sevClass === 'danger' ? 'danger' : ($sevClass === 'warning' ? 'warning' : 'pending'), conflict_severity_label((string)$viewReview['severity'], is_rtl())) ?>
      </p>
      <table class="admin-table">
        <tr><th><?= is_rtl() ? 'الطرف المفحوص' : 'Subject' ?></th><td><?= ui_e($viewReview['subject_name_ar'] ?: $viewReview['subject_name_en'] ?: '—') ?></td></tr>
        <tr><th><?= is_rtl() ? 'الطرف المطابق' : 'Matched party' ?></th><td><?= ui_e($viewReview['matched_name_ar'] ?: $viewReview['matched_name_en'] ?: '—') ?></td></tr>
        <tr><th><?= is_rtl() ? 'نوع المطابقة' : 'Match type' ?></th><td><?= ui_e((string)$viewReview['match_type']) ?></td></tr>
        <tr><th><?= is_rtl() ? 'درجة التشابه' : 'Match score' ?></th><td><?= ui_e(number_format((float)$viewReview['match_score'], 1)) ?>%</td></tr>
        <tr><th><?= is_rtl() ? 'القضية' : 'Case' ?></th><td><?= ui_e($viewReview['case_title'] ?? '—') ?></td></tr>
        <?php if (!empty($viewReview['ai_assisted'])): ?>
          <tr><th><?= is_rtl() ? 'مساعدة AI (اقتراح)' : 'AI assistance (suggestion)' ?></th><td><?= ui_e((string)($viewReview['ai_rationale'] ?? '')) ?> <?= $viewReview['ai_confidence'] !== null ? '(' . ui_e(number_format((float)$viewReview['ai_confidence'], 0)) . '%)' : '' ?></td></tr>
        <?php endif; ?>
      </table>

      <?php if ($canManage && in_array($viewReview['status'], ['open', 'under_review'], true)): ?>
        <h3 class="firm-subhead"><?= is_rtl() ? 'تسجيل القرار' : 'Record a decision' ?></h3>
        <form method="post" class="stack-form">
          <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
          <input type="hidden" name="action" value="decide">
          <input type="hidden" name="review_id" value="<?= (int)$viewReview['id'] ?>">
          <label><?= is_rtl() ? 'القرار' : 'Decision' ?>
            <select name="decision" id="cf-decision" onchange="document.getElementById('cf-override').style.display=this.value==='cleared_with_override'?'block':'none'">
              <option value="approved"><?= is_rtl() ? 'اعتماد — لا يوجد تعارض فعلي' : 'Approve — no actual conflict' ?></option>
              <option value="rejected"><?= is_rtl() ? 'رفض — يوجد تعارض' : 'Reject — conflict exists' ?></option>
              <option value="cleared_with_override"><?= is_rtl() ? 'تجاوز بموافقة' : 'Clear with override' ?></option>
              <option value="closed"><?= is_rtl() ? 'إغلاق' : 'Close' ?></option>
            </select>
          </label>
          <label><?= is_rtl() ? 'ملاحظة' : 'Note' ?><textarea name="note" rows="2" maxlength="1000"></textarea></label>
          <div id="cf-override" style="display:none">
            <label><?= is_rtl() ? 'سبب التجاوز (إلزامي)' : 'Override reason (required)' ?><textarea name="override_reason" rows="2" maxlength="1000"></textarea></label>
            <label><?= is_rtl() ? 'المعتمِد (مالك أو شريك)' : 'Approver (owner or partner)' ?>
              <select name="override_approved_by">
                <?php foreach ($reviewers as $r): ?>
                  <option value="<?= (int)$r['id'] ?>"><?= ui_e($r['full_name']) ?> — <?= ui_e($r['member_role']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'حفظ القرار' : 'Save decision' ?></button>
        </form>
      <?php endif; ?>

      <?php if ($canManage && $reviewers): ?>
        <h3 class="firm-subhead"><?= is_rtl() ? 'إسناد المراجعة' : 'Assign the review' ?></h3>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="review_id" value="<?= (int)$viewReview['id'] ?>">
          <select name="reviewer_id">
            <?php foreach ($reviewers as $r): ?><option value="<?= (int)$r['id'] ?>"><?= ui_e($r['full_name']) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary"><?= is_rtl() ? 'إسناد' : 'Assign' ?></button>
        </form>
      <?php endif; ?>

      <?php if ($trail): ?>
        <h3 class="firm-subhead"><?= is_rtl() ? 'سجل التدقيق (غير قابل للتعديل)' : 'Audit trail (append-only)' ?></h3>
        <table class="admin-table">
          <thead><tr><th><?= is_rtl() ? 'التاريخ' : 'When' ?></th><th><?= is_rtl() ? 'الإجراء' : 'Action' ?></th><th><?= is_rtl() ? 'المنفّذ' : 'Actor' ?></th><th><?= is_rtl() ? 'التفاصيل' : 'Detail' ?></th></tr></thead>
          <tbody>
            <?php foreach ($trail as $t): ?>
              <tr>
                <td><?= ui_e(ui_date($t['created_at'], true)) ?></td>
                <td><?= ui_e((string)$t['action']) ?></td>
                <td><?= ui_e($t['actor_name'] ?? '—') ?></td>
                <td><?= ui_e((string)($t['detail'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- ============ Screening ============ -->
  <?php if ($canManage): ?>
  <section class="dash-card">
    <h2><?= is_rtl() ? 'فحص تعارض' : 'Screen for conflicts' ?></h2>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="screen">
      <div class="grid-2">
        <label><?= is_rtl() ? 'اسم الطرف (عربي)' : 'Party name (AR)' ?><input type="text" name="subject_name_ar" maxlength="255"></label>
        <label><?= is_rtl() ? 'الاسم (إنجليزي)' : 'Name (EN)' ?><input type="text" name="subject_name_en" maxlength="255"></label>
      </div>
      <div class="grid-2">
        <label><?= is_rtl() ? 'الصفة' : 'Role' ?>
          <select name="subject_type">
            <option value="opposing_party"><?= is_rtl() ? 'طرف خصم' : 'Opposing party' ?></option>
            <option value="client"><?= is_rtl() ? 'عميل' : 'Client' ?></option>
            <option value="related_person"><?= is_rtl() ? 'شخص ذو علاقة' : 'Related person' ?></option>
            <option value="related_company"><?= is_rtl() ? 'شركة ذات علاقة' : 'Related company' ?></option>
            <option value="witness"><?= is_rtl() ? 'شاهد' : 'Witness' ?></option>
          </select>
        </label>
        <label><?= is_rtl() ? 'القضية (اختياري)' : 'Case (optional)' ?>
          <select name="case_id"><option value=""><?= is_rtl() ? 'بدون' : 'None' ?></option>
            <?php foreach ($firmCases as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ui_e($c['title']) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="grid-2">
        <label><?= is_rtl() ? 'رقم الهوية' : 'ID number' ?><input type="text" name="subject_id_number" maxlength="80"></label>
        <label><?= is_rtl() ? 'السجل التجاري' : 'Commercial register' ?><input type="text" name="subject_cr" maxlength="80"></label>
      </div>
      <?php if ($branches): ?>
        <label><?= is_rtl() ? 'الفرع' : 'Branch' ?><select name="branch_id"><option value=""><?= is_rtl() ? 'على مستوى المكتب' : 'Firm-wide' ?></option>
          <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ui_e($b['name_ar']) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'فحص' : 'Screen' ?></button>
    </form>
  </section>
  <?php endif; ?>

  <!-- ============ Review queue ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'قائمة المراجعات' : 'Review queue' ?></h2>
    <?php if ($reviews): ?>
      <table class="admin-table">
        <thead><tr><th>#</th><th><?= is_rtl() ? 'الطرف' : 'Subject' ?></th><th><?= is_rtl() ? 'مطابقة' : 'Matched' ?></th><th><?= is_rtl() ? 'الخطورة' : 'Severity' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
        <tbody>
          <?php foreach ($reviews as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= ui_e($r['subject_name_ar'] ?: $r['subject_name_en'] ?: '—') ?></td>
              <td><?= ui_e($r['matched_name_ar'] ?: $r['matched_name_en'] ?: '—') ?> <span class="muted"><?= ui_e(number_format((float)$r['match_score'], 0)) ?>%</span></td>
              <td><?= ui_badge(in_array($r['severity'], ['critical', 'high'], true) ? 'danger' : 'pending', conflict_severity_label((string)$r['severity'], is_rtl())) ?></td>
              <td><?= ui_badge(in_array($r['status'], ['open', 'under_review'], true) ? 'pending' : 'active', conflict_status_label((string)$r['status'], is_rtl())) ?></td>
              <td><a class="btn btn-secondary btn-sm" href="?review=<?= (int)$r['id'] ?>"><?= is_rtl() ? 'فتح' : 'Open' ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد مراجعات' : 'No reviews') ?><?php endif; ?>
  </section>

  <!-- ============ Party register ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'سجل الأطراف' : 'Party register' ?></h2>
    <form method="get" class="inline-form">
      <input type="text" name="pq" value="<?= ui_e($_GET['pq'] ?? '') ?>" placeholder="<?= is_rtl() ? 'بحث بالاسم' : 'Search by name' ?>">
      <button type="submit" class="btn btn-secondary btn-sm"><?= is_rtl() ? 'بحث' : 'Search' ?></button>
    </form>
    <?php if ($parties): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الاسم' : 'Name' ?></th><th><?= is_rtl() ? 'الصفة' : 'Type' ?></th><th><?= is_rtl() ? 'الهوية / السجل' : 'ID / CR' ?></th><th><?= is_rtl() ? 'الفرع' : 'Branch' ?></th><th></th></tr></thead>
        <tbody>
          <?php foreach ($parties as $p): ?>
            <tr>
              <td><?= ui_e($p['name_ar'] ?: $p['name_en']) ?></td>
              <td><?= ui_e((string)$p['party_type']) ?></td>
              <td><?= ui_e((string)($p['id_number'] ?: $p['commercial_register'] ?: '—')) ?></td>
              <td><?= ui_e((string)($p['branch_id'] ?? '')) ?: (is_rtl() ? 'على مستوى المكتب' : 'Firm-wide') ?></td>
              <td><?php if ($canManage): ?>
                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="archive_party"><input type="hidden" name="party_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-danger btn-sm" type="submit"><?= is_rtl() ? 'أرشفة' : 'Archive' ?></button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد أطراف' : 'No parties') ?><?php endif; ?>

    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة طرف' : 'Add a party' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_party">
        <div class="grid-2">
          <label><?= is_rtl() ? 'الاسم (عربي)' : 'Name (AR)' ?><input type="text" name="name_ar" maxlength="255"></label>
          <label><?= is_rtl() ? 'الاسم (إنجليزي)' : 'Name (EN)' ?><input type="text" name="name_en" maxlength="255"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'الصفة' : 'Role' ?>
            <select name="party_type">
              <option value="client"><?= is_rtl() ? 'عميل' : 'Client' ?></option>
              <option value="opposing_party"><?= is_rtl() ? 'طرف خصم' : 'Opposing party' ?></option>
              <option value="related_person"><?= is_rtl() ? 'شخص ذو علاقة' : 'Related person' ?></option>
              <option value="related_company"><?= is_rtl() ? 'شركة ذات علاقة' : 'Related company' ?></option>
              <option value="witness"><?= is_rtl() ? 'شاهد' : 'Witness' ?></option>
              <option value="other"><?= is_rtl() ? 'أخرى' : 'Other' ?></option>
            </select>
          </label>
          <label><?= is_rtl() ? 'رقم الهوية' : 'ID number' ?><input type="text" name="id_number" maxlength="80"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'السجل التجاري' : 'Commercial register' ?><input type="text" name="commercial_register" maxlength="80"></label>
          <label><?= is_rtl() ? 'الدولة' : 'Country' ?><input type="text" name="country" maxlength="100"></label>
        </div>
        <?php if ($branches): ?>
          <label><?= is_rtl() ? 'الفرع' : 'Branch' ?><select name="branch_id"><option value=""><?= is_rtl() ? 'على مستوى المكتب' : 'Firm-wide' ?></option>
            <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= ui_e($b['name_ar']) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
        <label><?= is_rtl() ? 'ملاحظات' : 'Notes' ?><input type="text" name="notes" maxlength="500"></label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إضافة الطرف' : 'Add party' ?></button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php';
