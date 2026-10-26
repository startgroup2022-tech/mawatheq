// Screenshot harness for the Execution 58 lawyer-app delivery evidence.
//
// It renders the REAL lawyer screens — LawyerShell and its dashboard, wallet,
// consultations, profile and settings, plus this app's sign-in — against a fake
// HTTP transport serving the exact JSON shapes the live endpoints return. Nothing
// here is a mock of a widget; only the network edge is replaced, which is the same
// seam `test/exec58_lawyer_app_test.dart` uses.
//
// Run from this package:
//   flutter test tool/screenshots/capture_lawyer_screens.dart --update-goldens
// PNGs land in tool/screenshots/goldens_lawyer/.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_profile_screen.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_settings_screen.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_shell.dart';
import 'package:provider/provider.dart';

import 'package:mawatheq_lawyer/features/auth/lawyer_login_screen.dart';

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
  Future<String?> readAccountType() async => 'lawyer';
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async => token = null;
}

// --------------------------------------------------------------- API fixtures

const _dashboard = {
  'ok': true,
  'lawyer': {'role': 'owner', 'is_firm': true, 'firm_name': 'شركة الحربي للمحاماة'},
  'consultations': {
    'pending': 3,
    'confirmed': 5,
    'completed': 41,
    'cancelled': 2,
    'total': 51,
  },
  'cases_active': 12,
  'clients': 27,
  'tasks_open': 4,
  'notifications_unread': 6,
  'wallet': {
    'currency': 'SAR',
    'status': 'active',
    'available_minor': 4820500,
    'pending_minor': 1200000,
    'reserved_minor': 0,
    'available': '48,205.00',
    'pending': '12,000.00',
    'reserved': '0.00',
    'gross_earned': '96,410.00',
    'net_earned': '82,050.00',
    'commission': '14,360.00',
    'withdrawn': '33,845.00',
    'payout_details': 'مصرف الراجحي •••• 4471',
  },
  'upcoming_appointments': [
    {
      'id': 21,
      'topic': 'مرافعة في قضية تجارية',
      'consultation_type': 'video',
      'scheduled_at': '2026-11-02 10:00',
      'client_name': 'شركة الأفق',
    },
    {
      'id': 22,
      'topic': 'مراجعة عقد توريد',
      'consultation_type': 'text',
      'scheduled_at': '2026-11-03 13:30',
      'client_name': 'مؤسسة النخبة',
    },
  ],
  'recent_activity': [
    {
      'kind': 'consultation',
      'id': 21,
      'title': 'استشارة بخصوص عقد إيجار',
      'status': 'confirmed',
      'at': 'اليوم',
    },
    {'kind': 'case', 'id': 9, 'title': 'قضية مطالبة مالية', 'status': 'active', 'at': 'أمس'},
  ],
};

const _walletSummary = {
  'ok': true,
  'currency': 'SAR',
  'status': 'active',
  'available_minor': 4820500,
  'pending_minor': 1200000,
  'reserved_minor': 0,
  'available': '48,205.00',
  'pending': '12,000.00',
  'reserved': '0.00',
  'gross_earned': '96,410.00',
  'net_earned': '82,050.00',
  'commission': '14,360.00',
  'withdrawn': '33,845.00',
  'payout_details': 'مصرف الراجحي •••• 4471',
};

const _transactions = {
  'ok': true,
  'transactions': [
    {
      'id': 501,
      'kind': 'payment',
      'amount': '2,000.00',
      'currency': 'SAR',
      'state': 'settled',
      'at': '2026-11-01',
      'description': 'استشارة مكتملة',
    },
    {
      'id': 502,
      'kind': 'commission',
      'amount': '-300.00',
      'currency': 'SAR',
      'state': 'settled',
      'at': '2026-11-01',
      'description': 'عمولة المنصة',
    },
  ],
};

const _withdrawals = {
  'ok': true,
  'withdrawals': [
    {
      'id': 71,
      'amount': '5,000.00',
      'currency': 'SAR',
      'state': 'paid',
      'method': 'bank_transfer',
      'requested_at': '2026-10-28',
    },
  ],
};

const _profile = {
  'ok': true,
  'profile': {
    'id': 1,
    'full_name': 'عبدالله الحربي',
    'email': 'lawyer@mawatheq.com',
    'phone': '+966500000000',
    'license_number': 'SA-2019-4471',
    'years_experience': 12,
    'bio': 'محامٍ ومستشار قانوني في الأنظمة التجارية.',
    'status': 'approved',
    'photo_url': '',
    'specialties': ['الأنظمة التجارية', 'التحكيم'],
  },
};

const _settings = {
  'ok': true,
  'settings': {
    'notify_email': true,
    'notify_push': true,
    'notify_sms': false,
    'profile_visible': true,
    'availability': 'available',
  },
};

const _specialties = {
  'ok': true,
  'specialties': [
    {'id': 1, 'name_ar': 'الأنظمة التجارية'},
    {'id': 2, 'name_ar': 'التحكيم'},
  ],
};

