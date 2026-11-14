import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config/app_config.dart';
import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// One invoice with its line items, payments and a printable receipt link.
///
/// The receipt is opened in the browser rather than rendered natively: the
/// printable page already exists (`invoice-print.php`) and produces the exact
/// document the customer wants to file or forward. Re-drawing it in Flutter would
/// be a second copy of the same document that can drift from the web one.
class InvoiceDetailScreen extends StatefulWidget {
  const InvoiceDetailScreen({
    super.key,
    required this.invoiceId,
    this.repository,
  });

  final int invoiceId;
  final ApiRepository? repository;

  @override
  State<InvoiceDetailScreen> createState() => _InvoiceDetailScreenState();
}

class _InvoiceDetailScreenState extends State<InvoiceDetailScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  InvoiceDetail? _invoice;
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
      final invoice = await _repo.invoiceDetail(widget.invoiceId);
      if (!mounted) return;
      setState(() {
        _invoice = invoice;
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

  Future<void> _openReceipt(String url, bool rtl) async {
    // The API returns an absolute URL; fall back to the configured site if a
    // deployment returned a relative one, so the button never opens nothing.
    final target = url.isEmpty
        ? '${AppConfig.siteBase}/invoice-print.php?id=${widget.invoiceId}'
        : url;
    final uri = Uri.tryParse(target);
    if (uri == null) return;
    var opened = false;
    try {
      opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      opened = false;
    }
    if (opened || !mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          rtl ? 'تعذّر فتح الفاتورة.' : 'Could not open the invoice.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      appBar: AppBar(title: Text(rtl ? 'تفاصيل الفاتورة' : 'Invoice details')),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 5, height: 60),
            )
          : _error != null
          ? _errorState(context, rtl)
          : _body(context, rtl, _invoice!),
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
                (rtl ? 'تعذّر تحميل الفاتورة.' : 'Could not load the invoice.'),
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

  Widget _body(BuildContext context, bool rtl, InvoiceDetail inv) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            gradient: context.tokens.washGradient,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: context.tokens.washBorder),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      inv.number.isEmpty
                          ? (rtl ? 'فاتورة' : 'Invoice')
                          : inv.number,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                  StatusBadge(status: inv.status),
                ],
              ),
              if (inv.party.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(inv.party, style: Theme.of(context).textTheme.bodySmall),
              ],
              if (inv.issuedAt.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  inv.issuedAt,
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
              const SizedBox(height: 14),
              _amountRow(
                context,
                rtl ? 'الإجمالي' : 'Total',
                '${inv.total.toStringAsFixed(2)} ${inv.currency}',
                context.tokens.heading,
              ),
              _amountRow(
                context,
                rtl ? 'المدفوع' : 'Paid',
                '${inv.amountPaid.toStringAsFixed(2)} ${inv.currency}',
                context.tokens.successFg,
              ),
              _amountRow(
                context,
                rtl ? 'المتبقّي' : 'Balance',
                '${inv.balance.toStringAsFixed(2)} ${inv.currency}',
                inv.balance > 0 ? AppColors.danger : context.tokens.textMuted,
              ),
            ],
          ),
        ),
        if (inv.description.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text(inv.description, style: Theme.of(context).textTheme.bodySmall),
        ],
        if (inv.items.isNotEmpty) ...[
          const SizedBox(height: 20),
          SectionHeader(title: rtl ? 'البنود' : 'Line items'),
          ...inv.items.map((item) => _itemRow(context, item, inv.currency)),
        ],
        if (inv.payments.isNotEmpty) ...[
          const SizedBox(height: 20),
          SectionHeader(title: rtl ? 'المدفوعات' : 'Payments'),
          ...inv.payments.map(
            (p) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          p.method.isEmpty
                              ? (rtl ? 'دفعة' : 'Payment')
                              : p.method,
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                        if (p.paidAt.isNotEmpty)
                          Text(
                            p.paidAt,
                            style: Theme.of(context).textTheme.labelSmall,
                          ),
                      ],
                    ),
                  ),
                  Text(
                    '${p.amount.toStringAsFixed(2)} ${p.currency}',
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: context.tokens.heading,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
        const SizedBox(height: 24),
        FilledButton.icon(
          onPressed: () => _openReceipt(inv.receiptUrl, rtl),
          icon: const Icon(Icons.print_outlined, size: 18),
          label: Text(rtl ? 'عرض / طباعة الفاتورة' : 'View / print invoice'),
          style: FilledButton.styleFrom(minimumSize: const Size(0, 48)),
        ),
      ],
    );
  }

  Widget _amountRow(
    BuildContext context,
    String label,
    String value,
    Color color,
  ) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        Text(
          value,
          style: TextStyle(fontWeight: FontWeight.w800, color: color),
        ),
      ],
    ),
  );

  Widget _itemRow(
    BuildContext context,
    InvoiceItem item,
    String currency,
  ) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Row(
      children: [
        Expanded(
          child: Text(
            item.description,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        Text(
          item.quantity != 0
              ? '${item.quantity.toStringAsFixed(0)} × ${item.unitPrice.toStringAsFixed(2)}'
              : item.total.toStringAsFixed(2),
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(width: 12),
        Text(
          '${item.total.toStringAsFixed(2)} $currency',
          style: TextStyle(
            fontWeight: FontWeight.w700,
            color: context.tokens.heading,
          ),
        ),
      ],
    ),
  );
}
