import 'dart:io';
import 'dart:math';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
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
import 'payment_webview_screen.dart';

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

  /// The professional booking filters, and the vocabulary they are built from. The
  /// options come from the server so the sheet can never offer a value the backend
  /// would ignore. Both are only needed on the lawyer step.
  JourneyFilters _filters = const JourneyFilters();
  JourneyFilterOptions _filterOptions = const JourneyFilterOptions();

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
      // A new specialty starts with a clean filter set; carrying the previous
      // specialty's filters over would silently narrow an unrelated search.
      _filters = const JourneyFilters();
    });
    // The filter vocabulary is loaded alongside the first result so the sheet is
    // ready when the customer opens it; a failure to load it never blocks the list.
    _loadFilterOptions();
    await _loadLawyers();
  }

  /// Fetch the lawyer list for the current specialty and filter set.
  Future<void> _loadLawyers() async {
    final specialty = _chosenSpecialty;
    if (specialty == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final match = await _repo.journeyLawyers(
        categoryId: specialty.id,
        filters: _filters,
      );
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

  Future<void> _loadFilterOptions() async {
    try {
      final options = await _repo.journeyFilters();
      if (!mounted) return;
      setState(() => _filterOptions = options);
    } on ApiException {
      // The filter sheet degrades to whatever vocabulary it already has rather than
      // turning the lawyer list into an error screen.
    }
  }

  /// Opens the filter sheet and applies the returned filters, if the customer
  /// confirms. A dismissed sheet leaves the current filters untouched.
  Future<void> _openFilters() async {
    final result = await showModalBottomSheet<JourneyFilters>(
      context: context,
      isScrollControlled: true,
      backgroundColor: context.tokens.surface,
      builder: (_) => _JourneyFilterSheet(
        initial: _filters,
        options: _filterOptions,
      ),
    );
    if (result == null) return;
    setState(() => _filters = result);
    await _loadLawyers();
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
      // A dead end otherwise: the customer opened booking and found nothing to
      // choose. Retry is the real fix when the list failed to load, and the contact
      // route is the honest answer when the platform genuinely has no published
      // specialty — either way the screen offers a way forward.
      return EmptyState(
        icon: Icons.gavel_outlined,
        title: 'لا توجد تخصصات متاحة',
        message: 'تعذّر عرض التخصصات الآن. أعد المحاولة، أو تواصل معنا وسنساعدك مباشرة.',
        actionLabel: 'إعادة المحاولة',
        onAction: _loadSpecialties,
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
    // The filters and the count are always reachable, even when the current filter
    // set excluded every lawyer — otherwise the customer would have no way back to
    // a wider search.
    return Column(
      children: [
        _JourneyFilterBar(
          active: !_filters.isEmpty,
          count: _match.count,
          onOpen: _openFilters,
          onClear: _filters.isEmpty
              ? null
              : () async {
                  setState(() => _filters = const JourneyFilters());
                  await _loadLawyers();
                },
        ),
        Expanded(child: _lawyerStepBody()),
      ],
    );
  }

  Widget _lawyerStepBody() {
    if (_match.lawyers.isEmpty) {
      // "Your filters excluded everyone" and "this specialty has no lawyer" are
      // different problems with different fixes, so they get different copy.
      if (_match.filteredOut || !_filters.isEmpty) {
        return EmptyState(
          icon: Icons.filter_alt_off_outlined,
          title: 'لا يوجد محامٍ يطابق الفلاتر',
          message: 'جرّب توسيع نطاق البحث أو مسح الفلاتر لعرض جميع المحامين لهذا التخصص.',
          actionLabel: 'مسح الفلاتر',
          onAction: () async {
            setState(() => _filters = const JourneyFilters());
            await _loadLawyers();
          },
        );
      }
      return NoLawyerState(
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

  /// The month the calendar is showing, and the server's real per-day availability for
  /// it. The grid draws only days the backend reports as bookable, so a customer never
  /// taps a day the lawyer is closed or fully booked.
  late DateTime _month = DateTime(_date.year, _date.month);
  MonthAvailability _monthData =
      const MonthAvailability(month: '', days: {}, workingWeekdays: [], hasHours: false);
  bool _loadingMonth = false;

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
    _loadMonth();
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

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  String get _monthParam =>
      '${_month.year.toString().padLeft(4, '0')}-${_month.month.toString().padLeft(2, '0')}';

  /// Loads the real per-day availability for the month the calendar is showing, so the
  /// grid only lights up days the backend says have open slots.
  Future<void> _loadMonth() async {
    setState(() => _loadingMonth = true);
    try {
      final data = await widget.repository.monthAvailability(
        lawyerId: widget.lawyer.id,
        month: _monthParam,
      );
      if (!mounted) return;
      setState(() {
        _monthData = data;
        _loadingMonth = false;
      });
      // If the currently-selected day has no slots in this month, move the selection to
      // the first bookable day so the customer is not shown an empty time list on open.
      final selIso = _dateParam;
      if (data.slotsOn(selIso) == 0) {
        final today = DateTime.now();
        final todayIso = _iso(DateTime(today.year, today.month, today.day));
        final firstBookable = data.days.entries
            .where((e) => e.value > 0 && e.key.compareTo(todayIso) >= 0)
            .map((e) => e.key)
            .toList()
          ..sort();
        if (firstBookable.isNotEmpty && firstBookable.first != selIso) {
          final parts = firstBookable.first.split('-');
          _date = DateTime(
              int.parse(parts[0]), int.parse(parts[1]), int.parse(parts[2]));
          _slot = null;
          _loadSlots();
        }
      }
    } on ApiException {
      if (!mounted) return;
      setState(() => _loadingMonth = false);
    }
  }

  /// Moves the calendar a month and reloads its availability. Never allows a month
  /// before the current one, since a past month can never be booked.
  Future<void> _shiftMonth(int delta) async {
    final next = DateTime(_month.year, _month.month + delta);
    final now = DateTime.now();
    if (next.year < now.year ||
        (next.year == now.year && next.month < now.month)) {
      return;
    }
    setState(() => _month = next);
    await _loadMonth();
  }

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

  /// Picks a day from the real calendar: only days the backend reports as bookable are
  /// enabled, so the customer cannot choose a closed or fully-booked day.
  void _pickDate(DateTime day) {
    setState(() {
      _date = day;
      _slot = null;
    });
    _loadSlots();
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
      final booking = await widget.repository.bookConsultation(
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
      final consultationId = booking.consultationId;

      // A priced consultation is confirmed only after payment. Open the gateway's hosted
      // page in a web view; whatever the customer does there, the server re-verifies the
      // capture independently, so the app never asserts "paid" on the web view's word.
      if (booking.paymentRequired && booking.paymentUrl != null) {
        final settled = await Navigator.of(context).push<bool>(
          MaterialPageRoute(
            builder: (_) => PaymentWebViewScreen(
              paymentUrl: booking.paymentUrl!,
              consultationId: consultationId,
            ),
          ),
        );
        if (!mounted) return;
        if (settled != true) {
          // The customer closed the web view before finishing. The booking exists and its
          // slot is reserved; tell them plainly and let the consultations list show the
          // real state rather than implying success.
          setState(() {
            _submitting = false;
            _error = 'لم يكتمل الدفع. يمكنك إتمامه من صفحة الاستشارات.';
          });
          return;
        }
      } else if (booking.paymentRequired && booking.unconfirmed) {
        // The booking was saved but the gateway could not be reached, so it is NOT confirmed.
        // Saying "booked" here is exactly the defect this execution fixes.
        setState(() {
          _submitting = false;
          _error = 'تم حفظ الطلب لكن تعذّر بدء الدفع، لذلك لم يُؤكَّد الموعد بعد. '
              'يمكنك إكمال الدفع من صفحة الاستشارات.';
        });
        return;
      }

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
        // The real availability calendar. Days come from the backend's month map, so a
        // closed day or a fully-booked day is not selectable — the customer can only tap
        // a day the lawyer can actually take.
        _BookingCalendar(
          month: _month,
          monthData: _monthData,
          loading: _loadingMonth,
          selected: _date,
          onSelect: _pickDate,
          onPrev: () => _shiftMonth(-1),
          onNext: () => _shiftMonth(1),
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
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _LawyerAvatar(lawyer: lawyer),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(lawyer.name,
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: context.tokens.heading)),
                    if (lawyer.specialization.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(lawyer.specialization,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              color: context.tokens.textMuted, fontSize: 12.5)),
                    ],
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 10,
                      runSpacing: 4,
                      children: [
                        if (lawyer.yearsExperience > 0)
                          _MetaChip(
                            icon: Icons.workspace_premium_outlined,
                            text: '${lawyer.yearsExperience} سنة خبرة',
                          ),
                        // Prefer the server's combined "city، country" label; fall back
                        // to the legacy jurisdiction name for an older record.
                        if (lawyer.location.isNotEmpty)
                          _MetaChip(
                            icon: Icons.place_outlined,
                            text: lawyer.location,
                          )
                        else if (lawyer.jurisdiction.isNotEmpty)
                          _MetaChip(
                            icon: Icons.place_outlined,
                            text: lawyer.jurisdiction,
                          ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    // The consultation price and the length it covers, straight from the
                    // backend's one pricing calculation. Deliberately not a star rating:
                    // there is no ratings data behind a star, so showing one would be a
                    // fabricated badge (owner decision, Execution 66).
                    Row(
                      children: [
                        if (lawyer.feeDisplay.isNotEmpty) ...[
                          Icon(Icons.payments_outlined,
                              size: 15, color: AppColors.teal),
                          const SizedBox(width: 4),
                          Flexible(
                            child: Text(
                              '${lawyer.feeDisplay} / ${lawyer.durationMinutes} دقيقة',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  color: AppColors.teal,
                                  fontSize: 12.5,
                                  fontWeight: FontWeight.w700),
                            ),
                          ),
                        ] else
                          Flexible(
                            child: Text(
                              'حسب الاتفاق',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  color: context.tokens.textSubtle,
                                  fontSize: 12.5),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    // Availability comes from the backend's own slot calculation, so
                    // the customer sees the same thing here as on the booking form.
                    Text(
                      lawyer.hasAvailability
                          ? '${lawyer.nextSlotCount} موعد متاح'
                          : 'لا توجد مواعيد منشورة',
                      style: TextStyle(
                          color: lawyer.hasAvailability
                              ? AppColors.teal
                              : context.tokens.textSubtle,
                          fontSize: 12,
                          fontWeight: FontWeight.w600),
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


/// The real availability calendar for the booking step.
///
/// It renders one month at a time from the backend's `month` map: a day with open slots
/// is tappable, a day with none (closed, in the past, or fully booked) is greyed and
/// inert. This is the app's half of "connect the calendar to the actual appointments" —
/// the grid is drawn from the same `get_month_availability()` the website calendar uses,
/// so both surfaces agree slot-for-slot.
class _BookingCalendar extends StatelessWidget {
  const _BookingCalendar({
    required this.month,
    required this.monthData,
    required this.loading,
    required this.selected,
    required this.onSelect,
    required this.onPrev,
    required this.onNext,
  });

  final DateTime month;
  final MonthAvailability monthData;
  final bool loading;
  final DateTime selected;
  final ValueChanged<DateTime> onSelect;
  final VoidCallback onPrev;
  final VoidCallback onNext;

  static const _weekdaysAr = ['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final first = DateTime(month.year, month.month, 1);
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    // Sunday-first grid, matching the Arabic week.
    final leading = first.weekday % 7;
    final today = DateTime.now();
    final todayIso = _iso(DateTime(today.year, today.month, today.day));
    final selectedIso = _iso(selected);
    final canGoBack = !(month.year == today.year && month.month == today.month);

    final cells = <Widget>[];
    for (var i = 0; i < leading; i++) {
      cells.add(const SizedBox.shrink());
    }
    for (var day = 1; day <= daysInMonth; day++) {
      final date = DateTime(month.year, month.month, day);
      final iso = _iso(date);
      final slots = monthData.slotsOn(iso);
      final isPast = iso.compareTo(todayIso) < 0;
      final bookable = slots > 0 && !isPast;
      final isSelected = iso == selectedIso;

      cells.add(
        _CalendarDay(
          day: day,
          slots: slots,
          enabled: bookable,
          selected: isSelected,
          isToday: iso == todayIso,
          onTap: bookable ? () => onSelect(date) : null,
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              IconButton(
                icon: const Icon(Icons.chevron_right, size: 20),
                onPressed: canGoBack ? onPrev : null,
                tooltip: 'الشهر السابق',
              ),
              Expanded(
                child: Text(
                  '${month.month} / ${month.year}',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontWeight: FontWeight.w700, color: context.tokens.heading),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.chevron_left, size: 20),
                onPressed: onNext,
                tooltip: 'الشهر التالي',
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: _weekdaysAr
                .map((w) => Expanded(
                      child: Text(
                        w,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            fontSize: 10.5,
                            color: context.tokens.textSubtle,
                            fontWeight: FontWeight.w600),
                      ),
                    ))
                .toList(),
          ),
          const SizedBox(height: 6),
          if (loading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
            )
          else ...[
            GridView.count(
              crossAxisCount: 7,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 4,
              crossAxisSpacing: 4,
              childAspectRatio: 1,
              children: cells,
            ),
            if (!monthData.hasHours)
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Text(
                  'لم ينشر هذا المحامي أوقات دوام — سيؤكد الموعد بعد الطلب.',
                  style: TextStyle(
                      fontSize: 11.5, color: context.tokens.textSubtle),
                ),
              )
            else if (monthData.days.values.every((n) => n == 0))
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Text(
                  'لا توجد أيام متاحة في هذا الشهر. جرّب الشهر التالي.',
                  style: TextStyle(
                      fontSize: 11.5, color: context.tokens.textSubtle),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

/// One day cell. A bookable day shows a small dot count; an unbookable day is flat.
class _CalendarDay extends StatelessWidget {
  const _CalendarDay({
    required this.day,
    required this.slots,
    required this.enabled,
    required this.selected,
    required this.isToday,
    required this.onTap,
  });

  final int day;
  final int slots;
  final bool enabled;
  final bool selected;
  final bool isToday;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final Color bg;
    final Color fg;
    if (selected) {
      bg = AppColors.navy;
      fg = Colors.white;
    } else if (enabled) {
      bg = AppColors.teal.withValues(alpha: 0.10);
      fg = context.tokens.heading;
    } else {
      bg = context.tokens.surface2;
      fg = context.tokens.textSubtle;
    }
    return Material(
      color: bg,
      borderRadius: BorderRadius.circular(8),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(8),
            border: isToday && !selected
                ? Border.all(color: AppColors.teal, width: 1.4)
                : null,
          ),
          alignment: Alignment.center,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                '$day',
                style: TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600, color: fg),
              ),
              if (enabled && slots > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    '$slots',
                    style: TextStyle(
                        fontSize: 9,
                        color: selected ? Colors.white70 : AppColors.teal),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The lawyer's photo when they have one, and a branded initials disc when they do
/// not. A missing photo is the common case on a young platform, so the fallback has
/// to look deliberate rather than broken — it shows the lawyer's initials on the
/// brand navy instead of a generic person glyph every card would repeat.
class _LawyerAvatar extends StatelessWidget {
  const _LawyerAvatar({required this.lawyer});

  final LawyerProfile lawyer;

  @override
  Widget build(BuildContext context) {
    final url = lawyer.photoUrl(AppConfig.siteBase);
    if (url != null) {
      return ClipOval(
        child: Image.network(
          url,
          width: 44,
          height: 44,
          fit: BoxFit.cover,
          // A broken image must degrade to the initials disc, not a broken-image icon.
          errorBuilder: (_, __, ___) => _initials(),
          loadingBuilder: (context, child, progress) =>
              progress == null ? child : _initials(),
        ),
      );
    }
    return _initials();
  }

  Widget _initials() {
    final parts = lawyer.name
        .trim()
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .toList();
    // First character of the first two words. `substring` rather than `characters`
    // because Arabic letters here are single UTF-16 units and the package is not a
    // dependency of this screen.
    final letters = parts.take(2).map((p) => p.substring(0, 1)).join();
    return Container(
      width: 44,
      height: 44,
      decoration: const BoxDecoration(
        color: AppColors.navy,
        shape: BoxShape.circle,
      ),
      alignment: Alignment.center,
      child: letters.isEmpty
          ? const Icon(Icons.person, color: Colors.white, size: 22)
          : Text(
              letters,
              style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w700,
                  fontSize: 16),
            ),
    );
  }
}

/// One small labelled fact on a lawyer card (experience, city/country).
class _MetaChip extends StatelessWidget {
  const _MetaChip({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14, color: context.tokens.textSubtle),
        const SizedBox(width: 4),
        // Flexible so a long value (a jurisdiction, a long year count) ellipsizes
        // inside the Wrap instead of overflowing it. A chip is decorative metadata;
        // truncating it is always better than a layout error.
        Flexible(
          child: Text(
            text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(color: context.tokens.textMuted, fontSize: 12),
          ),
        ),
      ],
    );
  }
}


/// The bar above the lawyer list: the matching count, a Filters button, and a Clear
/// action that appears only when a filter is active. The count is the server's own,
/// so it always describes the list below it.
class _JourneyFilterBar extends StatelessWidget {
  const _JourneyFilterBar({
    required this.active,
    required this.count,
    required this.onOpen,
    required this.onClear,
  });

  final bool active;
  final int count;
  final VoidCallback onOpen;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        border: Border(bottom: BorderSide(color: context.tokens.border)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              '$count محامٍ مطابق',
              style: TextStyle(
                  fontWeight: FontWeight.w700, color: context.tokens.heading),
            ),
          ),
          if (onClear != null)
            TextButton(
              onPressed: onClear,
              child: const Text('مسح الفلاتر'),
            ),
          OutlinedButton.icon(
            onPressed: onOpen,
            icon: const Icon(Icons.tune, size: 18),
            label: Text(active ? 'الفلاتر (مفعّلة)' : 'الفلاتر'),
          ),
        ],
      ),
    );
  }
}

/// The filter sheet. It holds a working copy of the filters and only returns them
/// when the customer presses Apply, so a dismissed sheet changes nothing. The city
/// list is dependent: choosing a country replaces the city options and clears a city
/// that does not belong to the new country, matching the website.
class _JourneyFilterSheet extends StatefulWidget {
  const _JourneyFilterSheet({required this.initial, required this.options});

  final JourneyFilters initial;
  final JourneyFilterOptions options;

  @override
  State<_JourneyFilterSheet> createState() => _JourneyFilterSheetState();
}

class _JourneyFilterSheetState extends State<_JourneyFilterSheet> {
  late JourneyFilters _draft = widget.initial;
  late final TextEditingController _minFee =
      TextEditingController(text: widget.initial.minFee);
  late final TextEditingController _maxFee =
      TextEditingController(text: widget.initial.maxFee);

  @override
  void dispose() {
    _minFee.dispose();
    _maxFee.dispose();
    super.dispose();
  }

  List<String> get _cities => widget.options.cities[_draft.country] ?? const [];

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 16,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('تصفية المحامين',
                style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: context.tokens.heading)),
            const SizedBox(height: 14),
            _label('الدولة'),
            DropdownButtonFormField<String>(
              initialValue: _draft.country.isEmpty ? null : _draft.country,
              isExpanded: true,
              decoration: const InputDecoration(hintText: 'كل الدول'),
              items: widget.options.countries
                  .map((c) => DropdownMenuItem(value: c.code, child: Text(c.name)))
                  .toList(),
              onChanged: (v) => setState(() {
                // Changing country drops a city that no longer belongs to it.
                _draft = _draft.copyWith(country: v ?? '', city: '');
              }),
            ),
            const SizedBox(height: 12),
            _label('المدينة'),
            DropdownButtonFormField<String>(
              // Keyed on the country so a country change rebuilds the field with the
              // new city list and no stale selection — a FormField keeps its own
              // value, so without the key the previous city would linger.
              key: ValueKey('city_${_draft.country}'),
              initialValue: _draft.city.isEmpty ? null : _draft.city,
              isExpanded: true,
              decoration: InputDecoration(
                hintText: _cities.isEmpty ? 'اختر الدولة أولاً' : 'كل المدن',
              ),
              items: _cities
                  .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                  .toList(),
              onChanged: _cities.isEmpty
                  ? null
                  : (v) => setState(() => _draft = _draft.copyWith(city: v ?? '')),
            ),
            const SizedBox(height: 12),
            _label('سنوات الخبرة'),
            DropdownButtonFormField<String>(
              initialValue: _draft.experience.isEmpty ? null : _draft.experience,
              isExpanded: true,
              decoration: const InputDecoration(hintText: 'كل مستويات الخبرة'),
              items: widget.options.experience
                  .map((b) => DropdownMenuItem(value: b.key, child: Text(b.label)))
                  .toList(),
              onChanged: (v) => setState(
                  () => _draft = _draft.copyWith(experience: v ?? '')),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _label('أقل أجر ساعي'),
                      TextField(
                        controller: _minFee,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(hintText: '0'),
                        onChanged: (v) => _draft = _draft.copyWith(minFee: v.trim()),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _label('أعلى أجر ساعي'),
                      TextField(
                        controller: _maxFee,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(hintText: 'أي مبلغ'),
                        onChanged: (v) => _draft = _draft.copyWith(maxFee: v.trim()),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('المتاحون بمواعيد فقط'),
              value: _draft.availableOnly,
              onChanged: (v) =>
                  setState(() => _draft = _draft.copyWith(availableOnly: v)),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () {
                      _minFee.clear();
                      _maxFee.clear();
                      setState(() => _draft = const JourneyFilters());
                    },
                    child: const Text('مسح'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: PrimaryButton(
                    label: 'تطبيق الفلاتر',
                    onPressed: () => Navigator.of(context).pop(_draft),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(text,
            style: TextStyle(
                fontWeight: FontWeight.w700, color: context.tokens.textMuted)),
      );
}
