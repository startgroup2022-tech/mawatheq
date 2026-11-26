<?php
$pageTitle = 'المالية';
$active = 'finance';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٤.٦ المالية</b> — فواتير · عروض أسعار · اتفاقيات أتعاب · مصروفات · أقساط · كشف حساب</div>

<div class="page-head">
  <div><h1>المالية</h1>
    <p>دورة مالية كاملة للمكتب: عرض سعر ← اتفاقية أتعاب ← فاتورة ← دفعات وأقساط ← كشف حساب.</p></div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('receipt') ?> مصروف</button>
  <button class="btn btn-ghost"><?= ico('file') ?> عرض سعر</button>
  <button class="btn btn-primary"><?= ico('plus') ?> فاتورة</button>
</div>

<div class="kpis">
  <div class="kpi green"><div class="kpi-label">المحصَّل هذا الشهر</div><div class="kpi-value">١٨٢٬٥٠٠</div>
    <div class="kpi-meta">ريال · ٢٣ دفعة</div></div>
  <div class="kpi"><div class="kpi-label">مستحقات قائمة</div><div class="kpi-value">٣١٥٬٠٠٠</div>
    <div class="kpi-meta">١٢ فاتورة</div></div>
  <div class="kpi" style="--x:0"><div class="kpi-label" style="color:var(--danger)">متأخرة السداد</div>
    <div class="kpi-value" style="color:var(--danger)">١٦٥٬٠٠٠</div>
    <div class="kpi-meta">٤ فواتير · تحتاج تحصيلاً</div></div>
  <div class="kpi gold"><div class="kpi-label">مصروفات الشهر</div><div class="kpi-value">٢٤٬٣٠٠</div>
    <div class="kpi-meta">ريال · ١٧ بنداً</div></div>
</div>

