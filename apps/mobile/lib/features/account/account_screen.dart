import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/config/app_config.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/public_content.dart';
import '../../core/security/biometric_service.dart';
import '../../core/security/rate_app_service.dart';
import '../../core/storage/token_storage.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../core/theme/theme_controller.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../alerts/availability_alerts_screen.dart';
import '../favorites/favorites_screen.dart';
import '../home/home_shell.dart';
import '../notifications_settings/notification_settings_screen.dart';
import '../public/pricing_screen.dart';
import '../reviews/reviews_screen.dart';
import 'invoice_detail_screen.dart';
import 'privacy_screen.dart';
import 'support_center_screen.dart';
import 'transactions_screen.dart';

/// Profile, permissions, invoices and session controls.
///
/// The permission list is rendered exactly as the server reported it, so the
/// screen never claims a capability the account does not have.
class AccountScreen extends StatefulWidget {
  const AccountScreen({
    super.key,
    this.repository,
    this.onOpenProfessionalProfile,
    this.biometrics,
    this.rateApp,
  });

  /// The shared repository, so an embedding can inject a test edge. Falls back to the
  /// default client, which is what the customer shell relies on.
  final ApiRepository? repository;

  /// Shown only when supplied. The lawyer shell passes one, because a lawyer has a
  /// professional profile the customer does not; the customer shell passes nothing
  /// and the tile is absent.
  final VoidCallback? onOpenProfessionalProfile;

  /// Device biometric capability. Injectable so a widget test can answer "the tile
  /// is hidden when the device cannot do biometrics" without a device. The default
  /// is the real `local_auth` service, which is what production uses.
  final BiometricService? biometrics;

  /// In-app store rating. Injectable for the same reason as [biometrics].
  final RateAppService? rateApp;

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  late final BiometricService _biometrics =
      widget.biometrics ?? LocalAuthBiometricService();
  late final RateAppService _rateApp = widget.rateApp ?? InAppReviewService();
  late final TokenStore _tokens = TokenStorage();

  List<Invoice> _invoices = const [];
  bool _loadingInvoices = true;

  /// Whether this device can offer biometric sign-in, and whether it is enabled.
  /// The tile is hidden entirely when the device cannot, rather than shown disabled,
  /// so a customer without the hardware never sees a feature they cannot use.
  bool _biometricAvailable = false;
  bool _biometricEnabled = false;

  @override
  void initState() {
    super.initState();
    _loadInvoices();
    _loadBiometricState();
  }

  Future<void> _loadBiometricState() async {
    final available = await _biometrics.isAvailable();
    final enabled = await _tokens.readBiometricEnabled();
    if (!mounted) return;
    setState(() {
      _biometricAvailable = available;
      // A stale "enabled" flag with no token would offer an unlock that cannot
      // succeed; treat that as disabled rather than showing a broken switch.
      _biometricEnabled = available && enabled;
    });
  }

  /// Enabling asks for a biometric first, so the customer proves the factor works
  /// before it becomes the thing that unlocks the app. Disabling needs no proof.
  Future<void> _toggleBiometric(bool value) async {
    final rtl = context.read<LocaleController>().isRtl;
    if (value) {
      final ok = await _biometrics.authenticate(
        reason: rtl ? 'تأكيد تفعيل الدخول بالبصمة' : 'Confirm enabling biometric sign-in',
      );
      if (!ok || !mounted) return;
    }
    await _tokens.saveBiometricEnabled(value);
    if (!mounted) return;
    setState(() => _biometricEnabled = value);
  }

