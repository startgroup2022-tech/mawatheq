<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/audit_log.php';
$lawyerId = $_SESSION['lawyer_id'];
$firmId = tenant_write_firm_id($pdo);
$caseId = (int)($_GET['id'] ?? 0);
$caseActionMessage = null;

$caseScope = tenant_owner_where($pdo, 'c');
$stmt = $pdo->prepare('SELECT c.*, u.full_name AS client_name, u.email AS client_email, l.hourly_rate AS lawyer_hourly_rate FROM cases c JOIN users u ON u.id=c.user_id JOIN lawyers l ON l.id=c.lawyer_id WHERE c.id=? AND ' . $caseScope['sql']);
$stmt->execute(array_merge([$caseId], $caseScope['params']));
$case = $stmt->fetch();
if (!$case) {
    $activeLawyer = 'cases';
    require_once __DIR__ . '/includes/lawyer_header.php';
    echo '<div class="app-content"><p>' . (is_rtl() ? 'القضية غير موجودة.' : 'Case not found.') . '</p></div>';
    require __DIR__.'/includes/lawyer_footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'update_case') {
        tenant_require($pdo, 'cases.edit');
        $allowedStatuses = ['open','in_review','closed_won','closed_lost','closed_settled'];
        $probability = trim((string)($_POST['win_probability'] ?? ''));
        if (!in_array($_POST['status'] ?? '', $allowedStatuses, true) || ($probability !== '' && (!ctype_digit($probability) || (int)$probability < 0 || (int)$probability > 100))) {
            $caseActionMessage = is_rtl() ? 'قيمة الحالة أو احتمالية الفوز غير صحيحة.' : 'Invalid status or win probability.';
        } else {
        $pdo->prepare('UPDATE cases SET status=?, win_probability=? WHERE id=?')
            ->execute([$_POST['status'], $probability !== '' ? (int)$probability : null, $caseId]);
        if ($_POST['status'] !== $case['status']) {
            log_action($pdo, 'تحديث حالة القضية إلى ' . $_POST['status'], 'case', $caseId);
            notify($pdo, 'client', (int)$case['user_id'], is_rtl() ? 'تحديث حالة القضية' : 'Case status updated', $case['title'] . ' → ' . $_POST['status'], '/consultations.php');
        }
        header('Location: /lawyer/case-detail.php?id=' . $caseId); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'add_event') {
        $eventText = trim($_POST['event'] ?? ''); $eventDate = $_POST['event_date'] ?? '';
        $dateObject = DateTime::createFromFormat('!Y-m-d', $eventDate);
        if ($eventText === '' || !$dateObject || $dateObject->format('Y-m-d') !== $eventDate) {
            $caseActionMessage = is_rtl() ? 'أدخل وصف الحدث وتاريخاً صحيحاً.' : 'Enter an event and a valid date.';
        } else {
        $pdo->prepare('INSERT INTO case_timeline (case_id, event, event_date) VALUES (?,?,?)')
            ->execute([$caseId, $eventText, $eventDate]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'add_party') {
        $partyType = $_POST['party_type'] ?? 'other';
        $partyName = trim($_POST['name'] ?? '');
        if (!in_array($partyType,['plaintiff','defendant','witness','third_party','other'],true) || $partyName === '') {
            $caseActionMessage = is_rtl() ? 'بيانات الطرف غير صحيحة.' : 'Invalid party details.';
        } else {
        $pdo->prepare('INSERT INTO case_parties (case_id, party_type, name, role_description, contact_info) VALUES (?,?,?,?,?)')
            ->execute([$caseId, $partyType, $partyName, trim($_POST['role_description'] ?? ''), trim($_POST['contact_info'] ?? '')]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#parties'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'delete_party') {
        $pdo->prepare('DELETE FROM case_parties WHERE id=? AND case_id=?')->execute([(int)$_POST['party_id'], $caseId]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#parties'); exit;
    }
    if (($_POST['action'] ?? '') === 'add_claim') {
        $claimType = $_POST['claim_type'] ?? '';
        $claimDescription = trim($_POST['description'] ?? '');
        if (!in_array($claimType,['claim','defense'],true) || $claimDescription === '') {
            $caseActionMessage = is_rtl() ? 'بيانات الادعاء أو الدفع غير صحيحة.' : 'Invalid claim or defense details.';
        } else {
        $pdo->prepare('INSERT INTO case_claims (case_id, claim_type, description) VALUES (?,?,?)')
            ->execute([$caseId, $claimType, $claimDescription]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#claims'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'delete_claim') {
        $pdo->prepare('DELETE FROM case_claims WHERE id=? AND case_id=?')->execute([(int)$_POST['claim_id'], $caseId]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#claims'); exit;
    }
    if (($_POST['action'] ?? '') === 'add_fact') {
        $factDescription = trim($_POST['fact_description'] ?? '');
        $occurredAt = trim($_POST['occurred_at'] ?? '');
        $factDate = $occurredAt !== '' ? DateTime::createFromFormat('!Y-m-d', $occurredAt) : null;
        if ($factDescription === '' || ($occurredAt !== '' && (!$factDate || $factDate->format('Y-m-d') !== $occurredAt))) {
            $caseActionMessage = is_rtl() ? 'أدخل وصف الواقعة وتاريخاً صحيحاً إن توفر.' : 'Enter a fact description and a valid date if provided.';
        } else {
            $pdo->prepare('INSERT INTO case_facts (case_id,description,occurred_at,source_note) VALUES (?,?,?,?)')
                ->execute([$caseId,$factDescription,$occurredAt !== '' ? $occurredAt : null,trim($_POST['source_note'] ?? '') ?: null]);
            header('Location: /lawyer/case-detail.php?id=' . $caseId . '#facts'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'delete_fact') {
        $pdo->prepare('DELETE FROM case_facts WHERE id=? AND case_id=?')->execute([(int)($_POST['fact_id'] ?? 0),$caseId]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#facts'); exit;
    }
    if (($_POST['action'] ?? '') === 'add_task') {
        $taskTitle = trim($_POST['title'] ?? '');
        $dueDate = $_POST['due_date'] ?? '';
        $dueDateObject = $dueDate !== '' ? DateTime::createFromFormat('!Y-m-d', $dueDate) : null;
        if ($taskTitle === '' || ($dueDate !== '' && (!$dueDateObject || $dueDateObject->format('Y-m-d') !== $dueDate))) { $caseActionMessage = is_rtl() ? 'أدخل عنوان المهمة وتاريخاً صحيحاً.' : 'Enter a task title and valid due date.'; } else {
        $clientVisible = !empty($_POST['client_visible']) ? 1 : 0;
        $pdo->prepare('INSERT INTO case_tasks (case_id, title, due_date,client_visible) VALUES (?,?,?,?)')
            ->execute([$caseId, $taskTitle, $dueDate !== '' ? $dueDate : null,$clientVisible]);
        if ($clientVisible) {
            $pdo->prepare('INSERT INTO notifications (recipient_type,recipient_id,title,message,link) VALUES (?,?,?,?,?)')
                ->execute(['client',(int)$case['user_id'],is_rtl()?'أضاف محاميك مهمة للقضية':'Your lawyer added a case task',$taskTitle,'/case-tasks.php']);
        }
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#tasks'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'toggle_task') {
        $pdo->prepare("UPDATE case_tasks SET status = IF(status='pending','done','pending') WHERE id=? AND case_id=?")->execute([(int)$_POST['task_id'], $caseId]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#tasks'); exit;
    }
    if (($_POST['action'] ?? '') === 'delete_task') {
        $pdo->prepare('DELETE FROM case_tasks WHERE id=? AND case_id=?')->execute([(int)$_POST['task_id'], $caseId]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#tasks'); exit;
    }
    if (($_POST['action'] ?? '') === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') { $caseActionMessage = is_rtl() ? 'اكتب الملاحظة أولاً.' : 'Enter a note first.'; } else {
        $pdo->prepare('INSERT INTO case_notes (case_id, lawyer_id, firm_id, note) VALUES (?,?,?,?)')
            ->execute([$caseId, $lawyerId, $firmId, $note]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#notes'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'add_evidence') {
        $title = trim($_POST['evidence_title'] ?? '');
        $description = trim($_POST['evidence_description'] ?? '');
        $evidenceType = in_array($_POST['evidence_type'] ?? '', ['document','contract','correspondence','photo','other'], true) ? $_POST['evidence_type'] : 'other';
        $relatedClaimId = (int)($_POST['related_claim_id'] ?? 0);
        $relatedFactId = (int)($_POST['related_fact_id'] ?? 0);
        $relatedLawArticleId = (int)($_POST['related_law_article_id'] ?? 0);
        if ($relatedClaimId) {
            $claimCheck = $pdo->prepare('SELECT id FROM case_claims WHERE id=? AND case_id=?');
            $claimCheck->execute([$relatedClaimId,$caseId]);
            if (!$claimCheck->fetch()) $relatedClaimId = 0;
        }
        if ($relatedFactId) {
            $factCheck = $pdo->prepare('SELECT id FROM case_facts WHERE id=? AND case_id=?');
            $factCheck->execute([$relatedFactId,$caseId]);
            if (!$factCheck->fetch()) $relatedFactId = 0;
        }
        if ($relatedLawArticleId) {
            $articleCheck = $pdo->prepare("SELECT id FROM law_articles WHERE id=? AND verification_status='verified'");
            $articleCheck->execute([$relatedLawArticleId]);
            if (!$articleCheck->fetch()) {
                $caseActionMessage = is_rtl() ? 'المادة النظامية المحددة غير موجودة أو غير موثقة.' : 'The selected law article is missing or unverified.';
            }
        }
        $file = $_FILES['evidence_file'] ?? null;
        $storedName = null;
        $originalName = null;
        if ($title === '') {
            $caseActionMessage = is_rtl() ? 'أدخل عنوان الدليل.' : 'Enter an evidence title.';
        } elseif (!$caseActionMessage && $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $validation = validate_legal_upload($file);
            if (!$validation['ok']) {
                $caseActionMessage = is_rtl() ? 'الملف غير صالح. يسمح بملف PDF أو صورة حتى 15 ميغابايت.' : 'Invalid file. Upload a PDF or image up to 15 MB.';
            } else {
                if (!is_dir(EVIDENCE_UPLOAD_DIR)) mkdir(EVIDENCE_UPLOAD_DIR, 0755, true);
                $storedName = bin2hex(random_bytes(20)) . '.' . $validation['extension'];
                if (!move_uploaded_file($file['tmp_name'], EVIDENCE_UPLOAD_DIR . $storedName)) {
                    $caseActionMessage = is_rtl() ? 'تعذّر حفظ الملف.' : 'Could not store the file.';
                    $storedName = null;
                } else {
                    $originalName = basename(str_replace('\\', '/', (string)$file['name']));
                }
            }
        }
        if (!$caseActionMessage) {
            $pdo->prepare('INSERT INTO case_evidence (case_id, added_by_lawyer_id, firm_id, related_claim_id, related_fact_id, related_law_article_id, title, evidence_type, description, original_name, stored_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$caseId, $lawyerId, $firmId, $relatedClaimId ?: null, $relatedFactId ?: null, $relatedLawArticleId ?: null, $title, $evidenceType, $description ?: null, $originalName, $storedName]);
            $evidenceId = (int)$pdo->lastInsertId();
            if ($storedName && !empty($_POST['analyze_evidence'])) {
                $settings=ai_load_settings($pdo);
                if (!empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0') {
                    $types = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
                    $ai = analyze_document_with_ai($settings['ai_provider'], $settings['ai_api_key'], $settings['ai_model'] ?? '', base64_encode(file_get_contents(EVIDENCE_UPLOAD_DIR . $storedName)), $types[$validation['extension']], is_rtl(), $evidenceType === 'contract' ? 'contract' : 'general');
                    if ($ai['ok']) {
                        $findings = json_encode(['risks'=>$ai['risks'],'recommendations'=>$ai['recommendations']], JSON_UNESCAPED_UNICODE);
                        $pdo->prepare('UPDATE case_evidence SET ai_summary=?,ai_findings=?,ai_analyzed_at=NOW() WHERE id=?')->execute([$ai['summary'],$findings,$evidenceId]);
                    }
                }
            }
            header('Location: /lawyer/case-detail.php?id=' . $caseId . '#evidence'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'analyze_case' && empty($_POST['ai_consent'])) {
        $caseActionMessage = is_rtl() ? 'وافق على إرسال بيانات القضية إلى مزود AI المهيأ لتحليلها.' : 'Consent to send case data to the configured AI provider for analysis.';
    }
    if (($_POST['action'] ?? '') === 'analyze_case' && !empty($_POST['ai_consent'])) {
        $settings=ai_load_settings($pdo);
        if (empty($settings['ai_provider']) || empty($settings['ai_api_key']) || ($settings['ai_enabled'] ?? '1') === '0') {
            $caseActionMessage = is_rtl() ? 'فعّل مزوّد الذكاء الاصطناعي من إعدادات الإدارة أولاً.' : 'Enable an AI provider in admin settings first.';
        } elseif ((int)$pdo->query("SELECT COUNT(*) FROM case_analyses WHERE lawyer_id=" . (int)$lawyerId . " AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)")->fetchColumn() >= 10) {
            $caseActionMessage = is_rtl() ? 'وصلت إلى الحد المؤقت لتحليلات القضايا. حاول بعد ساعة.' : 'Hourly case-analysis limit reached. Try again in an hour.';
        } else {
            $caseFacts = ['case' => ['title' => $case['title'], 'type' => $case['case_type'], 'description' => $case['description'], 'status' => $case['status']], 'parties' => [], 'claims_and_defenses' => [], 'facts' => [], 'timeline' => [], 'tasks' => [], 'evidence_register' => [], 'risk_register' => []];
            foreach (['case_parties' => 'parties', 'case_claims' => 'claims_and_defenses', 'case_facts' => 'facts', 'case_timeline' => 'timeline', 'case_tasks' => 'tasks', 'case_evidence' => 'evidence_register', 'case_risks' => 'risk_register'] as $table => $key) {
                $query = $table === 'case_evidence'
                    ? 'SELECT e.id,e.title,e.evidence_type,e.description,e.original_name,e.ai_summary,e.ai_findings,e.related_claim_id,e.related_fact_id,e.related_law_article_id,e.created_at,cf.description AS linked_fact,la.law_name_ar AS linked_law_name,la.article_number AS linked_article_number,la.content_ar AS linked_law_text FROM case_evidence e LEFT JOIN case_facts cf ON cf.id=e.related_fact_id LEFT JOIN law_articles la ON la.id=e.related_law_article_id WHERE e.case_id=? ORDER BY e.id'
                    : "SELECT * FROM {$table} WHERE case_id=? ORDER BY id";
                $q = $pdo->prepare($query); $q->execute([$caseId]); $caseFacts[$key] = $q->fetchAll();
            }
            $systemPrompt = is_rtl()
                ? 'أنت مساعد تحليل قانوني داخلي للمحامي. حلل الوقائع المقدمة فقط بصيغة JSON. لا تخترع وقائع أو مواد قانونية أو سوابق. أرجع JSON فقط بالمفاتيح التالية: narrative (نص التحليل السردي الكامل بنفس الأسلوب المعتاد: ما هو معلوم وما هو ناقص، حجج الطرفين، نقاط القوة والضعف، التناقضات، مخاطر المواعيد والأدلة، الخطوات التالية، مع تنبيه أن النتيجة تحتاج مراجعة المحامي)، legal_issues (مصفوفة نصوص بأبرز المسائل القانونية المستخرجة من الوقائع فقط)، burden_of_proof (نص قصير يوضح على أي طرف يقع عبء الإثبات لكل ادعاء رئيسي بناءً على الوقائع المقدمة فقط، لا قاعدة نظامية عامة إلا إذا ذُكرت صراحة في السجل)، argument_strength (كائن بمفاتيح plaintiff_score وdefendant_score من 1 إلى 5 وreasoning نص قصير يوضح أساس التقييم استناداً للوقائع والأدلة المسجلة فقط)، counterarguments (مصفوفة نصوص بأبرز الحجج المضادة المحتملة التي قد يثيرها الطرف الآخر بناءً على السجل)، risk_matrix (مصفوفة كائنات بمفاتيح risk وlikelihood وimpact بقيم low/medium/high وnote). ميّز دائماً بين الوقائع والاستنتاجات، ولا تصغ أي تقييم كحقيقة قطعية.'
                : 'You are an internal legal analysis assistant for a lawyer. Analyze only the supplied facts, returned as JSON. Never invent facts, statutes, or precedents. Return JSON only with these keys: narrative (the full narrative analysis in the usual style: known/missing information, arguments on both sides, strengths, weaknesses, contradictions, deadline/evidence risks, next steps, with a note that lawyer review is required), legal_issues (string array of the key legal issues extracted only from the supplied facts), burden_of_proof (short text on which party bears the burden of proof for each main claim, based only on the supplied facts — no general statutory rule unless explicitly present in the record), argument_strength (object with plaintiff_score and defendant_score from 1 to 5, and reasoning explaining the basis using only the recorded facts/evidence), counterarguments (string array of likely counterarguments the opposing side could raise based on the record), risk_matrix (array of objects with risk, likelihood, impact as low/medium/high, and note). Always distinguish facts from inferences, and never phrase an assessment as definitive fact.';
            $userMessage = json_encode($caseFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = $settings['ai_provider'] === 'anthropic'
                ? call_anthropic_raw($settings['ai_api_key'], $settings['ai_model'] ?? '', $systemPrompt, $userMessage, 2600)
                : call_openai_raw($settings['ai_api_key'], $settings['ai_model'] ?? '', $systemPrompt, $userMessage, 2600);
            if (!$result['ok'] || !$result['text']) {
                $caseActionMessage = is_rtl() ? 'تعذّر تحليل القضية حالياً. تحقق من إعدادات مزوّد الذكاء الاصطناعي.' : 'Case analysis failed. Check the AI provider settings.';
            } else {
                $structuredClean = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($result['text'])));
                $structuredData = json_decode($structuredClean, true);
                $narrative = is_array($structuredData) && !empty($structuredData['narrative']) ? (string)$structuredData['narrative'] : $result['text'];
                $structuredToStore = is_array($structuredData) ? json_encode([
                    'legal_issues' => is_array($structuredData['legal_issues'] ?? null) ? array_slice($structuredData['legal_issues'], 0, 20) : [],
                    'burden_of_proof' => is_string($structuredData['burden_of_proof'] ?? null) ? $structuredData['burden_of_proof'] : null,
                    'argument_strength' => is_array($structuredData['argument_strength'] ?? null) ? [
                        'plaintiff_score' => max(1, min(5, (int)($structuredData['argument_strength']['plaintiff_score'] ?? 0))),
                        'defendant_score' => max(1, min(5, (int)($structuredData['argument_strength']['defendant_score'] ?? 0))),
                        'reasoning' => (string)($structuredData['argument_strength']['reasoning'] ?? ''),
                    ] : null,
                    'counterarguments' => is_array($structuredData['counterarguments'] ?? null) ? array_slice($structuredData['counterarguments'], 0, 20) : [],
                    'risk_matrix' => is_array($structuredData['risk_matrix'] ?? null) ? array_values(array_filter(array_map(function ($r) {
                        if (!is_array($r)) return null;
                        $lvl = fn($v) => in_array($v, ['low', 'medium', 'high'], true) ? $v : 'unknown';
                        return ['risk' => (string)($r['risk'] ?? ''), 'likelihood' => $lvl($r['likelihood'] ?? ''), 'impact' => $lvl($r['impact'] ?? ''), 'note' => (string)($r['note'] ?? '')];
                    }, array_slice($structuredData['risk_matrix'], 0, 20)))) : [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                $pdo->prepare('INSERT INTO case_analyses (case_id,lawyer_id,analysis,structured_json,model) VALUES (?,?,?,?,?)')
                    ->execute([$caseId, $lawyerId, $narrative, $structuredToStore, $settings['ai_model'] ?? null]);
                header('Location: /lawyer/case-detail.php?id=' . $caseId . '#analysis'); exit;
            }
        }
    }
    if (($_POST['action'] ?? '') === 'analyze_evidence' && empty($_POST['ai_consent'])) {
        $caseActionMessage = is_rtl() ? 'وافق على إرسال سجل الأدلة والادعاءات لمزوّد AI المهيأ لتحليلها.' : 'Consent to send the evidence/claims register to the configured AI provider for analysis.';
    }
    if (($_POST['action'] ?? '') === 'analyze_evidence' && !empty($_POST['ai_consent'])) {
        require_once __DIR__ . '/../includes/evidence_intelligence.php';
        $settings = ai_load_settings($pdo);
        if ((int)$pdo->query("SELECT COUNT(*) FROM case_evidence_reports WHERE lawyer_id=" . (int)$lawyerId . " AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)")->fetchColumn() >= 10) {
            $caseActionMessage = is_rtl() ? 'وصلت إلى الحد المؤقت لتحليلات الأدلة. حاول بعد ساعة.' : 'Hourly evidence-analysis limit reached. Try again in an hour.';
        } else {
            $evidenceForAnalysis = $pdo->prepare('SELECT * FROM case_evidence WHERE case_id=? ORDER BY id');
            $evidenceForAnalysis->execute([$caseId]); $evidenceForAnalysis = $evidenceForAnalysis->fetchAll();
            $claimsForAnalysis = $pdo->prepare('SELECT * FROM case_claims WHERE case_id=? ORDER BY id');
            $claimsForAnalysis->execute([$caseId]); $claimsForAnalysis = $claimsForAnalysis->fetchAll();
            $factsForAnalysis = $pdo->prepare('SELECT * FROM case_facts WHERE case_id=? ORDER BY id');
            $factsForAnalysis->execute([$caseId]); $factsForAnalysis = $factsForAnalysis->fetchAll();

            $evidenceReport = evidence_intelligence_analyze($pdo, $settings, $case, $evidenceForAnalysis, $claimsForAnalysis, $factsForAnalysis, is_rtl());
            if (!$evidenceReport['ok']) {
                $caseActionMessage = $evidenceReport['error'];
            } else {
                $pdo->prepare('INSERT INTO case_evidence_reports (case_id,lawyer_id,conflicts_json,missing_evidence_json,summary,model,evidence_count_at_run) VALUES (?,?,?,?,?,?,?)')
                    ->execute([
                        $caseId, $lawyerId,
                        json_encode($evidenceReport['conflicts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($evidenceReport['missing'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $evidenceReport['summary'],
                        $settings['ai_model'] ?? null,
                        count($evidenceForAnalysis),
                    ]);
                header('Location: /lawyer/case-detail.php?id=' . $caseId . '#evidence'); exit;
            }
        }
    }
    if (($_POST['action'] ?? '') === 'add_risk') {
        $title = trim($_POST['risk_title'] ?? '');
        $likelihood = max(1,min(5,(int)($_POST['likelihood'] ?? 1)));
        $impact = max(1,min(5,(int)($_POST['impact'] ?? 1)));
        if ($title === '') {
            $caseActionMessage = is_rtl() ? 'أدخل وصف الخطر.' : 'Enter a risk description.';
        } else {
            $pdo->prepare('INSERT INTO case_risks (case_id,title,likelihood,impact,mitigation) VALUES (?,?,?,?,?)')
                ->execute([$caseId,$title,$likelihood,$impact,trim($_POST['mitigation'] ?? '') ?: null]);
            header('Location: /lawyer/case-detail.php?id=' . $caseId . '#risks'); exit;
        }
    }
    if (($_POST['action'] ?? '') === 'toggle_risk') {
        $pdo->prepare("UPDATE case_risks SET status=IF(status='open','mitigated','open') WHERE id=? AND case_id=?")
            ->execute([(int)($_POST['risk_id'] ?? 0),$caseId]);
        header('Location: /lawyer/case-detail.php?id=' . $caseId . '#risks'); exit;
    }
    if (($_POST['action'] ?? '') === 'add_time_entry') {
        $description = trim($_POST['time_description'] ?? '');
        $minutes = (int)($_POST['minutes'] ?? 0);
        $workDate = $_POST['work_date'] ?? date('Y-m-d');
        $workDateObject = DateTime::createFromFormat('!Y-m-d', $workDate);
        $dateValid = $workDateObject && $workDateObject->format('Y-m-d') === $workDate;
        if ($description === '' || $minutes < 1 || $minutes > 1440 || !$dateValid) {
            $caseActionMessage = is_rtl() ? 'أدخل وصفاً وتاريخاً ومدة صحيحة (من دقيقة إلى 24 ساعة).' : 'Enter a description, valid date, and duration (1 minute to 24 hours).';
        } else {
            $rate = max(0,(float)($case['lawyer_hourly_rate'] ?? 0));
            $pdo->prepare('INSERT INTO case_time_entries (case_id,lawyer_id,firm_id,work_date,description,minutes,hourly_rate,billable) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$caseId,$lawyerId,$firmId,$workDate,$description,$minutes,$rate,!empty($_POST['billable']) ? 1 : 0]);
            header('Location: /lawyer/case-detail.php?id=' . $caseId . '#time'); exit;
        }
    }
}

$activeLawyer = 'cases';
require_once __DIR__ . '/includes/lawyer_header.php';

$timeline = $pdo->prepare('SELECT * FROM case_timeline WHERE case_id=? ORDER BY event_date DESC');
$timeline->execute([$caseId]);
$timeline = $timeline->fetchAll();

$parties = $pdo->prepare('SELECT * FROM case_parties WHERE case_id=? ORDER BY id');
$parties->execute([$caseId]); $parties = $parties->fetchAll();
$partyLabels = ['plaintiff' => 'مدّعٍ', 'defendant' => 'مدّعى عليه', 'witness' => 'شاهد', 'third_party' => 'طرف ثالث', 'other' => 'أخرى'];

$claims = $pdo->prepare('SELECT * FROM case_claims WHERE case_id=? ORDER BY id');
$claims->execute([$caseId]); $claims = $claims->fetchAll();

$facts = $pdo->prepare('SELECT * FROM case_facts WHERE case_id=? ORDER BY (occurred_at IS NULL),occurred_at,id');
$facts->execute([$caseId]); $facts = $facts->fetchAll();
$verifiedLawArticles = $pdo->query("SELECT id,law_name_ar,article_number FROM law_articles WHERE verification_status='verified' ORDER BY law_name_ar,article_number LIMIT 500")->fetchAll();

$tasks = $pdo->prepare('SELECT * FROM case_tasks WHERE case_id=? ORDER BY (due_date IS NULL), due_date, id');
$tasks->execute([$caseId]); $tasks = $tasks->fetchAll();

$notes = $pdo->prepare('SELECT * FROM case_notes WHERE case_id=? ORDER BY created_at DESC');
$notes->execute([$caseId]); $notes = $notes->fetchAll();
$latestEvidenceReport = $pdo->prepare('SELECT * FROM case_evidence_reports WHERE case_id=? ORDER BY created_at DESC LIMIT 1');
$latestEvidenceReport->execute([$caseId]); $latestEvidenceReport = $latestEvidenceReport->fetch();
$evidenceConflicts = $latestEvidenceReport ? (json_decode($latestEvidenceReport['conflicts_json'] ?? '[]', true) ?: []) : [];
$evidenceMissing = $latestEvidenceReport ? (json_decode($latestEvidenceReport['missing_evidence_json'] ?? '[]', true) ?: []) : [];
$evidence = $pdo->prepare('SELECT e.*,cc.description AS related_claim,cf.description AS related_fact,la.law_name_ar AS related_law_name,la.article_number AS related_law_article_number FROM case_evidence e LEFT JOIN case_claims cc ON cc.id=e.related_claim_id LEFT JOIN case_facts cf ON cf.id=e.related_fact_id LEFT JOIN law_articles la ON la.id=e.related_law_article_id WHERE e.case_id=? ORDER BY e.created_at DESC');
$evidence->execute([$caseId]); $evidence = $evidence->fetchAll();
$analyses = $pdo->prepare('SELECT * FROM case_analyses WHERE case_id=? ORDER BY created_at DESC LIMIT 10');
$analyses->execute([$caseId]); $analyses = $analyses->fetchAll();
$risks = $pdo->prepare('SELECT * FROM case_risks WHERE case_id=? ORDER BY likelihood*impact DESC,id DESC');
$risks->execute([$caseId]); $risks = $risks->fetchAll();
$timeEntries = $pdo->prepare('SELECT te.*,i.is_draft AS invoice_is_draft FROM case_time_entries te LEFT JOIN invoices i ON i.id=te.invoice_id WHERE te.case_id=? ORDER BY te.work_date DESC,te.id DESC');
$timeEntries->execute([$caseId]); $timeEntries = $timeEntries->fetchAll();
$billableMinutes = array_sum(array_map(fn($entry)=>(int)$entry['billable']?(int)$entry['minutes']:0,$timeEntries));
$billableValue = array_sum(array_map(fn($entry)=>(int)$entry['billable']?round(((int)$entry['minutes']/60)*(float)$entry['hourly_rate'],3):0,$timeEntries));
?>
<div class="app-content">
  <?php if ($caseActionMessage): ?><div class="form-alert error"><?= htmlspecialchars($caseActionMessage) ?></div><?php endif; ?>
  <div class="dash-card" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px">
    <div>
      <h2 style="margin-bottom:4px"><?= htmlspecialchars($case['title']) ?></h2>
      <p style="margin:0"><?= htmlspecialchars($case['client_name']) ?> — <?= htmlspecialchars($case['client_email']) ?> · <?= htmlspecialchars($case['case_type']) ?></p>
      <?php if ($case['description']): ?><p style="margin-top:10px"><?= htmlspecialchars($case['description']) ?></p><?php endif; ?>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a href="#parties" class="icon-btn">👥 <?= is_rtl() ? 'الأطراف' : 'Parties' ?></a>
      <a href="#claims" class="icon-btn">📋 <?= is_rtl() ? 'الادعاءات' : 'Claims' ?></a>
      <a href="#tasks" class="icon-btn">✅ <?= is_rtl() ? 'المهام' : 'Tasks' ?></a>
      <a href="#notes" class="icon-btn">📝 <?= is_rtl() ? 'الملاحظات' : 'Notes' ?></a>
      <a href="#evidence" class="icon-btn">📎 <?= is_rtl() ? 'الأدلة' : 'Evidence' ?></a>
      <a href="#analysis" class="icon-btn">🧠 <?= is_rtl() ? 'تحليل القضية' : 'Case analysis' ?></a>
      <a href="#risks" class="icon-btn">⚠️ <?= is_rtl() ? 'المخاطر' : 'Risks' ?></a>
      <a href="#time" class="icon-btn">⏱️ <?= is_rtl() ? 'الوقت' : 'Time' ?></a>
    </div>
  </div>

  <div class="dash-card">
    <h3 style="margin-bottom:14px"><?= is_rtl() ? 'الحالة وتقدير احتمالية الفوز' : 'Status & win probability' ?></h3>
    <form method="post" class="grid-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_case">
      <div class="field">
        <label><?= is_rtl() ? 'الحالة' : 'Status' ?></label>
        <select name="status">
          <?php foreach (['open'=>'مفتوحة','in_review'=>'قيد المراجعة','closed_won'=>'مغلقة - فوز','closed_lost'=>'مغلقة - خسارة','closed_settled'=>'مغلقة - تسوية'] as $val => $label): ?>
            <option value="<?= $val ?>" <?= $case['status'] === $val ? 'selected' : '' ?>><?= is_rtl() ? $label : $val ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'احتمالية الفوز (%)' : 'Win probability (%)' ?></label>
        <input type="number" min="0" max="100" name="win_probability" value="<?= htmlspecialchars($case['win_probability'] ?? '') ?>">
      </div>
      <div style="grid-column:1/-1"><button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'حفظ' : 'Save' ?></button></div>
    </form>
  </div>

  <div class="dash-card" id="parties">
    <h3 style="margin-bottom:14px">👥 <?= is_rtl() ? 'أطراف القضية' : 'Case parties' ?></h3>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_party' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة طرف':'Add party' ?></summary>
    <form method="post" class="grid-2" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_party">
      <div class="field"><label><?= is_rtl() ? 'نوع الطرف' : 'Party type' ?></label>
        <div class="select-wrap"><select name="party_type" class="pro-select">
          <?php foreach ($partyLabels as $val => $label): ?><option value="<?= $val ?>"><?= $label ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="field"><label><?= is_rtl() ? 'الاسم' : 'Name' ?></label><input type="text" name="name" required></div>
      <div class="field"><label><?= is_rtl() ? 'الصفة/الدور' : 'Role' ?></label><input type="text" name="role_description"></div>
      <div class="field"><label><?= is_rtl() ? 'بيانات التواصل' : 'Contact info' ?></label><input type="text" name="contact_info"></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl() ? 'إضافة طرف' : 'Add party' ?></button></div>
    </form></details>
    <?php foreach ($parties as $p): ?>
      <div class="rec-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <span><span class="status-pill status-pending"><?= $partyLabels[$p['party_type']] ?? $p['party_type'] ?></span> <strong><?= htmlspecialchars($p['name']) ?></strong> <?= $p['role_description'] ? '— ' . htmlspecialchars($p['role_description']) : '' ?> <?= $p['contact_info'] ? '· ' . htmlspecialchars($p['contact_info']) : '' ?></span>
        <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'حذف هذا الطرف؟' : 'Delete this party?' ?>')" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_party"><input type="hidden" name="party_id" value="<?= $p['id'] ?>"><button type="submit" class="icon-btn danger">✕</button></form>
      </div>
    <?php endforeach; ?>
    <?php if (empty($parties)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد أطراف مسجّلة بعد.' : 'No parties recorded yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="claims">
    <h3 style="margin-bottom:14px">📋 <?= is_rtl() ? 'الادعاءات والدفوع' : 'Claims & defenses' ?></h3>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_claim' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة ادعاء أو دفع':'Add claim or defense' ?></summary>
    <form method="post" class="grid-2" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_claim">
      <div class="field"><label><?= is_rtl() ? 'النوع' : 'Type' ?></label>
        <div class="select-wrap"><select name="claim_type" class="pro-select">
          <option value="claim"><?= is_rtl() ? 'ادعاء' : 'Claim' ?></option>
          <option value="defense"><?= is_rtl() ? 'دفع' : 'Defense' ?></option>
        </select></div>
      </div>
      <div class="field" style="grid-column:span 1"><label><?= is_rtl() ? 'الوصف' : 'Description' ?></label><input type="text" name="description" required></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl() ? 'إضافة' : 'Add' ?></button></div>
    </form></details>
    <?php foreach ($claims as $c): ?>
      <div class="rec-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <span><span class="status-pill <?= $c['claim_type']==='claim' ? 'status-pending' : 'status-cancelled' ?>"><?= $c['claim_type']==='claim' ? (is_rtl()?'ادعاء':'Claim') : (is_rtl()?'دفع':'Defense') ?></span> <?= htmlspecialchars($c['description']) ?></span>
        <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'حذف؟' : 'Delete?' ?>')" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_claim"><input type="hidden" name="claim_id" value="<?= $c['id'] ?>"><button type="submit" class="icon-btn danger">✕</button></form>
      </div>
    <?php endforeach; ?>
    <?php if (empty($claims)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد ادعاءات أو دفوع مسجّلة بعد.' : 'No claims or defenses recorded yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="facts">
    <h3 style="margin-bottom:14px">🧾 <?= is_rtl() ? 'سجل وقائع القضية' : 'Case fact register' ?></h3>
    <p class="field-hint"><?= is_rtl() ? 'سجّل الوقائع ومصدر كل معلومة لتمييزها عن استنتاجات التحليل.' : 'Record facts and their sources so they remain distinct from analysis inferences.' ?></p>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_fact' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة واقعة':'Add fact' ?></summary>
    <form method="post" class="grid-2" style="margin:14px 0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_fact">
      <div class="field" style="grid-column:1/-1"><label><?= is_rtl()?'الواقعة':'Fact' ?></label><textarea name="fact_description" rows="2" required maxlength="10000"></textarea></div>
      <div class="field"><label><?= is_rtl()?'تاريخ الواقعة (اختياري)':'Fact date (optional)' ?></label><input type="date" name="occurred_at"></div>
      <div class="field"><label><?= is_rtl()?'المصدر أو مرجع الملف':'Source or record reference' ?></label><input name="source_note" maxlength="1000"></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl()?'إضافة واقعة':'Add fact' ?></button></div>
    </form></details>
    <?php foreach ($facts as $fact): ?><div class="rec-item" style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start"><div><?php if ($fact['occurred_at']): ?><span class="status-pill status-pending"><?= htmlspecialchars($fact['occurred_at']) ?></span><?php endif; ?><div><?= nl2br(htmlspecialchars($fact['description'])) ?></div><?php if ($fact['source_note']): ?><small class="field-hint"><?= is_rtl()?'المصدر: ':'Source: ' ?><?= htmlspecialchars($fact['source_note']) ?></small><?php endif; ?></div><form method="post" style="margin:0" onsubmit="return confirm('<?= is_rtl()?'حذف الواقعة؟':'Delete this fact?' ?>')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_fact"><input type="hidden" name="fact_id" value="<?= (int)$fact['id'] ?>"><button type="submit" class="icon-btn danger">✕</button></form></div><?php endforeach; ?>
    <?php if (!$facts): ?><p style="color:var(--text-muted)"><?= is_rtl()?'لا توجد وقائع مسجلة بعد.':'No facts recorded yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="tasks">
    <h3 style="margin-bottom:14px">✅ <?= is_rtl() ? 'المهام والمواعيد' : 'Tasks & deadlines' ?></h3>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_task' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة مهمة':'Add task' ?></summary>
    <form method="post" class="grid-2" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_task">
      <div class="field"><label><?= is_rtl() ? 'المهمة' : 'Task' ?></label><input type="text" name="title" required></div>
      <div class="field"><label><?= is_rtl() ? 'تاريخ الاستحقاق' : 'Due date' ?></label><input type="date" name="due_date"></div>
      <div class="field"><label><input type="checkbox" name="client_visible" value="1"> <?= is_rtl() ? 'إظهار المهمة للعميل' : 'Show task to client' ?></label></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl() ? 'إضافة مهمة' : 'Add task' ?></button></div>
    </form></details>
    <?php foreach ($tasks as $t):
      $overdue = $t['status'] === 'pending' && $t['due_date'] && strtotime($t['due_date']) < strtotime('today');
    ?>
      <div class="rec-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px;<?= $t['status']==='done' ? 'opacity:.6' : '' ?>">
        <span style="<?= $t['status']==='done' ? 'text-decoration:line-through' : '' ?>">
          <?= htmlspecialchars($t['title']) ?>
          <?php if (!empty($t['client_visible'])): ?><span class="status-pill status-confirmed"><?= is_rtl()?'ظاهرة للعميل':'Client-visible' ?></span><?php endif; ?>
          <?php if ($t['due_date']): ?><span class="status-pill <?= $overdue ? 'status-cancelled' : 'status-pending' ?>"><?= date('Y-m-d', strtotime($t['due_date'])) ?></span><?php endif; ?>
        </span>
        <span style="display:flex;gap:6px">
          <form method="post" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="task_id" value="<?= $t['id'] ?>"><button type="submit" class="icon-btn"><?= $t['status']==='done' ? (is_rtl()?'إرجاع':'Undo') : (is_rtl()?'تم':'Done') ?></button></form>
          <form method="post" onsubmit="return confirm('<?= is_rtl() ? 'حذف المهمة؟' : 'Delete task?' ?>')" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?= $t['id'] ?>"><button type="submit" class="icon-btn danger">✕</button></form>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if (empty($tasks)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد مهام بعد.' : 'No tasks yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="notes">
    <h3 style="margin-bottom:14px">📝 <?= is_rtl() ? 'ملاحظات خاصة (لا يراها العميل)' : 'Private notes (not visible to client)' ?></h3>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_note' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة ملاحظة':'Add note' ?></summary>
    <form method="post" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_note">
      <div class="field"><textarea name="note" rows="2" required></textarea></div>
      <button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl() ? 'إضافة ملاحظة' : 'Add note' ?></button>
    </form></details>
    <?php foreach ($notes as $n): ?>
      <div class="rec-item"><span style="color:var(--text-muted);font-size:.78rem"><?= date('Y-m-d H:i', strtotime($n['created_at'])) ?></span><br><?= nl2br(htmlspecialchars($n['note'])) ?></div>
    <?php endforeach; ?>
    <?php if (empty($notes)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد ملاحظات بعد.' : 'No notes yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="evidence">
    <h3 style="margin-bottom:14px">📎 <?= is_rtl() ? 'سجل أدلة القضية' : 'Case evidence register' ?></h3>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_evidence' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة دليل':'Add evidence' ?></summary>
    <form method="post" enctype="multipart/form-data" class="grid-2" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_evidence">
      <div class="field"><label><?= is_rtl() ? 'عنوان الدليل' : 'Evidence title' ?></label><input name="evidence_title" required maxlength="255"></div>
      <div class="field"><label><?= is_rtl() ? 'التصنيف' : 'Type' ?></label><select name="evidence_type"><option value="document"><?= is_rtl()?'مستند':'Document' ?></option><option value="contract"><?= is_rtl()?'عقد':'Contract' ?></option><option value="correspondence"><?= is_rtl()?'مراسلة':'Correspondence' ?></option><option value="photo"><?= is_rtl()?'صورة':'Photo' ?></option><option value="other"><?= is_rtl()?'أخرى':'Other' ?></option></select></div>
      <div class="field"><label><?= is_rtl()?'ربط بادعاء أو دفع':'Link to claim or defense' ?></label><select name="related_claim_id"><option value="0">—</option><?php foreach($claims as $claim): ?><option value="<?= (int)$claim['id'] ?>"><?= htmlspecialchars(mb_substr($claim['description'],0,90)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= is_rtl()?'ربط بواقعة':'Link to fact' ?></label><select name="related_fact_id"><option value="0">—</option><?php foreach($facts as $fact): ?><option value="<?= (int)$fact['id'] ?>"><?= htmlspecialchars(mb_substr($fact['description'],0,90)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= is_rtl()?'مادة نظامية موثقة (اختياري)':'Verified law article (optional)' ?></label><select name="related_law_article_id"><option value="0">—</option><?php foreach($verifiedLawArticles as $article): ?><option value="<?= (int)$article['id'] ?>"><?= htmlspecialchars($article['law_name_ar'].($article['article_number'] ? ' · مادة '.$article['article_number'] : '')) ?></option><?php endforeach; ?></select><small><?= is_rtl()?'هذا الربط لا يثبت انطباق المادة على القضية.':'Linking an article does not establish that it applies to this case.' ?></small></div>
      <div class="field"><label><?= is_rtl() ? 'وصف ومصدر الدليل' : 'Description and source' ?></label><textarea name="evidence_description" rows="2"></textarea></div>
      <div class="field"><label><?= is_rtl() ? 'الملف (اختياري، PDF أو صورة)' : 'File (optional, PDF or image)' ?></label><input type="file" name="evidence_file" accept=".pdf,.png,.jpg,.jpeg"></div>
      <div class="field"><label><input type="checkbox" name="analyze_evidence" value="1"> <?= is_rtl() ? 'تحليل الملف بالذكاء الاصطناعي' : 'Analyze file with AI' ?></label><small><?= is_rtl() ? 'بالتحديد، سيُرسل الملف إلى مزود AI المهيأ في إعدادات مواثيق لمعالجته.' : 'When selected, the file is sent to the AI provider configured in Mawatheq settings.' ?></small></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl() ? 'إضافة دليل' : 'Add evidence' ?></button></div>
    </form></details>
    <?php foreach ($evidence as $item): ?><div class="rec-item"><strong><?= htmlspecialchars($item['title']) ?></strong> <span class="status-pill status-pending"><?= htmlspecialchars($item['evidence_type']) ?></span>
      <?php if ($item['description']): ?><div><?= nl2br(htmlspecialchars($item['description'])) ?></div><?php endif; ?>
      <?php if ($item['related_claim']): ?><div class="field-hint"><?= is_rtl()?'مرتبط بـ: ':'Linked to: ' ?><?= htmlspecialchars($item['related_claim']) ?></div><?php endif; ?>
      <?php if ($item['related_fact']): ?><div class="field-hint"><?= is_rtl()?'الواقعة المرتبطة: ':'Related fact: ' ?><?= htmlspecialchars($item['related_fact']) ?></div><?php endif; ?>
      <?php if ($item['related_law_name']): ?><div class="field-hint"><?= is_rtl()?'المادة المرتبطة: ':'Linked article: ' ?><?= htmlspecialchars($item['related_law_name'].($item['related_law_article_number'] ? ' · مادة '.$item['related_law_article_number'] : '')) ?></div><?php endif; ?>
      <?php if ($item['stored_name']): ?><a href="/lawyer/download-evidence.php?id=<?= (int)$item['id'] ?>"><?= htmlspecialchars($item['original_name']) ?> · <?= is_rtl()?'تنزيل':'Download' ?></a><?php endif; ?>
      <?php if ($item['ai_summary']): ?><div class="rec-item" style="margin-top:8px"><strong><?= is_rtl()?'ملخص AI':'AI summary' ?>:</strong> <?= htmlspecialchars($item['ai_summary']) ?><?php $aiFindings=json_decode($item['ai_findings'] ?? '',true) ?: [];foreach(($aiFindings['risks'] ?? []) as $risk): ?><div style="color:var(--danger)">⚠️ <?= htmlspecialchars($risk) ?></div><?php endforeach;foreach(($aiFindings['recommendations'] ?? []) as $recommendation): ?><div>💡 <?= htmlspecialchars($recommendation) ?></div><?php endforeach; ?></div><?php endif; ?>
      <small style="display:block;color:var(--text-muted)"><?= date('Y-m-d H:i',strtotime($item['created_at'])) ?></small>
    </div><?php endforeach; ?>
    <?php if (!$evidence): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد أدلة مسجلة بعد.' : 'No evidence recorded yet.' ?></p><?php endif; ?>

    <?php if (count($evidence) >= 2): ?>
    <div class="dash-card" style="margin-top:14px;background:var(--surface-alt,#f9f9f9)">
      <h4 style="margin:0 0 8px">🧩 <?= is_rtl() ? 'كشف تعارض الأدلة والأدلة الناقصة' : 'Evidence conflict & gap detection' ?></h4>
      <p style="color:var(--text-muted);font-size:.82rem;margin:0 0 10px"><?= is_rtl() ? 'تحليل آلي يقارن سجل الأدلة بالادعاءات والوقائع المسجّلة فقط. استرشادي أولي — لا يغني عن مراجعة المحامي.' : 'Automated review comparing the evidence register against the recorded claims and facts only. Preliminary and advisory — does not replace lawyer review.' ?></p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="analyze_evidence">
        <label style="display:block;font-size:.82rem;margin-bottom:8px"><input type="checkbox" name="ai_consent" value="1"> <?= is_rtl() ? 'أوافق على إرسال سجل الأدلة/الادعاءات/الوقائع لمزوّد AI المهيأ لتحليله.' : 'I consent to sending the evidence/claims/facts register to the configured AI provider for analysis.' ?></label>
        <button class="btn btn-outline btn-sm" type="submit">🔍 <?= is_rtl() ? 'تشغيل التحليل' : 'Run analysis' ?></button>
      </form>
      <?php if ($latestEvidenceReport): ?>
        <p style="font-size:.78rem;color:var(--text-muted);margin:10px 0 0"><?= is_rtl() ? 'آخر تحليل: ' : 'Last run: ' ?><?= date('Y-m-d H:i', strtotime($latestEvidenceReport['created_at'])) ?> (<?= (int)$latestEvidenceReport['evidence_count_at_run'] ?> <?= is_rtl()?'دليلاً':'items' ?>)</p>
        <?php if ($latestEvidenceReport['summary']): ?><p style="margin:8px 0"><?= nl2br(htmlspecialchars($latestEvidenceReport['summary'])) ?></p><?php endif; ?>
        <?php $evidenceTitleById = []; foreach ($evidence as $ev) $evidenceTitleById[(int)$ev['id']] = $ev['title']; ?>
        <?php if ($evidenceConflicts): ?>
          <h5 style="margin:12px 0 6px;color:var(--danger)"><?= is_rtl()?'تعارضات محتملة':'Potential conflicts' ?></h5>
          <?php foreach ($evidenceConflicts as $conflict): ?>
            <div class="risk-item">
              <strong><?= htmlspecialchars(implode(' ↔ ', array_map(fn($id) => $evidenceTitleById[(int)$id] ?? ('#'.$id), $conflict['evidence_ids'] ?? []))) ?></strong>
              <div><?= htmlspecialchars($conflict['description'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($evidenceMissing): ?>
          <h5 style="margin:12px 0 6px;color:var(--gold)"><?= is_rtl()?'أدلة يُحتمل نقصها':'Possibly missing evidence' ?></h5>
          <?php foreach ($evidenceMissing as $gap): ?>
            <div class="rec-item"><?= htmlspecialchars($gap['description'] ?? '') ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!$evidenceConflicts && !$evidenceMissing): ?><p style="color:var(--success);font-size:.85rem"><?= is_rtl()?'لم يُرصد تعارض أو نقص ظاهر في آخر تشغيل.':'No conflicts or apparent gaps found in the last run.' ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="dash-card" id="analysis">
    <h3 style="margin-bottom:8px">🧠 <?= is_rtl() ? 'تحليل القضية' : 'Case analysis' ?></h3>
    <p style="color:var(--text-muted);font-size:.84rem"><?= is_rtl() ? 'يلخص الذكاء الاصطناعي الوقائع والادعاءات والدفوع والمواعيد وسجل الأدلة المدخل في القضية. راجع التحليل مهنياً قبل الاعتماد عليه.' : 'AI reviews the case facts, claims, defenses, deadlines, and evidence register. Review it professionally before relying on it.' ?></p>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="analyze_case"><label class="field-hint"><input type="checkbox" name="ai_consent" value="1" required> <?= is_rtl() ? 'أوافق على إرسال بيانات هذه القضية إلى مزود AI المهيأ.' : 'I agree to send this case data to the configured AI provider.' ?></label><button class="btn btn-primary btn-sm" type="submit">✨ <?= is_rtl() ? 'إنشاء تحليل جديد' : 'Generate analysis' ?></button></form>
    <?php foreach ($analyses as $analysis): $structured = json_decode($analysis['structured_json'] ?? '', true) ?: null; ?><div class="rec-item" style="margin-top:14px"><small style="color:var(--text-muted)"><?= date('Y-m-d H:i',strtotime($analysis['created_at'])) ?><?= $analysis['model'] ? ' · '.htmlspecialchars($analysis['model']) : '' ?></small><div style="white-space:pre-wrap;margin-top:8px"><?= htmlspecialchars($analysis['analysis']) ?></div>
      <?php if ($structured): ?>
        <?php if (!empty($structured['legal_issues'])): ?><h5 style="margin:12px 0 4px"><?= is_rtl()?'المسائل القانونية المستخرجة':'Extracted legal issues' ?></h5><ul style="margin:0;padding-inline-start:18px"><?php foreach ($structured['legal_issues'] as $issue): ?><li><?= htmlspecialchars($issue) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($structured['burden_of_proof'])): ?><h5 style="margin:12px 0 4px"><?= is_rtl()?'عبء الإثبات':'Burden of proof' ?></h5><p style="margin:0"><?= nl2br(htmlspecialchars($structured['burden_of_proof'])) ?></p><?php endif; ?>
        <?php if (!empty($structured['argument_strength']) && ($structured['argument_strength']['plaintiff_score'] || $structured['argument_strength']['defendant_score'])): $as=$structured['argument_strength']; ?>
          <h5 style="margin:12px 0 4px"><?= is_rtl()?'مؤشر قوة الحجج (استرشادي 1-5)':'Argument strength indicator (advisory, 1-5)' ?></h5>
          <div class="grid-2"><div><?= is_rtl()?'المدّعي':'Plaintiff' ?>: <strong><?= (int)$as['plaintiff_score'] ?>/5</strong></div><div><?= is_rtl()?'المدّعى عليه':'Defendant' ?>: <strong><?= (int)$as['defendant_score'] ?>/5</strong></div></div>
          <?php if (!empty($as['reasoning'])): ?><p style="margin:6px 0 0;color:var(--text-muted);font-size:.85rem"><?= htmlspecialchars($as['reasoning']) ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($structured['counterarguments'])): ?><h5 style="margin:12px 0 4px"><?= is_rtl()?'حجج مضادة محتملة':'Potential counterarguments' ?></h5><ul style="margin:0;padding-inline-start:18px"><?php foreach ($structured['counterarguments'] as $ca): ?><li><?= htmlspecialchars($ca) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($structured['risk_matrix'])): ?><h5 style="margin:12px 0 4px"><?= is_rtl()?'مصفوفة المخاطر':'Risk matrix' ?></h5><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= is_rtl()?'الخطر':'Risk' ?></th><th><?= is_rtl()?'الاحتمالية':'Likelihood' ?></th><th><?= is_rtl()?'الأثر':'Impact' ?></th><th><?= is_rtl()?'ملاحظة':'Note' ?></th></tr></thead><tbody><?php foreach ($structured['risk_matrix'] as $rm): ?><tr><td><?= htmlspecialchars($rm['risk'] ?? '') ?></td><td><?= htmlspecialchars($rm['likelihood'] ?? '') ?></td><td><?= htmlspecialchars($rm['impact'] ?? '') ?></td><td><?= htmlspecialchars($rm['note'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <p style="margin:10px 0 0;font-size:.78rem;color:var(--text-muted)"><?= is_rtl()?'تحليل استرشادي مبني على الوقائع المسجّلة فقط — ليس رأياً قانونياً نهائياً.':'Advisory analysis based only on recorded facts — not a final legal opinion.' ?></p>
      <?php endif; ?>
    </div><?php endforeach; ?>
    <?php if (!$analyses): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لم يُنشأ تحليل بعد.' : 'No analysis generated yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="risks">
    <h3>⚠️ <?= is_rtl() ? 'مصفوفة مخاطر القضية' : 'Case risk matrix' ?></h3>
    <p style="color:var(--text-muted);font-size:.84rem"><?= is_rtl() ? 'قيّم الاحتمالية والأثر من 1 (منخفض) إلى 5 (عالٍ). الدرجة = الاحتمالية × الأثر.' : 'Rate likelihood and impact from 1 (low) to 5 (high). Score = likelihood × impact.' ?></p>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_risk' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة خطر':'Add risk' ?></summary>
    <form method="post" class="grid-2" style="margin:14px 0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_risk">
      <div class="field"><label><?= is_rtl()?'وصف الخطر':'Risk' ?></label><input name="risk_title" required maxlength="255"></div>
      <div class="field"><label><?= is_rtl()?'الاحتمالية (1–5)':'Likelihood (1–5)' ?></label><input type="number" name="likelihood" min="1" max="5" value="3" required></div>
      <div class="field"><label><?= is_rtl()?'الأثر (1–5)':'Impact (1–5)' ?></label><input type="number" name="impact" min="1" max="5" value="3" required></div>
      <div class="field"><label><?= is_rtl()?'خطة التخفيف':'Mitigation plan' ?></label><textarea name="mitigation" rows="2"></textarea></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit">+ <?= is_rtl()?'إضافة خطر':'Add risk' ?></button></div>
    </form></details>
    <?php foreach ($risks as $risk): $score=(int)$risk['likelihood']*(int)$risk['impact']; ?><div class="rec-item" style="display:flex;justify-content:space-between;gap:12px;align-items:center;<?= $risk['status']==='mitigated'?'opacity:.6':'' ?>"><div><strong><?= htmlspecialchars($risk['title']) ?></strong> <span class="status-pill <?= $score>=15?'status-cancelled':($score>=8?'status-pending':'status-confirmed') ?>"><?= is_rtl()?'الدرجة':'Score' ?>: <?= $score ?>/25</span><div style="font-size:.82rem;color:var(--text-muted)"><?= is_rtl()?'الاحتمالية':'Likelihood' ?> <?= (int)$risk['likelihood'] ?>/5 · <?= is_rtl()?'الأثر':'Impact' ?> <?= (int)$risk['impact'] ?>/5</div><?php if ($risk['mitigation']): ?><div><?= nl2br(htmlspecialchars($risk['mitigation'])) ?></div><?php endif; ?></div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_risk"><input type="hidden" name="risk_id" value="<?= (int)$risk['id'] ?>"><button class="icon-btn" type="submit"><?= $risk['status']==='mitigated'?(is_rtl()?'إعادة فتح':'Reopen'):(is_rtl()?'تم التخفيف':'Mitigated') ?></button></form></div><?php endforeach; ?>
    <?php if (!$risks): ?><p style="color:var(--text-muted)"><?= is_rtl()?'لم تسجل مخاطر بعد.':'No risks recorded yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card" id="time">
    <h3>⏱️ <?= is_rtl()?'سجل الوقت':'Time tracking' ?></h3>
    <p style="color:var(--text-muted)"><?= is_rtl()?'الوقت القابل للفوترة: '.round($billableMinutes/60,2).' ساعة · القيمة التقديرية: '.number_format($billableValue,3).' دينار بحريني (حسب سعرك الحالي عند تسجيل الوقت).':'Billable time: '.round($billableMinutes/60,2).' hours · Estimated value: '.number_format($billableValue,3).' BHD (rate snapshot at entry time).' ?></p>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_time_entry' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'تسجيل وقت عمل':'Log work time' ?></summary>
    <form method="post" class="grid-2" style="margin:14px 0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_time_entry">
      <div class="field"><label><?= is_rtl()?'وصف العمل':'Work description' ?></label><input name="time_description" required maxlength="255"></div>
      <div class="field"><label><?= is_rtl()?'التاريخ':'Date' ?></label><input type="date" name="work_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label><?= is_rtl()?'المدة بالدقائق':'Duration in minutes' ?></label><input type="number" name="minutes" min="1" max="1440" required></div>
      <div class="field"><label><input type="checkbox" name="billable" value="1" checked> <?= is_rtl()?'قابل للفوترة':'Billable' ?></label><small><?= is_rtl()?'السعر بالساعة المسجل لملفك: ':'Profile hourly rate: ' ?><?= number_format((float)($case['lawyer_hourly_rate'] ?? 0),3) ?> <?= is_rtl()?'دينار بحريني':'BHD' ?></small></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl()?'تسجيل الوقت':'Log time' ?></button></div>
    </form></details>
    <?php foreach ($timeEntries as $entry): ?><div class="rec-item"><strong><?= date('Y-m-d',strtotime($entry['work_date'])) ?></strong> · <?= htmlspecialchars($entry['description']) ?> · <?= round((int)$entry['minutes']/60,2) ?> <?= is_rtl()?'ساعة':'hours' ?> · <?= (int)$entry['billable'] ? number_format(((int)$entry['minutes']/60)*(float)$entry['hourly_rate'],3).' BHD' : (is_rtl()?'غير قابل للفوترة':'Non-billable') ?><?php if($entry['invoice_id']): ?><div class="field-hint"><a href="/lawyer/invoices.php"><?= is_rtl()?'مرتبط بفاتورة #':'Linked to invoice #' ?><?= (int)$entry['invoice_id'] ?></a> · <?= !empty($entry['invoice_is_draft'])?(is_rtl()?'مسودة غير مرسلة':'Unsent draft'):(is_rtl()?'مفوتر':'Invoiced') ?></div><?php endif; ?></div><?php endforeach; ?>
    <?php if (!$timeEntries): ?><p style="color:var(--text-muted)"><?= is_rtl()?'لم تسجل وقتاً بعد.':'No time entries yet.' ?></p><?php endif; ?>
  </div>

  <div class="dash-card">
    <h3 style="margin-bottom:14px"><?= is_rtl() ? 'الجدول الزمني للقضية' : 'Case timeline' ?></h3>
    <details class="lawyer-form-toggle"<?= $caseActionMessage && ($_POST['action']??'')==='add_event' ? ' open' : '' ?>><summary>＋ <?= is_rtl()?'إضافة حدث زمني':'Add timeline event' ?></summary>
    <form method="post" class="grid-2" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_event">
      <div class="field"><label><?= is_rtl() ? 'الحدث' : 'Event' ?></label><input type="text" name="event" required></div>
      <div class="field"><label><?= is_rtl() ? 'التاريخ' : 'Date' ?></label><input type="date" name="event_date" required></div>
      <div style="grid-column:1/-1"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'إضافة حدث' : 'Add event' ?></button></div>
    </form></details>
    <?php foreach ($timeline as $t): ?>
      <div class="rec-item" style="border-color:var(--teal);background:#EAF6F9"><strong><?= date('Y-m-d', strtotime($t['event_date'])) ?></strong> — <?= htmlspecialchars($t['event']) ?></div>
    <?php endforeach; ?>
    <?php if (empty($timeline)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد أحداث بعد.' : 'No events yet.' ?></p><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
