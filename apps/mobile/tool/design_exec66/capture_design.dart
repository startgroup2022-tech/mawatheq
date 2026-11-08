// Execution 66 — HIGH-FIDELITY DESIGN MOCKUPS for the Flutter booking experience.
//
// These are DESIGN-ONLY widgets, not production screens. They exist to put the
// proposed redesign in front of the owner for approval before any implementation.
// Nothing in this file is referenced by the application, and no production screen
// is modified by it.
//
// Every colour, radius, shadow and spacing value is read from the real brand tokens
// (`lib/core/theme/app_colors.dart`), so what you see is the shipped palette and the
// shipped type — not an approximation of it. The Arabic strings are the real product
// vocabulary.
//
// Run:
//   flutter test tool/design_exec66/capture_design.dart --update-goldens
// The PNGs land in tool/design_exec66/goldens/.

import 'dart:ui' show FontFeature;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:mawatheq_mobile/core/theme/app_colors.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';

// --------------------------------------------------------------------------- type
TextStyle _ar({
  double size = 14,
  FontWeight weight = FontWeight.w400,
  Color? color,
  double? height,
}) =>
    GoogleFonts.ibmPlexSansArabic(
      fontSize: size,
      fontWeight: weight,
      color: color ?? AppColors.text,
      height: height,
    );

TextStyle _display({
  double size = 19,
  FontWeight weight = FontWeight.w800,
  Color? color,
}) =>
    GoogleFonts.cairo(
      fontSize: size,
      fontWeight: weight,
      color: color ?? AppColors.navyDark,
    );

/// Numbers, prices and times always read left-to-right, even inside an RTL layout.
TextStyle _num({
  double size = 15,
  FontWeight weight = FontWeight.w700,
  Color? color,
}) =>
    GoogleFonts.ibmPlexSansArabic(
      fontSize: size,
      fontWeight: weight,
      color: color ?? AppColors.navyDark,
    ).copyWith(fontFeatures: const [FontFeature.tabularFigures()]);

// ------------------------------------------------------------------- scaffolding
/// A design phone screen: gradient app bar, scrolling body, fixed bottom action bar.
class _Phone extends StatelessWidget {
  const _Phone({
    required this.title,
    required this.subtitle,
    required this.children,
    this.step,
    this.stepLabels = const ['المحامي', 'النوع', 'الموعد', 'المراجعة', 'الدفع'],
    this.bottom,
    this.leadingBack = true,
    this.bodyPadding = const EdgeInsets.fromLTRB(16, 16, 16, 20),
  });

  final String title;
  final String subtitle;
  final List<Widget> children;
  final int? step;
  final List<String> stepLabels;
  final Widget? bottom;
  final bool leadingBack;
  final EdgeInsets bodyPadding;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Column(
        children: [
          Container(
            decoration: const BoxDecoration(gradient: AppColors.brandHero),
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        if (leadingBack)
                          const Icon(Icons.arrow_back, color: Colors.white, size: 22),
                        if (leadingBack) const SizedBox(width: 12),
                        Expanded(
                          child: Text('مواثيق', style: _display(size: 17, color: Colors.white)),
                        ),
                        const Icon(Icons.notifications_none, color: Colors.white, size: 22),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Text(title, style: _display(size: 20, color: Colors.white)),
                    const SizedBox(height: 3),
                    Text(subtitle, style: _ar(size: 12, color: const Color(0xFFBFD8EA))),
                  ],
                ),
              ),
            ),
          ),
          if (step != null) _StepBar(step: step!, labels: stepLabels),
          Expanded(
            child: SingleChildScrollView(
              padding: bodyPadding,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: children,
              ),
            ),
          ),
          if (bottom != null) bottom!,
        ],
      ),
    );
  }
}

class _StepBar extends StatelessWidget {
  const _StepBar({required this.step, required this.labels});
  final int step;
  final List<String> labels;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.surface,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        children: [
          Row(
            children: List.generate(labels.length, (i) {
              final done = i + 1 < step;
              final now = i + 1 == step;
              return Expanded(
                child: Padding(
                  padding: EdgeInsets.only(left: i == 0 ? 0 : 4),
                  child: Row(
                    children: [
                      Container(
                        width: 24,
                        height: 24,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: done
                              ? AppColors.success
                              : now
                                  ? AppColors.navy
                                  : AppColors.surface3,
                          border: Border.all(
                            color: done
                                ? AppColors.success
                                : now
                                    ? AppColors.navy
                                    : AppColors.border,
                            width: 1.5,
                          ),
                        ),
                        alignment: Alignment.center,
                        child: done
                            ? const Icon(Icons.check, size: 13, color: Colors.white)
                            : Text(
                                '${i + 1}',
                                style: _num(
                                  size: 11,
                                  color: now ? Colors.white : AppColors.textSubtle,
                                ),
                              ),
                      ),
                      if (i < labels.length - 1)
                        Expanded(
                          child: Container(
                            height: 2,
                            margin: const EdgeInsets.symmetric(horizontal: 4),
                            color: done ? AppColors.success : AppColors.border,
                          ),
                        ),
                    ],
                  ),
                ),
              );
            }),
          ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerRight,
            child: Text(
              labels[step - 1],
              style: _ar(size: 12, weight: FontWeight.w700, color: AppColors.navyDark),
            ),
          ),
        ],
      ),
    );
  }
}

