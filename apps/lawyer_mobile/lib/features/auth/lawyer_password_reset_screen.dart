import 'package:flutter/material.dart';

import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/features/auth/password_reset_screens.dart';

/// Password recovery for the lawyer app.
///
/// The shared screen already knows how to call the lawyer variant of the reset
/// endpoints (`account_type: lawyer`), so this is a thin typed wrapper rather than
/// a second implementation. Duplicating it would create a second place where the
/// lawyer/client distinction could be got wrong.
class LawyerPasswordResetScreen extends StatelessWidget {
  const LawyerPasswordResetScreen({super.key, required this.repository});

  final ApiRepository repository;

  @override
  Widget build(BuildContext context) {
    return ForgotPasswordScreen(repository: repository, asLawyer: true);
  }
}
