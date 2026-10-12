/// Public marketing content for guest browsing.
///
/// The wording is taken from the approved website copy (`lang/strings.php`) so the
/// app and the site say the same thing. Nothing here claims a capability the
/// platform does not ship — each feature maps to a real screen or endpoint, and the
/// pricing section is filled from `GET /api/v1/plans.php` rather than from here.
///
/// Order of every pair is [Arabic, English].
class PublicContent {
  const PublicContent._();

  // ------------------------------------------------------------------ brand

  static const heroBadge = ['منصة قانونية متكاملة', 'A Complete Legal Platform'];

  static const heroTitle = [
    'مواثيق — منصتك القانونية المتكاملة',
    'Mawatheq — Your Complete Legal Platform',
  ];

  static const heroBody = [
    'إدارة القضايا والمستندات والاستشارات والمكاتب القانونية، مع أدوات ذكاء اصطناعي '
        'تساعدك على إنجاز أعمالك بكفاءة.',
    'Manage cases, documents, consultations and law firms, with AI tools that help '
        'you get legal work done efficiently.',
  ];

  // -------------------------------------------------------------- platform

  static const platformTitle = [
    'منصة واحدة للعمل القانوني',
    'One platform for legal work',
  ];

  static const platformBody = [
    'مواثيق تجمع القضايا والمستندات والاستشارات والمواعيد والفواتير وصلاحيات الفريق '
        'في مساحة عمل واحدة، للعميل والمحامي والمكتب القانوني.',
    'Mawatheq brings cases, documents, consultations, deadlines, invoices and team '
        'permissions into one workspace, for clients, lawyers and law firms.',
  ];

  // --------------------------------------------------------------- legal AI

  static const aiEyebrow = ['الذكاء القانوني', 'Legal AI'];

  static const aiTitle = [
    'إجابات مبنية على الأنظمة، لا على التخمين',
    'Answers grounded in the law, not guesses',
  ];

  static const aiBody = [
    'يبحث المساعد أولاً في مكتبة الأنظمة داخل المنصة، ثم يعرض المرجع والمادة عند '
        'توفّرها. وإن لم يجد سنداً نظامياً واضحاً، يقول ذلك بدل أن يخترع إجابة.',
    'The assistant searches the in-platform laws library first, then returns the '
        'reference and article when available. If it finds no clear statutory basis, '
        'it says so instead of inventing an answer.',
  ];

  /// Only true statements: the assistant genuinely refuses rather than fabricates.
  static const aiNote = [
    'لا يُعرض مرجع نظامي إلا إذا أرجعه الخادم فعلاً.',
    'A statutory reference is only shown when the server actually returned one.',
  ];

  // --------------------------------------------------------------- features

  static const featuresTitle = [
    'كل ما يحتاجه العمل القانوني',
    'Everything legal work needs',
  ];

  static const featuresSub = [
    'وحدات مبنية على وظائف المنصة الفعلية، لا وعود تسويقية.',
    'Modules built on the platform’s actual functionality, not marketing promises.',
  ];

  /// [icon name, AR, EN] — the same set the signed-in feature grid uses.
  static const features = <(String, String, String)>[
    ('ai', 'المساعد القانوني الذكي', 'Smart legal assistant'),
    ('laws', 'البحث في الأنظمة والتشريعات', 'Laws and legislation search'),
    ('cases', 'إدارة القضايا', 'Case management'),
    ('documents', 'إدارة المستندات', 'Document management'),
    ('analysis', 'تحليل المستندات', 'Document analysis'),
    ('compare', 'مقارنة العقود', 'Contract comparison'),
    ('firms', 'إدارة مكاتب المحاماة', 'Law firm management'),
    ('team', 'إدارة فريق العمل', 'Team management'),
    ('client_portal', 'بوابة العملاء', 'Client portal'),
    ('consultations', 'الاستشارات القانونية', 'Legal consultations'),
    ('deadlines', 'المواعيد والمهام', 'Deadlines and tasks'),
    ('notifications', 'التنبيهات والإشعارات', 'Alerts and notifications'),
    ('invoices', 'الفواتير والاشتراكات', 'Invoices and subscriptions'),
    ('security', 'الخصوصية والأمان', 'Privacy and security'),
  ];

  // --------------------------------------------------------------- services

  static const servicesTitle = ['الخدمات القانونية', 'Legal services'];

  static const servicesSub = [
    'خدمات متاحة عبر المنصة، لكل منها شاشة وواجهة برمجية قائمة.',
    'Services available through the platform, each backed by a real screen and API.',
  ];

