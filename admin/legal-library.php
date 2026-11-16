<?php
/**
 * Admin → المكتبة القانونية والقضائية (Execution 74).
 *
 * The operator console for the legal library: it lists every registered source with
 * its redistribution decision, lets the operator enable/disable a source and run a
 * sync, and shows the sync-run audit trail. It never writes an item by hand and never
 * copies a file — the source's own terms decide what the importers may keep.
 *
 * The sync is synchronous and capped (`--pages` small) so the request stays within a
 * web timeout; the full catalogue is meant to be built by cron/legal-library-sync.php.
 */
$activeAdmin = 'legal-library';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/legal_library.php';
$pageTitle = is_rtl() ? 'المكتبة القانونية والقضائية' : 'Legal & judicial library';
require_once __DIR__ . '/includes/admin_header.php';

$notice = null;
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $action = (string)($_POST['action'] ?? '');
    $sourceKey = (string)($_POST['source_key'] ?? '');

    if ($action === 'toggle') {
        $src = legal_library_source($sourceKey);
        if ($src) {
            $new = !((int)$src['is_enabled'] === 1);
            legal_library_set_source_enabled($pdo, $sourceKey, $new);
            log_action($pdo, 'legal_library_source_toggle', 'legal_library_sources', $sourceKey, $new ? 'enabled' : 'disabled');
            $notice = $new
                ? (is_rtl() ? 'تم تفعيل المصدر.' : 'Source enabled.')
                : (is_rtl() ? 'تم تعطيل المصدر.' : 'Source disabled.');
        }
    } elseif ($action === 'sync') {
        $src = legal_library_source($sourceKey);
        if ($src) {
            // A short bounded slice: the windowed walker advances the decisions cursor
            // a few pages and resumes next time, so the button never risks a web timeout.
            $opts = ['pages' => 2, 'page_size' => 20, 'with_details' => false, 'windowed' => true, 'max_pages' => 3, 'pause_ms' => 3000];
            if ($sourceKey === 'mashortich_books') $opts = [];
            $res = legal_library_sync($pdo, $sourceKey, $opts);
            log_action($pdo, 'legal_library_sync', 'legal_library_sources', $sourceKey,
                json_encode(['status' => $res['status'], 'new' => $res['new'] ?? 0, 'updated' => $res['updated'] ?? 0], JSON_UNESCAPED_UNICODE));
            if (($res['status'] ?? 'error') === 'error') {
                $noticeType = 'error';
                $notice = (is_rtl() ? 'فشلت المزامنة: ' : 'Sync failed: ') . ($res['error'] ?? '');
            } else {
                $notice = is_rtl()
                    ? sprintf('تمت المزامنة: جديد %d، مُحدَّث %d، مُتجاهَل %d.', (int)$res['new'], (int)$res['updated'], (int)$res['skipped'])
                    : sprintf('Synced: %d new, %d updated, %d skipped.', (int)$res['new'], (int)$res['updated'], (int)$res['skipped']);
            }
        }
    }
}

$sources = legal_library_sources(false);
$runs = [];
$totalItems = 0;
try {
    $runs = $pdo->query('SELECT * FROM legal_library_sync_runs ORDER BY id DESC LIMIT 25')->fetchAll();
    $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM legal_library_items')->fetchColumn();
} catch (Throwable $e) { $runs = []; }

