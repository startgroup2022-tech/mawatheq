import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'core/auth_controller.dart';
import 'core/locale_controller.dart';
import 'core/theme/app_theme.dart';

import 'features/home/home_shell.dart';
import 'features/public/onboarding_screen.dart';
import 'shared/widgets/brand_widgets.dart';

/// Root widget: wires locale + direction, then shows onboarding, login or the
/// authenticated shell based on the restored session.
class MawatheqApp extends StatefulWidget {
  const MawatheqApp({super.key});

  @override
  State<MawatheqApp> createState() => _MawatheqAppState();
}

class _MawatheqAppState extends State<MawatheqApp> {
  bool _restored = false;

  @override
  void initState() {
    super.initState();
    // Validate any stored token once, before deciding which screen to show.
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await context.read<AuthController>().restore();
      if (mounted) setState(() => _restored = true);
    });
  }

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
          textDirection:
              locale.isRtl ? TextDirection.rtl : TextDirection.ltr,
          child: child ?? const SizedBox.shrink(),
        );
      },
      home: !_restored
          ? const _SplashScreen()
          : (auth.isSignedIn ? const HomeShell() : const _Gate()),
    );
  }
}

/// Decides between onboarding and login for a signed-out visitor.
class _Gate extends StatelessWidget {
  const _Gate();

  @override
  Widget build(BuildContext context) {
    return const OnboardingScreen();
  }
}

/// Branded splash shown while the stored token is validated.
class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.light().scaffoldBackgroundColor,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const BrandLogo(size: 84),
            const SizedBox(height: 18),
            Text('مواثيق', style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 6),
            Text(
              'منصة قانونية متكاملة',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 28),
            const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2.2),
            ),
          ],
        ),
      ),
    );
  }
}
