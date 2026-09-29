<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/notifications.php';
$lawyerId = $_SESSION['lawyer_id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    // البحث عن العميل بالبريد الإلكتروني (يجب أن يكون مسجّلاً في الموقع مسبقاً)
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $stmt->execute([trim($_POST['client_email'])]);
    $client = $stmt->fetch();
    if (!$client) {
        $error = is_rtl() ? 'لا يوجد عميل مسجّل بهذا البريد الإلكتروني. يجب أن ينشئ العميل حساباً أولاً.' : 'No registered client found with this email. The client must create an account first.';
    } else {
        $pdo->prepare('INSERT INTO cases (lawyer_id, user_id, title, case_type, description) VALUES (?,?,?,?,?)')
            ->execute([$lawyerId, $client['id'], $_POST['title'], $_POST['case_type'], $_POST['description']]);
        notify($pdo, 'client', $client['id'], is_rtl() ? 'تم فتح قضية جديدة لك' : 'A new case was opened for you', $_POST['title'], '/consultations.php');
        header('Location: /lawyer/cases.php'); exit;
    }
}

$activeLawyer = 'cases';
require_once __DIR__ . '/includes/lawyer_header.php';

$cases = $pdo->prepare('SELECT c.*, u.full_name AS client_name FROM cases c JOIN users u ON u.id=c.user_id WHERE c.lawyer_id=? ORDER BY c.updated_at DESC');
$cases->execute([$lawyerId]);
$cases = $cases->fetchAll();
?>
<div class="app-content">
  <div class="toolbar"><h2><?= is_rtl() ? 'القضايا' : 'Cases' ?></h2><div style="display:flex;gap:8px;flex-wrap:wrap"><a href="/lawyer/export-cases.php" class="btn btn-outline btn-sm"><?= is_rtl()?'تصدير CSV':'Export CSV' ?></a><button type="button" class="btn btn-primary" onclick="openModal('addCaseModal')">＋ <?= is_rtl()?'إضافة قضية':'Add case' ?></button></div></div>

  <div class="modal-backdrop<?= $error ? ' is-open' : '' ?>" id="addCaseModal"><form class="modal-form" method="post" aria-labelledby="addCaseTitle"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <button type="button" class="modal-close" onclick="closeModal('addCaseModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button>
    <input type="hidden" name="action" value="create">
    <h3 id="addCaseTitle" style="margin-bottom:14px"><?= is_rtl() ? 'إضافة قضية جديدة' : 'Add a new case' ?></h3>
    <?php if (!empty($error)): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني للعميل (يجب أن يملك حساباً في مواثيق)' : "Client's email (must already have a Mawatheq account)" ?></label><input type="email" name="client_email" required></div>
    <div class="field"><label><?= is_rtl() ? 'عنوان القضية' : 'Case title' ?></label><input type="text" name="title" required></div>
    <div class="field"><label><?= is_rtl() ? 'نوع القضية' : 'Case type' ?></label><input type="text" name="case_type" placeholder="<?= is_rtl() ? 'عمالي، تجاري، عقاري...' : 'Labor, Commercial, Real Estate...' ?>"></div>
    <div class="field"><label><?= is_rtl() ? 'تفاصيل' : 'Description' ?></label><textarea name="description" rows="3"></textarea></div>
    <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'إضافة القضية' : 'Add case' ?></button>
  </form></div>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th><?= is_rtl() ? 'القضية' : 'Case' ?></th><th><?= is_rtl() ? 'العميل' : 'Client' ?></th><th><?= is_rtl() ? 'احتمالية الفوز' : 'Win probability' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($cases as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['title']) ?></td>
          <td><?= htmlspecialchars($c['client_name']) ?></td>
          <td><?= $c['win_probability'] !== null ? $c['win_probability'] . '%' : '—' ?></td>
          <td><span class="status-pill status-<?= str_starts_with($c['status'], 'closed') ? 'confirmed' : 'pending' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
          <td><a class="icon-btn" href="/lawyer/case-detail.php?id=<?= $c['id'] ?>"><?= is_rtl() ? 'فتح' : 'Open' ?></a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cases)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد قضايا بعد' : 'No cases yet' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
