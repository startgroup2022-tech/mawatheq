<?php
/**
 * البحث الدلالي (Semantic Search) — طبقة فوق knowledge_center.php و law_search.php.
 * تعمل فقط عند ضبط مزوّد متجهات (embedding_provider) من الإعدادات؛ وإن لم يكن مضبوطاً
 * تُرجع الدوال هنا مصفوفة فارغة بهدوء ليستمر الاستدعاء الأصلي بالبحث بالكلمات المفتاحية —
 * لا يتعطل شيء أبداً بغياب مفتاح المتجهات (نفس فلسفة بقية المشروع).
 */
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/secret_crypto.php';
require_once __DIR__ . '/legal_chunker.php';

/** أقل درجة تشابه دلالي نعتبر بعدها المصدر "ذا صلة كافية" — يُستخدم أيضاً لكشف عدم وجود سند كافٍ (جزء من Hallucination Detection). */
const SEMANTIC_MIN_SCORE = 0.72;

function cosine_similarity(array $a,array $b): float {
    if(!$a||count($a)!==count($b))return 0.0;
    $dot=0.0;$normA=0.0;$normB=0.0;
    foreach($a as $i=>$v){$x=(float)$v;$y=(float)$b[$i];$dot+=$x*$y;$normA+=$x*$x;$normB+=$y*$y;}
    if($normA<=0||$normB<=0)return 0.0;
    return $dot/(sqrt($normA)*sqrt($normB));
}

/** يولّد متجه نص واحد (سؤال المستخدم مثلاً) عبر مزوّد المتجهات المفعّل، أو null إن لم يكن مضبوطاً/فشل. */
function embedding_for_query(PDO $pdo, string $text): ?array {
    $settings = embedding_load_settings($pdo);
    if (!embedding_provider_configured($settings)) return null;
    $result = ai_provider_embeddings($settings, [$text]);
    if (!$result['ok'] || empty($result['vectors'][0])) return null;
    return $result['vectors'][0];
}

/**
 * يفهرس متجهات كل مقاطع (chunks) مصدر معرفي واحد لم تُفهرَس بعد.
 * يُستدعى بعد knowledge_index_text() ولا يفشل الاستدعاء الأصلي حتى إن فشلت المتجهات.
 */
function knowledge_index_embeddings(PDO $pdo, int $sourceId): void {
    $settings = embedding_load_settings($pdo);
    if (!embedding_provider_configured($settings)) {
        $pdo->prepare("UPDATE knowledge_sources SET embedding_status='not_configured' WHERE id=?")->execute([$sourceId]);
        return;
    }
    $pdo->prepare("UPDATE knowledge_sources SET embedding_status='pending' WHERE id=?")->execute([$sourceId]);
    $stmt = $pdo->prepare('SELECT id,chunk_text FROM knowledge_chunks WHERE source_id=? AND embedding IS NULL ORDER BY chunk_order');
    $stmt->execute([$sourceId]);
    $rows = $stmt->fetchAll();
    if (!$rows) { $pdo->prepare("UPDATE knowledge_sources SET embedding_status='indexed' WHERE id=?")->execute([$sourceId]); return; }

    $failed = false;
    foreach (array_chunk($rows, 32) as $batch) {
        $texts = array_map(fn($r) => $r['chunk_text'], $batch);
        $result = ai_provider_embeddings($settings, $texts);
        if (!$result['ok']) { $failed = true; error_log('Embedding indexing failed for source ' . $sourceId . ': ' . ($result['error'] ?? '')); break; }
        $update = $pdo->prepare('UPDATE knowledge_chunks SET embedding=?, embedding_model=? WHERE id=?');
        foreach ($batch as $i => $row) $update->execute([json_encode($result['vectors'][$i]), $result['model'], $row['id']]);
    }
    $pdo->prepare('UPDATE knowledge_sources SET embedding_status=? WHERE id=?')->execute([$failed ? 'failed' : 'indexed', $sourceId]);
    knowledge_log($pdo, $sourceId, 'embedding_indexing', $failed ? 'failed' : 'completed', $failed ? 'Embedding generation failed.' : ('Embedded ' . count($rows) . ' chunk(s).'));
}

/**
 * Per-source grants (knowledge_source_permissions) — additive ACL layer.
 *
 * The scope_type/scope_id model decides *reach* (which sources an actor can see at all).
 * The grants table can only *narrow* that reach, never widen it: a source that the scope
 * model hides stays hidden even with a matching grant row. This keeps the scope model
 * authoritative and means enabling the ACL is behavior-preserving until rows exist.
 *
 * Activation is opt-in and observable: initialize the ACL (one row per visible source)
 * by calling knowledge_acl_initialize(); until that has happened, retrieval behaves
 * exactly as before. Giving an actor an empty ACL is treated as "not yet customized"
 * (full scope reach), not "denied everything" — safer for existing accounts.
 */

