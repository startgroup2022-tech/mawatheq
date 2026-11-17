// Execution 74 — the legal & judicial library in the app.
//
// The screens are mounted against a transport that answers with the endpoint's real
// response shapes, and the assertions are on the rendered text and on the parsed
// model. The transports are fakes at the network edge only: every screen, model and
// widget below is the production one.
//
// The two rules the library exists to enforce are checked here:
//   * a `hosted` source exposes its readable text and the app shows it inline;
//   * a `link_only` source exposes only metadata and its official URL — the app
//     never invents a copy of a file the platform may not host.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
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
import 'package:mawatheq_mobile/features/library/library_favorites_screen.dart';
import 'package:mawatheq_mobile/features/library/library_item_screen.dart';
import 'package:mawatheq_mobile/features/library/library_screen.dart';
import 'package:mawatheq_mobile/features/library/library_search_screen.dart';
import 'package:provider/provider.dart';

// --------------------------------------------------------------- transport

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

class _Tokens implements TokenStore {
  String? _token = 'library-token';
  @override
  Future<void> saveToken(String value) async => _token = value;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> saveAccountType(String value) async {}
  @override
  Future<String?> readAccountType() async => 'client';
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => 'test';
  @override
  Future<void> saveBiometricEnabled(bool enabled) async {}
  @override
  Future<bool> readBiometricEnabled() async => false;
  @override
  Future<void> clear() async => _token = null;
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

// ---------------------------------------------------------------- fixtures

const _hostedItem = {
  'id': 11,
  'type': 'judgment',
  'type_label': 'أحكام وقرارات قضائية',
  'title': 'حكم رقم 4471161621 — المحكمة التجارية',
  'decision_title': 'قرار في دعوى مطالبة مالية',
  'title_is_derived': false,
  'summary': 'خلاف حول تنفيذ عقد توريد.',
  'court_name': 'المحكمة التجارية بالرياض',
  'city': 'الرياض',
  'decision_number': '4471161621',
  'hijri_year': 1445,
  'gregorian_year': 2024,
  'decision_date': '2024-05-08',
  'category': 'تجاري',
  'source_key': 'moj_decisions',
  'source_name': 'القرارات والأحكام القضائية — وزارة العدل',
  'publisher': 'وزارة العدل',
  'redistribution': 'hosted',
  'can_read_here': true,
  'can_download': true,
  'download_url': '/legal-library-file.php?id=11',
  'download_is_external': false,
  'body': '<p>الوقائع: تقدم المدعي بدعوى…</p><br><p>الأسباب: وحيث إن…</p>',
  'sections': {
    'facts': 'تقدم المدعي بدعوى مطالبة مالية.',
    'reasons': 'وحيث إن العقد صحيح نافذ.',
    'ruling': 'حكمت المحكمة بإلزام المدعى عليه بالمبلغ.',
  },
  'is_favorite': false,
};

const _linkOnlyItem = {
  'id': 22,
  'type': 'book',
  'type_label': 'كتب قانونية',
  'title': 'موسوعة الأنظمة السعودية',
  'source_key': 'mashortich_books',
  'source_name': 'مكتبة المصدر',
  'redistribution': 'link_only',
  'can_read_here': false,
  'can_download': true,
  'source_url': 'https://example.org/book/22',
  'download_url': 'https://example.org/book/22.pdf',
  'download_is_external': true,
};

Future<(int, Object)> _ok(Uri uri) async {
  final action = uri.queryParameters['action'] ?? 'home';
  switch (action) {
    case 'home':
      return (200, {
        'ok': true,
        'total': 2,
        'enabled_sources': 3,
        'sources': [
          {
            'key': 'moj_decisions',
            'name_ar': 'القرارات والأحكام القضائية — وزارة العدل',
            'name_en': 'Judicial decisions — MOJ',
            'publisher_ar': 'وزارة العدل',
            'item_count': 40,
            'redistribution': 'hosted',
          },
          {
            'key': 'mashortich_books',
            'name_ar': 'مكتبة المصدر',
            'name_en': 'Al-Masdar',
            'item_count': 14,
            'redistribution': 'link_only',
          },
        ],
        'types': [
          {'key': 'judgment', 'name_ar': 'أحكام', 'name_en': 'Judgments', 'count': 40},
          {'key': 'book', 'name_ar': 'كتب', 'name_en': 'Books', 'count': 14},
        ],
        'latest': [
          {
            'id': 11,
            'title': 'حكم رقم 4471161621 — المحكمة التجارية',
            'type_label': 'أحكام وقرارات قضائية',
            'source_name': 'القرارات والأحكام القضائية — وزارة العدل',
            'court_name': 'المحكمة التجارية بالرياض',
            'decision_number': '4471161621',
            'hijri_year': 1445,
          },
        ],
        'courts': 1,
        'files': 0,
        'browse': {
          'courts': [
            {'name': 'المحكمة التجارية بالرياض', 'n': 40},
          ],
          'cities': [
            {'name': 'الرياض', 'n': 30},
          ],
          'years': [
            {'name': '1445', 'n': 20},
          ],
        },
      });
    case 'search':
      return (200, {
        'ok': true,
        'total': 2,
        'page': 1,
        'pages': 1,
        'per_page': 20,
        'items': [_hostedItem, _linkOnlyItem],
      });
    case 'item':
      return (200, {'ok': true, 'item': _hostedItem});
    case 'facets':
      return (200, {
        'ok': true,
        'sources': [
          {'key': 'moj_decisions', 'name_ar': 'وزارة العدل', 'item_count': 40},
        ],
        'courts': ['المحكمة التجارية بالرياض'],
        'cities': ['الرياض'],
        'years': [1445, 1444],
        'categories': ['تجاري'],
        'types': ['judgment', 'book'],
      });
    case 'favorites':
      return (200, {
        'ok': true,
        'items': [_hostedItem, _linkOnlyItem],
      });
    default:
      return (200, {'ok': true});
  }
}

// ----------------------------------------------------------------- harness

ApiRepository _repo(_Transport t) =>
    ApiRepository(client: ApiClient(client: t, tokens: _Tokens()));

AuthController _auth(ApiRepository repo) {
  final auth = AuthController(repository: repo, tokens: _Tokens());
  auth.enterGuest();
  return auth;
}

Widget _wrap(ApiRepository repo, AuthController auth, Widget child) {
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
      theme: AppTheme.light(),
      home: child,
    ),
  );
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}

