<?php
$activePage = 'case-tasks';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notifications.php';
$userId = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $taskId = (int)($_POST['task_id'] ?? 0);
    $q = $pdo->prepare("SELECT t.id,t.title,c.lawyer_id,c.firm_id FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE t.id=? AND c.user_id=? AND t.client_visible=1 AND t.status='pending' LIMIT 1");
    $q->execute([$taskId,$userId]); $task = $q->fetch();
    if ($task) {
        $pdo->prepare("UPDATE case_tasks SET status='done' WHERE id=? AND status='pending'")->execute([$taskId]);
        // Let the whole firm know the client completed a shared task, not just the case owner.
        notify_tenant_except($pdo, $task['firm_id'] !== null ? (int)$task['firm_id'] : null, (int)$task['lawyer_id'], 0, is_rtl()?'أكمل العميل مهمة':'Client completed a task', $task['title'], '/lawyer/cases.php');
    }
    header('Location: /case-tasks.php'); exit;
}
$q = $pdo->prepare('SELECT t.*,c.title case_title,l.full_name lawyer_name FROM case_tasks t JOIN cases c ON c.id=t.case_id JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? AND t.client_visible=1 ORDER BY (t.status=\'done\'),(t.due_date IS NULL),t.due_date,t.id');
$q->execute([$userId]); $tasks=$q->fetchAll();
?>
<div class="app-content"><div class="toolbar"><h2>✅ <?= is_rtl()?'مهامي القانونية':'My legal tasks' ?></h2></div>
<p style="color:var(--text-muted)"><?= is_rtl()?'المهام التي شاركها محاموك معك في القضايا.':'Tasks your lawyers have shared with you for your cases.' ?></p>
<?php foreach($tasks as $task): ?><div class="dash-card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;<?= $task['status']==='done'?'opacity:.65':'' ?>"><div><strong><?= htmlspecialchars($task['title']) ?></strong><div style="color:var(--text-muted);font-size:.84rem"><?= htmlspecialchars($task['case_title']) ?> · <?= htmlspecialchars($task['lawyer_name']) ?><?= $task['due_date']?' · '.(is_rtl()?'الاستحقاق ':'Due ').date('Y-m-d',strtotime($task['due_date'])):'' ?></div></div><?php if($task['status']==='pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="btn btn-primary btn-sm" type="submit"><?= is_rtl()?'تم إنجاز المهمة':'Mark complete' ?></button></form><?php else: ?><span class="status-pill status-confirmed"><?= is_rtl()?'مكتملة':'Completed' ?></span><?php endif; ?></div><?php endforeach; ?>
<?php if(!$tasks): ?><div class="dash-card" style="text-align:center;color:var(--text-muted)"><?= is_rtl()?'لا توجد مهام مشاركة معك حالياً.':'No tasks have been shared with you yet.' ?></div><?php endif; ?></div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
