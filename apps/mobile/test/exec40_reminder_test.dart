import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/notifications/reminder_service.dart';

/// The reminder service must never throw on a platform that cannot show
/// notifications, and it must refuse to schedule one that can never fire.
///
/// These run on the host (no Android), which is exactly the degraded path a
/// widget test or a desktop build takes. The assertions therefore cover the
/// behaviour the booking screen depends on: a real answer, never an exception.
void main() {
  group('ReminderService on a platform without notification support', () {
    test('reports unsupported rather than throwing', () async {
      // The host test runner is neither Android nor iOS, so the service must
      // degrade instead of calling into a plugin that is not registered.
      final status = await ReminderService.scheduleConsultationReminder(
        consultationId: 1,
        appointment: DateTime.now().add(const Duration(days: 1)),
        lawyerName: 'أ. أحمد',
      );

      expect(status, ReminderStatus.unsupported);
    });

    test('an appointment that has already passed is refused first', () async {
      // The past-check runs before platform detection, so a stale booking is
      // reported as such instead of as an unsupported platform.
      final status = await ReminderService.scheduleConsultationReminder(
        consultationId: 2,
        appointment: DateTime.now().subtract(const Duration(hours: 3)),
        lawyerName: 'أ. أحمد',
      );

      expect(status, ReminderStatus.inThePast);
    });

    test('cancelling is a no-op, not an error', () async {
      await expectLater(ReminderService.cancel(3), completes);
    });
  });

  group('describe', () {
    final appointment = DateTime(2026, 3, 4, 9, 5);

    test('a scheduled reminder names the time', () {
      final text =
          ReminderService.describe(ReminderStatus.scheduled, appointment);
      expect(text, contains('09:05'));
      expect(text, contains('تذكير'));
    });

    test('an inexact reminder warns it may be late', () {
      final text =
          ReminderService.describe(ReminderStatus.scheduledInexact, appointment);
      expect(text, contains('قد يتأخر'));
    });

    test('a denied permission tells the customer what to do', () {
      final text = ReminderService.describe(
          ReminderStatus.permissionDenied, appointment);
      expect(text, contains('إعدادات الجهاز'));
    });

    test('every status produces a non-empty sentence', () {
      // A status without a message would leave the confirmation dialog blank.
      for (final status in ReminderStatus.values) {
        final text = ReminderService.describe(status, appointment);
        expect(text.trim(), isNotEmpty, reason: 'no text for $status');
      }
    });
  });

  group('the reminder is described as device-local', () {
    test('the copy never claims the platform sent a notification', () {
      // The product has no server-side reminder service, so no message may imply
      // one. These words are the ones that would overstate it.
      final appointment = DateTime(2026, 3, 4, 9, 5);
      for (final status in ReminderStatus.values) {
        final text = ReminderService.describe(status, appointment);
        expect(text, isNot(contains('المنصة')));
        expect(text, isNot(contains('رسالة نصية')));
      }
    });
  });

  group('the host really is the degraded path', () {
    test('the test runner is not Android or iOS', () {
      // Guards the assumptions above: if this ever runs on a device, the
      // "unsupported" expectations would need revisiting rather than silently
      // passing for the wrong reason.
      expect(Platform.isAndroid || Platform.isIOS, isFalse);
    });
  });
}
