import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Published plans, read from `GET /api/v1/plans.php`.
///
/// Prices, limits and the featured flag all come from the `plans` table, so this
/// screen cannot drift from what the platform actually charges.
class PricingScreen extends StatefulWidget {
  const PricingScreen({super.key, this.repository});

  /// Injectable for tests; production always uses the shared client.
  final ApiRepository? repository;

  @override
  State<PricingScreen> createState() => _PricingScreenState();
}

class _PricingScreenState extends State<PricingScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  List<Plan> _plans = const [];
  bool _loading = true;
  String? _error;
  bool _yearly = false;

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
      final plans = await _repo.plans();
      if (!mounted) return;
      setState(() {
        _plans = plans;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BrandAppBar(subtitle: 'الخطط والأسعار'),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4, height: 150),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _plans.isEmpty
                  ? const EmptyState(
                      icon: Icons.workspace_premium_outlined,
                      title: 'لا توجد خطط منشورة',
                      message: 'لم تُنشر أي خطط من لوحة الإدارة بعد.',
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          Center(
                            child: SegmentedButton<bool>(
                              segments: const [
                                ButtonSegment(value: false, label: Text('شهري')),
                                ButtonSegment(value: true, label: Text('سنوي')),
                              ],
                              selected: {_yearly},
                              onSelectionChanged: (s) =>
                                  setState(() => _yearly = s.first),
                            ),
                          ),
                          const SizedBox(height: 18),
                          ..._plans.map((plan) => Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: _PlanCard(plan: plan, yearly: _yearly),
                              )),
                          const SizedBox(height: 8),
                          Text(
                            'الأسعار بالريال السعودي. تُدار الخطط من لوحة الإدارة.',
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.labelSmall,
                          ),
                        ],
                      ),
                    ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.plan, required this.yearly});

  final Plan plan;
  final bool yearly;

  @override
  Widget build(BuildContext context) {
    final price = yearly ? plan.priceYearly : plan.priceMonthly;
    final featured = plan.isFeatured;

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(
          color: featured ? AppColors.teal : AppColors.border,
          width: featured ? 1.6 : 1,
        ),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: featured ? AppShadow.md : AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  plan.displayName,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              if (featured)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    gradient: AppColors.cyanAccent,
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                  ),
                  child: const Text(
                    'الأكثر اختياراً',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.navyDeep,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                price <= 0 ? 'مجاناً' : price.toStringAsFixed(0),
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              if (price > 0) ...[
                const SizedBox(width: 4),
                Text('ر.س / ${yearly ? 'سنوياً' : 'شهرياً'}',
                    style: Theme.of(context).textTheme.bodySmall),
              ],
            ],
          ),
          if (plan.descriptionAr.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(plan.descriptionAr,
                style: Theme.of(context).textTheme.bodySmall),
          ],
          const SizedBox(height: 14),
          // Only the limits the plan actually defines are listed; a null limit
          // means "unlimited", which is stated rather than shown as a number.
          _limit(context, 'استفسارات المساعد', plan.aiQueriesLimit),
          _limit(context, 'تحليل المستندات', plan.documentAnalysisLimit),
          _limit(context, 'استشارات مشمولة', plan.consultationsIncluded),
        ],
      ),
    );
  }

  Widget _limit(BuildContext context, String label, int? value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(top: 3),
            child: Icon(Icons.check_circle_outline,
                size: 15, color: AppColors.success),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '$label: ${value == null ? 'غير محدود' : '$value'}',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }
}
