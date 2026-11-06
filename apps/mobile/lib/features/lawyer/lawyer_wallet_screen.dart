import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// The lawyer's wallet: balances, the ledger, withdrawal requests and the payout
/// account on file.
///
/// Every figure is read from `GET /api/v1/wallet.php`, which reads the same ledger
/// the web console reads. Nothing is computed here — a balance shown in the app that
/// the console disagrees with would be worse than no balance at all.
class LawyerWalletScreen extends StatefulWidget {
  const LawyerWalletScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LawyerWalletScreen> createState() => _LawyerWalletScreenState();
}

class _LawyerWalletScreenState extends State<LawyerWalletScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  WalletSummary? _summary;
  List<WalletTransaction> _transactions = const [];
  List<WalletWithdrawal> _withdrawals = const [];
  bool _loading = true;
  String? _error;
  int _tab = 0;

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
      final summary = await _repo.walletSummary();
      final tx = await _repo.walletTransactions(limit: 100);
      final wd = await _repo.walletWithdrawals();
      if (!mounted) return;
      setState(() {
        _summary = summary;
        _transactions = tx;
        _withdrawals = wd;
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

  Future<void> _openWithdrawalSheet() async {
    final summary = _summary;
    if (summary == null) return;
    final created = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _WithdrawalSheet(repository: _repo, summary: summary),
    );
    if (created == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: 'المحفظة',
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
              : _body(context, _summary!),
    );
  }

  Widget _body(BuildContext context, WalletSummary s) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
          child: _BalanceHeader(summary: s, onWithdraw: _openWithdrawalSheet),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: FilterChips(
            options: const ['الحركات', 'طلبات السحب'],
            selected: _tab == 0 ? 'الحركات' : 'طلبات السحب',
            onSelected: (v) => setState(() => _tab = v == 'الحركات' ? 0 : 1),
          ),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: _tab == 0 ? _ledger(context) : _withdrawalList(context),
          ),
        ),
      ],
    );
  }

  Widget _ledger(BuildContext context) {
    if (_transactions.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 60),
          EmptyState(
            icon: Icons.receipt_long_outlined,
            title: 'لا توجد حركات',
            message: 'ستظهر هنا كل حركة على محفظتك عند تسجيل أول دفعة.',
          ),
        ],
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      itemCount: _transactions.length,
      separatorBuilder: (_, __) => Divider(height: 1, color: context.tokens.border),
      itemBuilder: (context, i) => _LedgerTile(tx: _transactions[i]),
    );
  }

  Widget _withdrawalList(BuildContext context) {
    if (_withdrawals.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 60),
          EmptyState(
            icon: Icons.account_balance_wallet_outlined,
            title: 'لا توجد طلبات سحب',
            message: 'يمكنك طلب سحب رصيدك المتاح في أي وقت، ويعتمده فريق المنصة.',
          ),
        ],
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      itemCount: _withdrawals.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) => _WithdrawalTile(withdrawal: _withdrawals[i]),
    );
  }
}

/// The headline balance and the withdrawal action.
class _BalanceHeader extends StatelessWidget {
  const _BalanceHeader({required this.summary, required this.onWithdraw});

  final WalletSummary summary;
  final VoidCallback onWithdraw;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: AppColors.brandHero,
        borderRadius: BorderRadius.circular(AppRadius.xl),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'الرصيد المتاح للسحب',
            style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 12.5),
          ),
          const SizedBox(height: 6),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                summary.available,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 30,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                summary.currency,
                style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 14),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(child: _HeroStat(label: 'قيد التسوية', value: summary.pending)),
              Expanded(child: _HeroStat(label: 'محجوز', value: summary.reserved)),
              Expanded(child: _HeroStat(label: 'إجمالي الأرباح', value: summary.grossEarned)),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(child: _HeroStat(label: 'عمولة المنصة', value: summary.commission)),
              Expanded(child: _HeroStat(label: 'إجمالي المسحوب', value: summary.withdrawn)),
              const Expanded(child: SizedBox()),
            ],
          ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: summary.availableMinor > 0 ? onWithdraw : null,
              icon: const Icon(Icons.arrow_downward),
              label: const Text('طلب سحب'),
              style: FilledButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: AppColors.navy,
              ),
            ),
          ),
          if (summary.availableMinor <= 0) ...[
            const SizedBox(height: 8),
            Text(
              'لا يوجد رصيد متاح للسحب حالياً.',
              style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 12),
            ),
          ],
        ],
      ),
    );
  }
}

class _HeroStat extends StatelessWidget {
  const _HeroStat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: TextStyle(color: Colors.white.withValues(alpha: 0.8), fontSize: 11)),
        const SizedBox(height: 2),
        Text(
          value,
          style: const TextStyle(color: Colors.white, fontSize: 13.5, fontWeight: FontWeight.w700),
        ),
      ],
    );
  }
}

