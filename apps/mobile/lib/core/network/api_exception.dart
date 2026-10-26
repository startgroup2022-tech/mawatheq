import 'dart:convert';
import 'dart:io';

/// The kind of failure, so the UI can decide between "retry", "log in again" and
/// "this will not work until the server is configured".
enum ApiErrorKind {
  /// The device could not reach the server at all.
  network,

  /// The token is missing, expired or revoked (HTTP 401).
  unauthorized,

  /// The account is not allowed to perform this action (HTTP 403).
  forbidden,

  /// The request was rejected as invalid (HTTP 400/409/422).
  validation,

  /// The resource does not exist (HTTP 404).
  notFound,

  /// The AI provider or another dependency is not configured/available.
  dependency,

  /// Anything else (HTTP 5xx, unparseable body).
  server,
}

/// A single error type for every API call.
///
/// The backend always answers with `{ "ok": false, "error": "..." }`
/// (`api/v1/README.md`), so the server's own Arabic message is preserved and
/// shown to the user instead of a generic string.
class ApiException implements Exception {
  ApiException(this.kind, this.message, {this.statusCode});

  final ApiErrorKind kind;
  final String message;
  final int? statusCode;

  /// Whether offering a "retry" action makes sense.
  bool get isRetryable =>
      kind == ApiErrorKind.network || kind == ApiErrorKind.server;

  @override
  String toString() => 'ApiException($kind, $statusCode): $message';
}

/// Turns a transport-level failure into an [ApiException].
ApiException networkErrorFrom(Object error) {
  if (error is SocketException) {
    return ApiException(
      ApiErrorKind.network,
      'تعذّر الاتصال بالخادم. تحقّق من اتصالك بالإنترنت ثم أعد المحاولة.',
    );
  }
  if (error is HttpException) {
    return ApiException(ApiErrorKind.network, 'انقطع الاتصال بالخادم أثناء الطلب.');
  }
  return ApiException(ApiErrorKind.network, 'تعذّر إتمام الطلب. أعد المحاولة.');
}

/// Maps an HTTP status to an error kind.
ApiErrorKind kindForStatus(int status) {
  if (status == 401) return ApiErrorKind.unauthorized;
  if (status == 403) return ApiErrorKind.forbidden;
  if (status == 404) return ApiErrorKind.notFound;
  if (status == 400 || status == 409 || status == 422) {
    return ApiErrorKind.validation;
  }
  if (status == 503 || status == 502) return ApiErrorKind.dependency;
  return ApiErrorKind.server;
}

/// Extracts `error` from the standard envelope, falling back to a safe message.
///
/// [authAttempt] marks a request that *establishes* a session (sign-in, password
/// reset) rather than one that uses an existing token. It changes only the 401
/// fallback: on a sign-in there is no session to expire, so answering "session
/// expired" is always wrong. That is exactly what a lawyer saw when their password
/// was simply incorrect — the endpoint's English `invalid email or password` was
/// replaced by the status fallback below, which is written for a protected call.
String messageFromBody(String body, int status, {bool authAttempt = false}) {
  try {
    final decoded = jsonDecode(body);
    if (decoded is Map && decoded['error'] is String) {
      final msg = (decoded['error'] as String).trim();
      if (msg.isNotEmpty) {
        if (authAttempt) {
          // On a sign-in, prefer Arabic over the server's raw English. An
          // unrecognised code still passes through so the reset screens keep
          // switching on values such as `invalid_token`.
          return authErrorText(msg) ??
              (_containsArabic(msg) ? msg : 'البريد الإلكتروني أو كلمة المرور غير صحيحة.');
        }
        return localizeServerMessage(msg, status);
      }
    }
  } catch (_) {
    // Body was not JSON (an HTML error page, for instance).
  }
  if (authAttempt && status == 401) {
    return 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
  }
  return fallbackMessageForStatus(status);
}

