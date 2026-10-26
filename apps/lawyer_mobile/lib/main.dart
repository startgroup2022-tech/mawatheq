import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/deep_link_service.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';

import 'app.dart';

/// Composition root of the lawyer app.
///
/// One repository, one token store and one auth controller, shared through
/// `provider`. The repository is the shared implementation, so this app and the
/// customer app speak to the API through exactly the same client — only the shell
/// around it differs, which is what keeps the two apps from drifting apart.
///
/// The token store is namespaced to this app, so its stored session is separate
/// from the customer app's even on a device that has both installed.
void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // The Arabic families are bundled in `assets/fonts/`; a runtime fetch would
  // reflow the UI once it lands and could silently fall back to a face that does
  // not shape Arabic the way the brand requires.
  GoogleFonts.config.allowRuntimeFetching = false;

  final tokens = TokenStorage(namespace: 'lawyer');
  final repository = ApiRepository();
  final auth = AuthController(repository: repository, tokens: tokens);
  final deepLinks = DeepLinkService(resetPaths: IncomingLink.lawyerResetPaths);
  // Listen from startup so a reset link that launched the app cold is not missed.
  deepLinks.start();

  runApp(
    MultiProvider(
      providers: [
        Provider<ApiRepository>.value(value: repository),
        Provider<TokenStorage>.value(value: tokens),
        ChangeNotifierProvider<AuthController>.value(value: auth),
        ChangeNotifierProvider<LocaleController>(
          create: (_) => LocaleController()..load(),
        ),
        ChangeNotifierProvider<ThemeController>(
          create: (_) => ThemeController()..load(),
        ),
      ],
      child: LawyerApp(deepLinks: deepLinks),
    ),
  );
}
