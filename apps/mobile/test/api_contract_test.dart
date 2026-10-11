import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_exception.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/public/pricing_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';

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
