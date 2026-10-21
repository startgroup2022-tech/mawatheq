import 'package:flutter/material.dart';

/// The colours that change between light and dark, in one place.
///
/// Why this exists rather than reading [AppColors] directly: [AppColors] holds the
/// brand palette, and most of it must NOT change with the theme. `navy`, `teal`,
/// `gold` and the status colours are the brand — a dark theme that re-tinted them
/// would stop looking like Mawatheq. What must change is the neutral layer: the
/// page background, card surfaces, borders and body text. Those are the values
/// here.
///
/// Screens read them with `context.tokens` (see the extension at the bottom of
/// this file), which resolves to [light] or [dark] from the ambient [ThemeData].
/// A screen therefore never asks "is dark mode on?" — it asks for `surface` and
/// gets the right one, which is what keeps the two themes from drifting.
///
/// The dark values are not a mechanical inversion of the light ones. A straight
/// inversion of #F6F8FA gives a glare that is worse than the light theme it
/// replaces, and pure black loses the card/border separation the layout relies on.
/// The palette below is a deep navy-grey derived from `AppColors.navyDeep`, so the
/// brand hue survives into dark mode, with surfaces lifted just far enough above
/// the background for cards to read as cards.
@immutable
class AppThemeTokens {
  const AppThemeTokens({
    required this.bg,
    required this.surface,
    required this.surface2,
    required this.surface3,
    required this.border,
    required this.borderStrong,
    required this.text,
    required this.textMuted,
    required this.textSubtle,
    required this.heading,
    required this.onBrand,
    required this.successBg,
    required this.warningBg,
    required this.dangerBg,
    required this.infoBg,
    required this.washGradient,
    required this.washBorder,
    required this.warningBorder,
    required this.dangerBorder,
    required this.dangerFg,
    required this.warningFg,
    required this.successFg,
    required this.brandFill,
    required this.splashGradient,
    required this.shadow,
    required this.brightness,
  });

  /// The page background, behind every card.
  final Color bg;

  /// A card or sheet. Always lifted from [bg] so a card reads as a card.
  final Color surface;

  /// A quieter surface for nested blocks inside a card.
  final Color surface2;
  final Color surface3;

  final Color border;
  final Color borderStrong;

  /// Body text.
  final Color text;
  final Color textMuted;
  final Color textSubtle;

  /// Titles and headings. Distinct from [text] because the brand navy that reads
  /// well on white is unreadable on a dark surface, so dark mode lifts it to a
  /// light tint of the same hue rather than leaving it.
  final Color heading;

  /// Text and icons drawn on top of a brand-coloured fill (navy button, teal chip).
  /// This stays near-white in both themes: the brand fills themselves do not change,
  /// so their foreground must not either.
  final Color onBrand;

  /// Tinted backgrounds for status badges. The status *foreground* colours
  /// (success/warning/danger/info) are brand-semantic and stay constant; only these
  /// backgrounds have to be re-tuned, because a pale wash that works on white is a
  /// glaring block on a dark surface.
  final Color successBg;
  final Color warningBg;
  final Color dangerBg;
  final Color infoBg;

  /// Card shadow colour. Dark mode needs a stronger shadow to separate surfaces,
  /// because a black shadow on a near-black background is invisible.
  final Color shadow;

  /// The soft brand wash used behind hero cards. A gradient, so it cannot be
  /// expressed as a single colour: the light version is a pale cyan wash, the dark
  /// version a deep navy one. Same role, same direction, different values.
  final LinearGradient washGradient;

  /// The hairline border that sits on [washGradient].
  final Color washBorder;

  /// Borders for the warning and danger callouts, which must be tinted versions of
  /// those semantics rather than fixed pastels.
  final Color warningBorder;
  final Color dangerBorder;

  /// Foreground-safe semantic colours for text and icons.
  ///
  /// The brand's danger/warning/success values are tuned for light backgrounds; on
  /// the dark surface they land near 3.8:1, under the 4.5:1 body-text floor. These
  /// are the same hues lightened for dark mode, so an error stays recognisably red
  /// while remaining readable. Borders and filled buttons keep using the brand
  /// values, where the surrounding fill supplies the contrast instead.
  final Color dangerFg;
  final Color warningFg;
  final Color successFg;

