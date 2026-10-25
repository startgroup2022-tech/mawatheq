import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';
import '../../core/theme/app_colors.dart';
import 'call_service.dart';

/// Opens a call, or explains why it cannot be opened.
///
/// The provider check comes first. A deployment that has not configured a media
/// server yet, or whose calls are switched off, must say so *before* the user sees a
/// ringing screen — a call that fails silently is worse than one that never starts.
class CallLauncher {
  const CallLauncher._();

  /// Start an outgoing call to the other party of [consultationId].
  static Future<void> start(
    BuildContext context, {
    ApiRepository? repository,
    required int consultationId,
    required String callType,
    String peerName = '',
  }) async {
    final repo = repository ?? ApiRepository();
    try {
      final state = await repo.callState(consultationId);
      if (!state.available) {
        if (!context.mounted) return;
        _explain(context, state.message.isEmpty ? 'المكالمات غير متاحة حالياً.' : state.message);
        return;
      }
      final join = await repo.startCall(consultationId: consultationId, callType: callType);
      if (!context.mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => CallScreen(
            repository: repo,
            join: join,
            callType: callType,
            peerName: peerName,
            outgoing: true,
          ),
        ),
      );
    } on ApiException catch (e) {
      if (!context.mounted) return;
      _explain(context, e.message);
    }
  }

  static void _explain(BuildContext context, String message) {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تعذّر بدء المكالمة'),
        content: Text(message),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('حسناً')),
        ],
      ),
    );
  }
}

/// The call screen, used for both an outgoing and an accepted incoming call.
///
/// The two directions share one screen because the only real difference is which side
/// creates the offer and what the header says; two screens would drift.
class CallScreen extends StatefulWidget {
  const CallScreen({
    super.key,
    required this.repository,
    required this.join,
    required this.callType,
    this.peerName = '',
    this.outgoing = true,
  });

  final ApiRepository repository;
  final CallJoinInfo join;
  final String callType;
  final String peerName;
  final bool outgoing;

  @override
  State<CallScreen> createState() => _CallScreenState();
}

