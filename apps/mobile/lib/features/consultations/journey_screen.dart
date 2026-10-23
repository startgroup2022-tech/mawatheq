import 'dart:io';
import 'dart:math';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/notifications/reminder_service.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/specialty_icons.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../../shared/widgets/capture_sheet.dart';
import '../assistant/assistant_screen.dart';
import '../public/public_info_screen.dart';

/// رحلة الاستشارة على الجوال — the same journey the website runs.
///
/// specialty → issue type → lawyer comparison → booking. It calls the shared
/// `/api/v1/journey.php` endpoints, so the two clients cannot drift apart, and the
/// internal legal classification never crosses the wire: the customer only ever
/// sees the plain-language specialty and issue type they picked.
class ConsultationJourneyScreen extends StatefulWidget {
  const ConsultationJourneyScreen({
    super.key,
    this.repository,
    this.initialSpecialty,
  });

  /// Injectable for widget tests; production builds create their own.
  final ApiRepository? repository;

  /// A specialty the customer already chose on the home screen. When set, the
  /// journey opens on the issue-type step instead of asking them to pick the same
  /// specialty a second time.
  final JourneySpecialty? initialSpecialty;

  @override
  State<ConsultationJourneyScreen> createState() =>
      _ConsultationJourneyScreenState();
}

enum _Step { specialty, issueType, lawyers }

/// The two halves of booking, kept as separate steps so the customer chooses the
/// appointment before being asked for the topic and attachments. Combining them
/// made one long form where the time — the decision that matters most — competed
/// with everything else on the screen.
enum _BookingStep { appointment, confirm }

