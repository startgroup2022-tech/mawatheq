import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/data/latest_all.dart' as tz_data;
import 'package:timezone/timezone.dart' as tz;

/// The outcome of asking for a reminder, so the booking screen can tell the
/// customer the truth about whether one was actually set.
enum ReminderStatus {
  /// Scheduled and will fire on this device.
  scheduled,

  /// The customer declined the notification permission. The booking still
  /// succeeded; only the reminder is missing.
  permissionDenied,

  /// The platform refused the exact alarm and the inexact fallback was used, so
  /// the reminder may arrive a few minutes late.
  scheduledInexact,

  /// Nothing was scheduled: this platform has no notification support (a test
  /// run, desktop, web) or the plugin failed to initialise.
  unsupported,

  /// The appointment time has already passed, so there is nothing to remind about.
  inThePast,
}

/// Appointment reminders, scheduled locally on the device.
///
/// There is no server-side reminder endpoint, so this deliberately does not claim
/// the platform sent anything: the reminder is derived from the booking the
/// customer just made and lives entirely on their phone. That also means it works
/// without a network connection and never discloses the appointment to a push
/// provider.
///
/// Every entry point is safe to call on a platform without notification support
/// (widget tests, desktop, web) — it returns [ReminderStatus.unsupported] rather
/// than throwing, so the booking flow never breaks because of a reminder.
class ReminderService {
  ReminderService._();

  static final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();

  static bool _initialised = false;
  static bool _initFailed = false;

  /// Notification ids are per-consultation so a second booking does not replace
  /// the first reminder. Android requires a 32-bit int, so the id is folded into
  /// that range rather than used directly.
  static int _notificationId(int consultationId) =>
      consultationId & 0x7fffffff;

  static bool get _supported =>
      !kIsWeb && (Platform.isAndroid || Platform.isIOS);

  /// Initialises the plugin and the timezone database once per process.
  ///
  /// `tz.UTC` is used as the location deliberately. A reminder is scheduled from an
  /// absolute instant derived from the device's own wall-clock [DateTime], and
  /// carrying that instant through UTC is lossless — so the reminder fires at the
  /// time the customer chose without resolving a timezone name, which would need
  /// another plugin and another lookup that can fail.
  static Future<bool> _ensureInitialised() async {
    if (_initialised) return true;
    if (_initFailed) return false;
    if (!_supported) return false;

    try {
      tz_data.initializeTimeZones();

      const android = AndroidInitializationSettings('@mipmap/ic_launcher');
      const darwin = DarwinInitializationSettings(
        // Permission is requested explicitly at the moment a reminder is set, so
        // the app never prompts on first launch.
        requestAlertPermission: false,
        requestBadgePermission: false,
        requestSoundPermission: false,
      );

      final ok = await _plugin.initialize(
        settings: const InitializationSettings(android: android, iOS: darwin),
      );
      _initialised = ok ?? false;
      if (!_initialised) _initFailed = true;
      return _initialised;
    } catch (_) {
      _initFailed = true;
      return false;
    }
  }

  /// Asks for the notification permission, and for the exact-alarm permission on
  /// Android 12+. Returns whether notifications may be posted.
  static Future<bool> requestPermissions() async {
    if (!await _ensureInitialised()) return false;

    try {
      if (Platform.isAndroid) {
        final android = _plugin.resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>();
        if (android == null) return false;

        final granted = await android.requestNotificationsPermission();
        // Best-effort: a refusal here only downgrades the reminder to inexact.
        try {
          await android.requestExactAlarmsPermission();
        } catch (_) {}
        return granted ?? false;
      }

      if (Platform.isIOS) {
        final ios = _plugin.resolvePlatformSpecificImplementation<
            IOSFlutterLocalNotificationsPlugin>();
        final granted = await ios?.requestPermissions(alert: true, sound: true);
        return granted ?? false;
      }
    } catch (_) {}
    return false;
  }

  /// Schedules a reminder [leadTime] before [appointment].
  ///
  /// [appointment] is the device-local wall-clock time the customer chose, which
  /// is what the API stored. The reminder body names the lawyer and the time so
  /// the notification is useful without opening the app.
  static Future<ReminderStatus> scheduleConsultationReminder({
    required int consultationId,
    required DateTime appointment,
    required String lawyerName,
    Duration leadTime = const Duration(minutes: 60),
  }) async {
    final fireAt = appointment.subtract(leadTime);
    if (!fireAt.isAfter(DateTime.now())) return ReminderStatus.inThePast;
    if (!await _ensureInitialised()) return ReminderStatus.unsupported;

    final granted = await requestPermissions();
    if (!granted) return ReminderStatus.permissionDenied;

    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'consultation_reminders',
        'تذكيرات الاستشارات',
        channelDescription: 'تذكير بموعد الاستشارة القانونية قبل ساعته.',
        importance: Importance.high,
        priority: Priority.high,
      ),
      iOS: DarwinNotificationDetails(),
    );

    final body = 'موعدك مع $lawyerName الساعة '
        '${_two(appointment.hour)}:${_two(appointment.minute)}.';

    // Exact alarms can be refused by the OS or by policy; when that happens the
    // inexact mode still delivers, just not to the minute. Trying exact first and
    // falling back keeps the reminder accurate where it is allowed.
    for (final mode in const [
      AndroidScheduleMode.exactAllowWhileIdle,
      AndroidScheduleMode.inexactAllowWhileIdle,
    ]) {
      try {
        await _plugin.zonedSchedule(
          id: _notificationId(consultationId),
          title: 'تذكير بموعد استشارتك',
          body: body,
          scheduledDate: tz.TZDateTime.from(fireAt, tz.UTC),
          notificationDetails: details,
          androidScheduleMode: mode,
          payload: 'consultation:$consultationId',
        );
        return mode == AndroidScheduleMode.exactAllowWhileIdle
            ? ReminderStatus.scheduled
            : ReminderStatus.scheduledInexact;
      } catch (_) {
        // Fall through to the next mode; if both fail, report unsupported.
      }
    }
    return ReminderStatus.unsupported;
  }

  /// Cancels the reminder for one consultation, used when a booking is undone.
  static Future<void> cancel(int consultationId) async {
    if (!await _ensureInitialised()) return;
    try {
      await _plugin.cancel(id: _notificationId(consultationId));
    } catch (_) {}
  }

  static String _two(int v) => v.toString().padLeft(2, '0');

  /// The Arabic sentence shown under the toggle, so the customer knows what will
  /// happen before they turn it on.
  static String describe(ReminderStatus status, DateTime appointment) {
    final time = '${_two(appointment.hour)}:${_two(appointment.minute)}';
    switch (status) {
      case ReminderStatus.scheduled:
        return 'سيصلك تذكير على هذا الجهاز قبل الموعد بساعة ($time).';
      case ReminderStatus.scheduledInexact:
        return 'سيصلك تذكير قريباً من الموعد ($time)؛ قد يتأخر قليلاً حسب إعدادات النظام.';
      case ReminderStatus.permissionDenied:
        return 'لم يُمنح إذن الإشعارات، لذا لن يصل تذكير. يمكنك تفعيله من إعدادات الجهاز.';
      case ReminderStatus.inThePast:
        return 'الموعد قريب جداً، لذا لم يُضبط تذكير.';
      case ReminderStatus.unsupported:
        return 'تعذّر ضبط تذكير على هذا الجهاز.';
    }
  }
}
