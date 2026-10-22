# مواثيق — تطبيق الجوال (Mawatheq Mobile)

Flutter client for the Mawatheq legal platform. Arabic-first (RTL) with an
English mode, sharing the website's brand tokens.

## What it is, and what it is not

The app is a **client of the existing HTTP API**. It talks only to `api/v1/`
over HTTPS and never opens a database connection. Every price, plan limit,
status value and permission it displays comes from the server, so the app
cannot drift from what the platform actually does.

## Requirements

- Flutter 3.35+ (Dart 3.9+)
- The API reachable over HTTPS

## Running

Point the app at an API base without editing code:

```bash
flutter run --dart-define=MAWATHEQ_API_BASE=https://mawatheq.com/api/v1
```

`MAWATHEQ_SITE_BASE` overrides the marketing-site base used for pages the app
does not implement natively.

## Architecture

```
lib/
  core/
    config/app_config.dart        build-time API/site base, timeouts
    network/api_client.dart       HTTP + auth header + error mapping
    network/api_exception.dart    typed failures (network/unauthorized/…)
    network/api_repository.dart   one method per endpoint
    network/models.dart           JSON → typed models
    storage/token_storage.dart    TokenStore interface + keychain impl
    auth_controller.dart          session state (provider)
    guest_session.dart            guest mode + remembered destination
    public_content.dart           public marketing copy (AR/EN)
    locale_controller.dart        ar/en switch (provider)
    theme/, strings.dart          brand tokens
  features/
    public/                       splash, info pages, pricing, lawyer directory
    auth/                         login, register
    home/                         one shell + dashboard tab, for guest and signed-in
    cases/ documents/ consultations/ assistant/ notifications/ account/
    consultations/journey_screen.dart  specialty → issue type → lawyer → book
  shared/widgets/brand_widgets.dart   18 shared components
  shared/widgets/auth_gate.dart       sign-in prompt for protected actions
```

Layering rule: a screen depends on `ApiRepository`, never on `ApiClient` or on
`http` directly. `ApiRepository` is the only place that knows endpoint paths.

Screens take the repository as a constructor argument and fall back to
`ApiRepository()`. A screen that builds its own client cannot be mounted against a
fake transport, which is how `ConsultationsScreen` came to render an empty list in
the screenshot harness: the fixture was served by a transport the screen never used.

The Arabic type families live in `assets/fonts/` and are bundled, not fetched.
`main.dart` disables `GoogleFonts` runtime fetching, so a weight added to the theme
needs a matching file in that directory or the app throws at first paint.

## The consultation journey

`ConsultationJourneyScreen` walks the customer through four steps: specialty →
issue type → lawyer → booking. It calls the same three `journey.php` actions the
website does (`specialties`, `issue-types`, `lawyers`), so both clients show the
same data and obey the same rules.

The internal Najiz legal classification is never part of this journey. It is
absent from `JourneySpecialty`, `JourneyIssueType` and `JourneyMatch`, it is never
sent in the booking request, and it is never rendered. The customer chooses from
plain-language vocabulary only; the classification stays server-side and is shown
to the lawyer alone. `test/exec37_journey_test.dart` asserts this on the model,
the request body and the rendered screen.

A specialty with no registered lawyer still appears, marked as such, and the
lawyer step shows a clear alternative instead of an unrelated match.

## Authentication

Tokens are issued by `/api/v1/auth/login.php` (clients) and
`/api/v1/auth/lawyer-login.php` (lawyers) — two genuinely different endpoints,
which is why the login screen asks which account type is signing in rather than
guessing. The token is stored in the OS secure store via `TokenStorage`
(Keychain / EncryptedSharedPreferences) and is the only credential the app
holds. Logout revokes it server-side, then clears it locally even if the revoke
call fails.

## Guest access

A visitor can browse the public content without an account. `AuthController`
gains a `guest` status that is deliberately **not** a session: no token is issued,
no user id exists, and `can(...)` stays false for every permission. Guest mode
therefore changes nothing on the server and cannot reach a protected endpoint.

- A visitor lands on the same home shell a signed-in client uses. There is no
  separate guest homepage: one product across the sign-in boundary means the guest
  sees what the app is, not a marketing page standing in for it.
- The home tab shows the published specialties, the service list and the banner
  carousel — all from public endpoints. The account tab states plainly that the
  visitor is browsing as a guest and offers sign in or create account.
- Tapping a private area (cases, documents, assistant, consultations,
  notifications, account) raises an authentication prompt offering sign in,
  create account, or keep browsing. The protected call is never attempted first.
- Choosing sign in records the destination in `GuestSession`; after a successful
  login the app opens that screen instead of the dashboard. The record is consumed
  once, so the intent cannot be replayed.

## Splash and routing

