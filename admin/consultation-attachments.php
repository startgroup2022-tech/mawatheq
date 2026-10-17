<?php
/**
 * Admin — consultation attachments console.
 *
 * Provides what the brief asks for: attachment management, file metadata, consultation
 * association, storage usage statistics, the configurable limits, and the upload/download
 * audit trail.
 *
 * Access follows the existing admin RBAC map: `consultation-attachments.php` is listed in
 * includes/admin_rbac.php for super_admin and support, and the guard below refuses any other
 * role. download-consultation-attachment.php re-reads the same map, so an editor cannot reach
 * the files by id even though the console is closed to them.
 *
 * Limits are edited here and stored in site_settings, which is where
 * consultation_attachment_limits() reads them from. The values are clamped on read, so a
 * mistyped field cannot disable uploads for everyone.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/consultation_attachments.php';

$limits = consultation_attachment_limits($pdo);
$saved = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_limits') {
        $fields = [
            'consultation_attachment_document_mb' => (int)($_POST['document_mb'] ?? 0),
            'consultation_attachment_audio_mb' => (int)($_POST['audio_mb'] ?? 0),
            'consultation_attachment_video_mb' => (int)($_POST['video_mb'] ?? 0),
            'consultation_attachment_max_files' => (int)($_POST['max_files'] ?? 0),
            'consultation_attachment_total_mb' => (int)($_POST['total_mb'] ?? 0),
            'consultation_attachment_user_total_mb' => (int)($_POST['user_total_mb'] ?? 0),
        ];
        $invalid = [];
        foreach ($fields as $key => $value) {
            if ($value < 1) { $invalid[] = $key; continue; }
            $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
                ->execute([$key, (string)$value]);
        }
        consultation_attachment_limits_forget($pdo);
        $limits = consultation_attachment_limits($pdo);
        log_action($pdo, 'تحديث حدود مرفقات الاستشارات');
        $saved = $invalid
            ? adm('تم الحفظ، لكن تم تجاهل القيم غير الصالحة (يجب أن تكون 1 على الأقل).', 'Saved, but invalid values (must be at least 1) were ignored.')
            : adm('تم حفظ الحدود.', 'Limits saved.');
    }

    if ($action === 'purge') {
        // Two different cleanups, reported separately because they mean different things:
        // abandoned drafts are simply junk, while a row whose file is missing is a record of
        // a file that no longer exists and needs a human to decide what to do about it.
        $drafts = consultation_attachment_purge_drafts($pdo, 48);
        $orphans = consultation_attachment_purge_orphans($pdo);
        log_action($pdo, 'تنظيف مرفقات الاستشارات: مسودات ' . $drafts['removed'] . ' / ملفات بلا سجل ' . $orphans['files_removed']);
        $saved = adm(
            sprintf('تم حذف %d مسودة منتهية (%s) و%d ملف بلا سجل (%s).', $drafts['removed'], consultation_attachment_format_size($drafts['bytes_reclaimed']), $orphans['files_removed'], consultation_attachment_format_size($orphans['bytes_reclaimed'])),
            sprintf('Removed %d abandoned drafts (%s) and %d orphaned files (%s).', $drafts['removed'], consultation_attachment_format_size($drafts['bytes_reclaimed']), $orphans['files_removed'], consultation_attachment_format_size($orphans['bytes_reclaimed']))
        );
        if ($orphans['rows_missing_file'] > 0) {
            $error = adm(
                sprintf('تنبيه: %d سجلاً يشير إلى ملف مفقود. لم يُحذف أي منها تلقائياً — راجعها يدوياً.', $orphans['rows_missing_file']),
                sprintf('Warning: %d records point at a missing file. None were deleted automatically — review them manually.', $orphans['rows_missing_file'])
            );
        }
    }

    if ($action === 'remove') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        $row = consultation_attachment_find($pdo, $attachmentId);
        if ($row && ($row['status'] ?? '') === 'stored') {
            if (consultation_attachment_delete($pdo, $row, ['type' => 'admin', 'id' => (int)$_SESSION['admin_id']])) {
                log_action($pdo, 'حذف مرفق استشارة: ' . (string)$row['original_name'], 'consultation_attachment', $attachmentId);
                $saved = adm('تم حذف المرفق.', 'The attachment was removed.');
            } else {
                $error = adm('تعذّر حذف المرفق.', 'The attachment could not be removed.');
            }
        } else {
            $error = adm('المرفق غير موجود.', 'Attachment not found.');
        }
    }
}

$activeAdmin = 'consultation-attachments';
require_once __DIR__ . '/includes/admin_header.php';

$firmFilter = (int)($_GET['firm_id'] ?? 0);
$categoryFilter = (string)($_GET['category'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'stored');

$where = ['1=1'];
$params = [];
if ($statusFilter !== '' && in_array($statusFilter, ['stored', 'staged', 'deleted'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($firmFilter > 0) { $where[] = 'a.firm_id = ?'; $params[] = $firmFilter; }
if ($categoryFilter !== '' && in_array($categoryFilter, ['document', 'audio', 'video'], true)) {
    $where[] = 'a.file_category = ?';
    $params[] = $categoryFilter;
}
if ($search !== '') {
    $where[] = '(a.original_name LIKE ? OR u.full_name LIKE ? OR l.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$rows = $pdo->prepare(
    "SELECT a.*, c.scheduled_at, c.status AS consultation_status,
            u.full_name AS client_name, l.full_name AS lawyer_name, f.name AS firm_name
       FROM consultation_attachments a
       LEFT JOIN consultations c ON c.id = a.consultation_id
       LEFT JOIN users u ON u.id = c.user_id
       LEFT JOIN lawyers l ON l.id = c.lawyer_id
       LEFT JOIN law_firms f ON f.id = a.firm_id
      WHERE $whereSql
      ORDER BY a.created_at DESC
      LIMIT 300"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$stats = consultation_attachment_stats($pdo, $firmFilter > 0 ? $firmFilter : null);

// Upload and download activity, read from the audit trail the attachment routes write to.
$auditRows = [];
try {
    $audit = $pdo->prepare("SELECT actor_type, actor_name, action, created_at FROM audit_logs WHERE target_type='consultation_attachment' ORDER BY id DESC LIMIT 40");
    $audit->execute();
    $auditRows = $audit->fetchAll();
} catch (Throwable $e) {
    // A pre-v42 install has no audit_logs table; the console still works without the feed.
}

$firms = [];
try { $firms = $pdo->query('SELECT id, name FROM law_firms ORDER BY name')->fetchAll(); } catch (Throwable $e) {}

$storageRoot = consultation_attachment_storage_root();
$appRoot = realpath(__DIR__ . '/..');
$rootIsOutsideWebroot = !str_starts_with(realpath($storageRoot) ?: $storageRoot, (string)$appRoot . DIRECTORY_SEPARATOR);

$extraPageAssets = [
    '<link rel="stylesheet" href="/assets/css/consultation-attachments.css">',
];
?>
<div class="app-content">
  <div class="pg-head">
    <div>
      <h1><?= h(adm('مرفقات الاستشارات', 'Consultation attachments')) ?></h1>
      <p><?= h(adm('إدارة الملفات المرفقة بالاستشارات: البيانات الوصفية، التخزين، الحدود، وسجل النشاط.', 'Manage consultation attachment files: metadata, storage, limits and activity.')) ?></p>
    </div>
  </div>

  <?php if ($saved): ?><div class="alert ok"><?= h($saved) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert warn"><?= h($error) ?></div><?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-val"><?= (int)$stats['files'] ?></div><div class="kpi-lbl"><?= h(adm('ملفات مخزّنة', 'Stored files')) ?></div></div>
    <div class="kpi"><div class="kpi-val"><?= h(consultation_attachment_format_size((int)$stats['bytes'])) ?></div><div class="kpi-lbl"><?= h(adm('إجمالي الحجم', 'Total size')) ?></div></div>
    <div class="kpi"><div class="kpi-val"><?= (int)$stats['consultations'] ?></div><div class="kpi-lbl"><?= h(adm('استشارات بمرفقات', 'Consultations with files')) ?></div></div>
    <div class="kpi"><div class="kpi-val"><?= (int)($stats['by_category']['document'] ?? 0) ?> / <?= (int)($stats['by_category']['audio'] ?? 0) ?> / <?= (int)($stats['by_category']['video'] ?? 0) ?></div><div class="kpi-lbl"><?= h(adm('مستند / صوت / فيديو', 'Doc / audio / video')) ?></div></div>
  </div>

  <div class="card" style="padding:18px;margin-bottom:18px">
    <h3 style="margin:0 0 6px"><?= h(adm('حالة التخزين', 'Storage')) ?></h3>
    <p style="margin:0 0 4px;font-size:.85rem;color:var(--text-muted);direction:ltr;text-align:start">
      <?= h($storageRoot) ?>
    </p>
    <p style="margin:0;font-size:.85rem">
      <?php if ($rootIsOutsideWebroot): ?>
        <span class="status-pill status-confirmed"><?= h(adm('خارج جذر الويب', 'Outside the web root')) ?></span>
        <?= h(adm('الملفات غير قابلة للوصول عبر أي رابط مباشر.', 'The files are not reachable through any direct URL.')) ?>
      <?php else: ?>
        <span class="status-pill status-pending"><?= h(adm('داخل مجلد المشروع', 'Inside the project directory')) ?></span>
        <?= h(adm('محمي عبر .htaccess فقط. يُفضّل ضبط MAWATHEQ_PRIVATE_STORAGE_DIR خارج جذر الويب.', 'Protected by .htaccess only. Prefer setting MAWATHEQ_PRIVATE_STORAGE_DIR outside the web root.')) ?>
      <?php endif; ?>
    </p>
  </div>

  <div class="card" style="padding:18px;margin-bottom:18px">
    <h3 style="margin:0 0 12px"><?= h(adm('الحدود القابلة للضبط', 'Configurable limits')) ?></h3>
    <form method="post" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_limits">
      <label class="field" style="margin:0"><span><?= h(adm('مستند (م.ب)', 'Document (MB)')) ?></span><input type="number" min="1" max="512" name="document_mb" value="<?= (int)$limits['document_mb'] ?>" style="width:110px"></label>
      <label class="field" style="margin:0"><span><?= h(adm('صوت (م.ب)', 'Audio (MB)')) ?></span><input type="number" min="1" max="1024" name="audio_mb" value="<?= (int)$limits['audio_mb'] ?>" style="width:110px"></label>
      <label class="field" style="margin:0"><span><?= h(adm('فيديو (م.ب)', 'Video (MB)')) ?></span><input type="number" min="1" max="2048" name="video_mb" value="<?= (int)$limits['video_mb'] ?>" style="width:110px"></label>
      <label class="field" style="margin:0"><span><?= h(adm('أقصى عدد ملفات', 'Max files')) ?></span><input type="number" min="1" max="50" name="max_files" value="<?= (int)$limits['max_files'] ?>" style="width:110px"></label>
      <label class="field" style="margin:0"><span><?= h(adm('إجمالي الاستشارة (م.ب)', 'Per consultation (MB)')) ?></span><input type="number" min="1" max="10240" name="total_mb" value="<?= (int)$limits['total_mb'] ?>" style="width:130px"></label>
      <label class="field" style="margin:0"><span><?= h(adm('إجمالي حساب العميل (م.ب)', 'Per client (MB)')) ?></span><input type="number" min="1" max="51200" name="user_total_mb" value="<?= (int)$limits['user_total_mb'] ?>" style="width:140px"></label>
      <button class="btn btn-primary" type="submit"><?= h(adm('حفظ الحدود', 'Save limits')) ?></button>
    </form>
    <p style="margin:10px 0 0;font-size:.82rem;color:var(--text-muted)">
      <?= h(adm('ملاحظة: يجب أن تكون حدود PHP على الخادم (upload_max_filesize و post_max_size) أكبر من أكبر حد هنا، وإلا رُفض الملف قبل وصوله إلى التطبيق.', 'Note: the server PHP limits (upload_max_filesize and post_max_size) must exceed the largest limit here, or the file is rejected before it reaches the application.')) ?>
      <?= h(sprintf(adm('القيم الحالية على الخادم: upload_max_filesize=%s، post_max_size=%s.', 'Server values now: upload_max_filesize=%s, post_max_size=%s.'), ini_get('upload_max_filesize'), ini_get('post_max_size'))) ?>
    </p>
  </div>

  <div class="card" style="padding:18px;margin-bottom:18px">
    <h3 style="margin:0 0 10px"><?= h(adm('صيانة', 'Maintenance')) ?></h3>
    <form method="post" onsubmit="return confirm('<?= h(adm('حذف المسودات المنتهية والملفات بلا سجل؟', 'Remove abandoned drafts and orphaned files?')) ?>')">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="purge">
      <button class="btn btn-outline" type="submit"><?= h(adm('تنظيف المسودات المنتهية والملفات بلا سجل', 'Clean abandoned drafts and orphaned files')) ?></button>
    </form>
    <p style="margin:8px 0 0;font-size:.82rem;color:var(--text-muted)">
      <?= h(adm('المسودات أقدم من 48 ساعة. لن تُحذف أي سجلات تشير إلى ملف مفقود — تُبلَّغ فقط.', 'Drafts older than 48 hours. Records pointing at a missing file are reported, never deleted.')) ?>
    </p>
  </div>

  <form method="get" class="tbl-tools" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <label class="field" style="margin:0"><span><?= h(adm('بحث', 'Search')) ?></span><input type="search" name="q" value="<?= h($search) ?>" placeholder="<?= h(adm('اسم الملف، العميل، المحامي', 'Filename, client, lawyer')) ?>" style="min-width:220px"></label>
    <label class="field" style="margin:0"><span><?= h(adm('الحالة', 'Status')) ?></span>
      <select name="status">
        <?php foreach (['stored' => adm('مخزّن', 'Stored'), 'staged' => adm('مسودة', 'Staged'), 'deleted' => adm('محذوف', 'Deleted'), '' => adm('الكل', 'All')] as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $statusFilter === (string)$k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="field" style="margin:0"><span><?= h(adm('الفئة', 'Category')) ?></span>
      <select name="category">
        <option value=""><?= h(adm('الكل', 'All')) ?></option>
        <?php foreach (['document' => adm('مستند', 'Document'), 'audio' => adm('صوت', 'Audio'), 'video' => adm('فيديو', 'Video')] as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $categoryFilter === $k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select></label>
    <?php if ($firms): ?>
      <label class="field" style="margin:0"><span><?= h(adm('المكتب', 'Firm')) ?></span>
        <select name="firm_id">
          <option value="0"><?= h(adm('كل المكاتب', 'All firms')) ?></option>
          <?php foreach ($firms as $f): ?>
            <option value="<?= (int)$f['id'] ?>" <?= $firmFilter === (int)$f['id'] ? 'selected' : '' ?>><?= h((string)$f['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" type="submit"><?= h(adm('تصفية', 'Filter')) ?></button>
    <a class="btn btn-outline btn-sm" href="/admin/consultation-attachments.php"><?= h(adm('إعادة تعيين', 'Reset')) ?></a>
  </form>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr>
        <th><?= h(adm('الملف', 'File')) ?></th>
        <th><?= h(adm('النوع', 'Type')) ?></th>
        <th><?= h(adm('الحجم', 'Size')) ?></th>
        <th><?= h(adm('الاستشارة', 'Consultation')) ?></th>
        <th><?= h(adm('المكتب', 'Firm')) ?></th>
        <th><?= h(adm('رُفع بواسطة', 'Uploaded by')) ?></th>
        <th><?= h(adm('التاريخ', 'Date')) ?></th>
        <th><?= h(adm('تنزيلات', 'Downloads')) ?></th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <span class="ca-item-icon ca-cat-<?= h((string)$r['file_category']) ?>" style="width:28px;height:28px;border-radius:7px"></span>
              <span title="<?= h((string)$r['original_name']) ?>"><?= h(mb_strimwidth((string)$r['original_name'], 0, 44, '…', 'UTF-8')) ?></span>
            </div>
          </td>
          <td><span class="pill gray"><?= h(strtoupper((string)$r['file_type'])) ?></span><br><small><?= h((string)$r['mime_type']) ?></small></td>
          <td><?= h(consultation_attachment_format_size((int)$r['file_size'])) ?></td>
          <td>
            <?php if ($r['consultation_id']): ?>
              <a href="/admin/consultations.php">#<?= (int)$r['consultation_id'] ?></a>
              <br><small><?= h((string)($r['client_name'] ?? '')) ?> → <?= h((string)($r['lawyer_name'] ?? '')) ?></small>
            <?php else: ?>
              <span class="pill gray"><?= h(adm('مسودة', 'Draft')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= h((string)($r['firm_name'] ?? '—')) ?></td>
          <td><?= h((string)$r['uploaded_by_type']) ?> #<?= (int)$r['uploaded_by_id'] ?></td>
          <td><?= h(date('Y-m-d H:i', strtotime((string)$r['created_at']))) ?></td>
          <td><?= (int)$r['download_count'] ?></td>
          <td>
            <?php if (($r['status'] ?? '') === 'stored'): ?>
              <a class="icon-btn" href="/download-consultation-attachment.php?id=<?= (int)$r['id'] ?>"><?= h(adm('تنزيل', 'Download')) ?></a>
              <form method="post" style="display:inline" onsubmit="return confirm('<?= h(adm('حذف هذا المرفق؟', 'Remove this attachment?')) ?>')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="attachment_id" value="<?= (int)$r['id'] ?>">
                <button class="icon-btn danger" type="submit"><?= h(adm('حذف', 'Remove')) ?></button>
              </form>
            <?php else: ?>
              <span class="pill gray"><?= h((string)$r['status']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty"><?= h(adm('لا توجد مرفقات مطابقة.', 'No matching attachments.')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($auditRows): ?>
  <div class="card" style="padding:18px;margin-top:18px">
    <h3 style="margin:0 0 10px"><?= h(adm('سجل النشاط (رفع/حذف/تنزيل)', 'Activity log (upload / remove / download)')) ?></h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th><?= h(adm('الوقت', 'Time')) ?></th><th><?= h(adm('المستخدم', 'Actor')) ?></th><th><?= h(adm('الإجراء', 'Action')) ?></th></tr></thead>
        <tbody>
          <?php foreach ($auditRows as $a): ?>
            <tr>
              <td><?= h(date('Y-m-d H:i', strtotime((string)$a['created_at']))) ?></td>
              <td><?= h((string)$a['actor_name']) ?> <small>(<?= h((string)$a['actor_type']) ?>)</small></td>
              <td><?= h((string)$a['action']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