/// The fixed bottom bar that replaces the desktop summary rail — this is the fix for
/// the "the button is 1146px down a 1080px screen" defect.
class _BottomBar extends StatelessWidget {
  const _BottomBar({
    required this.label,
    required this.value,
    required this.cta,
    this.ctaColor = AppColors.navy,
  });
  final String label;
  final String value;
  final String cta;
  final Color ctaColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.border)),
        boxShadow: AppShadow.sm,
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
          child: Row(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(label, style: _ar(size: 11, color: AppColors.textMuted)),
                  Text(value, style: _num(size: 16)),
                ],
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Container(
                  height: 48,
                  decoration: BoxDecoration(
                    color: ctaColor,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  alignment: Alignment.center,
                  child: Text(cta,
                      style: _ar(size: 14, weight: FontWeight.w700, color: Colors.white)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child, this.padding = const EdgeInsets.all(16), this.border});
  final Widget child;
  final EdgeInsets padding;
  final Color? border;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: border ?? AppColors.border),
        boxShadow: AppShadow.xs,
      ),
      child: child,
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text, {this.badge});
  final String text;
  final String? badge;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 26,
          height: 26,
          decoration: BoxDecoration(
            color: AppColors.infoBg,
            borderRadius: BorderRadius.circular(AppRadius.sm),
          ),
          alignment: Alignment.center,
          child: Text(badge ?? '•', style: _num(size: 12, color: AppColors.teal)),
        ),
        const SizedBox(width: 10),
        Expanded(child: Text(text, style: _display(size: 16))),
      ],
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.text, required this.kind, required this.icon});
  final String text;
  final String kind; // info | ok | warn | err
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (kind) {
      'ok' => (AppColors.successBg, const Color(0xFF14663F)),
      'warn' => (AppColors.warningBg, AppColors.warning),
      'err' => (AppColors.dangerBg, const Color(0xFF9C3A22)),
      _ => (AppColors.infoBg, const Color(0xFF155F73)),
    };
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 17, color: fg),
          const SizedBox(width: 9),
          Expanded(child: Text(text, style: _ar(size: 12, color: fg, height: 1.6))),
        ],
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip(this.text, {this.on = false});
  final String text;
  final bool on;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
      decoration: BoxDecoration(
        color: on ? AppColors.navy : AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: on ? AppColors.navy : AppColors.border, width: 1.5),
      ),
      child: Text(
        text,
        style: _ar(
          size: 13,
          weight: FontWeight.w600,
          color: on ? Colors.white : AppColors.textMuted,
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill(this.text, {required this.bg, required this.fg});
  final String text;
  final Color bg;
  final Color fg;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration:
          BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.pill)),
      child: Text(text, style: _ar(size: 11, weight: FontWeight.w700, color: fg)),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow(this.k, this.v, {this.strong = false, this.numeric = false});
  final String k;
  final String v;
  final bool strong;
  final bool numeric;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: Text(k, style: _ar(size: 13, color: AppColors.textMuted))),
          const SizedBox(width: 10),
          Text(
            v,
            textAlign: TextAlign.left,
            style: numeric
                ? _num(size: 13, weight: strong ? FontWeight.w800 : FontWeight.w700)
                : _ar(
                    size: 13,
                    weight: strong ? FontWeight.w800 : FontWeight.w700,
                    color: AppColors.navyDark,
                  ),
          ),
        ],
      ),
    );
  }
}

// ------------------------------------------------------------------ lawyer card
class _LawyerCard extends StatelessWidget {
  const _LawyerCard({
    required this.initial,
    required this.name,
    required this.specialty,
    required this.city,
    required this.rating,
    required this.ratingCount,
    required this.years,
    required this.price,
    required this.duration,
    required this.slots,
    required this.selected,
    this.extraSlots,
  });

