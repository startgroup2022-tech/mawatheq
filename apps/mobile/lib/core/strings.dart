/// UI strings, Arabic-first with English alongside — the same pattern as the
/// website's `lang/strings.php`.
class S {
  const S._();

  static const Map<String, List<String>> _t = {
    // Order: [ar, en]
    'home': ['الرئيسية', 'Home'],
    'assistant': ['المساعد الذكي', 'Assistant'],
    'cases': ['القضايا', 'Cases'],
    'documents': ['المستندات', 'Documents'],
    'account': ['حسابي', 'Account'],
    'research': ['البحث القانوني', 'Legal research'],
    'consultations': ['الاستشارات', 'Consultations'],
    'appointments': ['المواعيد', 'Appointments'],
    'notifications': ['الإشعارات', 'Notifications'],
    'profile': ['الملف الشخصي', 'Profile'],
    'settings': ['الإعدادات', 'Settings'],
    'security': ['الأمان والجلسات', 'Security & sessions'],
    'features': ['مميزات المنصة', 'Platform features'],
    'pricing': ['الأسعار', 'Pricing'],
    'contact': ['تواصل معنا', 'Contact'],
    'legal': ['الخصوصية والشروط', 'Privacy & terms'],
    'login': ['تسجيل الدخول', 'Login'],
    'register': ['إنشاء حساب', 'Create account'],
    'logout': ['تسجيل الخروج', 'Log out'],
    'email': ['البريد الإلكتروني', 'Email'],
    'password': ['كلمة المرور', 'Password'],
    'fullName': ['الاسم الكامل', 'Full name'],
    'phone': ['رقم الجوال', 'Phone'],
    'forgotPassword': ['نسيت كلمة المرور؟', 'Forgot password?'],
    'asLawyer': ['الدخول كمحامٍ', 'Sign in as a lawyer'],
    'asClient': ['الدخول كعميل', 'Sign in as a client'],
    'noAccount': ['ليس لديك حساب؟', "Don't have an account?"],
    'haveAccount': ['لديك حساب بالفعل؟', 'Already have an account?'],
    'retry': ['إعادة المحاولة', 'Retry'],
    'loading': ['جارٍ التحميل…', 'Loading…'],
    'empty': ['لا توجد بيانات لعرضها.', 'Nothing to show yet.'],
    'searchHint': ['ابحث…', 'Search…'],
    'aiTitle': ['المساعد القانوني الذكي', 'Smart legal assistant'],
    'aiSubtitle': ['أسئلة قانونية بإجابات مبنية على الأنظمة', 'Legal answers grounded in the law'],
    'aiHint': ['اكتب سؤالك القانوني…', 'Type your legal question…'],
    'aiSend': ['إرسال', 'Send'],
    'aiChecking': ['جارٍ التحقق من توفّر المساعد…', 'Checking assistant availability…'],
    'uploadDocument': ['رفع مستند', 'Upload a document'],
    'analysisConsent': [
      'أوافق على إرسال هذا المستند إلى مزوّد الذكاء الاصطناعي للتحليل.',
      'I agree to send this document to the AI provider for analysis.'
    ],
    'recentCases': ['أحدث القضايا', 'Recent cases'],
    'upcoming': ['القادم', 'Upcoming'],
    'viewAll': ['عرض الكل', 'View all'],
    'onboarding1Title': ['منصة قانونية متكاملة', 'A complete legal platform'],
    'onboarding1Body': [
      'قضايا ومستندات واستشارات ومواعيد وفواتير وصلاحيات فريق — في مساحة واحدة.',
      'Cases, documents, consultations, deadlines, invoices and team permissions — in one workspace.'
    ],
    'onboarding2Title': ['مساعد قانوني يعتمد المصادر', 'A source-grounded legal assistant'],
    'onboarding2Body': [
      'يبحث أولاً في مكتبة الأنظمة، ويعرض المرجع والمادة عند توفّرها.',
      'It searches the laws library first and returns the reference and article when available.'
    ],
    'onboarding3Title': ['بياناتك محميّة', 'Your data is protected'],
    'onboarding3Body': [
      'رموز مجزّأة، وصلاحيات دقيقة، وعزل بيانات بين المكاتب، وتنزيل محمي للمستندات.',
      'Hashed tokens, granular permissions, tenant isolation and protected document downloads.'
    ],
    'skip': ['تخطٍّ', 'Skip'],
    'next': ['التالي', 'Next'],
    'start': ['ابدأ الآن', 'Get started'],
    'guest': ['زائر', 'Guest'],
    'signInToContinue': ['سجّل الدخول للمتابعة', 'Sign in to continue'],
    'notConfigured': [
      'هذه الميزة تحتاج ضبطاً من لوحة الإدارة.',
      'This feature needs configuration from the admin console.'
    ],
  };

  static String t(String key, String locale) {
    final entry = _t[key];
    if (entry == null) return key;
    return locale == 'en' ? entry[1] : entry[0];
  }
}
