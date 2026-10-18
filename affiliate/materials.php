<?php
/**
 * Marketing materials (Execution 31).
 *
 * Ready-made copy and assets an affiliate can use, plus the rules they must follow.
 * The compliance notice is not decoration: a partner who advertises a legal service
 * with an unapproved claim creates a regulatory problem for the platform, so the
 * page states the prohibitions plainly and in the same place as the assets.
 */
$activeAffiliate = 'materials';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_tracking.php';


$link = affiliate_build_link($affiliate, '/');

// Copy blocks. Kept as data rather than as loose markup so the same strings can be
// offered with a copy button and translated in one place.
$snippets = is_rtl() ? [
    'short' => 'استشارة قانونية موثوقة عبر مواثيق — منصة تجمع محامين معتمدين وتتيح حجز استشارتك ومتابعة قضيتك إلكترونياً.',
    'medium' => 'هل تحتاج استشارة قانونية؟ مواثيق منصة قانونية تجمع نخبة من المحامين المعتمدين، مع حجز إلكتروني ومتابعة كاملة لملفك. ابدأ الآن: ' . $link,
    'email' => "السلام عليكم،\n\nأود أن ألفت انتباهكم إلى منصة مواثيق، وهي منصة قانونية تتيح التواصل مع محامين معتمدين وحجز الاستشارات ومتابعة الملفات إلكترونياً.\n\nيمكنكم البدء من خلال هذا الرابط: " . $link . "\n\nمع التقدير.",
    'whatsapp' => 'مرحباً 👋 منصة مواثيق للخدمات القانونية — استشارات مع محامين معتمدين ومتابعة كاملة للملف. ابدأ من هنا: ' . $link,
] : [
    'short' => 'Trusted legal advice through Mawatheq — a platform connecting you with licensed lawyers, with online booking and case tracking.',
    'medium' => 'Need legal advice? Mawatheq connects you with licensed lawyers, with online booking and full case tracking. Get started: ' . $link,
    'email' => "Hello,\n\nI wanted to introduce Mawatheq, a legal platform for reaching licensed lawyers, booking consultations and tracking matters online.\n\nYou can get started here: " . $link . "\n\nBest regards.",
    'whatsapp' => 'Hi 👋 Mawatheq legal services — consultations with licensed lawyers and full case tracking. Start here: ' . $link,
];

$snippetLabels = [
    'short' => is_rtl() ? 'وصف قصير (سطر واحد)' : 'Short description (one line)',
    'medium' => is_rtl() ? 'وصف متوسط (منشور)' : 'Medium description (post)',
    'email' => is_rtl() ? 'نص بريد إلكتروني' : 'Email copy',
    'whatsapp' => is_rtl() ? 'رسالة واتساب' : 'WhatsApp message',
];

