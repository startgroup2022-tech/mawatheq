<?php
/**
 * AI Case Copilot.
 *
 * Two clearly separated columns: "from the file" (database facts, quoted) and "AI proposal"
 * (model output, labelled, requiring review). A run is stored with requires_review=1 and must
 * be approved by a lawyer before it is treated as settled. A failed citation check is shown
 * prominently.
 *
 * A tenant in local_only / external_disabled privacy mode never reaches an external provider
 * here — that gate lives in ai_gateway_call and is re-entered on every attempt.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/branch_access.php';
require_once __DIR__ . '/../includes/ai_copilot.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$error = null; $success = null; $result = null; $runId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'run') {
            $runId = (int)($_POST['case_id'] ?? 0);
            $result = ai_copilot_run($pdo, $runId, (string)($_POST['kind'] ?? 'summary'), is_rtl(), [
                'draft_type' => (string)($_POST['draft_type'] ?? ''),
            ]);
            if ($result['error'] === 'case_not_found_or_forbidden') {
                throw new RuntimeException(is_rtl() ? 'القضية غير موجودة أو لا تملك صلاحية الوصول إليها.' : 'Case not found or not accessible to you.');
            }
        } elseif ($action === 'review') {
            $runId = (int)($_POST['run_id'] ?? 0);
            $ok = ai_copilot_review($pdo, $runId, (string)($_POST['decision'] ?? ''), $_POST['note'] ?? null);
            $success = $ok ? (is_rtl() ? 'تم تسجيل قرار المراجعة.' : 'Review decision recorded.') : null;
            if (!$ok) throw new RuntimeException(is_rtl() ? 'تعذّر تسجيل المراجعة.' : 'Could not record the review.');
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$activeLawyer = 'ai-copilot';
require_once __DIR__ . '/includes/lawyer_header.php';

if (!$firmId) {
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'مساعد القضية الذكي' : 'AI case copilot') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

$cases = $pdo->prepare('SELECT id, title, status FROM cases WHERE firm_id=? ORDER BY created_at DESC LIMIT 200');
$cases->execute([$firmId]);
$cases = $cases->fetchAll();
$currentCaseId = (int)($_POST['case_id'] ?? $_GET['case'] ?? 0);
if ($currentCaseId && !ai_copilot_case($pdo, $currentCaseId)) $currentCaseId = 0;
$history = $currentCaseId ? ai_copilot_runs_for_case($pdo, $currentCaseId) : [];

$verified = $result['verified'] ?? [];
$suggested = $result['suggested'] ?? null;
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'مساعد القضية الذكي' : 'AI case copilot',
      is_rtl() ? 'مخرجات مبنية على ملف القضية. ما يقرأه النظام من الملف يظهر منفصلاً عن اقتراح الذكاء الاصطناعي.' : 'Output grounded in the case file. What the system reads from the file is shown separately from the AI proposal.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <section class="dash-card">
    <form method="get" class="inline-form">
      <label><?= is_rtl() ? 'القضية' : 'Case' ?>
        <select name="case" onchange="this.form.submit()">
          <option value=""><?= is_rtl() ? 'اختر قضية' : 'Select a case' ?></option>
          <?php foreach ($cases as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $currentCaseId === (int)$c['id'] ? 'selected' : '' ?>><?= ui_e($c['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </section>

  <?php if ($currentCaseId): ?>
  <section class="dash-card">
    <h2><?= is_rtl() ? 'تشغيل مهمة' : 'Run a task' ?></h2>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="run">
      <input type="hidden" name="case_id" value="<?= $currentCaseId ?>">
      <div class="grid-2">
        <label><?= is_rtl() ? 'المهمة' : 'Task' ?>
          <select name="kind">
            <?php foreach (ai_copilot_kinds() as $k): ?>
              <option value="<?= ui_e($k) ?>"><?= ui_e(ai_copilot_kind_label($k, is_rtl())) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= is_rtl() ? 'نوع المسودة (لمهمة المسودة فقط)' : 'Draft type (draft task only)' ?><input type="text" name="draft_type" maxlength="100"></label>
      </div>
      <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'تشغيل' : 'Run' ?></button>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($result): ?>
    <section class="dash-card">
      <h2><?= is_rtl() ? 'النتيجة — ' : 'Result — ' ?><?= ui_e(ai_copilot_kind_label((string)$result['kind'], is_rtl())) ?></h2>
      <?php if (!empty($result['denied'])): ?>
        <div class="form-alert"><?= is_rtl() ? 'رُفض الإرسال الخارجي حسب سياسة الخصوصية؛ لم تُرسل بيانات.' : 'External transmission was denied by the privacy policy; no data was sent.' ?></div>
      <?php elseif (!empty($result['local_fallback'])): ?>
        <div class="form-alert"><?= is_rtl() ? 'المزود غير متاح — عُرضت إجابة محلية من مكتبة الأنظمة.' : 'Provider unavailable — a local law-library answer is shown.' ?></div>
      <?php endif; ?>
      <?php if (isset($result['citation_integrity_ok']) && !$result['citation_integrity_ok']): ?>
        <div class="form-alert error">
          <?= is_rtl() ? 'تحذير: استشهادات لم يتم التحقق منها: ' : 'Warning: unverified citations: ' ?>
          <?= ui_e(implode('، ', array_slice($result['unsupported_citations'], 0, 8))) ?>
        </div>
      <?php endif; ?>

      <div class="grid-2">
        <div>
          <h3 class="firm-subhead"><?= is_rtl() ? 'من ملف القضية (مُثبت)' : 'From the case file (verified)' ?></h3>
          <?php if ($verified): ?>
            <pre class="muted" style="white-space:pre-wrap;direction:inherit"><?= ui_e(json_encode($verified, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
          <?php else: ?><p class="muted"><?= is_rtl() ? 'لا توجد بيانات مسجّلة لهذه المهمة.' : 'No stored data for this task.' ?></p><?php endif; ?>
        </div>
        <div>
          <h3 class="firm-subhead"><?= is_rtl() ? 'اقتراح الذكاء الاصطناعي (يحتاج مراجعة)' : 'AI proposal (needs review)' ?></h3>
          <?php if ($suggested !== null && $suggested !== ''): ?>
            <div style="white-space:pre-wrap"><?= ui_e($suggested) ?></div>
            <?php if (!empty($result['run_id'])): ?>
              <p class="muted"><?= is_rtl() ? 'حالة المراجعة: ' : 'Review status: ' ?><?= ui_e((string)$result['review_status']) ?></p>
              <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="run_id" value="<?= (int)$result['run_id'] ?>">
                <button type="submit" name="decision" value="approved" class="btn btn-primary btn-sm"><?= is_rtl() ? 'اعتماد' : 'Approve' ?></button>
                <button type="submit" name="decision" value="rejected" class="btn btn-outline btn-sm"><?= is_rtl() ? 'رفض' : 'Reject' ?></button>
              </form>
            <?php endif; ?>
          <?php elseif ($result['ok'] === false && !empty($result['error'])): ?>
            <p class="muted"><?= ui_e((string)$result['error']) ?></p>
          <?php else: ?>
            <p class="muted"><?= is_rtl() ? 'لا يوجد اقتراح.' : 'No proposal.' ?></p>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($history): ?>
  <section class="dash-card">
    <h2><?= is_rtl() ? 'سجل التشغيل' : 'Run history' ?></h2>
    <table class="admin-table">
      <thead><tr><th>#</th><th><?= is_rtl() ? 'المهمة' : 'Task' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'يحتاج مراجعة' : 'Needs review' ?></th><th><?= is_rtl() ? 'التاريخ' : 'When' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= (int)$h['id'] ?></td>
            <td><?= ui_e(ai_copilot_kind_label((string)$h['kind'], is_rtl())) ?></td>
            <td><?= ui_badge($h['status'] === 'approved' ? 'success' : ($h['status'] === 'failed' ? 'danger' : 'neutral'), (string)$h['status']) ?></td>
            <td><?= (int)$h['requires_review'] ? (is_rtl() ? 'نعم' : 'Yes') : (is_rtl() ? 'لا' : 'No') ?></td>
            <td><?= ui_e(ui_date($h['created_at'], true)) ?></td>
            <td>
              <?php if ((int)$h['requires_review'] && in_array($h['status'], ['pending_review', 'completed'], true)): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="review">
                  <input type="hidden" name="run_id" value="<?= (int)$h['id'] ?>">
                  <button type="submit" name="decision" value="approved" class="btn btn-primary btn-sm"><?= is_rtl() ? 'اعتماد' : 'Approve' ?></button>
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
