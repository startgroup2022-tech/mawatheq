<?php
$activePage = 'draft-reviews';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/csrf.php';
$userId = (int)$_SESSION['user_id'];
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    if (!in_array($decision, ['approved','changes_requested'], true)) {
        $message = is_rtl() ? 'اختر اعتماد المسودة أو طلب تعديلات.' : 'Choose approval or request changes.';
    } else {
        $q = $pdo->prepare("SELECT d.id,d.lawyer_id,d.title FROM draft_documents d JOIN cases c ON c.id=d.case_id WHERE d.id=? AND c.user_id=? AND d.client_visible=1 AND d.approval_status IN ('submitted','changes_requested') LIMIT 1");
        $q->execute([$draftId,$userId]); $draft = $q->fetch();
        if (!$draft) {
            $message = is_rtl() ? 'المسودة غير متاحة للمراجعة.' : 'This draft is not available for review.';
        } else {
            $notes = trim($_POST['notes'] ?? '');
            $pdo->prepare('INSERT INTO draft_reviews (draft_document_id,user_id,decision,notes) VALUES (?,?,?,?)')->execute([$draftId,$userId,$decision,$notes ?: null]);
            $pdo->prepare('UPDATE draft_documents SET approval_status=? WHERE id=?')->execute([$decision,$draftId]);
            $label = $decision === 'approved' ? (is_rtl()?'اعتمد العميل المسودة':'Client approved the draft') : (is_rtl()?'طلب العميل تعديلات على المسودة':'Client requested draft changes');
            $pdo->prepare('INSERT INTO notifications (recipient_type,recipient_id,title,message,link) VALUES (?,?,?,?,?)')->execute(['lawyer',(int)$draft['lawyer_id'],$label,$draft['title'],'/lawyer/drafting.php#draft-'.$draftId]);
            header('Location: /draft-reviews.php'); exit;
        }
    }
}
$q = $pdo->prepare("SELECT d.*,c.title AS case_title,l.full_name AS lawyer_name FROM draft_documents d JOIN cases c ON c.id=d.case_id JOIN lawyers l ON l.id=d.lawyer_id WHERE c.user_id=? AND d.client_visible=1 ORDER BY d.created_at DESC");
$q->execute([$userId]); $drafts = $q->fetchAll();
?>
<div class="app-content"><div class="toolbar"><h2><?= is_rtl()?'مراجعة المسودات القانونية':'Review legal drafts' ?></h2></div>
<div class="form-alert" style="background:#FFF8E6;color:#8A6D1D"><?= is_rtl()?'اعتمادك يؤكد مراجعتك للمسودة مع المحامي، ولا يحولها إلى مستند نهائي أو يغني عن مراجعة المحامي المرخص.':'Your approval records your review with the lawyer; it does not make this a final legal document or replace lawyer review.' ?></div>
<?php if ($message): ?><div class="form-alert error"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php foreach ($drafts as $draft): ?><div class="dash-card"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><h3><?= htmlspecialchars($draft['title']) ?></h3><p><?= htmlspecialchars($draft['case_title']) ?> · <?= htmlspecialchars($draft['lawyer_name']) ?></p></div><span class="status-pill status-pending"><?= ['draft'=>'Draft','submitted'=>is_rtl()?'بانتظار مراجعتك':'Awaiting your review','approved'=>is_rtl()?'معتمدة':'Approved','changes_requested'=>is_rtl()?'تعديلات مطلوبة':'Changes requested'][$draft['approval_status']] ?? '' ?></span></div>
<textarea readonly rows="12" style="width:100%;margin-top:12px;font-family:inherit;line-height:1.8"><?= htmlspecialchars($draft['generated_content'] ?? '') ?></textarea>
<?php if (in_array($draft['approval_status'], ['submitted','changes_requested'], true)): ?><form method="post" class="grid-2" style="margin-top:14px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="draft_id" value="<?= (int)$draft['id'] ?>"><div class="field"><label><?= is_rtl()?'ملاحظاتك للمحامي':'Notes for the lawyer' ?></label><textarea name="notes" rows="2"></textarea></div><div class="field"><label><?= is_rtl()?'قرار المراجعة':'Review decision' ?></label><select name="decision"><option value="approved"><?= is_rtl()?'أعتمد المسودة':'Approve draft' ?></option><option value="changes_requested"><?= is_rtl()?'أطلب تعديلات':'Request changes' ?></option></select></div><div><button class="btn btn-primary btn-sm" type="submit"><?= is_rtl()?'إرسال المراجعة':'Submit review' ?></button></div></form><?php endif; ?>
<?php $reviews=$pdo->prepare('SELECT decision,notes,created_at FROM draft_reviews WHERE draft_document_id=? ORDER BY created_at DESC');$reviews->execute([(int)$draft['id']]);foreach($reviews->fetchAll() as $review): ?><div class="rec-item"><small><?= date('Y-m-d H:i',strtotime($review['created_at'])) ?></small> · <?= htmlspecialchars($review['decision']) ?><?php if ($review['notes']): ?><div><?= nl2br(htmlspecialchars($review['notes'])) ?></div><?php endif; ?></div><?php endforeach; ?></div><?php endforeach; ?>
<?php if (!$drafts): ?><div class="dash-card" style="text-align:center;color:var(--text-muted)"><?= is_rtl()?'لا توجد مسودات بانتظار المراجعة.':'No drafts have been shared for review.' ?></div><?php endif; ?></div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