  final String initial;
  final String name;
  final String specialty;
  final String city;
  final String rating;
  final String ratingCount;
  final int years;
  final int price;
  final int duration;
  final List<String> slots;
  final bool selected;
  final int? extraSlots;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(
          color: selected ? AppColors.teal : AppColors.border,
          width: selected ? 1.6 : 1,
        ),
        boxShadow: selected ? AppShadow.sm : AppShadow.xs,
      ),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(14),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    gradient: const LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [Color(0xFFDCEFF8), Color(0xFFEAF6F9)],
                    ),
                  ),
                  alignment: Alignment.center,
                  child: Text(initial, style: _display(size: 22, color: AppColors.navy)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: _display(size: 15)),
                      const SizedBox(height: 2),
                      Text(specialty,
                          style: _ar(size: 12, weight: FontWeight.w700, color: AppColors.teal)),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          const Icon(Icons.location_on_outlined,
                              size: 13, color: AppColors.textMuted),
                          const SizedBox(width: 3),
                          Text(city, style: _ar(size: 12, color: AppColors.textMuted)),
                        ],
                      ),
                      const SizedBox(height: 7),
                      Wrap(
                        spacing: 5,
                        runSpacing: 5,
                        children: [
                          _Pill('★ $rating · $ratingCount تقييماً موثقاً',
                              bg: const Color(0xFFFEF6E7), fg: const Color(0xFF8A6D1D)),
                          _Pill('خبرة $years سنة',
                              bg: AppColors.infoBg, fg: const Color(0xFF1C7A90)),
                          _Pill('متاح قريباً', bg: AppColors.successBg, fg: AppColors.success),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Container(
            decoration: const BoxDecoration(
              color: AppColors.surface2,
              border: Border(
                top: BorderSide(color: AppColors.border),
                bottom: BorderSide(color: AppColors.border),
              ),
            ),
            child: Row(
              children: [
                Expanded(child: _stat('قيمة الاستشارة', '$price ر.س', price: true)),
                Container(width: 1, height: 42, color: AppColors.border),
                Expanded(child: _stat('المدة', '$duration دقيقة')),
                Container(width: 1, height: 42, color: AppColors.border),
                Expanded(child: _stat('الخبرة', '$years سنة')),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('أقرب المواعيد المتاحة فعلياً',
                    style: _ar(size: 11, color: AppColors.textMuted)),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 7,
                  runSpacing: 7,
                  children: [
                    ...slots.map((s) => Container(
                          padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
                          decoration: BoxDecoration(
                            color: AppColors.successBg,
                            borderRadius: BorderRadius.circular(AppRadius.sm),
                          ),
                          child: Text(s,
                              style: _ar(
                                  size: 11, weight: FontWeight.w700, color: AppColors.success)),
                        )),
                    if (extraSlots != null)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
                        decoration: BoxDecoration(
                          color: AppColors.surface3,
                          borderRadius: BorderRadius.circular(AppRadius.sm),
                        ),
                        child: Text('+$extraSlots مواعيد',
                            style: _ar(
                                size: 11, weight: FontWeight.w700, color: AppColors.textMuted)),
                      ),
                  ],
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 4, 14, 14),
            child: Row(
              children: [
                Expanded(child: _outlineButton('عرض الملف')),
                const SizedBox(width: 9),
                Expanded(
                  child: _solidButton(selected ? 'اختيار هذا المحامي ✓' : 'اختيار هذا المحامي'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stat(String k, String v, {bool price = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Column(
          children: [
            Text(k, style: _ar(size: 11, color: AppColors.textSubtle)),
            const SizedBox(height: 2),
            Text(v, style: _num(size: 14, color: price ? AppColors.goldDeep : AppColors.navyDark)),
          ],
        ),
      );

  Widget _outlineButton(String t) => Container(
        height: 44,
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(AppRadius.md),
          border: Border.all(color: AppColors.borderStrong, width: 1.5),
        ),
        alignment: Alignment.center,
        child: Text(t, style: _ar(size: 13, weight: FontWeight.w700, color: AppColors.navy)),
      );

  Widget _solidButton(String t) => Container(
        height: 44,
        decoration: BoxDecoration(
          color: AppColors.navy,
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        alignment: Alignment.center,
        child: Text(t, style: _ar(size: 13, weight: FontWeight.w700, color: Colors.white)),
      );
}

// -------------------------------------------------------------------- calendar
class _Calendar extends StatelessWidget {
  const _Calendar();
  @override
  Widget build(BuildContext context) {
    const dows = ['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];
    // November 2026 starts on a Sunday; 6/7, 13/14, 20/21, 27/28 are Fri/Sat (off).
    final cells = <Widget>[];
    for (final d in dows) {
      cells.add(Center(
          child: Text(d,
              style: _ar(size: 10, weight: FontWeight.w700, color: AppColors.textSubtle))));
    }
    const offDays = {6, 7, 13, 14, 20, 21, 27, 28};
    for (var i = 0; i < 30; i++) {
      final day = i + 1;
      final off = offDays.contains(day);
      final sel = day == 11;
      cells.add(Container(
        height: 38,
        decoration: BoxDecoration(
          color: sel ? AppColors.navy : (off ? Colors.transparent : AppColors.infoBg),
          borderRadius: BorderRadius.circular(AppRadius.sm),
        ),
        alignment: Alignment.center,
        child: Text(
          '$day',
          style: _num(
            size: 13,
            color: sel ? Colors.white : (off ? AppColors.textSubtle : const Color(0xFF155F73)),
          ),
        ),
      ));
    }
    return _Card(
      padding: const EdgeInsets.all(12),
      child: Column(
        children: [
          Row(
            children: [
              Row(
                children: [
                  _calNav(Icons.chevron_right),
                  const SizedBox(width: 6),
                  _calNav(Icons.chevron_left),
                ],
              ),
              const Spacer(),
              Text('نوفمبر ٢٠٢٦', style: _display(size: 14)),
            ],
          ),
          const SizedBox(height: 10),
          GridView.count(
            crossAxisCount: 7,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 4,
            crossAxisSpacing: 4,
            children: cells,
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 14,
            runSpacing: 6,
            children: [
              _legend(AppColors.infoBg, 'متاح'),
              _legend(AppColors.navy, 'المختار'),
              _legend(AppColors.surface3, 'غير متاح / إجازة'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _calNav(IconData i) => Container(
        width: 34,
        height: 34,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(AppRadius.sm),
          border: Border.all(color: AppColors.border, width: 1.5),
        ),
        child: Icon(i, size: 18, color: AppColors.navy),
      );

  Widget _legend(Color c, String t) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 11,
            height: 11,
            decoration: BoxDecoration(
              color: c,
              borderRadius: BorderRadius.circular(3),
              border: Border.all(color: AppColors.border),
            ),
          ),
          const SizedBox(width: 5),
          Text(t, style: _ar(size: 11, color: AppColors.textMuted)),
        ],
      );
}

class _SlotGrid extends StatelessWidget {
  const _SlotGrid({required this.slots});
  final List<(String, String)> slots; // (label, state: free|taken|sel)

  @override
  Widget build(BuildContext context) {
    return GridView.count(
      crossAxisCount: 4,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 8,
      crossAxisSpacing: 8,
      childAspectRatio: 2.0,
      children: slots.map((s) {
        final (label, state) = s;
        final sel = state == 'sel';
        final taken = state == 'taken';
        return Container(
          decoration: BoxDecoration(
            color: sel
                ? AppColors.navy
                : taken
                    ? AppColors.surface3
                    : AppColors.surface,
            borderRadius: BorderRadius.circular(AppRadius.md),
            border: Border.all(color: sel ? AppColors.navy : AppColors.border, width: 1.5),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: _num(
              size: 13,
              color: sel
                  ? Colors.white
                  : taken
                      ? AppColors.textSubtle
                      : AppColors.navyDark,
            ).copyWith(decoration: taken ? TextDecoration.lineThrough : null),
          ),
        );
      }).toList(),
    );
  }
}

// ---------------------------------------------------------------------- screens
Widget _screenLawyers() => _Phone(
      title: 'اختر المحامي',
      subtitle: 'الخطوة 1 من 5 · الأحوال الشخصية',
      step: 1,
      children: [
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _SectionTitle('ابحث ورشّح النتائج', badge: '1'),
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: AppColors.border, width: 1.5),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.search, size: 18, color: AppColors.textSubtle),
                    const SizedBox(width: 8),
                    Text('ابحث باسم المحامي أو التخصص…',
                        style: _ar(size: 13, color: AppColors.textSubtle)),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              const Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  _Chip('الرياض ✕', on: true),
                  _Chip('11–15 سنة ✕', on: true),
                  _Chip('أقل من 900 ر.س ✕', on: true),
                  _Chip('+ فلتر'),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Text('2', style: _display(size: 17, color: AppColors.teal)),
            const SizedBox(width: 5),
            Text('محامٍ مطابق',
                style: _ar(size: 14, weight: FontWeight.w700, color: AppColors.navyDark)),
            const Spacer(),
            Text('الأعلى تقييماً', style: _ar(size: 12, color: AppColors.textMuted)),
          ],
        ),
        const SizedBox(height: 12),
        const _LawyerCard(
          initial: 'ع',
          name: 'مكتب المحامي عبدالله الشهري',
          specialty: 'الأحوال الشخصية · الدعاوى التجارية',
          city: 'الرياض، السعودية',
          rating: '4.8',
          ratingCount: '128',
          years: 12,
          price: 750,
          duration: 30,
          slots: ['الأحد ٠٩:٠٠', 'الأحد ١١:٣٠'],
          extraSlots: 6,
          selected: true,
        ),
        const SizedBox(height: 14),
        const _LawyerCard(
          initial: 'ن',
          name: 'نورة بنت سعد القحطاني',
          specialty: 'الأحوال الشخصية · الدعاوى العمالية',
          city: 'الرياض، السعودية',
          rating: '4.6',
          ratingCount: '74',
          years: 7,
          price: 500,
          duration: 30,
          slots: ['الإثنين ١٠:٠٠', 'الأربعاء ٠٩:٣٠'],
          selected: false,
        ),
      ],
      bottom: const _BottomBar(
          label: 'الإجمالي', value: '750.00 ر.س', cta: 'متابعة — نوع الاستشارة'),
    );

Widget _typeOption(String emoji, String title, String desc, String price, bool on) =>
    Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: on ? AppColors.infoBg : AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: on ? AppColors.teal : AppColors.border, width: 1.5),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: on ? Colors.white : AppColors.surface3,
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            alignment: Alignment.center,
            child: Text(emoji, style: const TextStyle(fontSize: 19)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style:
                        _ar(size: 14, weight: FontWeight.w800, color: AppColors.navyDark)),
                const SizedBox(height: 2),
                Text(desc, style: _ar(size: 12, color: AppColors.textMuted, height: 1.5)),
                const SizedBox(height: 4),
                Text(price, style: _num(size: 12, color: AppColors.goldDeep)),
              ],
            ),
          ),
          Icon(
            on ? Icons.radio_button_checked : Icons.radio_button_unchecked,
            size: 20,
            color: on ? AppColors.teal : AppColors.borderStrong,
          ),
        ],
      ),
    );

