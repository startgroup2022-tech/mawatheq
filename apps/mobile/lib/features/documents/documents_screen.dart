import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Document list from `GET /api/v1/documents.php`.
///
/// Upload is deliberately not wired to a file picker in this build: adding a
/// picker needs a platform plugin that was not part of the approved dependency
/// set, and shipping a button that cannot pick a file would be worse than
/// documenting the gap. The upload path (`ApiRepository.uploadDocument`, which
/// enforces the AI-consent flag) is implemented and ready for that plugin.
class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({super.key});

  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  final _repo = ApiRepository();
  List<DocumentSummary> _all = const [];
  bool _loading = true;
  String? _error;
  String _filter = 'الكل';

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
      final docs = await _repo.documents();
      if (!mounted) return;
      setState(() {
        _all = docs;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  List<DocumentSummary> get _visible {
    if (_filter == 'الكل') return _all;
    if (_filter == 'مكتملة') return _all.where((d) => d.isAnalysed).toList();
    if (_filter == 'قيد المعالجة') return _all.where((d) => d.isProcessing).toList();
    return _all;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: LoadingSkeleton(count: 6),
      );
    }
    if (_error != null) return ErrorState(message: _error!, onRetry: _load);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
          child: FilterChips(
            options: const ['الكل', 'مكتملة', 'قيد المعالجة'],
            selected: _filter,
            onSelected: (v) => setState(() => _filter = v),
          ),
        ),
        Expanded(
          child: _visible.isEmpty
              ? EmptyState(
                  icon: Icons.description_outlined,
                  title: 'لا توجد مستندات',
                  message: _all.isEmpty
                      ? 'لم تُرفع أي مستندات إلى حسابك بعد.'
                      : 'لا توجد مستندات مطابقة للتصفية.',
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                    itemCount: _visible.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, i) => DocumentCard(
                      item: _visible[i],
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) =>
                              DocumentDetailScreen(documentId: _visible[i].id),
                        ),
                      ),
                    ),
                  ),
                ),
        ),
      ],
    );
  }
}

/// Document detail: metadata plus the AI analysis result when it exists.
class DocumentDetailScreen extends StatefulWidget {
  const DocumentDetailScreen({super.key, required this.documentId});

  final int documentId;

  @override
  State<DocumentDetailScreen> createState() => _DocumentDetailScreenState();
}

class _DocumentDetailScreenState extends State<DocumentDetailScreen> {
  final _repo = ApiRepository();
  Map<String, dynamic>? _data;
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
      final data = await _repo.documentDetail(widget.documentId);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BrandAppBar(subtitle: 'تفاصيل المستند'),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _buildBody(),
    );
  }

  Widget _buildBody() {
    final json = Json(_data ?? const {});
    final doc = json.object('document') ?? json;
    final analysis = json.object('analysis');

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.surface,
            border: Border.all(color: AppColors.border),
            borderRadius: BorderRadius.circular(AppRadius.lg),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      doc.str('title', fallback: 'مستند #${widget.documentId}'),
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  StatusBadge(status: doc.str('status', fallback: 'pending')),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                'النوع: ${doc.str('document_type', fallback: 'general')}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        SectionHeader(title: 'التحليل الذكي'),
        if (analysis == null)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.surface2,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: AppColors.border),
            ),
            child: Text(
              doc.str('status') == 'processing'
                  ? 'التحليل قيد المعالجة. سيظهر الناتج عند اكتماله.'
                  : 'لا يوجد تحليل محفوظ لهذا المستند بعد.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          )
        else
          ..._analysisSections(analysis),
      ],
    );
  }

  /// Renders only the analysis fields the backend actually returned.
  List<Widget> _analysisSections(Json analysis) {
    final widgets = <Widget>[];
    final summary = analysis.str('summary');
    if (summary.isNotEmpty) {
      widgets.add(Text(summary, style: Theme.of(context).textTheme.bodyMedium));
      widgets.add(const SizedBox(height: 12));
    }

    for (final entry in const [
      ('المخاطر', 'risks'),
      ('الالتزامات', 'obligations'),
      ('البنود المهمة', 'key_clauses'),
    ]) {
      final list = analysis.list(entry.$2);
      if (list.isEmpty) continue;
      widgets.add(Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(entry.$1,
            style: Theme.of(context).textTheme.titleMedium),
      ));
      widgets.addAll(list.map((item) => Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Padding(
                  padding: EdgeInsets.only(top: 6),
                  child: Icon(Icons.circle, size: 6, color: AppColors.teal),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    item.str('text', fallback: item.str('title', fallback: item.toString())),
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
          )));
      widgets.add(const SizedBox(height: 12));
    }
    return widgets;
  }
}
