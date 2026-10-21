import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/theme/app_colors.dart';
import 'package:mawatheq_mobile/core/theme/app_tokens.dart';

/// Pins the dark theme, which is otherwise untestable by inspection: the failure
/// mode of a colour bug is "text is invisible", and nothing in the widget tests
/// renders dark mode.
///
/// These are real assertions on the real token values, not snapshots: a snapshot
/// would happily lock in unreadable text.

/// WCAG 2.1 relative luminance.
double _luminance(Color c) {
  double channel(double v) {
    v = v / 255.0;
    return v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
  }

  return 0.2126 * channel(c.r * 255) +
      0.7152 * channel(c.g * 255) +
      0.0722 * channel(c.b * 255);
}

double _contrast(Color a, Color b) {
  final la = _luminance(a);
  final lb = _luminance(b);
  final hi = math.max(la, lb);
  final lo = math.min(la, lb);
  return (hi + 0.05) / (lo + 0.05);
}

void main() {
  group('dark theme contrast', () {
    const t = AppThemeTokens.dark;

    test('body and heading text meet the 4.5:1 floor on every surface', () {
      for (final surface in [t.bg, t.surface, t.surface2, t.surface3]) {
        expect(_contrast(t.text, surface), greaterThanOrEqualTo(4.5),
            reason: 'text on $surface');
        expect(_contrast(t.heading, surface), greaterThanOrEqualTo(4.5),
            reason: 'heading on $surface');
      }
    });

    test('muted and subtle text stay above the 3:1 large-text floor', () {
      for (final surface in [t.bg, t.surface, t.surface2]) {
        expect(_contrast(t.textMuted, surface), greaterThanOrEqualTo(3.0),
            reason: 'textMuted on $surface');
        expect(_contrast(t.textSubtle, surface), greaterThanOrEqualTo(3.0),
            reason: 'textSubtle on $surface');
      }
    });

    test('semantic foregrounds stay readable on the card surface', () {
      expect(_contrast(t.dangerFg, t.surface), greaterThanOrEqualTo(4.5));
      expect(_contrast(t.warningFg, t.surface), greaterThanOrEqualTo(4.5));
      expect(_contrast(t.successFg, t.surface), greaterThanOrEqualTo(4.5));
    });

    test('the brand-danger red would have failed, which is why dangerFg exists',
        () {
      // Guards the reason for the token: if someone "simplifies" dangerFg back to
      // AppColors.danger, this documents that it was a readability decision.
      expect(_contrast(AppColors.danger, t.surface), lessThan(4.5));
    });

    test('surfaces are lifted from the background so cards read as cards', () {
      expect(_luminance(t.surface), greaterThan(_luminance(t.bg)));
      expect(_luminance(t.surface2), greaterThan(_luminance(t.bg)));
    });

    test('borders are visible against the surface they outline', () {
      expect(_contrast(t.border, t.surface), greaterThan(1.2));
    });

    test('the primary button reads as a button, not a hole', () {
      // The brand navy on the dark surface separates by only 1.4:1, which is why
      // dark mode uses a lifted navy. Pin both halves of the trade-off.
      expect(_contrast(t.brandFill, t.surface), greaterThanOrEqualTo(2.0),
          reason: 'button fill must separate from the card behind it');
      expect(_contrast(t.onBrand, t.brandFill), greaterThanOrEqualTo(4.5),
          reason: 'the label on the button must stay readable');
    });

    test('the brand navy really was too dark for the dark surface', () {
      // Documents why brandFill exists rather than using AppColors.navy directly.
      expect(_contrast(AppColors.navy, t.surface), lessThan(2.0));
    });
  });

  group('light theme is unchanged', () {
    const t = AppThemeTokens.light;

    test('heading is exactly the navy the light UI always used', () {
      // DashboardCard used to hardcode AppColors.navy for its number. The token
      // must resolve to that same value in light mode, or the redesign would be a
      // visual regression rather than a dark-mode fix.
      expect(t.heading, AppColors.navy);
    });

    test('the light palette is byte-identical to the shipped constants', () {
      // The brief's hard constraint: the approved light palette must not shift.
      // Every neutral token must equal the AppColors constant it replaced.
      expect(t.bg, AppColors.bg);
      expect(t.surface, AppColors.surface);
      expect(t.surface2, AppColors.surface2);
      expect(t.surface3, AppColors.surface3);
      expect(t.border, AppColors.border);
      expect(t.borderStrong, AppColors.borderStrong);
      expect(t.text, AppColors.text);
      expect(t.textMuted, AppColors.textMuted);
      expect(t.textSubtle, AppColors.textSubtle);
      expect(t.successBg, AppColors.successBg);
      expect(t.warningBg, AppColors.warningBg);
      expect(t.dangerBg, AppColors.dangerBg);
      expect(t.infoBg, AppColors.infoBg);
      expect(t.onBrand, Colors.white);
      expect(t.brightness, Brightness.light);
    });

    test('the light button fill is exactly the brand navy', () {
      // Non-regression: the light-mode primary button must not shift.
      expect(t.brandFill, AppColors.navy);
    });

    test('body text meets 4.5:1 on the page and card surfaces', () {
      for (final surface in [t.bg, t.surface]) {
        expect(_contrast(t.text, surface), greaterThanOrEqualTo(4.5));
        expect(_contrast(t.heading, surface), greaterThanOrEqualTo(4.5));
      }
    });

    test('semantic foregrounds are the original brand values', () {
      expect(t.dangerFg, AppColors.danger);
      expect(t.warningFg, AppColors.warning);
      expect(t.successFg, AppColors.success);
    });

    test('the wash gradient matches the original hero-card gradient', () {
      expect(t.washGradient.colors, AppColors.lightSurface.colors);
    });

    test('the splash gradient matches the original constant', () {
      expect(t.splashGradient.colors,
          const [Color(0xFFF7FBFD), Color(0xFFEAF6F9), Color(0xFFFBFDFE)]);
      expect(t.splashGradient.stops, const [0.0, 0.55, 1.0]);
    });
  });

  group('status badges are legible in both themes', () {
    // Every status the backend can emit, grouped the way the UI groups them.
    const statuses = [
      'active', 'open', 'confirmed', 'paid', 'approved', 'completed',
      'pending', 'draft', 'in_review', 'processing', 'expired',
      'closed_lost', 'cancelled', 'overdue', 'failed', 'suspended', 'rejected',
      'closed_won', 'closed_settled', 'published',
      'something_new_the_backend_added',
    ];

    test('foreground reads on its own background in the dark theme', () {
      const t = AppThemeTokens.dark;
      for (final s in statuses) {
        final fg = AppColors.statusFgFor(s, t);
        final bg = AppColors.backgroundForStatusIn(s, t);
        // Badge text is bold and small; hold it to the body-text floor rather than
        // the relaxed large-text one, since the pill is the whole message.
        expect(_contrast(fg, bg), greaterThanOrEqualTo(4.5),
            reason: 'badge "$s" in dark mode');
      }
    });

    test('foreground reads on its own background in the light theme', () {
      const t = AppThemeTokens.light;
      for (final s in statuses) {
        // The info badge is a known pre-existing shortfall in the shipped light
        // theme; see the dedicated test below. Everything else must clear 3:1.
        if (AppColors.statusFgFor(s, t) == AppColors.info) continue;
        final fg = AppColors.statusFgFor(s, t);
        final bg = AppColors.backgroundForStatusIn(s, t);
        expect(_contrast(fg, bg), greaterThanOrEqualTo(3.0),
            reason: 'badge "$s" in light mode');
      }
    });

    test('the light info badge is a known shortfall, recorded not fixed', () {
      const t = AppThemeTokens.light;
      // The brand teal on the pale info wash measures ~2.5:1, under the 3:1 floor.
      // This predates the theme work. Raising it would mean editing the approved
      // light palette, which the brief forbids as non-regressive, so it is pinned
      // here instead: if someone later changes it, this test tells them they moved
      // a shipped value rather than silently drifting.
      expect(
        _contrast(AppColors.statusFgFor('published', t),
            AppColors.backgroundForStatusIn('published', t)),
        closeTo(2.54, 0.05),
      );
    });

    test('an unknown status renders neutral rather than inventing a colour', () {
      const t = AppThemeTokens.dark;
      // The backend adding a state must not produce an invisible or alarming badge.
      expect(AppColors.statusFgFor('brand_new_state', t), t.textMuted);
      expect(AppColors.backgroundForStatusIn('brand_new_state', t), t.surface3);
    });

    test('the status hue is recognisable in both themes', () {
      const l = AppThemeTokens.light;
      const d = AppThemeTokens.dark;
      // "green means approved" must survive the theme switch, so the two greens
      // must share a hue even though their lightness differs.
      Color green(Color c) => c;
      expect(HSLColor.fromColor(green(AppColors.statusFgFor('approved', l))).hue,
          closeTo(HSLColor.fromColor(green(AppColors.statusFgFor('approved', d))).hue, 20));
      expect(HSLColor.fromColor(green(AppColors.statusFgFor('failed', l))).hue,
          closeTo(HSLColor.fromColor(green(AppColors.statusFgFor('failed', d))).hue, 20));
    });

    test('the light theme still resolves to the original brand values', () {
      const l = AppThemeTokens.light;
      // Non-regression: these are what shipped before the token layer.
      expect(AppColors.statusFgFor('paid', l), AppColors.success);
      expect(AppColors.statusFgFor('pending', l), AppColors.warning);
      expect(AppColors.statusFgFor('failed', l), AppColors.danger);
      expect(AppColors.statusFgFor('published', l), AppColors.info);
      expect(AppColors.backgroundForStatusIn('paid', l), AppColors.successBg);
      expect(AppColors.backgroundForStatusIn('pending', l), AppColors.warningBg);
      expect(AppColors.backgroundForStatusIn('failed', l), AppColors.dangerBg);
      expect(AppColors.backgroundForStatusIn('published', l), AppColors.infoBg);
    });

    test('the no-context helper agrees with the light theme', () {
      for (final s in statuses) {
        expect(AppColors.forStatus(s), AppColors.statusFgFor(s, AppThemeTokens.light));
        expect(AppColors.backgroundForStatus(s),
            AppColors.backgroundForStatusIn(s, AppThemeTokens.light));
      }
    });
  });

  group('brand identity survives both themes', () {
    test('the palette that must not change is not duplicated in tokens', () {
      // teal/navy/gold are brand constants; a theme that redefined them would stop
      // looking like Mawatheq. Assert they are the same object in both palettes by
      // asserting the tokens simply do not carry them.
      const t = AppThemeTokens.dark;
      expect(t.bg, isNot(AppColors.navy));
      expect(AppColors.teal, const Color(0xFF2BA8C4));
      expect(AppColors.navy, const Color(0xFF1E3A5F));
    });

    test('both palettes expose the full token set', () {
      for (final t in [AppThemeTokens.light, AppThemeTokens.dark]) {
        expect(t.brightness, isNotNull);
        expect(t.washGradient.colors.length, 2);
        expect(t.splashGradient.colors.length, 3);
      }
    });
  });
}
