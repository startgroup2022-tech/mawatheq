import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// The lawyer's account settings: notifications, privacy, password and sessions.
///
/// Account deletion is deliberately here rather than in the profile: it is the one
/// irreversible action, and it sits behind a password re-entry and the server's own
/// open-work check. The website's privacy page promises this route exists.
class LawyerSettingsScreen extends StatefulWidget {
  const LawyerSettingsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LawyerSettingsScreen> createState() => _LawyerSettingsScreenState();
}

class _LawyerSettingsScreenState extends State<LawyerSettingsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  LawyerSettings? _settings;
  bool _loading = true;
  String? _error;
  bool _saving = false;

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
      final s = await _repo.lawyerSettings();
      if (!mounted) return;
      setState(() {
        _settings = s;
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

  Future<void> _persist({bool? inApp, bool? email, bool? profileVisible}) async {
    final s = _settings;
    if (s == null || _saving) return;
    final nextInApp = inApp ?? s.notifyInApp;
    final nextEmail = email ?? s.notifyEmail;
    final nextVisible = profileVisible ?? s.profileVisible;
    // Optimistic: the switch reflects the tap immediately, then reverts if the server
    // refuses. A toggle that lags behind the finger reads as broken.
    setState(() {
      _saving = true;
      _settings = LawyerSettings(
        notifyInApp: nextInApp,
        notifyEmail: nextEmail,
        profileVisible: nextVisible,
        activeSessions: s.activeSessions,
      );
    });
    try {
      await _repo.saveLawyerSettings(
        inApp: nextInApp,
        email: nextEmail,
        profileVisible: profileVisible == null ? null : nextVisible,
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _settings = s);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _changePassword() async {
    final result = await showDialog<bool>(
      context: context,
      builder: (_) => _PasswordDialog(repository: _repo),
    );
    if (result == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم تغيير كلمة المرور. ستحتاج لتسجيل الدخول مجدداً على الأجهزة الأخرى.')),
      );
    }
  }

  Future<void> _signOutAll() async {
    final confirmed = await ConfirmationDialog.show(
      context,
      title: 'إنهاء جميع الجلسات',
      message: 'سيتم إلغاء كل رموز الدخول على جميع الأجهزة، بما في ذلك هذا الجهاز.',
      confirmLabel: 'إنهاء الكل',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    try {
      await _repo.signOutAllDevices();
      if (!mounted) return;
      await context.read<AuthController>().signOut();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _deleteAccount() async {
    final password = await _askPassword();
    if (password == null || password.isEmpty || !mounted) return;
    final confirmed = await ConfirmationDialog.show(
      context,
      title: 'حذف الحساب',
      message:
          'سيتم تعطيل حسابك وإخفاء بياناتك الشخصية. قد تُحفظ السجلات المالية والقانونية وفقاً للأنظمة المعمول بها، ولن تتمكن من الدخول مرة أخرى.',
      confirmLabel: 'حذف الحساب نهائياً',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    try {
      await _repo.deleteAccount(password: password);
      if (!mounted) return;
      await context.read<AuthController>().signOut();
    } on ApiException catch (e) {
      if (!mounted) return;
      // The server reports `has_open_work` when cases or consultations are still open;
      // its message names the blockers, so show it rather than a generic failure.
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

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
          decoration: const InputDecoration(labelText: 'كلمة المرور الحالية'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(controller.text),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(subtitle: 'الإعدادات'),
      body: _loading
          ? const Padding(padding: EdgeInsets.all(16), child: LoadingSkeleton(count: 5))
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _body(context, _settings!),
    );
  }

  Widget _body(BuildContext context, LawyerSettings s) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      children: [
        const SectionHeader(title: 'تفضيلات الإشعارات'),
        SwitchListTile(
          value: s.notifyInApp,
          onChanged: _saving ? null : (v) => _persist(inApp: v),
          title: const Text('إشعارات داخل التطبيق'),
          subtitle: const Text('تنبيهات الطلبات والرسائل داخل التطبيق'),
        ),
        SwitchListTile(
          value: s.notifyEmail,
          onChanged: _saving ? null : (v) => _persist(email: v),
          title: const Text('إشعارات البريد الإلكتروني'),
          subtitle: const Text('ملخصات وتنبيهات على بريدك'),
        ),
        const SizedBox(height: 18),
        const SectionHeader(title: 'الخصوصية'),
        SwitchListTile(
          value: s.profileVisible,
          onChanged: _saving ? null : (v) => _persist(profileVisible: v),
          title: const Text('إظهار الملف للعملاء'),
          subtitle: const Text('عند الإيقاف لن يظهر ملفك في دليل المحامين'),
        ),
        const SizedBox(height: 18),
        const SectionHeader(title: 'الأمان'),
        _Tile(
          icon: Icons.lock_outline,
          label: 'تغيير كلمة المرور',
          onTap: _changePassword,
        ),
        _Tile(
          icon: Icons.devices_outlined,
          label: 'الجلسات النشطة',
          value: '${s.activeSessions}',
          onTap: _signOutAll,
        ),
        _Tile(
          icon: Icons.logout,
          label: 'إنهاء جميع الجلسات',
          onTap: _signOutAll,
        ),
        const SizedBox(height: 18),
        const SectionHeader(title: 'الحساب'),
        _Tile(
          icon: Icons.delete_outline,
          label: 'حذف الحساب',
          danger: true,
          onTap: _deleteAccount,
        ),
      ],
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.label,
    this.value,
    this.onTap,
    this.danger = false,
  });

  final IconData icon;
  final String label;
  final String? value;
  final VoidCallback? onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.border),
      ),
      child: ListTile(
        // 48px minimum height keeps the row a legal touch target.
        minVerticalPadding: 12,
        leading: Icon(icon, color: danger ? AppColors.danger : context.tokens.textMuted),
        title: Text(
          label,
          style: TextStyle(
            color: danger ? AppColors.danger : context.tokens.text,
            fontWeight: FontWeight.w600,
          ),
        ),
        trailing: value != null
            ? Text(value!, style: TextStyle(color: context.tokens.textMuted))
            : const Icon(Icons.chevron_left, size: 20),
        onTap: onTap,
      ),
    );
  }
}

/// Change the password. The server verifies the current one, so the dialog only
/// collects and pre-checks length.
class _PasswordDialog extends StatefulWidget {
  const _PasswordDialog({required this.repository});

  final ApiRepository repository;

  @override
  State<_PasswordDialog> createState() => _PasswordDialogState();
}

class _PasswordDialogState extends State<_PasswordDialog> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_next.text.length < 8) {
      setState(() => _error = 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.repository.changeLawyerPassword(
        currentPassword: _current.text,
        newPassword: _next.text,
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
    return AlertDialog(
      title: const Text('تغيير كلمة المرور'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            controller: _current,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'كلمة المرور الحالية'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _next,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'كلمة المرور الجديدة'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: AppColors.danger, fontSize: 13)),
          ],
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: const Text('حفظ'),
        ),
      ],
    );
  }
}
