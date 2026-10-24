import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/config/app_config.dart';

/// Execution 52 — the version string, and the account-deletion tile.
///
/// The account screen used to print a hardcoded "1.0.0" while the APK shipped 1.4.0,
/// so the one place a customer reads the version was wrong for several releases. The
/// value now lives in [AppConfig.appVersion], and this test reads `pubspec.yaml` and
/// fails if the two drift — a checked claim rather than a comment asking people to
/// remember.
void main() {
  group('the app version', () {
    test('matches the version in pubspec.yaml', () {
      final pubspec = File('pubspec.yaml').readAsStringSync();
      final match = RegExp(r'^version:\s*([0-9]+\.[0-9]+\.[0-9]+)',
              multiLine: true)
          .firstMatch(pubspec);
      expect(match, isNotNull, reason: 'pubspec.yaml must declare a version');
      expect(
        AppConfig.appVersion,
        match!.group(1),
        reason: 'AppConfig.appVersion drifted from pubspec.yaml',
      );
    });

    test('is not the stale hardcoded 1.0.0', () {
      expect(AppConfig.appVersion, isNot('1.0.0'));
    });
  });
}
