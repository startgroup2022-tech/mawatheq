import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';
import '../../core/theme/app_colors.dart';
import 'call_screen.dart';

/// Watches for an incoming call and raises the ringing screen.
///
/// Wrapping a shell body means every signed-in surface — the customer shell and the
/// lawyer shell — gets incoming calls without either of them re-implementing the
/// poll. Only one watcher is active at a time (the shell is a single widget tree), so
/// a call cannot be raised twice.
///
/// ## Why the ring is a route, not an overlay
///
/// A ringing call must be visible and answerable from wherever the user is. Pushing a
/// full-screen route does that without touching the shell's layout, and it gives the
/// screen its own back-stack entry, so "decline" returns the user exactly where they
/// were.
class IncomingCallWatcher extends StatefulWidget {
  const IncomingCallWatcher({super.key, required this.child, this.repository});

  final Widget child;
  final ApiRepository? repository;

  @override
  State<IncomingCallWatcher> createState() => _IncomingCallWatcherState();
}

class _IncomingCallWatcherState extends State<IncomingCallWatcher> with WidgetsBindingObserver {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  Timer? _timer;
  bool _showing = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // 5 seconds: an incoming call is not latency-critical to *discover* — the caller
    // is ringing for up to 45 seconds — and a tighter loop would wake the radio
    // constantly for a feature that is idle almost all the time.
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _check());
    _check();
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Resuming is the moment a call most likely arrived while the app was backgrounded,
    // so check immediately rather than waiting for the next tick.
    if (state == AppLifecycleState.resumed) _check();
  }

  Future<void> _check() async {
    if (_showing || !mounted) return;
    try {
      final call = await _repo.incomingCall();
      if (call == null || !mounted || _showing) return;
      // A call the server says is ringing may have been answered or ended between the
      // poll and the route push; the ringing screen re-checks on open.
      _showing = true;
      await Navigator.of(context).push(
        MaterialPageRoute(
          fullscreenDialog: true,
          builder: (_) => IncomingCallScreen(
            repository: _repo,
            call: call,
            onDismissed: () => _showing = false,
          ),
        ),
      );
      _showing = false;
    } on ApiException {
      // A failed poll is not an error worth showing: the next tick retries.
    } catch (_) {
      // Same.
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

/// The ringing screen: who is calling, answer or decline.
class IncomingCallScreen extends StatefulWidget {
  const IncomingCallScreen({
    super.key,
    required this.repository,
    required this.call,
    this.onDismissed,
  });

  final ApiRepository repository;
  final CallSession call;
  final VoidCallback? onDismissed;

  @override
  State<IncomingCallScreen> createState() => _IncomingCallScreenState();
}

class _IncomingCallScreenState extends State<IncomingCallScreen> {
  bool _busy = false;
  String? _error;

  String get _peerName {
    final c = widget.call;
    // The caller is the other party, so show whichever name is not mine.
    if (c.initiatedBy == 'client') return c.clientName;
    return c.lawyerName;
  }

  Future<void> _answer() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.repository.acceptCall(widget.call.id);
      final join = await widget.repository.callToken(widget.call.id);
      if (!mounted) return;
      // Pop the ring first so the call screen sits on top of the shell, then open it
      // on the root navigator. Opening it before the pop would leave the ring under
      // the call and the user would have to dismiss it twice.
      Navigator.of(context).pop();
      widget.onDismissed?.call();
      final rootNavigator = Navigator.of(context, rootNavigator: true);
      await rootNavigator.push(
        MaterialPageRoute(
          builder: (_) => callScreenFor(
            repository: widget.repository,
            join: join,
            callType: widget.call.callType,
            peerName: _peerName,
            outgoing: false,
          ),
        ),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = e.message;
      });
    }
  }

  Future<void> _decline() async {
    setState(() => _busy = true);
    try {
      await widget.repository.rejectCall(widget.call.id);
    } on ApiException {
      // Declining locally is the user's intent; a failed notification does not keep
      // the ring on screen.
    }
    if (!mounted) return;
    Navigator.of(context).pop();
    widget.onDismissed?.call();
  }

  @override
  Widget build(BuildContext context) {
    final isVideo = widget.call.isVideo;
    return PopScope(
      // Back must decline, not silently dismiss — otherwise the caller keeps ringing
      // at a user who has clearly walked away from the screen.
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        _decline();
      },
      child: Scaffold(
        backgroundColor: AppColors.navyDark,
        body: SafeArea(
          child: Column(
            children: [
              const Spacer(flex: 2),
              Container(
                width: 118,
                height: 118,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: AppColors.brandHero,
                  boxShadow: [
                    BoxShadow(color: Colors.black.withValues(alpha: 0.3), blurRadius: 30),
                  ],
                ),
                child: Center(
                  child: Text(
                    _initials(_peerName),
                    style: const TextStyle(color: Colors.white, fontSize: 38, fontWeight: FontWeight.w800),
                  ),
                ),
              ),
              const SizedBox(height: 22),
              Text(
                _peerName.isEmpty ? 'مكالمة واردة' : _peerName,
                style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              Text(
                isVideo ? 'مكالمة فيديو واردة' : 'مكالمة صوتية واردة',
                style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 14),
              ),
              if (widget.call.topic.isNotEmpty) ...[
                const SizedBox(height: 6),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 32),
                  child: Text(
                    widget.call.topic,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: Colors.white.withValues(alpha: 0.7), fontSize: 13),
                  ),
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: 14),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 32),
                  child: Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: AppColors.danger, fontSize: 13),
                  ),
                ),
              ],
              const Spacer(flex: 3),
              Padding(
                padding: const EdgeInsets.only(bottom: 44),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    _CallActionButton(
                      icon: Icons.call_end,
                      label: 'رفض',
                      color: AppColors.danger,
                      onTap: _busy ? null : _decline,
                    ),
                    _CallActionButton(
                      icon: isVideo ? Icons.videocam : Icons.call,
                      label: 'قبول',
                      color: AppColors.success,
                      onTap: _busy ? null : _answer,
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static String _initials(String name) {
    final trimmed = name.trim();
    if (trimmed.isEmpty) return '؟';
    return String.fromCharCode(trimmed.runes.first);
  }
}

class _CallActionButton extends StatelessWidget {
  const _CallActionButton({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 72,
          height: 72,
          child: Material(
            color: onTap == null ? color.withValues(alpha: 0.5) : color,
            shape: const CircleBorder(),
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: onTap,
              child: Icon(icon, color: Colors.white, size: 30),
            ),
          ),
        ),
        const SizedBox(height: 10),
        Text(label, style: const TextStyle(color: Colors.white, fontSize: 13.5, fontWeight: FontWeight.w700)),
      ],
    );
  }
}
