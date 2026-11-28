// Execution 71 — the lawyer workspace survives a partially deployed backend.
//
// `exec68_lawyer_workspace_test.dart` serves all five endpoints successfully, so it
// cannot see this defect: `_load()` awaited them in sequence inside one try, and a
// single failure replaced the entire screen with an error. On production four of the
// five (`clients`, `templates`, `chatbot`, `subscription`) return 404 while `firm.php`
// is fine, so a lawyer with a firm, clients and a plan would be told their workspace
// failed and shown nothing at all.
//
// The rule these tests pin: one failing endpoint degrades *that tab*, never the screen.
// The firm — the thing the screen exists to show — must still render.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
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

/// Answers the paths in [byPath] and 404s everything else — the shape production has
/// today, where only `firm.php` of the five workspace endpoints is deployed.
ApiRepository _repoWith(Map<String, Object> byPath) {
  final t = _Transport((uri) async {
    for (final entry in byPath.entries) {
      if (uri.path.endsWith(entry.key)) return (200, entry.value);
    }
    return (404, '<!DOCTYPE html><html><body>Not Found</body></html>');
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
  ],
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
  testWidgets('a firm renders even when the other four endpoints are 404', (tester) async {
    await _pump(tester, _repoWith({'/firm.php': _firmResponse}));

    // The screen's whole purpose. Before the fix this was replaced by ErrorState.
    expect(find.text('مكتب الأمانة للمحاماة'), findsOneWidget);
    expect(find.text('مالك المكتب'), findsOneWidget);
    expect(find.text('سارة القحطاني'), findsOneWidget);
  });

  testWidgets('one failed tab does not erase the others', (tester) async {
    // Production's exact shape: firm deployed, the rest absent.
    await _pump(tester, _repoWith({
      '/firm.php': _firmResponse,
      '/subscription.php': {
        'ok': true,
        'currency': 'SAR',
        'plan': {'id': 2, 'name_ar': 'احترافية', 'price_monthly_display': '149.00'},
        'limits': [],
      },
    }));

    expect(find.text('مكتب الأمانة للمحاماة'), findsOneWidget);

    // The plan still renders on its own tab.
    await tester.drag(find.byType(TabBar), const Offset(-260, 0));
    await tester.pumpAndSettle();
    await tester.tap(find.text('الاشتراك'));
    await tester.pumpAndSettle();
    expect(find.text('احترافية'), findsOneWidget);
  });

  testWidgets('a failed tab explains itself instead of showing an empty list', (tester) async {
    await _pump(tester, _repoWith({'/firm.php': _firmResponse}));

    await tester.tap(find.text('الموكلون'));
    await tester.pumpAndSettle();

    // "لا يوجد موكلون بعد" would be a lie: the request failed, we do not know.
    expect(find.text('لا يوجد موكلون بعد'), findsNothing);
    expect(find.textContaining('تعذّر تحميل'), findsOneWidget);
  });

  testWidgets('a total failure still offers a retry', (tester) async {
    await _pump(tester, _repoWith({}));
    expect(find.text('إعادة المحاولة'), findsOneWidget);
  });
}
