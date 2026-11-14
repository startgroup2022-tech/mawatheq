import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Support appointments: the open slots and the customer's own requests.
///
/// A slot is only offered when the server says it still has capacity
/// (`remaining > 0`); a full slot is shown as unavailable rather than hidden, so
/// the customer can see the diary is real. Booking and cancelling both go through
/// the server, which owns the capacity check — the app never assumes it won a race.
class SupportAppointmentsScreen extends StatefulWidget {
  const SupportAppointmentsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<SupportAppointmentsScreen> createState() =>
      _SupportAppointmentsScreenState();
}

class _SupportAppointmentsScreenState extends State<SupportAppointmentsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<SupportSlot> _slots = const [];
  List<SupportAppointment> _appointments = const [];
  bool _loading = true;
  bool _busy = false;
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
      final slots = await _repo.supportSlots();
      final appointments = await _repo.supportAppointments();
      if (!mounted) return;
      setState(() {
        _slots = slots;
        _appointments = appointments;
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
      setState(() => _loading = false);
    }
  }

  Future<void> _book(SupportSlot slot, bool rtl) async {
    final subject = await _askSubject(rtl);
    if (subject == null || subject.trim().isEmpty || !mounted) return;
    setState(() => _busy = true);
    try {
      await _repo.bookSupportAppointment(
        slotId: slot.id,
        subject: subject.trim(),
      );
      if (!mounted) return;
      _snack(rtl ? 'تم إرسال طلب الموعد.' : 'Appointment requested.');
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    } catch (_) {
      if (!mounted) return;
      _snack(rtl ? 'تعذّر حجز الموعد.' : 'Could not book the appointment.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(SupportAppointment appointment, bool rtl) async {
    final confirmed = await ConfirmationDialog.show(
      context,
      title: rtl ? 'إلغاء الموعد' : 'Cancel appointment',
      message: rtl
          ? 'هل تريد إلغاء طلب الموعد؟'
          : 'Cancel this appointment request?',
      confirmLabel: rtl ? 'إلغاء الموعد' : 'Cancel appointment',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    setState(() => _busy = true);
    try {
      await _repo.cancelSupportAppointment(appointment.id);
      await _load();
    } catch (_) {
      if (!mounted) return;
      _snack(rtl ? 'تعذّر إلغاء الموعد.' : 'Could not cancel the appointment.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<String?> _askSubject(bool rtl) {
    final controller = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(rtl ? 'موضوع الموعد' : 'Appointment subject'),
        content: TextField(
          controller: controller,
          autofocus: true,
          maxLength: 200,
          decoration: InputDecoration(
            labelText: rtl ? 'الموضوع' : 'Subject',
            hintText: rtl ? 'بماذا نساعدك؟' : 'How can we help?',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: Text(rtl ? 'إلغاء' : 'Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(controller.text),
            child: Text(rtl ? 'متابعة' : 'Continue'),
          ),
        ],
      ),
    );
  }

  void _snack(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      appBar: AppBar(
        title: Text(rtl ? 'مواعيد الدعم' : 'Support appointments'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4, height: 64),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(
                        rtl
                            ? 'تعذّر تحميل المواعيد. اسحب للتحديث.'
                            : 'Could not load appointments. Pull to refresh.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  if (_appointments.isNotEmpty) ...[
                    SectionHeader(title: rtl ? 'طلباتي' : 'My requests'),
                    ..._appointments.map(
                      (a) => _appointmentTile(context, rtl, a),
                    ),
                    const SizedBox(height: 20),
                  ],
                  SectionHeader(
                    title: rtl ? 'المواعيد المتاحة' : 'Available slots',
                  ),
                  if (_slots.isEmpty)
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: context.tokens.surface2,
                        borderRadius: BorderRadius.circular(AppRadius.md),
                        border: Border.all(color: context.tokens.border),
                      ),
                      child: Text(
                        rtl
                            ? 'لا توجد مواعيد متاحة حالياً. جرّب لاحقاً أو افتح تذكرة.'
                            : 'No slots are open right now. Try later or open a ticket.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    )
                  else
                    ..._slots.map((s) => _slotTile(context, rtl, s)),
                ],
              ),
            ),
    );
  }

  Widget _slotTile(BuildContext context, bool rtl, SupportSlot slot) {
    final full = slot.isFull;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: context.tokens.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: context.tokens.border),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: ListTile(
          enabled: !full && !_busy,
          leading: Icon(Icons.event_outlined, color: context.tokens.textMuted),
          title: Text(slot.startsAt),
          subtitle: Text(
            full
                ? (rtl ? 'مكتمل' : 'Full')
                : (rtl
                      ? 'المتبقّي: ${slot.remaining}'
                      : '${slot.remaining} left'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
          trailing: FilledButton(
            onPressed: full || _busy ? null : () => _book(slot, rtl),
            child: Text(rtl ? 'حجز' : 'Book'),
          ),
        ),
      ),
    );
  }

  Widget _appointmentTile(
    BuildContext context,
    bool rtl,
    SupportAppointment a,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: context.tokens.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: context.tokens.border),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: Padding(
          padding: const EdgeInsets.all(13),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      a.subject,
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${a.reference} · ${a.startsAt}',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                    if (a.adminNote.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                          a.adminNote,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  StatusBadge(status: a.status),
                  if (a.status == 'pending' || a.status == 'confirmed')
                    TextButton(
                      onPressed: _busy ? null : () => _cancel(a, rtl),
                      child: Text(rtl ? 'إلغاء' : 'Cancel'),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
