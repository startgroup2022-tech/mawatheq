<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
$lawyerId = $_SESSION['lawyer_id'];
$firmId = tenant_write_firm_id($pdo);
$error = null; $generated = null;
$firmMembership = tenant_is_firm($pdo) ? ['firm_id' => $firmId, 'member_role' => tenant_role($pdo)] : null;
$draftScope = tenant_owner_where($pdo, 'd');
$caseScope = tenant_owner_where($pdo, 'c');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_client_review') {
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $q = $pdo->prepare('SELECT d.id,d.case_id,c.user_id,d.title FROM draft_documents d JOIN cases c ON c.id=d.case_id WHERE d.id=? AND ' . $draftScope['sql'] . ' AND d.status=\'generated\'');
    $q->execute(array_merge([$draftId], $draftScope['params']));
    $draft = $q->fetch();
    $internalReady = true;
    if ($draft) {
        $stageQuery = $pdo->prepare('SELECT approval_stage,internal_review_started_at FROM draft_documents WHERE id=?');
        $stageQuery->execute([$draftId]); $stageState = $stageQuery->fetch();
        $internalReady = $stageState && (empty($stageState['internal_review_started_at']) || $stageState['approval_stage'] === 'approved');
    }
    if ($draft && $internalReady) {
        $pdo->prepare("UPDATE draft_documents SET approval_status='submitted',client_visible=1 WHERE id=?")
            ->execute([$draftId]);
        $pdo->prepare('INSERT INTO notifications (recipient_type,recipient_id,title,message,link) VALUES (?,?,?,?,?)')
            ->execute(['client',(int)$draft['user_id'],is_rtl()?'مسودة قانونية للمراجعة':'A legal draft is ready for review',$draft['title'],'/draft-reviews.php']);
        header('Location: /lawyer/drafting.php#draft-' . $draftId); exit;
    }
    $error = $draft && !$internalReady
        ? (is_rtl() ? 'يجب اعتماد المسودة من المكتب قبل إرسالها للعميل.' : 'The firm must approve this draft before client review.')
        : (is_rtl() ? 'لا يمكن إرسال هذه المسودة. اربطها بقضية أولاً.' : 'This draft cannot be shared. Link it to a case first.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_revision') {
    $draftId = (int)($_POST['draft_id'] ?? 0);
    $content = trim($_POST['generated_content'] ?? '');
    try {
        if ($content === '') throw new RuntimeException('Draft content cannot be empty.');
        $pdo->beginTransaction();
        $current = $pdo->prepare("SELECT approval_version FROM draft_documents WHERE id=? AND " . $draftScope['sql'] . " AND approval_status='changes_requested' AND status='generated' FOR UPDATE");
        $current->execute(array_merge([$draftId], $draftScope['params'])); $versionRow=$current->fetch();
        if (!$versionRow) throw new RuntimeException('Draft is not available for revision.');
        $newVersion=(int)$versionRow['approval_version']+1;
        $q = $pdo->prepare("UPDATE draft_documents SET generated_content=?,approval_status='draft',approval_stage='draft',approval_version=?,client_visible=0 WHERE id=?");
        $q->execute([$content,$newVersion,$draftId]);
        $versionInsert=$pdo->prepare('INSERT INTO draft_document_versions (draft_document_id,version_number,content,saved_by_lawyer_id) VALUES (?,?,?,?)');
        $versionInsert->execute([$draftId,$newVersion,$content,$lawyerId]);
        $pdo->commit();
        header('Location: /lawyer/drafting.php#draft-' . $draftId); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Draft revision save failed: '.$e->getMessage());
        $error = is_rtl() ? 'تعذر حفظ التعديلات على هذه المسودة.' : 'Could not save changes to this draft.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_internal_review') {
    if (!$firmMembership) {
        $error = is_rtl() ? 'مسار المراجعة الداخلية متاح لمسودات أعضاء المكاتب فقط.' : 'Internal review is available for firm members only.';
    } else {
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $reviewerCount=$pdo->prepare("SELECT COUNT(*) FROM law_firm_members WHERE firm_id=? AND lawyer_id<>? AND member_role IN ('owner','partner','lawyer')");
        $reviewerCount->execute([(int)$firmMembership['firm_id'],(int)$lawyerId]);
        $hasReviewers=(int)$reviewerCount->fetchColumn()>0;
        if (!$hasReviewers) {
            $error = is_rtl() ? 'أضف محامياً أو شريكاً آخر للمكتب قبل بدء المراجعة.' : 'Add another lawyer or partner to the firm before starting review.';
        } else {
            $q = $pdo->prepare("UPDATE draft_documents SET approval_stage='lawyer_review',approval_firm_id=?,internal_review_started_at=COALESCE(internal_review_started_at,NOW()),client_visible=0 WHERE id=? AND " . $draftScope['sql'] . " AND status='generated' AND approval_stage='draft' AND approval_status='draft'");
            $q->execute(array_merge([(int)$firmMembership['firm_id'],$draftId], $draftScope['params']));
        }
        if (isset($q) && $q->rowCount()) {
            $notify = $pdo->prepare("INSERT INTO notifications (recipient_type,recipient_id,title,message,link) SELECT 'lawyer',lawyer_id,?,?,? FROM law_firm_members WHERE firm_id=? AND lawyer_id<>? AND member_role IN ('owner','partner','lawyer')");
            $notify->execute([is_rtl()?'مسودة بانتظار مراجعة المكتب':'Draft awaiting firm review',is_rtl()?'توجد مسودة جديدة في قائمة المراجعة الداخلية.':'A draft is waiting in the internal review queue.','/lawyer/review-queue.php',(int)$firmMembership['firm_id'],(int)$lawyerId]);
            header('Location: /lawyer/drafting.php#draft-' . $draftId); exit;
        }
        if ($hasReviewers) $error = is_rtl() ? 'تعذر إرسال المسودة للمراجعة. تحقق من حالتها.' : 'Could not submit this draft for internal review.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_draft') {
    tenant_require($pdo, 'drafting.edit');
    $delScope = tenant_owner_where($pdo, 'draft_documents');
    $pdo->prepare('DELETE FROM draft_documents WHERE id=? AND ' . $delScope['sql'])->execute(array_merge([(int)($_POST['draft_id'] ?? 0)], $delScope['params']));
    header('Location: /lawyer/drafting.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    tenant_require($pdo, 'drafting.create');
    $docType = is_string($_POST['doc_type'] ?? null) ? trim($_POST['doc_type']) : '';
    $partyA = is_string($_POST['party_a'] ?? null) ? trim($_POST['party_a']) : '';
    $partyB = is_string($_POST['party_b'] ?? null) ? trim($_POST['party_b']) : '';
    $details = is_string($_POST['details'] ?? null) ? trim($_POST['details']) : '';
    $language = ($_POST['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
    $caseInput = is_string($_POST['case_id'] ?? null) ? trim($_POST['case_id']) : '';
    $parsedCaseId = $caseInput !== '' ? filter_var($caseInput,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : null;
    $caseId = $parsedCaseId ? (int)$parsedCaseId : null;
    $invalidCaseSelection = $caseInput !== '' && !$parsedCaseId;
    $includeCaseContext = ($_POST['include_case_context'] ?? '') === '1';
    $details = mb_substr($details, 0, 6000, 'UTF-8');

    $settings = ai_load_settings($pdo);
    $aiEnabled = !empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0';

    if ($caseId) {
        $ownedCase = $pdo->prepare('SELECT c.id,c.title,c.case_type,c.description,c.status FROM cases c WHERE c.id=? AND ' . $caseScope['sql']);
        $ownedCase->execute(array_merge([$caseId], $caseScope['params']));
        $ownedCaseData = $ownedCase->fetch();
    } else {
        $ownedCaseData = null;
    }

    $allowedDocTypes = ['خطاب مطالبة قانونية','مذكرة قانونية','اتفاقية عمل','اتفاقية سرية (NDA)','إشعار إنهاء عقد','إنذار قانوني','صحيفة دعوى','مذكرة دفاع','رد قانوني','عقد عام','ملخص قضية','رأي قانوني','Legal demand letter','Legal memo','Employment agreement','Non-disclosure agreement (NDA)','Contract termination notice','Legal notice','Statement of claim','Defense memorandum','Legal response','General contract','Case summary','Legal opinion'];
    if ($invalidCaseSelection || ($caseId && !$ownedCaseData)) {
        $error = is_rtl() ? 'القضية المحددة غير موجودة أو ليست ضمن قضاياك.' : 'The selected case was not found or is not yours.';
    } elseif (($_POST['ai_consent'] ?? '') !== '1') {
        $error = is_rtl() ? 'وافق على إرسال التفاصيل إلى مزود الذكاء الاصطناعي لتوليد المسودة.' : 'Consent to send the details to the AI provider to generate the draft.';
    } elseif (!in_array($docType, $allowedDocTypes, true) || !$details) {
        $error = is_rtl() ? 'يرجى اختيار نوع المستند وتعبئة التفاصيل.' : 'Please choose a document type and fill in the details.';
    } elseif (mb_strlen(is_string($_POST['details'] ?? null) ? trim($_POST['details']) : '', 'UTF-8') > 6000 || mb_strlen($partyA, 'UTF-8') > 255 || mb_strlen($partyB, 'UTF-8') > 255) {
        $error = is_rtl() ? 'تجاوزت التفاصيل أو أسماء الأطراف الحد المسموح.' : 'The details or party names exceed the allowed length.';
    } elseif (!$aiEnabled) {
        $error = is_rtl() ? 'هذه الميزة تحتاج تفعيل مزوّد ذكاء اصطناعي من لوحة تحكم الإدارة ← الإعدادات أولاً.' : 'This feature needs an AI provider activated from admin Settings first.';
    } else {
        $draftDetails = $details;
        if ($includeCaseContext && $ownedCaseData) {
            $caseContext = ['case'=>['title'=>$ownedCaseData['title'],'type'=>$ownedCaseData['case_type'],'description'=>$ownedCaseData['description'],'status'=>$ownedCaseData['status']]];
            $caseQueries = [
                'parties'=>['SELECT party_type,name,role_description FROM case_parties WHERE case_id=? ORDER BY id LIMIT 30',[$caseId]],
                'claims_and_defenses'=>['SELECT claim_type,description FROM case_claims WHERE case_id=? ORDER BY id LIMIT 50',[$caseId]],
                'facts'=>['SELECT description,occurred_at,source_note FROM case_facts WHERE case_id=? ORDER BY id LIMIT 50',[$caseId]],
                'timeline'=>['SELECT event,event_date FROM case_timeline WHERE case_id=? ORDER BY event_date LIMIT 50',[$caseId]],
                'tasks'=>['SELECT title,due_date,status FROM case_tasks WHERE case_id=? ORDER BY id LIMIT 50',[$caseId]],
                'evidence_register'=>["SELECT e.title,e.evidence_type,e.description,e.ai_summary,cf.description AS linked_fact,la.law_name_ar AS linked_law,la.article_number FROM case_evidence e LEFT JOIN case_facts cf ON cf.id=e.related_fact_id LEFT JOIN law_articles la ON la.id=e.related_law_article_id AND la.verification_status='verified' WHERE e.case_id=? ORDER BY e.id LIMIT 50",[$caseId]],
                'risk_register'=>['SELECT title,likelihood,impact,mitigation,status FROM case_risks WHERE case_id=? ORDER BY id LIMIT 30',[$caseId]],
            ];
            foreach ($caseQueries as $key=>$definition) {
                $query=$pdo->prepare($definition[0]); $query->execute($definition[1]); $caseContext[$key]=$query->fetchAll();
            }
            foreach ($caseContext['case'] as $field=>$value) if (is_string($value)) $caseContext['case'][$field]=mb_substr($value,0,500,'UTF-8');
            foreach ($caseQueries as $key=>$definition) {
                $caseContext[$key]=array_slice($caseContext[$key],0,5);
                foreach ($caseContext[$key] as &$record) foreach ($record as $field=>$value) {
                    if (is_string($value)) $record[$field]=mb_substr($value,0,250,'UTF-8');
                }
                unset($record);
            }
            $contextJson=json_encode($caseContext,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            while (strlen((string)$contextJson)>20000) {
                $largestKey=null; $largestCount=0;
                foreach ($caseQueries as $key=>$definition) if (count($caseContext[$key])>$largestCount) { $largestKey=$key; $largestCount=count($caseContext[$key]); }
                if ($largestKey===null || $largestCount===0) break;
                array_pop($caseContext[$largestKey]);
                $contextJson=json_encode($caseContext,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
            $draftDetails .= "\n\n" . ($language==='ar'?'سجل القضية المختار (بيانات مرجعية فقط):':'Selected case record (reference data only):') . "\n" . (string)$contextJson;
        }
        require_once __DIR__ . '/../includes/ai_gateway.php';
        $aiTenant = ai_gateway_tenant_context($pdo, 'lawyer', (int)$lawyerId);
        $result = generate_legal_draft($settings['ai_provider'], $settings['ai_api_key'], $settings['ai_model'] ?? '', $docType, $partyA, $partyB, $draftDetails, $language === 'ar', [
            'pdo' => $pdo, 'settings' => $settings, 'tenant' => $aiTenant,
            'actor_id' => (int)$lawyerId, 'actor_type' => 'lawyer',
        ]);
        $stmt = $pdo->prepare('INSERT INTO draft_documents (lawyer_id, firm_id, case_id, doc_type, title, party_a, party_b, details, generated_content, language, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $title = $docType . ($partyA ? ' — ' . $partyA : '') . ($partyB ? ' / ' . $partyB : '');
        $stmt->execute([$lawyerId, $firmId, $caseId, $docType, $title, $partyA, $partyB, $draftDetails, $result['content'], $language, $result['ok'] ? 'generated' : 'failed']);
        if (!$result['ok']) { $error = $result['error']; } else {
            $generated = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO draft_document_versions (draft_document_id,version_number,content,saved_by_lawyer_id) VALUES (?,1,?,?)')->execute([$generated,$result['content'],$lawyerId]);
        }
    }
}

$activeLawyer = 'drafting';
require_once __DIR__ . '/includes/lawyer_header.php';

$docTypes = is_rtl()
    ? ['خطاب مطالبة قانونية', 'مذكرة قانونية', 'صحيفة دعوى', 'مذكرة دفاع', 'رد قانوني', 'اتفاقية عمل', 'اتفاقية سرية (NDA)', 'عقد عام', 'إشعار إنهاء عقد', 'إنذار قانوني', 'ملخص قضية', 'رأي قانوني']
    : ['Legal demand letter', 'Legal memo', 'Statement of claim', 'Defense memorandum', 'Legal response', 'Employment agreement', 'Non-disclosure agreement (NDA)', 'General contract', 'Contract termination notice', 'Legal notice', 'Case summary', 'Legal opinion'];

$cases = $pdo->prepare('SELECT c.id, c.title FROM cases c WHERE ' . $caseScope['sql'] . ' ORDER BY c.created_at DESC');
$cases->execute($caseScope['params']); $cases = $cases->fetchAll();

$drafts = $pdo->prepare('SELECT d.* FROM draft_documents d WHERE ' . $draftScope['sql'] . ' ORDER BY d.created_at DESC LIMIT 20');
$drafts->execute($draftScope['params']); $drafts = $drafts->fetchAll();
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= is_rtl() ? 'الصياغة القانونية بالذكاء الاصطناعي' : 'AI Legal Drafting' ?></h2>
    <button type="button" class="btn btn-primary" onclick="openModal('draftModal')">+ <?= is_rtl() ? 'مسودة جديدة' : 'New draft' ?></button>
  </div>
  <div class="form-alert" style="background:#FFF8E6;color:#8A6D1D">
    ⚠️ <?= is_rtl() ? 'كل مسودة يولّدها الذكاء الاصطناعي هنا هي نقطة بداية فقط، وتحتاج مراجعتك وتدقيقك القانوني الكامل قبل استخدامها فعلياً أو إرسالها لأي طرف.' : 'Every AI-generated draft here is only a starting point and requires your full legal review before actual use or sending.' ?>
  </div>

  <?php if ($generated): ?><div class="form-alert success"><?= is_rtl() ? 'تم توليد المسودة بنجاح — تجدها بالأسفل.' : 'Draft generated successfully — see it below.' ?></div><?php endif; ?>

  <div class="modal-backdrop<?= $error ? ' is-open' : '' ?>" id="draftModal">
    <form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      
      <button type="button" class="modal-close" onclick="closeModal('draftModal')">✕</button>
      <input type="hidden" name="action" value="generate">
      <h3 style="margin-bottom:14px"><?= is_rtl() ? 'مسودة مستند جديدة' : 'New document draft' ?></h3>
      <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <div class="grid-2">
        <div class="field">
          <label><?= is_rtl() ? 'نوع المستند' : 'Document type' ?></label>
          <div class="select-wrap"><select name="doc_type" class="pro-select" required>
            <option value="" disabled selected><?= is_rtl() ? 'اختر...' : 'Choose...' ?></option>
            <?php foreach ($docTypes as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
          </select></div>
        </div>
        <div class="field">
          <label><?= is_rtl() ? 'اللغة' : 'Language' ?></label>
          <div class="select-wrap"><select name="language" class="pro-select">
            <option value="ar" <?= is_rtl() ? 'selected' : '' ?>>العربية</option>
            <option value="en" <?= !is_rtl() ? 'selected' : '' ?>>English</option>
          </select></div>
        </div>
        <div class="field"><label><?= is_rtl() ? 'الطرف الأول' : 'Party A' ?></label><input type="text" name="party_a"></div>
        <div class="field"><label><?= is_rtl() ? 'الطرف الثاني' : 'Party B' ?></label><input type="text" name="party_b"></div>
        <div class="field" style="grid-column:1/-1">
          <label><?= is_rtl() ? 'ربط بقضية (اختياري)' : 'Link to a case (optional)' ?></label>
          <div class="select-wrap"><select name="case_id" class="pro-select">
            <option value=""><?= is_rtl() ? 'بدون ربط' : 'Not linked' ?></option>
            <?php foreach ($cases as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option><?php endforeach; ?>
          </select></div>
        </div>
      </div>
      <div class="field"><label><?= is_rtl() ? 'تفاصيل الحالة (كل ما تريد تضمينه بالمسودة)' : 'Case details (everything to include in the draft)' ?></label><textarea name="details" rows="5" maxlength="6000" required></textarea></div>
      <div class="field"><label><input type="checkbox" name="include_case_context" value="1"> <?= is_rtl() ? 'إدراج سجل القضية المرتبطة (الأطراف والوقائع والادعاءات والأدلة والمهام) في سياق المسودة' : 'Include the linked case record (parties, facts, claims, evidence and tasks) in the draft context' ?></label><small><?= is_rtl()?'لا تُرسل ملفات الأدلة نفسها؛ تُستخدم البيانات الوصفية والملخصات المسجلة فقط.':'Evidence files themselves are not sent; only recorded metadata and summaries are included.' ?></small></div>
      <div class="field"><label><input type="checkbox" name="ai_consent" value="1" required> <?= is_rtl() ? 'أوافق على إرسال التفاصيل وسجل القضية إذا اخترت تضمينه إلى مزود AI المهيأ.' : 'I consent to sending these details and the case record, if selected, to the configured AI provider.' ?></label></div>
      <button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? '✨ توليد المسودة' : '✨ Generate draft' ?></button>
    </form>
  </div>

  <?php foreach ($drafts as $d): ?>
    <div class="dash-card" id="draft-<?= $d['id'] ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
        <div>
          <strong><?= htmlspecialchars($d['title']) ?></strong>
          <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px"><?= date('Y-m-d H:i', strtotime($d['created_at'])) ?></div>
        </div>
        <span class="status-pill status-<?= $d['status'] === 'generated' ? 'confirmed' : 'cancelled' ?>"><?= $d['status'] === 'generated' ? (is_rtl()?'تم التوليد':'Generated') : (is_rtl()?'فشل':'Failed') ?></span>
        <?php if ($d['status'] === 'generated'): ?><span class="status-pill status-pending"><?= ['draft'=>is_rtl()?'مسودة':'Draft','submitted'=>is_rtl()?'بانتظار مراجعة العميل':'Awaiting client review','approved'=>is_rtl()?'اعتمدها العميل':'Client approved','changes_requested'=>is_rtl()?'طلب العميل تعديلات':'Changes requested'][$d['approval_status']] ?? '' ?></span><?php endif; ?>
        <?php if (!empty($d['internal_review_started_at'])): ?><span class="status-pill status-pending"><?= ['draft'=>is_rtl()?'بانتظار إعادة الإرسال':'Ready to resubmit','lawyer_review'=>is_rtl()?'مراجعة محامٍ':'Lawyer review','partner_review'=>is_rtl()?'مراجعة شريك':'Partner review','approved'=>is_rtl()?'اعتماد المكتب':'Firm approved','rejected'=>is_rtl()?'مرفوضة — يلزم تعديل':'Rejected — revise','changes_requested'=>is_rtl()?'تعديلات مطلوبة':'Changes requested'][$d['approval_stage']] ?? '' ?> · v<?= (int)$d['approval_version'] ?></span><?php endif; ?>
      </div>
      <?php if ($d['generated_content']): ?>
        <?php $reviewStmt=$pdo->prepare('SELECT decision,notes,created_at FROM draft_reviews WHERE draft_document_id=? ORDER BY created_at DESC');$reviewStmt->execute([(int)$d['id']]);$draftReviews=$reviewStmt->fetchAll();foreach($draftReviews as $draftReview): ?><?php if ($draftReview['notes']): ?><div class="rec-item" style="margin-top:8px"><small><?= date('Y-m-d H:i',strtotime($draftReview['created_at'])) ?> · <?= htmlspecialchars($draftReview['decision']) ?></small><div><?= nl2br(htmlspecialchars($draftReview['notes'])) ?></div></div><?php endif; ?><?php endforeach; ?>
        <?php $workflowStmt=$pdo->prepare('SELECT r.stage,r.decision,r.notes,r.version_number,r.created_at,l.full_name AS reviewer_name FROM draft_workflow_reviews r JOIN lawyers l ON l.id=r.reviewer_lawyer_id WHERE r.draft_document_id=? ORDER BY r.created_at DESC');$workflowStmt->execute([(int)$d['id']]);foreach($workflowStmt->fetchAll() as $workflowReview): ?><div class="rec-item" style="margin-top:8px"><small><?= date('Y-m-d H:i',strtotime($workflowReview['created_at'])) ?> · <?= htmlspecialchars($workflowReview['reviewer_name']) ?> · <?= htmlspecialchars($workflowReview['stage']) ?> · v<?= (int)$workflowReview['version_number'] ?> · <?= htmlspecialchars($workflowReview['decision']) ?></small><?php if($workflowReview['notes']): ?><div><?= nl2br(htmlspecialchars($workflowReview['notes'])) ?></div><?php endif; ?></div><?php endforeach; ?>
        <?php $versionStmt=$pdo->prepare('SELECT v.version_number,v.content,v.created_at,l.full_name AS saved_by FROM draft_document_versions v JOIN lawyers l ON l.id=v.saved_by_lawyer_id WHERE v.draft_document_id=? ORDER BY v.version_number DESC');$versionStmt->execute([(int)$d['id']]);$draftVersions=$versionStmt->fetchAll();if(count($draftVersions)>1): ?><details class="rec-item" style="margin-top:10px"><summary><?= is_rtl()?'سجل الإصدارات':'Version history' ?></summary><?php foreach($draftVersions as $draftVersion): ?><details style="margin:8px"><summary>v<?= (int)$draftVersion['version_number'] ?> · <?= date('Y-m-d H:i',strtotime($draftVersion['created_at'])) ?> · <?= htmlspecialchars($draftVersion['saved_by']) ?></summary><pre style="white-space:pre-wrap;font-family:inherit;line-height:1.8"><?= htmlspecialchars($draftVersion['content']) ?></pre></details><?php endforeach; ?></details><?php endif; ?>
        <?php if ($d['approval_status'] === 'changes_requested'): ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="save_revision"><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>"><textarea name="generated_content" id="draft-ta-<?= (int)$d['id'] ?>" rows="12" style="width:100%;font-family:inherit;font-size:.85rem;line-height:1.9;padding:14px;border:1px solid var(--border);border-radius:10px"><?= htmlspecialchars($d['generated_content']) ?></textarea><button class="btn btn-primary btn-sm" type="submit"><?= is_rtl()?'حفظ التعديلات':'Save revision' ?></button></form>
        <?php else: ?><textarea readonly rows="10" id="draft-ta-<?= $d['id'] ?>" style="width:100%;margin-top:14px;font-family:inherit;font-size:.85rem;line-height:1.9;padding:14px;border:1px solid var(--border);border-radius:10px;background:#FAFBFC"><?= htmlspecialchars($d['generated_content']) ?></textarea><?php endif; ?>
        <div style="display:flex;gap:8px;margin-top:10px">
          <?php if ($firmMembership && $d['status']==='generated' && $d['approval_stage']==='draft' && $d['approval_status']==='draft'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="submit_internal_review"><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl()?'إرسال للمراجعة الداخلية':'Submit for firm review' ?></button></form><?php endif; ?>
          <?php if ($d['case_id'] && in_array($d['approval_status'], ['draft','changes_requested'], true) && (empty($d['internal_review_started_at']) || $d['approval_stage']==='approved')): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="submit_client_review"><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl()?'إرسال للعميل للمراجعة':'Send to client for review' ?></button></form><?php endif; ?>
          <?php if (empty($d['internal_review_started_at']) || $d['approval_stage']==='approved'): ?><button type="button" class="btn btn-outline btn-sm" onclick="copyDraft(<?= $d['id'] ?>, this)">📋 <?= is_rtl() ? 'نسخ' : 'Copy' ?></button><a class="btn btn-outline btn-sm" href="/lawyer/download-draft.php?id=<?= $d['id'] ?>">⬇️ <?= is_rtl() ? 'تحميل (txt)' : 'Download (.txt)' ?></a><a class="btn btn-outline btn-sm" href="/lawyer/download-draft-docx.php?id=<?= $d['id'] ?>">📄 <?= is_rtl() ? 'تحميل (Word)' : 'Download (.docx)' ?></a><?php else: ?><span class="field-hint"><?= is_rtl()?'التصدير متاح بعد اعتماد المكتب.':'Export is available after firm approval.' ?></span><?php endif; ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= is_rtl() ? 'حذف هذه المسودة؟' : 'Delete this draft?' ?>')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_draft"><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger)" type="submit">🗑️ <?= is_rtl() ? 'حذف' : 'Delete' ?></button></form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (empty($drafts)): ?><div class="dash-card" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد مسودات بعد.' : 'No drafts yet.' ?></div><?php endif; ?>
</div>
<script>
function copyDraft(id, btn){
  var ta = document.getElementById('draft-ta-' + id);
  ta.select();
  ta.setSelectionRange(0, 999999);
  navigator.clipboard.writeText(ta.value).then(function(){
    var original = btn.textContent;
    btn.textContent = '<?= is_rtl() ? "✓ تم النسخ" : "✓ Copied" ?>';
    setTimeout(function(){ btn.textContent = original; }, 1500);
  });
}
</script>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
