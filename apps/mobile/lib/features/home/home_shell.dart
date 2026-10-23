import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/guest_session.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../account/account_screen.dart';
import '../assistant/assistant_screen.dart';
import '../auth/login_screen.dart';
import '../auth/register_screen.dart';
import '../cases/cases_screen.dart';
import '../documents/documents_screen.dart';
import '../notifications/notifications_screen.dart';
import '../public/pricing_screen.dart';
import '../public/public_info_screen.dart';
import '../public/public_lawyers_screen.dart';
import 'home_tab.dart';

/// The one shell the app runs in, for a guest and for a signed-in customer.
///
/// Previously there were two shells (`HomeShell` and `PublicGuestShell`), which
/// made the same product look like two apps across the sign-in boundary. This is
/// the single shell; the session only changes the greeting and whether a tab needs
/// an account.
///
/// ## Why every tab owns a Navigator
///
/// The bottom bar must stay visible while the customer moves through the product —
/// picking a specialty, walking the consultation journey, opening a lawyer profile.
/// A plain `Navigator.push` covers the whole shell, bar included, so the journey
/// used to run full-screen and the bar vanished.
///
/// Each tab therefore owns a nested [Navigator]. A push inside a tab is confined to
/// that tab's area, so the bar and the tab's own app bar stay put, and each tab
/// keeps its own back stack when the customer switches away and back. Only the
/// screens that genuinely must own the whole window — sign-in, registration,
/// password reset, the splash — are pushed on the *root* navigator, which is the
/// documented exception the brief allows.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, this.repository, this.initialIndex = 0});

  /// Injectable for widget tests; production uses the shared client.
  final ApiRepository? repository;

  /// The tab to open on. Always 0 (Home) after sign-in, per the specification;
  /// it exists so a deep link or a test can open another tab directly.
  final int initialIndex;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  static const int _tabCount = 5;

  late int _index = widget.initialIndex;

  /// Tabs are built on first visit and then kept, so switching back is instant and
  /// scroll position survives. They are deliberately *not* all built up front: an
  /// IndexedStack of five screens starts five loads at once, and a tab left spinning
  /// in the background keeps the frame scheduler busy.
  final Map<int, Widget> _built = {};

  /// One navigator per tab, so a push stays inside its tab. Recreated for the
  /// account tab when the session changes, which resets that tab's stack instead of
  /// leaving a signed-in screen sitting under a guest's back button.
  final List<GlobalKey<NavigatorState>> _navKeys = List.generate(
    _tabCount,
    (_) => GlobalKey<NavigatorState>(),
  );

  /// Null until the first build, so the first frame never drops the cache.
  bool? _accountTabWasSignedIn;

  Widget _pageFor(int i) {
    return _built.putIfAbsent(i, () {
      final signedIn = context.read<AuthController>().isSignedIn;
      switch (i) {
        case 1:
          return const _TabScaffold(title: 'القضايا', child: CasesScreen());
        case 2:
          // Not `embedded`: the tab root supplies its own Scaffold and app bar, the
          // same way a pushed route does.
          return const AssistantScreen();
        case 3:
          return const _TabScaffold(
            title: 'المستندات',
            child: DocumentsScreen(),
          );
        case 4:
          return _TabScaffold(
            title: 'حسابي',
            child: signedIn ? const AccountScreen() : const _GuestAccountTab(),
          );
        default:
          return _homeTab();
      }
    });
  }

  /// The home tab: the shell's richest app bar (greeting, notifications, or the
  /// guest badge and sign-in action) over the scrolling home.
  Widget _homeTab() {
    final auth = context.read<AuthController>();
    final signedIn = auth.isSignedIn;
    final user = auth.user;

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: signedIn && user.name.isNotEmpty ? user.name : null,
        actions: [
          if (signedIn)
            IconButton(
              tooltip: 'الإشعارات',
              icon: const Icon(Icons.notifications_none),
              onPressed: () =>
                  _pushInTab(0, (_) => const NotificationsScreen()),
            )
          else ...[
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: context.tokens.surface3,
                borderRadius: BorderRadius.circular(AppRadius.pill),
              ),
              child: Text(
                'زائر',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: context.tokens.textMuted,
                ),
              ),
            ),
            TextButton(
              onPressed: () => _pushAuthRoute((_) => const LoginScreen()),
              child: const Text('تسجيل الدخول'),
            ),
          ],
        ],
      ),
      body: HomeTab(
        repository: widget.repository,
        onNavigate: _onTab,
        onOpenLawyers: _openDirectory,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();
    final signedIn = auth.isSignedIn;

    // Signing in or out changes what the account tab holds, so its cached entry and
    // its back stack are dropped and rebuilt on the next visit.
    if (_accountTabWasSignedIn != null && _accountTabWasSignedIn != signedIn) {
      _built.remove(4);
      _navKeys[4] = GlobalKey<NavigatorState>();
    }
    _accountTabWasSignedIn = signedIn;

    // Materialise the visible tab: nothing else has asked for it on the first frame.
    _pageFor(_index);

    return PopScope(
      // The shell never pops itself: system back is routed to the active tab's
      // navigator first, then to the home tab, and only then closes the app. Letting
      // the framework pop the shell would drop the whole product on one back press.
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
              icon: Icon(Icons.home_outlined),
              selectedIcon: Icon(Icons.home),
              label: 'الرئيسية',
            ),
            NavigationDestination(
              icon: Icon(Icons.folder_outlined),
              selectedIcon: Icon(Icons.folder),
              label: 'القضايا',
            ),
            NavigationDestination(
              icon: Icon(Icons.auto_awesome_outlined),
              selectedIcon: Icon(Icons.auto_awesome),
              label: 'المساعد',
            ),
            NavigationDestination(
              icon: Icon(Icons.description_outlined),
              selectedIcon: Icon(Icons.description),
              label: 'المستندات',
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

  /// A tab's nested navigator. An unvisited tab renders nothing rather than an empty
  /// navigator, which is what keeps five screens from loading at once.
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

  /// Pushes a screen inside a tab, so the bottom bar stays visible.
  void _pushInTab(int tab, WidgetBuilder builder) {
    final nav = _navKeys[tab].currentState;
    if (nav == null) return;
    nav.push(MaterialPageRoute<void>(builder: builder));
  }

  /// Pushes a screen that must own the whole window (sign-in, registration) on the
  /// root navigator. This is the documented full-screen exception: an auth screen
  /// has no tab to belong to and must not sit under the bar.
  void _pushAuthRoute(WidgetBuilder builder) {
    final nav = Navigator.of(context, rootNavigator: true);
    nav.push(MaterialPageRoute<void>(builder: builder));
  }

  /// System back: pop the active tab's stack, else return to the home tab, else let
  /// the platform close the app.
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
    SystemNavigator.pop();
  }

  /// A tab that needs an account raises the sign-in prompt instead of switching to
  /// a page the server would refuse to fill. The gate is a convenience, not the
  /// authorization: every protected endpoint still requires a token.
  void _onTab(int i) {
    if (!context.read<AuthController>().isSignedIn) {
      switch (i) {
        case 1:
          requireAccount(context, ProtectedDestination.cases);
          return;
        case 3:
          requireAccount(context, ProtectedDestination.documents);
          return;
        default:
          break;
      }
    }
    if (i == _index) {
      // Re-tapping the active tab is the familiar "go back to the top of this
      // section" gesture.
      _navKeys[i].currentState?.popUntil((route) => route.isFirst);
      return;
    }
    // Materialise the destination before showing it, so its first frame is content.
    _pageFor(i);
    setState(() => _index = i);
  }

  /// The lawyer directory belongs to the home tab's stack, so it opens with the bar
  /// still in place rather than over the whole shell.
  void _openDirectory() {
    _pushInTab(0, (_) => PublicLawyersScreen(repository: widget.repository));
  }
}

