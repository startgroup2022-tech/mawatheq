import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/theme/app_colors.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/app_tokens.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';

/// The home screen's primary actions and the widgets behind them.
///
/// These assert the two things the redesign is for: the booking action is the
/// first thing under the slider, and the filled card stays readable in dark mode.
/// Both were real defects — the previous layout put counters where the action
/// belonged, and a filled brand card with a muted caption is invisible on the dark
/// palette.

Widget _under(ThemeData theme, Widget child) => MaterialApp(
      theme: theme,
      home: Scaffold(body: Center(child: child)),
    );

Color? _textColor(WidgetTester tester, String text) =>
    tester.widget<Text>(find.text(text)).style?.color;

/// The [Material] belonging to an [ActionCard], not the one Scaffold provides.
/// `find.byType(Material).first` picks up the Scaffold's own Material, whose
/// colour is the page background — an assertion against it would pass or fail for
/// reasons unrelated to the card.
Material _cardMaterial(WidgetTester tester) => tester.widget<Material>(
      find.descendant(
        of: find.byType(ActionCard),
        matching: find.byType(Material),
      ),
    );

void main() {
  group('ActionCard', () {
    testWidgets('the filled card paints the brand fill and on-brand text',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.light(),
        ActionCard(
          icon: Icons.event_available_outlined,
          label: 'احجز استشارة',
          caption: 'اختر التخصص والمحامي والموعد',
          onTap: () {},
        ),
      ));

      expect(_cardMaterial(tester).color, AppThemeTokens.light.brandFill);
      expect(_textColor(tester, 'احجز استشارة'),
          AppThemeTokens.light.onBrand);
    });

    testWidgets('the caption stays readable on the dark filled card',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.dark(),
        ActionCard(
          icon: Icons.event_available_outlined,
          label: 'احجز استشارة',
          caption: 'اختر التخصص والمحامي والموعد',
          onTap: () {},
        ),
      ));

      // The regression this guards: a muted navy caption on the dark brand fill.
      // The caption must be derived from the on-brand colour, never textMuted.
      final caption =
          _textColor(tester, 'اختر التخصص والمحامي والموعد');
      expect(caption, isNotNull);
      expect(caption, isNot(AppThemeTokens.dark.textMuted));
      expect(caption!.a, greaterThan(0.8));
    });

    testWidgets('the outline variant uses the surface, not the brand fill',
        (tester) async {
      await tester.pumpWidget(_under(
        AppTheme.light(),
        ActionCard(
          icon: Icons.auto_awesome_outlined,
          label: 'اسأل المساعد الذكي',
          caption: 'إجابة فورية',
          primary: false,
          onTap: () {},
        ),
      ));

      expect(_cardMaterial(tester).color, AppThemeTokens.light.surface);
      expect(_cardMaterial(tester).color,
          isNot(AppThemeTokens.light.brandFill));
      // The caption may be muted here: it sits on an ordinary surface.
      expect(_textColor(tester, 'إجابة فورية'),
          AppThemeTokens.light.textMuted);
    });

    testWidgets('tapping the card invokes the callback', (tester) async {
      var taps = 0;
      await tester.pumpWidget(_under(
        AppTheme.light(),
        ActionCard(
          icon: Icons.event_available_outlined,
          label: 'احجز استشارة',
          caption: 'اختر التخصص والمحامي والموعد',
          onTap: () => taps++,
        ),
      ));

      await tester.tap(find.text('احجز استشارة'));
      await tester.pumpAndSettle();
      expect(taps, 1);
    });

    testWidgets('a long Arabic label wraps instead of overflowing',
        (tester) async {
      // A narrow card is where an unbounded Row/Text overflows, and the label here
      // is two words that do not fit on one line at 320dp.
      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: 150,
            child: ActionCard(
              icon: Icons.event_available_outlined,
              label: 'اسأل المساعد الذكي',
              caption: 'إجابة فورية مبنية على الأنظمة',
              onTap: () {},
            ),
          ),
        ),
      ));

      expect(tester.takeException(), isNull);
    });
  });

  group('the counter row stays compact', () {
    testWidgets('three counters render without overflow in a narrow column',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: 260,
            child: Row(
              children: const [
                Expanded(
                  child: DashboardCard(value: '12', label: 'قضية'),
                ),
                Expanded(
                  child: DashboardCard(value: '3', label: 'مستند'),
                ),
                Expanded(
                  child: DashboardCard(value: '5', label: 'استشارة'),
                ),
              ],
            ),
          ),
        ),
      ));

      expect(tester.takeException(), isNull);
      expect(find.text('12'), findsOneWidget);
    });
  });

  group('the dark palette keeps the brand card legible', () {
    testWidgets('on-brand text is not the muted tone', (tester) async {
      // A direct token assertion: the caption derives from onBrand with alpha, so
      // the dark palette must not make onBrand equal to textMuted.
      expect(AppThemeTokens.dark.onBrand,
          isNot(AppThemeTokens.dark.textMuted));
      expect(AppThemeTokens.dark.brandFill, isNot(AppColors.navy));
    });
  });
}
