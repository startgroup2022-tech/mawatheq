import 'package:in_app_review/in_app_review.dart';

/// The in-app store rating prompt, behind an interface so it is testable.
///
/// The plugin is a platform channel; a widget test cannot call it and must not try.
/// The interface also lets the caller decide, without a device, whether the store
/// flow is available before offering the tile.
abstract class RateAppService {
  /// True when the platform's in-app review flow can be shown right now.
  Future<bool> isAvailable();

  /// Requests the in-app review. Returns true when the request was handed to the
  /// platform — note that the platform may still choose not to display a dialog
  /// (it rate-limits how often the prompt appears), so a true result means
  /// "requested", never "the user saw it".
  Future<bool> requestReview();
}

/// Production [RateAppService] backed by `in_app_review`.
class InAppReviewService implements RateAppService {
  InAppReviewService({InAppReview? review})
    : _review = review ?? InAppReview.instance;

  final InAppReview _review;

  @override
  Future<bool> isAvailable() async {
    try {
      return await _review.isAvailable();
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> requestReview() async {
    try {
      await _review.requestReview();
      return true;
    } catch (_) {
      return false;
    }
  }
}
