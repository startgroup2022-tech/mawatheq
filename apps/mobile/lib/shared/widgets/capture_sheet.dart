import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:record/record.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import 'brand_widgets.dart';

/// One file the customer produced with the device, ready to be staged.
class CapturedFile {
  const CapturedFile({required this.file, required this.kind});

  final File file;

  /// 'audio' for a voice note, 'image' for a camera capture. Matches the
  /// categories the attachment endpoint itself assigns.
  final String kind;
}

/// What the sheet is currently doing, so the UI can explain a refusal rather than
/// fail silently.
enum _CaptureState { idle, recording, busy }

/// Voice and camera capture for consultation attachments.
///
/// Both actions are permission-gated and explain themselves: a denied microphone
/// or camera shows the reason and offers to open the system settings, instead of
/// a button that appears to do nothing. On a platform without these capabilities
/// (widget tests, desktop) the sheet reports that plainly.
class CaptureSheet extends StatefulWidget {
  const CaptureSheet({super.key, this.imagePicker, this.recorder});

  /// Injectable for tests; production builds create their own.
  final ImagePicker? imagePicker;
  final AudioRecorder? recorder;

  /// Opens the sheet and resolves to the captured file, or null if cancelled.
  static Future<CapturedFile?> show(BuildContext context) {
    return showModalBottomSheet<CapturedFile>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const CaptureSheet(),
    );
  }

  @override
  State<CaptureSheet> createState() => _CaptureSheetState();
}

class _CaptureSheetState extends State<CaptureSheet> {
  late final ImagePicker _picker = widget.imagePicker ?? ImagePicker();
  late final AudioRecorder _recorder = widget.recorder ?? AudioRecorder();

  _CaptureState _state = _CaptureState.idle;
  String? _notice;

  /// Elapsed recording time, shown while recording so the customer can see the
  /// note is being captured and how long it has run.
  Timer? _ticker;
  Duration _elapsed = Duration.zero;

  static const _maxRecording = Duration(minutes: 10);

  bool get _canCapture =>
      !kIsWeb && (Platform.isAndroid || Platform.isIOS);

  @override
  void dispose() {
    _ticker?.cancel();
    // A sheet dismissed mid-recording must not leave the microphone open.
    _recorder.dispose();
    super.dispose();
  }

  void _setNotice(String? message) {
    if (!mounted) return;
    setState(() => _notice = message);
  }

  // --------------------------------------------------------------- camera

  Future<void> _capturePhoto() async {
    if (!_canCapture) {
      _setNotice('التقاط الصور غير متاح على هذا الجهاز.');
      return;
    }
    setState(() {
      _state = _CaptureState.busy;
      _notice = null;
    });
    try {
      // The camera permission is requested by the platform through image_picker on
      // first use. A denial surfaces as a PlatformException, which is turned into
      // an actionable message rather than a crash.
      final shot = await _picker.pickImage(
        source: ImageSource.camera,
        imageQuality: 85,
        maxWidth: 2400,
      );
      if (shot == null) {
        if (mounted) setState(() => _state = _CaptureState.idle);
        return;
      }
      final file = File(shot.path);
      if (!await file.exists()) {
        if (mounted) setState(() => _state = _CaptureState.idle);
        _setNotice('تعذّر الوصول إلى الصورة الملتقطة.');
        return;
      }
      if (!mounted) return;
      Navigator.of(context).pop(CapturedFile(file: file, kind: 'image'));
    } catch (e) {
      if (mounted) setState(() => _state = _CaptureState.idle);
      _setNotice(_cameraError(e));
    }
  }

  /// Turns a platform refusal into a sentence the customer can act on.
  String _cameraError(Object error) {
    final text = error.toString().toLowerCase();
    if (text.contains('camera_access_denied') || text.contains('permission')) {
      return 'لم يُمنح إذن الكاميرا. يمكنك تفعيله من إعدادات التطبيق.';
    }
    if (text.contains('no_available_camera')) {
      return 'لا توجد كاميرا متاحة على هذا الجهاز.';
    }
    return 'تعذّر التقاط الصورة. حاول مرة أخرى.';
  }

  // --------------------------------------------------------------- recorder

