import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/lawyer_models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/calls/call_service.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_consultations_screen.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_dashboard_screen.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_profile_screen.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_shell.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_wallet_screen.dart';
import 'package:provider/provider.dart';

/// Execution 57 — the lawyer application.
///
/// These tests drive the real [ApiRepository] through a fake HTTP transport, so the
/// production client, its JSON parsing and the widgets are all exercised. The
/// assertions are on what the screen shows for the payload the server actually sends —
/// not on a mock's return value.
class _FakeTransport extends http_base.BaseClient {
  _FakeTransport(this.handler);

  final Future<(int, Object)> Function(http_base.BaseRequest request) handler;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final (status, body) = await handler(request);
    final payload = body is String ? body : jsonEncode(body);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(payload)),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
  String? _token = 'test-token';

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> saveAccountType(String type) async {}

  @override
  Future<String?> readAccountType() async => 'lawyer';

  @override
  Future<void> saveDeviceLabel(String label) async {}

  @override
  Future<String?> readDeviceLabel() async => null;

  @override
  Future<void> clear() async => _token = null;
}

ApiRepository _repo(Future<(int, Object)> Function(http_base.BaseRequest) handler) => ApiRepository(
      client: ApiClient(client: _FakeTransport(handler), tokens: _MemoryTokenStore()),
    );

/// Mount a screen at a phone-sized surface. The Flutter test default (800x600) is a
/// tablet-ish window on which phone layouts overflow, which looks like a layout bug
/// and is not one.
Future<void> _pumpPhone(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(1080, 2280);
  tester.view.devicePixelRatio = 3.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  // Wrapped in a Scaffold: the dashboard and the wallet rely on one for their
  // Material ancestors, and a screen that already supplies its own simply nests.
  await tester.pumpWidget(MaterialApp(home: Scaffold(body: child)));
  await tester.pump(const Duration(milliseconds: 400));
}

const _dashboardJson = {
  'ok': true,
  'lawyer': {'id': 1, 'role': 'owner', 'firm_id': 1, 'is_firm': true},
  'consultations': {'pending': 3, 'confirmed': 2, 'completed': 7, 'cancelled': 1, 'total': 13},
  'upcoming_appointments': [
    {
      'id': 42,
      'topic': 'مراجعة عقد تجاري',
      'consultation_type': 'video',
      'scheduled_at': '2026-10-26 11:00',
      'client_name': 'أحمد العتيبي',
    },
  ],
  'cases_active': 5,
  'clients': 9,
  'tasks_open': 4,
  'notifications_unread': 2,
  'wallet': {
    'currency': 'SAR',
    'available_minor': 125000,
    'pending_minor': 40000,
    'available': '1250.00',
    'pending': '400.00',
    'gross_earned': '9000.00',
    'commission': '900.00',
    'withdrawn': '2000.00',
  },
  'recent_activity': [
    {'kind': 'consultation', 'id': 1, 'title': 'استشارة عمالية', 'status': 'pending', 'at': '2026-10-24'},
  ],
};

const _consultationsJson = {
  'ok': true,
  'consultations': [
    {
      'id': 11,
      'status': 'pending',
      'consultation_type': 'video',
      'topic': 'طلب استشارة فيديو',
      'client_name': 'أحمد العتيبي',
      'scheduled_date': '2026-10-26',
      'scheduled_time': '11:00',
      'attachment_count': 0,
    },
    {
      'id': 12,
      'status': 'confirmed',
      'consultation_type': 'voice',
      'topic': 'استشارة صوتية',
      'client_name': 'سارة القحطاني',
      'scheduled_date': '2026-10-27',
      'scheduled_time': '14:00',
      'attachment_count': 2,
    },
  ],
};

