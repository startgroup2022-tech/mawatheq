import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'app.dart';
import 'core/auth_controller.dart';
import 'core/deep_link_service.dart';
import 'core/guest_session.dart';
import 'core/locale_controller.dart';
import 'core/network/api_repository.dart';
import 'core/storage/token_storage.dart';
import 'core/theme/theme_controller.dart';

/// Composition root.
///
/// One repository, one token store and one auth controller are created here and
/// shared through `provider`; screens never construct their own clients, so there
/// is exactly one place that knows the API base URL and the token.
void main() {
  WidgetsFlutterBinding.ensureInitialized();

  final tokens = TokenStorage();
  final repository = ApiRepository();
  final auth = AuthController(repository: repository, tokens: tokens);
  final deepLinks = DeepLinkService();
  // Listen from startup so a reset link that launched the app cold is not missed.
  deepLinks.start();

  runApp(
    MultiProvider(
      providers: [
        Provider<ApiRepository>.value(value: repository),
        Provider<TokenStorage>.value(value: tokens),
        ChangeNotifierProvider<AuthController>.value(value: auth),
        ChangeNotifierProvider<GuestSession>(create: (_) => GuestSession()),
        ChangeNotifierProvider<LocaleController>(
          create: (_) => LocaleController()..load(),
        ),
        // The stored theme choice is read before the first frame the user can
        // interact with, so the app does not flash light and then switch to dark.
        ChangeNotifierProvider<ThemeController>(
          create: (_) => ThemeController()..load(),
        ),
      ],
      child: MawatheqApp(deepLinks: deepLinks),
    ),
  );
}
