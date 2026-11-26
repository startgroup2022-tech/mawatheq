// Execution 69 — a lawyer with no published hours must still be bookable in the app.
//
// `_BookingCalendar` gated every day cell on `slots > 0`. A lawyer who has not published
// hours — the default state of a new account, and true of every seeded lawyer — has an
// empty month map, so every cell was disabled and the step could never advance. The
// website already handled this: `booking.php` falls back to a plain date/time when
// `$hasAvailability` is false, and `booking_slot_is_bookable()` only enforces the grid
// when a grid exists. The app silently disagreed with both.
//
// This test walks the real journey to the appointment step with a month response that
// reports `has_hours: false` and no days, then asserts the customer can pick a time and
// continue. The transport is a fake only at the HTTP edge; every screen, model and
// widget below is the production one.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/features/consultations/journey_screen.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';

class _NoHoursTransport extends http_base.BaseClient {
  _NoHoursTransport(this.monthJson, {this.oneOpenDayMonth = false});
  final String monthJson;

  /// When true the reply is generated for whichever month the calendar asks for, with a
  /// single open day (the 1st). A fixed month key would not match the month the widget
  /// renders and would make "every day closed" pass for the wrong reason.
  final bool oneOpenDayMonth;
  final List<Uri> calls = [];

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    calls.add(request.url);
    final String body;
    if (request.url.path.endsWith('/journey.php')) {
      switch (request.url.queryParameters['action']) {
        case 'specialties':
          body = '{"ok":true,"specialties":[{"id":1,"name":"الأحوال الشخصية",'
              '"description":"شؤون الأسرة.","icon_key":"family","lawyer_count":1}]}';
        case 'issue-types':
          body = '{"ok":true,"issue_types":[{"id":5,"name":"إثبات عقد الزواج"}]}';
        default:
          body = '{"ok":true,"lawyers":[{"id":1,"name":"Firm A Owner",'
              '"title":"محامٍ","specialty":"الأحوال الشخصية","rating":4.5}],'
              '"matched":true}';
      }
    } else if (request.url.path.endsWith('/available-slots.php') &&
        request.url.queryParameters.containsKey('month')) {
      if (!oneOpenDayMonth) {
        body = monthJson;
      } else {
        final requested = request.url.queryParameters['month']!;
        final parts = requested.split('-');
        final year = int.parse(parts[0]);
        final month = int.parse(parts[1]);
        final dayCount = DateTime(year, month + 1, 0).day;
        final days = <String, int>{};
        for (var d = 1; d <= dayCount; d++) {
          // The last day of the month, so the open day is never already in the past —
          // a past day is legitimately disabled and would make this assertion vacuous.
          days['$requested-${d.toString().padLeft(2, '0')}'] =
              d == dayCount ? 4 : 0;
        }
        body = jsonEncode({
          'month': requested,
          'days': days,
          'working_weekdays': [DateTime(year, month, dayCount).weekday % 7],
          'has_hours': true,
        });
      }
    } else if (request.url.path.endsWith('/available-slots.php')) {
      body = '{"ok":true,"slots":[]}';
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

class _Tokens implements TokenStore {
  @override
  Future<void> saveToken(String value) async {}
  @override
  Future<String?> readToken() async => 'journey-token';
  @override
  Future<void> saveAccountType(String value) async {}
  @override
  Future<String?> readAccountType() async => 'client';
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

/// A phone-sized surface: the default 800x600 test window is tablet-ish and makes phone
/// layouts overflow for reasons unrelated to the code under test.
void _phoneSurface(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2280);
  tester.view.devicePixelRatio = 3.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

Future<void> _walkToAppointment(WidgetTester tester, ApiRepository repo) async {
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
}

void main() {
  group('Execution 69 — booking with no published hours', () {
    testWidgets('the customer can propose a time and continue',
        (tester) async {
      _phoneSurface(tester);
      // The real shape the endpoint returns for a lawyer with no lawyer_availability rows:
      // a full month of zeros and has_hours false.
      final transport = _NoHoursTransport(
          '{"month":"2026-12","days":{},"working_weekdays":[],"has_hours":false}');
      final repo = ApiRepository(
        client: ApiClient(client: transport, tokens: _Tokens()),
      );
      await _walkToAppointment(tester, repo);

      // The step states why there is no grid instead of pretending the lawyer is closed.
      expect(find.text('اقترح وقتاً'), findsOneWidget);
      expect(
        find.textContaining('لم ينشر هذا المحامي أوقات دوام'),
        findsOneWidget,
      );

      // A proposed time is offered and selectable — this is the defect: it used to be
      // an empty list under a fully disabled calendar.
      await tester.scrollUntilVisible(find.text('10:00'), 200,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('10:00'));
      await tester.pumpAndSettle();

      await tester.scrollUntilVisible(
          find.widgetWithText(PrimaryButton, 'متابعة'), 200,
          scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      final button = tester.widget<PrimaryButton>(
          find.widgetWithText(PrimaryButton, 'متابعة'));
      expect(button.onPressed, isNotNull,
          reason: 'choosing a time must let the customer continue');
    });

    testWidgets('a lawyer who did publish hours still cannot be booked on a closed day',
        (tester) async {
      _phoneSurface(tester);
      // The regression risk of the fix: relaxing `slots > 0` must not open days the
      // lawyer has explicitly closed. One open day, every other day zero.
      final transport = _NoHoursTransport('', oneOpenDayMonth: true);
      final repo = ApiRepository(
        client: ApiClient(client: transport, tokens: _Tokens()),
      );
      await _walkToAppointment(tester, repo);

      expect(find.text('الأوقات المتاحة'), findsOneWidget,
          reason: 'with a grid the heading stays the availability one');
      expect(find.text('اقترح وقتاً'), findsNothing);

      // Count the tappable day cells inside the calendar grid. Exactly one day has open
      // slots in the generated month, so exactly one cell may be enabled.
      final enabledCells = tester
          .widgetList<InkWell>(find.descendant(
            of: find.byType(GridView),
            matching: find.byType(InkWell),
          ))
          .where((w) => w.onTap != null)
          .length;
      expect(enabledCells, 1,
          reason: 'a published grid must still disable the days the lawyer is closed');
    });
  });
}
