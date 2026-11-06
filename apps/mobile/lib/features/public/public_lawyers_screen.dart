import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../assistant/assistant_screen.dart';
import 'public_info_screen.dart';

/// The public directory of approved lawyers, from `GET /api/v1/lawyers.php`.
///
/// Readable without an account — a visitor comparing lawyers before signing up is
/// the normal consumer path. Nothing here is gated: the endpoint is public and
/// returns only directory fields, so no auth prompt is raised to view it. Booking
/// one is a protected action and does raise the prompt.
class PublicLawyersScreen extends StatefulWidget {
  const PublicLawyersScreen({
    super.key,
    this.repository,
    this.embedded = false,
  });

  final ApiRepository? repository;

  /// When true the page renders only its content, without its own `Scaffold` or
  /// app bar, so it can live inside the guest shell without a duplicated header.
  final bool embedded;

  @override
  State<PublicLawyersScreen> createState() => _PublicLawyersScreenState();
}

class _PublicLawyersScreenState extends State<PublicLawyersScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<LawyerProfile> _all = const [];
  bool _loading = true;
  String? _error;
  String _query = '';

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
      final list = await _repo.lawyers();
      if (!mounted) return;
      setState(() {
        _all = list;
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
        _error = 'تعذّر تحميل قائمة المحامين. أعد المحاولة.';
      });
    }
  }

  /// Filtered locally over the already-fetched directory. The endpoint also accepts
  /// `q`, but filtering here keeps typing instant and avoids a request per keystroke.
  List<LawyerProfile> get _visible {
    final needle = _query.trim();
    if (needle.isEmpty) return _all;
    return _all
        .where((l) =>
            l.name.contains(needle) ||
            l.specialization.contains(needle) ||
            l.jurisdiction.contains(needle))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;

    final body = _loading
        ? const Padding(
            padding: EdgeInsets.all(16),
            child: LoadingSkeleton(count: 5),
          )
        : _error != null
            ? ErrorState(message: _error!, onRetry: _load)
            : _body(rtl);

    if (widget.embedded) {
      return Container(color: context.tokens.bg, child: body);
    }

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'المحامون' : 'Lawyers'),
      ),
      body: body,
    );
  }

  Widget _body(bool rtl) {
    final visible = _visible;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
        children: [
          TextField(
            onChanged: (v) => setState(() => _query = v),
            decoration: InputDecoration(
              hintText: rtl
                  ? 'ابحث بالاسم أو التخصص أو المدينة'
                  : 'Search by name, specialty or city',
              prefixIcon: const Icon(Icons.search),
              filled: true,
              fillColor: context.tokens.surface,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.lg),
                borderSide: BorderSide(color: context.tokens.border),
              ),
            ),
          ),
          const SizedBox(height: 16),
          if (_all.isEmpty)
            NoLawyerState(
              onAskAssistant: _openAssistant,
              onContact: _openContact,
              message: rtl ? 'لا يوجد محامي متاح' : 'No lawyers available',
            )
          else if (visible.isEmpty)
            EmptyState(
              icon: Icons.search_off,
              title: rtl ? 'لا نتائج مطابقة' : 'No matches',
              message: rtl
                  ? 'جرّب كلمة أخرى، أو امسح البحث لعرض كل المحامين.'
                  : 'Try another term, or clear the search to see everyone.',
            )
          else ...[
            Text(
              rtl
                  ? '${visible.length} محامياً متاحاً'
                  : '${visible.length} lawyers available',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 10),
            for (final lawyer in visible) ...[
              _LawyerCard(lawyer: lawyer, rtl: rtl, onBook: () => _book(lawyer)),
              const SizedBox(height: 10),
            ],
          ],
        ],
      ),
    );
  }

  /// Booking needs an account, so a guest is stopped at the door with the standard
  /// prompt rather than being sent to a screen the server would reject.
  Future<void> _book(LawyerProfile lawyer) async {
    await requireAccount(
      context,
      ProtectedDestination.consultations,
      message: context.read<LocaleController>().isRtl
          ? 'أنشئ حساباً لحجز استشارة مع ${lawyer.name}.'
          : 'Create an account to book a consultation with ${lawyer.name}.',
    );
  }

  /// The two routes out of the empty directory, matching the journey's no-lawyer
  /// state so a customer sees one consistent offer wherever they hit the state.
  void _openAssistant() {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const AssistantScreen()),
    );
  }

  void _openContact() {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => const PublicInfoScreen(section: InfoSection.contact),
      ),
    );
  }
}

class _LawyerCard extends StatelessWidget {
  const _LawyerCard({
    required this.lawyer,
    required this.rtl,
    required this.onBook,
  });

  final LawyerProfile lawyer;
  final bool rtl;
  final VoidCallback onBook;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.border),
        boxShadow: AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const CircleAvatar(
                radius: 24,
                backgroundColor: AppColors.navy,
                child: Icon(Icons.person, color: Colors.white),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      lawyer.name,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: context.tokens.heading,
                        fontSize: 15,
                      ),
                    ),
                    if (lawyer.specialization.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        lawyer.specialization,
                        style: TextStyle(
                            color: context.tokens.textMuted, fontSize: 13),
                      ),
                    ],
                  ],
                ),
              ),
              if (lawyer.rating > 0)
                Row(
                  children: [
                    const Icon(Icons.star, size: 15, color: AppColors.gold),
                    const SizedBox(width: 3),
                    Text(
                      lawyer.rating.toStringAsFixed(1),
                      style: TextStyle(
                          color: context.tokens.textMuted,
                          fontSize: 12,
                          fontWeight: FontWeight.w600),
                    ),
                  ],
                ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              if (lawyer.jurisdiction.isNotEmpty) ...[
                Icon(Icons.place_outlined,
                    size: 15, color: context.tokens.textSubtle),
                const SizedBox(width: 4),
                Flexible(
                  child: Text(
                    lawyer.jurisdiction,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        color: context.tokens.textSubtle, fontSize: 12),
                  ),
                ),
                const SizedBox(width: 12),
              ],
              if (lawyer.hourlyRate > 0) ...[
                Icon(Icons.payments_outlined,
                    size: 15, color: context.tokens.textSubtle),
                const SizedBox(width: 4),
                Text(
                  rtl
                      ? '${lawyer.hourlyRate.toStringAsFixed(0)} ر.س/ساعة'
                      : 'SAR ${lawyer.hourlyRate.toStringAsFixed(0)}/hr',
                  style: TextStyle(
                      color: context.tokens.textSubtle, fontSize: 12),
                ),
              ],
            ],
          ),
          const SizedBox(height: 12),
          SecondaryButton(
            label: rtl ? 'احجز استشارة' : 'Book a consultation',
            icon: Icons.event_available_outlined,
            expand: true,
            onPressed: onBook,
          ),
        ],
      ),
    );
  }
}
