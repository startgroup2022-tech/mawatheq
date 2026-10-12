import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/public_content.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../auth/login_screen.dart';
import '../auth/register_screen.dart';
import 'pricing_screen.dart';
import 'public_info_screen.dart';
import 'public_service_list.dart';

/// The public homepage a guest browses without an account.
///
/// Every section maps to a capability the platform actually ships, and the pricing
/// preview is read from `GET /api/v1/plans.php` — nothing here is invented, and no
/// private data (cases, documents, consultations, profiles) is reachable from it.
class PublicHomeScreen extends StatefulWidget {
  const PublicHomeScreen({super.key, this.repository, this.embedded = false});

  /// Injectable for tests; production uses the shared client.
  final ApiRepository? repository;

  /// When true the page renders only its content, without its own `Scaffold` or
  /// app bar, so it can live inside [PublicGuestShell] without a duplicated header.
  final bool embedded;

  @override
  State<PublicHomeScreen> createState() => _PublicHomeScreenState();
}

class _PublicHomeScreenState extends State<PublicHomeScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<Plan> _plans = const [];
  bool _loadingPlans = true;
  String? _plansError;

  @override
  void initState() {
    super.initState();
    _loadPlans();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = true;
      _plansError = null;
    });
    try {
      final plans = await _repo.plans();
      if (!mounted) return;
      setState(() {
        _plans = plans;
        _loadingPlans = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loadingPlans = false;
        _plansError = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loadingPlans = false;
        _plansError = 'تعذّر تحميل الخطط. أعد المحاولة.';
      });
    }
  }

  bool get _isRtl => context.read<LocaleController>().isRtl;

  String _t(List<String> pair) => PublicContent.pick(pair, _isRtl);

  @override
  Widget build(BuildContext context) {
    final rtl = _isRtl;

    if (widget.embedded) {
      return RefreshIndicator(
        onRefresh: _loadPlans,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
          children: _content(rtl),
        ),
      );
    }

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
            // Flexible so a narrow phone or a long locale string ellipsises
            // instead of overflowing the toolbar.
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
      body: RefreshIndicator(
        onRefresh: _loadPlans,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
          children: _content(rtl),
        ),
      ),
    );
  }

  /// The homepage sections, shared by the standalone page and the guest shell.
  List<Widget> _content(bool rtl) => [
        _hero(rtl),
        const SizedBox(height: 22),
        _section(
          title: _t(PublicContent.platformTitle),
          body: _t(PublicContent.platformBody),
        ),
        const SizedBox(height: 22),
        _legalAi(rtl),
        const SizedBox(height: 22),
        SectionHeader(title: _t(PublicContent.featuresTitle)),
        Text(_t(PublicContent.featuresSub),
            style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 12),
        _featureGrid(rtl),
        const SizedBox(height: 24),
        _services(rtl),
        const SizedBox(height: 24),
        _solutions(rtl),
        const SizedBox(height: 24),
        _section(
          title: _t(PublicContent.caseManagementTitle),
          body: _t(PublicContent.caseManagementBody),
          icon: Icons.folder_outlined,
        ),
        const SizedBox(height: 16),
        _section(
          title: _t(PublicContent.documentsTitle),
          body: _t(PublicContent.documentsBody),
          icon: Icons.description_outlined,
        ),
        const SizedBox(height: 16),
        _section(
          title: _t(PublicContent.consultationsTitle),
          body: _t(PublicContent.consultationsBody),
          icon: Icons.forum_outlined,
        ),
        const SizedBox(height: 24),
        _protectedArea(rtl),
        const SizedBox(height: 24),
        _pricingPreview(rtl),
        const SizedBox(height: 24),
        _howItWorks(rtl),
        const SizedBox(height: 24),
        _faq(rtl),
        const SizedBox(height: 24),
        _finalCta(rtl),
      ];

  // ------------------------------------------------------------------ sections

  Widget _hero(bool rtl) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        gradient: AppColors.lightSurface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: const Color(0xFFCFE7F2)),
        boxShadow: AppShadow.sm,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              gradient: AppColors.cyanAccent,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Text(
              _t(PublicContent.heroBadge),
              style: const TextStyle(
                fontSize: AppFontSize.xs,
                fontWeight: FontWeight.w700,
                color: AppColors.navyDeep,
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.s3),
          Text(
            _t(PublicContent.heroTitle),
            style: Theme.of(context)
                .textTheme
                .headlineSmall
                ?.copyWith(color: AppColors.navy, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: AppSpacing.s2),
          Text(_t(PublicContent.heroBody),
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AppColors.textMuted,
                    height: 1.7,
                  )),
          const SizedBox(height: AppSpacing.s5),
          PrimaryButton(
            label: rtl ? 'إنشاء حساب مجاني' : 'Create a free account',
            icon: Icons.person_add_alt,
            expand: true,
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const RegisterScreen()),
            ),
          ),
          const SizedBox(height: 10),
          SecondaryButton(
            label: rtl ? 'استكشف المنصة' : 'Explore the platform',
            icon: Icons.explore_outlined,
            expand: true,
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => const PublicInfoScreen(section: InfoSection.services),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _section({
    required String title,
    required String body,
    IconData? icon,
  }) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: AppColors.border),
        boxShadow: AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (icon != null) ...[
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: AppColors.infoBg,
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: Icon(icon, size: 22, color: AppColors.teal),
            ),
            const SizedBox(height: 12),
          ],
          Text(title,
              style: Theme.of(context)
                  .textTheme
                  .titleLarge
                  ?.copyWith(color: AppColors.navy)),
          const SizedBox(height: 8),
          Text(body,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.7)),
        ],
      ),
    );
  }

  Widget _legalAi(bool rtl) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        gradient: AppColors.brandHero,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        boxShadow: AppShadow.md,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.16),
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Text(
              _t(PublicContent.aiEyebrow),
              style: const TextStyle(
                fontSize: AppFontSize.xs,
                fontWeight: FontWeight.w700,
                color: Colors.white,
              ),
            ),
          ),
          const SizedBox(height: 14),
          Text(
            _t(PublicContent.aiTitle),
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 10),
          Text(
            _t(PublicContent.aiBody),
            style: TextStyle(
              fontSize: AppFontSize.sm,
              height: 1.8,
              color: Colors.white.withValues(alpha: 0.92),
            ),
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.verified_outlined, size: 16, color: AppColors.cyan),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _t(PublicContent.aiNote),
                  style: TextStyle(
                    fontSize: AppFontSize.xs,
                    height: 1.6,
                    color: Colors.white.withValues(alpha: 0.85),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _featureGrid(bool rtl) {
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 1.55,
      children: [
        for (final f in PublicContent.features)
          _Tile(
            icon: _iconFor(f.$1),
            label: rtl ? f.$2 : f.$3,
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => PublicInfoScreen(
                  section: InfoSection.services,
                  highlight: rtl ? f.$2 : f.$3,
                ),
              ),
            ),
          ),
      ],
    );
  }

  /// The public services a visitor can read about but must sign in to use.
  ///
  /// Tapping a card raises the authentication prompt naming that service — it never
  /// opens a protected dashboard and never fires a request the server would reject.
  Widget _services(bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: _t(PublicContent.servicesTitle)),
        Text(_t(PublicContent.servicesSub),
            style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 12),
        PublicServiceList(rtl: rtl),
      ],
    );
  }

  Widget _solutions(bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: _t(PublicContent.solutionsTitle)),
        const SizedBox(height: 4),
        for (var i = 0; i < PublicContent.solutions.length; i++)
          Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: AppColors.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(_iconFor(PublicContent.solutions[i].$1),
                        size: 18, color: AppColors.teal),
                    const SizedBox(width: 8),
                    Text(
                      rtl
                          ? PublicContent.solutions[i].$2
                          : PublicContent.solutions[i].$3,
                      style: Theme.of(context)
                          .textTheme
                          .titleMedium
                          ?.copyWith(color: AppColors.navy),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  _t([PublicContent.solutionsBody[i].$1, PublicContent.solutionsBody[i].$2]),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.7),
                ),
              ],
            ),
          ),
      ],
    );
  }

  /// The private areas a guest cannot enter. Tapping one raises the authentication
  /// prompt instead of firing a request the server would reject — the app never
  /// attempts a protected call on behalf of a guest.
  Widget _protectedArea(bool rtl) {
    const entries = <(ProtectedDestination, IconData, String, String)>[
      (ProtectedDestination.cases, Icons.folder_outlined, 'القضايا', 'Cases'),
      (ProtectedDestination.documents, Icons.description_outlined, 'المستندات', 'Documents'),
      (ProtectedDestination.legalAi, Icons.auto_awesome_outlined, 'المساعد الذكي', 'Assistant'),
      (ProtectedDestination.consultations, Icons.forum_outlined, 'الاستشارات', 'Consultations'),
      (ProtectedDestination.notifications, Icons.notifications_outlined, 'الإشعارات', 'Notifications'),
      (ProtectedDestination.account, Icons.person_outline, 'حسابي', 'Account'),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: rtl ? 'منطقتك الخاصة' : 'Your private area',
        ),
        Text(
          rtl
              ? 'هذه الأقسام خاصة بحسابك، ولا يمكن تصفّحها كزائر.'
              : 'These sections belong to your account and cannot be browsed as a guest.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
            for (final e in entries)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                // Material carries the surface and border so the tile's ink paints
                // on it, instead of a decorated ancestor hiding the splash.
                child: Material(
                  color: AppColors.surface,
                  clipBehavior: Clip.antiAlias,
                  shape: RoundedRectangleBorder(
                    side: const BorderSide(color: AppColors.border),
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                  ),
                  child: ListTile(
                    leading: Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        color: AppColors.surface3,
                        borderRadius: BorderRadius.circular(AppRadius.md),
                      ),
                      child: Icon(e.$2, size: 20, color: AppColors.textMuted),
                    ),
                    title: Text(
                      rtl ? e.$3 : e.$4,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                    trailing: const Icon(Icons.lock_outline,
                        size: 17, color: AppColors.textSubtle),
                    onTap: () => requireAccount(context, e.$1),
                  ),
                ),
              ),
      ],
    );
  }

  Widget _pricingPreview(bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: rtl ? 'الأسعار' : 'Pricing',
          actionLabel: rtl ? 'عرض الكل' : 'View all',
          onAction: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const PricingScreen()),
          ),
        ),
        if (_loadingPlans)
          const LoadingSkeleton(count: 2, height: 110)
        else if (_plansError != null)
          // An unavailable pricing API is stated honestly — never filled with
          // invented numbers.
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.warningBg,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: const Color(0xFFF3E0BE)),
            ),
            child: Row(
              children: [
                const Icon(Icons.cloud_off_outlined,
                    size: 18, color: AppColors.warning),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    rtl
                        ? 'تعذّر تحميل الأسعار من الخادم الآن. حدّث الصفحة لاحقاً.'
                        : 'Pricing could not be loaded from the server right now. '
                            'Try again later.',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.warning,
                      height: 1.6,
                    ),
                  ),
                ),
              ],
            ),
          )
        else if (_plans.isEmpty)
          Text(
            rtl ? 'لم تُنشر أي خطط بعد.' : 'No plans published yet.',
            style: Theme.of(context).textTheme.bodySmall,
          )
        else
          for (final plan in _plans.take(3))
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _PlanPreview(plan: plan, rtl: rtl),
            ),
      ],
    );
  }

  Widget _howItWorks(bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: _t(PublicContent.howTitle)),
        const SizedBox(height: 4),
        for (var i = 0; i < PublicContent.steps.length; i++)
          Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: AppColors.border),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 30,
                  height: 30,
                  decoration: const BoxDecoration(
                    color: AppColors.navy,
                    shape: BoxShape.circle,
                  ),
                  child: Center(
                    child: Text(
                      '${i + 1}',
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 13,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        rtl
                            ? PublicContent.steps[i].$2
                            : PublicContent.steps[i].$3,
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(color: AppColors.navy),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _t([PublicContent.stepsBody[i].$1, PublicContent.stepsBody[i].$2]),
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(height: 1.7),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _faq(bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: _t(PublicContent.faqTitle)),
        const SizedBox(height: 4),
        for (var i = 0; i < PublicContent.faqs.length; i++)
          Theme(
            data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(AppRadius.lg),
                border: Border.all(color: AppColors.border),
              ),
              child: ExpansionTile(
                shape: const Border(),
                collapsedShape: const Border(),
                title: Text(
                  rtl ? PublicContent.faqs[i].$1 : PublicContent.faqs[i].$2,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                ),
                childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                children: [
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      rtl
                          ? PublicContent.faqAnswers[i].$1
                          : PublicContent.faqAnswers[i].$2,
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(height: 1.8),
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _finalCta(bool rtl) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: AppColors.border),
        boxShadow: AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            _t(PublicContent.finalTitle),
            textAlign: TextAlign.center,
            style: Theme.of(context)
                .textTheme
                .titleLarge
                ?.copyWith(color: AppColors.navy),
          ),
          const SizedBox(height: 8),
          Text(
            _t(PublicContent.finalBody),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.7),
          ),
          const SizedBox(height: 18),
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
          const SizedBox(height: 12),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              TextButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) =>
                        const PublicInfoScreen(section: InfoSection.about),
                  ),
                ),
                child: Text(rtl ? 'عن مواثيق' : 'About Mawatheq'),
              ),
              TextButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) =>
                        const PublicInfoScreen(section: InfoSection.contact),
                  ),
                ),
                child: Text(rtl ? 'تواصل معنا' : 'Contact us'),
              ),
            ],
          ),
          // Leaving guest mode is explicit, so a guest is never silently dropped
          // back at the welcome screen.
          if (context.watch<AuthController>().isGuest)
            TextButton.icon(
              onPressed: () => context.read<AuthController>().exitGuest(),
              icon: const Icon(Icons.exit_to_app, size: 16),
              label: Text(rtl ? 'إنهاء وضع الزائر' : 'Exit guest mode'),
            ),
        ],
      ),
    );
  }

  static IconData _iconFor(String key) => switch (key) {
    'ai' || 'analyze' => Icons.auto_awesome_outlined,
    'laws' => Icons.menu_book_outlined,
    'cases' => Icons.folder_outlined,
    'documents' => Icons.description_outlined,
    'compare' => Icons.compare_arrows_outlined,
    'firms' => Icons.business_outlined,
    'team' => Icons.groups_outlined,
    'client_portal' => Icons.people_outline,
    'consultations' || 'consult' => Icons.forum_outlined,
    'deadlines' => Icons.event_outlined,
    'notifications' => Icons.notifications_outlined,
    'invoices' => Icons.receipt_long_outlined,
    'security' => Icons.security_outlined,
    'draft' => Icons.edit_document,
    'represent' => Icons.handshake_outlined,
    'client' => Icons.person_outline,
    'lawyer' => Icons.balance_outlined,
    'firm' => Icons.apartment_outlined,
    'account' => Icons.person_add_alt,
    'workspace' => Icons.dashboard_customize_outlined,
    'work' => Icons.work_outline,
    'follow' => Icons.notifications_active_outlined,
    _ => Icons.check_circle_outline,
  };
}

