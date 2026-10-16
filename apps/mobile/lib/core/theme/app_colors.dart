import 'package:flutter/material.dart';

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
  static const lightSurface = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFDCEFF8), Color(0xFFEAF6F9)],
  );

  /// Maps a backend status value to its colour.
  /// The values mirror the ENUMs in `database/schema.sql` so a badge can never
  /// invent a state the backend does not produce.
  static Color forStatus(String status) {
    switch (status.toLowerCase()) {
      case 'active':
      case 'open':
      case 'confirmed':
      case 'paid':
      case 'approved':
      case 'completed':
        return success;
      case 'pending':
      case 'draft':
      case 'in_review':
      case 'processing':
      case 'expired':
        return warning;
      case 'closed_lost':
      case 'cancelled':
      case 'overdue':
      case 'failed':
      case 'suspended':
      case 'rejected':
        return danger;
      case 'closed_won':
      case 'closed_settled':
      case 'published':
        return info;
      default:
        return textMuted;
    }
  }

  static Color backgroundForStatus(String status) {
    switch (status.toLowerCase()) {
      case 'active':
      case 'open':
      case 'confirmed':
      case 'paid':
      case 'approved':
      case 'completed':
        return successBg;
      case 'pending':
      case 'draft':
      case 'in_review':
      case 'processing':
      case 'expired':
        return warningBg;
      case 'closed_lost':
      case 'cancelled':
      case 'overdue':
      case 'failed':
      case 'suspended':
      case 'rejected':
        return dangerBg;
      case 'closed_won':
      case 'closed_settled':
      case 'published':
        return infoBg;
      default:
        return surface3;
    }
  }
}

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
