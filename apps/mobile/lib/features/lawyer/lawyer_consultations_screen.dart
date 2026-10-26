import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../calls/call_screen.dart';

/// The lawyer's consultation queue.
///
/// `GET /api/v1/consultations.php` returns the lawyer's own queue for a lawyer token,
/// so the same endpoint serves both roles. This screen adds what only a lawyer may do:
/// confirm, complete or cancel a booking, and start a consultation session.
///
/// The decision buttons are shown from the row's own status, and the server refuses a
/// transition it does not allow — the UI is a convenience, never the authorisation.
class LawyerConsultationsScreen extends StatefulWidget {
  const LawyerConsultationsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LawyerConsultationsScreen> createState() => _LawyerConsultationsScreenState();
}

class _LawyerConsultationsScreenState extends State<LawyerConsultationsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  List<Consultation> _all = const [];
  bool _loading = true;
  String? _error;
  String _filter = 'قيد الانتظار';
  int? _busyId;

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
      final items = await _repo.consultations();
      if (!mounted) return;
      setState(() {
        _all = items;
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

  List<Consultation> get _visible {
    switch (_filter) {
      case 'قيد الانتظار':
        return _all.where((c) => c.status == 'pending').toList();
      case 'مؤكدة':
        return _all.where((c) => c.status == 'confirmed').toList();
      case 'مكتملة':
        return _all.where((c) => c.status == 'completed').toList();
      default:
        return _all;
    }
  }

  Future<void> _changeStatus(Consultation c, String status) async {
    setState(() => _busyId = c.id);
    try {
      await _repo.changeConsultationStatus(consultationId: c.id, status: status);
      if (!mounted) return;
      final label = switch (status) {
        'confirmed' => 'تم تأكيد الاستشارة.',
        'completed' => 'تم إتمام الاستشارة.',
        'cancelled' => 'تم إلغاء الاستشارة.',
        _ => 'تم التحديث.',
      };
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(label)));
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busyId = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: 'الاستشارات',
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: _loading
          ? const Padding(padding: EdgeInsets.all(16), child: LoadingSkeleton(count: 5))
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
                      // `FilterChips` is itself a horizontal scroll view; wrapping it in
                      // another one gives the inner viewport an unbounded width and the
                      // layout throws. It scrolls on its own.
                      child: FilterChips(
                        options: const ['قيد الانتظار', 'مؤكدة', 'مكتملة', 'الكل'],
                        selected: _filter,
                        onSelected: (v) => setState(() => _filter = v),
                      ),
                    ),
                    Expanded(
                      child: _visible.isEmpty
                          ? const EmptyState(
                              icon: Icons.forum_outlined,
                              title: 'لا توجد استشارات',
                              message: 'ستظهر هنا طلبات الاستشارة الواردة إليك.',
                            )
                          : RefreshIndicator(
                              onRefresh: _load,
                              child: ListView.separated(
                                padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                                itemCount: _visible.length,
                                separatorBuilder: (_, __) => const SizedBox(height: 12),
                                itemBuilder: (context, i) => _ConsultationTile(
                                  item: _visible[i],
                                  busy: _busyId == _visible[i].id,
                                  onChangeStatus: _changeStatus,
                                ),
                              ),
                            ),
                    ),
                  ],
                ),
    );
  }
}

class _ConsultationTile extends StatelessWidget {
  const _ConsultationTile({
    required this.item,
    required this.busy,
    required this.onChangeStatus,
  });

  final Consultation item;
  final bool busy;
  final void Function(Consultation, String) onChangeStatus;

  static const _types = {'text': 'نصية', 'voice': 'صوتية', 'video': 'مرئية'};

  @override
  Widget build(BuildContext context) {
    final isVoiceOrVideo = item.consultationType == 'voice' || item.consultationType == 'video';
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  item.topic.isEmpty ? 'استشارة #${item.id}' : item.topic,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(fontWeight: FontWeight.w700, color: context.tokens.heading),
                ),
              ),
              const SizedBox(width: 8),
              StatusBadge(status: item.status),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            [
              item.clientName,
              _types[item.consultationType] ?? item.consultationType,
              item.schedule,
            ].where((s) => s.isNotEmpty).join(' · '),
            style: TextStyle(fontSize: 12.5, color: context.tokens.textMuted),
          ),
          const SizedBox(height: 14),
          if (busy)
            const Center(
              child: SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2)),
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (item.status == 'pending') ...[
                  _ActionButton(
                    label: 'تأكيد',
                    icon: Icons.check,
                    filled: true,
                    onTap: () => onChangeStatus(item, 'confirmed'),
                  ),
                  _ActionButton(
                    label: 'رفض',
                    icon: Icons.close,
                    onTap: () => onChangeStatus(item, 'cancelled'),
                  ),
                ],
                if (item.status == 'confirmed') ...[
                  if (isVoiceOrVideo)
                    _ActionButton(
                      label: item.consultationType == 'video' ? 'مكالمة فيديو' : 'مكالمة صوتية',
                      icon: item.consultationType == 'video' ? Icons.videocam : Icons.call,
                      filled: true,
                      onTap: () => CallLauncher.start(
                        context,
                        repository: null,
                        consultationId: item.id,
                        callType: item.consultationType,
                        peerName: item.clientName,
                      ),
                    ),
                  _ActionButton(
                    label: 'إتمام',
                    icon: Icons.done_all,
                    onTap: () => onChangeStatus(item, 'completed'),
                  ),
                  _ActionButton(
                    label: 'إلغاء',
                    icon: Icons.cancel_outlined,
                    onTap: () => onChangeStatus(item, 'cancelled'),
                  ),
                ],
              ],
            ),
        ],
      ),
    );
  }
}

class _ActionButton extends StatelessWidget {
  const _ActionButton({
    required this.label,
    required this.icon,
    required this.onTap,
    this.filled = false,
  });

  final String label;
  final IconData icon;
  final VoidCallback onTap;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    // 44px minimum touch target (WCAG 2.5.8), which a bare TextButton does not meet.
    final child = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 17),
        const SizedBox(width: 6),
        Text(label),
      ],
    );
    return SizedBox(
      height: 44,
      child: filled
          ? FilledButton(onPressed: onTap, child: child)
          : OutlinedButton(onPressed: onTap, child: child),
    );
  }
}
