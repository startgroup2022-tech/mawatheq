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
    locale_controller.dart        ar/en switch (provider)
    theme/, strings.dart          brand tokens
  features/
    public/                       onboarding, pricing
    auth/                         login, register
    home/                         shell + dashboard tab
    cases/ documents/ consultations/ assistant/ notifications/ account/
  shared/widgets/brand_widgets.dart   18 shared components
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
flutter test        # 11 tests: model parsing, error mapping, pricing screen
```

The tests use a fake transport that returns the same JSON and status codes the
PHP endpoints return, so the production client, repository and widgets are all
exercised for real. `_MemoryTokenStore` stands in for the platform keychain,
which is not available in a unit-test process.

## Known gaps

- **Document upload is not wired to a file picker.** `ApiRepository.uploadDocument`
  is implemented (including the mandatory AI-consent flag the endpoint requires),
  but the picker needs a platform plugin outside the approved dependency set.
  Shipping a button that cannot pick a file would be worse than documenting it.
- **Firm management screens are not built.** The API supports firm membership and
  the account screen shows firm role and permissions, but the firm-admin
  management flows remain website-only.
