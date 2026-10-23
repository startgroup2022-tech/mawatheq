// Screenshot harness for the Execution 41 delivery evidence.
//
// This renders the REAL app widgets — the real HomeShell, HomeTab, login screen,
// consultation journey and booking form — against a fake HTTP transport that serves
// the same JSON shapes the PHP API returns. Nothing here is a mock of a widget: the
// screens under test are the production ones, and only the network edge is replaced,
// which is the same seam the widget test suite uses.
//
// Run with:
//   flutter test tool/screenshots/capture_screenshots.dart --update-goldens
// The PNGs land in tool/screenshots/goldens/.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/guest_session.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';
import 'package:mawatheq_mobile/features/consultations/consultations_screen.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';
import 'package:provider/provider.dart';

class _FakeTransport extends http_base.BaseClient {
  _FakeTransport(this.handler);
  final Future<(int, Object)> Function(Uri uri) handler;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final (status, body) = await handler(request.url);
    final payload = body is String ? body : jsonEncode(body);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(payload)),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
  String? token;
  @override
  Future<void> saveToken(String value) async => token = value;
  @override
  Future<String?> readToken() async => token;
  @override
  Future<void> saveAccountType(String value) async {}
  @override
  Future<String?> readAccountType() async => null;
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async => token = null;
}

// --------------------------------------------------------------- API fixtures

const _banners = [
  {
    'id': 1,
    'title_ar': 'استشارتك الأولى بخصم ٢٠٪',
    'title_en': '20% off your first consultation',
    'subtitle_ar': 'لفترة محدودة',
    'subtitle_en': 'Limited time',
    'image_path': 'uploads/banners/welcome.jpg',
    'alt_ar': 'عرض خصم على الاستشارة الأولى',
    'alt_en': 'Discount on your first consultation',
    'link_type': 'none',
  },
];

const _specialties = [
  {'id': 1, 'name': 'الأحوال الشخصية', 'description': 'شؤون الأسرة: الزواج والطلاق والنفقة والحضانة والميراث.', 'icon_key': 'family', 'lawyer_count': 0},
  {'id': 2, 'name': 'الدعاوى التجارية', 'description': 'النزاعات التجارية والشركات والعقود والأوراق التجارية والإفلاس.', 'icon_key': 'commercial', 'lawyer_count': 0},
  {'id': 3, 'name': 'الدعاوى العمالية', 'description': 'نزاعات العمل: حقوق وواجبات العامل وصاحب العمل ومستحقات نهاية الخدمة.', 'icon_key': 'labor', 'lawyer_count': 0},
  {'id': 4, 'name': 'الدعاوى الجزائية', 'description': 'القضايا الجزائية والمطالبات المالية الناشئة عن الجرائم.', 'icon_key': 'criminal', 'lawyer_count': 0},
  {'id': 5, 'name': 'الدعاوى العامة', 'description': 'الدعاوى المدنية والعقارية والعقود والتحكيم.', 'icon_key': 'general', 'lawyer_count': 0},
  {'id': 6, 'name': 'دعاوى التنفيذ', 'description': 'تنفيذ الأحكام والسندات التنفيذية والمنازعات المتعلقة بها.', 'icon_key': 'enforcement', 'lawyer_count': 0},
  {'id': 7, 'name': 'الدعاوى المتعلقة بالإنهاءات', 'description': 'إنهاءات الأوقاف والمواريث والصكوك والولايات.', 'icon_key': 'endowments', 'lawyer_count': 0},
];

const _issueTypes = [
  {
    'id': 11,
    'name': 'طلاق أو خلع',
    'definition': 'إجراءات إنهاء عقد الزواج',
    'group': 'أحوال الزوجية',
  },
  {
    'id': 12,
    'name': 'حضانة ونفقة',
    'definition': 'الحضانة والمصروف الشهري للأبناء',
    'group': 'الآثار المالية',
  },
  {
    'id': 13,
    'name': 'تقسيم ميراث',
    'definition': 'توزيع التركة بين الورثة',
    'group': 'المواريث',
  },
];