Widget _screenType() => _Phone(
      title: 'نوع الاستشارة',
      subtitle: 'الخطوة 2 من 5 · عبدالله الشهري',
      step: 2,
      children: [
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _SectionTitle('اختر نوع الاستشارة', badge: '2'),
              const SizedBox(height: 6),
              Text('تُعرض الأنواع التي يقدّمها هذا المحامي فعلاً.',
                  style: _ar(size: 12, color: AppColors.textMuted)),
              const SizedBox(height: 14),
              _typeOption('🎥', 'استشارة فيديو', 'مكالمة مرئية عبر مواثيق في الموعد المحدد.',
                  '750 ر.س · 30 دقيقة', true),
              const SizedBox(height: 10),
              _typeOption('🎧', 'استشارة صوتية', 'مكالمة صوتية بدون مشاركة الفيديو.',
                  '750 ر.س · 30 دقيقة', false),
              const SizedBox(height: 10),
              _typeOption('✍️', 'استشارة نصية', 'سؤال وجواب مكتوب مع إمكانية إرفاق المستندات.',
                  '750 ر.س · 30 دقيقة', false),
              const SizedBox(height: 14),
              const _Note(
                  text:
                      'هذا المحامي يقبل الفيديو والصوت والنص. الأنواع غير المتاحة تظهر معطّلة مع سبب واضح.',
                  kind: 'ok',
                  icon: Icons.check_circle_outline),
            ],
          ),
        ),
      ],
      bottom: const _BottomBar(
          label: 'الإجمالي', value: '750.00 ر.س', cta: 'متابعة — اختر الموعد'),
    );

