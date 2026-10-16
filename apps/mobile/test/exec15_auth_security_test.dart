import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;

import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_exception.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';

/// A transport that answers every request with a fixed status/body, so the
/// production client and [AuthController] run unchanged.
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
  _MemoryTokenStore({this.token});

  String? token;

  @override
  Future<void> saveToken(String value) async => token = value;
  @override
  Future<String?> readToken() async => token;
  @override
  Future<void> saveAccountType(String value) async {}
  @override
  Future<String?> readAccountType() async => null;
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async => token = null;
}

AuthController _auth(http_base.BaseClient transport, TokenStore tokens) =>
    AuthController(
      repository: ApiRepository(
        client: ApiClient(client: transport, tokens: tokens),
      ),
      tokens: tokens,
    );

void main() {
  group('Logout', () {
    test('a failed server revoke still clears the stored token', () async {
      final tokens = _MemoryTokenStore(token: 'tok');
      // The revoke endpoint fails; the device must not keep a usable token.
      final auth = _auth(
        _FakeTransport((_) async => (500, {'ok': false, 'error': 'boom'})),
        tokens,
      );

      await auth.signOut();

      expect(await tokens.readToken(), isNull);
      expect(auth.status, AuthStatus.signedOut);
    });

    test('a successful revoke clears the token and the session', () async {
      final tokens = _MemoryTokenStore(token: 'tok');
      final auth = _auth(_FakeTransport((_) async => (200, {'ok': true})), tokens);

      await auth.signOut();

      expect(await tokens.readToken(), isNull);
      expect(auth.status, AuthStatus.signedOut);
      expect(auth.user.id, 0);
    });
  });

  group('Session restore', () {
    test('a rejected token is cleared locally and flagged as expired', () async {
      final tokens = _MemoryTokenStore(token: 'stale');
      final auth = _auth(
        _FakeTransport((_) async => (401, {'ok': false, 'error': 'unauthorized'})),
        tokens,
      );

      await auth.restore();

      expect(await tokens.readToken(), isNull,
          reason: 'a token the server rejects must not be kept on the device');
      expect(auth.sessionExpired, isTrue);
      expect(auth.status, AuthStatus.signedOut);
    });

    test('an offline check keeps the token and does not claim expiry', () async {
      final tokens = _MemoryTokenStore(token: 'tok');
      final auth = _auth(
        _FakeTransport((_) async => throw const _Offline()),
        tokens,
      );

      await auth.restore();

      expect(await tokens.readToken(), 'tok',
          reason: 'a reachability failure must not destroy a valid token');
      expect(auth.sessionExpired, isFalse);
      expect(auth.status, AuthStatus.signedOut);
    });

    test('no stored token means signed out without any request', () async {
      final tokens = _MemoryTokenStore();
      var called = false;
      final auth = _auth(
        _FakeTransport((_) async {
          called = true;
          return (200, {'ok': true});
        }),
        tokens,
      );

      await auth.restore();

      expect(called, isFalse);
      expect(auth.status, AuthStatus.signedOut);
    });
  });

  group('Guest mode', () {
    test('entering guest issues no token and no user id', () async {
      final tokens = _MemoryTokenStore();
      final auth = _auth(_FakeTransport((_) async => (200, {'ok': true})), tokens);

      auth.enterGuest();

      expect(auth.status, AuthStatus.guest);
      expect(auth.isGuest, isTrue);
      expect(auth.isSignedIn, isFalse);
      expect(auth.user.id, 0);
      expect(await tokens.readToken(), isNull);
    });

    test('a guest holds no capability', () {
      final auth = _auth(
          _FakeTransport((_) async => (200, {'ok': true})), _MemoryTokenStore());
      auth.enterGuest();
      expect(auth.can('cases.view'), isFalse);
      expect(auth.can('documents.upload'), isFalse);
    });

    test('restore never clobbers an active guest session', () async {
      final auth = _auth(
          _FakeTransport((_) async => (200, {'ok': true})), _MemoryTokenStore());
      auth.enterGuest();
      await auth.restore();
      expect(auth.status, AuthStatus.guest);
    });
  });

  group('Sign in', () {
    test('an unverified account gets no token and an explanatory message',
        () async {
      final tokens = _MemoryTokenStore();
      final auth = _auth(
        _FakeTransport((_) async => (
              202,
              {'ok': true, 'verification_required': true, 'user': {'id': 1}}
            )),
        tokens,
      );

      final ok = await auth.signIn(
        email: 'a@b.com',
        password: 'Password1',
        asLawyer: false,
      );

      expect(ok, isFalse);
      expect(await tokens.readToken(), isNull);
      expect(auth.error, isNotNull);
      expect(auth.status, isNot(AuthStatus.signedIn));
    });

    test('a verified account stores the token and becomes signed in', () async {
      final tokens = _MemoryTokenStore();
      final auth = _auth(
        _FakeTransport((_) async => (
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
            )),
        tokens,
      );

      final ok = await auth.signIn(
        email: 'a@b.com',
        password: 'Password1',
        asLawyer: false,
      );

      expect(ok, isTrue);
      expect(await tokens.readToken(), 'tok');
      expect(auth.status, AuthStatus.signedIn);
      expect(auth.isSignedIn, isTrue);
    });
  });

  group('API errors', () {
    test('a 401 maps to an unauthorized ApiException', () async {
      final repo = ApiRepository(
        client: ApiClient(
          client: _FakeTransport((_) async => (401, {'ok': false, 'error': 'no'})),
          tokens: _MemoryTokenStore(),
        ),
      );
      await expectLater(
        repo.me(),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiErrorKind.unauthorized)),
      );
    });

    test('a 500 maps to a server ApiException', () async {
      final repo = ApiRepository(
        client: ApiClient(
          client: _FakeTransport((_) async => (500, {'ok': false})),
          tokens: _MemoryTokenStore(),
        ),
      );
      await expectLater(
        repo.me(),
        throwsA(
            isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.server)),
      );
    });
  });
}

class _Offline implements Exception {
  const _Offline();
}
