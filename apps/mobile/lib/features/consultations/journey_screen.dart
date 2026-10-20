import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';

/// رحلة الاستشارة على الجوال — the same journey the website runs.
///
/// specialty → issue type → lawyer comparison → booking. It calls the shared
/// `/api/v1/journey.php` endpoints, so the two clients cannot drift apart, and the
/// internal legal classification never crosses the wire: the customer only ever
/// sees the plain-language specialty and issue type they picked.
class ConsultationJourneyScreen extends StatefulWidget {
  const ConsultationJourneyScreen({super.key, this.repository});

  /// Injectable for widget tests; production builds create their own.
  final ApiRepository? repository;

  @override
  State<ConsultationJourneyScreen> createState() =>
      _ConsultationJourneyScreenState();
}

enum _Step { specialty, issueType, lawyers }

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
          icon: _iconFor(s.iconKey),
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
      return EmptyState(
        icon: Icons.search_off,
        title: 'لا يوجد محامٍ متاح حالياً',
        message: _match.alternative.isNotEmpty
            ? _match.alternative
            : 'جرّب تخصصاً آخر أو تواصل معنا لترشيح محامٍ مناسب.',
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

  @override
  void initState() {
    super.initState();
    _loadSlots();
  }

  @override
  void dispose() {
    _topic.dispose();
    super.dispose();
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
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await widget.repository.bookConsultation(
        lawyerId: widget.lawyer.id,
        consultationType: 'text',
        date: _dateParam,
        time: _slot!,
        topic: _topic.text.trim(),
        specialtyCategoryId: widget.specialty.id,
        issueTypeId: widget.issueType?.id,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم إرسال طلب الاستشارة بنجاح.')),
      );
      Navigator.of(context).popUntil((route) => route.isFirst);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BrandAppBar(subtitle: 'تأكيد الحجز'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        children: [
          _SummaryCard(
            label: 'موضوع الاستشارة',
            value: widget.specialty.name,
            secondary: widget.issueType?.name,
          ),
          const SizedBox(height: 12),
          _SummaryCard(
            label: 'المحامي',
            value: widget.lawyer.name,
          ),
          const SizedBox(height: 12),
          ListTile(
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
              side: const BorderSide(color: AppColors.border),
            ),
            tileColor: AppColors.surface,
            leading: const Icon(Icons.calendar_today_outlined),
            title: const Text('التاريخ'),
            subtitle: Text(_dateParam),
            onTap: _pickDate,
          ),
          const SizedBox(height: 12),
          if (_loadingSlots)
            const LoadingSkeleton(count: 2)
          else if (_slots.isEmpty)
            const Text('لا توجد أوقات متاحة في هذا اليوم. اختر يوماً آخر.',
                style: TextStyle(color: AppColors.textMuted))
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
          const SizedBox(height: 16),
          TextField(
            controller: _topic,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'اكتب وصفاً موجزاً لمشكلتك (اختياري)',
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: AppColors.danger)),
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
      ),
    );
  }
}

// ------------------------------------------------------------- building blocks

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
        color: AppColors.surface,
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textMuted)),
          const SizedBox(height: 6),
          Text(value,
              style: const TextStyle(
                  fontWeight: FontWeight.w700, color: AppColors.navyDark)),
          if (secondary != null && secondary!.isNotEmpty)
            Text(secondary!,
                style: const TextStyle(color: AppColors.textMuted)),
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

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('الخطوة $step من 3',
              style: const TextStyle(
                  color: AppColors.teal,
                  fontSize: 12,
                  fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(title,
              style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: AppColors.navyDark)),
          if (subtitle != null)
            Text(subtitle!,
                style: const TextStyle(color: AppColors.textMuted)),
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
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.border),
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
                        style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            color: AppColors.navyDark)),
                    if (subtitle.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(subtitle,
                          style: const TextStyle(
                              color: AppColors.textMuted, fontSize: 13)),
                    ],
                    if (footnote != null && footnote!.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(footnote!,
                          style: const TextStyle(
                              color: AppColors.textSubtle, fontSize: 12)),
                    ],
                  ],
                ),
              ),
              const Icon(Icons.chevron_left, color: AppColors.textSubtle),
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
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.border),
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
                        style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            color: AppColors.navyDark)),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        const Icon(Icons.star, size: 14, color: AppColors.gold),
                        const SizedBox(width: 4),
                        Text(lawyer.rating.toStringAsFixed(1),
                            style: const TextStyle(
                                color: AppColors.textMuted, fontSize: 12)),
                        if (lawyer.jurisdiction.isNotEmpty) ...[
                          const SizedBox(width: 10),
                          Flexible(
                            child: Text(lawyer.jurisdiction,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                    color: AppColors.textSubtle, fontSize: 12)),
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

IconData _iconFor(String key) {
  switch (key) {
    case 'family':
      return Icons.family_restroom;
    case 'commercial':
      return Icons.storefront_outlined;
    case 'labor':
      return Icons.work_outline;
    case 'criminal':
      return Icons.gavel_outlined;
    case 'realestate':
      return Icons.home_work_outlined;
    default:
      return Icons.balance_outlined;
  }
}
