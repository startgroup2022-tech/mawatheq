<?php
/**
 * Lawyer clients — the full list of clients this lawyer or firm actually has a
 * relationship with, and one client's detail.
 *
 * "Client" is derived, never stored: a user is a client of this tenant when a case,
 * consultation or invoice belonging to the tenant points at them
 * (tenant_client_ids_subquery). That is the same derivation the dashboard panel and
 * finance_client_balances use, so the three surfaces cannot disagree.
 *
 * Read-only by design. Creating a case is the case page's job, and it is the only
 * place that validates the client email against a real account.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/finance.php';

$rtl = is_rtl();
$clientScope = tenant_client_ids_subquery($pdo);
// A bare UNION is not valid inside `IN (...)` when the subquery also carries an extra
// predicate, so the union is wrapped as a derived table and used as a plain IN list.
$clientIn = 'SELECT * FROM (' . $clientScope['sql'] . ') AS scope_clients';
$clientScope['in'] = $clientIn;
$viewId = (int)($_GET['id'] ?? 0);

/** One client's summary row, or null when the client is outside this tenant. */
function lawyer_client_row(PDO $pdo, array $scope, int $userId): ?array
{
    $sql = "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                   (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$scope['in']})) AS cases,
                   (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$scope['in']})
                      AND c.status IN ('open','in_review')) AS open_cases,
                   (SELECT COUNT(*) FROM consultations k WHERE k.user_id = u.id AND k.user_id IN ({$scope['in']})) AS consultations
              FROM users u
             WHERE u.id = ? AND u.id IN ({$scope['in']})
             LIMIT 1";
    $stmt = $pdo->prepare($sql);
    // The IN list appears four times (three subselects, then the id filter), so its
    // params are repeated in that exact order.
    $stmt->execute(array_merge(
        $scope['params'], $scope['params'], $scope['params'],
        [$userId],
        $scope['params']
    ));
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($viewId > 0) {
    $client = lawyer_client_row($pdo, $clientScope, $viewId);
    if (!$client) {
        http_response_code(404);
        $pageTitle = $rtl ? 'العميل غير موجود' : 'Client not found';
        require_once __DIR__ . '/includes/lawyer_header.php';
        echo '<div class="ws-empty-inline">' . ($rtl
            ? 'هذا العميل غير مرتبط بأي من قضاياك أو استشاراتك أو فواتيرك.'
            : 'This client is not linked to any of your cases, consultations or invoices.') . '</div>';
        require_once __DIR__ . '/includes/lawyer_footer.php';
        exit;
    }

    $caseScope = tenant_owner_where($pdo, 'c');
    $stmt = $pdo->prepare("SELECT c.id, c.title, c.case_type, c.status, c.created_at
                             FROM cases c
                            WHERE c.user_id = ? AND {$caseScope['sql']}
                            ORDER BY c.created_at DESC LIMIT 100");
    $stmt->execute(array_merge([$viewId], $caseScope['params']));
    $clientCases = $stmt->fetchAll() ?: [];

    $invScope = tenant_owner_where($pdo, 'i');
    $stmt = $pdo->prepare("SELECT i.id, i.invoice_number, i.amount, i.amount_paid, i.status, i.currency
                             FROM invoices i
                            WHERE i.user_id = ? AND {$invScope['sql']} AND i.is_draft = 0
                            ORDER BY i.created_at DESC LIMIT 100");
    $stmt->execute(array_merge([$viewId], $invScope['params']));
    $clientInvoices = $stmt->fetchAll() ?: [];

    $outstanding = 0.0;
    foreach ($clientInvoices as $inv) {
        if ($inv['status'] !== 'cancelled') {
            $outstanding += (float)$inv['amount'] - (float)$inv['amount_paid'];
        }
    }

    $pageTitle = (string)$client['full_name'];
    require_once __DIR__ . '/includes/lawyer_header.php';
    ?>
<div class="ws-content">
  <p><a class="btn btn-outline btn-sm" href="/lawyer/clients.php"><?= $rtl ? '← كل العملاء' : '← All clients' ?></a></p>

  <div class="ws-group">
    <h2><?= ui_e($client['full_name']) ?></h2>
    <p style="color:var(--text-muted)">
      <?= ui_e($client['email']) ?>
      <?php if (!empty($client['phone'])): ?> · <?= ui_e($client['phone']) ?><?php endif; ?>
    </p>
    <p>
      <span class="ws-chip"><?= (int)$client['cases'] ?> <?= $rtl ? 'قضية' : 'cases' ?></span>
      <span class="ws-chip"><?= (int)$client['open_cases'] ?> <?= $rtl ? 'مفتوحة' : 'open' ?></span>
      <span class="ws-chip"><?= (int)$client['consultations'] ?> <?= $rtl ? 'استشارة' : 'consultations' ?></span>
      <?php if ($outstanding > 0): ?>
      <span class="ws-chip"><?= $rtl ? 'مستحق' : 'Outstanding' ?>: <?= money($outstanding) ?></span>
      <?php endif; ?>
    </p>
  </div>

  <div class="ws-group">
    <h2><?= $rtl ? 'القضايا' : 'Cases' ?></h2>
    <?php if (!$clientCases): ?>
      <div class="ws-empty-inline"><?= $rtl ? 'لا قضايا لهذا العميل.' : 'No cases for this client.' ?></div>
    <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead><tr><th><?= $rtl ? 'القضية' : 'Case' ?></th><th><?= $rtl ? 'النوع' : 'Type' ?></th><th><?= $rtl ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($clientCases as $c): ?>
          <tr>
            <td data-label="<?= $rtl ? 'القضية' : 'Case' ?>"><?= ui_e($c['title']) ?></td>
            <td data-label="<?= $rtl ? 'النوع' : 'Type' ?>"><?= ui_e((string)($c['case_type'] ?? '—')) ?></td>
            <td data-label="<?= $rtl ? 'الحالة' : 'Status' ?>"><span class="status-pill status-<?= str_starts_with((string)$c['status'], 'closed') ? 'confirmed' : 'pending' ?>"><?= ui_e($c['status']) ?></span></td>
            <td data-label=""><a class="icon-btn" href="/lawyer/case-detail.php?id=<?= (int)$c['id'] ?>"><?= $rtl ? 'فتح' : 'Open' ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="ws-group">
    <h2><?= $rtl ? 'الفواتير' : 'Invoices' ?></h2>
    <?php if (!$clientInvoices): ?>
      <div class="ws-empty-inline"><?= $rtl ? 'لا فواتير لهذا العميل.' : 'No invoices for this client.' ?></div>
    <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead><tr><th><?= $rtl ? 'الرقم' : 'Number' ?></th><th><?= $rtl ? 'المبلغ' : 'Amount' ?></th><th><?= $rtl ? 'المدفوع' : 'Paid' ?></th><th><?= $rtl ? 'الحالة' : 'Status' ?></th></tr></thead>
        <tbody>
        <?php foreach ($clientInvoices as $inv): ?>
          <tr>
            <td data-label="<?= $rtl ? 'الرقم' : 'Number' ?>"><?= ui_e((string)$inv['invoice_number']) ?></td>
            <td data-label="<?= $rtl ? 'المبلغ' : 'Amount' ?>"><?= money((float)$inv['amount'], (string)($inv['currency'] ?: null)) ?></td>
            <td data-label="<?= $rtl ? 'المدفوع' : 'Paid' ?>"><?= money((float)$inv['amount_paid'], (string)($inv['currency'] ?: null)) ?></td>
            <td data-label="<?= $rtl ? 'الحالة' : 'Status' ?>"><?= ui_e($inv['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
    <?php
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ["u.id IN ({$clientIn})"];
$params = $clientScope['params'];
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u WHERE {$whereSql}");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT u.id, u.full_name, u.email, u.phone,
            (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$clientIn})) AS cases,
            (SELECT COUNT(*) FROM cases c WHERE c.user_id = u.id AND c.user_id IN ({$clientIn})
               AND c.status IN ('open','in_review')) AS open_cases
       FROM users u
      WHERE {$whereSql}
      GROUP BY u.id, u.full_name, u.email, u.phone
      ORDER BY open_cases DESC, cases DESC, u.full_name ASC
      LIMIT {$perPage} OFFSET {$offset}"
);
// Derived table appears twice in the SELECT list and once in the WHERE clause.
$stmt->execute(array_merge($clientScope['params'], $clientScope['params'], $params));
$clients = $stmt->fetchAll() ?: [];

$balances = [];
foreach (finance_client_balances($pdo, tenant_owner_where($pdo, 'i'), 500) as $bal) {
    $balances[(int)$bal['id']] = (float)$bal['outstanding'];
}

$pageTitle = $rtl ? 'العملاء' : 'Clients';
require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="ws-content">
  <div class="ws-group">
    <h2><?= $rtl ? 'العملاء' : 'Clients' ?></h2>
    <p style="color:var(--text-muted)"><?= $rtl
        ? 'كل من رفع قضية أو استشارة أو فاتورة معك أو مع مكتبك.'
        : 'Everyone with a case, consultation or invoice with you or your firm.' ?></p>

    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <input type="search" name="q" value="<?= ui_e($search) ?>"
             placeholder="<?= $rtl ? 'بحث بالاسم أو البريد' : 'Search name or email' ?>" style="flex:1;min-width:200px">
      <button class="btn btn-outline btn-sm" type="submit"><?= $rtl ? 'بحث' : 'Search' ?></button>
    </form>

    <?php if (!$clients): ?>
      <div class="ws-empty-inline"><?= $rtl
          ? 'لا عملاء بعد. افتح قضية لعميل ليظهر هنا.'
          : 'No clients yet. Open a case for a client and they will appear here.' ?></div>
    <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead><tr>
          <th><?= $rtl ? 'العميل' : 'Client' ?></th>
          <th><?= $rtl ? 'البريد' : 'Email' ?></th>
          <th><?= $rtl ? 'القضايا' : 'Cases' ?></th>
          <th><?= $rtl ? 'المفتوحة' : 'Open' ?></th>
          <th><?= $rtl ? 'المستحق' : 'Outstanding' ?></th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($clients as $cl): $out = $balances[(int)$cl['id']] ?? 0.0; ?>
          <tr>
            <td data-label="<?= $rtl ? 'العميل' : 'Client' ?>"><?= ui_e($cl['full_name']) ?></td>
            <td data-label="<?= $rtl ? 'البريد' : 'Email' ?>"><?= ui_e($cl['email']) ?></td>
            <td data-label="<?= $rtl ? 'القضايا' : 'Cases' ?>"><?= (int)$cl['cases'] ?></td>
            <td data-label="<?= $rtl ? 'المفتوحة' : 'Open' ?>"><?= (int)$cl['open_cases'] ?></td>
            <td data-label="<?= $rtl ? 'المستحق' : 'Outstanding' ?>"><?= $out > 0 ? money($out) : '—' ?></td>
            <td data-label=""><a class="icon-btn" href="?id=<?= (int)$cl['id'] ?>"><?= $rtl ? 'عرض' : 'View' ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:12px">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="icon-btn<?= $p === $page ? ' active' : '' ?>" href="?<?= ui_e(http_build_query(['q' => $search, 'page' => $p])) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