class _ConsultationJourneyScreenState extends State<ConsultationJourneyScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  _Step _step = _Step.specialty;
  bool _loading = true;
  String? _error;

  List<JourneySpecialty> _specialties = const [];
  List<JourneyIssueType> _issueTypes = const [];
  JourneyMatch _match = const JourneyMatch();

  JourneySpecialty? _chosenSpecialty;
  JourneyIssueType? _chosenIssueType;

  @override
  void initState() {
    super.initState();
    final preselected = widget.initialSpecialty;
    if (preselected != null) {
      // The customer already picked this specialty on the home screen, so start at
      // the sub-specialty step. The specialty list is still loaded so going back
      // shows the full set rather than an empty step.
      _chosenSpecialty = preselected;
      _step = _Step.issueType;
      _loadSpecialties();
      _loadIssueTypes(preselected);
      return;
    }
    _loadSpecialties();
  }

  Future<void> _loadSpecialties() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final list = await _repo.journeySpecialties();
      if (!mounted) return;
      setState(() {
        _specialties = list;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  Future<void> _chooseSpecialty(JourneySpecialty s) async {
    setState(() {
      _chosenSpecialty = s;
      _step = _Step.issueType;
      _loading = true;
      _error = null;
      _issueTypes = const [];
    });
    await _loadIssueTypes(s);
  }

  /// Loads the plain-language issue types for one specialty. Shared by the
  /// specialty step and by the home screen's pre-scoped entry, so both reach the
  /// same sub-specialty list.
  Future<void> _loadIssueTypes(JourneySpecialty s) async {
    try {
      final list = await _repo.journeyIssueTypes(categoryId: s.id);
      if (!mounted) return;
      setState(() {
        _issueTypes = list;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  Future<void> _chooseIssueType(JourneyIssueType? issue) async {
    final specialty = _chosenSpecialty;
    if (specialty == null) return;
    setState(() {
      _chosenIssueType = issue;
      _step = _Step.lawyers;
      _loading = true;
      _error = null;
      _match = const JourneyMatch();
    });
    try {
      final match = await _repo.journeyLawyers(categoryId: specialty.id);
      if (!mounted) return;
      setState(() {
        _match = match;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  void _back() {
    setState(() {
      _error = null;
      if (_step == _Step.lawyers) {
        _step = _Step.issueType;
      } else if (_step == _Step.issueType) {
        _step = _Step.specialty;
        _chosenSpecialty = null;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: BrandAppBar(
        subtitle: 'احجز استشارتك',
        leading: _step == _Step.specialty
            ? null
            : IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: _back,
              ),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 5),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _retryCurrent)
              : _body(),
    );
  }

  void _retryCurrent() {
    switch (_step) {
      case _Step.specialty:
        _loadSpecialties();
      case _Step.issueType:
        if (_chosenSpecialty != null) _chooseSpecialty(_chosenSpecialty!);
      case _Step.lawyers:
        _chooseIssueType(_chosenIssueType);
    }
  }

  Widget _body() {
    switch (_step) {
      case _Step.specialty:
        return _specialtyStep();
      case _Step.issueType:
        return _issueTypeStep();
      case _Step.lawyers:
        return _lawyerStep();
    }
  }

  Widget _specialtyStep() {
    if (_specialties.isEmpty) {
      return const EmptyState(
        icon: Icons.gavel_outlined,
        title: 'لا توجد تخصصات متاحة',
        message: 'سيتم إضافة التخصصات قريباً.',
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
      itemCount: _specialties.length + 1,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) {
        if (i == 0) return const _StepHeader(step: 1, title: 'ما نوع مشكلتك القانونية؟');
        final s = _specialties[i - 1];
        return _ChoiceCard(
          icon: specialtyIcon(s.iconKey),
          title: s.name,
          subtitle: s.description,
          footnote: s.hasLawyers
              ? '${s.lawyerCount} محامٍ متاح'
              : 'سيتم ترشيح محامٍ لك',
          onTap: () => _chooseSpecialty(s),
        );
      },
    );
  }

  Widget _issueTypeStep() {
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
      itemCount: _issueTypes.length + 2,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) {
        if (i == 0) {
          return _StepHeader(
            step: 2,
            title: 'حدّد نوع المشكلة',
            subtitle: _chosenSpecialty?.name,
          );
        }
        if (i == _issueTypes.length + 1) {
          // "لست متأكداً" — the customer is never blocked by not knowing the term.
          return _ChoiceCard(
            icon: Icons.help_outline,
            title: 'لست متأكداً من نوع المشكلة',
            subtitle: 'اختر هذا الخيار وسنساعدك في التصنيف.',
            onTap: () => _chooseIssueType(null),
          );
        }
        final t = _issueTypes[i - 1];
        return _ChoiceCard(
          icon: Icons.description_outlined,
          title: t.name,
          subtitle: t.definition,
          footnote: t.group,
          onTap: () => _chooseIssueType(t),
        );
      },
    );
  }

  Widget _lawyerStep() {
    if (_match.lawyers.isEmpty) {
      return _NoLawyerState(
        alternative: _match.alternative,
        onAskAssistant: _openAssistant,
        onContact: _openContact,
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
      itemCount: _match.lawyers.length + 1,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) {
        if (i == 0) {
          return _StepHeader(
            step: 3,
            title: 'اختر محاميك',
            subtitle: _chosenSpecialty?.name,
          );
        }
        return _LawyerChoiceCard(
          lawyer: _match.lawyers[i - 1],
          onTap: () => _startBooking(_match.lawyers[i - 1]),
        );
      },
    );
  }

  /// Opens the AI assistant from the empty state. The assistant reports its own
  /// honest unavailable state when no provider is configured; the empty state never
  /// claims an answer is possible.
  void _openAssistant() {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const AssistantScreen()),
    );
  }

  /// Opens the contact/support page. A customer who found no lawyer needs a human
  /// route, not a dead end.
  void _openContact() {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => const PublicInfoScreen(section: InfoSection.contact),
      ),
    );
  }

  /// Opens the booking screen carrying the journey choice, so the consultation is
  /// stored with the customer's specialty and issue type.
  void _startBooking(LawyerProfile lawyer) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => _JourneyBookingScreen(
          repository: _repo,
          lawyer: lawyer,
          specialty: _chosenSpecialty!,
          issueType: _chosenIssueType,
        ),
      ),
    );
  }
}

/// The no-lawyer empty state for step 3.
///
/// A specialty with no approved lawyer is a normal outcome, not an error: the
/// `lawyer_specialties` table ships empty, so a fresh deployment has none. Showing a
/// bare "nothing here" left the customer at a dead end, which is worse than the
/// problem. This keeps the headline the journey already used and adds the two routes
/// out — ask the assistant, or contact the firm — as real buttons rather than a
/// sentence telling them to do something the app does not offer.
///
/// The copy is Arabic-only, like the rest of the journey screen: the flow is driven
/// by the Arabic taxonomy and has no English variant.
class _NoLawyerState extends StatelessWidget {
  const _NoLawyerState({
    required this.alternative,
    required this.onAskAssistant,
    required this.onContact,
  });

