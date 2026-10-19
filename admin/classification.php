<?php
/**
 * Admin — Saudi Najiz classification management.
 *
 * Manages the imported hierarchy: browse, search, edit the Arabic name and definition,
 * retire and restore entries, re-run the import, and read the reconciliation record.
 *
 * Two deliberate constraints:
 *
 *  • DELETE is never offered. A classification referenced by a case or a consultation is
 *    protected by an ON DELETE RESTRICT foreign key, and an unreferenced one is retired
 *    with is_active = 0 so historical casework keeps resolving. Nothing here can remove
 *    a row a lawyer or client already relies on.
 *
 *  • The import can be previewed (dry run) before it is committed, and re-running it is
 *    idempotent, so an operator can always see what it would do before letting it write.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/najiz_import.php';

/** Local audit writer: this feature keeps its own trail so a classification change is
 *  traceable even if the shared audit table is unavailable. */
function najiz_audit(PDO $pdo, string $action, string $kind, ?int $id, ?array $before, ?array $after): void {
    try {
        $pdo->prepare('INSERT INTO najiz_audit_log (actor_admin_id, actor_label, action, entity_kind, entity_id, before_json, after_json, ip_address)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $_SESSION['admin_id'] ?? null,
                $_SESSION['admin_name'] ?? null,
                $action, $kind, $id,
                $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ]);
    } catch (Throwable $e) {
        error_log('najiz_audit: ' . $e->getMessage());
    }
}

$notice = null;
$error = null;
$tableMissing = false;

try {
    $pdo->query('SELECT 1 FROM najiz_categories LIMIT 1');
} catch (Throwable $e) {
    $tableMissing = true;
}

$POST = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $POST ? (string)($_POST['action'] ?? '') : '';

