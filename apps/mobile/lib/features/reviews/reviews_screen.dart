import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// «قيّم استشاراتك» — the client's completed consultations awaiting a rating, from
/// `GET /api/v1/lawyer-reviews.php?reviewable=1`.
///
/// A rating is verified by construction: only a completed consultation the caller owns
/// appears here, and the server enforces the one-review-per-consultation rule. The screen
/// never shows a rating control for a consultation that is not completed.
class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

class _ReviewsScreenState extends State<ReviewsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<ReviewableConsultation> _items = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final list = await _repo.reviewableConsultations();
      if (!mounted) return;
      setState(() {
        _items = list;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'تعذّر تحميل الاستشارات. أعد المحاولة.';
      });
    }
  }

  Future<void> _openRatingSheet(ReviewableConsultation c) async {
    final submitted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: context.tokens.surface,
      builder: (_) => _RatingSheet(repository: _repo, consultation: c),
    );
    if (submitted == true) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'تقييم الاستشارات' : 'Rate consultations'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 3),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _body(rtl),
    );
  }

  Widget _body(bool rtl) {
    if (_items.isEmpty) {
      return EmptyState(
        icon: Icons.star_border,
        title: rtl ? 'لا توجد استشارات بانتظار التقييم' : 'Nothing to rate',
        message: rtl
            ? 'بعد إكمال استشارة ستظهر هنا لتقييم المحامي ومساعدة العملاء الآخرين.'
            : 'After a consultation is completed it appears here so you can rate the lawyer.',
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        itemCount: _items.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          final c = _items[i];
          return Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: context.tokens.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  c.lawyerName.isEmpty ? (rtl ? 'محامٍ' : 'A lawyer') : c.lawyerName,
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: context.tokens.heading,
                  ),
                ),
                if (c.topic.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(c.topic, style: TextStyle(color: context.tokens.textMuted, fontSize: 13)),
                ],
                const SizedBox(height: 12),
                PrimaryButton(
                  label: rtl ? 'قيّم المحامي' : 'Rate the lawyer',
                  icon: Icons.star_outline,
                  expand: true,
                  onPressed: () => _openRatingSheet(c),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _RatingSheet extends StatefulWidget {
  const _RatingSheet({required this.repository, required this.consultation});

  final ApiRepository repository;
  final ReviewableConsultation consultation;

  @override
  State<_RatingSheet> createState() => _RatingSheetState();
}

class _RatingSheetState extends State<_RatingSheet> {
  int _rating = 5;
  final _comment = TextEditingController();
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await widget.repository.submitReview(
        consultationId: widget.consultation.id,
        rating: _rating,
        comment: _comment.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = 'تعذّر إرسال التقييم. حاول مرة أخرى.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.read<LocaleController>().isRtl;
    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            rtl ? 'قيّم استشارتك' : 'Rate your consultation',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var star = 1; star <= 5; star++)
                IconButton(
                  onPressed: () => setState(() => _rating = star),
                  iconSize: 36,
                  icon: Icon(
                    star <= _rating ? Icons.star_rounded : Icons.star_border_rounded,
                    color: star <= _rating ? AppColors.gold : context.tokens.textSubtle,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _comment,
            maxLines: 4,
            maxLength: 600,
            decoration: InputDecoration(
              hintText: rtl ? 'شاركنا تجربتك (اختياري)' : 'Share your experience (optional)',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(_error!, style: const TextStyle(color: AppColors.danger, fontSize: 13)),
          ],
          const SizedBox(height: 8),
          PrimaryButton(
            label: rtl ? 'إرسال التقييم' : 'Submit rating',
            expand: true,
            loading: _submitting,
            onPressed: _submitting ? null : _submit,
          ),
        ],
      ),
    );
  }
}