  /// The server's own explanation, when it sent one. Rendered verbatim so the app
  /// does not invent a reason the backend did not give.
  final String alternative;

  final VoidCallback onAskAssistant;
  final VoidCallback onContact;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.s6),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 68,
              height: 68,
              decoration: BoxDecoration(
                color: context.tokens.infoBg,
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.search_off, size: 30, color: AppColors.teal),
            ),
            const SizedBox(height: AppSpacing.s4),
            Text(
              'لا يوجد محامٍ متاح حالياً',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: AppSpacing.s2),
            Text(
              alternative.isNotEmpty
                  ? alternative
                  : 'جرّب تخصصاً آخر أو تواصل معنا لترشيح محامٍ مناسب.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: AppSpacing.s5),
            // Both routes are real: the assistant opens the AI screen, contact opens
            // the support page. Neither is a placeholder.
            PrimaryButton(
              label: 'اسأل المساعد الذكي',
              icon: Icons.auto_awesome_outlined,
              onPressed: onAskAssistant,
              expand: true,
            ),
            const SizedBox(height: AppSpacing.s3),
            SecondaryButton(
              label: 'تواصل معنا',
              icon: Icons.support_agent_outlined,
              onPressed: onContact,
              expand: true,
            ),
          ],
        ),
      ),
    );
  }
}

/// The booking form at the end of the journey: date, time and the written topic.
class _JourneyBookingScreen extends StatefulWidget {
  const _JourneyBookingScreen({
    required this.repository,
    required this.lawyer,
    required this.specialty,
    required this.issueType,
  });

  final ApiRepository repository;
  final LawyerProfile lawyer;
  final JourneySpecialty specialty;
  final JourneyIssueType? issueType;

  @override
  State<_JourneyBookingScreen> createState() => _JourneyBookingScreenState();
}

class _JourneyBookingScreenState extends State<_JourneyBookingScreen> {
  final _topic = TextEditingController();
  DateTime _date = DateTime.now().add(const Duration(days: 1));
  String? _slot;
  List<String> _slots = const [];
  bool _loadingSlots = false;
  bool _submitting = false;
  String? _error;

  /// The token this form's recordings/documents are staged against. Generated once per
  /// booking form: the files have to exist somewhere before the consultation does, and
  /// the booking POST binds them by carrying the same token.
  late final String _draftToken = _generateDraftToken();
  List<StagedAttachment> _attachments = const [];
  List<String> _allowedExtensions = const ['pdf', 'png', 'jpg', 'jpeg', 'mp3', 'wav', 'm4a', 'ogg', 'mp4', 'mov', 'webm'];
  int _maxFiles = 0;
  bool _uploading = false;

  /// Which half of booking the customer is on. The appointment step is shown
  /// first so the time is chosen before the description is written.
  _BookingStep _bookingStep = _BookingStep.appointment;

  /// Whether the customer wants a reminder for this appointment. On by default:
  /// a booked consultation the customer forgets is the failure this prevents.
  /// The outcome of scheduling is carried to the confirmation dialog directly, so
  /// it is deliberately not held in a field.
  bool _reminderEnabled = true;

