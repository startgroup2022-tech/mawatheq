import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';

/// Execution 66 phase B — the native booking calendar, pricing and the lawyer card.
///
/// The customer app must agree with the website: the same price, the same bookable
/// days, and no fabricated rating. These tests drive the real `ApiRepository` and the
/// real `ConsultationJourneyScreen` against a fake transport that returns the exact
/// bytes `api/v1/journey.php` and `api/available-slots.php` send, so a change to the
/// contract on either side fails here rather than in a customer's hands.
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
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> saveAccountType(String type) async {}
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
  Future<void> clear() async => _token = null;
}

const _specialtiesJson = '{"ok":true,"specialties":['
    '{"id":1,"name":"الأحوال الشخصية","description":"شؤون الأسرة.",'
    '"icon_key":"family","lawyer_count":2}],"ready":true}';

const _issueTypesJson = '{"ok":true,"issue_types":['
    '{"id":1,"name":"إثبات عقد الزواج","definition":"دعوى.","group":"مجموعة"}],'
    '"not_sure":{"id":0,"name":"لست متأكداً"}}';

// The journey response now carries the ONE payable amount (`fee_display`), its length,
// and the pricing object — exactly what api/v1/journey.php builds from the shared
// consultation_pricing module. There is deliberately no rating field in play.
const _matchJson = '{"ok":true,"lawyers":['
    '{"id":1,"full_name":"Firm A Owner","jurisdiction":"السعودية",'
    '"fee_display":"500 ر.س","duration_minutes":30,'
    '"pricing":{"has_price":true,"total":500,"duration_minutes":30},'
    '"years_experience":8,'
    '"availability":{"has_published_hours":true,"slot_count":6}}],'
    '"matched":true,"alternative":null}';

// A month map with two bookable days and the rest closed, as get_month_availability
// would compute from a lawyer who works only two days that month.
String _monthJson(String y, String m, List<String> freeDays) {
  final days = <String, int>{};
  for (var d = 1; d <= 28; d++) {
    final iso = '$y-$m-${d.toString().padLeft(2, '0')}';
    days[iso] = freeDays.contains(iso) ? 6 : 0;
  }
  return jsonEncode({
    'ok': true,
    'month': '$y-$m',
    'days': days,
    'working_weekdays': [0, 1, 2, 3, 4],
    'has_hours': true,
  });
}

ApiRepository _repo(Future<(int, Object)> Function(Uri) handler) => ApiRepository(
      client: ApiClient(
        client: _FakeTransport(handler),
        tokens: _MemoryTokenStore()..saveToken('t'),
      ),
    );

/// The journey handler used by the screen tests: specialties, issue types, one match,
/// a month map, and two slots on any day.
Future<(int, Object)> _journeyHandler(Uri uri) async {
  if (uri.path.endsWith('/journey.php')) {
    switch (uri.queryParameters['action']) {
      case 'specialties':
        return (200, _specialtiesJson);
      case 'issue-types':
        return (200, _issueTypesJson);
      default:
        return (200, _matchJson);
    }
  }
  if (uri.path.endsWith('/available-slots.php')) {
    if (uri.queryParameters.containsKey('month')) {
      final now = DateTime.now();
      // Make the 20th and 25th of the current month bookable.
      final y = now.year.toString();
      final m = now.month.toString().padLeft(2, '0');
      return (200, _monthJson(y, m, ['$y-$m-20', '$y-$m-25']));
    }
    return (200, '{"ok":true,"slots":["10:00","11:00"]}');
  }
  if (uri.path.endsWith('/consultation-attachments.php')) {
    return (200, '{"ok":true,"attachments":[],"limits":{"document_max_mb":10,"max_files":5}}');
  }
  return (200, jsonEncode({'ok': true, 'consultation_id': 20}));
}

