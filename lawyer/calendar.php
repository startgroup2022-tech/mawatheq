<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
$lawyerId = (int)$_SESSION['lawyer_id'];
$calendarMessage = null;
$eventTypes = ['court_session','appeal_deadline','contract_expiry','contract_renewal','government_deadline','other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'add_event') {
        $title = trim((string)($_POST['title'] ?? ''));
        $type = (string)($_POST['event_type'] ?? 'other');
        $rawDate = trim((string)($_POST['event_at'] ?? ''));
        $eventDate = DateTime::createFromFormat('!Y-m-d\\TH:i', $rawDate);
        $caseId = (int)($_POST['case_id'] ?? 0);
        $validCase = true;
        if ($caseId > 0) {
            $check = $pdo->prepare('SELECT id FROM cases WHERE id=? AND lawyer_id=?');
            $check->execute([$caseId, $lawyerId]);
            $validCase = (bool)$check->fetchColumn();
        }
        if ($title === '' || !in_array($type, $eventTypes, true) || !$eventDate || $eventDate->format('Y-m-d\\TH:i') !== $rawDate || !$validCase) {
            $calendarMessage = is_rtl() ? 'تحقق من عنوان الموعد ونوعه وتاريخه والقضية المرتبطة.' : 'Check the event title, type, date, and linked case.';
        } else {
            $pdo->prepare('INSERT INTO legal_calendar_events (lawyer_id,case_id,event_type,title,description,event_at) VALUES (?,?,?,?,?,?)')
                ->execute([$lawyerId, $caseId ?: null, $type, $title, trim((string)($_POST['description'] ?? '')) ?: null, $eventDate->format('Y-m-d H:i:00')]);
            header('Location: /lawyer/calendar.php?added=1'); exit;
        }
    } elseif (in_array($action, ['complete_event','cancel_event','delete_event'], true)) {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($action === 'delete_event') {
            $pdo->prepare('DELETE FROM legal_calendar_events WHERE id=? AND lawyer_id=?')->execute([$eventId,$lawyerId]);
        } else {
            $nextStatus = $action === 'complete_event' ? 'completed' : 'cancelled';
            $pdo->prepare('UPDATE legal_calendar_events SET status=? WHERE id=? AND lawyer_id=?')->execute([$nextStatus,$eventId,$lawyerId]);
        }
        header('Location: /lawyer/calendar.php'); exit;
    }
}

