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
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container"><div class="ll-empty"><span class="ll-empty-icon">🔍</span>'
       . (is_rtl() ? 'لم يُعثر على هذا العنصر.' : 'This item could not be found.') . '</div></div></section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$item = legal_library_public_item($row, $userId, true);
$pageTitle = $item['title'];
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';

// The body is stored as the portal's HTML (with <br>). Render it as text paragraphs
// rather than raw HTML: the text comes from a third party, so it is escaped, not trusted.
$bodyHtml = '';
if (!empty($item['body'])) {
    $parts = preg_split('/\n+/', str_replace(['<br />', '<br/>', '<br>'], "\n", (string)$item['body']));
    foreach ($parts as $p) {
        $p = trim(strip_tags($p));
        if ($p !== '') $bodyHtml .= '<p>' . htmlspecialchars($p) . '</p>';
    }
}
?>
<section class="section">
  <div class="container">
    <div class="toolbar" style="margin-bottom:14px">
      <a href="/legal-library-search.php" class="btn btn-outline btn-sm">← <?= is_rtl() ? 'رجوع للبحث' : 'Back to search' ?></a>
      <a href="/legal-library.php" class="btn btn-outline btn-sm"><?= is_rtl() ? 'رئيسية المكتبة' : 'Library home' ?></a>
    </div>

    <div class="ll-reader">
      <span class="ll-chip type"><?= htmlspecialchars((string)$item['type_label']) ?></span>
      <?php if ($item['source_name']): ?><span class="ll-chip src"><?= htmlspecialchars((string)$item['source_name']) ?></span><?php endif; ?>
      <h1 style="margin:12px 0 8px;font-size:1.35rem;line-height:1.7"><?= htmlspecialchars((string)$item['title']) ?></h1>

      <div class="ll-chips" style="margin-bottom:14px">
        <?php if ($item['court_name']): ?><span class="ll-chip">🏛 <?= htmlspecialchars((string)$item['court_name']) ?></span><?php endif; ?>
        <?php if ($item['city']): ?><span class="ll-chip">📍 <?= htmlspecialchars((string)$item['city']) ?></span><?php endif; ?>
        <?php if ($item['decision_number']): ?><span class="ll-chip"><?= is_rtl() ? 'رقم الحكم' : 'Decision no.' ?> <?= htmlspecialchars((string)$item['decision_number']) ?></span><?php endif; ?>
        <?php if ($item['case_number']): ?><span class="ll-chip"><?= is_rtl() ? 'رقم القضية' : 'Case no.' ?> <?= htmlspecialchars((string)$item['case_number']) ?></span><?php endif; ?>
        <?php if ($item['decision_date']): ?><span class="ll-chip">🗓 <?= htmlspecialchars((string)$item['decision_date']) ?></span><?php endif; ?>
        <?php if ($item['hijri_year']): ?><span class="ll-chip"><?= (int)$item['hijri_year'] ?> <?= is_rtl() ? 'هـ' : 'AH' ?></span><?php endif; ?>
        <?php if ($item['publisher']): ?><span class="ll-chip"><?= htmlspecialchars((string)$item['publisher']) ?></span><?php endif; ?>
        <?php if ($item['file_type']): ?><span class="ll-chip"><?= htmlspecialchars(strtoupper((string)$item['file_type'])) ?><?= $item['file_size_kb'] ? ' · ' . number_format((int)$item['file_size_kb']) . ' KB' : '' ?></span><?php endif; ?>
      </div>

      <div class="ll-actions">
        <?php if ($item['can_read_here']): ?>
          <span class="ll-chip" style="background:#E8F5F3;color:#0d6b5e">✔ <?= is_rtl() ? 'النص الكامل متاح للقراءة هنا' : 'Full text readable here' ?></span>
        <?php endif; ?>
        <?php if (!empty($item['download_url'])): ?>
          <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars((string)$item['download_url']) ?>" <?= $item['download_is_external'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
            ⬇ <?= $item['download_is_external']
                ? (is_rtl() ? 'تنزيل من المصدر الرسمي' : 'Download from the official source')
                : (is_rtl() ? 'تنزيل الملف' : 'Download file') ?>
          </a>
        <?php endif; ?>
        <?php if (!empty($item['source_url'])): ?>
          <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars((string)$item['source_url']) ?>" target="_blank" rel="noopener noreferrer">🔗 <?= is_rtl() ? 'الصفحة الرسمية' : 'Official page' ?></a>
        <?php endif; ?>
        <?php if ($userId > 0): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="favorite">
            <button class="btn btn-sm ll-fav-btn <?= !empty($item['is_favorite']) ? 'on' : 'btn-outline' ?>" type="submit">
              <?= !empty($item['is_favorite']) ? '★ ' . (is_rtl() ? 'محفوظ' : 'Saved') : '☆ ' . (is_rtl() ? 'احفظ في مكتبتي' : 'Save to my library') ?>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($item['summary']): ?><p class="ll-summary" style="font-size:1rem"><?= htmlspecialchars((string)$item['summary']) ?></p><?php endif; ?>

      <?php if ($bodyHtml !== ''): ?>
        <hr style="border:none;border-top:1px solid var(--line,#E2E8F0);margin:18px 0">
        <div class="ll-body"><?= $bodyHtml ?></div>
      <?php elseif ($item['redistribution'] === 'link_only'): ?>
        <div class="ll-banner" style="margin-top:16px"><?= is_rtl()
          ? 'هذا المصدر لا يسمح بنسخ الملف داخل مواثيق. يمكنك تنزيله أو قراءته من الرابط الرسمي أعلاه.'
          : 'This source does not permit copying the file into Mawatheq. Use the official link above to read or download it.' ?></div>
      <?php elseif ($item['item_type'] === 'judgment'): ?>
        <p class="muted-sm" style="margin-top:16px"><?= is_rtl()
          ? 'نص الحكم الكامل متاح عبر الصفحة الرسمية لوزارة العدل. اضغط «الصفحة الرسمية» لعرضه.'
          : 'The full ruling text is available on the Ministry of Justice official page. Press “Official page” to view it.' ?></p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
