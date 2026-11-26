<?php
$pageTitle = 'الشات بوت';
$active = 'chatbot';
require __DIR__ . '/_shell.php';
?>
<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>٥. الشات بوت الخاص بالمكتب</b> — widget للموقع · رابط مستقل · تحويل لموظف · WhatsApp</div>

<div class="page-head">
  <div><h1>الشات بوت الخاص بالمكتب</h1>
    <p>بوت يجيب من معرفة المكتب وقوالبه، ويحوّل للموظف عند الحاجة. يُضمَّن في موقع المكتب بسطر واحد.</p></div>
  <div class="spacer"></div>
  <span class="chip chip-green"><?= ico('check') ?> مُفعّل</span>
  <button class="btn btn-primary"><?= ico('save') ?> حفظ</button>
</div>

<div class="cols-2">
  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>معاينة مباشرة</h3><div class="spacer"></div>
        <span class="chip chip-teal">هكذا يراه الزائر</span></div>
      <div class="card-body" style="background:#EDF1F5;display:grid;place-items:center">
        <div class="bot-preview" style="width:100%;max-width:352px">
          <div class="bot-head">
            <span class="av">م</span>
            <div><b>مكتب الأفق للمحاماة</b><small>مساعد قانوني · يرد فوراً</small></div>
            <span class="bot-online"><i></i> متصل</span>
          </div>
          <div class="bot-body">
            <div class="bot-msg b">السلام عليكم 👋 أنا مساعد مكتب الأفق. كيف أقدر أساعدك؟</div>
            <div class="bot-opts">
              <span class="bot-opt">الاستشارات</span>
              <span class="bot-opt">الأتعاب</span>
              <span class="bot-opt">حجز موعد</span>
            </div>
            <div class="bot-msg u">كم أتعاب قضية تجارية؟</div>
            <div class="bot-msg b">أتعاب القضايا التجارية تُحدَّد بعد دراسة الملف، وتبدأ من ١٥٬٠٠٠ ريال.
              تحب أحوّلك لمحامٍ للتقيم الدقيق؟</div>
            <div class="bot-opts">
              <span class="bot-opt">نعم، حوّلني</span>
              <span class="bot-opt">احجز استشارة</span>
            </div>
          </div>
          <div class="bot-input"><span class="f">اكتب رسالتك…</span>
            <span class="bot-send"><?= ico('send') ?></span></div>
          <div class="bot-note">مدعوم بمواثيق · لا يقدّم رأياً قانونياً ملزماً</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>التضمين في موقع المكتب</h3></div>
      <div class="card-body">
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:9px">
          سطر واحد قبل <code>&lt;/body&gt;</code>:</p>
        <div style="background:var(--navy-dark);color:#A8E6F5;border-radius:8px;padding:11px 13px;
          font-family:ui-monospace,monospace;font-size:.73rem;direction:ltr;text-align:left;
          overflow-x:auto;white-space:nowrap">
          &lt;script src="https://mawatheq.com/bot.js?id=<b style="color:#FFD98A">afaq</b>" defer&gt;&lt;/script&gt;
        </div>
        <div style="display:flex;gap:8px;margin-top:11px;flex-wrap:wrap">
          <button class="btn btn-ghost btn-sm"><?= ico('file') ?> نسخ الكود</button>
          <button class="btn btn-ghost btn-sm"><?= ico('layers') ?> صفحة مستقلة</button>
          <button class="btn btn-ghost btn-sm"><?= ico('search') ?> فحص التثبيت</button>
        </div>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:var(--s-5);min-width:0">
    <div class="card">
      <div class="card-head"><h3>إعداد البوت</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:11px">
        <div class="fld"><label>اسم البوت</label><div class="input filled">مساعد مكتب الأفق</div></div>
        <div class="fld"><label>رسالة الترحيب</label>
          <div class="input filled">السلام عليكم 👋 أنا مساعد مكتب الأفق. كيف أقدر أساعدك؟</div></div>
        <div class="fld"><label>نبرة الرد</label><div class="input filled">رسمية ومختصرة</div></div>
        <div class="fld"><label>مصادر المعرفة</label>
          <div class="input filled">معرفة المكتب (١٢ مقالاً) + مكتبة القوالب + الأسئلة الشائعة</div>
          <span class="hint">لا يصل البوت لبيانات القضايا أو الموكلين إطلاقاً.</span></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>قنوات التشغيل</h3></div>
      <div class="card-body" style="padding:0">
        <?php foreach ([
          ['widget','موقع المكتب (widget)','مُفعّل','chip-green'],
          ['layers','صفحة مستقلة','مُفعّل','chip-green'],
          ['bot','WhatsApp Business','غير مُهيّأ','chip-gray'],
          ['users','تحويل لموظف','مُفعّل','chip-green'],
        ] as [$i,$n,$s,$c]): ?>
        <div class="doc-row"><span class="doc-ico"><?= ico($i) ?></span>
          <div><b><?= $n ?></b><small><?= $s ?></small></div>
          <div style="margin-inline-start:auto"><span class="chip <?= $c ?>"><?= $s ?></span></div></div>
        <?php endforeach; ?>
      </div>
      <div class="card-body" style="border-top:1px solid var(--border)">
        <div class="disclaimer"><?= ico('alert') ?>
          <span><b>WhatsApp</b> يحتاج بيانات اعتماد Meta الرسمية
            (توكن + رقم + رمز تحقق) من صاحب الحساب. لن يُفعَّل بلا تلك البيانات.</span></div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_end.php'; ?>
