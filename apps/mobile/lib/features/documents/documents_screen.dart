import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Document list from `GET /api/v1/documents.php`, with upload.
///
/// Upload is only offered to a capability that can actually perform it: a client
/// always may, a lawyer only with `documents.upload` and only into a case of their
/// own tenant (picked from `GET /api/v1/cases.php`). The file picker is restricted
/// to the formats the server accepts — pdf, png, jpg — so the user cannot select a
/// file the backend will reject.
class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({super.key, this.repository});

  /// Injected in tests; production uses the default client.
  final ApiRepository? repository;

  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();
  List<DocumentSummary> _all = const [];
  bool _loading = true;
  bool _uploading = false;
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

  // ------------------------------------------------------------------ upload

  /// Whether this account may upload at all. The server is the authority; this only
  /// decides whether to show the button.
  bool get _canUpload {
    final user = context.read<AuthController>().user;
    if (user.isClient) return true;
    if (user.isLawyer) return user.can('documents.upload');
    return false;
  }

  Future<void> _startUpload() async {
    final user = context.read<AuthController>().user;
    final picked = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: const ['pdf', 'png', 'jpg', 'jpeg'],
      withData: false,
    );
    final path = picked?.files.single.path;
    if (path == null || !mounted) return;

    // A lawyer's document must live on one of their own cases; a client's upload is
    // account-scoped and the server attaches it without a case.
    String? caseId;
    if (user.isLawyer) {
      caseId = await _pickCase();
      if (caseId == null || !mounted) return;
    }

    final documentType = await _pickDocumentType();
    if (documentType == null || !mounted) return;

    final consent = await ConfirmationDialog.show(
      context,
      title: 'موافقة التحليل الذكي',
      message:
          'سيُرسل نص هذا المستند إلى مزوّد الذكاء الاصطناعي لتحليله. '
          'يمكنك رفض الموافقة الآن؛ عندها لن يُرفع المستند.',
      confirmLabel: 'أوافق وأرفع',
      cancelLabel: 'إلغاء',
    );
    if (!consent || !mounted) return;

    setState(() => _uploading = true);
    try {
      await _repo.uploadDocument(
        file: File(path),
        aiConsent: true,
        documentType: documentType,
        caseId: caseId,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('تم رفع المستند، وسيظهر بعد اكتمال التحليل.'),
        ),
      );
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<String?> _pickCase() async {
    List<CaseSummary> cases;
    try {
      cases = await _repo.cases();
    } on ApiException catch (e) {
      if (!mounted) return null;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(e.message)));
      return null;
    }
    if (!mounted) return null;
    if (cases.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('لا توجد قضايا مرتبطة بحسابك لربط المستند بها.'),
        ),
      );
      return null;
    }
    return showModalBottomSheet<String>(
      context: context,
      builder: (sheetContext) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            const Padding(
              padding: EdgeInsets.all(16),
              child: Text('اختر القضية المرتبطة بالمستند'),
            ),
            for (final c in cases)
              ListTile(
                title: Text(c.title),
                subtitle: c.caseNumber.isEmpty ? null : Text(c.caseNumber),
                onTap: () => Navigator.of(sheetContext).pop(c.id.toString()),
              ),
          ],
        ),
      ),
    );
  }

  Future<String?> _pickDocumentType() => showModalBottomSheet<String>(
    context: context,
    builder: (sheetContext) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Padding(
            padding: EdgeInsets.all(16),
            child: Text('نوع المستند'),
          ),
          ListTile(
            title: const Text('مستند عام'),
            onTap: () => Navigator.of(sheetContext).pop('general'),
          ),
          ListTile(
            title: const Text('عقد'),
            onTap: () => Navigator.of(sheetContext).pop('contract'),
          ),
        ],
      ),
    ),
  );

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
        if (_canUpload)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
            child: PrimaryButton(
              label: 'رفع مستند جديد',
              icon: Icons.upload_file,
              expand: true,
              loading: _uploading,
              onPressed: _uploading ? null : _startUpload,
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
    // The server returns flat `findings` (type/content) and `entities`
    // (entity_type/value) arrays, not a nested `analysis` object.
    final findings = json.list('findings');
    final entities = json.list('entities');

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: context.tokens.surface,
            border: Border.all(color: context.tokens.border),
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
        if (findings.isEmpty && entities.isEmpty)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.surface2,
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: context.tokens.border),
            ),
            child: Text(
              doc.str('status') == 'processing'
                  ? 'التحليل قيد المعالجة. سيظهر الناتج عند اكتماله.'
                  : 'لا يوجد تحليل محفوظ لهذا المستند بعد.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          )
        else
          ..._analysisSections(doc, findings, entities),
      ],
    );
  }

  /// Renders the analysis the backend actually returned: a one-line summary plus the
  /// grouped findings and extracted entities. Nothing is invented for a document whose
  /// analysis has not completed.
  List<Widget> _analysisSections(
    Json doc,
    List<Json> findings,
    List<Json> entities,
  ) {
    final widgets = <Widget>[];
    final summary = doc.str('summary');
    if (summary.isNotEmpty) {
      widgets.add(Text(summary, style: Theme.of(context).textTheme.bodyMedium));
      widgets.add(const SizedBox(height: 12));
    }

    for (final entry in const [
      ('المخاطر', 'risk'),
      ('التوصيات', 'recommendation'),
      ('الالتزامات', 'obligation'),
      ('البنود المهمة', 'clause'),
    ]) {
      final items = findings.where((f) => f.str('type') == entry.$2).toList();
      if (items.isEmpty) {
        continue;
      }
      widgets.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text(entry.$1, style: Theme.of(context).textTheme.titleMedium),
        ),
      );
      widgets.addAll(items.map((f) => _bullet(f.str('content'))));
      widgets.add(const SizedBox(height: 12));
    }

    if (entities.isNotEmpty) {
      widgets.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text(
            'الكيانات المستخرجة',
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
      );
      widgets.addAll(
        entities.map(
          (e) => _bullet(
            '${_entityLabel(e.str('entity_type'))}: ${e.str('value')}',
          ),
        ),
      );
    }
    return widgets;
  }

  Widget _bullet(String text) => Padding(
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
          child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
        ),
      ],
    ),
  );

  static String _entityLabel(String type) => switch (type) {
    'date' => 'تاريخ',
    'amount' => 'مبلغ',
    'party' => 'طرف',
    _ => type,
  };
}
