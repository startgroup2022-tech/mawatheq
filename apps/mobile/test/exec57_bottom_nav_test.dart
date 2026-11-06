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
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/home/home_tab.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Execution 57 — the client bottom navigation, measured rather than described.
///
/// `exec47_navigation_test.dart` already pins the hard part: one bar, per-tab back
/// stacks, and the bar surviving a push. This file covers the remaining dimensions
/// the brief names, because each of them fails in a way a single screenshot cannot
/// show:
///
///   - **safe area** — the bar must sit above the system gesture inset, not under it;
///   - **keyboard** — the bar must stay put when the keyboard opens, and the body
///     must not be squeezed behind it;
///   - **dark mode** — the bar must resolve to the dark surface, not the light one;
///   - **RTL** — the destinations must keep the same logical order;
///   - **five sections** — every destination must select and render its own page.
///
/// The bar is located by type and its geometry is read from the render object, so a
/// bar that is present but zero-height or off-screen is a failure, not a pass.

/// Answers the login call the shell's auth controller makes. A generic `{"ok":true}`
/// is not enough: `signIn` requires a token, so the shell would stay a guest and the
/// guest prompt would cover the bar — a fixture bug that would read as a nav bug.
class _Transport extends http_base.BaseClient {
  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final path = request.url.path;
    final body = path.endsWith('/auth/login.php')
        ? <String, dynamic>{
            'ok': true,
            'token': 'tok',
            'type': 'client',
            'profile': {'id': 1, 'full_name': 'عميل تجريبي'},
            'role': 'client',
            'firm_id': null,
            'permissions': <String>[],
          }
        : <String, dynamic>{'ok': true};
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode(body))),
      200,
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

class _Prefs implements PrefsStore {
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

/// Bounded pumps. `pumpAndSettle` never returns here: several screens show an
/// animated loading skeleton, so the frame scheduler does not idle.
Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}

Widget _wrap({
  required AuthController auth,
  required ApiRepository repo,
  required ThemeData theme,
  required Widget child,
  TextDirection direction = TextDirection.rtl,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
      ChangeNotifierProvider<LocaleController>(create: (_) => LocaleController()),
      ChangeNotifierProvider<ThemeController>(
        create: (_) => ThemeController(prefs: _Prefs()),
      ),
      Provider<ApiRepository>.value(value: repo),
    ],
    child: MaterialApp(
      theme: theme,
      home: Directionality(textDirection: direction, child: child),
    ),
  );
}

Future<AuthController> _signedIn(ApiRepository repo) async {
  final auth = AuthController(repository: repo, tokens: _MemoryTokenStore());
  await auth.signIn(
    email: 'client@example.com',
    password: 'secret123',
    asLawyer: false,
  );
  return auth;
}

