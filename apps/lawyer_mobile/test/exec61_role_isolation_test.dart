import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_shell.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:mawatheq_lawyer/app.dart';
import 'package:mawatheq_lawyer/features/auth/lawyer_login_screen.dart';

/// Execution 61 — role isolation in the independent lawyer app.
///
/// The lawyer app must never show a customer surface. The account tab wraps the
/// shared [AccountScreen], and that screen's sign-out pushes the *customer*
/// [HomeShell]. This test drives the real app root and the real widget tree to
/// prove whether a lawyer who signs out can land on the customer shell.
class _FakeTransport extends http_base.BaseClient {
  _FakeTransport(this.handler);

  final Future<(int, Object)> Function(http_base.BaseRequest request) handler;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final (status, body) = await handler(request);
    final payload = body is String ? body : jsonEncode(body);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(payload)),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> saveAccountType(String type) async {}
  @override
  Future<String?> readAccountType() async => 'lawyer';
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async => _token = null;
}

ApiRepository _repo(Future<(int, Object)> Function(http_base.BaseRequest) handler) =>
    ApiRepository(client: ApiClient(client: _FakeTransport(handler), tokens: _MemoryTokenStore()));

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('signing out of the lawyer account tab must not open the customer shell',
      (tester) async {
    tester.view.physicalSize = const Size(1080, 2280);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    // A signed-in lawyer: `/me.php` reports a lawyer session.
    final repo = _repo((request) async {
      final path = request.url.path;
      if (path.contains('/me.php')) {
        return (200, {
          'ok': true,
          'type': 'lawyer',
          'id': 3,
          'full_name': 'Firm B Owner',
          'email': 'owner-b@example.com',
        });
      }
      if (path.contains('invoices')) return (200, {'ok': true, 'invoices': <Object>[]});
      if (path.contains('revoke')) return (200, {'ok': true});
      return (200, {'ok': true});
    });
    final tokens = _MemoryTokenStore();
    await tokens.saveToken('a' * 64);
    final auth = AuthController(repository: repo, tokens: tokens);

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<ApiRepository>.value(value: repo),
          ChangeNotifierProvider<AuthController>.value(value: auth),
          ChangeNotifierProvider<LocaleController>(create: (_) => LocaleController()),
          ChangeNotifierProvider<ThemeController>(create: (_) => ThemeController()),
        ],
        child: const LawyerApp(),
      ),
    );

    // Splash (720ms) + restore.
    await tester.pump(const Duration(milliseconds: 900));
    await tester.pump(const Duration(milliseconds: 900));
    expect(find.byType(LawyerShell), findsOneWidget,
        reason: 'a restored lawyer session must reach the workspace');

    // Open the account tab.
    await tester.tap(find.text('حسابي'));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.scrollUntilVisible(
      find.text('تسجيل الخروج'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.text('تسجيل الخروج'), findsOneWidget,
        reason: 'the account tab must offer sign-out');

    await tester.tap(find.text('تسجيل الخروج'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    final confirm = find.widgetWithText(FilledButton, 'تسجيل الخروج');
    expect(confirm, findsOneWidget, reason: 'the confirmation dialog must appear');
    await tester.tap(confirm);
    // Explicit pumps, not pumpAndSettle: the customer HomeShell runs an endless
    // banner animation, so a settle would time out — which is itself the symptom.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));

    // The defect: the shared sign-out pushes the customer HomeShell unconditionally.
    expect(find.byType(HomeShell), findsNothing,
        reason: 'the lawyer app must never render the customer shell');
    expect(find.byType(LawyerLoginScreen), findsOneWidget,
        reason: 'signing out must return to the lawyer sign-in screen');
  });
}