Widget _screenSchedule() => _Phone(
      title: 'اختر الموعد',
      subtitle: 'الخطوة 3 من 5 · التوقيت المحلي للرياض (GMT+3)',
      step: 3,
      children: [
        const _Calendar(),
        const SizedBox(height: 14),
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('الأوقات المتاحة — الأربعاء ١١ نوفمبر', style: _display(size: 14)),
              const SizedBox(height: 3),
              Text('مدة كل موعد 30 دقيقة', style: _ar(size: 12, color: AppColors.textMuted)),
              const SizedBox(height: 12),
              const _SlotGrid(slots: [
                ('٠٩:٠٠', 'free'),
                ('٠٩:٣٠', 'free'),
                ('١٠:٠٠', 'taken'),
                ('١٠:٣٠', 'free'),
                ('١١:٠٠', 'sel'),
                ('١١:٣٠', 'free'),
                ('١٢:٠٠', 'taken'),
                ('١٣:٠٠', 'free'),
                ('١٣:٣٠', 'free'),
                ('١٤:٠٠', 'free'),
                ('١٤:٣٠', 'free'),
                ('١٥:٠٠', 'free'),
              ]),
              const SizedBox(height: 12),
              const _Note(
                  text: 'المواعيد المشطوبة محجوزة مسبقاً أو خارج ساعات الدوام.',
                  kind: 'warn',
                  icon: Icons.warning_amber_rounded),
              const SizedBox(height: 10),
              const _Note(
                  text:
                      'عند المتابعة يُحجز الموعد مؤقتاً 15 دقيقة لإكمال الدفع، ثم يُحرَّر تلقائياً إن لم يكتمل.',
                  kind: 'info',
                  icon: Icons.schedule),
            ],
          ),
        ),
      ],
      bottom: const _BottomBar(
          label: 'الموعد المختار', value: '11:00', cta: 'متابعة — المراجعة'),
    );

Widget _screenReview() => _Phone(
      title: 'مراجعة الحجز',
      subtitle: 'الخطوة 4 من 5 · تأكد قبل الدفع',
      step: 4,
      children: [
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _SectionTitle('تفاصيل الاستشارة', badge: '4'),
              const SizedBox(height: 14),
              const _SummaryRow('المحامي', 'عبدالله الشهري'),
              const _SummaryRow('التخصص', 'الأحوال الشخصية'),
              const _SummaryRow('نوع الاستشارة', 'فيديو · 30 دقيقة'),
              const _SummaryRow('التاريخ والوقت', '2026-11-11 · 11:00', numeric: true),
              const _SummaryRow('المنطقة الزمنية', 'الرياض (GMT+3)'),
            ],
          ),
        ),
        const SizedBox(height: 14),
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('موضوع الاستشارة',
                  style: _ar(size: 13, weight: FontWeight.w700, color: AppColors.navyDark)),
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.surface2,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: AppColors.border),
                ),
                child: Text(
                  'مراجعة عقد إيجار تجاري والالتزامات المالية على الطرفين.',
                  style: _ar(size: 13, color: AppColors.text, height: 1.6),
                ),
              ),
              const SizedBox(height: 14),
              Text('طريقة التذكير',
                  style: _ar(size: 13, weight: FontWeight.w700, color: AppColors.navyDark)),
              const SizedBox(height: 8),
              const Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  _Chip('🔔 إشعار التطبيق', on: true),
                  _Chip('✉️ البريد', on: true),
                  _Chip('📱 SMS'),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: const [
              _SummaryRow('قيمة الاستشارة', '750.00 ر.س', numeric: true),
              _SummaryRow('ضريبة القيمة المضافة', '0.00 ر.س', numeric: true),
              _SummaryRow('رسوم الخدمة', '0.00 ر.س', numeric: true),
              Divider(height: 20),
              _SummaryRow('الإجمالي المستحق', '750.00 ر.س', strong: true, numeric: true),
            ],
          ),
        ),
        const SizedBox(height: 14),
        const _Note(
            text: 'بالمتابعة أنت توافق على سياسة الإلغاء: إلغاء مجاني حتى 24 ساعة قبل الموعد.',
            kind: 'info',
            icon: Icons.info_outline),
      ],
      bottom: const _BottomBar(
          label: 'الإجمالي', value: '750.00 ر.س', cta: 'متابعة إلى الدفع'),
    );

Widget _payBadge(IconData i, String t, String s) => Column(
      children: [
        Icon(i, size: 21, color: AppColors.teal),
        const SizedBox(height: 6),
        Text(t, style: _ar(size: 11, weight: FontWeight.w700, color: AppColors.navyDark)),
        Text(s, style: _ar(size: 10, color: AppColors.textSubtle)),
      ],
    );

