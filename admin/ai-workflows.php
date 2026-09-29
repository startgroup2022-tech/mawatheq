<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/ai_workflow.php';
require_once __DIR__ . '/../includes/audit_log.php';

$adminId = (int)$_SESSION['admin_id']; $message = null; $error = null;
$ready = true;
try { $pdo->query('SELECT 1 FROM ai_workflows LIMIT 1'); $pdo->query('SELECT workflow_run_id FROM ai_agent_executions LIMIT 1'); } catch (Throwable $e) { $ready = false; }
$settings = $ready ? ai_load_settings($pdo) : [];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'run') {
            $q = $pdo->prepare('SELECT * FROM ai_workflows WHERE id=?'); $q->execute([(int)($_POST['workflow_id'] ?? 0)]); $wf = $q->fetch();
            if (!$wf) throw new RuntimeException(adm('سير العمل غير موجود.', 'Workflow not found.'));
            $task = trim((string)($_POST['task_input'] ?? ''));
            @set_time_limit(180);
            $r = ai_workflow_run($pdo, $settings, $wf, $task, $adminId);
            log_action($pdo, 'تشغيل سير عمل AI: ' . $wf['name'] . ' (' . ($r['status'] ?? 'failed') . ')', 'ai_workflow_run', $r['run_id'] ?: null);
            if ($r['ok']) { $message = $r['status'] === 'pending_approval' ? adm('اكتمل التشغيل وهو بانتظار موافقتك أدناه.', 'Run finished and is waiting for your approval below.') : adm('اكتمل التشغيل.', 'Run completed.'); }
            else $error = $r['error'];
        } elseif ($action === 'review') {
            $id = (int)($_POST['run_id'] ?? 0); $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
            $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 500, 'UTF-8');
            if ($decision === 'rejected' && $note === '') throw new RuntimeException(adm('اكتب سبب الرفض.', 'A rejection reason is required.'));
            $u = $pdo->prepare("UPDATE ai_workflow_runs SET status=?, reviewed_by_admin_id=?, reviewed_at=NOW(), review_note=? WHERE id=? AND status='pending_approval'");
            $u->execute([$decision, $adminId, $note ?: null, $id]);
            if ($u->rowCount()) { log_action($pdo, ($decision === 'approved' ? 'اعتماد' : 'رفض') . ' مخرجات سير عمل AI #' . $id, 'ai_workflow_run', $id); $message = $decision === 'approved' ? adm('تم اعتماد المخرجات.', 'Output approved.') : adm('تم رفض المخرجات.', 'Output rejected.'); }
            else throw new RuntimeException(adm('هذا التشغيل ليس بانتظار الموافقة.', 'This run is not awaiting approval.'));
        } elseif ($action === 'save_workflow') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150, 'UTF-8');
            if ($name === '') throw new RuntimeException(adm('اسم سير العمل مطلوب.', 'Workflow name is required.'));
            $raw = []; foreach ((array)($_POST['step_agent'] ?? []) as $i => $a) $raw[] = ['agent' => $a, 'module' => ($_POST['step_module'][$i] ?? '')];
            $v = ai_workflow_validate_steps($pdo, $raw); if (!$v['ok']) throw new RuntimeException($v['error']);
            $pdo->prepare('INSERT INTO ai_workflows (name,description,steps_json,requires_approval,created_by_admin_id) VALUES (?,?,?,?,?)')
                ->execute([$name, mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500, 'UTF-8') ?: null, json_encode($v['steps']), isset($_POST['requires_approval']) ? 1 : 0, $adminId]);
            log_action($pdo, 'إنشاء سير عمل AI: ' . $name, 'ai_workflow', (int)$pdo->lastInsertId()); $message = adm('تم حفظ سير العمل.', 'Workflow saved.');
        } elseif ($action === 'toggle_workflow') {
            $pdo->prepare("UPDATE ai_workflows SET status=IF(status='active','disabled','active') WHERE id=?")->execute([(int)($_POST['workflow_id'] ?? 0)]);
            $message = adm('تم تحديث الحالة.', 'Status updated.');
        }
    } catch (PDOException $e) { error_log('ai-workflows: ' . $e->getMessage()); $error = adm('تعذّر الحفظ (قد يكون الاسم مكررًا).', 'Could not save (the name may already exist).'); }
    catch (Throwable $e) { $error = $e->getMessage(); }
}

