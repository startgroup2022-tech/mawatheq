import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// «تنبيهات توفّر المواعيد» — the client's "notify me when a slot opens" list, from
/// `GET /api/v1/availability-alerts.php`.
///
/// Subscriptions are created from a lawyer's card ("أبلغني عند توفّر موعد"); this screen
/// is where the customer sees and cancels them. Cancelling never deletes the row — it
/// flips it to `cancelled`, so re-subscribing later reuses the same pair.
class AvailabilityAlertsScreen extends StatefulWidget {
  const AvailabilityAlertsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<AvailabilityAlertsScreen> createState() => _AvailabilityAlertsScreenState();
}

class _AvailabilityAlertsScreenState extends State<AvailabilityAlertsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<AvailabilityAlert> _alerts = const [];
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
      final list = await _repo.availabilityAlerts();
      if (!mounted) return;
      setState(() {
        _alerts = list;
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
        _error = 'تعذّر تحميل التنبيهات. أعد المحاولة.';
      });
    }
  }

  Future<void> _cancel(AvailabilityAlert alert) async {
    try {
      await _repo.setAvailabilityAlert(alert.lawyerId, subscribe: false);
      await _load();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر إلغاء التنبيه.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'تنبيهات توفّر المواعيد' : 'Availability alerts'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _body(rtl),
    );
  }

  Widget _body(bool rtl) {
    if (_alerts.isEmpty) {
      return EmptyState(
        icon: Icons.notifications_active_outlined,
        title: rtl ? 'لا توجد تنبيهات' : 'No alerts yet',
        message: rtl
            ? 'عندما لا يتوفر موعد لدى محامٍ، اضغط «أبلغني عند توفّر موعد» وسنخبرك فوراً عند فتح موعد.'
            : 'When a lawyer has no open slot, tap "Notify me" and we will tell you the moment one opens.',
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        itemCount: _alerts.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          final a = _alerts[i];
          return Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: context.tokens.border),
            ),
            child: Row(
              children: [
                const CircleAvatar(
                  radius: 22,
                  backgroundColor: AppColors.navy,
                  child: Icon(Icons.notifications_active_outlined, color: Colors.white),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        a.lawyerName.isEmpty ? (rtl ? 'محامٍ' : 'A lawyer') : a.lawyerName,
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: context.tokens.heading,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        a.isActive
                            ? (rtl ? 'التنبيه مُفعّل — سنخبرك عند توفّر موعد.' : 'Active — we will tell you when a slot opens.')
                            : (rtl ? 'تم إشعارك بتوفّر موعد.' : 'You were notified of an open slot.'),
                        style: TextStyle(
                          color: a.isActive ? AppColors.teal : context.tokens.textMuted,
                          fontSize: 12.5,
                        ),
                      ),
                    ],
                  ),
                ),
                if (a.isActive)
                  TextButton(
                    onPressed: () => _cancel(a),
                    child: Text(rtl ? 'إلغاء' : 'Cancel'),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}