void _phoneSurface(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2280);
  tester.view.devicePixelRatio = 3.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

String _allText(WidgetTester tester) => tester
    .widgetList<Text>(find.byType(Text))
    .map((t) => t.data ?? '')
    .join(' | ');

/// A lazy `ListView` does not build what is off-screen, so content below the fold is
/// not in the tree until it is scrolled to. Drag the primary scrollable up in steps
/// and collect the text seen along the way.
Future<String> _scrolledText(WidgetTester tester) async {
  final seen = <String>[_allText(tester)];
  for (var i = 0; i < 8; i++) {
    final scrollable = find.byType(Scrollable);
    if (scrollable.evaluate().isEmpty) break;
    await tester.drag(scrollable.first, const Offset(0, -600));
    await tester.pump(const Duration(milliseconds: 120));
    seen.add(_allText(tester));
  }
  return seen.join(' || ');
}

void main() {
  group('model parsing', () {
    test('a hosted item exposes its body and an internal download', () {
      final item = LegalLibraryItem.fromJson(Map<String, dynamic>.from(_hostedItem));
      expect(item.canReadHere, isTrue);
      expect(item.body, isNotNull);
      expect(item.downloadIsExternal, isFalse);
      expect(item.downloadUrl, '/legal-library-file.php?id=11');
      expect(item.hijriYear, 1445);
      expect(item.typeLabel, 'أحكام وقرارات قضائية');
      // Execution 68: the real title, its derived flag and the structured sections.
      expect(item.decisionTitle, 'قرار في دعوى مطالبة مالية');
      expect(item.titleIsDerived, isFalse);
      expect(item.decisionDate, '2024-05-08');
      expect(item.sections, isNotNull);
      expect(item.sections!.ruling, 'حكمت المحكمة بإلزام المدعى عليه بالمبلغ.');
      expect(item.sections!.facts, isNotNull);
      expect(item.sections!.isEmpty, isFalse);
    });

    test('a link_only item exposes metadata and an external download only', () {
      final item = LegalLibraryItem.fromJson(Map<String, dynamic>.from(_linkOnlyItem));
      expect(item.canReadHere, isFalse);
      expect(item.body, isNull);
      expect(item.downloadIsExternal, isTrue);
      expect(item.downloadUrl, 'https://example.org/book/22.pdf');
    });

    test('a null body parses to null rather than an empty string', () {
      final item = LegalLibraryItem.fromJson({
        'id': 1,
        'title': 'x',
        'redistribution': 'link_only',
      });
      expect(item.body, isNull);
      expect(item.hijriYear, isNull);
    });
  });

  group('repository', () {
    test('home parses sources and counts', () async {
      final repo = _repo(_Transport(_ok));
      final home = await repo.legalLibraryHome();
      expect(home.total, 2);
      expect(home.enabledSources, 3);
      expect(home.sources.first.isHosted, isTrue);
      expect(home.sources.last.isHosted, isFalse);
      expect(home.types.first.count, 40);
      // Execution 68: the browse vocabulary and the extra counters.
      expect(home.courts, 1);
      expect(home.browse.courts.first.name, 'المحكمة التجارية بالرياض');
      expect(home.browse.courts.first.count, 40);
      expect(home.browse.cities.first.name, 'الرياض');
      expect(home.browse.years.first.name, '1445');
      // The latest strip now carries full items, not just {id,title}.
      expect(home.latest.first.title, contains('4471161621'));
      expect(home.latest.first.courtName, 'المحكمة التجارية بالرياض');
    });

    test('search parses the page envelope', () async {
      final repo = _repo(_Transport(_ok));
      final page = await repo.legalLibrarySearch(filters: {'q': 'تحكيم'});
      expect(page.total, 2);
      expect(page.pages, 1);
      expect(page.items, hasLength(2));
    });

    test('facets parses years as ints', () async {
      final repo = _repo(_Transport(_ok));
      final facets = await repo.legalLibraryFacets();
      expect(facets.years, [1445, 1444]);
      expect(facets.courts, contains('المحكمة التجارية بالرياض'));
    });
  });

  group('screens', () {
    testWidgets('the hub renders its sources and counts', (tester) async {
      _phoneSurface(tester);
      final t = _Transport(_ok);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(repo, _auth(repo), LegalLibraryScreen(repository: repo)),
      );
      await _settle(tester);

      final text = await _scrolledText(tester);
      expect(text, contains('المكتبة القانونية والقضائية'));
      expect(text, contains('القرارات والأحكام القضائية — وزارة العدل'));
      expect(text, contains('مكتبة المصدر'));
      // Execution 68: the hub exposes real browse buckets with their counts.
      expect(text, contains('تصفّح حسب الجهة القضائية'));
      expect(text, contains('المحكمة التجارية بالرياض'));
      expect(tester.takeException(), isNull);
    });

    testWidgets('search lists the items it fetched', (tester) async {
      _phoneSurface(tester);
      final t = _Transport(_ok);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(repo, _auth(repo), LegalLibrarySearchScreen(repository: repo)),
      );
      await _settle(tester);

      final text = _allText(tester);
      expect(text, contains('حكم رقم 4471161621 — المحكمة التجارية'));
      expect(text, contains('موسوعة الأنظمة السعودية'));
      expect(tester.takeException(), isNull);
    });

    testWidgets('a hosted item renders its readable body inline', (tester) async {
      _phoneSurface(tester);
      final t = _Transport(_ok);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(repo, _auth(repo), LegalLibraryItemScreen(itemId: 11, repository: repo)),
      );
      await _settle(tester);

      final text = await _scrolledText(tester);
      expect(text, contains('النص الكامل متاح للقراءة هنا'));
      // The stored HTML is shown as text, with the <br> breaks turned into lines.
      expect(text, contains('الوقائع: تقدم المدعي بدعوى…'));
      expect(text, isNot(contains('<p>')));
      expect(tester.takeException(), isNull);
    });

    testWidgets('the item page renders the structured sections', (tester) async {
      _phoneSurface(tester);
      final t = _Transport(_ok);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(repo, _auth(repo), LegalLibraryItemScreen(itemId: 11, repository: repo)),
      );
      await _settle(tester);

      final text = await _scrolledText(tester);
      // Execution 68: each published section is shown under its own heading.
      expect(text, contains('منطوق الحكم'));
      expect(text, contains('حكمت المحكمة بإلزام المدعى عليه بالمبلغ.'));
      expect(text, contains('الوقائع'));
      expect(text, contains('الأسباب'));
      expect(tester.takeException(), isNull);
    });

    testWidgets('the saved-items list renders what the endpoint returned',
        (tester) async {
      _phoneSurface(tester);
      final t = _Transport(_ok);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(repo, _auth(repo), LegalLibraryFavoritesScreen(repository: repo)),
      );
      await _settle(tester);

      final text = _allText(tester);
      expect(text, contains('حكم رقم 4471161621 — المحكمة التجارية'));
      expect(text, contains('موسوعة الأنظمة السعودية'));
      expect(tester.takeException(), isNull);
    });
  });
}