  static const services = <(String, String, String)>[
    (
      'consult',
      'استشارات قانونية',
      'Legal consultations',
    ),
    (
      'analyze',
      'تحليل ومراجعة المستندات',
      'Document analysis and review',
    ),
    (
      'laws',
      'البحث في الأنظمة واللوائح',
      'Research in laws and regulations',
    ),
    (
      'draft',
      'صياغة العقود والمذكرات',
      'Contract and memorandum drafting',
    ),
    (
      'represent',
      'التواصل مع محامٍ معتمد',
      'Connect with a licensed lawyer',
    ),
  ];

  // --------------------------------------------------- lawyers and law firms

  static const solutionsTitle = [
    'للأفراد والمحامين والمكاتب',
    'For individuals, lawyers and firms',
  ];

  static const solutions = <(String, String, String)>[
    (
      'client',
      'للعميل',
      'For clients',
    ),
    (
      'lawyer',
      'للمحامي',
      'For lawyers',
    ),
    (
      'firm',
      'للمكتب القانوني',
      'For law firms',
    ),
  ];

  static const solutionsBody = <(String, String)>[
    (
      'متابعة القضايا والمستندات، وحجز الاستشارات، ومتابعة الفواتير من مكان واحد.',
      'Follow cases and documents, book consultations and track invoices in one place.',
    ),
    (
      'إدارة القضايا والموكلين والمواعيد، والبحث في الأنظمة، وصياغة المسودات.',
      'Manage cases, clients and deadlines, research the law and draft documents.',
    ),
    (
      'فريق وصلاحيات، وعزل بيانات بين المكاتب، وإدارة الفروع والنطاقات والموارد.',
      'Team and permissions, tenant data isolation, and branch, domain and HR management.',
    ),
  ];

  // ------------------------------------------------- documents & consultations

  static const documentsTitle = [
    'مستنداتك محفوظة ومضبوطة الوصول',
    'Your documents, stored and access-controlled',
  ];

  static const documentsBody = [
    'ارفع المستندات ونظّمها داخل القضية أو العميل. التنزيل محمي، والوصول محدود '
        'بصلاحياتك، وكل عملية تُسجَّل.',
    'Upload and organise documents inside a case or client. Downloads are protected, '
        'access is limited by your permissions, and every action is logged.',
  ];

  static const consultationsTitle = [
    'استشارات قانونية بموعد واضح',
    'Legal consultations with a clear appointment',
  ];

  static const consultationsBody = [
    'اختر المحامي المناسب من الدليل، واطّلع على الأوقات المتاحة فعلياً، واحجز '
        'استشارة نصية أو صوتية أو مرئية.',
    'Pick the right lawyer from the directory, see genuinely available slots, and book '
        'a text, voice or video consultation.',
  ];

  // ------------------------------------------------------------- how it works

  static const howTitle = ['كيف تبدأ خلال دقائق', 'How you start in minutes'];

  static const steps = <(String, String, String)>[
    ('account', 'أنشئ حسابك', 'Create your account'),
    ('workspace', 'جهّز مساحة عملك', 'Set up your workspace'),
    ('work', 'ابدأ العمل القانوني', 'Do the legal work'),
    ('follow', 'تعاون وتابع', 'Collaborate and follow up'),
  ];

  static const stepsBody = <(String, String)>[
    (
      'سجّل بالبريد وأكّده، أو انضم كمحامٍ لمراجعة طلبك.',
      'Sign up with email and verify it, or join as a lawyer for review.',
    ),
    (
      'اختر باقتك، وأضف فريقك وصلاحياتهم إن كنت مكتباً.',
      'Choose your plan and add your team and their permissions if you run a firm.',
    ),
    (
      'افتح القضايا، وارفع المستندات، وابحث في الأنظمة، وأصغ المسودات.',
      'Open cases, upload documents, search the laws and draft.',
    ),
    (
      'شارك فريقك، وتابع المواعيد والإشعارات والفواتير.',
      'Share with your team and follow deadlines, notifications and invoices.',
    ),
  ];

  // -------------------------------------------------------------------- about

  static const aboutTitle = [
    'تمكين الوصول إلى الخدمات القانونية باستخدام الذكاء الاصطناعي',
    'Empowering legal access with AI',
  ];