  Future<void> _openRateApp() async {
    final rtl = context.read<LocaleController>().isRtl;
    final available = await _rateApp.isAvailable();
    if (!available) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(rtl
              ? 'تقييم التطبيق غير متاح على هذا الجهاز.'
              : 'In-app rating is not available on this device.'),
        ),
      );
      return;
    }
    await _rateApp.requestReview();
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

  /// Google Play requires an in-app route to delete an account.
  ///
  /// The password is collected here and verified by the server; a token alone must
  /// never be enough to destroy an account. A refusal that names blockers (an open
  /// case, an upcoming consultation) is shown verbatim, because "delete failed" with
  /// no reason is what makes a customer contact support instead.
  Future<void> _deleteAccount() async {
    final password = await _askPassword();
    if (password == null || password.isEmpty || !mounted) return;

    final confirmed = await ConfirmationDialog.show(
      context,
      title: 'حذف الحساب',
      message:
          'سيتم إلغاء تفعيل حسابك وإزالة بياناتك الشخصية وإبطال جميع أجهزة الدخول. '
          'لا يمكن التراجع عن هذا الإجراء.',
      confirmLabel: 'حذف نهائي',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    try {
      await _repo.deleteAccount(password: password);
      if (!mounted) return;
      await context.read<AuthController>().signOut();
      if (!mounted) return;
      context.read<AuthController>().enterGuest();
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const HomeShell()),
        (route) => false,
      );
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم حذف الحساب.')),
      );
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.toString())),
      );
    }
  }

  /// A single-field password prompt. Returns null when dismissed.
  Future<String?> _askPassword() {
    final controller = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تأكيد كلمة المرور'),
        content: TextField(
          controller: controller,
          obscureText: true,
          autofocus: true,
          decoration: const InputDecoration(
            labelText: 'كلمة المرور',
            hintText: 'أدخل كلمة المرور لحذف الحساب',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('إلغاء'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(controller.text),
            child: const Text('متابعة'),
          ),
        ],
      ),
    );
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
                child: Material(
                  color: context.tokens.surface,
                  shape: RoundedRectangleBorder(
                    side: BorderSide(color: context.tokens.border),
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => InvoiceDetailScreen(
                          invoiceId: inv.id,
                          repository: widget.repository,
                        ),
                      ),
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
                  ),
                ),
              )),
        const SizedBox(height: 22),

        // Client-only engagement destinations. A lawyer has no favorites, no
        // availability alerts and no consultations to rate, so the whole block is
        // absent for them rather than shown empty.
        if (!user.isLawyer) ...[
          SectionHeader(title: 'نشاطي'),
          _SettingTile(
            icon: Icons.favorite_border,
            label: 'المحامون المفضّلون',
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => FavoritesScreen(repository: widget.repository),
              ),
            ),
          ),
          _SettingTile(
            icon: Icons.notifications_active_outlined,
            label: 'تنبيهات توفّر المواعيد',
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => AvailabilityAlertsScreen(repository: widget.repository),
              ),
            ),
          ),
          _SettingTile(
            icon: Icons.star_outline,
            label: 'قيّم استشاراتك',
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => ReviewsScreen(repository: widget.repository),
              ),
            ),
          ),
          const SizedBox(height: 22),
        ],

        SectionHeader(title: 'الإعدادات'),
        // Lawyer-only: the professional profile is what clients see, so it sits at the
        // top of the settings block when the shell supplies the route.
        if (widget.onOpenProfessionalProfile != null)
          _SettingTile(
            icon: Icons.badge_outlined,
            label: 'الملف المهني',
            onTap: widget.onOpenProfessionalProfile!,
          ),
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
          icon: Icons.receipt_long_outlined,
          label: 'سجل العمليات',
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => TransactionsScreen(repository: widget.repository),
            ),
          ),
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
          value: PublicContent.supportEmail,
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => SupportCenterScreen(repository: widget.repository),
            ),
          ),
        ),
        // Opt-in biometric sign-in. Hidden entirely on a device without the
        // hardware or without an enrolled biometric, so the customer never sees a
        // switch that cannot work. Placed next to the other security controls.
        if (_biometricAvailable)
          _SettingTile(
            icon: Icons.fingerprint,
            label: 'الدخول بالبصمة',
            value: _biometricEnabled ? 'مفعّل' : 'معطّل',
            onTap: () => _toggleBiometric(!_biometricEnabled),
          ),
        _SettingTile(
          icon: Icons.shield_outlined,
          label: 'الخصوصية والموافقات',
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => PrivacyScreen(repository: widget.repository),
            ),
          ),
        ),
        _SettingTile(
          icon: Icons.notifications_none,
          label: 'إعدادات الإشعارات',
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => NotificationSettingsScreen(repository: widget.repository),
            ),
          ),
        ),
        _SettingTile(
          icon: Icons.star_outline,
          label: 'قيّم التطبيق',
          onTap: _openRateApp,
        ),
        // Google Play requires an in-app account-deletion route; privacy.php on the
        // website promises one. It is the last tile, away from the everyday actions.
        _SettingTile(
          icon: Icons.delete_outline,
          label: 'حذف الحساب',
          onTap: _deleteAccount,
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
            // Signing out returns to the product as a guest, not to the sign-in
            // form. The shell renders the same home for a guest, so the customer
            // keeps their place instead of being dropped on a wall.
            context.read<AuthController>().enterGuest();
            Navigator.of(context).pushAndRemoveUntil(
              MaterialPageRoute(builder: (_) => const HomeShell()),
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
            'مواثيق · الإصدار ${AppConfig.appVersion}',
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
