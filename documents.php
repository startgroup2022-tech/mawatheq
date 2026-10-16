<?php
$activePage = 'documents';
require_once __DIR__ . '/includes/app_header.php';

$stmt = $pdo->prepare('SELECT d.*, c.title AS case_title FROM documents d LEFT JOIN cases c ON c.id=d.case_id WHERE d.user_id=? ORDER BY d.id DESC');
$stmt->execute([$_SESSION['user_id']]);
$docs = $stmt->fetchAll();

// Cases the client owns, so an upload can be filed against the case it belongs to.
$userCases = $pdo->prepare('SELECT id,title FROM cases WHERE user_id=? ORDER BY created_at DESC');
$userCases->execute([$_SESSION['user_id']]);
$userCases = $userCases->fetchAll();
?>
<div class="app-content">
  <h2><?= t('feat2_title') ?></h2>
  <p style="margin-bottom:20px"><?= t('feat2_body') ?></p>
  <?php if (($_GET['error'] ?? '') === 'ai_consent'): ?><div class="form-alert error"><?= is_rtl()?'يجب الموافقة على إرسال المستند لمزود AI لتحليله.':'Consent to AI processing is required to analyze a document.' ?></div><?php endif; ?>

  <form class="dash-card" id="uploadForm" method="post" action="/api/upload_document.php" enctype="multipart/form-data" style="text-align:center"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label class="upload-box" for="fileInput" id="uploadLabel">
      <div style="font-size:2rem;margin-bottom:10px">📤</div>
      <strong><?= is_rtl() ? 'اسحب الملف هنا أو اضغط للاختيار' : 'Drag a file here or click to browse' ?></strong>
      <p style="margin:6px 0 0"><?= is_rtl() ? 'PDF أو صورة — حتى ' . MAX_UPLOAD_SIZE_MB . ' ميجابايت' : 'PDF or image — up to ' . MAX_UPLOAD_SIZE_MB . 'MB' ?></p>
    </label>
    <input type="file" name="document" id="fileInput" accept=".pdf,.png,.jpg,.jpeg" style="display:none">
        <div class="field" style="max-width:420px;margin:12px auto;text-align:start"><label><?= is_rtl()?'نوع التحليل':'Analysis type' ?></label><select name="document_type" class="pro-select"><option value="general"><?= is_rtl()?'مستند عام':'General document' ?></option><option value="contract"><?= is_rtl()?'تحليل عقد تفصيلي':'Detailed contract analysis' ?></option></select></div>
          <?php if ($userCases): ?>
          <div class="field" style="max-width:420px;margin:12px auto;text-align:start"><label><?= is_rtl()?'القضية المرتبطة (اختياري)':'Related case (optional)' ?></label><select name="case_id" class="pro-select"><option value=""><?= is_rtl()?'بدون قضية محددة':'No specific case' ?></option><?php foreach ($userCases as $uc): ?><option value="<?= (int)$uc['id'] ?>"><?= htmlspecialchars($uc['title']) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
    <p id="selectedFileName" style="font-size:.82rem;color:var(--text-muted)"></p>
    <label class="field-hint" style="display:block;margin:12px"><input type="checkbox" name="ai_consent" value="1" required> <?= is_rtl() ? 'أوافق على إرسال المستند إلى مزود الذكاء الاصطناعي المهيأ لتحليله.' : 'I agree to send this document to the configured AI provider for analysis.' ?></label>
    <button class="btn btn-primary" id="uploadButton" type="submit" disabled><?= is_rtl() ? 'رفع وتحليل المستند' : 'Upload and analyze document' ?></button>
    <input type="hidden" name="ajax" value="0">
  </form>

  <h3 style="margin:28px 0 14px"><?= is_rtl() ? 'المستندات السابقة' : 'Previous documents' ?></h3>
  <?php if (empty($docs)): ?>
    <p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد مستندات مرفوعة بعد.' : 'No documents uploaded yet.' ?></p>
  <?php endif; ?>
  <?php foreach ($docs as $doc): ?>
    <div class="dash-card" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
      <div>
        <a href="/document-analysis.php?id=<?= (int)$doc['id'] ?>" style="text-decoration:none"><strong><?= htmlspecialchars($doc['original_name']) ?></strong></a> <?php if (($doc['document_type'] ?? 'general')==='contract'): ?><span class="status-pill status-pending"><?= is_rtl()?'تحليل عقد':'Contract review' ?></span><?php endif; ?>
        <?php if (!empty($doc['case_title'])): ?><span class="status-pill status-confirmed"><?= is_rtl()?'القضية: ':'Case: ' ?><?= htmlspecialchars($doc['case_title']) ?></span><?php endif; ?>
        <div style="font-size:.82rem;color:var(--text-muted);margin-top:4px"><?= date('Y-m-d H:i', strtotime($doc['created_at'])) ?></div>
      </div>
      <div style="display:flex;align-items:center;gap:10px"><span class="status-pill status-<?= htmlspecialchars($doc['status']) ?>"><?= htmlspecialchars($doc['status']) ?></span><a class="btn btn-outline btn-sm" href="/download-document.php?id=<?= (int)$doc['id'] ?>"><?= is_rtl()?'تنزيل الملف':'Download file' ?></a></div>
    </div>
  <?php endforeach; ?>
</div>
<script>
document.getElementById('fileInput').addEventListener('change',function(){
  document.getElementById('selectedFileName').textContent=this.files.length?this.files[0].name:'';
  document.getElementById('uploadButton').disabled=!this.files.length;
});
</script>

<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
