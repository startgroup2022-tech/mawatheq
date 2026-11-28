<?php
$activeLawyer = 'profile';
require_once __DIR__ . '/includes/lawyer_header.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/specialization_field.php';
require_once __DIR__ . '/../includes/profile_completion.php';
require_once __DIR__ . '/../includes/lawyer_readiness.php';
require_once __DIR__ . '/../includes/jurisdiction.php';
require_once __DIR__ . '/../includes/cities.php';
require_once __DIR__ . '/../includes/customer_features.php';
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
    } elseif (specialization_normalize_list($_POST['specializations'] ?? []) === []) {
        $error = is_rtl() ? 'يرجى اختيار تخصص قانوني واحد على الأقل.' : 'Please select at least one legal specialization.';
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
            $specList = specialization_normalize_list($_POST['specializations'] ?? []);
            // Consultation modes and spoken languages (Execution 72). A lawyer must offer at
            // least one mode; the office address is stored only when in-office is offered, so
            // a stale address never leaks onto a profile that no longer does visits.
            $offersRemote = !empty($_POST['offers_remote']) ? 1 : 0;
            $offersOffice = !empty($_POST['offers_in_office']) ? 1 : 0;
            if (!$offersRemote && !$offersOffice) $offersRemote = 1;
            $officeAddress = $offersOffice ? trim((string)($_POST['office_address'] ?? '')) : '';
            $languages = lawyer_languages_normalize($_POST['languages'] ?? []);
            $pdo->prepare('UPDATE lawyers SET full_name=?, phone=?, specialization=?, specializations=?, bio=?, years_experience=?, hourly_rate=?, license_number=?, qualifications=?, social_linkedin=?, social_twitter=?, social_website=?, photo_path=?, jurisdiction=?, offers_remote=?, offers_in_office=?, office_address=?, languages=? WHERE id=?')
                ->execute([
                    trim($_POST['full_name']), $phoneResult['value'], ($specList[0] ?? ''), specialization_list_to_stored($specList), trim($_POST['bio'] ?? ''),
                    (int)$_POST['years_experience'], (float)$_POST['hourly_rate'], trim($_POST['license_number'] ?? ''),
                    trim($_POST['qualifications'] ?? ''), trim($_POST['social_linkedin'] ?? ''), trim($_POST['social_twitter'] ?? ''),
                    trim($_POST['social_website'] ?? ''), $photoPath, lawyer_country_from_input(null, null)['jurisdiction'],
                    $offersRemote, $offersOffice, ($officeAddress !== '' ? $officeAddress : null), $languages, $lawyerId
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
$readiness = lawyer_panel_readiness($pdo, $lawyer);
?>
<div class="app-content">
  <h2><?= is_rtl() ? 'ملفي الشخصي' : 'My Profile' ?></h2>

  <?php render_completion_card($lawyer); ?>

  <?php if (!$readiness['ready']): ?>
    <?php render_lawyer_readiness_card($readiness); ?>
  <?php endif; ?>

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
      <label><?= is_rtl() ? 'التخصصات القانونية' : 'Legal specializations' ?></label>
      <?php render_specialization_checklist('specializations', lawyer_specialization_list($lawyer)); ?>
      <p class="field-hint"><?= is_rtl() ? 'اختر كل التخصصات التي تمارسها — يمكنك اختيار أكثر من تخصص.' : 'Choose every specialization you practise — you may select more than one.' ?></p>
    </div>
    <div class="field">
      <label><?= is_rtl() ? 'الدولة' : 'Country' ?></label>
      <input class="pro-input" type="text" value="<?= htmlspecialchars(country_name(LAWYER_COUNTRY_ONLY)) ?>" readonly disabled>
      <input type="hidden" name="jurisdiction" value="<?= htmlspecialchars(country_name(LAWYER_COUNTRY_ONLY)) ?>">
      <p class="field-hint"><?= is_rtl() ? 'المنصة تستقبل محامين من السعودية فقط حالياً.' : 'The platform currently onboards lawyers from Saudi Arabia only.' ?></p>
    </div>
    <div class="grid-2">
      <div class="field"><label><?= is_rtl() ? 'سنوات الخبرة' : 'Years of experience' ?></label><input class="pro-input" type="number" min="0" name="years_experience" value="<?= $lawyer['years_experience'] ?>"></div>
      <div class="field"><label><?= is_rtl() ? 'السعر بالساعة' : 'Hourly rate' ?> (<?= currency_symbol() ?>)</label><input class="pro-input" type="number" step="<?= currency_step() ?>" min="0" name="hourly_rate" value="<?= $lawyer['hourly_rate'] ?>"></div>
      <div class="field"><label><?= is_rtl() ? 'رقم الترخيص (اختياري)' : 'License number (optional)' ?></label><input class="pro-input" type="text" name="license_number" value="<?= htmlspecialchars($lawyer['license_number'] ?? '') ?>"></div>
    </div>
    <div class="field"><label><?= is_rtl() ? 'المؤهلات (الشهادات، الدرجات العلمية، العضويات المهنية)' : 'Qualifications (degrees, certificates, memberships)' ?></label><textarea name="qualifications" rows="3" placeholder="<?= is_rtl() ? 'مثال: بكالوريوس حقوق - جامعة الملك سعود، عضو الهيئة السعودية للمحامين...' : 'e.g. LLB - King Saud University, Member of the Saudi Bar...' ?>"><?= htmlspecialchars($lawyer['qualifications'] ?? '') ?></textarea></div>
    <div class="field"><label><?= is_rtl() ? 'نبذة تعريفية' : 'Bio' ?></label><textarea name="bio" rows="3"><?= htmlspecialchars($lawyer['bio'] ?? '') ?></textarea></div>

    <div class="form-section-title"><?= is_rtl() ? 'طريقة الاستشارة واللغات' : 'Consultation mode & languages' ?></div>
    <div class="field">
      <label><?= is_rtl() ? 'كيف تقدّم استشاراتك؟' : 'How do you offer consultations?' ?></label>
      <label class="check-row" style="display:flex;align-items:center;gap:10px;min-height:44px">
        <input type="checkbox" name="offers_remote" value="1" <?= (int)($lawyer['offers_remote'] ?? 1) === 1 ? 'checked' : '' ?>>
        <span><?= is_rtl() ? 'عن بُعد (مكالمة صوتية/مرئية أو محادثة)' : 'Remote (voice/video or chat)' ?></span>
      </label>
      <label class="check-row" style="display:flex;align-items:center;gap:10px;min-height:44px">
        <input type="checkbox" name="offers_in_office" value="1" <?= (int)($lawyer['offers_in_office'] ?? 0) === 1 ? 'checked' : '' ?>>
        <span><?= is_rtl() ? 'في المكتب (زيارة حضورية)' : 'In office (in-person visit)' ?></span>
      </label>
      <p class="field-hint"><?= is_rtl() ? 'اختر واحدة على الأقل. يُعرض الخيار للعميل عند الحجز.' : 'Choose at least one. Customers see this choice when booking.' ?></p>
    </div>
    <div class="field">
      <label><?= is_rtl() ? 'عنوان المكتب (إن كنت تستقبل زيارات)' : 'Office address (if you see clients in person)' ?></label>
      <input class="pro-input" type="text" name="office_address" value="<?= htmlspecialchars($lawyer['office_address'] ?? '') ?>" placeholder="<?= is_rtl() ? 'المدينة، الحي، الشارع' : 'City, district, street' ?>">
    </div>
    <div class="field">
      <label><?= is_rtl() ? 'لغات الاستشارة' : 'Consultation languages' ?></label>
      <div class="lang-checks" style="display:flex;flex-wrap:wrap;gap:12px">
        <?php $lwLangs = lawyer_languages_labels($lawyer['languages'] ?? null); foreach (lawyer_languages_catalog() as $key => $label): ?>
          <label class="check-row" style="display:flex;align-items:center;gap:8px;min-height:44px">
            <input type="checkbox" name="languages[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($label, $lwLangs, true) ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

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
