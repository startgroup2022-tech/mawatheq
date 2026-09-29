<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
$lawyerId = (int)$_SESSION['lawyer_id'];
$membershipQuery = $pdo->prepare('SELECT firm_id,member_role FROM law_firm_members WHERE lawyer_id=? LIMIT 1');
$membershipQuery->execute([$lawyerId]);
$membership = $membershipQuery->fetch() ?: null;
$message = null;

if (!$membership) { http_response_code(403); exit(is_rtl()?'هذه الصفحة لأعضاء المكاتب القانونية فقط.':'This page is available to law-firm members only.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (!in_array($decision,['approved','changes_requested','rejected'],true)) {
        $message = is_rtl()?'قرار المراجعة غير صالح.':'Invalid review decision.';
    } else {
        try {
            $pdo->beginTransaction();
            $draftQuery = $pdo->prepare('SELECT d.id,d.lawyer_id,d.approval_stage,d.approval_version,d.status,d.approval_firm_id AS firm_id FROM draft_documents d WHERE d.id=? AND d.approval_firm_id=? FOR UPDATE');
            $draftQuery->execute([$draftId,(int)$membership['firm_id']]);
            $draft = $draftQuery->fetch();
            $stage = $draft['approval_stage'] ?? '';
            $isLawyerReviewer = in_array($membership['member_role'],['owner','partner','lawyer'],true);
            $isPartnerReviewer = in_array($membership['member_role'],['owner','partner'],true);
            $eligible = $draft && (int)$draft['lawyer_id'] !== $lawyerId && $draft['status']==='generated'
                && (($stage==='lawyer_review' && $isLawyerReviewer) || ($stage==='partner_review' && $isPartnerReviewer));
            if (!$eligible) {
                $pdo->rollBack();
                $message = is_rtl()?'المسودة غير موجودة في مكتبك أو لا يمكنك مراجعة هذه المرحلة.':'Draft not found in your firm or you are not authorized for this review stage.';
            } else {
                $nextStage = $stage;
                $approvalStatus = null;
                if ($decision==='approved') $nextStage = $stage==='lawyer_review' ? 'partner_review' : 'approved';
                if ($decision==='changes_requested') { $nextStage='changes_requested'; $approvalStatus='changes_requested'; }
                if ($decision==='rejected') { $nextStage='rejected'; $approvalStatus='changes_requested'; }
                $update = $pdo->prepare('UPDATE draft_documents SET approval_stage=?,approval_status=COALESCE(?,approval_status),client_visible=0 WHERE id=? AND approval_stage=? AND approval_version=?');
                $update->execute([$nextStage,$approvalStatus,$draftId,$stage,(int)$draft['approval_version']]);
                if (!$update->rowCount()) throw new RuntimeException('Draft review state changed concurrently.');
                $history = $pdo->prepare('INSERT INTO draft_workflow_reviews (draft_document_id,reviewer_lawyer_id,stage,decision,notes,version_number) VALUES (?,?,?,?,?,?)');
                $history->execute([$draftId,$lawyerId,$stage,$decision,$notes?:null,(int)$draft['approval_version']]);
                if ($decision==='approved' && $stage==='lawyer_review') {
                    $notify = $pdo->prepare("INSERT INTO notifications (recipient_type,recipient_id,title,message,link) SELECT 'lawyer',lawyer_id,?,?,? FROM law_firm_members WHERE firm_id=? AND member_role IN ('owner','partner') AND lawyer_id<>?");
                    $notify->execute([is_rtl()?'مسودة بانتظار اعتماد شريك':'Draft awaiting partner approval',is_rtl()?'اجتازت المسودة مراجعة المحامي وتحتاج مراجعة شريك أو مالك المكتب.':'The draft passed lawyer review and needs partner or owner approval.','/lawyer/review-queue.php',(int)$membership['firm_id'],$lawyerId]);
                } else {
                    $notify = $pdo->prepare('INSERT INTO notifications (recipient_type,recipient_id,title,message,link) VALUES (?,?,?,?,?)');
                    $noticeTitle = $decision==='approved' ? (is_rtl()?'تم اعتماد المسودة من المكتب':'Draft approved by the firm') : (is_rtl()?'ملاحظات على المسودة':'Draft review feedback');
                    $noticeText = $decision==='approved' ? (is_rtl()?'أصبحت المسودة معتمدة ويمكن تنزيلها.':'The draft is approved and available for download.') : (is_rtl()?'يرجى مراجعة قرار وملاحظات المراجع في قائمة المسودات.':'Review the decision and notes in your draft list.');
                    $notify->execute(['lawyer',(int)$draft['lawyer_id'],$noticeTitle,$noticeText,'/lawyer/drafting.php#draft-'.$draftId]);
                }
                $pdo->commit();
                header('Location: /lawyer/review-queue.php?updated=1'); exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Draft review workflow failed: '.$e->getMessage());
            $message = is_rtl()?'تعذر حفظ المراجعة. حدّث الصفحة وحاول مرة أخرى.':'Could not save the review. Refresh and try again.';
        }
    }
}

$stageFilter = in_array($membership['member_role'],['owner','partner'],true)
    ? "AND d.approval_stage IN ('lawyer_review','partner_review')"
    : ($membership['member_role']==='lawyer' ? "AND d.approval_stage='lawyer_review'" : 'AND 1=0');
$queueQuery = $pdo->prepare("SELECT d.id,d.title,d.doc_type,d.generated_content,d.approval_stage,d.approval_version,d.created_at,l.full_name AS author_name,c.title AS case_title FROM draft_documents d JOIN lawyers l ON l.id=d.lawyer_id LEFT JOIN cases c ON c.id=d.case_id WHERE d.approval_firm_id=? AND d.lawyer_id<>? AND d.status='generated' {$stageFilter} ORDER BY FIELD(d.approval_stage,'lawyer_review','partner_review'),d.created_at ASC LIMIT 100");
$queueQuery->execute([(int)$membership['firm_id'],$lawyerId]); $queue=$queueQuery->fetchAll();
$historyQuery=$pdo->prepare('SELECT r.*,d.title,l.full_name AS reviewer_name,author.full_name AS author_name FROM draft_workflow_reviews r JOIN draft_documents d ON d.id=r.draft_document_id JOIN lawyers l ON l.id=r.reviewer_lawyer_id JOIN lawyers author ON author.id=d.lawyer_id WHERE d.approval_firm_id=? ORDER BY r.created_at DESC LIMIT 30');
$historyQuery->execute([(int)$membership['firm_id']]); $history=$historyQuery->fetchAll();
$activeLawyer='reviews';
require_once __DIR__.'/includes/lawyer_header.php';
$stageLabels=['lawyer_review'=>is_rtl()?'مراجعة محامٍ':'Lawyer review','partner_review'=>is_rtl()?'مراجعة شريك':'Partner review'];
$decisionLabels=['approved'=>is_rtl()?'اعتماد المرحلة':'Approve stage','changes_requested'=>is_rtl()?'طلب تعديلات':'Request changes','rejected'=>is_rtl()?'رفض المسودة':'Reject draft'];
?>
<div class="app-content">
  <div class="toolbar"><h2>✅ <?= is_rtl()?'قائمة مراجعة المسودات':'Draft review queue' ?></h2></div>
  <p style="color:var(--text-muted)"><?= is_rtl()?'تنتقل المسودة من مراجعة محامٍ إلى مراجعة شريك/مالك. طلب التعديل يعيدها للمحامي المنشئ، ولا يُتاح تنزيلها بعد بدء المسار إلا بعد اعتماد المكتب.':'Drafts move from lawyer review to partner/owner review. A revision request returns the draft to its author. Downloads are held after the workflow starts until firm approval.' ?></p>
  <?php if ($message): ?><div class="form-alert error"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if (isset($_GET['updated'])): ?><div class="form-alert success"><?= is_rtl()?'تم حفظ قرار المراجعة.':'Review decision saved.' ?></div><?php endif; ?>
  <?php foreach($queue as $item): ?><div class="dash-card">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><h3><?= htmlspecialchars($item['title']) ?></h3><p><?= htmlspecialchars($item['author_name']) ?> · <?= htmlspecialchars($item['case_title']??'—') ?> · v<?= (int)$item['approval_version'] ?></p></div><span class="status-pill status-pending"><?= $stageLabels[$item['approval_stage']] ?></span></div>
    <details><summary><?= is_rtl()?'عرض المسودة':'View draft' ?></summary><pre style="white-space:pre-wrap;font-family:inherit;line-height:1.8"><?= htmlspecialchars($item['generated_content']) ?></pre></details>
    <?php $versionsQuery=$pdo->prepare('SELECT version_number,content,created_at FROM draft_document_versions WHERE draft_document_id=? AND version_number<? ORDER BY version_number DESC');$versionsQuery->execute([(int)$item['id'],(int)$item['approval_version']]);$draftVersions=$versionsQuery->fetchAll();if($draftVersions): ?><details style="margin-top:8px"><summary><?= is_rtl()?'مقارنة الإصدارات السابقة':'Previous versions' ?></summary><?php foreach($draftVersions as $previousVersion): ?><details style="margin:8px"><summary>v<?= (int)$previousVersion['version_number'] ?> · <?= date('Y-m-d H:i',strtotime($previousVersion['created_at'])) ?></summary><pre style="white-space:pre-wrap;font-family:inherit;line-height:1.8"><?= htmlspecialchars($previousVersion['content']) ?></pre></details><?php endforeach; ?></details><?php endif; ?>
    <form method="post" class="grid-2" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="draft_id" value="<?= (int)$item['id'] ?>"><div class="field"><label><?= is_rtl()?'ملاحظات المراجع':'Reviewer notes' ?></label><textarea name="notes" rows="2" maxlength="10000"></textarea></div><div class="field"><label><?= is_rtl()?'القرار':'Decision' ?></label><select class="pro-select" name="decision"><?php foreach($decisionLabels as $decision=>$label): ?><option value="<?= $decision ?>"><?= $label ?></option><?php endforeach; ?></select></div><div><button class="btn btn-primary btn-sm" type="submit"><?= is_rtl()?'حفظ القرار':'Save decision' ?></button></div></form>
  </div><?php endforeach; ?>
  <?php if(!$queue): ?><div class="dash-card" style="color:var(--text-muted)"><?= is_rtl()?'لا توجد مسودات بانتظار مراجعتك حالياً.':'No drafts are waiting for your review.' ?></div><?php endif; ?>
  <div class="dash-card"><h3><?= is_rtl()?'سجل المراجعات':'Review history' ?></h3><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= is_rtl()?'التاريخ':'Date' ?></th><th><?= is_rtl()?'المسودة':'Draft' ?></th><th><?= is_rtl()?'المرحلة':'Stage' ?></th><th><?= is_rtl()?'المراجع':'Reviewer' ?></th><th><?= is_rtl()?'القرار':'Decision' ?></th><th><?= is_rtl()?'الملاحظات':'Notes' ?></th></tr></thead><tbody>
  <?php foreach($history as $row): ?><tr><td><?= date('Y-m-d H:i',strtotime($row['created_at'])) ?></td><td><?= htmlspecialchars($row['title']) ?> · v<?= (int)$row['version_number'] ?></td><td><?= $stageLabels[$row['stage']]??htmlspecialchars($row['stage']) ?></td><td><?= htmlspecialchars($row['reviewer_name']) ?></td><td><?= $decisionLabels[$row['decision']]??htmlspecialchars($row['decision']) ?></td><td><?= nl2br(htmlspecialchars($row['notes']??'')) ?></td></tr><?php endforeach; ?>
  <?php if(!$history): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted)"><?= is_rtl()?'لا يوجد سجل مراجعات بعد.':'No review history yet.' ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php require_once __DIR__.'/includes/lawyer_footer.php'; ?>
