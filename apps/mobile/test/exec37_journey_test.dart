import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';

/// Execution 37 — the mobile consultation journey.
///
/// The journey calls the same `/api/v1/journey.php` endpoints the website does, so
/// these tests pin the contract from the client side: the customer sees the
/// plain-language specialty and issue type, and the internal legal classification
/// never appears in the model or on the screen. The fake transport returns the real
/// response bytes, and the production client/repository/screen handle them.
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
  String? _type;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> saveAccountType(String type) async => _type = type;

  @override
  Future<String?> readAccountType() async => _type;

  @override
  Future<void> saveDeviceLabel(String label) async {}

  @override
  Future<String?> readDeviceLabel() async => null;

  @override
  Future<void> clear() async {
    _token = null;
    _type = null;
  }
}

/// Byte-for-byte what api/v1/journey.php returns for the seeded specialties.
const _specialtiesJson = '{"ok":true,"specialties":['
    '{"id":1,"name":"الأحوال الشخصية","description":"شؤون الأسرة: الزواج والطلاق والنفقة.",'
    '"icon_key":"family","lawyer_count":2},'
    '{"id":4,"name":"الدعاوى الجزائية","description":"القضايا الجزائية.",'
    '"icon_key":"criminal","lawyer_count":0}],"ready":true}';

const _issueTypesJson = '{"ok":true,"issue_types":['
    '{"id":1,"name":"إثبات عقد الزواج","definition":"دعوى لإثبات الزواج غير الموثق.",'
    '"group":"دعاوى الزواج والفرقة"}],'
    '"not_sure":{"id":0,"name":"لست متأكداً"}}';

const _matchJson = '{"ok":true,"lawyers":['
    '{"id":1,"full_name":"Firm A Owner","rating":5,"jurisdiction":"السعودية"}],'
    '"matched":true,"alternative":null}';

const _emptyMatchJson = '{"ok":true,"lawyers":[],"matched":false,'
    '"alternative":"لا يوجد محامٍ متاح في هذا التخصص حالياً، ويمكننا ترشيح محامٍ لك."}';

