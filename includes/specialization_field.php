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
    $current = specialization_normalize_stored((string)$storedValue);
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

/**
 * القيمة المخزّنة، مطبَّعة إلى تخصص واحد معروف من القائمة.
 *
 * القيم القديمة كانت تُخزَّن كعدة تخصصات مفصولة بـ " - "، فنأخذ أول تخصص معروف منها.
 * قيمة غير معروفة تُعاد كما هي (لا نخترع تخصصاً)، فلا يُختار شيء في القائمة المنسدلة.
 */
function specialization_normalize_stored(string $stored): string {
    $stored = trim($stored);
    $options = specialization_options();
    if ($stored === '' || in_array($stored, $options, true)) return $stored;
    foreach (array_map('trim', explode(' - ', $stored)) as $part) {
        if (in_array($part, $options, true)) return $part;
    }
    return $stored;
}

/**
 * حقل تخصص قابل للبحث: مربّع بحث فوق قائمة منسدلة أصلية.
 *
 * الـ `<select>` يبقى عنصر التحكم الحقيقي، فيحتفظ بـ `required` وبمسار «بلا JavaScript»،
 * ولا يرسل النموذج إلا قيمة من القائمة. `assets/js/specialization-combo.js` يخفي
 * الخيارات غير المطابقة فقط — لا يعيد بناء القائمة، فلا يضيع الترتيب ولا القيمة المختارة.
 */
function render_specialization_combobox(string $name, ?string $storedValue = '', string $id = ''): void {
    $rtl = is_rtl();
    $options = specialization_options();
    $current = specialization_normalize_stored((string)$storedValue);
    $id = $id !== '' ? $id : $name;
    $searchId = $id . '_search';
    $placeholder = $rtl ? 'اختر التخصص القانوني...' : 'Select legal specialization...';
    $searchPlaceholder = $rtl ? 'اكتب للبحث...' : 'Type to search...';
    $emptyText = $rtl ? 'لا يوجد تخصص مطابق.' : 'No matching specialization.';
    ?>
    <div class="spec-combo" data-spec-combo>
      <div class="spec-combo-search">
        <span class="spec-combo-icon" aria-hidden="true">&#128269;</span>
        <input type="text" id="<?= htmlspecialchars($searchId) ?>" class="pro-input spec-combo-input"
               data-spec-search autocomplete="off" placeholder="<?= htmlspecialchars($searchPlaceholder) ?>"
               aria-controls="<?= htmlspecialchars($id) ?>" aria-label="<?= htmlspecialchars($placeholder) ?>">
        <button type="button" class="spec-combo-toggle" data-spec-toggle aria-expanded="false"
                aria-controls="<?= htmlspecialchars($id) ?>" aria-label="<?= htmlspecialchars($placeholder) ?>">&#9662;</button>
      </div>
      <div class="select-wrap">
        <select name="<?= htmlspecialchars($name) ?>" id="<?= htmlspecialchars($id) ?>"
                class="pro-select" data-spec-select required>
          <option value="" disabled <?= $current === '' ? 'selected' : '' ?>><?= htmlspecialchars($placeholder) ?></option>
          <?php foreach ($options as $opt): ?>
            <option value="<?= htmlspecialchars($opt) ?>" <?= $current === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="spec-combo-empty" data-spec-empty hidden><?= htmlspecialchars($emptyText) ?></p>
    </div>
    <?php
}