/// The Arabic message shown when the server sent nothing the customer can read.
String fallbackMessageForStatus(int status) {
  if (status == 401) return 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.';
  if (status == 403) return 'لا تملك صلاحية للوصول إلى هذا العنصر.';
  if (status == 404) return 'العنصر المطلوب غير موجود.';
  if (status >= 500) return 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.';
  return 'تعذّر إتمام الطلب.';
}

/// Keeps a server error only when the customer can actually read it.
///
/// The backend answers in Arabic, but not everything it writes is meant for the
/// customer: a 500 from the generic handler is the English `internal server error`,
/// and the auth layer writes English diagnostics such as
/// `unauthorized: invalid or expired token`. Those used to be shown verbatim, so an
/// Arabic-first app displayed raw English. Two things are deliberately preserved:
///
/// * Arabic text, because the backend's own wording is more specific than any
///   generic fallback and the app is Arabic-first.
/// * A bare lower_snake token such as `invalid_token`, because those are stable
///   machine codes the UI localises itself (`password_reset_screens.dart` switches
///   on them). Translating them here would break that mapping.
///
/// Everything else is replaced with the status-appropriate Arabic message.
String localizeServerMessage(String message, int status) {
  final msg = message.trim();
  if (msg.isEmpty) return fallbackMessageForStatus(status);
  if (_containsArabic(msg)) return msg;
  if (_isMachineCode(msg)) return msg;
  return fallbackMessageForStatus(status);
}

/// True when the text carries at least one Arabic letter.
bool _containsArabic(String value) {
  for (final rune in value.runes) {
    final isArabic = (rune >= 0x0600 && rune <= 0x06FF) || // Arabic
        (rune >= 0x0750 && rune <= 0x077F) || // Arabic Supplement
        (rune >= 0x08A0 && rune <= 0x08FF) || // Arabic Extended-A
        (rune >= 0xFB50 && rune <= 0xFDFF) || // Arabic Presentation Forms-A
        (rune >= 0xFE70 && rune <= 0xFEFF); // Arabic Presentation Forms-B
    if (isArabic) return true;
  }
  return false;
}

/// True for a single `lower_snake` token — a machine code, not prose.
bool _isMachineCode(String value) =>
    RegExp(r'^[a-z][a-z0-9_]*$').hasMatch(value);

/// Arabic text for a **sign-in** response, or `null` when the value is not
/// something this table recognises.
///
/// `api/v1/auth/lawyer-login.php` and `login.php` answer with a stable code rather
/// than prose so the app can say the right thing in the user's language:
///
/// * `invalid_credentials` — the generic wrong-email-or-password answer. It stays
///   deliberately generic so the form cannot be used to discover which addresses
///   exist; only the password-verified states below are ever named.
/// * `email_not_verified`, `pending_approval`, `account_suspended`,
///   `account_not_approved` — reported only after the password already matched, so
///   naming the state leaks nothing. These must not be shown as a wrong password,
///   which is what made an approved-but-pending lawyer believe sign-in was broken.
///
/// An unrecognised `lower_snake` code is passed through, because the reset screens
/// switch on values such as `invalid_token`. Unrecognised **prose** returns `null`
/// so the caller can substitute Arabic — a sign-in must never surface the server's
/// raw English (`invalid email or password`) to an Arabic-first app.
String? authErrorText(String code) {
  final msg = code.trim();
  switch (msg) {
    case 'invalid_credentials':
      return 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
    case 'email_not_verified':
      return 'لم يتم تأكيد بريدك الإلكتروني بعد. افتح رابط التأكيد الذي أرسلناه '
          'إلى بريدك، ثم حاول مرة أخرى.';
    case 'pending_approval':
      return 'تم تأكيد بريدك، وحسابك الآن قيد المراجعة من إدارة مواثيق. '
          'ستتمكن من الدخول مباشرة بعد اعتماده.';
    case 'account_suspended':
      return 'حسابك موقوف حالياً. يرجى التواصل مع دعم مواثيق لإعادة تفعيله.';
    case 'account_not_approved':
      return 'حسابك غير معتمد بعد. يرجى التواصل مع دعم مواثيق.';
    default:
      return _isMachineCode(msg) ? msg : null;
  }
}