  static String _generateDraftToken() {
    // 32 random bytes as 64 lowercase hex characters — the exact shape the endpoint
    // validates (`/^[a-f0-9]{64}$/`) and unguessable enough to stand alone.
    final rng = Random.secure();
    final bytes = List<int>.generate(32, (_) => rng.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  @override
  void initState() {
    super.initState();
    _loadSlots();
    _loadStaged();
  }

  @override
  void dispose() {
    _topic.dispose();
    super.dispose();
  }

  /// Pulls the server's own allow-list and any files already staged, so a resumed form
  /// (or a rebuild) never loses what the customer attached.
  Future<void> _loadStaged() async {
    try {
      final staged =
          await widget.repository.journeyStagedAttachments(draftToken: _draftToken);
      if (!mounted) return;
      setState(() {
        _attachments = staged.attachments;
        _allowedExtensions = staged.allowedExtensions.isEmpty
            ? _allowedExtensions
            : staged.allowedExtensions;
        _maxFiles = staged.maxFiles;
      });
    } on ApiException {
      // A failure here must not block the booking; the customer can still write the topic.
    }
  }

  Future<void> _attachFile() async {
    if (_uploading) return;
    if (_maxFiles > 0 && _attachments.length >= _maxFiles) {
      setState(() => _error = 'بلغت الحد الأقصى لعدد المرفقات ($_maxFiles).');
      return;
    }

    final picked = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: _allowedExtensions,
      withData: false,
    );
    final path = picked?.files.single.path;
    if (path == null || !mounted) return;

    await _uploadFile(File(path));
  }

  /// Records a voice note or captures a photo, then stages it like any other file.
  ///
  /// The sheet does the permission handling and returns only a real file, so this
  /// method never has to reason about a denied permission.
  Future<void> _captureMedia() async {
    if (_uploading) return;
    if (_maxFiles > 0 && _attachments.length >= _maxFiles) {
      setState(() => _error = 'بلغت الحد الأقصى لعدد المرفقات ($_maxFiles).');
      return;
    }

    final captured = await CaptureSheet.show(context);
    if (captured == null || !mounted) return;
    await _uploadFile(captured.file);
  }

  /// Stages one local file against the draft token and appends it to the list.
  /// Shared by the picker and the capture sheet so both paths enforce the same
  /// limit and surface the same server error.
  Future<void> _uploadFile(File file) async {
    setState(() {
      _uploading = true;
      _error = null;
    });
    try {
      final attachment = await widget.repository.uploadJourneyAttachment(
        file: file,
        draftToken: _draftToken,
      );
      if (!mounted) return;
      setState(() {
        _attachments = [..._attachments, attachment];
        _uploading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _uploading = false;
        _error = e.message;
      });
    }
  }

  Future<void> _removeAttachment(StagedAttachment attachment) async {
    try {
      await widget.repository.deleteJourneyAttachment(attachment.id);
      if (!mounted) return;
      setState(() {
        _attachments =
            _attachments.where((a) => a.id != attachment.id).toList();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    }
  }

  String get _dateParam =>
      '${_date.year.toString().padLeft(4, '0')}-${_date.month.toString().padLeft(2, '0')}-${_date.day.toString().padLeft(2, '0')}';

  Future<void> _loadSlots() async {
    setState(() {
      _loadingSlots = true;
      _slot = null;
      _slots = const [];
    });
    try {
      final slots = await widget.repository.availableSlots(
        lawyerId: widget.lawyer.id,
        date: _dateParam,
      );
      if (!mounted) return;
      setState(() {
        _slots = slots;
        _loadingSlots = false;
      });
    } on ApiException {
      if (!mounted) return;
      setState(() => _loadingSlots = false);
    }
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 60)),
    );
    if (picked != null) {
      setState(() => _date = picked);
      _loadSlots();
    }
  }

