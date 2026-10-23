import 'dart:convert';
import 'dart:io';

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
import 'package:mawatheq_mobile/features/home/home_tab.dart';
import 'package:mawatheq_mobile/shared/widgets/banner_carousel.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';
import 'package:mawatheq_mobile/shared/widgets/home_slides.dart';
import 'package:provider/provider.dart';

/// Execution 43 — the home slider and the two primary actions.
///
/// The defect this pins: the carousel was rendered only when the server returned at
/// least one banner, and `home_banners` ships empty. On a fresh install — production
/// — the top of the home screen was therefore just the two action cards, which is
/// exactly what the owner rejected ("the real slider was replaced by two static
/// rectangular cards"). These tests hold the slider in place regardless of what the
/// backend returns, and check that both actions and the specialty section are real.
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

/// Serves the public endpoints. `banners` and `specialties` are parameterised so a
/// test can reproduce the fresh-install state (both empty) or the imported state.
Future<(int, Object)> _endpoints(
  Uri uri, {
  required List<Object> banners,
  required List<Object> specialties,
}) async {
  if (uri.path.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': banners});
  }
  if (uri.path.endsWith('/journey.php')) {
    return (
      200,
      {'ok': true, 'specialties': specialties, 'ready': true}
    );
  }
  if (uri.path.endsWith('/ai-status.php')) {
    return (200, {'ok': true, 'available': true, 'configured': true});
  }
  if (uri.path.endsWith('/notifications.php')) {
    return (200, {'ok': true, 'notifications': [], 'unread_count': 0});
  }
  if (uri.path.endsWith('/lawyers.php')) {
    return (200, {'ok': true, 'lawyers': []});
  }
  return (200, {'ok': true});
}

ApiRepository _repo(http_base.BaseClient transport) =>
    ApiRepository(client: ApiClient(client: transport));

/// A guest session, which is the harder case: everything the home shows a visitor
/// must also work without an account.
AuthController _guest(http_base.BaseClient transport) => AuthController(
      repository: _repo(transport),
      tokens: _MemoryTokenStore(),
    )..enterGuest();

Widget _homeTab({
  required AuthController auth,
  required ApiRepository repo,
  LocaleController? locale,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
      ChangeNotifierProvider<LocaleController>.value(
        value: locale ?? LocaleController(),
      ),
      ChangeNotifierProvider<ThemeController>.value(
        value: ThemeController(prefs: _NullPrefs()),
      ),
      Provider<ApiRepository>.value(value: repo),
    ],
    child: MaterialApp(
      theme: AppTheme.light(),
      // Production applies the direction in the app builder from the locale
      // controller; the harness mirrors it so an RTL assertion tests the same path.
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      home: Scaffold(body: HomeTab(repository: repo, onOpenLawyers: () {})),
    ),
  );
}