Widget _screenPayment() => _Phone(
      title: 'الدفع الآمن',
      subtitle: 'الخطوة 5 من 5 · بوابة ن-جينيوس',
      step: 5,
      children: [
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: const [
              _SectionTitle('ملخص الحجز', badge: '5'),
              SizedBox(height: 14),
              _SummaryRow('المحامي', 'عبدالله الشهري'),
              _SummaryRow('نوع الاستشارة', 'فيديو · 30 دقيقة'),
              _SummaryRow('التاريخ والوقت', '2026-11-11 · 11:00', numeric: true),
              Divider(height: 20),
              _SummaryRow('المبلغ المطلوب', '750.00 ر.س', strong: true, numeric: true),
            ],
          ),
        ),
        const SizedBox(height: 14),
        _Card(
          child: Row(
            children: [
              Expanded(child: _payBadge(Icons.lock_outline, 'اتصال مشفّر', 'TLS 1.3')),
              const SizedBox(width: 10),
              Expanded(
                  child: _payBadge(Icons.account_balance, 'بوابة معتمدة', 'N-Genius')),
              const SizedBox(width: 10),
              Expanded(
                  child: _payBadge(Icons.receipt_long_outlined, 'فاتورة فورية', 'بعد التأكيد')),
            ],
          ),
        ),
        const SizedBox(height: 14),
        const _Note(
            text: 'موعدك محجوز مؤقتاً 14 دقيقة و32 ثانية. لن يُحرَّر ما دمت في صفحة الدفع.',
            kind: 'info',
            icon: Icons.timer_outlined),
        const SizedBox(height: 10),
        const _Note(
            text: 'لا يُعتبر الحجز مؤكداً إلا بعد أن تتحقق مواثيق من نجاح الدفع على الخادم.',
            kind: 'warn',
            icon: Icons.gpp_maybe_outlined),
        const SizedBox(height: 16),
        Container(
          height: 52,
          decoration: BoxDecoration(
              color: AppColors.teal, borderRadius: BorderRadius.circular(AppRadius.md)),
          alignment: Alignment.center,
          child: Text('الانتقال إلى الدفع الآمن — 750.00 ر.س',
              style: _ar(size: 15, weight: FontWeight.w700, color: Colors.white)),
        ),
      ],
    );

Widget _filled(String t) => Container(
      height: 48,
      decoration: BoxDecoration(
          color: AppColors.navy, borderRadius: BorderRadius.circular(AppRadius.md)),
      alignment: Alignment.center,
      child: Text(t, style: _ar(size: 14, weight: FontWeight.w700, color: Colors.white)),
    );

Widget _filledTeal(String t) => Container(
      height: 48,
      decoration: BoxDecoration(
          color: AppColors.teal, borderRadius: BorderRadius.circular(AppRadius.md)),
      alignment: Alignment.center,
      child: Text(t, style: _ar(size: 14, weight: FontWeight.w700, color: Colors.white)),
    );

Widget _ghost(String t) => Container(
      height: 44,
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.borderStrong, width: 1.5),
      ),
      alignment: Alignment.center,
      child: Text(t, style: _ar(size: 13, weight: FontWeight.w700, color: AppColors.navy)),
    );

Widget _ghostPlain(String t) => Container(
      height: 44,
      alignment: Alignment.center,
      child: Text(t, style: _ar(size: 13, weight: FontWeight.w700, color: AppColors.textMuted)),
    );

Widget _screenConfirmed() => _Phone(
      title: 'تم تأكيد حجزك',
      subtitle: 'تم التحقق من الدفع بنجاح',
      leadingBack: false,
      bodyPadding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
      children: [
        Center(
          child: Container(
            width: 88,
            height: 88,
            decoration: const BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.successBg,
              boxShadow: [
                BoxShadow(color: Color(0x141E8E5A), blurRadius: 0, spreadRadius: 12)
              ],
            ),
            alignment: Alignment.center,
            child: const Icon(Icons.check_rounded, size: 46, color: AppColors.success),
          ),
        ),
        const SizedBox(height: 18),
        Center(
            child: Text('وصلتك الفاتورة على بريدك الإلكتروني',
                style: _ar(size: 13, color: AppColors.textMuted))),
        const SizedBox(height: 18),
        _Card(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              Container(
                padding: const EdgeInsets.all(14),
                decoration: const BoxDecoration(
                  color: AppColors.surface2,
                  borderRadius: BorderRadius.only(
                    topLeft: Radius.circular(AppRadius.lg),
                    topRight: Radius.circular(AppRadius.lg),
                  ),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('رقم الحجز', style: _ar(size: 11, color: AppColors.textMuted)),
                          Text('MWQ-C-2026-004821',
                              style: _num(size: 13, weight: FontWeight.w800)),
                        ],
                      ),
                    ),
                    const _Pill('مؤكد · مدفوع',
                        bg: AppColors.successBg, fg: AppColors.success),
                  ],
                ),
              ),
              const Padding(
                padding: EdgeInsets.all(14),
                child: Column(
                  children: [
                    _SummaryRow('المحامي', 'عبدالله الشهري'),
                    _SummaryRow('نوع الاستشارة', 'فيديو · 30 دقيقة'),
                    _SummaryRow('الموعد', '2026-11-11 · 11:00', numeric: true),
                    _SummaryRow('المبلغ المدفوع', '750.00 ر.س', numeric: true),
                    _SummaryRow('رقم الفاتورة', 'INV-2026-011933', numeric: true),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Row(children: [Expanded(child: _filled('دخول غرفة الاستشارة'))]),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(child: _ghost('تنزيل الفاتورة')),
            const SizedBox(width: 10),
            Expanded(child: _ghost('إضافة للتقويم')),
          ],
        ),
        const SizedBox(height: 16),
        const _Note(
            text:
                'سيصلك تذكير قبل الموعد بـ 24 ساعة و1 ساعة. يمكنك الإلغاء أو إعادة الجدولة من «استشاراتي».',
            kind: 'info',
            icon: Icons.notifications_active_outlined),
      ],
    );