  Future<void> _submit() async {
    if (_slot == null) {
      setState(() => _error = 'اختر وقتاً متاحاً.');
      return;
    }
    // The server accepts a consultation with a written description OR at least one
    // staged file ("audio only" is a valid submission). Enforce the same rule here so
    // the customer is told before the request leaves the device.
    if (_topic.text.trim().isEmpty && _attachments.isEmpty) {
      setState(() => _error = 'اكتب وصفاً للاستشارة أو أرفق ملفاً واحداً على الأقل.');
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      final consultationId = await widget.repository.bookConsultation(
        lawyerId: widget.lawyer.id,
        consultationType: 'text',
        date: _dateParam,
        time: _slot!,
        topic: _topic.text.trim(),
        specialtyCategoryId: widget.specialty.id,
        issueTypeId: widget.issueType?.id,
        // Binds the recordings/documents staged above to the consultation the server
        // is about to create. With no attachments the server treats it as an ordinary
        // booking, so this is safe to always send.
        draftToken: _draftToken,
      );
      if (!mounted) return;

      // The booking is already made, so a reminder failure must not be reported as a
      // booking failure. The outcome is recorded and shown on the confirmation.
      ReminderStatus? reminder;
      if (_reminderEnabled && consultationId > 0) {
        reminder = await ReminderService.scheduleConsultationReminder(
          consultationId: consultationId,
          appointment: _appointmentDateTime,
          lawyerName: widget.lawyer.name,
        );
      }
      if (!mounted) return;

      await _showConfirmation(consultationId, reminder);
      if (!mounted) return;
      Navigator.of(context).popUntil((route) => route.isFirst);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = e.message;
      });
    }
  }

  /// The chosen date and slot as one local wall-clock instant, which is what both
  /// the reminder and the confirmation text need.
  DateTime get _appointmentDateTime {
    final parts = (_slot ?? '00:00').split(':');
    final hour = int.tryParse(parts.isNotEmpty ? parts[0] : '') ?? 0;
    final minute = int.tryParse(parts.length > 1 ? parts[1] : '') ?? 0;
    return DateTime(_date.year, _date.month, _date.day, hour, minute);
  }

  /// Confirms the booking in place, including what happened to the reminder. A
  /// snack bar is not enough: the customer needs to see the time they booked and
  /// whether a reminder was actually set.
  Future<void> _showConfirmation(int consultationId, ReminderStatus? reminder) {
    return showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('تم إرسال طلب الاستشارة'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('التاريخ: $_dateParam — الوقت: ${_slot ?? ''}'),
            const SizedBox(height: 8),
            Text('المحامي: ${widget.lawyer.name}'),
            if (reminder != null) ...[
              const SizedBox(height: 12),
              Text(
                ReminderService.describe(reminder, _appointmentDateTime),
                style: TextStyle(
                  fontSize: 12.5,
                  height: 1.6,
                  color: reminder == ReminderStatus.scheduled
                      ? context.tokens.successFg
                      : context.tokens.warningFg,
                ),
              ),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('حسناً'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final onAppointment = _bookingStep == _BookingStep.appointment;
    return Scaffold(
      appBar: BrandAppBar(
        subtitle: onAppointment ? 'اختر الموعد' : 'تأكيد الحجز',
        leading: onAppointment
            ? null
            : IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: () =>
                    setState(() => _bookingStep = _BookingStep.appointment),
              ),
      ),
      body: onAppointment ? _appointmentStep() : _confirmStep(),
    );
  }

  /// Step 4 of the journey: choose the day and the available time.
  Widget _appointmentStep() {
    final canContinue = _slot != null && !_loadingSlots;
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      children: [
        const _StepHeader(step: 4, title: 'اختر الموعد', subtitle: 'الخطوة الأخيرة قبل التأكيد'),
        const SizedBox(height: 6),
        _SummaryCard(
          label: 'موضوع الاستشارة',
          value: widget.specialty.name,
          secondary: widget.issueType?.name,
        ),
        const SizedBox(height: 12),
        _SummaryCard(label: 'المحامي', value: widget.lawyer.name),
        const SizedBox(height: 12),
        ListTile(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
            side: BorderSide(color: context.tokens.border),
          ),
          tileColor: context.tokens.surface,
          leading: const Icon(Icons.calendar_today_outlined),
          title: const Text('التاريخ'),
          subtitle: Text(_dateParam),
          trailing: const Icon(Icons.edit_calendar_outlined, size: 18),
          onTap: _pickDate,
        ),
        const SizedBox(height: 14),
        Text(
          'الأوقات المتاحة',
          style: TextStyle(
            fontWeight: FontWeight.w700,
            color: context.tokens.heading,
          ),
        ),
        const SizedBox(height: 8),
        if (_loadingSlots)
          const LoadingSkeleton(count: 2)
        else if (_slots.isEmpty)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.warningBg,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: context.tokens.warningBorder),
            ),
            child: Row(
              children: [
                Icon(Icons.event_busy_outlined,
                    size: 18, color: context.tokens.warningFg),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'لا توجد أوقات متاحة في هذا اليوم. اختر يوماً آخر.',
                    style: TextStyle(
                      fontSize: 12.5,
                      color: context.tokens.warningFg,
                      height: 1.6,
                    ),
                  ),
                ),
              ],
            ),
          )
        else
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _slots
                .map((s) => ChoiceChip(
                      label: Text(s),
                      selected: _slot == s,
                      showCheckmark: false,
                      onSelected: (_) => setState(() => _slot = s),
                    ))
                .toList(),
          ),
        const SizedBox(height: 22),
        PrimaryButton(
          label: 'متابعة',
          icon: Icons.arrow_forward,
          expand: true,
          onPressed: canContinue
              ? () => setState(() => _bookingStep = _BookingStep.confirm)
              : null,
        ),
      ],
    );
  }

  /// Step 5: the topic, attachments and reminder, then confirm.
  Widget _confirmStep() {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      children: [
        const _StepHeader(step: 5, title: 'تفاصيل الاستشارة', subtitle: 'ثم أكّد الحجز'),
        const SizedBox(height: 6),
        _SummaryCard(
          label: 'الموعد المختار',
          value: '$_dateParam — ${_slot ?? ''}',
          secondary: widget.lawyer.name,
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _topic,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'اكتب وصفاً موجزاً لمشكلتك (أو أرفق ملفاً)',
          ),
        ),
        const SizedBox(height: 16),
        _AttachmentsSection(
          attachments: _attachments,
          uploading: _uploading,
          atLimit: _maxFiles > 0 && _attachments.length >= _maxFiles,
          onAdd: _attachFile,
          onCapture: _captureMedia,
          onRemove: _removeAttachment,
        ),
        const SizedBox(height: 8),
        _ReminderToggle(
          enabled: _reminderEnabled,
          onChanged: (v) => setState(() => _reminderEnabled = v),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(_error!, style: TextStyle(color: context.tokens.dangerFg)),
        ],
        const SizedBox(height: 18),
        PrimaryButton(
          label: 'تأكيد الحجز',
          icon: Icons.check_circle_outline,
          expand: true,
          loading: _submitting,
          onPressed: _submit,
        ),
      ],
    );
  }
}

