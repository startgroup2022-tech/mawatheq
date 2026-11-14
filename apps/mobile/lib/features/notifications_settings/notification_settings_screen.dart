import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// «إعدادات الإشعارات» — the customer's notification channels, from
/// `GET/POST /api/v1/notification-preferences.php`.
///
/// The channel catalogue (keys, labels, descriptions) comes from the server, so the app
/// shows exactly the channels the backend can enforce. The in-app bell is `locked`: it
/// cannot be turned off, because it is the platform's own record of activity, and the
/// switch is drawn disabled rather than hidden so the customer understands why.
class NotificationSettingsScreen extends StatefulWidget {
  const NotificationSettingsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<NotificationSettingsScreen> createState() => _NotificationSettingsScreenState();
}

class _NotificationSettingsScreenState extends State<NotificationSettingsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<NotificationChannel> _channels = const [];
  bool _loading = true;
  String? _error;
  String? _busyKey;

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
      final list = await _repo.notificationPreferences();
      if (!mounted) return;
      setState(() {
        _channels = list;
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
        _error = 'تعذّر تحميل إعدادات الإشعارات.';
      });
    }
  }

  Future<void> _toggle(NotificationChannel channel, bool value) async {
    setState(() => _busyKey = channel.key);
    try {
      final updated = await _repo.setNotificationPreference(
        channel: channel.key,
        enabled: value,
      );
      if (!mounted) return;
      setState(() {
        _channels = updated;
        _busyKey = null;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _busyKey = null);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر حفظ الإعداد. حاول مرة أخرى.')),
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
        title: Text(rtl ? 'إعدادات الإشعارات' : 'Notification settings'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 3),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _body(rtl),
    );
  }

  Widget _body(bool rtl) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          rtl
              ? 'اختر القنوات التي تصلك عبرها التنبيهات. تبقى إشعارات التطبيق مفعّلة دائماً.'
              : 'Choose the channels you receive alerts on. In-app notifications stay on.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        for (final channel in _channels) ...[
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            decoration: BoxDecoration(
              color: context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: context.tokens.border),
            ),
            child: Row(
              children: [
                Icon(_iconFor(channel.key), color: AppColors.teal, size: 22),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        channel.label(rtl: rtl),
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: context.tokens.heading,
                        ),
                      ),
                      if (channel.description(rtl: rtl).isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(
                          channel.description(rtl: rtl),
                          style: TextStyle(
                            color: context.tokens.textMuted,
                            fontSize: 12.5,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                if (_busyKey == channel.key)
                  const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                else
                  Switch(
                    value: channel.locked ? true : channel.enabled,
                    onChanged: channel.locked ? null : (v) => _toggle(channel, v),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 10),
        ],
        if (_channels.any((c) => c.locked))
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              rtl
                  ? 'إشعارات التطبيق مفعّلة دائماً لأنها سجل النشاط داخل المنصة.'
                  : 'In-app notifications are always on — they are the platform activity record.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],
    );
  }

  IconData _iconFor(String key) {
    switch (key) {
      case 'email':
        return Icons.mail_outline;
      case 'push':
        return Icons.notifications_outlined;
      default:
        return Icons.inbox_outlined;
    }
  }
}
