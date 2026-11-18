import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'support_ticket_screen.dart';
import 'support_appointments_screen.dart';

/// The support centre: channels, tickets and support appointments.
///
/// Every channel and both in-app services are read from the server, so a channel
/// the operator disabled simply does not appear — the app never shows a dead
/// contact button. Tickets and appointments are behind their own toggles for the
/// same reason.
class SupportCenterScreen extends StatefulWidget {
  const SupportCenterScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<SupportCenterScreen> createState() => _SupportCenterScreenState();
}

class _SupportCenterScreenState extends State<SupportCenterScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  SupportSettings? _settings;
  List<SupportTicket> _tickets = const [];
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
      final settings = await _repo.supportSettings();
      // Tickets are optional: the channel list must still render if the ticket
      // call fails (the operator may have tickets disabled, or the customer may
      // be a guest).
      List<SupportTicket> tickets = const [];
      if (settings.has('tickets')) {
        try {
          tickets = await _repo.supportTickets();
        } catch (_) {
          tickets = const [];
        }
      }
      if (!mounted) return;
      setState(() {
        _settings = settings;
        _tickets = tickets;
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

  Future<void> _openChannel(SupportChannel channel, bool rtl) async {
    Uri? uri;
    switch (channel.type) {
      case 'email':
        uri = Uri(scheme: 'mailto', path: channel.value);
      case 'whatsapp':
        final url = _settings?.whatsappUrl ?? '';
        uri = url.isNotEmpty ? Uri.tryParse(url) : null;
      default:
        uri = null;
    }
    if (uri == null) return;
    try {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(rtl ? 'تعذّر فتح الرابط.' : 'Could not open the link.'),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;

    return Scaffold(
      appBar: AppBar(title: Text(rtl ? 'مركز الدعم' : 'Support centre')),
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
                            ? 'تعذّر تحميل بعض بيانات الدعم. اسحب للتحديث.'
                            : 'Some support data could not be loaded. Pull to refresh.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  if (_settings != null) _channels(context, rtl, _settings!),
                  if (_settings?.has('tickets') ?? false) ...[
                    const SizedBox(height: 20),
                    _ticketsSection(context, rtl),
                  ],
                  if (_settings?.has('appointments') ?? false) ...[
                    const SizedBox(height: 20),
                    _SettingCard(
                      icon: Icons.event_available_outlined,
                      title: rtl ? 'مواعيد الدعم' : 'Support appointments',
                      subtitle: rtl
                          ? 'احجز موعداً مباشراً مع فريق الدعم.'
                          : 'Book a direct appointment with the support team.',
                      onTap: () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => SupportAppointmentsScreen(
                              repository: widget.repository,
                            ),
                          ),
                        );
                        _load();
                      },
                    ),
                  ],
                ],
              ),
            ),
    );
  }

  Widget _channels(BuildContext context, bool rtl, SupportSettings settings) {
    final visible = settings.channels
        .where((c) => c.type == 'email' || c.type == 'whatsapp')
        .toList();
    if (visible.isEmpty && settings.hours.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: context.tokens.surface2,
          borderRadius: BorderRadius.circular(AppRadius.md),
          border: Border.all(color: context.tokens.border),
        ),
        child: Text(
          rtl
              ? 'لم يفعّل النظام أي قناة تواصل بعد. يمكنك إنشاء تذكرة أدناه.'
              : 'No contact channel is enabled yet. You can open a ticket below.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: rtl ? 'قنوات التواصل' : 'Contact channels'),
        if (settings.hours.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              children: [
                Icon(Icons.schedule, size: 18, color: context.tokens.textMuted),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    settings.hours,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
        ...visible.map(
          (c) => _SettingCard(
            icon: c.type == 'email'
                ? Icons.email_outlined
                : Icons.chat_outlined,
            title: c.type == 'email'
                ? (rtl ? 'البريد الإلكتروني' : 'Email')
                : (rtl ? 'واتساب' : 'WhatsApp'),
            subtitle: c.value,
            onTap: () => _openChannel(c, rtl),
          ),
        ),
      ],
    );
  }

  Widget _ticketsSection(BuildContext context, bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: rtl ? 'تذاكري' : 'My tickets',
          actionLabel: rtl ? 'تذكرة جديدة' : 'New ticket',
          onAction: () async {
            await Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) =>
                    SupportTicketScreen(repository: widget.repository),
              ),
            );
            _load();
          },
        ),
        if (_tickets.isEmpty)
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: context.tokens.surface2,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: context.tokens.border),
            ),
            child: Text(
              rtl ? 'لا توجد تذاكر بعد.' : 'No tickets yet.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          )
        else
          ..._tickets.map(
            (t) => _SettingCard(
              icon: t.isClosed
                  ? Icons.check_circle_outline
                  : Icons.confirmation_number_outlined,
              title: t.subject,
              subtitle: '${t.number} · ${_statusLabel(t.status, rtl)}',
              trailingBadge: t.unread ? (rtl ? 'رد جديد' : 'New reply') : null,
              onTap: () async {
                await Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => SupportTicketScreen(
                      ticketId: t.id,
                      repository: widget.repository,
                    ),
                  ),
                );
                _load();
              },
            ),
          ),
      ],
    );
  }

  String _statusLabel(String status, bool rtl) {
    switch (status) {
      case 'open':
        return rtl ? 'مفتوحة' : 'Open';
      case 'pending':
        return rtl ? 'قيد الانتظار' : 'Pending';
      case 'answered':
        return rtl ? 'تم الرد' : 'Answered';
      case 'closed':
        return rtl ? 'مغلقة' : 'Closed';
      default:
        return status;
    }
  }
}

/// A tappable settings-style card, matching the account screen's tile.
class _SettingCard extends StatelessWidget {
  const _SettingCard({
    required this.icon,
    required this.title,
    required this.onTap,
    this.subtitle,
    this.trailingBadge,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final String? trailingBadge;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: context.tokens.surface,
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: context.tokens.border),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: ListTile(
          onTap: onTap,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
          leading: Icon(icon, size: 20, color: context.tokens.textMuted),
          title: Text(title, style: Theme.of(context).textTheme.bodyMedium),
          subtitle: subtitle == null
              ? null
              : Text(subtitle!, style: Theme.of(context).textTheme.bodySmall),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (trailingBadge != null)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 8,
                    vertical: 2,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.teal.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                  ),
                  child: Text(
                    trailingBadge!,
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.teal,
                    ),
                  ),
                ),
              const SizedBox(width: 6),
              Icon(
                Icons.chevron_left,
                size: 18,
                color: context.tokens.textSubtle,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