// ------------------------------------------------------------- building blocks

/// The recordings and documents the customer attaches to the consultation.
///
/// Files are staged server-side as they are picked (against the form's draft token),
/// so a large recording does not have to survive in memory until submit, and a file
/// the customer removes is gone from the server immediately. A consultation is valid
/// with only a recording and no written description, which is why this sits next to
/// the topic field rather than behind an "advanced" toggle.
class _AttachmentsSection extends StatelessWidget {
  const _AttachmentsSection({
    required this.attachments,
    required this.uploading,
    required this.atLimit,
    required this.onAdd,
    required this.onCapture,
    required this.onRemove,
  });

  final List<StagedAttachment> attachments;
  final bool uploading;
  final bool atLimit;
  final VoidCallback onAdd;
  final VoidCallback onCapture;
  final void Function(StagedAttachment) onRemove;

  @override
  Widget build(BuildContext context) {
    final disabled = uploading || atLimit;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'المرفقات والتسجيلات',
          style: TextStyle(fontWeight: FontWeight.w700, color: context.tokens.heading),
        ),
        const SizedBox(height: 4),
        Text(
          'سجّل ملاحظة صوتية أو التقط صورة لمستند، أو أرفق ملفاً من جهازك. '
          'الوصف المكتوب أو مرفق واحد على الأقل مطلوب.',
          style: TextStyle(fontSize: 12, color: context.tokens.textMuted),
        ),
        const SizedBox(height: 10),
        for (final attachment in attachments)
          _AttachmentTile(
            attachment: attachment,
            onRemove: () => onRemove(attachment),
          ),
        const SizedBox(height: 6),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: disabled ? null : onCapture,
                icon: const Icon(Icons.mic_none, size: 18),
                label: const Text('صوت أو صورة'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: disabled ? null : onAdd,
                icon: uploading
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.attach_file, size: 18),
                label: Text(
                  uploading
                      ? 'جارٍ الرفع…'
                      : atLimit
                          ? 'بلغت الحد الأقصى'
                          : 'إرفاق ملف',
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// The appointment reminder opt-in.
///
/// The switch states plainly that the reminder lives on the device, because the
/// platform has no server-side reminder service and claiming otherwise would be a
/// promise the product cannot keep.
class _ReminderToggle extends StatelessWidget {
  const _ReminderToggle({required this.enabled, required this.onChanged});

  final bool enabled;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    // Material rather than a decorated Container: SwitchListTile paints its
    // background and ink splash on the nearest Material ancestor, so a
    // DecoratedBox in between would hide both.
    return Material(
      color: context.tokens.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: context.tokens.border),
      ),
      child: SwitchListTile.adaptive(
        value: enabled,
        onChanged: onChanged,
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
        title: Text(
          'تذكير قبل الموعد',
          style: TextStyle(
            fontWeight: FontWeight.w700,
            fontSize: 14,
            color: context.tokens.heading,
          ),
        ),
        subtitle: Text(
          'يصلك تذكير على هذا الجهاز قبل الموعد بساعة.',
          style: TextStyle(fontSize: 12, color: context.tokens.textMuted),
        ),
        secondary: Icon(Icons.notifications_active_outlined,
            color: context.tokens.heading),
      ),
    );
  }
}

