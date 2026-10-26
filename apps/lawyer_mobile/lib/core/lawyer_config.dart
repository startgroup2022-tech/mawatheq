/// Build-time configuration for the independent lawyer app.
///
/// The API base URL can be overridden at build time without editing code:
///
///   flutter build apk --release \
///     --dart-define=MAWATHEQ_API_BASE=https://mawatheq.com/api/v1
///
/// No credential is ever compiled into the app. Authentication happens against
/// the platform's own `/api/v1/auth/lawyer-login.php` and the returned token is
/// kept in the OS secure store (see the shared `TokenStorage`).
class LawyerConfig {
  const LawyerConfig._();

  static const String apiBase = String.fromEnvironment(
    'MAWATHEQ_API_BASE',
    defaultValue: 'https://mawatheq.com/api/v1',
  );

  /// Public marketing site, for links the app does not implement natively.
  static const String siteBase = String.fromEnvironment(
    'MAWATHEQ_SITE_BASE',
    defaultValue: 'https://mawatheq.com',
  );

  /// A lawyer signs in here, not through the customer page. Kept as a constant so
  /// a fallback link can never point a lawyer at the client login by accident.
  static const String lawyerLoginUrl = 'https://mawatheq.com/lawyer-login.php';

  /// The user-visible version. Must equal the `version:` line in `pubspec.yaml`;
  /// `exec58_lawyer_app_test.dart` reads the pubspec and fails if they drift, the
  /// same guard the client app uses.
  static const String appVersion = '1.1.0';

  /// Shown under the brand mark on the sign-in screen.
  static const String appNameAr = 'مواثيق للمحامين';
  static const String appNameEn = 'Mawatheq Lawyer';
  static const String tagline = 'مساحة عمل المحامي';
}
