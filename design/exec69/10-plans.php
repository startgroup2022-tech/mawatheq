<?php
$pageTitle = 'الباقات والاشتراكات';
$active = 'plans';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٧. الباقات</b> — الحدود العشرة · مفاتيح المزايا · الأسعار من <code>plans</code> فقط</div>

<div class="page-head">
  <div><h1>الباقات والاشتراكات</h1>
    <p>كل رقم في هذه الصفحة يُقرأ من جدول <code>plans</code> و<code>plan_features</code> — لا سعر مكتوب في الكود.</p></div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('settings') ?> تحرير الباقات</button>
  <button class="btn btn-primary"><?= ico('plus') ?> باقة جديدة</button>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi-label">الاشتراكات النشطة</div><div class="kpi-value">٨٧</div>
    <div class="kpi-meta">٤١ أساسية · ٣٨ احترافية · ٨ مؤسسية</div></div>
  <div class="kpi green"><div class="kpi-label">الإيراد الشهري المتكرر</div><div class="kpi-value">١٢٬٠٤٢</div>
    <div class="kpi-meta">ريال / شهر</div></div>
  <div class="kpi gold"><div class="kpi-label">تجديد هذا الشهر</div><div class="kpi-value">١٤</div>
    <div class="kpi-meta">٢ تحتاج متابعة دفع</div></div>
  <div class="kpi navy"><div class="kpi-label">معدل الترقية</div><div class="kpi-value">٢٢٪</div>
    <div class="kpi-meta">أساسية ← احترافية</div></div>
</div>

<div class="card">
  <div class="card-head"><h3>مصفوفة المزايا</h3><div class="spacer"></div>
    <span class="chip chip-gray">١٨ بنداً</span></div>
  <div class="card-body" style="padding:0">
    <div class="matrix-wrap"><table class="matrix">
      <thead><tr>
        <th style="min-width:210px">الميزة</th>
        <th class="plan">الأساسية<br><span style="font-weight:600;color:var(--text-muted);font-size:.74rem">٠ ريال</span></th>
        <th class="plan hl">الاحترافية<br><span style="font-weight:600;color:var(--teal-text);font-size:.74rem">١٤٩ ريال/شهر</span></th>
        <th class="plan">المؤسسية<br><span style="font-weight:600;color:var(--text-muted);font-size:.74rem">٧٩٩ ريال/شهر</span></th>
      </tr></thead>
      <tbody>
        <tr class="grp"><td colspan="4">الاستخدام والحدود</td></tr>
        <tr><td>أسئلة المساعد الذكي / شهر</td><td class="num">١٥</td><td class="hl num"><b>غير محدود</b></td><td class="num"><b>غير محدود</b></td></tr>
        <tr><td>تحليل المستندات / شهر</td><td class="num">٢</td><td class="hl num">١٥</td><td class="num"><b>غير محدود</b></td></tr>
        <tr><td>الاستشارات المجانية / شهر</td><td class="num">٠</td><td class="hl num">١</td><td class="num">٥</td></tr>
        <tr><td>عدد الموظفين</td><td class="num">١</td><td class="hl num">٥</td><td class="num"><b>غير محدود</b></td></tr>
        <tr><td>عدد الموكلين</td><td class="num">١٠</td><td class="hl num">١٠٠</td><td class="num"><b>غير محدود</b></td></tr>
        <tr><td>عدد القضايا النشطة</td><td class="num">٥</td><td class="hl num">٥٠</td><td class="num"><b>غير محدود</b></td></tr>
        <tr><td>مساحة التخزين</td><td class="num">١ ج.ب</td><td class="hl num">٢٥ ج.ب</td><td class="num">١٠٠ ج.ب</td></tr>

        <tr class="grp"><td colspan="4">الذكاء الاصطناعي</td></tr>
        <tr><td>المساعد القانوني المسنود بالمصادر</td><td><span class="yes">✓</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>محرر المستندات والتصدير DOCX/PDF</td><td><span class="no">—</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>مكتبة القوالب الكاملة</td><td class="num">٥ قوالب</td><td class="hl num"><b>كل القوالب</b></td><td class="num"><b>كل القوالب</b></td></tr>
        <tr><td>تحليل العقود والمخاطر</td><td><span class="no">—</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>

        <tr class="grp"><td colspan="4">إدارة المكتب</td></tr>
        <tr><td>إدارة القضايا والموكلين</td><td><span class="yes">✓</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>التقويم والجلسات والمهام</td><td><span class="no">—</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>الفواتير والعروض والاتفاقيات</td><td><span class="no">—</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>التقارير المالية</td><td><span class="no">—</span></td><td class="hl"><span class="no">—</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>الشات بوت للموقع</td><td><span class="no">—</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>ربط WhatsApp</td><td><span class="no">—</span></td><td class="hl"><span class="no">—</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>تخصيص هوية المكتب</td><td><span class="no">—</span></td><td class="hl"><span class="yes">✓</span></td><td><span class="yes">✓</span></td></tr>
        <tr><td>دعم مخصص ومستشار قانوني</td><td><span class="no">—</span></td><td class="hl"><span class="no">—</span></td><td><span class="yes">✓</span></td></tr>
      </tbody>
    </table></div>
  </div>
