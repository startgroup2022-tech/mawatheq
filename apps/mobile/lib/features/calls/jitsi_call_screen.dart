import 'package:flutter/material.dart';
import 'package:jitsi_meet_flutter_sdk/jitsi_meet_flutter_sdk.dart';

import '../../core/network/lawyer_models.dart';

/// A full-screen Jitsi meeting, branded as MAWATHEQ.
///
/// The SDK renders a native Jitsi UI over the whole screen, so this widget exists
/// only to hand it the server-authorised join parameters and to translate the
/// callbacks into the consultation's own lifecycle. Every parameter comes from
/// `CallMeeting`, which the backend derived after checking that the signed-in
/// account is a participant of that call — the app never invents a room, and it
/// never joins a call the server did not authorise.
///
/// The Jitsi UI is shown in Arabic to match the app, and the pre-join screen is
/// skipped for the caller who already pressed "call" so the lawyer is not asked
/// twice whether they want to join.
///
/// `subject` is not a constructor argument in SDK 13: it is a `configOverrides`
/// entry, which is where Jitsi itself reads the conference title from.
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
  final JitsiMeet _jitsi = JitsiMeet();
  bool _started = false;
  bool _closing = false;

  @override
  void initState() {
    super.initState();
    // The native UI owns the screen; the Flutter frame behind it is only a
    // placeholder while the SDK initialises.
    WidgetsBinding.instance.addPostFrameCallback((_) => _join());
  }

  /// Ends the meeting exactly once, whatever triggered it.
  ///
  /// Three things can close a call — the user hanging up, a failed join, and the
  /// widget being disposed while the native UI is still up. Without the guard the
  /// hangup would be reported two or three times.
  void _finish({String? errorMessage}) {
    if (_closing) return;
    _closing = true;
    if (errorMessage != null && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('تعذّر بدء المكالمة: $errorMessage')),
      );
    }
    widget.onLeft?.call();
    if (mounted) Navigator.of(context).maybePop();
  }

  Future<void> _join() async {
    if (_started) return;
    _started = true;
    final m = widget.meeting;

    final options = JitsiMeetConferenceOptions(
      room: m.room,
      serverURL: 'https://${m.domain}',
      token: m.jwt,
      userInfo: JitsiMeetUserInfo(
        displayName: m.displayName.isEmpty ? null : m.displayName,
      ),
      configOverrides: {
        'subject': m.subject,
        'startWithAudioMuted': false,
        'startWithVideoMuted': m.callType != 'video',
        'prejoinPageEnabled': false,
        'disableDeepLinking': true,
        'defaultLanguage': m.language,
        // The consultation is a two-party legal conversation. Recording,
        // livestreaming and file upload are refused server-side by the JWT
        // feature claims, and are hidden here so no one is offered a control
        // the token would reject.
        'fileRecordingsEnabled': false,
        'liveStreamingEnabled': false,
        'toolbarButtons': [
          'microphone',
          'camera',
          'closedcaptions',
          'desktop',
          'chat',
          'raisehand',
          'tileview',
          'hangup',
        ],
      },
      featureFlags: {
        'unsaferoomwarning.enabled': false,
        'welcomepage.enabled': false,
        'prejoinpage.enabled': false,
      },
    );

    final listener = JitsiMeetEventListener(
      conferenceTerminated: (_, error) {
        // A non-null error means the join failed rather than the user hanging up.
        _finish(errorMessage: error?.toString());
      },
      readyToClose: () => _finish(),
    );

    try {
      final response = await _jitsi.join(options, listener);
      if (response.error != null) _finish(errorMessage: response.error.toString());
    } catch (e) {
      _finish(errorMessage: e.toString());
    }
  }

  @override
  Widget build(BuildContext context) {
    // The native Jitsi view is presented modally by the SDK; this is only what is
    // visible during the brief initialisation window.
    return Scaffold(
      backgroundColor: Colors.black,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const CircularProgressIndicator(color: Colors.white),
            const SizedBox(height: 16),
            Text(
              widget.peerName.isEmpty ? 'جارٍ الاتصال…' : 'جارٍ الاتصال بـ ${widget.peerName}…',
              style: const TextStyle(color: Colors.white),
            ),
            if (!widget.meeting.enforceable) ...[
              const SizedBox(height: 12),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 32),
                child: Text(
                  widget.meeting.accessMessage,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white70, fontSize: 12),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
