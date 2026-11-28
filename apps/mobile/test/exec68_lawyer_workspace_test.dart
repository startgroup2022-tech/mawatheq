// Execution 68 — the lawyer workspace screen.
//
// The screen builds its data from five endpoints. A widget test that only asserts "it
// mounted" would pass with all five returning an empty object, which is exactly the
// blank-screen defect Execution 41 recorded. These tests therefore assert the *rendered
// text*: the firm name, a client's name, a template title, the assistant's conversation
// count and a plan's enforced limit.
//
// The transport is a fake at the network edge only. The production ApiClient, repository,
// models and widgets are all exercised.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/workspace_models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_workspace_screen.dart';

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
  @override
  Future<void> saveToken(String value) async {}
  @override
  Future<String?> readToken() async => 'test-token';
  @override
  Future<void> saveAccountType(String value) async {}
  @override
  Future<String?> readAccountType() async => 'lawyer';
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => 'test';
  @override
  Future<void> saveBiometricEnabled(bool enabled) async {}
  @override
  Future<bool> readBiometricEnabled() async => false;
  @override
  Future<void> clear() async {}
}

ApiRepository _repoWith(Map<String, Object> byPath) {
  final t = _Transport((uri) async {
    final path = uri.path;
    for (final entry in byPath.entries) {
      if (path.endsWith(entry.key)) return (200, entry.value);
    }
    return (404, {'ok': false, 'error': 'not_found'});
  });
  return ApiRepository(client: ApiClient(client: t, tokens: _Tokens()));
}

final _firmResponse = {
  'ok': true,
  'role': 'owner',
  'can_manage': true,
  'firm': {
    'id': 1,
    'name': 'مكتب الأمانة للمحاماة',
    'email': 'office@amana.sa',
    'city': 'الرياض',
    'about_ar': 'مكتب متخصص في القضايا التجارية.',
    'is_public': true,
  },
  'members': [
    {'full_name': 'سارة القحطاني', 'member_role': 'owner', 'specialization': 'تجاري'},
    {'full_name': 'خالد الشمري', 'member_role': 'lawyer', 'specialization': 'عمالي'},
  ],
};

final _clientsResponse = {
  'ok': true,
  'total': 1,
  'clients': [
    {
      'id': 7,
      'full_name': 'مؤسسة النخيل التجارية',
      'email': 'nakhil@example.sa',
      'cases_count': 3,
      'consultations_count': 2,
    },
  ],
};

final _templatesResponse = {
  'ok': true,
  'ready': true,
  'tier': 'paid',
  'categories': [
    {'id': 1, 'name_ar': 'لوائح الدعاوى'}
  ],
  'templates': [
    {
      'id': 3,
      'title_ar': 'عقد أتعاب محاماة',
      'plan_tier': 'paid',
      'can_use': true,
      'category_ar': 'العقود والاتفاقيات',
    },
    {
      'id': 9,
      'title_ar': 'لائحة اعتراضية',
      'plan_tier': 'enterprise',
      'can_use': false,
      'category_ar': 'الاعتراضات',
    },
  ],
};

final _chatbotResponse = {
  'ok': true,
  'available': true,
  'plan_allows': true,
  'bot': {
    'id': 1,
    'public_key': 'f5d4a8ff96b94ecdee19a9d796005183',
    'display_name_ar': 'المساعد القانوني',
    'greeting_ar': 'مرحباً بك. كيف يمكننا مساعدتك؟',
    'is_enabled': true,
  },
  'stats': {'total': 12, 'open': 4, 'converted': 8},
};

final _subscriptionResponse = {
  'ok': true,
  'currency': 'SAR',
  'plan': {
    'id': 2,
    'name_ar': 'احترافية',
    'price_monthly': '149.000',
    'price_yearly': '1490.000',
  },
  'limits': [
    {'key': 'max_staff', 'limit': 5, 'used': 2, 'remaining': 3, 'enforced': true, 'exhausted': false},
    {'key': 'max_active_cases', 'limit': 50, 'used': 50, 'remaining': 0, 'enforced': true, 'exhausted': true},
    {'key': 'ai_queries_limit', 'limit': null, 'used': null, 'remaining': null, 'enforced': false, 'exhausted': false},
  ],
};

Map<String, Object> _allEndpoints() => {
      '/firm.php': _firmResponse,
      '/clients.php': _clientsResponse,
      '/templates.php': _templatesResponse,
      '/chatbot.php': _chatbotResponse,
      '/subscription.php': _subscriptionResponse,
    };

