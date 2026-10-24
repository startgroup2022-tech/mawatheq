import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'core/auth_controller.dart';
import 'core/config/app_config.dart';
import 'core/deep_link_service.dart';
import 'core/locale_controller.dart';
import 'core/network/api_repository.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/theme_controller.dart';

import 'features/auth/password_reset_screens.dart';
import 'features/home/home_shell.dart';
import 'features/public/splash_screen.dart';

/// Root widget: wires locale + direction, then routes from the splash to the
/// authenticated dashboard or the consumer shell depending on the session.
///
/// [deepLinks] is optional so widget tests can omit it and never touch a platform
/// channel; in production `main.dart` supplies one that turns a reset link into the
/// native reset screen.
class MawatheqApp extends StatefulWidget {
  const MawatheqApp({super.key, this.deepLinks});

  final DeepLinkService? deepLinks;

  @override
  State<MawatheqApp> createState() => _MawatheqAppState();
}

class _MawatheqAppState extends State<MawatheqApp> {
  bool _restored = false;
  bool _splashDone = false;
  final _navigatorKey = GlobalKey<NavigatorState>();

  /// A reset token delivered before the app finished restoring is held here and
  /// opened once the navigator exists, so a cold-start link is never dropped.
  String? _pendingResetToken;

  @override
  void initState() {
    super.initState();
    // Validate any stored token once, before deciding which screen to show.
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await context.read<AuthController>().restore();
      // The session check resolved, so the ceiling is no longer needed. Cancelling
      // it here also means a normal startup leaves no timer behind.
      _splashCeiling?.cancel();
      if (!mounted) return;
      setState(() => _restored = true);
      final token = _pendingResetToken;
      if (token != null) {
        _pendingResetToken = null;
        _openResetScreen(token);
      }
    });

    // Hard ceiling on the splash, independent of every request timeout below it.
    // The session check is already bounded (AppConfig.startupTimeout), but this is
    // the last line of defence: a future startup call that forgets its own timeout
    // must not be able to hold a user on a logo. Reaching this only marks the check
    // as done; a late `restore()` still updates the session normally.
    _splashCeiling = Timer(AppConfig.splashCeiling, () {
      if (mounted && !_restored) setState(() => _restored = true);
    });

    widget.deepLinks?.onResetPassword = _onResetToken;
  }

  Timer? _splashCeiling;

  @override
  void dispose() {
    _splashCeiling?.cancel();
    super.dispose();
  }

  void _onResetToken(String token) {
    if (_navigatorKey.currentState == null) {
      _pendingResetToken = token;
      return;
    }
    _openResetScreen(token);
  }

  void _openResetScreen(String token) {
    final navigator = _navigatorKey.currentState;
    if (navigator == null) return;
    // The repository is provided in production (main.dart); a bare test harness may
    // not provide it, in which case there is nothing to call and no link to act on.
    ApiRepository? repository;
    try {
      repository = context.read<ApiRepository>();
    } on ProviderNotFoundException {
      repository = null;
    }
    if (repository == null) return;
    navigator.push(
      MaterialPageRoute(
        builder: (_) => ResetPasswordScreen(
          repository: repository!,
          initialToken: token,
        ),
      ),
    );
  }

  /// The splash stays until BOTH the session check and the entrance animation are
  /// done, so the branding is not cut off mid-animation — but the animation is
  /// short, so it never becomes an artificial delay.
  bool get _showSplash => !_restored || !_splashDone;

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleController>();
    final auth = context.watch<AuthController>();
    // Read the theme choice, but tolerate a harness that does not provide one:
    // widget tests mount screens directly and should not have to supply it.
    ThemeController? themeController;
    try {
      themeController = context.watch<ThemeController>();
    } on ProviderNotFoundException {
      themeController = null;
    }

    return MaterialApp(
      title: 'مواثيق',
      navigatorKey: _navigatorKey,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      // Without a controller the app uses the light theme, matching the default a
      // first-time user gets, so a bare test harness renders the same palette.
      themeMode: themeController?.themeMode ?? ThemeMode.light,
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
              // Signed in, a guest, or an unauthenticated visitor (including an
              // expired session) all open the same shell on the same home. Only
              // the greeting and the account tab differ, which is what keeps the
              // product one product across the sign-in boundary.
              AuthStatus.signedIn ||
              AuthStatus.guest ||
              AuthStatus.signedOut =>
                const HomeShell(),
              _ => const HomeShell(),
            },
    );
  }
}