$sql = "SELECT event_at,event_title,case_title,case_id,event_type,event_id,event_status FROM (
    SELECT CONCAT(ct.event_date,' 00:00:00') AS event_at,ct.event AS event_title,c.title AS case_title,c.id AS case_id,'timeline' AS event_type,NULL AS event_id,'upcoming' AS event_status
    FROM case_timeline ct JOIN cases c ON c.id=ct.case_id WHERE c.lawyer_id=?
    UNION ALL
    SELECT CONCAT(t.due_date,' 23:59:59') AS event_at,t.title AS event_title,c.title AS case_title,c.id AS case_id,'task' AS event_type,NULL AS event_id,'upcoming' AS event_status
    FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE c.lawyer_id=? AND t.due_date IS NOT NULL AND t.status='pending'
    UNION ALL
    SELECT co.scheduled_at AS event_at,COALESCE(co.topic,'استشارة') AS event_title,u.full_name AS case_title,NULL AS case_id,'consultation' AS event_type,NULL AS event_id,'upcoming' AS event_status
    FROM consultations co JOIN users u ON u.id=co.user_id WHERE co.lawyer_id=? AND co.status IN ('pending','confirmed')
    UNION ALL
    SELECT e.event_at AS event_at,e.title AS event_title,COALESCE(c.title,'—') AS case_title,e.case_id,e.event_type,e.id AS event_id,e.status AS event_status
    FROM legal_calendar_events e LEFT JOIN cases c ON c.id=e.case_id WHERE e.lawyer_id=? AND e.status<>'cancelled'
) calendar_items WHERE event_at >= DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY event_at ASC LIMIT 500";
$stmt = $pdo->prepare($sql); $stmt->execute([$lawyerId,$lawyerId,$lawyerId,$lawyerId]); $events = $stmt->fetchAll();
$overdueEvents = [];
$soonEvents = [];
$nowTimestamp = time();
foreach ($events as $calendarEvent) {
    if (($calendarEvent['event_status'] ?? '') !== 'upcoming' || in_array($calendarEvent['event_type'] ?? '', ['timeline'], true)) continue;
    $eventTimestamp = strtotime($calendarEvent['event_at'] ?? '');
    if (!$eventTimestamp) continue;
    if ($eventTimestamp < $nowTimestamp) $overdueEvents[] = $calendarEvent;
    elseif ($eventTimestamp <= $nowTimestamp + 7 * 86400) $soonEvents[] = $calendarEvent;
}
$caseQuery = $pdo->prepare("SELECT id,title FROM cases WHERE lawyer_id=? AND status NOT IN ('closed_won','closed_lost','closed_settled') ORDER BY title");
$caseQuery->execute([$lawyerId]); $cases = $caseQuery->fetchAll();
$activeLawyer = 'calendar';
require_once __DIR__ . '/includes/lawyer_header.php';
$labels = [
    'timeline'=>is_rtl()?'موعد/حدث قضية':'Case event','task'=>is_rtl()?'مهمة مستحقة':'Task deadline','consultation'=>is_rtl()?'استشارة':'Consultation',
    'court_session'=>is_rtl()?'جلسة قضائية':'Court session','appeal_deadline'=>is_rtl()?'موعد استئناف':'Appeal deadline',
    'contract_expiry'=>is_rtl()?'انتهاء عقد':'Contract expiry','contract_renewal'=>is_rtl()?'تجديد عقد':'Contract renewal',
    'government_deadline'=>is_rtl()?'موعد جهة حكومية':'Government deadline','other'=>is_rtl()?'موعد آخر':'Other event'
];
?>
<div class="app-content">
  <div class="toolbar"><h2>📅 <?= is_rtl() ? 'التقويم القانوني' : 'Legal calendar' ?></h2><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-outline" href="/lawyer/calendar-export.php"><?= is_rtl() ? 'تصدير التقويم (ICS)' : 'Export calendar (ICS)' ?></a><button type="button" class="btn btn-primary" onclick="openModal('addCalendarEventModal')">＋ <?= is_rtl()?'إضافة موعد':'Add event' ?></button></div></div>
  <p style="color:var(--text-muted)"><?= is_rtl() ? 'يجمع مواعيد القضايا ومهامها والاستشارات، ويمكنك إضافة جلسات ومواعيد استئناف وتجديد وانتهاء العقود والمواعيد الحكومية.' : 'View case events, tasks, consultations, court sessions, appeal dates, contract renewals and expirations, and government deadlines.' ?></p>
  <?php if ($calendarMessage): ?><div class="form-alert error"><?= htmlspecialchars($calendarMessage) ?></div><?php endif; ?>
  <?php if (isset($_GET['added'])): ?><div class="form-alert success"><?= is_rtl()?'تمت إضافة الموعد إلى التقويم.':'Event added to the calendar.' ?></div><?php endif; ?>
  <?php if ($overdueEvents): ?><div class="form-alert error"><strong><?= is_rtl()?'مواعيد متأخرة تحتاج مراجعة':'Overdue items to review' ?> (<?= count($overdueEvents) ?>)</strong><ul style="margin:8px 0 0"><?php foreach(array_slice($overdueEvents,0,8) as $urgent): ?><li><?= htmlspecialchars($urgent['event_title']) ?> — <?= date('Y-m-d H:i',strtotime($urgent['event_at'])) ?><?= $urgent['case_id']?' · '.htmlspecialchars($urgent['case_title']):'' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($soonEvents): ?><div class="form-alert" style="background:#FFF8E6;color:#795b12"><strong><?= is_rtl()?'مواعيد خلال الأيام السبعة القادمة':'Due in the next seven days' ?> (<?= count($soonEvents) ?>)</strong><ul style="margin:8px 0 0"><?php foreach(array_slice($soonEvents,0,8) as $urgent): ?><li><?= htmlspecialchars($urgent['event_title']) ?> — <?= date('Y-m-d H:i',strtotime($urgent['event_at'])) ?><?= $urgent['case_id']?' · '.htmlspecialchars($urgent['case_title']):'' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="modal-backdrop<?= $calendarMessage ? ' is-open' : '' ?>" id="addCalendarEventModal"><form class="modal-form" method="post" aria-labelledby="addCalendarEventTitle">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_event">
    <button type="button" class="modal-close" onclick="closeModal('addCalendarEventModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button>
    <h3 id="addCalendarEventTitle"><?= is_rtl()?'إضافة موعد قانوني':'Add legal event' ?></h3>
    <div class="grid-2">
      <div class="field"><label><?= is_rtl()?'العنوان':'Title' ?></label><input name="title" maxlength="255" required></div>
      <div class="field"><label><?= is_rtl()?'النوع':'Type' ?></label><select name="event_type" class="pro-select"><?php foreach(array_slice($eventTypes,0) as $type): ?><option value="<?= $type ?>"><?= $labels[$type] ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= is_rtl()?'التاريخ والوقت':'Date and time' ?></label><input type="datetime-local" name="event_at" required value="<?= htmlspecialchars(date('Y-m-d\\TH:i',strtotime('+1 day'))) ?>"></div>
      <div class="field"><label><?= is_rtl()?'القضية (اختياري)':'Case (optional)' ?></label><select name="case_id" class="pro-select"><option value="0">—</option><?php foreach($cases as $case): ?><option value="<?= (int)$case['id'] ?>"><?= htmlspecialchars($case['title']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:1/-1"><label><?= is_rtl()?'ملاحظات':'Notes' ?></label><textarea name="description" rows="2" maxlength="5000"></textarea></div>
    </div><button class="btn btn-primary" type="submit"><?= is_rtl()?'حفظ الموعد':'Save event' ?></button>
  </form></div>
  <div class="table-wrap"><table class="admin-table"><thead><tr><th><?= is_rtl()?'التاريخ والوقت':'Date and time' ?></th><th><?= is_rtl()?'النوع':'Type' ?></th><th><?= is_rtl()?'الموضوع':'Item' ?></th><th><?= is_rtl()?'القضية / العميل':'Case / Client' ?></th><th><?= is_rtl()?'الإجراء':'Action' ?></th></tr></thead><tbody>
  <?php foreach ($events as $event): $rowTimestamp=strtotime($event['event_at']); $isOverdue=($event['event_status']==='upcoming' && $rowTimestamp && $rowTimestamp<$nowTimestamp && $event['event_type']!=='timeline'); ?><tr><td><?= date('Y-m-d H:i',strtotime($event['event_at'])) ?><?php if($isOverdue): ?> <span class="status-pill status-cancelled"><?= is_rtl()?'متأخر':'Overdue' ?></span><?php endif; ?></td><td><span class="status-pill status-pending"><?= $labels[$event['event_type']] ?? htmlspecialchars($event['event_type']) ?></span></td><td><?= htmlspecialchars($event['event_title']) ?><?= $event['event_status']==='completed'?' · '.(is_rtl()?'مكتمل':'Completed'):'' ?></td><td><?php if ($event['case_id']): ?><a href="/lawyer/case-detail.php?id=<?= (int)$event['case_id'] ?>"><?= htmlspecialchars($event['case_title']) ?></a><?php else: ?><?= htmlspecialchars($event['case_title']) ?><?php endif; ?></td><td><?php if ($event['event_id'] && $event['event_status']==='upcoming'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= (int)$event['event_id'] ?>"><button class="btn btn-outline btn-sm" name="action" value="complete_event"><?= is_rtl()?'إكمال':'Complete' ?></button> <button class="btn btn-outline btn-sm" name="action" value="delete_event" onclick="return confirm('<?= is_rtl()?'حذف الموعد؟':'Delete this event?' ?>')"><?= is_rtl()?'حذف':'Delete' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$events): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted)"><?= is_rtl()?'لا توجد مواعيد ضمن الفترة الحالية.':'No agenda items in this period.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
