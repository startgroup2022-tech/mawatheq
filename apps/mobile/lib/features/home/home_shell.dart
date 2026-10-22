import 'package:flutter/material.dart';
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
/// Previously there were two: `HomeShell` for an account and `PublicGuestShell`
/// for a visitor, with different tabs, different home screens and different app
/// bars. That made the same product look like two apps across the sign-in
/// boundary, and it meant a signed-in customer could not reach the lawyer
/// directory at all. This shell keeps one tab set and one home; the session only
/// changes the greeting, the badge and whether a tab needs an account.
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
  late int _index = widget.initialIndex;

  /// Tabs are built on first visit and then kept, so switching back is instant and
  /// scroll position survives. They are deliberately *not* all built up front: an
  /// IndexedStack of five screens starts five loads at once, and a tab left spinning
  /// in the background keeps the frame scheduler busy.
  final Map<int, Widget> _built = {};

  /// Null until the first build, so the first frame never drops the cache.
  bool? _accountTabWasSignedIn;

  Widget _pageFor(int i) {
    return _built.putIfAbsent(i, () {
      final signedIn = context.read<AuthController>().isSignedIn;
      switch (i) {
        case 1:
          return const CasesScreen();
        case 2:
          return const AssistantScreen();
        case 3:
          return const DocumentsScreen();
        case 4:
          return signedIn ? const AccountScreen() : const _GuestAccountTab();
        default:
          return HomeTab(
            repository: widget.repository,
            onNavigate: _onTab,
            onOpenLawyers: _openDirectory,
          );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();
    final signedIn = auth.isSignedIn;
    final user = auth.user;

    // Signing in or out changes what the account tab holds, so its cached entry is
    // dropped and rebuilt on the next visit.
    if (_accountTabWasSignedIn != null && _accountTabWasSignedIn != signedIn) {
      _built.remove(4);
    }
    _accountTabWasSignedIn = signedIn;

    // Materialise the visible tab: nothing else has asked for it on the first frame.
    _pageFor(_index);

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: signedIn && user.name.isNotEmpty ? user.name : null,
        actions: [
          if (signedIn)
            IconButton(
              tooltip: 'الإشعارات',
              icon: const Icon(Icons.notifications_none),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const NotificationsScreen()),
              ),
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
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const LoginScreen()),
              ),
              child: const Text('تسجيل الدخول'),
            ),
          ],
        ],
      ),
      body: IndexedStack(
        index: _index,
        children: [
          for (var i = 0; i < 5; i++)
            _built[i] ?? const SizedBox.shrink(),
        ],
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
    );
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
    // Materialise the destination before showing it, so its first frame is content.
    _pageFor(i);
    setState(() => _index = i);
  }

  void _openDirectory() {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => PublicLawyersScreen(repository: widget.repository),
      ),
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
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const LoginScreen()),
          ),
        ),
        const SizedBox(height: 10),
        SecondaryButton(
          label: 'إنشاء حساب',
          icon: Icons.person_add_alt,
          expand: true,
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const RegisterScreen()),
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
            MaterialPageRoute(builder: (_) => const PricingScreen()),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(Icons.info_outline, color: context.tokens.heading),
          title: const Text('من نحن'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => const PublicInfoScreen(section: InfoSection.about),
            ),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(Icons.support_agent, color: context.tokens.heading),
          title: const Text('تواصل معنا'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
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
