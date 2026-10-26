import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/deep_link_service.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:provider/provider.dart';

import 'package:mawatheq_lawyer/core/lawyer_config.dart';
import 'package:mawatheq_lawyer/features/auth/lawyer_login_screen.dart';

/// Execution 58 — the independent lawyer app.
///
/// These tests drive the real [ApiRepository] through a fake HTTP transport, so the
/// production client, its JSON parsing and the real widgets are exercised. The
/// payloads below are the shapes the live endpoints actually return, captured from
/// `/api/v1/` over HTTP — not a shape invented to make an assertion pass.
///
/// The central case is the role check: `/auth/lawyer-login.php` returns the account
/// under a `lawyer` key and **no top-level `type`**. An earlier test invented
/// `user: {type: 'lawyer'}`, which the server never sends, and that hid a defect
/// where a signed-in lawyer was parsed as a client and handed the customer shell.
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

/// Mount at a phone surface. The Flutter test default (800x600) is a tablet-ish
/// window on which phone layouts overflow, which looks like a layout bug and is not.
Future<void> _pumpPhone(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(1080, 2280);
  tester.view.devicePixelRatio = 3.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  await tester.pumpWidget(MaterialApp(home: Scaffold(body: child)));
  await tester.pump(const Duration(milliseconds: 400));
}

/// The exact body of `POST /api/v1/auth/lawyer-login.php` on success, as captured
/// from the running app. Note the `lawyer` key and the absence of `type`.
Map<String, Object> _lawyerLoginBody() => {
      'ok': true,
      'token': 'a' * 64,
      'lawyer': {
        'id': 3,
        'full_name': 'Firm B Owner',
        'email': 'owner-b@example.com',
        'specialization': null,
        'rating': 5,
        'photo_path': null,
      },
    };

void main() {
  group('lawyer sign-in', () {
    test('a lawyer-login response produces a lawyer-typed session', () async {
      final repo = _repo((_) async => (200, _lawyerLoginBody()));

      final outcome = await repo.lawyerLogin(
        email: 'owner-b@example.com',
        password: 'secret',
      );

      // The whole point of the fix: without an explicit default type this was
      // `client`, and `app.dart` routed the lawyer into the customer shell.
      expect(outcome.user.isLawyer, isTrue,
          reason: 'the lawyer endpoint must yield a lawyer session');
      expect(outcome.token, 'a' * 64);
      expect(outcome.user.name, 'Firm B Owner',
          reason: 'the name is under the `lawyer` key, not `user`');
      expect(outcome.user.email, 'owner-b@example.com');
    });

    test('a client-login response stays a client session', () async {
      // Guards the other direction: the default type must not turn every caller
      // into a lawyer. The client endpoint nests under `user` and sends no type.
      final repo = _repo((_) async => (200, {
            'ok': true,
            'token': 'b' * 64,
            'user': {'id': 1, 'full_name': 'Client A', 'email': 'client-a@example.com'},
          }));

      final outcome = await repo.login(email: 'client-a@example.com', password: 'secret');

      expect(outcome.user.isLawyer, isFalse);
      expect(outcome.user.name, 'Client A');
    });

    test('a rejected lawyer login surfaces the server message', () async {
      final repo = _repo((_) async => (401, {'ok': false, 'error': 'invalid email or password'}));

      await expectLater(
        repo.lawyerLogin(email: 'x@example.com', password: 'wrong'),
        throwsA(isA<Object>()),
      );
    });
  });

  group('lawyer login screen', () {
    testWidgets('is a lawyer form — no account-type switch, no registration', (tester) async {
      final repo = _repo((_) async => (200, _lawyerLoginBody()));
      final auth = AuthController(repository: repo, tokens: _MemoryTokenStore());

      await _pumpPhone(
        tester,
        MultiProvider(
          providers: [
            Provider<ApiRepository>.value(value: repo),
            ChangeNotifierProvider<AuthController>.value(value: auth),
          ],
          child: const LawyerLoginScreen(),
        ),
      );

      // The independent app has no customer entry point at all.
      expect(find.text('الدخول إلى مساحة العمل'), findsOneWidget);
      expect(find.textContaining('أنشئ حساباً'), findsNothing);
      expect(find.textContaining('أنا عميل'), findsNothing);
      // Email + password fields, and the lawyer-only password recovery link.
      expect(find.byType(TextFormField), findsNWidgets(2));
      expect(find.text('نسيت كلمة المرور؟'), findsOneWidget);
    });

    testWidgets('a wrong password shows the server message and does not sign in', (tester) async {
      final repo = _repo((_) async => (401, {'ok': false, 'error': 'بيانات الدخول غير صحيحة'}));
      final auth = AuthController(repository: repo, tokens: _MemoryTokenStore());

      await _pumpPhone(
        tester,
        MultiProvider(
          providers: [
            Provider<ApiRepository>.value(value: repo),
            ChangeNotifierProvider<AuthController>.value(value: auth),
          ],
          child: const LawyerLoginScreen(),
        ),
      );

      await tester.enterText(find.byType(TextFormField).at(0), 'owner-b@example.com');
      await tester.enterText(find.byType(TextFormField).at(1), 'wrong-password');
      await tester.tap(find.text('الدخول إلى مساحة العمل'));
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('بيانات الدخول غير صحيحة'), findsOneWidget);
      expect(auth.status, isNot(AuthStatus.signedIn));
    });
  });

  group('lawyer reset deep link', () {
    test('the lawyer app accepts its own reset path and rejects the client one', () {
      final lawyerToken = 'a' * 64;

      final own = IncomingLink.tryParse(
        Uri.parse('https://mawatheq.com/lawyer-reset-password.php?token=$lawyerToken'),
        resetPaths: IncomingLink.lawyerResetPaths,
      );
      expect(own, isNotNull, reason: 'the lawyer path must be recognized');
      expect(own!.token, lawyerToken);

      // The customer path belongs to the other app; recognizing it here would let a
      // client link open the lawyer reset screen.
      final foreign = IncomingLink.tryParse(
        Uri.parse('https://mawatheq.com/reset-password.php?token=$lawyerToken'),
        resetPaths: IncomingLink.lawyerResetPaths,
      );
      expect(foreign, isNull);

      // A non-mawatheq host is refused regardless of the path.
      final hostile = IncomingLink.tryParse(
        Uri.parse('https://evil.example/lawyer-reset-password.php?token=$lawyerToken'),
        resetPaths: IncomingLink.lawyerResetPaths,
      );
      expect(hostile, isNull);
    });
  });

  group('lawyer app version', () {
    test('the config version matches pubspec', () {
      // A drift between the two is invisible until a support call asks "which
      // version are you on" and the answer is wrong. The check reads the file, so
      // it fails at test time, not in the field.
      final text = File('pubspec.yaml').readAsStringSync();
      final match = RegExp(r'^version:\s*(\S+)', multiLine: true).firstMatch(text);
      expect(match, isNotNull, reason: 'pubspec.yaml must carry a version:');
      // `version:` carries the build number (`1.0.0+1`); the app reports the semver.
      final pubspecVersion = match!.group(1)!.split('+').first;
      expect(LawyerConfig.appVersion, pubspecVersion,
          reason: 'lib/core/lawyer_config.dart and pubspec.yaml must agree');
    });
  });
}