const _walletSummaryJson = {
  'ok': true,
  'currency': 'SAR',
  'status': 'active',
  'available_minor': 125000,
  'pending_minor': 40000,
  'reserved_minor': 0,
  'available': '1250.00',
  'pending': '400.00',
  'reserved': '0.00',
  'gross_earned': '9000.00',
  'net_earned': '8100.00',
  'commission': '900.00',
  'withdrawn': '2000.00',
  'payout_details': '',
};

void main() {
  group('lawyer dashboard', () {
    testWidgets('renders every figure from the server payload', (tester) async {
      final repo = _repo((req) async {
        expect(req.url.path, contains('lawyer-dashboard.php'));
        return (200, _dashboardJson);
      });

      await _pumpPhone(tester, LawyerDashboardScreen(repository: repo));

      // The pending call-to-action names the real count.
      expect(find.textContaining('3 طلب استشارة'), findsOneWidget);
      // The four stat tiles.
      expect(find.text('5'), findsOneWidget); // cases active
      expect(find.text('9'), findsOneWidget); // clients
      expect(find.text('4'), findsOneWidget); // open tasks
      // Wallet headline and breakdown.
      expect(find.text('1250.00'), findsOneWidget);
      expect(find.text('SAR'), findsOneWidget);
      expect(find.text('400.00'), findsOneWidget);
      expect(find.text('9000.00'), findsOneWidget);
      // The upcoming appointment.
      expect(find.text('مراجعة عقد تجاري'), findsOneWidget);
      // The activity feed sits below the fold in a lazy list, so scroll it into view
      // the way a reader would; an unbuilt list row is legitimately absent.
      await tester.dragUntilVisible(
        find.text('استشارة عمالية'),
        find.byType(ListView),
        const Offset(0, -240),
      );
      await tester.pump(const Duration(milliseconds: 200));
      expect(find.text('استشارة عمالية'), findsOneWidget);
    });

    testWidgets('a zero-pending dashboard hides the call to action', (tester) async {
      final repo = _repo((req) async => (200, {
            ..._dashboardJson,
            'consultations': {'pending': 0, 'confirmed': 0, 'completed': 0, 'cancelled': 0, 'total': 0},
          }));

      await _pumpPhone(tester, LawyerDashboardScreen(repository: repo));

      // Nothing to act on means no banner — a permanent "0 requests" card trains the
      // reader to ignore the area.
      expect(find.textContaining('بانتظار قرارك'), findsNothing);
      // The stat tiles still render, with the server's zeros.
      expect(find.text('استشارات نشطة'), findsOneWidget);
    });

    testWidgets('a server error shows the server message, not a fabricated dashboard', (tester) async {
      final repo = _repo((req) async => (500, {'ok': false, 'error': 'تعذّر تحميل البيانات.'}));

      await _pumpPhone(tester, LawyerDashboardScreen(repository: repo));

      expect(find.text('تعذّر تحميل البيانات.'), findsOneWidget);
      // No invented numbers on the failure path.
      expect(find.text('1250.00'), findsNothing);
    });
  });

  group('lawyer consultations', () {
    testWidgets('the pending filter lists only pending requests', (tester) async {
      final repo = _repo((req) async => (200, _consultationsJson));

      await _pumpPhone(tester, LawyerConsultationsScreen(repository: repo));

      expect(find.text('طلب استشارة فيديو'), findsOneWidget);
      // The confirmed one belongs to the other filter.
      expect(find.text('استشارة صوتية'), findsNothing);
      // A pending row offers accept and reject.
      expect(find.text('تأكيد'), findsOneWidget);
      expect(find.text('رفض'), findsOneWidget);
    });

    testWidgets('a confirmed voice consultation offers a call and completion', (tester) async {
      final repo = _repo((req) async {
        if (req.method == 'GET') return (200, _consultationsJson);
        return (200, {'ok': true});
      });

      await _pumpPhone(tester, LawyerConsultationsScreen(repository: repo));
      await tester.tap(find.text('مؤكدة'));
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('استشارة صوتية'), findsOneWidget);
      expect(find.text('مكالمة صوتية'), findsOneWidget);
      expect(find.text('إتمام'), findsOneWidget);
      // A confirmed request is no longer pending, so it must not offer a second accept.
      expect(find.text('تأكيد'), findsNothing);
    });

    testWidgets('confirming posts the status and refreshes', (tester) async {
      final posts = <String>[];
      final repo = _repo((req) async {
        if (req.method == 'POST') {
          posts.add(req.url.path);
          final body = jsonDecode((req as http_base.Request).body) as Map<String, dynamic>;
          expect(body['action'], 'change-status');
          expect(body['status'], 'confirmed');
          expect(body['consultation_id'], 11);
          return (200, {'ok': true});
        }
        return (200, _consultationsJson);
      });

      await _pumpPhone(tester, LawyerConsultationsScreen(repository: repo));
      await tester.tap(find.text('تأكيد'));
      await tester.pump(const Duration(milliseconds: 400));

      expect(posts, isNotEmpty);
      expect(find.text('تم تأكيد الاستشارة.'), findsOneWidget);
    });
  });

  group('lawyer wallet', () {
    testWidgets('shows the balance and the ledger from the server', (tester) async {
      final repo = _repo((req) async {
        switch (req.url.queryParameters['action']) {
          case 'summary':
            return (200, _walletSummaryJson);
          case 'transactions':
            return (200, {
              'ok': true,
              'transactions': [
                {
                  'id': 1,
                  'kind': 'payment',
                  'amount_minor': 125000,
                  'amount': '1250.00',
                  'state': 'available',
                  'created_at': '2026-10-24 10:00',
                },
                {
                  'id': 2,
                  'kind': 'commission',
                  'amount_minor': -12500,
                  'amount': '-125.00',
                  'state': 'available',
                  'created_at': '2026-10-24 10:00',
                },
              ],
            });
          default:
            return (200, {'ok': true, 'withdrawals': []});
        }
      });

      await _pumpPhone(tester, LawyerWalletScreen(repository: repo));

      // The balance is in the header and again as the ledger's first row; both are
      // the same server figure, so two occurrences is correct.
      expect(find.text('1250.00'), findsNWidgets(2));
      expect(find.text('دفعة مستلمة'), findsOneWidget);
      // "عمولة المنصة" labels both the summary figure and the ledger row of the same
      // kind; the label appearing twice is the design, not a duplicate render.
      expect(find.text('عمولة المنصة'), findsWidgets);
      // A debit is shown with its sign, which is the ledger's own data.
      expect(find.text('-125.00'), findsOneWidget);
    });

    testWidgets('withdraw is disabled when nothing is available', (tester) async {
      final repo = _repo((req) async {
        if (req.url.queryParameters['action'] == 'summary') {
          return (200, {..._walletSummaryJson, 'available_minor': 0, 'available': '0.00'});
        }
        if (req.url.queryParameters['action'] == 'transactions') {
          return (200, {'ok': true, 'transactions': []});
        }
        return (200, {'ok': true, 'withdrawals': []});
      });

      await _pumpPhone(tester, LawyerWalletScreen(repository: repo));

      final button = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'طلب سحب'));
      expect(button.onPressed, isNull);
      expect(find.text('لا يوجد رصيد متاح للسحب حالياً.'), findsOneWidget);
    });

    testWidgets('an empty ledger shows the empty state, not a blank screen', (tester) async {
      final repo = _repo((req) async {
        if (req.url.queryParameters['action'] == 'summary') return (200, _walletSummaryJson);
        if (req.url.queryParameters['action'] == 'transactions') {
          return (200, {'ok': true, 'transactions': []});
        }
        return (200, {'ok': true, 'withdrawals': []});
      });

      await _pumpPhone(tester, LawyerWalletScreen(repository: repo));

      expect(find.text('لا توجد حركات'), findsOneWidget);
    });
  });

  group('lawyer profile', () {
    const profileJson = {
      'ok': true,
      'profile': {
        'id': 1,
        'full_name': 'فهد الشمري',
        'email': 'fahd@example.com',
        'phone': '+966500000000',
        'specialization': 'تجاري',
        'bio': 'محامٍ تجاري بخبرة عشر سنوات.',
        'years_experience': 10,
        'license_number': 'LIC-999',
        'photo_path': '',
        'qualifications': 'بكالوريوس قانون',
        'social_linkedin': '',
        'social_twitter': '',
        'social_website': '',
        'jurisdiction': 'السعودية',
        'hourly_rate': 600,
        'rating': 4.8,
        'status': 'approved',
        'email_verified': true,
        'created_at': '2026-01-01',
      },
      'specialty_ids': [2, 5],
      'availability': [
        {'day_of_week': 0, 'start_time': '09:00', 'end_time': '13:00', 'slot_minutes': 30},
      ],
    };

    testWidgets('an approved and verified lawyer is told they are visible', (tester) async {
      final repo = _repo((req) async => (200, profileJson));

      await _pumpPhone(tester, LawyerProfileScreen(repository: repo));

      expect(find.text('حسابك معتمد وظاهر للعملاء.'), findsOneWidget);
      expect(find.text('فهد الشمري'), findsOneWidget);
      expect(find.text('تجاري'), findsOneWidget);
      expect(find.text('10'), findsOneWidget);
      expect(find.text('LIC-999'), findsOneWidget);
      await tester.dragUntilVisible(
        find.text('الأحد'),
        find.byType(ListView),
        const Offset(0, -240),
      );
      await tester.pump(const Duration(milliseconds: 200));
      expect(find.text('الأحد'), findsOneWidget);
    });

    testWidgets('a pending lawyer is told the profile is not visible', (tester) async {
      final repo = _repo((req) async => (200, {
            ...profileJson,
            'profile': {...(profileJson['profile'] as Map), 'status': 'pending'},
          }));

      await _pumpPhone(tester, LawyerProfileScreen(repository: repo));

      expect(find.textContaining('قيد المراجعة'), findsOneWidget);
    });

    testWidgets('an approved but unverified lawyer is told to verify the email', (tester) async {
      final repo = _repo((req) async => (200, {
            ...profileJson,
            'profile': {...(profileJson['profile'] as Map), 'email_verified': false},
          }));

      await _pumpPhone(tester, LawyerProfileScreen(repository: repo));

      expect(find.textContaining('يلزم تأكيد البريد'), findsOneWidget);
    });
  });

  group('models', () {
    test('wallet summary keeps the minor units and the formatted strings apart', () {
      final w = WalletSummary.fromJson(_walletSummaryJson);
      expect(w.availableMinor, 125000);
      expect(w.available, '1250.00');
      expect(w.currency, 'SAR');
    });

    test('a debit transaction is detected by its sign', () {
      final debit = WalletTransaction.fromJson({'id': 1, 'kind': 'commission', 'amount_minor': -12500});
      expect(debit.isDebit, isTrue);
      // No preformatted string: the model formats with the currency's own decimals.
      expect(debit.amount, '-125.00');
    });

    test('a three-decimal currency is formatted with three decimals', () {
      final bhd = WalletTransaction.fromJson({
        'id': 2,
        'kind': 'payment',
        'amount_minor': 1500,
        'currency': 'BHD',
      });
      expect(bhd.amount, '1.500');
    });

    test('a call session exposes its type and liveness', () {
      final call = CallSession.fromJson({
        'id': 9,
        'consultation_id': 11,
        'call_type': 'video',
        'status': 'ringing',
      });
      expect(call.isVideo, isTrue);
      expect(call.isRinging, isTrue);
      expect(call.isActive, isTrue);
    });

    test('call state reports unavailability with its reason', () {
      final state = CallState.fromJson({
        'ok': true,
        'available': false,
        'blocking': 'no_provider',
        'message': 'المكالمات غير مهيّأة على هذه المنصة.',
      });
      expect(state.available, isFalse);
      expect(state.blocking, 'no_provider');
      expect(state.message, contains('غير مهيّأة'));
    });

    test('a joined call keeps the ICE servers the server sent', () {
      final join = CallJoinInfo.fromJson({
        'ok': true,
        'call': {'id': 9, 'consultation_id': 11, 'call_type': 'voice', 'status': 'ringing'},
        'token': 'abc',
        'ice_servers': [
          {'urls': 'stun:stun.example.com:3478'},
        ],
      });
      expect(join.token, 'abc');
      expect(join.iceServers, hasLength(1));
      expect(join.call.callType, 'voice');
    });
  });

  group('call controller', () {
    test('a controller with no server ICE list still has a fallback', () async {
      // The controller is constructed but never started (which would open a real
      // device); this asserts the constructor accepts an empty server list, which is
      // the state of an unconfigured deployment.
      final repo = _repo((req) async => (200, {'ok': true}));
      final c = CallController(
        repository: repo,
        callId: 1,
        token: 't',
        callType: 'voice',
        iceServers: const [],
      );
      expect(c.phase, CallPhase.preparing);
      expect(c.isVideoCall, isFalse);
      c.dispose();
    });

    test('hang-up is safe when the call never started', () async {
      final repo = _repo((req) async => (200, {'ok': true}));
      final c = CallController(
        repository: repo,
        callId: 1,
        token: 't',
        callType: 'voice',
        iceServers: const [],
      );
      // No peer connection exists; the teardown must not throw.
      await c.hangUp();
      expect(c.phase, CallPhase.ended);
    });
  });

  group('lawyer shell', () {
    testWidgets('offers the five lawyer destinations, not the customer ones', (tester) async {
      final repo = _repo((req) async {
        if (req.url.path.contains('lawyer-login')) {
          // Exactly what /auth/lawyer-login.php returns: the account is nested
          // under `lawyer` and there is no top-level `type`. The earlier mock
          // invented `user: {type: 'lawyer'}`, which the server never sends — and
          // that hid a real defect where a signed-in lawyer was parsed as a client
          // and routed into the customer shell.
          return (200, {
            'ok': true,
            'token': 'tok',
            'lawyer': {
              'id': 1,
              'full_name': 'فهد الشمري',
              'email': 'f@example.com',
            },
          });
        }
        return (200, _dashboardJson);
      });
      // Drive the real sign-in path so the shell sees the same state production gives it.
      final auth = AuthController(repository: repo, tokens: _MemoryTokenStore());
      await auth.signIn(email: 'f@example.com', password: 'secret123', asLawyer: true);

      // The regression guard for the routing defect: the lawyer-login payload has
      // no top-level `type`, so without the explicit default the session would be
      // typed `client` and `app.dart` would build the customer shell instead.
      expect(auth.user.isLawyer, isTrue,
          reason: 'a lawyer-login response must produce a lawyer-typed session');
      expect(auth.user.name, 'فهد الشمري',
          reason: 'the name lives under the `lawyer` key');

      await _pumpPhone(
        tester,
        ChangeNotifierProvider<AuthController>.value(
          value: auth,
          child: LawyerShell(repository: repo),
        ),
      );

      // Assert on the bar's own destinations, not on page text: "المحفظة" is also a
      // section heading on the dashboard, so a text search cannot tell the tab from
      // the content.
      final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));
      final labels = bar.destinations
          .cast<NavigationDestination>()
          .map((d) => d.label)
          .toList();
      expect(labels, ['لوحتي', 'الاستشارات', 'المحفظة', 'التنبيهات', 'حسابي']);
      // The customer-only destination must be absent.
      expect(labels, isNot(contains('المساعد')));
    });
  });
}
