<?php
$pageTitle = 'الجلسات والمواعيد';
$active = 'calendar';
require __DIR__ . '/_shell.php';

$days = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
// نوفمبر ٢٠٢٦ يبدأ يوم الأحد؛ ٣٠ يوماً.
$events = [
  4  => [['court','جلسة — ٤٨٧٠٠٠٠١٦٢']],
  9  => [['consult','استشارة — ع. المطيري ١١:٠٠']],
  10 => [['meet','اجتماع فريق ٠٩:٣٠'],['dead','ميعاد اعتراض']],
  12 => [['court','جلسة — المحكمة التجارية']],
  16 => [['consult','استشارة — ن. السبيعي']],
  18 => [['court','جلسة — ٤٧٧٢٨٤٦٢٣١']],
  23 => [['meet','مراجعة شهرية']],
  25 => [['dead','ميعاد استئناف']],
  28 => [['court','جلسة تحضيرية']],
];
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٤.٤ الجلسات والمواعيد</b> — تقويم شهري/أسبوعي/يومي · منع التعارض · مهل الاعتراض والاستئناف</div>

<div class="page-head">
  <div><h1>الجلسات والمواعيد</h1>
    <p>تقويم حقيقي بثلاثة عروض، مع منع تعارض المواعيد وتنبيهات مهل الاعتراض والاستئناف.</p></div>
  <div class="spacer"></div>
  <div style="display:flex;gap:2px;background:var(--surface);border:1px solid var(--border);
    border-radius:9px;padding:3px">
    <span class="cat" style="border:0;padding:5px 13px">يومي</span>
    <span class="cat" style="border:0;padding:5px 13px">أسبوعي</span>
    <span class="cat active" style="padding:5px 13px">شهري</span>
  </div>
  <button class="btn btn-primary"><?= ico('plus') ?> موعد جديد</button>
</div>

<div class="card">
  <div class="card-head">
    <button class="iconbtn">‹</button>
    <h3 style="margin:0">نوفمبر ٢٠٢٦</h3>
    <button class="iconbtn">›</button>
    <div class="spacer"></div>
    <span class="chip chip-navy">جلسة</span>
    <span class="chip chip-teal">استشارة</span>
    <span class="chip chip-gold">اجتماع</span>
    <span class="chip chip-red">ميعاد نظامي</span>
  </div>
  <div class="cal">
    <?php foreach ($days as $d): ?><div class="cal-h"><?= $d ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < 35; $i++):
      $day = $i + 1; // عمود الأحد = ١
      $isMute = $day > 30;
      $isToday = $day === 11;
    ?>
      <div class="cal-d<?= $isMute ? ' mute' : '' ?><?= $isToday ? ' today' : '' ?>">
        <div class="d"><?= $isMute ? '' : $day ?></div>
        <?php if (!$isMute && isset($events[$day])): foreach ($events[$day] as [$cls, $txt]): ?>
          <div class="ev <?= $cls ?>"><?= $txt ?></div>
        <?php endforeach; endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="cols-2">
  <div class="card">
    <div class="card-head"><h3>المواعيد القادمة</h3><div class="spacer"></div>
      <span class="chip chip-gold">٣ خلال ٧ أيام</span></div>
    <div class="card-body" style="padding:0">
      <?php foreach ([
        ['١٢ نوفمبر · ١٠:٣٠ ص', 'جلسة مرافعة — المحكمة التجارية بالرياض', 'قضية MWQ-2026-0142', 'chip-navy'],
        ['١٠ نوفمبر · ٠٩:٣٠ ص', 'اجتماع فريق — مراجعة ملفات الأسبوع', '٤ مشاركين', 'chip-gold'],
        ['١٠ نوفمبر', 'آخر يوم للاعتراض على الحكم', 'ميعاد نظامي — لا يُمدَّد', 'chip-red'],
      ] as [$when, $what, $sub, $c]): ?>
      <div class="doc-row"><span class="doc-ico"><?= ico('calendar') ?></span>
        <div><b><?= $what ?></b><small><?= $when ?> · <?= $sub ?></small></div>
        <div style="margin-inline-start:auto"><span class="chip <?= $c ?>"><?= explode(' ', $when)[0] ?></span></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>موعد جديد</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:11px">
      <div class="fld"><label>نوع الموعد</label>
        <div class="input filled">جلسة قضائية</div></div>
      <div class="fld"><label>القضية</label>
        <div class="input filled">MWQ-2026-0142 — مطالبة مالية</div></div>
      <div class="fieldset">
        <div class="fld"><label>التاريخ</label><div class="input filled">٢٠٢٦-١١-١٢</div></div>
        <div class="fld"><label>الوقت</label><div class="input filled">١٠:٣٠</div></div>
      </div>
      <div class="fld"><label>المحكمة والموقع</label>
        <div class="input filled">المحكمة التجارية بالرياض — الدائرة الثالثة</div></div>
      <div class="disclaimer"><?= ico('alert') ?>
        <span>النظام يمنع تعارض المواعيد: سيُرفض الحفظ إذا تبيّن تعارض مع موعد قائم للمحامي نفسه.</span></div>
      <button class="btn btn-primary" style="justify-content:center"><?= ico('check') ?> حفظ الموعد</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