const _lawyers = [
  {
    'id': 101,
    'full_name': 'المحامي عبدالله الحربي',
    'specialization': 'الأحوال الشخصية',
    'jurisdiction': 'الرياض',
    'rating': 4.8,
    'hourly_rate': 350.0,
  },
  {
    'id': 102,
    'full_name': 'المحامية نورة القحطاني',
    'specialization': 'الأحوال الشخصية',
    'jurisdiction': 'جدة',
    'rating': 4.9,
    'hourly_rate': 420.0,
  },
  {
    'id': 103,
    'full_name': 'المحامي سعد الدوسري',
    'specialization': 'الأحوال الشخصية',
    'jurisdiction': 'الدمام',
    'rating': 4.5,
    'hourly_rate': 300.0,
  },
];

/// The production state while the taxonomy import has not been run: no operator
/// banners and no server specialties. Everything else is the normal fixture.
Future<(int, Object)> _apiNoBanners(Uri uri) async {
  final p = uri.path;
  if (p.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': <Object>[]});
  }
  if (p.endsWith('/journey.php') &&
      uri.queryParameters['action'] == 'specialties') {
    return (200, {'ok': true, 'specialties': <Object>[], 'ready': true});
  }
  return _api(uri);
}

/// The production state *after* MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql: still no
/// operator banners, so the built-in slides carry the carousel, and the seven real
/// categories now arrive from the server. `_specialties` holds the names and
/// descriptions the import actually writes.
Future<(int, Object)> _apiImportedTaxonomy(Uri uri) async {
  final p = uri.path;
  if (p.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': <Object>[]});
  }
  if (p.endsWith('/journey.php') &&
      uri.queryParameters['action'] == 'specialties') {
    return (200, {'ok': true, 'specialties': _specialties, 'ready': true});
  }
  return _api(uri);
}

Future<(int, Object)> _api(Uri uri) async {
  final p = uri.path;
  if (p.endsWith('/auth/login.php')) {
    return (200, {
      'ok': true,
      'token': 'shot-token',
      'type': 'client',
      'role': 'client',
      'firm_id': null,
      'permissions': [],
      'profile': {'id': 1, 'full_name': 'ثائر العتيبي'},
    });
  }
  if (p.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': _banners});
  }
  if (p.endsWith('/journey.php')) {
    switch (uri.queryParameters['action']) {
      case 'specialties':
        return (200, {'ok': true, 'specialties': _specialties});
      case 'issue-types':
        return (200, {'ok': true, 'issue_types': _issueTypes});
      case 'lawyers':
        return (200, {'ok': true, 'matched': true, 'lawyers': _lawyers});
    }
    return (200, {'ok': true});
  }
  if (p.endsWith('/available-slots.php')) {
    return (200, {
      'ok': true,
      'slots': ['09:00', '10:00', '11:00', '13:00', '15:00'],
    });
  }
  if (p.endsWith('/ai-status.php')) {
    return (200, {'ok': true, 'available': true, 'provider': 'local'});
  }
  if (p.endsWith('/notifications.php')) {
    return (200, {'ok': true, 'unread_count': 3, 'notifications': []});
  }
  if (p.endsWith('/consultations.php')) {
    return (200, {
      'ok': true,
      'consultations': [
        {
          'id': 7,
          'topic': 'استشارة بخصوص عقد إيجار تجاري',
          'status': 'confirmed',
          'consultation_type': 'text',
          'lawyer_name': 'المحامي عبدالله الحربي',
          'scheduled_date': '2026-11-02',
          'scheduled_time': '10:00',
          'attachment_count': 2,
        },
        {
          'id': 8,
          'topic': 'مراجعة اتفاقية شراكة',
          'status': 'pending',
          'consultation_type': 'voice',
          'lawyer_name': 'المحامية نورة القحطاني',
          'scheduled_date': '2026-11-05',
          'scheduled_time': '13:30',
          'attachment_count': 0,
        },
      ],
    });
  }
  if (p.endsWith('/lawyers.php')) {
    return (200, {'ok': true, 'lawyers': _lawyers});
  }
  if (p.endsWith('/me.php')) {
    return (200, {
      'ok': true,
      'profile': {'id': 1, 'full_name': 'ثائر العتيبي'},
      'type': 'client',
      'role': 'client',
      'permissions': [],
    });
  }
  return (200, {'ok': true, 'cases': [], 'documents': [], 'notifications': []});
}

