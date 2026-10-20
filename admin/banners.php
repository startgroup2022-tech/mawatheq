<?php
/**
 * إدارة بانرات الصفحة الرئيسية (سلايدر تطبيق الجوال).
 *
 * The customer app's home slider is fed entirely from `home_banners` (upgrade_v64.sql).
 * Nothing is seeded here: a fresh install shows an empty slider in the app, because
 * inventing a promotion is exactly what the brief forbids. The operator adds the real
 * ones.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/banners.php';

$error = null;
$success = null;
$editingBanner = null;

$linkTypes = [
    'none'      => 'بدون رابط (صورة فقط)',
    'category'  => 'فتح تخصص',
    'specialty' => 'بدء الرحلة من تخصص',
    'url'       => 'رابط خارجي (https)',
];

/** التخصصات المتاحة كوجهة، بصيغة العميل لا التصنيف الداخلي. */
$categoryOptions = [];
try {
    require_once __DIR__ . '/../includes/legal_journey.php';
    foreach (legal_journey_specialties($pdo, '') as $row) {
        // legal_journey_specialties() already returns customer-facing text only, so the
        // operator picks from the same labels the customer sees — not the taxonomy.
        $categoryOptions[] = [
            'id'   => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    error_log('admin/banners category options: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_banner') {
            $bannerId   = (int)($_POST['banner_id'] ?? 0);
            $titleAr    = trim($_POST['title_ar'] ?? '');
            $titleEn    = trim($_POST['title_en'] ?? '');
            $subtitleAr = trim($_POST['subtitle_ar'] ?? '');
            $subtitleEn = trim($_POST['subtitle_en'] ?? '');
            $altAr      = trim($_POST['alt_ar'] ?? '');
            $altEn      = trim($_POST['alt_en'] ?? '');
            $linkType   = (string)($_POST['link_type'] ?? 'none');
            // The form offers a specialty dropdown and a URL box side by side. The URL
            // wins only when the operator chose the external-link destination, so a
            // leftover value in the other field can never silently retarget a banner.
            $linkTarget = trim((string)($_POST['link_target'] ?? ''));
            if ($linkType === 'url' && trim((string)($_POST['link_target_url'] ?? '')) !== '') {
                $linkTarget = trim((string)$_POST['link_target_url']);
            }
            $status     = in_array($_POST['status'] ?? '', ['active', 'hidden'], true) ? $_POST['status'] : 'active';
            $sortOrder  = (int)($_POST['sort_order'] ?? 0);
            $startsAt   = trim($_POST['starts_at'] ?? '');
            $endsAt     = trim($_POST['ends_at'] ?? '');

            if ($titleAr === '') {
                $error = 'عنوان البانر بالعربية مطلوب.';
            } elseif ($linkType === 'url' && $linkTarget === '') {
                $error = 'أدخل الرابط الخارجي أو اختر «بدون رابط».';
            } else {
                // Existing image is reused on an edit unless a new file is uploaded, so
                // editing the title of a banner does not silently drop its image.
                $imagePath = null;
                $existing  = null;
                if ($bannerId > 0) {
                    $stmt = $pdo->prepare('SELECT image_path FROM home_banners WHERE id=?');
                    $stmt->execute([$bannerId]);
                    $existing = $stmt->fetchColumn();
                }

                $file = $_FILES['image'] ?? [];
                $hasUpload = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

                if ($hasUpload) {
                    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                        throw new RuntimeException('تعذّر رفع الصورة. أعد المحاولة.');
                    }
                    if ((int)$file['size'] < 1 || (int)$file['size'] > MAX_UPLOAD_SIZE_MB * 1024 * 1024) {
                        throw new RuntimeException('حجم الصورة غير مسموح؛ الحد ' . MAX_UPLOAD_SIZE_MB . ' ميغابايت.');
                    }
                    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                    [$ok, $detail] = banner_image_validate($file['tmp_name'], $extension);
                    if (!$ok) {
                        throw new RuntimeException($detail);
                    }
                    $stored = bin2hex(random_bytes(24)) . '.' . $extension;
                    $dir = banner_storage_dir();
                    if ($dir === null) {
                        throw new RuntimeException('تعذّر تهيئة مجلد تخزين صور البانرات.');
                    }
                    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $stored)) {
                        throw new RuntimeException('تعذّر حفظ الصورة في التخزين.');
                    }
                    // 0660, not 0640: the file must stay deletable by whichever account
                    // the console runs as, which may not be the one that uploaded it.
                    @chmod($dir . DIRECTORY_SEPARATOR . $stored, 0660);
                    $imagePath = 'uploads/banners/' . $stored;
                } elseif ($existing) {
                    $imagePath = (string)$existing;
                } else {
                    $error = 'صورة البانر مطلوبة.';
                }

                if ($error === null) {
                    [$destOk, $destMsg] = banner_destination_validate($pdo, $linkType, $linkTarget);
                    if (!$destOk) {
                        // The freshly uploaded file is removed so a rejected banner does
                        // not leave an orphan image in the storage directory.
                        if ($hasUpload && $imagePath) {
                            @unlink(dirname(__DIR__) . '/' . $imagePath);
                        }
                        $error = $destMsg;
                    }
                }

                if ($error === null) {
                    $window = [
                        $startsAt !== '' ? $startsAt : null,
                        $endsAt   !== '' ? $endsAt   : null,
                    ];
                    if ($bannerId > 0) {
                        $pdo->prepare(
                            'UPDATE home_banners SET title_ar=?, title_en=?, subtitle_ar=?, subtitle_en=?,
                                    image_path=?, alt_ar=?, alt_en=?, link_type=?, link_target=?,
                                    status=?, sort_order=?, starts_at=?, ends_at=? WHERE id=?'
                        )->execute([
                            $titleAr, $titleEn, $subtitleAr ?: null, $subtitleEn ?: null,
                            $imagePath, $altAr ?: null, $altEn ?: null, $linkType,
                            $linkType === 'none' ? null : $linkTarget,
                            $status, $sortOrder, $window[0], $window[1], $bannerId,
                        ]);
                        log_action($pdo, "تعديل بانر '" . $titleAr . "'", 'home_banner', $bannerId);
                        $success = 'تم تحديث البانر. سيظهر التغيير في التطبيق فوراً.';
                    } else {
                        $pdo->prepare(
                            'INSERT INTO home_banners (title_ar, title_en, subtitle_ar, subtitle_en,
                                    image_path, alt_ar, alt_en, link_type, link_target,
                                    status, sort_order, starts_at, ends_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                        )->execute([
                            $titleAr, $titleEn, $subtitleAr ?: null, $subtitleEn ?: null,
                            $imagePath, $altAr ?: null, $altEn ?: null, $linkType,
                            $linkType === 'none' ? null : $linkTarget,
                            $status, $sortOrder, $window[0], $window[1],
                        ]);
                        log_action($pdo, "إضافة بانر '" . $titleAr . "'", 'home_banner', (int)$pdo->lastInsertId());
                        $success = 'تمت إضافة البانر. سيظهر في سلايدر التطبيق حسب الترتيب.';
                    }
                }
            }
        } elseif ($action === 'toggle_status') {
            $bannerId = (int)$_POST['banner_id'];
            $pdo->prepare("UPDATE home_banners SET status = IF(status='active','hidden','active') WHERE id=?")->execute([$bannerId]);
            log_action($pdo, 'تفعيل/تعطيل بانر #' . $bannerId, 'home_banner', $bannerId);
            header('Location: /admin/banners.php'); exit;
        } elseif ($action === 'delete_banner') {
            $bannerId = (int)$_POST['banner_id'];
            // The row's image is removed with it: an orphaned file under uploads/ is
            // both a storage leak and a live URL nobody is tracking any more.
            $stmt = $pdo->prepare('SELECT image_path FROM home_banners WHERE id=?');
            $stmt->execute([$bannerId]);
            $path = (string)($stmt->fetchColumn() ?: '');
            $pdo->prepare('DELETE FROM home_banners WHERE id=?')->execute([$bannerId]);
            if ($path !== '' && str_starts_with($path, 'uploads/banners/')) {
                @unlink(dirname(__DIR__) . '/' . $path);
            }
            log_action($pdo, 'حذف بانر #' . $bannerId, 'home_banner', $bannerId);
            $success = 'تم حذف البانر.';
        }
    } catch (PDOException $e) {
        error_log('Admin banner action failed: ' . $e->getMessage());
        $error = 'تعذّر حفظ التغيير. تأكد من تطبيق الترقية upgrade_v64.sql، ثم راجع سجل أخطاء PHP.';
        $editingBanner = isset($bannerId) ? (int)$bannerId : null;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$banners = [];
try {
    $banners = $pdo->query('SELECT * FROM home_banners ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    error_log('admin/banners list: ' . $e->getMessage());
    $error = $error ?: 'جدول البانرات غير موجود. طبّق الترقية database/upgrade_v64.sql أولاً.';
}

$activeAdmin = 'banners';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>بانرات الصفحة الرئيسية <span class="count-badge"><?= count($banners) ?></span></h2>
    <button type="button" class="btn btn-primary" onclick="openModal('bannerModal-new')">+ إضافة بانر</button>
  </div>

  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="dash-card" style="color:var(--text-muted);font-size:.85rem">
    تظهر البانرات في سلايدر التطبيق أعلى الصفحة الرئيسية، مرتّبة بحسب «الترتيب».
    البانر خارج نافذة الجدولة (من/إلى) لا يظهر، ويمكنك إيقافه مؤقتاً بالتعطيل.
  </div>

  <?php if (empty($banners)): ?>
    <div class="dash-card" style="text-align:center;color:var(--text-muted)">
      لا توجد بانرات بعد. حتى تضيف بانراً حقيقياً، يعرض التطبيق حالة فارغة في السلايدر
      وينتقل مباشرة إلى الأقسام تحته — لا نعرض بانرات وهمية.
    </div>
  <?php endif; ?>

  <?php foreach ($banners as $b): ?>
  <div class="dash-card">
    <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
      <img src="/<?= htmlspecialchars($b['image_path']) ?>" alt=""
           style="width:180px;height:90px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">
      <div style="flex:1;min-width:220px">
        <h3 style="margin-bottom:4px">
          <?= htmlspecialchars($b['title_ar']) ?>
          <?php if ($b['title_en'] !== ''): ?><span style="color:var(--text-muted);font-weight:400;font-size:.85rem">(<?= htmlspecialchars($b['title_en']) ?>)</span><?php endif; ?>
          <span class="status-pill status-<?= $b['status']==='active' ? 'confirmed' : 'pending' ?>"><?= $b['status']==='active' ? 'مفعّل' : 'معطّل' ?></span>
        </h3>
        <?php if ($b['subtitle_ar']): ?><p style="margin:0;color:var(--text-muted);font-size:.85rem"><?= htmlspecialchars($b['subtitle_ar']) ?></p><?php endif; ?>
        <p style="margin:6px 0 0;color:var(--text-muted);font-size:.8rem">
          الوجهة: <?= htmlspecialchars($linkTypes[$b['link_type']] ?? $b['link_type']) ?>
          <?= $b['link_target'] ? ' → ' . htmlspecialchars($b['link_target']) : '' ?>
          · الترتيب: <?= (int)$b['sort_order'] ?>
          <?php if ($b['starts_at']): ?> · من: <?= htmlspecialchars($b['starts_at']) ?><?php endif; ?>
          <?php if ($b['ends_at']): ?> · إلى: <?= htmlspecialchars($b['ends_at']) ?><?php endif; ?>
        </p>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:14px;border-top:1px solid var(--border);padding-top:12px">
      <button type="button" class="btn btn-outline btn-sm" onclick="openModal('bannerModal-<?= $b['id'] ?>')">تعديل</button>
      <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
        <button class="btn btn-outline btn-sm" type="submit"><?= $b['status']==='active' ? 'تعطيل' : 'تفعيل' ?></button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('حذف هذا البانر وصورته نهائياً؟')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_banner"><input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
        <button class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger)" type="submit">حذف</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>

  <?php
  // Shared field block, rendered once per modal. Kept as a closure so the edit and
  // "new" forms cannot drift apart.
  $renderFields = function (array $b) use ($linkTypes, $categoryOptions): void { ?>
      <label style="display:block;margin-bottom:6px;font-size:.85rem">العنوان (عربي) *</label>
      <input type="text" name="title_ar" required maxlength="255" value="<?= htmlspecialchars($b['title_ar']) ?>"
             style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">

      <label style="display:block;margin-bottom:6px;font-size:.85rem">العنوان (إنجليزي)</label>
      <input type="text" name="title_en" maxlength="255" value="<?= htmlspecialchars($b['title_en']) ?>"
             style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">

      <label style="display:block;margin-bottom:6px;font-size:.85rem">الوصف (عربي)</label>
      <input type="text" name="subtitle_ar" maxlength="500" value="<?= htmlspecialchars((string)$b['subtitle_ar']) ?>"
             style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">

      <label style="display:block;margin-bottom:6px;font-size:.85rem">الوصف (إنجليزي)</label>
      <input type="text" name="subtitle_en" maxlength="500" value="<?= htmlspecialchars((string)$b['subtitle_en']) ?>"
             style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">

      <label style="display:block;margin-bottom:6px;font-size:.85rem">الصورة <?= $b['id'] ? '(اتركها فارغة للإبقاء على الحالية)' : '*' ?></label>
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp" <?= $b['id'] ? '' : 'required' ?>
             style="width:100%;margin-bottom:12px">
      <p style="margin:-6px 0 12px;color:var(--text-muted);font-size:.78rem">
        المقاس المقترح 1200×500 (نسبة 12:5). الصيغ: JPG / PNG / WEBP.
      </p>

      <label style="display:block;margin-bottom:6px;font-size:.85rem">نص بديل (عربي) — لقارئ الشاشة</label>
      <input type="text" name="alt_ar" maxlength="255" value="<?= htmlspecialchars((string)$b['alt_ar']) ?>"
             style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">

      <label style="display:block;margin-bottom:6px;font-size:.85rem">نص بديل (إنجليزي)</label>
      <input type="text" name="alt_en" maxlength="255" value="<?= htmlspecialchars((string)$b['alt_en']) ?>"
             style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">

      <label style="display:block;margin-bottom:6px;font-size:.85rem">الوجهة عند النقر</label>
      <select name="link_type" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">
        <?php foreach ($linkTypes as $value => $label): ?>
          <option value="<?= $value ?>"<?= $b['link_type'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>

      <label style="display:block;margin-bottom:6px;font-size:.85rem">هدف الوجهة</label>
      <?php if ($categoryOptions): ?>
        <select name="link_target" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px">
          <option value="">— بدون —</option>
          <?php foreach ($categoryOptions as $c): ?>
            <option value="<?= $c['id'] ?>"<?= (string)$b['link_target'] === (string)$c['id'] ? ' selected' : '' ?>>
              #<?= $c['id'] ?> — <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="link_target_url" placeholder="أو رابط خارجي https://…" maxlength="500"
               value="<?= $b['link_type'] === 'url' ? htmlspecialchars((string)$b['link_target']) : '' ?>"
               style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">
      <?php else: ?>
        <input type="text" name="link_target" maxlength="500" value="<?= htmlspecialchars((string)$b['link_target']) ?>"
               placeholder="معرّف التخصص أو رابط https://…"
               style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">
      <?php endif; ?>
      <p style="margin:-6px 0 12px;color:var(--text-muted);font-size:.78rem">
        «فتح تخصص» و«بدء الرحلة من تخصص» يستخدمان قائمة التخصصات أعلاه؛ «رابط خارجي» يفتح في متصفح الجهاز.
      </p>

      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <div style="flex:1;min-width:120px">
          <label style="display:block;margin-bottom:6px;font-size:.85rem">الترتيب</label>
          <input type="number" name="sort_order" value="<?= (int)$b['sort_order'] ?>"
                 style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
        </div>
        <div style="flex:1;min-width:120px">
          <label style="display:block;margin-bottom:6px;font-size:.85rem">الحالة</label>
          <select name="status" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
            <option value="active"<?= $b['status'] === 'active' ? ' selected' : '' ?>>مفعّل</option>
            <option value="hidden"<?= $b['status'] === 'hidden' ? ' selected' : '' ?>>معطّل</option>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
        <div style="flex:1;min-width:150px">
          <label style="display:block;margin-bottom:6px;font-size:.85rem">يبدأ من (اختياري)</label>
          <input type="datetime-local" name="starts_at" value="<?= $b['starts_at'] ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$b['starts_at']))) : '' ?>"
                 style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
        </div>
        <div style="flex:1;min-width:150px">
          <label style="display:block;margin-bottom:6px;font-size:.85rem">ينتهي في (اختياري)</label>
          <input type="datetime-local" name="ends_at" value="<?= $b['ends_at'] ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$b['ends_at']))) : '' ?>"
                 style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
        </div>
      </div>
  <?php };

  foreach ($banners as $b): ?>
  <div class="modal-backdrop<?= $editingBanner === (int)$b['id'] ? ' is-open' : '' ?>" id="bannerModal-<?= $b['id'] ?>">
    <form class="modal-form" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="button" class="modal-close" onclick="closeModal('bannerModal-<?= $b['id'] ?>')">✕</button>
      <input type="hidden" name="action" value="save_banner">
      <input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
      <h3 style="margin-bottom:14px">تعديل بانر: <?= htmlspecialchars($b['title_ar']) ?></h3>
      <?php $renderFields($b); ?>
      <button class="btn btn-primary btn-block" type="submit" style="margin-top:14px">حفظ التعديلات</button>
    </form>
  </div>
  <?php endforeach; ?>

  <div class="modal-backdrop<?= $editingBanner === 0 ? ' is-open' : '' ?>" id="bannerModal-new">
    <form class="modal-form" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="button" class="modal-close" onclick="closeModal('bannerModal-new')">✕</button>
      <input type="hidden" name="action" value="save_banner">
      <input type="hidden" name="banner_id" value="0">
      <h3 style="margin-bottom:14px">إضافة بانر جديد</h3>
      <?php $renderFields([
        'id' => 0, 'title_ar' => '', 'title_en' => '', 'subtitle_ar' => '', 'subtitle_en' => '',
        'alt_ar' => '', 'alt_en' => '', 'link_type' => 'none', 'link_target' => '',
        'status' => 'active', 'sort_order' => count($banners), 'starts_at' => null, 'ends_at' => null,
      ]); ?>
      <button class="btn btn-primary btn-block" type="submit" style="margin-top:14px">إضافة البانر</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
