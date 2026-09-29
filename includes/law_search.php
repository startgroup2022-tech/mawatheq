<?php
require_once __DIR__ . '/semantic_search.php';
/**
 * بحث في مكتبة الأنظمة (law_articles): يحاول أولاً فهرس البحث النصي الكامل
 * (FULLTEXT) للحصول على ترتيب أدق حسب الصلة، وإن لم يتوفر الفهرس أو فشل
 * الاستعلام (قاعدة بيانات لم تُحدَّث بعد)، يرجع تلقائياً لبحث بالكلمات
 * المفتاحية (LIKE) مع ترتيب بسيط حسب عدد الكلمات المطابقة.
 *
 * فلاتر اختيارية بالمعامل $filters:
 *  - category_id: تقييد بتصنيف نظامي محدد
 *  - jurisdiction: تقييد بدولة/ولاية قضائية (عبر law_categories.jurisdiction)
 *  - verified_only: bool — استبعاد المواد غير الموثّقة (Source Verification)
 *  - date_from / date_to: 'YYYY-MM-DD' — يقيّد بتاريخ إضافة المادة (created_at)
 * بحث العبارة الحرفية (Exact Phrase Search): ضع الاستعلام بين علامتي اقتباس
 * "..." ليُعامَل كعبارة واحدة كاملة بدل تقسيمه لكلمات.
 */
function search_law_articles(string $query, int $limit = 20, array $filters = []): array {
    global $pdo;
    $query = trim($query);
    if ($query === '') return [];

    $exactPhrase = null;
    if (preg_match('/^"(.+)"$/u', $query, $m) || preg_match('/^“(.+)”$/u', $query, $m)) {
        $exactPhrase = trim($m[1]);
    }

    $filterSql = ''; $filterParams = [];
    if (!empty($filters['category_id'])) { $filterSql .= ' AND la.category_id=?'; $filterParams[] = (int)$filters['category_id']; }
    if (!empty($filters['jurisdiction'])) { $filterSql .= ' AND lc.jurisdiction=?'; $filterParams[] = $filters['jurisdiction']; }
    if (!empty($filters['verified_only'])) { $filterSql .= " AND la.verification_status='verified'"; }
    if (!empty($filters['date_from'])) { $filterSql .= ' AND la.created_at>=?'; $filterParams[] = $filters['date_from'] . ' 00:00:00'; }
    if (!empty($filters['date_to'])) { $filterSql .= ' AND la.created_at<=?'; $filterParams[] = $filters['date_to'] . ' 23:59:59'; }

    if ($exactPhrase !== null) {
        // عبارة حرفية: تطابق تام للنص الفرعي بدون تقسيم كلمات ولا FULLTEXT.
        $like = '%' . $exactPhrase . '%';
        $sql = "SELECT la.*, lc.name_ar AS cat_name_ar, lc.name_en AS cat_name_en FROM law_articles la
                JOIN law_categories lc ON lc.id = la.category_id
                WHERE (la.content_ar LIKE ? OR la.content_en LIKE ? OR la.law_name_ar LIKE ?)" . $filterSql . "
                LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$like, $like, $like], $filterParams));
        return $stmt->fetchAll();
    }

    // 1) محاولة البحث النصي الكامل (الأدق والأسرع)
    if (!$filterSql) {
        try {
            $stmt = $pdo->prepare("
                SELECT la.*, lc.name_ar AS cat_name_ar, lc.name_en AS cat_name_en,
                       MATCH(la.law_name_ar, la.content_ar, la.content_en) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
                FROM law_articles la JOIN law_categories lc ON lc.id = la.category_id
                WHERE MATCH(la.law_name_ar, la.content_ar, la.content_en) AGAINST (? IN NATURAL LANGUAGE MODE)
                ORDER BY relevance DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $query);
            $stmt->bindValue(2, $query);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (!empty($rows)) return $rows;
        } catch (Throwable $e) {
            // الفهرس غير موجود بعد (شغّل database/upgrade_v6.sql) — تابع للبديل بالأسفل
        }
    }

    // 2) بديل: بحث بالكلمات المفتاحية مع ترتيب بعدد التطابقات (يدعم الفلاتر أيضاً)
    $words = array_values(array_filter(preg_split('/[\s،,\.]+/u', $query), fn($w) => mb_strlen($w) >= 2));
    if (empty($words)) $words = [$query];

    $conditions = [];
    $params = [];
    foreach ($words as $w) {
        $conditions[] = '(la.law_name_ar LIKE ? OR la.content_ar LIKE ? OR la.content_en LIKE ? OR la.article_number LIKE ?)';
        $like = '%' . $w . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = "SELECT la.*, lc.name_ar AS cat_name_ar, lc.name_en AS cat_name_en FROM law_articles la
            JOIN law_categories lc ON lc.id = la.category_id
            WHERE (" . implode(' OR ', $conditions) . ")" . $filterSql . "
            LIMIT " . (int)($limit * 3); // نجلب أكثر ثم نرتب يدوياً بالأسفل
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $filterParams));
    $rows = $stmt->fetchAll();

    // ترتيب يدوي بعدد الكلمات المطابقة داخل كل مادة
    usort($rows, function ($a, $b) use ($words) {
        $score = fn($r) => array_sum(array_map(fn($w) => (mb_stripos($r['content_ar'] . ' ' . $r['content_en'] . ' ' . $r['law_name_ar'], $w) !== false) ? 1 : 0, $words));
        return $score($b) <=> $score($a);
    });

    $rows=array_slice($rows,0,$limit);
    if($exactPhrase===null&&count($rows)<$limit&&$pdo instanceof PDO){
        try {
            $semantic=search_law_articles_semantic($pdo,$query,$limit-count($rows),isset($filters['category_id'])?(int)$filters['category_id']:null,$filters);
            $seen=array_fill_keys(array_map(function($r){return (int)$r['id'];},$rows),true);
            foreach($semantic as $item)if(!isset($seen[(int)$item['id']])){$seen[(int)$item['id']]=true;$rows[]=$item;}
        }catch(Throwable $e){error_log('Semantic law listing unavailable: '.$e->getMessage());}
    }
    return array_slice($rows,0,$limit);
}

/**
 * يرجع أفضل مادة نظامية واحدة مطابقة لنص معين، أو null إن لم يوجد تطابق.
 * يدمج البحث بالكلمات المفتاحية مع البحث الدلالي (إن كان مزوّد المتجهات مفعّلاً)
 * ويفضّل الدلالي عندما يتجاوز عتبة الثقة SEMANTIC_MIN_SCORE ولا يوجد تطابق كلمات دقيق،
 * لأن السؤال قد يُصاغ بكلمات مختلفة عن نص المادة (Source Grounding أشمل).
 */
function find_best_law_match(string $text): ?array {
    global $pdo;
    $keywordResults = search_law_articles($text, 1, ['verified_only'=>true]);
    $keywordMatch = $keywordResults[0] ?? null;
    if ($keywordMatch) return $keywordMatch;
    if ($pdo instanceof PDO) {
        try {
            $semanticResults = search_law_articles_semantic($pdo, $text, 1);
            if ($semanticResults) return $semanticResults[0];
        } catch (Throwable $e) { error_log('Semantic law search unavailable: ' . $e->getMessage()); }
    }
    return null;
}

/** رابط قابل للنقر لعرض مادة نظامية محددة داخل مكتبة الأنظمة. */
function law_article_url(int $articleId): string {
    return '/laws.php?article=' . $articleId;
}
