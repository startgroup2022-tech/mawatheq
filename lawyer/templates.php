<?php
/**
 * مكتبة القوالب — استعراض القالب، تعبئته، وتصديره Word/PDF.
 *
 * The catalogue lives in `document_templates` (v81 schema, filled by v83). This page is
 * the only place a lawyer uses it, and it does three things in one flow so a template is
 * never just a wall of text:
 *
 *   1. browse/filter the catalogue (platform rows + this firm's own rows);
 *   2. fill the template's declared fields, with the rendered document previewed live;
 *   3. export the filled document as Word (.docx) or PDF.
 *
 * Export reuses `includes/docx_export.php` and `includes/pdf.php` — the two engines the
 * rest of the platform already ships — rather than introducing a third.
 *
 * Two rules the page must hold:
 *   - a firm sees and edits only its own rows; a platform row is read-only here, and a
 *     firm that wants to change one copies it into its own scope first;
 *   - the tier gate is server-side. A `paid` template on a `free` firm is listed as
 *     locked and the export endpoint refuses it, so hiding the button is not the gate.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/template_library.php';
require_once __DIR__ . '/../includes/plan_features.php';
require_once __DIR__ . '/../includes/docx_export.php';
require_once __DIR__ . '/../includes/pdf.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = tenant_firm_id($pdo);
$firmTier = template_tier_for_firm($pdo, $firmId);

$ready = template_library_ready($pdo);
$categories = $ready ? template_categories_all($pdo) : [];

$viewId = (int)($_GET['t'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$message = null;
$error = null;

// ---- POST: save a firm template, copy a platform one, or export ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_template') {
        if (!$firmId) {
            $error = is_rtl()
                ? 'أنشئ مكتباً أولاً من صفحة «المكتب» لتتمكن من حفظ قوالب خاصة بمكتبك.'
                : 'Create a firm first (Firm page) before saving firm-owned templates.';
        } else {
            // `max_templates` is a ceiling the pricing page advertises, so the create path is
            // where it has to hold. Checked before the write: a refused create stores nothing.
            $tplCheck = plan_check(
                $pdo,
                plan_for_firm($pdo, (int)$firmId, $lawyerId),
                ['firm_id' => (int)$firmId, 'lawyer_id' => $lawyerId],
                'max_templates'
            );
            if (!$tplCheck['allowed']) {
                $error = $tplCheck['message'];
            } else {
            [$ok, $newId, $err] = template_save($pdo, $firmId, $_POST, $lawyerId);
            if ($ok) {
                log_action($pdo, 'template_save', 'document_template', (int)$newId, ['slug' => (string)($_POST['slug'] ?? '')]);
                header('Location: templates.php?t=' . (int)$newId . '&saved=1');
                exit;
            }
            $error = match ($err) {
                'title_required' => is_rtl() ? 'عنوان القالب مطلوب.' : 'Template title is required.',
                'bad_slug' => is_rtl() ? 'المعرّف (slug) غير صالح: استخدم حروفاً إنجليزية صغيرة وأرقاماً وشرطات.' : 'Invalid slug: use lowercase letters, digits and hyphens.',
                'duplicate_slug' => is_rtl() ? 'يوجد قالب بهذا المعرّف في مكتبك بالفعل.' : 'Your firm already has a template with that slug.',
                'not_found_or_forbidden' => is_rtl() ? 'لا يمكنك تعديل هذا القالب.' : 'You cannot edit that template.',
                'firm_required' => is_rtl() ? 'لا يوجد مكتب مرتبط بحسابك.' : 'No firm is linked to your account.',
                default => is_rtl() ? 'تعذّر حفظ القالب.' : 'The template could not be saved.',
            };
            }
        }
    } elseif ($action === 'copy_template') {
        // Copy a platform template into the firm's scope so it becomes editable.
        $srcId = (int)($_POST['source_id'] ?? 0);
        $src = $srcId > 0 ? template_get($pdo, $srcId, $firmId) : null;
        if (!$src) {
            $error = is_rtl() ? 'القالب المصدر غير موجود.' : 'Source template not found.';
        } elseif (!$firmId) {
            $error = is_rtl() ? 'أنشئ مكتباً أولاً لنسخ القوالب.' : 'Create a firm first to copy templates.';
        } elseif (!template_can_use($src, $firmTier)) {
            $error = is_rtl() ? 'هذا القالب يتطلب باقة أعلى.' : 'This template needs a higher plan.';
        } elseif (!($copyCheck = plan_check($pdo, plan_for_firm($pdo, (int)$firmId, $lawyerId), ['firm_id' => (int)$firmId, 'lawyer_id' => $lawyerId], 'max_templates'))['allowed']) {
            // Copying also creates a firm-owned row, so it consumes the same quota.
            $error = $copyCheck['message'];
        } else {
            $copy = $src;
            unset($copy['id']);
            $copy['slug'] = substr($src['slug'], 0, 100) . '-f' . $firmId;
            $copy['title_ar'] = $src['title_ar'] . ' (نسخة المكتب)';
            [$ok, $newId, $err] = template_save($pdo, $firmId, $copy, $lawyerId);
            if ($ok) {
                header('Location: templates.php?edit=' . (int)$newId . '&copied=1');
                exit;
            }
            $error = is_rtl() ? 'تعذّر نسخ القالب.' : 'The template could not be copied.';
        }
    } elseif ($action === 'delete_template') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($firmId && $delId > 0 && template_delete($pdo, $delId, $firmId)) {
            log_action($pdo, 'template_delete', 'document_template', $delId, []);
            header('Location: templates.php?deleted=1');
            exit;
        }
        $error = is_rtl() ? 'تعذّر حذف القالب.' : 'The template could not be deleted.';
    } elseif ($action === 'export') {
        $tplId = (int)($_POST['template_id'] ?? 0);
        $tpl = $tplId > 0 ? template_get($pdo, $tplId, $firmId) : null;
        $format = in_array($_POST['format'] ?? 'docx', ['docx', 'pdf'], true) ? (string)$_POST['format'] : 'docx';

        if (!$tpl) {
            http_response_code(404);
            exit('Template not found');
        }
        if (!template_can_use($tpl, $firmTier)) {
            http_response_code(403);
            exit('This template requires a higher plan');
        }

        $values = [];
        foreach (template_fields($tpl) as $f) {
            $values[$f['key']] = (string)($_POST['f_' . $f['key']] ?? '');
        }
        $body = template_render($tpl, $values, is_rtl());
        $title = (string)($tpl['title_ar'] ?: $tpl['title_en']);

        log_action($pdo, 'template_export', 'document_template', (int)$tpl['id'], ['format' => $format]);

        if ($format === 'docx') {
            $bytes = build_simple_docx($title, $body, '', is_rtl());
            if ($bytes === null) {
                http_response_code(500);
                exit('DOCX generation is unavailable on this host (ZipArchive missing)');
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="template-' . (int)$tpl['id'] . '.docx"');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit;
        }

        if (!mawatheq_pdf_fonts_available()) {
            http_response_code(500);
            exit('PDF fonts are unavailable on this host');
        }
        // The template body is plain text; escape it and keep the paragraph breaks,
        // so a value a lawyer typed can never inject markup into the PDF.
        $html = '<div style="white-space:pre-wrap;line-height:1.9">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
            . '</div>';
        $mpdf = mawatheq_pdf_render($html, ['title' => $title]);
        mawatheq_pdf_download($mpdf, 'template-' . (int)$tpl['id'] . '.pdf');
        exit;
    }
}

if (isset($_GET['saved'])) $message = is_rtl() ? 'تم حفظ القالب.' : 'Template saved.';
if (isset($_GET['copied'])) $message = is_rtl() ? 'تم نسخ القالب إلى مكتبك. عدّله كما تريد.' : 'Template copied into your firm. Edit it freely.';
if (isset($_GET['deleted'])) $message = is_rtl() ? 'تم حذف القالب.' : 'Template deleted.';

$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (int)($_GET['cat'] ?? 0);
$templates = $ready ? template_list($pdo, $firmId, ['search' => $search, 'category_id' => $categoryFilter]) : [];

$current = null;
if ($viewId > 0 && $ready) $current = template_get($pdo, $viewId, $firmId);
if ($current && !template_can_use($current, $firmTier)) {
    $lockedCurrent = true;
} else {
    $lockedCurrent = false;
}

$editing = null;
if ($editId > 0 && $ready) {
    $editing = template_get($pdo, $editId, $firmId);
    // Only a firm-owned row is editable; a platform row must be copied first.
    if ($editing && $editing['firm_id'] === null) $editing = null;
}

require_once __DIR__ . '/includes/lawyer_header.php';
?>
<style>
.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.tpl-card{border:1px solid var(--border);border-radius:12px;background:#fff;padding:16px;display:flex;flex-direction:column;gap:8px}
.tpl-card h3{margin:0;font-size:1rem;color:var(--navy-dark)}
.tpl-card .cat{font-size:.75rem;color:var(--teal);font-weight:700}
.tpl-card p{margin:0;font-size:.85rem;color:var(--text-muted);line-height:1.6}
.tpl-lock{background:#FDF3E1;color:#7A5409;border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:700;display:inline-block}
.tpl-tier{background:#E8F4F1;color:#0F6B5C;border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:700;display:inline-block}
.tpl-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:820px){.tpl-fields{grid-template-columns:1fr}}
.tpl-preview{white-space:pre-wrap;background:#FAFBFC;border:1px solid var(--border);border-radius:10px;padding:18px;line-height:2;font-size:.95rem;max-height:520px;overflow:auto}
</style>

<div class="app-content">
  <div class="toolbar">
    <h2><?= is_rtl() ? 'مكتبة القوالب القانونية' : 'Legal template library' ?></h2>
    <span class="tpl-tier"><?= is_rtl() ? 'باقتك:' : 'Your plan:' ?> <?= htmlspecialchars($firmTier) ?></span>
  </div>

  <?php if ($message): ?><div class="dash-card" style="border-color:var(--teal)"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="dash-card" style="border-color:#E5B4B4;color:#8A2B2B"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if (!$ready): ?>
    <div class="dash-card">
      <p><?= is_rtl() ? 'مكتبة القوالب غير مفعّلة بعد على هذه القاعدة. طبّق ترقية قاعدة البيانات upgrade_v81.sql ثم upgrade_v83.sql.' : 'The template library is not enabled on this database yet. Apply upgrade_v81.sql then upgrade_v83.sql.' ?></p>
    </div>
  <?php elseif ($editing): ?>
    <!-- ============ editor ============ -->
    <div class="dash-card">
      <h3><?= is_rtl() ? 'تحرير قالب المكتب' : 'Edit firm template' ?></h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_template">
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <div class="tpl-fields">
          <label><?= is_rtl() ? 'العنوان (عربي)' : 'Title (Arabic)' ?>
            <input class="input" type="text" name="title_ar" required value="<?= htmlspecialchars((string)$editing['title_ar']) ?>">
          </label>
          <label><?= is_rtl() ? 'العنوان (إنجليزي)' : 'Title (English)' ?>
            <input class="input" type="text" name="title_en" value="<?= htmlspecialchars((string)$editing['title_en']) ?>">
          </label>
          <label><?= is_rtl() ? 'المعرّف (slug)' : 'Slug' ?>
            <input class="input" type="text" name="slug" value="<?= htmlspecialchars((string)$editing['slug']) ?>">
          </label>
          <label><?= is_rtl() ? 'التصنيف' : 'Category' ?>
            <select class="input" name="category_id">
              <option value="0">—</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$editing['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$c['name_ar']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><?= is_rtl() ? 'الباقة المطلوبة' : 'Required tier' ?>
            <select class="input" name="plan_tier">
              <?php foreach (['free','paid','corporate'] as $tier): ?>
                <option value="<?= $tier ?>" <?= $editing['plan_tier'] === $tier ? 'selected' : '' ?>><?= $tier ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><?= is_rtl() ? 'نشط' : 'Active' ?>
            <select class="input" name="is_active">
              <option value="1" <?= (int)$editing['is_active'] === 1 ? 'selected' : '' ?>><?= is_rtl() ? 'نعم' : 'Yes' ?></option>
              <option value="0" <?= (int)$editing['is_active'] === 0 ? 'selected' : '' ?>><?= is_rtl() ? 'لا' : 'No' ?></option>
            </select>
          </label>
        </div>
        <label style="display:block;margin-top:12px"><?= is_rtl() ? 'نص القالب (استخدم {{الاسم}} للمتغيرات)' : 'Template body (use {{name}} for placeholders)' ?>
          <textarea class="input" name="body_ar" rows="14" style="font-family:inherit"><?= htmlspecialchars((string)$editing['body_ar']) ?></textarea>
        </label>
        <label style="display:block;margin-top:12px"><?= is_rtl() ? 'الحقول الديناميكية (JSON)' : 'Dynamic fields (JSON)' ?>
          <textarea class="input" name="fields_json" rows="6" style="font-family:monospace;direction:ltr"><?= htmlspecialchars((string)$editing['fields_json']) ?></textarea>
        </label>
        <div style="margin-top:14px;display:flex;gap:10px">
          <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
          <a class="btn" href="templates.php"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></a>
        </div>
      </form>
    </div>

  <?php elseif ($current): ?>
    <!-- ============ fill + export ============ -->
    <p><a href="templates.php">&larr; <?= is_rtl() ? 'كل القوالب' : 'All templates' ?></a></p>
    <div class="dash-card">
      <div class="cat"><?= htmlspecialchars((string)($current['category_ar'] ?? '')) ?></div>
      <h3 style="margin:4px 0"><?= htmlspecialchars((string)$current['title_ar']) ?></h3>
      <p style="color:var(--text-muted)"><?= htmlspecialchars((string)$current['description_ar']) ?></p>

      <?php if ($lockedCurrent): ?>
        <div class="tpl-lock"><?= is_rtl() ? 'هذا القالب يتطلب باقة أعلى — رقِّ باقتك لاستخدامه.' : 'This template requires a higher plan — upgrade to use it.' ?></div>
      <?php else: ?>
        <form method="post" id="fillForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="export">
          <input type="hidden" name="template_id" value="<?= (int)$current['id'] ?>">
          <div class="tpl-fields">
            <?php foreach (template_fields($current) as $f): ?>
              <label><?= htmlspecialchars(is_rtl() ? $f['label_ar'] : $f['label_en']) ?><?= $f['required'] ? ' *' : '' ?>
                <?php if ($f['type'] === 'textarea'): ?>
                  <textarea class="input" name="f_<?= htmlspecialchars($f['key']) ?>" rows="4" <?= $f['required'] ? 'required' : '' ?> data-preview></textarea>
                <?php else: ?>
                  <input class="input" type="<?= $f['type'] === 'number' ? 'number' : ($f['type'] === 'date' ? 'date' : 'text') ?>"
                         name="f_<?= htmlspecialchars($f['key']) ?>" <?= $f['required'] ? 'required' : '' ?> data-preview>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

          <h4 style="margin:18px 0 8px"><?= is_rtl() ? 'معاينة مباشرة' : 'Live preview' ?></h4>
          <div class="tpl-preview" id="tplPreview"><?= htmlspecialchars((string)$current['body_ar']) ?></div>

          <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit" name="format" value="docx"><?= is_rtl() ? 'تصدير Word' : 'Export Word' ?></button>
            <button class="btn" type="submit" name="format" value="pdf"><?= is_rtl() ? 'تصدير PDF' : 'Export PDF' ?></button>
          </div>
        </form>

        <script>
        // Live preview fills {{placeholders}} from the inputs, so the lawyer sees the
        // document before exporting. Kept in a file-free script block: the CSP allows
        // 'self' scripts and this page is served by us; no inline handlers.
        (function(){
          var raw = <?= json_encode((string)$current['body_ar'], JSON_UNESCAPED_UNICODE) ?>;
          var out = document.getElementById('tplPreview');
          var inputs = document.querySelectorAll('[data-preview]');
          function render(){
            var t = raw;
            inputs.forEach(function(el){
              var k = el.name.replace(/^f_/, '');
              var v = el.value.trim();
              if (v) t = t.split('{{' + k + '}}').join(v);
            });
            out.textContent = t;
          }
          inputs.forEach(function(el){ el.addEventListener('input', render); });
          render();
        })();
        </script>
      <?php endif; ?>

      <?php if ($current['firm_id'] !== null): ?>
        <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px;display:flex;gap:10px;flex-wrap:wrap">
          <a class="btn" href="templates.php?edit=<?= (int)$current['id'] ?>"><?= is_rtl() ? 'تحرير القالب' : 'Edit template' ?></a>
          <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'حذف القالب؟' : 'Delete this template?' ?>')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
            <button class="btn" type="submit" style="color:#8A2B2B"><?= is_rtl() ? 'حذف' : 'Delete' ?></button>
          </form>
        </div>
      <?php else: ?>
        <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="copy_template">
            <input type="hidden" name="source_id" value="<?= (int)$current['id'] ?>">
            <button class="btn" type="submit"><?= is_rtl() ? 'نسخ إلى مكتبي لتعديله' : 'Copy to my firm to edit' ?></button>
          </form>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ============ catalogue ============ -->
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
      <input class="input" type="search" name="q" placeholder="<?= is_rtl() ? 'ابحث في القوالب…' : 'Search templates…' ?>" value="<?= htmlspecialchars($search) ?>" style="max-width:280px">
      <select class="input" name="cat" style="max-width:240px">
        <option value="0"><?= is_rtl() ? 'كل التصنيفات' : 'All categories' ?></option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$c['name_ar']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'بحث' : 'Search' ?></button>
    </form>

    <?php if (!$templates): ?>
      <div class="dash-card">
        <p><?= is_rtl() ? 'لا توجد قوالب مطابقة. جرّب بحثاً آخر أو تصنيفاً مختلفاً.' : 'No templates match. Try another search or category.' ?></p>
      </div>
    <?php else: ?>
      <div class="tpl-grid">
        <?php foreach ($templates as $t): $locked = !template_can_use($t, $firmTier); ?>
          <div class="tpl-card">
            <div class="cat"><?= htmlspecialchars((string)($t['category_ar'] ?? '')) ?></div>
            <h3><?= htmlspecialchars((string)$t['title_ar']) ?></h3>
            <p><?= htmlspecialchars(mb_substr((string)$t['description_ar'], 0, 110)) ?></p>
            <div style="margin-top:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <?php if ($locked): ?>
                <span class="tpl-lock"><?= is_rtl() ? 'باقة أعلى' : 'Higher plan' ?></span>
              <?php endif; ?>
              <?php if ($t['firm_id'] !== null): ?>
                <span class="tpl-tier"><?= is_rtl() ? 'خاص بمكتبك' : 'Firm template' ?></span>
              <?php endif; ?>
              <a class="btn btn-primary" href="templates.php?t=<?= (int)$t['id'] ?>"><?= $locked ? (is_rtl() ? 'عرض' : 'View') : (is_rtl() ? 'استخدام' : 'Use') ?></a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($firmId): ?>
      <div class="dash-card" style="margin-top:22px">
        <h3><?= is_rtl() ? 'إنشاء قالب خاص بمكتبك' : 'Create a firm template' ?></h3>
        <p style="color:var(--text-muted)"><?= is_rtl() ? 'أضف صيغة يعتمدها مكتبك، وستظهر لعملاء المكتب فقط. أو ابدأ من قالب جاهز بالنسخ.' : 'Add a wording your firm relies on; it appears to your firm only. Or start from a ready template by copying it.' ?></p>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_template">
          <div class="tpl-fields">
            <label><?= is_rtl() ? 'العنوان (عربي)' : 'Title (Arabic)' ?><input class="input" type="text" name="title_ar" required></label>
            <label><?= is_rtl() ? 'العنوان (إنجليزي)' : 'Title (English)' ?><input class="input" type="text" name="title_en"></label>
            <label><?= is_rtl() ? 'المعرّف (slug)' : 'Slug' ?><input class="input" type="text" name="slug" placeholder="my-firm-memo"></label>
            <label><?= is_rtl() ? 'التصنيف' : 'Category' ?>
              <select class="input" name="category_id"><option value="0">—</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars((string)$c['name_ar']) ?></option><?php endforeach; ?>
              </select>
            </label>
          </div>
          <label style="display:block;margin-top:12px"><?= is_rtl() ? 'نص القالب' : 'Template body' ?>
            <textarea class="input" name="body_ar" rows="8" style="font-family:inherit"></textarea>
          </label>
          <label style="display:block;margin-top:12px"><?= is_rtl() ? 'الحقول (JSON)' : 'Fields (JSON)' ?>
            <textarea class="input" name="fields_json" rows="4" style="font-family:monospace;direction:ltr" placeholder='[{"key":"party_a","label_ar":"الطرف الأول","type":"text","required":true}]'></textarea>
          </label>
          <input type="hidden" name="is_active" value="1">
          <button class="btn btn-primary" type="submit" style="margin-top:12px"><?= is_rtl() ? 'إنشاء القالب' : 'Create template' ?></button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
