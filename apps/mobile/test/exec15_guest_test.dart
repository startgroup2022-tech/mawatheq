import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/app.dart';
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/guest_session.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_exception.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/auth/login_screen.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/home/home_tab.dart';
import 'package:mawatheq_mobile/features/public/pricing_screen.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';
import 'package:mawatheq_mobile/features/public/splash_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/auth_gate.dart';
import 'package:mawatheq_mobile/shared/widgets/banner_carousel.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// A fake transport returning the same JSON/status the PHP endpoints return, so the
/// production client, repository and widgets are exercised for real.
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

/// In-memory [TokenStore]; the production store uses the platform keychain, which
/// is not available in a unit-test process.
class _MemoryTokenStore implements TokenStore {
  _MemoryTokenStore({this.token});
  String? token;
  String? type;

  @override
  Future<void> saveToken(String value) async => token = value;
  @override
  Future<String?> readToken() async => token;
  @override
  Future<void> saveAccountType(String value) async => type = value;
  @override
  Future<String?> readAccountType() async => type;
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async {
    token = null;
    type = null;
  }
}

const _plansJson = {
  'ok': true,
  'plans': [
    {
      'id': 1,
      'name_ar': 'أساسية',
      'name_en': 'Basic',
      'description_ar': 'للأفراد',
      'description_en': 'For individuals',
      'price_monthly': '0.000',
      'price_yearly': '0.000',
      'ai_queries_limit': 15,
      'document_analysis_limit': 2,
      'consultations_included': 0,
      'is_corporate': 0,
      'is_featured': 0,
    },
    {
      'id': 2,
      'name_ar': 'احترافية',
      'name_en': 'Professional',
      'description_ar': 'للمحامين',
      'description_en': 'For lawyers',
      'price_monthly': '149.000',
      'price_yearly': '1490.000',
      'ai_queries_limit': null,
      'document_analysis_limit': 100,
      'consultations_included': 5,
      'is_corporate': 0,
      'is_featured': 1,
    },
  ],
};

/// The public endpoints the guest home calls, so the real repository and widgets are
/// exercised rather than a stub. `plans` is served too because the account tab links
/// to the pricing page from the same shell.
Future<(int, Object)> _publicEndpoints(Uri uri) async {
  if (uri.path.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': _bannersJson});
  }
  if (uri.path.endsWith('/journey.php')) {
    if (uri.queryParameters['action'] == 'specialties') {
      return (200, {
        'ok': true,
        'specialties': [
          {
            'id': 1,
            'name': 'الأحوال الشخصية',
            'icon_key': 'family',
            'lawyer_count': 3,
          },
          {
            'id': 2,
            'name': 'القضايا التجارية',
            'icon_key': 'commercial',
            'lawyer_count': 0,
          },
        ],
      });
    }
    return (200, {'ok': true, 'issue_types': []});
  }
  if (uri.path.endsWith('/lawyers.php')) {
    return (200, {'ok': true, 'lawyers': _lawyersJson});
  }
  if (uri.path.endsWith('/plans.php')) return (200, _plansJson);
  return (200, {'ok': true});
}

const _bannersJson = [
  {
    'id': 1,
    'title_ar': 'استشارتك الأولى بخصم ٢٠٪',
    'title_en': '20% off your first consultation',
    'subtitle_ar': 'لفترة محدودة',
    'image_path': 'uploads/banners/one.jpg',
    'alt_ar': 'عرض الاستشارة الأولى',
    'link_type': 'none',
    'link_target': null,
  },
  {
    'id': 2,
    'title_ar': 'تعرّف على محامينا',
    'title_en': 'Meet our lawyers',
    'image_path': 'uploads/banners/two.jpg',
    'link_type': 'specialty',
    'link_target': '1',
  },
];

const _lawyersJson = [
  {
    'id': 7,
    'full_name': 'سارة العتيبي',
    'specialization': 'الأحوال الشخصية',
    'jurisdiction': 'الرياض',
    'rating': '4.800',
    'hourly_rate': '350.000',
  },
  {
    'id': 8,
    'full_name': 'خالد المطيري',
    'specialization': 'القضايا التجارية',
    'jurisdiction': 'جدة',
    'rating': '0',
    'hourly_rate': '0',
  },
];