if (!$tableMissing && $POST) {
    try {
        if ($action === 'toggle') {
            $kind = (string)($_POST['kind'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            $map = ['category' => 'najiz_categories', 'subcategory' => 'najiz_subcategories',
                    'case_type' => 'najiz_case_types', 'request' => 'najiz_judicial_requests'];
            if (!isset($map[$kind]) || $id <= 0) throw new RuntimeException('bad target');
            $t = $map[$kind];
            $before = $pdo->prepare("SELECT id, name_ar, is_active FROM `$t` WHERE id=?");
            $before->execute([$id]);
            $row = $before->fetch();
            if (!$row) throw new RuntimeException('not found');
            $new = ((int)$row['is_active'] === 1) ? 0 : 1;
            $pdo->prepare("UPDATE `$t` SET is_active=? WHERE id=?")->execute([$new, $id]);
            najiz_audit($pdo, $new ? 'activate' : 'deactivate', $kind, $id, $row, ['is_active' => $new]);
            $notice = $new ? 'تم تفعيل السجل.' : 'تم تعطيل السجل (لم يُحذف).';
        } elseif ($action === 'edit') {
            $kind = (string)($_POST['kind'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name_ar'] ?? ''));
            $def = trim((string)($_POST['definition'] ?? ''));
            if ($name === '') throw new RuntimeException('الاسم مطلوب');
            if ($kind === 'category') {
                $b = $pdo->prepare('SELECT id, name_ar FROM najiz_categories WHERE id=?'); $b->execute([$id]); $row = $b->fetch();
                if (!$row) throw new RuntimeException('not found');
                $pdo->prepare('UPDATE najiz_categories SET name_ar=? WHERE id=?')->execute([$name, $id]);
                najiz_audit($pdo, 'edit', $kind, $id, $row, ['name_ar' => $name]);
            } elseif ($kind === 'subcategory') {
                $b = $pdo->prepare('SELECT id, name_ar FROM najiz_subcategories WHERE id=?'); $b->execute([$id]); $row = $b->fetch();
                if (!$row) throw new RuntimeException('not found');
                $pdo->prepare('UPDATE najiz_subcategories SET name_ar=? WHERE id=?')->execute([$name, $id]);
                najiz_audit($pdo, 'edit', $kind, $id, $row, ['name_ar' => $name]);
            } elseif ($kind === 'case_type') {
                $b = $pdo->prepare('SELECT id, name_ar, definition FROM najiz_case_types WHERE id=?'); $b->execute([$id]); $row = $b->fetch();
                if (!$row) throw new RuntimeException('not found');
                // Keep search_norm in step with the edited name, or search silently rots.
                $pdo->prepare('UPDATE najiz_case_types SET name_ar=?, definition=?, definition_missing=?, search_norm=? WHERE id=?')
                    ->execute([$name, $def !== '' ? $def : null, $def !== '' ? 0 : 1, najiz_normalize_ar($name), $id]);
                najiz_audit($pdo, 'edit', $kind, $id, $row, ['name_ar' => $name, 'definition' => $def]);
            } elseif ($kind === 'request') {
                $b = $pdo->prepare('SELECT id, name_ar FROM najiz_judicial_requests WHERE id=?'); $b->execute([$id]); $row = $b->fetch();
                if (!$row) throw new RuntimeException('not found');
                $pdo->prepare('UPDATE najiz_judicial_requests SET name_ar=?, search_norm=? WHERE id=?')
                    ->execute([$name, najiz_normalize_ar($name), $id]);
                najiz_audit($pdo, 'edit', $kind, $id, $row, ['name_ar' => $name]);
            } else {
                throw new RuntimeException('bad target');
            }
            $notice = 'تم حفظ التعديل.';
        } elseif ($action === 'import_dry') {
            $r = najiz_import($pdo, ['dry_run' => true]);
            $notice = 'معاينة الاستيراد: ' . $r['counts']['lawsuit_types'] . ' نوع دعوى، '
                . $r['counts']['judicial_requests'] . ' طلب قضائي، المطابقة: '
                . ($r['reconciles'] ? 'مطابق تمامًا للمصدر' : 'غير مطابق — راجع السجل');
        } elseif ($action === 'import_run') {
            $r = najiz_import($pdo, ['actor_admin_id' => (int)($_SESSION['admin_id'] ?? 0)]);
            najiz_audit($pdo, 'import', 'run', $r['run_id'], null, $r['inserted'] + $r['updated']);
            $notice = 'تم الاستيراد. جديد: ' . array_sum($r['inserted']) . '، محدَّث: ' . array_sum($r['updated'])
                . '. الأنواع: ' . $r['counts']['lawsuit_types'] . ' / المعلن في المصدر: ' . $r['declared_total_types'];
        }
    } catch (Throwable $e) {
        $error = 'تعذّر تنفيذ العملية: ' . $e->getMessage();
    }
}

$activeAdmin = 'classification';
require_once __DIR__ . '/includes/admin_header.php';

$stats = ['categories'=>0,'subcategories'=>0,'lawsuit_types'=>0,'judicial_requests'=>0];
$runs = [];
$audit = [];
$dupes = [];
$noDetail = [];
$catList = [];
$searchQ = trim((string)($_GET['q'] ?? ''));
$catFilter = (int)($_GET['cat'] ?? 0);
$rows = [];

if (!$tableMissing) {
    foreach (['categories'=>'najiz_categories','subcategories'=>'najiz_subcategories',
              'lawsuit_types'=>'najiz_case_types','judicial_requests'=>'najiz_judicial_requests'] as $k=>$t) {
        $stats[$k] = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE is_active=1")->fetchColumn();
    }
    $catList = $pdo->query('SELECT id, name_ar FROM najiz_categories ORDER BY sort_order, id')->fetchAll();
    $runs = $pdo->query('SELECT * FROM najiz_import_runs ORDER BY id DESC LIMIT 8')->fetchAll();
    try { $audit = $pdo->query('SELECT * FROM najiz_audit_log ORDER BY id DESC LIMIT 15')->fetchAll(); } catch (Throwable $e) {}

    // Same-name records under one parent — shown explicitly so an operator can see that
    // the duplicates are real source records and were not collapsed by the import.
    $dupes = $pdo->query('SELECT ca.name_ar cat, sc.name_ar sub, ct.name_ar t, COUNT(*) n
                            FROM najiz_case_types ct
                            JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id
                            JOIN najiz_categories ca ON ca.id=sc.category_id
                           GROUP BY ct.subcategory_id, ct.name_ar HAVING n > 1
                           ORDER BY n DESC, ca.sort_order')->fetchAll();

    // Types the source lists without a detail block.
    $noDetail = $pdo->query('SELECT ct.id, ct.name_ar, ca.name_ar cat, sc.name_ar sub
                               FROM najiz_case_types ct
                               JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id
                               JOIN najiz_categories ca ON ca.id=sc.category_id
                              WHERE ct.parse_note IS NOT NULL')->fetchAll();

    if ($searchQ !== '' && mb_strlen($searchQ) >= 2) {
        $rows = najiz_search($pdo, $searchQ, 100);
    } elseif ($catFilter > 0) {
        $s = $pdo->prepare('SELECT ct.id, ct.name_ar, ct.definition, ct.definition_missing, ct.is_active,
                                   sc.name_ar sub_name, ca.name_ar cat_name,
                                   (SELECT COUNT(*) FROM najiz_judicial_requests jr WHERE jr.case_type_id=ct.id) req_count
                              FROM najiz_case_types ct
                              JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id
                              JOIN najiz_categories ca ON ca.id=sc.category_id
                             WHERE ca.id=? ORDER BY sc.sort_order, ct.sort_order LIMIT 300');
        $s->execute([$catFilter]);
        $rows = $s->fetchAll();
    } else {
        $rows = $pdo->query('SELECT ct.id, ct.name_ar, ct.definition, ct.definition_missing, ct.is_active,
                                    sc.name_ar sub_name, ca.name_ar cat_name,
                                    (SELECT COUNT(*) FROM najiz_judicial_requests jr WHERE jr.case_type_id=ct.id) req_count
                               FROM najiz_case_types ct
                               JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id
                               JOIN najiz_categories ca ON ca.id=sc.category_id
                              ORDER BY ca.sort_order, sc.sort_order, ct.sort_order LIMIT 300')->fetchAll();
    }
}
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= is_rtl() ? 'تصنيفات ناجز' : 'Najiz classifications' ?>
      <span class="count-badge"><?= (int)$stats['lawsuit_types'] ?></span></h2>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="import_dry">
      <button class="btn" type="submit"><?= is_rtl() ? 'معاينة الاستيراد' : 'Preview import' ?></button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('<?= is_rtl() ? 'إعادة الاستيراد من ملف المصدر؟ العملية آمنة وقابلة للتكرار ولا تحذف شيئًا.' : 'Re-import from the source file? This is idempotent and deletes nothing.' ?>')">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="import_run">
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'إعادة الاستيراد' : 'Re-run import' ?></button>
    </form>
  </div>

  <?php if ($tableMissing): ?>
    <div class="form-alert error"><?= is_rtl()
      ? 'جداول التصنيفات غير موجودة. طبّق ترقية قاعدة البيانات upgrade_v59.sql أولاً.'
      : 'Classification tables are missing. Apply database upgrade_v59.sql first.' ?></div>
  <?php else: ?>

  <?php if ($notice): ?><div class="form-alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="dash-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:18px">
    <div style="display:flex;gap:22px;flex-wrap:wrap">
      <div><strong><?= (int)$stats['categories'] ?></strong> <span style="color:#64748b"><?= is_rtl() ? 'تصنيف رئيسي' : 'main categories' ?></span></div>
      <div><strong><?= (int)$stats['subcategories'] ?></strong> <span style="color:#64748b"><?= is_rtl() ? 'تصنيف فرعي' : 'subcategories' ?></span></div>
      <div><strong><?= (int)$stats['lawsuit_types'] ?></strong> <span style="color:#64748b"><?= is_rtl() ? 'نوع دعوى' : 'lawsuit types' ?></span></div>
      <div><strong><?= (int)$stats['judicial_requests'] ?></strong> <span style="color:#64748b"><?= is_rtl() ? 'طلب قضائي' : 'judicial requests' ?></span></div>
    </div>
  </div>

  <?php if ($dupes): ?>
  <div class="dash-card" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:16px;margin-bottom:18px">
    <strong><?= is_rtl() ? 'أسماء متكررة داخل التصنيف نفسه (سجلات مستقلة في المصدر — محفوظة كما هي)' : 'Repeated names within one parent (distinct source records — preserved as-is)' ?></strong>
    <ul style="margin:10px 0 0;padding-inline-start:20px;font-size:13px">
      <?php foreach ($dupes as $d): ?>
        <li><?= htmlspecialchars($d['cat']) ?> / <?= htmlspecialchars($d['sub']) ?> — «<?= htmlspecialchars($d['t']) ?>» ×<?= (int)$d['n'] ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($noDetail): ?>
  <div class="dash-card" style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:18px">
    <strong><?= is_rtl() ? 'أنواع دعاوى وردت في المصدر دون طلبات أو تعريف (مستوردة كما هي)' : 'Types the source lists with no requests or definition (imported as-is)' ?></strong>
    <ul style="margin:10px 0 0;padding-inline-start:20px;font-size:13px">
      <?php foreach ($noDetail as $d): ?>
        <li><?= htmlspecialchars($d['cat']) ?> / <?= htmlspecialchars($d['sub']) ?> — <?= htmlspecialchars($d['name_ar']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="dash-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:18px">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div class="field" style="flex:1;min-width:220px;margin:0">
        <label><?= is_rtl() ? 'بحث' : 'Search' ?></label>
        <input type="search" name="q" value="<?= htmlspecialchars($searchQ) ?>" placeholder="<?= is_rtl() ? 'اسم نوع الدعوى أو الطلب' : 'type or request name' ?>">
      </div>
      <div class="field" style="min-width:200px;margin:0">
        <label><?= is_rtl() ? 'التصنيف الرئيسي' : 'Main category' ?></label>
        <select name="cat">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($catList as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $catFilter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name_ar']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'تطبيق' : 'Apply' ?></button>
      <a class="btn" href="/admin/classification.php"><?= is_rtl() ? 'إعادة الضبط' : 'Reset' ?></a>
    </form>
  </div>

  <div class="dash-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:0;overflow-x:auto">
    <table class="data-table" style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th>#</th><th><?= is_rtl() ? 'نوع الدعوى' : 'Lawsuit type' ?></th>
        <th><?= is_rtl() ? 'المسار' : 'Path' ?></th>
        <th><?= is_rtl() ? 'الطلبات' : 'Requests' ?></th>
        <th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
        <th><?= is_rtl() ? 'إجراء' : 'Action' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td>
            <strong><?= htmlspecialchars($r['name_ar']) ?></strong>
            <?php if (!empty($r['definition'])): ?>
              <div style="font-size:12px;color:#64748b;max-width:420px"><?= htmlspecialchars(mb_substr($r['definition'], 0, 140)) ?><?= mb_strlen($r['definition']) > 140 ? '…' : '' ?></div>
            <?php else: ?>
              <div style="font-size:12px;color:#94a3b8"><?= is_rtl() ? 'لا يوجد تعريف في المصدر' : 'no definition in source' ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:13px;color:#475569"><?= htmlspecialchars($r['cat_name']) ?> › <?= htmlspecialchars($r['sub_name']) ?></td>
          <td><?= isset($r['req_count']) ? (int)$r['req_count'] : '—' ?></td>
          <td>
            <?php $act = $r['is_active'] ?? 1; ?>
            <span style="font-size:12px;padding:2px 8px;border-radius:10px;background:<?= $act ? '#dcfce7' : '#fee2e2' ?>;color:<?= $act ? '#166534' : '#991b1b' ?>">
              <?= $act ? (is_rtl() ? 'مفعّل' : 'active') : (is_rtl() ? 'معطّل' : 'inactive') ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <button class="btn" type="button" onclick="najizEdit(<?= (int)$r['id'] ?>, 'case_type', <?= htmlspecialchars(json_encode($r['name_ar'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((string)($r['definition'] ?? ''), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= is_rtl() ? 'تعديل' : 'Edit' ?></button>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="kind" value="case_type">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn" type="submit"><?= $act ? (is_rtl() ? 'تعطيل' : 'Retire') : (is_rtl() ? 'تفعيل' : 'Restore') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px"><?= is_rtl() ? 'لا توجد نتائج.' : 'No results.' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="dash-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;margin-top:18px">
    <h3 style="margin-top:0"><?= is_rtl() ? 'سجل عمليات الاستيراد والمطابقة' : 'Import runs & reconciliation' ?></h3>
    <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th>#</th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'البصمة' : 'SHA-256' ?></th>
        <th><?= is_rtl() ? 'المعلن' : 'Declared' ?></th><th><?= is_rtl() ? 'العدّاد' : 'Counts' ?></th><th><?= is_rtl() ? 'التاريخ' : 'When' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($runs as $run): ?>
        <tr>
          <td><?= (int)$run['id'] ?></td>
          <td><?= htmlspecialchars($run['status']) ?></td>
          <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars(substr((string)$run['source_sha256'], 0, 16)) ?>…</td>
          <td><?= $run['declared_total_types'] === null ? '—' : (int)$run['declared_total_types'] ?></td>
          <td style="font-size:12px;color:#475569"><?= htmlspecialchars((string)$run['counts_json']) ?></td>
          <td style="font-size:12px"><?= htmlspecialchars((string)$run['started_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$runs): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:18px">—</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php if ($audit): ?>
  <div class="dash-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;margin-top:18px">
    <h3 style="margin-top:0"><?= is_rtl() ? 'سجل التغييرات الإدارية' : 'Administrative change log' ?></h3>
    <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;border-collapse:collapse">
      <thead><tr><th>#</th><th><?= is_rtl() ? 'المنفّذ' : 'Actor' ?></th><th><?= is_rtl() ? 'العملية' : 'Action' ?></th>
        <th><?= is_rtl() ? 'الكيان' : 'Entity' ?></th><th><?= is_rtl() ? 'التاريخ' : 'When' ?></th></tr></thead>
      <tbody>
      <?php foreach ($audit as $a): ?>
        <tr><td><?= (int)$a['id'] ?></td><td><?= htmlspecialchars((string)($a['actor_label'] ?? '—')) ?></td>
          <td><?= htmlspecialchars($a['action']) ?></td>
          <td><?= htmlspecialchars($a['entity_kind']) ?> #<?= (int)$a['entity_id'] ?></td>
          <td style="font-size:12px"><?= htmlspecialchars((string)$a['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal-backdrop" id="najizEditModal">
    <form class="modal-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="kind" value="case_type">
      <input type="hidden" name="id" id="najizEditId" value="">
      <button type="button" class="modal-close" onclick="closeModal('najizEditModal')">✕</button>
      <h3 style="margin-bottom:14px"><?= is_rtl() ? 'تعديل نوع الدعوى' : 'Edit lawsuit type' ?></h3>
      <div class="field"><label><?= is_rtl() ? 'الاسم (عربي)' : 'Name (Arabic)' ?></label><input type="text" name="name_ar" id="najizEditName" required></div>
      <div class="field"><label><?= is_rtl() ? 'التعريف' : 'Definition' ?></label><textarea name="definition" id="najizEditDef" rows="4"></textarea></div>
      <button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
    </form>
  </div>
  <script>
  function najizEdit(id, kind, name, def){
    document.getElementById('najizEditId').value = id;
    document.querySelector('#najizEditModal [name=kind]').value = kind;
    document.getElementById('najizEditName').value = name || '';
    document.getElementById('najizEditDef').value = def || '';
    if (typeof openModal === 'function') openModal('najizEditModal');
    else document.getElementById('najizEditModal').classList.add('open');
  }
  </script>

  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