Future<void> _pump(WidgetTester tester, ApiRepository repo) async {
  tester.view.physicalSize = const Size(1080, 2280);
  tester.view.devicePixelRatio = 3.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(MaterialApp(
    theme: AppTheme.light(),
    home: LawyerWorkspaceScreen(repository: repo),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('firm tab renders the firm name, role and members', (tester) async {
    await _pump(tester, _repoWith(_allEndpoints()));
    expect(find.text('مكتب الأمانة للمحاماة'), findsOneWidget);
    expect(find.text('مالك المكتب'), findsOneWidget);
    expect(find.text('سارة القحطاني'), findsOneWidget);
    expect(find.text('الأعضاء (2)'), findsOneWidget);
  });

  testWidgets('clients tab renders a real client and its counts', (tester) async {
    await _pump(tester, _repoWith(_allEndpoints()));
    await tester.tap(find.text('الموكلون'));
    await tester.pumpAndSettle();
    expect(find.text('مؤسسة النخيل التجارية'), findsOneWidget);
    expect(find.text('3 قضية · 2 استشارة'), findsOneWidget);
  });

  testWidgets('templates tab marks an unavailable tier as locked', (tester) async {
    await _pump(tester, _repoWith(_allEndpoints()));
    await tester.tap(find.text('القوالب'));
    await tester.pumpAndSettle();
    expect(find.text('عقد أتعاب محاماة'), findsOneWidget);
    expect(find.text('لائحة اعتراضية'), findsOneWidget);
    // The gate is server-side; the screen must surface the refusal, not hide the row.
    expect(find.textContaining('يتطلب باقة أعلى'), findsOneWidget);
  });

  testWidgets('chatbot tab renders the assistant and its conversation counts', (tester) async {
    await _pump(tester, _repoWith(_allEndpoints()));
    await tester.tap(find.text('المساعد'));
    await tester.pumpAndSettle();
    expect(find.text('المساعد القانوني'), findsOneWidget);
    expect(find.text('12'), findsOneWidget);
    expect(find.text('4'), findsOneWidget);
  });

  testWidgets('subscription tab shows the price and never renders unlimited as zero', (tester) async {
    await _pump(tester, _repoWith(_allEndpoints()));
    // Five tabs do not fit 360dp, so the bar scrolls. That is the shipped behaviour.
    await tester.drag(find.byType(TabBar), const Offset(-260, 0));
    await tester.pumpAndSettle();
    await tester.tap(find.text('الاشتراك'));
    await tester.pumpAndSettle();
    expect(find.textContaining('149.000'), findsOneWidget);
    expect(find.text('2 / 5'), findsOneWidget);
    // A null limit is "بلا حد" — the bug this guards is showing "0 / 0".
    expect(find.text('بلا حد'), findsOneWidget);
  });

  testWidgets('a solo lawyer gets the no-firm state, not an error', (tester) async {
    final repo = _repoWith({
      '/firm.php': {'ok': true, 'firm': null, 'role': '', 'can_manage': false, 'members': []},
      '/clients.php': {'ok': true, 'total': 0, 'clients': []},
      '/templates.php': {'ok': true, 'ready': true, 'tier': 'free', 'categories': [], 'templates': []},
      '/chatbot.php': {'ok': true, 'available': false, 'reason': 'no_firm', 'bot': null},
      '/subscription.php': {'ok': true, 'currency': 'SAR', 'plan': null, 'limits': []},
    });
    await _pump(tester, repo);
    expect(find.text('لا يوجد مكتب مرتبط بحسابك'), findsOneWidget);
    await tester.tap(find.text('المساعد'));
    await tester.pumpAndSettle();
    expect(find.textContaining('متاح للمكاتب فقط'), findsOneWidget);
  });

  testWidgets('a failed request shows the server message and a retry', (tester) async {
    final t = _Transport((uri) async {
      if (uri.path.endsWith('/firm.php')) {
        return (500, {'ok': false, 'error': 'تعذّر تحميل بيانات المكتب.'});
      }
      return (500, {'ok': false, 'error': 'server'});
    });
    final repo = ApiRepository(client: ApiClient(client: t, tokens: _Tokens()));
    await _pump(tester, repo);
    expect(find.textContaining('تعذّر تحميل بيانات المكتب'), findsOneWidget);
    expect(find.text('إعادة المحاولة'), findsOneWidget);
  });

  test('models carry the server flags through unchanged', () {
    final sub = SubscriptionInfo.fromJson(_subscriptionResponse);
    expect(sub.currency, 'SAR');
    expect(sub.limits.length, 3);
    final unlimited = sub.limits.firstWhere((l) => l.key == 'ai_queries_limit');
    expect(unlimited.enforced, isFalse);
    expect(unlimited.limit, isNull);
    final exhausted = sub.limits.firstWhere((l) => l.key == 'max_active_cases');
    expect(exhausted.exhausted, isTrue);

    final tpl = LegalTemplate.fromJson(
      (_templatesResponse['templates'] as List).last as Map<String, dynamic>,
    );
    expect(tpl.canUse, isFalse, reason: 'a locked template must not read as usable');

    final bot = FirmChatbot.fromJson(_chatbotResponse);
    expect(bot.available, isTrue);
    expect(bot.totalConversations, 12);

    final firm = FirmInfo.fromJson(_firmResponse);
    expect(firm?.canManage, isTrue);
    expect(firm?.members.length, 2);
  });
}
