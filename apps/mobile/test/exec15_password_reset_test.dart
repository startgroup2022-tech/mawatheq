import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:provider/provider.dart';

import 'package:mawatheq_mobile/core/deep_link_service.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/auth/password_reset_screens.dart';

class _FakeTransport extends http_base.BaseClient {
  _FakeTransport(this.handler);

  final Future<(int, Object)> Function(Uri uri, String body) handler;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final body = request is http_base.Request ? request.body : '';
    final (status, payload) = await handler(request.url, body);
    final text = payload is String ? payload : jsonEncode(payload);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(text)),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
  @override
  Future<void> saveToken(String value) async {}
  @override
  Future<String?> readToken() async => null;
  @override
  Future<void> saveAccountType(String value) async {}
  @override
  Future<String?> readAccountType() async => null;
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async {}
}

ApiRepository _repo(_FakeTransport transport) =>
    ApiRepository(client: ApiClient(client: transport, tokens: _MemoryTokenStore()));

Widget _wrap(Widget child) => MultiProvider(
      providers: [
        ChangeNotifierProvider<LocaleController>(
          create: (_) => LocaleController(),
        ),
      ],
      child: MaterialApp(home: child),
    );

final _token = 'a' * 64;

class _FakeLinkSource implements LinkSource {
  _FakeLinkSource({this.initial});

  final Uri? initial;
  final _controller = StreamController<Uri>.broadcast();

  @override
  Future<Uri?> getInitialLink() async => initial;

  @override
  Stream<Uri> get uriLinkStream => _controller.stream;

  void emit(Uri uri) => _controller.add(uri);

  Future<void> close() => _controller.close();
}