/// One feature/service tile in the public grid.
class _Tile extends StatelessWidget {
  const _Tile({required this.icon, required this.label, this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: AppColors.infoBg,
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                ),
                child: Icon(icon, size: 18, color: AppColors.teal),
              ),
              Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: AppFontSize.sm,
                  fontWeight: FontWeight.w600,
                  height: 1.4,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Compact plan row in the homepage pricing preview. Prices come from the API.
class _PlanPreview extends StatelessWidget {
  const _PlanPreview({required this.plan, required this.rtl});

  final Plan plan;
  final bool rtl;

  @override
  Widget build(BuildContext context) {
    final price = plan.priceMonthly;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(
          color: plan.isFeatured ? AppColors.teal : AppColors.border,
          width: plan.isFeatured ? 1.6 : 1,
        ),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  rtl ? plan.nameAr : (plan.nameEn.isEmpty ? plan.nameAr : plan.nameEn),
                  style: Theme.of(context)
                      .textTheme
                      .titleMedium
                      ?.copyWith(color: AppColors.navy),
                ),
                if (plan.descriptionAr.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    rtl ? plan.descriptionAr : plan.descriptionEn,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 12),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                price <= 0 ? (rtl ? 'مجاناً' : 'Free') : price.toStringAsFixed(0),
                style: Theme.of(context)
                    .textTheme
                    .titleLarge
                    ?.copyWith(color: AppColors.navy, fontWeight: FontWeight.w800),
              ),
              if (price > 0)
                Text(
                  rtl ? 'ر.س / شهرياً' : 'SAR / month',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
            ],
          ),
        ],
      ),
    );
  }
}
