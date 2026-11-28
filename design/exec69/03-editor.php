<?php
$pageTitle = 'محرر المستندات';
$active = 'editor';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٣. محرر المستندات</b> — ترقيم · ترويسة وتذييل · شعار المكتب · DOCX/PDF · إصدارات</div>

<div class="page-head">
  <div><h1>محرر المستندات</h1>
    <p>تحرير مباشر مع ترويسة المكتب وتذييله، وحفظ تلقائي للإصدارات، وتصدير DOCX وPDF عربي سليم.</p></div>
  <div class="spacer"></div>
  <span class="chip chip-green"><?= ico('check') ?> حُفظ تلقائياً ١١:٤٢</span>
  <button class="btn btn-ghost"><?= ico('download') ?> PDF</button>
  <button class="btn btn-primary"><?= ico('download') ?> DOCX</button>
</div>

<div class="editor">
  <div class="editor-main">
    <div class="editor-toolbar">
      <span class="tool on">B</span><span class="tool"><i>I</i></span><span class="tool">U</span>
      <span class="tool-sep"></span>
      <span class="tool">H1</span><span class="tool">H2</span>
      <span class="tool-sep"></span>
      <span class="tool">≡</span><span class="tool">١.</span><span class="tool">☰</span>
      <span class="tool-sep"></span>
      <span class="tool on">RTL</span><span class="tool">LTR</span>
      <span class="tool-sep"></span>
      <span class="tool">⊞</span><span class="tool">🖼</span><span class="tool">✍</span><span class="tool">◎</span>
      <span class="tool-sep"></span>
      <span class="tool" title="إدراج رقم صفحة">#</span>
      <span style="margin-inline-start:auto;font-size:.72rem;color:var(--text-muted)">صفحة ١ من ٣</span>
    </div>

    <div class="paper-wrap">
      <div class="paper">
        <div class="doc-head">
          <div class="doc-logo" style="color:#fff">م</div>
          <div><strong>مكتب الأفق للمحاماة والاستشارات القانونية</strong>
            الرياض · طريق الملك فهد · هاتف ٠١١٢٢٣٣٤٤٥<br>
            ترخيص وزارة العدل رقم ٤٢١٥ · www.afaq-law.sa</div>
        </div>
        <h5>مذكرة جوابية</h5>
        <p><b>أولاً — من حيث الشكل:</b> تُقبل المذكرة شكلاً لرفعها في الميعاد النظامي المقرر
          نظاماً، وقد استوفت أوضاعها الشكلية كافة.</p>
        <p><b>ثانياً — من حيث الموضوع:</b> لم يُقدّم المدّعي ما يثبت تنفيذ الالتزام المترتب
          عليه قبل مطالبة المدّعى عليه، ويُعدّ عبء الإثبات عليه أصلاً وفقاً للقواعد العامة.</p>
        <p><b>ثالثاً — الطلبات:</b> نلتمس من عدالة المحكمة رفض الدعوى لعدم الثبوت،
          وإلزام المدّعي بالرسوم والمصاريف.</p>
        <div style="display:flex;justify-content:space-between;margin-top:34px;font-size:.7rem">
          <div><b>المحامي</b><br>عبدالله المطيري<br>رقم الترخيص ٤٢١٥</div>
          <div style="width:78px;height:78px;border:1.5px dashed #C9D5E1;border-radius:50%;
            display:grid;place-items:center;color:#5C6B7A;font-size:.7rem;text-align:center">
            ختم<br>المكتب</div>
        </div>
        <div class="doc-foot"><span>مكتب الأفق للمحاماة</span><span>صفحة ١ من ٣</span></div>
      </div>
    </div>
  </div>

  <aside class="editor-side">
    <div class="side-sec">
      <h5>حالة المستند</h5>
      <span class="chip chip-gold">قيد المراجعة</span>
      <div style="margin-top:9px;font-size:.74rem;color:var(--text-muted);line-height:1.7">
        مرتبط بقضية <b style="color:var(--navy)">٤٨٧٠٠٠٠١٦٢</b><br>
        الموكل: شركة الأفق للتجارة
      </div>
    </div>

    <div class="side-sec">
      <h5>الإصدارات</h5>
      <div class="ver active"><b>v٣</b> الحالي <span>١١:٤٢</span></div>
      <div class="ver"><b>v٢</b> قبل التدقيق <span>١١:٠٥</span></div>
      <div class="ver"><b>v١</b> التوليد الأولي <span>١٠:٤٠</span></div>
      <div style="margin-top:8px"><span style="font-size:.72rem;color:var(--teal-text);font-weight:700">استعادة إصدار سابق ←</span></div>
    </div>

    <div class="side-sec">
      <h5>هوية المكتب</h5>
      <div class="ver"><span style="width:22px;height:22px;border-radius:5px;background:var(--navy);
        color:#fff;display:grid;place-items:center;font-size:.7rem;font-weight:800">م</span> الشعار مُدرج</div>
      <div class="ver">ترويسة وتذييل ✓</div>
      <div class="ver">ختم وتوقيع ✓</div>
      <div class="ver">ترقيم الصفحات ✓</div>
    </div>

    <div class="side-sec" style="margin-bottom:0">
      <h5>تصدير</h5>
      <div style="display:flex;flex-direction:column;gap:6px">
        <button class="btn btn-ghost btn-sm"><?= ico('download') ?> Word (DOCX)</button>
        <button class="btn btn-ghost btn-sm"><?= ico('download') ?> PDF</button>
        <button class="btn btn-ghost btn-sm"><?= ico('file') ?> طباعة</button>
      </div>
    </div>
  </aside>
</div>

<div class="card">
  <div class="card-body" style="display:flex;gap:14px;align-items:flex-start">
    <?= ico('alert') ?>
    <div style="font-size:.78rem;color:var(--text-muted);line-height:1.75">
      <b style="color:var(--navy)">ملاحظة تقنية للاعتماد:</b> تصدير PDF عربي على استضافة cPanel
      يتطلب مكتبة بخط عربي مضمّن (mPDF). الخطوط المضمّنة في المشروع: Cairo و IBM Plex Sans Arabic.
      DOCX يُبنى مباشرة بلا مكتبة خارجية.
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
