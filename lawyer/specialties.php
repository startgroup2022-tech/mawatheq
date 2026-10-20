<?php
/**
 * تخصصات المحامي — ربط المحامي بالتخصصات التي يقدّمها فعلاً.
 *
 * The customer journey matches a customer to lawyers through this link. Without it a
 * lawyer simply does not appear in any specialty, so this page is how a lawyer makes
 * themselves discoverable. It is a self-service page: a lawyer edits only their own
 * rows, and the ids are validated against the real specialties on write.
 *
 * The specialty names shown here are the same names the customer sees, so a lawyer
 * knows exactly which customer choice they will appear under.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/legal_journey.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    else {
        $posted = array_map('intval', (array)($_POST['categories'] ?? []));
        $posted = array_values(array_unique(array_filter($posted, fn($v) => $v > 0)));

        // Keep only ids that are real, active specialties. A crafted id is ignored
        // rather than stored, so the link can never point at nothing.
        $valid = [];
        if (legal_journey_ready($pdo) && $posted) {
            $ph = implode(',', array_fill(0, count($posted), '?'));
            $s = $pdo->prepare("SELECT id FROM najiz_categories WHERE is_active = 1 AND id IN ($ph)");
            $s->execute($posted);
            $valid = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id = ?')->execute([$lawyerId]);
            if ($valid) {
                $ins = $pdo->prepare('INSERT INTO lawyer_specialties (lawyer_id, category_id) VALUES (?,?)');
                foreach ($valid as $cid) $ins->execute([$lawyerId, $cid]);
            }
            $pdo->commit();
            log_action($pdo, 'تحديث تخصصات المحامي (' . count($valid) . ')', 'lawyer', $lawyerId);
            $message = is_rtl() ? 'تم حفظ تخصصاتك. ستظهر الآن في نتائج العملاء.' : 'Your specialties are saved. You will now appear in customer results.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('lawyer specialties save: ' . $e->getMessage());
            $error = is_rtl() ? 'تعذّر الحفظ. حاول مرة أخرى.' : 'Could not save. Please try again.';
        }
    }
}

$activeLawyer = 'specialties';
require_once __DIR__ . '/includes/lawyer_header.php';

$ready = legal_journey_ready($pdo);
$all = $ready ? legal_journey_specialties($pdo) : [];
$mine = $ready ? array_column(legal_journey_specialties_for_lawyer($pdo, $lawyerId), 'id') : [];
?>
<div class="app-content">
  <div class="toolbar"><h2><?= is_rtl() ? 'تخصصاتي' : 'My specialties' ?></h2></div>

  <?php if ($message): ?><div class="dash-card" style="border-color:var(--teal);color:var(--navy-dark)"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="dash-card" style="border-color:#E5B4B4;color:#8A2B2B"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if (!$ready): ?>
    <div class="dash-card">
      <p><?= is_rtl() ? 'دليل التخصصات غير مفعّل بعد. تواصل مع الدعم.' : 'The specialty guide is not enabled yet. Please contact support.' ?></p>
    </div>
  <?php else: ?>
    <div class="dash-card">
      <h3><?= is_rtl() ? 'اختر التخصصات التي تقدّمها' : 'Choose the specialties you provide' ?></h3>
      <p style="color:var(--text-muted)">
        <?= is_rtl()
            ? 'يُستخدم اختيارك لمطابقتك مع العملاء الذين يبحثون عن تخصصك. لن تظهر في نتائج تخصص لم تحدده.'
            : 'Your selection is used to match you with customers looking for your specialty. You will not appear under a specialty you do not select.' ?>
      </p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;margin:14px 0">
          <?php foreach ($all as $s): ?>
            <label style="display:flex;gap:10px;align-items:center;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer">
              <input type="checkbox" name="categories[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $mine, true) ? 'checked' : '' ?>>
              <span>
                <strong style="display:block;color:var(--navy-dark)"><?= htmlspecialchars($s['name']) ?></strong>
                <span style="font-size:.8rem;color:var(--text-muted)"><?= htmlspecialchars($s['description']) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'حفظ التخصصات' : 'Save specialties' ?></button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