/** Whether the ACL layer has been activated (at least one grant row exists). */
function knowledge_permissions_initialized(PDO $pdo): bool {
    try {
        return ((int)$pdo->query('SELECT COUNT(*) FROM knowledge_source_permissions')->fetchColumn()) > 0;
    } catch (PDOException $e) {
        error_log('Knowledge ACL probe failed, falling back to scope model: ' . $e->getMessage());
        return false;
    }
}

/**
 * SQL fragment that keeps only sources permitted for the actor when the ACL is active.
 * Returns an empty string when the ACL is inactive, so the scope model alone decides.
 */
function knowledge_permission_sql(PDO $pdo, string $actorType, int $actorId, string $sourceExpr = 's.id'): string {
    if (!knowledge_permissions_initialized($pdo)) return '';
    // Ungoverned (no grants) OR any matching client/lawyer/firm grant. The scope model that
    // this fragment is AND-ed onto still gates reach, so a grant can never expose a
    // scope-hidden source. Admin grants are intentionally not consulted here: admins use the
    // admin surface, not retrieval, so an admin grant must never widen client/lawyer reach.
    return " AND (
                 NOT EXISTS (SELECT 1 FROM knowledge_source_permissions p WHERE p.source_id=$sourceExpr AND p.permission='read')
              OR EXISTS (SELECT 1 FROM knowledge_source_permissions p3 WHERE p3.source_id=$sourceExpr AND p3.permission='read' AND p3.subject_type='firm' AND EXISTS (SELECT 1 FROM law_firm_members fm2 WHERE fm2.firm_id=p3.subject_id AND fm2.lawyer_id=?))
              OR EXISTS (SELECT 1 FROM knowledge_source_permissions p4 WHERE p4.source_id=$sourceExpr AND p4.permission='read' AND p4.subject_type=? AND p4.subject_id=?)
            )";
}

/** Bind values for knowledge_permission_sql() in placeholder order; empty when the ACL is inactive. */
function knowledge_permission_params(PDO $pdo, string $actorType, int $actorId): array {
    return knowledge_permissions_initialized($pdo) ? [$actorId, $actorType, $actorId] : [];
}

/**
 * Idempotently seed one 'read' grant per currently-visible source for every subject that
 * the scope model already grants reach to. Running this activates the ACL layer with zero
 * behavior change; administrators can then remove individual grants to restrict access.
 * Returns the number of grant rows inserted.
 */
function knowledge_acl_initialize(PDO $pdo): int {
    $inserted = 0;
    $sources = [];
    $stmt = $pdo->query("SELECT id,scope_type,scope_id,created_by_type,created_by,module_id FROM knowledge_sources WHERE status='active'");
    foreach ($stmt->fetchAll() as $s) $sources[(int)$s['id']] = $s;

    $grant = $pdo->prepare('INSERT IGNORE INTO knowledge_source_permissions (source_id,subject_type,subject_id,permission) VALUES (?,?,?,?)');
    $lawyerIds = array_map('intval', $pdo->query('SELECT id FROM lawyers')->fetchAll(PDO::FETCH_COLUMN));
    $firmOf = [];
    foreach ($pdo->query('SELECT firm_id,lawyer_id FROM law_firm_members')->fetchAll() as $fm) $firmOf[(int)$fm['lawyer_id']] = (int)$fm['firm_id'];

    foreach ($sources as $id => $s) {
        if ($s['scope_type'] === 'global') {
            foreach ($lawyerIds as $lid) { $grant->execute([$id, 'lawyer', $lid, 'read']); $inserted += $grant->rowCount(); }
            foreach ($pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $grant->execute([$id, 'client', (int)$uid, 'read']); $inserted += $grant->rowCount();
            }
        } elseif ($s['scope_type'] === 'firm') {
            foreach ($lawyerIds as $lid) {
                if (($firmOf[$lid] ?? null) !== (int)$s['scope_id']) continue;
                $grant->execute([$id, 'firm', (int)$s['scope_id'], 'read']); $inserted += $grant->rowCount();
                $grant->execute([$id, 'lawyer', $lid, 'read']); $inserted += $grant->rowCount();
            }
        } elseif ($s['scope_type'] === 'private') {
            $subject = $s['created_by_type'] === 'lawyer' ? 'lawyer' : 'client';
            if ($s['created_by'] !== null) { $grant->execute([$id, $subject, (int)$s['created_by'], 'read']); $inserted += $grant->rowCount(); }
        } elseif ($s['scope_type'] === 'case') {
            $caseOwner = $pdo->prepare('SELECT lawyer_id,user_id FROM cases WHERE id=?');
            $caseOwner->execute([(int)$s['scope_id']]);
            if ($c = $caseOwner->fetch()) {
                if (!empty($c['lawyer_id'])) { $grant->execute([$id, 'lawyer', (int)$c['lawyer_id'], 'read']); $inserted += $grant->rowCount(); }
                if (!empty($c['user_id'])) { $grant->execute([$id, 'client', (int)$c['user_id'], 'read']); $inserted += $grant->rowCount(); }
            }
        }
    }
    return $inserted;
}

