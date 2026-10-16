<?php
require_once __DIR__ . '/schema_guard.php';

/**
 * The jurisdiction column is additive and was introduced by upgrade_v12.sql.
 * Databases created from an older base may lack it, which turned every page that
 * filters or lists by jurisdiction into a bare HTTP 500. Repair it once here, at
 * the single include every such page already loads, so the failure cannot recur.
 *
 * Repair is opt-in (MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL=1), matching config.php and
 * api/v1/bootstrap.php: an ordinary page load must not alter the schema as a side
 * effect. A drifted database is reported by database/repair/00_preflight_check.php
 * and repaired by applying database/upgrade_v*.sql.
 */
if (isset($pdo) && $pdo instanceof PDO && getenv('MAWATHEQ_ALLOW_RUNTIME_SCHEMA_DDL') === '1') {
    schema_ensure_column($pdo, 'lawyers', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'");
    schema_ensure_column($pdo, 'law_categories', 'jurisdiction', "VARCHAR(100) NOT NULL DEFAULT 'السعودية'");
}

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