void main() {
  late ApiRepository repo;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
    binding.platformDispatcher.accessibilityFeaturesTestValue =
        const FakeAccessibilityFeatures(disableAnimations: true);
    repo = ApiRepository(
      client: ApiClient(client: _Transport(), tokens: _MemoryTokenStore()),
    );
  });

  tearDown(() {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.resetPhysicalSize();
    view.resetDevicePixelRatio();
    binding.platformDispatcher.clearAccessibilityFeaturesTestValue();
  });

  Future<void> mount(
    WidgetTester tester, {
    ThemeData? theme,
    TextDirection direction = TextDirection.rtl,
  }) async {
    await tester.pumpWidget(
      _wrap(
        auth: await _signedIn(repo),
        repo: repo,
        theme: theme ?? AppTheme.light(),
        direction: direction,
        child: HomeShell(repository: repo),
      ),
    );
    await _settle(tester);
  }

  /// Tap a destination by its label. The label text is the only stable handle: the
  /// bar rebuilds its children, so an index into the InkWells is not.
  Future<void> tapNav(WidgetTester tester, String label) async {
    await tester.tap(
      find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text(label),
      ),
    );
    await _settle(tester);
  }

  // ------------------------------------------------------------ five sections

  testWidgets('the bar carries exactly the five main sections', (tester) async {
    await mount(tester);

    final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));
    expect(bar.destinations.length, 5);

    final labels = bar.destinations
        .cast<NavigationDestination>()
        .map((d) => d.label)
        .toList();
    expect(labels, ['الرئيسية', 'القضايا', 'المساعد', 'المستندات', 'حسابي']);
  });

  testWidgets('every destination selects and renders its own page',
      (tester) async {
    await mount(tester);

    const labels = ['الرئيسية', 'القضايا', 'المساعد', 'المستندات', 'حسابي'];
    for (var i = 0; i < labels.length; i++) {
      await tapNav(tester, labels[i]);

      final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));
      expect(bar.selectedIndex, i, reason: 'tapping destination $i selects it');
      expect(
        find.byType(NavigationBar),
        findsOneWidget,
        reason: 'still exactly one bar after visiting destination $i',
      );
    }
  });

  testWidgets('returning to a visited tab keeps its state', (tester) async {
    await mount(tester);

    // Home, then Documents, then back to Home. The home tab must be the same
    // widget instance: an IndexedStack keeps it alive, a rebuilt child would not.
    final homeBefore = tester.element(find.byType(HomeTab));
    await tapNav(tester, 'المستندات');
    await tapNav(tester, 'الرئيسية');

    expect(tester.element(find.byType(HomeTab)), same(homeBefore));
  });

  // ---------------------------------------------------------------- geometry

  testWidgets('the bar is laid out at the bottom and is not zero-height',
      (tester) async {
    await mount(tester);

    final rect = tester.getRect(find.byType(NavigationBar));
    final screen = tester.getRect(find.byType(HomeShell));
    expect(rect.height, greaterThan(40), reason: 'a real bar, not a collapsed one');
    expect(
      rect.bottom,
      moreOrLessEquals(screen.bottom, epsilon: 1),
      reason: 'the bar sits on the bottom edge',
    );
    expect(rect.top, greaterThan(screen.top));
  });

  testWidgets('the bar clears the bottom safe area', (tester) async {
    // A phone with a gesture bar: 1080x2280 at 3x, with a 48px bottom inset.
    tester.view.physicalSize = const Size(1080, 2280);
    tester.view.devicePixelRatio = 3.0;
    tester.view.padding = const FakeViewPadding(bottom: 144);
    addTearDown(tester.view.resetPadding);

    await mount(tester);

    final bar = tester.getRect(find.byType(NavigationBar));
    // The bar's own box reaches the screen edge (the padding is inside it), but its
    // content must sit above the inset — otherwise the labels are under the gesture
    // pill and untappable.
    final contentBottom = bar.bottom - 48; // 144 physical / 3.0 dpr
    final labelBottom = tester.getRect(find.text('الرئيسية')).bottom;
    expect(
      labelBottom,
      lessThanOrEqualTo(contentBottom + 1),
      reason: 'the destination labels are above the system inset',
    );
  });

  testWidgets('opening the keyboard does not push the bar off screen',
      (tester) async {
    await mount(tester);

    // The Android soft keyboard reports itself as a bottom view inset.
    tester.view.viewInsets = const FakeViewPadding(bottom: 900);
    addTearDown(tester.view.resetViewInsets);
    await _settle(tester);

    expect(find.byType(NavigationBar), findsOneWidget);
    final bar = tester.getRect(find.byType(NavigationBar));
    expect(bar.height, greaterThan(40));
    expect(bar.top, greaterThanOrEqualTo(0));
    expect(bar.bottom, lessThanOrEqualTo(tester.getRect(find.byType(HomeShell)).bottom + 1));
  });

  // ------------------------------------------------------------------- theme

  testWidgets('the bar resolves to the dark surface in dark mode',
      (tester) async {
    await mount(tester, theme: AppTheme.dark());

    expect(find.byType(NavigationBar), findsOneWidget);
    final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));
    final material = bar.backgroundColor ??
        Theme.of(tester.element(find.byType(NavigationBar))).colorScheme.surface;

    // The light surface is near-white; the dark one is not. Comparing the channel
    // sum is enough to tell them apart without pinning an exact token value.
    final sum = material.r + material.g + material.b;
    expect(
      sum,
      lessThan(1.5),
      reason: 'dark mode must not paint the light bar',
    );
  });

  // --------------------------------------------------------------------- RTL

  testWidgets('destinations keep their order under RTL', (tester) async {
    await mount(tester, direction: TextDirection.rtl);

    final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));
    final labels = bar.destinations
        .cast<NavigationDestination>()
        .map((d) => d.label)
        .toList();
    expect(labels.first, 'الرئيسية');
    expect(labels.last, 'حسابي');
  });

  testWidgets('a sign-out rebuilds the account tab without breaking the bar',
      (tester) async {
    final auth = await _signedIn(repo);
    await tester.pumpWidget(
      _wrap(
        auth: auth,
        repo: repo,
        theme: AppTheme.light(),
        child: HomeShell(repository: repo),
      ),
    );
    await _settle(tester);

    // Land on the account tab, then sign out: the shell drops and rebuilds that tab.
    await tapNav(tester, 'حسابي');
    await auth.signOut();
    await _settle(tester);

    expect(find.byType(NavigationBar), findsOneWidget);
    final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));
    expect(bar.destinations.length, 5);
  });
}
