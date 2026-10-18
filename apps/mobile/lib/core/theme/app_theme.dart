import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import 'app_colors.dart';

/// Builds the Material theme from the shared brand tokens.
///
/// The same three font families used by the website are used here, so Arabic
/// headlines render identically on both surfaces. There is intentionally no dark
/// theme: the approved design is a light, airy layout and a dark variant would
/// contradict it (see MAWATHEQ_DESIGN_SYSTEM.md).
class AppTheme {
  const AppTheme._();

  static TextTheme _textTheme() {
    // Cairo for display, IBM Plex Sans Arabic for body — matching the site.
    final base = GoogleFonts.ibmPlexSansArabicTextTheme();
    return base.copyWith(
      displayLarge: GoogleFonts.cairo(
        fontSize: AppFontSize.hero,
        fontWeight: FontWeight.w800,
        color: AppColors.navy,
        height: 1.35,
      ),
      headlineLarge: GoogleFonts.cairo(
        fontSize: AppFontSize.h1,
        fontWeight: FontWeight.w800,
        color: AppColors.navy,
        height: 1.35,
      ),
      headlineMedium: GoogleFonts.cairo(
        fontSize: AppFontSize.h2,
        fontWeight: FontWeight.w700,
        color: AppColors.navy,
        height: 1.4,
      ),
      titleLarge: GoogleFonts.cairo(
        fontSize: AppFontSize.h3,
        fontWeight: FontWeight.w700,
        color: AppColors.navy,
        height: 1.45,
      ),
      titleMedium: GoogleFonts.cairo(
        fontSize: AppFontSize.lg,
        fontWeight: FontWeight.w700,
        color: AppColors.navy,
        height: 1.5,
      ),
      bodyLarge: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.md,
        color: AppColors.text,
        height: 1.75,
      ),
      bodyMedium: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.base,
        color: AppColors.text,
        height: 1.75,
      ),
      bodySmall: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.sm,
        color: AppColors.textMuted,
        height: 1.7,
      ),
      labelLarge: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.base,
        fontWeight: FontWeight.w600,
        color: AppColors.text,
      ),
      labelSmall: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.xs,
        color: AppColors.textSubtle,
      ),
    );
  }

  static ThemeData light() {
    final textTheme = _textTheme();
    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.navy,
      primary: AppColors.navy,
      secondary: AppColors.teal,
      surface: AppColors.surface,
      error: AppColors.danger,
      brightness: Brightness.light,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: AppColors.bg,
      textTheme: textTheme,
      splashFactory: InkRipple.splashFactory,
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.navy,
        elevation: 0,
        scrolledUnderElevation: 1,
        centerTitle: false,
        titleTextStyle: textTheme.titleMedium,
      ),
      cardTheme: CardThemeData(
        color: AppColors.surface,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          side: const BorderSide(color: AppColors.border),
        ),
        margin: EdgeInsets.zero,
      ),
      dividerTheme: const DividerThemeData(
        color: AppColors.border,
        thickness: 1,
        space: 1,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.s4,
          vertical: AppSpacing.s3,
        ),
        hintStyle: textTheme.bodyMedium?.copyWith(color: AppColors.textSubtle),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.teal, width: 1.6),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.danger),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.6),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.navy,
          foregroundColor: Colors.white,
          minimumSize: const Size(0, 48),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s6),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
          textStyle: GoogleFonts.ibmPlexSansArabic(
            fontSize: AppFontSize.base,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.navy,
          minimumSize: const Size(0, 48),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s6),
          side: const BorderSide(color: AppColors.borderStrong),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
          textStyle: GoogleFonts.ibmPlexSansArabic(
            fontSize: AppFontSize.base,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: AppColors.teal),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.surface,
        selectedColor: AppColors.infoBg,
        side: const BorderSide(color: AppColors.border),
        labelStyle: textTheme.bodySmall?.copyWith(color: AppColors.text),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.pill),
        ),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: AppColors.surface,
        selectedItemColor: AppColors.teal,
        unselectedItemColor: AppColors.textSubtle,
        selectedLabelStyle: GoogleFonts.ibmPlexSansArabic(
          fontSize: AppFontSize.xs,
          fontWeight: FontWeight.w700,
        ),
        unselectedLabelStyle: GoogleFonts.ibmPlexSansArabic(
          fontSize: AppFontSize.xs,
        ),
        type: BottomNavigationBarType.fixed,
        elevation: 8,
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.navyDark,
        contentTextStyle: textTheme.bodyMedium?.copyWith(color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.teal,
      ),
    );
  }
}