/// One staged file: the server's own category, name and size, with a remove action.
class _AttachmentTile extends StatelessWidget {
  const _AttachmentTile({required this.attachment, required this.onRemove});

  final StagedAttachment attachment;
  final VoidCallback onRemove;

  IconData get _icon {
    switch (attachment.category) {
      case 'audio':
        return Icons.mic_none;
      case 'video':
        return Icons.videocam_outlined;
      case 'image':
        return Icons.image_outlined;
      default:
        return Icons.description_outlined;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        border: Border.all(color: context.tokens.border),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(_icon, size: 20, color: context.tokens.heading),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  attachment.originalName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontWeight: FontWeight.w600, color: context.tokens.heading),
                ),
                if (attachment.sizeLabel.isNotEmpty)
                  Text(
                    attachment.sizeLabel,
                    style: TextStyle(
                        fontSize: 11, color: context.tokens.textMuted),
                  ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'إزالة',
            icon: const Icon(Icons.close, size: 18),
            onPressed: onRemove,
          ),
        ],
      ),
    );
  }
}

/// A labelled summary block — used on the confirmation screen to restate the
/// customer's own words for what they chose.
class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.label, required this.value, this.secondary});

  final String label;
  final String value;
  final String? secondary;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        border: Border.all(color: context.tokens.border),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: context.tokens.textMuted)),
          const SizedBox(height: 6),
          Text(value,
              style: TextStyle(
                  fontWeight: FontWeight.w700, color: context.tokens.heading)),
          if (secondary != null && secondary!.isNotEmpty)
            Text(secondary!,
                style: TextStyle(color: context.tokens.textMuted)),
        ],
      ),
    );
  }
}

class _StepHeader extends StatelessWidget {
  const _StepHeader({required this.step, required this.title, this.subtitle});

  final int step;
  final String title;
  final String? subtitle;

  /// The journey has five steps: specialty → issue type → lawyer → appointment →
  /// details. Kept as one constant so the "of N" label cannot drift from the
  /// number of steps actually rendered.
  static const totalSteps = 5;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('الخطوة $step من $totalSteps',
              style: const TextStyle(
                  color: AppColors.teal,
                  fontSize: 12,
                  fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(title,
              style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: context.tokens.heading)),
          if (subtitle != null)
            Text(subtitle!,
                style: TextStyle(color: context.tokens.textMuted)),
        ],
      ),
    );
  }
}

class _ChoiceCard extends StatelessWidget {
  const _ChoiceCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.footnote,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final String? footnote;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: context.tokens.border),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 20,
                backgroundColor: AppColors.teal.withValues(alpha: 0.12),
                child: Icon(icon, color: AppColors.teal, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title,
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: context.tokens.heading)),
                    if (subtitle.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(subtitle,
                          style: TextStyle(
                              color: context.tokens.textMuted, fontSize: 13)),
                    ],
                    if (footnote != null && footnote!.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(footnote!,
                          style: TextStyle(
                              color: context.tokens.textSubtle, fontSize: 12)),
                    ],
                  ],
                ),
              ),
              Icon(Icons.chevron_left, color: context.tokens.textSubtle),
            ],
          ),
        ),
      ),
    );
  }
}

class _LawyerChoiceCard extends StatelessWidget {
  const _LawyerChoiceCard({required this.lawyer, required this.onTap});

  final LawyerProfile lawyer;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: context.tokens.border),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            children: [
              const CircleAvatar(
                radius: 22,
                backgroundColor: AppColors.navy,
                child: Icon(Icons.person, color: Colors.white),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(lawyer.name,
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: context.tokens.heading)),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        const Icon(Icons.star, size: 14, color: AppColors.gold),
                        const SizedBox(width: 4),
                        Text(lawyer.rating.toStringAsFixed(1),
                            style: TextStyle(
                                color: context.tokens.textMuted, fontSize: 12)),
                        if (lawyer.jurisdiction.isNotEmpty) ...[
                          const SizedBox(width: 10),
                          Flexible(
                            child: Text(lawyer.jurisdiction,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                    color: context.tokens.textSubtle, fontSize: 12)),
                          ),
                        ],
                      ],
                    ),
                  ],
                ),
              ),
              PrimaryButton(
                label: 'اختيار',
                onPressed: onTap,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

