import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../../core/network/lawyer_models.dart';
import 'jitsi_shell.dart';

/// A MAWATHEQ-branded Jitsi meeting carried by the hosted web client.
///
/// ## Why a WebView and not the native Jitsi SDK
///
/// The native SDK (`jitsi_meet_flutter_sdk`) bundles
/// `com.facebook.react:react-native-webrtc`, which ships the same `org.webrtc`
/// classes and the same `libjingle_peerconnection_so.so` as the
/// `io.github.webrtc-sdk:android` build that `flutter_webrtc` already brings in
/// for the built-in peer-to-peer screen. Two WebRTC builds cannot coexist in one
/// APK: Gradle fails first on the duplicated `.so` and then on every duplicated
/// `org.webrtc` class. The hosted web client needs no native dependency at all,
/// so both call transports live in one APK and the built-in screen keeps its own
/// WebRTC build untouched.
///
/// ## What still enforces access
///
/// Nothing about the access control moved into the app. The backend derives the
/// room from the consultation and signs the per-participant JWT, and the JWT is
/// handed to the web client as the `jwt` option, whose `room` claim the bridge
/// checks. This widget only carries what the server authorised; it never invents a
/// room or a token.
///
/// ## The External API shell
///
/// The meeting is loaded through a small local document that pulls Jitsi's
/// `external_api.js` and creates the conference. Going through the External API
/// rather than loading the join URL directly is what makes the lifecycle
/// observable: `readyToClose` and `videoConferenceLeft` arrive as messages on a
/// named JavaScript channel, so the screen can report the hangup exactly once and
/// pop itself. A bare join URL leaves the Flutter side with no signal that the call
/// ended, because the native UI it used to rely on is gone.
class JitsiCallScreen extends StatefulWidget {
  const JitsiCallScreen({
    super.key,
    required this.meeting,
    this.peerName = '',
    this.onLeft,
  });

  final CallMeeting meeting;
  final String peerName;

  /// Called once the meeting has ended, so the caller can record the hangup.
  final VoidCallback? onLeft;

  @override
  State<JitsiCallScreen> createState() => _JitsiCallScreenState();
}

class _JitsiCallScreenState extends State<JitsiCallScreen> {
  WebViewController? _controller;
  bool _finished = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _start());
  }

  /// Ends the meeting exactly once, whatever triggered it.
  ///
  /// Three things can end a call — the user hanging up, a failed join, and the
  /// widget being disposed while the WebView is still up. Without the guard the
  /// hangup would be reported two or three times and the server would see a
  /// duplicate `endCall`.
  void _finish({String? errorMessage}) {
    if (_finished) return;
    _finished = true;
    if (errorMessage != null && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('تعذّر بدء المكالمة: $errorMessage')),
      );
    }
    widget.onLeft?.call();
    if (mounted) Navigator.of(context).maybePop();
  }

  /// Asks the OS for the microphone and the camera, then loads the meeting.
  ///
  /// The permissions are requested here, before the page loads, rather than only
  /// answering the WebView's `onPermissionRequest`: the web client calls
  /// `getUserMedia` as soon as the conference joins, and a request still waiting on
  /// a system dialog is denied by the browser layer. A voice call only needs the
  /// microphone, so the camera is not requested for one — asking for a permission
  /// the call will never use is exactly the kind of prompt that trains a user to
  /// refuse.
  Future<void> _start() async {
    final needsCamera = widget.meeting.callType == 'video';
    try {
      final wanted = <Permission>[
        Permission.microphone,
        if (needsCamera) Permission.camera,
      ];
      final statuses = await wanted.request();
      final micOk = statuses[Permission.microphone]?.isGranted ?? false;
      if (!micOk) {
        _finish(errorMessage: 'لم يتم منح إذن الميكروفون.');
        return;
      }
    } catch (_) {
      // A platform without runtime permissions (the test surface) still gets to
      // load the page; the WebView callback below is the second line of defence.
    }
    if (!mounted) return;
    await _load();
  }

  Future<void> _load() async {
    final controller = WebViewController(
      // The second line of defence: if the OS dialog was skipped or already
      // granted, the page's own request is granted here rather than silently
      // denied, which would leave the call connected with no audio.
      onPermissionRequest: (request) => request.grant(),
    );
    await controller.setJavaScriptMode(JavaScriptMode.unrestricted);
    await controller.setBackgroundColor(Colors.black);
    await controller.setNavigationDelegate(
      NavigationDelegate(
        onWebResourceError: (error) {
          // Only a failure of the top-level document ends the call; a blocked
          // sub-resource (an analytics beacon, say) must not.
          if (error.isForMainFrame ?? false) {
            _finish(errorMessage: error.description);
          }
        },
      ),
    );
    await controller.addJavaScriptChannel(
      jitsiChannel,
      onMessageReceived: (message) {
        final payload = message.message;
        if (payload == 'readyToClose' || payload == 'left') {
          _finish();
        } else if (payload.startsWith('error:')) {
          _finish(errorMessage: payload.substring('error:'.length));
        }
      },
    );
    if (!mounted) return;
    await controller.loadHtmlString(
      jitsiShellHtml(widget.meeting),
      baseUrl: jitsiBaseUrl(widget.meeting),
    );
    if (!mounted) return;
    setState(() => _controller = controller);
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: controller == null
            ? Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const CircularProgressIndicator(color: Colors.white),
                    const SizedBox(height: 16),
                    Text(
                      widget.peerName.isEmpty
                          ? 'جارٍ الاتصال…'
                          : 'جارٍ الاتصال بـ ${widget.peerName}…',
                      style: const TextStyle(color: Colors.white),
                    ),
                  ],
                ),
              )
            : WebViewWidget(controller: controller),
      ),
    );
  }
}
