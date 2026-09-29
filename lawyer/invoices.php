<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/tenant.php';
$lawyerId = $_SESSION['lawyer_id'];
$firmId = tenant_write_firm_id($pdo);
require_once __DIR__ . '/../includes/audit_log.php';
// نطاق الاستعلام: المكتب بأكمله (firm) أو المحامي المنفرد فقط.
$invScope = tenant_owner_where($pdo, 'invoices');
$invScopeI = tenant_owner_where($pdo, 'i');
$timeScope = tenant_owner_where($pdo, 'case_time_entries');
$timeScopeTe = tenant_owner_where($pdo, 'te');

// تتغير حالة الفاتورة عبر POST محمي وضمن فواتير المستأجر الحالي فقط.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='mark_paid') {
    tenant_require($pdo, 'finance.record_payments');
    $invoiceId=is_string($_POST['invoice_id'] ?? null) ? (filter_var($_POST['invoice_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0) : 0;
    $paymentUpdate=$pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW(), payment_method='manual' WHERE id=? AND " . $invScope['sql'] . " AND status='unpaid' AND is_draft=0");
    $paymentUpdate->execute(array_merge([$invoiceId], $invScope['params']));
    if($paymentUpdate->rowCount()) log_action($pdo,'تسجيل استلام دفع فاتورة','invoice',$invoiceId);
    header('Location: /lawyer/invoices.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='cancel_invoice') {
    tenant_require($pdo, 'finance.create_invoices');
    $invoiceId=is_string($_POST['invoice_id'] ?? null) ? (filter_var($_POST['invoice_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0) : 0;
    $pdo->beginTransaction();
    try {
        $draftCheck=$pdo->prepare("SELECT is_draft FROM invoices WHERE id=? AND " . $invScope['sql'] . " AND status='unpaid' FOR UPDATE");
        $draftCheck->execute(array_merge([$invoiceId], $invScope['params'])); $draftRow=$draftCheck->fetch();
        if ($draftRow) {
            $cancel=$pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=? AND " . $invScope['sql'] . " AND status='unpaid'");
            $cancel->execute(array_merge([$invoiceId], $invScope['params']));
            if ((int)$draftRow['is_draft']===1) {
                $pdo->prepare('UPDATE case_time_entries SET invoice_id=NULL WHERE invoice_id=? AND ' . $timeScope['sql'])->execute(array_merge([$invoiceId], $timeScope['params']));
            }
            if ($cancel->rowCount()===0) $draftRow=false;
        }
        $pdo->commit();
        if($draftRow) log_action($pdo,(int)$draftRow['is_draft']===1?'حذف مسودة فاتورة':'إلغاء فاتورة','invoice',$invoiceId);
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); error_log('Invoice cancellation failed: '.$e->getMessage()); }
    header('Location: /lawyer/invoices.php'); exit;
}

$error = null; $created = false; $createdMessage = null;

// عملاء هذا المستأجر (من القضايا والاستشارات والفواتير) لاختيارهم عند إصدار فاتورة
$tenantClients = tenant_client_ids_subquery($pdo);
$clientsStmt = $pdo->prepare('
    SELECT DISTINCT u.id, u.full_name FROM users u
    WHERE u.id IN (' . $tenantClients['sql'] . ')
    ORDER BY u.full_name
');
$clientsStmt->execute($tenantClients['params']);
$clients = $clientsStmt->fetchAll();

$caseScope = tenant_owner_where($pdo, 'c');
$casesStmt = $pdo->prepare('SELECT c.id, c.title, c.user_id FROM cases c WHERE ' . $caseScope['sql'] . ' ORDER BY c.created_at DESC');
$casesStmt->execute($caseScope['params']);
$cases = $casesStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    tenant_require($pdo, 'finance.create_invoices');
    $userInput = is_string($_POST['user_id'] ?? null) ? $_POST['user_id'] : '';
    $userId = filter_var($userInput,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
    $desc = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
    $amountInput = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
    $amount = is_numeric($amountInput) ? (float)$amountInput : 0.0;
    $dueDate = is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
    $caseInput = is_string($_POST['case_id'] ?? null) ? trim($_POST['case_id']) : '';
    $caseId = $caseInput !== '' ? (filter_var($caseInput,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null) : null;

    $dueDateObject = $dueDate ? DateTime::createFromFormat('!Y-m-d',(string)$dueDate) : null;
    $validDueDate = !$dueDate || ($dueDateObject && $dueDateObject->format('Y-m-d') === $dueDate);
    $clientCheck=$pdo->prepare('SELECT id FROM users WHERE id=? AND id IN (' . $tenantClients['sql'] . ')');
    $clientCheck->execute(array_merge([$userId], $tenantClients['params']));
    $validCase = $caseInput === '' || (bool)$caseId;
    if ($caseId) { $caseCheck=$pdo->prepare('SELECT c.id FROM cases c WHERE c.id=? AND c.user_id=? AND ' . $caseScope['sql']); $caseCheck->execute(array_merge([$caseId,$userId], $caseScope['params'])); $validCase=(bool)$caseCheck->fetchColumn(); }
    if (!$userId || !$clientCheck->fetchColumn() || !$desc || mb_strlen($desc,'UTF-8')>255 || !is_finite($amount) || $amount <= 0 || $amount > 99999999.99 || !$validDueDate || !$validCase) {
        $error = is_rtl() ? 'يرجى اختيار العميل وتعبئة الوصف والمبلغ (أكبر من صفر).' : 'Please choose a client and fill description and amount (> 0).';
    } else {
        $pdo->prepare('INSERT INTO invoices (lawyer_id,firm_id,user_id,case_id,description,amount,due_date) VALUES (?,?,?,?,?,?,?)')
            ->execute([$lawyerId, $firmId, $userId, $caseId, $desc, $amount, $dueDate ?: null]);
        notify($pdo, 'client', $userId, is_rtl() ? 'فاتورة جديدة' : 'New invoice', $desc . ' — ' . number_format($amount,3) . (is_rtl()?' د.ب':' BHD'), '/invoices.php');
        $created = true; $createdMessage=is_rtl()?'تم إصدار الفاتورة بنجاح.':'Invoice issued successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create_time_invoice') {
    tenant_require($pdo, 'finance.create_invoices');
    $caseId=is_string($_POST['case_id'] ?? null) ? (filter_var($_POST['case_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0) : 0;
    $dueDate=is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
    $date= $dueDate!=='' ? DateTime::createFromFormat('!Y-m-d',$dueDate) : null;
    if ($dueDate!=='' && (!$date || $date->format('Y-m-d')!==$dueDate)) {
        $error=is_rtl()?'تاريخ الاستحقاق غير صحيح.':'Invalid due date.';
    } else {
        try {
            $pdo->beginTransaction();
            $caseLock=$pdo->prepare('SELECT c.id,c.user_id,c.title FROM cases c WHERE c.id=? AND ' . $caseScope['sql'] . ' FOR UPDATE');
            $caseLock->execute(array_merge([$caseId], $caseScope['params'])); $billingCase=$caseLock->fetch();
            if (!$billingCase) throw new RuntimeException('Case not found.');
            $entriesQuery=$pdo->prepare('SELECT id,minutes,hourly_rate FROM case_time_entries WHERE case_id=? AND ' . $timeScope['sql'] . ' AND billable=1 AND invoice_id IS NULL ORDER BY work_date,id LIMIT 500 FOR UPDATE');
            $entriesQuery->execute(array_merge([$caseId], $timeScope['params'])); $billableEntries=$entriesQuery->fetchAll();
            if (!$billableEntries) throw new RuntimeException('No unbilled entries.');
            $minutes=0; $amount=0.0;
            foreach($billableEntries as $entry){$minutes+=(int)$entry['minutes'];$amount+=round(((int)$entry['minutes']/60)*(float)$entry['hourly_rate'],3);}
            if (!is_finite($amount) || $amount<=0 || $amount>99999999.99) throw new RuntimeException('Invalid calculated amount.');
            $lineCount=count($billableEntries);
            $description=(is_rtl()?'مسودة أتعاب وقت عمل للقضية: ':'Draft time billing for case: ').mb_substr($billingCase['title'],0,170,'UTF-8').' · '.$lineCount.' '.(is_rtl()?'سجل':'entries').' / '.round($minutes/60,3).' '.(is_rtl()?'ساعة':'hours');
            $pdo->prepare('INSERT INTO invoices (lawyer_id,firm_id,user_id,case_id,description,amount,due_date,is_draft) VALUES (?,?,?,?,?,?,?,1)')
                ->execute([$lawyerId,$firmId,(int)$billingCase['user_id'],$caseId,mb_substr($description,0,255,'UTF-8'),$amount,$dueDate!==''?$dueDate:null]);
            $invoiceId=(int)$pdo->lastInsertId();
            $ids=array_map(static fn($entry)=>(int)$entry['id'],$billableEntries);
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $attach=$pdo->prepare("UPDATE case_time_entries SET invoice_id=? WHERE " . $timeScope['sql'] . " AND invoice_id IS NULL AND id IN ($placeholders)");
            $attach->execute(array_merge([$invoiceId], $timeScope['params'], $ids));
            if ($attach->rowCount()!==$lineCount) throw new RuntimeException('Time entries changed during invoice creation.');
            $pdo->commit();
            log_action($pdo,'إنشاء مسودة فاتورة من سجل الوقت','invoice',$invoiceId);
            $created=true; $createdMessage=is_rtl()?'أُنشئت مسودة الفاتورة للمراجعة. لم تُرسل للعميل بعد.':'Invoice draft created for review. It has not been sent to the client.';
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            error_log('Time invoice draft failed: '.$e->getMessage());
            $error=is_rtl()?'تعذر إنشاء المسودة. تحقق من القضية وسجلات الوقت القابلة للفوترة.':'Could not create the draft. Check the case and billable time entries.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='issue_time_invoice') {
    tenant_require($pdo, 'finance.create_invoices');
    $invoiceId=is_string($_POST['invoice_id'] ?? null) ? (filter_var($_POST['invoice_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0) : 0; $issueData=null;
    try {
        $pdo->beginTransaction();
        $draftLock=$pdo->prepare("SELECT id,user_id,description,amount FROM invoices WHERE id=? AND " . $invScope['sql'] . " AND status='unpaid' AND is_draft=1 FOR UPDATE");
        $draftLock->execute(array_merge([$invoiceId], $invScope['params'])); $issueData=$draftLock->fetch();
        if (!$issueData) throw new RuntimeException('Draft not found.');
        $entryCount=$pdo->prepare('SELECT COUNT(*) FROM case_time_entries WHERE invoice_id=? AND ' . $timeScope['sql']); $entryCount->execute(array_merge([$invoiceId], $timeScope['params']));
        if ((int)$entryCount->fetchColumn()<1) throw new RuntimeException('Draft has no linked time.');
        $pdo->prepare("UPDATE invoices SET is_draft=0 WHERE id=? AND " . $invScope['sql'] . " AND is_draft=1")->execute(array_merge([$invoiceId], $invScope['params']));
        notify($pdo,'client',(int)$issueData['user_id'],is_rtl()?'فاتورة أتعاب وقت جديدة':'New time billing invoice',$issueData['description'].' — '.number_format((float)$issueData['amount'],3).(is_rtl()?' د.ب':' BHD'),'/invoices.php');
        $pdo->commit();
        log_action($pdo,'إصدار فاتورة أتعاب وقت للعميل','invoice',$invoiceId);
        $created=true; $createdMessage=is_rtl()?'تم إصدار الفاتورة وإشعار العميل.':'Invoice issued and client notified.';
    } catch (Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('Time invoice issue failed: '.$e->getMessage());
        $error=is_rtl()?'تعذر إصدار الفاتورة.':'Could not issue the invoice.';
    }
}

$activeLawyer = 'invoices';

// تحديد الفواتير المتأخرة تلقائياً للعرض (دون تعديل الحالة المخزنة إن كانت "غير مدفوعة" وتجاوز تاريخ الاستحقاق)
$stmt = $pdo->prepare('
    SELECT i.*, u.full_name AS client_name, c.title AS case_title
    FROM invoices i
    JOIN users u ON u.id = i.user_id
    LEFT JOIN cases c ON c.id = i.case_id
    WHERE ' . $invScopeI['sql'] . '
    ORDER BY i.created_at DESC
');
$stmt->execute($invScopeI['params']);
$invoices = $stmt->fetchAll();

$unbilledTimeQuery=$pdo->prepare("SELECT c.id AS case_id,c.title AS case_title,u.full_name AS client_name,COUNT(te.id) AS entry_count,SUM(te.minutes) AS total_minutes,SUM(ROUND(te.minutes*te.hourly_rate/60,3)) AS amount FROM case_time_entries te JOIN cases c ON c.id=te.case_id JOIN users u ON u.id=c.user_id WHERE " . $timeScopeTe['sql'] . " AND te.billable=1 AND te.invoice_id IS NULL GROUP BY c.id,c.title,u.full_name ORDER BY c.title");
$unbilledTimeQuery->execute($timeScopeTe['params']); $unbilledTimeCases=$unbilledTimeQuery->fetchAll();
$activeLawyer = 'invoices';
require_once __DIR__ . '/includes/lawyer_header.php';

$totalPaid = 0; $totalUnpaid = 0;
foreach ($invoices as $inv) {
    if (!empty($inv['is_draft'])) continue;
    if ($inv['status'] === 'paid') $totalPaid += $inv['amount'];
    elseif ($inv['status'] !== 'cancelled') $totalUnpaid += $inv['amount'];
}
?>
<div class="app-content lawyer-invoices">
  <div class="toolbar"><div><h2><?= is_rtl() ? 'الفواتير' : 'Invoices' ?></h2><p class="field-hint" style="margin:6px 0 0"><?= is_rtl() ? 'تابع الفواتير والمدفوعات ومسودات أتعاب الوقت من مكان واحد.' : 'Manage issued invoices, payments, and time-billing drafts in one place.' ?></p></div><button type="button" class="btn btn-primary" onclick="openModal('addInvoiceModal')">＋ <?= is_rtl() ? 'إصدار فاتورة' : 'Issue invoice' ?></button></div>

  <div class="invoice-summary">
    <div class="stat-card"><div class="num"><?= number_format($totalPaid, 3) ?></div><div class="label"><?= is_rtl() ? 'إجمالي المحصّل (د.ب)' : 'Total collected (BHD)' ?></div></div>
    <div class="stat-card"><div class="num"><?= number_format($totalUnpaid, 3) ?></div><div class="label"><?= is_rtl() ? 'مستحق (غير مدفوع)' : 'Outstanding (unpaid)' ?></div></div>
  </div>

  <div class="invoice-toolbar">
    <h3 style="margin:0"><?= is_rtl() ? 'سجل الفواتير' : 'Invoice register' ?> <span class="count-badge"><?= count($invoices) ?></span></h3>
  </div>
  <?php if ($created): ?><div class="form-alert success"><?= htmlspecialchars($createdMessage ?? '') ?></div><?php endif; ?>
  <?php if ($error && ($_POST['action'] ?? '')!=='create'): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($unbilledTimeCases): ?><div class="dash-card"><h3>⏱️ <?= is_rtl()?'الوقت القابل للفوترة':'Unbilled billable time' ?></h3><p class="field-hint"><?= is_rtl()?'أنشئ مسودة للمراجعة أولاً؛ لن تظهر للعميل أو تسمح بالدفع حتى تصدرها.':'Create a draft for review; it stays hidden from the client and cannot be paid until issued.' ?></p>
    <?php foreach($unbilledTimeCases as $timeCase): ?><form method="post" class="invoice-time-row"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="create_time_invoice"><input type="hidden" name="case_id" value="<?= (int)$timeCase['case_id'] ?>"><div><strong><?= htmlspecialchars($timeCase['case_title']) ?></strong><div class="field-hint"><?= htmlspecialchars($timeCase['client_name']) ?> · <?= (int)$timeCase['entry_count'] ?> <?= is_rtl()?'سجل وقت':'time entries' ?> · <?= round((int)$timeCase['total_minutes']/60,2) ?> <?= is_rtl()?'ساعة':'hours' ?> · <?= number_format((float)$timeCase['amount'],3) ?> <?= is_rtl()?'د.ب':'BHD' ?></div></div><div class="field"><label><?= is_rtl()?'تاريخ الاستحقاق':'Due date' ?></label><input type="date" name="due_date" value="<?= htmlspecialchars(date('Y-m-d',strtotime('+14 days'))) ?>"></div><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl()?'إنشاء مسودة':'Create draft' ?></button></form><?php endforeach; ?>
  </div><?php endif; ?>

  <div class="modal-backdrop<?= $error && ($_POST['action'] ?? '')==='create' ? ' is-open' : '' ?>" id="addInvoiceModal">
    <form class="modal-form" method="post" aria-labelledby="invoiceModalTitle"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <button type="button" class="modal-close" onclick="closeModal('addInvoiceModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button>
    <input type="hidden" name="action" value="create">
    <h3 id="invoiceModalTitle" style="margin-bottom:14px"><?= is_rtl() ? 'إصدار فاتورة جديدة' : 'Issue a new invoice' ?></h3>
    <?php if ($error && ($_POST['action'] ?? '')==='create'): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="grid-2">
      <div class="field">
        <label><?= is_rtl() ? 'العميل' : 'Client' ?></label>
        <div class="select-wrap"><select name="user_id" class="pro-select" required>
          <option value="" disabled selected><?= is_rtl() ? 'اختر العميل...' : 'Choose client...' ?></option>
          <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'القضية (اختياري)' : 'Case (optional)' ?></label>
        <div class="select-wrap"><select name="case_id" class="pro-select">
          <option value=""><?= is_rtl() ? 'بدون ربط بقضية' : 'Not linked to a case' ?></option>
          <?php foreach ($cases as $cs): ?><option value="<?= $cs['id'] ?>"><?= htmlspecialchars($cs['title']) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="field"><label><?= is_rtl() ? 'الوصف' : 'Description' ?></label><input type="text" name="description" maxlength="255" placeholder="<?= is_rtl() ? 'مثال: أتعاب استشارة + إعداد مذكرة' : 'e.g. Consultation fee + memo drafting' ?>" required></div>
      <div class="field"><label><?= is_rtl() ? 'المبلغ (د.ب)' : 'Amount (BHD)' ?></label><input type="number" step="0.001" min="0" name="amount" required></div>
      <div class="field"><label><?= is_rtl() ? 'تاريخ الاستحقاق (اختياري)' : 'Due date (optional)' ?></label><input type="date" name="due_date"></div>
    </div>
    <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'إصدار الفاتورة' : 'Issue invoice' ?></button>
    <?php if (empty($clients)): ?><p class="field-hint" style="margin-top:10px"><?= is_rtl() ? 'ستظهر أسماء عملائك هنا بعد أول قضية أو استشارة معهم.' : 'Your clients will appear here after your first case or consultation with them.' ?></p><?php endif; ?>
  </form>
  </div>

  <div class="invoice-filters"><input id="invoiceSearch" type="search" placeholder="<?= is_rtl()?'ابحث باسم العميل أو وصف الفاتورة أو القضية':'Search client, invoice, or case' ?>" aria-label="<?= is_rtl()?'البحث في الفواتير':'Search invoices' ?>"><select id="invoiceStatusFilter" aria-label="<?= is_rtl()?'تصفية حسب الحالة':'Filter by status' ?>"><option value="all"><?= is_rtl()?'كل الحالات':'All statuses' ?></option><option value="draft"><?= is_rtl()?'مسودات':'Drafts' ?></option><option value="unpaid"><?= is_rtl()?'غير مدفوعة':'Unpaid' ?></option><option value="paid"><?= is_rtl()?'مدفوعة':'Paid' ?></option><option value="overdue"><?= is_rtl()?'متأخرة':'Overdue' ?></option><option value="cancelled"><?= is_rtl()?'ملغاة':'Cancelled' ?></option></select></div>

  <div class="table-wrap">
    <table class="admin-table" id="invoiceTable">
      <thead><tr><th><?= is_rtl() ? 'العميل' : 'Client' ?></th><th><?= is_rtl() ? 'الوصف' : 'Description' ?></th><th><?= is_rtl() ? 'القضية' : 'Case' ?></th><th><?= is_rtl() ? 'المبلغ' : 'Amount' ?></th><th><?= is_rtl() ? 'الاستحقاق' : 'Due' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($invoices as $inv):
          $isOverdue = $inv['status'] === 'unpaid' && $inv['due_date'] && strtotime($inv['due_date']) < time();
          $displayStatus = !empty($inv['is_draft']) ? 'draft' : ($isOverdue ? 'overdue' : $inv['status']);
          $statusLabels = ['draft'=>is_rtl()?'مسودة غير مرسلة':'Unsent draft','unpaid' => (is_rtl()?'غير مدفوعة':'Unpaid'), 'paid' => (is_rtl()?'مدفوعة':'Paid'), 'overdue' => (is_rtl()?'متأخرة':'Overdue'), 'cancelled' => (is_rtl()?'ملغاة':'Cancelled')];
        ?>
        <tr data-invoice-status="<?= htmlspecialchars($displayStatus) ?>" data-invoice-search="<?= htmlspecialchars(mb_strtolower($inv['client_name'].' '.$inv['description'].' '.($inv['case_title']??''),'UTF-8')) ?>">
          <td><?= htmlspecialchars($inv['client_name']) ?></td>
          <td><?= htmlspecialchars($inv['description']) ?></td>
          <td><?php if($inv['case_id']): ?><a href="/lawyer/case-detail.php?id=<?= (int)$inv['case_id'] ?>"><?= htmlspecialchars($inv['case_title'] ?? '—') ?></a><?php else: ?><?= htmlspecialchars($inv['case_title'] ?? '—') ?><?php endif; ?></td>
          <td><?= number_format($inv['amount'], 3) ?> <?= is_rtl() ? 'د.ب' : 'BHD' ?></td>
          <td><?= $inv['due_date'] ? date('Y-m-d', strtotime($inv['due_date'])) : '—' ?></td>
          <td><span class="status-pill status-<?= $displayStatus === 'paid' ? 'confirmed' : ($displayStatus === 'overdue' ? 'cancelled' : 'pending') ?>"><?= $statusLabels[$displayStatus] ?></span></td>
          <td><div class="invoice-actions">
            <?php if ($inv['status'] === 'unpaid' && !empty($inv['is_draft'])): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('<?= is_rtl()?'تأكيد إصدار الفاتورة وإشعار العميل؟':'Issue this invoice and notify the client?' ?>')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="issue_time_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>"><button class="icon-btn" type="submit"><?= is_rtl()?'مراجعة وإصدار':'Review & issue' ?></button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('<?= is_rtl()?'حذف المسودة وإعادة الساعات إلى السجل غير المفوتر؟':'Delete draft and release its time entries?' ?>')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>"><button class="icon-btn danger" type="submit"><?= is_rtl()?'حذف المسودة':'Discard draft' ?></button></form>
            <?php elseif ($inv['status'] === 'unpaid'): ?>
              <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'تأكيد استلام الدفعة؟' : 'Confirm payment received?' ?>')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>"><button class="icon-btn" type="submit"><?= is_rtl() ? 'تحديد كمدفوعة' : 'Mark paid' ?></button></form>
              <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'إلغاء الفاتورة؟' : 'Cancel invoice?' ?>')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>"><button class="icon-btn danger" type="submit"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button></form>
            <?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?><tr><td colspan="7"><div class="empty-state"><div style="font-size:2rem">🧾</div><strong><?= is_rtl() ? 'لا توجد فواتير بعد' : 'No invoices yet' ?></strong><p><?= is_rtl()?'ابدأ بإنشاء فاتورة لعميل مرتبط بقضية أو استشارة.':'Create an invoice for a client linked to a case or consultation.' ?></p><button type="button" class="btn btn-outline btn-sm" onclick="openModal('addInvoiceModal')"><?= is_rtl()?'إصدار أول فاتورة':'Create first invoice' ?></button></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function(){
  var search=document.getElementById('invoiceSearch'), filter=document.getElementById('invoiceStatusFilter'), rows=Array.prototype.slice.call(document.querySelectorAll('#invoiceTable tbody tr[data-invoice-status]'));
  function applyInvoiceFilters(){var q=(search.value||'').trim().toLocaleLowerCase(), status=filter.value; rows.forEach(function(row){var matchText=(row.dataset.invoiceSearch||'').toLocaleLowerCase().indexOf(q)!==-1, matchStatus=status==='all'||row.dataset.invoiceStatus===status; row.hidden=!(matchText&&matchStatus);});}
  search.addEventListener('input',applyInvoiceFilters); filter.addEventListener('change',applyInvoiceFilters);
})();
</script>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
