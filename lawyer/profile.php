<?php
$activeLawyer = 'profile';
require_once __DIR__ . '/includes/lawyer_header.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/specialization_field.php';
require_once __DIR__ . '/../includes/profile_completion.php';
require_once __DIR__ . '/../includes/jurisdiction.php';
$lawyerId = $_SESSION['lawyer_id'];

$stmt = $pdo->prepare('SELECT * FROM lawyers WHERE id=?');
$stmt->execute([$lawyerId]);
$lawyer = $stmt->fetch();
$saved = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phoneResult = validate_phone($_POST['phone_dial'] ?? '+966', $_POST['phone_local'] ?? '');
    if (!$phoneResult['ok']) {
        $error = $phoneResult['error'];
    } elseif (trim($_POST['specialization'] ?? '') === '') {
        $error = is_rtl() ? 'يرجى اختيار التخصص القانوني.' : 'Please select your legal specialization.';
    } else {
        // رفع صورة شخصية (اختياري)
        $photoPath = $lawyer['photo_path'];
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','webp'];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed) || $_FILES['photo']['size'] > 3 * 1024 * 1024) {
                $error = is_rtl() ? 'صورة غير صالحة (jpg/png/webp بحد أقصى 3 ميغابايت).' : 'Invalid photo (jpg/png/webp, max 3MB).';
            } else {
                if (!is_dir(LAWYER_PHOTO_DIR)) mkdir(LAWYER_PHOTO_DIR, 0755, true);
                $storedName = 'lawyer_' . $lawyerId . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], LAWYER_PHOTO_DIR . $storedName)) {
                    $photoPath = 'uploads/lawyer-photos/' . $storedName;
                }
            }
        }

        if (!$error) {
            $pdo->prepare('UPDATE lawyers SET full_name=?, phone=?, specialization=?, bio=?, years_experience=?, hourly_rate=?, license_number=?, qualifications=?, social_linkedin=?, social_twitter=?, social_website=?, photo_path=?, jurisdiction=? WHERE id=?')
                ->execute([
                    trim($_POST['full_name']), $phoneResult['value'], trim($_POST['specialization']), trim($_POST['bio'] ?? ''),
                    (int)$_POST['years_experience'], (float)$_POST['hourly_rate'], trim($_POST['license_number'] ?? ''),
                    trim($_POST['qualifications'] ?? ''), trim($_POST['social_linkedin'] ?? ''), trim($_POST['social_twitter'] ?? ''),
                    trim($_POST['social_website'] ?? ''), $photoPath, $_POST['jurisdiction'] ?? 'السعودية', $lawyerId
                ]);
            if (!empty($_POST['new_password'])) {
                $pdo->prepare('UPDATE lawyers SET password_hash=? WHERE id=?')
                    ->execute([password_hash($_POST['new_password'], PASSWORD_BCRYPT), $lawyerId]);
            }
            $_SESSION['lawyer_name'] = $_POST['full_name'];
            $saved = true;
        }
    }
    $stmt->execute([$lawyerId]);
    $lawyer = $stmt->fetch();
}

