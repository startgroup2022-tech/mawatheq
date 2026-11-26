<?php
$pageTitle = 'الموكلون';
$active = 'clients';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٤.٢ إدارة الموكلين</b> — فرد/شركة · ملف موحّد · سجلات القضايا والفواتير</div>

<div class="page-head">
  <div><h1>الموكلون</h1>
    <p>ملف موحّد لكل موكل: بياناته، قضاياه، استشاراته، عقوده، مستنداته، وفواتيره.</p></div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('download') ?> تصدير</button>
  <button class="btn btn-primary"><?= ico('plus') ?> موكل جديد</button>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi-label">إجمالي الموكلين</div><div class="kpi-value">١٤٨</div>
    <div class="kpi-meta">+٦ هذا الشهر</div></div>
  <div class="kpi navy"><div class="kpi-label">شركات</div><div class="kpi-value">٣٢</div>
    <div class="kpi-meta">٢١٫٦٪ من الإجمالي</div></div>
  <div class="kpi gold"><div class="kpi-label">أفراد</div><div class="kpi-value">١١٦</div>
    <div class="kpi-meta">٧٨٫٤٪</div></div>
  <div class="kpi green"><div class="kpi-label">موكلون نشطون</div><div class="kpi-value">٩٤</div>
    <div class="kpi-meta">لديهم قضية أو استشارة قائمة</div></div>
</div>

<div class="cols-2">
  <div class="card">
    <div class="card-head"><h3>قائمة الموكلين</h3><div class="spacer"></div>
      <span class="chip chip-gray">٥ من ١٤٨</span></div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>الموكل</th><th>النوع</th><th>القضايا</th><th>الرصيد</th><th>الحالة</th></tr></thead>
        <tbody>
          <tr><td><div class="who"><span class="av">أف</span>
            <div><b>شركة الأفق للتجارة</b><small style="color:var(--text-muted);font-size:.72rem">س.ت ١٠١٠٤٢٥٨٩٦</small></div></div></td>
            <td><span class="chip chip-navy">شركة</span></td><td class="num">٣</td>
            <td class="num" style="color:var(--danger);font-weight:600">٤٥٬٠٠٠</td>
            <td><span class="chip chip-green">نشط</span></td></tr>
          <tr><td><div class="who"><span class="av">ع م</span>
            <div><b>عبدالله المطيري</b><small style="color:var(--text-muted);font-size:.72rem">١٠٨٨٤٤٥٥٦٦</small></div></div></td>
            <td><span class="chip chip-gray">فرد</span></td><td class="num">١</td>
            <td class="num">٠</td><td><span class="chip chip-green">نشط</span></td></tr>
          <tr><td><div class="who"><span class="av">بن</span>
            <div><b>مؤسسة البناء الحديث</b><small style="color:var(--text-muted);font-size:.72rem">س.ت ١٠١٠٩٩٨٨٧٧</small></div></div></td>
            <td><span class="chip chip-navy">شركة</span></td><td class="num">٢</td>
            <td class="num" style="color:var(--danger);font-weight:600">١٢٠٬٠٠٠</td>
            <td><span class="chip chip-gold">متابعة</span></td></tr>
          <tr><td><div class="who"><span class="av">ن س</span>
            <div><b>نورة السبيعي</b><small style="color:var(--text-muted);font-size:.72rem">١٠٥٥٤٤٣٣٢٢</small></div></div></td>
            <td><span class="chip chip-gray">فرد</span></td><td class="num">١</td>
            <td class="num">٠</td><td><span class="chip chip-green">نشط</span></td></tr>
          <tr><td><div class="who"><span class="av">خ</span>
            <div><b>خالد الدوسري</b><small style="color:var(--text-muted);font-size:.72rem">١٠٧٧٦٦٥٥٤٤</small></div></div></td>
            <td><span class="chip chip-gray">فرد</span></td><td class="num">٠</td>
            <td class="num">٠</td><td><span class="chip chip-gray">مؤرشف</span></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>ملف موكل — شركة الأفق</h3><div class="spacer"></div>
      <button class="btn btn-ghost btn-sm"><?= ico('edit') ?> تعديل</button></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:var(--s-4)">
      <div style="display:flex;gap:12px;align-items:center">
        <span class="avatar" style="width:46px;height:46px;font-size:.86rem">أف</span>
        <div><b style="font-family:var(--serif);font-size:.94rem">شركة الأفق للتجارة</b>
          <div style="font-size:.74rem;color:var(--text-muted)">شركة · س.ت ١٠١٠٤٢٥٨٩٦ · الرياض</div></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;font-size:.78rem">
        <div><span style="color:var(--text-muted);font-size:.72rem">الهاتف</span><br>٠٥٥١٢٣٤٥٦٧</div>
        <div><span style="color:var(--text-muted);font-size:.72rem">البريد</span><br>info@afaq.sa</div>
        <div><span style="color:var(--text-muted);font-size:.72rem">الممثل النظامي</span><br>فهد العتيبي</div>
        <div><span style="color:var(--text-muted);font-size:.72rem">الرقم الضريبي</span><br>٣١٠١٢٣٤٥٦٧٨٩٠٣</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <span class="chip chip-navy">٣ قضايا</span>
        <span class="chip chip-teal">٥ استشارات</span>
        <span class="chip chip-gray">٢ عقد</span>
        <span class="chip chip-gold">٨ مستندات</span>
      </div>
      <div>
        <h4 style="margin-bottom:7px">الملاحظات الداخلية</h4>
        <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.28);
          border-radius:8px;padding:9px 11px;font-size:.75rem;color:#6B4A08">
          <?= ico('alert') ?> لا تظهر للعميل إطلاقاً — مرئية لفريق المكتب فقط.
        </div>
      </div>
      <div>
        <h4 style="margin-bottom:7px">آخر نشاط</h4>
        <div style="font-size:.76rem;color:var(--text-muted);line-height:1.85">
          · جلسة قضية ٤٨٧٠٠٠٠١٦٢ — ١٢ نوفمبر<br>
          · فاتورة INV-2026-0042 — ٤٥٬٠٠٠ ريال (غير مسددة)<br>
          · رفع مستند «عقد التوريد» — ٢ نوفمبر
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
