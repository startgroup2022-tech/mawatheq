import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:provider/provider.dart';

import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/guest_session.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/alerts/availability_alerts_screen.dart';
import 'package:mawatheq_mobile/features/favorites/favorites_screen.dart';
import 'package:mawatheq_mobile/features/notifications_settings/notification_settings_screen.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';
import 'package:mawatheq_mobile/features/reviews/reviews_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/lawyer_card.dart';

/// Execution 72 — the customer engagement surfaces in the app.
///
/// Each screen is driven against a fake transport that returns the endpoint's real JSON
/// shape, so the test asserts what the production client parses and renders rather than a
/// hand-built model. A screen that builds its own client could not be tested this way;
/// these screens accept an injected [ApiRepository] for exactly that reason.
void main() {
  testWidgets('the directory sends the filter vocabulary request and shows a lawyer',
      (tester) async {
    final transport = _FakeTransport();
    await tester.pumpWidget(_wrap(child: PublicLawyersScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    expect(transport.requested.any((u) => u.path.endsWith('/journey.php')), isTrue);
    expect(find.text('سارة العتيبي'), findsOneWidget);
  });

  testWidgets('the favorites screen renders the saved lawyers', (tester) async {
    final transport = _FakeTransport();
    await tester.pumpWidget(_wrap(child: FavoritesScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    expect(transport.requested.any((u) => u.path.endsWith('/favorites.php')), isTrue);
    expect(find.text('سارة العتيبي'), findsOneWidget);
    expect(find.byType(LawyerCard), findsOneWidget);
  });

  testWidgets('the favorites screen shows the empty state with none saved', (tester) async {
    final transport = _FakeTransport(favoritesEmpty: true);
    await tester.pumpWidget(_wrap(child: FavoritesScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    expect(find.text('لا يوجد محامون في المفضّلة'), findsOneWidget);
    expect(find.byType(LawyerCard), findsNothing);
  });

  testWidgets('the reviews screen lists the rateable consultation', (tester) async {
    final transport = _FakeTransport();
    await tester.pumpWidget(_wrap(child: ReviewsScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    expect(
      transport.requested.any((u) =>
          u.path.endsWith('/lawyer-reviews.php') && u.queryParameters['reviewable'] == '1'),
      isTrue,
    );
    expect(find.text('محامٍ اختبار'), findsOneWidget);
    expect(find.text('قيّم المحامي'), findsOneWidget);
  });

  testWidgets('the alerts screen lists an active alert with a cancel control', (tester) async {
    final transport = _FakeTransport();
    await tester.pumpWidget(_wrap(child: AvailabilityAlertsScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    expect(find.text('محامٍ اختبار'), findsOneWidget);
    expect(find.text('إلغاء'), findsOneWidget);
  });

  testWidgets('the notification settings render the server channels, in-app locked',
      (tester) async {
    final transport = _FakeTransport();
    await tester.pumpWidget(
        _wrap(child: NotificationSettingsScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    // The three server channels are drawn with their server labels.
    expect(find.text('إشعارات داخل التطبيق'), findsOneWidget);
    expect(find.text('البريد الإلكتروني'), findsOneWidget);
    expect(find.text('الإشعارات الفورية'), findsOneWidget);

    // The in-app switch is disabled (locked), the others are live.
    final switches = tester.widgetList<Switch>(find.byType(Switch)).toList();
    expect(switches.length, 3);
    expect(switches.where((s) => s.onChanged == null).length, 1,
        reason: 'exactly the in-app channel is locked');
  });

  testWidgets('the shared card hides the heart for a guest (no onToggleFavorite)',
      (tester) async {
    await tester.pumpWidget(_wrap(
      child: const Scaffold(
        body: LawyerCard(
          lawyer: LawyerProfile(id: 7, name: 'سارة العتيبي'),
          rtl: true,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('سارة العتيبي'), findsOneWidget);
    expect(find.byIcon(Icons.favorite_border), findsNothing);
    expect(find.byIcon(Icons.favorite), findsNothing);
  });

  testWidgets('the shared card shows the filled heart when favorited', (tester) async {
    var tapped = false;
    await tester.pumpWidget(_wrap(
      child: Scaffold(
        body: LawyerCard(
          lawyer: const LawyerProfile(id: 7, name: 'سارة العتيبي'),
          rtl: true,
          isFavorite: true,
          onToggleFavorite: () => tapped = true,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.favorite), findsOneWidget);
    await tester.tap(find.byIcon(Icons.favorite));
    expect(tapped, isTrue);
  });
}

/// Serves the real endpoint payloads for the Execution 72 surfaces, and records every
/// request so the test can assert the URL the screen actually asked for.
class _FakeTransport extends http_base.BaseClient {
  _FakeTransport({this.favoritesEmpty = false});
  final bool favoritesEmpty;
  final List<Uri> requested = [];

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    requested.add(request.url);
    final uri = request.url;
    Object body;
    if (uri.path.endsWith('/journey.php') && uri.queryParameters['action'] == 'filters') {
      body = {
        'ok': true,
        'countries': [
          {'code': 'SA', 'name': 'السعودية'},
        ],
        'cities': {
          'SA': ['الرياض'],
        },
        'experience': [
          {'key': 'under5', 'label': 'أقل من 5 سنوات'},
        ],
        'specializations': ['تجاري'],
      };
    } else if (uri.path.endsWith('/lawyers.php')) {
      body = {
        'ok': true,
        'lawyers': [_lawyer],
        'count': 1,
      };
    } else if (uri.path.endsWith('/favorites.php')) {
      body = {'ok': true, 'lawyers': favoritesEmpty ? <Object>[] : [_lawyer]};
    } else if (uri.path.endsWith('/lawyer-reviews.php') &&
        uri.queryParameters['reviewable'] == '1') {
      body = {
        'ok': true,
        'consultations': [
          {
            'id': 55,
            'lawyer_id': 7,
            'lawyer_name': 'محامٍ اختبار',
            'topic': 'مراجعة عقد',
            'scheduled_at': '2026-11-01 10:00:00',
          },
        ],
      };
    } else if (uri.path.endsWith('/availability-alerts.php')) {
      body = {
        'ok': true,
        'alerts': [
          {
            'id': 3,
            'lawyer_id': 7,
            'lawyer_name': 'محامٍ اختبار',
            'status': 'active',
          },
        ],
      };
    } else if (uri.path.endsWith('/notification-preferences.php')) {
      body = {
        'ok': true,
        'channels': [
          {
            'key': 'in_app',
            'enabled': true,
            'locked': true,
            'label_ar': 'إشعارات داخل التطبيق',
            'label_en': 'In-app',
          },
          {
            'key': 'email',
            'enabled': true,
            'locked': false,
            'label_ar': 'البريد الإلكتروني',
            'label_en': 'Email',
          },
          {
            'key': 'push',
            'enabled': false,
            'locked': false,
            'label_ar': 'الإشعارات الفورية',
            'label_en': 'Push',
          },
        ],
      };
    } else {
      body = {'ok': true};
    }
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode(body))),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }

  Map<String, Object> get _lawyer => {
        'id': 7,
        'full_name': 'سارة العتيبي',
        'specialization': 'تجاري',
        'jurisdiction': 'السعودية',
        'city': 'الرياض',
        'location': 'الرياض، السعودية',
        'fee_display': '350 ر.س',
        'duration_minutes': 30,
        'hourly_rate': '350.000',
        'mode_chips': ['عن بُعد'],
        'languages': ['العربية'],
      };
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
  Future<void> saveBiometricEnabled(bool enabled) async {}
  @override
  Future<bool> readBiometricEnabled() async => false;
  @override
  Future<void> clear() async {}
}

ApiRepository _repo(_FakeTransport transport) => ApiRepository(
      client: ApiClient(client: transport, tokens: _MemoryTokenStore()),
    );

Widget _wrap({required Widget child}) {
  final auth = AuthController(
    repository: ApiRepository(
      client: ApiClient(client: _FakeTransport(), tokens: _MemoryTokenStore()),
    ),
    tokens: _MemoryTokenStore(),
  )..enterGuest();
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthController>.value(value: auth),
      ChangeNotifierProvider<GuestSession>.value(value: GuestSession()),
      ChangeNotifierProvider<LocaleController>.value(value: LocaleController()),
    ],
    child: MaterialApp(home: child),
  );
}
