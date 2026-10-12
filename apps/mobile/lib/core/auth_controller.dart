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

  /// Browsing public content without an account.
  ///
  /// This is deliberately *not* a session: no token is issued, no user id exists,
  /// and [AuthController.user] stays empty. A guest can only reach endpoints that
  /// are public on the server; every protected call still returns 401 and the app
  /// asks the visitor to sign in.
  guest,
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
  bool _sessionExpired = false;
  bool? _lastRegistrationEmailSent;

  AuthStatus get status => _status;
  SessionUser get user => _user;
  String? get error => _error;
  bool get isSignedIn => _status == AuthStatus.signedIn;
  bool get isGuest => _status == AuthStatus.guest;

  /// For the most recent [register] call: whether the server reported the
  /// verification email was handed off (`email_sent`). Null when the endpoint
  /// did not report it. Lets the success screen avoid promising a delivery that
  /// failed — the real cause is fixed server-side (see `includes/smtp.php`).
  bool? get lastRegistrationEmailSent => _lastRegistrationEmailSent;

  /// True when the stored token was rejected by the server (expired or revoked)
  /// during [restore], so the welcome screen can say so.
  bool get sessionExpired => _sessionExpired;

  /// True when the visitor may browse public content — signed in or not.
  bool get canBrowsePublic => _status != AuthStatus.checking;

  /// Enters guest mode: public browsing, no account, no token, no user id.
  ///
  /// This changes nothing on the server. Any stored token is left untouched (a guest
  /// is a visitor who has not signed in, not a signed-out user), and [user] stays
  /// empty so no screen can mistake a guest for an authenticated account.
  void enterGuest() {
    _error = null;
    _status = AuthStatus.guest;
    notifyListeners();
  }

  /// Leaves guest mode back to the welcome screen, without touching any token.
  void exitGuest() {
    if (_status != AuthStatus.guest) return;
    _status = AuthStatus.signedOut;
    notifyListeners();
  }

  /// Restores a session from the secure store on app start.
  ///
  /// A token that the server rejects (revoked, expired) is cleared locally so the
  /// app never keeps a token it cannot use, and [sessionExpired] is raised so the
  /// welcome screen can explain why the visitor has to sign in again rather than
  /// appearing to have lost the session for no reason.
  Future<void> restore() async {
    // Never clobber an active guest session: a guest has no token to validate,
    // and the splash must not bounce them back to the welcome screen.
    if (_status == AuthStatus.guest) return;

    final token = await _tokens.readToken();
    if (token == null || token.isEmpty) {
      _status = AuthStatus.signedOut;
      notifyListeners();
      return;
    }
    try {
      _user = await _repo.me();
      _status = AuthStatus.signedIn;
      _sessionExpired = false;
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.unauthorized || e.kind == ApiErrorKind.forbidden) {
        await _tokens.clear();
        _sessionExpired = true;
      }
      _status = AuthStatus.signedOut;
    } catch (_) {
      // Offline: keep the token, but do not pretend to be signed in. This is a
      // reachability problem, not an expired session, so the flag stays down.
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
      _sessionExpired = false;
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
    _lastRegistrationEmailSent = null;
    try {
      final outcome = await _repo.register(
        fullName: fullName,
        email: email,
        password: password,
        accountType: accountType,
        phone: phone,
      );
      _lastRegistrationEmailSent = outcome.emailSent;
      if (outcome.token != null && outcome.token!.isNotEmpty) {
        await _tokens.saveToken(outcome.token!);
        _user = outcome.user;
        _status = AuthStatus.signedIn;
        _sessionExpired = false;
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
    _sessionExpired = false;
    notifyListeners();
  }

  /// Whether the UI should offer a capability. The server enforces it regardless,
  /// so a stale value here can only hide a button, never grant access.
  bool can(String permission) => _user.can(permission);
}
