<?php
/**
 * المكتبة القانونية والقضائية — صفحة العنصر (Execution 74).
 * Legal & judicial library — item detail (read / download / save).
 *
 * Public, like the hub and search pages. Renders one item through
 * legal_library_public_item(), which is the single place the source's redistribution
 * rule is applied:
 *   - a `hosted` source with text  → the ruling/statute text is shown inline (read here);
 *   - a `link_only` source         → only metadata and the official link are shown, and
 *                                    the download button points at the source, never at
 *                                    a copy inside Mawatheq.
 *
 * Saving to "مكتبتي" is a CSRF-checked POST for a signed-in client; a guest sees no save
 * button. The POST is handled before the shell is printed so the redirect works (no
 * output buffering — see the POST-redirect note in AGENTS.md).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/legal_library.php';

$id = (int)($_GET['id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    // Only a signed-in client may save; a guest POST is ignored and redirected back.
    if (($_POST['action'] ?? '') === 'favorite' && $id > 0 && $userId > 0) {
        legal_library_favorite_toggle($pdo, $userId, $id);
    }
    header('Location: /legal-library-item.php?id=' . $id);
    exit;
}

$row = legal_library_item($id);
if (!$row) {
    http_response_code(404);
    $pageTitle = is_rtl() ? 'العنصر غير موجود' : 'Item not found';
    $pageStyles = ['/assets/css/legal-library.css'];
    require_once __DIR__ . '/includes/header.php';
    require_once __DIR__ . '/includes/legal_library_ui.php';
    echo '<div class="ll-page"><section class="section"><div class="container"><div class="ll-empty">'
       . '<div class="ll-empty-icon">' . ui_icon('search', 'll-ico-lg') . '</div>'
       . '<h3>' . (is_rtl() ? 'لم يُعثر على هذا العنصر' : 'This item could not be found') . '</h3>'
       . '<p><a href="/legal-library.php">' . (is_rtl() ? 'العودة إلى المكتبة' : 'Back to the library') . '</a></p>'
       . '</div></div></section></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$item = legal_library_public_item($row, $userId, true);
$related = legal_library_related($id, 6);
$pageTitle = $item['title'];
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal_library_ui.php';
$rtl = is_rtl();

// The structured sections the source published. Rendered as text (escaped), never as
// raw HTML — the text comes from a third party.
$sections = [];
if (!empty($item['sections'])) {
    foreach (['facts' => $rtl ? 'الوقائع' : 'Facts', 'reasons' => $rtl ? 'الأسباب' : 'Reasons',
              'ruling' => $rtl ? 'منطوق الحكم' : 'Ruling', 'narration' => $rtl ? 'الوقائع والسرد' : 'Narration'] as $k => $label) {
        $val = $item['sections'][$k] ?? null;
        if (is_string($val) && trim($val) !== '') $sections[$k] = ['label' => $label, 'text' => $val];
    }
}
// A body that is the ruling text and not already covered by a section.
$bodyText = !empty($item['body']) ? (string)$item['body'] : '';
$bodyCoveredByRuling = isset($sections['ruling']) && trim($sections['ruling']['text']) === trim($bodyText);
?>
<div class="ll-page">
<section class="section">
  <div class="container">
    <nav class="muted-sm" style="margin-bottom:12px">
      <a href="/legal-library.php" style="color:var(--ll-teal);text-decoration:none"><?= $rtl ? 'المكتبة القانونية' : 'Legal library' ?></a>
      <span> / </span>
      <a href="/legal-library-search.php?type=<?= urlencode((string)$item['type']) ?>" style="color:var(--ll-teal);text-decoration:none"><?= ui_e((string)$item['type_label']) ?></a>
    </nav>

    <div class="ll-reader">
      <div class="ll-reader-top">
        <?= ll_chip((string)$item['type_label'], 'type') ?>
        <?php if (!empty($item['source_name'])): ?><?= ll_chip((string)$item['source_name'], 'src', 'building') ?><?php endif; ?>
        <?php if (!empty($item['title_is_derived'])): ?><?= ll_chip($rtl ? 'وصف من الحقول الرسمية' : 'Described from official fields', 'derived', 'info') ?><?php endif; ?>
        <?php if (!empty($item['can_read_here'])): ?><?= ll_chip($rtl ? 'النص الكامل متاح للقراءة' : 'Full text readable here', 'type', 'check-circle') ?><?php endif; ?>
      </div>

      <h1><?= ui_e((string)$item['title']) ?></h1>

      <?php if (!empty($item['decision_title']) && $item['decision_title'] !== $item['title']): ?>
        <p class="muted-sm" style="margin:-4px 0 12px"><?= ui_e((string)$item['decision_title']) ?></p>
      <?php endif; ?>

      <!-- ------------------------------------------------ meta grid -->
      <div class="ll-detail-grid">
        <?php
        $meta = [];
        if (!empty($item['court_name'])) $meta[] = [$rtl ? 'الجهة القضائية' : 'Court', $item['court_name'], 'landmark'];
        if (!empty($item['city'])) $meta[] = [$rtl ? 'المنطقة / المدينة' : 'Region / city', $item['city'], 'map'];
        if (!empty($item['decision_number'])) $meta[] = [$rtl ? 'رقم الحكم' : 'Ruling number', $item['decision_number'], ''];
        if (!empty($item['case_number'])) $meta[] = [$rtl ? 'رقم القضية' : 'Case number', $item['case_number'], ''];
        if (!empty($item['decision_date'])) $meta[] = [$rtl ? 'تاريخ الحكم' : 'Ruling date', $item['decision_date'], 'calendar'];
        elseif (!empty($item['hijri_year'])) $meta[] = [$rtl ? 'السنة' : 'Year', (int)$item['hijri_year'] . ($rtl ? ' هـ' : ' AH'), 'calendar'];
        if (!empty($item['category'])) $meta[] = [$rtl ? 'التصنيف' : 'Category', $item['category'], ''];
        if (!empty($item['publisher'])) $meta[] = [$rtl ? 'الناشر' : 'Publisher', $item['publisher'], ''];
        if (!empty($item['file_type'])) $meta[] = [$rtl ? 'نوع الملف' : 'File type', strtoupper((string)$item['file_type']) . ($item['file_size_kb'] ? ' · ' . number_format((int)$item['file_size_kb']) . ' KB' : ''), 'file'];
        foreach ($meta as $m): ?>
          <div class="dl">
            <div class="dt"><?= ui_icon($m[2] ?: 'info', 'll-ico ll-ico-sm') ?> <?= ui_e($m[0]) ?></div>
            <div class="dd"><?= ui_e((string)$m[1]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- -------------------------------------------------- actions -->
      <div class="ll-actions">
        <?php if (!empty($item['download_url'])): ?>
          <a class="btn btn-primary btn-sm" href="<?= ui_e((string)$item['download_url']) ?>" <?= $item['download_is_external'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
            <?= ui_icon($item['download_is_external'] ? 'external' : 'download', 'll-ico ll-ico-sm') ?>
            <?= $item['download_is_external']
                ? ($rtl ? 'تنزيل من المصدر الرسمي' : 'Download from the official source')
                : ($rtl ? 'تنزيل الملف' : 'Download file') ?>
          </a>
        <?php endif; ?>
        <?php if (!empty($item['source_url'])): ?>
          <a class="btn btn-outline btn-sm" href="<?= ui_e((string)$item['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= ui_icon('link', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'الصفحة الرسمية' : 'Official page' ?></a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline btn-sm" data-ll-print><?= ui_icon('print', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'طباعة' : 'Print' ?></button>
        <button type="button" class="btn btn-outline btn-sm" data-ll-copy="<?= ui_e(rtrim(SITE_URL, '/') . '/legal-library-item.php?id=' . (int)$item['id']) ?>" data-copied="<?= $rtl ? 'تم نسخ الرابط' : 'Link copied' ?>"><?= ui_icon('copy', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'نسخ الرابط' : 'Copy link' ?></button>
        <?php if ($userId > 0): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
            <input type="hidden" name="action" value="favorite">
            <button class="btn btn-sm ll-fav-btn <?= !empty($item['is_favorite']) ? 'on' : 'btn-outline' ?>" type="submit">
              <?= ui_icon(!empty($item['is_favorite']) ? 'star-filled' : 'star', 'll-ico ll-ico-sm') ?>
              <?= !empty($item['is_favorite']) ? ($rtl ? 'محفوظ في مكتبتي' : 'Saved in my library') : ($rtl ? 'احفظ في مكتبتي' : 'Save to my library') ?>
            </button>
          </form>
        <?php else: ?>
          <a class="btn btn-outline btn-sm" href="/login.php"><?= ui_icon('star', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'سجّل الدخول للحفظ' : 'Sign in to save' ?></a>
        <?php endif; ?>
      </div>

      <!-- ------------------------------------------------- content -->
      <?php if ($sections || ($bodyText !== '' && !$bodyCoveredByRuling)): ?>
        <div class="ll-reader-layout">
          <div>
            <?php if (!empty($item['summary'])): ?>
              <div class="ll-callout"><?= ui_icon('info', 'll-ico') ?><span><?= ui_e((string)$item['summary']) ?></span></div>
            <?php endif; ?>

            <?php if ($bodyText !== '' && !$bodyCoveredByRuling): ?>
              <h2 class="ll-sec-title" id="ll-sec-text"><?= $rtl ? 'نص الحكم' : 'Ruling text' ?></h2>
              <div class="ll-prose"><?= ui_e($bodyText) ?></div>
            <?php endif; ?>

            <?php foreach ($sections as $k => $sec): ?>
              <h2 class="ll-sec-title" id="ll-sec-<?= ui_e($k) ?>"><?= ui_e($sec['label']) ?></h2>
              <div class="ll-prose"><?= ui_e($sec['text']) ?></div>
            <?php endforeach; ?>
          </div>

          <aside class="ll-toc">
            <h4><?= $rtl ? 'محتويات الصفحة' : 'On this page' ?></h4>
            <?php if ($bodyText !== '' && !$bodyCoveredByRuling): ?><a href="#ll-sec-text"><?= $rtl ? 'نص الحكم' : 'Ruling text' ?></a><?php endif; ?>
            <?php foreach ($sections as $k => $sec): ?>
              <a href="#ll-sec-<?= ui_e($k) ?>"><?= ui_e($sec['label']) ?></a>
            <?php endforeach; ?>
          </aside>
        </div>
      <?php elseif ($item['redistribution'] === 'link_only'): ?>
        <div class="ll-callout warn">
          <?= ui_icon('shield', 'll-ico') ?>
          <span><?= $rtl
            ? 'هذا المصدر يُعرض بالبيانات والرابط الرسمي فقط، ولا يُنسخ نصه أو ملفه داخل مواثيق التزاماً بسياسة إعادة النشر. استخدم «الصفحة الرسمية» لقراءة النص الكامل لدى الجهة المصدِرة.'
            : 'This source is shown as metadata plus its official link; its text or file is not copied into Mawatheq, in line with the redistribution policy. Use “Official page” to read the full text at the issuing authority.' ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- ------------------------------------------------- related -->
    <?php if ($related): ?>
      <div class="ll-section">
        <div class="ll-section-head"><h2><?= ui_icon('scale', 'll-ico') ?> <?= $rtl ? 'أحكام ذات صلة' : 'Related rulings' ?></h2></div>
        <div class="ll-mini-list">
          <?php foreach ($related as $r): echo ll_mini_card($r); endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
</div>
<script src="/assets/js/legal-library.js" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