ApiRepository _repo(Future<(int, Object)> Function(Uri) handler) => ApiRepository(
      client: ApiClient(
        client: _FakeTransport(handler),
        tokens: _MemoryTokenStore()..saveToken('t'),
      ),
    );

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

  group('Journey models read the journey.php contract', () {
    test('a specialty parses name, description, icon and count', () {
      final s = JourneySpecialty.fromJson(jsonDecode(
          '{"id":1,"name":"الأحوال الشخصية","description":"شؤون الأسرة.",'
          '"icon_key":"family","lawyer_count":2}') as Map<String, dynamic>);
      expect(s.id, 1);
      expect(s.name, 'الأحوال الشخصية');
      expect(s.description, 'شؤون الأسرة.');
      expect(s.iconKey, 'family');
      expect(s.lawyerCount, 2);
      expect(s.hasLawyers, isTrue);
    });

    test('an unstaffed specialty is still shown, and says so', () {
      final s = JourneySpecialty.fromJson(jsonDecode(
          '{"id":4,"name":"الدعاوى الجزائية","lawyer_count":0}') as Map<String, dynamic>);
      expect(s.hasLawyers, isFalse);
      expect(s.name, 'الدعاوى الجزائية');
    });

    test('an issue type parses its name, definition and group', () {
      final t = JourneyIssueType.fromJson(jsonDecode(
          '{"id":1,"name":"إثبات عقد الزواج","definition":"دعوى لإثبات الزواج.",'
          '"group":"دعاوى الزواج والفرقة"}') as Map<String, dynamic>);
      expect(t.name, 'إثبات عقد الزواج');
      expect(t.definition, 'دعوى لإثبات الزواج.');
      expect(t.group, 'دعاوى الزواج والفرقة');
    });

    test('the journey models expose no internal classification field', () {
      // A response that DOES carry the internal id must not surface it in the model:
      // the field simply has no home in the customer's model.
      final s = JourneySpecialty.fromJson(jsonDecode(
          '{"id":1,"name":"الأحوال الشخصية","najiz_category_id":7,'
          '"najiz_case_type_id":9}') as Map<String, dynamic>);
      expect(s.name, 'الأحوال الشخصية');
      expect(s.toString().contains('najiz'), isFalse);
    });
  });

  group('Repository journey methods call the right endpoints', () {
    test('journeySpecialties hits journey.php?action=specialties', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _specialtiesJson);
      });
      final list = await repo.journeySpecialties();
      expect(seen.path, endsWith('/journey.php'));
      expect(seen.queryParameters['action'], 'specialties');
      expect(list.length, 2);
      expect(list.first.name, 'الأحوال الشخصية');
    });

    test('journeyIssueTypes sends the chosen category_id', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _issueTypesJson);
      });
      final list = await repo.journeyIssueTypes(categoryId: 1);
      expect(seen.queryParameters['action'], 'issue-types');
      expect(seen.queryParameters['category_id'], '1');
      expect(list.single.name, 'إثبات عقد الزواج');
    });

    test('journeyLawyers sends the category_id and parses the match', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _matchJson);
      });
      final match = await repo.journeyLawyers(categoryId: 1);
      expect(seen.queryParameters['action'], 'lawyers');
      expect(seen.queryParameters['category_id'], '1');
      expect(match.matched, isTrue);
      expect(match.lawyers.single.name, 'Firm A Owner');
    });

    test('an unstaffed specialty surfaces the alternative sentence', () async {
      final repo = _repo((_) async => (200, _emptyMatchJson));
      final match = await repo.journeyLawyers(categoryId: 4);
      expect(match.matched, isFalse);
      expect(match.lawyers, isEmpty);
      expect(match.alternative, contains('ترشيح محامٍ'));
    });

    test('booking sends the journey context under customer-safe names', () async {
      final recording = _RecordingTransport();
      final repo = ApiRepository(
        client: ApiClient(
          client: recording,
          tokens: _MemoryTokenStore()..saveToken('t'),
        ),
      );
      final id = await repo.bookConsultation(
        lawyerId: 1,
        consultationType: 'text',
        date: '2026-11-01',
        time: '10:00',
        specialtyCategoryId: 1,
        issueTypeId: 5,
      );
      expect(id, 20);
      final sent = recording.lastBody;
      expect(sent.contains('specialty_category_id'), isTrue);
      expect(sent.contains('issue_type_id'), isTrue);
      // The internal name must never be sent from the customer's app.
      expect(sent.contains('najiz_case_type_id'), isFalse);
    });

    test('booking omits the journey fields when the journey was skipped', () async {
      final recording = _RecordingTransport();
      final repo = ApiRepository(
        client: ApiClient(
          client: recording,
          tokens: _MemoryTokenStore()..saveToken('t'),
        ),
      );
      await repo.bookConsultation(
        lawyerId: 1,
        consultationType: 'text',
        date: '2026-11-01',
        time: '10:00',
      );
      expect(recording.lastBody.contains('specialty_category_id'), isFalse);
      expect(recording.lastBody.contains('issue_type_id'), isFalse);
    });
  });

  group('The journey screen renders the three steps', () {
    testWidgets('step 1 shows the specialty cards from the API', (tester) async {
      final repo = _repo((uri) async {
        expect(uri.queryParameters['action'], 'specialties');
        return (200, _specialtiesJson);
      });
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      expect(find.text('ما نوع مشكلتك القانونية؟'), findsOneWidget);
      expect(find.text('الأحوال الشخصية'), findsOneWidget);
      expect(find.text('الدعاوى الجزائية'), findsOneWidget);
      // The unstaffed specialty is still offered.
      expect(find.text('سيتم ترشيح محامٍ لك'), findsOneWidget);
      expect(find.text('2 محامٍ متاح'), findsOneWidget);
    });

    testWidgets('choosing a specialty loads step 2 issue types', (tester) async {
      final repo = _repo((uri) async {
        if (uri.queryParameters['action'] == 'specialties') {
          return (200, _specialtiesJson);
        }
        expect(uri.queryParameters['action'], 'issue-types');
        return (200, _issueTypesJson);
      });
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الأحوال الشخصية'));
      await tester.pumpAndSettle();

      expect(find.text('حدّد نوع المشكلة'), findsOneWidget);
      expect(find.text('إثبات عقد الزواج'), findsOneWidget);
      expect(find.text('لست متأكداً من نوع المشكلة'), findsOneWidget);
    });

    testWidgets('step 3 lists the matched lawyers', (tester) async {
      final repo = _repo((uri) async {
        switch (uri.queryParameters['action']) {
          case 'specialties':
            return (200, _specialtiesJson);
          case 'issue-types':
            return (200, _issueTypesJson);
          default:
            return (200, _matchJson);
        }
      });
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الأحوال الشخصية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();

      expect(find.text('اختر محاميك'), findsOneWidget);
      expect(find.text('Firm A Owner'), findsOneWidget);
    });

    testWidgets('a specialty with no lawyers shows the alternative, not a dead end',
        (tester) async {
      final repo = _repo((uri) async {
        switch (uri.queryParameters['action']) {
          case 'specialties':
            return (200, _specialtiesJson);
          case 'issue-types':
            return (200, _issueTypesJson);
          default:
            return (200, _emptyMatchJson);
        }
      });
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الدعاوى الجزائية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();

      expect(find.text('لا يوجد محامٍ متاح حالياً'), findsOneWidget);
      expect(find.textContaining('ترشيح محامٍ'), findsOneWidget);
    });
  });
}

/// Reads the whole request body so the booking test can assert what was sent.
class _RecordingTransport extends http_base.BaseClient {
  String lastBody = '';

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final chunks = await request.finalize().toList();
    lastBody = utf8.decode(chunks.expand((c) => c).toList());
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode({'ok': true, 'consultation_id': 20}))),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}
