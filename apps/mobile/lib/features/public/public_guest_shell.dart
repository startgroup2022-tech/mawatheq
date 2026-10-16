import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../auth/login_screen.dart';
import '../auth/register_screen.dart';
import 'pricing_screen.dart';
import 'public_home_screen.dart';
import 'public_info_screen.dart';

/// The shell a guest browses: public navigation only.
///
/// This is deliberately a different shell from the authenticated [HomeShell]. A guest
/// sees the marketing tabs — home, services, pricing, about, account — and never the
/// private dashboard tabs (cases, documents, firm administration). Entering guest mode
/// therefore cannot unlock an authenticated route.
class PublicGuestShell extends StatefulWidget {
  const PublicGuestShell({super.key, this.repository, this.initialIndex = 0});

  /// Injectable for tests; production uses the shared client.
  final ApiRepository? repository;

  final int initialIndex;

  @override
  State<PublicGuestShell> createState() => _PublicGuestShellState();
}

class _PublicGuestShellState extends State<PublicGuestShell> {
  late int _index = widget.initialIndex;

  bool get _rtl => context.read<LocaleController>().isRtl;

  @override
  Widget build(BuildContext context) {
    final rtl = _rtl;

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
      body: IndexedStack(
        index: _index,
        children: [
          PublicHomeScreen(repository: widget.repository, embedded: true),
          _ServicesTab(repository: widget.repository),
          PricingScreen(repository: widget.repository),
          const PublicInfoScreen(section: InfoSection.about, embedded: true),
          const _GuestAccountTab(),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.home_outlined),
            selectedIcon: const Icon(Icons.home),
            label: rtl ? 'الرئيسية' : 'Home',
          ),
          NavigationDestination(
            icon: const Icon(Icons.grid_view_outlined),
            selectedIcon: const Icon(Icons.grid_view),
            label: rtl ? 'الخدمات' : 'Services',
          ),
          NavigationDestination(
            icon: const Icon(Icons.sell_outlined),
            selectedIcon: const Icon(Icons.sell),
            label: rtl ? 'الأسعار' : 'Pricing',
          ),
          NavigationDestination(
            icon: const Icon(Icons.info_outline),
            selectedIcon: const Icon(Icons.info),
            label: rtl ? 'من نحن' : 'About',
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

/// The services tab: every public service, each raising the auth prompt when tapped.
class _ServicesTab extends StatelessWidget {
  const _ServicesTab({this.repository});

  final ApiRepository? repository;

  @override
  Widget build(BuildContext context) {
    return PublicInfoScreen(section: InfoSection.services, embedded: true);
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
