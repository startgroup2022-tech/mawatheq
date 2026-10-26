import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/theme/app_colors.dart';
import 'package:mawatheq_mobile/core/theme/app_tokens.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';

import '../../core/lawyer_config.dart';
import 'lawyer_password_reset_screen.dart';

/// Sign-in for the lawyer app.
///
/// This app has no account-type switch on purpose: it is the lawyer app, so it
/// always calls `/auth/lawyer-login.php`. A client credential is refused by the
/// server (the endpoint only reads the `lawyers` table), and if a token ever came
/// back for a non-lawyer the gate in the shell sends it to the refusal screen —
/// the role is decided by the server, never by a local flag.
class LawyerLoginScreen extends StatefulWidget {
  const LawyerLoginScreen({super.key, this.onSignedIn});

  /// Called after a successful lawyer sign-in. The shell replaces itself with the
  /// workspace; the callback keeps this screen free of navigation policy.
  final VoidCallback? onSignedIn;

  @override
  State<LawyerLoginScreen> createState() => _LawyerLoginScreenState();
}

class _LawyerLoginScreenState extends State<LawyerLoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _busy = true);

    final auth = context.read<AuthController>();
    final ok = await auth.signIn(
      email: _email.text.trim(),
      password: _password.text,
      asLawyer: true,
      deviceLabel: 'Mawatheq Lawyer (${LawyerConfig.appVersion})',
    );

    if (!mounted) return;
    setState(() => _busy = false);

    if (ok) {
      widget.onSignedIn?.call();
      return;
    }
    final message = auth.error ?? 'تعذّر تسجيل الدخول. تحقّق من بياناتك.';
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  void _openReset() {
    final repo = context.read<ApiRepository>();
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => LawyerPasswordResetScreen(repository: repo),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: 24),
                const Center(child: BrandLogo(size: 84)),
                const SizedBox(height: 18),
                Text(
                  LawyerConfig.appNameAr,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineMedium,
                ),
                const SizedBox(height: 6),
                Text(
                  LawyerConfig.tagline,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 32),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.email],
                  decoration: const InputDecoration(
                    labelText: 'البريد الإلكتروني',
                    prefixIcon: Icon(Icons.mail_outline, size: 20),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().isEmpty) return 'أدخل البريد الإلكتروني';
                    if (!v.contains('@')) return 'صيغة البريد غير صحيحة';
                    return null;
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _password,
                  obscureText: _obscure,
                  textInputAction: TextInputAction.done,
                  autofillHints: const [AutofillHints.password],
                  onFieldSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                    labelText: 'كلمة المرور',
                    prefixIcon: const Icon(Icons.lock_outline, size: 20),
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscure ? Icons.visibility_off : Icons.visibility,
                        size: 20,
                      ),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                  validator: (v) =>
                      (v == null || v.isEmpty) ? 'أدخل كلمة المرور' : null,
                ),
                const SizedBox(height: 6),
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton(
                    onPressed: _openReset,
                    child: const Text('نسيت كلمة المرور؟'),
                  ),
                ),
                const SizedBox(height: 8),
                PrimaryButton(
                  label: 'الدخول إلى مساحة العمل',
                  expand: true,
                  loading: _busy,
                  onPressed: _submit,
                ),
                const SizedBox(height: 20),
                // A lawyer who reaches this app without an account yet needs the
                // way in, and it is the lawyer portal — never the customer one.
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: context.tokens.surface2,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.info_outline,
                          size: 18, color: context.tokens.textMuted),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'الحساب مخصّص للمحامين المعتمدين. للانضمام إلى المنصة، '
                          'سجّل عبر بوابة المحامين على الموقع.',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
