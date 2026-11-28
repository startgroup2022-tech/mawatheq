import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/lawyer_models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'lawyer_settings_screen.dart';

/// The lawyer's professional profile.
///
/// The status banner is the point of the screen: an unapproved lawyer is invisible to
/// clients, and nothing on the page can change that. Saying so plainly — with the
/// reason — is better than letting someone fill in a profile that never appears.
class LawyerProfileScreen extends StatefulWidget {
  const LawyerProfileScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LawyerProfileScreen> createState() => _LawyerProfileScreenState();
}

class _LawyerProfileScreenState extends State<LawyerProfileScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  LawyerProfileDetail? _profile;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final p = await _repo.lawyerProfile();
      if (!mounted) return;
      setState(() {
        _profile = p;
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

  Future<void> _edit() async {
    final p = _profile;
    if (p == null) return;
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => LawyerProfileEditScreen(repository: _repo, profile: p)),
    );
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: 'الملف المهني',
        actions: [
          if (_profile != null)
            IconButton(
              tooltip: 'تعديل',
              onPressed: _edit,
              icon: const Icon(Icons.edit_outlined),
            ),
          IconButton(
            tooltip: 'الإعدادات',
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => LawyerSettingsScreen(repository: _repo)),
            ),
            icon: const Icon(Icons.settings_outlined),
          ),
        ],
      ),
      body: _loading
          ? const Padding(padding: EdgeInsets.all(16), child: LoadingSkeleton(count: 5))
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _body(context, _profile!),
    );
  }

  Widget _body(BuildContext context, LawyerProfileDetail p) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
      children: [
        _StatusBanner(profile: p),
        const SizedBox(height: 16),
        Row(
          children: [
            _Avatar(name: p.fullName),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    p.fullName.isEmpty ? '—' : p.fullName,
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: context.tokens.heading),
                  ),
                  const SizedBox(height: 3),
                  Text(p.specialization.isEmpty ? 'لم تُحدَّد التخصصات بعد' : p.specialization,
                      style: TextStyle(fontSize: 13, color: context.tokens.textMuted)),
                  if (p.rating > 0) ...[
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        const Icon(Icons.star, size: 15, color: AppColors.gold),
                        const SizedBox(width: 4),
                        Text(p.rating.toStringAsFixed(1),
                            style: TextStyle(fontSize: 12.5, color: context.tokens.textMuted)),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 20),
        const SectionHeader(title: 'بيانات التواصل'),
        _Field(label: 'البريد الإلكتروني', value: p.email),
        _Field(label: 'الهاتف', value: p.phone.isEmpty ? '—' : p.phone),
        _Field(label: 'الاختصاص القضائي', value: p.jurisdiction.isEmpty ? '—' : p.jurisdiction),
        const SizedBox(height: 18),
        const SectionHeader(title: 'المؤهلات والخبرة'),
        _Field(label: 'سنوات الخبرة', value: '${p.yearsExperience}'),
        _Field(label: 'رقم الترخيص', value: p.licenseNumber.isEmpty ? '—' : p.licenseNumber),
        _Field(label: 'أجر الاستشارة', value: p.hourlyRate > 0 ? '${p.hourlyRate.toStringAsFixed(0)} / ساعة' : '—'),
        if (p.qualifications.isNotEmpty) _Field(label: 'المؤهلات', value: p.qualifications),
        if (p.bio.isNotEmpty) ...[
          const SizedBox(height: 8),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: context.tokens.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('نبذة', style: TextStyle(fontSize: 12, color: context.tokens.textMuted)),
                const SizedBox(height: 6),
                Text(p.bio, style: TextStyle(fontSize: 13.5, height: 1.6, color: context.tokens.text)),
              ],
            ),
          ),
        ],
        const SizedBox(height: 18),
        const SectionHeader(title: 'التخصصات'),
        if (p.specialtyIds.isEmpty)
          Text('لم تُحدَّد تخصصات. أضفها من شاشة التعديل لتصلك الطلبات المطابقة.',
              style: TextStyle(fontSize: 13, color: context.tokens.textMuted))
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: p.specialtyIds
                .map((id) => Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: context.tokens.surface3,
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                      ),
                      child: Text('#$id', style: TextStyle(fontSize: 12, color: context.tokens.text)),
                    ))
                .toList(),
          ),
        const SizedBox(height: 18),
        const SectionHeader(title: 'أوقات العمل'),
        if (p.availability.isEmpty)
          Text('لم تُضف أوقات عمل. أضفها ليتمكن العملاء من الحجز في الأوقات المناسبة.',
              style: TextStyle(fontSize: 13, color: context.tokens.textMuted))
        else
          ...p.availability.map((a) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  children: [
                    Expanded(child: Text(a.dayLabel, style: TextStyle(fontSize: 13.5, color: context.tokens.text))),
                    Text('${a.startTime} - ${a.endTime}',
                        style: TextStyle(fontSize: 13, color: context.tokens.textMuted)),
                    const SizedBox(width: 10),
                    Text('${a.slotMinutes} د', style: TextStyle(fontSize: 12, color: context.tokens.textMuted)),
                  ],
                ),
              )),
        const SizedBox(height: 22),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            onPressed: _edit,
            icon: const Icon(Icons.edit_outlined),
            label: const Text('تعديل الملف'),
          ),
        ),
      ],
    );
  }
}

