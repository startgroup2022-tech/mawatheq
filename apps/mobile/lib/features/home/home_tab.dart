import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../account/account_screen.dart';
import '../consultations/consultations_screen.dart';
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

  int _unread = 0;

  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
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
                color: AppColors.warningBg,
                borderRadius: BorderRadius.circular(AppRadius.md),
                border: Border.all(color: const Color(0xFFF3E0BE)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.warning_amber_rounded,
                      size: 17, color: AppColors.warning),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _ai.reason,
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.warning,
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
}
