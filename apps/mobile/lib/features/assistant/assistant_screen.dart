import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Legal assistant chat, backed by `POST /api/v1/ai-chat.php`.
///
/// Two honest behaviours matter here:
///  * When the platform reports the provider is not configured, the screen says
///    so and disables sending — it does not present a fake demo answer.
///  * When the laws library supplied a statutory reference, it is shown under the
///    answer. When it did not, no reference is invented.
class AssistantScreen extends StatefulWidget {
  const AssistantScreen({super.key});

  @override
  State<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends State<AssistantScreen> {
  final _repo = ApiRepository();
  final _input = TextEditingController();
  final _scroll = ScrollController();

  final List<_Message> _messages = [];
  AiStatus _status = AiStatus.unknown;
  bool _checking = true;
  bool _sending = false;
  int? _conversationId;

  @override
  void initState() {
    super.initState();
    _checkAvailability();
  }

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _checkAvailability() async {
    setState(() => _checking = true);
    try {
      final status = await _repo.aiStatus();
      if (!mounted) return;
      setState(() {
        _status = status;
        _checking = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _status = AiStatus.unknown;
        _checking = false;
      });
      if (e.kind == ApiErrorKind.dependency) {
        _showError(e.message);
      }
    }
  }

  Future<void> _send() async {
    final text = _input.text.trim();
    if (text.isEmpty || _sending) return;

    setState(() {
      _messages.add(_Message(text: text, isUser: true));
      _input.clear();
      _sending = true;
    });
    _scrollToEnd();

    try {
      final reply = await _repo.askAssistant(
        message: text,
        conversationId: _conversationId,
      );
      if (!mounted) return;
      setState(() {
        _conversationId = reply.conversationId;
        _messages.add(_Message(
          text: reply.reply.isEmpty
              ? 'لم يصل نص الإجابة. حاول إعادة صياغة السؤال.'
              : reply.reply,
          isUser: false,
          reference: reply.hasReference ? reply.reference : null,
        ));
        _sending = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _messages.add(_Message(
          text: e.message,
          isUser: false,
          isError: true,
        ));
      });
      // A 503 means the provider is down right now; re-check so the banner and
      // the send button reflect reality.
      if (e.kind == ApiErrorKind.dependency) _checkAvailability();
    }
    _scrollToEnd();
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 260),
          curve: Curves.easeOut,
        );
      }
    });
  }

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) {
      return const Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            CircularProgressIndicator(strokeWidth: 2.2),
            SizedBox(height: 14),
            Text('جارٍ التحقق من توفّر المساعد…'),
          ],
        ),
      );
    }

    return Column(
      children: [
        if (!_status.available)
          Container(
            width: double.infinity,
            margin: const EdgeInsets.fromLTRB(16, 14, 16, 0),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.warningBg,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: const Color(0xFFF3E0BE)),
            ),
            child: Row(
              children: [
                const Icon(Icons.construction_outlined,
                    size: 18, color: AppColors.warning),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'المساعد الذكي غير متاح حالياً. ${_status.reason}',
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: AppColors.warning,
                      height: 1.65,
                    ),
                  ),
                ),
              ],
            ),
          ),

        Expanded(
          child: _messages.isEmpty
              ? EmptyState(
                  icon: Icons.auto_awesome_outlined,
                  title: 'المساعد القانوني الذكي',
                  message: _status.available
                      ? 'اطرح سؤالاً قانونياً، وسيبحث المساعد في مكتبة الأنظمة قبل الإجابة.'
                      : 'سيظهر هنا حوارك مع المساعد بعد تهيئة المزوّد من لوحة الإدارة.',
                )
              : ListView.builder(
                  controller: _scroll,
                  padding: const EdgeInsets.all(16),
                  itemCount: _messages.length + (_sending ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (index == _messages.length) {
                      return const Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: Padding(
                          padding: EdgeInsets.all(8),
                          child: SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        ),
                      );
                    }
                    final m = _messages[index];
                    return m.isError
                        ? _errorBubble(m.text)
                        : AIChatBubble(
                            message: m.text,
                            isUser: m.isUser,
                            reference: m.reference,
                          );
                  },
                ),
        ),

        SafeArea(
          top: false,
          child: Container(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
            decoration: const BoxDecoration(
              color: AppColors.surface,
              border: Border(top: BorderSide(color: AppColors.border)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _input,
                    enabled: _status.available && !_sending,
                    minLines: 1,
                    maxLines: 4,
                    textInputAction: TextInputAction.send,
                    onSubmitted: (_) => _send(),
                    decoration: InputDecoration(
                      hintText: _status.available
                          ? 'اكتب سؤالك القانوني…'
                          : 'المساعد غير متاح حالياً',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.filled(
                  onPressed: (_status.available && !_sending) ? _send : null,
                  style: IconButton.styleFrom(
                    backgroundColor: AppColors.teal,
                    disabledBackgroundColor: AppColors.surface3,
                  ),
                  icon: const Icon(Icons.send, size: 20),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _errorBubble(String text) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Container(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.of(context).size.width * 0.82,
        ),
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppColors.dangerBg,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(color: const Color(0xFFF2CFC4)),
        ),
        child: Row(
          children: [
            const Icon(Icons.error_outline, size: 16, color: AppColors.danger),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                text,
                style: const TextStyle(
                  fontSize: 13,
                  color: AppColors.danger,
                  height: 1.7,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Message {
  _Message({
    required this.text,
    required this.isUser,
    this.reference,
    this.isError = false,
  });

  final String text;
  final bool isUser;
  final String? reference;
  final bool isError;
}
