<?php
$pageTitle = 'مكتبة القوالب القانونية';
$active = 'templates';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٢. مكتبة القوالب</b> — ١٨ تصنيفاً · حقول ديناميكية · إصدارات · اعتماد</div>

<div class="page-head">
  <div><h1>مكتبة القوالب القانونية</h1>
    <p>قوالب معتمدة جاهزة، كل قالب بحقوله الديناميكية وإصداراته. اختر التصنيف ثم القالب ثم املأ الحقول.</p></div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('folder') ?> تصنيف جديد</button>
  <button class="btn btn-primary"><?= ico('plus') ?> قالب جديد</button>
</div>

<div class="card"><div class="card-body" style="padding:14px 20px">
  <div class="cat-row">
    <span class="cat active">الكل (٤٢)</span>
    <span class="cat">لوائح الدعاوى</span><span class="cat">المذكرات الجوابية</span>
    <span class="cat">مذكرات الدفاع</span><span class="cat">لوائح الاعتراض</span>
    <span class="cat">طلبات الاستئناف</span><span class="cat">عقود العمل</span>
    <span class="cat">عقود الشراكة</span><span class="cat">عقود التوريد</span>
    <span class="cat">عقود الخدمات</span><span class="cat">عقود الإيجار</span>
    <span class="cat">اتفاقيات السرية</span><span class="cat">اتفاقيات التسوية</span>
    <span class="cat">خطابات المطالبة</span><span class="cat">الإنذارات</span>
    <span class="cat">الإقرارات</span><span class="cat">المخالصات</span>
    <span class="cat">الوكالات</span><span class="cat">تلخيص الأحكام</span>
  </div>
</div></div>

<div class="tpl-grid">
  <?php
  $tpls = [
    ['لوائح الدعاوى', 'لائحة دعوى مطالبة مالية', 'لائحة مكتملة الأركان لمطالبة مالية مع بيان الوقائع والطلبات.', '٩ حقول', 'معتمد', 'chip-green', 'briefcase'],
    ['المذكرات الجوابية', 'مذكرة جوابية — دفع بعدم الثبوت', 'مذكرة جوابية على دعوى مطالبة، بهيكل ثلاثي: الشكل والموضوع والطلبات.', '٧ حقول', 'معتمد', 'chip-green', 'edit'],
    ['مذكرات الدفاع', 'مذكرة دفاع جنائية', 'هيكل دفاع مع الدفوع الشكلية والموضوعية والأسانيد النظامية.', '١١ حقلاً', 'معتمد', 'chip-green', 'shield'],
    ['لوائح الاعتراض', 'لائحة اعتراض على حكم', 'اعتراض على حكم مع بيان أسباب الاعتراض النظامية وميعاده.', '٨ حقول', 'قيد المراجعة', 'chip-gold', 'file'],
    ['عقود التوريد', 'عقد توريد بضائع', 'عقد توريد شامل: الكميات والمواصفات والدفعات والضمان.', '١٤ حقلاً', 'معتمد', 'chip-green', 'layers'],
    ['اتفاقيات السرية', 'اتفاقية عدم إفصاح (NDA)', 'اتفاقية سرية ثنائية مع تعريف المعلومة السرية ومدة الالتزام.', '٦ حقول', 'معتمد', 'chip-green', 'shield'],
    ['عقود الإيجار', 'عقد إيجار تجاري', 'عقد إيجار وحدة تجارية مع السعر والمدة والتجديد.', '١٢ حقلاً', 'معتمد', 'chip-green', 'folder'],
    ['المخالصات', 'مخالصة نهائية', 'مخالصة بإبراء الذمة مقابل مبلغ محدد، مع أثرها النظامي.', '٥ حقول', 'قيد المراجعة', 'chip-gold', 'check'],
    ['الوكالات', 'وكالة خاصة', 'توكيل خاص بنطاق محدد مع بيان الحدود الزمنية والموضوعية.', '٧ حقول', 'مسودة', 'chip-gray', 'users'],
  ];
  foreach ($tpls as [$cat, $name, $desc, $fields, $status, $cls, $icon]): ?>
  <div class="tpl">
    <div class="tpl-ico"><?= ico($icon) ?></div>
    <div style="font-size:.7rem;color:var(--text-muted);font-weight:600"><?= htmlspecialchars($cat) ?></div>
    <h4><?= htmlspecialchars($name) ?></h4>
    <p><?= htmlspecialchars($desc) ?></p>
    <div class="tpl-foot">
      <span class="chip <?= $cls ?>"><?= htmlspecialchars($status) ?></span>
      <span><?= htmlspecialchars($fields) ?></span>
      <span style="margin-inline-start:auto;color:var(--teal-text);font-weight:700">استخدام ←</span>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head"><h3>تعبئة الحقول الديناميكية — «لائحة دعوى مطالبة مالية»</h3>
    <div class="spacer"></div><span class="chip chip-navy">الإصدار ٣</span></div>
  <div class="card-body">
    <div class="fieldset">
      <div class="fld"><label>اسم المدّعي <span class="req">*</span></label>
        <div class="input filled">شركة الأفق للتجارة</div>
        <span class="hint">مستورد من ملف الموكل</span></div>
      <div class="fld"><label>اسم المدّعى عليه <span class="req">*</span></label>
        <div class="input filled">مؤسسة البناء الحديث</div></div>
      <div class="fld"><label>المبلغ المطالب به (ريال) <span class="req">*</span></label>
        <div class="input filled">٢٥٠٬٠٠٠٫٠٠</div></div>
      <div class="fld"><label>المحكمة المختصة</label>
        <div class="input placeholder">المحكمة التجارية بالرياض</div></div>
      <div class="fld" style="grid-column:1/-1"><label>ملخّص الوقائع</label>
        <div class="input placeholder" style="min-height:64px;display:flex;align-items:flex-start;padding-top:9px">
          لم يسدّد المدّعى عليه قيمة التوريدات المستلمة بموجب أمر الشراء رقم…</div></div>
    </div>
    <div style="display:flex;gap:9px;margin-top:18px;flex-wrap:wrap">
      <button class="btn btn-primary"><?= ico('file') ?> توليد المستند</button>
      <button class="btn btn-ghost"><?= ico('spark') ?> تحسين الصياغة بالذكاء الاصطناعي</button>
      <button class="btn btn-ghost"><?= ico('users') ?> استيراد بيانات القضية</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
