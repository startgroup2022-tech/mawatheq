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

// Redistribution labels, defined before the POST handler so it can name the policy it
// just recorded in its confirmation notice.
$redistLabelsAll = [
    'hosted'    => is_rtl() ? 'يُخزَّن النص ويُقرأ هنا' : 'Text stored & read here',
    'link_only' => is_rtl() ? 'بيانات وصفية ورابط رسمي فقط' : 'Metadata + official link only',
    'unknown'   => is_rtl() ? 'بانتظار مراجعة الشروط' : 'Pending terms review',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $action = (string)($_POST['action'] ?? '');
    $sourceKey = (string)($_POST['source_key'] ?? '');

    if ($action === 'toggle') {
        $src = legal_library_source($sourceKey);
        if ($src) {
            $new = !((int)$src['is_enabled'] === 1);
            legal_library_set_source_enabled($pdo, $sourceKey, $new);
            log_action($pdo, 'legal_library_source_toggle: ' . $sourceKey . ' → ' . ($new ? 'enabled' : 'disabled'), 'legal_library_sources', (int)$src['id']);
            $notice = $new
                ? (is_rtl() ? 'تم تفعيل المصدر.' : 'Source enabled.')
                : (is_rtl() ? 'تم تعطيل المصدر.' : 'Source disabled.');
        }
    } elseif ($action === 'redistribution') {
        // The operator's licensing decision for this source. `hosted` permits storing
        // and reading the source's own text; `link_only` keeps metadata + the official
        // link. Recorded in the audit log because it changes what the site republishes.
        $newRedist = (string)($_POST['redistribution'] ?? '');
        $src = legal_library_source($sourceKey);
        if ($src && legal_library_set_source_redistribution($pdo, $sourceKey, $newRedist)) {
            log_action($pdo, 'legal_library_source_redistribution: ' . $sourceKey . ' → ' . $newRedist, 'legal_library_sources', (int)$src['id']);
            $notice = is_rtl()
                ? sprintf('تم تحديث سياسة النشر إلى: %s.', $redistLabelsAll[$newRedist] ?? $newRedist)
                : sprintf('Redistribution policy set to: %s.', $redistLabelsAll[$newRedist] ?? $newRedist);
        } else {
            $noticeType = 'error';
            $notice = is_rtl() ? 'سياسة نشر غير صحيحة.' : 'Invalid redistribution policy.';
        }
    } elseif ($action === 'sync') {
        $src = legal_library_source($sourceKey);
        if ($src) {
            // A short bounded slice: the windowed walker advances the decisions cursor
            // a few pages and resumes next time, so the button never risks a web timeout.
            $opts = ['pages' => 2, 'page_size' => 20, 'with_details' => false, 'windowed' => true, 'max_pages' => 3, 'pause_ms' => 3000];
            if ($sourceKey === 'mashortich_books') $opts = [];
            $res = legal_library_sync($pdo, $sourceKey, $opts);
            log_action($pdo, 'legal_library_sync: ' . $sourceKey . ' → '
                . json_encode(['status' => $res['status'], 'new' => $res['new'] ?? 0, 'updated' => $res['updated'] ?? 0], JSON_UNESCAPED_UNICODE),
                'legal_library_sources', (int)$src['id']);
            if (($res['status'] ?? 'error') === 'error') {
                $noticeType = 'error';
                $notice = (is_rtl() ? 'فشلت المزامنة: ' : 'Sync failed: ') . ($res['error'] ?? '');
            } else {
                $notice = is_rtl()
                    ? sprintf('تمت المزامنة: جديد %d، مُحدَّث %d، مُتجاهَل %d.', (int)$res['new'], (int)$res['updated'], (int)$res['skipped'])
                    : sprintf('Synced: %d new, %d updated, %d skipped.', (int)$res['new'], (int)$res['updated'], (int)$res['skipped']);
            }
        }
    } elseif ($action === 'enrich') {
        // Fetch the real detail (title + text) for a bounded batch of catalogue rows
        // that do not have one yet. The list endpoint carries no title, so this is what
        // makes the imported rows readable instead of a bare number-and-court list.
        // When the source is `hosted`, rows that were fetched while it was `link_only`
        // (detail timestamp set, no stored text) are re-visited so the now-permitted
        // text is captured.
        $src = legal_library_source('moj_decisions');
        if ($src) {
            $r = legal_library_enrich_moj_details($pdo, $src, ['limit' => 8, 'pause_ms' => 3500, 'refetch' => $src['redistribution'] === 'hosted']);
            log_action($pdo, 'legal_library_enrich: moj_decisions → ' . json_encode($r, JSON_UNESCAPED_UNICODE),
                'legal_library_sources', (int)$src['id']);
            if ($r['enriched'] === 0 && $r['blocked'] > 0) {
                $noticeType = 'error';
                $notice = is_rtl()
                    ? 'رفضت البوابة الطلبات مؤقتاً (حماية WAF). أعد المحاولة بعد قليل.'
                    : 'The gateway is temporarily refusing requests (WAF). Try again shortly.';
            } else {
                $notice = is_rtl()
                    ? sprintf('تم إثراء %d عنصر بالنص والعنوان الحقيقي، وفشل %d.', $r['enriched'], $r['failed'])
                    : sprintf('Enriched %d items with the real title/text; %d failed.', $r['enriched'], $r['failed']);
            }
        }
    }
}