$statusLabels = [
    'running' => is_rtl() ? 'جارٍ' : 'Running',
    'ok'      => is_rtl() ? 'ناجح' : 'OK',
    'partial' => is_rtl() ? 'جزئي' : 'Partial',
    'error'   => is_rtl() ? 'فشل' : 'Error',
];
$redistLabels = [
    'hosted'    => is_rtl() ? 'يُخزَّن النص ويُقرأ هنا' : 'Text stored & read here',
    'link_only' => is_rtl() ? 'بيانات وصفية ورابط رسمي فقط' : 'Metadata + official link only',
    'unknown'   => is_rtl() ? 'بانتظار مراجعة الشروط' : 'Pending terms review',
];
$csrf = csrf_token();
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'المكتبة القانونية والقضائية' : 'Legal & judicial library' ?></h1>
  <p style="margin-bottom:16px"><?= is_rtl()
    ? 'المكتبة تجمع محتوى قانونياً وقضائياً من مصادر رسمية ومفتوحة. قرار إعادة النشر لكل مصدر يحدد ما يُخزَّن: النص الكامل من المصادر التي تتيح القراءة العامة، أو البيانات الوصفية والرابط الرسمي فقط. لا يُنسخ أي ملف إلى مواثيق دون ذلك.'
    : 'The library aggregates legal content from official open sources. Each source\'s redistribution decision governs what is stored: full text from sources that publish it for public reading, or metadata and the official link only. No file is copied into Mawatheq without that permission.' ?></p>

  <?php if ($notice): ?><div class="form-alert <?= $noticeType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

  <div class="stat-grid" style="margin-bottom:18px">
    <div class="dash-card stat-card"><div class="stat-value"><?= number_format($totalItems) ?></div><div class="stat-label"><?= is_rtl() ? 'إجمالي العناصر' : 'Total items' ?></div></div>
    <div class="dash-card stat-card"><div class="stat-value"><?= count($sources) ?></div><div class="stat-label"><?= is_rtl() ? 'المصادر المسجّلة' : 'Registered sources' ?></div></div>
    <div class="dash-card stat-card"><div class="stat-value"><?= count(array_filter($sources, fn($s) => (int)$s['is_enabled'] === 1)) ?></div><div class="stat-label"><?= is_rtl() ? 'المصادر المفعّلة' : 'Enabled sources' ?></div></div>
  </div>

  <div class="dash-card" style="margin-bottom:18px">
    <h2 style="margin-top:0"><?= is_rtl() ? 'المصادر' : 'Sources' ?></h2>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th><?= is_rtl() ? 'المصدر' : 'Source' ?></th>
          <th><?= is_rtl() ? 'الجهة' : 'Publisher' ?></th>
          <th><?= is_rtl() ? 'النوع' : 'Type' ?></th>
          <th><?= is_rtl() ? 'سياسة النشر' : 'Redistribution' ?></th>
          <th><?= is_rtl() ? 'العناصر' : 'Items' ?></th>
          <th><?= is_rtl() ? 'آخر مزامنة' : 'Last sync' ?></th>
          <th><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sources as $s): ?>
          <tr>
            <td data-label="<?= is_rtl() ? 'المصدر' : 'Source' ?>">
              <strong><?= htmlspecialchars(is_rtl() ? $s['name_ar'] : $s['name_en']) ?></strong>
              <?php if (!(int)$s['is_enabled']): ?><br><span class="status-cancelled"><?= is_rtl() ? 'معطّل' : 'Disabled' ?></span><?php endif; ?>
            </td>
            <td data-label="<?= is_rtl() ? 'الجهة' : 'Publisher' ?>"><?= htmlspecialchars(is_rtl() ? $s['publisher_ar'] : $s['publisher_en']) ?></td>
            <td data-label="<?= is_rtl() ? 'النوع' : 'Type' ?>"><?= htmlspecialchars(implode(', ', array_map('legal_library_item_type_label', array_filter(explode(',', (string)$s['content_types']))))) ?></td>
            <td data-label="<?= is_rtl() ? 'سياسة النشر' : 'Redistribution' ?>"><?= htmlspecialchars($redistLabels[$s['redistribution']] ?? $s['redistribution']) ?></td>
            <td data-label="<?= is_rtl() ? 'العناصر' : 'Items' ?>"><?= number_format((int)$s['item_count']) ?></td>
            <td data-label="<?= is_rtl() ? 'آخر مزامنة' : 'Last sync' ?>">
              <?php if ($s['last_sync_at']): ?>
                <span class="ltr"><?= htmlspecialchars((string)$s['last_sync_at']) ?></span>
                <br><small class="muted-sm"><?= htmlspecialchars($statusLabels[$s['last_sync_status']] ?? (string)$s['last_sync_status']) ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td data-label="<?= is_rtl() ? 'إجراءات' : 'Actions' ?>">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="source_key" value="<?= htmlspecialchars((string)$s['source_key']) ?>">
                <button class="btn btn-outline btn-sm" name="action" value="toggle" type="submit"><?= (int)$s['is_enabled'] ? (is_rtl() ? 'تعطيل' : 'Disable') : (is_rtl() ? 'تفعيل' : 'Enable') ?></button>
                <?php if (in_array($s['source_key'], ['moj_decisions', 'moj_legislation', 'mashortich_books'], true)): ?>
                  <button class="btn btn-primary btn-sm" name="action" value="sync" type="submit"><?= is_rtl() ? 'مزامنة الآن' : 'Sync now' ?></button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted-sm" style="margin-top:10px"><?= is_rtl()
      ? 'المزامنة الكاملة تُشغَّل من cron: php cron/legal-library-sync.php'
      : 'Full sync runs from cron: php cron/legal-library-sync.php' ?></p>
  </div>

  <div class="dash-card">
    <h2 style="margin-top:0"><?= is_rtl() ? 'سجل المزامنات' : 'Sync history' ?></h2>
    <?php if (!$runs): ?>
      <p class="muted-sm"><?= is_rtl() ? 'لا يوجد سجل مزامنة بعد.' : 'No sync runs yet.' ?></p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th>#</th><th><?= is_rtl() ? 'المصدر' : 'Source' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
          <th><?= is_rtl() ? 'مُشاهد' : 'Seen' ?></th><th><?= is_rtl() ? 'جديد' : 'New' ?></th>
          <th><?= is_rtl() ? 'مُحدَّث' : 'Updated' ?></th><th><?= is_rtl() ? 'مُتجاهَل' : 'Skipped' ?></th>
          <th><?= is_rtl() ? 'البدء' : 'Started' ?></th><th><?= is_rtl() ? 'خطأ' : 'Error' ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
          <tr>
            <td data-label="#"><?= (int)$r['id'] ?></td>
            <td data-label="<?= is_rtl() ? 'المصدر' : 'Source' ?>"><?= htmlspecialchars((string)$r['source_key']) ?></td>
            <td data-label="<?= is_rtl() ? 'الحالة' : 'Status' ?>"><span class="status-<?= $r['status'] === 'ok' ? 'completed' : ($r['status'] === 'error' ? 'cancelled' : 'pending') ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
            <td data-label="<?= is_rtl() ? 'مُشاهد' : 'Seen' ?>"><?= (int)$r['items_seen'] ?></td>
            <td data-label="<?= is_rtl() ? 'جديد' : 'New' ?>"><?= (int)$r['items_new'] ?></td>
            <td data-label="<?= is_rtl() ? 'مُحدَّث' : 'Updated' ?>"><?= (int)$r['items_updated'] ?></td>
            <td data-label="<?= is_rtl() ? 'مُتجاهَل' : 'Skipped' ?>"><?= (int)$r['items_skipped'] ?></td>
            <td data-label="<?= is_rtl() ? 'البدء' : 'Started' ?>"><span class="ltr"><?= htmlspecialchars((string)$r['started_at']) ?></span></td>
            <td data-label="<?= is_rtl() ? 'خطأ' : 'Error' ?>"><?= $r['error_message'] ? htmlspecialchars(mb_substr((string)$r['error_message'], 0, 120)) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
