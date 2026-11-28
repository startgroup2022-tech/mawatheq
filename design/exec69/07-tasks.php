<?php
$pageTitle = 'المهام';
$active = 'tasks';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٤.٥ إدارة المهام</b> — إسناد · أولوية · مواعيد · ربط بقضية أو موكل · رؤية العميل</div>

<div class="page-head">
  <div><h1>المهام</h1>
    <p>مهام المكتب والمواعيد النهائية، بإسناد واضح وأولوية ورؤية يتحكم بها المحامي.</p></div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('chart') ?> تقرير الإنجاز</button>
  <button class="btn btn-primary"><?= ico('plus') ?> مهمة جديدة</button>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi-label">مهام مفتوحة</div><div class="kpi-value">٢٤</div>
    <div class="kpi-meta">٧ مسندة إليّ</div></div>
  <div class="kpi gold"><div class="kpi-label">تستحق اليوم</div><div class="kpi-value">٥</div>
    <div class="kpi-meta">٢ عالية الأولوية</div></div>
  <div class="kpi" style="--x:0"><div class="kpi-label" style="color:var(--danger)">متأخرة</div>
    <div class="kpi-value" style="color:var(--danger)">٣</div>
    <div class="kpi-meta">تحتاج متابعة فورية</div></div>
  <div class="kpi green"><div class="kpi-label">أُنجزت هذا الأسبوع</div><div class="kpi-value">١٨</div>
    <div class="kpi-meta">معدل الإنجاز ٨٦٪</div></div>
</div>

<div class="cols-2">
  <div class="card">
    <div class="card-head"><h3>قائمة المهام</h3><div class="spacer"></div>
      <span class="cat active" style="padding:4px 11px;font-size:.74rem">الكل</span>
      <span class="cat" style="padding:4px 11px;font-size:.74rem">متأخرة</span>
      <span class="cat" style="padding:4px 11px;font-size:.74rem">مسندة إليّ</span></div>
    <div class="card-body" style="padding:0">
      <?php foreach ([
        ['hi','تقديم المذكرة الجوابية','قضية MWQ-2026-0142 · متأخرة يومين','ع م','متأخرة','chip-red'],
        ['hi','مراجعة عقد التوريد قبل التوقيع','موكل: شركة الأفق · اليوم','ن س','اليوم','chip-red'],
        ['md','تحضير حافظة مستندات الجلسة','قضية MWQ-2026-0142 · ١٠ نوفمبر','ع م','قريبة','chip-gold'],
        ['md','إعداد عرض سعر — استشارة سنوية','موكل: مؤسسة البناء · ١٢ نوفمبر','خ د','قريبة','chip-gold'],
        ['lo','أرشفة ملفات القضايا المغلقة','بدون قضية · ٢٠ نوفمبر','ع م','لاحقاً','chip-gray'],
        ['lo','متابعة تحصيل فاتورة INV-0042','موكل: شركة الأفق · ٢٥ نوفمبر','ن س','لاحقاً','chip-gray'],
      ] as [$pri,$title,$meta,$who,$when,$cls]): ?>
      <div class="task"><span class="pri <?= $pri ?>"></span><span class="chk"></span>
        <div class="task-body"><b><?= $title ?></b><small><?= $meta ?></small></div>
        <div style="display:flex;align-items:center;gap:7px;flex:none">
          <span class="chip <?= $cls ?>"><?= $when ?></span>
          <span class="avatar" style="width:26px;height:26px;font-size:.7rem"><?= $who ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>مهمة جديدة</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:11px">
        <div class="fld"><label>العنوان <span class="req">*</span></label>
          <div class="input placeholder">مثال: مراجعة لائحة الدعوى</div></div>
        <div class="fld"><label>الربط</label>
          <div class="input filled">قضية MWQ-2026-0142</div>
          <span class="hint">يمكن ربط المهمة بموكل بدل قضية، أو تركها عامة للمكتب.</span></div>
        <div class="fieldset">
          <div class="fld"><label>الإسناد</label><div class="input filled">نورة السبيعي</div></div>
          <div class="fld"><label>الأولوية</label><div class="input filled">عالية</div></div>
        </div>
        <div class="fld"><label>تاريخ الاستحقاق</label><div class="input filled">٢٠٢٦-١١-١٠</div></div>
        <div style="display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:8px;
          background:#F7FAFC;border:1px solid var(--border)">
          <span class="chk done" style="width:17px;height:17px;margin:0"></span>
          <span style="font-size:.77rem">مرئية للعميل في بوابته</span>
        </div>
        <button class="btn btn-primary" style="justify-content:center"><?= ico('check') ?> حفظ المهمة</button>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>حِمل الفريق</h3></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([['ع م','عبدالله المطيري','٩','chip-red'],['ن س','نورة السبيعي','٧','chip-gold'],
                        ['خ د','خالد الدوسري','٥','chip-green'],['س ف','سارة الفهد','٣','chip-green']] as [$a,$n,$c,$cl]): ?>
        <div class="doc-row"><span class="avatar" style="width:28px;height:28px;font-size:.7rem"><?= $a ?></span>
          <div><b><?= $n ?></b><small>مهام مفتوحة</small></div>
          <div style="margin-inline-start:auto"><span class="chip <?= $cl ?>"><?= $c ?> مهام</span></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
