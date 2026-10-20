import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../auth/login_screen.dart';
import '../auth/register_screen.dart';
import 'home_tab_public.dart';
import 'pricing_screen.dart';
import 'public_info_screen.dart';
import 'public_lawyers_screen.dart';

/// The shell a guest browses: the consumer app, without an account.
///
/// This is deliberately a different shell from the authenticated [HomeShell], and it
/// is a consumer experience rather than a marketing site: home, lawyers, a
/// consultation entry point, and the account doorway. The private dashboard tabs
/// (cases, documents, firm administration) are not reachable from here, so entering
/// guest mode cannot unlock an authenticated route.
///
/// It reuses [HomeShell]'s tab layout, colours and app bar so the app looks like one
/// product across the sign-in boundary rather than two.
class PublicGuestShell extends StatefulWidget {
  const PublicGuestShell({super.key, this.repository, this.initialIndex = 0});

  /// Injectable for tests; production uses the shared client.
  final ApiRepository? repository;

  final int initialIndex;

  @override
  State<PublicGuestShell> createState() => _PublicGuestShellState();
}

class _PublicGuestShellState extends State<PublicGuestShell> {
  /// The selected bottom-nav destination. It is NOT the page index: "Consult" is a
  /// nav entry with no page, so the two do not line up. Keeping them separate is what
  /// stops "Account" from indexing past the end of the page list.
  late int _navIndex = _navIndexForPage(widget.initialIndex);

  static const _consultNavIndex = 2;

  /// Maps a page index (0..2) onto its nav position, skipping the Consult entry.
  static int _navIndexForPage(int page) => page < _consultNavIndex ? page : page + 1;

  int get _pageIndex =>
      _navIndex < _consultNavIndex ? _navIndex : _navIndex - 1;

  bool get _rtl => context.read<LocaleController>().isRtl;

  @override
  Widget build(BuildContext context) {
    final rtl = _rtl;

    final pages = [
      PublicHomeTab(
        repository: widget.repository,
        onOpenLawyers: () => setState(() => _navIndex = 1),
      ),
      PublicLawyersScreen(repository: widget.repository, embedded: true),
      const _GuestAccountTab(),
    ];

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        titleSpacing: AppSpacing.s4,
        title: Row(
          children: [
            const BrandLogo(size: 28),
            const SizedBox(width: AppSpacing.s2),
            const Flexible(
              child: Text(
                'مواثيق',
                overflow: TextOverflow.ellipsis,
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
        actions: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(
              color: AppColors.surface3,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Text(
              rtl ? 'زائر' : 'Guest',
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: AppColors.textMuted,
              ),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const LoginScreen()),
            ),
            child: Text(rtl ? 'تسجيل الدخول' : 'Sign in'),
          ),
        ],
      ),
      body: IndexedStack(index: _pageIndex, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _navIndex,
        onDestinationSelected: (i) {
          if (i == _consultNavIndex) {
            // Consultation needs an account, so it opens the sign-in prompt instead
            // of switching to a page the server would refuse to fill.
            requireAccount(context, ProtectedDestination.consultations);
            return;
          }
          setState(() => _navIndex = i);
        },
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.home_outlined),
            selectedIcon: const Icon(Icons.home),
            label: rtl ? 'الرئيسية' : 'Home',
          ),
          NavigationDestination(
            icon: const Icon(Icons.gavel_outlined),
            selectedIcon: const Icon(Icons.gavel),
            label: rtl ? 'المحامون' : 'Lawyers',
          ),
          NavigationDestination(
            icon: const Icon(Icons.forum_outlined),
            selectedIcon: const Icon(Icons.forum),
            label: rtl ? 'استشارة' : 'Consult',
          ),
          NavigationDestination(
            icon: const Icon(Icons.person_outline),
            selectedIcon: const Icon(Icons.person),
            label: rtl ? 'حسابي' : 'Account',
          ),
        ],
      ),
    );
  }
}

/// The guest "account" tab: sign in, register, or leave guest mode.
///
/// It never shows private account data — there is no account. It is the doorway to
/// creating one, plus the explicit way back to the welcome screen.
class _GuestAccountTab extends StatelessWidget {
  const _GuestAccountTab();

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;

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
            child: const Icon(Icons.person_outline,
                size: 34, color: AppColors.navyDeep),
          ),
        ),
        const SizedBox(height: 18),
        Text(
          rtl ? 'أنت تتصفّح كزائر' : 'You are browsing as a guest',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleLarge,
        ),
        const SizedBox(height: 8),
        Text(
          rtl
              ? 'أنشئ حساباً للوصول إلى القضايا والمستندات والاستشارات والمساعد '
                  'القانوني، أو سجّل الدخول إن كان لديك حساب.'
              : 'Create an account to reach cases, documents, consultations and the '
                  'legal assistant, or sign in if you already have one.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.8),
        ),
        const SizedBox(height: 22),
        PrimaryButton(
          label: rtl ? 'تسجيل الدخول' : 'Sign in',
          icon: Icons.login,
          expand: true,
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const LoginScreen()),
          ),
        ),
        const SizedBox(height: 10),
        SecondaryButton(
          label: rtl ? 'إنشاء حساب' : 'Create account',
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
          leading: const Icon(Icons.sell_outlined, color: AppColors.navy),
          title: Text(rtl ? 'الأسعار والخطط' : 'Pricing and plans'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const PricingScreen()),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.info_outline, color: AppColors.navy),
          title: Text(rtl ? 'من نحن' : 'About us'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) =>
                  const PublicInfoScreen(section: InfoSection.about),
            ),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.support_agent, color: AppColors.navy),
          title: Text(rtl ? 'تواصل معنا' : 'Contact us'),
          trailing: const Icon(Icons.chevron_left, size: 18),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) =>
                  const PublicInfoScreen(section: InfoSection.contact),
            ),
          ),
        ),
        const SizedBox(height: 8),
        TextButton.icon(
          onPressed: () => context.read<AuthController>().exitGuest(),
          icon: const Icon(Icons.exit_to_app, size: 18),
          label: Text(rtl ? 'إنهاء وضع الزائر' : 'Exit guest mode'),
        ),
      ],
    );
  }
}
