import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';

import '../config/app_config.dart';
import '../storage/token_storage.dart';
import 'api_exception.dart';

/// The single point of contact between the app and the MAWATHEQ backend.
///
/// Everything goes through the platform's existing versioned API (`/api/v1/`),
/// which already enforces authentication, role permissions and tenant isolation
/// server-side. The app never reaches the database and never talks to the AI
/// provider directly — see MAWATHEQ_MOBILE_API_CONTRACT.md.
class ApiClient {
  ApiClient({http.Client? client, TokenStore? tokens})
      : _http = client ?? http.Client(),
        _tokens = tokens ?? TokenStorage();

  final http.Client _http;
  final TokenStore _tokens;

  /// Test/DI seam: allows a fake transport without touching the secure store.
  http.Client get transport => _http;

  Future<Map<String, String>> _headers({bool auth = true}) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json; charset=utf-8',
    };
    if (auth) {
      final token = await _tokens.readToken();
      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }
    return headers;
  }

  Uri _uri(String path, [Map<String, dynamic>? query]) {
    final base = AppConfig.apiBase.endsWith('/')
        ? AppConfig.apiBase.substring(0, AppConfig.apiBase.length - 1)
        : AppConfig.apiBase;
    final normalized = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$base$normalized').replace(
      queryParameters: query?.map((k, v) => MapEntry(k, '$v')),
    );
  }

  /// Decodes the standard envelope and raises [ApiException] on failure.
  Map<String, dynamic> _decode(http.Response response) {
    final status = response.statusCode;
    final body = utf8.decode(response.bodyBytes);

    if (status >= 200 && status < 300) {
      if (body.trim().isEmpty) return <String, dynamic>{'ok': true};
      try {
        final decoded = jsonDecode(body);
        if (decoded is Map<String, dynamic>) return decoded;
        return <String, dynamic>{'ok': true, 'data': decoded};
      } catch (_) {
        throw ApiException(
          ApiErrorKind.server,
          'رد غير متوقع من الخادم.',
          statusCode: status,
        );
      }
    }

    // 202 is used by login when email verification is still required.
    throw ApiException(
      kindForStatus(status),
      messageFromBody(body, status),
      statusCode: status,
    );
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    bool auth = true,
  }) async {
    try {
      final response = await _http
          .get(_uri(path, query), headers: await _headers(auth: auth))
          .timeout(AppConfig.timeout);
      return _decode(response);
    } on ApiException {
      rethrow;
    } catch (error) {
      throw networkErrorFrom(error);
    }
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
  }) async {
    try {
      final response = await _http
          .post(
            _uri(path),
            headers: await _headers(auth: auth),
            body: jsonEncode(body ?? const <String, dynamic>{}),
          )
          .timeout(AppConfig.timeout);
      return _decode(response);
    } on ApiException {
      rethrow;
    } catch (error) {
      throw networkErrorFrom(error);
    }
  }

  /// Multipart upload for `POST /api/v1/documents.php`.
  ///
  /// `aiConsent` must be true: the endpoint refuses to send a document to the AI
  /// provider without the user's explicit acknowledgement.
  Future<Map<String, dynamic>> uploadDocument({
    required File file,
    required bool aiConsent,
    String? documentType,
    String? caseId,
  }) async {
    try {
      final request = http.MultipartRequest('POST', _uri('/documents.php'));
      final token = await _tokens.readToken();
      request.headers['Accept'] = 'application/json';
      if (token != null && token.isNotEmpty) {
        request.headers['Authorization'] = 'Bearer $token';
      }

      request.files.add(
        await http.MultipartFile.fromPath(
          'document',
          file.path,
          contentType: _mediaTypeFor(file.path),
        ),
      );
      request.fields['ai_consent'] = aiConsent ? '1' : '0';
      if (documentType != null) request.fields['document_type'] = documentType;
      if (caseId != null) request.fields['case_id'] = caseId;

      final streamed = await _http.send(request).timeout(AppConfig.timeout);
      final response = await http.Response.fromStream(streamed);
      return _decode(response);
    } on ApiException {
      rethrow;
    } catch (error) {
      throw networkErrorFrom(error);
    }
  }

  /// Multipart upload for the consultation journey's staged attachments.
  ///
  /// `POST /api/v1/consultation-attachments.php` with `draft_token`, so the customer can
  /// attach recordings and documents to a consultation that does not exist yet. The token
  /// is a 64-hex string the caller generates; the server binds the staged files to the
  /// consultation when the booking POST carries the same token.
  ///
  /// `contentType` is intentionally left to the server for audio and video: the app cannot
  /// always name the container reliably, and the endpoint sniffs the bytes anyway
  /// (`consultation_attachment_sniff`). Sending a wrong guess would only mislead.
  Future<Map<String, dynamic>> uploadConsultationAttachment({
    required File file,
    required String draftToken,
    MediaType? contentType,
  }) async {
    try {
      final request =
          http.MultipartRequest('POST', _uri('/consultation-attachments.php'));
      final token = await _tokens.readToken();
      request.headers['Accept'] = 'application/json';
      if (token != null && token.isNotEmpty) {
        request.headers['Authorization'] = 'Bearer $token';
      }

      request.files.add(
        await http.MultipartFile.fromPath(
          'file',
          file.path,
          contentType: contentType,
        ),
      );
      request.fields['draft_token'] = draftToken;

      final streamed = await _http.send(request).timeout(AppConfig.timeout);
      final response = await http.Response.fromStream(streamed);
      return _decode(response);
    } on ApiException {
      rethrow;
    } catch (error) {
      throw networkErrorFrom(error);
    }
  }

  /// `DELETE /api/v1/consultation-attachments.php?id=` — removes a staged file before the
  /// customer submits, so a mis-picked recording does not travel with the booking.
  Future<void> deleteConsultationAttachment(int attachmentId) async {
    try {
      final response = await _http
          .delete(
            _uri('/consultation-attachments.php', {'id': attachmentId}),
            headers: await _headers(),
          )
          .timeout(AppConfig.timeout);
      _decode(response);
    } on ApiException {
      rethrow;
    } catch (error) {
      throw networkErrorFrom(error);
    }
  }

  /// Mirrors the server's allow-list in `includes/upload_validation.php` exactly
  /// (pdf/png/jpg/jpeg). Anything else returns null so the request carries no
  /// misleading content type; the server sniffs the bytes and rejects it anyway.
  static MediaType? _mediaTypeFor(String path) {
    final lower = path.toLowerCase();
    if (lower.endsWith('.pdf')) return MediaType('application', 'pdf');
    if (lower.endsWith('.png')) return MediaType('image', 'png');
    if (lower.endsWith('.jpg') || lower.endsWith('.jpeg')) {
      return MediaType('image', 'jpeg');
    }
    // No other extension is accepted by the server; leave the content type unset.
    return null;
  }

  void dispose() => _http.close();
}
