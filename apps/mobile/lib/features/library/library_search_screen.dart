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

/// «البحث في المكتبة» — search & browse, from
/// `GET /api/v1/legal-library.php?action=search` and `action=facets`.
///
/// Public. Every filter is applied server-side, so the app and the website return
/// the same rows for the same choices; the filter vocabulary comes from the data
/// actually imported, so an empty database offers no invented values.
class LegalLibrarySearchScreen extends StatefulWidget {
  const LegalLibrarySearchScreen({
    super.key,
    this.repository,
    this.initialFilters = const {},
  });

  final ApiRepository? repository;
  final Map<String, String> initialFilters;

  @override
  State<LegalLibrarySearchScreen> createState() => _LegalLibrarySearchScreenState();
}

class _LegalLibrarySearchScreenState extends State<LegalLibrarySearchScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  late final TextEditingController _query =
      TextEditingController(text: widget.initialFilters['q'] ?? '');

  LegalLibraryFacets _facets = const LegalLibraryFacets();
  LegalLibraryPage _page = const LegalLibraryPage(total: 0, page: 1, pages: 0, perPage: 20, items: []);
  bool _loading = true;
  String? _error;

  Map<String, String> _filters = {};
  int _pageNumber = 1;

  @override
  void initState() {
    super.initState();
    _filters = Map<String, String>.from(widget.initialFilters);
    _loadFacets();
    _load();
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _loadFacets() async {
    try {
      final f = await _repo.legalLibraryFacets();
      if (!mounted) return;
      setState(() => _facets = f);
    } on ApiException {
      // Facets are a convenience; a failure leaves the filter sheet empty rather
      // than turning search into an error screen.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await _repo.legalLibrarySearch(
        filters: _filters,
        page: _pageNumber,
      );
      if (!mounted) return;
      setState(() {
        _page = page;
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
        _error = 'تعذّر تحميل النتائج. أعد المحاولة.';
      });
    }
  }

  void _applySearch() {
    setState(() {
      final q = _query.text.trim();
      if (q.isEmpty) {
        _filters.remove('q');
      } else {
        _filters['q'] = q;
      }
      _pageNumber = 1;
    });
    _load();
  }

  void _goToPage(int page) {
    setState(() => _pageNumber = page);
    _load();
  }

  bool get _hasFilters =>
      _filters.keys.any((k) => k != 'q' && (_filters[k] ?? '').isNotEmpty);

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'البحث في المكتبة' : 'Search the library'),
        actions: [
          IconButton(
            tooltip: rtl ? 'تصفية' : 'Filter',
            onPressed: _openFilterSheet,
            icon: Badge(
              isLabelVisible: _hasFilters,
              child: const Icon(Icons.tune),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
            child: TextField(
              controller: _query,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _applySearch(),
              decoration: InputDecoration(
                hintText: rtl ? 'ابحث في العنوان والنص…' : 'Search titles and text…',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.arrow_forward),
                  onPressed: _applySearch,
                ),
                filled: true,
                fillColor: context.tokens.surface,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(AppRadius.lg),
                  borderSide: BorderSide(color: context.tokens.border),
                ),
              ),
            ),
          ),
          if (!_loading && _error == null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  rtl ? 'النتائج: ${_page.total}' : 'Results: ${_page.total}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ),
          Expanded(
            child: _loading
                ? const Padding(
                    padding: EdgeInsets.all(AppSpacing.s4),
                    child: LoadingSkeleton(count: 5),
                  )
                : _error != null
                    ? ErrorState(message: _error!, onRetry: _load)
                    : _results(rtl),
          ),
        ],
      ),
    );
  }

  Widget _results(bool rtl) {
    if (_page.items.isEmpty) {
      return EmptyState(
        icon: Icons.search_off,
        message: rtl
            ? 'لا توجد نتائج مطابقة. جرّب كلمات أقل أو أزل بعض الفلاتر.'
            : 'No matching results. Try fewer keywords or remove some filters.',
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      itemCount: _page.items.length + (_page.pages > 1 ? 1 : 0),
      itemBuilder: (context, index) {
        if (index == _page.items.length) return _pager(rtl);
        final item = _page.items[index];
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: LibraryItemCard(
            item: item,
            rtl: rtl,
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => LegalLibraryItemScreen(
                  itemId: item.id,
                  repository: widget.repository,
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _pager(bool rtl) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (_page.page > 1)
            OutlinedButton(
              onPressed: () => _goToPage(_page.page - 1),
              child: Text(rtl ? 'السابق' : 'Prev'),
            ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Text('${_page.page} / ${_page.pages}'),
          ),
          if (_page.page < _page.pages)
            OutlinedButton(
              onPressed: () => _goToPage(_page.page + 1),
              child: Text(rtl ? 'التالي' : 'Next'),
            ),
        ],
      ),
    );
  }

  void _openFilterSheet() {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: context.tokens.surface,
      builder: (_) => _FilterSheet(
        facets: _facets,
        current: _filters,
        onApply: (next) {
          setState(() {
            _filters = next;
            _pageNumber = 1;
          });
          _load();
        },
      ),
    );
  }
}

class _FilterSheet extends StatefulWidget {
  const _FilterSheet({
    required this.facets,
    required this.current,
    required this.onApply,
  });

  final LegalLibraryFacets facets;
  final Map<String, String> current;
  final ValueChanged<Map<String, String>> onApply;

  @override
  State<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<_FilterSheet> {
  late final Map<String, String> _draft = Map<String, String>.from(widget.current);

  Widget _dropdown(String key, String label, List<String> values) {
    if (values.isEmpty) return const SizedBox.shrink();
    final current = _draft[key];
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DropdownButtonFormField<String>(
        initialValue: (current != null && values.contains(current)) ? current : null,
        isExpanded: true,
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
        ),
        items: [
          DropdownMenuItem(value: '', child: Text(label.isEmpty ? 'الكل' : 'الكل')),
          for (final v in values) DropdownMenuItem(value: v, child: Text(v)),
        ],
        onChanged: (v) => setState(() {
          if (v == null || v.isEmpty) {
            _draft.remove(key);
          } else {
            _draft[key] = v;
          }
        }),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.s4),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(rtl ? 'تصفية النتائج' : 'Filter results',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: AppSpacing.s4),
              _dropdown('source', rtl ? 'المصدر' : 'Source',
                  [for (final s in widget.facets.sources) s.key]),
              _dropdown('type', rtl ? 'النوع' : 'Type', widget.facets.types),
              _dropdown('court', rtl ? 'المحكمة' : 'Court', widget.facets.courts),
              _dropdown('city', rtl ? 'المدينة' : 'City', widget.facets.cities),
              _dropdown('year', rtl ? 'السنة الهجرية' : 'Hijri year',
                  [for (final y in widget.facets.years) '$y']),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () {
                        final next = Map<String, String>.from(_draft);
                        next.removeWhere((k, v) => k != 'q');
                        widget.onApply(next);
                        Navigator.of(context).pop();
                      },
                      child: Text(rtl ? 'إعادة تعيين' : 'Reset'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FilledButton(
                      onPressed: () {
                        widget.onApply(_draft);
                        Navigator.of(context).pop();
                      },
                      child: Text(rtl ? 'تطبيق' : 'Apply'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
