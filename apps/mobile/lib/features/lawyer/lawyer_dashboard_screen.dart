import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'lawyer_wallet_screen.dart';

/// The lawyer's home. Every figure comes from `GET /api/v1/lawyer-dashboard.php`,
/// which reads the same tables as the web console.
///
/// The layout is ordered by what a lawyer acts on: what is waiting for a decision,
/// then today's work, then the money, then history. It is deliberately not a grid of
/// equal cards — a dashboard where everything has the same weight tells the reader
/// nothing.
class LawyerDashboardScreen extends StatefulWidget {
  const LawyerDashboardScreen({super.key, this.repository, this.onOpenConsultations});

  final ApiRepository? repository;

  /// Lets the shell switch to the consultations tab instead of pushing a copy.
  final VoidCallback? onOpenConsultations;

  @override
  State<LawyerDashboardScreen> createState() => _LawyerDashboardScreenState();
}

class _LawyerDashboardScreenState extends State<LawyerDashboardScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  LawyerDashboard? _data;
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
      final data = await _repo.lawyerDashboard();
      if (!mounted) return;
      setState(() {
        _data = data;
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
    return RefreshIndicator(
      onRefresh: _load,
      child: _loading
          ? const Padding(padding: EdgeInsets.all(16), child: LoadingSkeleton(count: 6))
          : _error != null
              ? ListView(
                  children: [
                    const SizedBox(height: 80),
                    ErrorState(message: _error!, onRetry: _load),
                  ],
                )
              : _content(context, _data!),
    );
  }

  Widget _content(BuildContext context, LawyerDashboard d) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
      children: [
        // The one thing that needs a decision today.
        if (d.pending > 0)
          _NeedsActionCard(count: d.pending, onTap: widget.onOpenConsultations ?? () {}),
        const SizedBox(height: 14),
        _StatGrid(data: d),
        const SizedBox(height: 18),
        if (d.upcoming.isNotEmpty) ...[
          SectionHeader(
            title: 'المواعيد القادمة',
            actionLabel: 'عرض الكل',
            onAction: widget.onOpenConsultations,
          ),
          const SizedBox(height: 8),
          for (final a in d.upcoming) ...[
            _UpcomingTile(appointment: a, onTap: widget.onOpenConsultations),
            const SizedBox(height: 8),
          ],
          const SizedBox(height: 10),
        ],
        SectionHeader(
          title: 'المحفظة',
          actionLabel: 'التفاصيل',
          onAction: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => LawyerWalletScreen(repository: widget.repository)),
          ),
        ),
        const SizedBox(height: 8),
        _WalletCard(wallet: d.wallet),
        const SizedBox(height: 18),
        if (d.activity.isNotEmpty) ...[
          const SectionHeader(title: 'النشاط الأخير'),
          const SizedBox(height: 8),
          for (final item in d.activity) _ActivityTile(item: item),
        ],
      ],
    );
  }
}

/// The pending-consultations call to action. Shown only when there is work.
class _NeedsActionCard extends StatelessWidget {
  const _NeedsActionCard({required this.count, required this.onTap});

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          gradient: AppColors.brandHero,
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
        child: Row(
          children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: const Icon(Icons.pending_actions, color: Colors.white),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '$count طلب استشارة بانتظار قرارك',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 15,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    'راجع الطلبات الجديدة واتخذ الإجراء',
                    style: TextStyle(color: Colors.white.withValues(alpha: 0.9), fontSize: 12.5),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_left, color: Colors.white),
          ],
        ),
      ),
    );
  }
}

/// The four headline numbers.
class _StatGrid extends StatelessWidget {
  const _StatGrid({required this.data});

  final LawyerDashboard data;

