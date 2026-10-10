<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/tenant.php';
$lawyerId = $_SESSION['lawyer_id'];
$firmId = tenant_write_firm_id($pdo);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    tenant_require($pdo, 'cases.create');
    // البحث عن العميل بالبريد الإلكتروني (يجب أن يكون مسجّلاً في الموقع مسبقاً)
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $stmt->execute([trim($_POST['client_email'])]);
    $client = $stmt->fetch();
    if (!$client) {
        $error = is_rtl() ? 'لا يوجد عميل مسجّل بهذا البريد الإلكتروني. يجب أن ينشئ العميل حساباً أولاً.' : 'No registered client found with this email. The client must create an account first.';
    } else {
        $pdo->prepare('INSERT INTO cases (lawyer_id, firm_id, user_id, title, case_type, description) VALUES (?,?,?,?,?,?)')
            ->execute([$lawyerId, $firmId, $client['id'], $_POST['title'], $_POST['case_type'], $_POST['description']]);
        $caseId = (int)$pdo->lastInsertId();
        notify($pdo, 'client', $client['id'], is_rtl() ? 'تم فتح قضية جديدة لك' : 'A new case was opened for you', $_POST['title'], '/consultations.php');
        // Firm-wide assignment notice for the rest of the tenant, skipping the creator.
        notify_tenant_except($pdo, $firmId, $lawyerId, $lawyerId, is_rtl() ? 'قضية جديدة في المكتب' : 'New case in the firm', $_POST['title'], '/lawyer/cases.php');
        log_action($pdo, 'فتح قضية جديدة: ' . $_POST['title'], 'case', $caseId);
        require_once __DIR__ . '/../includes/integrations.php';
        integration_emit_quietly($pdo, $firmId !== null ? (int)$firmId : null, 'case.created', [
            'case_id' => $caseId, 'title' => (string)$_POST['title'], 'case_type' => (string)($_POST['case_type'] ?? ''),
            'client_id' => (int)$client['id'], 'lawyer_id' => (int)$lawyerId,
        ]);
        header('Location: /lawyer/cases.php'); exit;
    }
}

$activeLawyer = 'cases';
require_once __DIR__ . '/includes/lawyer_header.php';

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$scope = tenant_owner_where($pdo, 'c');
  $where = [$scope['sql']];
  $params = $scope['params'];
  // Branch isolation narrows the firm scope further: a member restricted to a branch does
  // not see records filed at another branch unless explicitly shared (branch_access_sql).
  require_once __DIR__ . '/../includes/branch_access.php';
  [$branchSql, $branchParams] = branch_access_sql($pdo, 'c.branch_id', 'case', 'c.id');
  if ($branchSql !== '') {
      $where[] = ltrim($branchSql, ' AND');
      $params = array_merge($params, $branchParams);
  }
if ($search !== '') {
    $where[] = '(c.title LIKE ? OR u.full_name LIKE ? OR c.case_type LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'c.status=?';
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM cases c JOIN users u ON u.id=c.user_id WHERE $whereSql");
$countStmt->execute($params);
$totalCases = (int)$countStmt->fetchColumn();

$cases = $pdo->prepare("SELECT c.*, u.full_name AS client_name FROM cases c JOIN users u ON u.id=c.user_id WHERE $whereSql ORDER BY c.updated_at DESC LIMIT $perPage OFFSET $offset");
$cases->execute($params);
$cases = $cases->fetchAll();
$totalPages = max(1, (int)ceil($totalCases / $perPage));
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

  <div class="table-wrap table-cards">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="<?= is_rtl()?'بحث بالعنوان/العميل/النوع':'Search title, client, or type' ?>" style="flex:1;min-width:200px">
      <select name="status">
        <option value=""><?= is_rtl()?'كل الحالات':'All statuses' ?></option>
        <?php foreach (['open','in_review','closed_won','closed_lost','closed_settled'] as $st): ?>
        <option value="<?= $st ?>"<?= $statusFilter === $st ? ' selected' : '' ?>><?= htmlspecialchars($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-outline btn-sm" type="submit"><?= is_rtl()?'تصفية':'Filter' ?></button>
    </form>
    <table class="admin-table">
      <thead><tr><th><?= is_rtl() ? 'القضية' : 'Case' ?></th><th><?= is_rtl() ? 'العميل' : 'Client' ?></th><th><?= is_rtl() ? 'احتمالية الفوز' : 'Win probability' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($cases as $c): ?>
        <tr>
          <td data-label="<?= is_rtl() ? 'القضية' : 'Case' ?>"><?= htmlspecialchars($c['title']) ?></td>
          <td data-label="<?= is_rtl() ? 'العميل' : 'Client' ?>"><?= htmlspecialchars($c['client_name']) ?></td>
          <td data-label="<?= is_rtl() ? 'احتمالية الفوز' : 'Win probability' ?>"><?= $c['win_probability'] !== null ? $c['win_probability'] . '%' : '—' ?></td>
          <td data-label="<?= is_rtl() ? 'الحالة' : 'Status' ?>"><span class="status-pill status-<?= str_starts_with($c['status'], 'closed') ? 'confirmed' : 'pending' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
          <td data-label=""><a class="icon-btn" href="/lawyer/case-detail.php?id=<?= $c['id'] ?>"><?= is_rtl() ? 'فتح' : 'Open' ?></a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cases)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد قضايا بعد' : 'No cases yet' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:12px">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="icon-btn<?= $p === $page ? ' active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['q' => $search, 'status' => $statusFilter, 'page' => $p])) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
