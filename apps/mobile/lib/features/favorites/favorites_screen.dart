import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../../shared/widgets/lawyer_card.dart';

/// «المحامون المفضّلون» — the client's saved consultants, from
/// `GET /api/v1/favorites.php`.
///
/// The list is the same set the website shows; both read one endpoint, so a lawyer
/// saved on the phone appears on the web and vice versa. Removing a favourite is done
/// from the card's heart, which calls the same toggle the directory uses.
class FavoritesScreen extends StatefulWidget {
  const FavoritesScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<FavoritesScreen> createState() => _FavoritesScreenState();
}

class _FavoritesScreenState extends State<FavoritesScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<LawyerProfile> _lawyers = const [];
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
      final list = await _repo.favorites();
      if (!mounted) return;
      setState(() {
        _lawyers = list;
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
        _error = 'تعذّر تحميل المفضّلين. أعد المحاولة.';
      });
    }
  }

  Future<void> _remove(LawyerProfile lawyer) async {
    // Optimistic removal: the row disappears immediately and is restored if the
    // server refuses, so the list never lies about what is saved.
    final previous = _lawyers;
    setState(() => _lawyers = _lawyers.where((l) => l.id != lawyer.id).toList());
    try {
      await _repo.toggleFavorite(lawyer.id, action: 'remove');
    } catch (_) {
      if (!mounted) return;
      setState(() => _lawyers = previous);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر إزالة المحامي من المفضّلين.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'المحامون المفضّلون' : 'Favorite lawyers'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _body(rtl),
    );
  }

  Widget _body(bool rtl) {
    if (_lawyers.isEmpty) {
      return EmptyState(
        icon: Icons.favorite_border,
        title: rtl ? 'لا يوجد محامون في المفضّلة' : 'No favorite lawyers yet',
        message: rtl
            ? 'اضغط على القلب في أي محامٍ لإضافته إلى قائمتك للرجوع إليه بسرعة.'
            : 'Tap the heart on any lawyer to save them to your list.',
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        itemCount: _lawyers.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (context, i) => LawyerCard(
          lawyer: _lawyers[i],
          rtl: rtl,
          isFavorite: true,
          onToggleFavorite: () => _remove(_lawyers[i]),
        ),
      ),
    );
  }
}
