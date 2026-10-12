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
import 'package:mawatheq_mobile/features/public/onboarding_screen.dart';
import 'package:mawatheq_mobile/features/public/pricing_screen.dart';
import 'package:mawatheq_mobile/features/public/public_guest_shell.dart';
import 'package:mawatheq_mobile/features/public/public_home_screen.dart';
import 'package:mawatheq_mobile/features/public/splash_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/auth_gate.dart';
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
    final view =
        TestWidgetsFlutterBinding.ensureInitialized().platformDispatcher.views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
  });

  tearDown(() {
    final view =
        TestWidgetsFlutterBinding.ensureInitialized().platformDispatcher.views.first;
    view.resetPhysicalSize();
    view.resetDevicePixelRatio();
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

    testWidgets('the welcome screen offers a prominent guest entry',
        (tester) async {
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      auth.exitGuest();

      await tester.pumpWidget(_wrap(auth: auth, child: const OnboardingScreen()));
      await tester.pumpAndSettle();

      expect(find.text('الدخول كزائر'), findsOneWidget);

      await tester.tap(find.text('الدخول كزائر'));
      await tester.pumpAndSettle();
      expect(auth.status, AuthStatus.guest);
    });
  });

  // ------------------------------------------------------------ guest homepage

  group('Guest homepage', () {
    testWidgets('renders the public sections and real prices from the API',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PublicHomeScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      // Public sections are present.
      expect(find.text('مواثيق'), findsWidgets);
      expect(find.text('الدخول كزائر'), findsNothing); // not the welcome button
      expect(find.textContaining('منصة قانونية متكاملة'), findsWidgets);

      // Prices come from the API, not from a hardcoded constant. The pricing
      // preview sits below the fold, so scroll it into view first.
      await _scrollTo(tester, find.text('149'));
      expect(find.text('149'), findsOneWidget);
      expect(find.text('مجاناً'), findsOneWidget);
    });

    testWidgets('shows no private data — no case, document or user content',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(auth: auth, child: PublicHomeScreen(repository: _repo(transport))),
      );
      await tester.pumpAndSettle();

      // The guest homepage must never surface a private record.
      expect(find.textContaining('قضيتي'), findsNothing);
      expect(find.textContaining('فاتورة #'), findsNothing);
    });

    testWidgets('an unavailable pricing API shows an honest state, not prices',
        (tester) async {
      final transport = _FakeTransport(
        (_) async => (503, {'ok': false, 'error': 'unavailable'}),
      );
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(auth: auth, child: PublicHomeScreen(repository: _repo(transport))),
      );
      await tester.pumpAndSettle();

      await _scrollTo(tester, find.textContaining('تعذّر تحميل الأسعار'));
      expect(find.textContaining('تعذّر تحميل الأسعار'), findsOneWidget);
      expect(find.text('149'), findsNothing);
    });
  });

  // --------------------------------------------------------- protected routes

  group('Protected routes for a guest', () {
    testWidgets('tapping a private area raises the auth prompt', (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: PublicHomeScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await _scrollTo(tester, find.text('منطقتك الخاصة'));
      await tester.tap(find.text('القضايا').first);
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
        return (200, _plansJson);
      });
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: PublicHomeScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();
      final callsBefore = calls;

      await _scrollTo(tester, find.text('منطقتك الخاصة'));
      await tester.tap(find.text('المستندات').first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('متابعة التصفح'));
      await tester.pumpAndSettle();

      // Still on the public page, and no protected endpoint was called.
      expect(find.text('تسجيل الدخول'), findsWidgets);
      expect(calls, callsBefore);
      // Keeping browsing records no destination to resume.
      expect(guest.hasPendingIntent, isFalse);
    });

    testWidgets('choosing sign in records the intended destination',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: PublicHomeScreen(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      await _scrollTo(tester, find.text('منطقتك الخاصة'));
      await tester.tap(find.text('المساعد الذكي').first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('تسجيل الدخول').last);
      await tester.pumpAndSettle();

      expect(guest.pendingIntent, ProtectedDestination.legalAi);
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

    testWidgets('an unauthenticated start routes to the welcome screen',
        (tester) async {
      final transport = _FakeTransport((uri) async {
        return (200, {'ok': true, 'plans': []});
      });
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

      expect(find.byType(OnboardingScreen), findsOneWidget);
    });

    testWidgets('a guest start routes to the public homepage', (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
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

      expect(find.byType(PublicHomeScreen), findsOneWidget);
    });

    testWidgets('an expired stored token lands on the welcome screen, not a '
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
      final transport = _FakeTransport((_) async => (200, _plansJson));
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
                  child: PublicHomeScreen(repository: _repo(transport)),
                ),
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      final dir = Directionality.of(
        tester.element(find.byType(PublicHomeScreen)),
      );
      expect(dir, TextDirection.rtl);
      expect(find.text('إنشاء حساب مجاني'), findsOneWidget);
    });

    testWidgets('English mode renders LTR with English copy', (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
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
                  child: PublicHomeScreen(repository: _repo(transport)),
                ),
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      final dir = Directionality.of(
        tester.element(find.byType(PublicHomeScreen)),
      );
      expect(dir, TextDirection.ltr);
      expect(find.text('Create a free account'), findsOneWidget);
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

  // ------------------------------------------------ guest routing (exec 15.1)

  group('Guest routing', () {
    testWidgets('a guest lands on the public shell, never the authenticated one',
        (tester) async {
      final auth = AuthController(
        repository: _repo(_FakeTransport((_) async => (200, {'ok': true}))),
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
          child: const PublicGuestShell(),
        ),
      );
      await tester.pumpAndSettle();

      // The public shell is present; the authenticated shell is not.
      expect(find.byType(PublicGuestShell), findsOneWidget);
      expect(find.byType(HomeShell), findsNothing);
      // Public navigation only: the guest tab bar never names the private areas.
      expect(find.text('الرئيسية'), findsWidgets);
      expect(find.text('الخدمات'), findsOneWidget);
      expect(find.text('الأسعار'), findsOneWidget);
      expect(find.text('القضايا'), findsNothing);
      expect(find.text('المستندات'), findsNothing);
    });

    testWidgets('the guest shell shows public content, not a dashboard',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          child: PublicGuestShell(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      // The public homepage hero is visible inside the shell.
      expect(find.textContaining('مواثيق'), findsWidgets);
      expect(find.text('القضايا'), findsNothing);
    });

    testWidgets('a guest tapping a service raises the sign-in prompt',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
      final auth = AuthController(
        repository: _repo(transport),
        tokens: _MemoryTokenStore(),
      );
      final guest = GuestSession();

      await tester.pumpWidget(
        _wrap(
          auth: auth,
          guest: guest,
          child: PublicGuestShell(repository: _repo(transport)),
        ),
      );
      await tester.pumpAndSettle();

      // Scroll the homepage to the service list and tap a service card. The label
      // is unique to the services list, so the tap cannot land on a decorative tile.
      final tile = find.text('البحث في الأنظمة واللوائح');
      await _scrollTo(tester, tile);
      await tester.tap(tile);
      await tester.pumpAndSettle();

      // The prompt names the requested service and never opens a dashboard.
      expect(find.textContaining('الخدمة المطلوبة'), findsOneWidget);
      expect(find.textContaining('البحث في الأنظمة واللوائح'), findsWidgets);
      expect(find.byType(HomeShell), findsNothing);
    });

    testWidgets('a guest choosing a plan is routed to sign-in, not a checkout',
        (tester) async {
      final transport = _FakeTransport((_) async => (200, _plansJson));
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
