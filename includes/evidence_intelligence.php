<?php
/**
 * Evidence Intelligence — الأدلة الناقصة والمتعارضة.
 * يبني تقريراً يقارن سجل الأدلة الفعلي لقضية بادعاءاتها/وقائعها المسجّلة فقط (لا معرفة خارجية،
 * ولا اختراع أدلة). النتيجة استرشادية للمحامي، وليست إثباتاً قضائياً — نفس فلسفة بقية وحدات AI
 * في المشروع (Source Grounding + تنبيه صريح بضرورة المراجعة البشرية).
 */
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/ai_gateway.php';

/**
 * @return array{ok:bool, error?:string, conflicts?:array, missing?:array, summary?:string}
 */
function evidence_intelligence_analyze(PDO $pdo, array $settings, array $case, array $evidenceRows, array $claims, array $facts, bool $isArabic, array $gateway = []): array {
    if (!$evidenceRows) {
        return ['ok' => false, 'error' => $isArabic ? 'لا توجد أدلة مسجلة بعد لتحليلها.' : 'No evidence recorded yet to analyze.'];
    }
    $enabled = !empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0';
    if (!$enabled) {
        return ['ok' => false, 'error' => $isArabic ? 'فعّل مزوّد الذكاء الاصطناعي من إعدادات الإدارة أولاً.' : 'Enable an AI provider in admin settings first.'];
    }

    // نرسل فقط الحقول النصية لسجل الأدلة/الادعاءات/الوقائع — لا ملفات، لا بيانات عملاء حساسة زائدة.
    $payload = [
        'case' => ['title' => $case['title'] ?? '', 'type' => $case['case_type'] ?? '', 'status' => $case['status'] ?? ''],
        'claims_and_defenses' => array_map(fn($c) => ['id' => (int)$c['id'], 'description' => $c['description'] ?? ''], $claims),
        'facts' => array_map(fn($f) => ['id' => (int)$f['id'], 'description' => $f['description'] ?? '', 'occurred_at' => $f['occurred_at'] ?? null], $facts),
        'evidence_register' => array_map(fn($e) => [
            'id' => (int)$e['id'],
            'title' => $e['title'] ?? '',
            'type' => $e['evidence_type'] ?? '',
            'description' => $e['description'] ?? '',
            'ai_summary' => $e['ai_summary'] ?? null,
            'related_claim_id' => $e['related_claim_id'] ?? null,
            'related_fact_id' => $e['related_fact_id'] ?? null,
            'created_at' => $e['created_at'] ?? null,
        ], $evidenceRows),
    ];

    $systemPrompt = $isArabic
        ? 'أنت مساعد تحليل أدلة داخلي لمحامٍ. حلل حصراً سجل الأدلة والادعاءات والوقائع المرفق أدناه بصيغة JSON. لا تخترع أدلة أو وقائع غير مذكورة. مهمتك جزءان فقط:'
          . "\n1) conflicts: أزواج/مجموعات أدلة تتعارض مضامينها مع بعضها، أو دليل يتعارض مع واقعة مسجّلة — لكل تعارض اذكر evidence_ids المعنية ووصف التعارض بجملة أو جملتين."
          . "\n2) missing: أدلة يبدو أنها ناقصة استناداً فقط لما هو مذكور من ادعاءات/دفوع/وقائع (مثال: ادعاء دفع مبلغ بلا أي دليل دفع مرتبط) — لكل بند اذكر related_claim_id أو related_fact_id إن وجد ووصف الفجوة."
          . "\nأرجع JSON فقط بالمفاتيح: conflicts (مصفوفة كائنات {evidence_ids, description})، missing (مصفوفة كائنات {related_claim_id, related_fact_id, description})، summary (سطر أو سطران بالعربية). إن لم تجد شيئاً، أرجع مصفوفات فارغة. هذا تحليل استرشادي أولي يتطلب مراجعة المحامي، وليس إثباتاً قضائياً — لا تصغ النتائج كحقائق قطعية."
        : 'You are an internal evidence-analysis assistant for a lawyer. Analyze only the evidence/claims/facts register supplied below as JSON. Never invent evidence or facts not listed. Two tasks only:'
          . "\n1) conflicts: sets of evidence items whose content contradicts each other, or evidence that contradicts a recorded fact — for each, list the evidence_ids involved and a one/two-sentence description."
          . "\n2) missing: evidence that appears to be missing based only on the listed claims/defenses/facts (e.g. a claim of payment with no linked payment evidence) — for each, note related_claim_id or related_fact_id if applicable and describe the gap."
          . "\nReturn JSON only with keys: conflicts (array of {evidence_ids, description}), missing (array of {related_claim_id, related_fact_id, description}), summary (one or two sentences). Return empty arrays if nothing found. This is a preliminary, advisory analysis requiring lawyer review — do not phrase findings as definitive fact.";

    $userMessage = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $systemPrompt .= "\n" . ai_gateway_injection_guard($isArabic) . "\n";
    $tenant = $gateway['tenant'] ?? ai_gateway_tenant_context($pdo, 'lawyer', (int)($gateway['actor_id'] ?? 0));
    $response = ai_gateway_call($pdo, $settings, $tenant, 'evidence-ai', 'case_information', (int)($gateway['actor_id'] ?? 0), $gateway['actor_type'] ?? 'lawyer', $systemPrompt, $userMessage, 1800, null, 'evidence_intelligence');

    if (!$response['ok'] || !$response['text']) {
        return ['ok' => false, 'error' => $isArabic ? 'تعذّر تحليل الأدلة حالياً.' : 'Evidence analysis failed.'];
    }
    $clean = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($response['text'])));
    $data = json_decode($clean, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => $isArabic ? 'رد غير صالح من مزوّد الذكاء الاصطناعي.' : 'Invalid response from the AI provider.'];
    }

    $validEvidenceIds = array_map(fn($e) => (int)$e['id'], $evidenceRows);
    $conflicts = [];
    foreach ((is_array($data['conflicts'] ?? null) ? $data['conflicts'] : []) as $c) {
        if (!is_array($c)) continue;
        $ids = array_values(array_intersect(array_map('intval', (array)($c['evidence_ids'] ?? [])), $validEvidenceIds));
        if (count($ids) < 2) continue; // تعارض يحتاج دليلين على الأقل فعلاً موجودين بالسجل
        $conflicts[] = ['evidence_ids' => $ids, 'description' => trim((string)($c['description'] ?? ''))];
    }
    $missing = [];
    foreach ((is_array($data['missing'] ?? null) ? $data['missing'] : []) as $m) {
        if (!is_array($m) || trim((string)($m['description'] ?? '')) === '') continue;
        $missing[] = [
            'related_claim_id' => isset($m['related_claim_id']) && (int)$m['related_claim_id'] > 0 ? (int)$m['related_claim_id'] : null,
            'related_fact_id' => isset($m['related_fact_id']) && (int)$m['related_fact_id'] > 0 ? (int)$m['related_fact_id'] : null,
            'description' => trim((string)$m['description']),
        ];
    }

    return [
        'ok' => true,
        'conflicts' => $conflicts,
        'missing' => $missing,
        'summary' => trim((string)($data['summary'] ?? '')),
    ];
}
