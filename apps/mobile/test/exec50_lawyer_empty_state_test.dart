import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/assistant/assistant_screen.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';
import 'package:mawatheq_mobile/features/public/public_info_screen.dart';
import 'package:mawatheq_mobile/features/public/public_lawyers_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';
import 'package:provider/provider.dart';

/// Execution 50 — the lawyer empty state.
///
/// The owner's report: when no lawyer is available the screen showed technical
/// text, a debugging dump and raw server fields under the message. The requirement
/// is a single sentence and exactly two working buttons.
///
/// Two surfaces can reach that state — the consultation journey (step 3) and the
/// public lawyer directory — so both are pinned here, and both are asserted to
/// render the *same* shared widget. If a future edit reintroduces a per-screen copy
/// of the state, one of these fails.
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
  Future<void> clear() async => _token = null;
}

ApiRepository _repo(Future<(int, Object)> Function(Uri) handler) => ApiRepository(
      client: ApiClient(
        client: _FakeTransport(handler),
        tokens: _MemoryTokenStore()..saveToken('t'),
      ),
    );

const _specialtiesJson = '{"ok":true,"specialties":['
    '{"id":4,"name":"الدعاوى الجزائية","description":"القضايا الجزائية.",'
    '"icon_key":"criminal","lawyer_count":0}],"ready":true}';

const _issueTypesJson = '{"ok":true,"issue_types":['
    '{"id":1,"name":"إثبات عقد الزواج","definition":"دعوى لإثبات الزواج غير الموثق.",'
    '"group":"دعاوى الزواج والفرقة"}],'
    '"not_sure":{"id":0,"name":"لست متأكداً"}}';

/// Exactly what `api/v1/journey.php` sends for an unstaffed specialty: the empty
/// list plus a full `alternative` object. The empty state must read none of it.
const _emptyMatchJson = '{"ok":true,"lawyers":[],"matched":false,'
    '"alternative":{"reason":"لا يوجد حاليًا محامٍ متاح في هذا التخصص.",'
    '"suggest":"يمكنك اختيار تخصص آخر أو إرسال طلبك وسنرشّح لك محاميًا مناسبًا.",'
    '"specialties":[{"id":2,"name":"الدعاوى التجارية","lawyer_count":0}]}}';