void main() {
  setUp(() {
    final view = TestWidgetsFlutterBinding.ensureInitialized().platformDispatcher
        .views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
  });

  tearDown(() {
    final view = TestWidgetsFlutterBinding.ensureInitialized().platformDispatcher
        .views.first;
    view.resetPhysicalSize();
    view.resetDevicePixelRatio();
  });

  group('MonthAvailability reads the month map contract', () {
    test('parses days, working weekdays and has_hours', () {
      final m = MonthAvailability.fromJson(jsonDecode(
          '{"month":"2026-11","days":{"2026-11-20":6,"2026-11-21":0},'
          '"working_weekdays":[0,1,2,3,4],"has_hours":true}') as Map<String, dynamic>);
      expect(m.month, '2026-11');
      expect(m.slotsOn('2026-11-20'), 6);
      expect(m.slotsOn('2026-11-21'), 0);
      expect(m.slotsOn('2026-11-22'), 0, reason: 'a day absent from the map has no slots');
      expect(m.workingWeekdays, [0, 1, 2, 3, 4]);
      expect(m.hasHours, isTrue);
    });

    test('a lawyer with no hours is an honest empty map', () {
      final m = MonthAvailability.fromJson(jsonDecode(
          '{"month":"2026-11","days":{},"working_weekdays":[],"has_hours":false}')
          as Map<String, dynamic>);
      expect(m.hasHours, isFalse);
      expect(m.isEmpty, isTrue);
    });
  });

  group('The repository asks the endpoint for a month', () {
    test('monthAvailability sends lawyer_id and month', () async {
      Uri? seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _monthJson('2026', '11', ['2026-11-20']));
      });
      final m = await repo.monthAvailability(lawyerId: 7, month: '2026-11');
      expect(seen!.queryParameters['lawyer_id'], '7');
      expect(seen!.queryParameters['month'], '2026-11');
      expect(m.slotsOn('2026-11-20'), 6);
    });
  });

  group('LawyerProfile carries the consultation price, not an hourly rate', () {
    test('fee_display and duration_minutes parse', () {
      final p = LawyerProfile.fromJson(jsonDecode(
          '{"id":1,"full_name":"س","fee_display":"500 ر.س","duration_minutes":30,'
          '"pricing":{"total":500}}') as Map<String, dynamic>);
      expect(p.feeDisplay, '500 ر.س');
      expect(p.durationMinutes, 30);
      expect(p.consultationPrice, 500);
    });
  });

  group('The journey screen renders the real calendar and the real price', () {
    testWidgets('the lawyer card shows the consultation price, never a star',
        (tester) async {
      final repo = _repo(_journeyHandler);
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الأحوال الشخصية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();

      // The price the gateway will charge, with its length.
      expect(find.textContaining('500 ر.س'), findsOneWidget);
      expect(find.textContaining('30 دقيقة'), findsWidgets);
      // No star rating anywhere — there is no ratings data behind one.
      expect(find.byIcon(Icons.star), findsNothing);
    });

    testWidgets('the appointment step draws a calendar of real bookable days',
        (tester) async {
      final repo = _repo(_journeyHandler);
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الأحوال الشخصية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Firm A Owner'));
      await tester.pumpAndSettle();

      // The calendar grid is on screen (weekday headers are its signature).
      await tester.scrollUntilVisible(find.text('أحد'), 200,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      expect(find.text('أحد'), findsOneWidget);
      expect(find.text('الأوقات المتاحة'), findsOneWidget);
    });

    testWidgets('choosing a bookable day loads its slots', (tester) async {
      final repo = _repo(_journeyHandler);
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الأحوال الشخصية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Firm A Owner'));
      await tester.pumpAndSettle();

      // The first bookable day (the 20th) is auto-selected, so its slots are shown
      // without any extra tap — the customer is never left on an empty time list.
      await tester.scrollUntilVisible(find.text('10:00'), 200,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      expect(find.text('10:00'), findsOneWidget);
      expect(find.text('11:00'), findsOneWidget);
    });
  });
}
