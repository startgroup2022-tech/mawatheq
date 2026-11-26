<?php
$pageTitle = 'ملف القضية';
$active = 'cases';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٤.٣ ملف القضية</b> — رقم داخلي تلقائي · محكمة ودائرة · أطراف · سجل زمني · مساعد مدمج</div>

<div class="page-head">
  <div>
    <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:5px">
      <h1 style="margin:0">مطالبة مالية — شركة الأفق</h1>
      <span class="chip chip-green">قائمة</span>
      <span class="chip chip-navy">تجاري</span>
    </div>
    <p>الرقم الداخلي <b style="color:var(--navy)">MWQ-2026-0142</b> · الرقم الرسمي ٤٨٧٠٠٠٠١٦٢ ·
      المحكمة التجارية بالرياض · الدائرة الثالثة</p>
  </div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('spark') ?> مساعد القضية</button>
  <button class="btn btn-primary"><?= ico('plus') ?> إضافة إجراء</button>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi-label">المبلغ المطالب به</div><div class="kpi-value">٢٥٠٬٠٠٠</div>
    <div class="kpi-meta">ريال سعودي</div></div>
  <div class="kpi green"><div class="kpi-label">المحصَّل</div><div class="kpi-value">٠</div>
    <div class="kpi-meta">لم تُسدَّد بعد</div></div>
  <div class="kpi gold"><div class="kpi-label">الجلسة القادمة</div><div class="kpi-value" style="font-size:1.05rem">١٢ نوفمبر</div>
    <div class="kpi-meta">١٠:٣٠ ص · المحكمة التجارية</div></div>
  <div class="kpi navy"><div class="kpi-label">ساعات العمل</div><div class="kpi-value">١٨٫٥</div>
    <div class="kpi-meta">قابلة للفوترة</div></div>
</div>

<div class="cols-2">
  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>أطراف القضية</h3><div class="spacer"></div>
        <span class="chip chip-gray">case_parties</span></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>الاسم</th><th>الصفة</th><th>جهة التمثيل</th></tr></thead>
        <tbody>
          <tr><td>شركة الأفق للتجارة</td><td><span class="chip chip-teal">مدّعي</span></td><td>ممثلها النظامي</td></tr>
          <tr><td>مؤسسة البناء الحديث</td><td><span class="chip chip-red">مدّعى عليه</span></td><td>ممثلها النظامي</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
      <div class="card-head"><h3>الوقائع والطلبات</h3></div>
      <div class="card-body" style="font-size:.82rem;line-height:1.85;color:var(--text)">
        <p><b>الوقائع:</b> تعاقد المدّعي مع المدّعى عليه على توريد مواد بناء بموجب أمر شراء
          رقم PO-2025-118، وقد استلم المدّعى عليه التوريدات كاملةً ولم يسدّد قيمتها.</p>
        <p style="margin-bottom:0"><b>الطلبات:</b> إلزام المدّعى عليه بمبلغ ٢٥٠٬٠٠٠ ريال،
          مع الفوائد النظامية والرسوم والمصاريف.</p>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>السجل الزمني</h3><div class="spacer"></div>
        <span class="chip chip-gray">case_timeline</span></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([
          ['١٢ نوفمبر ٢٠٢٦', 'جلسة مرافعة — المحكمة التجارية', 'chip-navy'],
          ['٤ نوفمبر ٢٠٢٦', 'إيداع المذكرة الجوابية', 'chip-teal'],
          ['٢٨ أكتوبر ٢٠٢٦', 'تسجيل الدعوى — الرقم ٤٨٧٠٠٠٠١٦٢', 'chip-green'],
          ['٢٠ أكتوبر ٢٠٢٦', 'فتح ملف القضية', 'chip-gray'],
        ] as [$d, $e, $c]): ?>
        <div class="doc-row"><span class="doc-ico" style="background:rgba(43,168,196,.1);color:var(--teal-text)">●</span>
          <div><b><?= $e ?></b><small><?= $d ?></small></div>
          <div style="margin-inline-start:auto"><span class="chip <?= $c ?>">إجراء</span></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>الجلسات</h3></div>
      <div class="card-body" style="padding:0">
        <div class="doc-row"><span class="doc-ico"><?= ico('calendar') ?></span>
          <div><b>جلسة مرافعة</b><small>١٢ نوفمبر ٢٠٢٦ · ١٠:٣٠ ص</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-gold">قادمة</span></div></div>
        <div class="doc-row"><span class="doc-ico"><?= ico('calendar') ?></span>
          <div><b>جلسة تحضيرية</b><small>٢٠ أكتوبر ٢٠٢٦ · ٠٩:٠٠ ص</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-green">انعقدت</span></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>المستندات والأدلة</h3></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([['PDF','عقد التوريد.pdf'],['PDF','أمر الشراء PO-2025-118.pdf'],['IMG','صور الاستلام.jpg']] as [$t,$n]): ?>
        <div class="doc-row"><span class="doc-ico"><?= $t ?></span>
          <div><b><?= $n ?></b><small>مرفق بالقضية</small></div>
          <div style="margin-inline-start:auto;color:var(--teal-text)"><?= ico('download') ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>المهام</h3><div class="spacer"></div><span class="chip chip-red">٢ متأخرة</span></div>
      <div class="card-body" style="padding:0">
        <div class="task"><span class="pri hi"></span><span class="chk"></span>
          <div class="task-body"><b>تقديم المذكرة الجوابية</b>
            <small>متأخرة يومين · عالية · نورة السبيعي</small></div></div>
        <div class="task"><span class="pri md"></span><span class="chk"></span>
          <div class="task-body"><b>تحضير حافظة المستندات</b><small>١٠ نوفمبر · متوسطة</small></div></div>
        <div class="task"><span class="pri lo"></span><span class="chk done"></span>
          <div class="task-body"><b>إشعار الموكل بموعد الجلسة</b><small>٤ نوفمبر · منجزة</small></div></div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
