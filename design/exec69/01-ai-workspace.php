<?php
/**
 * معاينة تصميم — المساعد القانوني الذكي (مساحة عمل المحامي)
 * صفحة ثابتة (static) للاعتماد فقط، لا تتصل بقاعدة البيانات.
 */
$pageTitle = 'المساعد القانوني الذكي';
$active = 'ai-workspace';
$dir = 'rtl';
require __DIR__ . '/_shell.php';
?>

<div class="spec"><span class="tag">معاينة تصميم</span>
  <b>١. المساعد القانوني الذكي</b> — مساحة عمل المحامي · RTL · هوية مواثيق المعتمدة</div>

<div class="page-head">
  <div>
    <h1>المساعد القانوني الذكي</h1>
    <p>صياغة، تحليل، تلخيص، وتدقيق — مسنود بمكتبة الأنظمة والأحكام داخل مواثيق، مع مصدر لكل عبارة.</p>
  </div>
  <div class="spacer"></div>
  <button class="btn btn-ghost"><?= ico('book') ?> مكتبة القوالب</button>
  <button class="btn btn-primary"><?= ico('plus') ?> محادثة جديدة</button>
</div>

<div class="chat">
  <!-- المحادثات المحفوظة (A3 + A4) -->
  <aside class="chat-side">
    <div class="chat-side-head">
      <div class="chat-new"><?= ico('plus') ?> محادثة جديدة</div>
    </div>
    <div style="padding:10px 12px 6px"><div class="nav-label" style="color:#5C6B7A">مرتبطة بقضية</div></div>

    <a class="conv active"><strong>مذكرة جوابية — قضية ٤٨٧٠٠٠٠١٦٢</strong><span>قضية تجارية · منذ ٣ دقائق</span></a>
    <a class="conv"><strong>تحليل حكم — تعويض عن ضرر</strong><span>المحكمة التجارية · أمس</span></a>

    <div style="padding:12px 12px 6px"><div class="nav-label" style="color:#5C6B7A">عامة</div></div>
    <a class="conv"><strong>صياغة إنذار قانوني</strong><span>بدون ربط · ٣ نوفمبر</span></a>
    <a class="conv"><strong>مقارنة عقدَي توريد</strong><span>مستندان · ٢ نوفمبر</span></a>
    <a class="conv"><strong>تدقيق صياغة مذكرة دفاع</strong><span>قضية ٤٧٧٢٨٤٦٢٣١ · ٢٨ أكتوبر</span></a>
  </aside>

  <div class="chat-main">
    <div class="chat-top">
      <span class="chip chip-navy">قضية ٤٨٧٠٠٠٠١٦٢</span>
      <span class="chip chip-teal">مذكرة جوابية</span>
      <span class="chip chip-gray">مسودة</span>
      <div class="spacer" style="flex:1"></div>
      <span style="font-size:.72rem;color:var(--text-muted)">النموذج: نموذج سريع · ١٢٤٠ رمز</span>
      <button class="btn btn-ghost btn-sm"><?= ico('save') ?> حفظ في القضية</button>
    </div>

    <div class="chat-body">
      <div class="msg user">
        <div class="bubble">اكتب لي مذكرة جوابية على دعوى مطالبة مالية بمبلغ ٢٥٠٬٠٠٠ ريال،
          الدفع بعدم صحة المطالبة لعدم تقديم ما يثبت تنفيذ الالتزام.</div>
      </div>

      <div class="msg ai">
        <div class="bubble">
          <b>مذكرة جوابية (مسودة أولية)</b><br><br>
          أتشرف بتقديم هذه المذكرة الجوابية على الدعوى المقامة من المدّعي بطلب مبلغ
          ٢٥٠٬٠٠٠ ريال، وذلك على النحو الآتي:<br><br>
          <b>أولاً — من حيث الشكل:</b> تُقبل المذكرة شكلاً لرفعها في الميعاد النظامي.<br><br>
          <b>ثانياً — من حيث الموضوع:</b> لم يُقدّم المدّعي ما يثبت تنفيذ الالتزام المترتب
          عليه، ويُعدّ عبء الإثبات عليه أصلاً.<br><br>
          <b>ثالثاً — الطلبات:</b> نلتمس رفض الدعوى لعدم الثبوت.
          <div class="cite">
            <div class="cite-item">📚 <span><b>نظام المعاملات المدنية</b> — المادة (١١٦) ·
              <span class="verified">✓ موثّق</span> · <a href="#">المصدر الرسمي</a></span></div>
            <div class="cite-item">⚖️ <span><b>حكم تجاري رقم ٤٨٧٠٠٠٠١٦٢</b> — المحكمة التجارية ·
              <span class="verified">✓ موثّق</span> · <a href="#">عرض الحكم</a></span></div>
          </div>
        </div>
      </div>

      <div class="disclaimer">
        <?= ico('alert') ?>
        <span>المستند المولَّد <b>مسودة تحتاج مراجعة محامٍ مرخّص</b>. الاستشهادات أعلاه
          مرتبطة بمصادرها ويمكن التحقق منها بالنقر؛ ولم يُختلق أي نص نظامي.</span>
      </div>
    </div>

    <div class="chat-input">
      <button class="iconbtn" title="إرفاق PDF / Word / صورة"><?= ico('paperclip') ?></button>
      <div class="field">اكتب سؤالك القانوني أو اطلب صياغة…</div>
      <button class="btn btn-teal"><?= ico('send') ?></button>
    </div>
    <div style="padding:0 16px 10px;display:flex;gap:6px;flex-wrap:wrap">
      <span class="attach-chip"><?= ico('file') ?> عقد-التوريد.pdf · ٢٤٠ ك.ب</span>
      <span class="attach-chip"><?= ico('image') ?> صورة-الحكم.jpg · ٨٨ ك.ب</span>
    </div>
  </div>
</div>

<!-- القدرات: الـ19 وظيفة المطلوبة -->
<div class="card">
  <div class="card-head"><h3>قدرات المساعد</h3><div class="spacer"></div>
    <span class="chip chip-teal">١٩ وظيفة</span></div>
  <div class="card-body">
    <div class="cols-3">
      <div>
        <h4>الصياغة</h4>
        <p style="font-size:.78rem;color:var(--text-muted);margin:0">
          لوائح الدعاوى · المذكرات الجوابية · مذكرات الدفاع · الاعتراض والاستئناف ·
          العقود والاتفاقيات · خطابات المطالبة · الإنذارات القانونية</p>
      </div>
      <div>
        <h4>التحليل</h4>
        <p style="font-size:.78rem;color:var(--text-muted);margin:0">
          تحليل الأحكام · تلخيص المستندات · تحليل العقود والمخاطر · اقتراح الدفوع
          والأسانيد للمراجعة</p>
      </div>
      <div>
        <h4>المعالجة</h4>
        <p style="font-size:.78rem;color:var(--text-muted);margin:0">
          التدقيق اللغوي والقانوني · إعادة الصياغة · مقارنة المستندات ·
          استخراج البيانات من المرفوعات</p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_shell_end.php'; ?>
