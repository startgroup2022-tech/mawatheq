import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Notifications from `GET /api/v1/notifications.php`, with a server-backed
/// "mark all read" action.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, this.repository});

  /// The shared repository. Falls back to the default client so an embedding
  /// that does not inject it still works, while a caller that needs to control
  /// the network edge can supply one instead.
  final ApiRepository? repository;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  NotificationPage? _page;
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
      final page = await _repo.notifications();
      if (!mounted) return;
      setState(() {
        _page = page;
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

  Future<void> _markAllRead() async {
    try {
      await _repo.markAllNotificationsRead();
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _page?.items ?? const <AppNotification>[];

    return Scaffold(
      appBar: BrandAppBar(
        subtitle: 'الإشعارات',
        actions: [
          if ((_page?.unreadCount ?? 0) > 0)
            TextButton(
              onPressed: _markAllRead,
              child: const Text('تحديد الكل كمقروء'),
            ),
        ],
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 5),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : items.isEmpty
                  ? const EmptyState(
                      icon: Icons.notifications_none,
                      title: 'لا توجد إشعارات',
                      message: 'ستظهر هنا إشعاراتك عند وصولها.',
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: items.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (context, i) => _NotificationTile(item: items[i]),
                      ),
                    ),
    );
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({required this.item});

  final AppNotification item;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: item.isRead ? context.tokens.surface : context.tokens.infoBg,
        border: Border.all(
          color: item.isRead ? context.tokens.border : context.tokens.washBorder,
        ),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: item.isRead ? context.tokens.surface3 : context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            child: Icon(
              item.isRead
                  ? Icons.notifications_none
                  : Icons.notifications_active_outlined,
              size: 17,
              color: item.isRead ? context.tokens.textSubtle : AppColors.teal,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.title,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontSize: AppFontSize.base,
                        fontWeight:
                            item.isRead ? FontWeight.w500 : FontWeight.w700,
                      ),
                ),
                if (item.body.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(item.body, style: Theme.of(context).textTheme.bodySmall),
                ],
                if (item.createdAt.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Text(
                    item.createdAt,
                    style: TextStyle(
                      fontSize: 11,
                      color: context.tokens.textSubtle,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