// The rules a partner must follow. These are the substantive constraints, not a
// generic policy list: each one corresponds to something that would actually create
// a regulatory or legal problem for the platform.
$rules = is_rtl() ? [
    'لا تقدّم استشارة قانونية بنفسك، ولا تصف نفسك بأنك محامٍ أو مستشار قانوني.',
    'لا تعِد بأي نتيجة قضائية أو مبلغ تعويض، ولا تضمن قبول أي قضية.',
    'لا تستخدم شعار المنصة أو اسمها بطريقة توحي بشراكة رسمية أو وكالة دون إذن كتابي.',
    'لا تسوّق عبر رسائل غير مرغوبة (بريد مزعج) أو قوائم مشتراة.',
    'لا تنشر أسماء عملاء أو تفاصيل قضاياهم، ولا تدّعي وجودهم.',
    'لا تعد بتخفيض أو ميزة غير معلنة على المنصة.',
    'التزم بالإفصاح عن كونك مسوّقاً بالعمولة عند نشر أي محتوى ترويجي.',
] : [
    'Do not give legal advice yourself, and do not present yourself as a lawyer or legal consultant.',
    'Do not promise any case outcome or compensation amount, and do not guarantee that a matter will be accepted.',
    'Do not use the platform\'s name or logo in a way that implies an official partnership or agency without written permission.',
    'Do not market through unsolicited messages (spam) or purchased lists.',
    'Do not publish client names or case details, and do not claim clients you do not have.',
    'Do not promise discounts or benefits the platform has not announced.',
    'Disclose that you are a commission-based affiliate whenever you publish promotional content.',
];
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'المواد التسويقية' : 'Marketing materials') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'نصوص جاهزة ورابطك، مع قواعد الترويج التي يجب الالتزام بها.' : 'Ready-made copy and your link, with the promotion rules you must follow.') ?></p>
    </div>
  </div>

  <?php if ($affiliateCanEarn): ?>
  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'رابطك' : 'Your link') ?></h2></div>
    <div class="aff-linkbox-row">
      <input type="text" readonly value="<?= ui_e($link) ?>" id="affMatLink">
      <button type="button" class="btn btn-gold" data-copy-target="#affMatLink" data-copy-done="<?= ui_e(is_rtl() ? 'تم النسخ' : 'Copied') ?>"><?= ui_e(is_rtl() ? 'نسخ' : 'Copy') ?></button>
    </div>
  </section>
  <?php endif; ?>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'نصوص جاهزة' : 'Ready-made copy') ?></h2></div>
    <div class="aff-snippets">
      <?php foreach ($snippets as $key => $text): ?>
        <div class="aff-snippet">
          <div class="aff-snippet-head">
            <span><?= ui_e($snippetLabels[$key]) ?></span>
            <button type="button" class="btn btn-outline btn-xs" data-copy-target="#snip-<?= ui_e($key) ?>" data-copy-done="<?= ui_e(is_rtl() ? 'تم' : 'Done') ?>"><?= ui_e(is_rtl() ? 'نسخ' : 'Copy') ?></button>
          </div>
          <textarea id="snip-<?= ui_e($key) ?>" readonly rows="<?= $key === 'email' ? 6 : 3 ?>"><?= ui_e($text) ?></textarea>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'قواعد الترويج' : 'Promotion rules') ?></h2></div>
    <div class="aff-rules">
      <p><?= ui_e(is_rtl()
        ? 'التزم بهذه القواعد في كل محتوى ترويجي. مخالفتها قد تؤدي إلى إيقاف الحساب وعدم صرف العمولات المرتبطة بالمخالفة.'
        : 'Follow these rules in all promotional content. Breaching them can lead to account suspension and non-payment of commission connected to the breach.') ?></p>
      <ul>
        <?php foreach ($rules as $rule): ?><li><?= ui_e($rule) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'قوائم التحقق' : 'Checklists') ?></h2></div>
    <div class="aff-checklists">
      <div>
        <h3><?= ui_e(is_rtl() ? 'قبل النشر' : 'Before you publish') ?></h3>
        <ul>
          <li><?= ui_e(is_rtl() ? 'تأكد أن الرابط يعمل ويفتح الصفحة المقصودة.' : 'Check the link works and opens the intended page.') ?></li>
          <li><?= ui_e(is_rtl() ? 'راجع النص للتأكد من عدم وجود أي وعد بنتيجة.' : 'Review the copy for any promise of an outcome.') ?></li>
          <li><?= ui_e(is_rtl() ? 'أضف إفصاحاً بأنك مسوّق بالعمولة.' : 'Add a disclosure that you are a commission-based affiliate.') ?></li>
        </ul>
      </div>
      <div>
        <h3><?= ui_e(is_rtl() ? 'بعد النشر' : 'After you publish') ?></h3>
        <ul>
          <li><?= ui_e(is_rtl() ? 'تابع النقرات في لوحة التحكم.' : 'Watch the clicks in your dashboard.') ?></li>
          <li><?= ui_e(is_rtl() ? 'راجع التحويلات للتأكد من تسجيلها.' : 'Review conversions to confirm they were recorded.') ?></li>
          <li><?= ui_e(is_rtl() ? 'أبلغنا عن أي تحويل مفقود خلال 30 يوماً.' : 'Report any missing conversion within 30 days.') ?></li>
        </ul>
      </div>
    </div>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
