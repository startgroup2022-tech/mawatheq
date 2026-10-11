import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../auth/login_screen.dart';
import '../public/pricing_screen.dart';

/// Profile, permissions, invoices and session controls.
///
/// The permission list is rendered exactly as the server reported it, so the
/// screen never claims a capability the account does not have.
class AccountScreen extends StatefulWidget {
  const AccountScreen({super.key});

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  final _repo = ApiRepository();
  List<Invoice> _invoices = const [];
  bool _loadingInvoices = true;

  @override
  void initState() {
    super.initState();
    _loadInvoices();
  }

  Future<void> _loadInvoices() async {
    try {
      final invoices = await _repo.invoices();
      if (!mounted) return;
      setState(() {
        _invoices = invoices;
        _loadingInvoices = false;
      });
    } catch (_) {
      // Invoices are optional on this screen; a failure here must not blank it.
      if (mounted) setState(() => _loadingInvoices = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();
    final locale = context.watch<LocaleController>();
    final user = auth.user;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            gradient: AppColors.lightSurface,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: const Color(0xFFCFE7F2)),
          ),
          child: Row(
            children: [
              Container(
                width: 56,
                height: 56,
                decoration: const BoxDecoration(
                  color: AppColors.surface,
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: Text(
                    user.name.isEmpty ? '؟' : user.name.characters.first,
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: AppColors.navy,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      user.name.isEmpty ? 'مستخدم' : user.name,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      user.email,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        _Pill(
                          label: user.isLawyer ? 'محامٍ' : 'عميل',
                          color: AppColors.teal,
                        ),
                        if (user.isFirmMember) ...[
                          const SizedBox(width: 6),
                          _Pill(
                            label: user.role.isEmpty ? 'عضو مكتب' : user.role,
                            color: AppColors.navy,
                          ),
                        ],
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 22),

        SectionHeader(title: 'الصلاحيات'),
        if (user.permissions.isEmpty)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.surface2,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: AppColors.border),
            ),
            child: Text(
              'لم يُرجِع الخادم قائمة صلاحيات لهذا الحساب.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          )
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: user.permissions
                .map((p) => _Pill(label: p, color: AppColors.success))
                .toList(),
          ),
        const SizedBox(height: 22),

        SectionHeader(title: 'الفواتير'),
        if (_loadingInvoices)
          const LoadingSkeleton(count: 2, height: 58)
        else if (_invoices.isEmpty)
          Text('لا توجد فواتير.',
              style: Theme.of(context).textTheme.bodySmall)
        else
          ..._invoices.take(5).map((inv) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Container(
                  padding: const EdgeInsets.all(13),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    border: Border.all(color: AppColors.border),
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              inv.number.isEmpty
                                  ? 'فاتورة #${inv.id}'
                                  : inv.number,
                              style: Theme.of(context).textTheme.bodyMedium,
                            ),
                            if (inv.issuedAt.isNotEmpty)
                              Text(inv.issuedAt,
                                  style: Theme.of(context).textTheme.bodySmall),
                          ],
                        ),
                      ),
                      Text(
                        '${inv.total.toStringAsFixed(0)} ${inv.currency}',
                        style: const TextStyle(
                          fontWeight: FontWeight.w700,
                          color: AppColors.navy,
                        ),
                      ),
                      const SizedBox(width: 8),
                      StatusBadge(status: inv.status),
                    ],
                  ),
                ),
              )),
        const SizedBox(height: 22),

        SectionHeader(title: 'الإعدادات'),
        _SettingTile(
          icon: Icons.language,
          label: 'اللغة',
          value: locale.isRtl ? 'العربية' : 'English',
          onTap: () => context.read<LocaleController>().toggle(),
        ),
        _SettingTile(
          icon: Icons.workspace_premium_outlined,
          label: 'الخطط والأسعار',
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const PricingScreen()),
          ),
        ),
        _SettingTile(
          icon: Icons.help_outline,
          label: 'الدعم',
          value: 'info@mawatheq.com',
          onTap: () {},
        ),
        const SizedBox(height: 22),

        OutlinedButton.icon(
          onPressed: () async {
            final confirmed = await ConfirmationDialog.show(
              context,
              title: 'تسجيل الخروج',
              message:
                  'سيتم إلغاء رمز الدخول على هذا الجهاز. ستحتاج لتسجيل الدخول مجدداً.',
              confirmLabel: 'تسجيل الخروج',
              destructive: true,
            );
            if (!confirmed || !context.mounted) return;

            await context.read<AuthController>().signOut();
            if (!context.mounted) return;
            Navigator.of(context).pushAndRemoveUntil(
              MaterialPageRoute(builder: (_) => const LoginScreen()),
              (route) => false,
            );
          },
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.danger,
            side: const BorderSide(color: Color(0xFFF2CFC4)),
            minimumSize: const Size(0, 48),
          ),
          icon: const Icon(Icons.logout, size: 18),
          label: const Text('تسجيل الخروج'),
        ),
        const SizedBox(height: 12),
        Center(
          child: Text(
            'مواثيق · الإصدار 1.0.0',
            style: Theme.of(context).textTheme.labelSmall,
          ),
        ),
      ],
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
          color: color,
        ),
      ),
    );
  }
}

class _SettingTile extends StatelessWidget {
  const _SettingTile({
    required this.icon,
    required this.label,
    this.value,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String? value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: ListTile(
        onTap: onTap,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        leading: Icon(icon, size: 20, color: AppColors.textMuted),
        title: Text(label, style: Theme.of(context).textTheme.bodyMedium),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (value != null)
              Text(value!, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(width: 6),
            const Icon(Icons.chevron_left, size: 18, color: AppColors.textSubtle),
          ],
        ),
      ),
    );
  }
}
