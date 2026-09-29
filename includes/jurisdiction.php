<?php
/** الدول/الأنظمة القانونية المدعومة حالياً بالمنصة. */
function jurisdiction_options(): array {
    return ['السعودية', 'البحرين', 'الإمارات', 'الكويت', 'قطر', 'عُمان', 'مصر', 'الأردن'];
}

function render_jurisdiction_select(string $name, string $selected = 'السعودية', bool $includeAll = false): void {
    $rtl = is_rtl();
    ?>
    <div class="select-wrap">
      <select name="<?= htmlspecialchars($name) ?>" class="pro-select">
        <?php if ($includeAll): ?><option value=""><?= $rtl ? 'كل الدول' : 'All countries' ?></option><?php endif; ?>
        <?php foreach (jurisdiction_options() as $j): ?>
          <option value="<?= htmlspecialchars($j) ?>" <?= $selected === $j ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
}
