import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/app.dart';
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/guest_session.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:mawatheq_mobile/features/public/splash_screen.dart';
import 'package:provider/provider.dart';

/// A transport that never answers — the shape of a captive portal, a dead mobile
/// network, or a server that accepts the TCP connection and then stalls.
///
/// The existing startup tests all use a fake that resolves on the next microtask,
/// which is why a hang on the splash was never caught: with an instant answer the
/// `restore()` future completes before anyone could notice it was unbounded.
class _HangingTransport extends http_base.BaseClient {
  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) {
    // Never completes. A real socket would eventually time out at the OS level,
    // which can be minutes.
    return Completer<http_base.StreamedResponse>().future;
  }
}

/// Stalls the startup session check (`/me.php`) and answers everything else
/// immediately.
///
/// This is the exact scenario the splash bug was about — the session check is what
/// gates the splash — and it keeps the test free of unrelated pending timers from
/// the home screen's own requests, so the assertion is about the startup path.
class _StalledSessionTransport extends http_base.BaseClient {
  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) {
    if (request.url.path.endsWith('/me.php')) {
      return Completer<http_base.StreamedResponse>().future;
    }
    return Future.value(http_base.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode({'ok': true, 'banners': [], 'specialties': []}))),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    ));
  }
}

/// Answers `/me.php` with a valid session and everything else with an empty 200.
class _InstantTransport extends http_base.BaseClient {
  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final path = request.url.path;
    final body = path.endsWith('/me.php')
        ? jsonEncode({
            'ok': true,
            'user': {
              'id': 1,
              'full_name': 'عميل اختبار',
              'email': 'client@example.com',
              'type': 'client',
            },
          })
        : jsonEncode({'ok': true});
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(body)),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
  _MemoryTokenStore({this.token});
  String? token;
  String? type;

  @override
  Future<void> saveToken(String value) async => token = value;
  @override
  Future<String?> readToken() async => token;
  @override
  Future<void> saveAccountType(String value) async => type = value;
  @override
  Future<String?> readAccountType() async => type;
  @override
  Future<void> saveDeviceLabel(String label) async {}
  @override
  Future<String?> readDeviceLabel() async => null;
  @override
  Future<void> clear() async => token = null;
}

/// Mirrors `main.dart`'s provider set, so the tree under test is the one that
/// ships. The existing startup tests omit `ApiRepository`; `HomeShell` reads it,
/// which is why a harness that leaves it out cannot reproduce production.
Widget _app({
  required http_base.BaseClient transport,
  required TokenStore tokens,
}) {
  final repository = ApiRepository(
    client: ApiClient(client: transport, tokens: tokens),
  );
  final auth = AuthController(repository: repository, tokens: tokens);
  return MultiProvider(
    providers: [
      Provider<ApiRepository>.value(value: repository),
      Provider<TokenStore>.value(value: tokens),
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
      ChangeNotifierProvider<LocaleController>(create: (_) => LocaleController()),
    ],
    child: const MawatheqApp(),
  );
}

void main() {
  // The Flutter test surface is 800x600, a tablet-ish window that overflows the
  // phone layouts. Set a phone-sized surface for every case in this file.

  group('Execution 47 — the splash must never hang on the network', () {
    testWidgets('a stalled /me.php does not hold the splash past its budget',
        (tester) async {
      tester.view.physicalSize = const Size(1080, 2280);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      // A stored token means restore() calls the server. The session check never
      // answers — the transport stalls exactly like a captive portal.
      await tester.pumpWidget(_app(
        transport: _StalledSessionTransport(),
        tokens: _MemoryTokenStore(token: 'a-valid-looking-token'),
      ));

      // The splash is on screen while the check is in flight — that is correct.
      await tester.pump();
      expect(find.byType(SplashScreen), findsOneWidget);

      // Past the entrance animation (720ms) and the startup budget (5s). The app
      // must have moved on by itself, without any user action.
      await tester.pump(const Duration(seconds: 6));
      await tester.pump();

      expect(
        find.byType(HomeShell),
        findsOneWidget,
        reason: 'a stalled startup request must not leave the user on the splash',
      );
      expect(find.byType(SplashScreen), findsNothing);

      // Drain the underlying request's own 30s timer so the test leaves no
      // pending timer behind; in production it simply resolves unnoticed.
      await tester.pump(const Duration(seconds: 31));
      await tester.pump();
    });

    testWidgets('a first launch with no stored token never waits on the network',
        (tester) async {
      tester.view.physicalSize = const Size(1080, 2280);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(_app(
        transport: _HangingTransport(),
        tokens: _MemoryTokenStore(),
      ));

      // No token → restore() returns without any request, so the splash is
      // bounded by the animation alone.
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 800));
      await tester.pump();

      expect(find.byType(HomeShell), findsOneWidget);
    });

    testWidgets('a healthy startup still lands on the shell', (tester) async {
      tester.view.physicalSize = const Size(1080, 2280);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(_app(
        transport: _InstantTransport(),
        tokens: _MemoryTokenStore(token: 'good'),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 900));
      await tester.pump();
      expect(find.byType(HomeShell), findsOneWidget);
    });

    test('restore() itself is bounded, not only the widget', () async {
      final auth = AuthController(
        repository: ApiRepository(
          client: ApiClient(
            client: _HangingTransport(),
            tokens: _MemoryTokenStore(token: 'x'),
          ),
        ),
        tokens: _MemoryTokenStore(token: 'x'),
      );
      final started = DateTime.now();
      await auth.restore();
      final elapsed = DateTime.now().difference(started);
      expect(auth.status, isNot(AuthStatus.checking));
      expect(
        elapsed.inSeconds,
        lessThan(20),
        reason: 'restore() must give up rather than wait for a stalled socket',
      );
    });
  });
}
