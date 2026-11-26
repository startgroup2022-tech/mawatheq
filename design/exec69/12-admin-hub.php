<?php
$pageTitle = 'لوحة إدارة المنصة';
$active = 'dashboard';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٨. لوحة الإدارة</b> — القوالب · النماذج · الشات بوت · التخزين · المزايا · سجل الوصول</div>

<div class="page-head">
  <div><h1>لوحة إدارة المنصة</h1>
    <p>الوحدات الأربع الناقصة اليوم: القوالب، كتالوج النماذج، الشات بوت، والتخزين — إضافة إلى قرار السرية.</p></div>
  <div class="spacer"></div>
  <span class="chip chip-navy">super_admin</span>
  <button class="btn btn-primary"><?= ico('plus') ?> إضافة</button>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi-label">المكاتب المسجّلة</div><div class="kpi-value">٣٢</div>
    <div class="kpi-meta">٢٨ نشط · ٤ معلّق</div></div>
  <div class="kpi green"><div class="kpi-label">استهلاك الذكاء الاصطناعي</div><div class="kpi-value">١٢٤٬٨٠٠</div>
    <div class="kpi-meta">طلب هذا الشهر · ٩٨٫٢٪ ناجح</div></div>
  <div class="kpi gold"><div class="kpi-label">التخزين المستخدم</div><div class="kpi-value">٤٨٢ ج.ب</div>
    <div class="kpi-meta">من ١٬٠٠٠ ج.ب مخصّصة</div></div>
  <div class="kpi navy"><div class="kpi-label">القوالب المنشورة</div><div class="kpi-value">٤٢</div>
    <div class="kpi-meta">١٨ تصنيفاً · ٣ قيد المراجعة</div></div>
</div>

<div class="cols-2">
  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>كتالوج نماذج الذكاء الاصطناعي</h3><div class="spacer"></div>
        <span class="chip chip-teal">جديد</span></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>النموذج</th><th>المزوّد</th><th>السياق</th><th>التكلفة</th><th>الحالة</th></tr></thead>
        <tbody>
          <tr><td><b>نموذج سريع</b></td><td>OpenRouter</td><td class="num">٨٢ك</td>
            <td><span class="chip chip-green">مجاني</span></td><td><span class="chip chip-green">مفعّل</span></td></tr>
          <tr><td><b>نموذج متوازن</b></td><td>OpenRouter</td><td class="num">١٢٨ك</td>
            <td><span class="chip chip-gold">منخفض</span></td><td><span class="chip chip-green">مفعّل</span></td></tr>
          <tr><td><b>نموذج متقدم</b></td><td>Anthropic</td><td class="num">٢٠٠ك</td>
            <td><span class="chip chip-navy">متوسط</span></td><td><span class="chip chip-green">مفعّل</span></td></tr>
          <tr><td><b>نموذج محلي</b></td><td>متوافق OpenAI</td><td class="num">٣٢ك</td>
            <td><span class="chip chip-green">مجاني</span></td><td><span class="chip chip-gray">معطّل</span></td></tr>
        </tbody>
      </table></div>
      <div class="card-body" style="border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm"><?= ico('plus') ?> نموذج</button>
        <button class="btn btn-ghost btn-sm"><?= ico('chart') ?> حدود الاستهلاك</button>
        <button class="btn btn-ghost btn-sm"><?= ico('shield') ?> سياسة الخصوصية</button>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>سجل وصول الإدارة لمحتوى المكاتب</h3><div class="spacer"></div>
        <span class="chip chip-gold">يتطلب قراراً</span></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([
          ['أ. الإدارة','تنزيل مستند — شركة الأفق','دعم فني · تذكرة #٤٢٢','١١:٠٤'],
          ['م. الدعم','عرض استشارة — مؤسسة البناء','طلب العميل · تذكرة #٤١٩','أمس'],
        ] as [$who,$what,$why,$when]): ?>
        <div class="doc-row"><span class="avatar" style="width:28px;height:28px;font-size:.7rem">
            <?= mb_substr($who,0,2) ?></span>
          <div><b><?= $what ?></b><small><?= $who ?> · <?= $why ?></small></div>
          <div style="margin-inline-start:auto;font-size:.72rem;color:var(--text-muted)"><?= $when ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="card-body" style="border-top:1px solid var(--border)">
        <div class="disclaimer"><?= ico('alert') ?>
          <span>الخيار المقترح: <b>الوصول مسموح لكنه مسجَّل إلزامياً</b> بمرجع التذكرة —
            يحافظ على الدعم ويصنع محاسبة. البديل: حجب افتراضي مع طلب موافقة.</span></div>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>الوحدات الإدارية</h3></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([
          ['book','إدارة القوالب','٤٢ قالباً · ١٨ تصنيفاً','chip-green','موجود'],
          ['spark','كتالوج النماذج','٤ نماذج · ٣ مزوّدين','chip-teal','جديد'],
          ['bot','إعدادات الشات بوت','٣٢ مكتباً · ٩ مفعّل','chip-teal','جديد'],
          ['layers','إدارة التخزين','٤٨٢ ج.ب · ٦٤٪','chip-gold','جديد'],
          ['receipt','مفاتيح مزايا الباقات','١٠ حدود · ٣ باقات','chip-gold','جديد'],
          ['shield','الاحتفاظ بالبيانات','٤ سياسات · غير منفَّذة','chip-red','ناقص'],
        ] as [$i,$n,$s,$c,$t]): ?>
        <div class="doc-row"><span class="doc-ico"><?= ico($i) ?></span>
          <div><b><?= $n ?></b><small><?= $s ?></small></div>
          <div style="margin-inline-start:auto"><span class="chip <?= $c ?>"><?= $t ?></span></div></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>حدود الاستهلاك لكل مكتب</h3><div class="spacer"></div>
        <span class="chip chip-gray">أعلى ٥</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:11px">
        <?php foreach ([['مكتب الأفق','٩٢٪','#C7492A'],['البناء الحديث','٧٨٪','var(--gold-text)'],
                        ['الرياض للمحاماة','٦٤٪','var(--gold-text)'],['العدالة','٤١٪','var(--success-text)'],
                        ['الميزان','٢٢٪','var(--success-text)']] as [$n,$p,$c]): ?>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:.76rem;margin-bottom:4px">
            <span><?= $n ?></span><b style="color:<?= $c ?>"><?= $p ?></b></div>
          <div style="height:6px;border-radius:4px;background:#EEF2F6;overflow:hidden">
            <div style="height:100%;width:<?= $p ?>;background:<?= $c ?>;border-radius:4px"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
