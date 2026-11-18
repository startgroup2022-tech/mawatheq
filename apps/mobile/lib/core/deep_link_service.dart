import 'dart:async';

import 'package:app_links/app_links.dart';

/// A link the app was opened with, if it maps to a screen the app owns.
///
/// Only the reset-password link is handled natively; every other path (support,
/// payment, marketing) stays in the browser. A malformed or unknown link is
/// ignored rather than opened, so a hostile link cannot steer the app.
class IncomingLink {
  const IncomingLink.resetPassword(this.token) : isReset = true;

  final String token;
  final bool isReset;

  /// Parses a `mawatheq.com/reset-password.php?token=...` (or `/reset-password`)
  /// App Link. Returns null for anything else.
  ///
  /// The host is checked explicitly as well as the path: App Links verification
  /// already restricts the host at the OS level, but a parser that trusted any host
  /// would hand a custom-scheme or other-domain link straight to the reset screen.
  ///
  /// [resetPaths] lets the lawyer app accept its own path (`/lawyer-reset-password`)
  /// as well. The two apps ship as separate packages with separate application ids,
  /// so Android resolves the link to the app whose manifest declared that path; the
  /// default keeps the customer app's behaviour byte-identical.
  static IncomingLink? tryParse(
    Uri uri, {
    Set<String>? allowedHosts,
    Set<String>? resetPaths,
  }) {
    final hosts = allowedHosts ?? _defaultHosts;
    if (uri.scheme != 'https' || !hosts.contains(uri.host.toLowerCase())) {
      return null;
    }
    final paths = resetPaths ?? _defaultResetPaths;
    final path = uri.path.replaceAll(RegExp(r'/+'), '/').toLowerCase();
    if (!paths.contains(path)) return null;
    final token = uri.queryParameters['token'] ?? '';
    if (!RegExp(r'^[a-f0-9]{64}$').hasMatch(token)) return null;
    return IncomingLink.resetPassword(token);
  }

  static final Set<String> _defaultHosts = {
    'mawatheq.com',
    'www.mawatheq.com',
  };

  static const Set<String> _defaultResetPaths = {
    '/reset-password.php',
    '/reset-password',
  };

  /// The lawyer app's reset paths, declared in its own manifest.
  static const Set<String> lawyerResetPaths = {
    '/lawyer-reset-password.php',
    '/lawyer-reset-password',
  };
}

/// The slice of `AppLinks` the service needs, so a test can drive it with a
/// fake stream instead of a platform channel.
abstract class LinkSource {
  Future<Uri?> getInitialLink();
  Stream<Uri> get uriLinkStream;
}

/// Production [LinkSource], backed by the `app_links` plugin.
class AppLinksSource implements LinkSource {
  AppLinksSource([AppLinks? links]) : _links = links ?? AppLinks();

  final AppLinks _links;

  @override
  Future<Uri?> getInitialLink() => _links.getInitialLink();

  @override
  Stream<Uri> get uriLinkStream => _links.uriLinkStream;
}

/// Bridges Android App Links / iOS Universal Links into the running app.
///
/// Kept deliberately small and injectable so widget tests never touch a platform
/// channel. The initial link (app launched cold) and the stream (app already
/// running) are both surfaced to one callback.
class DeepLinkService {
  DeepLinkService({
    LinkSource? links,
    void Function(String token)? onResetPassword,
    Set<String>? resetPaths,
  })  : _links = links ?? AppLinksSource(),
        _resetPaths = resetPaths ?? IncomingLink._defaultResetPaths {
    if (onResetPassword != null) this.onResetPassword = onResetPassword;
  }

  final LinkSource _links;

  /// Which reset paths this app owns. The customer app keeps the default; the lawyer
  /// app passes [IncomingLink.lawyerResetPaths] so its own link is recognized and the
  /// customer path is not.
  final Set<String> _resetPaths;

  void Function(String token)? _onResetPassword;

  /// A link that arrived before the callback was assigned — the app was launched
  /// cold by the link, so `start()` runs before the first widget is built.
  String? _pendingToken;

  /// Called with the token when a reset link arrives. The token is not logged.
  /// Assigning it flushes any token that arrived while it was still null.
  set onResetPassword(void Function(String token)? handler) {
    _onResetPassword = handler;
    final pending = _pendingToken;
    if (pending != null && handler != null) {
      _pendingToken = null;
      handler(pending);
    }
  }

  void Function(String token)? get onResetPassword => _onResetPassword;

  StreamSubscription<Uri>? _sub;

  /// Reads the link the app was launched with and then listens for new ones.
  Future<void> start() async {
    try {
      final initial = await _links.getInitialLink();
      if (initial != null) _handle(initial);
    } catch (_) {
      // A platform without App Links support simply never delivers one.
    }
    _sub = _links.uriLinkStream.listen(
      _handle,
      onError: (_) {},
    );
  }

  void _handle(Uri uri) {
    final link = IncomingLink.tryParse(uri, resetPaths: _resetPaths);
    if (link == null || !link.isReset) return;
    final handler = _onResetPassword;
    if (handler == null) {
      _pendingToken = link.token;
    } else {
      handler(link.token);
    }
  }

  Future<void> dispose() async {
    await _sub?.cancel();
    _sub = null;
  }
}
