import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/guest_session.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Client self-registration.
///
/// The backend requires email verification before a token is issued, so success
/// normally means "check your inbox", not "signed in". The screen says exactly
/// that instead of pretending the account is ready.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();

  bool _busy = false;
  bool _done = false;
  String _accountType = 'individual';

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _busy = true);

    final auth = context.read<AuthController>();
    final ok = await auth.register(
      fullName: _name.text.trim(),
      email: _email.text.trim(),
      password: _password.text,
      accountType: _accountType,
      phone: _phone.text.trim(),
    );

    if (!mounted) return;
    setState(() {
      _busy = false;
      _done = ok;
    });

    if (!ok && auth.error != null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(auth.error!)));
      return;
    }

    // Most deployments verify the email before issuing a token, so `ok` usually
    // means "check your inbox" and the success screen below is shown. When the
    // backend *does* sign the account in immediately, go straight Home — the same
    // landing a sign-in gets — rather than leaving the customer on a form.
    if (ok && auth.isSignedIn) _goHome();
  }

  /// Registration lands on Home when it signs the account in, exactly like sign-in.
  void _goHome() {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(
        builder: (_) => ProtectedDestination.dashboard.buildShell(),
      ),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_done) {
      // The server reports whether the verification email was actually handed off.
      // Never promise "we sent it" when delivery failed; point to the resend page
      // instead. A null (older backend) keeps the original positive wording.
      final emailSent = context
          .watch<AuthController>()
          .lastRegistrationEmailSent;
      final delivered = emailSent ?? true;
      return Scaffold(
        appBar: const BrandAppBar(),
        body: EmptyState(
          icon: delivered
              ? Icons.mark_email_unread_outlined
              : Icons.error_outline,
          title: 'تم إنشاء الحساب',
          message: delivered
              ? 'أرسلنا رابط تأكيد إلى بريدك الإلكتروني. أكّد البريد ثم سجّل الدخول.'
              : 'تم إنشاء الحساب، لكن تعذّر إرسال رسالة التأكيد الآن. من صفحة "إعادة إرسال التفعيل" على الموقع يمكنك طلب رابط جديد.',
          actionLabel: 'رجوع لتسجيل الدخول',
          onAction: () => Navigator.of(context).pop(),
        ),
      );
    }

    return Scaffold(
      appBar: const BrandAppBar(),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'إنشاء حساب عميل',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 6),
                Text(
                  'للأفراد والشركات. أما المحامون فينضمّون من صفحة الانضمام للمحامين على الموقع.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 22),
                TextFormField(
                  controller: _name,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'الاسم الكامل',
                    prefixIcon: Icon(Icons.person_outline, size: 20),
                  ),
                  validator: (v) => (v == null || v.trim().length < 3)
                      ? 'أدخل الاسم الكامل'
                      : null,
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'البريد الإلكتروني',
                    prefixIcon: Icon(Icons.mail_outline, size: 20),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().isEmpty) {
                      return 'أدخل البريد الإلكتروني';
                    }
                    if (!v.contains('@')) return 'صيغة البريد غير صحيحة';
                    return null;
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'رقم الجوال (اختياري)',
                    prefixIcon: Icon(Icons.phone_outlined, size: 20),
                  ),
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _password,
                  obscureText: true,
                  textInputAction: TextInputAction.done,
                  decoration: const InputDecoration(
                    labelText: 'كلمة المرور',
                    prefixIcon: Icon(Icons.lock_outline, size: 20),
                  ),
                  validator: (v) => (v == null || v.length < 8)
                      ? 'كلمة المرور 8 أحرف على الأقل'
                      : null,
                ),
                const SizedBox(height: 22),
                // The backend distinguishes individuals from companies and
                // rejects any other value, so the choice is required.
                Container(
                  padding: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: context.tokens.surface3,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  child: Row(
                    children: [
                      _AccountTypeTab(
                        label: 'فرد',
                        selected: _accountType == 'individual',
                        onTap: () =>
                            setState(() => _accountType = 'individual'),
                      ),
                      _AccountTypeTab(
                        label: 'شركة',
                        selected: _accountType == 'company',
                        onTap: () => setState(() => _accountType = 'company'),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                PrimaryButton(
                  label: 'إنشاء الحساب',
                  expand: true,
                  loading: _busy,
                  onPressed: _submit,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _AccountTypeTab extends StatelessWidget {
  const _AccountTypeTab({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: selected ? context.tokens.surface : Colors.transparent,
            borderRadius: BorderRadius.circular(AppRadius.sm),
            boxShadow: selected ? AppShadow.xs : null,
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: selected
                  ? context.tokens.heading
                  : context.tokens.textMuted,
            ),
          ),
        ),
      ),
    );
  }
}