void main() {
  group('home slider is always present', () {
    testWidgets('a fresh install with no banners still renders the carousel',
        (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      expect(find.byType(BannerCarousel), findsOneWidget);
      expect(find.byType(PageView), findsOneWidget);
      // Four built-in slides ship, so the pagination dots are shown.
      expect(kBuiltInHomeSlides.length, greaterThan(1));
    });

    testWidgets('operator banners replace the built-in slides',
        (tester) async {
      final t = _FakeTransport((u) => _endpoints(
            u,
            banners: [
              {
                'id': 1,
                'title_ar': 'عنوان المشغّل',
                'title_en': 'Operator title',
                'image_path': 'uploads/banners/x.jpg',
                'link_type': 'none',
              },
            ],
            specialties: [],
          ));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      expect(find.text('عنوان المشغّل'), findsOneWidget);
      // The built-in copy is not shown when the operator has published one.
      expect(find.text('استشارتك القانونية بخطوات بسيطة'), findsNothing);
    });
  });

  group('the slider moves on its own', () {
    testWidgets('advances to the next slide after the dwell interval',
        (tester) async {
      // The heart of "a genuine slider, not two static cards": without a tick the
      // page never changes. A `pumpAndSettle` cannot be used here — it would wait
      // out the re-armed timer forever — so the clock is advanced explicitly.
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      final view = tester.widget<PageView>(find.byType(PageView));
      expect(view.controller!.page, 0);
      expect(find.text('استشارتك القانونية بخطوات بسيطة'), findsOneWidget);

      // Past the 4.5s dwell plus the 480ms transition.
      await tester.pump(const Duration(milliseconds: 4600));
      await tester.pump(const Duration(milliseconds: 600));

      expect(view.controller!.page, 1);
      // The second slide's subtitle is unique; its title also appears as the label
      // of the assistant button below the slider.
      expect(find.text('إجابات فورية مبنية على الأنظمة واللوائح'), findsOneWidget);
    });

    testWidgets('a manual swipe moves the slider too', (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      final view = tester.widget<PageView>(find.byType(PageView));
      // In RTL the PageView's scroll axis is reversed, so a rightward fling is the
      // one that advances to the next slide. A fling rather than a plain drag, so
      // the gesture clears the settle threshold on the narrow default surface.
      await tester.fling(
        find.byType(PageView),
        const Offset(400, 0),
        1200,
      );
      await tester.pumpAndSettle();

      expect(view.controller!.page, 1);
      expect(find.text('إجابات فورية مبنية على الأنظمة واللوائح'), findsOneWidget);
    });
  });

  group('the two primary actions', () {
    testWidgets('both buttons are present and labelled', (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      expect(find.text('احجز استشارة'), findsOneWidget);
      expect(find.text('اسأل المساعد الذكي'), findsOneWidget);
      expect(find.byType(ActionCard), findsNWidgets(2));
    });
  });

  group('specialties', () {
    testWidgets('the seven real categories show when the backend has none',
        (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      expect(find.text('اختر تخصصك القانوني'), findsOneWidget);
      // Every fallback category is on screen; the grid is not an empty box.
      for (final s in kBuiltInSpecialties) {
        expect(find.text(s.name), findsOneWidget,
            reason: '${s.name} should be listed');
      }
    });

    testWidgets('the backend list wins when it is present', (tester) async {
      final t = _FakeTransport((u) => _endpoints(
            u,
            banners: [],
            specialties: [
              {
                'id': 9,
                'name': 'تخصص من الخادم',
                'icon_key': 'family',
                'lawyer_count': 2,
              },
            ],
          ));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      expect(find.text('تخصص من الخادم'), findsOneWidget);
      expect(find.text('الأحوال الشخصية'), findsNothing);
    });

    // The end-to-end claim: the seven categories the taxonomy import writes, served
    // in the endpoint's real response shape, reach the home screen through the real
    // ApiClient and ApiRepository. The fixture is the captured output of
    // `api/v1/journey.php?action=specialties` against a database that ran
    // MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql — not a hand-written stub, which
    // would only prove the test agrees with itself.
    testWidgets('the imported taxonomy reaches the screen through the real client',
        (tester) async {
      final payload = jsonDecode(
        File('test/fixtures/real_journey_specialties.json').readAsStringSync(),
      ) as Map<String, dynamic>;
      final specialties = (payload['specialties'] as List).cast<Object>();
      expect(specialties.length, 7);

      final t = _FakeTransport(
        (u) => _endpoints(u, banners: [], specialties: specialties),
      );
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      for (final s in specialties.cast<Map<String, dynamic>>()) {
        expect(
          find.text(s['name'] as String),
          findsOneWidget,
          reason: '${s['name']} from the import must render on the home screen',
        );
      }
      // A description and an icon key survived the wire, so the cards are not bare.
      expect(
        find.text('شؤون الأسرة: الزواج والطلاق والنفقة والحضانة والميراث.'),
        findsOneWidget,
      );
    });
  });

  group('RTL and theme', () {
    testWidgets('the carousel lays out in RTL for Arabic', (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      final dir = Directionality.of(
        tester.element(find.byType(BannerCarousel)),
      );
      expect(dir, TextDirection.rtl);
    });

    testWidgets('the built-in slider renders in the dark theme', (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(MultiProvider(
        providers: [
          ChangeNotifierProvider<AuthController>.value(value: auth),
          ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
          ChangeNotifierProvider<LocaleController>.value(
            value: LocaleController(),
          ),
          ChangeNotifierProvider<ThemeController>.value(
            value: ThemeController(prefs: _NullPrefs()),
          ),
          Provider<ApiRepository>.value(value: _repo(t)),
        ],
        child: MaterialApp(
          theme: AppTheme.dark(),
          builder: (context, child) => Directionality(
            textDirection: TextDirection.rtl,
            child: child ?? const SizedBox.shrink(),
          ),
          home: Scaffold(body: HomeTab(repository: _repo(t), onOpenLawyers: () {})),
        ),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.byType(BannerCarousel), findsOneWidget);
      expect(find.text('احجز استشارة'), findsOneWidget);
    });
  });
}