  @override
  Widget build(BuildContext context) {
    final tiles = <Widget>[
      DashboardCard(
        label: 'استشارات نشطة',
        value: '${data.confirmed}',
        icon: Icons.event_available_outlined,
        accent: AppColors.teal,
      ),
      DashboardCard(
        label: 'قضايا نشطة',
        value: '${data.casesActive}',
        icon: Icons.folder_open_outlined,
        accent: AppColors.navy,
      ),
      DashboardCard(
        label: 'عملاء',
        value: '${data.clients}',
        icon: Icons.people_outline,
        accent: AppColors.teal,
      ),
      DashboardCard(
        label: 'مهام مفتوحة',
        value: '${data.tasksOpen}',
        icon: Icons.checklist_outlined,
        accent: AppColors.gold,
      ),
    ];
    return LayoutBuilder(
      builder: (context, constraints) {
        final twoUp = constraints.maxWidth > 520;
        if (!twoUp) {
          return Column(
            children: [
              for (var i = 0; i < tiles.length; i += 2) ...[
                Row(
                  children: [
                    Expanded(child: tiles[i]),
                    const SizedBox(width: 12),
                    Expanded(child: tiles[i + 1]),
                  ],
                ),
                if (i + 2 < tiles.length) const SizedBox(height: 12),
              ],
            ],
          );
        }
        return Row(
          children: [
            for (var i = 0; i < tiles.length; i++) ...[
              Expanded(child: tiles[i]),
              if (i < tiles.length - 1) const SizedBox(width: 12),
            ],
          ],
        );
      },
    );
  }
}

class _UpcomingTile extends StatelessWidget {
  const _UpcomingTile({required this.appointment, this.onTap});

  final UpcomingAppointment appointment;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final icon = switch (appointment.consultationType) {
      'video' => Icons.videocam_outlined,
      'voice' => Icons.call_outlined,
      _ => Icons.chat_bubble_outline,
    };
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: context.tokens.surface,
          borderRadius: BorderRadius.circular(AppRadius.md),
          border: Border.all(color: context.tokens.border),
        ),
        child: Row(
          children: [
            Icon(icon, color: AppColors.teal, size: 22),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    appointment.topic.isEmpty ? 'استشارة' : appointment.topic,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(fontWeight: FontWeight.w700, color: context.tokens.heading),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    [appointment.clientName, appointment.scheduledAt]
                        .where((s) => s.isNotEmpty)
                        .join(' · '),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(fontSize: 12.5, color: context.tokens.textMuted),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_left, size: 18),
          ],
        ),
      ),
    );
  }
}

class _WalletCard extends StatelessWidget {
  const _WalletCard({required this.wallet});

  final WalletSummary wallet;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('الرصيد المتاح', style: TextStyle(fontSize: 12.5, color: context.tokens.textMuted)),
          const SizedBox(height: 4),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                wallet.available,
                style: TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w800,
                  color: context.tokens.heading,
                ),
              ),
              const SizedBox(width: 6),
              Text(wallet.currency, style: TextStyle(fontSize: 13, color: context.tokens.textMuted)),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(child: _MiniStat(label: 'قيد التسوية', value: wallet.pending)),
              Expanded(child: _MiniStat(label: 'محجوز', value: wallet.reserved)),
              Expanded(child: _MiniStat(label: 'إجمالي الأرباح', value: wallet.grossEarned)),
            ],
          ),
        ],
      ),
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: TextStyle(fontSize: 11.5, color: context.tokens.textMuted)),
        const SizedBox(height: 2),
        Text(
          value,
          style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: context.tokens.heading),
        ),
      ],
    );
  }
}

class _ActivityTile extends StatelessWidget {
  const _ActivityTile({required this.item});

  final ActivityItem item;

  @override
  Widget build(BuildContext context) {
    final isCase = item.kind == 'case';
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        children: [
          Icon(
            isCase ? Icons.folder_outlined : Icons.forum_outlined,
            size: 18,
            color: context.tokens.textMuted,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              item.title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 13.5, color: context.tokens.text),
            ),
          ),
          Text(item.status, style: TextStyle(fontSize: 11.5, color: context.tokens.textMuted)),
        ],
      ),
    );
  }
}
