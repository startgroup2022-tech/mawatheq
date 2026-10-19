<?php
/**
 * AI Case Copilot (Executive 08).
 *
 * A lawyer-facing assistant grounded in the firm's own stored records. The design rule that
 * matters most is the split between what is known and what is suggested:
 *
 *   * `verified` data is read straight from the database (timeline, facts, notes, hearings,
 *     documents, parties). It is quoted, not generated.
 *   * `suggested` data is AI output. It is stored in a separate column and the UI labels it
 *     as a proposal requiring lawyer review. It is never merged into the verified side.
 *
 * Every run is permission-checked before any provider call:
 *   - the firm comes from the session, and the case must belong to that firm;
 *   - the case's branch must be one the lawyer may see (branch isolation);
 *   - the AI call goes through the privacy gateway, so a tenant in local_only mode never
 *     reaches an external provider.
 *
 * Consequential actions (creating a draft, filing a document) are not performed here — the
 * module produces suggestions and a review record; a human applies them.
 */

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/ai_resilience.php';
require_once __DIR__ . '/knowledge_center.php';
require_once __DIR__ . '/legal_ai.php';
require_once __DIR__ . '/audit_log.php';

if (!function_exists('ai_copilot_kinds')) {
    function ai_copilot_kinds(): array {
        return ['chronology', 'summary', 'facts', 'missing_documents', 'hearing_prep', 'research',
                'document_analysis', 'draft', 'risk_flags'];
    }
}

if (!function_exists('ai_copilot_kind_label')) {
    function ai_copilot_kind_label(string $kind, bool $rtl = true): string {
        $ar = ['chronology' => 'التسلسل الزمني', 'summary' => 'ملخص القضية', 'facts' => 'الوقائع المهمة',
               'missing_documents' => 'المستندات الناقصة', 'hearing_prep' => 'التحضير للجلسة',
               'research' => 'البحث القانوني المقترح', 'document_analysis' => 'تحليل المستندات',
               'draft' => 'توليد مسودة', 'risk_flags' => 'مؤشرات المخاطر'];
        $en = ['chronology' => 'Case chronology', 'summary' => 'Case summary', 'facts' => 'Important facts',
               'missing_documents' => 'Missing documents', 'hearing_prep' => 'Hearing preparation',
               'research' => 'Suggested research', 'document_analysis' => 'Document analysis',
               'draft' => 'Draft generation', 'risk_flags' => 'Risk flags'];
        return ($rtl ? $ar : $en)[$kind] ?? $kind;
    }
}

if (!function_exists('ai_copilot_case')) {
    /**
     * Load a case the current lawyer is allowed to work on, or null.
     * Enforces firm ownership (session firm) AND branch visibility.
     */
    function ai_copilot_case(PDO $pdo, int $caseId): ?array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return null;
        $stmt = $pdo->prepare('SELECT * FROM cases WHERE id=? AND firm_id=? LIMIT 1');
        $stmt->execute([$caseId, $firmId]);
        $case = $stmt->fetch();
        if (!$case) return null;
        if (!branch_access_can_access_branch($pdo, $case['branch_id'] !== null ? (int)$case['branch_id'] : null)) return null;
        return $case;
    }
}

