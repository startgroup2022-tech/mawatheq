import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/register_screen.dart';
import 'brand_widgets.dart';

/// What the visitor chose in the authentication prompt.
enum AuthPromptChoice { signIn, register, keepBrowsing }

/// The polished prompt shown when a guest reaches something that needs an account.
///
/// It offers the three paths the brief asks for — sign in, create an account, or
/// keep browsing — and never pretends the action succeeded. The protected call is
/// not attempted first: the app stops at the door rather than firing a request the
/// server would reject.
Future<AuthPromptChoice> showAuthPrompt(
  BuildContext context, {
  required ProtectedDestination destination,
  String? message,
}) async {
  final isRtl = context.read<LocaleController>().isRtl;

  final choice = await showModalBottomSheet<AuthPromptChoice>(
    context: context,
    isScrollControlled: true,
    backgroundColor: context.tokens.surface,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xl)),
    ),
    builder: (sheetContext) => _AuthPromptSheet(
      destination: destination,
      message: message,
      isRtl: isRtl,
    ),
  );

  if (choice == null) return AuthPromptChoice.keepBrowsing;

  // Record the intent so a successful sign-in resumes here. Kept outside the sheet
  // so it survives the sheet being dismissed.
  if (choice != AuthPromptChoice.keepBrowsing && context.mounted) {
    context.read<GuestSession>().remember(destination);
  }
  return choice;
}

/// Stops a guest at a protected action and routes them to sign-in/register.
///
/// Returns `true` only when the visitor chose to sign in or register, so a caller
/// can stop work immediately; the navigation itself is handled here.
Future<bool> requireAccount(
  BuildContext context,
  ProtectedDestination destination, {
  String? message,
}) async {
  final choice = await showAuthPrompt(
    context,
    destination: destination,
    message: message,
  );
  if (!context.mounted) return false;

  switch (choice) {
    case AuthPromptChoice.signIn:
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => LoginScreen(returnTo: destination),
        ),
      );
      return true;
    case AuthPromptChoice.register:
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => const RegisterScreen()),
      );
      return true;
    case AuthPromptChoice.keepBrowsing:
      return false;
  }
}

class _AuthPromptSheet extends StatelessWidget {
  const _AuthPromptSheet({
    required this.destination,
    required this.isRtl,
    this.message,
  });

  final ProtectedDestination destination;
  final bool isRtl;
  final String? message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = isRtl ? 'ابدأ استخدام الخدمة' : 'Start using this service';
    final body = message ??
        (isRtl
            ? 'للاستفادة من هذه الخدمة، يرجى تسجيل الدخول أو إنشاء حساب جديد.'
            : 'To use this service, please sign in or create a new account.');

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(22, 10, 22, 22),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: context.tokens.borderStrong,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
              ),
            ),
            const SizedBox(height: 20),
            Center(
              child: Container(
                width: 62,
                height: 62,
                decoration: BoxDecoration(
                  gradient: AppColors.cyanAccent,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: const Icon(Icons.lock_outline,
                    size: 28, color: AppColors.navyDeep),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              title,
              textAlign: TextAlign.center,
              style: theme.textTheme.titleLarge,
            ),
            const SizedBox(height: 10),
            // The selected service is named explicitly so the visitor knows
            // exactly what they are being asked to sign in for.
            Center(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                decoration: BoxDecoration(
                  color: context.tokens.surface3,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
                child: Text(
                  isRtl
                      ? 'الخدمة المطلوبة: ${destination.labelAr}'
                      : 'Requested service: ${destination.labelEn}',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: context.tokens.heading,
                  ),
                ),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              body,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 22),
            PrimaryButton(
              label: isRtl ? 'تسجيل الدخول' : 'Sign in',
              icon: Icons.login,
              expand: true,
              onPressed: () =>
                  Navigator.of(context).pop(AuthPromptChoice.signIn),
            ),
            const SizedBox(height: 10),
            SecondaryButton(
              label: isRtl ? 'إنشاء حساب' : 'Create account',
              icon: Icons.person_add_alt,
              expand: true,
              onPressed: () =>
                  Navigator.of(context).pop(AuthPromptChoice.register),
            ),
            const SizedBox(height: 4),
            TextButton(
              onPressed: () =>
                  Navigator.of(context).pop(AuthPromptChoice.keepBrowsing),
              child: Text(isRtl ? 'متابعة التصفح' : 'Keep browsing'),
            ),
          ],
        ),
      ),
    );
  }
}
