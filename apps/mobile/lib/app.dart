import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'core/auth_controller.dart';
import 'core/locale_controller.dart';
import 'core/theme/app_theme.dart';

import 'features/home/home_shell.dart';
import 'features/public/onboarding_screen.dart';
import 'features/public/public_guest_shell.dart';
import 'features/public/splash_screen.dart';

/// Root widget: wires locale + direction, then routes from the splash to the
/// dashboard, the welcome screen, or the public homepage depending on the session.
class MawatheqApp extends StatefulWidget {
  const MawatheqApp({super.key});

  @override
  State<MawatheqApp> createState() => _MawatheqAppState();
}

class _MawatheqAppState extends State<MawatheqApp> {
  bool _restored = false;
  bool _splashDone = false;

  @override
  void initState() {
    super.initState();
    // Validate any stored token once, before deciding which screen to show.
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await context.read<AuthController>().restore();
      if (mounted) setState(() => _restored = true);
    });
  }

  /// The splash stays until BOTH the session check and the entrance animation are
  /// done, so the branding is not cut off mid-animation — but the animation is
  /// short, so it never becomes an artificial delay.
  bool get _showSplash => !_restored || !_splashDone;

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleController>();
    final auth = context.watch<AuthController>();

    return MaterialApp(
      title: 'مواثيق',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      locale: Locale(locale.locale),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) {
        // Force the text direction from the chosen locale so Arabic is always
        // RTL even when the device locale is something else.
        return Directionality(
          textDirection: locale.isRtl ? TextDirection.rtl : TextDirection.ltr,
          child: child ?? const SizedBox.shrink(),
        );
      },
      home: _showSplash
          ? SplashScreen(
              onAnimationComplete: () {
                if (mounted) setState(() => _splashDone = true);
              },
            )
          : switch (auth.status) {
              // Authenticated user → the role-based platform shell.
              AuthStatus.signedIn => const HomeShell(),
              // Guest → the public shell. Deliberately a different shell from
              // HomeShell: a guest never sees the authenticated dashboard.
              AuthStatus.guest => const PublicGuestShell(),
              // Unauthenticated (including an expired session) → welcome screen
              // with guest access.
              _ => const OnboardingScreen(),
            },
    );
  }
}
