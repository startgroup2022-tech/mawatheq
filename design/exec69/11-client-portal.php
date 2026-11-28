<?php
$pageTitle = 'بوابة الموكل';
$active = 'dashboard';
// بوابة العميل تُعرض بهيكل العميل لا بهيكل المحامي.
$clientShell = true;
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٦. بوابة الموكل</b> — القضايا · الجلسات · المستندات المشتركة · الفواتير · الدفع</div>

<div class="portal-hero">
  <h1 style="color:#fff">أهلاً عبدالله 👋</h1>
  <p>لديك جلسة يوم ١٢ نوفمبر، وفاتورة مستحقة، ومستندان جديدان شاركهما محاميك معك.</p>
  <div style="display:flex;gap:9px;margin-top:16px;position:relative;z-index:1;flex-wrap:wrap">
    <button class="btn btn-teal"><?= ico('calendar') ?> تفاصيل الجلسة</button>
    <button class="btn btn-gold"><?= ico('receipt') ?> سداد الفاتورة</button>
  </div>
</div>

<div class="kpis">
  <div class="kpi navy"><div class="kpi-label">قضاياي</div><div class="kpi-value">٢</div>
    <div class="kpi-meta">قضية قائمة · واحدة قيد المراجعة</div></div>
  <div class="kpi gold"><div class="kpi-label">الجلسة القادمة</div>
    <div class="kpi-value" style="font-size:1.05rem">١٢ نوفمبر</div>
    <div class="kpi-meta">١٠:٣٠ ص · المحكمة التجارية</div></div>
  <div class="kpi"><div class="kpi-label">مستندات مشتركة</div><div class="kpi-value">٤</div>
    <div class="kpi-meta">٢ جديدان هذا الأسبوع</div></div>
  <div class="kpi" style="--x:0"><div class="kpi-label" style="color:var(--danger)">مستحق عليّ</div>
    <div class="kpi-value" style="color:var(--danger)">٤٥٬٠٠٠</div>
    <div class="kpi-meta">ريال · فاتورة INV-2026-0042</div></div>
</div>

<div class="cols-2">
  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>قضاياي</h3><div class="spacer"></div>
        <span class="chip chip-gray">٢</span></div>
      <div class="card-body" style="padding:0">
        <div class="doc-row"><span class="doc-ico"><?= ico('briefcase') ?></span>
          <div><b>مطالبة مالية — شركة الأفق</b>
            <small>MWQ-2026-0142 · جلسة ١٢ نوفمبر · ٣ مهام مكتملة</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-green">قائمة</span></div></div>
        <div class="doc-row"><span class="doc-ico"><?= ico('briefcase') ?></span>
          <div><b>نزاع عقد إيجار</b><small>MWQ-2026-0138 · بانتظار رأي المحامي</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-gold">قيد المراجعة</span></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>المستندات التي شاركها محاميك</h3><div class="spacer"></div>
        <span class="chip chip-teal">٤ مستندات</span></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([
          ['PDF','مذكرة جوابية — نسخة للاطلاع','شاركها: ع. المطيري · ٤ نوفمبر','جديد'],
          ['PDF','محضر جلسة ٢٠ أكتوبر','شاركها: ع. المطيري · ٢١ أكتوبر',''],
          ['PDF','فاتورة INV-2026-0042','تلقائي · ١ نوفمبر',''],
        ] as [$t,$n,$s,$badge]): ?>
        <div class="doc-row"><span class="doc-ico"><?= $t ?></span>
          <div><b><?= $n ?></b><small><?= $s ?></small></div>
          <div style="margin-inline-start:auto;display:flex;align-items:center;gap:7px">
            <?php if ($badge): ?><span class="chip chip-gold">جديد</span><?php endif; ?>
            <span style="color:var(--teal-text)"><?= ico('download') ?></span></div></div>
        <?php endforeach; ?>
        <div style="padding:10px 16px;font-size:.73rem;color:var(--text-muted);
          background:#FBFCFD;border-top:1px solid var(--border)">
          يظهر هنا فقط ما يسمح محاميك بمشاركته — لا مستندات داخلية ولا ملاحظات المكتب.
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>ما المطلوب مني</h3></div>
      <div class="card-body" style="padding:0">
        <div class="task"><span class="pri hi"></span><span class="chk"></span>
          <div class="task-body"><b>سداد فاتورة INV-2026-0042</b>
            <small>٤٥٬٠٠٠ ريال · استحقت ١٠ نوفمبر</small></div>
          <div style="flex:none"><button class="btn btn-teal btn-sm">سداد</button></div></div>
        <div class="task"><span class="pri md"></span><span class="chk"></span>
          <div class="task-body"><b>رفع أصل عقد التوريد</b><small>طلبه محاميك · قبل الجلسة</small></div>
          <div style="flex:none"><button class="btn btn-ghost btn-sm">رفع</button></div></div>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>المواعيد</h3></div>
      <div class="card-body" style="padding:0">
        <div class="doc-row"><span class="doc-ico"><?= ico('calendar') ?></span>
          <div><b>جلسة مرافعة</b><small>١٢ نوفمبر · ١٠:٣٠ ص</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-navy">قادمة</span></div></div>
        <div class="doc-row"><span class="doc-ico"><?= ico('clock') ?></span>
          <div><b>استشارة مرئية</b><small>١٦ نوفمبر · ٠٤:٠٠ م</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-teal">مؤكدة</span></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>فواتيري</h3></div>
      <div class="card-body" style="padding:0">
        <div class="doc-row"><span class="doc-ico"><?= ico('receipt') ?></span>
          <div><b>INV-2026-0042</b><small>٤٥٬٠٠٠ ريال · مستحقة</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-red">غير مسددة</span></div></div>
        <div class="doc-row"><span class="doc-ico"><?= ico('receipt') ?></span>
          <div><b>INV-2026-0038</b><small>٢٠٬٠٠٠ ريال · مسددة</small></div>
          <div style="margin-inline-start:auto"><span class="chip chip-green">مسددة</span></div></div>
      </div>
      <div class="card-body" style="border-top:1px solid var(--border);display:flex;gap:8px">
        <button class="btn btn-teal" style="flex:1;justify-content:center"><?= ico('wallet') ?> سداد الآن</button>
        <button class="btn btn-ghost"><?= ico('download') ?></button>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>تواصل مع محاميك</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;gap:9px;align-items:center">
          <span class="avatar" style="width:36px;height:36px">ع م</span>
          <div><b style="font-size:.84rem">عبدالله المطيري</b>
            <div style="font-size:.72rem;color:var(--text-muted)">محامٍ مسؤول عن قضيتك</div></div>
        </div>
        <div style="display:flex;gap:7px">
          <button class="btn btn-ghost btn-sm" style="flex:1;justify-content:center"><?= ico('send') ?> رسالة</button>
          <button class="btn btn-ghost btn-sm" style="flex:1;justify-content:center"><?= ico('calendar') ?> حجز</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