$activeAdmin = 'ai-workflows'; $pageTitle = adm('سير عمل الوكلاء', 'Agent workflows');
require_once __DIR__ . '/includes/admin_header.php';
if (!$ready) {
    echo '<div class="app-content"><div class="card"><h3>' . h(adm('يلزم تشغيل ترقية قاعدة البيانات', 'Database upgrade required')) . '</h3><p>' . h(adm('شغّل database/upgrade_v41.sql (وتأكد من تشغيل upgrade_v26 سابقًا) ثم أعد تحميل الصفحة.', 'Run database/upgrade_v41.sql (upgrade_v26 must already be applied) and reload.')) . '</p></div></div>';
    require __DIR__ . '/includes/admin_footer.php'; exit;
}
$agents = $pdo->query('SELECT agent_key,name,allowed_modules,status FROM ai_agents ORDER BY name')->fetchAll();
$workflows = $pdo->query('SELECT * FROM ai_workflows ORDER BY id DESC')->fetchAll();
$wfName = []; foreach ($workflows as $w) $wfName[$w['id']] = $w['name'];
$pending = $pdo->query("SELECT * FROM ai_workflow_runs WHERE status='pending_approval' ORDER BY id DESC")->fetchAll();
$runs = $pdo->query('SELECT r.*, a.full_name reviewer FROM ai_workflow_runs r LEFT JOIN admins a ON a.id=r.reviewed_by_admin_id ORDER BY r.id DESC LIMIT 40')->fetchAll();
$stats = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(status='pending_approval'),0) p, COALESCE(SUM(status='failed'),0) f, COALESCE(SUM(total_input_tokens+total_output_tokens),0) tok, SUM(estimated_cost) cost FROM ai_workflow_runs")->fetch();
$stepRows = function (int $runId) use ($pdo): array { $s = $pdo->prepare('SELECT e.step_no,e.status,e.output_text,e.error_message,e.duration_ms,e.input_tokens,e.output_tokens,e.source_ids_json,a.name FROM ai_agent_executions e LEFT JOIN ai_agents a ON a.id=e.agent_id WHERE e.workflow_run_id=? ORDER BY e.step_no,e.id'); $s->execute([$runId]); return $s->fetchAll(); };
$stLabel = ['running' => adm('قيد التشغيل', 'Running'), 'completed' => adm('مكتمل', 'Completed'), 'failed' => adm('فشل', 'Failed'), 'pending_approval' => adm('بانتظار الموافقة', 'Pending approval'), 'approved' => adm('معتمد', 'Approved'), 'rejected' => adm('مرفوض', 'Rejected')];
$stPill = ['running' => 'blue', 'completed' => 'green', 'failed' => 'red', 'pending_approval' => 'amber', 'approved' => 'green', 'rejected' => 'red'];
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
$activeWf = array_values(array_filter($workflows, fn($w) => $w['status'] === 'active'));
$renderSteps = function (int $runId) use ($stepRows): void {
    foreach ($stepRows($runId) as $s): $ids = json_decode((string)$s['source_ids_json'], true) ?: []; ?>
    <div class="card" style="background:var(--surface-2);margin:10px 0"><div class="card-h"><h3><?= h(adm('الخطوة ', 'Step ')) . (int)$s['step_no'] ?> · <?= h($s['name'] ?: '—') ?></h3><span class="pill <?= $s['status'] === 'completed' ? 'green' : 'red' ?>"><?= h($s['status']) ?></span></div>
      <div style="white-space:pre-wrap;font-size:13px"><?= h($s['output_text'] ?: $s['error_message']) ?></div>
      <small style="color:var(--muted)"><?= (int)$s['duration_ms'] ?> ms · <?= (int)$s['input_tokens'] ?>/<?= (int)$s['output_tokens'] ?> tokens · <?= h(adm('مصادر: ', 'sources: ')) . h(implode(', ', array_map(fn($x) => '#' . $x, $ids)) ?: '—') ?></small></div>
<?php endforeach; };
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('سير عمل الوكلاء', 'Agent workflows')) ?></h1><p><?= h(adm('سلاسل وكلاء متعددة الخطوات، مقيّدة بالمصادر الموثّقة، ولا يُعتمد ناتجها قبل مراجعة بشرية.', 'Multi-step agent chains grounded in verified sources — nothing is final until a human approves it.')) ?></p></div>
    <div class="pg-tools"><a class="btn btn-outline" href="/admin/ai-orchestration.php"><?= adm_icon('bot') ?><?= h(adm('الوكلاء والوحدات', 'Agents & modules')) ?></a></div></div>
  <?php if ($message): ?><div class="form-alert success"><?= h($message) ?></div><?php endif; ?><?php if ($error): ?><div class="form-alert error"><?= h($error) ?></div><?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('bot') ?></span></div><div class="kpi-val"><?= (int)$stats['n'] ?></div><div class="kpi-lbl"><?= h(adm('إجمالي التشغيلات', 'Total runs')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('check') ?></span></div><div class="kpi-val"><?= (int)$stats['p'] ?></div><div class="kpi-lbl"><?= h(adm('بانتظار الموافقة', 'Awaiting approval')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic red"><?= adm_icon('shield') ?></span></div><div class="kpi-val"><?= (int)$stats['f'] ?></div><div class="kpi-lbl"><?= h(adm('تشغيلات فاشلة', 'Failed runs')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic teal"><?= adm_icon('spark') ?></span></div><div class="kpi-val"><?= number_format((int)$stats['tok']) ?></div><div class="kpi-lbl"><?= h(adm('إجمالي الـ Tokens', 'Total tokens')) ?><?= $stats['cost'] !== null ? ' · ≈ ' . number_format((float)$stats['cost'], 4) : '' ?></div></div>
  </div>

  <?php if ($pending): ?>
  <div class="card" style="border-color:var(--gold)"><div class="card-h"><h3><?= adm_icon('check') ?> <?= h(adm('طابور الموافقة البشرية', 'Human approval queue')) ?> <span class="count-badge"><?= count($pending) ?></span></h3></div>
    <?php foreach ($pending as $p): ?>
      <details class="card" style="background:var(--surface-2)" open><summary><b>#<?= (int)$p['id'] ?> · <?= h($wfName[$p['workflow_id']] ?? '—') ?></b> <small style="color:var(--muted)"> — <?= h(mb_substr($p['task_input'], 0, 90, 'UTF-8')) ?></small></summary>
        <p style="margin:10px 0 4px"><b><?= h(adm('المخرجات النهائية (مسودة تنتظر اعتمادك)', 'Final output (draft awaiting approval)')) ?></b></p>
        <div style="white-space:pre-wrap;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:12px;font-size:13px"><?= h($p['final_output']) ?></div>
        <details style="margin:10px 0"><summary><?= h(adm('عرض خطوات التنفيذ', 'Show execution steps')) ?></summary><?php $renderSteps((int)$p['id']); ?></details>
        <form method="post" class="grid-2" style="align-items:end"><?= $csrf ?><input type="hidden" name="action" value="review"><input type="hidden" name="run_id" value="<?= (int)$p['id'] ?>">
          <div class="field" style="margin:0"><label><?= h(adm('ملاحظة المراجعة (إلزامية عند الرفض)', 'Review note (required to reject)')) ?></label><input name="review_note" maxlength="500"></div>
          <div style="display:flex;gap:8px"><button class="btn btn-primary" name="decision" value="approve" type="submit"><?= h(adm('اعتماد', 'Approve')) ?></button><button class="btn btn-outline btn-danger" name="decision" value="reject" type="submit"><?= h(adm('رفض', 'Reject')) ?></button></div></form>
        <p class="field-hint"><?= h(adm('مخرجات الذكاء الاصطناعي مساعدة أولية وليست رأيًا قانونيًا؛ راجع الاستشهادات قبل الاعتماد.', 'AI output is preliminary assistance, not legal advice — verify the citations before approving.')) ?></p></details>
    <?php endforeach; ?></div>
  <?php endif; ?>

  <div class="grid g-12">
    <div class="card"><div class="card-h"><h3><?= h(adm('تشغيل سير عمل', 'Run a workflow')) ?></h3></div>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="run">
        <div class="field"><label><?= h(adm('سير العمل', 'Workflow')) ?></label><select name="workflow_id" required><?php foreach ($activeWf as $w): ?><option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= h(adm('المهمة / السؤال', 'Task / question')) ?></label><textarea name="task_input" rows="5" required maxlength="6000"></textarea><p class="field-hint"><?= h(adm('يشترط وجود مصادر معرفة موثّقة مطابقة، وإلا يرفض الوكيل الإجابة.', 'Requires matching verified knowledge sources; otherwise the agent refuses to answer.')) ?></p></div>
        <button class="btn btn-primary" type="submit" <?= $activeWf ? '' : 'disabled' ?>><?= adm_icon('bot') ?><?= h(adm('تشغيل', 'Run')) ?></button></form></div>
    <div class="card"><div class="card-h"><h3><?= h(adm('سير العمل المتاحة', 'Available workflows')) ?></h3><button class="btn btn-outline btn-sm" onclick="openModal('mWf')"><?= adm_icon('plus') ?><?= h(adm('جديد', 'New')) ?></button></div>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th><?= h(adm('الاسم', 'Name')) ?></th><th><?= h(adm('الخطوات', 'Steps')) ?></th><th><?= h(adm('موافقة', 'Approval')) ?></th><th></th></tr></thead><tbody>
        <?php foreach ($workflows as $w): $st = json_decode($w['steps_json'], true) ?: []; ?><tr style="<?= $w['status'] === 'disabled' ? 'opacity:.5' : '' ?>"><td><b><?= h($w['name']) ?></b><br><small style="color:var(--muted)"><?= h($w['description']) ?></small></td><td><?= h(implode(' → ', array_map(fn($s) => $s['agent'], $st))) ?></td><td><span class="pill <?= $w['requires_approval'] ? 'amber' : 'gray' ?>"><?= $w['requires_approval'] ? h(adm('مطلوبة', 'Required')) : h(adm('لا', 'No')) ?></span></td>
          <td><form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="action" value="toggle_workflow"><input type="hidden" name="workflow_id" value="<?= (int)$w['id'] ?>"><button class="icon-btn" type="submit"><?= h($w['status'] === 'active' ? adm('تعطيل', 'Disable') : adm('تفعيل', 'Enable')) ?></button></form></td></tr><?php endforeach; ?>
        <?php if (!$workflows): ?><tr><td colspan="4" class="empty"><?= h(adm('لا توجد سير عمل', 'No workflows')) ?></td></tr><?php endif; ?></tbody></table></div></div>
  </div>

  <div class="card"><div class="card-h"><h3><?= h(adm('سجل التشغيل', 'Run history')) ?></h3></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>#</th><th><?= h(adm('سير العمل', 'Workflow')) ?></th><th><?= h(adm('المهمة', 'Task')) ?></th><th><?= h(adm('الحالة', 'Status')) ?></th><th>Tokens</th><th><?= h(adm('المراجع', 'Reviewer')) ?></th><th><?= h(adm('التاريخ', 'Date')) ?></th></tr></thead><tbody>
      <?php foreach ($runs as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= h($wfName[$r['workflow_id']] ?? '—') ?></td><td style="max-width:280px;white-space:normal"><?= h(mb_substr($r['task_input'], 0, 100, 'UTF-8')) ?><?php if ($r['error_message']): ?><br><small style="color:var(--danger)"><?= h(adm('الخطوة ', 'Step ')) . (int)$r['failed_step'] ?>: <?= h($r['error_message']) ?></small><?php endif; ?><?php if ($r['review_note']): ?><br><small style="color:var(--muted)"><?= h($r['review_note']) ?></small><?php endif; ?></td>
        <td><span class="pill <?= $stPill[$r['status']] ?>"><?= h($stLabel[$r['status']]) ?></span></td><td><?= (int)$r['total_input_tokens'] + (int)$r['total_output_tokens'] ?></td><td><?= h($r['reviewer'] ?: '—') ?></td><td><?= h(date('m-d H:i', strtotime($r['created_at']))) ?></td></tr><?php endforeach; ?>
      <?php if (!$runs): ?><tr><td colspan="7" class="empty"><?= h(adm('لا توجد تشغيلات بعد', 'No runs yet')) ?></td></tr><?php endif; ?></tbody></table></div></div>
</div>

<div class="modal-backdrop" id="mWf"><form class="modal-form" method="post"><?= $csrf ?><button type="button" class="modal-close" onclick="closeModal('mWf')">✕</button><input type="hidden" name="action" value="save_workflow">
  <h3><?= h(adm('سير عمل جديد', 'New workflow')) ?></h3>
  <div class="field"><label><?= h(adm('الاسم', 'Name')) ?></label><input name="name" required maxlength="150"></div>
  <div class="field"><label><?= h(adm('الوصف', 'Description')) ?></label><input name="description" maxlength="500"></div>
  <div class="form-section-title"><?= h(adm('الخطوات بالترتيب (حتى 6)', 'Steps in order (up to 6)')) ?></div>
  <?php for ($i = 0; $i < 4; $i++): ?><div class="grid-2"><div class="field"><select name="step_agent[]"><option value="">— <?= h(adm('وكيل', 'agent')) ?> <?= $i + 1 ?> —</option><?php foreach ($agents as $a): ?><option value="<?= h($a['agent_key']) ?>"><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><input name="step_module[]" placeholder="<?= h(adm('معرّف الوحدة مثل legal_research', 'module id e.g. legal_research')) ?>"></div></div><?php endfor; ?>
  <p class="field-hint"><?= h(adm('يجب أن تكون الوحدة ضمن الوحدات المسموحة للوكيل.', 'The module must be one the agent is allowed to use.')) ?></p>
  <label style="display:flex;gap:8px;align-items:center;margin:8px 0 16px"><input type="checkbox" name="requires_approval" checked> <?= h(adm('يتطلب موافقة بشرية قبل اعتماد الناتج', 'Requires human approval before the output is final')) ?></label>
  <button class="btn btn-primary" type="submit"><?= h(adm('حفظ', 'Save')) ?></button></form></div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
