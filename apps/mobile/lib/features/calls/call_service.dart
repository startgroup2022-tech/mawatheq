import 'dart:async';

import 'package:flutter_webrtc/flutter_webrtc.dart';

import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';

/// The state a call screen renders from.
enum CallPhase {
  /// Getting the join token and opening the local devices.
  preparing,

  /// The peer connection exists; waiting for the other party's media.
  connecting,

  /// Media is flowing.
  connected,

  /// The other party left or the call was ended.
  ended,

  /// Something failed; [CallController.error] says what.
  failed,
}

/// A thin holder around a Flutter WebRTC video renderer.
///
/// The plugin's `RTCVideoRenderer` is created asynchronously. Holding it here keeps
/// that initialisation in one place instead of in a widget's `initState`, where a
/// rebuild would create a second renderer and blank the video.
class CallRenderer {
  final RTCVideoRenderer renderer = RTCVideoRenderer();
  bool _ready = false;
  MediaStream? _stream;

  bool get isReady => _ready;
  MediaStream? get stream => _stream;

  Future<void> attach(MediaStream stream) async {
    if (!_ready) {
      await renderer.initialize();
      _ready = true;
    }
    _stream = stream;
    renderer.srcObject = stream;
  }

  void detach() {
    _stream = null;
    if (_ready) renderer.srcObject = null;
  }
}

/// Drives one WebRTC call end to end.
///
/// ## Why polling and not a socket
///
/// SDP offers/answers and ICE candidates are relayed through `api/v1/calls.php`,
/// which stores each signal as a row. A WebSocket service would be lower latency,
/// but it is a second thing to deploy and firewall on shared cPanel hosting, and it
/// would need its own authentication path. Polling the endpoint the app already
/// authenticates against keeps the media path peer-to-peer while adding no new
/// server. The interval is short enough for a call to connect in a second or two.
///
/// ## What this class is not
///
/// It does not decide whether a call may happen — the server does that when it
/// issues the token, and it re-checks the participant on every signal. The client
/// cannot widen its own access.
class CallController {
  CallController({
    required this.repository,
    required this.callId,
    required this.token,
    required this.callType,
    this.iceServers = const [],
    this.onChanged,
  });

  final ApiRepository repository;
  final int callId;
  final String token;

  /// `voice` or `video`.
  final String callType;

  /// The ICE servers the server handed us. Never hardcoded: a deployment that
  /// configures its own TURN server must have it used.
  final List<Map<String, dynamic>> iceServers;

  /// Called on every observable change, so the screen can `setState`. A `ChangeNotifier`
  /// was the alternative; a callback keeps this class free of a Flutter dependency so
  /// the signalling logic can be exercised without a widget tree.
  ///
  /// Mutable so the screen can attach its own listener after construction, which lets
  /// the elapsed-time ticker live in the widget rather than in the signalling layer.
  void Function()? onChanged;

  RTCPeerConnection? _pc;
  MediaStream? _localStream;
  final CallRenderer localRenderer = CallRenderer();
  final CallRenderer remoteRenderer = CallRenderer();

  Timer? _pollTimer;
  int _lastSignalId = 0;
  bool _disposed = false;
  final List<RTCIceCandidate> _pendingRemoteCandidates = [];
  bool _remoteDescriptionSet = false;
  bool _remoteVideoSeen = false;

  CallPhase _phase = CallPhase.preparing;
  CallPhase get phase => _phase;

  String? _error;
  String? get error => _error;

  bool _muted = false;
  bool get muted => _muted;

  bool _cameraOff = false;
  bool get cameraOff => _cameraOff;

  bool _speakerOn = true;
  bool get speakerOn => _speakerOn;

  bool _frontCamera = true;
  bool get frontCamera => _frontCamera;

  DateTime? _connectedAt;
  Duration get elapsed {
    final start = _connectedAt;
    if (start == null) return Duration.zero;
    return DateTime.now().difference(start);
  }

  bool get isVideoCall => callType == 'video';

  /// Whether the remote side is currently sending video.
  bool get remoteHasVideo => _remoteVideoSeen;

  /// Whether this device has more than one camera, so the switch button can be
  /// hidden rather than shown as a dead control.
  bool _canSwitchCamera = false;
  bool get canSwitchCamera => _canSwitchCamera;

  /// Open the local devices and join the peer connection.
  ///
  /// [offerer] must be true for the party that created the call. The callee waits
  /// for the caller's offer, which is what makes the exchange deterministic instead
  /// of a glare of two simultaneous offers.
  Future<void> start({required bool offerer}) async {
    try {
      await _openLocalMedia();
      await _createPeerConnection();
      await repository.callJoined(callId, token);
      _setPhase(CallPhase.connecting);
      _startPolling();
      if (offerer) {
        await _createAndSendOffer();
      }
    } catch (e) {
      _fail(_describe(e));
    }
  }

