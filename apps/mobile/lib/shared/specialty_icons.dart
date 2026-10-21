import 'package:flutter/material.dart';

/// Maps a `najiz_categories.customer_icon_key` to its icon.
///
/// The keys are the ones the database actually stores, read from
/// `customer_icon_key` in `upgrade_v63.sql` / the classification import:
/// family, commercial, labor, criminal, general, enforcement, endowments.
/// `realestate` is kept because an operator can add it from the admin panel.
///
/// This lives in one place because the mapping was previously duplicated in the
/// home tab and the journey screen, and both copies were missing `enforcement` and
/// `endowments` — two of the seven real categories fell through to the generic
/// balance icon, so a third of the specialties looked alike. One list cannot drift
/// from itself.
///
/// An unknown key falls back to the scales rather than throwing: an operator adding
/// a category must not be able to break the app with a key the client has not
/// learned yet.
IconData specialtyIcon(String key) {
  switch (key) {
    case 'family':
      return Icons.family_restroom;
    case 'commercial':
      return Icons.storefront_outlined;
    case 'labor':
      return Icons.work_outline;
    case 'criminal':
      return Icons.gavel_outlined;
    case 'realestate':
      return Icons.home_work_outlined;
    case 'enforcement':
      return Icons.gavel_rounded;
    case 'endowments':
      return Icons.account_balance_outlined;
    case 'general':
    default:
      return Icons.balance_outlined;
  }
}
