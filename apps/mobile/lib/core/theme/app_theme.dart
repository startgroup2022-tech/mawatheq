import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import 'app_colors.dart';
import 'app_tokens.dart';

/// Builds the Material theme from the shared brand tokens.
///
/// The same three font families used by the website are used here, so Arabic
/// headlines render identically on both surfaces.
///
/// Both a light and a dark theme are built from the same [AppThemeTokens] structure,
/// so the two cannot drift apart: every neutral colour (background, surface, border,
/// body text) comes from the token set, and only the brand colours — navy, teal,
/// gold, and the status semantics — are fixed. That is deliberate. A dark theme that
/// re-tinted the brand would stop being Mawatheq; what has to change is the neutral
/// layer, and that is exactly what the tokens carry.
class AppTheme {
  const AppTheme._();

  /// Text styles, coloured from [tokens] so headings stay readable on both
  /// backgrounds. The brand navy that reads well on white is too dark on a dark
  /// surface, so [AppThemeTokens.heading] carries a lifted tint for dark mode.
  static TextTheme _textTheme(AppThemeTokens tokens) {
    // Cairo for display, IBM Plex Sans Arabic for body — matching the site.
    final base = GoogleFonts.ibmPlexSansArabicTextTheme();
    return base.copyWith(
      displayLarge: GoogleFonts.cairo(
        fontSize: AppFontSize.hero,
        fontWeight: FontWeight.w800,
        color: tokens.heading,
        height: 1.35,
      ),
      headlineLarge: GoogleFonts.cairo(
        fontSize: AppFontSize.h1,
        fontWeight: FontWeight.w800,
        color: tokens.heading,
        height: 1.35,
      ),
      headlineMedium: GoogleFonts.cairo(
        fontSize: AppFontSize.h2,
        fontWeight: FontWeight.w700,
        color: tokens.heading,
        height: 1.4,
      ),
      titleLarge: GoogleFonts.cairo(
        fontSize: AppFontSize.h3,
        fontWeight: FontWeight.w700,
        color: tokens.heading,
        height: 1.45,
      ),
      titleMedium: GoogleFonts.cairo(
        fontSize: AppFontSize.lg,
        fontWeight: FontWeight.w700,
        color: tokens.heading,
        height: 1.5,
      ),
      bodyLarge: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.md,
        color: tokens.text,
        height: 1.75,
      ),
      bodyMedium: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.base,
        color: tokens.text,
        height: 1.75,
      ),
      bodySmall: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.sm,
        color: tokens.textMuted,
        height: 1.7,
      ),
      labelLarge: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.base,
        fontWeight: FontWeight.w600,
        color: tokens.text,
      ),
      labelSmall: GoogleFonts.ibmPlexSansArabic(
        fontSize: AppFontSize.xs,
        color: tokens.textSubtle,
      ),
    );
  }

  /// The approved light theme.
  static ThemeData light() => _build(AppThemeTokens.light);

  /// The dark theme. Same structure, neutral layer swapped.
  static ThemeData dark() => _build(AppThemeTokens.dark);

  static ThemeData _build(AppThemeTokens tokens) {
    final textTheme = _textTheme(tokens);
    final isDark = tokens.isDark;

    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.navy,
      // The brand fills do not change between themes, so primary stays navy in
      // both. Only the surfaces and on-surface colours come from the tokens.
      primary: AppColors.navy,
      onPrimary: tokens.onBrand,
      secondary: AppColors.teal,
      onSecondary: tokens.onBrand,
      surface: tokens.surface,
      onSurface: tokens.text,
      error: AppColors.danger,
      brightness: tokens.brightness,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: tokens.brightness,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: tokens.bg,
      canvasColor: tokens.bg,
      textTheme: textTheme,
      splashFactory: InkRipple.splashFactory,
      appBarTheme: AppBarTheme(
        backgroundColor: tokens.surface,
        foregroundColor: tokens.heading,
        elevation: 0,
        scrolledUnderElevation: 1,
        centerTitle: false,
        titleTextStyle: textTheme.titleMedium,
        iconTheme: IconThemeData(color: tokens.heading),
      ),
      cardTheme: CardThemeData(
        color: tokens.surface,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          side: BorderSide(color: tokens.border),
        ),
        margin: EdgeInsets.zero,
      ),
      dividerTheme: DividerThemeData(
        color: tokens.border,
        thickness: 1,
        space: 1,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: tokens.surface,
        titleTextStyle: textTheme.titleLarge,
        contentTextStyle: textTheme.bodyMedium,
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: tokens.surface,
        surfaceTintColor: Colors.transparent,
      ),
      listTileTheme: ListTileThemeData(
        textColor: tokens.text,
        iconColor: tokens.textMuted,
      ),
      iconTheme: IconThemeData(color: tokens.textMuted),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: tokens.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.s4,
          vertical: AppSpacing.s3,
        ),
        hintStyle: textTheme.bodyMedium?.copyWith(color: tokens.textSubtle),
        labelStyle: textTheme.bodyMedium?.copyWith(color: tokens.textMuted),
        prefixIconColor: tokens.textSubtle,
        suffixIconColor: tokens.textSubtle,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: BorderSide(color: tokens.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: BorderSide(color: tokens.border),
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
          backgroundColor: tokens.brandFill,
          foregroundColor: tokens.onBrand,
          disabledBackgroundColor:
              isDark ? tokens.surface3 : tokens.borderStrong,
          disabledForegroundColor: tokens.textSubtle,
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
          foregroundColor: tokens.heading,
          minimumSize: const Size(0, 48),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s6),
          side: BorderSide(color: tokens.borderStrong),
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
        // Teal is legible on both backgrounds, so it is unchanged.
        style: TextButton.styleFrom(foregroundColor: AppColors.teal),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: tokens.surface,
        selectedColor: tokens.infoBg,
        side: BorderSide(color: tokens.border),
        labelStyle: textTheme.bodySmall?.copyWith(color: tokens.text),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.pill),
        ),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: tokens.surface,
        selectedItemColor: AppColors.teal,
        unselectedItemColor: tokens.textSubtle,
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
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: tokens.surface,
        indicatorColor: tokens.infoBg,
        labelTextStyle: WidgetStatePropertyAll(
          GoogleFonts.ibmPlexSansArabic(fontSize: AppFontSize.xs),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        // The snack bar keeps a dark fill in both themes: it is a transient
        // overlay, and a light snack bar on a light page disappears.
        backgroundColor: isDark ? tokens.surface3 : AppColors.navyDark,
        contentTextStyle: textTheme.bodyMedium?.copyWith(color: tokens.onBrand),
        actionTextColor: AppColors.cyan,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.teal,
      ),
      // The remaining surfaces Material draws for us.
      popupMenuTheme: PopupMenuThemeData(
        color: tokens.surface,
        surfaceTintColor: Colors.transparent,
        textStyle: textTheme.bodyMedium,
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: isDark ? tokens.surface3 : AppColors.navyDark,
          borderRadius: BorderRadius.circular(AppRadius.sm),
        ),
        textStyle: textTheme.bodySmall?.copyWith(color: tokens.onBrand),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: const WidgetStatePropertyAll(AppColors.teal),
        trackColor: WidgetStateProperty.resolveWith(
          (states) => states.contains(WidgetState.selected)
              ? AppColors.teal.withValues(alpha: 0.35)
              : tokens.surface3,
        ),
      ),
      radioTheme: const RadioThemeData(
        fillColor: WidgetStatePropertyAll(AppColors.teal),
      ),
      checkboxTheme: CheckboxThemeData(
        fillColor: const WidgetStatePropertyAll(AppColors.teal),
        side: BorderSide(color: tokens.borderStrong),
      ),
    );
  }
}
