<?php
/**
 * مواعيد قضاياي — الجلسات والمواعيد النظامية للعميل.
 *
 * The client could see their cases and their documents, but not the single thing they
 * most need to act on: when the next hearing is. `legal_calendar_events` already models
 * court sessions and statutory deadlines for the lawyer; this page is the client-facing
 * read of the same rows.
 *
 * Scope is the query, not a filter applied afterwards: the event is joined to a case
 * whose `user_id` is the signed-in client. An event on another client's case cannot be
 * selected at all, so no amount of id guessing reaches it. The page is read-only by
 * design — a client does not edit the court's calendar.
 */
$activePage = 'case-hearings';
require_once __DIR__ . '/lang/strings.php';
$pageTitle = is_rtl() ? 'مواعيد قضاياي' : 'My case schedule';
$pageSubtitle = is_rtl()
    ? 'جلسات المحكمة والمواعيد النظامية المسجّلة على قضاياك.'
    : 'Court sessions and statutory deadlines recorded on your cases.';
require_once __DIR__ . '/includes/app_header.php';

$userId = (int)$_SESSION['user_id'];

// The join is the authorization: `c.user_id = ?`. Do not add a client-supplied id here.
$stmt = $pdo->prepare(
    "SELECT e.*, c.title AS case_title, l.full_name AS lawyer_name
       FROM legal_calendar_events e
       JOIN cases c ON c.id = e.case_id
       LEFT JOIN lawyers l ON l.id = e.lawyer_id
      WHERE c.user_id = ?
        AND e.status <> 'cancelled'
      ORDER BY e.event_at ASC"
);
$stmt->execute([$userId]);
$events = $stmt->fetchAll();

$now = new DateTimeImmutable('now');
$upcoming = [];
$past = [];
foreach ($events as $e) {
    try { $at = new DateTimeImmutable((string)$e['event_at']); }
    catch (Throwable $ex) { continue; }
    if ($at >= $now) $upcoming[] = $e + ['_at' => $at];
    else $past[] = $e + ['_at' => $at];
}
$past = array_reverse($past); // most recent first

$typeLabel = function (string $type): string {
    $ar = is_rtl();
    return match ($type) {
        'court_session' => $ar ? 'جلسة محكمة' : 'Court session',
        'appeal_deadline' => $ar ? 'آخر موعد استئناف' : 'Appeal deadline',
        'contract_expiry' => $ar ? 'انتهاء عقد' : 'Contract expiry',
        'contract_renewal' => $ar ? 'تجديد عقد' : 'Contract renewal',
        'government_deadline' => $ar ? 'موعد حكومي' : 'Government deadline',
        default => $ar ? 'موعد' : 'Event',
    };
};
$typeTone = fn(string $t): string => match ($t) {
    'court_session' => 'info',
    'appeal_deadline' => 'warning',
    'government_deadline' => 'warning',
    default => 'neutral',
};
?>
<div class="dash-head">
  <div>
    <h1><?= ui_e($pageTitle) ?></h1>
    <p style="color:var(--text-muted)"><?= ui_e($pageSubtitle) ?></p>
  </div>
</div>

<?php if (!$events): ?>
  <div class="dash-card">
    <p style="color:var(--text-muted)"><?= is_rtl()
        ? 'لا توجد مواعيد مسجّلة على قضاياك حالياً. يسجّلها محاميك عند تحديد جلسة أو موعد نظامي.'
        : 'No dates are recorded on your cases yet. Your lawyer records them when a hearing or a statutory deadline is set.' ?></p>
  </div>
<?php else: ?>

  <div class="dash-card">
    <h3><?= is_rtl() ? 'المواعيد القادمة' : 'Upcoming' ?> <span style="color:var(--text-muted);font-weight:400">(<?= count($upcoming) ?>)</span></h3>
    <?php if (!$upcoming): ?>
      <p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد مواعيد قادمة.' : 'No upcoming dates.' ?></p>
    <?php else: ?>
      <?php foreach ($upcoming as $e): ?>
        <?php
          $days = (int)$now->diff($e['_at'])->format('%r%a');
          $soon = $days >= 0 && $days <= 7;
        ?>
        <div style="display:flex;gap:14px;align-items:flex-start;padding:14px;border:1px solid <?= $soon ? '#E8D5A8' : 'var(--border)' ?>;border-radius:12px;margin-bottom:10px;background:<?= $soon ? '#FFFBF2' : '#fff' ?>">
          <div style="min-width:92px;text-align:center;border-inline-end:1px solid var(--border);padding-inline-end:12px">
            <div style="font-size:1.4rem;font-weight:700;color:var(--navy-dark)"><?= $e['_at']->format('d') ?></div>
            <div style="font-size:.78rem;color:var(--text-muted)"><?= $e['_at']->format('Y-m') ?></div>
            <div style="font-size:.8rem;color:var(--teal);font-weight:600"><?= $e['_at']->format('H:i') ?></div>
          </div>
          <div style="flex:1">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
              <strong style="color:var(--navy-dark)"><?= ui_e((string)$e['title']) ?></strong>
              <span class="status-badge status-<?= $typeTone((string)$e['event_type']) === 'warning' ? 'pending' : ($typeTone((string)$e['event_type']) === 'info' ? 'active' : 'closed') ?>"><?= ui_e($typeLabel((string)$e['event_type'])) ?></span>
              <?php if ($soon): ?><span class="status-badge status-pending"><?= is_rtl() ? 'قريب' : 'Soon' ?></span><?php endif; ?>
            </div>
            <div style="font-size:.86rem;color:var(--text-muted)">
              <?= is_rtl() ? 'القضية:' : 'Case:' ?> <?= ui_e((string)$e['case_title']) ?>
              <?php if (!empty($e['lawyer_name'])): ?> · <?= is_rtl() ? 'المحامي:' : 'Lawyer:' ?> <?= ui_e((string)$e['lawyer_name']) ?><?php endif; ?>
            </div>
            <?php if (!empty($e['description'])): ?>
              <p style="margin:8px 0 0;font-size:.88rem"><?= nl2br(ui_e((string)$e['description'])) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($past): ?>
  <div class="dash-card" style="margin-top:16px">
    <h3><?= is_rtl() ? 'مواعيد سابقة' : 'Past' ?> <span style="color:var(--text-muted);font-weight:400">(<?= count($past) ?>)</span></h3>
    <div class="table-wrap table-cards">
      <table class="admin-table" style="width:100%">
        <thead><tr>
          <th><?= is_rtl() ? 'التاريخ' : 'Date' ?></th>
          <th><?= is_rtl() ? 'النوع' : 'Type' ?></th>
          <th><?= is_rtl() ? 'العنوان' : 'Title' ?></th>
          <th><?= is_rtl() ? 'القضية' : 'Case' ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($past as $e): ?>
            <tr>
              <td data-label="<?= is_rtl() ? 'التاريخ' : 'Date' ?>"><?= $e['_at']->format('Y-m-d H:i') ?></td>
              <td data-label="<?= is_rtl() ? 'النوع' : 'Type' ?>"><?= ui_e($typeLabel((string)$e['event_type'])) ?></td>
              <td data-label="<?= is_rtl() ? 'العنوان' : 'Title' ?>"><?= ui_e((string)$e['title']) ?></td>
              <td data-label="<?= is_rtl() ? 'القضية' : 'Case' ?>"><?= ui_e((string)$e['case_title']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