if (!function_exists('ai_copilot_verified_chronology')) {
    /** Verified timeline assembled from stored records only. */
    function ai_copilot_verified_chronology(PDO $pdo, int $caseId): array {
        $events = [];
        try {
            $stmt = $pdo->prepare('SELECT event, event_date, created_at FROM case_timeline WHERE case_id=? ORDER BY event_date');
            $stmt->execute([$caseId]);
            foreach ($stmt->fetchAll() as $r) {
                $events[] = ['date' => $r['event_date'] ?: substr((string)$r['created_at'], 0, 10), 'event' => $r['event'], 'source' => 'timeline'];
            }
        } catch (PDOException $e) { /* table may be empty */ }
        try {
            $stmt = $pdo->prepare('SELECT description, occurred_at FROM case_facts WHERE case_id=? ORDER BY occurred_at');
            $stmt->execute([$caseId]);
            foreach ($stmt->fetchAll() as $r) {
                $events[] = ['date' => $r['occurred_at'] ?: null, 'event' => $r['description'], 'source' => 'fact'];
            }
        } catch (PDOException $e) {}
        try {
            $stmt = $pdo->prepare("SELECT title, event_at, event_type FROM legal_calendar_events WHERE case_id=? AND status<>'cancelled' ORDER BY event_at");
            $stmt->execute([$caseId]);
            foreach ($stmt->fetchAll() as $r) {
                $events[] = ['date' => substr((string)$r['event_at'], 0, 10), 'event' => $r['title'], 'source' => 'hearing', 'type' => $r['event_type']];
            }
        } catch (PDOException $e) {}
        usort($events, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        return $events;
    }
}

if (!function_exists('ai_copilot_verified_facts')) {
    function ai_copilot_verified_facts(PDO $pdo, int $caseId): array {
        $out = [];
        try {
            $stmt = $pdo->prepare('SELECT description, occurred_at, source_note FROM case_facts WHERE case_id=? ORDER BY occurred_at');
            $stmt->execute([$caseId]);
            $out = $stmt->fetchAll();
        } catch (PDOException $e) {}
        return $out;
    }
}

if (!function_exists('ai_copilot_verified_parties')) {
    function ai_copilot_verified_parties(PDO $pdo, int $caseId): array {
        try {
            $stmt = $pdo->prepare('SELECT party_type, name, role_description FROM case_parties WHERE case_id=?');
            $stmt->execute([$caseId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

if (!function_exists('ai_copilot_missing_documents')) {
    /**
     * Compare a standard evidence checklist against what the case already holds. The
     * checklist is a factual gap analysis, not an AI claim.
     */
    function ai_copilot_missing_documents(PDO $pdo, int $caseId): array {
        $expected = [
            'national_id' => ['ar' => 'صورة الهوية / السجل التجاري', 'en' => 'ID / commercial register copy'],
            'contract' => ['ar' => 'العقد أو الاتفاقية', 'en' => 'Contract / agreement'],
            'correspondence' => ['ar' => 'المراسلات', 'en' => 'Correspondence'],
            'evidence' => ['ar' => 'المستندات المؤيدة', 'en' => 'Supporting evidence'],
            'power_of_attorney' => ['ar' => 'وكالة المحاماة', 'en' => 'Power of attorney'],
            'invoice_receipt' => ['ar' => 'الفواتير / الإيصالات', 'en' => 'Invoices / receipts'],
        ];
        $present = [];
        try {
            $stmt = $pdo->prepare("SELECT LOWER(file_type) ft, LOWER(original_name) nm FROM documents WHERE case_id=?");
            $stmt->execute([$caseId]);
            foreach ($stmt->fetchAll() as $d) { $present[] = $d['ft'] . ' ' . $d['nm']; }
        } catch (PDOException $e) {}
        try {
            $stmt = $pdo->prepare('SELECT LOWER(title) t, LOWER(description) d FROM case_evidence WHERE case_id=?');
            $stmt->execute([$caseId]);
            foreach ($stmt->fetchAll() as $d) { $present[] = $d['t'] . ' ' . $d['d']; }
        } catch (PDOException $e) {}
        $haystack = mb_strtolower(implode(' | ', $present), 'UTF-8');

        $missing = []; $found = [];
        $keywords = [
            'national_id' => ['هوي', 'سجل', 'id', 'register'],
            'contract' => ['عقد', 'اتفاق', 'contract', 'agreement'],
            'correspondence' => ['مراسل', 'خطاب', 'بريد', 'letter', 'correspond'],
            'evidence' => ['دليل', 'مستند', 'إثبات', 'evidence', 'proof'],
            'power_of_attorney' => ['وكال', 'power of attorney', 'poa'],
            'invoice_receipt' => ['فاتور', 'إيصال', 'invoice', 'receipt'],
        ];
        foreach ($expected as $key => $labels) {
            $hit = false;
            foreach ($keywords[$key] as $kw) { if ($kw !== '' && mb_strpos($haystack, $kw) !== false) { $hit = true; break; } }
            if ($hit) $found[] = ['key' => $key, 'label_ar' => $labels['ar'], 'label_en' => $labels['en']];
            else $missing[] = ['key' => $key, 'label_ar' => $labels['ar'], 'label_en' => $labels['en']];
        }
        return ['missing' => $missing, 'found' => $found];
    }
}

if (!function_exists('ai_copilot_verified_hearings')) {
    function ai_copilot_verified_hearings(PDO $pdo, int $caseId): array {
        try {
            $stmt = $pdo->prepare('SELECT title, event_type, event_at, description, status FROM legal_calendar_events
                                   WHERE case_id=? AND status=? ORDER BY event_at');
            $stmt->execute([$caseId, 'upcoming']);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

if (!function_exists('ai_copilot_deterministic_flags')) {
    /** Rule-based risk markers derived from stored fields — not AI guesses. */
    function ai_copilot_deterministic_flags(PDO $pdo, array $case): array {
        $flags = [];
        try {
            $stmt = $pdo->prepare('SELECT title, likelihood, impact, status FROM case_risks WHERE case_id=?');
            $stmt->execute([(int)$case['id']]);
            foreach ($stmt->fetchAll() as $r) {
                $flags[] = ['code' => 'case_risk', 'severity' => in_array(strtolower((string)$r['impact']), ['high', 'critical'], true) ? 'high' : 'medium',
                            'detail' => $r['title']];
            }
        } catch (PDOException $e) {}
        $openConflict = conflict_open_count_safe($pdo, (int)$case['id']);
        if ($openConflict > 0) {
            $flags[] = ['code' => 'open_conflict', 'severity' => 'high', 'detail' => 'توجد مراجعة تعارض غير محسومة لهذه القضية'];
        }
        if (empty($case['description'])) {
            $flags[] = ['code' => 'missing_description', 'severity' => 'low', 'detail' => 'وصف القضية فارغ'];
        }
        return $flags;
    }
}

if (!function_exists('conflict_open_count_safe')) {
    function conflict_open_count_safe(PDO $pdo, int $caseId): int {
        try {
            if (!function_exists('conflict_case_has_open')) require_once __DIR__ . '/conflict_engine.php';
            return function_exists('conflict_case_has_open') ? conflict_case_has_open($pdo, $caseId) : 0;
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('ai_copilot_ai_settings')) {
    /** Load platform AI settings for the gateway (same shape used everywhere else). */
    function ai_copilot_ai_settings(PDO $pdo): array {
        $settings = [];
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
        foreach ($stmt->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
        return $settings;
    }
}

if (!function_exists('ai_copilot_run')) {
    /**
     * Execute one copilot task for a case.
     *
     * @return array{ok:bool,kind:string,verified:array,suggested:?string,review_status:string,
     *               local_fallback:bool,denied:bool,error:?string,run_id:?int}
     */
    function ai_copilot_run(PDO $pdo, int $caseId, string $kind, bool $isArabic = true, array $extra = []): array {
        $case = ai_copilot_case($pdo, $caseId);
        $fail = fn(string $err) => ['ok' => false, 'kind' => $kind, 'verified' => [], 'suggested' => null,
                                    'review_status' => 'failed', 'local_fallback' => false, 'denied' => false, 'error' => $err, 'run_id' => null];
        if (!$case) return $fail('case_not_found_or_forbidden');
        if (!in_array($kind, ai_copilot_kinds(), true)) return $fail('bad_kind');

        $lawyerId = (int)(tenant_current($pdo)['lawyer_id'] ?? 0);
        $firmId = (int)$case['firm_id'];

        // ---- verified side: database facts, no model involved ----
        $verified = [];
        switch ($kind) {
            case 'chronology':
                $verified = ['events' => ai_copilot_verified_chronology($pdo, $caseId)];
                break;
            case 'facts':
                $verified = ['facts' => ai_copilot_verified_facts($pdo, $caseId), 'parties' => ai_copilot_verified_parties($pdo, $caseId)];
                break;
            case 'missing_documents':
                $verified = ai_copilot_missing_documents($pdo, $caseId);
                break;
            case 'hearing_prep':
                $verified = ['hearings' => ai_copilot_verified_hearings($pdo, $caseId), 'parties' => ai_copilot_verified_parties($pdo, $caseId)];
                break;
            case 'risk_flags':
                $verified = ['flags' => ai_copilot_deterministic_flags($pdo, $case),
                             'case' => ['id' => (int)$case['id'], 'title' => $case['title']]];
                break;
            case 'summary':
            case 'research':
            case 'document_analysis':
            case 'draft':
                $verified = ['case' => ['id' => (int)$case['id'], 'title' => $case['title'], 'case_type' => $case['case_type'], 'status' => $case['status']]];
                break;
        }

        // ---- suggested side: model output, always labelled ----
        $settings = ai_copilot_ai_settings($pdo);
        $tenant = ai_gateway_tenant_context($pdo, 'lawyer', $lawyerId, tenant_current($pdo));
        $system = ai_gateway_injection_guard($isArabic) . "\n" . ($isArabic
            ? 'أنت مساعد قانوني داخل مكتب محاماة في المملكة العربية السعودية. اعتمد فقط على الوقائع المعطاة. ميّز بوضوح بين ما هو مثبت وبين ما هو اقتراح. لا تخترع نصوصاً قانونية أو أرقام مواد.'
            : 'You are a legal assistant inside a Saudi law firm. Use only the facts supplied. Clearly separate established facts from suggestions. Never invent statutes or article numbers.');

        $contextText = ai_copilot_context_text($case, $verified, $isArabic);
        $prompt = ai_copilot_prompt($kind, $contextText, $isArabic, $extra);

        $res = ai_resilient_gateway_call(
            $pdo, $settings, $tenant, 'case_copilot_' . $kind, 'case_information',
            $lawyerId, 'lawyer', $system, $prompt, 1100, 0.2, null, true, $isArabic
        );

        $suggested = $res['ok'] ? (string)$res['text'] : ($res['local_fallback'] ? (string)$res['text'] : null);
        $reviewStatus = $res['ok'] ? 'pending_review' : ($res['local_fallback'] ? 'pending_review' : 'failed');
        $requiresReview = 1;

        $runId = ai_copilot_persist($pdo, $case, $kind, $settings, $verified, $suggested, $reviewStatus, $res['error'] ?? null, $requiresReview);

        // Citation verification: any law name the model cites must appear in the retrieved
        // sources or the firm's own records, otherwise it is flagged as unsupported.
        $citationOk = true; $unsupported = [];
        if ($suggested !== null && $suggested !== '') {
            [$citationOk, $unsupported] = ai_copilot_verify_citations($pdo, $suggested, $contextText);
            if (!$citationOk) ai_copilot_flag_unsupported($pdo, $runId, $unsupported);
        }

        return [
            'ok' => $res['ok'], 'kind' => $kind, 'verified' => $verified, 'suggested' => $suggested,
            'review_status' => $reviewStatus, 'local_fallback' => (bool)$res['local_fallback'],
            'denied' => (bool)$res['denied'], 'error' => $res['error'] ?? null, 'run_id' => $runId,
            'citation_integrity_ok' => $citationOk, 'unsupported_citations' => $unsupported,
        ];
    }
}

if (!function_exists('ai_copilot_context_text')) {
    function ai_copilot_context_text(array $case, array $verified, bool $isArabic): string {
        $lines = [];
        $lines[] = ($isArabic ? 'عنوان القضية: ' : 'Case title: ') . (string)$case['title'];
        $lines[] = ($isArabic ? 'النوع: ' : 'Type: ') . (string)$case['case_type'];
        $lines[] = ($isArabic ? 'الحالة: ' : 'Status: ') . (string)$case['status'];
        if (!empty($case['description'])) $lines[] = ($isArabic ? 'الوصف: ' : 'Description: ') . (string)$case['description'];
        foreach ($verified['events'] ?? [] as $e) $lines[] = '[' . ($e['date'] ?? '?') . '] ' . $e['event'];
        foreach ($verified['facts'] ?? [] as $f) $lines[] = ($isArabic ? 'واقعة: ' : 'Fact: ') . (string)$f['description'];
        foreach ($verified['parties'] ?? [] as $p) $lines[] = ($isArabic ? 'طرف: ' : 'Party: ') . $p['party_type'] . ' - ' . $p['name'];
        foreach ($verified['hearings'] ?? [] as $h) $lines[] = ($isArabic ? 'جلسة: ' : 'Hearing: ') . $h['title'] . ' @ ' . $h['event_at'];
        foreach ($verified['missing'] ?? [] as $m) $lines[] = ($isArabic ? 'مستند ناقص: ' : 'Missing document: ') . $m['label_ar'];
        foreach ($verified['flags'] ?? [] as $fl) $lines[] = ($isArabic ? 'مؤشر: ' : 'Flag: ') . $fl['detail'];
        // Untrusted-record guard: everything above came from the database and could contain
        // hostile text, so it is wrapped rather than pasted as instructions.
        return ai_guard_untrusted('case-record', implode("\n", $lines));
    }
}

if (!function_exists('ai_copilot_prompt')) {
    function ai_copilot_prompt(string $kind, string $context, bool $isArabic, array $extra): string {
        $tasks = $isArabic ? [
            'summary' => 'اكتب ملخصاً موجزاً للقضية في فقرة واحدة ثم ثلاث نقاط رئيسية.',
            'research' => 'اقترح خطوط البحث القانوني المناسبة مع الإشارة إلى نوع المصادر المطلوبة، دون اختراع أرقام مواد.',
            'document_analysis' => 'حلّل المستندات المذكورة ولخّص ما يهم القضية وما ينقص.',
            'draft' => 'اكتب مسودة أولية مناسبة سيُراجعها محامٍ قبل الاستخدام.',
            'hearing_prep' => 'جهّز قائمة تحضير للجلسة: النقاط، المستندات، والأسئلة المحتملة.',
            'chronology' => 'رتّب الوقائع زمنياً وأشر إلى أي فجوات زمنية.',
            'facts' => 'استخرج الوقائع الجوهرية وميّز الثابت عن المحتمل.',
            'missing_documents' => 'اذكر المستندات الناقصة وأثرها المحتمل.',
            'risk_flags' => 'اذكر المخاطر القانونية المحتملة ودرجة أهميتها.',
        ] : [
            'summary' => 'Write a concise case summary in one paragraph then three key points.',
            'research' => 'Suggest legal research directions and the source types needed, without inventing article numbers.',
            'document_analysis' => 'Analyse the listed documents, summarise what matters and what is missing.',
            'draft' => 'Produce a first-draft document for a lawyer to review before use.',
            'hearing_prep' => 'Prepare a hearing checklist: points, documents and likely questions.',
            'chronology' => 'Order the events chronologically and note any gaps.',
            'facts' => 'Extract the material facts and mark established vs probable.',
            'missing_documents' => 'List the missing documents and their likely impact.',
            'risk_flags' => 'List the likely legal risks and their importance.',
        ];
        $task = $tasks[$kind] ?? ($isArabic ? 'قدّم تحليلاً مفيداً.' : 'Provide a useful analysis.');
        $tail = $isArabic ? "\n\nفي النهاية أضف قسماً بعنوان «يحتاج تأكيد المحامي» يسرد كل ما هو استنتاجي." : "\n\nEnd with a section titled \"Needs lawyer confirmation\" listing everything inferential.";
        if (!empty($extra['draft_type'])) $tail .= $isArabic ? "\nنوع المسودة المطلوب: " . $extra['draft_type'] : "\nDraft type: " . $extra['draft_type'];
        return ($isArabic ? "المهمة: " : "Task: ") . $task . "\n\n" . ($isArabic ? "الوقائع المتاحة:\n" : "Available facts:\n") . $context . $tail;
    }
}

if (!function_exists('ai_copilot_verify_citations')) {
    /**
     * Verify that law/article names the model cites actually exist in the retrieved
     * sources or firm records. Returns [ok, unsupportedNames]. Reuses the platform's
     * `citation_matches_sources()` so one rule governs all legal AI surfaces.
     *
     * The extraction is conservative: only quoted names and "قانون/المادة N" forms are
     * checked, so ordinary prose does not drown the check in false positives.
     */
    function ai_copilot_verify_citations(PDO $pdo, string $answer, string $contextText): array {
        $candidates = [];
        if (preg_match_all('/«([^»]{3,120})»/u', $answer, $m)) $candidates = array_merge($candidates, $m[1]);
        if (preg_match_all('/"([^"]{3,120})"/u', $answer, $m2)) $candidates = array_merge($candidates, $m2[1]);
        if (preg_match_all('/((?:قانون|مرسوم|قرار)\s+[\p{Arabic}\s]{3,60})/u', $answer, $m3)) {
            foreach ($m3[1] as $c) $candidates[] = trim($c);
        }

        // Ground truth: the case context plus the firm's law library names for this query.
        $sourceTexts = [$contextText];
        try {
            $stmt = $pdo->query('SELECT law_name_ar, law_name_en FROM law_articles LIMIT 500');
            foreach ($stmt->fetchAll() as $row) {
                if (!empty($row['law_name_ar'])) $sourceTexts[] = $row['law_name_ar'];
                if (!empty($row['law_name_en'])) $sourceTexts[] = $row['law_name_en'];
            }
        } catch (PDOException $e) {}

        $unsupported = [];
        foreach (array_unique($candidates) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (!citation_matches_sources($c, $sourceTexts)) $unsupported[] = $c;
        }
        return [count($unsupported) === 0, array_values(array_unique($unsupported))];
    }
}

if (!function_exists('ai_copilot_persist')) {
    function ai_copilot_persist(PDO $pdo, array $case, string $kind, array $settings, array $verified, ?string $suggested, string $status, ?string $error, int $requiresReview): int {
        try {
            $stmt = $pdo->prepare('INSERT INTO ai_copilot_runs
                (firm_id, case_id, lawyer_id, kind, provider, model, verified_json, suggested_json, status,
                 requires_review, error_message, branch_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                (int)$case['firm_id'], (int)$case['id'], (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null,
                $kind, (string)($settings['ai_provider'] ?? '') ?: null, (string)($settings['ai_model'] ?? '') ?: null,
                json_encode($verified, JSON_UNESCAPED_UNICODE), $suggested !== null ? json_encode(['text' => $suggested], JSON_UNESCAPED_UNICODE) : null,
                $status, $requiresReview, $error !== null ? mb_substr($error, 0, 500, 'UTF-8') : null,
                $case['branch_id'] !== null ? (int)$case['branch_id'] : null,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('Copilot persist failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('ai_copilot_flag_unsupported')) {
    /** Record a failed citation check into the existing answer-review table. */
    function ai_copilot_flag_unsupported(PDO $pdo, int $runId, array $unsupported): void {
        try {
            $stmt = $pdo->prepare('UPDATE ai_copilot_runs SET status=? WHERE id=?');
            $stmt->execute(['pending_review', $runId]);
        } catch (PDOException $e) {}
        log_action($pdo, 'استشهادات غير مؤكدة في مساعد القضية: ' . implode('، ', array_slice($unsupported, 0, 5)), 'ai_copilot', $runId);
    }
}

if (!function_exists('ai_copilot_review')) {
    /** A lawyer approves or rejects a copilot run. Human-in-the-loop gate. */
    function ai_copilot_review(PDO $pdo, int $runId, string $decision, ?string $note = null): bool {
        if (!in_array($decision, ['approved', 'rejected'], true)) return false;
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return false;
        $stmt = $pdo->prepare('UPDATE ai_copilot_runs SET status=?, reviewed_by_lawyer_id=?, reviewed_at=NOW()
                               WHERE id=? AND firm_id=?');
        $stmt->execute([$decision, (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null, $runId, $firmId]);
        if ($stmt->rowCount() < 1) return false;
        log_action($pdo, 'مراجعة مخرجات مساعد القضية: ' . $decision, 'ai_copilot', $runId);
        return true;
    }
}

if (!function_exists('ai_copilot_run_get')) {
    function ai_copilot_run_get(PDO $pdo, int $runId): ?array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return null;
        $stmt = $pdo->prepare('SELECT * FROM ai_copilot_runs WHERE id=? AND firm_id=? LIMIT 1');
        $stmt->execute([$runId, $firmId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if (!branch_access_can_access_branch($pdo, $row['branch_id'] !== null ? (int)$row['branch_id'] : null)) return null;
        $row['verified'] = $row['verified_json'] ? (json_decode($row['verified_json'], true) ?: []) : [];
        $row['suggested'] = $row['suggested_json'] ? (json_decode($row['suggested_json'], true) ?: []) : [];
        return $row;
    }
}

if (!function_exists('ai_copilot_runs_for_case')) {
    function ai_copilot_runs_for_case(PDO $pdo, int $caseId): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $stmt = $pdo->prepare('SELECT id, kind, status, requires_review, provider, model, created_at
                               FROM ai_copilot_runs WHERE firm_id=? AND case_id=? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([$firmId, $caseId]);
        return $stmt->fetchAll();
    }
}
