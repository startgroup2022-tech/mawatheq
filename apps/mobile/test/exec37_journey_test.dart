import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';

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

  group('Journey attachments: staging, limits and the booking hand-off', () {
    test('a staged attachment parses the server-classified category', () {
      final a = StagedAttachment.fromJson(jsonDecode(
          '{"id":31,"name":"تسجيل.m4a","category":"audio","extension":"m4a",'
          '"size":2048,"size_label":"2 KB","status":"staged"}') as Map<String, dynamic>);
      expect(a.id, 31);
      expect(a.originalName, 'تسجيل.m4a');
      // The category comes from the server's byte-sniffing, not the filename.
      expect(a.isAudio, isTrue);
      expect(a.isVideo, isFalse);
      expect(a.sizeLabel, '2 KB');
      expect(a.status, 'staged');
    });

    test('the staged list carries the server limits, including the file ceiling',
        () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (
          200,
          '{"ok":true,"attachments":[{"id":1,"name":"a.pdf","category":"document",'
          '"extension":"pdf","size":10,"size_label":"10 B","status":"staged"}],'
          '"limits":{"document_mb":10,"audio_mb":50,"video_mb":100,"max_files":5,'
          '"allowed_extensions":["pdf","png","jpg","jpeg","mp3","m4a"],'
          '"audio_extensions":["mp3","m4a"],"image_extensions":["png","jpg","jpeg"]}}'
        );
      });
      final staged = await repo.journeyStagedAttachments(draftToken: 'a' * 64);
      expect(seen.path, endsWith('/consultation-attachments.php'));
      expect(seen.queryParameters['draft_token'], 'a' * 64);
      expect(staged.attachments.single.originalName, 'a.pdf');
      expect(staged.maxFiles, 5);
      expect(staged.audioMb, 50);
      expect(staged.atLimit, isFalse);
      // The picker must offer exactly what the server accepts.
      expect(staged.allowedExtensions.contains('pdf'), isTrue);
      expect(staged.allowedExtensions.contains('mp3'), isTrue);
      expect(staged.allowedExtensions.contains('exe'), isFalse);
      // Per-category ceilings, with images riding the document limit.
      expect(staged.limitMbFor('audio'), 50);
      expect(staged.limitMbFor('video'), 100);
      expect(staged.limitMbFor('image'), 10);
      expect(staged.limitMbFor('document'), 10);
    });

    test('atLimit is true once the server maximum is reached', () {
      final staged = JourneyStagedAttachments.fromJson(jsonDecode(
          '{"ok":true,"attachments":[{"id":1,"name":"a.pdf","category":"document"},'
          '{"id":2,"name":"b.pdf","category":"document"}],'
          '"limits":{"max_files":2}}') as Map<String, dynamic>);
      expect(staged.atLimit, isTrue);
    });

    test('booking carries the draft token so staged files are bound', () async {
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
        topic: 'تفاصيل',
        specialtyCategoryId: 1,
        issueTypeId: 5,
        draftToken: 'b' * 64,
      );
      expect(recording.lastBody.contains('draft_token'), isTrue);
      expect(recording.lastBody.contains('b' * 64), isTrue);
      // The customer's app must still never name the internal classification.
      expect(recording.lastBody.contains('najiz'), isFalse);
    });

    test('a booking with no attachments sends no draft token', () async {
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
      expect(recording.lastBody.contains('draft_token'), isFalse);
    });

    test('a staged upload posts the file as "file" with the draft token', () async {
      final recording = _RecordingTransport();
      final repo = ApiRepository(
        client: ApiClient(
          client: recording,
          tokens: _MemoryTokenStore()..saveToken('t'),
        ),
      );
      final file = File('${Directory.systemTemp.path}/exec37_stage_probe.pdf')
        ..writeAsBytesSync(utf8.encode('%PDF-1.4 probe'));
      addTearDown(() => file.existsSync() ? file.deleteSync() : null);

      await repo.uploadJourneyAttachment(file: file, draftToken: 'c' * 64);

      // The multipart body names the field the endpoint reads and carries the token.
      expect(recording.lastBody.contains('name="file"'), isTrue);
      expect(recording.lastBody.contains('c' * 64), isTrue);
      expect(recording.lastUrl.path, endsWith('/consultation-attachments.php'));
    });

    test('removing a staged attachment deletes it by id', () async {
      final recording = _RecordingTransport();
      final repo = ApiRepository(
        client: ApiClient(
          client: recording,
          tokens: _MemoryTokenStore()..saveToken('t'),
        ),
      );
      await repo.deleteJourneyAttachment(31);
      expect(recording.lastMethod, 'DELETE');
      expect(recording.lastUrl.queryParameters['id'], '31');
    });

    test('a consultation reads back the files attached to it', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (
          200,
          '{"ok":true,"attachments":[{"id":77,"name":"تسجيل.m4a","category":"audio",'
          '"extension":"m4a","size":4096,"size_label":"4 KB","status":"stored"}],'
          '"can_upload":true,"limits":{"document_mb":10,"max_files":5}}'
        );
      });
      final list = await repo.consultationAttachments(20);
      expect(seen.path, endsWith('/consultation-attachments.php'));
      expect(seen.queryParameters['consultation_id'], '20');
      expect(list.single.id, 77);
      expect(list.single.isAudio, isTrue);
      expect(list.single.status, 'stored');
    });

    test('the consultation list surfaces the attachment count', () {
      final c = Consultation.fromJson(jsonDecode(
          '{"id":20,"status":"confirmed","consultation_type":"voice",'
          '"topic":"استشارة","attachment_count":3}') as Map<String, dynamic>);
      expect(c.attachmentCount, 3);
    });

    test('a consultation with no attachments counts zero', () {
      final c = Consultation.fromJson(jsonDecode(
          '{"id":21,"status":"pending"}') as Map<String, dynamic>);
      expect(c.attachmentCount, 0);
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

    testWidgets('an empty submission is refused before it reaches the server',
        (tester) async {
      final recording = _RecordingTransport();
      final repo = ApiRepository(
        client: ApiClient(client: recording, tokens: _MemoryTokenStore()..saveToken('t')),
      );
      await tester.pumpWidget(
        MaterialApp(home: ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      // Walk the real journey: specialty → issue type → lawyer → booking form.
      await tester.tap(find.text('الأحوال الشخصية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Firm A Owner'));
      await tester.pumpAndSettle();

      expect(find.widgetWithText(PrimaryButton, 'تأكيد الحجز'), findsOneWidget);
      await tester.tap(find.text('10:00'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(PrimaryButton, 'تأكيد الحجز'));
      await tester.pumpAndSettle();

      expect(find.text('اكتب وصفاً للاستشارة أو أرفق ملفاً واحداً على الأقل.'),
          findsOneWidget);
      expect(recording.lastUrl.path, isNot(endsWith('/consultations.php')),
          reason: 'the booking must not be sent with neither a description nor a file');
    });
  });
}

/// Reads the whole request body so the booking test can assert what was sent, and
/// answers with the same fixtures the other tests use so a real journey can be walked.
class _RecordingTransport extends http_base.BaseClient {
  String lastBody = '';
  String lastMethod = '';
  Uri lastUrl = Uri();

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    lastMethod = request.method;
    lastUrl = request.url;
    final chunks = await request.finalize().toList();
    lastBody = utf8.decode(chunks.expand((c) => c).toList(), allowMalformed: true);

    final String body;
    if (request.url.path.endsWith('/journey.php')) {
      switch (request.url.queryParameters['action']) {
        case 'specialties':
          body = _specialtiesJson;
        case 'issue-types':
          body = _issueTypesJson;
        default:
          body = _matchJson;
      }
    } else if (request.url.path.endsWith('/available-slots.php')) {
      body = '{"ok":true,"slots":["10:00","11:00"]}';
    } else if (request.url.path.endsWith('/consultation-attachments.php')) {
      body = '{"ok":true,"attachments":[],"limits":{"document_mb":10,"max_files":5}}';
    } else {
      body = jsonEncode({'ok': true, 'consultation_id': 20});
    }

    return http_base.StreamedResponse(
      Stream.value(utf8.encode(body)),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}
