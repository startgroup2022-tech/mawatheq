// Execution 41 — blank-screen sweep.
//
// Every screen that loads data from the API is mounted against a transport that
// answers with the real response shapes, and then asserted to render the content it
// fetched. This is the regression guard for the class of defect where a screen builds
// but shows nothing: a hardcoded repository that bypasses the injected client, a
// response field the model does not read, or an exception swallowed into an empty
// state. A blank screen is invisible to a compile check and to a test that only
// asserts the screen mounted, so the assertions here are on the rendered text.
//
// The transports are fakes at the network edge only. Every screen, model, parser and
// widget below is the production one.

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
import 'package:mawatheq_mobile/features/assistant/assistant_screen.dart';
import 'package:mawatheq_mobile/features/cases/cases_screen.dart';
import 'package:mawatheq_mobile/features/consultations/consultations_screen.dart';
import 'package:mawatheq_mobile/features/documents/documents_screen.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/notifications/notifications_screen.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';
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
  String? _token = 'sweep-token';
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
  Future<String?> readDeviceLabel() async => 'sweep';
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

final _cases = [
  {
    'id': 42,
    'title': 'نزاع عقد توريد',
    'case_number': 'CN-2026-0042',
    'status': 'active',
    'client_name': 'ثائر العتيبي',
    'next_hearing': '2026-11-12',
  },
];

final _documents = [
  {
    'id': 5,
    'title': 'عقد إيجار تجاري',
    'file_type': 'pdf',
    'status': 'ready',
    'created_at': '2026-10-01',
  },
];

final _consultations = [
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
];

final _notifications = [
  {
    'id': 3,
    'title': 'تم تأكيد استشارتك',
    'body': 'أكد المحامي موعد الاستشارة يوم ٢ نوفمبر.',
    'is_read': false,
    'created_at': '2026-10-20 09:00',
  },
];

final _lawyers = [
  {
    'id': 101,
    'full_name': 'المحامي عبدالله الحربي',
    'specialization': 'الأحوال الشخصية',
    'jurisdiction': 'الرياض',
    'rating': 4.8,
    'hourly_rate': 350.0,
  },
];

Future<(int, Object)> _ok(Uri uri) async {
  final p = uri.path;
  if (p.endsWith('/cases.php')) return (200, {'ok': true, 'cases': _cases});
  if (p.endsWith('/documents.php')) {
    return (200, {'ok': true, 'documents': _documents});
  }
  if (p.endsWith('/consultations.php')) {
    return (200, {'ok': true, 'consultations': _consultations});
  }
  if (p.endsWith('/notifications.php')) {
    return (200, {
      'ok': true,
      'unread_count': 1,
      'notifications': _notifications,
    });
  }
  if (p.endsWith('/lawyers.php')) return (200, {'ok': true, 'lawyers': _lawyers});
  if (p.endsWith('/me.php')) {
    return (200, {
      'ok': true,
      'type': 'client',
      'role': 'client',
      'profile': {'id': 1, 'full_name': 'ثائر العتيبي'},
      'permissions': [],
    });
  }
  return (200, {'ok': true});
}

// ----------------------------------------------------------------- harness

ApiRepository _repo(_Transport t) =>
    ApiRepository(client: ApiClient(client: t, tokens: _Tokens()));

AuthController _signedIn(ApiRepository repo) {
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
      home: Scaffold(body: child),
    ),
  );
}

/// Pumps bounded frames. `pumpAndSettle` cannot be used: several screens show an
/// animated loading skeleton, so the frame scheduler never goes idle.
Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}

/// Gives the test the shape of a phone. The default test surface is 800x600, a
/// tablet-ish window that makes phone layouts overflow and would report false
/// failures.
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

void main() {
  testWidgets('the home shell renders the dashboard, not a blank body',
      (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(_wrap(repo, _signedIn(repo), HomeShell(repository: repo)));
    await _settle(tester);

    // The home is a scrollable page and the promotional slider occupies the first
    // screenful, so the services section below it is reached by scrolling — which is
    // what a customer does. Asserting it without scrolling only proves the top of
    // the page renders.
    expect(_allText(tester), contains('اختر تخصصك القانوني'));
    await tester.scrollUntilVisible(
      find.text('إدارة القضايا'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await _settle(tester);

    expect(_allText(tester), contains('إدارة القضايا'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the cases screen lists the cases it fetched', (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(_wrap(repo, _signedIn(repo), CasesScreen(repository: repo)));
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('نزاع عقد توريد'));
    expect(text, contains('CN-2026-0042'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the documents screen lists the documents it fetched',
      (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), DocumentsScreen(repository: repo)),
    );
    await _settle(tester);

    expect(_allText(tester), contains('عقد إيجار تجاري'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the consultations screen lists the consultations it fetched',
      (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), ConsultationsScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('استشارة بخصوص عقد إيجار تجاري'));
    expect(text, contains('المحامي عبدالله الحربي'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the notifications screen lists the notifications it fetched',
      (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), NotificationsScreen(repository: repo)),
    );
    await _settle(tester);

    expect(_allText(tester), contains('تم تأكيد استشارتك'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the lawyer directory lists the lawyers it fetched',
      (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), PublicLawyersScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('المحامي عبدالله الحربي'));
    expect(text, contains('الرياض'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the assistant screen renders its composer and greeting',
      (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), AssistantScreen(repository: repo)),
    );
    await _settle(tester);

    // The composer is the screen's reason to exist; without it the screen is a
    // dead end even though it "rendered".
    expect(find.byType(TextField), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('no swept screen renders an empty body', (tester) async {
    // A blunt structural guard: each screen must put real text on the screen. A
    // screen that builds but paints nothing fails here even if the assertions above
    // were later loosened.
    _phoneSurface(tester);
    final screens = <String, Widget Function(ApiRepository)>{
      'cases': (r) => CasesScreen(repository: r),
      'documents': (r) => DocumentsScreen(repository: r),
      'consultations': (r) => ConsultationsScreen(repository: r),
      'notifications': (r) => NotificationsScreen(repository: r),
      'lawyers': (r) => PublicLawyersScreen(repository: r),
      'assistant': (r) => AssistantScreen(repository: r),
    };

    for (final entry in screens.entries) {
      final t = _Transport(_ok);
      final repo = _repo(t);
      await tester.pumpWidget(
        _wrap(repo, _signedIn(repo), entry.value(repo)),
      );
      await _settle(tester);

      final text = _allText(tester).replaceAll('|', '').trim();
      expect(
        text.length,
        greaterThan(10),
        reason: '${entry.key} rendered an empty body',
      );
      expect(tester.takeException(), isNull, reason: entry.key);
    }
  });
}