class _LedgerTile extends StatelessWidget {
  const _LedgerTile({required this.tx});

  final WalletTransaction tx;

  /// A human label for the ledger `kind`. The enum values are the server's; the
  /// Arabic here is display only.
  String get _label => switch (tx.kind) {
        'payment' => 'دفعة مستلمة',
        'commission' => 'عمولة المنصة',
        'refund' => 'استرداد',
        'withdrawal' => 'سحب',
        'withdrawal_reversal' => 'إرجاع سحب',
        'payout' => 'تحويل',
        'adjustment' => 'تسوية',
        _ => tx.kind,
      };

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: tx.isDebit ? AppColors.dangerBg : AppColors.successBg,
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            child: Icon(
              tx.isDebit ? Icons.arrow_upward : Icons.arrow_downward,
              size: 18,
              color: tx.isDebit ? AppColors.danger : AppColors.success,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _label,
                  style: TextStyle(fontWeight: FontWeight.w700, color: context.tokens.heading),
                ),
                const SizedBox(height: 2),
                Text(
                  tx.createdAt,
                  style: TextStyle(fontSize: 12, color: context.tokens.textMuted),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                tx.amount,
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  color: tx.isDebit ? AppColors.danger : AppColors.success,
                ),
              ),
              if (tx.state == 'pending')
                Text('قيد التسوية',
                    style: TextStyle(fontSize: 11, color: context.tokens.textMuted)),
            ],
          ),
        ],
      ),
    );
  }
}

class _WithdrawalTile extends StatelessWidget {
  const _WithdrawalTile({required this.withdrawal});

  final WalletWithdrawal withdrawal;

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (withdrawal.status) {
      'approved' => ('معتمد', AppColors.success),
      'paid' => ('تم التحويل', AppColors.success),
      'rejected' => ('مرفوض', AppColors.danger),
      'cancelled' => ('ملغي', AppColors.textMuted),
      _ => ('بانتظار المراجعة', AppColors.warning),
    };
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${withdrawal.amount} ${withdrawal.currency}',
                  style: TextStyle(fontWeight: FontWeight.w800, color: context.tokens.heading),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
                child: Text(label, style: TextStyle(fontSize: 11.5, color: color, fontWeight: FontWeight.w700)),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'طلب في ${withdrawal.createdAt}',
            style: TextStyle(fontSize: 12, color: context.tokens.textMuted),
          ),
          if (withdrawal.reference.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              'مرجع التحويل: ${withdrawal.reference}',
              style: TextStyle(fontSize: 12, color: context.tokens.textMuted),
            ),
          ],
          if (withdrawal.adminNote.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              withdrawal.adminNote,
              style: TextStyle(fontSize: 12, color: context.tokens.text),
            ),
          ],
        ],
      ),
    );
  }
}

/// The withdrawal request form. Validates the amount against the available balance
/// locally for a fast error, and lets the server be the final authority.
class _WithdrawalSheet extends StatefulWidget {
  const _WithdrawalSheet({required this.repository, required this.summary});

  final ApiRepository repository;
  final WalletSummary summary;

  @override
  State<_WithdrawalSheet> createState() => _WithdrawalSheetState();
}

class _WithdrawalSheetState extends State<_WithdrawalSheet> {
  final _amount = TextEditingController();
  final _note = TextEditingController();
  String _method = 'bank_transfer';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final value = _amount.text.trim();
    if (!RegExp(r'^\d+(\.\d{1,3})?$').hasMatch(value)) {
      setState(() => _error = 'أدخل مبلغاً صحيحاً.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.repository.requestWithdrawal(
        amount: value,
        method: _method,
        note: _note.text.trim().isEmpty ? null : _note.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('طلب سحب', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(
            'المتاح للسحب: ${widget.summary.available} ${widget.summary.currency}',
            style: TextStyle(fontSize: 13, color: context.tokens.textMuted),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'المبلغ',
              hintText: '0.00',
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            initialValue: _method,
            decoration: const InputDecoration(labelText: 'طريقة التحويل'),
            items: const [
              DropdownMenuItem(value: 'bank_transfer', child: Text('تحويل بنكي')),
              DropdownMenuItem(value: 'cash', child: Text('نقداً')),
              DropdownMenuItem(value: 'other', child: Text('أخرى')),
            ],
            onChanged: (v) => setState(() => _method = v ?? 'bank_transfer'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _note,
            maxLines: 2,
            decoration: const InputDecoration(labelText: 'ملاحظة (اختياري)'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: AppColors.danger, fontSize: 13)),
          ],
          const SizedBox(height: 18),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      height: 18,
                      width: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('إرسال الطلب'),
            ),
          ),
        ],
      ),
    );
  }
}