class _CallScreenState extends State<CallScreen> {
  late final CallController _call;
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    _call = CallController(
      repository: widget.repository,
      callId: widget.join.call.id,
      token: widget.join.token,
      callType: widget.callType,
      iceServers: widget.join.iceServers,
    );
    _call.onChanged = () {
      if (!mounted) return;
      // The elapsed label is a display concern: tick only while connected, and stop
      // the timer the moment the phase changes so a finished call leaves nothing
      // behind.
      if (_call.phase == CallPhase.connected) {
        _ticker ??= Timer.periodic(const Duration(seconds: 1), (_) {
          if (mounted) setState(() {});
        });
      } else {
        _ticker?.cancel();
        _ticker = null;
      }
      setState(() {});
    };
    _call.start(offerer: widget.outgoing);
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _call.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isVideo = widget.callType == 'video';
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        // Capture the navigator before the await: `context` may be gone by the time
        // the hang-up completes, and reading it afterwards is the lint's real concern.
        final navigator = Navigator.of(context);
        await _call.hangUp();
        if (mounted) navigator.maybePop();
      },
      child: Scaffold(
        backgroundColor: AppColors.navyDark,
        body: SafeArea(
          child: Stack(
            children: [
              // Remote video fills the screen; the local preview floats above it.
              if (isVideo && _call.remoteHasVideo)
                Positioned.fill(child: _remoteView())
              else
                Positioned.fill(child: _audioStage()),
              if (isVideo && _call.remoteHasVideo)
                Positioned(top: 60, right: 16, child: _localPreview()),
              Positioned(top: 12, left: 16, right: 16, child: _header()),
              Positioned(left: 0, right: 0, bottom: 0, child: _controls(isVideo)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _remoteView() {
    if (!_call.remoteRenderer.isReady) {
      return const ColoredBox(color: AppColors.navyDark);
    }
    return RTCVideoView(
      _call.remoteRenderer.renderer,
      objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover,
    );
  }

  Widget _audioStage() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 108,
            height: 108,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: AppColors.brandHero,
              boxShadow: [
                BoxShadow(color: Colors.black.withValues(alpha: 0.25), blurRadius: 24),
              ],
            ),
            child: Center(
              child: Text(
                _initials(widget.peerName),
                style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w800),
              ),
            ),
          ),
          const SizedBox(height: 20),
          Text(
            widget.peerName.isEmpty ? 'استشارة' : widget.peerName,
            style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            _statusLine(),
            style: TextStyle(color: Colors.white.withValues(alpha: 0.8), fontSize: 14),
          ),
        ],
      ),
    );
  }

  Widget _header() {
    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                widget.callType == 'video' ? 'مكالمة فيديو' : 'مكالمة صوتية',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 2),
              Text(
                _statusLine(),
                style: TextStyle(color: Colors.white.withValues(alpha: 0.8), fontSize: 12.5),
              ),
            ],
          ),
        ),
        if (_call.phase == CallPhase.connected)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.16),
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Text(
              _elapsedLabel(),
              style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w700),
            ),
          ),
      ],
    );
  }

  Widget _localPreview() {
    return ClipRRect(
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Container(
        width: 104,
        height: 150,
        color: Colors.black26,
        child: _call.localRenderer.isReady
            ? RTCVideoView(
                _call.localRenderer.renderer,
                objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover,
                mirror: true,
              )
            : const Center(child: Icon(Icons.person, color: Colors.white54)),
      ),
    );
  }

  Widget _controls(bool isVideo) {
    final inSession = _call.phase == CallPhase.connected || _call.phase == CallPhase.connecting;
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 30),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Colors.transparent, Colors.black.withValues(alpha: 0.65)],
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_call.error != null) ...[
            Text(
              _call.error!,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white, fontSize: 13.5),
            ),
            const SizedBox(height: 16),
          ],
          Wrap(
            alignment: WrapAlignment.center,
            spacing: 18,
            runSpacing: 14,
            children: [
              _RoundButton(
                icon: _call.muted ? Icons.mic_off : Icons.mic,
                label: _call.muted ? 'إلغاء الكتم' : 'كتم',
                active: _call.muted,
                onTap: inSession ? _call.toggleMute : null,
              ),
              if (isVideo)
                _RoundButton(
                  icon: _call.cameraOff ? Icons.videocam_off : Icons.videocam,
                  label: _call.cameraOff ? 'تشغيل الكاميرا' : 'إيقاف الكاميرا',
                  active: _call.cameraOff,
                  onTap: inSession ? _call.toggleCamera : null,
                ),
              if (isVideo && _call.canSwitchCamera)
                _RoundButton(
                  icon: Icons.cameraswitch,
                  label: 'تبديل الكاميرا',
                  onTap: _call.switchCamera,
                ),
              _RoundButton(
                icon: _call.speakerOn ? Icons.volume_up : Icons.volume_off,
                label: _call.speakerOn ? 'مكبر الصوت' : 'السماعة',
                active: !_call.speakerOn,
                onTap: _call.toggleSpeaker,
              ),
              _RoundButton(
                icon: Icons.call_end,
                label: 'إنهاء',
                danger: true,
                onTap: () async {
                  final navigator = Navigator.of(context);
                  await _call.hangUp();
                  if (mounted) navigator.maybePop();
                },
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _elapsedLabel() {
    final d = _call.elapsed;
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  String _statusLine() {
    switch (_call.phase) {
      case CallPhase.preparing:
        return 'جارٍ التحضير…';
      case CallPhase.connecting:
        return 'جارٍ الاتصال…';
      case CallPhase.connected:
        return 'متصل';
      case CallPhase.ended:
        return _call.error ?? 'انتهت المكالمة';
      case CallPhase.failed:
        return _call.error ?? 'تعذّر الاتصال';
    }
  }

  static String _initials(String name) {
    final trimmed = name.trim();
    if (trimmed.isEmpty) return '؟';
    return String.fromCharCode(trimmed.runes.first);
  }
}

/// One round control in the call bar.
class _RoundButton extends StatelessWidget {
  const _RoundButton({
    required this.icon,
    required this.label,
    required this.onTap,
    this.active = false,
    this.danger = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool active;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final background = danger
        ? AppColors.danger
        : active
            ? Colors.white
            : Colors.white.withValues(alpha: 0.18);
    final foreground = danger
        ? Colors.white
        : active
            ? AppColors.navyDark
            : Colors.white;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        // 56px: comfortably above the 44px minimum, which matters for a control used
        // one-handed while the screen is held to the ear.
        SizedBox(
          width: 56,
          height: 56,
          child: Material(
            color: background,
            shape: const CircleBorder(),
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: onTap,
              child: Icon(icon, color: foreground, size: 24),
            ),
          ),
        ),
        const SizedBox(height: 6),
        Text(label, style: const TextStyle(color: Colors.white, fontSize: 11.5)),
      ],
    );
  }
}
