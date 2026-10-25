<?php
/**
 * Admin — platform commission rules for lawyer wallets (Execution 56).
 *
 * A rate is a commercial decision, so nothing is seeded: the default when no rule
 * exists is ZERO commission, which is the only safe default (the lawyer keeps the
 * whole collected amount). Changing a rate affects only payments recorded after the
 * change — the split is stored on each ledger row at credit time, so already-earned
 * money never moves.
 *
 * The POST handler runs before the shell is included: `admin_header.php` prints the
 * whole <head> immediately with no output buffering, so a header() redirect issued
 * afterwards is silently dropped (see the "POST-redirect pages" note in AGENTS.md).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/wallet.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_rule') {
        $firmId = is_string($_POST['firm_id'] ?? null) && $_POST['firm_id'] !== ''
            ? (filter_var($_POST['firm_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null)
            : null;
        $rateRaw = (string)($_POST['rate_percent'] ?? '');
        $rate = is_numeric($rateRaw) ? (float)$rateRaw : -1;
        $note = is_string($_POST['note'] ?? null) ? trim((string)$_POST['note']) : '';
        if ($rate < 0 || $rate > 100) {
            $error = 'rate_invalid';
        } else {
            $pdo->prepare('INSERT INTO wallet_commission_rules (firm_id, service_key, rate_percent, note)
                VALUES (?, \'default\', ?, ?)
                ON DUPLICATE KEY UPDATE rate_percent=VALUES(rate_percent), note=VALUES(note), is_active=1')
                ->execute([$firmId, $rate, $note !== '' ? mb_substr($note, 0, 255) : null]);
            log_action($pdo, 'تحديث نسبة عمولة المنصة', 'wallet_commission_rule', $firmId);
            header('Location: /admin/wallet-settings.php?saved=1&t=' . time());
            exit;
        }
    }

    if ($action === 'delete_rule') {
        $ruleId = is_string($_POST['rule_id'] ?? null)
            ? (filter_var($_POST['rule_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
            : 0;
        if ($ruleId > 0) {
            $pdo->prepare('DELETE FROM wallet_commission_rules WHERE id=?')->execute([$ruleId]);
            log_action($pdo, 'حذف قاعدة عمولة', 'wallet_commission_rule', $ruleId);
        }
        header('Location: /admin/wallet-settings.php?saved=1&t=' . time());
        exit;
    }
}

$errorText = match ($error) {
    'rate_invalid' => adm('النسبة يجب أن تكون بين 0 و100.', 'The rate must be between 0 and 100.'),
    default        => $error !== null ? adm('تعذّر حفظ القاعدة.', 'The rule could not be saved.') : null,
};

$rules = $pdo->query('SELECT r.*, f.name AS firm_name FROM wallet_commission_rules r
    LEFT JOIN law_firms f ON f.id = r.firm_id ORDER BY (r.firm_id IS NOT NULL), r.id')->fetchAll();
$firms = $pdo->query('SELECT id, name FROM law_firms ORDER BY name LIMIT 500')->fetchAll();

$activeAdmin = 'wallet-settings';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= adm('عمولة المنصة', 'Platform commission') ?></h1><p><?= adm(
      'النسبة تُقرأ لحظة تسجيل الدفعة وتُثبَّت على صف الحركة. تعديل النسبة لاحقاً لا يغيّر أرباحاً محقّقة. غياب أي قاعدة يعني عمولة صفر.',
      'The rate is read when the payment is recorded and fixed on the ledger row. Changing it later does not alter already-earned money. No rule means zero commission.'
    ) ?></p></div></div>

  <?php if (isset($_GET['saved'])): ?><div class="form-alert success"><?= adm('تم الحفظ.', 'Saved.') ?></div><?php endif; ?>
  <?php if ($errorText !== null): ?><div class="form-alert error"><?= h($errorText) ?></div><?php endif; ?>

  <section class="dash-card">
    <h3><?= adm('إضافة أو تحديث قاعدة', 'Add or update a rule') ?></h3>
    <form method="post" class="app-form" style="max-width:560px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_rule">
      <label><?= adm('المكتب (اتركه فارغاً للافتراضي على المنصة)', 'Firm (leave empty for the platform default)') ?>
        <select name="firm_id">
          <option value=""><?= adm('افتراضي المنصة', 'Platform default') ?></option>
          <?php foreach ($firms as $f): ?>
            <option value="<?= (int)$f['id'] ?>"><?= h((string)$f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= adm('النسبة %', 'Rate %') ?>
        <input type="number" name="rate_percent" step="0.001" min="0" max="100" value="0" required>
      </label>
      <label><?= adm('ملاحظة', 'Note') ?>
        <input type="text" name="note" maxlength="255">
      </label>
      <button class="btn btn-primary" type="submit"><?= adm('حفظ القاعدة', 'Save rule') ?></button>
    </form>
  </section>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th><?= adm('النطاق', 'Scope') ?></th>
        <th><?= adm('النسبة', 'Rate') ?></th>
        <th><?= adm('ملاحظة', 'Note') ?></th>
        <th><?= adm('إجراء', 'Action') ?></th>
      </tr></thead>
      <tbody>
        <?php if (!$rules): ?>
          <tr><td colspan="4" class="empty"><?= adm('لا توجد قواعد. العمولة المطبَّقة حالياً صفر.', 'No rules. The applied commission is currently zero.') ?></td></tr>
        <?php else: foreach ($rules as $r): ?>
          <tr>
            <td data-label="<?= adm('النطاق', 'Scope') ?>"><?= h((string)($r['firm_name'] ?? adm('افتراضي المنصة', 'Platform default'))) ?></td>
            <td data-label="<?= adm('النسبة', 'Rate') ?>"><span class="num-ltr"><?= number_format((float)$r['rate_percent'], 3) ?>%</span></td>
            <td data-label="<?= adm('ملاحظة', 'Note') ?>"><?= h((string)($r['note'] ?? '—')) ?></td>
            <td data-label="<?= adm('إجراء', 'Action') ?>">
              <form method="post" onsubmit="return confirm('<?= adm('حذف القاعدة؟', 'Delete this rule?') ?>')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                <button class="icon-btn danger" type="submit"><?= adm('حذف', 'Delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