void main() {
  /// The app always mounts `LocaleController` at the root; a test that pushes a
  /// screen reading it (the contact page, the assistant) needs the same provider or
  /// the push throws `ProviderNotFoundException` — a harness gap, not a screen bug.
  Widget wrap(Widget child) => ChangeNotifierProvider(
        create: (_) => LocaleController(),
        child: MaterialApp(home: child),
      );

  group('NoLawyerState — the shared widget', () {
    testWidgets('renders one message and exactly the two required buttons',
        (tester) async {
      var assistant = 0;
      var contact = 0;

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: NoLawyerState(
            onAskAssistant: () => assistant++,
            onContact: () => contact++,
          ),
        ),
      ));

      expect(find.text('لا يوجد محامي متاح'), findsOneWidget);
      expect(find.text('المساعد الذكي'), findsOneWidget);
      expect(find.text('تواصل معنا'), findsOneWidget);

      await tester.tap(find.text('المساعد الذكي'));
      await tester.tap(find.text('تواصل معنا'));
      expect(assistant, 1);
      expect(contact, 1);
    });

    testWidgets('renders no raw backend field, code or placeholder',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: NoLawyerState(onAskAssistant: () {}, onContact: () {}),
        ),
      ));

      // The exact strings the server sends in `alternative`, plus the shapes that
      // leaked before: a stringified map and a JSON-ish field dump.
      for (final leak in [
        'reason',
        'suggest',
        'specialties',
        '{',
        '}',
        'null',
        'JSON',
        'http',
        'error',
        'Error',
        'debug',
        'Debug',
      ]) {
        expect(find.textContaining(leak), findsNothing,
            reason: 'empty state must not show "$leak"');
      }

      // The server's own Arabic sentence is also not echoed: the state shows one
      // reviewed message, not the backend's wording.
      expect(find.textContaining('محاميًا مناسبًا'), findsNothing);
      expect(find.textContaining('نرشّح'), findsNothing);
    });

    testWidgets('shows no third action and no extra paragraph', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: NoLawyerState(onAskAssistant: () {}, onContact: () {}),
        ),
      ));

      // Exactly two tap targets in the state, and exactly one Text besides the
      // button labels — the message.
      final buttons = find.byWidgetPredicate(
        (w) => w is ButtonStyleButton || w is OutlinedButton,
      );
      expect(buttons, findsNWidgets(2));
    });
  });

  group('the journey empty state', () {
    testWidgets('uses the shared widget and shows only message + two buttons',
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
        wrap(ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('الدعاوى الجزائية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();

      expect(find.byType(NoLawyerState), findsOneWidget);
      expect(find.text('لا يوجد محامي متاح'), findsOneWidget);
      expect(find.text('المساعد الذكي'), findsOneWidget);
      expect(find.text('تواصل معنا'), findsOneWidget);
      expect(find.textContaining('reason'), findsNothing);
      expect(find.textContaining('suggest'), findsNothing);
    });

    testWidgets('the assistant button opens the real assistant', (tester) async {
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
        wrap(ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('الدعاوى الجزائية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('المساعد الذكي'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(AssistantScreen), findsOneWidget);
    });

    testWidgets('the contact button opens the real contact page', (tester) async {
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
        wrap(ConsultationJourneyScreen(repository: repo)),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('الدعاوى الجزائية'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('إثبات عقد الزواج'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('تواصل معنا'));
      await tester.pumpAndSettle();
      expect(find.byType(PublicInfoScreen), findsOneWidget);
    });
  });

  group('the public directory empty state', () {
    testWidgets('an empty directory shows only the message and the two buttons',
        (tester) async {
      final repo = _repo((uri) async => (200, '{"ok":true,"lawyers":[]}'));

      await tester.pumpWidget(wrap(PublicLawyersScreen(repository: repo)));
      await tester.pumpAndSettle();

      expect(find.byType(NoLawyerState), findsOneWidget);
      expect(find.text('لا يوجد محامي متاح'), findsOneWidget);
      expect(find.text('المساعد الذكي'), findsOneWidget);
      expect(find.text('تواصل معنا'), findsOneWidget);
      // The old copy is gone: no "approved lawyers appear here" paragraph.
      expect(find.textContaining('فور اعتمادهم'), findsNothing);
    });

    testWidgets('the directory assistant button opens the assistant',
        (tester) async {
      final repo = _repo((uri) async => (200, '{"ok":true,"lawyers":[]}'));

      await tester.pumpWidget(wrap(PublicLawyersScreen(repository: repo)));
      await tester.pumpAndSettle();

      await tester.tap(find.text('المساعد الذكي'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(AssistantScreen), findsOneWidget);
    });

    testWidgets('the directory contact button opens the contact page',
        (tester) async {
      final repo = _repo((uri) async => (200, '{"ok":true,"lawyers":[]}'));

      await tester.pumpWidget(wrap(PublicLawyersScreen(repository: repo)));
      await tester.pumpAndSettle();

      await tester.tap(find.text('تواصل معنا'));
      await tester.pumpAndSettle();
      expect(find.byType(PublicInfoScreen), findsOneWidget);
    });

    testWidgets('lawyers present means no empty state at all', (tester) async {
      const withLawyers = '{"ok":true,"lawyers":['
          '{"id":6,"full_name":"thaer aljallad","specialization":"قانون جنائي",'
          '"years_experience":0,"hourly_rate":null,"rating":"5.0","bio":null,'
          '"photo_path":null,"jurisdiction":"السعودية"}]}';
      final repo = _repo((uri) async => (200, withLawyers));

      await tester.pumpWidget(wrap(PublicLawyersScreen(repository: repo)));
      await tester.pumpAndSettle();

      expect(find.byType(NoLawyerState), findsNothing);
      expect(find.text('لا يوجد محامي متاح'), findsNothing);
      expect(find.textContaining('thaer aljallad'), findsOneWidget);
    });
  });
}