$phoneParts = split_phone($lawyer['phone'] ?? '');
?>
<div class="app-content">
  <h2><?= is_rtl() ? 'ملفي الشخصي' : 'My Profile' ?></h2>

  <?php render_completion_card($lawyer); ?>

  <?php if ($saved): ?><div class="form-alert success"><?= is_rtl() ? 'تم الحفظ بنجاح.' : 'Saved successfully.' ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form class="dash-card" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <div class="form-section-title"><?= is_rtl() ? 'البيانات الأساسية' : 'Basic info' ?></div>
    <div class="profile-photo-row">
      <img class="profile-photo-preview" src="<?= !empty($lawyer['photo_path']) ? '/' . htmlspecialchars($lawyer['photo_path']) : '/assets/img/avatar-placeholder.svg' ?>" alt="" onerror="this.style.display='none'">
      <div class="field" style="flex:1">
        <label><?= is_rtl() ? 'الصورة الشخصية' : 'Profile photo' ?></label>
        <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
        <p class="field-hint"><?= is_rtl() ? 'jpg / png / webp — بحد أقصى 3 ميغابايت.' : 'jpg / png / webp — max 3MB.' ?></p>
      </div>
    </div>

    <div class="grid-2">
      <div class="field"><label><?= is_rtl() ? 'الاسم الكامل' : 'Full name' ?></label><input class="pro-input" type="text" name="full_name" value="<?= htmlspecialchars($lawyer['full_name']) ?>"></div>
      <div class="field">
        <label><?= is_rtl() ? 'رقم الجوال' : 'Phone' ?></label>
        <?php render_phone_field('phone', $phoneParts['dial'], $phoneParts['local']); ?>
      </div>
      <div class="field"><label><?= is_rtl() ? 'كلمة مرور جديدة (اختياري)' : 'New password (optional)' ?></label><input class="pro-input" type="password" name="new_password" minlength="8"></div>
    </div>

    <div class="form-section-title"><?= is_rtl() ? 'التخصص والخبرة' : 'Specialization & experience' ?></div>
    <div class="field">
      <label><?= is_rtl() ? 'التخصص القانوني' : 'Legal specialization' ?></label>
      <?php render_specialization_field('specialization', $lawyer['specialization']); ?>
    </div>
    <div class="field">
      <label><?= is_rtl() ? 'الدولة / الاختصاص القضائي' : 'Country / Jurisdiction' ?></label>
      <?php render_jurisdiction_select('jurisdiction', $lawyer['jurisdiction'] ?? 'السعودية'); ?>
      <p class="field-hint"><?= is_rtl() ? 'الدولة التي تمارس فيها المحاماة — تساعد العملاء على إيجادك حسب الدولة.' : 'The country where you practice law — helps clients find you by country.' ?></p>
    </div>
    <div class="grid-2">
      <div class="field"><label><?= is_rtl() ? 'سنوات الخبرة' : 'Years of experience' ?></label><input class="pro-input" type="number" min="0" name="years_experience" value="<?= $lawyer['years_experience'] ?>"></div>
      <div class="field"><label><?= is_rtl() ? 'السعر بالساعة (د.ب)' : 'Hourly rate (BHD)' ?></label><input class="pro-input" type="number" step="0.001" min="0" name="hourly_rate" value="<?= $lawyer['hourly_rate'] ?>"></div>
      <div class="field"><label><?= is_rtl() ? 'رقم الترخيص (اختياري)' : 'License number (optional)' ?></label><input class="pro-input" type="text" name="license_number" value="<?= htmlspecialchars($lawyer['license_number'] ?? '') ?>"></div>
    </div>
    <div class="field"><label><?= is_rtl() ? 'المؤهلات (الشهادات، الدرجات العلمية، العضويات المهنية)' : 'Qualifications (degrees, certificates, memberships)' ?></label><textarea name="qualifications" rows="3" placeholder="<?= is_rtl() ? 'مثال: بكالوريوس حقوق - جامعة الملك سعود، عضو الهيئة السعودية للمحامين...' : 'e.g. LLB - King Saud University, Member of the Saudi Bar...' ?>"><?= htmlspecialchars($lawyer['qualifications'] ?? '') ?></textarea></div>
    <div class="field"><label><?= is_rtl() ? 'نبذة تعريفية' : 'Bio' ?></label><textarea name="bio" rows="3"><?= htmlspecialchars($lawyer['bio'] ?? '') ?></textarea></div>

    <div class="form-section-title"><?= is_rtl() ? 'وسائل التواصل' : 'Social links' ?></div>
    <div class="grid-2">
      <div class="field"><label>LinkedIn</label><input class="pro-input" type="url" name="social_linkedin" placeholder="https://linkedin.com/in/..." value="<?= htmlspecialchars($lawyer['social_linkedin'] ?? '') ?>"></div>
      <div class="field"><label>X (Twitter)</label><input class="pro-input" type="url" name="social_twitter" placeholder="https://x.com/..." value="<?= htmlspecialchars($lawyer['social_twitter'] ?? '') ?>"></div>
      <div class="field"><label><?= is_rtl() ? 'موقعك الشخصي / مكتبك' : 'Personal / firm website' ?></label><input class="pro-input" type="url" name="social_website" placeholder="https://..." value="<?= htmlspecialchars($lawyer['social_website'] ?? '') ?>"></div>
    </div>

    <button class="btn btn-primary btn-lg" type="submit"><?= is_rtl() ? 'حفظ التغييرات' : 'Save changes' ?></button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
