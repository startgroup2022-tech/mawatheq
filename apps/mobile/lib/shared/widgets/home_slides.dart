import '../../core/network/models.dart';

/// The seven main legal categories, used to populate the specialty grid when the
/// backend has not yet returned any.
///
/// These are the same seven categories the Najiz classification import creates
/// (`najiz_categories`, seeded by `database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql`
/// after `upgrade_v59.sql`/`upgrade_v63.sql`), with the same names and icon keys, so
/// the fallback and the imported taxonomy describe the same thing. The ids match the
/// import's deterministic order, so tapping a fallback card routes to the same
/// specialty the server would have listed.
///
/// It exists so the home screen shows the legal specialties and services on a
/// database whose classification has not been imported yet — the production state at
/// the time of this release — instead of an empty "no specialties" box. Once the
/// import runs, the real list arrives from `journey.php` and this is not used.
const List<JourneySpecialty> kBuiltInSpecialties = [
  JourneySpecialty(
    id: 1,
    name: 'الأحوال الشخصية',
    description: 'الزواج والطلاق والحضانة والنفقة والمواريث',
    iconKey: 'family',
  ),
  JourneySpecialty(
    id: 2,
    name: 'الدعاوى التجارية',
    description: 'الشركات والعقود والمنازعات التجارية',
    iconKey: 'commercial',
  ),
  JourneySpecialty(
    id: 3,
    name: 'الدعاوى العمالية',
    description: 'علاقات العمل والأجور وإنهاء الخدمة',
    iconKey: 'labor',
  ),
  JourneySpecialty(
    id: 4,
    name: 'الدعاوى الجزائية',
    description: 'الجرائم والدفاع الجنائي والتحقيقات',
    iconKey: 'criminal',
  ),
  JourneySpecialty(
    id: 5,
    name: 'الدعاوى العامة',
    description: 'الدعاوى المدنية والعقود والالتزامات',
    iconKey: 'general',
  ),
  JourneySpecialty(
    id: 6,
    name: 'دعاوى التنفيذ',
    description: 'تنفيذ الأحكام والسندات التنفيذية',
    iconKey: 'enforcement',
  ),
  JourneySpecialty(
    id: 7,
    name: 'الدعاوى المتعلقة بالإنهاءات',
    description: 'الإفلاس والتصفية وإعادة التنظيم المالي',
    iconKey: 'endowments',
  ),
];

/// The slides the home carousel shows when the operator has published none.
///
/// `home_banners` ships empty on purpose: the platform must not invent a discount
/// or a deadline that nobody approved. That left the home screen with no slider at
/// all on a fresh install, which is what made the top of the page look like a pair
/// of static cards. These slides fill that gap without fabricating a promotion —
/// each one describes a capability the app actually ships, carries a real licensed
/// photograph, and offers the two primary actions inside the banner itself.
///
/// An operator banner always takes precedence: as soon as `banners.php` returns a
/// row, that row is shown instead and this list is not used.
///
/// The ids are negative so they can never collide with a server banner id, and the
/// link types reuse the same vocabulary the server sends (`specialty`, `assistant`)
/// so the carousel's tap handling needs no special case for a built-in slide.
const List<HomeBanner> kBuiltInHomeSlides = [
  HomeBanner(
    id: -1,
    titleAr: 'استشارتك القانونية بخطوات بسيطة',
    titleEn: 'Your legal consultation in a few steps',
    subtitleAr: 'اختر التخصص والمحامي والموعد المناسب لك',
    subtitleEn: 'Pick the specialty, the lawyer and the slot that suits you',
    eyebrowAr: 'استشارة قانونية',
    eyebrowEn: 'Legal consultation',
    iconKey: 'family',
    gradientKey: 'navy',
    assetImage: 'assets/images/banners/banner_consultation.jpg',
    linkType: 'specialty',
    linkTarget: '1',
    altAr: 'ابدأ حجز استشارة قانونية',
    altEn: 'Start booking a legal consultation',
  ),
  HomeBanner(
    id: -2,
    titleAr: 'إجابات فورية من المساعد الذكي',
    titleEn: 'Instant answers from the AI assistant',
    subtitleAr: 'إجابات مبنية على الأنظمة واللوائح القانونية',
    subtitleEn: 'Answers grounded in the regulations',
    eyebrowAr: 'المساعد القانوني',
    eyebrowEn: 'Legal assistant',
    iconKey: 'commercial',
    gradientKey: 'teal',
    assetImage: 'assets/images/banners/banner_office.jpg',
    linkType: 'assistant',
    linkTarget: 'assistant',
    altAr: 'افتح المساعد القانوني الذكي',
    altEn: 'Open the AI legal assistant',
  ),
  HomeBanner(
    id: -3,
    titleAr: 'محامون موثوقون في تخصصك',
    titleEn: 'Trusted lawyers in your field',
    subtitleAr: 'تصفّح المحامين المعتمدين واختر الأنسب لقضيتك',
    subtitleEn: 'Browse approved lawyers and choose the right fit',
    eyebrowAr: 'دليل المحامين',
    eyebrowEn: 'Lawyer directory',
    iconKey: 'labor',
    gradientKey: 'deep',
    assetImage: 'assets/images/banners/banner_advice.jpg',
    linkType: 'lawyers',
    linkTarget: 'lawyers',
    altAr: 'تصفّح دليل المحامين',
    altEn: 'Browse the lawyer directory',
  ),
  HomeBanner(
    id: -4,
    titleAr: 'قضاياك ومستنداتك في مكان آمن',
    titleEn: 'Your cases and documents, secured',
    subtitleAr: 'إدارة كاملة للقضايا والمستندات مع تشفير للبيانات',
    subtitleEn: 'Full case and document management, with encryption',
    eyebrowAr: 'منصة مواثيق',
    eyebrowEn: 'Mawatheq platform',
    iconKey: 'realestate',
    gradientKey: 'gold',
    assetImage: 'assets/images/banners/banner_library.jpg',
    linkType: 'specialty',
    linkTarget: '1',
    altAr: 'تعرّف على منصة مواثيق',
    altEn: 'Learn about the Mawatheq platform',
  ),
];
