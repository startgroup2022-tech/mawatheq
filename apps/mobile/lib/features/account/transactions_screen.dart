import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'invoice_detail_screen.dart';

/// The account's full transaction history.
///
/// One list for everything the customer paid for — consultations, packages,
/// invoices and invoice payments — because the server merges the four tables into
/// one shape (see includes/transactions.php). The type and status filters are sent
/// to the server, so the list and its count always describe the same rows; a
/// client-side filter would drift the moment the history outgrows one page.
class TransactionsScreen extends StatefulWidget {
  const TransactionsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<TransactionsScreen> createState() => _TransactionsScreenState();
}

class _TransactionsScreenState extends State<TransactionsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  TransactionPage? _page;
  bool _loading = true;
  String? _error;

  /// 'all' or one of the server's type keys.
  String _type = 'all';

  /// 'all' or one of the server's status keys.
  String _status = 'all';

  static const _statusKeys = [
    'all',
    'paid',
    'pending',
    'unpaid',
    'failed',
    'refunded',
  ];

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
      final page = await _repo.transactions(type: _type, status: _status);
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
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = null;
      });
    }
  }

  static const _typeKeys = [
    'all',
    'consultation',
    'package',
    'invoice',
    'invoice_payment',
    'refund',
  ];

  String _typeLabel(String key, bool rtl) {
    switch (key) {
      case 'consultation':
        return rtl ? 'استشارات' : 'Consultations';
      case 'package':
        return rtl ? 'باقات' : 'Packages';
      case 'invoice':
        return rtl ? 'فواتير' : 'Invoices';
      case 'invoice_payment':
        return rtl ? 'دفعات' : 'Payments';
      case 'refund':
        return rtl ? 'مبالغ مستردة' : 'Refunds';
      default:
        return rtl ? 'الكل' : 'All';
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    final page = _page;

    return Scaffold(
      appBar: AppBar(title: Text(rtl ? 'سجل العمليات' : 'Transaction history')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (page != null) _summaryCard(context, page.summary, rtl),
            const SizedBox(height: 12),
            _filterRow(context, rtl),
            const SizedBox(height: 16),
            if (_loading)
              const LoadingSkeleton(count: 4, height: 64)
            else if (_error != null)
              _errorState(context, rtl)
            else if (page == null || page.transactions.isEmpty)
              _emptyState(context, rtl)
            else
              ...page.transactions.map(
                (t) => _transactionTile(context, t, rtl),
              ),
          ],
        ),
      ),
    );
  }

  Widget _summaryCard(BuildContext context, TransactionSummary s, bool rtl) {
    Widget stat(String label, String value, Color color) => Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: Theme.of(context).textTheme.labelSmall),
          const SizedBox(height: 2),
          Text(
            value,
            style: TextStyle(
              fontWeight: FontWeight.w800,
              color: color,
              fontSize: 15,
            ),
          ),
        ],
      ),
    );
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: context.tokens.washGradient,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.washBorder),
      ),
      child: Row(
        children: [
          stat(rtl ? 'المدفوع' : 'Paid', s.paid, context.tokens.successFg),
          stat(rtl ? 'المعلّق' : 'Pending', s.pending, context.tokens.heading),
          stat(rtl ? 'المسترد' : 'Refunded', s.refunded, AppColors.danger),
        ],
      ),
    );
  }

  Widget _filterRow(BuildContext context, bool rtl) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final key in _typeKeys) ...[
                ChoiceChip(
                  label: Text(_typeLabel(key, rtl)),
                  selected: _type == key,
                  onSelected: (_) {
                    setState(() => _type = key);
                    _load();
                  },
                ),
                const SizedBox(width: 8),
              ],
            ],
          ),
        ),
        const SizedBox(height: 8),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final key in _statusKeys) ...[
                ChoiceChip(
                  label: Text(_statusLabel(key, rtl)),
                  selected: _status == key,
                  onSelected: (_) {
                    setState(() => _status = key);
                    _load();
                  },
                ),
                const SizedBox(width: 8),
              ],
            ],
          ),
        ),
      ],
    );
  }

  String _statusLabel(String key, bool rtl) {
    switch (key) {
      case 'paid':
        return rtl ? 'مدفوع' : 'Paid';
      case 'pending':
        return rtl ? 'قيد الانتظار' : 'Pending';
      case 'unpaid':
        return rtl ? 'غير مدفوع' : 'Unpaid';
      case 'failed':
        return rtl ? 'فشل' : 'Failed';
      case 'refunded':
        return rtl ? 'مسترد' : 'Refunded';
      default:
        return rtl ? 'كل الحالات' : 'All statuses';
    }
  }

  Widget _transactionTile(BuildContext context, Transaction t, bool rtl) {
    final color = switch (t.status) {
      'paid' => context.tokens.successFg,
      'failed' => AppColors.danger,
      'refunded' => AppColors.warning,
      _ => context.tokens.heading,
    };
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: context.tokens.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: context.tokens.border),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.md),
          onTap: t.type == 'invoice'
              ? () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => InvoiceDetailScreen(
                      invoiceId: t.id,
                      repository: widget.repository,
                    ),
                  ),
                )
              : null,
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        t.title.isEmpty ? _typeLabel(t.type, rtl) : t.title,
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      if (t.subtitle.isNotEmpty)
                        Text(
                          t.subtitle,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      if (t.date.isNotEmpty)
                        Text(
                          t.date,
                          style: Theme.of(context).textTheme.labelSmall,
                        ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      '${t.isRefund ? '+' : ''}${t.amount} ${t.currency}',
                      style: TextStyle(
                        fontWeight: FontWeight.w800,
                        color: color,
                      ),
                    ),
                    const SizedBox(height: 4),
                    StatusBadge(status: t.status),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _emptyState(BuildContext context, bool rtl) => Container(
    padding: const EdgeInsets.all(20),
    decoration: BoxDecoration(
      color: context.tokens.surface2,
      borderRadius: BorderRadius.circular(AppRadius.md),
      border: Border.all(color: context.tokens.border),
    ),
    child: Text(
      rtl
          ? 'لا توجد عمليات مطابقة. جرّب مسح عوامل التصفية.'
          : 'No matching transactions. Try clearing the filters.',
      style: Theme.of(context).textTheme.bodySmall,
    ),
  );

  Widget _errorState(BuildContext context, bool rtl) => Column(
    children: [
      Text(
        rtl
            ? 'تعذّر تحميل سجل العمليات.'
            : 'Could not load the transaction history.',
        style: Theme.of(context).textTheme.bodySmall,
      ),
      const SizedBox(height: 8),
      OutlinedButton.icon(
        onPressed: _load,
        icon: const Icon(Icons.refresh, size: 18),
        label: Text(rtl ? 'إعادة المحاولة' : 'Retry'),
      ),
    ],
  );
}
