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
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';

/// Execution 68 — a lawyer may practise more than one area of law.
///
/// The website and the REST API both return the full `specializations` array, and keep
/// the legacy single `specialization` for callers written against the old field. The app
/// must show the whole set on a card and still search across it, while never losing the
/// single value on a response that predates the array.
void main() {
  group('LawyerProfile specializations', () {
    test('reads the full array and joins it for display', () {
      final p = LawyerProfile.fromJson({
        'id': 1,
        'full_name': 'سارة العتيبي',
        'specialization': 'قانون تجاري',
        'specializations': ['قانون تجاري', 'قانون جنائي', 'قانون العمل'],
      });
      expect(p.specializations, ['قانون تجاري', 'قانون جنائي', 'قانون العمل']);
      expect(p.specializationLabel, 'قانون تجاري · قانون جنائي · قانون العمل');
    });

    test('falls back to the single value when the array is absent', () {
      final p = LawyerProfile.fromJson({
        'id': 2,
        'full_name': 'خالد الحربي',
        'specialization': 'قانون جنائي',
      });
      expect(p.specializations, isEmpty);
      expect(p.specializationLabel, 'قانون جنائي');
    });

    test('an empty array falls back to the single value', () {
      final p = LawyerProfile.fromJson({
        'id': 3,
        'full_name': 'منى',
        'specialization': 'قانون العمل',
        'specializations': <String>[],
      });
      expect(p.specializationLabel, 'قانون العمل');
    });

    test('a non-list specializations value degrades to the single value', () {
      final p = LawyerProfile.fromJson({
        'id': 4,
        'full_name': 'فهد',
        'specialization': 'قانون تجاري',
        'specializations': 'قانون تجاري - قانون جنائي',
      });
      expect(p.specializations, isEmpty);
      expect(p.specializationLabel, 'قانون تجاري');
    });
  });

  testWidgets('the directory card shows every declared specialization',
      (tester) async {
    final transport = _RecordingTransport();
    await tester.pumpWidget(
        _wrap(child: PublicLawyersScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    expect(find.text('قانون تجاري · قانون جنائي'), findsOneWidget);
  });

  testWidgets('the name search matches a secondary specialization',
      (tester) async {
    final transport = _RecordingTransport();
    await tester.pumpWidget(
        _wrap(child: PublicLawyersScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    // The card advertises both areas; typing the second one must keep the lawyer
    // visible, which only works if the filter searches the joined label.
    await tester.enterText(find.byType(TextField).first, 'جنائي');
    await tester.pumpAndSettle();
    expect(find.text('سارة العتيبي'), findsOneWidget);
  });
}

class _RecordingTransport extends http_base.BaseClient {
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
        'specializations': ['قانون تجاري', 'قانون جنائي'],
      };
    } else if (uri.path.endsWith('/lawyers.php')) {
      body = {
        'ok': true,
        'lawyers': [
          {
            'id': 7,
            'full_name': 'سارة العتيبي',
            'specialization': 'قانون تجاري',
            'specializations': ['قانون تجاري', 'قانون جنائي'],
            'jurisdiction': 'السعودية',
            'city': 'الرياض',
            'location': 'الرياض، السعودية',
            'fee_display': '350 ر.س',
            'duration_minutes': 30,
            'hourly_rate': '350.000',
          },
        ],
        'count': 1,
      };
    } else {
      body = {'ok': true};
    }
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(body is String ? body : jsonEncode(body))),
      200,
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
  Future<void> saveBiometricEnabled(bool enabled) async {}
  @override
  Future<bool> readBiometricEnabled() async => false;
  @override
  Future<void> clear() async {}
}

ApiRepository _repo(_RecordingTransport transport) => ApiRepository(
      client: ApiClient(client: transport, tokens: _MemoryTokenStore()),
    );

Widget _wrap({required Widget child}) {
  final auth = AuthController(
    repository: ApiRepository(
      client: ApiClient(client: _RecordingTransport(), tokens: _MemoryTokenStore()),
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
