import 'package:flutter/material.dart';

import '../features/account/account_screen.dart';
import '../features/consultations/consultations_screen.dart';
import '../features/home/home_shell.dart';
import '../features/notifications/notifications_screen.dart';
import 'network/models.dart';

/// A place a signed-in user can reach that a guest cannot.
///
/// The app records the destination when it has to stop a guest, and replays it
/// after a successful sign-in, so the visitor lands where they were going instead
/// of back at the dashboard — but only when the freshly signed-in account is
/// actually permitted to be there. [allows] answers that question, and a denial is
/// surfaced as a real access-denied screen rather than an empty page.
enum ProtectedDestination {
  dashboard('الرئيسية', 'Home', 0, null, true),
  cases('إدارة القضايا', 'Case management', 1, 'cases.view', true),
  legalAi('المساعد القانوني الذكي', 'Legal AI assistant', 2, null, true),
  documents('إدارة المستندات', 'Document management', 3, 'documents.view', true),
  account('حسابي', 'Account', 4, null, true),
  consultations('الاستشارات القانونية', 'Legal consultations', null, 'consultations.view', true),
  notifications('الإشعارات', 'Notifications', null, null, true);

  const ProtectedDestination(
    this.labelAr,
    this.labelEn,
    this.tabIndex,
    this.requiredPermission,
    this.clientAllowed,
  );

  final String labelAr;
  final String labelEn;

  /// The bottom-nav index when the destination is a shell tab, otherwise null for
  /// screens that are pushed on top of the shell.
  final int? tabIndex;

  /// The tenant capability a firm member needs, matching the server's catalog in
  /// `includes/tenant.php`. Null means the destination is not permission-gated for
  /// staff — every signed-in account may open it.
  final String? requiredPermission;

  /// Whether a client may open this. A client has no tenant capabilities, so
  /// checking [requiredPermission] against a client would deny them their own data.
  final bool clientAllowed;

  String label(bool isRtl) => isRtl ? labelAr : labelEn;

  /// Whether [user] may open this destination.
  ///
  /// Clients are decided by [clientAllowed] because they hold no tenant permissions;
  /// staff are decided by the server-issued [requiredPermission] they carry, so the
  /// app agrees with the server instead of guessing.
  bool allows(SessionUser user) {
    if (user.isClient) return clientAllowed;
    final permission = requiredPermission;
    if (permission == null) return true;
    return user.can(permission);
  }

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
  String? _selectedPlan;

  ProtectedDestination? get pendingIntent => _pending;

  bool get hasPendingIntent => _pending != null;

  /// The plan name a guest chose on the pricing page, so it can be carried into
  /// sign-in. This is a display name only — no price, no id, no entitlement — so it
  /// cannot be mistaken for an activated subscription.
  String? get selectedPlan => _selectedPlan;

  /// Records the plan the visitor picked before being asked to sign in.
  void rememberPlan(String planName) {
    _selectedPlan = planName;
    notifyListeners();
  }

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

/// Shown when a signed-in account reaches a service its permissions do not cover.
///
/// A firm member can be signed in and still lack a capability (a staff member has no
/// case-creation rights, for instance). Rather than opening an empty dashboard or
/// pretending the action worked, the app says plainly that this account cannot use
/// the service and offers a way back.
class AccessDeniedScreen extends StatelessWidget {
  const AccessDeniedScreen({
    super.key,
    required this.destination,
    required this.isRtl,
    this.onBack,
  });

  final ProtectedDestination destination;
  final bool isRtl;

  /// Overrides the default "go to my home" action; used by tests.
  final VoidCallback? onBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Icon(Icons.lock_outline, size: 56, color: theme.colorScheme.primary),
              const SizedBox(height: 18),
              Text(
                isRtl ? 'لا تملك صلاحية الوصول' : 'You do not have access',
                textAlign: TextAlign.center,
                style: theme.textTheme.titleLarge,
              ),
              const SizedBox(height: 10),
              Text(
                isRtl
                    ? 'حسابك لا يملك الصلاحية اللازمة لفتح «${destination.labelAr}». '
                        'تواصل مع مدير المكتب لمنحك الصلاحية.'
                    : 'Your account lacks the permission required to open '
                        '“${destination.labelEn}”. Ask your firm administrator to grant it.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed:
                    onBack ?? () => Navigator.of(context).maybePop(),
                child: Text(isRtl ? 'العودة إلى حسابي' : 'Back to my account'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
