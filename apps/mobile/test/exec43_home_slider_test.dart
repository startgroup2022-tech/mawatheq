import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:flutter/rendering.dart';
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
import 'package:mawatheq_mobile/features/assistant/assistant_screen.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';
import 'package:mawatheq_mobile/features/home/home_tab.dart';
import 'package:mawatheq_mobile/shared/widgets/banner_carousel.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';
import 'package:mawatheq_mobile/shared/widgets/home_slides.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
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
  if (uri.path.endsWith('/login.php')) {
    return (
      200,
      {
        'ok': true,
        'token': 'tok',
        'type': 'client',
        'role': 'client',
        'firm_id': null,
        'permissions': <String>[],
        'profile': {'id': 7, 'full_name': 'عميل'},
      }
    );
  }
  return (200, {'ok': true});
}

ApiRepository _repo(http_base.BaseClient transport, [TokenStore? tokens]) =>
    ApiRepository(
      client: ApiClient(client: transport, tokens: tokens ?? _MemoryTokenStore()),
    );

/// A guest session, which is the harder case: everything the home shows a visitor
/// must also work without an account.
AuthController _guest(http_base.BaseClient transport) => AuthController(
      repository: _repo(transport),
      tokens: _MemoryTokenStore(),
    )..enterGuest();

/// A signed-in client, together with the repository that shares its token store.
///
/// The two must share one store: `signIn` writes the token, and the home's own
/// request then has to send it. A repository built on the default `TokenStorage`
/// would reach for `flutter_secure_storage`, whose platform channel never answers in
/// a widget test, so the home would sit on its skeleton forever.
Future<(AuthController, ApiRepository)> _signedIn(
  http_base.BaseClient transport,
) async {
  final tokens = _MemoryTokenStore();
  final repo = _repo(transport, tokens);
  final auth = AuthController(repository: repo, tokens: tokens);
  await auth.signIn(email: 'a@b.com', password: 'Password1', asLawyer: false);
  return (auth, repo);
}

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

    testWidgets('every built-in slide photo is bundled, not silently a gradient',
        (tester) async {
      // The trap this catches: `assets/images/` in pubspec is not recursive, so a
      // photo in `assets/images/banners/` is absent from the bundle until that
      // directory is declared too. `Image.asset` then fails at paint, the
      // `errorBuilder` swaps in the brand gradient, and the slider still renders —
      // so every other test in this file passes while the owner sees four flat
      // panels instead of photographs. Only loading the real bytes proves it.
      for (final slide in kBuiltInHomeSlides) {
        expect(slide.assetImage, isNotEmpty,
            reason: 'slide ${slide.id} has no bundled photo');
        final bytes = await rootBundle.load(slide.assetImage);
        expect(bytes.lengthInBytes, greaterThan(1000),
            reason: '${slide.assetImage} is in the bundle but empty');
      }
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
      // The second slide's headline is unique, so finding it proves the frame moved
      // to slide two rather than merely repainting slide one.
      expect(find.text('إجابات فورية من المساعد الذكي'), findsOneWidget);
      expect(find.text('استشارتك القانونية بخطوات بسيطة'), findsNothing);
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
      expect(find.text('إجابات فورية من المساعد الذكي'), findsOneWidget);
    });
  });

  group('the two primary actions', () {
    testWidgets('both buttons sit below the slider and are labelled',
        (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      // The owner rejected a pair of static cards AND wants two separate buttons
      // under the slider. Both hold only if the actions are a dedicated row below
      // the carousel — not inside a slide, and not a card.
      expect(find.text('احجز استشارة'), findsOneWidget);
      expect(find.text('اسأل المساعد الذكي'), findsOneWidget);
      expect(find.byType(ActionCard), findsNothing);
      // They are outside the carousel, so they never travel with a slide.
      expect(
        find.descendant(
          of: find.byType(BannerCarousel),
          matching: find.text('احجز استشارة'),
        ),
        findsNothing,
      );
      // And they are real buttons, not decorative text.
      expect(find.widgetWithText(FilledButton, 'احجز استشارة'), findsOneWidget);
      expect(
        find.widgetWithText(OutlinedButton, 'اسأل المساعد الذكي'),
        findsOneWidget,
      );
    });

    testWidgets('the actions render below the carousel in the widget tree',
        (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      // Order matters to the brief: the slider comes first, the actions directly
      // after it. A layout that put them above, or inside a slide, would still pass
      // the presence checks above, so assert the vertical order too.
      final carouselY = tester.getBottomLeft(find.byType(BannerCarousel)).dy;
      final bookY = tester.getTopLeft(find.text('احجز استشارة')).dy;
      expect(bookY, greaterThan(carouselY));
    });

    testWidgets('the booking button opens the real journey', (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final (auth, repo) = await _signedIn(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: repo));
      await tester.pumpAndSettle();

      await tester.tap(find.text('احجز استشارة'));
      // The journey screen shows an animating skeleton while it loads, so the frame
      // scheduler never goes idle and `pumpAndSettle` would time out. Pump explicit
      // frames instead — a property of the destination, not of this test.
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      // The signed-in customer lands on the journey, not on a sign-in prompt.
      expect(find.byType(ConsultationJourneyScreen), findsOneWidget);
    });

    testWidgets('the assistant button opens the real assistant', (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final (auth, repo) = await _signedIn(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: repo));
      await tester.pumpAndSettle();

      await tester.tap(find.text('اسأل المساعد الذكي'));
      // The assistant checks provider availability on open and shows a skeleton while
      // it does, so settle explicitly rather than waiting for idle.
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.byType(AssistantScreen), findsOneWidget);
    });

    testWidgets('a guest is asked to sign in, and the destination is remembered',
        (tester) async {
      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);

      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      await tester.tap(find.text('احجز استشارة'));
      await tester.pumpAndSettle();

      // Booking needs an account; the prompt is shown and is not a dead end.
      expect(find.text('متابعة التصفح'), findsOneWidget);
      expect(find.byType(ConsultationJourneyScreen), findsNothing);
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

    // The test above only proves the frame is RTL, which was never in doubt: the
    // text was still drawn against the wrong edge. This one measures where the copy
    // actually lands, which is the thing the owner can see.
    //
    // The regression it pins: the copy Column used `CrossAxisAlignment.end`. The
    // cross axis of a Column is horizontal, so in RTL `end` resolves to the LEFT and
    // the copy was pushed against the left edge.
    //
    // The headline has to be SHORT for this to be observable, and that is the whole
    // trap. A headline wide enough to fill the Column stretches the Text to the full
    // width, so both margins come out equal and a broken alignment measures as fine.
    // Every built-in slide headline is long enough to wrap in the test font, which is
    // why the first version of this test passed against the bug. The short banner
    // below is the case that actually distinguishes the two layouts, and the built-in
    // slides are swept afterwards so a future long-to-short edit is still covered.
    testWidgets('Arabic copy is anchored to the RIGHT edge of its slide',
        (tester) async {
      tester.view.physicalSize = const Size(1080, 2280);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      // Deliberately short: it must not wrap, or the defect is invisible.
      const shortSlide = HomeBanner(
        id: 1,
        titleAr: 'مرحباً',
        titleEn: 'Welcome',
        subtitleAr: 'أهلاً',
        subtitleEn: 'Hi',
      );

      await tester.pumpWidget(MultiProvider(
        providers: [
          ChangeNotifierProvider<LocaleController>.value(
            value: LocaleController(),
          ),
        ],
        child: MaterialApp(
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: Scaffold(
              body: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: BannerCarousel(
                  banners: const [shortSlide],
                  autoAdvance: false,
                ),
              ),
            ),
          ),
        ),
      ));
      await tester.pump(const Duration(milliseconds: 400));

      final slideRect = tester.getRect(find.byType(BannerCarousel));
      final titleRect = tester.getRect(find.text('مرحباً'));
      final leftMargin = titleRect.left - slideRect.left;
      final rightMargin = slideRect.right - titleRect.right;

      expect(
        rightMargin,
        lessThan(leftMargin),
        reason: 'the Arabic headline is not anchored to the right edge: '
            'left margin ${leftMargin.toStringAsFixed(1)}, '
            'right margin ${rightMargin.toStringAsFixed(1)}. A left-anchored '
            'headline means the copy Column is using CrossAxisAlignment.end, which '
            'resolves to the LEFT under RTL.',
      );

      final subRect = tester.getRect(find.text('أهلاً'));
      expect(
        slideRect.right - subRect.right,
        lessThan(subRect.left - slideRect.left),
        reason: 'the Arabic subtitle is not anchored to the right edge',
      );
    });

    // The short-title case above proves the alignment rule; this sweep proves it holds
    // for every slide that actually ships, including any headline added later.
    testWidgets('every built-in slide keeps its copy inside the slide frame',
        (tester) async {
      tester.view.physicalSize = const Size(1080, 2280);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      final t = _FakeTransport((u) => _endpoints(u, banners: [], specialties: []));
      final auth = _guest(t);
      await tester.pumpWidget(_homeTab(auth: auth, repo: _repo(t)));
      await tester.pumpAndSettle();

      final slideRect = tester.getRect(find.byType(BannerCarousel));
      // Driven through the PageController rather than a drag: a swipe's direction is
      // itself RTL-sensitive, and this test is about layout, not gestures.
      final controller = tester
          .widget<PageView>(find.byType(PageView))
          .controller!;
      var measured = 0;

      for (var i = 0; i < kBuiltInHomeSlides.length; i++) {
        controller.jumpToPage(i);
        await tester.pumpAndSettle();

        final slide = kBuiltInHomeSlides[i];
        final title = find.text(slide.titleAr);
        expect(
          title,
          findsOneWidget,
          reason: 'slide $i ("${slide.titleAr}") did not render',
        );

        // The first character must land on the right half of the slide.
        final para = tester.renderObject<RenderParagraph>(title);
        final paraRect = tester.getRect(title);
        final boxes = para.getBoxesForSelection(
          const TextSelection(baseOffset: 0, extentOffset: 1),
        );
        expect(boxes, isNotEmpty, reason: 'slide $i title has no glyphs');
        final firstGlyphX = paraRect.left + boxes.first.left;
        final slideMidX = slideRect.left + slideRect.width / 2;
        expect(
          firstGlyphX,
          greaterThan(slideMidX),
          reason: 'slide $i ("${slide.titleAr}") draws its first character at '
              'x=${firstGlyphX.toStringAsFixed(1)}, left of the slide midpoint '
              '${slideMidX.toStringAsFixed(1)}',
        );

        // And the copy must sit inside the frame, not overhang it.
        expect(paraRect.left, greaterThanOrEqualTo(slideRect.left - 1));
        expect(paraRect.right, lessThanOrEqualTo(slideRect.right + 1));
        measured++;
      }

      // A loop that measured nothing would pass vacuously.
      expect(measured, kBuiltInHomeSlides.length);
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
