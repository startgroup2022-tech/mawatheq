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
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';

/// Execution 67 — the public directory's professional filters.
///
/// The screen must send the same filter query the website's consultations page and the
/// app's journey send, so the same choices return the same lawyers everywhere. These
/// tests drive the real [PublicLawyersScreen] against a fake transport that records the
/// request URL, so the query it actually sends is asserted rather than assumed.
void main() {
  testWidgets('the directory requests the server-side filter vocabulary',
      (tester) async {
    final transport = _RecordingTransport();
    await tester.pumpWidget(_wrap(child: PublicLawyersScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    // The filter sheet's options come from the server; the screen asks for them.
    expect(transport.requested.any((u) => u.path.endsWith('/journey.php')), isTrue,
        reason: 'the screen loads its filter vocabulary from journey.php');
  });

  testWidgets('applying a country filter re-queries with country=SA',
      (tester) async {
    final transport = _RecordingTransport();
    await tester.pumpWidget(_wrap(child: PublicLawyersScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    transport.requested.clear();
    await tester.tap(find.text('الفلاتر'));
    await tester.pumpAndSettle();

    // Choose the country in the sheet, then apply. The sheet lists specialization,
    // country, city, experience; the country dropdown is the second FormField.
    await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
    await tester.pumpAndSettle();
    await tester.tap(find.text('السعودية').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('تطبيق الفلاتر'));
    await tester.pumpAndSettle();

    final lawyerCalls = transport.requested
        .where((u) => u.path.endsWith('/lawyers.php'))
        .toList();
    expect(lawyerCalls.isNotEmpty, isTrue);
    expect(lawyerCalls.last.queryParameters['country'], 'SA');
  });

  testWidgets('the sheet offers the experience bands and the specialization list',
      (tester) async {
    final transport = _RecordingTransport();
    await tester.pumpWidget(_wrap(child: PublicLawyersScreen(repository: _repo(transport))));
    await tester.pumpAndSettle();

    await tester.tap(find.text('الفلاتر'));
    await tester.pumpAndSettle();

    expect(find.text('سنوات الخبرة'), findsOneWidget);
    expect(find.text('التخصص'), findsOneWidget);
    expect(find.text('المدينة'), findsOneWidget);
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
          {'code': 'AE', 'name': 'الإمارات'},
        ],
        'cities': {
          'SA': ['الرياض', 'جدة'],
          'AE': ['دبي'],
        },
        'experience': [
          {'key': 'under5', 'label': 'أقل من 5 سنوات'},
          {'key': 'over20', 'label': 'أكثر من 20 سنة'},
        ],
        'specializations': ['تجاري', 'جنائي'],
      };
    } else if (uri.path.endsWith('/lawyers.php')) {
      body = {
        'ok': true,
        'lawyers': [
          {
            'id': 7,
            'full_name': 'سارة العتيبي',
            'specialization': 'تجاري',
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