  Future<void> _openLocalMedia() async {
    final constraints = <String, dynamic>{
      'audio': true,
      'video': isVideoCall
          ? {
              'facingMode': 'user',
              'width': {'ideal': 1280},
              'height': {'ideal': 720},
            }
          : false,
    };
    _localStream = await navigator.mediaDevices.getUserMedia(constraints);
    await localRenderer.attach(_localStream!);
    // A video call starts with the camera on; a voice call has no video track at all,
    // which is what keeps it a genuine audio call rather than a muted video one.
    _cameraOff = !isVideoCall;
    if (isVideoCall) {
      try {
        final devices = await navigator.mediaDevices.enumerateDevices();
        _canSwitchCamera = devices.where((d) => d.kind == 'videoinput').length > 1;
      } catch (_) {
        _canSwitchCamera = false;
      }
    }
    _notify();
  }

  Future<void> _createPeerConnection() async {
    final config = <String, dynamic>{
      'iceServers': iceServers.isEmpty
          // A server-provided list is authoritative. An empty list would leave the
          // client unable to gather any candidate, so a last-resort public STUN is
          // used only when the server sent nothing at all.
          ? [
              {'urls': 'stun:stun.l.google.com:19302'}
            ]
          : iceServers,
      'sdpSemantics': 'unified-plan',
      'bundlePolicy': 'max-bundle',
      'rtcpMuxPolicy': 'require',
    };
    final pc = await createPeerConnection(config);
    _pc = pc;

    for (final track in _localStream!.getTracks()) {
      await pc.addTrack(track, _localStream!);
    }

    pc.onIceCandidate = (candidate) {
      if (candidate.candidate == null) return;
      // ICE candidates are relayed as they are gathered; the other side buffers any
      // that arrive before its remote description is set.
      repository.sendCallSignal(
        callId: callId,
        token: token,
        kind: 'ice',
        data: {
          'candidate': candidate.candidate,
          'sdpMid': candidate.sdpMid,
          'sdpMLineIndex': candidate.sdpMLineIndex,
        },
      );
    };

    pc.onTrack = (event) {
      if (event.streams.isNotEmpty) {
        remoteRenderer.attach(event.streams.first);
        if (event.track.kind == 'video') _remoteVideoSeen = true;
        _notify();
      }
    };

    pc.onConnectionState = (state) {
      switch (state) {
        case RTCPeerConnectionState.RTCPeerConnectionStateConnected:
          _connectedAt ??= DateTime.now();
          _setPhase(CallPhase.connected);
          break;
        case RTCPeerConnectionState.RTCPeerConnectionStateFailed:
          _fail('تعذّر إنشاء الاتصال. تحقق من الشبكة.');
          break;
        case RTCPeerConnectionState.RTCPeerConnectionStateDisconnected:
          // Transient on a mobile network; the next poll and ICE retry recover.
          _setPhase(CallPhase.connecting);
          break;
        default:
          break;
      }
    };
  }

  Future<void> _createAndSendOffer() async {
    final pc = _pc;
    if (pc == null) return;
    final offer = await pc.createOffer({
      'offerToReceiveAudio': true,
      'offerToReceiveVideo': isVideoCall,
    });
    await pc.setLocalDescription(offer);
    await repository.sendCallSignal(
      callId: callId,
      token: token,
      kind: 'offer',
      data: {'sdp': offer.sdp, 'type': offer.type},
    );
  }

  void _startPolling() {
    _pollTimer?.cancel();
    // 900ms: fast enough that the first offer is picked up within a second, slow
    // enough not to hammer the endpoint for the whole call.
    _pollTimer = Timer.periodic(const Duration(milliseconds: 900), (_) => _poll());
  }

  Future<void> _poll() async {
    if (_disposed) return;
    try {
      final signals = await repository.pollCallSignals(
        callId: callId,
        token: token,
        afterId: _lastSignalId,
      );
      for (final s in signals) {
        _lastSignalId = s.id;
        await _handleSignal(s);
      }
    } catch (_) {
      // A dropped poll is not a call failure; the next tick retries. Ending the call
      // on one failed request would make a brief network blip hang up on the user.
    }
  }

