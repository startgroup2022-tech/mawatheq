<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $slug = trim($_POST['slug']) ?: strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $_POST['title_en']));
    $stmt = $pdo->prepare('INSERT INTO articles (title_ar,title_en,slug,excerpt_ar,excerpt_en,content_ar,content_en,status,published_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $status = $_POST['status'] === 'published' ? 'published' : 'draft';
    $stmt->execute([
        $_POST['title_ar'], $_POST['title_en'], $slug,
        $_POST['excerpt_ar'], $_POST['excerpt_en'],
        $_POST['content_ar'], $_POST['content_en'],
        $status, $status === 'published' ? date('Y-m-d H:i:s') : null
    ]);
    header('Location: /admin/articles.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete') {
    $id=(int)($_POST['article_id'] ?? 0);
    $pdo->prepare('DELETE FROM articles WHERE id=?')->execute([$id]);
    log_action($pdo,'حذف مقال','article',$id);
    header('Location: /admin/articles.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='publish') {
    $id=(int)($_POST['article_id'] ?? 0);
    $pdo->prepare("UPDATE articles SET status='published', published_at=IFNULL(published_at, NOW()) WHERE id=?")->execute([$id]);
    log_action($pdo,'نشر مقال','article',$id);
    header('Location: /admin/articles.php'); exit;
}

$activeAdmin = 'articles';
require_once __DIR__ . '/includes/admin_header.php';

$articles = $pdo->query('SELECT * FROM articles ORDER BY id DESC')->fetchAll();
?>
<div class="app-content">
  <div class="toolbar">
    <h2>مركز المعرفة <span class="count-badge"><?= count($articles) ?></span></h2>
    <button type="button" class="btn btn-primary" onclick="openModal('addArticleModal')">+ مقال جديد</button>
  </div>

  <div class="modal-backdrop" id="addArticleModal">
    <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="button" class="modal-close" onclick="closeModal('addArticleModal')">✕</button>
      <input type="hidden" name="action" value="create">
      <h3 style="margin-bottom:14px">مقال جديد</h3>
      <div class="grid-2">
        <div class="field"><label>العنوان (عربي)</label><input type="text" name="title_ar" required></div>
        <div class="field"><label>العنوان (إنجليزي)</label><input type="text" name="title_en" required></div>
        <div class="field"><label>مقتطف (عربي)</label><input type="text" name="excerpt_ar"></div>
        <div class="field"><label>مقتطف (إنجليزي)</label><input type="text" name="excerpt_en"></div>
      </div>
      <div class="field"><label>المحتوى (عربي)</label><textarea name="content_ar" rows="4"></textarea></div>
      <div class="field"><label>المحتوى (إنجليزي)</label><textarea name="content_en" rows="4"></textarea></div>
      <div class="grid-2">
        <div class="field"><label>الرابط (slug) — اختياري</label><input type="text" name="slug" placeholder="my-article"></div>
        <div class="field"><label>الحالة</label>
          <select name="status"><option value="draft">مسودة</option><option value="published">منشور</option></select>
        </div>
      </div>
      <button class="btn btn-primary btn-block" type="submit">حفظ المقال</button>
    </form>
  </div>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th>العنوان</th><th>الحالة</th><th>تاريخ النشر</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($articles as $a): ?>
        <tr>
          <td><?= htmlspecialchars($a['title_ar']) ?></td>
          <td><span class="status-pill status-<?= $a['status'] === 'published' ? 'confirmed' : 'pending' ?>"><?= $a['status'] === 'published' ? 'منشور' : 'مسودة' ?></span></td>
          <td><?= $a['published_at'] ? date('Y-m-d', strtotime($a['published_at'])) : '—' ?></td>
          <td>
            <?php if ($a['status'] !== 'published'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="article_id" value="<?= (int)$a['id'] ?>"><button class="icon-btn" type="submit">نشر</button></form><?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف هذا المقال؟')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="article_id" value="<?= (int)$a['id'] ?>"><button class="icon-btn danger" type="submit">حذف</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($articles)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-muted)">لا توجد مقالات بعد</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
