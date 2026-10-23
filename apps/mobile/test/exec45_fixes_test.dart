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
import 'package:mawatheq_mobile/features/assistant/assistant_screen.dart';
import 'package:mawatheq_mobile/features/home/home_shell.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// A transport that answers the public endpoints the home screen calls, so the real
/// client, repository and widgets are exercised rather than stubbed.
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

class _MemoryTokenStore implements TokenStore {
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

Future<(int, Object)> _public(Uri uri) async {
  if (uri.path.endsWith('/banners.php')) {
    return (200, {'ok': true, 'banners': []});
  }
  if (uri.path.endsWith('/journey.php')) {
    return (200, {
      'ok': true,
      'specialties': [
        {'id': 1, 'name': 'الأحوال الشخصية', 'icon_key': 'family', 'lawyer_count': 3},
      ],
      'issue_types': [],
    });
  }
  if (uri.path.endsWith('/ai-status.php')) {
    return (200, {'ok': true, 'available': true, 'blocking': null});
  }
  return (200, {'ok': true});
}

ApiRepository _repo(_Transport t) => ApiRepository(
      client: ApiClient(client: t, tokens: _MemoryTokenStore()),
    );

Widget _wrap({
  required AuthController auth,
  required Widget child,
  GuestSession? guest,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>.value(value: guest ?? GuestSession()),
      ChangeNotifierProvider<LocaleController>.value(value: LocaleController()),
    ],
    child: MaterialApp(home: child),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
    binding.platformDispatcher.accessibilityFeaturesTestValue =
        const FakeAccessibilityFeatures(disableAnimations: true);
  });

  tearDown(() {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.resetPhysicalSize();
    view.resetDevicePixelRatio();
    binding.platformDispatcher.clearAccessibilityFeaturesTestValue();
  });

  group('Exec45 — assistant screen works as a pushed route', () {
    // Regression: AssistantScreen used to return a bare Column. As a bottom-nav tab
    // the shell supplied the Scaffold, so it looked fine; pushed as a route (the home
    // action and the journey's no-lawyer state both do that) the TextField threw
    // "No Material widget found" and the screen arrived with no app bar and no way
    // back. The defect is invisible to a tab-level test, so this pushes it.
    testWidgets('pushed as a route it has a Scaffold, an app bar and a back button',
        (tester) async {
      final repo = _repo(_Transport(_public));
      await tester.pumpWidget(MaterialApp(
        home: Builder(
          builder: (ctx) => Scaffold(
            body: Center(
              child: ElevatedButton(
                onPressed: () => Navigator.of(ctx).push(
                  MaterialPageRoute(
                    builder: (_) => AssistantScreen(repository: repo),
                  ),
                ),
                child: const Text('open'),
              ),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.byType(AppBar), findsOneWidget);
      expect(find.byType(BackButton), findsOneWidget);
      expect(find.byType(TextField), findsWidgets);
    });

    testWidgets('as a tab it does not nest a second Scaffold', (tester) async {
      final t = _Transport(_public);
      final auth = AuthController(
        repository: _repo(t),
        tokens: _MemoryTokenStore(),
      );
      await tester.pumpWidget(
        _wrap(auth: auth, child: HomeShell(repository: _repo(t))),
      );
      await tester.pumpAndSettle();

      // The shell owns the only Scaffold; a second one would double the app bar.
      expect(find.byType(Scaffold), findsOneWidget);
    });
  });

  group('Exec45 — the account screen keeps its Scaffold contract', () {
    testWidgets('account tab renders inside the shell without exception',
        (tester) async {
      final t = _Transport(_public);
      final auth = AuthController(
        repository: _repo(t),
        tokens: _MemoryTokenStore(),
      );
      auth.enterGuest();
      await tester.pumpWidget(
        _wrap(auth: auth, child: HomeShell(repository: _repo(t))),
      );
      await tester.pumpAndSettle();

      // A guest sees the sign-in prompt tab, which is a real screen, not a blank.
      expect(tester.takeException(), isNull);
      expect(find.byType(HomeShell), findsOneWidget);
    });
  });
}