`SplashScreen` shows the original logo unchanged on a light brand surface with a
short fade/scale/rise entrance. It stays until both the session check and the
animation finish — the animation is deliberately brief so it never becomes an
artificial delay. Routing then follows the session: signed in → dashboard,
guest or visitor → the home shell, with the guest badge and the account tab
explaining the state. A stored token the server rejects is cleared and the shell
reports the expired session instead of pretending to be signed in.

## Honest degradation

The platform's AI provider is configured in the admin console, so the app must
cope with it being absent:

- `GET /api/v1/ai-status.php` reports `available` plus a machine-readable
  `blocking` reason. The assistant screen shows that reason and disables sending
  rather than presenting a fake answer.
- `POST /api/v1/ai-chat.php` declines with an explicit message when the laws
  library has no matching article. The app shows that text as-is and only
  displays a statutory reference when the server returned one.

## Tests

```bash
flutter analyze     # must be clean
flutter test        # 204 tests: model parsing, error mapping, pricing, guest, splash,
                    #            auth security, password reset, deep links, consultation
                    #            journey, theming, blank-screen sweep
```

The tests use a fake transport that returns the same JSON and status codes the
PHP endpoints return, so the production client, repository and widgets are all
exercised for real. `_MemoryTokenStore` stands in for the platform keychain,
which is not available in a unit-test process.

### Screenshots

`tool/screenshots/capture_screenshots.dart` renders nine screens at 1080×2280 and
writes them to `tool/screenshots/goldens/`:

```bash
flutter test tool/screenshots/capture_screenshots.dart --update-goldens
```

It asserts each screen's text **before** capturing. `matchesGoldenFile` will
happily save a picture of a blank screen, so without the assertion a broken screen
would produce a passing test and a useless image.

`test/exec41_blank_screen_sweep_test.dart` is the companion guard: it mounts every
data-loading screen against the fake transport and asserts the fetched content
reached the screen, which is the failure mode neither a compile check nor a
mount-only test catches.

`test/exec37_journey_test.dart` pins the customer consultation journey against the
same `api/v1/journey.php` contract the website uses, and asserts the one rule the
feature exists to enforce: the internal Najiz classification never reaches the
customer. The model has no field for it, the request never sends it, and the
rendered journey screen never prints it.

  ## Password reset and Android App Links

  The reset email links to `https://mawatheq.com/reset-password.php?as=<type>&token=<64 hex>`.
  Tapping it on a device where the app is installed opens the native reset screen
  (`ResetPasswordScreen`) instead of the website; the token is validated before use and a
  link that does not match the expected host, path or token shape is ignored.

  Three pieces must agree for the link to open the app:

  1. The `intent-filter android:autoVerify="true"` entries in `android/app/src/main/AndroidManifest.xml`
     (host `mawatheq.com` and `www.mawatheq.com`, path prefix `/reset-password`).
  2. `https://mawatheq.com/.well-known/assetlinks.json` (committed at the repository root and
     served by the web root), whose `package_name` is `com.mawatheq.app`.
  3. The SHA-256 fingerprint of the certificate the installed build is signed with.

  The committed `assetlinks.json` carries a `REPLACE_WITH_RELEASE_SHA256_FINGERPRINT`
  placeholder. Before release:

  ```bash
  keytool -list -v -keystore <your-upload-key>.jks -alias <alias> | grep SHA256
  ```

  Paste the resulting colon-separated fingerprint into `assetlinks.json`. If the build is
  distributed through Play App Signing, use the App signing key certificate fingerprint from
  the Play Console (Setup → App integrity) instead of the local upload key.

  Release signing is driven by `android/key.properties` (gitignored); when it is absent the
  release build falls back to the debug signature. A properly signed release APK/AAB is needed
  for App Links verification — a debug-signed build will not verify against a release
  fingerprint.

  Verify on a device/emulator (requires the app installed and the domain serving the real
  fingerprint):

  ```bash
  adb shell pm verify-app-links --re-verify com.mawatheq.app
  adb shell pm get-app-links com.mawatheq.app          # expect "verified" for mawatheq.com
  adb shell am start -a android.intent.action.VIEW \
    -d "https://mawatheq.com/reset-password.php?as=client&token=<token>"
  ```

  ## Known gaps

- **Document upload needs a file picked on the device.** `ApiRepository.uploadDocument`
  is implemented (including the mandatory AI-consent flag the endpoint requires)
  and the documents screen is wired to `file_picker`.
- **Firm management screens are not built.** The API supports firm membership and
  the account screen shows firm role and permissions, but the firm-admin
  management flows remain website-only.
- **No in-app purchase.** The pricing screen states the published prices and
  explains that subscribing happens on the platform after sign-in; there is no
  mobile subscription API to call.