  Future<void> _handleSignal(CallSignal s) async {
    final pc = _pc;
    if (pc == null) return;
    final data = s.data is Map ? (s.data as Map).cast<String, dynamic>() : null;
    switch (s.kind) {
      case 'offer':
        if (data == null) return;
        await pc.setRemoteDescription(RTCSessionDescription(
          data['sdp']?.toString(),
          data['type']?.toString() ?? 'offer',
        ));
        _remoteDescriptionSet = true;
        await _flushPendingCandidates();
        final answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await repository.sendCallSignal(
          callId: callId,
          token: token,
          kind: 'answer',
          data: {'sdp': answer.sdp, 'type': answer.type},
        );
        break;
      case 'answer':
        if (data == null) return;
        // Only the caller sets a remote answer; ignoring a stray one prevents an
        // out-of-order relay from corrupting the session.
        if (_remoteDescriptionSet) return;
        await pc.setRemoteDescription(RTCSessionDescription(
          data['sdp']?.toString(),
          data['type']?.toString() ?? 'answer',
        ));
        _remoteDescriptionSet = true;
        await _flushPendingCandidates();
        break;
      case 'ice':
        if (data == null || data['candidate'] == null) return;
        final candidate = RTCIceCandidate(
          data['candidate']?.toString(),
          data['sdpMid']?.toString(),
          data['sdpMLineIndex'] is int ? data['sdpMLineIndex'] as int : 0,
        );
        if (!_remoteDescriptionSet) {
          // A candidate can arrive before the offer it belongs to. Buffering it is
          // required — setRemoteDescription throws otherwise, and the candidate would
          // be lost, which shows up as a call that connects for audio but never video.
          _pendingRemoteCandidates.add(candidate);
        } else {
          await pc.addCandidate(candidate);
        }
        break;
      case 'bye':
        await _remoteHungUp();
        break;
      default:
        break;
    }
  }

  Future<void> _flushPendingCandidates() async {
    final pc = _pc;
    if (pc == null) return;
    final pending = List<RTCIceCandidate>.from(_pendingRemoteCandidates);
    _pendingRemoteCandidates.clear();
    for (final c in pending) {
      try {
        await pc.addCandidate(c);
      } catch (_) {
        // A candidate that no longer applies is dropped; it cannot be repaired.
      }
    }
  }

  Future<void> _remoteHungUp() async {
    if (_disposed) return;
    _error = 'أنهى الطرف الآخر المكالمة.';
    _setPhase(CallPhase.ended);
    await _teardown();
  }

  /// Toggle the microphone. The track is disabled rather than removed, so the other
  /// side keeps the stream and the change is instant.
  void toggleMute() {
    final audio = _localStream?.getAudioTracks() ?? [];
    _muted = !_muted;
    for (final t in audio) {
      t.enabled = !_muted;
    }
    _notify();
  }

  /// Turn the camera off without dropping the video track (a re-enable is instant and
  /// the peer does not renegotiate).
  void toggleCamera() {
    final video = _localStream?.getVideoTracks() ?? [];
    if (video.isEmpty) return;
    _cameraOff = !_cameraOff;
    for (final t in video) {
      t.enabled = !_cameraOff;
    }
    _notify();
  }

  /// Switch the front/back camera by asking the plugin to swap the capture device.
  Future<void> switchCamera() async {
    final video = _localStream?.getVideoTracks() ?? [];
    if (video.isEmpty) return;
    try {
      await Helper.switchCamera(video.first);
      _frontCamera = !_frontCamera;
      _notify();
    } catch (_) {
      // Some devices expose a single camera; the button is hidden in that case, so
      // reaching here is harmless.
    }
  }

  /// Route audio to the speaker or the earpiece.
  Future<void> toggleSpeaker() async {
    _speakerOn = !_speakerOn;
    try {
      await Helper.setSpeakerphoneOn(_speakerOn);
    } catch (_) {
      // The platform may not support the call on this device.
    }
    _notify();
  }

  /// End the call from this side: tell the server, tell the peer, release the devices.
  Future<void> hangUp() async {
    if (_disposed) return;
    try {
      await repository.sendCallSignal(callId: callId, token: token, kind: 'bye');
    } catch (_) {
      // The server-side `end` below is the authoritative record; a failed courtesy
      // signal does not stop the hang-up.
    }
    try {
      await repository.endCall(callId, reason: 'hangup');
    } catch (_) {
      // The call still ends locally; the sweeper closes the row.
    }
    _setPhase(CallPhase.ended);
    await _teardown();
  }

  Future<void> _teardown() async {
    _pollTimer?.cancel();
    _pollTimer = null;
    try {
      await _localStream?.dispose();
    } catch (_) {}
    _localStream = null;
    try {
      await _pc?.close();
    } catch (_) {}
    _pc = null;
    localRenderer.detach();
    remoteRenderer.detach();
    _notify();
  }

  void _setPhase(CallPhase phase) {
    if (_disposed) return;
    _phase = phase;
    _notify();
  }

  void _fail(String message) {
    if (_disposed) return;
    _error = message;
    _phase = CallPhase.failed;
    _notify();
    _teardown();
  }

  String _describe(Object e) {
    final text = e.toString();
    if (text.contains('NotAllowedError') || text.contains('Permission')) {
      return 'لم يتم منح إذن الكاميرا أو الميكروفون.';
    }
    if (text.contains('NotFoundError')) {
      return 'لا توجد كاميرا أو ميكروفون على هذا الجهاز.';
    }
    return 'تعذّر بدء المكالمة.';
  }

  void _notify() {
    if (_disposed) return;
    onChanged?.call();
  }

  void dispose() {
    _disposed = true;
    _pollTimer?.cancel();
    _localStream?.dispose();
    _pc?.close();
    localRenderer.detach();
    remoteRenderer.detach();
  }
}