Future<(int, Object)> _api(Uri uri) async {
  final p = uri.path;
  if (p.endsWith('/auth/lawyer-login.php')) {
    // The exact shape the live endpoint returns: the account under `lawyer`, no
    // top-level `type`. This is what `exec58_lawyer_app_test.dart` pins.
    return (200, {
      'ok': true,
      'token': 'a' * 64,
      'lawyer': {
        'id': 1,
        'full_name': 'عبدالله الحربي',
        'email': 'lawyer@mawatheq.com',
        'specialization': 'الأنظمة التجارية',
        'rating': 5,
        'photo_path': null,
      },
    });
  }
  if (p.endsWith('/lawyer-dashboard.php')) return (200, _dashboard);
  if (p.endsWith('/wallet.php')) {
    switch (uri.queryParameters['action']) {
      case 'summary':
        return (200, _walletSummary);
      case 'transactions':
        return (200, _transactions);
      case 'withdrawals':
        return (200, _withdrawals);
      case 'statement':
        return (200, {'ok': true, 'statement': []});
    }
    return (200, {'ok': true});
  }
  if (p.endsWith('/lawyer-profile.php')) {
    switch (uri.queryParameters['action']) {
      case 'profile':
        return (200, _profile);
      case 'settings':
        return (200, _settings);
      case 'specialties':
        return (200, _specialties);
    }
    return (200, {'ok': true});
  }
  if (p.endsWith('/consultations.php')) {
    return (200, {
      'ok': true,
      'consultations': [
        {
          'id': 21,
          'topic': 'استشارة بخصوص عقد إيجار تجاري',
          'status': 'confirmed',
          'consultation_type': 'video',
          'client_name': 'شركة الأفق',
          'scheduled_date': '2026-11-02',
          'scheduled_time': '10:00',
          'attachment_count': 2,
        },
        {
          'id': 22,
          'topic': 'مراجعة اتفاقية شراكة',
          'status': 'pending',
          'consultation_type': 'text',
          'client_name': 'مؤسسة النخبة',
          'scheduled_date': '2026-11-03',
          'scheduled_time': '13:30',
          'attachment_count': 0,
        },
      ],
    });
  }
  if (p.endsWith('/notifications.php')) {
    return (200, {'ok': true, 'unread_count': 6, 'notifications': []});
  }
  return (200, {'ok': true});
}

// ------------------------------------------------------------------- harness

ApiRepository _repo(_FakeTransport t) =>
    ApiRepository(client: ApiClient(client: t, tokens: _MemoryTokenStore()));

Future<AuthController> _auth(_FakeTransport t, {bool signedIn = true}) async {
  final a = AuthController(repository: _repo(t), tokens: _MemoryTokenStore());
  if (signedIn) {
    await a.signIn(email: 'lawyer@mawatheq.com', password: 'secret123', asLawyer: true);
  }
  return a;
}

Widget _app({
  required AuthController auth,
  required ApiRepository repo,
  required Widget home,
  ThemeMode mode = ThemeMode.light,
}) {
  final theme = ThemeController(prefs: _NullPrefs());
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<LocaleController>(create: (_) => LocaleController()),
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
  for (var i = 0; i < 14; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}

/// Asserts the screen rendered the fixture content before capturing it.
///
/// `matchesGoldenFile` will happily write a picture of a blank screen, so without
/// this a broken screen would be a green test and a useless image.
Future<void> _shot(WidgetTester tester, String file, List<String> mustContain) async {
  final text = tester
      .widgetList<Text>(find.byType(Text))
      .map((t) => t.data ?? '')
      .join(' | ');
  for (final needle in mustContain) {
    expect(text, contains(needle), reason: '$file should show "$needle"');
  }
  await expectLater(find.byType(MaterialApp), matchesGoldenFile('goldens_lawyer/$file'));
}

void main() {
  setUpAll(() async {
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

  testWidgets('lawyer sign-in', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t, signedIn: false),
      repo: _repo(t),
      home: const LawyerLoginScreen(),
    ));
    await _settle(tester);
    await _shot(tester, '01_lawyer_login.png', <String>['مواثيق للمحامين']);
  });

  testWidgets('lawyer dashboard', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: LawyerShell(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '02_lawyer_dashboard.png', <String>[
      'لوحتي',
      'الاستشارات',
      'المحفظة',
      'حسابي',
    ]);
  });

  testWidgets('lawyer dashboard dark', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: LawyerShell(repository: _repo(t)),
      mode: ThemeMode.dark,
    ));
    await _settle(tester);
    await _shot(tester, '03_lawyer_dashboard_dark.png', <String>[
      'لوحتي',
      'المحفظة',
    ]);
  });

  testWidgets('lawyer wallet', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: LawyerShell(repository: _repo(t), initialIndex: 2),
    ));
    await _settle(tester);
    await _shot(tester, '04_lawyer_wallet.png', <String>['48,205.00']);
  });

  testWidgets('lawyer profile', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: LawyerProfileScreen(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '05_lawyer_profile.png', <String>['عبدالله الحربي']);
  });

  testWidgets('lawyer settings', (tester) async {
    final t = _FakeTransport(_api);
    await tester.pumpWidget(_app(
      auth: await _auth(t),
      repo: _repo(t),
      home: LawyerSettingsScreen(repository: _repo(t)),
    ));
    await _settle(tester);
    await _shot(tester, '06_lawyer_settings.png', <String>['الإعدادات']);
  });
}