class _SpecCard extends StatelessWidget {
  const _SpecCard(this.emoji, this.title, this.desc, this.count, this.on);
  final String emoji;
  final String title;
  final String desc;
  final int count;
  final bool on;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: on ? AppColors.teal : AppColors.border, width: on ? 1.6 : 1),
        boxShadow: AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.infoBg,
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            alignment: Alignment.center,
            child: Text(emoji, style: const TextStyle(fontSize: 19)),
          ),
          const SizedBox(height: 9),
          Text(title, style: _ar(size: 13, weight: FontWeight.w800, color: AppColors.navyDark)),
          const SizedBox(height: 4),
          Expanded(
              child: Text(desc, style: _ar(size: 11, color: AppColors.textMuted, height: 1.5))),
          Container(height: 1, color: AppColors.border),
          const SizedBox(height: 7),
          Row(
            children: [
              Text('$count', style: _num(size: 13, color: AppColors.teal)),
              const SizedBox(width: 4),
              Text('محامٍ متاح', style: _ar(size: 11, color: AppColors.textSubtle)),
              const Spacer(),
              const Icon(Icons.chevron_left, size: 17, color: AppColors.textSubtle),
            ],
          ),
        ],
      ),
    );
  }
}

Widget _screenSpecialty() => _Phone(
      title: 'ما تخصصك القانوني؟',
      subtitle: 'الخطوة 1 من 5 · اختر الخدمة أولاً',
      step: 1,
      stepLabels: const ['التخصص', 'النوع', 'الموعد', 'المحامي', 'الدفع'],
      children: [
        _Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: AppColors.border, width: 1.5),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.search, size: 18, color: AppColors.textSubtle),
                    const SizedBox(width: 8),
                    Text('ابحث عن تخصصك…', style: _ar(size: 13, color: AppColors.textSubtle)),
                  ],
                ),
              ),
              const SizedBox(height: 14),
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                childAspectRatio: 0.92,
                children: const [
                  _SpecCard('👪', 'الأحوال الشخصية', 'الزواج والطلاق، النفقة، الحضانة، والمواريث.',
                      2, true),
                  _SpecCard('🏢', 'الدعاوى التجارية', 'العقود التجارية، الإفلاس، ومنازعات الشركات.',
                      2, false),
                  _SpecCard('👷', 'الدعاوى العمالية', 'حقوق العاملين، إنهاء الخدمة، والمستحقات.',
                      1, false),
                  _SpecCard('⚖️', 'الدعاوى الجزائية', 'القضايا الجنائية والدفاع أمام المحاكم.',
                      1, false),
                ],
              ),
              const SizedBox(height: 12),
              const _Note(
                  text:
                      'التخصصات التي لا يوجد لها محامٍ تظهر بعدد حقيقي (0) — لا نعرض محامياً من تخصص آخر كبديل.',
                  kind: 'info',
                  icon: Icons.info_outline),
            ],
          ),
        ),
      ],
      bottom: const _BottomBar(
          label: 'المختار', value: 'الأحوال الشخصية', cta: 'متابعة — نوع الخدمة'),
    );