$sources = legal_library_sources(false);
$runs = [];
$totalItems = 0;
$enrichedItems = 0;
$pendingDetails = 0;
try {
    $runs = $pdo->query('SELECT * FROM legal_library_sync_runs ORDER BY id DESC LIMIT 25')->fetchAll();
    $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM legal_library_items')->fetchColumn();
    // Detail coverage: how many rows already carry the real title/text. A catalogue
    // that is all "pending" shows only numbers and courts, which is the state the
    // operator rejected — so this number is surfaced, not hidden.
    $enrichedItems = (int)$pdo->query('SELECT COUNT(*) FROM legal_library_items WHERE detail_fetched_at IS NOT NULL')->fetchColumn();
    $pendingDetails = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_items WHERE source_key='moj_decisions' AND detail_fetched_at IS NULL")->fetchColumn();
} catch (Throwable $e) { $runs = []; }

$statusLabels = [
    'running' => is_rtl() ? 'جارٍ' : 'Running',
    'ok'      => is_rtl() ? 'ناجح' : 'OK',
    'partial' => is_rtl() ? 'جزئي' : 'Partial',
    'error'   => is_rtl() ? 'فشل' : 'Error',
];
$redistLabels = $redistLabelsAll;
$captureOn = legal_library_capture_body();
$hostedButInert = !$captureOn && (bool)array_filter($sources, fn($s) => $s['redistribution'] === 'hosted');
$csrf = csrf_token();
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'المكتبة القانونية والقضائية' : 'Legal & judicial library' ?></h1>
  <p style="margin-bottom:16px"><?= is_rtl()
    ? 'المكتبة تجمع محتوى قانونياً وقضائياً من مصادر رسمية ومفتوحة. قرار إعادة النشر لكل مصدر يحدد ما يُخزَّن: النص الكامل من المصادر التي تتيح القراءة العامة، أو البيانات الوصفية والرابط الرسمي فقط. لا يُنسخ أي ملف إلى مواثيق دون ذلك.'
    : 'The library aggregates legal content from official open sources. Each source\'s redistribution decision governs what is stored: full text from sources that publish it for public reading, or metadata and the official link only. No file is copied into Mawatheq without that permission.' ?></p>

  <?php if ($notice): ?><div class="form-alert <?= $noticeType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

  <?php if ($hostedButInert): ?>
    <div class="form-alert warning" style="background:#FFF8E6;border-color:#F0DDA8;color:#7A5409">
      <?= is_rtl()
        ? 'يوجد مصدر مضبوط على «يُخزَّن النص ويُقرأ هنا» بينما مفتاح الالتقاط معطّل على الخادم، لذا لن يُخزَّن النص فعلياً. أضف MAWATHEQ_LL_CAPTURE_BODY=1 إلى بيئة الموقع (أو متغيّر بيئة cron) ثم أعد الإثراء.'
        : 'A source is set to “Text stored & read here” but the server capture flag is off, so no text will actually be stored. Add MAWATHEQ_LL_CAPTURE_BODY=1 to the site environment (or the cron environment) and re-run enrichment.' ?>
    </div>
  <?php endif; ?>

  <div class="stat-grid" style="margin-bottom:18px">
    <div class="dash-card stat-card"><div class="stat-value"><?= number_format($totalItems) ?></div><div class="stat-label"><?= is_rtl() ? 'إجمالي العناصر' : 'Total items' ?></div></div>
    <div class="dash-card stat-card"><div class="stat-value"><?= number_format($enrichedItems) ?></div><div class="stat-label"><?= is_rtl() ? 'عناصر بنص وعنوان حقيقي' : 'Items with real title/text' ?></div></div>
    <div class="dash-card stat-card"><div class="stat-value"><?= number_format($pendingDetails) ?></div><div class="stat-label"><?= is_rtl() ? 'بانتظار الإثراء' : 'Awaiting enrichment' ?></div></div>
    <div class="dash-card stat-card"><div class="stat-value"><?= count($sources) ?></div><div class="stat-label"><?= is_rtl() ? 'المصادر المسجّلة' : 'Registered sources' ?></div></div>
    <div class="dash-card stat-card"><div class="stat-value"><?= count(array_filter($sources, fn($s) => (int)$s['is_enabled'] === 1)) ?></div><div class="stat-label"><?= is_rtl() ? 'المصادر المفعّلة' : 'Enabled sources' ?></div></div>
  </div>

  <?php if ($pendingDetails > 0): ?>
    <div class="form-alert" style="background:#FFF8E6;border-color:#F0DDA8;color:#7A5409">
      <?= is_rtl()
        ? sprintf('يوجد %d عنصر في كتالوج وزارة العدل بانتظار الإثراء (العنوان والنص الحقيقي). شغّل «إثراء النصوص» أو cron/legal-library-sync.php لمعالجتها على دفعات.', $pendingDetails)
        : sprintf('%d Ministry-of-Justice rows are awaiting enrichment (real title + text). Run “Enrich text” or cron/legal-library-sync.php to process them in batches.', $pendingDetails) ?>
    </div>
  <?php endif; ?>

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
                <?php if ($s['source_key'] === 'moj_decisions'): ?>
                  <button class="btn btn-outline btn-sm" name="action" value="enrich" type="submit" title="<?= is_rtl() ? 'جلب العنوان والنص الحقيقي لدفعة من العناصر' : 'Fetch the real title/text for a batch of items' ?>"><?= is_rtl() ? 'إثراء النصوص' : 'Enrich text' ?></button>
                <?php endif; ?>
              </form>
              <form method="post" style="display:inline;margin-top:4px">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="source_key" value="<?= htmlspecialchars((string)$s['source_key']) ?>">
                <input type="hidden" name="action" value="redistribution">
                <label class="muted-sm" for="rd-<?= htmlspecialchars((string)$s['source_key']) ?>" style="display:block"><?= is_rtl() ? 'سياسة النشر' : 'Redistribution' ?></label>
                <select id="rd-<?= htmlspecialchars((string)$s['source_key']) ?>" name="redistribution" data-autosubmit style="max-width:220px">
                  <?php foreach ($redistLabelsAll as $rk => $rl): ?>
                    <option value="<?= htmlspecialchars($rk) ?>"<?= $s['redistribution'] === $rk ? ' selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
                  <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'حفظ' : 'Save' ?></button></noscript>
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
