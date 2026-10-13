import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_exception.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/documents/documents_screen.dart';
import 'package:mawatheq_mobile/features/public/pricing_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';
import 'package:provider/provider.dart';

/// Tests the real parsing and rendering path — a fake transport returns the same
/// JSON and status codes the PHP endpoints return, and the production
/// client/repository/screen handle them. Nothing here mocks the code under test.
class _FakeTransport extends http_base.BaseClient {
  _FakeTransport(this.handler);

  /// Returns `(statusCode, body)`. A body of `int` means "no body, just status".
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

/// In-memory [TokenStore] for tests. The production store talks to the platform
/// keychain, which is not available inside a unit-test process.
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

/// A transport that reads the whole request body — including a multipart body — and
/// answers with a small success payload, so the upload tests exercise the client's
/// real encoding rather than a stub of it.
class _RecordingTransport extends http_base.BaseClient {
  Uint8List lastBody = Uint8List(0);
  late Uri lastUrl;
  late String lastMethod;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    lastUrl = request.url;
    lastMethod = request.method;
    final chunks = await request.finalize().toList();
    lastBody = Uint8List.fromList(chunks.expand((c) => c).toList());
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode({'ok': true, 'document': {'id': 9}}))),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

void main() {
  // The default test surface (800x600) is shorter than any phone the app runs
  // on, so list screens legitimately overflow it. Use a realistic phone size.
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

  group('SessionUser reads the me.php contract', () {
    // Byte-for-byte what api/v1/me.php returned for the seeded firm staff account.
    const staffMe =
        '{"ok":true,"type":"lawyer","profile":{"id":4,"full_name":"موظف المكتب",'
        '"email":"staff@example.com","phone":"+966500000006",'
        '"specialization":"عمالي","years_experience":0,"hourly_rate":null,'
        '"rating":"5.0","bio":null,"photo_path":null,"jurisdiction":"السعودية"},'
        '"role":"staff","firm_id":1,"firm_name":"مكتب الاختبار",'
        '"permissions":["firm.view","clients.view","cases.view","documents.view",'
        '"documents.upload","documents.download","evidence.view","evidence.upload",'
        '"evidence.download","consultations.view","calendar.manage"]}';

    test('reads role, firm and permissions from the top level, not profile', () {
      final user =
          SessionUser.fromJson(jsonDecode(staffMe) as Map<String, dynamic>);
      expect(user.type, 'lawyer');
      expect(user.id, 4);
      expect(user.name, 'موظف المكتب');
      // These three sit beside `profile`, which is what the app used to miss.
      expect(user.role, 'staff');
      expect(user.firmId, 1);
      expect(user.firmName, 'مكتب الاختبار');
      expect(user.isFirmMember, isTrue);
      expect(user.can('documents.upload'), isTrue);
      expect(user.can('billing.manage'), isFalse);
    });

    test('a client has no firm and no permissions', () {
      final user = SessionUser.fromJson(jsonDecode(
        '{"ok":true,"type":"client","profile":{"id":1,"full_name":"عميل"},'
        '"role":"client","firm_id":null,"firm_name":null,"permissions":[]}',
      ) as Map<String, dynamic>);
      expect(user.isClient, isTrue);
      expect(user.firmId, isNull);
      expect(user.isFirmMember, isFalse);
      expect(user.can('documents.upload'), isFalse);
    });

    test('a solo lawyer with a null firm_id is not a firm member', () {
      final user = SessionUser.fromJson(jsonDecode(
        '{"ok":true,"type":"lawyer","profile":{"id":1,"full_name":"محامٍ"},'
        '"role":"solo","firm_id":null,"firm_name":null,'
        '"permissions":["cases.view","documents.view","documents.upload"]}',
      ) as Map<String, dynamic>);
      expect(user.isLawyer, isTrue);
      expect(user.firmId, isNull);
      expect(user.can('documents.upload'), isTrue);
    });

    test('the repository surfaces the parsed user through me()', () async {
      final repo = ApiRepository(
        client: ApiClient(
          client: _FakeTransport((_) async => (200, staffMe)),
          tokens: _MemoryTokenStore()..saveToken('t'),
        ),
      );
      final user = await repo.me();
      expect(user.role, 'staff');
      expect(user.firmId, 1);
      expect(user.permissions, contains('documents.upload'));
    });
  });

  group('uploadDocument builds the multipart the server expects', () {
    late Directory tmp;

    setUp(() async {
      tmp = await Directory.systemTemp.createTemp('mawatheq_upload');
      await File('${tmp.path}/contract.pdf')
          .writeAsBytes(utf8.encode('%PDF-1.4 body for a multipart assertion'));
    });

    tearDown(() async => tmp.delete(recursive: true));

    ApiRepository repoOn(_RecordingTransport transport) => ApiRepository(
          client: ApiClient(
            client: transport,
            tokens: _MemoryTokenStore()..saveToken('t'),
          ),
        );

    test('sends ai_consent=1, the document type and the chosen case_id', () async {
      final transport = _RecordingTransport();
      final result = await repoOn(transport).uploadDocument(
        file: File('${tmp.path}/contract.pdf'),
        aiConsent: true,
        documentType: 'contract',
        caseId: '7',
      );

      expect(result['ok'], isTrue);
      expect(transport.lastMethod, 'POST');
      expect(transport.lastUrl.path, endsWith('/documents.php'));

      final body = utf8.decode(transport.lastBody);
      expect(body, contains('name="ai_consent"'));
      expect(body, contains('name="document_type"'));
      expect(body, contains('contract'));
      expect(body, contains('name="case_id"'));
      expect(body, contains('filename="contract.pdf"'));
      expect(body, contains('application/pdf'));
    });

    test('omits case_id when none is supplied, as a client upload does', () async {
      final transport = _RecordingTransport();
      await repoOn(transport).uploadDocument(
        file: File('${tmp.path}/contract.pdf'),
        aiConsent: true,
      );
      final body = utf8.decode(transport.lastBody);
      expect(body, contains('name="ai_consent"'));
      expect(body, isNot(contains('name="case_id"')));
    });

    test('sends ai_consent=0 when the user declines', () async {
      final transport = _RecordingTransport();
      await repoOn(transport).uploadDocument(
        file: File('${tmp.path}/contract.pdf'),
        aiConsent: false,
      );
      final body = utf8.decode(transport.lastBody);
      expect(body, contains('name="ai_consent"\r\n\r\n0'));
    });
  });

  group('DocumentsScreen shows upload only to a capable account', () {
    // The session is established through the real restore() path: AuthController
    // calls me(), the transport answers with the given payload, and the screen
    // reads the resulting user. No test-only setter on AuthController.
    Future<void> pumpSignedIn(WidgetTester tester, String meJson) async {
      final transport = _FakeTransport((uri) async {
        if (uri.path.endsWith('/me.php')) return (200, meJson);
        return (200, {'ok': true, 'documents': []});
      });
      final repo = ApiRepository(
        client: ApiClient(
          client: transport,
          tokens: _MemoryTokenStore()..saveToken('t'),
        ),
      );
      final auth = AuthController(
        repository: repo,
        tokens: _MemoryTokenStore()..saveToken('t'),
      );
      await auth.restore();

      await tester.pumpWidget(
        ChangeNotifierProvider<AuthController>.value(
          value: auth,
          child: MaterialApp(
            // DocumentsScreen is a tab body; HomeShell supplies the Scaffold.
            home: Scaffold(body: DocumentsScreen(repository: repo)),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('a client sees the upload button', (tester) async {
      await pumpSignedIn(
        tester,
        '{"ok":true,"type":"client","profile":{"id":1,"full_name":"عميل"},'
        '"role":"client","firm_id":null,"permissions":[]}',
      );
      expect(find.text('رفع مستند جديد'), findsOneWidget);
    });

    testWidgets('a lawyer without documents.upload does not see it', (tester) async {
      await pumpSignedIn(
        tester,
        '{"ok":true,"type":"lawyer","profile":{"id":4,"full_name":"موظف"},'
        '"role":"staff","firm_id":1,'
        '"permissions":["cases.view","documents.view"]}',
      );
      expect(find.text('رفع مستند جديد'), findsNothing);
    });

    testWidgets('a lawyer with documents.upload sees it', (tester) async {
      await pumpSignedIn(
        tester,
        '{"ok":true,"type":"lawyer","profile":{"id":4,"full_name":"موظف"},'
        '"role":"staff","firm_id":1,'
        '"permissions":["documents.view","documents.upload"]}',
      );
      expect(find.text('رفع مستند جديد'), findsOneWidget);
    });
  });

  group('Plan parsing matches the plans table', () {
    test('reads the shipped free plan without inventing a price', () {
      final plan = Plan.fromJson(const {
        'id': 1,
        'name_ar': 'أساسية',
        'name_en': 'Basic',
        'description_ar': 'للأفراد',
        'price_monthly': '0.000',
        'price_yearly': '0.000',
        'ai_queries_limit': 15,
        'document_analysis_limit': 2,
        'consultations_included': 0,
        'is_corporate': 0,
        'is_featured': 0,
      });

      expect(plan.displayName, 'أساسية');
      expect(plan.priceMonthly, 0);
      expect(plan.isFree, isTrue);
      expect(plan.aiQueriesLimit, 15);
      expect(plan.documentAnalysisLimit, 2);
    });

    test('a null limit means unlimited, not zero', () {
      final plan = Plan.fromJson(const {
        'id': 3,
        'name_ar': 'مؤسسية',
        'price_monthly': '799.000',
        'price_yearly': '7990.000',
        'ai_queries_limit': null,
        'document_analysis_limit': null,
        'consultations_included': 5,
      });

      expect(plan.aiQueriesLimit, isNull);
      expect(plan.documentAnalysisLimit, isNull);
      expect(plan.consultationsIncluded, 5);
      expect(plan.isFree, isFalse);
    });
  });

  group('AiStatus', () {
    test('reports the real blocking reason instead of a fixed green state', () {
      final status = AiStatus.fromJson(const {
        'ok': true,
        'available': false,
        'configured': false,
        'reachable': false,
        'blocking': 'no_credentials',
        'provider_supported': true,
      });

      expect(status.available, isFalse);
      expect(status.reason, contains('مفتاح'));
    });

    test('an unknown blocking value yields no misleading message', () {
      const status = AiStatus(
        available: false,
        configured: false,
        reachable: false,
        blocking: 'something_new',
      );
      expect(status.reason, isEmpty);
    });
  });

  group('ApiClient error handling', () {
    test('surfaces the server\'s own Arabic message on 400', () async {
      final client = ApiClient(
        tokens: _MemoryTokenStore(),
        client: _FakeTransport((_) async => (
              400,
              {'ok': false, 'error': 'البريد الإلكتروني مستخدم بالفعل'}
            )),
      );

      await expectLater(
        client.get('/me.php'),
        throwsA(
          isA<ApiException>()
              .having((e) => e.message, 'message',
                  'البريد الإلكتروني مستخدم بالفعل')
              .having((e) => e.kind, 'kind', ApiErrorKind.validation),
        ),
      );
    });

    test('maps 401 to unauthorized so the app can ask for login', () async {
      final client = ApiClient(
        tokens: _MemoryTokenStore(),
        client: _FakeTransport((_) async => (401, '')),
      );
      await expectLater(
        client.get('/me.php'),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiErrorKind.unauthorized)),
      );
    });

    test('maps 503 to dependency (AI provider not configured)', () async {
      final client = ApiClient(
        tokens: _MemoryTokenStore(),
        client: _FakeTransport((_) async => (
              503,
              {'ok': false, 'error': 'AI service unavailable'}
            )),
      );
      await expectLater(
        client.post('/ai-chat.php', body: const {'message': 'x'}),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiErrorKind.dependency)),
      );
    });

    test('a transport failure becomes a retryable network error', () async {
      final client = ApiClient(
        tokens: _MemoryTokenStore(),
        client: _FakeTransport((_) async => throw const SocketException('down')),
      );
      await expectLater(
        client.get('/cases.php'),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiErrorKind.network)
            .having((e) => e.isRetryable, 'isRetryable', isTrue)),
      );
    });
  });

  group('Pricing screen renders the API response', () {
    testWidgets('shows the real price and marks null limits as unlimited',
        (tester) async {
      final repo = ApiRepository(
        client: ApiClient(
          tokens: _MemoryTokenStore(),
          client: _FakeTransport((uri) async {
            expect(uri.path, endsWith('/plans.php'));
            return (200, {
              'ok': true,
              'plans': [
                {
                  'id': 3,
                  'name_ar': 'مؤسسية',
                  'price_monthly': '799.000',
                  'price_yearly': '7990.000',
                  'description_ar': 'للمكاتب والشركات',
                  'ai_queries_limit': null,
                  'document_analysis_limit': null,
                  'consultations_included': 5,
                  'is_featured': 1,
                },
              ],
            });
          }),
        ),
      );

      await tester.pumpWidget(
        MaterialApp(home: PricingScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      expect(find.text('مؤسسية'), findsOneWidget);
      expect(find.text('799'), findsOneWidget);
      expect(find.textContaining('غير محدود'), findsWidgets);
      expect(find.text('الأكثر اختياراً'), findsOneWidget);
    });

    testWidgets('an empty plan list shows the honest empty state',
        (tester) async {
      final repo = ApiRepository(
        client: ApiClient(
          tokens: _MemoryTokenStore(),
          client: _FakeTransport((_) async => (200, {'ok': true, 'plans': []})),
        ),
      );

      await tester.pumpWidget(
        MaterialApp(home: PricingScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.textContaining('لم تُنشر'), findsOneWidget);
    });

    testWidgets('a server error shows its message and a retry action',
        (tester) async {
      final repo = ApiRepository(
        client: ApiClient(
          tokens: _MemoryTokenStore(),
          client: _FakeTransport((_) async => (
                500,
                {'ok': false, 'error': 'تعذّر جلب الخطط حالياً'}
              )),
        ),
      );

      await tester.pumpWidget(
        MaterialApp(home: PricingScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      expect(find.byType(ErrorState), findsOneWidget);
      expect(find.text('تعذّر جلب الخطط حالياً'), findsOneWidget);
      expect(find.text('إعادة المحاولة'), findsOneWidget);
    });
  });
}
