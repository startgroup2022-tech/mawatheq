import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../../shared/widgets/lawyer_card.dart';
import '../assistant/assistant_screen.dart';
import '../consultations/journey_screen.dart';
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

  /// The professional filters, applied server-side so the directory and the app's
  /// journey return the same lawyers for the same choices. The options vocabulary
  /// (countries, dependent cities, experience bands, specializations) comes from the
  /// server too, so the app can never offer a value the backend would ignore.
  JourneyFilters _filters = const JourneyFilters();
  JourneyFilterOptions _filterOptions = const JourneyFilterOptions();

  /// The client's saved lawyer ids, so a heart is filled on the right cards. Empty
  /// for a guest, who has no favorites and sees no heart at all.
  Set<int> _favoriteIds = const {};

  @override
  void initState() {
    super.initState();
    _loadFilterOptions();
    _load();
    _loadFavorites();
  }

  Future<void> _loadFavorites() async {
    // Tolerant read: a widget test mounts this screen without an AuthController.
    // No provider means "not signed in", so the heart is simply absent.
    final auth = context.read<AuthController?>();
    if (auth == null || !auth.isSignedIn) return;
    try {
      final ids = await _repo.favoriteIds();
      if (!mounted) return;
      setState(() => _favoriteIds = ids);
    } on ApiException {
      // Favorites are a convenience layer; a failure must not break the directory.
    }
  }

  Future<void> _toggleFavorite(LawyerProfile lawyer) async {
    final auth = context.read<AuthController?>();
    if (auth == null || !auth.isSignedIn) {
      await requireAccount(
        context,
        ProtectedDestination.consultations,
        message: context.read<LocaleController>().isRtl
            ? 'أنشئ حساباً لحفظ المحامين في مفضّلتك.'
            : 'Create an account to save lawyers to your favorites.',
      );
      return;
    }
    final wasFavorite = _favoriteIds.contains(lawyer.id);
    // Optimistic flip, restored on failure so the heart never disagrees with the server.
    setState(() {
      final next = Set<int>.from(_favoriteIds);
      if (wasFavorite) {
        next.remove(lawyer.id);
      } else {
        next.add(lawyer.id);
      }
      _favoriteIds = next;
    });
    try {
      await _repo.toggleFavorite(lawyer.id, action: wasFavorite ? 'remove' : 'add');
    } catch (_) {
      if (!mounted) return;
      setState(() {
        final next = Set<int>.from(_favoriteIds);
        if (wasFavorite) {
          next.add(lawyer.id);
        } else {
          next.remove(lawyer.id);
        }
        _favoriteIds = next;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر تحديث المفضّلة. حاول مرة أخرى.')),
      );
    }
  }

  Future<void> _loadFilterOptions() async {
    try {
      final options = await _repo.journeyFilters();
      if (!mounted) return;
      setState(() => _filterOptions = options);
    } on ApiException {
      // The filter sheet degrades to whatever vocabulary it already has rather than
      // turning the directory into an error screen.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      // The structured filters (specialization, country, city, experience, fee,
      // availability) run server-side — only the backend can apply an availability or
      // fee-band filter correctly. The free-text name box stays local so typing is
      // instant and matches the hint (name, specialty or city).
      final list = await _repo.lawyers(
        specialization: _filters.specialization,
        extraQuery: _filters.toQuery(),
      );
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

  Future<void> _openFilters() async {
    final result = await showModalBottomSheet<JourneyFilters>(
      context: context,
      isScrollControlled: true,
      backgroundColor: context.tokens.surface,
      builder: (_) => JourneyFilterSheet(
        initial: _filters,
        options: _filterOptions,
      ),
    );
    if (result == null) return;
    setState(() => _filters = result);
    await _load();
  }

  void _clearFilters() {
    setState(() => _filters = const JourneyFilters());
    _load();
  }

  /// The name box updates the local search immediately; no request is fired.
  void _onSearchChanged(String value) => setState(() => _query = value);

  /// The name search is applied locally over the already-fetched directory, so typing
  /// is instant and a keystroke does not fire a request. The structured filters are
  /// applied server-side in `_load()`.
  List<LawyerProfile> get _visible {
    final needle = _query.trim();
    if (needle.isEmpty) return _all;
    return _all
        .where((l) =>
            l.name.contains(needle) ||
            l.specializationLabel.contains(needle) ||
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
    final filtersActive = !_filters.isEmpty;

    return RefreshIndicator(
      onRefresh: _load,
      child: Column(
        children: [
          JourneyFilterBar(
            active: filtersActive,
            count: visible.length,
            onOpen: _openFilters,
            onClear: filtersActive ? _clearFilters : null,
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
              children: [
                TextField(
                  onChanged: _onSearchChanged,
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
                    message: filtersActive
                        ? (rtl
                            ? 'لا يوجد محامٍ يطابق الفلاتر المحددة'
                            : 'No lawyer matches these filters')
                        : (rtl ? 'لا يوجد محامي متاح' : 'No lawyers available'),
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
                    LawyerCard(
                      lawyer: lawyer,
                      rtl: rtl,
                      isFavorite: _favoriteIds.contains(lawyer.id),
                      onToggleFavorite: () => _toggleFavorite(lawyer),
                      onBook: () => _book(lawyer),
                    ),
                    const SizedBox(height: 10),
                  ],
                ],
              ],
            ),
          ),
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