/// A tab root's own `Scaffold`. Each tab supplies its app bar now that the shell no
/// longer draws one: the shell's body is a stack of navigators, and a pushed route
/// inside a tab brings its own bar, so a shell-level bar would show two.
class _TabScaffold extends StatelessWidget {
  const _TabScaffold({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(subtitle: title),
      body: child,
    );
  }
}

/// The account tab for a visitor: sign in, register, and the public pages.
///
/// It never shows private account data — there is no account. It is the doorway to
/// creating one, plus the public information a visitor is allowed to read.
class _GuestAccountTab extends StatelessWidget {
  const _GuestAccountTab();

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        const SizedBox(height: 12),
        Center(
          child: Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(
              gradient: AppColors.cyanAccent,
              borderRadius: BorderRadius.circular(22),
            ),
            child: const Icon(
              Icons.person_outline,
              size: 34,
              color: AppColors.navyDeep,
            ),
          ),
        ),
        const SizedBox(height: 18),
        Text(
          'أنت تتصفّح كزائر',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleLarge,
        ),
        const SizedBox(height: 8),
        Text(
          'أنشئ حساباً للوصول إلى القضايا والمستندات والاستشارات والمساعد '
          'القانوني، أو سجّل الدخول إن كان لديك حساب.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.8),
        ),
        const SizedBox(height: 22),
        PrimaryButton(
          label: 'تسجيل الدخول',
          icon: Icons.login,
          expand: true,
          // Sign-in owns the whole window, so it goes on the root navigator.
          onPressed: () => Navigator.of(
            context,
            rootNavigator: true,
          ).push(MaterialPageRoute<void>(builder: (_) => const LoginScreen())),
        ),
        const SizedBox(height: 10),
        SecondaryButton(
          label: 'إنشاء حساب',
          icon: Icons.person_add_alt,
          expand: true,
          onPressed: () => Navigator.of(context, rootNavigator: true).push(
            MaterialPageRoute<void>(builder: (_) => const RegisterScreen()),
          ),
        ),
        const SizedBox(height: 18),
        const Divider(),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(Icons.sell_outlined, color: context.tokens.heading),
          title: const Text('الأسعار والخطط'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(builder: (_) => const PricingScreen()),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(Icons.info_outline, color: context.tokens.heading),
          title: const Text('من نحن'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) =>
                  const PublicInfoScreen(section: InfoSection.about),
            ),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(Icons.support_agent, color: context.tokens.heading),
          title: const Text('تواصل معنا'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) =>
                  const PublicInfoScreen(section: InfoSection.contact),
            ),
          ),
        ),
        const SizedBox(height: 8),
        // A visitor can leave guest mode without creating an account: guest mode is
        // an opt-in convenience, and a visitor who changes their mind must be able
        // to get back to a plain unauthenticated start.
        TextButton.icon(
          onPressed: () => context.read<AuthController>().exitGuest(),
          icon: const Icon(Icons.exit_to_app, size: 18),
          label: const Text('إنهاء وضع الزائر'),
        ),
      ],
    );
  }
}
