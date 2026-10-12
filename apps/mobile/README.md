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
    public/                       splash, onboarding, guest home, info, pricing
    auth/                         login, register
    home/                         shell + dashboard tab
    cases/ documents/ consultations/ assistant/ notifications/ account/
  shared/widgets/brand_widgets.dart   18 shared components
  shared/widgets/auth_gate.dart       sign-in prompt for protected actions
```

Layering rule: a screen depends on `ApiRepository`, never on `ApiClient` or on
`http` directly. `ApiRepository` is the only place that knows endpoint paths.

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

- The welcome screen offers a prominent `الدخول كزائر` / `Continue as Guest`
  button.
- The guest homepage (`features/public/public_home_screen.dart`) shows only
  sections backed by shipped capabilities — features, services, documents,
  consultations, pricing, how-it-works, FAQ — and reads its prices from
  `GET /api/v1/plans.php`. No private record is reachable from it.
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
guest → public homepage, otherwise → welcome screen. A stored token the server
rejects is cleared and the welcome screen explains that the session expired.

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
flutter test        # 47 tests: model parsing, error mapping, pricing, guest, splash
```

The tests use a fake transport that returns the same JSON and status codes the
PHP endpoints return, so the production client, repository and widgets are all
exercised for real. `_MemoryTokenStore` stands in for the platform keychain,
which is not available in a unit-test process.

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
