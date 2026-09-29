<?php
/**
 * قائمة التخصصات القانونية الموحّدة — قائمة منسدلة فقط (بدون حقل كتابة حر)،
 * تُستخدم في نموذج تسجيل المحامي، ملفه الشخصي، وإضافته من لوحة التحكم.
 */
function specialization_options(): array {
    return [
        'قانون تجاري',
        'قانون جنائي',
        'قانون العمل والعمال',
        'الأحوال الشخصية',
        'قانون عقاري',
        'قانون شركات',
        'الملكية الفكرية',
        'التحكيم التجاري',
        'القانون الإداري',
        'الضرائب والزكاة',
        'القانون البنكي والمالي',
        'القانون المدني',
        'القانون الدولي',
    ];
}

/**
 * يطبع قائمة منسدلة (select) احترافية لاختيار التخصص الأساسي.
 * $storedValue: القيمة المخزّنة حالياً (تخصص واحد، أو أول تخصص من قيمة قديمة مفصولة بـ " - ").
 */
function render_specialization_field(string $name, ?string $storedValue = ''): void {
    $rtl = is_rtl();
    $options = specialization_options();
    $current = trim((string)$storedValue);
    // توافق مع قيم قديمة كانت مخزّنة كعدة تخصصات مفصولة بـ " - " — نأخذ أول تخصص معروف منها
    if ($current !== '' && !in_array($current, $options, true)) {
        $parts = array_map('trim', explode(' - ', $current));
        foreach ($parts as $p) { if (in_array($p, $options, true)) { $current = $p; break; } }
    }
    ?>
    <div class="select-wrap">
      <select name="<?= htmlspecialchars($name) ?>" class="pro-select" required>
        <option value="" disabled <?= $current === '' ? 'selected' : '' ?>><?= $rtl ? 'اختر التخصص القانوني...' : 'Select legal specialization...' ?></option>
        <?php foreach ($options as $opt): ?>
          <option value="<?= htmlspecialchars($opt) ?>" <?= $current === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
}
