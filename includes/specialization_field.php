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
 * قائمة تخصصات متعددة → مصفوفة معتمدة، بترتيب القائمة القانونية، بلا تكرار.
 *
 * يقبل مصفوفة (من مربّعات الاختيار) أو نصاً مخزّناً مفصولاً بـ " - "، فلا يهمّ إن
 * جاء المدخل من نموذج جديد أو من صفّ قديم. القيم غير المعروفة تُهمَل (لا نخترع تخصصاً)،
 * ويُعاد الترتيب حسب القائمة القانونية حتى تكون النتيجة حتمية مهما كان ترتيب الإرسال.
 */
function specialization_normalize_list($input): array {
    if (is_string($input)) {
        $input = $input === '' ? [] : array_map('trim', explode(' - ', $input));
    }
    if (!is_array($input)) return [];

    $options = specialization_options();
    $chosen = [];
    foreach ($input as $value) {
        if (!is_scalar($value)) continue;
        $value = trim((string)$value);
        if (in_array($value, $options, true)) $chosen[$value] = true;
    }
    return array_values(array_filter($options, fn($opt) => isset($chosen[$opt])));
}

/** قائمة التخصصات → القيمة المخزّنة في العمود (مفصولة بـ " - "). */
function specialization_list_to_stored(array $list): string {
    return implode(' - ', $list);
}

/**
 * التخصصات المخزّنة للمحامي كقائمة.
 *
 * يقرأ العمود الجديد `specializations` إن وُجد، وإلا يسقط إلى العمود القديم
 * `specialization` (قيمة واحدة أو مفصولة بـ " - ")، فلا يفقد صفٌّ قديم تخصصه.
 */
function lawyer_specialization_list(array $lawyerRow): array {
    $multi = (string)($lawyerRow['specializations'] ?? '');
    if (trim($multi) !== '') return specialization_normalize_list($multi);
    return specialization_normalize_list((string)($lawyerRow['specialization'] ?? ''));
}

/**
 * التخصصات المخزّنة → نص عرض واحد مفصول بـ " · ".
 *
 * يستخدم في البطاقات والقوائم التي كانت تعرض تخصصاً واحداً. صفٌّ بلا تخصص يُعيد ''،
 * فلا يعرض القالب فاصلاً معلّقاً.
 */
function lawyer_specialization_display(array $lawyerRow): string {
    return implode(' · ', lawyer_specialization_list($lawyerRow));
}


/**
 * حقل تخصصات متعددة (شرائح اختيار) مع مربّع بحث.
 *
 * يُستخدم في تسجيل المحامي وملفه الشخصي حيث يُسمح بأكثر من تخصص. كل مربّع اختيار
 * مخفي داخل شريحة هو عنصر التحكم الحقيقي (`name="specializations[]"`)، فالمسار يعمل
 * بلا JavaScript والقيم المُرسَلة محصورة في القائمة. الشريحة المختارة تتلوّن عبر
 * `:has(input:checked)` (مع `.is-checked` كبديل لمتصفح قديم)، ومربّع البحث يُخفي
 * الشرائح غير المطابقة فقط (عبر `data-spec-filter`)، ولا يمسّ حالة الاختيار.
 *
 * @param array<int,string> $selected التخصصات المختارة حالياً
 */
function render_specialization_checklist(string $name = 'specializations', array $selected = []): void {
    $rtl = is_rtl();
    $options = specialization_options();
    $selected = specialization_normalize_list($selected);
    $searchPlaceholder = $rtl ? 'ابحث عن تخصص...' : 'Search specializations...';
    $emptyText = $rtl ? 'لا يوجد تخصص مطابق.' : 'No matching specialization.';
    ?>
    <div class="spec-check" data-spec-check>
      <div class="spec-combo-search">
        <span class="spec-combo-icon" aria-hidden="true">&#128269;</span>
        <input type="text" class="pro-input spec-combo-input" data-spec-filter autocomplete="off"
               placeholder="<?= htmlspecialchars($searchPlaceholder) ?>"
               aria-label="<?= htmlspecialchars($searchPlaceholder) ?>">
      </div>
      <div class="spec-chips" role="group">
        <?php foreach ($options as $opt): $checked = in_array($opt, $selected, true); ?>
          <label class="spec-chip<?= $checked ? ' is-checked' : '' ?>" data-spec-label="<?= htmlspecialchars($opt) ?>">
            <input type="checkbox" name="<?= htmlspecialchars($name) ?>[]" value="<?= htmlspecialchars($opt) ?>" <?= $checked ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($opt) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="spec-combo-empty" data-spec-empty hidden><?= htmlspecialchars($emptyText) ?></p>
    </div>
    <?php
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
