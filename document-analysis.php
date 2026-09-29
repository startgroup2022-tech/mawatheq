<?php
$activePage = 'documents';
require_once __DIR__ . '/includes/app_header.php';

$docId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? AND user_id=?');
$stmt->execute([$docId, $_SESSION['user_id']]);
$doc = $stmt->fetch();
if (!$doc) { echo '<div class="app-content"><p>' . (is_rtl() ? 'المستند غير موجود.' : 'Document not found.') . '</p></div>'; require __DIR__.'/includes/app_footer.php'; exit; }

$findStmt = $pdo->prepare('SELECT * FROM document_findings WHERE document_id=? ORDER BY type, sort_order');
$findStmt->execute([$docId]);
$findings = $findStmt->fetchAll();
$risks = array_filter($findings, fn($f) => $f['type'] === 'risk');
$recs  = array_filter($findings, fn($f) => $f['type'] === 'recommendation');

$entStmt = $pdo->prepare('SELECT * FROM document_entities WHERE document_id=?');
$entStmt->execute([$docId]);
$entities = ['date' => [], 'amount' => [], 'party' => [], 'obligation' => []];
foreach ($entStmt->fetchAll() as $e) { $entities[$e['entity_type']][] = $e['value']; }

$statusMeta = [
    'completed'  => ['icon' => '✅', 'color' => 'var(--success)', 'label' => is_rtl() ? 'تم التحليل بنجاح' : 'Analysis completed'],
    'processing' => ['icon' => '⏳', 'color' => 'var(--gold)',    'label' => is_rtl() ? 'جارٍ التحليل...' : 'Analyzing...'],
    'pending'    => ['icon' => '⏸️', 'color' => 'var(--text-muted)', 'label' => is_rtl() ? 'بانتظار تفعيل الذكاء الاصطناعي' : 'Awaiting AI activation'],
    'failed'     => ['icon' => '⚠️', 'color' => 'var(--danger)',  'label' => is_rtl() ? 'تعذّر التحليل' : 'Analysis failed'],
];
$meta = $statusMeta[$doc['status']] ?? $statusMeta['pending'];
$contractAnalysis = [];
if (($doc['document_type'] ?? 'general') === 'contract' && !empty($doc['contract_analysis_json'])) {
    $decodedContract = json_decode($doc['contract_analysis_json'], true);
    if (is_array($decodedContract)) $contractAnalysis = $decodedContract;
}
?>
<div class="app-content">
  <div class="dash-card" style="display:flex;justify-content:space-between;align-items:center">
    <div><strong><?= htmlspecialchars($doc['original_name']) ?></strong><div style="font-size:.82rem;color:<?= $meta['color'] ?>"><?= $meta['label'] ?></div></div>
    <span style="font-size:1.4rem"><?= $meta['icon'] ?></span>
  </div>

  <div class="dash-card">
    <h3>📝 <?= t('analysis_summary') ?></h3>
    <p style="margin:0"><?= htmlspecialchars($doc['summary'] ?? '') ?></p>
  </div>

  <?php if ($doc['status'] === 'completed'): ?>
    <?php if (!empty($risks)): ?>
    <div class="dash-card">
      <h3 style="color:var(--danger)">⚠️ <?= is_rtl() ? 'نقاط المخاطر' : 'Risk Points' ?></h3>
      <?php foreach ($risks as $r): ?><div class="risk-item"><?= htmlspecialchars($r['content']) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($recs)): ?>
    <div class="dash-card">
      <h3 style="color:var(--success)">💡 <?= is_rtl() ? 'التوصيات' : 'Recommendations' ?></h3>
      <?php foreach ($recs as $r): ?><div class="rec-item"><?= htmlspecialchars($r['content']) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (array_filter($entities)): ?>
    <div class="dash-card">
      <h3>🔎 <?= is_rtl() ? 'بيانات مستخرجة من المستند' : 'Extracted document data' ?></h3>
      <div class="grid-2">
        <?php if (!empty($entities['date'])): ?><div><strong style="font-size:.85rem">📅 <?= is_rtl() ? 'تواريخ' : 'Dates' ?></strong><ul style="margin:6px 0 0;padding-inline-start:18px;font-size:.88rem;color:var(--text-muted)"><?php foreach ($entities['date'] as $v): ?><li><?= htmlspecialchars($v) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if (!empty($entities['amount'])): ?><div><strong style="font-size:.85rem">💰 <?= is_rtl() ? 'مبالغ مالية' : 'Amounts' ?></strong><ul style="margin:6px 0 0;padding-inline-start:18px;font-size:.88rem;color:var(--text-muted)"><?php foreach ($entities['amount'] as $v): ?><li><?= htmlspecialchars($v) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if (!empty($entities['party'])): ?><div><strong style="font-size:.85rem">👥 <?= is_rtl() ? 'أطراف' : 'Parties' ?></strong><ul style="margin:6px 0 0;padding-inline-start:18px;font-size:.88rem;color:var(--text-muted)"><?php foreach ($entities['party'] as $v): ?><li><?= htmlspecialchars($v) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (($doc['document_type'] ?? 'general') === 'contract'): ?>
    <div class="dash-card">
      <h3>📑 <?= is_rtl()?'تحليل العقد بنداً بنداً':'Contract clause analysis' ?></h3>
      <div class="form-alert" style="background:#FFF8E6;color:#795b12"><?= is_rtl()?'تحليل آلي أولي مستند إلى الملف المقروء فقط. قائمة البنود الناقصة عامة، ولا تمثل رأياً قانونياً أو تحققاً من نظام نافذ. راجع النص الأصلي والمحامي قبل الاعتماد.':'AI review based only on readable document content. Missing-clause items are a general checklist, not legal advice or verification against current law. Check the original and consult counsel before relying on it.' ?></div>
      <?php
        $contractLabels = ['payment_terms'=>is_rtl()?'شروط الدفع':'Payment terms','termination'=>is_rtl()?'الإنهاء':'Termination','renewal'=>is_rtl()?'التجديد':'Renewal','jurisdiction'=>is_rtl()?'الاختصاص وتسوية النزاع':'Jurisdiction / dispute resolution','liability'=>is_rtl()?'المسؤولية':'Liability','indemnity'=>is_rtl()?'التعويض':'Indemnity','penalties'=>is_rtl()?'الغرامات والجزاءات':'Penalties'];
        $hasContractTerms = false; foreach(array_keys($contractLabels) as $termKey) if(!empty($contractAnalysis[$termKey])) $hasContractTerms=true;
      ?>
      <?php if ($hasContractTerms): ?><div class="grid-2"><?php foreach($contractLabels as $termKey=>$termLabel): if(empty($contractAnalysis[$termKey])) continue; ?><div class="rec-item"><strong><?= htmlspecialchars($termLabel) ?></strong><div><?= nl2br(htmlspecialchars((string)$contractAnalysis[$termKey])) ?></div></div><?php endforeach; ?></div><?php endif; ?>
      <?php $clauses = is_array($contractAnalysis['clauses'] ?? null) ? $contractAnalysis['clauses'] : []; ?>
      <?php if ($clauses): ?><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= is_rtl()?'البند':'Clause' ?></th><th><?= is_rtl()?'المقتطف':'Excerpt' ?></th><th><?= is_rtl()?'المخاطر':'Risk' ?></th><th><?= is_rtl()?'الملاحظة والتوصية':'Issue and recommendation' ?></th></tr></thead><tbody>
      <?php foreach($clauses as $clause): if(!is_array($clause)) continue; $risk = in_array(($clause['risk_level'] ?? ''),['low','medium','high','unknown'],true) ? $clause['risk_level'] : 'unknown'; $present=$clause['present']??null; ?><tr><td><strong><?= htmlspecialchars((string)($clause['clause_name'] ?? '—')) ?></strong><div><?= $present===true ? (is_rtl()?'موجود':'Present') : ($present===false ? (is_rtl()?'لم يظهر':'Not found') : (is_rtl()?'غير واضح':'Unclear')) ?></div></td><td><?= nl2br(htmlspecialchars((string)($clause['excerpt'] ?? '—'))) ?></td><td><?= htmlspecialchars($risk) ?></td><td><?= nl2br(htmlspecialchars(trim((string)($clause['issue'] ?? '') . "\n" . (string)($clause['recommendation'] ?? '')))) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
      <?php foreach(['obligations'=>is_rtl()?'الالتزامات المستخرجة':'Extracted obligations','missing_clauses'=>is_rtl()?'بنود لم تظهر (قائمة تحقق عامة)':'Clauses not found (general checklist)','unusual_clauses'=>is_rtl()?'بنود غير معتادة تستحق المراجعة':'Unusual clauses to review'] as $listKey=>$listTitle): $listValues=is_array($contractAnalysis[$listKey]??null)?$contractAnalysis[$listKey]:[]; if(!$listValues) continue; ?><h4 style="margin:18px 0 8px"><?= htmlspecialchars($listTitle) ?></h4><ul><?php foreach($listValues as $listValue): if(is_array($listValue)) $listValue=json_encode($listValue,JSON_UNESCAPED_UNICODE); ?><li><?= htmlspecialchars((string)$listValue) ?></li><?php endforeach; ?></ul><?php endforeach; ?>
      <?php if (!$contractAnalysis): ?><p style="color:var(--text-muted)"><?= is_rtl()?'لم يُرجع مزود الذكاء الاصطناعي تفاصيل بنود منظمة لهذا العقد.':'The AI provider did not return structured clause details for this contract.' ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
  <?php elseif ($doc['status'] === 'pending'): ?>
    <div class="form-alert" style="background:#FFF8E6;color:#8A6D1D"><?= is_rtl() ? 'لتفعيل التحليل التلقائي، على الإدارة ربط مزوّد ذكاء اصطناعي من لوحة التحكم ← الإعدادات.' : 'To enable automatic analysis, an admin needs to connect an AI provider from Settings.' ?></div>
  <?php endif; ?>

  <a href="/consultations.php" class="btn btn-outline">⚖️ <?= t('discuss_with_lawyer') ?></a>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
