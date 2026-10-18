<?php
/**
 * Firm website & office structure management (v50).
 *
 * The write half of the firm public website: branding and SEO copy, published pages,
 * branches, departments, and internal announcements. Every write is scoped to the firm
 * resolved from the session (law_firm_members), never from a request parameter, and every
 * write requires owner/partner (firm_site_can_manage). A plain lawyer/staff member can view
 * this page read-only — useful, and harmless, since none of it is another firm's data.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/firm_site.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$error = null; $success = null;

$q = $pdo->prepare("SELECT f.*, m.member_role FROM law_firm_members m JOIN law_firms f ON f.id=m.firm_id WHERE m.lawyer_id=? AND m.status='active' LIMIT 1");
$q->execute([$lawyerId]); $firm = $q->fetch();
$firmId = $firm ? (int)$firm['id'] : 0;
$canManage = $firmId ? firm_site_can_manage($pdo, $firmId, $lawyerId) : false;
$isOwner = $firm && $firm['member_role'] === 'owner';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    if (!$firmId) {
        $error = is_rtl() ? 'أنشئ مكتباً أولاً من صفحة المكتب.' : 'Create a firm first from the Firm page.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = is_rtl() ? 'هذه العملية متاحة لمالك المكتب أو شريك فقط.' : 'Only a firm owner or partner may do this.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save_branding') {
                $fields = [
                    'tagline_ar' => trim((string)($_POST['tagline_ar'] ?? '')),
                    'tagline_en' => trim((string)($_POST['tagline_en'] ?? '')),
                    'about_ar' => trim((string)($_POST['about_ar'] ?? '')),
                    'about_en' => trim((string)($_POST['about_en'] ?? '')),
                    'practice_areas' => trim((string)($_POST['practice_areas'] ?? '')),
                    'working_hours' => trim((string)($_POST['working_hours'] ?? '')),
                    'website_url' => trim((string)($_POST['website_url'] ?? '')),
                    'meta_title_ar' => trim((string)($_POST['meta_title_ar'] ?? '')),
                    'meta_title_en' => trim((string)($_POST['meta_title_en'] ?? '')),
                    'meta_description_ar' => trim((string)($_POST['meta_description_ar'] ?? '')),
                    'meta_description_en' => trim((string)($_POST['meta_description_en'] ?? '')),
                ];
                if (mb_strlen($fields['tagline_ar']) > 300 || mb_strlen($fields['tagline_en']) > 300
                    || mb_strlen($fields['meta_title_ar']) > 200 || mb_strlen($fields['meta_title_en']) > 200
                    || mb_strlen($fields['meta_description_ar']) > 400 || mb_strlen($fields['meta_description_en']) > 400
                    || mb_strlen($fields['practice_areas']) > 1000 || mb_strlen($fields['working_hours']) > 500
                    || mb_strlen($fields['website_url']) > 255) {
                    throw new RuntimeException(is_rtl() ? 'أحد الحقول يتجاوز الطول المسموح.' : 'A field exceeds the allowed length.');
                }
                if ($fields['website_url'] !== '' && !filter_var($fields['website_url'], FILTER_VALIDATE_URL)) {
                    throw new RuntimeException(is_rtl() ? 'رابط الموقع غير صحيح.' : 'Invalid website URL.');
                }
                $social = [
                    'linkedin' => trim((string)($_POST['social_linkedin'] ?? '')),
                    'twitter' => trim((string)($_POST['social_twitter'] ?? '')),
                ];
                foreach ($social as $v) { if ($v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) throw new RuntimeException(is_rtl() ? 'أحد روابط التواصل غير صحيح.' : 'Invalid social URL.'); }
                $desiredSlug = trim((string)($_POST['public_slug'] ?? ''));
                $slug = firm_site_unique_slug($pdo, $firmId, $desiredSlug !== '' ? $desiredSlug : (string)$firm['name']);
                $isPublic = isset($_POST['is_public']) ? 1 : 0;
                $pdo->prepare('UPDATE law_firms SET tagline_ar=?,tagline_en=?,about_ar=?,about_en=?,practice_areas=?,working_hours=?,website_url=?,social_json=?,meta_title_ar=?,meta_title_en=?,meta_description_ar=?,meta_description_en=?,public_slug=?,is_public=? WHERE id=?')
                    ->execute([...array_values($fields), json_encode($social, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $slug, $isPublic, $firmId]);
                log_action($pdo, 'تحديث موقع المكتب', 'law_firm', $firmId);
                $success = is_rtl() ? 'تم حفظ بيانات الموقع.' : 'Website settings saved.';
            } elseif ($action === 'save_page') {
                $pageId = (int)($_POST['page_id'] ?? 0);
                $titleAr = trim((string)($_POST['title_ar'] ?? ''));
                $titleEn = trim((string)($_POST['title_en'] ?? ''));
                $contentAr = trim((string)($_POST['content_ar'] ?? ''));
                $contentEn = trim((string)($_POST['content_en'] ?? ''));
                $pageType = (string)($_POST['page_type'] ?? 'custom');
                if (!in_array($pageType, ['about', 'services', 'practice_areas', 'privacy', 'custom'], true)) $pageType = 'custom';
                $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
                $sortOrder = max(0, min(999, (int)($_POST['sort_order'] ?? 0)));
                if ($titleAr === '') throw new RuntimeException(is_rtl() ? 'عنوان الصفحة مطلوب.' : 'Page title is required.');
                if (mb_strlen($titleAr) > 255 || mb_strlen($titleEn) > 255) throw new RuntimeException(is_rtl() ? 'العنوان طويل جداً.' : 'Title too long.');
                $slug = firm_site_slugify(trim((string)($_POST['page_slug'] ?? '')) ?: $titleAr);
                if ($slug === '') $slug = 'page-' . ($pageId ?: substr(sha1((string)microtime(true)), 0, 8));
                if ($pageId > 0) {
                    // Confirm the page belongs to this firm before updating — a stale id from
                    // another firm must be a no-op, not a cross-tenant write.
                    $upd = $pdo->prepare('UPDATE firm_pages SET slug=?,page_type=?,title_ar=?,title_en=?,content_ar=?,content_en=?,status=?,sort_order=? WHERE id=? AND firm_id=?');
                    $upd->execute([$slug, $pageType, $titleAr, $titleEn ?: null, $contentAr, $contentEn ?: null, $status, $sortOrder, $pageId, $firmId]);
                    log_action($pdo, 'تحديث صفحة موقع المكتب', 'firm_page', $pageId);
                } else {
                    $ins = $pdo->prepare('INSERT INTO firm_pages (firm_id,slug,page_type,title_ar,title_en,content_ar,content_en,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
                    $ins->execute([$firmId, $slug, $pageType, $titleAr, $titleEn ?: null, $contentAr, $contentEn ?: null, $status, $sortOrder]);
                    log_action($pdo, 'إضافة صفحة إلى موقع المكتب', 'firm_page', (int)$pdo->lastInsertId());
                }
                $success = is_rtl() ? 'تم حفظ الصفحة.' : 'Page saved.';
            } elseif ($action === 'delete_page') {
                $pageId = (int)($_POST['page_id'] ?? 0);
                $pdo->prepare('DELETE FROM firm_pages WHERE id=? AND firm_id=?')->execute([$pageId, $firmId]);
                log_action($pdo, 'حذف صفحة من موقع المكتب', 'firm_page', $pageId);
                $success = is_rtl() ? 'تم حذف الصفحة.' : 'Page deleted.';
            } elseif ($action === 'add_branch') {
                $nameAr = trim((string)($_POST['name_ar'] ?? ''));
                if ($nameAr === '' || mb_strlen($nameAr) > 180) throw new RuntimeException(is_rtl() ? 'اسم الفرع مطلوب.' : 'Branch name is required.');
                $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
                if ($isPrimary) $pdo->prepare('UPDATE firm_branches SET is_primary=0 WHERE firm_id=?')->execute([$firmId]);
                $pdo->prepare('INSERT INTO firm_branches (firm_id,name_ar,name_en,city,address,phone,working_hours,is_primary) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$firmId, $nameAr, trim((string)($_POST['name_en'] ?? '')) ?: null, trim((string)($_POST['city'] ?? '')) ?: null, trim((string)($_POST['address'] ?? '')) ?: null, trim((string)($_POST['phone'] ?? '')) ?: null, trim((string)($_POST['working_hours'] ?? '')) ?: null, $isPrimary]);
                log_action($pdo, 'إضافة فرع', 'firm_branch', (int)$pdo->lastInsertId());
                $success = is_rtl() ? 'تمت إضافة الفرع.' : 'Branch added.';
            } elseif ($action === 'delete_branch') {
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $pdo->prepare('DELETE FROM firm_branches WHERE id=? AND firm_id=?')->execute([$branchId, $firmId]);
                log_action($pdo, 'حذف فرع', 'firm_branch', $branchId);
                $success = is_rtl() ? 'تم حذف الفرع.' : 'Branch removed.';
            } elseif ($action === 'add_department') {
                $nameAr = trim((string)($_POST['name_ar'] ?? ''));
                if ($nameAr === '' || mb_strlen($nameAr) > 180) throw new RuntimeException(is_rtl() ? 'اسم القسم مطلوب.' : 'Department name is required.');
                $managerId = (int)($_POST['manager_lawyer_id'] ?? 0);
                if ($managerId > 0) {
                    // The manager must be an active member of this same firm.
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM law_firm_members WHERE firm_id=? AND lawyer_id=? AND status='active'");
                    $chk->execute([$firmId, $managerId]);
                    if ((int)$chk->fetchColumn() === 0) throw new RuntimeException(is_rtl() ? 'مدير القسم يجب أن يكون عضواً في المكتب.' : 'Department manager must be a firm member.');
                }
                $pdo->prepare('INSERT INTO firm_departments (firm_id,name_ar,name_en,description,manager_lawyer_id) VALUES (?,?,?,?,?)')
                    ->execute([$firmId, $nameAr, trim((string)($_POST['name_en'] ?? '')) ?: null, trim((string)($_POST['description'] ?? '')) ?: null, $managerId ?: null]);
                log_action($pdo, 'إضافة قسم', 'firm_department', (int)$pdo->lastInsertId());
                $success = is_rtl() ? 'تمت إضافة القسم.' : 'Department added.';
            } elseif ($action === 'delete_department') {
                $deptId = (int)($_POST['department_id'] ?? 0);
                $pdo->prepare('DELETE FROM firm_departments WHERE id=? AND firm_id=?')->execute([$deptId, $firmId]);
                log_action($pdo, 'حذف قسم', 'firm_department', $deptId);
                $success = is_rtl() ? 'تم حذف القسم.' : 'Department removed.';
            } elseif ($action === 'add_announcement') {
                $title = trim((string)($_POST['title'] ?? ''));
                $body = trim((string)($_POST['body'] ?? ''));
                $audience = (string)($_POST['audience'] ?? 'all');
                if (!in_array($audience, ['all', 'lawyers', 'staff'], true)) $audience = 'all';
                if ($title === '' || mb_strlen($title) > 255) throw new RuntimeException(is_rtl() ? 'عنوان الإعلان مطلوب.' : 'Announcement title is required.');
                $pdo->prepare("INSERT INTO firm_announcements (firm_id,title,body,audience,status,created_by_lawyer_id,published_at) VALUES (?,?,?,?,'published',?,NOW())")
                    ->execute([$firmId, $title, $body, $audience, $lawyerId]);
                log_action($pdo, 'نشر إعلان داخلي', 'firm_announcement', (int)$pdo->lastInsertId());
                $success = is_rtl() ? 'تم نشر الإعلان.' : 'Announcement published.';
            } elseif ($action === 'delete_announcement') {
                $annId = (int)($_POST['announcement_id'] ?? 0);
                $pdo->prepare('DELETE FROM firm_announcements WHERE id=? AND firm_id=?')->execute([$annId, $firmId]);
                log_action($pdo, 'حذف إعلان داخلي', 'firm_announcement', $annId);
                $success = is_rtl() ? 'تم حذف الإعلان.' : 'Announcement deleted.';
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : (is_rtl() ? 'تعذّر إتمام العملية.' : 'The operation could not be completed.');
            if (!($e instanceof RuntimeException)) error_log('firm-site error: ' . $e->getMessage());
        }
        if (!$error) { header('Location: /lawyer/firm-site.php'); exit; }
    }
}

$pages = $firmId ? firm_site_pages($pdo, $firmId, false) : [];
$branches = $firmId ? firm_site_branches($pdo, $firmId, false) : [];
$departments = $firmId ? firm_site_departments($pdo, $firmId, false) : [];
$announcements = $firmId ? firm_site_announcements($pdo, $firmId, 'all', false) : [];
$members = [];
if ($firmId) {
    $mq = $pdo->prepare("SELECT l.id, l.full_name, m.member_role FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE m.firm_id=? AND m.status='active' ORDER BY l.full_name");
    $mq->execute([$firmId]); $members = $mq->fetchAll();
}
$social = $firmId && !empty($firm['social_json']) ? (json_decode((string)$firm['social_json'], true) ?: []) : [];

$activeLawyer = 'firm-site';
$pageTitle = is_rtl() ? 'موقع المكتب والهيكل التنظيمي' : 'Firm website & office structure';
$breadcrumbs = [[is_rtl() ? 'المكتب' : 'Firm', '/lawyer/firm.php'], [is_rtl() ? 'الموقع الإلكتروني' : 'Website', null]];
require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="app-content">
<?php if (!$firmId): ?>
  <?= ui_empty_state(
        is_rtl() ? 'لا يوجد مكتب بعد' : 'No firm yet',
        is_rtl() ? 'أنشئ مكتباً أولاً لإدارة موقعه الإلكتروني.' : 'Create a firm first to manage its website.',
        'landmark',
        ['<a class="btn btn-primary" href="/lawyer/firm.php">' . (is_rtl() ? 'إنشاء مكتب' : 'Create a firm') . '</a>']
      ) ?>
<?php else: ?>
  <?= ui_page_head($pageTitle, is_rtl() ? 'تحرير موقع المكتب العام وهيكل الفروع والأقسام.' : 'Edit the public site, branches and departments.', [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error" role="alert"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success" role="status"><?= ui_e($success) ?></div><?php endif; ?>
  <?php if (!$canManage): ?>
    <div class="form-alert"><?= is_rtl() ? 'لديك صلاحية العرض فقط. التعديل متاح لمالك المكتب أو الشريك.' : 'Read-only view. Editing is available to the firm owner or partner.' ?></div>
  <?php endif; ?>

  <?php if (!empty($firm['is_public']) && !empty($firm['public_slug'])): ?>
    <p class="firm-more">
      <a class="btn btn-outline" target="_blank" rel="noopener" href="/firm.php?firm=<?= urlencode((string)$firm['public_slug']) ?>">
        <?= is_rtl() ? 'عرض الموقع العام ↗' : 'View public site ↗' ?>
      </a>
      <code class="firm-url"><?= ui_e('/firm.php?firm=' . $firm['public_slug']) ?></code>
    </p>
  <?php endif; ?>

  <!-- ============ Branding & SEO ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الهوية والمحتوى التعريفي' : 'Branding & profile content' ?></h2>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_branding">
      <div class="grid-2">
        <label><?= is_rtl() ? 'الشعار النصي (عربي)' : 'Tagline (AR)' ?><input type="text" name="tagline_ar" maxlength="300" value="<?= ui_e($firm['tagline_ar'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
        <label><?= is_rtl() ? 'الشعار النصي (إنجليزي)' : 'Tagline (EN)' ?><input type="text" name="tagline_en" maxlength="300" value="<?= ui_e($firm['tagline_en'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
      </div>
      <div class="grid-2">
        <label><?= is_rtl() ? 'نبذة عن المكتب (عربي)' : 'About (AR)' ?><textarea name="about_ar" rows="5" <?= $canManage ? '' : 'disabled' ?>><?= ui_e($firm['about_ar'] ?? '') ?></textarea></label>
        <label><?= is_rtl() ? 'نبذة عن المكتب (إنجليزي)' : 'About (EN)' ?><textarea name="about_en" rows="5" <?= $canManage ? '' : 'disabled' ?>><?= ui_e($firm['about_en'] ?? '') ?></textarea></label>
      </div>
      <label><?= is_rtl() ? 'مجالات الممارسة (سطر أو فاصلة لكل مجال)' : 'Practice areas (one per line or comma)' ?><textarea name="practice_areas" rows="3" <?= $canManage ? '' : 'disabled' ?>><?= ui_e($firm['practice_areas'] ?? '') ?></textarea></label>
      <div class="grid-2">
        <label><?= is_rtl() ? 'ساعات العمل' : 'Working hours' ?><input type="text" name="working_hours" maxlength="500" value="<?= ui_e($firm['working_hours'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
        <label><?= is_rtl() ? 'الموقع الإلكتروني' : 'Website URL' ?><input type="url" name="website_url" maxlength="255" value="<?= ui_e($firm['website_url'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
      </div>
      <div class="grid-2">
        <label>LinkedIn<input type="url" name="social_linkedin" value="<?= ui_e($social['linkedin'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
        <label>X / Twitter<input type="url" name="social_twitter" value="<?= ui_e($social['twitter'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
      </div>
      <div class="grid-2">
        <label><?= is_rtl() ? 'عنوان SEO (عربي)' : 'Meta title (AR)' ?><input type="text" name="meta_title_ar" maxlength="200" value="<?= ui_e($firm['meta_title_ar'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
        <label><?= is_rtl() ? 'عنوان SEO (إنجليزي)' : 'Meta title (EN)' ?><input type="text" name="meta_title_en" maxlength="200" value="<?= ui_e($firm['meta_title_en'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
      </div>
      <div class="grid-2">
        <label><?= is_rtl() ? 'وصف SEO (عربي)' : 'Meta description (AR)' ?><textarea name="meta_description_ar" rows="2" maxlength="400" <?= $canManage ? '' : 'disabled' ?>><?= ui_e($firm['meta_description_ar'] ?? '') ?></textarea></label>
        <label><?= is_rtl() ? 'وصف SEO (إنجليزي)' : 'Meta description (EN)' ?><textarea name="meta_description_en" rows="2" maxlength="400" <?= $canManage ? '' : 'disabled' ?>><?= ui_e($firm['meta_description_en'] ?? '') ?></textarea></label>
      </div>
      <div class="grid-2">
        <label><?= is_rtl() ? 'الرابط العام (slug)' : 'Public slug' ?><input type="text" name="public_slug" maxlength="120" value="<?= ui_e($firm['public_slug'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></label>
        <label class="check-label"><input type="checkbox" name="is_public" value="1" <?= !empty($firm['is_public']) ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>> <?= is_rtl() ? 'نشر الموقع العام' : 'Publish public site' ?></label>
      </div>
      <?php if ($canManage): ?><button type="submit" class="btn btn-primary"><?= is_rtl() ? 'حفظ' : 'Save' ?></button><?php endif; ?>
    </form>
  </section>

  <!-- ============ Pages ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الصفحات المنشورة' : 'Published pages' ?></h2>
    <?php if ($pages): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'العنوان' : 'Title' ?></th><th><?= is_rtl() ? 'النوع' : 'Type' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pages as $p): ?>
          <tr>
            <td><?= ui_e($p['title_ar']) ?><br><span class="muted"><?= ui_e($p['slug']) ?></span></td>
            <td><?= ui_e($p['page_type']) ?></td>
            <td><?= ui_badge($p['status'] === 'published' ? 'active' : 'pending', $p['status'] === 'published' ? (is_rtl() ? 'منشورة' : 'Published') : (is_rtl() ? 'مسودة' : 'Draft')) ?></td>
            <td>
              <?php if ($canManage): ?>
              <form method="post" class="inline-form" onsubmit="return confirm('<?= is_rtl() ? 'حذف هذه الصفحة؟' : 'Delete this page?' ?>')">
                <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_page">
                <input type="hidden" name="page_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><?= is_rtl() ? 'حذف' : 'Delete' ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد صفحات' : 'No pages', is_rtl() ? 'أضف صفحة "من نحن" أو "الخدمات" أو أي صفحة مخصصة.' : 'Add an About, Services, or custom page.') ?>
    <?php endif; ?>
    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة / تعديل صفحة' : 'Add / edit a page' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_page">
        <div class="grid-2">
          <label><?= is_rtl() ? 'العنوان (عربي)' : 'Title (AR)' ?><input type="text" name="title_ar" maxlength="255" required></label>
          <label><?= is_rtl() ? 'العنوان (إنجليزي)' : 'Title (EN)' ?><input type="text" name="title_en" maxlength="255"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'النوع' : 'Type' ?>
            <select name="page_type"><option value="about"><?= is_rtl() ? 'من نحن' : 'About' ?></option><option value="services"><?= is_rtl() ? 'الخدمات' : 'Services' ?></option><option value="practice_areas"><?= is_rtl() ? 'مجالات الممارسة' : 'Practice areas' ?></option><option value="privacy"><?= is_rtl() ? 'الخصوصية' : 'Privacy' ?></option><option value="custom"><?= is_rtl() ? 'مخصص' : 'Custom' ?></option></select>
          </label>
          <label><?= is_rtl() ? 'الحالة' : 'Status' ?><select name="status"><option value="draft"><?= is_rtl() ? 'مسودة' : 'Draft' ?></option><option value="published"><?= is_rtl() ? 'منشورة' : 'Published' ?></option></select></label>
        </div>
        <label><?= is_rtl() ? 'الرابط (slug) — اختياري' : 'Slug — optional' ?><input type="text" name="page_slug" maxlength="120"></label>
        <div class="grid-2">
          <label><?= is_rtl() ? 'المحتوى (عربي)' : 'Content (AR)' ?><textarea name="content_ar" rows="5"></textarea></label>
          <label><?= is_rtl() ? 'المحتوى (إنجليزي)' : 'Content (EN)' ?><textarea name="content_en" rows="5"></textarea></label>
        </div>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'حفظ الصفحة' : 'Save page' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Branches ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الفروع' : 'Branches' ?></h2>
    <?php if ($branches): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'الفرع' : 'Branch' ?></th><th><?= is_rtl() ? 'المدينة' : 'City' ?></th><th><?= is_rtl() ? 'الهاتف' : 'Phone' ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($branches as $b): ?>
          <tr>
            <td><?= ui_e($b['name_ar']) ?><?= !empty($b['is_primary']) ? ' <span class="badge">' . (is_rtl() ? 'رئيسي' : 'Primary') . '</span>' : '' ?></td>
            <td><?= ui_e((string)$b['city']) ?></td>
            <td><?= ui_e((string)$b['phone']) ?></td>
            <td><?php if ($canManage): ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_branch"><input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>"><button type="submit" class="btn btn-danger btn-sm"><?= is_rtl() ? 'حذف' : 'Delete' ?></button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد فروع' : 'No branches') ?><?php endif; ?>
    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة فرع' : 'Add a branch' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_branch">
        <div class="grid-2">
          <label><?= is_rtl() ? 'اسم الفرع (عربي)' : 'Name (AR)' ?><input type="text" name="name_ar" maxlength="180" required></label>
          <label><?= is_rtl() ? 'اسم الفرع (إنجليزي)' : 'Name (EN)' ?><input type="text" name="name_en" maxlength="180"></label>
        </div>
        <div class="grid-2">
          <label><?= is_rtl() ? 'المدينة' : 'City' ?><input type="text" name="city" maxlength="100"></label>
          <label><?= is_rtl() ? 'الهاتف' : 'Phone' ?><input type="text" name="phone" maxlength="30"></label>
        </div>
        <label><?= is_rtl() ? 'العنوان' : 'Address' ?><input type="text" name="address" maxlength="255"></label>
        <label><?= is_rtl() ? 'ساعات العمل' : 'Working hours' ?><input type="text" name="working_hours" maxlength="500"></label>
        <label class="check-label"><input type="checkbox" name="is_primary" value="1"> <?= is_rtl() ? 'الفرع الرئيسي' : 'Primary branch' ?></label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إضافة الفرع' : 'Add branch' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Departments ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الأقسام' : 'Departments' ?></h2>
    <?php if ($departments): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'القسم' : 'Department' ?></th><th><?= is_rtl() ? 'المدير' : 'Manager' ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($departments as $d): $mgr = ''; foreach ($members as $m) { if ((int)$m['id'] === (int)$d['manager_lawyer_id']) { $mgr = $m['full_name']; break; } } ?>
          <tr>
            <td><?= ui_e($d['name_ar']) ?><br><span class="muted"><?= ui_e((string)$d['description']) ?></span></td>
            <td><?= ui_e($mgr) ?></td>
            <td><?php if ($canManage): ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_department"><input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>"><button type="submit" class="btn btn-danger btn-sm"><?= is_rtl() ? 'حذف' : 'Delete' ?></button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد أقسام' : 'No departments') ?><?php endif; ?>
    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة قسم' : 'Add a department' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_department">
        <div class="grid-2">
          <label><?= is_rtl() ? 'اسم القسم (عربي)' : 'Name (AR)' ?><input type="text" name="name_ar" maxlength="180" required></label>
          <label><?= is_rtl() ? 'اسم القسم (إنجليزي)' : 'Name (EN)' ?><input type="text" name="name_en" maxlength="180"></label>
        </div>
        <label><?= is_rtl() ? 'الوصف' : 'Description' ?><input type="text" name="description" maxlength="500"></label>
        <label><?= is_rtl() ? 'مدير القسم' : 'Manager' ?>
          <select name="manager_lawyer_id">
            <option value="0"><?= is_rtl() ? 'بدون' : 'None' ?></option>
            <?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ui_e($m['full_name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إضافة القسم' : 'Add department' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ============ Announcements (internal) ============ -->
  <section class="dash-card">
    <h2><?= is_rtl() ? 'الإعلانات الداخلية' : 'Internal announcements' ?></h2>
    <p class="muted"><?= is_rtl() ? 'إعلانات داخلية لأعضاء المكتب فقط — لا تُنشر علناً.' : 'Internal to firm members only — never shown publicly.' ?></p>
    <?php if ($announcements): ?>
      <div class="firm-ann-list">
        <?php foreach ($announcements as $a): ?>
          <article class="firm-ann">
            <header><strong><?= ui_e($a['title']) ?></strong> <span class="muted"><?= ui_e(ui_date($a['published_at'] ?? $a['created_at'], true)) ?></span></header>
            <?php if (!empty($a['body'])): ?><p><?= nl2br(ui_e((string)$a['body'])) ?></p><?php endif; ?>
            <?php if ($canManage): ?>
              <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="announcement_id" value="<?= (int)$a['id'] ?>"><button type="submit" class="btn btn-danger btn-sm"><?= is_rtl() ? 'حذف' : 'Delete' ?></button></form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد إعلانات' : 'No announcements') ?><?php endif; ?>
    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'نشر إعلان' : 'Publish an announcement' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_announcement">
        <label><?= is_rtl() ? 'العنوان' : 'Title' ?><input type="text" name="title" maxlength="255" required></label>
        <label><?= is_rtl() ? 'النص' : 'Body' ?><textarea name="body" rows="4"></textarea></label>
        <label><?= is_rtl() ? 'الجمهور' : 'Audience' ?><select name="audience"><option value="all"><?= is_rtl() ? 'الجميع' : 'All' ?></option><option value="lawyers"><?= is_rtl() ? 'المحامون' : 'Lawyers' ?></option><option value="staff"><?= is_rtl() ? 'الموظفون' : 'Staff' ?></option></select></label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'نشر' : 'Publish' ?></button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
