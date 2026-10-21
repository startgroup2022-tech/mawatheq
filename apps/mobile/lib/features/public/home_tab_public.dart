import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/specialty_icons.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/banner_carousel.dart';
import '../../shared/widgets/banner_link_screen.dart';
import '../../shared/widgets/brand_widgets.dart';

/// The guest home experience: what a visitor sees first.
///
/// This is a service screen, not a landing page. It answers "what can I do here,
/// and what do I do next" — search, browse legal specialties, read who the lawyers
/// are, start a consultation — instead of presenting the platform. Every card maps
/// to a capability the API actually serves, and an action that needs an account
/// raises the sign-in prompt rather than pretending to work.
class PublicHomeTab extends StatefulWidget {
  const PublicHomeTab({
    super.key,
    this.repository,
    required this.onOpenLawyers,
  });

  final ApiRepository? repository;

  /// Switches the guest shell to the lawyers tab.
  final VoidCallback onOpenLawyers;

  @override
  State<PublicHomeTab> createState() => _PublicHomeTabState();
}

class _PublicHomeTabState extends State<PublicHomeTab> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<HomeBanner> _banners = const [];
  List<JourneySpecialty> _specialties = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Banners and specialties are independent: a missing banner table must not blank
  /// the specialties, and vice versa. Each failure is tolerated on its own.
  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    List<HomeBanner> banners = const [];
    List<JourneySpecialty> specialties = const [];
    String? failure;

    try {
      banners = await _repo.banners();
    } on ApiException catch (e) {
      failure = e.message;
    } catch (_) {
      failure = 'تعذّر تحميل المحتوى. أعد المحاولة.';
    }

    try {
      specialties = await _repo.journeySpecialties();
    } on ApiException catch (e) {
      failure ??= e.message;
    } catch (_) {
      failure ??= 'تعذّر تحميل المحتوى. أعد المحاولة.';
    }

    if (!mounted) return;
    setState(() {
      _banners = banners;
      _specialties = specialties;
      _loading = false;
      _error = failure;
    });
  }

  bool get _rtl => context.read<LocaleController>().isRtl;

  @override
  Widget build(BuildContext context) {
    final rtl = _rtl;

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: LoadingSkeleton(count: 5),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          _greeting(rtl),
          const SizedBox(height: 16),
          _searchBar(rtl),
          // A failure is reported inline, above the sections that still have content.
          // It must never replace the screen: the slider and the specialty list come
          // from different endpoints, so one missing file would otherwise hide the
          // whole product (greeting, search, services, how-it-works) from the visitor.
          if (_error != null) ...[
            const SizedBox(height: 18),
            _loadNotice(rtl),
          ],
          if (_banners.isNotEmpty) ...[
            const SizedBox(height: 18),
            BannerCarousel(
              banners: _banners,
              onTap: (banner) => _openBanner(banner),
            ),
          ],
          const SizedBox(height: 22),
          SectionHeader(
            title: rtl ? 'تخصصاتنا القانونية' : 'Our legal specialties',
            actionLabel: rtl ? 'المحامون' : 'Lawyers',
            onAction: widget.onOpenLawyers,
          ),
          const SizedBox(height: 4),
          Text(
            rtl
                ? 'اختر التخصص الأقرب لمشكلتك لتبدأ.'
                : 'Pick the specialty closest to your matter to begin.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          _specialtyGrid(rtl),
          const SizedBox(height: 24),
          SectionHeader(title: rtl ? 'خدمات سريعة' : 'Quick services'),
          const SizedBox(height: 12),
          _quickServices(rtl),
          const SizedBox(height: 24),
          SectionHeader(title: rtl ? 'كيف تعمل مواثيق' : 'How Mawatheq works'),
          const SizedBox(height: 12),
          _steps(rtl),
          const SizedBox(height: 24),
          _consultCta(rtl),
        ],
      ),
    );
  }

  // ----------------------------------------------------------------- sections

  /// A non-blocking notice for a failed section load, with a retry action.
  Widget _loadNotice(bool rtl) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s4),
      decoration: BoxDecoration(
        color: context.tokens.dangerBg,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment:
            rtl ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.error_outline, size: 20, color: context.tokens.dangerFg),
              const SizedBox(width: AppSpacing.s3),
              Expanded(
                child: Text(
                  _error!,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.s3),
          SecondaryButton(
            label: rtl ? 'إعادة المحاولة' : 'Retry',
            icon: Icons.refresh,
            onPressed: _load,
          ),
        ],
      ),
    );
  }

  Widget _greeting(bool rtl) {
    return Column(
      crossAxisAlignment:
          rtl ? CrossAxisAlignment.end : CrossAxisAlignment.start,
      children: [
        Text(
          rtl ? 'أهلاً بك' : 'Welcome',
          style: Theme.of(context)
              .textTheme
              .headlineSmall
              ?.copyWith(color: context.tokens.heading, fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 4),
        Text(
          rtl
              ? 'استشارة قانونية موثوقة، من محامٍ معتمد.'
              : 'Trusted legal advice, from an approved lawyer.',
          style: Theme.of(context)
              .textTheme
              .bodyMedium
              ?.copyWith(color: context.tokens.textMuted),
        ),
      ],
    );
  }

  Widget _searchBar(bool rtl) {
    return TextField(
      // Search opens the lawyers directory with the term, which is the only public
      // dataset to search. It does not query anything private.
      onSubmitted: (_) => widget.onOpenLawyers(),
      readOnly: false,
      decoration: InputDecoration(
        hintText: rtl ? 'ابحث عن محامٍ أو تخصص' : 'Search a lawyer or specialty',
        prefixIcon: const Icon(Icons.search),
        filled: true,
        fillColor: context.tokens.surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          borderSide: BorderSide(color: context.tokens.border),
        ),
      ),
    );
  }

  Widget _specialtyGrid(bool rtl) {
    if (_specialties.isEmpty) {
      return Container(
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.s5),
        decoration: BoxDecoration(
          color: context.tokens.surface,
          borderRadius: BorderRadius.circular(AppRadius.xl),
          border: Border.all(color: context.tokens.border),
        ),
        child: Text(
          rtl
              ? 'ستظهر التخصصات هنا بعد تجهيزها من فريق مواثيق.'
              : 'Specialties will appear here once the team publishes them.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      );
    }

    // Show every specialty the server publishes, not an arbitrary prefix: the
    // owner asked for all categories to be reachable from the home screen.
    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: _specialties
          .map((s) => _SpecialtyChip(
                specialty: s,
                rtl: rtl,
                onTap: () => _startConsultation(),
              ))
          .toList(),
    );
  }

  Widget _quickServices(bool rtl) {
    // Each entry names a capability the platform ships and routes to the matching
    // protected destination after sign-in. Nothing here is a placeholder.
    final services = <(IconData, String, String, ProtectedDestination)>[
      (
        Icons.forum_outlined,
        'استشارة قانونية',
        'Legal consultation',
        ProtectedDestination.consultations,
      ),
      (
        Icons.folder_outlined,
        'متابعة قضاياي',
        'Track my cases',
        ProtectedDestination.cases,
      ),
      (
        Icons.description_outlined,
        'مستنداتي',
        'My documents',
        ProtectedDestination.documents,
      ),
      (
        Icons.auto_awesome_outlined,
        'المساعد القانوني',
        'Legal assistant',
        ProtectedDestination.legalAi,
      ),
    ];

    return Row(
      children: [
        for (var i = 0; i < services.length; i++) ...[
          if (i > 0) const SizedBox(width: 10),
          Expanded(
            child: _ServiceTile(
              icon: services[i].$1,
              label: rtl ? services[i].$2 : services[i].$3,
              onTap: () => requireAccount(context, services[i].$4),
            ),
          ),
        ],
      ],
    );
  }

  Widget _steps(bool rtl) {
    final steps = <(IconData, String, String)>[
      (
        Icons.touch_app_outlined,
        rtl ? 'اختر تخصصك' : 'Choose your specialty',
        rtl ? 'حدّد نوع المشكلة القانونية.' : 'Tell us the legal matter.',
      ),
      (
        Icons.event_available_outlined,
        rtl ? 'احجز موعدك' : 'Book your slot',
        rtl ? 'اختر محامياً ووقتاً يناسبك.' : 'Pick a lawyer and a time.',
      ),
      (
        Icons.video_call_outlined,
        rtl ? 'استلم استشارتك' : 'Get your consultation',
        rtl
            ? 'متابعة كاملة عبر حسابك.'
            : 'Follow it through from your account.',
      ),
    ];

    return Column(
      children: [
        for (var i = 0; i < steps.length; i++) ...[
          if (i > 0) const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: context.tokens.border),
            ),
            child: Row(
              children: [
                CircleAvatar(
                  radius: 20,
                  backgroundColor: AppColors.teal.withValues(alpha: 0.12),
                  child: Icon(steps[i].$1, color: AppColors.teal, size: 20),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        steps[i].$2,
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: context.tokens.heading),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        steps[i].$3,
                        style: TextStyle(
                            color: context.tokens.textMuted, fontSize: 13),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  Widget _consultCta(bool rtl) {
    return GradientHeroCard(
      eyebrow: rtl ? 'ابدأ الآن' : 'Start now',
      title: rtl ? 'احجز استشارتك الأولى' : 'Book your first consultation',
      body: rtl
          ? 'أنشئ حساباً مجانياً لحجز استشارة ومتابعة قضاياك ومستنداتك في مكان واحد.'
          : 'Create a free account to book a consultation and follow your cases '
              'and documents in one place.',
      ctaLabel: rtl ? 'ابدأ' : 'Get started',
      onCta: () => _startConsultation(),
    );
  }

  // ------------------------------------------------------------------ actions

  /// Opens the consultation journey if the visitor is signed in, and the sign-in
  /// prompt otherwise. The journey itself is the protected destination.
  void _startConsultation() {
    requireAccount(context, ProtectedDestination.consultations);
  }

  /// A banner destination is already resolved server-side; the app only maps the
  /// type onto the right local action.
  void _openBanner(HomeBanner banner) {
    switch (banner.linkType) {
      case 'url':
        // External links open a confirmation screen rather than launching straight
        // out of the app; see BannerLinkScreen.
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => BannerLinkScreen(banner: banner),
          ),
        );
      case 'category':
      case 'specialty':
        _startConsultation();
      default:
        break;
    }
  }
}

class _SpecialtyChip extends StatelessWidget {
  const _SpecialtyChip({
    required this.specialty,
    required this.rtl,
    required this.onTap,
  });

  final JourneySpecialty specialty;
  final bool rtl;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        onTap: onTap,
        child: Container(
          width: 150,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            border: Border.all(color: context.tokens.border),
            borderRadius: BorderRadius.circular(AppRadius.lg),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: context.tokens.infoBg,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                ),
                child: Icon(
                  specialtyIcon(specialty.iconKey),
                  size: 18,
                  color: AppColors.teal,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                specialty.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontWeight: FontWeight.w700,
                  color: context.tokens.heading,
                  fontSize: 13,
                  height: 1.35,
                ),
              ),
              if (specialty.hasLawyers) ...[
                const SizedBox(height: 4),
                Text(
                  rtl
                      ? '${specialty.lawyerCount} محامٍ'
                      : '${specialty.lawyerCount} lawyers',
                  style: TextStyle(
                      color: context.tokens.textSubtle, fontSize: 11),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _ServiceTile extends StatelessWidget {
  const _ServiceTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 6),
          decoration: BoxDecoration(
            border: Border.all(color: context.tokens.border),
            borderRadius: BorderRadius.circular(AppRadius.lg),
          ),
          child: Column(
            children: [
              Icon(icon, color: context.tokens.heading, size: 22),
              const SizedBox(height: 8),
              Text(
                label,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.3,
                  fontWeight: FontWeight.w600,
                  color: context.tokens.heading,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

