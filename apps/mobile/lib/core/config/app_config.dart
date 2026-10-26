/// Build-time configuration.
///
/// The API base URL can be overridden at build time without editing code:
///
///   flutter run --dart-define=MAWATHEQ_API_BASE=https://mawatheq.com/api/v1
///
/// No credential is ever compiled into the app: authentication happens against
/// the platform's own `/api/v1/auth/*` endpoints and the returned token is kept
/// in the OS secure store (see `TokenStorage`).
class AppConfig {
  const AppConfig._();

  static const String apiBase = String.fromEnvironment(
    'MAWATHEQ_API_BASE',
    defaultValue: 'https://mawatheq.com/api/v1',
  );

  /// Runtime override for [apiBase].
  ///
  /// [apiBase] is a compile-time constant, which is correct for this app: it is
  /// baked into the build. It is wrong for the second shell that reuses this
  /// library — the lawyer app ships its own `LawyerConfig.apiBase` and must be able
  /// to point the shared client at it. The lawyer app calls [setBaseUrl] once at
  /// startup. When it is not called the constant is used, so this app's behaviour
  /// is unchanged.
  static String? _runtimeBase;

  /// Point the shared HTTP client at a different API root for this process.
  static void setBaseUrl(String? url) {
    _runtimeBase = (url == null || url.isEmpty) ? null : url;
  }

  /// The API root every request actually uses.
  static String get baseUrl => _runtimeBase ?? apiBase;

  /// Public marketing site, used for links the app does not implement natively
  /// (for example the 2FA enrolment page).
  static const String siteBase = String.fromEnvironment(
    'MAWATHEQ_SITE_BASE',
    defaultValue: 'https://mawatheq.com',
  );

  static const String appNameAr = 'مواثيق';
  static const String appNameEn = 'Mawatheq';

  /// The user-visible version, shown on the account screen.
  ///
  /// It must equal the `version:` line in `pubspec.yaml`. There is no
  /// `package_info_plus` dependency (it is a platform plugin outside the approved
  /// set), so the value is mirrored here; `exec52_app_version_test.dart` reads
  /// `pubspec.yaml` and fails if the two drift, which is what keeps this honest
  /// rather than a second copy that silently goes stale.
  static const String appVersion = '1.6.1';

  /// Request timeout. Kept short so the UI can show a retry affordance quickly.
  static const Duration timeout = Duration(seconds: 30);

  /// Budget for the session check that gates the splash screen.
  ///
  /// [timeout] is right for a request the user asked for and is watching. It is
  /// wrong for the startup check: the user asked for nothing and is looking at a
  /// logo. A stalled socket — a captive portal, or a network that completes the
  /// TCP handshake and then never answers — would hold the splash for the full 30
  /// seconds, which is indistinguishable from a hang. This bounds it, and the
  /// stored token is kept so a later attempt can still restore the session.
  static const Duration startupTimeout = Duration(seconds: 5);

  /// Absolute ceiling on the splash screen, independent of any request timeout.
  ///
  /// The entrance animation is 720 ms; this is far longer than any legitimate
  /// startup, so reaching it means something is wrong, not that the app is slow.
  /// It exists so a future startup call that forgets its own timeout cannot
  /// reintroduce the hang this execution fixed.
  static const Duration splashCeiling = Duration(seconds: 8);

  /// The platform's default jurisdiction. The API returns the real value with
  /// each lawyer record; this is only a display fallback, never a hardcoded
  /// assumption about the user's jurisdiction.
  static const String defaultJurisdiction = 'السعودية';
}
