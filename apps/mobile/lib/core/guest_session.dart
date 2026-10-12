import 'package:flutter/material.dart';

import '../features/account/account_screen.dart';
import '../features/consultations/consultations_screen.dart';
import '../features/home/home_shell.dart';
import '../features/notifications/notifications_screen.dart';

/// A place a signed-in user can reach that a guest cannot.
///
/// The app records the destination when it has to stop a guest, and replays it
/// after a successful sign-in, so the visitor lands where they were going instead
/// of back at the dashboard.
enum ProtectedDestination {
  dashboard('الرئيسية', 'Home', 0),
  cases('القضايا', 'Cases', 1),
  assistant('المساعد الذكي', 'Assistant', 2),
  documents('المستندات', 'Documents', 3),
  account('حسابي', 'Account', 4),
  consultations('الاستشارات', 'Consultations', null),
  notifications('الإشعارات', 'Notifications', null);

  const ProtectedDestination(this.labelAr, this.labelEn, this.tabIndex);

  final String labelAr;
  final String labelEn;

  /// The bottom-nav index when the destination is a shell tab, otherwise null for
  /// screens that are pushed on top of the shell.
  final int? tabIndex;

  String label(bool isRtl) => isRtl ? labelAr : labelEn;

  /// The shell to land on, already focused on the right tab.
  Widget buildShell() => HomeShell(initialIndex: tabIndex ?? 0);

  /// Extra screen pushed above the shell, for destinations that are not tabs.
  Widget? buildPushedScreen() => switch (this) {
    ProtectedDestination.consultations => const ConsultationsScreen(),
    ProtectedDestination.notifications => const NotificationsScreen(),
    ProtectedDestination.account => const AccountScreen(),
    _ => null,
  };
}

/// Remembers the destination a guest was denied, so it can be resumed after login.
///
/// This holds UI intent only — no token, no identity, nothing the server would act
/// on. It exists purely so the sign-in flow can be helpful instead of dumping the
/// visitor back at the top of the app.
class GuestSession extends ChangeNotifier {
  ProtectedDestination? _pending;

  ProtectedDestination? get pendingIntent => _pending;

  bool get hasPendingIntent => _pending != null;

  /// Records where the visitor was heading before the sign-in prompt.
  void remember(ProtectedDestination destination) {
    _pending = destination;
    notifyListeners();
  }

  /// Returns the remembered destination and clears it, so a resumed intent is
  /// consumed exactly once.
  ProtectedDestination? consume() {
    final pending = _pending;
    _pending = null;
    if (pending != null) notifyListeners();
    return pending;
  }

  /// Drops any remembered destination (used when the visitor abandons sign-in).
  void clear() {
    if (_pending == null) return;
    _pending = null;
    notifyListeners();
  }
}
