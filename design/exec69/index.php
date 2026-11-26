<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>مواثيق — معاينات تصميم Execution 69</title>
<style>
@font-face{font-family:'Cairo';src:url('assets/fonts/Cairo-Bold.ttf') format('truetype');font-weight:700;font-display:block;}
@font-face{font-family:'Cairo';src:url('assets/fonts/Cairo-ExtraBold.ttf') format('truetype');font-weight:800;font-display:block;}
@font-face{font-family:'IBMPlexArabic';src:url('assets/fonts/IBMPlexSansArabic-Regular.ttf') format('truetype');font-weight:400;font-display:block;}
@font-face{font-family:'IBMPlexArabic';src:url('assets/fonts/IBMPlexSansArabic-SemiBold.ttf') format('truetype');font-weight:600;font-display:block;}
:root{--navy:#1E3A5F;--navy-dark:#0F1F33;--teal:#2BA8C4;--teal-text:#156B80;--gold:#F5A623;
  --bg:#F6F8FA;--surface:#fff;--border:#E3E9EF;--text:#16232F;--muted:#5C6B7A;
  --serif:'Cairo',sans-serif;--body:'IBMPlexArabic',sans-serif;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:var(--body);line-height:1.75;font-size:15px}
h1,h2,h3{font-family:var(--serif);font-weight:800;color:var(--navy-dark);margin:0 0 .5em;line-height:1.35}
a{color:var(--teal-text)}
.hero{background:linear-gradient(135deg,var(--navy-dark),var(--navy) 55%,#1B4B78);color:#fff;
  padding:52px 32px;text-align:center}
.hero h1{color:#fff;font-size:2rem;margin-bottom:10px}
.hero p{color:rgba(255,255,255,.82);margin:0 auto;max-width:66ch;font-size:.92rem}
.hero .meta{margin-top:20px;display:flex;gap:9px;justify-content:center;flex-wrap:wrap}
.hero .pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
  border-radius:20px;padding:5px 14px;font-size:.76rem;color:#fff}
.wrap{max-width:1240px;margin:0 auto;padding:32px 20px 60px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:24px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;
  box-shadow:0 2px 10px rgba(15,31,51,.06);display:flex;flex-direction:column}
.card .thumb{display:block;background:#EDF1F5;border-bottom:1px solid var(--border);position:relative;
  aspect-ratio:16/10;overflow:hidden}
.card .thumb img{width:100%;height:100%;object-fit:cover;object-position:top;display:block}
.card .body{padding:16px 18px;display:flex;flex-direction:column;gap:6px;flex:1}
.card h3{margin:0;font-size:1rem}
.card p{margin:0;font-size:.8rem;color:var(--muted);line-height:1.7}
.card .tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.tag{font-size:.7rem;font-weight:600;padding:3px 9px;border-radius:20px}
.tag.new{background:rgba(43,168,196,.13);color:var(--teal-text)}
.tag.gap{background:rgba(199,73,42,.11);color:#A6371D}
.tag.fix{background:rgba(245,166,35,.16);color:#7A5409}
.card .links{margin-top:auto;padding-top:11px;display:flex;gap:9px;font-size:.78rem;font-weight:600}
.note{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px 24px;
  margin-bottom:28px;font-size:.86rem;color:var(--muted);line-height:1.85}
.note b{color:var(--navy)}
.note code{background:#F0F4F8;padding:1px 6px;border-radius:4px;font-size:.82em}
@media(max-width:520px){.grid{grid-template-columns:1fr}.hero{padding:36px 18px}.hero h1{font-size:1.4rem}}
</style>
</head>
<body>
<div class="hero">
  <h1>معاينات تصميم — Execution 69</h1>
  <p>تصاميم الواجهات الجديدة لمنصة الذكاء الاصطناعي القانوني وإدارة مكاتب المحاماة،
     بهوية مواثيق المعتمدة (كحلي · تركواز · ذهبي). للمراجعة والاعتماد قبل التنفيذ.</p>
  <div class="meta">
    <span class="pill">١٢ شاشة</span>
    <span class="pill">RTL + LTR</span>
    <span class="pill">٢٤ لقطة</span>
    <span class="pill">٠ مخالفة تباين</span>
    <span class="pill">الفرع feature/ai-workspace-firm-suite</span>
  </div>
</div>

<div class="wrap">
  <div class="note">
    <b>كيف تُقرأ هذه الصفحة:</b> كل بطاقة تعرض لقطة حقيقية من صفحة HTML عاملة في
    <code>docs/design/exec69/</code> — ليست صورة مرسومة، بل صفحة تُعرض بالمتصفح.
    اجتازت كلها فحص آلي: لا نص أصغر من ١١ بكسل، لا تمرير أفقي على الهاتف،
    <code>&lt;h1&gt;</code> واحد لكل صفحة، وتباين لا يقل عن ٤٫٥:١.
    <br><br>
    <b>ملاحظة صدق:</b> هذه معاينات تصميم فقط. لا شيء منها متصل بقاعدة البيانات،
    ولا شيء منها منشور على الإنتاج.
  </div>

  <div class="grid">
    <?php
    $items = [
      ['01-ai-workspace','ai-workspace','١. المساعد القانوني الذكي',
       'محادثات محفوظة، ربط بقضية، إرفاق PDF/Word/صور، استشهادات موثّقة، وتحذير مراجعة المحامي.',
       ['new','fix'], ['new'=>'بناء تجربة جديدة على محرك RAG القائم','fix'=>'يحل A3 · A4 · A5']],
      ['02-templates','templates','٢. مكتبة القوالب',
       '١٨ تصنيفاً، حقول ديناميكية، إصدارات واعتماد، وقوالب خاصة بالمكتب.',
       ['new','gap'], ['new'=>'بناء كامل','gap'=>'الفجوة: ١٠٪ موجود']],
      ['03-editor','editor','٣. محرر المستندات',
       'تحرير مباشر، ترويسة المكتب وشعاره وتذييله، ترقيم صفحات، ختم وتوقيع، تصدير DOCX وPDF.',
       ['new','gap'], ['new'=>'بناء كامل','gap'=>'لا PDF عربي اليوم']],
      ['04-clients','clients','٤.٢ الموكلون',
       'ملف موكّل موحّد: فرد أو شركة، قضاياه، عقوده، مستنداته، رصيده، وملاحظات داخلية.',
       ['new','gap'], ['new'=>'شاشة جديدة','gap'=>'لا جدول موكلين اليوم']],
      ['05-case-file','case-file','٤.٣ ملف القضية',
       'رقم داخلي تلقائي، محكمة ودائرة، أطراف، وقائع وطلبات، سجل زمني، مستندات، مهام، ومساعد مدمج.',
       ['new','fix'], ['new'=>'بنية ملف جديدة','fix'=>'يحل غياب الرقم الداخلي']],
      ['06-calendar','calendar','٤.٤ الجلسات والمواعيد',
       'تقويم شهري حقيقي (وأسبوعي ويومي)، منع تعارض المواعيد، ومهل الاعتراض والاستئناف.',
       ['new','gap'], ['new'=>'تقويم حقيقي','gap'=>'الحالي قائمة ١١٤ سطراً']],
      ['07-tasks','tasks','٤.٥ المهام',
       'إسناد، أولوية، مواعيد، ربط بقضية أو موكل، ورؤية يتحكم بها المحامي.',
       ['new','gap'], ['new'=>'شاشة جديدة','gap'=>'case_tasks بلا إسناد أو أولوية']],
      ['08-finance','finance','٤.٦ المالية',
       'عروض أسعار، اتفاقيات أتعاب، فواتير، أقساط، مصروفات، كشف حساب، وتقارير.',
       ['new','gap'], ['new'=>'دورة مالية كاملة','gap'=>'لا مصروفات ولا عروض']],
      ['09-chatbot','chatbot','٥. الشات بوت',
       'بوت لكل مكتب يجيب من معرفته وقوالبه، يُضمَّن بسطر واحد، ويحوّل للموظف عند الحاجة.',
       ['new','gap'], ['new'=>'بناء كامل','gap'=>'الفجوة: ٠٪']],
      ['10-plans','plans','٧. الباقات',
       'الحدود العشرة: موظفون، موكلون، قضايا، تخزين، قوالب، شات بوت، تقارير، WhatsApp، هوية.',
       ['fix','new'], ['fix'=>'ربط كامل بقاعدة البيانات','new'=>'لا تعديل على أي سعر']],
      ['11-client-portal','client-portal','٦. بوابة الموكل',
       'قضاياي، الجلسات، المستندات التي شاركها المحامي فقط، الفواتير والسداد، وتواصل مباشر.',
       ['fix','new'], ['fix'=>'نموذج مشاركة مستندات','new'=>'لا ملاحظات داخلية']],
      ['12-admin-hub','admin-hub','٨. لوحة إدارة المنصة',
       'كتالوج نماذج AI، إدارة القوالب، الشات بوت، التخزين، مفاتيح المزايا، وسجل وصول الإدارة.',
       ['new','gap'], ['new'=>'٤ وحدات إدارية','gap'=>'قرار السرية مطلوب']],
    ];
    foreach ($items as [$slug, $shot, $title, $desc, $tags, $tagTexts]): ?>
    <div class="card">
      <a class="thumb" href="<?= $slug ?>.php"><img src="screenshots/<?= $shot ?>-desktop.png"
        alt="<?= htmlspecialchars($title) ?>" loading="lazy"></a>
      <div class="body">
        <h3><?= htmlspecialchars($title) ?></h3>
        <p><?= htmlspecialchars($desc) ?></p>
        <div class="tags">
          <?php foreach ($tags as $t): ?>
            <span class="tag <?= $t ?>"><?= htmlspecialchars($tagTexts[$t] ?? '') ?></span>
          <?php endforeach; ?>
        </div>
        <div class="links">
          <a href="<?= $slug ?>.php">فتح الصفحة ←</a>
          <a href="screenshots/<?= $shot ?>-phone.png">لقطة الهاتف ←</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
