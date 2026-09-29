<?php require_once __DIR__ . '/includes/header.php'; ?>
<section class="section">
  <div class="container" style="max-width:760px">
    <h1><?= t('footer_privacy') ?></h1>
    <p><?= is_rtl()
      ? 'تُخزّن بيانات الحساب والمستندات في قاعدة بيانات وملفات الاستضافة. تُخزّن كلمات المرور بصيغ تجزئة آمنة. عند طلب ميزة ذكاء اصطناعي، يُرسل السؤال أو المحتوى اللازم إلى مزود الذكاء الاصطناعي الذي اختارته إدارة المنصة لمعالجته. تحليل الملفات ومسودات المحامين وتحليل القضايا تتطلب موافقتك الصريحة قبل الإرسال. تخضع المعالجة لشروط وسياسات مزود الخدمة؛ لا ترفع بيانات لا تملك صلاحية مشاركتها.'
      : 'Account data and documents are stored in the platform database and hosting files. Passwords are stored as secure hashes. When you use an AI feature, the relevant question or content is sent to the AI provider configured by the platform administrator. Document analysis, lawyer drafts, and case analysis require your explicit consent before transmission. Processing is subject to the provider’s terms and privacy policies; do not upload data you are not authorized to share.' ?></p>
    <p><?= is_rtl()
      ? 'يمكنك في أي وقت طلب تصدير بياناتك أو حذف حسابك بالتواصل معنا عبر صفحة التواصل.'
      : 'You may request to export your data or delete your account at any time by contacting us via the contact page.' ?></p>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