  Future<void> _startRecording() async {
    if (!_canCapture) {
      _setNotice('تسجيل الصوت غير متاح على هذا الجهاز.');
      return;
    }
    setState(() {
      _notice = null;
      _state = _CaptureState.busy;
    });

    // Ask before touching the recorder: on Android the plugin throws if the
    // permission is absent, and a thrown error is a worse experience than a prompt.
    final granted = await _ensureMicPermission();
    if (!granted) {
      if (mounted) setState(() => _state = _CaptureState.idle);
      _setNotice('لم يُمنح إذن الميكروفون. يمكنك تفعيله من إعدادات التطبيق.');
      return;
    }

    try {
      if (!await _recorder.hasPermission()) {
        if (mounted) setState(() => _state = _CaptureState.idle);
        _setNotice('لم يُمنح إذن الميكروفون. يمكنك تفعيله من إعدادات التطبيق.');
        return;
      }

      final dir = await getTemporaryDirectory();
      final path =
          '${dir.path}/mawatheq_note_${DateTime.now().millisecondsSinceEpoch}.m4a';

      // AAC-LC in an m4a container is what the attachment endpoint accepts and what
      // both platforms encode natively, so no transcoding is needed.
      await _recorder.start(
        const RecordConfig(encoder: AudioEncoder.aacLc, bitRate: 96000),
        path: path,
      );

      if (!mounted) return;
      setState(() {
        _state = _CaptureState.recording;
        _elapsed = Duration.zero;
      });
      _ticker?.cancel();
      _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
        if (!mounted) return;
        setState(() => _elapsed += const Duration(seconds: 1));
        // Stop at the cap so a forgotten recording cannot fill the device.
        if (_elapsed >= _maxRecording) _stopRecording();
      });
    } catch (_) {
      if (mounted) setState(() => _state = _CaptureState.idle);
      _setNotice('تعذّر بدء التسجيل. تأكد من عدم استخدام الميكروفون في تطبيق آخر.');
    }
  }

  Future<bool> _ensureMicPermission() async {
    try {
      final status = await Permission.microphone.status;
      if (status.isGranted) return true;
      final requested = await Permission.microphone.request();
      return requested.isGranted;
    } catch (_) {
      // permission_handler can be unavailable on a platform without a permission
      // model; fall through to the recorder's own check.
      return true;
    }
  }

  Future<void> _stopRecording() async {
    _ticker?.cancel();
    _ticker = null;
    if (mounted) setState(() => _state = _CaptureState.busy);

    try {
      final path = await _recorder.stop();
      if (path == null) {
        if (mounted) setState(() => _state = _CaptureState.idle);
        _setNotice('لم يتم حفظ التسجيل. حاول مرة أخرى.');
        return;
      }
      final file = File(path);
      final exists = await file.exists();
      final size = exists ? await file.length() : 0;
      // A zero-byte or sub-second note is a mis-tap, not a voice note. Saying so
      // is better than uploading an empty file the lawyer cannot play.
      if (!exists || size < 1024 || _elapsed < const Duration(seconds: 1)) {
        if (mounted) setState(() => _state = _CaptureState.idle);
        _setNotice('التسجيل قصير جداً. أعد التسجيل مع توضيح موضوعك.');
        return;
      }
      if (!mounted) return;
      Navigator.of(context).pop(CapturedFile(file: file, kind: 'audio'));
    } catch (_) {
      if (mounted) setState(() => _state = _CaptureState.idle);
      _setNotice('تعذّر حفظ التسجيل. حاول مرة أخرى.');
    }
  }

  Future<void> _cancelRecording() async {
    _ticker?.cancel();
    _ticker = null;
    try {
      await _recorder.cancel();
    } catch (_) {}
    if (!mounted) return;
    setState(() {
      _state = _CaptureState.idle;
      _elapsed = Duration.zero;
    });
  }

  // ------------------------------------------------------------------ build

  @override
  Widget build(BuildContext context) {
    final recording = _state == _CaptureState.recording;
    final busy = _state == _CaptureState.busy;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 20,
          right: 20,
          top: 18,
          bottom: 18 + MediaQuery.of(context).viewInsets.bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.add_a_photo_outlined,
                    color: context.tokens.heading, size: 20),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    recording ? 'جارٍ التسجيل…' : 'إضافة مرفق بالصوت أو الكاميرا',
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: context.tokens.heading,
                    ),
                  ),
                ),
                IconButton(
                  tooltip: 'إغلاق',
                  icon: const Icon(Icons.close, size: 20),
                  onPressed: busy
                      ? null
                      : () {
                          if (recording) {
                            _cancelRecording();
                          } else {
                            Navigator.of(context).pop();
                          }
                        },
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              recording
                  ? 'يمكنك الإيقاف في أي وقت. الحد الأقصى ١٠ دقائق.'
                  : 'سجّل ملاحظة صوتية تشرح موضوعك، أو التقط صورة لمستند. '
                      'يمكنك إرفاق ملفات أخرى من قسم المرفقات.',
              style: TextStyle(fontSize: 12.5, color: context.tokens.textMuted, height: 1.6),
            ),
            const SizedBox(height: 16),

            if (recording)
              _RecordingIndicator(elapsed: _elapsed)
            else
              Row(
                children: [
                  Expanded(
                    child: _CaptureAction(
                      icon: Icons.mic_none,
                      label: 'تسجيل صوتي',
                      enabled: !busy,
                      onTap: _startRecording,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _CaptureAction(
                      icon: Icons.photo_camera_outlined,
                      label: 'التقاط صورة',
                      enabled: !busy,
                      onTap: _capturePhoto,
                    ),
                  ),
                ],
              ),

            if (recording) ...[
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: PrimaryButton(
                      label: 'إيقاف وإرفاق',
                      icon: Icons.stop_circle_outlined,
                      expand: true,
                      onPressed: _stopRecording,
                    ),
                  ),
                  const SizedBox(width: 10),
                  SecondaryButton(label: 'إلغاء', onPressed: _cancelRecording),
                ],
              ),
            ],

            if (busy && !recording) ...[
              const SizedBox(height: 18),
              const Center(
                child: SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
            ],

            if (_notice != null) ...[
              const SizedBox(height: 14),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: context.tokens.warningBg,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: context.tokens.warningBorder),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.info_outline,
                        size: 17, color: context.tokens.warningFg),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _notice!,
                        style: TextStyle(
                          fontSize: 12,
                          color: context.tokens.warningFg,
                          height: 1.6,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
              TextButton.icon(
                icon: const Icon(Icons.settings_outlined, size: 16),
                label: const Text('فتح إعدادات التطبيق'),
                onPressed: () => openAppSettings(),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// A large tap target for one capture action, matching the home CTA styling.
class _CaptureAction extends StatelessWidget {
  const _CaptureAction({
    required this.icon,
    required this.label,
    required this.enabled,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: Material(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          onTap: enabled ? onTap : null,
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 18),
            decoration: BoxDecoration(
              border: Border.all(color: context.tokens.border),
              borderRadius: BorderRadius.circular(AppRadius.lg),
            ),
            child: Column(
              children: [
                Container(
                  width: 42,
                  height: 42,
                  decoration: BoxDecoration(
                    color: context.tokens.surface2,
                    borderRadius: BorderRadius.circular(AppRadius.sm),
                    border: Border.all(color: context.tokens.border),
                  ),
                  child: Icon(icon, size: 21, color: context.tokens.heading),
                ),
                const SizedBox(height: 8),
                Text(
                  label,
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                    color: context.tokens.text,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The live recording state: a pulsing dot and the elapsed time.
class _RecordingIndicator extends StatelessWidget {
  const _RecordingIndicator({required this.elapsed});

  final Duration elapsed;

  @override
  Widget build(BuildContext context) {
    final minutes = elapsed.inMinutes.toString().padLeft(2, '0');
    final seconds = (elapsed.inSeconds % 60).toString().padLeft(2, '0');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
      decoration: BoxDecoration(
        color: context.tokens.dangerBg,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.dangerBorder),
      ),
      child: Row(
        children: [
          Icon(Icons.fiber_manual_record,
              size: 14, color: context.tokens.dangerFg),
          const SizedBox(width: 10),
          Text(
            '$minutes:$seconds',
            style: TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 18,
              color: context.tokens.dangerFg,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              'يتم التسجيل الآن…',
              style: TextStyle(fontSize: 12.5, color: context.tokens.dangerFg),
            ),
          ),
        ],
      ),
    );
  }
}
