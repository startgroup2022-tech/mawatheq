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

  /// Public marketing site, used for links the app does not implement natively
  /// (for example the 2FA enrolment page).
  static const String siteBase = String.fromEnvironment(
    'MAWATHEQ_SITE_BASE',
    defaultValue: 'https://mawatheq.com',
  );

  static const String appNameAr = 'مواثيق';
  static const String appNameEn = 'Mawatheq';

  /// Request timeout. Kept short so the UI can show a retry affordance quickly.
  static const Duration timeout = Duration(seconds: 30);

  /// The platform's default jurisdiction. The API returns the real value with
  /// each lawyer record; this is only a display fallback, never a hardcoded
  /// assumption about the user's jurisdiction.
  static const String defaultJurisdiction = 'السعودية';
}
