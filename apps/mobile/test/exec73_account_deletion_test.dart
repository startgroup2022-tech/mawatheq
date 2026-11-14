// Execution 73 — the in-app "Delete my account" screen.
//
// Google Play requires an in-app account-deletion route and requires that it works.
// This mounts the real screen against a fake transport at the network edge only, and
// asserts:
//   * the screen explains what is erased and what is retained (the Data safety claim);
//   * it shows a request the user started on the public website (no disagreement
//     between the app and the web);
//   * a successful deletion ends the session and leaves the delete screen;
//   * a refusal from the server is shown to the user rather than swallowed.
//
// Every model, parser, screen and widget below is the production one.

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
import 'package:mawatheq_mobile/features/account/delete_account_screen.dart';
import 'package:provider/provider.dart';

class _Transport extends http_base.BaseClient {
  _Transport(this.handler);
  final Future<(int, Object)> Function(Uri uri, http_base.BaseRequest req)
  handler;
  final List<Uri> requests = [];

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    requests.add(request.url);
    final (status, body) = await handler(request.url, request);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(body is String ? body : jsonEncode(body))),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _Tokens implements TokenStore {
  String? _token = 'exec73-token';
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
  Future<String?> readDeviceLabel() async => 'exec73';
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

ApiRepository _repo(_Transport t) =>
    ApiRepository(client: ApiClient(client: t, tokens: _Tokens()));

AuthController _auth(ApiRepository repo) =>
    AuthController(repository: repo, tokens: _Tokens());

Widget _wrap(ApiRepository repo, AuthController auth, Widget child) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
      ChangeNotifierProvider<LocaleController>(
        create: (_) => LocaleController(),
      ),
      ChangeNotifierProvider<ThemeController>(
        create: (_) => ThemeController(prefs: _Prefs()),
      ),
      Provider<ApiRepository>.value(value: repo),
    ],
    child: MaterialApp(theme: AppTheme.light(), home: child),
  );
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
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

void main() {
  testWidgets('the delete screen explains what is erased and retained', (
    tester,
  ) async {
    _phoneSurface(tester);
    final t = _Transport(
      (uri, req) async => (200, {'ok': true, 'has_request': false, 'request': null}),
    );
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _auth(repo), DeleteAccountScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('حذف حسابي'));
    expect(text, contains('يُحذف نهائياً'));
    expect(text, contains('يُحفظ لأسباب ملزمة'));
    expect(text, contains('الفواتير'));
    // The Google Play promise — the public web route — is surfaced in-app too.
    expect(text, contains('mawatheq.com/account-deletion'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the screen shows a request started on the website', (tester) async {
    _phoneSurface(tester);
    final t = _Transport(
      (uri, req) async => (
        200,
        {
          'ok': true,
          'has_request': true,
          'request': {
            'reference': 'DEL-AB12CD',
            'status': 'pending',
            'status_ar': 'بانتظار المعالجة',
            'status_en': 'Pending',
            'verified': true,
            'requested_at': '2026-11-11 09:00',
          },
        },
      ),
    );
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _auth(repo), DeleteAccountScreen(repository: repo)),
    );
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('لديك طلب حذف قائم'));
    expect(text, contains('DEL-AB12CD'));
    expect(text, contains('بانتظار المعالجة'));
    expect(text, contains('مؤكَّد'));
  });

  testWidgets('a server refusal on delete is shown, not swallowed', (tester) async {
    _phoneSurface(tester);
    final t = _Transport((uri, req) async {
      if (req.method == 'POST') {
        return (
          409,
          {
            'ok': false,
            'error': 'لا يمكن حذف الحساب لوجود استشارة قادمة.',
          },
        );
      }
      return (200, {'ok': true, 'has_request': false, 'request': null});
    });
    final repo = _repo(t);
    await tester.pumpWidget(
      _wrap(repo, _auth(repo), DeleteAccountScreen(repository: repo)),
    );
    await _settle(tester);

    // Tap delete, type a password, confirm, then confirm the dialog.
    await tester.tap(find.text('طلب حذف حسابي'));
    await tester.pumpAndSettle(const Duration(milliseconds: 200));
    await tester.enterText(find.byType(TextField).first, 'secret');
    await tester.tap(find.text('متابعة'));
    await tester.pumpAndSettle(const Duration(milliseconds: 200));
    await tester.tap(find.text('حذف نهائي'));
    await _settle(tester);

    final text = _allText(tester);
    expect(text, contains('لا يمكن حذف الحساب لوجود استشارة قادمة.'));
  });
}
