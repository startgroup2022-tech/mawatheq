import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../home/home_shell.dart';

/// Delete my account — Google Play requires this to be reachable in-app
/// (My Account → Settings → Delete my account) and to be a real, working route.
///
/// The screen does three things and nothing else:
///   1. explains exactly what is erased and what is retained, and why;
///   2. performs the deletion, which the server gates on the account's own password —
///      holding a session/token is deliberately not enough to destroy an account;
///   3. shows the status of a deletion request the user may have started on the public
///      website, so the in-app view and the web view cannot disagree.
///
/// A refusal that names blockers (an open case, an upcoming consultation) is shown
/// verbatim: "delete failed" with no reason is what makes a customer contact support.
class DeleteAccountScreen extends StatefulWidget {
  const DeleteAccountScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends State<DeleteAccountScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  DeletionRequestStatus? _request;
  bool _loadingStatus = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadStatus();
  }

  Future<void> _loadStatus() async {
    setState(() => _loadingStatus = true);
    try {
      final status = await _repo.deletionRequestStatus();
      if (!mounted) return;
      setState(() {
        _request = status;
        _loadingStatus = false;
      });
    } catch (_) {
      if (!mounted) return;
      // A status lookup failure must never block the deletion itself.
      setState(() => _loadingStatus = false);
    }
  }

  Future<void> _deleteNow(bool rtl) async {
    final password = await _askPassword(rtl);
    if (password == null || password.isEmpty || !mounted) return;

    final confirmed = await ConfirmationDialog.show(
      context,
      title: rtl ? 'حذف الحساب' : 'Delete account',
      message: rtl
          ? 'سيتم إلغاء تفعيل حسابك وإزالة بياناتك الشخصية وإبطال جميع أجهزة الدخول. '
              'لا يمكن التراجع عن هذا الإجراء.'
          : 'Your account will be deactivated, your personal data erased, and every '
              'signed-in device revoked. This cannot be undone.',
      confirmLabel: rtl ? 'حذف نهائي' : 'Delete permanently',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    setState(() => _busy = true);
    try {
      await _repo.deleteAccount(password: password);
      if (!mounted) return;
      // The account no longer exists: end the session and clear the token so the
      // device cannot keep using a credential the server has already revoked.
      await context.read<AuthController>().signOut();
      if (!mounted) return;
      context.read<AuthController>().enterGuest();
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const HomeShell()),
        (route) => false,
      );
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(rtl ? 'تم حذف الحساب.' : 'Your account was deleted.'),
        ),
      );
    } catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      final message = error is ApiException ? error.message : error.toString();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message)),
      );
    }
  }

  /// A single-field password prompt. Returns null when dismissed.
  Future<String?> _askPassword(bool rtl) {
    final controller = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(rtl ? 'تأكيد كلمة المرور' : 'Confirm your password'),
        content: TextField(
          controller: controller,
          obscureText: true,
          autofocus: true,
          decoration: InputDecoration(
            labelText: rtl ? 'كلمة المرور' : 'Password',
            hintText: rtl
                ? 'أدخل كلمة المرور لحذف الحساب'
                : 'Enter your password to delete the account',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: Text(rtl ? 'إلغاء' : 'Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(controller.text),
            child: Text(rtl ? 'متابعة' : 'Continue'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      appBar: AppBar(
        title: Text(rtl ? 'حذف حسابي' : 'Delete my account'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _introCard(context, rtl),
          const SizedBox(height: 16),
          _whatHappensCard(context, rtl),
          const SizedBox(height: 16),
          _statusCard(context, rtl),
          const SizedBox(height: 16),
          _deleteButton(context, rtl),
          const SizedBox(height: 12),
          Text(
            rtl
                ? 'بديل: يمكنك أيضاً تقديم طلب حذف من الموقع دون تسجيل الدخول عبر '
                    'mawatheq.com/account-deletion، وسيصلك رابط تأكيد على بريدك المسجّل.'
                : 'Alternative: you can also submit a deletion request on the website '
                    'without signing in at mawatheq.com/account-deletion; a confirmation '
                    'link is emailed to your registered address.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  Widget _introCard(BuildContext context, bool rtl) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.tokens.surface2,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, color: AppColors.danger),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              rtl
                  ? 'حذف الحساب إجراء نهائي. راجع ما سيحدث قبل المتابعة.'
                  : 'Deleting your account is permanent. Review what happens before you continue.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }

  Widget _whatHappensCard(BuildContext context, bool rtl) {
    const deletesAr = [
      'الاسم والبريد الإلكتروني ورقم الجوال',
      'كلمة المرور ومفاتيح الدخول',
      'جلسات الدخول وكل رموز الأجهزة',
      'الإشعارات والتفضيلات الشخصية',
    ];
    const deletesEn = [
      'Name, email address and phone number',
      'Password and sign-in credentials',
      'Sessions and every device token',
      'Notifications and personal preferences',
    ];
    const keepsAr = [
      'الفواتير والسجلات المالية — التزام مالي وضريبي (حتى ١٠ سنوات)',
      'الاستشارات والقضايا المكتملة التي يعتمد عليها طرف آخر — التزام مهني',
      'سجل الطلب نفسه — إثبات تنفيذ الحذف',
    ];
    const keepsEn = [
      'Invoices and financial records — accounting and tax obligation (up to 10 years)',
      'Completed consultations and cases another party relies on — professional duty',
      'The request record itself — proof the deletion was carried out',
    ];

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            rtl ? 'يُحذف نهائياً' : 'Erased permanently',
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: AppColors.danger,
            ),
          ),
          const SizedBox(height: 4),
          ...(rtl ? deletesAr : deletesEn).map((d) => _bullet(context, d)),
          const SizedBox(height: 12),
          Text(
            rtl ? 'يُحفظ لأسباب ملزمة' : 'Retained for binding reasons',
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: context.tokens.heading,
            ),
          ),
          const SizedBox(height: 4),
          ...(rtl ? keepsAr : keepsEn).map((k) => _bullet(context, k)),
          const SizedBox(height: 10),
          Text(
            rtl
                ? 'تُحفظ السجلات المطلوبة بدون بياناتك الشخصية.'
                : 'Retained records are stored without your personal data.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  Widget _statusCard(BuildContext context, bool rtl) {
    if (_loadingStatus) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 8),
        child: LoadingSkeleton(count: 1, height: 64),
      );
    }
    final request = _request;
    if (request == null) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.teal),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            rtl ? 'لديك طلب حذف قائم' : 'You have an open deletion request',
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: context.tokens.heading,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            '${rtl ? 'رقم الطلب' : 'Reference'}: ${request.reference}',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          Text(
            '${rtl ? 'الحالة' : 'Status'}: ${request.label(rtl)}',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          Text(
            '${rtl ? 'التحقق' : 'Verification'}: '
            '${request.verified ? (rtl ? 'مؤكَّد' : 'Verified') : (rtl ? 'غير مؤكَّد' : 'Unverified')}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  Widget _deleteButton(BuildContext context, bool rtl) {
    return SizedBox(
      width: double.infinity,
      child: FilledButton.icon(
        onPressed: _busy ? null : () => _deleteNow(rtl),
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.danger,
          minimumSize: const Size.fromHeight(48),
        ),
        icon: _busy
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
              )
            : const Icon(Icons.delete_forever_outlined),
        label: Text(
          _busy
              ? (rtl ? 'جارٍ الحذف…' : 'Deleting…')
              : (rtl ? 'طلب حذف حسابي' : 'Request account deletion'),
        ),
      ),
    );
  }

  Widget _bullet(BuildContext context, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 6, left: 2, right: 6),
              child: Container(
                width: 5,
                height: 5,
                decoration: BoxDecoration(
                  color: context.tokens.textMuted,
                  shape: BoxShape.circle,
                ),
              ),
            ),
            Expanded(
              child: Text(text, style: Theme.of(context).textTheme.bodySmall),
            ),
          ],
        ),
      );
}