  static const aboutBody = [
    'رؤيتنا هي سد الفجوة بين القانون والتكنولوجيا في النظام القانوني السعودي. يعتمد '
        'الأفراد والمحامون والشركات على منصتنا لتبسيط فهم القوانين، وتسريع مراجعة '
        'المستندات، والعمل مع خبراء موثوقين.',
    'Our vision is to bridge the gap between law and technology in the Saudi legal '
        'system. Individuals, lawyers and companies rely on our platform to simplify '
        'legal understanding, accelerate document review and work with trusted experts.',
  ];

  static const securityTitle = [
    'الأمان والخصوصية داخل التصميم',
    'Security and privacy by design',
  ];

  static const securityBody = [
    'نبني الحماية على ما يمكن إثباته في الكود: رموز مجزّأة، وحد لمحاولات الدخول، '
        'وصلاحيات دقيقة، وعزل بيانات بين المكاتب.',
    'Protection is built on what the code can demonstrate: hashed tokens, login '
        'throttling, granular permissions and tenant data isolation.',
  ];

  // ---------------------------------------------------------------------- FAQ

  static const faqTitle = [
    'إجابات عن أكثر أسئلتك شيوعاً',
    'Answers to your most common questions',
  ];

  static const faqs = <(String, String)>[
    (
      'ما مدى دقة المساعد القانوني الذكي؟',
      'How accurate is the legal assistant?',
    ),
    (
      'هل الإجابات ملزمة قانونياً؟',
      'Are the answers legally binding?',
    ),
    (
      'كيف يمكنني حجز استشارة؟',
      'How can I book a consultation?',
    ),
    (
      'هل يمكنني رفع عقود ممسوحة ضوئياً أو صور؟',
      'Can I upload scanned contracts or images?',
    ),
    (
      'هل أحتاج حساباً لتصفّح المنصة؟',
      'Do I need an account to browse the platform?',
    ),
  ];

  static const faqAnswers = <(String, String)>[
    (
      'يبحث المساعد أولاً في مكتبة الأنظمة داخل المنصة، ويستشهد بالمواد ذات الصلة '
          'عند توفّرها. وإن لم يجد سنداً نظامياً واضحاً، يقول ذلك بدل أن يخترع إجابة.',
      'The assistant searches the in-platform laws library first and cites the '
          'relevant articles when available. If it finds no clear statutory basis, it '
          'says so instead of inventing an answer.',
    ),
    (
      'الإجابات إرشادية أولية ولا تُعد استشارة قانونية ملزمة. للتمثيل الرسمي يُتواصل '
          'مع محامٍ معتمد عبر المنصة.',
      'Answers are preliminary guidance and are not binding legal advice. For official '
          'representation, work with a licensed lawyer through the platform.',
    ),
    (
      'تصفّح دليل المحامين، واطّلع على الأوقات المتاحة، ثم احجز موعداً يناسبك.',
      'Browse the lawyer directory, view the available slots, then book a time that '
          'suits you.',
    ),
    (
      'نعم، يقبل تحليل المستندات ملفات PDF والصور بصيغة PNG أو JPG.',
      'Yes — document analysis accepts PDF files and PNG or JPG images.',
    ),
    (
      'يمكنك تصفّح الصفحة الرئيسية والمميزات والخدمات والأسعار والأسئلة الشائعة دون '
          'حساب. حسابك مطلوب فقط للميزات الخاصة مثل القضايا والمستندات.',
      'You can browse the homepage, features, services, pricing and FAQ without an '
          'account. An account is only needed for private features such as cases and '
          'documents.',
    ),
  ];

  // -------------------------------------------------------------- contact

  static const contactTitle = ['تواصل معنا', 'Contact us'];

  static const contactBody = [
    'فريق الدعم متاح للإجابة على استفساراتك حول المنصة والاشتراكات.',
    'Our support team is available for questions about the platform and subscriptions.',
  ];

  /// The address the account screen already shows; the app has no support phone
  /// number in the shipped configuration, so none is invented here.
  static const supportEmail = 'info@mawatheq.com';

  // --------------------------------------------------------------- final CTA

  static const finalTitle = ['جاهز لتنظيم عملك القانوني؟', 'Ready to organise your legal work?'];

  static const finalBody = [
    'ابدأ بالباقة المجانية اليوم، وارفع قضاياك ومستنداتك عندما تكون جاهزاً.',
    'Start on the free plan today, and add your cases and documents when you are ready.',
  ];

  /// Picks the right half of a pair.
  static String pick(List<String> pair, bool isRtl) => isRtl ? pair[0] : pair[1];
}
