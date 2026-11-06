<?php require_once __DIR__ . '/includes/header.php'; ?>
<section class="section">
  <div class="container" style="max-width:760px">
    <h1><?= t('footer_privacy') ?></h1>
    <p><?= is_rtl()
      ? 'تُخزّن بيانات الحساب والمستندات في قاعدة بيانات وملفات الاستضافة. تُخزّن كلمات المرور بصيغ تجزئة آمنة. عند طلب ميزة ذكاء اصطناعي، يُرسل السؤال أو المحتوى اللازم إلى مزود الذكاء الاصطناعي الذي اختارته إدارة المنصة لمعالجته. تحليل الملفات ومسودات المحامين وتحليل القضايا تتطلب موافقتك الصريحة قبل الإرسال. تخضع المعالجة لشروط وسياسات مزود الخدمة؛ لا ترفع بيانات لا تملك صلاحية مشاركتها.'
      : 'Account data and documents are stored in the platform database and hosting files. Passwords are stored as secure hashes. When you use an AI feature, the relevant question or content is sent to the AI provider configured by the platform administrator. Document analysis, lawyer drafts, and case analysis require your explicit consent before transmission. Processing is subject to the provider’s terms and privacy policies; do not upload data you are not authorized to share.' ?></p>
    <p><?= is_rtl()
      ? 'يمكنك في أي وقت طلب تصدير بياناتك أو حذف حسابك بنفسك دون التواصل مع الدعم: من صفحة <a href="/account.php">حسابي</a> اختر «حذف الحساب»، وأكّد كلمة المرور. كما يتوفّر الحذف داخل تطبيق الجوال من تبويب «حسابي».'
      : 'You may export your data or delete your account yourself at any time, without contacting support: open <a href="/account.php">My Account</a>, choose “Delete account” and confirm your password. Deletion is also available inside the mobile app from the Account tab.' ?></p>
    <p><?= is_rtl()
      ? 'عند حذف الحساب نلغي تفعيله ونزيل بياناتك الشخصية (الاسم والبريد والجوال وكلمة المرور)، ونُبطل جميع أجهزة الدخول. تبقى السجلات المالية والقانونية المرتبطة بأطراف أخرى (مثل الفواتير الصادرة أو الاستشارات المكتملة) محفوظة كما يقتضي النظام، دون بياناتك الشخصية. وإذا كان لديك أعمال قائمة — قضية مفتوحة أو استشارة قادمة — فسنخبرك بها أولاً لتغلقها ثم تُكمل الحذف.'
      : 'Deleting your account deactivates it, removes your personal data (name, email, phone and password) and revokes every signed-in device. Financial and legal records tied to other parties (such as invoices already issued or completed consultations) are retained as the law requires, without your personal data. If you still have work in progress — an open case or an upcoming consultation — we will name it first so you can close it and then finish the deletion.' ?></p>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
