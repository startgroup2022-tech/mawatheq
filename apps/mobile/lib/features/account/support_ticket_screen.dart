import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// A support ticket: create a new one, or open an existing thread and reply.
///
/// One screen for both because the shape is the same — a subject, a body, and a
/// message list. [ticketId] null means "new ticket"; a value means "open and
/// reply". The server decides who may read the thread, so a ticket id the account
/// does not own simply 404s and the screen says so instead of showing a blank page.
class SupportTicketScreen extends StatefulWidget {
  const SupportTicketScreen({super.key, this.ticketId, this.repository});

  final int? ticketId;
  final ApiRepository? repository;

  @override
  State<SupportTicketScreen> createState() => _SupportTicketScreenState();
}

class _SupportTicketScreenState extends State<SupportTicketScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  final _subjectController = TextEditingController();
  final _bodyController = TextEditingController();
  final _replyController = TextEditingController();

  SupportTicketDetail? _detail;
  List<SupportCategory> _categories = const [];
  int _categoryId = 0;
  bool _loading = true;
  bool _sending = false;
  String? _error;

  bool get _isNew => widget.ticketId == null;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _subjectController.dispose();
    _bodyController.dispose();
    _replyController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final categories = await _repo.supportCategories();
      if (!mounted) return;
      setState(() {
        _categories = categories;
        if (_categoryId == 0 && categories.isNotEmpty) {
          _categoryId = categories.first.id;
        }
      });
      if (!_isNew) {
        final detail = await _repo.supportTicket(widget.ticketId!);
        if (!mounted) return;
        setState(() => _detail = detail);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    } catch (_) {
      // Non-fatal: the form still works without categories.
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _create() async {
    final subject = _subjectController.text.trim();
    final body = _bodyController.text.trim();
    final rtl = context.read<LocaleController>().isRtl;
    if (subject.isEmpty || body.isEmpty) {
      _snack(
        rtl ? 'يرجى إدخال الموضوع والتفاصيل.' : 'Enter a subject and details.',
      );
      return;
    }
    setState(() => _sending = true);
    try {
      await _repo.createSupportTicket(
        subject: subject,
        body: body,
        categoryId: _categoryId,
      );
      if (!mounted) return;
      _snack(rtl ? 'تم إنشاء التذكرة.' : 'Ticket created.');
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _sending = false);
      _snack(e.message);
    } catch (_) {
      if (!mounted) return;
      setState(() => _sending = false);
      _snack(rtl ? 'تعذّر إنشاء التذكرة.' : 'Could not create the ticket.');
    }
  }

  Future<void> _reply() async {
    final body = _replyController.text.trim();
    final rtl = context.read<LocaleController>().isRtl;
    if (body.isEmpty) return;
    setState(() => _sending = true);
    try {
      await _repo.replySupportTicket(id: widget.ticketId!, body: body);
      _replyController.clear();
      final detail = await _repo.supportTicket(widget.ticketId!);
      if (!mounted) return;
      setState(() {
        _detail = detail;
        _sending = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _sending = false);
      _snack(e.message);
    } catch (_) {
      if (!mounted) return;
      setState(() => _sending = false);
      _snack(rtl ? 'تعذّر إرسال الرد.' : 'Could not send the reply.');
    }
  }

  Future<void> _toggleStatus() async {
    final detail = _detail;
    if (detail == null) return;
    final rtl = context.read<LocaleController>().isRtl;
    final close = !detail.ticket.isClosed;
    final confirmed = await ConfirmationDialog.show(
      context,
      title: close
          ? (rtl ? 'إغلاق التذكرة' : 'Close ticket')
          : (rtl ? 'إعادة الفتح' : 'Reopen ticket'),
      message: close
          ? (rtl ? 'هل أنت متأكد من إغلاق التذكرة؟' : 'Close this ticket?')
          : (rtl ? 'هل تريد إعادة فتح التذكرة؟' : 'Reopen this ticket?'),
      confirmLabel: rtl ? 'تأكيد' : 'Confirm',
    );
    if (!confirmed || !mounted) return;
    try {
      await _repo.setSupportTicketStatus(id: widget.ticketId!, close: close);
      await _load();
    } catch (_) {
      if (!mounted) return;
      _snack(rtl ? 'تعذّر تحديث التذكرة.' : 'Could not update the ticket.');
    }
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
        title: Text(
          _isNew
              ? (rtl ? 'تذكرة جديدة' : 'New ticket')
              : (rtl ? 'تفاصيل التذكرة' : 'Ticket details'),
        ),
        actions: [
          if (!_isNew && _detail != null)
            IconButton(
              tooltip: _detail!.ticket.isClosed
                  ? (rtl ? 'إعادة الفتح' : 'Reopen')
                  : (rtl ? 'إغلاق' : 'Close'),
              onPressed: _toggleStatus,
              icon: Icon(
                _detail!.ticket.isClosed
                    ? Icons.lock_open_outlined
                    : Icons.lock_outline,
              ),
            ),
        ],
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4, height: 60),
            )
          : _isNew
          ? _newTicketForm(context, rtl)
          : _detail == null
          ? _errorState(context, rtl)
          : _thread(context, rtl, _detail!),
    );
  }

  Widget _newTicketForm(BuildContext context, bool rtl) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_categories.isNotEmpty) ...[
          Text(
            rtl ? 'الفئة' : 'Category',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 6),
          DropdownButtonFormField<int>(
            initialValue: _categoryId,
            items: _categories
                .map(
                  (c) =>
                      DropdownMenuItem(value: c.id, child: Text(c.name(rtl))),
                )
                .toList(),
            onChanged: (v) => setState(() => _categoryId = v ?? _categoryId),
          ),
          const SizedBox(height: 16),
        ],
        TextField(
          controller: _subjectController,
          maxLength: 200,
          decoration: InputDecoration(
            labelText: rtl ? 'الموضوع' : 'Subject',
            border: const OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _bodyController,
          maxLines: 6,
          maxLength: 5000,
          decoration: InputDecoration(
            labelText: rtl ? 'التفاصيل' : 'Details',
            alignLabelWithHint: true,
            border: const OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _sending ? null : _create,
          style: FilledButton.styleFrom(minimumSize: const Size(0, 48)),
          child: _sending
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Text(rtl ? 'إرسال التذكرة' : 'Submit ticket'),
        ),
      ],
    );
  }

  Widget _thread(BuildContext context, bool rtl, SupportTicketDetail detail) {
    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      detail.ticket.subject,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                  StatusBadge(status: detail.ticket.status),
                ],
              ),
              if (detail.ticket.number.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    detail.ticket.number,
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                ),
              const SizedBox(height: 16),
              ...detail.messages.map((m) => _message(context, rtl, m)),
              if (detail.attachments.isNotEmpty) ...[
                const SizedBox(height: 12),
                SectionHeader(title: rtl ? 'المرفقات' : 'Attachments'),
                ...detail.attachments.map(
                  (a) => Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      children: [
                        Icon(
                          Icons.attach_file,
                          size: 16,
                          color: context.tokens.textMuted,
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            a.name,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
        if (!detail.ticket.isClosed)
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _replyController,
                      minLines: 1,
                      maxLines: 4,
                      decoration: InputDecoration(
                        hintText: rtl ? 'اكتب ردك…' : 'Write a reply…',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    onPressed: _sending ? null : _reply,
                    icon: _sending
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.send),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _message(BuildContext context, bool rtl, SupportMessage m) {
    final fromSupport = m.fromSupport;
    return Align(
      alignment: fromSupport ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(12),
        constraints: const BoxConstraints(maxWidth: 320),
        decoration: BoxDecoration(
          color: fromSupport
              ? context.tokens.surface2
              : AppColors.teal.withValues(alpha: 0.10),
          borderRadius: BorderRadius.circular(AppRadius.md),
          border: Border.all(color: context.tokens.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              fromSupport
                  ? (rtl ? 'فريق الدعم' : 'Support')
                  : (rtl ? 'أنت' : 'You'),
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12),
            ),
            const SizedBox(height: 4),
            Text(m.body, style: Theme.of(context).textTheme.bodyMedium),
            if (m.createdAt.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  m.createdAt,
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _errorState(BuildContext context, bool rtl) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.error_outline, size: 48, color: AppColors.danger),
          const SizedBox(height: 12),
          Text(
            _error ??
                (rtl ? 'تعذّر تحميل التذكرة.' : 'Could not load the ticket.'),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: _load,
            icon: const Icon(Icons.refresh, size: 18),
            label: Text(rtl ? 'إعادة المحاولة' : 'Retry'),
          ),
        ],
      ),
    ),
  );
}
