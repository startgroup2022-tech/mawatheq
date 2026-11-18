<?php
/**
 * المكتبة القانونية والقضائية — مكتبتي المحفوظة (Execution 74).
 * Legal & judicial library — my saved items.
 *
 * The signed-in client's saved items. Removing is a CSRF-checked POST handled before
 * the shell is printed, so the redirect is not swallowed by already-sent headers.
 */
$activePage = 'legal-library';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/legal_library.php';
$pageTitle = is_rtl() ? 'مكتبتي القانونية' : 'My legal library';

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $itemId = (int)($_POST['item_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'remove' && $itemId > 0) {
        legal_library_favorite_toggle($pdo, $userId, $itemId);
    }
    header('Location: /legal-library-favorites.php');
    exit;
}

$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/app_header.php';

$rows = legal_library_favorites($pdo, $userId, 300);
?>
<div class="app-content">
  <div class="toolbar" style="margin-bottom:14px">
    <h1 style="margin:0;font-size:1.3rem"><?= is_rtl() ? 'مكتبتي القانونية المحفوظة' : 'My saved legal library' ?></h1>
    <a href="/legal-library.php" class="btn btn-outline btn-sm">← <?= is_rtl() ? 'المكتبة' : 'Library' ?></a>
  </div>

  <?php if (empty($rows)): ?>
    <div class="ll-empty"><span class="ll-empty-icon">★</span><?= is_rtl() ? 'لم تحفظ أي عنصر بعد. تصفّح المكتبة واضغط «احفظ في مكتبتي».' : 'You have not saved any item yet. Browse the library and press “Save to my library”.' ?></div>
  <?php else: ?>
    <div class="ll-list">
      <?php foreach ($rows as $row): $it = legal_library_public_item($row, $userId); ?>
        <div class="ll-item">
          <h3><a href="/legal-library-item.php?id=<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['title']) ?></a></h3>
          <div class="ll-chips">
            <span class="ll-chip type"><?= htmlspecialchars((string)$it['type_label']) ?></span>
            <?php if ($it['source_name']): ?><span class="ll-chip src"><?= htmlspecialchars((string)$it['source_name']) ?></span><?php endif; ?>
            <?php if ($it['hijri_year']): ?><span class="ll-chip"><?= (int)$it['hijri_year'] ?> <?= is_rtl() ? 'هـ' : 'AH' ?></span><?php endif; ?>
          </div>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'إزالة من مكتبتي' : 'Remove' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
