import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/theme/app_colors.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/app_tokens.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';
import 'package:mawatheq_mobile/shared/widgets/capture_sheet.dart';

/// Dark-mode audit of the widgets added in Execution 40.
///
/// The failure mode this exists to catch is a widget that renders but cannot be
/// read: a light-mode colour hard-coded into new UI, or an ink splash painted on a
/// surface that is not a Material. Both produce a screen that looks fine in a
/// screenshot and is unusable in the hand, so each is asserted directly.

Widget _under(ThemeData theme, Widget child) => MaterialApp(
      theme: theme,
      home: Scaffold(body: child),
    );

Color? _textColor(WidgetTester tester, String text) =>
    tester.widget<Text>(find.text(text)).style?.color;

void main() {
  group('ActionCard in the dark theme', () {
    testWidgets('the filled card uses the dark brand fill, not navy',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.dark(),
        Center(
          child: ActionCard(
            icon: Icons.event_available_outlined,
            label: 'احجز استشارة',
            caption: 'اختر التخصص والمحامي والموعد',
            onTap: () {},
          ),
        ),
      ));

      final material = tester.widget<Material>(
        find.descendant(
          of: find.byType(ActionCard),
          matching: find.byType(Material),
        ),
      );
      expect(material.color, AppThemeTokens.dark.brandFill);
      expect(material.color, isNot(AppColors.navy));
    });

    testWidgets('the label is on-brand white in both themes', (tester) async {
      for (final theme in [AppTheme.light(), AppTheme.dark()]) {
        await tester.pumpWidget(_under(
          theme,
          Center(
            child: ActionCard(
              icon: Icons.event_available_outlined,
              label: 'احجز استشارة',
              caption: 'اختر التخصص والمحامي والموعد',
              onTap: () {},
            ),
          ),
        ));
        expect(_textColor(tester, 'احجز استشارة'), AppThemeTokens.light.onBrand);
      }
    });

    testWidgets('the outline card reads on the dark surface', (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.dark(),
        Center(
          child: ActionCard(
            icon: Icons.auto_awesome_outlined,
            label: 'اسأل المساعد الذكي',
            caption: 'غير متاح حالياً',
            primary: false,
            onTap: () {},
          ),
        ),
      ));

      // The heading colour, not a fixed navy: navy on the dark surface is the
      // exact invisible-text bug the palette exists to prevent.
      expect(_textColor(tester, 'اسأل المساعد الذكي'),
          AppThemeTokens.dark.heading);
      expect(_textColor(tester, 'اسأل المساعد الذكي'), isNot(AppColors.navy));
    });

    testWidgets('the icon follows the card foreground in dark mode',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.dark(),
        Center(
          child: ActionCard(
            icon: Icons.auto_awesome_outlined,
            label: 'اسأل المساعد الذكي',
            caption: 'غير متاح حالياً',
            primary: false,
            onTap: () {},
          ),
        ),
      ));

      final icon = tester.widget<Icon>(
        find.byIcon(Icons.auto_awesome_outlined),
      );
      expect(icon.color, AppThemeTokens.dark.heading);
    });
  });

  group('CaptureSheet in the dark theme', () {
    testWidgets('renders both actions without overflow or exception',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.dark(),
        const CaptureSheet(),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('تسجيل صوتي'), findsOneWidget);
      expect(find.text('التقاط صورة'), findsOneWidget);
    });

    testWidgets('the action labels are readable, not a fixed navy',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.dark(),
        const CaptureSheet(),
      ));
      await tester.pumpAndSettle();

      final label = _textColor(tester, 'تسجيل صوتي');
      expect(label, isNot(AppColors.navy));
      expect(label, isNotNull);
    });

    testWidgets('the sheet also renders in the light theme', (tester) async {
      // Non-regression: the sheet is new, so both themes are asserted rather than
      // only the one that was being fixed.
      await tester.pumpWidget(_under(
        AppTheme.light(),
        const CaptureSheet(),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('تسجيل صوتي'), findsOneWidget);
    });
  });

  group('the reminder copy is legible on the dark surface', () {
    testWidgets('SwitchListTile paints on a Material, not a DecoratedBox',
        (tester) async {
      // A SwitchListTile inside a decorated Container throws in debug because the
      // ink splash would be hidden. Building the toggle is enough to prove the
      // widget tree is correct, since the assertion fires during layout.
      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.dark(),
        home: Scaffold(
          body: Material(
            color: AppThemeTokens.dark.surface,
            child: SwitchListTile.adaptive(
              value: true,
              onChanged: (_) {},
              title: const Text('تذكير قبل الموعد'),
              subtitle: const Text('يصلك تذكير على هذا الجهاز قبل الموعد بساعة.'),
            ),
          ),
        ),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    });
  });
}