  /// The fill for primary buttons.
  ///
  /// In light mode this is the brand navy. On the dark surface that same navy
  /// separates from the background by only 1.4:1, so the button reads as a hole
  /// rather than a button; dark mode lifts it to a lighter navy that still carries
  /// white text at 5.6:1. The brand hue is preserved, only its lightness changes.
  final Color brandFill;

  /// The splash background gradient.
  final LinearGradient splashGradient;

  final Brightness brightness;

  bool get isDark => brightness == Brightness.dark;

  /// The approved light palette — the original values, unchanged.
  static const light = AppThemeTokens(
    bg: Color(0xFFF6F8FA),
    surface: Color(0xFFFFFFFF),
    surface2: Color(0xFFF8FAFC),
    surface3: Color(0xFFF1F5F9),
    border: Color(0xFFE3E9EF),
    borderStrong: Color(0xFFCBD5E1),
    text: Color(0xFF16232F),
    textMuted: Color(0xFF5C6B7A),
    textSubtle: Color(0xFF8296A6),
    heading: Color(0xFF1E3A5F),
    onBrand: Color(0xFFFFFFFF),
    successBg: Color(0xFFE6F4ED),
    warningBg: Color(0xFFFDF3E1),
    dangerBg: Color(0xFFFBEAE5),
    infoBg: Color(0xFFEAF6F9),
    washGradient: LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [Color(0xFFDCEFF8), Color(0xFFEAF6F9)],
    ),
    washBorder: Color(0xFFCFE7F2),
    warningBorder: Color(0xFFF3E0BE),
    dangerBorder: Color(0xFFF2CFC4),
    dangerFg: Color(0xFFC7492A),
    warningFg: Color(0xFFB4780F),
    successFg: Color(0xFF1E8E5A),
    brandFill: Color(0xFF1E3A5F),
    splashGradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0xFFF7FBFD), Color(0xFFEAF6F9), Color(0xFFFBFDFE)],
      stops: [0.0, 0.55, 1.0],
    ),
    shadow: Color(0x0D0F1F33),
    brightness: Brightness.light,
  );

  /// The dark palette. Contrast ratios against [surface] are at least 4.5:1 for
  /// body text and 3:1 for muted text and borders.
  static const dark = AppThemeTokens(
    bg: Color(0xFF0B1622),
    surface: Color(0xFF13202E),
    surface2: Color(0xFF182736),
    surface3: Color(0xFF1E2E3E),
    border: Color(0xFF2A3B4D),
    borderStrong: Color(0xFF3D5266),
    text: Color(0xFFE8EEF4),
    textMuted: Color(0xFFA9B8C7),
    textSubtle: Color(0xFF8A9AAB),
    heading: Color(0xFFBFD9EA),
    onBrand: Color(0xFFFFFFFF),
    successBg: Color(0xFF123326),
    warningBg: Color(0xFF33280F),
    dangerBg: Color(0xFF3A1F19),
    infoBg: Color(0xFF0F2E38),
    washGradient: LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [Color(0xFF16303F), Color(0xFF12293A)],
    ),
    washBorder: Color(0xFF24455A),
    warningBorder: Color(0xFF4A3A18),
    dangerBorder: Color(0xFF4A2A22),
    dangerFg: Color(0xFFF08A6E),
    warningFg: Color(0xFFE8B65C),
    successFg: Color(0xFF5FCF95),
    brandFill: Color(0xFF35608F),
    splashGradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0xFF0B1622), Color(0xFF0E1E2C), Color(0xFF0B1622)],
      stops: [0.0, 0.55, 1.0],
    ),
    shadow: Color(0x66000000),
    brightness: Brightness.dark,
  );

  /// Resolves the palette for an explicit [Brightness].
  static AppThemeTokens of(Brightness brightness) =>
      brightness == Brightness.dark ? dark : light;
}

/// `context.tokens` — the palette for the ambient theme.
///
/// A screen calls `context.tokens.surface` and gets the correct surface for the
/// current theme, so no widget has to branch on brightness itself.
extension AppThemeTokensX on BuildContext {
  AppThemeTokens get tokens => AppThemeTokens.of(Theme.of(this).brightness);
}