/// The approval banner. Shown always, because the reason a profile is invisible is the
/// most useful thing on the screen.
class _StatusBanner extends StatelessWidget {
  const _StatusBanner({required this.profile});

  final LawyerProfileDetail profile;

  @override
  Widget build(BuildContext context) {
    final (bg, fg, icon, text) = switch (profile.status) {
      'approved' when profile.emailVerified => (
          AppColors.successBg,
          AppColors.success,
          Icons.verified_outlined,
          'حسابك معتمد وظاهر للعملاء.',
        ),
      'approved' => (
          AppColors.warningBg,
          AppColors.warning,
          Icons.mark_email_unread_outlined,
          'حسابك معتمد، لكن يلزم تأكيد البريد الإلكتروني قبل الظهور للعملاء.',
        ),
      'pending' => (
          AppColors.warningBg,
          AppColors.warning,
          Icons.hourglass_top_outlined,
          'حسابك قيد المراجعة من إدارة المنصة. لن يظهر ملفك للعملاء حتى يتم الاعتماد.',
        ),
      'suspended' => (
          AppColors.dangerBg,
          AppColors.danger,
          Icons.block_outlined,
          'حسابك موقوف. يرجى التواصل مع الدعم.',
        ),
      _ => (
          AppColors.dangerBg,
          AppColors.danger,
          Icons.error_outline,
          'حالة الحساب غير معروفة. يرجى التواصل مع الدعم.',
        ),
    };
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Row(
        children: [
          Icon(icon, color: fg, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text, style: TextStyle(color: fg, fontSize: 13, height: 1.5)),
          ),
        ],
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    final letter = name.trim().isEmpty ? '؟' : String.fromCharCode(name.trim().runes.first);
    return Container(
      width: 62,
      height: 62,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: AppColors.brandHero,
      ),
      child: Center(
        child: Text(letter,
            style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 130,
            child: Text(label, style: TextStyle(fontSize: 13, color: context.tokens.textMuted)),
          ),
          Expanded(
            child: Text(value,
                style: TextStyle(fontSize: 13.5, color: context.tokens.text, fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}

/// Edit the profile, its specialties and its weekly availability.
class LawyerProfileEditScreen extends StatefulWidget {
  const LawyerProfileEditScreen({super.key, required this.repository, required this.profile});

  final ApiRepository repository;
  final LawyerProfileDetail profile;

  @override
  State<LawyerProfileEditScreen> createState() => _LawyerProfileEditScreenState();
}

class _LawyerProfileEditScreenState extends State<LawyerProfileEditScreen> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _phone;
  late final TextEditingController _specialization;
  late final TextEditingController _bio;
  late final TextEditingController _years;
  late final TextEditingController _license;
  late final TextEditingController _qualifications;
  late final TextEditingController _linkedin;
  late final TextEditingController _twitter;
  late final TextEditingController _website;
  late final TextEditingController _rate;

  Set<int> _specialtyIds = {};
  List<SpecialtyOption> _catalogue = const [];
  List<AvailabilitySlot> _slots = [];
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final p = widget.profile;
    _name = TextEditingController(text: p.fullName);
    _phone = TextEditingController(text: p.phone);
    _specialization = TextEditingController(text: p.specialization);
    _bio = TextEditingController(text: p.bio);
    _years = TextEditingController(text: p.yearsExperience == 0 ? '' : '${p.yearsExperience}');
    _license = TextEditingController(text: p.licenseNumber);
    _qualifications = TextEditingController(text: p.qualifications);
    _linkedin = TextEditingController(text: p.socialLinkedin);
    _twitter = TextEditingController(text: p.socialTwitter);
    _website = TextEditingController(text: p.socialWebsite);
    _rate = TextEditingController(text: p.hourlyRate == 0 ? '' : p.hourlyRate.toStringAsFixed(0));
    _specialtyIds = p.specialtyIds.toSet();
    _slots = List.of(p.availability);
    _loadCatalogue();
  }

  Future<void> _loadCatalogue() async {
    try {
      final list = await widget.repository.specialtyCatalogue();
      if (mounted) setState(() => _catalogue = list);
    } on ApiException {
      // An empty catalogue is a valid answer on an install that has not imported the
      // taxonomy; the picker then says so rather than showing a broken list.
    }
  }

  @override
  void dispose() {
    for (final c in [_name, _phone, _specialization, _bio, _years, _license, _qualifications, _linkedin, _twitter, _website, _rate]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.repository.saveLawyerProfile({
        'full_name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'specialization': _specialization.text.trim(),
        'bio': _bio.text.trim(),
        'years_experience': int.tryParse(_years.text.trim()) ?? 0,
        'license_number': _license.text.trim(),
        'qualifications': _qualifications.text.trim(),
        'social_linkedin': _linkedin.text.trim(),
        'social_twitter': _twitter.text.trim(),
        'social_website': _website.text.trim(),
        'hourly_rate': double.tryParse(_rate.text.trim()) ?? 0,
      });
      await widget.repository.saveSpecialties(_specialtyIds.toList());
      await widget.repository.saveAvailability(_slots.map((s) => s.toJson()).toList());
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.message;
      });
    }
  }

  Future<void> _addSlot() async {
    final picked = await showDialog<AvailabilitySlot>(
      context: context,
      builder: (_) => const _SlotDialog(),
    );
    if (picked != null) setState(() => _slots.add(picked));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: BrandAppBar(
        subtitle: 'تعديل الملف',
        actions: [
          TextButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('حفظ'),
          ),
        ],
      ),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: [
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'الاسم الكامل'),
              validator: (v) => (v ?? '').trim().isEmpty ? 'الاسم مطلوب' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _specialization,
              decoration: const InputDecoration(labelText: 'التخصص الرئيسي'),
              validator: (v) => (v ?? '').trim().isEmpty ? 'التخصص مطلوب' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'رقم الهاتف'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _bio,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'نبذة تعريفية'),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _years,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'سنوات الخبرة'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    controller: _rate,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'أجر الساعة'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _license,
              decoration: const InputDecoration(labelText: 'رقم الترخيص'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _qualifications,
              maxLines: 2,
              decoration: const InputDecoration(labelText: 'المؤهلات العلمية'),
            ),
            const SizedBox(height: 20),
            const SectionHeader(title: 'روابط مهنية'),
            TextFormField(
              controller: _linkedin,
              decoration: const InputDecoration(labelText: 'LinkedIn'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _twitter,
              decoration: const InputDecoration(labelText: 'X / Twitter'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _website,
              decoration: const InputDecoration(labelText: 'الموقع الإلكتروني'),
            ),
            const SizedBox(height: 22),
            const SectionHeader(title: 'التخصصات'),
            if (_catalogue.isEmpty)
              Text('لا يتوفر دليل تخصصات على هذا النظام بعد.',
                  style: TextStyle(fontSize: 13, color: context.tokens.textMuted))
            else
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: _catalogue
                    .map((s) => FilterChip(
                          label: Text(s.label),
                          selected: _specialtyIds.contains(s.id),
                          onSelected: (on) => setState(() {
                            if (on) {
                              _specialtyIds.add(s.id);
                            } else {
                              _specialtyIds.remove(s.id);
                            }
                          }),
                        ))
                    .toList(),
              ),
            const SizedBox(height: 22),
            SectionHeader(
              title: 'أوقات العمل',
              actionLabel: 'إضافة',
              onAction: _addSlot,
            ),
            if (_slots.isEmpty)
              Text('لم تُضف أوقات عمل بعد.',
                  style: TextStyle(fontSize: 13, color: context.tokens.textMuted))
            else
              ..._slots.asMap().entries.map((entry) {
                final i = entry.key;
                final s = entry.value;
                return Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: context.tokens.surface,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    border: Border.all(color: context.tokens.border),
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text('${s.dayLabel} · ${s.startTime} - ${s.endTime} · ${s.slotMinutes} د',
                            style: TextStyle(fontSize: 13.5, color: context.tokens.text)),
                      ),
                      IconButton(
                        tooltip: 'حذف',
                        onPressed: () => setState(() => _slots.removeAt(i)),
                        icon: const Icon(Icons.delete_outline, size: 20),
                      ),
                    ],
                  ),
                );
              }),
            if (_error != null) ...[
              const SizedBox(height: 14),
              Text(_error!, style: const TextStyle(color: AppColors.danger, fontSize: 13)),
            ],
            const SizedBox(height: 22),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _saving ? null : _save,
                child: const Text('حفظ التغييرات'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A small dialog to pick one weekly window.
class _SlotDialog extends StatefulWidget {
  const _SlotDialog();

  @override
  State<_SlotDialog> createState() => _SlotDialogState();
}

class _SlotDialogState extends State<_SlotDialog> {
  int _day = 0;
  TimeOfDay _start = const TimeOfDay(hour: 9, minute: 0);
  TimeOfDay _end = const TimeOfDay(hour: 17, minute: 0);
  int _minutes = 30;

  String _fmt(TimeOfDay t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('إضافة وقت عمل'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          DropdownButtonFormField<int>(
            initialValue: _day,
            decoration: const InputDecoration(labelText: 'اليوم'),
            items: [
              for (var i = 0; i < AvailabilitySlot.dayNames.length; i++)
                DropdownMenuItem(value: i, child: Text(AvailabilitySlot.dayNames[i])),
            ],
            onChanged: (v) => setState(() => _day = v ?? 0),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () async {
                    final picked = await showTimePicker(context: context, initialTime: _start);
                    if (picked != null) setState(() => _start = picked);
                  },
                  child: Text('من ${_fmt(_start)}'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton(
                  onPressed: () async {
                    final picked = await showTimePicker(context: context, initialTime: _end);
                    if (picked != null) setState(() => _end = picked);
                  },
                  child: Text('إلى ${_fmt(_end)}'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            initialValue: _minutes,
            decoration: const InputDecoration(labelText: 'مدة الموعد (دقائق)'),
            items: const [
              DropdownMenuItem(value: 15, child: Text('15')),
              DropdownMenuItem(value: 30, child: Text('30')),
              DropdownMenuItem(value: 45, child: Text('45')),
              DropdownMenuItem(value: 60, child: Text('60')),
            ],
            onChanged: (v) => setState(() => _minutes = v ?? 30),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(AvailabilitySlot(
            dayOfWeek: _day,
            startTime: _fmt(_start),
            endTime: _fmt(_end),
            slotMinutes: _minutes,
          )),
          child: const Text('إضافة'),
        ),
      ],
    );
  }
}
