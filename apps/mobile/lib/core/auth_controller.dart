import 'package:flutter/foundation.dart';

import 'network/api_exception.dart';
import 'network/api_repository.dart';
import 'network/models.dart';
import 'storage/token_storage.dart';

/// How the app should present itself right now.
enum AuthStatus {
  /// Still checking the stored token — show the splash screen.
  checking,

  /// No valid session — public screens.
  signedOut,

  /// A valid session exists — authenticated shell.
  signedIn,
}

/// Holds the session and exposes sign-in/out. Deliberately small: it stores no
/// business data, only who is signed in and what the server said they may do.
class AuthController extends ChangeNotifier {
  AuthController({ApiRepository? repository, TokenStore? tokens})
      : _repo = repository ?? ApiRepository(),
        _tokens = tokens ?? TokenStorage();

  final ApiRepository _repo;
  final TokenStore _tokens;

  AuthStatus _status = AuthStatus.checking;
  SessionUser _user = SessionUser.empty;
  String? _error;

  AuthStatus get status => _status;
  SessionUser get user => _user;
  String? get error => _error;
  bool get isSignedIn => _status == AuthStatus.signedIn;

  /// Restores a session from the secure store on app start.
  ///
  /// A token that the server rejects (revoked, expired) is cleared locally so the
  /// app never keeps a token it cannot use.
  Future<void> restore() async {
    final token = await _tokens.readToken();
    if (token == null || token.isEmpty) {
      _status = AuthStatus.signedOut;
      notifyListeners();
      return;
    }
    try {
      _user = await _repo.me();
      _status = AuthStatus.signedIn;
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.unauthorized || e.kind == ApiErrorKind.forbidden) {
        await _tokens.clear();
      }
      _status = AuthStatus.signedOut;
    } catch (_) {
      // Offline: keep the token, but do not pretend to be signed in.
      _status = AuthStatus.signedOut;
    }
    notifyListeners();
  }

  /// Signs in as a client or a lawyer.
  ///
  /// [asLawyer] selects the endpoint; the server decides what the token may do.
  Future<bool> signIn({
    required String email,
    required String password,
    required bool asLawyer,
    String deviceLabel = 'Flutter',
  }) async {
    _error = null;
    try {
      final outcome = asLawyer
          ? await _repo.lawyerLogin(
              email: email, password: password, deviceLabel: deviceLabel)
          : await _repo.login(
              email: email, password: password, deviceLabel: deviceLabel);

      if (outcome.verificationRequired || outcome.token == null) {
        _error = 'يرجى تأكيد بريدك الإلكتروني أولاً، ثم تسجيل الدخول.';
        notifyListeners();
        return false;
      }

      await _tokens.saveToken(outcome.token!);
      await _tokens.saveAccountType(outcome.user.type);
      await _tokens.saveDeviceLabel(deviceLabel);
      _user = outcome.user;
      _status = AuthStatus.signedIn;
      notifyListeners();
      return true;
    } on ApiException catch (e) {
      _error = e.message;
      notifyListeners();
      return false;
    }
  }

  /// Registers a client account.
  ///
  /// Returns the message to show: the backend requires email verification before
  /// a token is issued, so a successful registration usually ends in "check your
  /// inbox" rather than a signed-in state.
  Future<bool> register({
    required String fullName,
    required String email,
    required String password,
    required String accountType,
    String? phone,
  }) async {
    _error = null;
    try {
      final outcome = await _repo.register(
        fullName: fullName,
        email: email,
        password: password,
        accountType: accountType,
        phone: phone,
      );
      if (outcome.token != null && outcome.token!.isNotEmpty) {
        await _tokens.saveToken(outcome.token!);
        _user = outcome.user;
        _status = AuthStatus.signedIn;
      }
      notifyListeners();
      return true;
    } on ApiException catch (e) {
      _error = e.message;
      notifyListeners();
      return false;
    }
  }

  /// Revokes the token server-side, then clears it locally.
  Future<void> signOut() async {
    await _repo.revokeToken();
    await _tokens.clear();
    _user = SessionUser.empty;
    _status = AuthStatus.signedOut;
    notifyListeners();
  }

  /// Whether the UI should offer a capability. The server enforces it regardless,
  /// so a stale value here can only hide a button, never grant access.
  bool can(String permission) => _user.can(permission);
}
