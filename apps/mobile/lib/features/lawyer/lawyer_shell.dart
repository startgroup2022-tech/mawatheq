import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../account/account_screen.dart';
import '../notifications/notifications_screen.dart';
import 'lawyer_consultations_screen.dart';
import 'lawyer_dashboard_screen.dart';
import 'lawyer_profile_screen.dart';
import 'lawyer_wallet_screen.dart';

/// The signed-in lawyer's shell.
///
/// It mirrors the customer shell's structure on purpose — one nested navigator per
/// tab, tabs built on first visit, the same [PopScope] back handling — so the two
/// apps behave identically and neither is a second implementation of the same idea.
/// What differs is the destination list: a lawyer acts on consultations and money,
/// not on a public directory.
class LawyerShell extends StatefulWidget {
  const LawyerShell({super.key, this.repository, this.initialIndex = 0});

  final ApiRepository? repository;
  final int initialIndex;

  @override
  State<LawyerShell> createState() => _LawyerShellState();
}

class _LawyerShellState extends State<LawyerShell> {
  static const int _tabCount = 5;

  late int _index = widget.initialIndex;
  final Map<int, Widget> _built = {};
  final List<GlobalKey<NavigatorState>> _navKeys = List.generate(
    _tabCount,
    (_) => GlobalKey<NavigatorState>(),
  );

  Widget _pageFor(int i) {
    return _built.putIfAbsent(i, () {
      switch (i) {
        case 1:
          return LawyerConsultationsScreen(repository: widget.repository);
        case 2:
          return LawyerWalletScreen(repository: widget.repository);
        case 3:
          return _LawyerNotificationsTab(
            child: NotificationsScreen(repository: widget.repository),
          );
        case 4:
          return _LawyerAccountTab(
            repository: widget.repository,
            onOpenProfile: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => LawyerProfileScreen(repository: widget.repository),
              ),
            ),
          );
        default:
          return _LawyerHomeTab(
            repository: widget.repository,
            onOpenConsultations: () => _onTab(1),
          );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    _pageFor(_index);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        _handleBack();
      },
      child: Scaffold(
        backgroundColor: context.tokens.bg,
        body: IndexedStack(
          index: _index,
          children: [for (var i = 0; i < _tabCount; i++) _tabNavigator(i)],
        ),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: _onTab,
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.dashboard_outlined),
              selectedIcon: Icon(Icons.dashboard),
              label: 'لوحتي',
            ),
            NavigationDestination(
              icon: Icon(Icons.forum_outlined),
              selectedIcon: Icon(Icons.forum),
              label: 'الاستشارات',
            ),
            NavigationDestination(
              icon: Icon(Icons.account_balance_wallet_outlined),
              selectedIcon: Icon(Icons.account_balance_wallet),
              label: 'المحفظة',
            ),
            NavigationDestination(
              icon: Icon(Icons.notifications_none),
              selectedIcon: Icon(Icons.notifications),
              label: 'التنبيهات',
            ),
            NavigationDestination(
              icon: Icon(Icons.person_outline),
              selectedIcon: Icon(Icons.person),
              label: 'حسابي',
            ),
          ],
        ),
      ),
    );
  }

  Widget _tabNavigator(int i) {
    if (!_built.containsKey(i)) return const SizedBox.shrink();
    return Navigator(
      key: _navKeys[i],
      onGenerateRoute: (settings) => MaterialPageRoute<void>(
        settings: settings,
        builder: (_) => _built[i] ?? const SizedBox.shrink(),
      ),
    );
  }

  void _handleBack() {
    final nav = _navKeys[_index].currentState;
    if (nav != null && nav.canPop()) {
      nav.pop();
      return;
    }
    if (_index != 0) {
      _onTab(0);
      return;
    }
    // The shell itself never pops; the platform closes the app.
    Navigator.of(context).maybePop();
  }

  void _onTab(int i) {
    if (i == _index) {
      _navKeys[i].currentState?.popUntil((route) => route.isFirst);
      return;
    }
    _pageFor(i);
    setState(() => _index = i);
  }
}

/// The dashboard tab root: an app bar with the lawyer's name and the notification
/// bell over the dashboard body.
class _LawyerHomeTab extends StatelessWidget {
  const _LawyerHomeTab({required this.repository, required this.onOpenConsultations});

  final ApiRepository? repository;
  final VoidCallback onOpenConsultations;

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthController>().user;
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: user.name.isNotEmpty ? user.name : 'لوحة المحامي',
        actions: [
          IconButton(
            tooltip: 'الإشعارات',
            icon: const Icon(Icons.notifications_none),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => NotificationsScreen(repository: repository),
              ),
            ),
          ),
        ],
      ),
      body: LawyerDashboardScreen(
        repository: repository,
        onOpenConsultations: onOpenConsultations,
      ),
    );
  }
}

/// The notifications tab supplies its own Scaffold, so this only adds the app bar
/// context the shell expects when the tab is opened directly.
class _LawyerNotificationsTab extends StatelessWidget {
  const _LawyerNotificationsTab({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => child;
}

/// The account tab. It wraps the shared [AccountScreen] — one account screen for both
/// roles means the sign-out, delete-account and language behaviour cannot drift — and
/// adds the lawyer-only professional profile entry the customer does not have.
class _LawyerAccountTab extends StatelessWidget {
  const _LawyerAccountTab({required this.repository, required this.onOpenProfile});

  final ApiRepository? repository;
  final VoidCallback onOpenProfile;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: 'حسابي',
        actions: [
          IconButton(
            tooltip: 'الملف المهني',
            onPressed: onOpenProfile,
            icon: const Icon(Icons.badge_outlined),
          ),
        ],
      ),
      body: AccountScreen(repository: repository, onOpenProfessionalProfile: onOpenProfile),
    );
  }
}
