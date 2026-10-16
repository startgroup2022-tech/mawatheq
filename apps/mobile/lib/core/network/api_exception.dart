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
String messageFromBody(String body, int status) {
  try {
    final decoded = jsonDecode(body);
    if (decoded is Map && decoded['error'] is String) {
      final msg = decoded['error'] as String;
      if (msg.trim().isNotEmpty) return msg;
    }
  } catch (_) {
    // Body was not JSON (an HTML error page, for instance).
  }
  if (status == 401) return 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.';
  if (status == 403) return 'لا تملك صلاحية للوصول إلى هذا العنصر.';
  if (status == 404) return 'العنصر المطلوب غير موجود.';
  if (status >= 500) return 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.';
  return 'تعذّر إتمام الطلب.';
}
