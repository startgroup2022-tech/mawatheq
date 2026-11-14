<div class="form-section-title">البيانات الأساسية</div>
<div class="grid-2">
  <div class="field"><label>اسم الباقة (عربي)</label><input type="text" name="name_ar" value="<?= htmlspecialchars($plan['name_ar']) ?>" required></div>
  <div class="field"><label>اسم الباقة (إنجليزي)</label><input type="text" name="name_en" value="<?= htmlspecialchars($plan['name_en']) ?>" required></div>
</div>
<div class="field"><label>وصف مختصر (عربي)</label><textarea name="description_ar" rows="2"><?= htmlspecialchars($plan['description_ar'] ?? '') ?></textarea></div>
<div class="field"><label>وصف مختصر (إنجليزي)</label><textarea name="description_en" rows="2"><?= htmlspecialchars($plan['description_en'] ?? '') ?></textarea></div>

<div class="form-section-title">السعر والمدة</div>
<div class="grid-2">
  <div class="field"><label>تصنيف الباقة (يظهر للعميل)</label>
    <div class="select-wrap"><select name="classification" class="pro-select">
      <option value="free" <?= ($plan['classification'] ?? '')==='free'?'selected':'' ?>>مجانية</option>
      <option value="paid" <?= ($plan['classification'] ?? 'paid')==='paid'?'selected':'' ?>>مدفوعة</option>
      <option value="corporate" <?= ($plan['classification'] ?? '')==='corporate'?'selected':'' ?>>مؤسسات</option>
    </select></div>
  </div>
  <div class="field"><label>دورة الفوترة</label>
    <div class="select-wrap"><select name="billing_cycle" class="pro-select">
      <option value="both" <?= ($plan['billing_cycle'] ?? 'both')==='both'?'selected':'' ?>>شهري وسنوي</option>
      <option value="monthly" <?= ($plan['billing_cycle'] ?? '')==='monthly'?'selected':'' ?>>شهري فقط</option>
      <option value="yearly" <?= ($plan['billing_cycle'] ?? '')==='yearly'?'selected':'' ?>>سنوي فقط</option>
    </select></div>
  </div>
  <div class="field"><label>السعر الشهري (<?= currency_symbol() ?>) — 0 تعني باقة مجانية</label><input type="number" step="<?= currency_step() ?>" min="0" name="price_monthly" value="<?= htmlspecialchars((string)$plan['price_monthly']) ?>"></div>
  <div class="field"><label>السعر السنوي (<?= currency_symbol() ?>) — اختياري</label><input type="number" step="<?= currency_step() ?>" min="0" name="price_yearly" value="<?= htmlspecialchars((string)($plan['price_yearly'] ?? '')) ?>"></div>
</div>
<div class="field" style="max-width:360px"><label>سعر الاستشارة المضمّنة (<?= currency_symbol() ?>) — اختياري، فارغ يعني لا يوجد</label><input type="number" step="<?= currency_step() ?>" min="0" name="consultation_price" value="<?= htmlspecialchars((string)($plan['consultation_price'] ?? '')) ?>"></div>

<div class="form-section-title">الحدود والمزايا الفعلية (تُطبَّق تلقائياً على كل مشترك)</div>
<div class="grid-2">
  <div class="field"><label>حد استفسارات المساعد الذكي شهرياً (فارغ = غير محدود)</label><input type="number" min="0" name="ai_queries_limit" value="<?= htmlspecialchars((string)($plan['ai_queries_limit'] ?? '')) ?>"></div>
  <div class="field"><label>حد تحليل المستندات شهرياً (فارغ = غير محدود)</label><input type="number" min="0" name="document_analysis_limit" value="<?= htmlspecialchars((string)($plan['document_analysis_limit'] ?? '')) ?>"></div>
  <div class="field"><label>عدد الاستشارات المضمّنة (معلوماتي)</label><input type="number" min="0" name="consultations_included" value="<?= htmlspecialchars((string)$plan['consultations_included']) ?>"></div>
  <div class="field">
    <label>الحالة</label>
    <div class="select-wrap"><select name="status" class="pro-select">
      <option value="active" <?= $plan['status']==='active'?'selected':'' ?>>مفعّلة (تظهر للعملاء)</option>
      <option value="hidden" <?= $plan['status']==='hidden'?'selected':'' ?>>معطّلة (مخفية)</option>
    </select></div>
  </div>
</div>
<div style="display:flex;gap:20px;margin:14px 0">
  <label style="display:flex;align-items:center;gap:6px;font-size:.88rem"><input type="checkbox" name="is_corporate" value="1" <?= !empty($plan['is_corporate']) ? 'checked' : '' ?> style="width:18px;height:18px"> باقة مؤسسات</label>
  <label style="display:flex;align-items:center;gap:6px;font-size:.88rem"><input type="checkbox" name="is_featured" value="1" <?= !empty($plan['is_featured']) ? 'checked' : '' ?> style="width:18px;height:18px"> باقة مميزة (تُبرَز بصفحة الأسعار)</label>
</div>
<input type="hidden" name="sort_order" value="<?= (int)($plan['sort_order'] ?? 0) ?>">
