<?php
/**
 * Court-order extraction workspace.
 *
 * Lists extractions for the firm, lets a lawyer run a fresh reading on a case document, and
 * records approval or rejection. The page repeats the core rule everywhere: the machine reading
 * is a suggestion, and nothing is shown to a client until a lawyer approves it.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/court_order_extraction.php';
require_once __DIR__ . '/../includes/document_text.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$error = null; $success = null;

if ($firmId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'extract') {
            $r = court_order_extract($pdo, $firmId, (int)$_POST['document_id'], (string)($_POST['source'] ?? 'auto'));
            if (empty($r['ok'])) {
                $msgs = [
                    'not_found' => is_rtl() ? 'المستند غير موجود.' : 'Document not found.',
                    'wrong_firm' => is_rtl() ? 'هذا المستند لا يخص مكتبك.' : 'That document belongs to another firm.',
                    'no_text' => is_rtl() ? 'تعذّر قراءة نص المستند. تأكد من رفعه بصيغة مدعومة.' : 'Could not read the document text. Make sure it was uploaded in a supported format.',
                ];
                throw new RuntimeException($msgs[$r['error']] ?? (is_rtl() ? 'تعذّر الاستخراج.' : 'Extraction failed.'));
            }
            $success = is_rtl() ? 'تم الاستخراج. راجع النتيجة واعتمدها قبل عرضها على العميل.' : 'Extraction done. Review it and approve before it reaches the client.';
        } elseif ($action === 'review') {
            $r = court_order_review($pdo, $firmId, (int)$_POST['extraction_id'], $lawyerId, (string)$_POST['decision'], (string)($_POST['note'] ?? ''));
            if (empty($r['ok'])) throw new RuntimeException(is_rtl() ? 'تعذّر تسجيل القرار.' : 'Could not record the decision.');
            $success = is_rtl() ? 'تم تسجيل قرارك.' : 'Your decision was recorded.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$activeLawyer = 'court-orders';
require_once __DIR__ . '/includes/lawyer_header.php';

if (!$firmId) {
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'استخراج الأوامر القضائية' : 'Court-order extraction') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

$items = court_order_list($pdo, $firmId);
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'استخراج الأوامر القضائية' : 'Court-order extraction',
      is_rtl() ? 'استخراج آلي للبيانات من أوامر المحكمة مع مراجعة محامٍ إلزامية.' : 'Machine extraction of court-order fields, always subject to lawyer review.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>
  <div class="form-alert"><?= is_rtl()
      ? 'القراءة الآلية اقتراح لا حكم. الحقول المستخرجة آلياً لا تُعرض على العميل إلا بعد اعتماد محامٍ، والقيم غير المدعومة بنص صريح تُدرج كغير مدعومة.'
      : 'The machine reading is a suggestion, not a finding. Nothing reaches the client until a lawyer approves it, and values with no explicit support in the text are listed as unsupported.' ?></div>

  <section class="dash-card">
    <h2><?= is_rtl() ? 'استخراج جديد' : 'New extraction' ?></h2>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="extract">
      <label><?= is_rtl() ? 'رقم المستند' : 'Document ID' ?><input type="number" name="document_id" min="1" required></label>
      <label class="check-row"><input type="checkbox" name="source" value="assist"> <?= is_rtl() ? 'إضافة قراءة مقترحة من مزوّد الذكاء الاصطناعي (تتطلب تفعيل المزوّد)' : 'Also request a suggested reading from the AI provider (requires a configured provider)' ?></label>
      <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'استخراج' : 'Extract' ?></button>
    </form>
  </section>

  <section class="dash-card">
    <h2><?= is_rtl() ? 'الاستخراجات' : 'Extractions' ?></h2>
    <?php if ($items): ?>
      <table class="admin-table">
        <thead><tr><th>#</th><th><?= is_rtl() ? 'القضية' : 'Case' ?></th><th><?= is_rtl() ? 'المستند' : 'Document' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'القرار' : 'Decision' ?></th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= (int)$it['id'] ?></td>
              <td><?= (int)$it['case_id'] ?></td>
              <td><?= (int)$it['document_id'] ?></td>
              <td><?= ui_badge($it['status'] === 'approved' ? 'success' : ($it['status'] === 'rejected' ? 'danger' : 'neutral'), ui_e((string)$it['status'])) ?></td>
              <td>
                <?php if (!empty($it['requires_review'])): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="review">
                    <input type="hidden" name="extraction_id" value="<?= (int)$it['id'] ?>">
                    <input type="text" name="note" maxlength="200" placeholder="<?= is_rtl() ? 'ملاحظة' : 'note' ?>">
                    <button class="btn btn-primary btn-sm" name="decision" value="approved" type="submit"><?= is_rtl() ? 'اعتماد' : 'Approve' ?></button>
                    <button class="btn btn-outline btn-sm" name="decision" value="rejected" type="submit"><?= is_rtl() ? 'رفض' : 'Reject' ?></button>
                  </form>
                <?php else: ?>
                  <?= ui_e((string)($it['review_note'] ?? '—')) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد استخراجات بعد' : 'No extractions yet') ?><?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php';
