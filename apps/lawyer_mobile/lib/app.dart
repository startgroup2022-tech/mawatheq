import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/config/app_config.dart';
import 'package:mawatheq_mobile/core/deep_link_service.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';
import 'package:mawatheq_mobile/features/auth/password_reset_screens.dart';
import 'package:mawatheq_mobile/features/calls/incoming_call_watcher.dart';
import 'package:mawatheq_mobile/features/lawyer/lawyer_shell.dart';
import 'package:mawatheq_mobile/features/public/splash_screen.dart';

import 'features/auth/lawyer_login_screen.dart';

/// Root widget of the independent lawyer app.
///
/// It is deliberately *not* the customer app with a flag flipped. There is no
/// public directory, no consultation booking, no AI assistant and no account-type
/// switch: the only signed-out screen is the lawyer sign-in, and the only signed-in
/// screen is the lawyer workspace. A client token is refused by the server's
/// `/auth/lawyer-login.php`, and even a token that somehow arrived without the
/// `lawyer` role is sent back to sign-in here rather than being shown a customer
/// surface it was never meant to see.
///
/// Every screen below the shell is the shared, already-verified implementation
/// (consultations, wallet, cases, calls, profile, settings), so this app cannot
/// disagree with the web console about what the backend said.
class LawyerApp extends StatefulWidget {
  const LawyerApp({super.key, this.deepLinks});

  final DeepLinkService? deepLinks;

  @override
  State<LawyerApp> createState() => _LawyerAppState();
}

class _LawyerAppState extends State<LawyerApp> {
  bool _restored = false;
  bool _splashDone = false;
  final _navigatorKey = GlobalKey<NavigatorState>();

  /// A reset token delivered before the app finished restoring is held here and
  /// opened once the navigator exists, so a cold-start link is never dropped.
  String? _pendingResetToken;

  Timer? _splashCeiling;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await context.read<AuthController>().restore();
      _splashCeiling?.cancel();
      if (!mounted) return;
      setState(() => _restored = true);
      final token = _pendingResetToken;
      if (token != null) {
        _pendingResetToken = null;
        _openResetScreen(token);
      }
    });

    // Same ceiling as the customer app: a stalled session check must never hold a
    // lawyer on a logo. Reaching it only marks the check as done; a late
    // `restore()` still updates the session normally.
    _splashCeiling = Timer(AppConfig.splashCeiling, () {
      if (mounted && !_restored) setState(() => _restored = true);
    });

    widget.deepLinks?.onResetPassword = _onResetToken;
  }

  @override
  void dispose() {
    _splashCeiling?.cancel();
    super.dispose();
  }

  void _onResetToken(String token) {
    if (_navigatorKey.currentContext == null) {
      _pendingResetToken = token;
      return;
    }
    _openResetScreen(token);
  }

  void _openResetScreen(String token) {
    _navigatorKey.currentState?.push(
      MaterialPageRoute(
        builder: (_) => LawyerResetFromLinkScreen(token: token),
      ),
    );
  }

  bool get _showSplash => !_restored || !_splashDone;

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleController>();
    // The theme controller is optional: a widget test may mount the app without a
    // provider, and it must fall back to the light theme rather than throw.
    final themeController = context.watch<ThemeController?>();

    final auth = context.watch<AuthController>();

    return MaterialApp(
      title: 'مواثيق للمحامين',
      navigatorKey: _navigatorKey,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: themeController?.themeMode ?? ThemeMode.light,
      locale: Locale(locale.locale),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) {
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
          : IncomingCallWatcher(
              child: switch (auth.status) {
                // Only a lawyer session reaches the workspace. Anything else — a
                // client token, a guest, or a signed-out lawyer — sees the lawyer
                // sign-in. There is no customer surface in this app at all.
                AuthStatus.signedIn when auth.user.isLawyer =>
                  const LawyerShell(),
                _ => const LawyerLoginScreen(),
              },
            ),
    );
  }
}

/// Opened from a password-reset deep link. It is the shared reset screen with the
/// lawyer account type, so a reset link never lands a lawyer on a customer form —
/// and the token from the link is carried straight into the form rather than being
/// dropped on the floor.
class LawyerResetFromLinkScreen extends StatelessWidget {
  const LawyerResetFromLinkScreen({super.key, required this.token});

  final String token;

  @override
  Widget build(BuildContext context) {
    return ResetPasswordScreen(
      repository: context.read<ApiRepository>(),
      initialToken: token,
      asLawyer: true,
    );
  }
}