/**
 * بحث دلالي في knowledge_chunks — نفس فلترة الصلاحيات المستخدمة في knowledge_retrieve()
 * (module/scope/case/firm) لكن الترتيب بالتشابه الدلالي (جيب التمام) بدل تقاطع الكلمات.
 * يُرجع مصفوفة فارغة بهدوء إن لم يوجد مزوّد متجهات مفعّل أو لا توجد متجهات مفهرسة بعد.
 */
function knowledge_semantic_search(PDO $pdo, string $query, string $moduleId, string $actorType, int $actorId, ?int $caseId = null, bool $verifiedOnly = false, int $limit = 4, array $filters = []): array {
    $query = trim($query);
    if ($query === '' || !in_array($actorType, ['client', 'lawyer'], true)) return [];
    if (!knowledge_permissions_initialized($pdo)) return [];
    $queryVector = embedding_for_query($pdo, $query);
    if (!$queryVector) return [];

    $caseAllowed = false;
    if ($caseId) {
        $ownerColumn = $actorType === 'lawyer' ? 'lawyer_id' : 'user_id';
        $check = $pdo->prepare('SELECT id FROM cases WHERE id=? AND ' . $ownerColumn . '=?');
        $check->execute([$caseId, $actorId]); $caseAllowed = (bool)$check->fetchColumn();
    }

    $sql = "SELECT s.id,s.source_name,s.source_url,s.version,s.source_type,s.verification_status,s.jurisdiction,s.category,s.created_at,s.module_id,k.chunk_order,k.chunk_text,k.embedding" . legal_chunk_provenance_select($pdo) . "
            FROM knowledge_chunks k JOIN knowledge_sources s ON s.id=k.source_id
            WHERE s.status='active' AND s.processing_status='completed' AND k.embedding IS NOT NULL AND s.module_id IN (?,'*')";
    $params = [$moduleId];
    if ($verifiedOnly) $sql .= " AND s.verification_status='verified'";
    if(!empty($filters['source_type'])&&in_array($filters['source_type'],['file','manual','website','external_api','database'],true)){$sql.=' AND s.source_type=?';$params[]=$filters['source_type'];}
    if(!empty($filters['jurisdiction'])){$sql.=' AND s.jurisdiction=?';$params[]=(string)$filters['jurisdiction'];}
    if(!empty($filters['date_from'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_from'])){$sql.=' AND s.created_at>=?';$params[]=$filters['date_from'].' 00:00:00';}
    if(!empty($filters['date_to'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_to'])){$sql.=' AND s.created_at<=?';$params[]=$filters['date_to'].' 23:59:59';}
    $sql .= " AND (s.scope_type='global'";
    if ($actorType === 'lawyer') {
        $sql .= " OR (s.scope_type='firm' AND EXISTS (SELECT 1 FROM law_firm_members fm WHERE fm.firm_id=s.scope_id AND fm.lawyer_id=?))";
        $params[] = $actorId;
    }
    if ($caseAllowed) { $sql .= " OR (s.scope_type='case' AND s.scope_id=?)"; $params[] = $caseId; }
    $sql .= " OR (s.scope_type='private' AND s.created_by_type=? AND s.created_by=?) )";
    $params[] = $actorType; $params[] = $actorId;
    $permissionSql = knowledge_permission_sql($pdo, $actorType, $actorId, 's.id');
    if ($permissionSql !== '') $params = array_merge($params, knowledge_permission_params($pdo, $actorType, $actorId));
    $sql .= $permissionSql . " LIMIT 500";

    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $vec = json_decode($row['embedding'], true);
        $row['_score'] = is_array($vec) ? cosine_similarity($queryVector, $vec) : 0.0;
        unset($row['embedding']);
    }
    unset($row);
    usort($rows, fn($a, $b) => $b['_score'] <=> $a['_score']);

    $selected = []; $seen = [];
    foreach ($rows as $row) {
        if ($row['_score'] < SEMANTIC_MIN_SCORE || isset($seen[$row['id']])) continue;
        $seen[$row['id']] = true; $selected[] = $row;
        if (count($selected) >= $limit) break;
    }
    if ($selected) {
        try {
            $access = $pdo->prepare('INSERT INTO knowledge_access_logs (source_id,actor_type,actor_id,module_id,case_id,query_hash) VALUES (?,?,?,?,?,?)');
            foreach ($selected as $source) $access->execute([(int)$source['id'], $actorType, $actorId, $moduleId, $caseAllowed ? $caseId : null, hash('sha256', $query)]);
        } catch (PDOException $e) { error_log('Knowledge access logging failed: ' . $e->getMessage()); }
    }
    return $selected;
}

/** يفهرس متجه مادة نظامية واحدة (يدمج العربي والإنجليزي بنص واحد قبل التوليد). */
function law_article_index_embedding(PDO $pdo, array $settings, array $article): bool {
    $text = $article['law_name_ar'] . ' — ' . $article['content_ar'] . "\n\n" . $article['law_name_en'] . ' — ' . $article['content_en'];
    $result = ai_provider_embeddings($settings, [$text]);
    if (!$result['ok'] || empty($result['vectors'][0])) return false;
    $pdo->prepare('UPDATE law_articles SET embedding=?, embedding_model=? WHERE id=?')
        ->execute([json_encode($result['vectors'][0]), $result['model'], $article['id']]);
    return true;
}

/** يفهرس كل مواد النظام التي لا تملك متجهاً بعد — دفعة بحجم $batchSize لتفادي انتهاء مهلة الاستضافة المشتركة. */
function law_articles_index_embeddings_batch(PDO $pdo, int $batchSize = 40): array {
    $settings = embedding_load_settings($pdo);
    if (!embedding_provider_configured($settings)) return ['processed' => 0, 'failed' => 0, 'remaining' => 0, 'configured' => false];
    $stmt = $pdo->prepare('SELECT id,law_name_ar,law_name_en,content_ar,content_en FROM law_articles WHERE embedding IS NULL LIMIT ?');
    $stmt->bindValue(1, $batchSize, PDO::PARAM_INT); $stmt->execute();
    $rows = $stmt->fetchAll();
    $processed = 0; $failed = 0;
    foreach ($rows as $article) { law_article_index_embedding($pdo, $settings, $article) ? $processed++ : $failed++; }
    $remaining = (int)$pdo->query('SELECT COUNT(*) FROM law_articles WHERE embedding IS NULL')->fetchColumn();
    return ['processed' => $processed, 'failed' => $failed, 'remaining' => $remaining, 'configured' => true];
}

/** بحث دلالي في مكتبة الأنظمة (law_articles) — يكمّل search_law_articles() الكلاسيكي بالكلمات المفتاحية. */
function search_law_articles_semantic(PDO $pdo, string $query, int $limit = 10, ?int $categoryId = null, array $filters = []): array {
    $query = trim($query);
    if ($query === '') return [];
    $queryVector = embedding_for_query($pdo, $query);
    if (!$queryVector) return [];

    $sql = "SELECT la.*, lc.name_ar AS cat_name_ar, lc.name_en AS cat_name_en
            FROM law_articles la JOIN law_categories lc ON lc.id = la.category_id
            WHERE la.embedding IS NOT NULL AND la.verification_status='verified'";
    $params = [];
    if ($categoryId) { $sql .= ' AND la.category_id=?'; $params[] = $categoryId; }
    if(!empty($filters['jurisdiction'])){$sql.=' AND lc.jurisdiction=?';$params[]=(string)$filters['jurisdiction'];}
    if(!empty($filters['date_from'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_from'])){$sql.=' AND la.created_at>=?';$params[]=$filters['date_from'].' 00:00:00';}
    if(!empty($filters['date_to'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_to'])){$sql.=' AND la.created_at<=?';$params[]=$filters['date_to'].' 23:59:59';}
    if(!empty($filters['verified_only']))$sql.=" AND la.verification_status='verified'";
    $rows = $pdo->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll();

    foreach ($rows as &$row) {
        $vec = json_decode($row['embedding'], true);
        $row['relevance'] = is_array($vec) ? cosine_similarity($queryVector, $vec) : 0.0;
        unset($row['embedding']);
    }
    unset($row);
    usort($rows, fn($a, $b) => $b['relevance'] <=> $a['relevance']);
    $rows = array_values(array_filter($rows, fn($r) => $r['relevance'] >= SEMANTIC_MIN_SCORE));
    return array_slice($rows, 0, $limit);
}
