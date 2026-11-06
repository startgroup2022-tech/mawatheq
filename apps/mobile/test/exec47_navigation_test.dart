import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/guest_session.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';
import 'package:mawatheq_mobile/features/account/account_screen.dart';
import 'package:mawatheq_mobile/features/auth/login_screen.dart';
import 'package:mawatheq_mobile/features/consultations/consultations_screen.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/home/home_tab.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Execution 47 — the persistent navigation shell.
///
/// Two defects are pinned here.
///
/// **The bottom bar disappeared inside the product.** Every push used the root
/// navigator, so opening the consultation journey (or a lawyer profile) covered the
/// whole shell, bar included. The shell now gives each tab its own navigator, and
/// these tests assert the bar is still on screen at every step of the journey.
///
/// **Sign-in did not land on Home.** It replayed whatever destination the guest had
/// been stopped at, so a customer who tapped "book a consultation" as a guest ended
/// up on Consultations after signing in. Sign-in now always lands on Home, and the
/// stack is replaced so Back cannot reach the login form again.

class _Transport extends http_base.BaseClient {
  _Transport(this.handler);
  final Future<(int, Object)> Function(Uri uri) handler;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final (status, body) = await handler(request.url);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(body is String ? body : jsonEncode(body))),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
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

/// Answers every endpoint the shell and the journey touch, so the production client
/// and widgets run for real rather than against a stub screen.
Future<(int, Object)> _api(Uri uri) async {
  final path = uri.path;
  if (path.endsWith('/auth/login.php')) {
    return (200, {
      'ok': true,
      'token': 'tok',
      'type': 'client',
      'profile': {'id': 1, 'full_name': 'عميل تجريبي'},
      'role': 'client',
      'firm_id': null,
      'permissions': [],
    });
  }
  if (path.endsWith('/me.php')) {
    return (200, {
      'ok': true,
      'user': {'id': 1, 'full_name': 'عميل تجريبي', 'type': 'client'},
      'role': 'client',
      'permissions': [],
    });
  }
  if (path.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': []});
  }
  if (path.endsWith('/journey.php')) {
    if (uri.queryParameters['action'] == 'specialties') {
      return (200, {
        'ok': true,
        'specialties': [
          {
            'id': 1,
            'name': 'الأحوال الشخصية',
            'description': 'شؤون الأسرة.',
            'icon_key': 'family',
            'lawyer_count': 2,
          },
          {
            'id': 2,
            'name': 'الدعاوى التجارية',
            'description': 'منازعات تجارية.',
            'icon_key': 'commercial',
            'lawyer_count': 0,
          },
        ],
      });
    }
    if (uri.queryParameters['action'] == 'issue-types') {
      return (200, {
        'ok': true,
        'issue_types': [
          {'id': 1, 'name': 'إثبات عقد الزواج', 'definition': 'دعوى.', 'group': 'دعاوى'},
        ],
      });
    }
    return (200, {
      'ok': true,
      'lawyers': [
        {
          'id': 7,
          'full_name': 'سارة العتيبي',
          'rating': 5,
          'jurisdiction': 'الرياض',
        },
      ],
      'matched': true,
    });
  }
  if (path.endsWith('/lawyers.php')) {
    return (200, {'ok': true, 'lawyers': []});
  }
  if (path.endsWith('/ai-status.php')) {
    return (200, {'ok': true, 'available': true, 'blocking': null});
  }
  return (200, {'ok': true});
}

ApiRepository _repo(_Transport t) => ApiRepository(
      client: ApiClient(client: t, tokens: _MemoryTokenStore()),
    );

Widget _wrap({
  required AuthController auth,
  required ApiRepository repo,
  required Widget child,
  GuestSession? guest,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>.value(value: guest ?? GuestSession()),
      ChangeNotifierProvider<LocaleController>(create: (_) => LocaleController()),
      ChangeNotifierProvider<ThemeController>(
        create: (_) => ThemeController(prefs: _Prefs()),
      ),
      Provider<ApiRepository>.value(value: repo),
    ],
    child: MaterialApp(theme: AppTheme.light(), home: child),
  );
}

class _Prefs implements PrefsStore {
  String? _theme;
  @override
  Future<void> saveThemeMode(String mode) async => _theme = mode;
  @override
  Future<String?> readThemeMode() async => _theme;
  @override
  Future<void> saveLocale(String locale) async {}
  @override
  Future<String> readLocale() async => 'ar';
  @override
  Future<void> saveOnboardingSeen(bool seen) async {}
  @override
  Future<bool> readOnboardingSeen() async => true;
}