void main() {
  group('DeepLinkService', () {
    test('a link that launched the app cold is held until a handler is set',
        () async {
      final source = _FakeLinkSource(
          initial: Uri.parse('https://mawatheq.com/reset-password.php?token=$_token'));
      final service = DeepLinkService(links: source);
      await service.start();

      // The widget that handles the link is built after start(), so nothing is
      // delivered yet; assigning the handler must flush the held token exactly once.
      final received = <String>[];
      service.onResetPassword = received.add;
      expect(received, [_token]);
      service.onResetPassword = received.add; // reassigning must not replay it
      expect(received, [_token]);

      await service.dispose();
      await source.close();
    });

    test('a link arriving while running goes straight to the handler', () async {
      final source = _FakeLinkSource();
      final received = <String>[];
      final service = DeepLinkService(links: source, onResetPassword: received.add);
      await service.start();

      source.emit(
          Uri.parse('https://www.mawatheq.com/reset-password?token=$_token'));
      await Future<void>.delayed(Duration.zero);
      expect(received, [_token]);

      await service.dispose();
      await source.close();
    });

    test('a non-reset link is ignored', () async {
      final source = _FakeLinkSource();
      final received = <String>[];
      final service = DeepLinkService(links: source, onResetPassword: received.add);
      await service.start();

      source.emit(Uri.parse('https://mawatheq.com/ai-assistant.php'));
      await Future<void>.delayed(Duration.zero);
      expect(received, isEmpty);

      await service.dispose();
      await source.close();
    });
  });

  group('IncomingLink', () {
    test('parses the reset link the email carries', () {
      final link = IncomingLink.tryParse(
        Uri.parse('https://mawatheq.com/reset-password.php?as=client&token=$_token'),
      );
      expect(link, isNotNull);
      expect(link!.isReset, isTrue);
      expect(link.token, _token);
    });

    test('accepts the extension-less reset path', () {
      final link = IncomingLink.tryParse(
        Uri.parse('https://www.mawatheq.com/reset-password?token=$_token'),
      );
      expect(link?.token, _token);
    });

    test('rejects a token that is not 64 hex characters', () {
      expect(
        IncomingLink.tryParse(
            Uri.parse('https://mawatheq.com/reset-password.php?token=short')),
        isNull,
      );
      expect(
        IncomingLink.tryParse(
            Uri.parse('https://mawatheq.com/reset-password.php?token=${'z' * 64}')),
        isNull,
      );
    });

    test('rejects any other path so a hostile link cannot steer the app', () {
      expect(
        IncomingLink.tryParse(
            Uri.parse('https://mawatheq.com/admin/users?token=$_token')),
        isNull,
      );
      expect(
        IncomingLink.tryParse(Uri.parse('https://evil.example/reset-password.php?token=$_token')),
        isNull,
      );
    });
  });

  group('ForgotPasswordScreen', () {
    testWidgets('sends the request and shows a neutral notice', (tester) async {
      String? body;
      final repo = _repo(_FakeTransport((uri, b) async {
        body = b;
        return (200, {'ok': true, 'message': 'sent'});
      }));

      await tester.pumpWidget(_wrap(ForgotPasswordScreen(repository: repo)));
      await tester.enterText(find.byType(TextField), 'user@example.com');
      await tester.tap(find.text('إرسال رابط إعادة التعيين'));
      await tester.pumpAndSettle();

      expect(body, contains('user@example.com'));
      // The response must not reveal whether the address exists.
      expect(find.textContaining('إذا كان البريد مسجّلاً'), findsOneWidget);
    });

    testWidgets('refuses a malformed email without calling the server',
        (tester) async {
      var called = false;
      final repo = _repo(_FakeTransport((uri, b) async {
        called = true;
        return (200, {'ok': true});
      }));

      await tester.pumpWidget(_wrap(ForgotPasswordScreen(repository: repo)));
      await tester.enterText(find.byType(TextField), 'not-an-email');
      await tester.tap(find.text('إرسال رابط إعادة التعيين'));
      await tester.pumpAndSettle();

      expect(called, isFalse);
      expect(find.textContaining('بريداً إلكترونياً صحيحاً'), findsOneWidget);
    });
  });

  group('ResetPasswordScreen', () {
    testWidgets('prefills the token from the deep link', (tester) async {
      final repo = _repo(_FakeTransport((_, __) async => (200, {'ok': true})));
      await tester.pumpWidget(
          _wrap(ResetPasswordScreen(repository: repo, initialToken: _token)));
      await tester.pump();

      final field = tester.widget<TextField>(find.byType(TextField).first);
      expect(field.controller?.text, _token);
    });

    testWidgets('rejects mismatched passwords before calling the server',
        (tester) async {
      var called = false;
      final repo = _repo(_FakeTransport((_, __) async {
        called = true;
        return (200, {'ok': true});
      }));

      await tester.pumpWidget(
          _wrap(ResetPasswordScreen(repository: repo, initialToken: _token)));
      await tester.enterText(find.byType(TextField).at(1), 'Password123');
      await tester.enterText(find.byType(TextField).at(2), 'Password999');
      await tester.tap(find.text('تغيير كلمة المرور'));
      await tester.pumpAndSettle();

      expect(called, isFalse);
      expect(find.textContaining('غير متطابقتين'), findsOneWidget);
    });

    testWidgets('submits and reports success', (tester) async {
      String? body;
      final repo = _repo(_FakeTransport((_, b) async {
        body = b;
        return (200, {'ok': true});
      }));

      await tester.pumpWidget(
          _wrap(ResetPasswordScreen(repository: repo, initialToken: _token)));
      await tester.enterText(find.byType(TextField).at(1), 'Password123');
      await tester.enterText(find.byType(TextField).at(2), 'Password123');
      await tester.tap(find.text('تغيير كلمة المرور'));
      await tester.pumpAndSettle();

      expect(body, contains(_token));
      expect(find.textContaining('تم تغيير كلمة المرور بنجاح'), findsOneWidget);
    });

    testWidgets('a rejected token is explained, not shown raw', (tester) async {
      final repo = _repo(
          _FakeTransport((_, __) async => (410, {'ok': false, 'error': 'invalid_token'})));

      await tester.pumpWidget(
          _wrap(ResetPasswordScreen(repository: repo, initialToken: _token)));
      await tester.enterText(find.byType(TextField).at(1), 'Password123');
      await tester.enterText(find.byType(TextField).at(2), 'Password123');
      await tester.tap(find.text('تغيير كلمة المرور'));
      await tester.pumpAndSettle();

      expect(find.textContaining('غير صالح أو منتهي الصلاحية'), findsOneWidget);
    });
  });
}
