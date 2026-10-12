import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/config/app_config.dart';
import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'register_screen.dart';

/// Sign-in for both account types.
///
/// The two token types are genuinely different on the backend (`/auth/login.php`
/// for clients, `/auth/lawyer-login.php` for lawyers), so the choice is explicit
/// rather than guessed from the email.
///
/// [returnTo] is the destination a guest was stopped at. When sign-in succeeds the
/// app opens that screen instead of the dashboard, so the visitor resumes what they
/// were doing.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.startAsLawyer = false, this.returnTo});

  final bool startAsLawyer;
  final ProtectedDestination? returnTo;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();

  late bool _asLawyer = widget.startAsLawyer;
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
      asLawyer: _asLawyer,
    );

    if (!mounted) return;
    setState(() => _busy = false);

    if (ok) {
      _resumeDestination();
    } else if (auth.error != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(auth.error!)),
      );
    }
  }

  /// After a successful sign-in, land on the destination the guest was heading
  /// for; otherwise the dashboard.
  ///
  /// The explicit [LoginScreen.returnTo] wins, and the guest-session record is
  /// consumed so the intent is honoured exactly once. The destination is opened
  /// only if the freshly signed-in account is permitted to be there — otherwise an
  /// access-denied screen is shown, never an empty dashboard.
  void _resumeDestination() {
    final guest = context.read<GuestSession>();
    final destination =
        widget.returnTo ?? guest.consume() ?? ProtectedDestination.dashboard;
    guest.clear();

    if (!destination.allows(context.read<AuthController>().user)) {
      final rtl = context.read<LocaleController>().isRtl;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(
          builder: (_) => AccessDeniedScreen(
            destination: destination,
            isRtl: rtl,
            onBack: () => Navigator.of(context).pushAndRemoveUntil(
              MaterialPageRoute(
                builder: (_) => ProtectedDestination.dashboard.buildShell(),
              ),
              (route) => false,
            ),
          ),
        ),
        (route) => false,
      );
      return;
    }

    final pushed = destination.buildPushedScreen();
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => destination.buildShell()),
      (route) => false,
    );
    if (pushed != null) {
      // A destination that is not a bottom-nav tab (consultations,
      // notifications) opens on top of the shell it belongs to.
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => pushed),
      );
    }
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
                const SizedBox(height: 20),
                const Center(child: BrandLogo(size: 76)),
                const SizedBox(height: 18),
                Text(
                  'مواثيق',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineMedium,
                ),
                const SizedBox(height: 6),
                Text(
                  'منصة قانونية متكاملة',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 28),

                // Account type — drives which endpoint is called.
                Container(
                  padding: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: AppColors.surface3,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  child: Row(
                    children: [
                      _TypeTab(
                        label: 'عميل',
                        selected: !_asLawyer,
                        onTap: () => setState(() => _asLawyer = false),
                      ),
                      _TypeTab(
                        label: 'محامٍ',
                        selected: _asLawyer,
                        onTap: () => setState(() => _asLawyer = true),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 22),

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
                    onPressed: () => _openWeb('/forgot-password.php'),
                    child: const Text('نسيت كلمة المرور؟'),
                  ),
                ),
                const SizedBox(height: 8),
                PrimaryButton(
                  label: _asLawyer ? 'الدخول كمحامٍ' : 'تسجيل الدخول',
                  expand: true,
                  loading: _busy,
                  onPressed: _submit,
                ),
                const SizedBox(height: 18),

                if (!_asLawyer)
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Flexible(
                        child: Text(
                        'ليس لديك حساب؟',
                        style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                      TextButton(
                        onPressed: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => const RegisterScreen(),
                          ),
                        ),
                        child: const Text('إنشاء حساب'),
                      ),
                    ],
                  ),

                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.infoBg,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    border: Border.all(color: const Color(0xFFCFEAF1)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline, size: 16, color: AppColors.teal),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          _asLawyer
                              ? 'دخول المحامين متاح بعد اعتماد الحساب من الإدارة.'
                              : 'يتطلب تسجيل الدخول تأكيد البريد الإلكتروني أولاً.',
                          style: const TextStyle(
                            fontSize: 12,
                            color: AppColors.textMuted,
                            height: 1.6,
                          ),
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

  /// Opens a page the app does not implement natively, in the system browser.
  void _openWeb(String path) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('افتح الرابط في المتصفح: ${AppConfig.siteBase}$path'),
      ),
    );
  }
}

class _TypeTab extends StatelessWidget {
  const _TypeTab({
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
            color: selected ? AppColors.surface : Colors.transparent,
            borderRadius: BorderRadius.circular(AppRadius.sm),
            boxShadow: selected ? AppShadow.xs : null,
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: selected ? AppColors.navy : AppColors.textMuted,
            ),
          ),
        ),
      ),
    );
  }
}
