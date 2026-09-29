<?php
/**
 * نسبة اكتمال ملف المحامي — تُعرض فقط داخل حساب المحامي بعد تسجيل الدخول
 * (لوحة التحكم والملف الشخصي)، ولا تظهر إطلاقاً في صفحة التسجيل.
 */
function lawyer_completion_items(array $lawyer): array {
    $rtl = is_rtl();
    $hasSocial = trim((string)($lawyer['social_linkedin'] ?? '')) !== ''
        || trim((string)($lawyer['social_twitter'] ?? '')) !== ''
        || trim((string)($lawyer['social_website'] ?? '')) !== '';

    return [
        [
            'label' => $rtl ? 'بيانات الحساب الأساسية' : 'Basic account info',
            'done'  => trim((string)($lawyer['full_name'] ?? '')) !== '' && trim((string)($lawyer['email'] ?? '')) !== '' && trim((string)($lawyer['phone'] ?? '')) !== '',
            'weight'=> 15,
        ],
        [
            'label' => $rtl ? 'الصورة الشخصية' : 'Profile photo',
            'done'  => trim((string)($lawyer['photo_path'] ?? '')) !== '',
            'weight'=> 15,
        ],
        [
            'label' => $rtl ? 'النبذة التعريفية' : 'Bio',
            'done'  => trim((string)($lawyer['bio'] ?? '')) !== '',
            'weight'=> 15,
        ],
        [
            'label' => $rtl ? 'التخصص' : 'Specialization',
            'done'  => trim((string)($lawyer['specialization'] ?? '')) !== '',
            'weight'=> 15,
        ],
        [
            'label' => $rtl ? 'سنوات الخبرة' : 'Years of experience',
            'done'  => is_numeric($lawyer['years_experience'] ?? null) && (float)$lawyer['years_experience'] > 0,
            'weight'=> 10,
        ],
        [
            'label' => $rtl ? 'المؤهلات' : 'Qualifications',
            'done'  => trim((string)($lawyer['qualifications'] ?? '')) !== '',
            'weight'=> 15,
        ],
        [
            'label' => $rtl ? 'وسائل التواصل' : 'Social links',
            'done'  => $hasSocial,
            'weight'=> 10,
        ],
        [
            'label' => $rtl ? 'رقم الترخيص (اختياري)' : 'License number (optional)',
            'done'  => trim((string)($lawyer['license_number'] ?? '')) !== '',
            'weight'=> 5,
        ],
    ];
}

function lawyer_completion_percent(array $lawyer): int {
    $items = lawyer_completion_items($lawyer);
    $max = array_sum(array_column($items, 'weight'));
    $score = array_sum(array_map(fn($i) => $i['done'] ? $i['weight'] : 0, $items));
    return $max > 0 ? (int) round(($score / $max) * 100) : 0;
}

/** يطبع شريط تقدّم بسيط بنسبة مئوية معطاة. */
function render_progress_bar(int $percent, string $label = ''): void {
    $percent = max(0, min(100, $percent));
    $color = $percent >= 80 ? 'var(--success, #1E8E5A)' : ($percent >= 40 ? 'var(--gold, #C9A24B)' : 'var(--danger, #C0392B)');
    ?>
    <div class="progress-wrap">
      <?php if ($label): ?><div class="progress-label"><span><?= htmlspecialchars($label) ?></span><span><?= $percent ?>%</span></div><?php endif; ?>
      <div class="progress-track"><div class="progress-fill" style="width:<?= $percent ?>%;background:<?= $color ?>"></div></div>
    </div>
    <?php
}

/** يطبع بطاقة اكتمال الملف الكاملة (نسبة + شريط + قائمة العناصر المتحققة والناقصة). */
function render_completion_card(array $lawyer): void {
    $rtl = is_rtl();
    $items = lawyer_completion_items($lawyer);
    $percent = lawyer_completion_percent($lawyer);
    ?>
    <div class="completion-card">
      <div class="completion-head">
        <div>
          <h3><?= $rtl ? 'اكتمال الملف الشخصي' : 'Profile completeness' ?></h3>
          <p><?= $rtl ? 'أكمل ملفك ليظهر بشكل أفضل للعملاء.' : 'Complete your profile to appear better to clients.' ?></p>
        </div>
        <div class="completion-ring" style="--p:<?= $percent ?>"><span><?= $percent ?>%</span></div>
      </div>
      <ul class="completion-list">
        <?php foreach ($items as $it): ?>
          <li class="<?= $it['done'] ? 'is-done' : 'is-pending' ?>">
            <span class="dot"><?= $it['done'] ? '✓' : '' ?></span>
            <span><?= htmlspecialchars($it['label']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($percent < 100): ?>
        <a href="/lawyer/profile.php" class="btn btn-outline btn-sm"><?= $rtl ? 'أكمل ملفك الآن' : 'Complete your profile' ?></a>
      <?php endif; ?>
    </div>
    <?php
}
