import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Requests a password reset link.
///
/// The app does not implement "set a new password" here: the emailed link lands on
/// the website's reset page, which the app can also open natively when it receives
/// the same link as an App Link (see `ResetPasswordScreen`). This screen only asks
/// the server to send that link, and reports the delivery the server confirmed.
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({
    super.key,
    required this.repository,
    this.asLawyer = false,
  });

  final ApiRepository repository;
  final bool asLawyer;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _email = TextEditingController();
  bool _busy = false;
  bool _sent = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final email = _email.text.trim();
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) {
      setState(() => _error = 'أدخل بريداً إلكترونياً صحيحاً.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.repository.requestPasswordReset(
        email: email,
        accountType: widget.asLawyer ? 'lawyer' : 'client',
      );
      if (mounted) setState(() => _sent = true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: rtl ? 'استعادة كلمة المرور' : 'Password recovery',
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          if (_sent)
            _Notice(
              icon: Icons.mark_email_read_outlined,
              text: rtl
                  ? 'إذا كان البريد مسجّلاً لدينا، ستصلك رسالة تحتوي رابط إعادة التعيين خلال دقائق. الرابط صالح لمدة ساعة واحدة. تفقّد مجلد الرسائل غير المرغوبة إن لم تصل.'
                  : 'If that email is registered, a reset link will arrive shortly. It is valid for 1 hour. Check your spam folder if it does not.',
            )
          else ...[
            Text(
              rtl
                  ? 'أدخل بريد حسابك وسنرسل لك رابطاً لإعادة تعيين كلمة المرور.'
                  : 'Enter your account email and we will send you a reset link.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 18),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              textDirection: TextDirection.ltr,
              decoration: InputDecoration(
                labelText: rtl ? 'البريد الإلكتروني' : 'Email',
                prefixIcon: const Icon(Icons.mail_outline),
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              _Notice(icon: Icons.error_outline, text: _error!, error: true),
            ],
            const SizedBox(height: 18),
            PrimaryButton(
              label: rtl ? 'إرسال رابط إعادة التعيين' : 'Send reset link',
              icon: Icons.send_outlined,
              expand: true,
              loading: _busy,
              onPressed: _submit,
            ),
          ],
        ],
      ),
    );
  }
}

/// Sets a new password using a reset token, either pasted by the user or delivered
/// by a reset App Link. Talks to `POST /api/v1/auth/reset-password.php`, so it
/// shares the exact token store, TTL and token-revocation behaviour as the website.
class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({
    super.key,
    required this.repository,
    this.initialToken = '',
    this.asLawyer = false,
  });

  final ApiRepository repository;
  final String initialToken;
  final bool asLawyer;

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  late final TextEditingController _token =
      TextEditingController(text: widget.initialToken);
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  bool _done = false;
  String? _error;

  @override
  void dispose() {
    _token.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_token.text.trim().isEmpty) {
      setState(() => _error = 'أدخل رمز إعادة التعيين من الرسالة.');
      return;
    }
    if (_password.text.length < 8) {
      setState(() => _error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.');
      return;
    }
    if (_password.text != _confirm.text) {
      setState(() => _error = 'كلمتا المرور غير متطابقتين.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.repository.resetPassword(
        token: _token.text.trim(),
        password: _password.text,
        passwordConfirm: _confirm.text,
        accountType: widget.asLawyer ? 'lawyer' : 'client',
      );
      if (mounted) setState(() => _done = true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = _friendly(e.message));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _friendly(String code) {
    switch (code) {
      case 'invalid_token':
        return 'رابط إعادة التعيين غير صالح أو منتهي الصلاحية. اطلب رابطاً جديداً.';
      case 'weak_password':
        return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
      case 'too_long':
        return 'كلمة المرور يجب ألا تتجاوز 72 بايتاً.';
      case 'mismatch':
        return 'كلمتا المرور غير متطابقتين.';
      default:
        return code;
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: rtl ? 'تعيين كلمة مرور جديدة' : 'Set a new password',
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          if (_done) ...[
            _Notice(
              icon: Icons.check_circle_outline,
              text: rtl
                  ? 'تم تغيير كلمة المرور بنجاح. يمكنك تسجيل الدخول الآن.'
                  : 'Your password was changed successfully. You can sign in now.',
            ),
            const SizedBox(height: 18),
            SecondaryButton(
              label: rtl ? 'العودة لتسجيل الدخول' : 'Back to sign in',
              icon: Icons.login,
              expand: true,
              onPressed: () => Navigator.of(context).pop(),
            ),
          ] else ...[
            TextField(
              controller: _token,
              textDirection: TextDirection.ltr,
              decoration: InputDecoration(
                labelText: rtl ? 'رمز إعادة التعيين' : 'Reset token',
                helperText: rtl
                    ? 'يُملأ تلقائياً عند فتح رابط الرسالة.'
                    : 'Filled automatically when the email link opens.',
                prefixIcon: const Icon(Icons.vpn_key_outlined),
              ),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _password,
              obscureText: true,
              decoration: InputDecoration(
                labelText: rtl ? 'كلمة المرور الجديدة' : 'New password',
                prefixIcon: const Icon(Icons.lock_outline),
              ),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _confirm,
              obscureText: true,
              decoration: InputDecoration(
                labelText: rtl ? 'تأكيد كلمة المرور' : 'Confirm password',
                prefixIcon: const Icon(Icons.lock_outline),
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              _Notice(icon: Icons.error_outline, text: _error!, error: true),
            ],
            const SizedBox(height: 18),
            PrimaryButton(
              label: rtl ? 'تغيير كلمة المرور' : 'Change password',
              icon: Icons.lock_reset,
              expand: true,
              loading: _busy,
              onPressed: _submit,
            ),
          ],
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.icon, required this.text, this.error = false});

  final IconData icon;
  final String text;
  final bool error;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: error ? context.tokens.dangerBg : context.tokens.infoBg,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: error ? context.tokens.dangerFg : context.tokens.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon,
              size: 18,
              color: error ? context.tokens.dangerFg : AppColors.teal),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      height: 1.6,
                      color: error ? AppColors.danger : context.tokens.heading,
                    )),
          ),
        ],
      ),
    );
  }
}