<div class="card">
  <div class="card-head"><h3>الفواتير</h3><div class="spacer"></div>
    <span class="cat active" style="padding:4px 11px;font-size:.74rem">الكل</span>
    <span class="cat" style="padding:4px 11px;font-size:.74rem">غير مسددة</span>
    <span class="cat" style="padding:4px 11px;font-size:.74rem">متأخرة</span>
    <span class="cat" style="padding:4px 11px;font-size:.74rem">مسددة</span></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>الفاتورة</th><th>الموكل</th><th>القضية</th><th>المبلغ</th><th>الاستحقاق</th><th>الحالة</th><th></th></tr></thead>
      <tbody>
        <tr><td><b>INV-2026-0042</b></td><td>شركة الأفق للتجارة</td><td>MWQ-2026-0142</td>
          <td class="num">٤٥٬٠٠٠٫٠٠</td><td>١٠ نوفمبر</td>
          <td><span class="chip chip-red">متأخرة</span></td>
          <td><span style="color:var(--teal-text);font-weight:700;font-size:.76rem">تحصيل ←</span></td></tr>
        <tr><td><b>INV-2026-0041</b></td><td>مؤسسة البناء الحديث</td><td>MWQ-2026-0139</td>
          <td class="num">١٢٠٬٠٠٠٫٠٠</td><td>٢٥ نوفمبر</td>
          <td><span class="chip chip-gold">غير مسددة</span></td>
          <td><span style="color:var(--teal-text);font-weight:700;font-size:.76rem">عرض ←</span></td></tr>
        <tr><td><b>INV-2026-0040</b></td><td>عبدالله المطيري</td><td>—</td>
          <td class="num">٥٬٧٥٠٫٠٠</td><td>٣ نوفمبر</td>
          <td><span class="chip chip-green">مسددة</span></td>
          <td><span style="color:var(--teal-text);font-weight:700;font-size:.76rem">عرض ←</span></td></tr>
        <tr><td><b>INV-2026-0039</b></td><td>نورة السبيعي</td><td>MWQ-2026-0121</td>
          <td class="num">١٥٬٠٠٠٫٠٠</td><td>٢٨ أكتوبر</td>
          <td><span class="chip chip-green">مسددة جزئياً</span></td>
          <td><span style="color:var(--teal-text);font-weight:700;font-size:.76rem">عرض ←</span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="cols-3">
  <div class="card">
    <div class="card-head"><h3>عروض الأسعار</h3><div class="spacer"></div><span class="chip chip-gray">٤</span></div>
    <div class="card-body" style="padding:0">
      <?php foreach ([['QT-2026-011','استشارة سنوية — البناء الحديث','٣٦٬٠٠٠','chip-gold'],
                      ['QT-2026-010','تأسيس شركة — عميل جديد','١٨٬٥٠٠','chip-teal'],
                      ['QT-2026-009','مراجعة عقود — الأفق','٩٬٠٠٠','chip-green']] as [$n,$d,$v,$c]): ?>
      <div class="doc-row"><span class="doc-ico"><?= ico('file') ?></span>
        <div><b><?= $n ?></b><small><?= $d ?> · <?= $v ?> ريال</small></div>
        <div style="margin-inline-start:auto"><span class="chip <?= $c ?>">●</span></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>اتفاقيات الأتعاب</h3><div class="spacer"></div><span class="chip chip-gray">٦</span></div>
    <div class="card-body" style="padding:0">
      <?php foreach ([['FA-2026-004','الأفق — أتعاب قضية','٢٥٬٠٠٠ + ٥٪'],
                      ['FA-2026-003','البناء — استشارة شهرية','٤٬٠٠٠/شهر'],
                      ['FA-2026-002','المطيري — أتعاب ثابتة','٨٬٠٠٠']] as [$n,$d,$v]): ?>
      <div class="doc-row"><span class="doc-ico"><?= ico('check') ?></span>
        <div><b><?= $n ?></b><small><?= $d ?> · <?= $v ?></small></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>المصروفات</h3><div class="spacer"></div><span class="chip chip-gray">٢٤٬٣٠٠</span></div>
    <div class="card-body" style="padding:0">
      <?php foreach ([['رسوم محكمة','٣٬٧٥٠','١٢ نوفمبر'],['ترجمة معتمدة','١٬٢٠٠','٩ نوفمبر'],
                      ['تصديق وزارة العدل','٨٠٠','٦ نوفمبر'],['مواصلات — جلسة','٣٥٠','٤ نوفمبر']] as [$n,$v,$d]): ?>
      <div class="doc-row"><span class="doc-ico"><?= ico('wallet') ?></span>
        <div><b><?= $n ?></b><small><?= $d ?></small></div>
        <div style="margin-inline-start:auto;font-size:.79rem;font-weight:600"><?= $v ?> ريال</div></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>كشف حساب — شركة الأفق للتجارة</h3><div class="spacer"></div>
    <button class="btn btn-ghost btn-sm"><?= ico('download') ?> PDF</button>
    <button class="btn btn-ghost btn-sm"><?= ico('download') ?> Excel</button></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>التاريخ</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
    <tbody>
      <tr><td>١ أكتوبر</td><td>فاتورة INV-2026-0038</td><td class="num">٤٥٬٠٠٠</td><td class="num">—</td><td class="num">٤٥٬٠٠٠</td></tr>
      <tr><td>١٥ أكتوبر</td><td>دفعة — تحويل بنكي</td><td class="num">—</td><td class="num">٢٠٬٠٠٠</td><td class="num">٢٥٬٠٠٠</td></tr>
      <tr><td>١ نوفمبر</td><td>فاتورة INV-2026-0042</td><td class="num">٤٥٬٠٠٠</td><td class="num">—</td><td class="num">٧٠٬٠٠٠</td></tr>
      <tr><td>٥ نوفمبر</td><td>قسط أول — اتفاقية FA-2026-004</td><td class="num">—</td><td class="num">٢٥٬٠٠٠</td><td class="num">٤٥٬٠٠٠</td></tr>
      <tr style="background:#FBFCFD"><td colspan="4"><b>الرصيد المستحق</b></td>
        <td class="num"><b style="color:var(--danger)">٤٥٬٠٠٠٫٠٠</b></td></tr>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
