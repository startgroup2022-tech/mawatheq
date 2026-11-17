import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/auth_controller.dart';
import '../../core/config/app_config.dart';
import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'library_widgets.dart';

/// One library item, from `GET /api/v1/legal-library.php?action=item`.
///
/// The redistribution rule is decided server-side: a `hosted` source returns the
/// text and the app shows it inline; a `link_only` source returns metadata and its
/// official URL, and the download button opens that URL — the app never builds a
/// copy of a file the platform may not host.
class LegalLibraryItemScreen extends StatefulWidget {
  const LegalLibraryItemScreen({
    super.key,
    required this.itemId,
    this.repository,
  });

  final int itemId;
  final ApiRepository? repository;

  @override
  State<LegalLibraryItemScreen> createState() => _LegalLibraryItemScreenState();
}

class _LegalLibraryItemScreenState extends State<LegalLibraryItemScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  LegalLibraryItem? _item;
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
      final item = await _repo.legalLibraryItem(widget.itemId);
      if (!mounted) return;
      setState(() {
        _item = item;
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
        _error = 'تعذّر تحميل العنصر. أعد المحاولة.';
      });
    }
  }

  Future<void> _toggleFavorite() async {
    final item = _item;
    if (item == null) return;
    final auth = context.read<AuthController?>();
    if (auth == null || !auth.isSignedIn) {
      await requireAccount(
        context,
        ProtectedDestination.account,
        message: context.read<LocaleController>().isRtl
            ? 'أنشئ حساباً لحفظ العنصر في «مكتبتي».'
            : 'Create an account to save this item to “My library”.',
      );
      return;
    }
    try {
      final saved = await _repo.legalLibraryToggleFavorite(item.id);
      if (!mounted) return;
      setState(() => _item = _copyWithFavorite(item, saved));
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر تحديث الحفظ. حاول مرة أخرى.')),
      );
    }
  }

  LegalLibraryItem _copyWithFavorite(LegalLibraryItem item, bool saved) {
    return LegalLibraryItem(
      id: item.id,
      type: item.type,
      typeLabel: item.typeLabel,
      title: item.title,
      decisionTitle: item.decisionTitle,
      titleIsDerived: item.titleIsDerived,
      summary: item.summary,
      courtName: item.courtName,
      city: item.city,
      caseNumber: item.caseNumber,
      decisionNumber: item.decisionNumber,
      hijriYear: item.hijriYear,
      gregorianYear: item.gregorianYear,
      decisionDate: item.decisionDate,
      category: item.category,
      sourceKey: item.sourceKey,
      sourceName: item.sourceName,
      publisher: item.publisher,
      sourceUrl: item.sourceUrl,
      fileUrl: item.fileUrl,
      fileType: item.fileType,
      fileSizeKb: item.fileSizeKb,
      canReadHere: item.canReadHere,
      canDownload: item.canDownload,
      downloadUrl: item.downloadUrl,
      downloadIsExternal: item.downloadIsExternal,
      redistribution: item.redistribution,
      body: item.body,
      sections: item.sections,
      isFavorite: saved,
    );
  }

  Future<void> _openUrl(String? raw, bool external) async {
    if (raw == null || raw.isEmpty) return;
    // A hosted file is served from the app's own host; a link_only item points at
    // the official source. The server tells us which, so we resolve accordingly.
    final url = raw.startsWith('http') ? raw : '${AppConfig.siteBase}$raw';
    final uri = Uri.tryParse(url);
    if (uri == null) return;
    try {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر فتح الرابط.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    final item = _item;

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'تفاصيل العنصر' : 'Item detail'),
        actions: [
          if (item != null)
            IconButton(
              tooltip: rtl ? 'حفظ' : 'Save',
              onPressed: _toggleFavorite,
              icon: Icon(
                item.isFavorite ? Icons.bookmark : Icons.bookmark_border,
                color: item.isFavorite ? AppColors.teal : null,
              ),
            ),
        ],
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(AppSpacing.s4),
              child: LoadingSkeleton(count: 6),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : item == null
                  ? const SizedBox.shrink()
                  : _body(item, rtl),
    );
  }

  Widget _body(LegalLibraryItem item, bool rtl) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (item.typeLabel.isNotEmpty)
              _chip(context, item.typeLabel, AppColors.teal),
            if (item.sourceName.isNotEmpty)
              _chip(context, item.sourceName, AppColors.navy),
            if (item.canReadHere) LibraryReadableBadge(rtl: rtl),
          ],
        ),
        const SizedBox(height: AppSpacing.s4),
        Text(
          item.title,
          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, height: 1.6),
        ),
        if (item.decisionTitle.isNotEmpty && item.decisionTitle != item.title) ...[
          const SizedBox(height: 6),
          Text(item.decisionTitle,
              style: TextStyle(color: context.tokens.textMuted, fontSize: 13, height: 1.6)),
        ],
        const SizedBox(height: AppSpacing.s4),
        Card(
          color: context.tokens.surface,
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.s4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (item.courtName.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'الجهة القضائية' : 'Court', value: item.courtName),
                if (item.city.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'المنطقة' : 'Region', value: item.city),
                if (item.decisionNumber.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'رقم الحكم' : 'Decision no.', value: item.decisionNumber),
                if (item.caseNumber.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'رقم القضية' : 'Case no.', value: item.caseNumber),
                if (item.decisionDate.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'التاريخ' : 'Date', value: item.decisionDate),
                if (item.hijriYear != null)
                  LibraryMetaRow(
                    label: rtl ? 'السنة الهجرية' : 'Hijri year',
                    value: '${item.hijriYear}${item.gregorianYear != null ? " (${item.gregorianYear}م)" : ""}',
                  ),
                if (item.category.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'التصنيف' : 'Category', value: item.category),
                if (item.publisher.isNotEmpty)
                  LibraryMetaRow(label: rtl ? 'الناشر' : 'Publisher', value: item.publisher),
                if (item.fileType.isNotEmpty)
                  LibraryMetaRow(
                    label: rtl ? 'الملف' : 'File',
                    value: item.fileType.toUpperCase() +
                        (item.fileSizeKb != null ? ' · ${item.fileSizeKb} KB' : ''),
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.s4),
        if (item.summary.isNotEmpty) ...[
          Text(item.summary, style: const TextStyle(height: 1.9)),
          const SizedBox(height: AppSpacing.s4),
        ],
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            if (item.canDownload && item.downloadUrl != null)
              PrimaryButton(
                label: item.downloadIsExternal
                    ? (rtl ? 'تنزيل من المصدر الرسمي' : 'Download from source')
                    : (rtl ? 'تنزيل الملف' : 'Download file'),
                icon: Icons.download_outlined,
                onPressed: () => _openUrl(item.downloadUrl, item.downloadIsExternal),
              ),
            if (item.sourceUrl != null)
              SecondaryButton(
                label: rtl ? 'الصفحة الرسمية' : 'Official page',
                icon: Icons.open_in_new,
                onPressed: () => _openUrl(item.sourceUrl, true),
              ),
          ],
        ),
        const SizedBox(height: AppSpacing.s4),
        // Structured sections first (facts/reasons/ruling), then any body text not
        // already covered by the ruling section. Nothing is generated — each block is
        // the source's own text.
        ..._contentBlocks(item, rtl),
      ],
    );
  }

  List<Widget> _contentBlocks(LegalLibraryItem item, bool rtl) {
    final out = <Widget>[];
    final sections = item.sections;
    final ruling = sections?.ruling;
    final body = item.body;

    if (ruling != null && ruling.isNotEmpty) {
      out.add(LibrarySectionBlock(
        title: rtl ? 'منطوق الحكم' : 'Ruling',
        body: ruling,
        icon: Icons.gavel_outlined,
      ));
    }
    if (sections?.facts != null && sections!.facts!.isNotEmpty) {
      out.add(LibrarySectionBlock(
        title: rtl ? 'الوقائع' : 'Facts',
        body: sections.facts!,
        icon: Icons.description_outlined,
      ));
    }
    if (sections?.reasons != null && sections!.reasons!.isNotEmpty) {
      out.add(LibrarySectionBlock(
        title: rtl ? 'الأسباب' : 'Reasons',
        body: sections.reasons!,
        icon: Icons.list_alt_outlined,
      ));
    }
    if (sections?.narration != null && sections!.narration!.isNotEmpty) {
      out.add(LibrarySectionBlock(
        title: rtl ? 'الوقائع والسرد' : 'Narration',
        body: sections.narration!,
        icon: Icons.article_outlined,
      ));
    }

    // Body text is shown when it is not identical to the ruling block above.
    if (body != null && body.isNotEmpty && body.trim() != (ruling ?? '').trim()) {
      out.add(LibrarySectionBlock(
        title: rtl ? 'نص الحكم' : 'Ruling text',
        body: _plainText(body),
      ));
    }

    if (out.isNotEmpty) return out;

    // No readable text: explain why, honestly, based on the redistribution rule.
    if (item.redistribution == 'link_only') {
      return [
        Container(
          padding: const EdgeInsets.all(AppSpacing.s3),
          decoration: BoxDecoration(
            color: context.tokens.warningBg,
            borderRadius: BorderRadius.circular(AppRadius.md),
            border: Border.all(color: context.tokens.warningBorder),
          ),
          child: Text(
            rtl
                ? 'هذا المصدر يُعرض بالبيانات والرابط الرسمي فقط، ولا يُنسخ نصه أو ملفه داخل مواثيق التزاماً بسياسة إعادة النشر. استخدم «الصفحة الرسمية» لقراءة النص الكامل لدى الجهة المصدِرة.'
                : 'This source is shown as metadata plus its official link; its text or file is not copied into Mawatheq, in line with the redistribution policy. Use “Official page” to read the full text at the issuing authority.',
            style: TextStyle(color: context.tokens.warningFg, height: 1.7, fontSize: 13),
          ),
        ),
      ];
    }
    return [
      Text(
        rtl
            ? 'نص هذا العنصر غير متاح للعرض المباشر. استخدم الصفحة الرسمية لعرضه.'
            : 'This item has no inline text. Use the official page to view it.',
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ];
  }

  /// The stored text may arrive as the portal's HTML with `<br>` breaks. Render it
  /// as plain text: it is third-party, so it is shown as text, never as markup.
  String _plainText(String body) {
    return body
        .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'<[^>]+>'), '')
        .trim();
  }

  Widget _chip(BuildContext context, String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: context.tokens.surface3,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        text,
        style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: color),
      ),
    );
  }
}
