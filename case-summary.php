<?php
/**
 * Client-visible case summary.
 *
 * Shows the case-file facts and only the AI copilot outputs a lawyer has explicitly
 * approved. Nothing pending review is ever rendered: the WHERE clause is
 * `status='approved'`, so an unapproved draft cannot leak to a client by a template mistake.
 * Ownership is enforced by the case query itself (cases.user_id = session user).
 */
$activePage = 'my-cases';
require_once __DIR__ . '/lang/strings.php';
$pageTitle = is_rtl() ? 'ملخص القضية' : 'Case summary';
$pageSubtitle = is_rtl()
    ? 'وقائع الملف والملخصات التي اعتمدها محاميك.'
    : 'File facts and the summaries your lawyer approved.';
require_once __DIR__ . '/includes/app_header.php';

$userId = (int)$_SESSION['user_id'];
$caseId = (int)($_GET['case'] ?? 0);

$stmt = $pdo->prepare('SELECT id, title, case_type, status, created_at FROM cases WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$caseId, $userId]);
$case = $stmt->fetch();

if (!$case) {
    echo '<div class="app-content">' . ui_page_head($pageTitle)
        . ui_empty_state(is_rtl() ? 'القضية غير موجودة' : 'Case not found', is_rtl() ? 'لا تملك صلاحية الوصول إلى هذه القضية.' : 'This case is not yours.')
        . '</div>';
    require_once __DIR__ . '/includes/app_footer.php';
    exit;
}

// Only approved runs, and only the fields a client may see. verified_json holds facts already
// visible in the client's own case view; suggested_json holds the lawyer-approved text.
$runs = [];
try {
    $q = $pdo->prepare("SELECT kind, verified_json, suggested_json, COALESCE(reviewed_at, created_at) AS updated_at
                        FROM ai_copilot_runs
                        WHERE case_id=? AND status='approved' AND suggested_json IS NOT NULL
                        ORDER BY COALESCE(reviewed_at, created_at) DESC LIMIT 50");
    $q->execute([$caseId]);
    $runs = $q->fetchAll();
} catch (Throwable $e) { $runs = []; }

$kindLabels = [
    'summary' => is_rtl() ? 'ملخص القضية' : 'Case summary',
    'chronology' => is_rtl() ? 'التسلسل الزمني' : 'Chronology',
    'risks' => is_rtl() ? 'المخاطر' : 'Risks',
    'missing_documents' => is_rtl() ? 'مستندات ناقصة' : 'Missing documents',
    'draft' => is_rtl() ? 'مسودة' : 'Draft',
];
?>
<div class="app-content">
  <?= ui_page_head($pageTitle . ' — ' . $case['title'], $pageSubtitle) ?>
  <section class="dash-card">
    <p>
      <?= ui_badge('info', (string)$case['case_type']) ?>
      <?= ui_badge($case['status'] === 'open' ? 'pending' : 'success', (string)$case['status']) ?>
    </p>
    <p class="muted"><?= is_rtl() ? 'تاريخ الفتح: ' : 'Opened: ' ?><?= ui_e(ui_date($case['created_at'])) ?></p>
  </section>

  <?php if ($runs): ?>
    <?php foreach ($runs as $r):
      $verified = $r['verified_json'] ? (json_decode((string)$r['verified_json'], true) ?: []) : [];
      $suggested = $r['suggested_json'] ? (json_decode((string)$r['suggested_json'], true) ?: []) : [];
      $text = (string)($suggested['text'] ?? '');
      if ($text === '') continue;
    ?>
      <section class="dash-card">
        <h2><?= ui_e($kindLabels[$r['kind']] ?? (string)$r['kind']) ?></h2>
        <p class="muted"><?= is_rtl() ? 'اعتمده محاميك في ' : 'Approved by your lawyer on ' ?><?= ui_e(ui_date($r['updated_at'], true)) ?></p>
        <?php if ($verified): ?>
          <h3 class="firm-subhead"><?= is_rtl() ? 'من ملف القضية' : 'From the case file' ?></h3>
          <?php if (is_array($verified) && array_is_list($verified)): ?>
            <ul>
              <?php foreach (array_slice($verified, 0, 30) as $v): ?>
                <li><?= ui_e(is_array($v) ? implode(' — ', array_map('strval', $v)) : (string)$v) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
        <h3 class="firm-subhead"><?= is_rtl() ? 'ملخص معتمد' : 'Approved summary' ?></h3>
        <div style="white-space:pre-wrap"><?= ui_e($text) ?></div>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <?= ui_empty_state(
        is_rtl() ? 'لا يوجد ملخص معتمد بعد' : 'No approved summary yet',
        is_rtl() ? 'سيظهر هنا أي ملخص يعتمده محاميك.' : 'Any summary your lawyer approves will appear here.') ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php';