// ------------------------------------------------------------------- harness

ApiRepository _repo(_FakeTransport t) =>
    ApiRepository(client: ApiClient(client: t, tokens: _MemoryTokenStore()));

/// Builds an [AuthController] in the requested session state.
///
/// The signed-in case goes through the real `signIn`, so the screenshot shows the
/// genuine post-login state rather than a hand-assembled one.
Future<AuthController> _auth(_FakeTransport t, {bool signedIn = false}) async {
  final a = AuthController(repository: _repo(t), tokens: _MemoryTokenStore());
  if (signedIn) {
    await a.signIn(
      email: 'client@mawatheq.com',
      password: 'secret123',
      asLawyer: false,
    );
  } else {
    a.enterGuest();
  }
  return a;
}

Widget _app({
  required AuthController auth,
  required ApiRepository repo,
  required Widget home,
  ThemeMode mode = ThemeMode.light,
  LocaleController? locale,
}) {
  final theme = ThemeController(prefs: _NullPrefs());
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
      ChangeNotifierProvider<LocaleController>.value(
        value: locale ?? LocaleController(),
      ),
      ChangeNotifierProvider<ThemeController>.value(value: theme),
      Provider<ApiRepository>.value(value: repo),
    ],
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: mode,
      home: home,
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
    ),
  );
}

class _NullPrefs implements PrefsStore {
  @override
  Future<void> saveThemeMode(String mode) async {}
  @override
  Future<String?> readThemeMode() async => null;
  @override
  Future<void> saveLocale(String locale) async {}
  @override
  Future<String> readLocale() async => 'ar';
  @override
  Future<void> saveOnboardingSeen(bool seen) async {}
  @override
  Future<bool> readOnboardingSeen() async => true;
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}


/// Asserts the screen actually rendered the fixture content, then captures it.
///
/// The assertion is the point: `matchesGoldenFile` is happy to write a picture of a
/// blank screen, so without this a broken screen would produce a green test and a
/// useless image. `expect` before `expectLater` makes a blank screen a failure.
Future<void> _shot(
  WidgetTester tester,
  String file,
  List<String> mustContain,
) async {
  final text = tester
      .widgetList<Text>(find.byType(Text))
      .map((t) => t.data ?? '')
      .join(' | ');
  for (final needle in mustContain) {
    expect(text, contains(needle), reason: '$file should show "$needle"');
  }
  await expectLater(
    find.byType(MaterialApp),
    matchesGoldenFile('goldens/$file'),
  );
}

