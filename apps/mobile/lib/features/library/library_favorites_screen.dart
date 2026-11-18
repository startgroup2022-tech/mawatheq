import 'package:flutter/material.dart';

import 'package:provider/provider.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'library_item_screen.dart';
import 'library_widgets.dart';

/// «مكتبتي» — the client's saved library items, from
/// `GET /api/v1/legal-library.php?action=favorites`.
///
/// The list is the same set the website shows: both read one endpoint, so an item
/// saved on the phone appears on the web and vice versa.
class LegalLibraryFavoritesScreen extends StatefulWidget {
  const LegalLibraryFavoritesScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LegalLibraryFavoritesScreen> createState() =>
      _LegalLibraryFavoritesScreenState();
}

class _LegalLibraryFavoritesScreenState extends State<LegalLibraryFavoritesScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<LegalLibraryItem> _items = const [];
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
      final items = await _repo.legalLibraryFavorites();
      if (!mounted) return;
      setState(() {
        _items = items;
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
        _error = 'تعذّر تحميل العناصر المحفوظة. أعد المحاولة.';
      });
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
        title: Text(rtl ? 'مكتبتي المحفوظة' : 'My saved items'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(AppSpacing.s4),
              child: LoadingSkeleton(count: 5),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _items.isEmpty
                  ? EmptyState(
                      icon: Icons.bookmark_border,
                      title: rtl ? 'لا عناصر محفوظة بعد' : 'Nothing saved yet',
                      message: rtl
                          ? 'افتح أي عنصر من المكتبة واضغط أيقونة الحفظ ليظهر هنا.'
                          : 'Open any library item and tap the save icon to keep it here.',
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
                        itemCount: _items.length,
                        itemBuilder: (context, index) {
                          final item = _items[index];
                          return Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: LibraryItemCard(
                              item: item,
                              rtl: rtl,
                              onTap: () async {
                                await Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (_) => LegalLibraryItemScreen(
                                      itemId: item.id,
                                      repository: widget.repository,
                                    ),
                                  ),
                                );
                                // The detail page can un-save an item, so re-read the
                                // list on return instead of trusting a stale card.
                                if (mounted) await _load();
                              },
                            ),
                          );
                        },
                      ),
                    ),
    );
  }
}
