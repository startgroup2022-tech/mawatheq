import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Published plans, read from `GET /api/v1/plans.php`.
///
/// Prices, limits and the featured flag all come from the `plans` table, so this
/// screen cannot drift from what the platform actually charges. There is no
/// purchase button: the platform has no mobile subscription API, so offering one
/// would be a lie. The CTA instead explains how to subscribe and opens the
/// authenticated flow only for a signed-in user.
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
  bool _compare = false;

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
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'تعذّر تحميل الخطط. تحقق من الاتصال وأعد المحاولة.';
      });
    }
  }

  /// Reads the locale provider when present. The screen is also exercised on its
  /// own (without the app's providers) by the contract tests, where Arabic is the
  /// documented default.
  bool get _isRtl {
    try {
      return context.read<LocaleController>().isRtl;
    } catch (_) {
      return true;
    }
  }

  /// Whether a plan has a yearly price at all. A null yearly price means the plan
  /// is not sold annually, so the switch is not offered for it.
  bool get _anyYearly => _plans.any((p) => p.priceYearly > 0);

  @override
  Widget build(BuildContext context) {
    final rtl = _isRtl;

    return Scaffold(
      appBar: BrandAppBar(subtitle: rtl ? 'الخطط والأسعار' : 'Plans and pricing'),
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
                          if (_anyYearly)
                            Center(
                              child: SegmentedButton<bool>(
                                segments: [
                                  ButtonSegment(
                                    value: false,
                                    label: Text(rtl ? 'شهري' : 'Monthly'),
                                  ),
                                  ButtonSegment(
                                    value: true,
                                    label: Text(rtl ? 'سنوي' : 'Yearly'),
                                  ),
                                ],
                                selected: {_yearly},
                                onSelectionChanged: (s) =>
                                    setState(() => _yearly = s.first),
                              ),
                            ),
                          const SizedBox(height: 18),
                          ..._plans.map((plan) => Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: _PlanCard(
                                  plan: plan,
                                  yearly: _yearly,
                                  rtl: rtl,
                                ),
                              )),
                          if (_plans.length > 1) ...[
                            const SizedBox(height: 6),
                            SecondaryButton(
                              label: rtl ? 'مقارنة الخطط' : 'Compare plans',
                              icon: _compare
                                  ? Icons.view_list_outlined
                                  : Icons.table_chart_outlined,
                              expand: true,
                              onPressed: () =>
                                  setState(() => _compare = !_compare),
                            ),
                            if (_compare) ...[
                              const SizedBox(height: 14),
                              _ComparisonTable(plans: _plans, rtl: rtl),
                            ],
                          ],
                          const SizedBox(height: 18),
                          _note(rtl),
                        ],
                      ),
                    ),
    );
  }

  /// The honest footnote: what the prices are, and what the app does not do.
  Widget _note(bool rtl) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.infoBg,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: const Color(0xFFCFEAF1)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.info_outline, size: 16, color: AppColors.teal),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              rtl
                  ? 'الأسعار بالريال السعودي كما هي منشورة في لوحة الإدارة. '
                      'الاشتراك يتم من خلال المنصة بعد تسجيل الدخول.'
                  : 'Prices are in Saudi Riyal exactly as published in the admin '
                      'console. Subscriptions are completed on the platform after '
                      'signing in.',
              style: const TextStyle(
                fontSize: 12,
                color: AppColors.textMuted,
                height: 1.7,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One plan card: price, billing period, description and the real usage limits.
class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.plan,
    required this.yearly,
    required this.rtl,
  });

  final Plan plan;
  final bool yearly;
  final bool rtl;

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
                  rtl
                      ? plan.displayName
                      : (plan.nameEn.isEmpty ? plan.nameAr : plan.nameEn),
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              if (featured)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    gradient: AppColors.cyanAccent,
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                  ),
                  child: Text(
                    rtl ? 'الأكثر اختياراً' : 'Most popular',
                    style: const TextStyle(
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
                price <= 0 ? (rtl ? 'مجاناً' : 'Free') : price.toStringAsFixed(0),
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              if (price > 0) ...[
                const SizedBox(width: 4),
                Text(
                  rtl
                      ? 'ر.س / ${yearly ? 'سنوياً' : 'شهرياً'}'
                      : 'SAR / ${yearly ? 'year' : 'month'}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ],
          ),
          if (plan.descriptionAr.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              rtl ? plan.descriptionAr : plan.descriptionEn,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
          const SizedBox(height: 14),
          // Only the limits the plan actually defines are listed; a null limit
          // means "unlimited", which is stated rather than shown as a number.
          _limit(context, rtl ? 'استفسارات المساعد' : 'Assistant queries',
              plan.aiQueriesLimit, rtl),
          _limit(context, rtl ? 'تحليل المستندات' : 'Document analyses',
              plan.documentAnalysisLimit, rtl),
          _limit(context, rtl ? 'استشارات مشمولة' : 'Consultations included',
              plan.consultationsIncluded, rtl),
          const SizedBox(height: 6),
          // No purchase button: there is no mobile subscription API. The label
          // states where the subscription actually happens.
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 11),
            decoration: BoxDecoration(
              color: AppColors.surface3,
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            child: Text(
              rtl
                  ? 'الاشتراك متاح من المنصة بعد تسجيل الدخول'
                  : 'Subscribe on the platform after signing in',
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: AppColors.textMuted,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _limit(BuildContext context, String label, int? value, bool rtl) {
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
              '$label: ${value == null ? (rtl ? 'غير محدود' : 'Unlimited') : '$value'}',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }
}

/// Side-by-side comparison of the published plans, built only from server fields.
class _ComparisonTable extends StatelessWidget {
  const _ComparisonTable({required this.plans, required this.rtl});

  final List<Plan> plans;
  final bool rtl;

  @override
  Widget build(BuildContext context) {
    String cell(int? v) =>
        v == null ? (rtl ? 'غير محدود' : 'Unlimited') : '$v';

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          headingTextStyle: const TextStyle(
            fontWeight: FontWeight.w800,
            color: AppColors.navy,
            fontSize: 12.5,
          ),
          dataTextStyle: const TextStyle(
            fontSize: 12.5,
            color: AppColors.text,
          ),
          columns: [
            DataColumn(label: Text(rtl ? 'الخطة' : 'Plan')),
            DataColumn(label: Text(rtl ? 'شهري' : 'Monthly')),
            DataColumn(label: Text(rtl ? 'سنوي' : 'Yearly')),
            DataColumn(label: Text(rtl ? 'المساعد' : 'Assistant')),
            DataColumn(label: Text(rtl ? 'التحليل' : 'Analysis')),
            DataColumn(label: Text(rtl ? 'استشارات' : 'Consult.')),
          ],
          rows: [
            for (final p in plans)
              DataRow(cells: [
                DataCell(Text(
                  rtl
                      ? p.displayName
                      : (p.nameEn.isEmpty ? p.nameAr : p.nameEn),
                )),
                DataCell(Text(
                  p.priceMonthly <= 0
                      ? (rtl ? 'مجاناً' : 'Free')
                      : p.priceMonthly.toStringAsFixed(0),
                )),
                DataCell(Text(
                  p.priceYearly <= 0
                      ? '—'
                      : p.priceYearly.toStringAsFixed(0),
                )),
                DataCell(Text(cell(p.aiQueriesLimit))),
                DataCell(Text(cell(p.documentAnalysisLimit))),
                DataCell(Text(cell(p.consultationsIncluded))),
              ]),
          ],
        ),
      ),
    );
  }
}