void main() {
  setUpAll(() async {
    // Real Arabic fonts, so the screenshots show shaped Arabic instead of the
    // test framework's tofu boxes. The files are checked in next to this harness,
    // so a capture run is offline and reproducible.
    //
    // The families are registered under the exact names `google_fonts` resolves
    // to, and runtime fetching is switched off: without that the package reaches
    // for fonts.gstatic.com inside the test, which is slow and flaky.
    // The Arabic families are bundled in `assets/fonts/` under the names
    // `google_fonts` resolves to, so a capture renders the same type a build ships.
    // This also matches production: `app.dart` disables runtime fetching there too,
    // so the harness cannot accidentally pass with a font the app would not have.
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  setUp(() {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
    binding.platformDispatcher.accessibilityFeaturesTestValue =
        const FakeAccessibilityFeatures(disableAnimations: true);
  });

  tearDown(() {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.resetPhysicalSize();
    view.resetDevicePixelRatio();
    binding.platformDispatcher.clearAccessibilityFeaturesTestValue();
  });

  testWidgets('guest home', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: HomeShell(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '01_guest_home.png', <String>[
      'اختر تخصصك القانوني',
      'الأحوال الشخصية',
      'زائر',
    ]);
  });

  testWidgets('guest home fresh install (built-in slider)', (tester) async {
    // The production state: `home_banners` is empty, so the built-in Mawatheq
    // slides carry the carousel. This is the shot that proves the home is not just
    // two static cards.
    final t = _FakeTransport(_apiNoBanners);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: HomeShell(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '10_guest_home_fresh_slider.png', <String>[
      'استشارتك القانونية بخطوات بسيطة',
      'احجز استشارة',
      'اسأل المساعد الذكي',
      'اختر تخصصك القانوني',
    ]);
  });

  testWidgets('guest home with the imported taxonomy', (tester) async {
    // The state production reaches after MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql:
    // still no operator banners, so the built-in slider carries the carousel, and the
    // seven real categories now come from the server. The names below are the ones the
    // import writes, not invented placeholders — a screenshot of fake data would prove
    // nothing about the delivery.
    final t = _FakeTransport(_apiImportedTaxonomy);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: HomeShell(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '11_guest_home_imported_taxonomy.png', <String>[
      'استشارتك القانونية بخطوات بسيطة',
      'احجز استشارة',
      'اسأل المساعد الذكي',
      'اختر تخصصك القانوني',
      'الدعاوى العمالية',
      'دعاوى التنفيذ',
      'الدعاوى المتعلقة بالإنهاءات',
    ]);
  });

  testWidgets('signed-in home', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: true),
      repo: _repo(t),
      home: HomeShell(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '02_signed_in_home.png', <String>[
      'اختر تخصصك القانوني',
      'ثائر العتيبي',
    ]);
  });

  testWidgets('signed-in home dark', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: true),
      repo: _repo(t),
      home: HomeShell(repository: _repo(t)),
      mode: ThemeMode.dark,
    ));
    await _settle(tester);
    await _shot(tester, '03_signed_in_home_dark.png', <String>[
      'اختر تخصصك القانوني',
      'ثائر العتيبي',
    ]);
  });

  testWidgets('guest account tab', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: HomeShell(repository: _repo(t), initialIndex: 4),
    ));
    await _settle(tester);
    await _shot(tester, '04_guest_account_tab.png', <String>[
      'أنت تتصفّح كزائر',
      'تسجيل الدخول',
      'إنشاء حساب',
    ]);
  });

  testWidgets('specialty journey step', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: true),
      repo: _repo(t),
      home: ConsultationJourneyScreen(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '05_journey_specialty.png', <String>[
      'ما نوع مشكلتك القانونية؟',
      'الأحوال الشخصية',
    ]);
  });

  testWidgets('issue type step', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: true),
      repo: _repo(t),
      home: ConsultationJourneyScreen(
        repository: _repo(t),
        initialSpecialty: const JourneySpecialty(
          id: 1,
          name: 'الأحوال الشخصية',
          iconKey: 'family',
          lawyerCount: 4,
        ),
      ),
    ));
    await _settle(tester);
    await _shot(tester, '06_journey_issue_type.png', <String>[
      'حدّد نوع المشكلة',
      'طلاق أو خلع',
    ]);
  });

  testWidgets('lawyer selection step', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: true),
      repo: _repo(t),
      home: ConsultationJourneyScreen(
        repository: _repo(t),
        initialSpecialty: const JourneySpecialty(
          id: 1,
          name: 'الأحوال الشخصية',
          iconKey: 'family',
          lawyerCount: 4,
        ),
      ),
    ));
    await _settle(tester);
    // Move from the sub-specialty step to the lawyer comparison.
    await tester.tap(find.text('طلاق أو خلع'));
    await _settle(tester);
    await _shot(tester, '07_journey_lawyers.png', <String>[
      'اختر محاميك',
      'المحامي عبدالله الحربي',
    ]);
  });

  testWidgets('public lawyer directory', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: PublicLawyersScreen(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '08_lawyer_directory.png', <String>[
      'المحامي عبدالله الحربي',
      'الرياض',
    ]);
  });

  testWidgets('consultations list', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: true),
      repo: _repo(t),
      home: ConsultationsScreen(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '09_consultations.png', <String>[
      'عقد إيجار تجاري',
      'المحامي عبدالله الحربي',
    ]);
  });
}