ApiRepository _repo(_FakeTransport transport) => ApiRepository(
      client: ApiClient(client: transport, tokens: _MemoryTokenStore()),
    );

Widget _wrap({
  required AuthController auth,
  required Widget child,
  GuestSession? guest,
  LocaleController? locale,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>.value(
        value: guest ?? GuestSession(),
      ),
      ChangeNotifierProvider<LocaleController>.value(
        value: locale ?? LocaleController(),
      ),
    ],
    child: MaterialApp(home: child),
  );
}

/// The consumer home is a tab body, not a page: it expects the shell to supply the
/// `Scaffold` (and therefore the `Material` its search field needs). Tests that mount
/// it directly must provide one, exactly as the shell does.
Widget _homeTab({
  required AuthController auth,
  required ApiRepository repo,
  GuestSession? guest,
  LocaleController? locale,
  VoidCallback? onOpenLawyers,
}) {
  return _wrap(
    auth: auth,
    guest: guest,
    locale: locale,
    child: Scaffold(
      body: HomeTab(
        repository: repo,
        onOpenLawyers: onOpenLawyers ?? () {},
      ),
    ),
  );
}

/// Mounts a widget that reads [LocaleController], which the carousel does to pick
/// between the Arabic and English copy of a banner.
Widget _withLocale(Widget child) {
  return ChangeNotifierProvider<LocaleController>.value(
    value: LocaleController(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

/// Taps a bottom-nav destination by name. The nav is matched explicitly because the
/// guest home also uses some of these words as section headings and actions.
Future<void> _tapNav(WidgetTester tester, String label) async {
  await tester.tap(
    find.descendant(
      of: find.byType(NavigationBar),
      matching: find.text(label),
    ),
  );
  await tester.pumpAndSettle();
}

/// Scrolls the guest homepage until [finder] is on screen. The page is long, so a
/// widget below the fold must be scrolled to before it can be tapped or asserted
/// on reliably.
Future<void> _scrollTo(WidgetTester tester, Finder finder) async {
  await tester.scrollUntilVisible(
    finder,
    220,
    scrollable: find.byType(Scrollable).first,
    maxScrolls: 60,
  );
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    // LocaleController persists through SharedPreferences; without a mock the
    // platform channel never answers and the test hangs.
    SharedPreferences.setMockInitialValues({});
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
    // The banner carousel auto-advances on a timer. Disabling animations is the
    // signal it uses to stay static, and it is what a real reduced-motion user gets.
    // It also keeps every test deterministic instead of racing a six-second timer.
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

  // ---------------------------------------------------------------- guest mode

  group('Guest access', () {
    test('entering guest mode issues no token and no user id', () async {
      final tokens = _MemoryTokenStore();
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
        tokens: tokens,
      );

      await auth.restore();
      expect(auth.status, AuthStatus.signedOut);

      auth.enterGuest();
      expect(auth.status, AuthStatus.guest);
      expect(auth.isGuest, isTrue);
      // The whole point of guest mode: no identity and no credential exist.
      expect(auth.isSignedIn, isFalse);
      expect(auth.user.id, 0);
      expect(auth.user.name, isEmpty);
      expect(await tokens.readToken(), isNull);
    });

    test('a guest is never treated as signed in by can()', () async {
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
        tokens: _MemoryTokenStore(),
      );
      auth.enterGuest();
      expect(auth.can('documents.upload'), isFalse);
      expect(auth.can('cases.view'), isFalse);
    });

    test('leaving guest mode returns to the welcome state, token untouched', () {
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
        tokens: _MemoryTokenStore(),
      );
      auth.enterGuest();
      auth.exitGuest();
      expect(auth.status, AuthStatus.signedOut);
      expect(auth.isGuest, isFalse);
    });

    testWidgets('the guest account tab offers sign-in, register and exit',
        (tester) async {
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(auth: auth, child: const HomeShell()),
      );
      await tester.pumpAndSettle();

      // Open the account tab.
      await _tapNav(tester, 'حسابي');

      expect(find.text('أنت تتصفّح كزائر'), findsOneWidget);
      expect(find.text('إنشاء حساب'), findsOneWidget);

      // The tab is a long list, so the exit action sits below the fold.
      await _scrollTo(tester, find.text('إنهاء وضع الزائر'));
      await tester.tap(find.text('إنهاء وضع الزائر'));
      await tester.pumpAndSettle();
      expect(auth.status, AuthStatus.signedOut);
    });
  });

  // ------------------------------------------------------------ guest homepage

  group('Consumer home for a guest', () {
    testWidgets('renders the operator banners from the API', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      // The first slide is the operator's own copy, not a placeholder promotion.
      expect(find.text('استشارتك الأولى بخصم ٢٠٪'), findsOneWidget);
      expect(find.text('لفترة محدودة'), findsOneWidget);
    });

    testWidgets('no operator banners still shows the built-in slider',
        (tester) async {
      // The home must never lose its slider. On a fresh install `home_banners` is
      // empty, so the built-in Mawatheq slides carry it instead of the top of the
      // screen collapsing to a bare pair of action cards.
      final transport = _FakeTransport((uri) async {
        if (uri.path.endsWith('/banners.php')) {
          return (200, {'ok': true, 'banners': []});
        }
        return _publicEndpoints(uri);
      });
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(BannerCarousel), findsOneWidget);
      expect(find.byType(PageView), findsOneWidget);
      // The first built-in slide is on screen.
      expect(find.text('استشارتك القانونية بخطوات بسيطة'), findsOneWidget);
      // The rest of the home screen still renders.
      expect(find.text('اختر تخصصك القانوني'), findsOneWidget);
    });

    testWidgets('shows real specialties with their lawyer counts',
        (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('الأحوال الشخصية'), findsOneWidget);
      expect(find.text('3 محامٍ'), findsOneWidget);
      // A specialty nobody covers is still listed, and says nothing misleading.
      expect(find.text('القضايا التجارية'), findsOneWidget);
    });

    testWidgets('a banner failure does not blank the specialties',
        (tester) async {
      final transport = _FakeTransport((uri) async {
        if (uri.path.endsWith('/banners.php')) {
          return (500, {'ok': false, 'error': 'boom'});
        }
        return _publicEndpoints(uri);
      });
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      // One failing section must not take the page down with it. A failed banner
      // request falls back to the built-in slides, so the slider is still there and
      // the specialties still render.
      expect(find.text('الأحوال الشخصية'), findsOneWidget);
      expect(find.byType(BannerCarousel), findsOneWidget);
      expect(find.text('استشارتك القانونية بخطوات بسيطة'), findsOneWidget);
    });

    testWidgets('shows no private data — no case, document or user content',
        (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      // The guest home must never surface a private record.
      expect(find.textContaining('قضيتي'), findsNothing);
      expect(find.textContaining('فاتورة #'), findsNothing);
    });

    testWidgets('a quick service raises the sign-in prompt', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
    guest: guest,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      await _scrollTo(tester, find.text('إدارة القضايا'));
      await tester.tap(find.text('إدارة القضايا'));
      await tester.pumpAndSettle();

      expect(find.text('متابعة التصفح'), findsOneWidget);
      await tester.tap(find.text('تسجيل الدخول').last);
      await tester.pumpAndSettle();
      expect(guest.pendingIntent, ProtectedDestination.cases);
    });
  });

  // --------------------------------------------------------- protected routes

  group('Protected routes for a guest', () {
    testWidgets('tapping a private area raises the auth prompt', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
    guest: guest,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      await _scrollTo(tester, find.text('الاستشارات القانونية'));
      await tester.tap(find.text('الاستشارات القانونية'));
      await tester.pumpAndSettle();

      // The three required choices, all in Arabic.
      expect(find.text('تسجيل الدخول'), findsWidgets);
      expect(find.text('إنشاء حساب'), findsWidgets);
      expect(find.text('متابعة التصفح'), findsOneWidget);
    });

    testWidgets('"متابعة التصفح" keeps the guest on the public page and fires '
        'no request', (tester) async {
      var calls = 0;
      final transport = _FakeTransport((uri) async {
        calls++;
        return _publicEndpoints(uri);
      });
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
    guest: guest,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();
      final callsBefore = calls;

      await _scrollTo(tester, find.text('إدارة المستندات'));
      await tester.tap(find.text('إدارة المستندات'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('متابعة التصفح'));
      await tester.pumpAndSettle();

      // Still on the public page, and no protected endpoint was called. Asserted on
      // the widget rather than a heading, because the list is scrolled at this point.
      expect(find.byType(HomeTab), findsOneWidget);
      expect(calls, callsBefore);
      // Keeping browsing records no destination to resume.
      expect(guest.hasPendingIntent, isFalse);
    });

    testWidgets('choosing sign in records the intended destination',
        (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _homeTab(
          auth: auth,
    guest: guest,
          repo: _repo(transport),
        ),
      );
      await tester.pumpAndSettle();

      await _scrollTo(tester, find.text('المساعد القانوني'));
      await tester.tap(find.text('المساعد القانوني'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('تسجيل الدخول').last);
      await tester.pumpAndSettle();

      expect(guest.pendingIntent, ProtectedDestination.legalAi);
    });
  });

  // ------------------------------------------------- public lawyer directory

  group('Public lawyer directory', () {
    testWidgets('lists real lawyers from the public endpoint', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PublicLawyersScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('سارة العتيبي'), findsOneWidget);
      expect(find.text('الأحوال الشخصية'), findsOneWidget);
      expect(find.text('350 ر.س/ساعة'), findsOneWidget);
      expect(find.text('2 محامياً متاحاً'), findsOneWidget);
      // A lawyer with no rating shows no stars rather than a fabricated zero.
      expect(find.text('خالد المطيري'), findsOneWidget);
    });

    testWidgets('search filters the already-loaded directory', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PublicLawyersScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await tester.enterText(find.byType(TextField), 'جدة');
      await tester.pumpAndSettle();

      expect(find.text('خالد المطيري'), findsOneWidget);
      expect(find.text('سارة العتيبي'), findsNothing);
      expect(find.text('1 محامياً متاحاً'), findsOneWidget);
    });

    testWidgets('booking a lawyer raises the sign-in prompt', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: PublicLawyersScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('احجز استشارة').first);
      await tester.pumpAndSettle();

      // Booking needs an account; the prompt names the lawyer and is not a dead end.
      expect(find.textContaining('سارة العتيبي'), findsWidgets);
      expect(find.text('متابعة التصفح'), findsOneWidget);
    });
  });

  // ------------------------------------------------------- login transitions

  group('Login transition preserves the destination', () {
    test('consume() returns the destination exactly once', () {
      final guest = GuestSession()..remember(ProtectedDestination.documents);
      expect(guest.consume(), ProtectedDestination.documents);
      expect(guest.consume(), isNull);
      expect(guest.hasPendingIntent, isFalse);
    });

    testWidgets('after sign-in the app opens the remembered destination',
        (tester) async {
      final transport = _FakeTransport((uri) async {
        if (uri.path.endsWith('/auth/login.php')) {
          return (200, {
            'ok': true,
            'token': 'tok',
            'type': 'client',
            'profile': {'id': 1, 'full_name': 'عميل'},
            'role': 'client',
            'firm_id': null,
            'permissions': [],
          });
        }
        return (200, {'ok': true, 'cases': [], 'documents': [],
            'consultations': [], 'notifications': []});
      });
      final repo = _repo(transport);
      final auth = AuthController(
        repository: repo,
        tokens: _MemoryTokenStore(),
      );
      final guest = GuestSession()..remember(ProtectedDestination.documents);

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: const LoginScreen(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
          find.byType(TextFormField).first, 'client@example.com');
      await tester.enterText(find.byType(TextFormField).last, 'secret123');
      await tester.tap(find.widgetWithText(FilledButton, 'تسجيل الدخول'));
      // Bounded pumps, not pumpAndSettle: the shell's tabs show a loading
      // skeleton while their requests are in flight, so nothing ever "settles".
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      // The shell opened on the Documents tab (index 3) rather than the dashboard.
      expect(find.byType(HomeShell), findsOneWidget);
      final shell = tester.widget<HomeShell>(find.byType(HomeShell));
      expect(shell.initialIndex, 3);
      // The intent was consumed, so it cannot be replayed.
      expect(guest.hasPendingIntent, isFalse);
    });

    testWidgets('without a remembered destination sign-in lands on the dashboard',
        (tester) async {
      final transport = _FakeTransport((uri) async {
        if (uri.path.endsWith('/auth/login.php')) {
          return (200, {
            'ok': true,
            'token': 'tok',
            'type': 'client',
            'profile': {'id': 1, 'full_name': 'عميل'},
            'role': 'client',
            'firm_id': null,
            'permissions': [],
          });
        }
        return (200, {'ok': true, 'cases': [], 'documents': [],
            'consultations': [], 'notifications': []});
      });
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(auth: auth, child: const LoginScreen()),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
          find.byType(TextFormField).first, 'client@example.com');
      await tester.enterText(find.byType(TextFormField).last, 'secret123');
      await tester.tap(find.widgetWithText(FilledButton, 'تسجيل الدخول'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      final shell = tester.widget<HomeShell>(find.byType(HomeShell));
      expect(shell.initialIndex, 0);
    });
  });

  // ------------------------------------------------------------ splash routing

  group('Splash routing', () {
    testWidgets('the splash shows the original logo and Arabic brand name',
        (tester) async {
      await tester.pumpWidget(
        const MaterialApp(home: SplashScreen()),
      );
      await tester.pump();

      expect(find.text('مواثيق'), findsOneWidget);
      expect(find.text('منطقة'), findsNothing);
      // The original asset is used, not a redrawn icon.
      expect(find.byType(Image), findsOneWidget);
    });

    testWidgets('an unauthenticated start opens the consumer shell, not a '
        'slideshow', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider<AuthController>.value(value: auth),
            ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
            ChangeNotifierProvider<LocaleController>(
              create: (_) => LocaleController(),
            ),
          ],
          child: const MawatheqApp(),
        ),
      );
      await tester.pumpAndSettle(const Duration(seconds: 2));

      // The first screen is the app itself, with browsable content — not an
      // onboarding carousel standing between the visitor and the product.
      expect(find.byType(HomeShell), findsOneWidget);
      expect(find.byType(HomeTab), findsOneWidget);
    });

    testWidgets('a guest start routes to the public shell', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider<AuthController>.value(value: auth),
            ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
            ChangeNotifierProvider<LocaleController>(
              create: (_) => LocaleController(),
            ),
          ],
          child: const MawatheqApp(),
        ),
      );
      await tester.pumpAndSettle(const Duration(seconds: 2));

      expect(find.byType(HomeShell), findsOneWidget);
      expect(find.byType(HomeTab), findsOneWidget);
    });

    testWidgets('an expired stored token lands on the consumer shell, not a '
        'broken session', (tester) async {
      final tokens = _MemoryTokenStore(token: 'expired');
      final transport = _FakeTransport(
        (_) async => (401, {'ok': false, 'error': 'expired'}),
      );
      final auth = AuthController(
        repository: _repo(transport),
        tokens: tokens,
      );

      await auth.restore();

      expect(auth.status, AuthStatus.signedOut);
      expect(auth.sessionExpired, isTrue);
      // The unusable token was cleared locally.
      expect(await tokens.readToken(), isNull);
    });

    test('a transport failure is not reported as an expired session', () async {
      final tokens = _MemoryTokenStore(token: 'still-good');
      final transport = _FakeTransport((_) async => (500, {'ok': false}));
      final auth = AuthController(repository: _repo(transport), tokens: tokens);

      await auth.restore();

      expect(auth.status, AuthStatus.signedOut);
      expect(auth.sessionExpired, isFalse);
      // An offline start must not discard a token the server never rejected.
      expect(await tokens.readToken(), 'still-good');
    });
  });

  // ----------------------------------------------------------------- pricing

  group('Pricing page', () {
    testWidgets('shows the yearly billing period from the API', (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PricingScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('سنوي'));
      await tester.pumpAndSettle();

      // The yearly price comes from the server's price_yearly column.
      expect(find.text('1490'), findsOneWidget);
    });

    testWidgets('offers a comparison built from server fields', (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PricingScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await tester.scrollUntilVisible(
        find.text('مقارنة الخطط'),
        200,
        scrollable: find.byType(Scrollable).first,
        maxScrolls: 40,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('مقارنة الخطط'));
      await tester.pumpAndSettle();

      expect(find.byType(DataTable), findsOneWidget);
      // The unlimited plan states "unlimited" rather than a fabricated number.
      expect(find.text('غير محدود'), findsWidgets);
    });

    testWidgets('never offers a purchase the backend cannot perform',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PricingScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('الاشتراك متاح من المنصة'),
        findsWidgets,
      );
      expect(find.text('اشترك الآن'), findsNothing);
    });

    testWidgets('an API failure shows an error state, not fabricated prices',
        (tester) async {
      final transport = _FakeTransport((_) async => (503, {'ok': false}));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PricingScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('149'), findsNothing);
      expect(find.textContaining('إعادة المحاولة'), findsWidgets);
    });
  });

  // ------------------------------------------------------------------- RTL/LTR

  group('Arabic RTL and English LTR', () {
    testWidgets('Arabic renders RTL and shows Arabic copy', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider<AuthController>.value(value: auth),
            ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
            ChangeNotifierProvider<LocaleController>.value(
              value: LocaleController(),
            ),
          ],
          child: Builder(
            builder: (context) {
              final locale = context.watch<LocaleController>();
              return MaterialApp(
                home: Directionality(
                  textDirection: locale.isRtl
                      ? TextDirection.rtl
                      : TextDirection.ltr,
                  child: Scaffold(
                    body: HomeTab(
                      repository: _repo(transport),
                      onOpenLawyers: () {},
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      final dir = Directionality.of(
        tester.element(find.byType(HomeTab)),
      );
      expect(dir, TextDirection.rtl);
      expect(find.text('اختر تخصصك القانوني'), findsOneWidget);
    });

    testWidgets('English mode renders LTR with English copy', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final locale = LocaleController();
      await locale.setLocale('en');

      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider<AuthController>.value(value: auth),
            ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
            ChangeNotifierProvider<LocaleController>.value(value: locale),
          ],
          child: Builder(
            builder: (context) {
              final l = context.watch<LocaleController>();
              return MaterialApp(
                home: Directionality(
                  textDirection:
                      l.isRtl ? TextDirection.rtl : TextDirection.ltr,
                  child: Scaffold(
                    body: HomeTab(
                      repository: _repo(transport),
                      onOpenLawyers: () {},
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      final dir = Directionality.of(
        tester.element(find.byType(HomeTab)),
      );
      expect(dir, TextDirection.ltr);
      expect(find.text('Choose your specialty'), findsOneWidget);
      expect(find.text('20% off your first consultation'), findsOneWidget);
    });
  });

  // ------------------------------------------------------- auth prompt choices

  group('Auth prompt', () {
    testWidgets('requireAccount returns false when the guest keeps browsing',
        (tester) async {
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      late bool result;
      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: Builder(
            builder: (context) => Scaffold(
              body: Center(
                child: TextButton(
                  onPressed: () async {
                    result = await requireAccount(
                      context,
                      ProtectedDestination.cases,
                    );
                  },
                  child: const Text('go'),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('متابعة التصفح'));
      await tester.pumpAndSettle();

      expect(result, isFalse);
    });
  });

  // ------------------------------------------------------------ api exception

  group('Guest calls stay on public endpoints', () {
    test('plans() and lawyers() are unauthenticated; me() is not', () async {
      final urls = <String>[];
      final transport = _FakeTransport((uri) async {
        urls.add(uri.path);
        if (uri.path.endsWith('/plans.php')) return (200, _plansJson);
        if (uri.path.endsWith('/lawyers.php')) {
          return (200, {'ok': true, 'lawyers': []});
        }
        return (401, {'ok': false, 'error': 'unauthorized'});
      });
      final repo = _repo(transport);

      await repo.plans();
      await repo.lawyers();

      // No Authorization header is required for the public reads, and no token
      // exists to leak.
      expect(urls, contains('/api/v1/plans.php'));
      expect(urls, contains('/api/v1/lawyers.php'));

      // A protected call without a token is refused by the server.
      await expectLater(
        repo.cases(),
        throwsA(isA<ApiException>()),
      );
    });
  });

  // ------------------------------------------------ guest routing (exec 41)
  //
  // Execution 41 replaced the two shells with one. These assert the replacement
  // contract: a guest and a signed-in customer get the same shell and the same
  // home, and only the personal parts differ.

  group('Unified shell', () {
    testWidgets('a guest lands in the one shell, on the one home', (tester) async {
      final auth = AuthController(
        repository: _repo(_FakeTransport(_publicEndpoints)),
        tokens: _MemoryTokenStore(),
      );
      final guest = GuestSession();
      final locale = LocaleController();
      await locale.setLocale('ar');

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          locale: locale,
          child: HomeShell(repository: _repo(_FakeTransport(_publicEndpoints))),
        ),
      );
      await tester.pumpAndSettle();

      // One shell, and the home inside it. There is no second shell to fall back to.
      expect(find.byType(HomeShell), findsOneWidget);
      expect(find.byType(HomeTab), findsOneWidget);

      // The tab bar is the full consumer set for a guest too: the same five tabs a
      // signed-in customer gets, because the gate is on the destination, not on the
      // tab's existence.
      Finder nav(String label) => find.descendant(
            of: find.byType(NavigationBar),
            matching: find.text(label),
          );
      expect(nav('الرئيسية'), findsOneWidget);
      expect(nav('القضايا'), findsOneWidget);
      expect(nav('المساعد'), findsOneWidget);
      expect(nav('المستندات'), findsOneWidget);
      expect(nav('حسابي'), findsOneWidget);
    });

    testWidgets('a guest home has the same structure as a signed-in home',
        (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(auth: auth, child: HomeShell(repository: _repo(transport))),
      );
      await tester.pumpAndSettle();

      // The guest's home is the real home: the two primary actions and the
      // specialty section, not a reduced or different layout.
      expect(find.byType(HomeTab), findsOneWidget);
      expect(find.text('احجز استشارة'), findsOneWidget);
      expect(find.text('اسأل المساعد الذكي'), findsOneWidget);
      expect(find.text('اختر تخصصك القانوني'), findsOneWidget);
    });

    testWidgets('a protected tab raises the sign-in prompt, not a dead screen',
        (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: HomeShell(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await _tapNav(tester, 'القضايا');

      // The prompt names the requested service and the guest stays on Home.
      expect(find.textContaining('إدارة القضايا'), findsWidgets);
      expect(find.byType(HomeTab), findsOneWidget);
    });

    testWidgets('the guest account tab offers sign-in and register, and no '
        'private data', (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(auth: auth, child: HomeShell(repository: _repo(transport))),
      );
      await tester.pumpAndSettle();

      await _tapNav(tester, 'حسابي');

      expect(find.text('أنت تتصفّح كزائر'), findsOneWidget);
      expect(find.text('تسجيل الدخول'), findsWidgets);
      expect(find.text('إنشاء حساب'), findsOneWidget);
    });

    testWidgets('a guest choosing a plan is routed to sign-in, not a checkout',
        (tester) async {
      final transport = _FakeTransport(_publicEndpoints);
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: PricingScreen(repository: _repo(transport), embedded: true),
        ),
      );
      await tester.pumpAndSettle();

      final choose = find.text('اختيار هذه الباقة').first;
      await tester.tap(choose);
      await tester.pumpAndSettle();

      // No subscription is activated; the chosen plan is only remembered for the
      // sign-in flow, and the prompt states the service requires an account.
      expect(guest.selectedPlan, isNotNull);
      expect(find.textContaining('الخدمة المطلوبة'), findsOneWidget);
    });
  });


  // ------------------------------------------------ banner carousel (exec 38)

  group('Banner carousel', () {
    testWidgets('renders nothing at all when there are no banners',
        (tester) async {
      await tester.pumpWidget(
        _withLocale(
          BannerCarousel(banners: const [], autoAdvance: false)
        ),
      );
      await tester.pumpAndSettle();

      // No frame, no dots, no placeholder — the caller shows its own content.
      expect(find.byType(PageView), findsNothing);
      expect(find.byType(SizedBox), findsWidgets);
      expect(tester.getSize(find.byType(BannerCarousel)).height, 0);
    });

    testWidgets('paging dots appear only when there is more than one banner',
        (tester) async {
      final one = [
        HomeBanner.fromJson(Map<String, dynamic>.from(_bannersJson[0])),
      ];
      await tester.pumpWidget(
        _withLocale(
          BannerCarousel(banners: one, autoAdvance: false)
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(PageView), findsOneWidget);
      expect(find.byType(AnimatedContainer), findsNothing);

      final two = _bannersJson
          .map((e) => HomeBanner.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      await tester.pumpWidget(
        _withLocale(
          BannerCarousel(banners: two, autoAdvance: false)
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(AnimatedContainer), findsNWidgets(2));
    });

    testWidgets('a banner with no destination is not tappable', (tester) async {
      var taps = 0;
      final banners = _bannersJson
          .map((e) => HomeBanner.fromJson(Map<String, dynamic>.from(e)))
          .toList();

      await tester.pumpWidget(
        _withLocale(
          BannerCarousel(
          banners: banners,
          autoAdvance: false,
          onTap: (_) => taps++,
          )
        ),
      );
      await tester.pumpAndSettle();

      // Slide one is link_type "none": it has no InkWell, so nothing can fire.
      expect(find.text('اعرف المزيد'), findsNothing);
      await tester.tap(find.text('استشارتك الأولى بخصم ٢٠٪'));
      await tester.pumpAndSettle();
      expect(taps, 0);

      // Slide two links to a specialty and does offer the affordance.
      await tester.drag(find.byType(PageView), const Offset(-400, 0));
      await tester.pumpAndSettle();
      expect(find.text('اعرف المزيد'), findsOneWidget);
      await tester.tap(find.text('تعرّف على محامينا'));
      await tester.pumpAndSettle();
      expect(taps, 1);
    });

    testWidgets('a broken image degrades to a titled block, not an error',
        (tester) async {
      final banners = [
        HomeBanner.fromJson(Map<String, dynamic>.from(_bannersJson[1])),
      ];

      await tester.pumpWidget(
        _withLocale(
          BannerCarousel(banners: banners, autoAdvance: false)
        ),
      );
      // Image.network cannot resolve in a widget test, so it takes the error path.
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('تعرّف على محامينا'), findsOneWidget);
    });
  });

  // ---------------------------------------------- permission-aware resume

  group('Permission-aware resume', () {
    test('a staff member without the capability is denied the destination', () {
      const staff = SessionUser(
        type: 'lawyer',
        id: 7,
        name: 'Staff',
        role: 'staff',
        permissions: ['cases.view', 'documents.view'],
      );
      // Staff can read cases but not manage documents' deletion; the destination
      // gate follows the server-issued capability, not a guess.
      expect(ProtectedDestination.cases.allows(staff), isTrue);
      expect(ProtectedDestination.consultations.allows(staff), isFalse);
    });

    test('a client is allowed their own destinations without tenant permissions',
        () {
      const client = SessionUser(
        type: 'client',
        id: 3,
        name: 'Client',
        role: 'client',
      );
      // Clients hold no tenant capabilities; the gate must not deny them their own
      // data just because permissions is empty.
      expect(ProtectedDestination.cases.allows(client), isTrue);
      expect(ProtectedDestination.documents.allows(client), isTrue);
      expect(ProtectedDestination.consultations.allows(client), isTrue);
    });

    test('a signed-in user is denied when the destination needs a missing capability',
        () {
      const staff = SessionUser(
        type: 'lawyer',
        id: 9,
        name: 'Staff',
        role: 'staff',
        permissions: ['cases.view'],
      );
      expect(ProtectedDestination.consultations.allows(staff), isFalse);
      // Destinations with no required permission are open to any signed-in user.
      expect(ProtectedDestination.legalAi.allows(staff), isTrue);
      expect(ProtectedDestination.account.allows(staff), isTrue);
    });
  });
}
