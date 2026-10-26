import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/config/app_config.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_exception.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';

/// Execution 59 — the lawyer sign-in defect and the Jitsi call path.
///
/// Two independent defects are pinned here, both proven against the real client:
///
/// 1. A correct-password lawyer whose account is not yet approved saw "session
///    expired" on the sign-in screen. `messageFromBody` mapped any English 401 to
///    the status fallback, which is written for a *protected* call, and the endpoint
///    returned the same generic 401 for wrong-password and for every account state.
/// 2. `LawyerConfig.apiBase` was never used: `ApiClient` read `AppConfig.apiBase`
///    only, so the lawyer app's own configured API root was dead code.
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

ApiRepository _repo(Future<(int, Object)> Function(http_base.BaseRequest) handler) {
  return ApiRepository(
    client: ApiClient(
      client: _FakeTransport(handler),
      tokens: _MemoryTokenStore(),
    ),
  );
}

void main() {
  group('lawyer sign-in reports the real reason', () {
    test('wrong password is a credential error, never an expired session', () async {
      final repo = _repo((_) async => (401, {'ok': false, 'error': 'invalid_credentials'}));
      try {
        await repo.lawyerLogin(email: 'a@b.com', password: 'nope');
        fail('a 401 must throw');
      } on ApiException catch (e) {
        expect(e.message, contains('كلمة المرور'));
        expect(e.message, isNot(contains('انتهت')));
      }
    });

    test('an English 401 body is not surfaced verbatim', () async {
      // An older deployment that still answers with prose must not leak English
      // into an Arabic-first sign-in screen.
      final repo = _repo((_) async => (401, {'ok': false, 'error': 'invalid email or password'}));
      try {
        await repo.lawyerLogin(email: 'a@b.com', password: 'nope');
        fail('a 401 must throw');
      } on ApiException catch (e) {
        expect(e.message, 'البريد الإلكتروني أو كلمة المرور غير صحيحة.');
      }
    });

    test('a verified-but-pending lawyer is told the account is under review', () async {
      final repo = _repo((_) async => (403, {'ok': false, 'error': 'pending_approval'}));
      try {
        await repo.lawyerLogin(email: 'a@b.com', password: 'right');
        fail('a 403 must throw');
      } on ApiException catch (e) {
        expect(e.message, contains('قيد المراجعة'));
      }
    });

    test('an unverified lawyer is told to confirm the email', () async {
      final repo = _repo((_) async => (403, {'ok': false, 'error': 'email_not_verified'}));
      try {
        await repo.lawyerLogin(email: 'a@b.com', password: 'right');
        fail('a 403 must throw');
      } on ApiException catch (e) {
        expect(e.message, contains('تأكيد'));
      }
    });

    test('a suspended lawyer is told to contact support', () async {
      final repo = _repo((_) async => (403, {'ok': false, 'error': 'account_suspended'}));
      try {
        await repo.lawyerLogin(email: 'a@b.com', password: 'right');
        fail('a 403 must throw');
      } on ApiException catch (e) {
        expect(e.message, contains('موقوف'));
      }
    });

    test('a protected call still reports an expired session on 401', () async {
      // The fix must be scoped to sign-in: a 401 on an authenticated request is
      // still an expired session, which is the message that was correct all along.
      final repo = _repo((_) async => (401, {'ok': false, 'error': 'unauthorized: invalid or expired token'}));
      try {
        await repo.me();
        fail('a 401 must throw');
      } on ApiException catch (e) {
        expect(e.message, contains('انتهت صلاحية الجلسة'));
      }
    });
  });

  group('authErrorText', () {
    test('maps the sign-in codes the endpoints return', () {
      expect(authErrorText('invalid_credentials'), contains('كلمة المرور'));
      expect(authErrorText('pending_approval'), contains('قيد المراجعة'));
      expect(authErrorText('email_not_verified'), contains('تأكيد'));
      expect(authErrorText('account_suspended'), contains('موقوف'));
      expect(authErrorText('account_not_approved'), contains('غير معتمد'));
    });

    test('passes an unknown machine code through for the reset screens', () {
      expect(authErrorText('invalid_token'), 'invalid_token');
    });

    test('returns null for unrecognised prose so the caller substitutes Arabic', () {
      expect(authErrorText('invalid email or password'), isNull);
      expect(authErrorText(''), isNull);
    });
  });

  group('the lawyer app controls its own API root', () {
    test('a repository base URL overrides the compiled default', () async {
      Uri? seen;
      final repo = ApiRepository(
        baseUrl: 'https://lawyer.example/api/v1',
        client: ApiClient(
          client: _FakeTransport((r) async {
            seen = r.url;
            return (401, {'ok': false, 'error': 'invalid_credentials'});
          }),
          tokens: _MemoryTokenStore(),
        ),
      );
      try {
        await repo.lawyerLogin(email: 'a@b.com', password: 'x');
      } on ApiException {
        // expected
      }
      expect(seen, isNotNull);
      expect(seen!.host, 'lawyer.example');
      expect(seen!.path, '/api/v1/auth/lawyer-login.php');
    });

    test('setBaseUrl changes the shared default and can be cleared', () {
      final original = AppConfig.baseUrl;
      addTearDown(() => AppConfig.setBaseUrl(null));
      AppConfig.setBaseUrl('https://override.example/api/v1');
      expect(AppConfig.baseUrl, 'https://override.example/api/v1');
      AppConfig.setBaseUrl(null);
      expect(AppConfig.baseUrl, original);
    });
  });
}
