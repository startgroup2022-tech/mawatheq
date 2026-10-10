<?php
/**
 * Client "My cases" (Phase 4).
 *
 * Read-only view of the cases that belong to the signed-in client. Scope is
 * enforced by the query itself (cases.user_id = session user), exactly like
 * api/v1/cases.php, so no case the client does not own can ever be listed.
 * Only client-visible tasks are counted, matching the API's visibility rule.
 */
$activePage = 'my-cases';
require_once __DIR__ . '/lang/strings.php';
$pageTitle = is_rtl() ? 'قضاياي' : 'My cases';
$pageSubtitle = is_rtl()
    ? 'القضايا المسجّلة لك لدى محاميك، مع حالتها وآخر تحديثاتها.'
    : 'Cases your lawyer has opened for you, with their status and latest activity.';
require_once __DIR__ . '/includes/app_header.php';

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT c.*, l.full_name AS lawyer_name, l.specialization AS lawyer_specialization
     FROM cases c JOIN lawyers l ON l.id = c.lawyer_id
     WHERE c.user_id = ?
     ORDER BY c.updated_at DESC, c.id DESC'
);
$stmt->execute([$userId]);
$cases = $stmt->fetchAll();

// One query for the visible-task counts instead of one per case.
$taskCounts = [];
if ($cases) {
    $ids = array_map(fn($c) => (int)$c['id'], $cases);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tq = $pdo->prepare("SELECT case_id, COUNT(*) total, SUM(status='pending') pending FROM case_tasks WHERE client_visible=1 AND case_id IN ($in) GROUP BY case_id");
    $tq->execute($ids);
    foreach ($tq->fetchAll() as $row) { $taskCounts[(int)$row['case_id']] = $row; }
}
?>
<div class="app-content">
  <?= ui_breadcrumbs([
      ['label' => is_rtl() ? 'لوحة العميل' : 'Client dashboard', 'href' => '/ai-assistant.php'],
      ['label' => is_rtl() ? 'قضاياي' : 'My cases'],
  ]) ?>
  <?= ui_page_head(
      is_rtl() ? 'قضاياي' : 'My cases',
      is_rtl() ? 'القضايا المسجّلة لك لدى محاميك، مع حالتها وآخر تحديثاتها.' : 'Cases your lawyer has opened for you, with their status and latest activity.'
  ) ?>

  <?php if (!$cases): ?>
    <?= ui_empty_state(
        is_rtl() ? 'لا توجد قضايا بعد' : 'No cases yet',
        is_rtl() ? 'عندما يفتح محاميك قضية باسمك ستظهر هنا مع حالتها ومهامها.' : 'Once your lawyer opens a case for you it will appear here with its status and tasks.',
        'briefcase',
        ['<a class="btn btn-primary" href="/consultations.php">' . (is_rtl() ? 'احجز استشارة' : 'Book a consultation') . '</a>']
    ) ?>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($cases as $c): $counts = $taskCounts[(int)$c['id']] ?? ['total' => 0, 'pending' => 0]; ?>
        <article class="dash-card">
          <div class="row-between" style="align-items:flex-start">
            <div style="min-width:0">
              <h3 style="margin:0 0 4px"><?= htmlspecialchars($c['title']) ?></h3>
              <p class="muted-sm" style="margin:0">
                <?= is_rtl() ? 'المحامي:' : 'Lawyer:' ?> <?= htmlspecialchars($c['lawyer_name']) ?>
                <?php if (!empty($c['case_type'])): ?> · <?= htmlspecialchars($c['case_type']) ?><?php endif; ?>
                <?php if (!empty($c['updated_at'])): ?> · <?= is_rtl() ? 'آخر تحديث' : 'Updated' ?> <?= ui_date($c['updated_at']) ?><?php endif; ?>
              </p>
            </div>
            <?= ui_badge($c['status'], ui_status_label($c['status'])) ?>
          </div>

          <div class="inline-actions" style="margin-top:14px">
            <?php if ($counts['total'] > 0): ?>
              <span class="badge badge-neutral"><?= is_rtl() ? 'المهام' : 'Tasks' ?> <span class="count-badge"><?= (int)$counts['total'] ?></span></span>
              <?php if ((int)$counts['pending'] > 0): ?>
                <span class="badge badge-warning"><?= is_rtl() ? 'بانتظارك' : 'Awaiting you' ?> <span class="count-badge"><?= (int)$counts['pending'] ?></span></span>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($c['win_probability'])): ?>
              <span class="badge badge-info"><?= is_rtl() ? 'تقدير الفوز' : 'Win estimate' ?> <?= (int)$c['win_probability'] ?>%</span>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="/case-tasks.php"><?= is_rtl() ? 'عرض المهام' : 'View tasks' ?></a>
              <a class="btn btn-outline btn-sm" href="/case-summary.php?case=<?= (int)$c['id'] ?>"><?= is_rtl() ? 'الملخص' : 'Summary' ?></a>
          </div>

          <?php if (!empty($c['description'])): ?>
            <p class="muted-sm" style="margin:14px 0 0"><?= htmlspecialchars(mb_substr((string)$c['description'], 0, 240)) ?><?= mb_strlen((string)$c['description']) > 240 ? '…' : '' ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
