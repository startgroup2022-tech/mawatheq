// Execution 65 — the customer account surfaces.
//
// Every screen here is mounted against a transport that answers with the real
// response shapes, and then asserted to render the content it fetched. That is the
// guard against the class of defect a compile check cannot see: a screen that builds
// but shows nothing, a response field the model does not read, or a filter chip that
// highlights without re-querying.
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
import 'package:mawatheq_mobile/features/account/privacy_screen.dart';
import 'package:mawatheq_mobile/features/account/support_appointments_screen.dart';
import 'package:mawatheq_mobile/features/account/support_center_screen.dart';
import 'package:mawatheq_mobile/features/account/transactions_screen.dart';
import 'package:provider/provider.dart';

// --------------------------------------------------------------- transport

class _Transport extends http_base.BaseClient {
  _Transport(this.handler);
  final Future<(int, Object)> Function(Uri uri) handler;

  /// Every request the screens made, in order, so a test can assert that a filter
  /// chip actually changed the query rather than only repainting.
  final List<Uri> requests = [];

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    requests.add(request.url);
    final (status, body) = await handler(request.url);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(body is String ? body : jsonEncode(body))),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _Tokens implements TokenStore {
  String? _token = 'exec65-token';
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
  Future<String?> readDeviceLabel() async => 'exec65';
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

final _settings = {
  'ok': true,
  'channels': [
    {'key': 'email', 'type': 'email', 'value': 'info@mawatheq.com'},
    {'key': 'whatsapp', 'type': 'whatsapp', 'value': '966501453765'},
    {'key': 'tickets', 'type': 'tickets', 'value': ''},
    {'key': 'appointments', 'type': 'appointments', 'value': ''},
  ],
  'hours': 'الأحد – الخميس، 9 صباحاً – 5 مساءً',
  'whatsapp_url': 'https://wa.me/966501453765',
};

final _tickets = [
  {
    'id': 11,
    'ticket_number': 'TKT-2026-000111',
    'subject': 'استفسار عن تجديد الاشتراك',
    'status': 'answered',
    'priority': 'normal',
    'category_ar': 'الفواتير والدفع',
    'category_en': 'Billing and payments',
    'updated_at': '2026-11-04 10:30',
    'unread_for_customer': 1,
  },
];

final _slots = [
  {
    'id': 21,
    'starts_at': '2026-11-10 04:32',
    'ends_at': '2026-11-10 05:02',
    'remaining': 2,
    'note': '',
  },
];

final _appointments = [
  {
    'id': 31,
    'reference': 'SUP-2026-822193',
    'subject': 'طلب موعد تجريبي',
    'status': 'pending',
    'starts_at': '2026-11-10 04:32',
    'admin_note': '',
  },
];

final _transactions = [
  {
    'id': 51,
    'type': 'invoice',
    'status': 'paid',
    'amount': '350.00',
    'currency': 'SAR',
    'date': '2026-11-01',
    'reference': 'INV-2026-0051',
    'title': 'فاتورة استشارة قانونية',
    'subtitle': 'المحامي عبدالله الحربي',
    'link': '',
  },
  {
    'id': 52,
    'type': 'refund',
    'status': 'refunded',
    'amount': '75.00',
    'currency': 'SAR',
    'date': '2026-10-28',
    'reference': 'REF-2026-0052',
    'title': 'مبلغ مسترد',
    'subtitle': '',
    'link': '',
  },
];

final _summary = {
  'count': 2,
  'paid': '350.00 ر.س',
  'pending': '0.00 ر.س',
  'refunded': '75.00 ر.س',
  'currency': 'SAR',
};

final _consents = {
  'ok': true,
  'consents': [
    {
      'key': 'analytics',
      'label_ar': 'تحليلات الاستخدام',
      'label_en': 'Usage analytics',
      'granted': true,
    },
    {
      'key': 'product_updates',
      'label_ar': 'تحديثات المنتج والعروض',
      'label_en': 'Product updates',
      'granted': false,
    },
  ],
};

final _privacyInfo = {
  'ok': true,
  'privacy_url': 'https://mawatheq.com/privacy.php',
  'terms_url': 'https://mawatheq.com/terms.php',
  'account_url': 'https://mawatheq.com/account.php',
  'deletion': {
    'deletes': ['الاسم', 'البريد الإلكتروني', 'الجوال'],
    'keeps': ['الفواتير الصادرة', 'الاستشارات المكتملة'],
  },
};

Future<(int, Object)> _ok(Uri uri) async {
  final p = uri.path;
  final action = uri.queryParameters['action'] ?? '';
  if (p.endsWith('/support.php')) {
    switch (action) {
      case 'settings':
        return (200, _settings);
      case 'tickets':
        return (200, {'ok': true, 'tickets': _tickets});
      case 'slots':
        return (200, {'ok': true, 'slots': _slots});
      case 'appointments':
        return (200, {'ok': true, 'appointments': _appointments});
    }
  }
  if (p.endsWith('/privacy.php')) {
    if (action == 'info') return (200, _privacyInfo);
    if (action == 'consents') return (200, _consents);
  }
  if (p.endsWith('/transactions.php')) {
    // The server filters; the fake mirrors that so a chip that does not re-query is
    // caught by the assertion on the request log rather than by luck.
    final type = uri.queryParameters['type'] ?? 'all';
    final rows = type == 'all'
        ? _transactions
        : _transactions.where((t) => t['type'] == type).toList();
    return (200, {
      'ok': true,
      'transactions': rows,
      'summary': {..._summary, 'count': rows.length},
      'types': [
        'consultation',
        'package',
        'invoice',
        'invoice_payment',
        'refund',
      ],
      'statuses': ['paid', 'pending', 'failed', 'refunded', 'unpaid'],
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
      home: child,
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
  testWidgets('the support centre renders the channels and the ticket list', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), SupportCenterScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('مركز الدعم'));
    expect(text, contains('البريد الإلكتروني'));
    expect(text, contains('info@mawatheq.com'));
    expect(text, contains('واتساب'));
    expect(text, contains('استفسار عن تجديد الاشتراك'));
    // The ticket number and its status are both on the row.
    expect(text, contains('TKT-2026-000111'));
    expect(text, contains('تم الرد'));
    // An unread reply is surfaced, not silently dropped.
    expect(text, contains('رد جديد'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the support centre shows an empty state when there are no tickets', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport((uri) async {
      if (uri.path.endsWith('/support.php') &&
          uri.queryParameters['action'] == 'tickets') {
        return (200, {'ok': true, 'tickets': <Object>[]});
      }
      return _ok(uri);
    });
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), SupportCenterScreen(repository: repo)),
    );
    await _settle(tester);

    expect(_allText(tester), contains('لا توجد تذاكر بعد.'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the transaction history renders its rows and its summary', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), TransactionsScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('سجل العمليات'));
    expect(text, contains('فاتورة استشارة قانونية'));
    expect(text, contains('المحامي عبدالله الحربي'));
    expect(text, contains('350.00 SAR'));
    // The summary totals come from the server's own currency formatting.
    expect(text, contains('350.00 ر.س'));
    // A refund is shown as an incoming amount, not as another charge.
    expect(text, contains('+75.00 SAR'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('a transaction filter re-queries the server, not just the view', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), TransactionsScreen(repository: repo)),
    );
    await _settle(tester);

    final before = t.requests.where((u) => u.path.endsWith('/transactions.php')).length;
    await tester.tap(find.text('استشارات'));
    await _settle(tester);

    final filtered = t.requests
        .where((u) => u.path.endsWith('/transactions.php'))
        .where((u) => u.queryParameters['type'] == 'consultation')
        .toList();
    expect(
      filtered.length,
      1,
      reason: 'the chip must issue a new filtered request',
    );
    expect(
      t.requests.where((u) => u.path.endsWith('/transactions.php')).length,
      greaterThan(before),
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('the transaction history shows a professional empty state', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport((uri) async {
      if (uri.path.endsWith('/transactions.php')) {
        return (200, {
          'ok': true,
          'transactions': <Object>[],
          'summary': {..._summary, 'count': 0},
          'types': ['consultation'],
          'statuses': ['paid'],
        });
      }
      return _ok(uri);
    });
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), TransactionsScreen(repository: repo)),
    );
    await _settle(tester);

    expect(_allText(tester), contains('لا توجد عمليات مطابقة'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the privacy screen renders the consents and the deletion terms', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), PrivacyScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('الخصوصية والموافقات'));
    expect(text, contains('سياسة الخصوصية'));
    expect(text, contains('الشروط والأحكام'));
    expect(text, contains('تحليلات الاستخدام'));
    expect(text, contains('تحديثات المنتج والعروض'));
    expect(text, contains('عند حذف الحساب'));
    expect(text, contains('يبقى للامتثال النظامي'));
    // The consent switches reflect the granted flag rather than a default.
    final switches = tester.widgetList<Switch>(find.byType(Switch)).toList();
    expect(switches.length, 2);
    expect(switches.first.value, isTrue);
    expect(switches.last.value, isFalse);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the appointments screen renders the slots and the requests', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), SupportAppointmentsScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('مواعيد الدعم'));
    expect(text, contains('طلباتي'));
    expect(text, contains('طلب موعد تجريبي'));
    expect(text, contains('SUP-2026-822193'));
    expect(text, contains('المواعيد المتاحة'));
    expect(text, contains('2026-11-10 04:32'));
    // The remaining capacity is shown so the customer knows a slot is limited.
    expect(text, contains('المتبقّي: 2'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the appointments screen shows an empty state with no slots', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport((uri) async {
      if (uri.path.endsWith('/support.php') &&
          uri.queryParameters['action'] == 'slots') {
        return (200, {'ok': true, 'slots': <Object>[]});
      }
      if (uri.path.endsWith('/support.php') &&
          uri.queryParameters['action'] == 'appointments') {
        return (200, {'ok': true, 'appointments': <Object>[]});
      }
      return _ok(uri);
    });
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _signedIn(repo), SupportAppointmentsScreen(repository: repo)),
    );
    await _settle(tester);

    expect(_allText(tester), contains('لا توجد مواعيد متاحة حالياً'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('no account screen overflows at a phone width', (tester) async {
    _phoneSurface(tester);
    final t = _Transport(_ok);
    final repo = _repo(t);
    final screens = <Widget>[
      SupportCenterScreen(repository: repo),
      TransactionsScreen(repository: repo),
      PrivacyScreen(repository: repo),
      SupportAppointmentsScreen(repository: repo),
    ];
    for (final screen in screens) {
      await tester.pumpWidget(_wrap(repo, _signedIn(repo), screen));
      await _settle(tester);
      expect(tester.takeException(), isNull);
    }
  });
}
