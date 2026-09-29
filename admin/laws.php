<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/semantic_search.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $verified=($_SESSION['admin_role']??'')==='super_admin'&&isset($_POST['verified'])?'verified':'unverified';
    $stmt = $pdo->prepare('INSERT INTO law_articles (category_id,law_name_ar,law_name_en,article_number,content_ar,content_en,verification_status) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        (int)$_POST['category_id'], $_POST['law_name_ar'], $_POST['law_name_en'],
        $_POST['article_number'], $_POST['content_ar'], $_POST['content_en'], $verified
    ]);
    log_action($pdo,'إضافة مادة إلى مكتبة الأنظمة ('.$verified.')','law_article',(int)$pdo->lastInsertId());
    try{$q=$pdo->prepare('SELECT id,law_name_ar,law_name_en,content_ar,content_en FROM law_articles WHERE id=?');$q->execute([(int)$pdo->lastInsertId()]);$article=$q->fetch();if($article)law_article_index_embedding($pdo,embedding_load_settings($pdo),$article);}catch(Throwable $e){error_log('New law embedding deferred: '.$e->getMessage());}
    header('Location: /admin/laws.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='verify') {
    if(($_SESSION['admin_role']??'')!=='super_admin'){http_response_code(403);exit('Forbidden');}
    $id=(int)($_POST['article_id']??0);$value=($_POST['verification_status']??'')==='verified'?'verified':'unverified';
    $pdo->prepare('UPDATE law_articles SET verification_status=? WHERE id=?')->execute([$value,$id]);
    log_action($pdo,'تغيير حالة توثيق مادة نظامية إلى '.$value,'law_article',$id);header('Location: /admin/laws.php');exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete') {
    $id=(int)($_POST['article_id'] ?? 0);
    $pdo->prepare('DELETE FROM law_articles WHERE id=?')->execute([$id]);
    log_action($pdo,'حذف مادة من مكتبة الأنظمة','law_article',$id);
    header('Location: /admin/laws.php'); exit;
}

$activeAdmin = 'laws';
require_once __DIR__ . '/includes/admin_header.php';

$categories = $pdo->query('SELECT * FROM law_categories ORDER BY sort_order')->fetchAll();
$articles = $pdo->query('SELECT la.*, lc.name_ar AS cat_name FROM law_articles la JOIN law_categories lc ON lc.id=la.category_id ORDER BY la.id DESC')->fetchAll();
?>
<div class="app-content">
  <div class="toolbar">
    <h2>مكتبة الأنظمة واللوائح <span class="count-badge"><?= count($articles) ?></span></h2>
    <button type="button" class="btn btn-primary" onclick="openModal('addLawModal')">+ إضافة مادة نظامية</button>
  </div>
  <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:18px">هذه المكتبة تُستخدم أيضاً كمصدر بحث للمساعد الذكي، مع فهرس بحث نصي كامل ورابط دائم لكل مادة.</p>

  <div class="modal-backdrop" id="addLawModal">
    <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="button" class="modal-close" onclick="closeModal('addLawModal')">✕</button>
      <input type="hidden" name="action" value="create">
      <h3 style="margin-bottom:14px">إضافة مادة نظامية</h3>
      <div class="grid-2">
        <div class="field"><label>التصنيف</label>
          <select name="category_id">
            <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name_ar']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>رقم المادة</label><input type="text" name="article_number" placeholder="المادة ٨٠"></div>
        <div class="field"><label>اسم النظام (عربي)</label><input type="text" name="law_name_ar" required></div>
        <div class="field"><label>اسم النظام (إنجليزي)</label><input type="text" name="law_name_en" required></div>
      </div>
      <div class="field"><label>نص المادة (عربي)</label><textarea name="content_ar" rows="3" required></textarea></div>
      <div class="field"><label>نص المادة (إنجليزي)</label><textarea name="content_en" rows="3" required></textarea></div>
      <?php if(($_SESSION['admin_role']??'')==='super_admin'):?><label><input type="checkbox" name="verified" value="1"> تم التحقق من المصدر الرسمي والنص</label><?php else:?><p class="field-hint">تُضاف المادة كغير موثقة، ويعتمدها المدير الأعلى بعد مراجعتها.</p><?php endif;?>
      <button class="btn btn-primary btn-block" type="submit">إضافة</button>
    </form>
  </div>

  <div class="table-wrap">
    <table class="admin-table">
      <thead><tr><th>التصنيف</th><th>النظام</th><th>المادة</th><th>التوثيق</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($articles as $a): ?>
        <tr>
          <td><?= htmlspecialchars($a['cat_name']) ?></td>
          <td><?= htmlspecialchars($a['law_name_ar']) ?></td>
          <td><?= htmlspecialchars($a['article_number']) ?></td>
          <td><?=htmlspecialchars($a['verification_status']??'unverified')?></td>
          <td><?php if(($_SESSION['admin_role']??'')==='super_admin'):?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="article_id" value="<?=(int)$a['id']?>"><input type="hidden" name="verification_status" value="<?=($a['verification_status']??'unverified')==='verified'?'unverified':'verified'?>"><button class="icon-btn" type="submit"><?=($a['verification_status']??'unverified')==='verified'?'إلغاء التوثيق':'اعتماد المصدر'?></button></form><?php endif;?><form method="post" style="display:inline" onsubmit="return confirm('حذف؟')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="article_id" value="<?= (int)$a['id'] ?>"><button class="icon-btn danger" type="submit">حذف</button></form></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($articles)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-muted)">لا توجد مواد بعد</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