</div>

<div class="cols-3">
  <?php foreach ([
    ['الأساسية','٠','٠','chip-gray','للأفراد', false],
    ['الاحترافية','١٤٩','١٤٩٠','chip-teal','للمكاتب الصغيرة', true],
    ['المؤسسية','٧٩٩','٧٩٩٠','chip-navy','للمكاتب الكبيرة', false],
  ] as [$n,$m,$y,$c,$sub,$hl]): ?>
  <div class="card" style="<?= $hl ? 'border-color:rgba(43,168,196,.5);box-shadow:0 6px 22px rgba(43,168,196,.15)' : '' ?>">
    <div class="card-body">
      <?php if ($hl): ?><div style="text-align:center;margin-bottom:9px">
        <span class="chip chip-gold">الأكثر اختياراً</span></div><?php endif; ?>
      <h3 style="text-align:center;margin-bottom:3px"><?= $n ?></h3>
      <div style="text-align:center;font-size:.73rem;color:var(--text-muted);margin-bottom:13px"><?= $sub ?></div>
      <div style="text-align:center;font-family:var(--serif);font-weight:800;font-size:1.8rem;
        color:var(--navy-dark);line-height:1"><?= $m ?><span style="font-size:.8rem;font-weight:600;
        color:var(--text-muted)"> ريال/شهر</span></div>
      <div style="text-align:center;font-size:.73rem;color:var(--text-muted);margin:5px 0 15px">
        أو <?= $y ?> ريال/سنة (شهرين مجاناً)</div>
      <div style="display:flex;flex-direction:column;gap:7px;font-size:.79rem;margin-bottom:16px">
        <?php
        $feat = [
          'الأساسية' => ['١٥ سؤالاً ذكياً شهرياً','تحليل عقدين شهرياً','مكتبة الأنظمة','٥ قوالب'],
          'الاحترافية' => ['أسئلة غير محدودة','١٥ مستنداً شهرياً','محرر المستندات','كل القوالب','التقويم والمهام'],
          'المؤسسية' => ['كل مزايا الاحترافية','مستخدمون متعددون','بنك البنود','٥ استشارات مجانية','تقارير مالية'],
        ][$n];
        foreach ($feat as $f): ?>
          <div style="display:flex;gap:7px;align-items:flex-start">
            <span style="color:var(--success-text);flex:none">✓</span><span><?= $f ?></span></div>
        <?php endforeach; ?>
      </div>
      <button class="btn <?= $hl ? 'btn-primary' : 'btn-ghost' ?>" style="width:100%;justify-content:center">
        <?= $hl ? 'ترقية الآن' : 'اختيار الباقة' ?></button>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body" style="display:flex;gap:12px;align-items:flex-start">
    <?= ico('shield') ?>
    <div style="font-size:.78rem;color:var(--text-muted);line-height:1.75">
      <b style="color:var(--navy)">ملاحظة تنفيذية:</b> الحدود العشرة (الموظفون، الموكلون، القضايا،
      التخزين، القوالب، الشات بوت، إدارة المكتب، التقارير، WhatsApp، الهوية) تُضاف كأعمدة في
      <code>plans</code> وتُفرَض على الخادم في نقطة واحدة. <b>لن يُعدَّل أي سعر حالي.</b>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
