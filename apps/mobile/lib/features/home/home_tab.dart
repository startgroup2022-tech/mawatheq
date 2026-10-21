import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/banner_carousel.dart';
import '../../shared/widgets/banner_link_screen.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../account/account_screen.dart';
import '../consultations/consultations_screen.dart';
import '../consultations/journey_screen.dart';
import '../notifications/notifications_screen.dart';
import '../public/pricing_screen.dart';

/// The signed-in home: greeting, real assistant availability, quick stats and
/// the feature grid. Every number shown comes from the API.
class HomeTab extends StatefulWidget {
  const HomeTab({super.key, required this.onNavigate});

  /// Switches the bottom-nav tab (0..4).
  final ValueChanged<int> onNavigate;

  @override
  State<HomeTab> createState() => _HomeTabState();
}

class _HomeTabState extends State<HomeTab> {
  final _repo = ApiRepository();

  AiStatus _ai = AiStatus.unknown;
  List<CaseSummary> _cases = const [];
  List<Consultation> _consultations = const [];
  List<DocumentSummary> _documents = const [];
  List<HomeBanner> _banners = const [];

  int _unread = 0;

  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
    // Banners load separately: a failure here must not blank the dashboard, and an
    // empty slider is a normal state rather than an error.
    _loadBanners();
  }

  Future<void> _loadBanners() async {
    try {
      final banners = await _repo.banners();
      if (!mounted) return;
      setState(() => _banners = banners);
    } catch (_) {
      // Leave the slider empty; the rest of the home screen is unaffected.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait([
        _repo.aiStatus(),
        _repo.cases(),
        _repo.documents(),
        _repo.consultations(),
        _repo.notifications(),
      ]);
      if (!mounted) return;
      setState(() {
        _ai = results[0] as AiStatus;
        _cases = results[1] as List<CaseSummary>;
        _documents = results[2] as List<DocumentSummary>;
        _consultations = results[3] as List<Consultation>;
        _unread = (results[4] as NotificationPage).unreadCount;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'تعذّر تحميل البيانات. أعد المحاولة.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthController>().user;

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: LoadingSkeleton(count: 5),
      );
    }

    if (_error != null) {
      return ErrorState(message: _error!, onRetry: _load);
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // Operator-published banners, above the greeting. The widget renders
          // nothing when there are none, so a fresh install shows no empty frame.
          if (_banners.isNotEmpty) ...[
            BannerCarousel(
              banners: _banners,
              onTap: (banner) => _openBanner(banner),
            ),
            const SizedBox(height: 18),
          ],
          // Greeting + real AI availability.
          GradientHeroCard(
            eyebrow: 'مواثيق',
            title: 'أهلاً ${user.name.isEmpty ? '' : user.name}'.trim(),
            body: _ai.available
                ? 'المساعد القانوني الذكي متاح الآن. اطرح سؤالك وستحصل على إجابة مبنية على الأنظمة.'
                : 'المساعد الذكي غير متاح حالياً. ${_ai.reason}',
            ctaLabel: 'المساعد الذكي',
            onCta: () => widget.onNavigate(2),
          ),
          const SizedBox(height: 18),

          if (!_ai.available)
            Container(
              margin: const EdgeInsets.only(bottom: 18),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: context.tokens.warningBg,
                borderRadius: BorderRadius.circular(AppRadius.md),
                border: Border.all(color: context.tokens.warningBorder),
              ),
              child: Row(
                children: [
                  Icon(Icons.warning_amber_rounded,
                      size: 17, color: context.tokens.warningFg),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _ai.reason,
                      style: TextStyle(
                        fontSize: 12,
                        color: context.tokens.warningFg,
                        height: 1.6,
                      ),
                    ),
                  ),
                ],
              ),
            ),

          // Real counters.
          Row(
            children: [
              Expanded(
                child: DashboardCard(
                  value: '${_cases.length}',
                  label: 'قضية',
                  icon: Icons.folder_outlined,
                  accent: AppColors.navy,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: DashboardCard(
                  value: '${_documents.length}',
                  label: 'مستند',
                  icon: Icons.description_outlined,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: DashboardCard(
                  value: '${_consultations.length}',
                  label: 'استشارة',
                  icon: Icons.forum_outlined,
                  accent: AppColors.gold,
                ),
              ),
            ],
          ),
          const SizedBox(height: 22),

          SectionHeader(
            title: 'مميزات المنصة',
          ),
          GridView.count(
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            childAspectRatio: 0.98,
            children: [
              FeatureTile(
                icon: Icons.folder_outlined,
                label: 'القضايا',
                onTap: () => widget.onNavigate(1),
              ),
              FeatureTile(
                icon: Icons.description_outlined,
                label: 'المستندات',
                onTap: () => widget.onNavigate(3),
              ),
              FeatureTile(
                icon: Icons.auto_awesome_outlined,
                label: 'المساعد الذكي',
                onTap: () => widget.onNavigate(2),
              ),
              FeatureTile(
                icon: Icons.forum_outlined,
                label: 'الاستشارات',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const ConsultationsScreen()),
                ),
              ),
              FeatureTile(
                icon: Icons.assignment_outlined,
                label: 'احجز استشارة',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(
                      builder: (_) => const ConsultationJourneyScreen()),
                ),
              ),
              FeatureTile(
                icon: Icons.event_outlined,
                label: 'المواعيد',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const ConsultationsScreen()),
                ),
              ),
              FeatureTile(
                icon: Icons.notifications_outlined,
                label: _unread > 0 ? 'الإشعارات ($_unread)' : 'الإشعارات',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const NotificationsScreen()),
                ),
              ),
              FeatureTile(
                icon: Icons.person_outline,
                label: 'الملف الشخصي',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const AccountScreen()),
                ),
              ),
              FeatureTile(
                icon: Icons.workspace_premium_outlined,
                label: 'الأسعار',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const PricingScreen()),
                ),
              ),
              FeatureTile(
                icon: Icons.security_outlined,
                label: 'الأمان',
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const AccountScreen()),
                ),
              ),
            ],
          ),
          const SizedBox(height: 22),

          if (_cases.isNotEmpty) ...[
            SectionHeader(
              title: 'أحدث القضايا',
              actionLabel: 'عرض الكل',
              onAction: () => widget.onNavigate(1),
            ),
            ..._cases.take(3).map((c) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: CaseCard(item: c, onTap: () => widget.onNavigate(1)),
                )),
          ],
          const SizedBox(height: 10),
        ],
      ),
    );
  }

  /// A banner destination is already resolved server-side; the app only maps the
  /// type onto a local tab. An external link is shown in full before leaving the
  /// app, and a banner whose target was hidden degrades to `none` (not tappable).
  void _openBanner(HomeBanner banner) {
    switch (banner.linkType) {
      case 'category':
      case 'specialty':
        widget.onNavigate(1);
      case 'url':
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => BannerLinkScreen(banner: banner),
          ),
        );
      default:
        break;
    }
  }
}