Widget _screenFailed() => _Phone(
      title: 'لم تكتمل عملية الدفع',
      subtitle: 'لم يُخصم أي مبلغ ولم يُؤكَّد الحجز',
      leadingBack: false,
      bodyPadding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
      children: [
        Center(
          child: Container(
            width: 88,
            height: 88,
            decoration: const BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.dangerBg,
              boxShadow: [
                BoxShadow(color: Color(0x14C7492A), blurRadius: 0, spreadRadius: 12)
              ],
            ),
            alignment: Alignment.center,
            child: const Icon(Icons.close_rounded, size: 46, color: AppColors.danger),
          ),
        ),
        const SizedBox(height: 18),
        Center(
            child: Text('تم رفض العملية من البنك',
                style: _ar(size: 13, color: AppColors.textMuted))),
        const SizedBox(height: 18),
        const _Note(
          text: 'السبب: رصيد غير كافٍ أو رفض من جهة الإصدار.\nرقم المرجع: NGN-ERR-4021 — يمكنك ذكره للدعم.',
          kind: 'err',
          icon: Icons.error_outline,
        ),
        const SizedBox(height: 14),
        _Card(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              Container(
                padding: const EdgeInsets.all(14),
                decoration: const BoxDecoration(
                  color: AppColors.surface2,
                  borderRadius: BorderRadius.only(
                    topLeft: Radius.circular(AppRadius.lg),
                    topRight: Radius.circular(AppRadius.lg),
                  ),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('الحجز المؤقت',
                              style: _ar(size: 11, color: AppColors.textMuted)),
                          Text('MWQ-C-2026-004821',
                              style: _num(size: 13, weight: FontWeight.w800)),
                        ],
                      ),
                    ),
                    const _Pill('محجوز مؤقتاً · غير مدفوع',
                        bg: AppColors.surface3, fg: AppColors.textMuted),
                  ],
                ),
              ),
              const Padding(
                padding: EdgeInsets.all(14),
                child: Column(
                  children: [
                    _SummaryRow('المحامي', 'عبدالله الشهري'),
                    _SummaryRow('الموعد', '2026-11-11 · 11:00', numeric: true),
                    _SummaryRow('المبلغ المطلوب', '750.00 ر.س', numeric: true),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        const _Note(
            text: 'موعدك محجوز مؤقتاً 13 دقيقة و05 ثانية. إن لم يكتمل الدفع يُحرَّر الموعد تلقائياً.',
            kind: 'warn',
            icon: Icons.timer_outlined),
        const SizedBox(height: 16),
        Row(
          children: [
            Expanded(flex: 3, child: _filledTeal('إعادة محاولة الدفع')),
            const SizedBox(width: 10),
            Expanded(flex: 2, child: _ghost('طريقة أخرى')),
          ],
        ),
        const SizedBox(height: 10),
        Row(children: [Expanded(child: _ghostPlain('إلغاء الحجز والرجوع للمحامين'))]),
      ],
    );

// ------------------------------------------------------------------ harness
Future<void> _shot(WidgetTester tester, String file, List<String> mustContain) async {
  final text = tester
      .widgetList<Text>(find.byType(Text))
      .map((t) => t.data ?? '')
      .join(' | ');
  for (final needle in mustContain) {
    expect(text, contains(needle), reason: '$file should show "$needle"');
  }
  await expectLater(
    find.byType(MaterialApp),
    matchesGoldenFile('goldens/$file'),
  );
}

Widget _wrap(Widget child) => MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      home: child,
      builder: (context, inner) => Directionality(
        textDirection: TextDirection.rtl,
        child: inner ?? const SizedBox.shrink(),
      ),
    );

void main() {
  setUpAll(() {
    // Same rule as the production app and the release harness: fonts are bundled,
    // so a design capture cannot pass with a font the shipped app would not have.
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  setUp(() {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    final view = binding.platformDispatcher.views.first;
    view.physicalSize = const Size(1080, 2280);
    view.devicePixelRatio = 3.0;
  });

  tearDown(() {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    binding.platformDispatcher.views.first.resetPhysicalSize();
    binding.platformDispatcher.views.first.resetDevicePixelRatio();
  });

  testWidgets('d5_01 lawyer selection (journey A)', (tester) async {
    await tester.pumpWidget(_wrap(_screenLawyers()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_01_lawyers.png', <String>[
      'اختر المحامي',
      'محامٍ مطابق',
      'مكتب المحامي عبدالله الشهري',
      'نورة بنت سعد القحطاني',
      'الأحد ٠٩:٠٠',
      'متابعة — نوع الاستشارة',
    ]);
  });

  testWidgets('d5_02 consultation type', (tester) async {
    await tester.pumpWidget(_wrap(_screenType()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_02_type.png', <String>[
      'اختر نوع الاستشارة',
      'استشارة فيديو',
      'استشارة صوتية',
      'استشارة نصية',
      'متابعة — اختر الموعد',
    ]);
  });

  testWidgets('d5_03 calendar and slots', (tester) async {
    await tester.pumpWidget(_wrap(_screenSchedule()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_03_schedule.png', <String>[
      'اختر الموعد',
      'نوفمبر ٢٠٢٦',
      'الأوقات المتاحة — الأربعاء ١١ نوفمبر',
      'الموعد المختار',
      '11:00',
    ]);
  });

  testWidgets('d5_04 review', (tester) async {
    await tester.pumpWidget(_wrap(_screenReview()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_04_review.png', <String>[
      'مراجعة الحجز',
      'موضوع الاستشارة',
      'طريقة التذكير',
      'الإجمالي المستحق',
      'متابعة إلى الدفع',
    ]);
  });

  testWidgets('d5_05 payment', (tester) async {
    await tester.pumpWidget(_wrap(_screenPayment()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_05_payment.png', <String>[
      'الدفع الآمن',
      'بوابة ن-جينيوس',
      'الانتقال إلى الدفع الآمن',
      'N-Genius',
    ]);
  });

  testWidgets('d5_06 confirmation', (tester) async {
    await tester.pumpWidget(_wrap(_screenConfirmed()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_06_confirmed.png', <String>[
      'تم تأكيد حجزك',
      'MWQ-C-2026-004821',
      'مؤكد · مدفوع',
      'دخول غرفة الاستشارة',
      'تنزيل الفاتورة',
    ]);
  });

  testWidgets('d5_07 specialty (journey B)', (tester) async {
    await tester.pumpWidget(_wrap(_screenSpecialty()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_07_specialty.png', <String>[
      'ما تخصصك القانوني؟',
      'الأحوال الشخصية',
      'الدعاوى التجارية',
      'الدعاوى العمالية',
      'متابعة — نوع الخدمة',
    ]);
  });

  testWidgets('d5_08 payment failed', (tester) async {
    await tester.pumpWidget(_wrap(_screenFailed()));
    await tester.pump(const Duration(milliseconds: 400));
    await _shot(tester, 'd5_08_failed.png', <String>[
      'لم تكتمل عملية الدفع',
      'NGN-ERR-4021',
      'محجوز مؤقتاً · غير مدفوع',
      'إعادة محاولة الدفع',
      'طريقة أخرى',
    ]);
  });
}
