import 'package:flutter/material.dart';

import 'app_tokens.dart';

/// MAWATHEQ brand palette.
///
/// Every value here is taken from the website's design tokens
/// (`assets/css/style.css` and `assets/css/enterprise.css`) or sampled from the
/// original logo, so the app and the site share one identity.
class AppColors {
  const AppColors._();

  // ---- Brand ----
  static const navy = Color(0xFF1E3A5F);
  static const navyDark = Color(0xFF0F1F33);

  /// Sampled from the lower part of the original logo (shield).
  static const navyDeep = Color(0xFF07334D);
  static const teal = Color(0xFF2BA8C4);

  /// Sampled from the upper part of the original logo (gradient).
  static const cyan = Color(0xFF8ACEE7);

  /// The restrained warm accent from the logo — used at most once per screen.
  static const gold = Color(0xFFF5A623);
  static const goldDeep = Color(0xFFE0941B);

  // ---- Surfaces ----
  static const bg = Color(0xFFF6F8FA);
  static const surface = Color(0xFFFFFFFF);
  static const surface2 = Color(0xFFF8FAFC);
  static const surface3 = Color(0xFFF1F5F9);
  static const border = Color(0xFFE3E9EF);
  static const borderStrong = Color(0xFFCBD5E1);

  // ---- Text ----
  static const text = Color(0xFF16232F);
  static const textMuted = Color(0xFF5C6B7A);
  static const textSubtle = Color(0xFF8296A6);

  // ---- Semantic ----
  static const success = Color(0xFF1E8E5A);
  static const successBg = Color(0xFFE6F4ED);
  static const warning = Color(0xFFB4780F);
  static const warningBg = Color(0xFFFDF3E1);
  static const danger = Color(0xFFC7492A);
  static const dangerBg = Color(0xFFFBEAE5);
  static const info = Color(0xFF2BA8C4);
  static const infoBg = Color(0xFFEAF6F9);

  // ---- Gradients ----
  static const brandHero = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [navy, navyDeep, teal],
  );

  static const cyanAccent = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [cyan, teal],
  );

  static const warmAccent = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [gold, goldDeep],
  );

  /// Soft light-blue wash used by hero cards on the home screen.
  ///
  /// Kept for the light theme only; widgets read `context.tokens.washGradient`,
  /// which resolves to this in light mode and a deep navy in dark mode.
  static const lightSurface = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFDCEFF8), Color(0xFFEAF6F9)],
  );

  /// Maps a backend status value to its colour.
  /// The values mirror the ENUMs in `database/schema.sql` so a badge can never
  /// invent a state the backend does not produce.
  ///
  /// These are brand-semantic and deliberately identical in both themes: "green
  /// means approved" must not become a different green in dark mode, or the badge
  /// stops being a reliable signal. Only the badge *backgrounds* vary by theme;
  /// see [backgroundForStatusIn].
  /// The semantic family a backend status belongs to.
  ///
  /// The status list lives here once, and both themes resolve a family to their own
  /// colours. Duplicating the switch per theme is how the two would silently drift:
  /// a status added to one list and not the other would render neutral.
  static _StatusFamily _familyFor(String status) {
    switch (status.toLowerCase()) {
      case 'active':
      case 'open':
      case 'confirmed':
      case 'paid':
      case 'approved':
      case 'completed':
        return _StatusFamily.success;
      case 'pending':
      case 'draft':
      case 'in_review':
      case 'processing':
      case 'expired':
        return _StatusFamily.warning;
      case 'closed_lost':
      case 'cancelled':
      case 'overdue':
      case 'failed':
      case 'suspended':
      case 'rejected':
        return _StatusFamily.danger;
      case 'closed_won':
      case 'closed_settled':
      case 'published':
        return _StatusFamily.info;
      default:
        return _StatusFamily.neutral;
    }
  }

  static Color _fg(_StatusFamily family, AppThemeTokens t) => switch (family) {
        _StatusFamily.success => t.successFg,
        _StatusFamily.warning => t.warningFg,
        _StatusFamily.danger => t.dangerFg,
        // The brand teal is already light enough to read on the deep dark wash
        // (5.1:1) and on the card surface, so unlike the other semantics it needs
        // no per-theme variant.
        _StatusFamily.info => info,
        _StatusFamily.neutral => t.textMuted,
      };

  static Color _bg(_StatusFamily family, AppThemeTokens t) => switch (family) {
        _StatusFamily.success => t.successBg,
        _StatusFamily.warning => t.warningBg,
        _StatusFamily.danger => t.dangerBg,
        _StatusFamily.info => t.infoBg,
        _StatusFamily.neutral => t.surface3,
      };

  /// The badge foreground for a status, resolved for the current theme.
  static Color statusFgFor(String status, AppThemeTokens tokens) =>
      _fg(_familyFor(status), tokens);

  /// The badge foreground for the light theme.
  static Color forStatus(String status) =>
      _fg(_familyFor(status), AppThemeTokens.light);

  /// The badge background for a status, resolved for the current theme.
  ///
  /// Separate from the foreground because the pale light-mode washes are glaring
  /// blocks on a dark surface, while the status *hue* must stay recognisable. The
  /// dark palette therefore supplies deep washes behind a lightened foreground.
  static Color backgroundForStatusIn(String status, AppThemeTokens tokens) =>
      _bg(_familyFor(status), tokens);

  /// The light-mode badge background. Retained for callers that have no
  /// [BuildContext] (and for the light theme, where it is still correct); new
  /// widget code should use [backgroundForStatusIn] so dark mode works.
  static Color backgroundForStatus(String status) =>
      _bg(_familyFor(status), AppThemeTokens.light);
}

/// The semantic family of a backend status. Internal to [AppColors].
enum _StatusFamily { success, warning, danger, info, neutral }

/// Spacing, radius and elevation scales — identical to the web tokens.
class AppSpacing {
  const AppSpacing._();
  static const s1 = 4.0;
  static const s2 = 8.0;
  static const s3 = 12.0;
  static const s4 = 16.0;
  static const s5 = 20.0;
  static const s6 = 24.0;
  static const s8 = 32.0;
  static const s10 = 40.0;
}

class AppRadius {
  const AppRadius._();
  static const xs = 6.0;
  static const sm = 8.0;
  static const md = 11.0;
  static const lg = 14.0;
  static const xl = 18.0;
  static const pill = 999.0;
}

class AppShadow {
  const AppShadow._();

  static const xs = [
    BoxShadow(color: Color(0x0D0F1F33), blurRadius: 2, offset: Offset(0, 1)),
  ];
  static const sm = [
    BoxShadow(color: Color(0x0D0F1F33), blurRadius: 8, offset: Offset(0, 2)),
  ];
  static const md = [
    BoxShadow(color: Color(0x120F1F33), blurRadius: 20, offset: Offset(0, 6)),
  ];
  static const lg = [
    BoxShadow(color: Color(0x330F1F33), blurRadius: 60, offset: Offset(0, 24)),
  ];
}

/// Font sizes — the same scale as the website. Minimum body size is 14.
class AppFontSize {
  const AppFontSize._();
  static const xs = 12.0;
  static const sm = 13.0;
  static const base = 14.0;
  static const md = 15.0;
  static const lg = 17.0;
  static const h3 = 19.0;
  static const h2 = 23.0;
  static const h1 = 28.0;
  static const hero = 32.0;
}
