import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_exception.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/home/home_tab.dart';
import 'package:provider/provider.dart';

/// Regression tests for the failure the owner reported from the released APK:
/// every screen showed "INTERNAL SERVER ERROR" and no legal categories appeared.
///
/// The root cause was a deployment gap (production served 404 for the two home
/// endpoints), but two client-side defects turned a missing optional file into a
/// blank, English, unusable app. These tests pin both.
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
  Future<void> clear() async {}
}

/// Replays exactly what production returned while the app was broken.
class _Transport extends http_base.BaseClient {
  _Transport(this.handler);

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

/// The LiteSpeed HTML 404 body production sends when a PHP file is absent.
const _htmlNotFound =
    '<!DOCTYPE html><html><head><title> 404 Not Found</title></head><body>'
    '<h1>404</h1><h2>Not Found</h2><p>Proudly powered by LiteSpeed Web Server'
    '</p></body></html>';

ApiRepository _repo(Future<(int, Object)> Function(Uri) handler) =>
    ApiRepository(client: ApiClient(client: _Transport(handler), tokens: _MemoryTokenStore()));

/// Production as it was: the home endpoints missing, everything else fine.
Future<(int, Object)> _productionWhileBroken(Uri uri) async {
  if (uri.path.endsWith('/banners.php') || uri.path.endsWith('/journey.php')) {
    return (404, _htmlNotFound);
  }
  if (uri.path.endsWith('/plans.php')) return (200, {'ok': true, 'plans': []});
  return (200, {'ok': true});
}

Widget _home(ApiRepository repo) => MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => LocaleController()),
        Provider<ApiRepository>.value(value: repo),
      ],
      child: MaterialApp(
        home: Scaffold(body: HomeTab(repository: repo, onOpenLawyers: () {})),
      ),
    );

void main() {
  group('Server error messages are localized', () {
    test('the English 500 body is replaced with Arabic', () {
      final message = messageFromBody(
        jsonEncode({'ok': false, 'error': 'internal server error'}),
        500,
      );
      expect(message, 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.');
      expect(message, isNot(contains('internal')));
    });

    test('an English auth diagnostic is replaced with Arabic', () {
      final message = messageFromBody(
        jsonEncode({'ok': false, 'error': 'unauthorized: invalid or expired token'}),
        401,
      );
      expect(message, 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.');
      expect(message, isNot(contains('unauthorized')));
    });

    test('a machine code the UI localises itself is preserved', () {
      // password_reset_screens.dart switches on these exact tokens.
      expect(
        messageFromBody(jsonEncode({'ok': false, 'error': 'invalid_token'}), 410),
        'invalid_token',
      );
      expect(
        messageFromBody(jsonEncode({'ok': false, 'error': 'weak_password'}), 422),
        'weak_password',
      );
    });

    test('an Arabic server message is kept verbatim', () {
      const arabic = 'تعذّر جلب الخطط حالياً';
      expect(
        messageFromBody(jsonEncode({'ok': false, 'error': arabic}), 500),
        arabic,
      );
    });

    test('an HTML body still yields the status-appropriate Arabic message', () {
      expect(messageFromBody(_htmlNotFound, 404), 'العنصر المطلوب غير موجود.');
    });

    test('an unexpected non-JSON status is not shown raw', () {
      expect(messageFromBody(_htmlNotFound, 500), contains('الخادم'));
    });
  });

  group('The home screen never blanks itself', () {
    testWidgets('a missing banners endpoint still renders the whole home',
        (tester) async {
      await tester.pumpWidget(_home(_repo(_productionWhileBroken)));
      await tester.pumpAndSettle();

      // The regression: the whole home must survive the failure, not just the
      // error notice. The primary actions, the specialty section and the services
      // are all still there.
      expect(find.text('احجز استشارة'), findsOneWidget);
      expect(find.text('اسأل المساعد الذكي'), findsOneWidget);
      expect(find.text('اختر تخصصك القانوني'), findsOneWidget);
      expect(find.text('خدمات مواثيق'), findsOneWidget);
      expect(find.text('الاستشارات القانونية'), findsOneWidget);
      expect(find.text('دليل المحامين'), findsOneWidget);
      expect(find.text('إدارة القضايا'), findsOneWidget);
    });

    testWidgets('the failure is reported inline with a retry, not as a page',
        (tester) async {
      await tester.pumpWidget(_home(_repo(_productionWhileBroken)));
      await tester.pumpAndSettle();

      expect(find.textContaining('إعادة المحاولة'), findsOneWidget);
      // The notice must not be a full-screen replacement.
      expect(find.text('خدمات مواثيق'), findsOneWidget);
    });

    testWidgets('the specialties section states its empty case', (tester) async {
      await tester.pumpWidget(_home(_repo(_productionWhileBroken)));
      await tester.pumpAndSettle();

      expect(
        find.text('لا توجد تخصصات منشورة بعد'),
        findsOneWidget,
      );
      // The state explains what happens next rather than leaving a blank frame.
      expect(find.textContaining('ستظهر التخصصات القانونية'), findsOneWidget);
    });

    testWidgets('all published specialties are shown, not a truncated prefix',
        (tester) async {
      final specialties = <Map<String, Object?>>[
        for (var i = 1; i <= 12; i++)
          {'id': i, 'name': 'تخصص رقم $i', 'icon_key': 'x', 'lawyer_count': 1},
      ];
      await tester.pumpWidget(_home(_repo((uri) async {
        if (uri.path.endsWith('/banners.php')) {
          return (200, {'ok': true, 'banners': []});
        }
        if (uri.path.endsWith('/journey.php')) {
          return (200, {'ok': true, 'specialties': specialties});
        }
        return (200, {'ok': true});
      })));
      await tester.pumpAndSettle();

      // The grid used to take(8); every specialty must be reachable.
      expect(find.text('تخصص رقم 9'), findsOneWidget);
      expect(find.text('تخصص رقم 12'), findsOneWidget);
    });

    testWidgets('a working server still shows banners and specialties',
        (tester) async {
      await tester.pumpWidget(_home(_repo((uri) async {
        if (uri.path.endsWith('/banners.php')) {
          return (200, {
            'ok': true,
            'banners': [
              {
                'id': 1,
                'title_ar': 'خصم 20%',
                'title_en': '20% off',
                'image_path': 'uploads/banners/a.jpg',
                'is_active': 1,
              },
            ],
          });
        }
        if (uri.path.endsWith('/journey.php')) {
          return (200, {
            'ok': true,
            'specialties': [
              {'id': 1, 'name': 'الأحوال الشخصية', 'icon_key': 'family', 'lawyer_count': 3},
            ],
          });
        }
        return (200, {'ok': true});
      })));
      await tester.pumpAndSettle();

      expect(find.text('الأحوال الشخصية'), findsOneWidget);
      expect(find.textContaining('خصم 20%'), findsWidgets);
      expect(find.textContaining('إعادة المحاولة'), findsNothing);
    });
  });
}