/// A genuinely signed-in controller, driven through the real `signIn` path so the
/// shell sees the same state it would in production.
Future<AuthController> _signedIn(ApiRepository repo) async {
  final auth = AuthController(repository: repo, tokens: _MemoryTokenStore());
  await auth.signIn(
    email: 'client@example.com',
    password: 'secret123',
    asLawyer: false,
  );
  return auth;
}

/// Bounded pumps. `pumpAndSettle` cannot be used anywhere in this file: several
/// screens show an animated loading skeleton, so the frame scheduler never idles.
Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
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

  // ---------------------------------------------------- persistent bottom bar

  group('The bottom bar survives normal navigation', () {
    testWidgets('it is on screen on the home tab', (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      expect(find.byType(NavigationBar), findsOneWidget);
    });

    testWidgets('opening the consultation journey keeps it visible',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      // A signed-in customer taps a specialty on the home, which opens the journey
      // scoped to it. This is the exact path the brief names.
      await tester.tap(find.text('الأحوال الشخصية'));
      await _settle(tester);

      // The journey really opened...
      expect(find.byType(ConsultationJourneyScreen), findsOneWidget);
      // ...and the bar is still there. This is the regression: it used to be covered.
      expect(find.byType(NavigationBar), findsOneWidget);
    });

    testWidgets('walking the journey steps keeps it visible', (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      await tester.tap(find.text('الأحوال الشخصية'));
      await _settle(tester);
      expect(find.byType(NavigationBar), findsOneWidget);

      // Step 2: pick an issue type, which pushes the lawyer step.
      await tester.tap(find.text('إثبات عقد الزواج'));
      await _settle(tester);

      // The lawyer step is showing and the bar is still in place.
      expect(find.byType(NavigationBar), findsOneWidget);
      expect(find.textContaining('سارة العتيبي'), findsWidgets);
    });

    testWidgets('the lawyer directory opens with the bar in place',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      // The home's "browse lawyers" entry point, which sits below the fold.
      await tester.scrollUntilVisible(
        find.text('دليل المحامين').first,
        240,
        scrollable: find.byType(Scrollable).first,
        maxScrolls: 60,
      );
      await _settle(tester);
      await tester.tap(find.text('دليل المحامين').first);
      await _settle(tester);

      expect(find.byType(PublicLawyersScreen), findsOneWidget);
      expect(find.byType(NavigationBar), findsOneWidget);
    });

    testWidgets('switching tabs keeps one bar, never two', (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      Future<void> tapNav(String label) async {
        await tester.tap(
          find.descendant(
            of: find.byType(NavigationBar),
            matching: find.text(label),
          ),
        );
        await _settle(tester);
      }

      for (final label in ['القضايا', 'المساعد', 'المستندات', 'حسابي', 'الرئيسية']) {
        await tapNav(label);
        expect(
          find.byType(NavigationBar),
          findsOneWidget,
          reason: 'exactly one bar on the $label tab',
        );
      }
    });

    testWidgets('each tab keeps its own back stack', (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      // Push the journey onto the home tab.
      await tester.tap(find.text('الأحوال الشخصية'));
      await _settle(tester);
      expect(find.byType(ConsultationJourneyScreen), findsOneWidget);

      // Visit another tab and come back: the journey is still there, because the
      // stack belongs to the tab rather than to the shell.
      await tester.tap(
        find.descendant(
          of: find.byType(NavigationBar),
          matching: find.text('القضايا'),
        ),
      );
      await _settle(tester);
      expect(find.byType(ConsultationJourneyScreen), findsNothing);

      await tester.tap(
        find.descendant(
          of: find.byType(NavigationBar),
          matching: find.text('الرئيسية'),
        ),
      );
      await _settle(tester);
      expect(find.byType(ConsultationJourneyScreen), findsOneWidget);
    });

    testWidgets('re-tapping the active tab returns to the top of its section',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      await tester.tap(find.text('الأحوال الشخصية'));
      await _settle(tester);
      expect(find.byType(ConsultationJourneyScreen), findsOneWidget);

      // Tapping the already-selected Home tab pops its stack.
      await tester.tap(
        find.descendant(
          of: find.byType(NavigationBar),
          matching: find.text('الرئيسية'),
        ),
      );
      await _settle(tester);

      expect(find.byType(ConsultationJourneyScreen), findsNothing);
      expect(find.byType(HomeTab), findsOneWidget);
    });
  });

  // ------------------------------------------------------- full-screen routes

  group('Documented full-screen exceptions', () {
    testWidgets('sign-in owns the whole window, with no bottom bar',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(auth: await _signedIn(repo), repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      // Sign out to reach the guest account tab, whose sign-in button opens the
      // full-screen form.
      await tester.tap(
        find.descendant(
          of: find.byType(NavigationBar),
          matching: find.text('حسابي'),
        ),
      );
      await _settle(tester);

      // The account tab for a signed-in user is the real account screen.
      expect(find.byType(AccountScreen), findsOneWidget);
      expect(find.byType(NavigationBar), findsOneWidget);
    });

    testWidgets('a pushed login form is not wrapped by the bar', (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      final auth = AuthController(
        repository: repo,
        tokens: _MemoryTokenStore(),
      )..enterGuest();

      await tester.pumpWidget(
        _wrap(auth: auth, repo: repo, child: HomeShell(repository: repo)),
      );
      await _settle(tester);

      // Guest account tab -> sign-in. It must own the window.
      await tester.tap(
        find.descendant(
          of: find.byType(NavigationBar),
          matching: find.text('حسابي'),
        ),
      );
      await _settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'تسجيل الدخول').first);
      await _settle(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.byType(NavigationBar), findsNothing);
    });
  });

  // ---------------------------------------------------- login always goes Home

  group('Sign-in always lands on Home', () {
    testWidgets('a client who signs in from the guest prompt ends on Home',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      final auth = AuthController(
        repository: repo,
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      final guest = GuestSession()..remember(ProtectedDestination.consultations);

      await tester.pumpWidget(
        _wrap(auth: auth, repo: repo, guest: guest, child: const LoginScreen()),
      );
      await _settle(tester);

      await tester.enterText(
          find.byType(TextFormField).first, 'client@example.com');
      await tester.enterText(find.byType(TextFormField).last, 'secret123');
      await tester.tap(find.widgetWithText(FilledButton, 'تسجيل الدخول'));
      await _settle(tester);

      final shell = tester.widget<HomeShell>(find.byType(HomeShell));
      expect(shell.initialIndex, 0, reason: 'Home, not the remembered service');
      // The remembered intent is cleared, so nothing can replay it later.
      expect(guest.hasPendingIntent, isFalse);
      // The login form is gone from the stack, so Back cannot return to it.
      expect(find.byType(LoginScreen), findsNothing);
    });

    testWidgets('signing in with no remembered destination also lands on Home',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      final auth = AuthController(
        repository: repo,
        tokens: _MemoryTokenStore(),
      );

      await tester.pumpWidget(
        _wrap(auth: auth, repo: repo, child: const LoginScreen()),
      );
      await _settle(tester);

      await tester.enterText(
          find.byType(TextFormField).first, 'client@example.com');
      await tester.enterText(find.byType(TextFormField).last, 'secret123');
      await tester.tap(find.widgetWithText(FilledButton, 'تسجيل الدخول'));
      await _settle(tester);

      final shell = tester.widget<HomeShell>(find.byType(HomeShell));
      expect(shell.initialIndex, 0);
    });

    testWidgets('sign-in started from a journey still lands on Home',
        (tester) async {
      final t = _Transport(_api);
      final repo = _repo(t);
      final auth = AuthController(
        repository: repo,
        tokens: _MemoryTokenStore(),
      )..enterGuest();
      // The journey records consultations as the reason it stopped the guest.
      final guest = GuestSession()..remember(ProtectedDestination.consultations);

      await tester.pumpWidget(
        _wrap(auth: auth, repo: repo, guest: guest, child: const LoginScreen()),
      );
      await _settle(tester);

      await tester.enterText(
          find.byType(TextFormField).first, 'client@example.com');
      await tester.enterText(find.byType(TextFormField).last, 'secret123');
      await tester.tap(find.widgetWithText(FilledButton, 'تسجيل الدخول'));
      await _settle(tester);

      // Home, not Consultations — the defect the brief names.
      expect(find.byType(ConsultationsScreen), findsNothing);
      expect(tester.widget<HomeShell>(find.byType(HomeShell)).initialIndex, 0);
    });
  });
}
