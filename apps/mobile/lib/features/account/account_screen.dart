import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../core/theme/theme_controller.dart';
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
            gradient: context.tokens.washGradient,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: context.tokens.washBorder),
          ),
          child: Row(
            children: [
              Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(
                  color: context.tokens.surface,
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: Text(
                    user.name.isEmpty ? '؟' : user.name.characters.first,
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: context.tokens.heading,
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
                            color: context.tokens.heading,
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
              color: context.tokens.surface2,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: context.tokens.border),
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
                .map((p) => _Pill(label: p, color: context.tokens.successFg))
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
                    color: context.tokens.surface,
                    border: Border.all(color: context.tokens.border),
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
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: context.tokens.heading,
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
        // The theme chooser reads its state tolerantly, like the app shell does:
        // widget tests mount this screen without a ThemeController.
        _ThemeSettingTile(),
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
            side: BorderSide(color: context.tokens.dangerBorder),
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
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      // Material, not a decorated Container, so the tile's ink splash is
      // visible on the surface it actually paints on.
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
          title: Text(label, style: Theme.of(context).textTheme.bodyMedium),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (value != null)
                Text(value!, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(width: 6),
              Icon(Icons.chevron_left, size: 18, color: context.tokens.textSubtle),
            ],
          ),
        ),
      ),
    );
  }
}

/// The light/dark/system chooser.
///
/// Reads [ThemeController] as a nullable type so this screen is safe to mount
/// directly by widget tests, which do not provide one. A missing provider hides
/// the tile rather than crashing the settings list.
class _ThemeSettingTile extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final controller = context.watch<ThemeController?>();
    if (controller == null) return const SizedBox.shrink();

    final locale = context.watch<LocaleController>();
    final mode = controller.mode;

    return _SettingTile(
      icon: mode.icon,
      label: locale.isRtl ? 'المظهر' : 'Appearance',
      value: locale.isRtl ? mode.labelAr : mode.labelEn,
      onTap: () => _showThemePicker(context, controller, locale.isRtl),
    );
  }

  void _showThemePicker(
    BuildContext context,
    ThemeController controller,
    bool rtl,
  ) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: context.tokens.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xl)),
      ),
      builder: (sheetContext) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 6),
              child: Align(
                alignment: rtl ? Alignment.centerRight : Alignment.centerLeft,
                child: Text(
                  rtl ? 'المظهر' : 'Appearance',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 15,
                    color: sheetContext.tokens.heading,
                  ),
                ),
              ),
            ),
            for (final option in AppThemeMode.values)
              ListTile(
                leading: Icon(option.icon,
                    size: 20, color: sheetContext.tokens.textMuted),
                title: Text(
                  rtl ? option.labelAr : option.labelEn,
                  style: Theme.of(sheetContext).textTheme.bodyMedium,
                ),
                trailing: option == controller.mode
                    ? Icon(Icons.check, size: 20, color: AppColors.teal)
                    : null,
                onTap: () {
                  controller.setMode(option);
                  Navigator.of(sheetContext).pop();
                },
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}
