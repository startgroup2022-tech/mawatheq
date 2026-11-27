<?php
/**
 * مكتبة القوالب القانونية — القراءة والكتابة والعرض.
 *
 * `document_templates` and `template_categories` existed with a complete schema but no
 * reader: only `includes/plan_features.php` mentioned the table, so a lawyer had no way
 * to see, use or create a template. This file is the single source for the template
 * catalogue, and the three surfaces (lawyer library, admin console, document generator)
 * all read it here so they cannot disagree about what a template is.
 *
 * Two scopes share one table:
 *   - `firm_id IS NULL`  → a platform template every firm may use (seeded by v81).
 *   - `firm_id = <id>`   → a template private to that firm.
 * A firm's own template with the same slug as a platform one wins, because the firm
 * customised it deliberately.
 *
 * Plan gating is `plan_tier`: a template marked `corporate` is not offered to a `free`
 * plan. The tier order lives in `template_tier_rank()` so the comparison happens in one
 * place. An unknown or absent plan is treated as `free` — the conservative direction,
 * and it matches how `plan_features.php` treats "no plan at all" for *limits* while
 * still refusing a paid *tier* benefit.
 */

require_once __DIR__ . '/tenant.php';

if (!function_exists('template_tier_rank')) {
    /** Order plan tiers so a comparison is a number, not a scattered in_array. */
    function template_tier_rank(string $tier): int {
        return match ($tier) {
            'corporate' => 3,
            'paid' => 2,
            'free' => 1,
            default => 0,
        };
    }
}

if (!function_exists('template_tier_for_firm')) {
    /**
     * The tier a firm is entitled to, read from its plan's own `classification`.
     *
     * `plans.classification` is an enum('free','paid','corporate') — the same vocabulary
     * as `document_templates.plan_tier`, so the comparison is direct and does not guess
     * from a price or a slug. A firm with no plan, or a hidden plan, gets `free`: it may
     * still use the free catalogue.
     */
    function template_tier_for_firm(PDO $pdo, ?int $firmId): string {
        if (!$firmId) return 'free';
        try {
            $stmt = $pdo->prepare(
                'SELECT p.classification FROM law_firms f
                   JOIN plans p ON p.id = f.plan_id
                  WHERE f.id = ? AND p.status = ? LIMIT 1'
            );
            $stmt->execute([$firmId, 'active']);
            $tier = (string)($stmt->fetchColumn() ?: '');
            return in_array($tier, ['free', 'paid', 'corporate'], true) ? $tier : 'free';
        } catch (PDOException $e) {
            return 'free';
        }
    }
}

if (!function_exists('template_can_use')) {
    /** Is this template within the tier the firm is entitled to? */
    function template_can_use(array $template, string $firmTier): bool {
        return template_tier_rank((string)($template['plan_tier'] ?? 'free'))
            <= template_tier_rank($firmTier);
    }
}

if (!function_exists('template_categories_all')) {
    function template_categories_all(PDO $pdo): array {
        try {
            $stmt = $pdo->query('SELECT * FROM template_categories WHERE is_active = 1 ORDER BY sort_order, id');
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('template_library_ready')) {
    /** Both tables must exist before any page reads them. */
    function template_library_ready(PDO $pdo): bool {
        try {
            $pdo->query('SELECT 1 FROM document_templates LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('template_list')) {
    /**
     * Templates visible to a firm: the platform catalogue plus that firm's own rows.
     *
     * A firm row with the same slug as a platform row replaces it, so a firm that
     * customised a template sees its version and not two entries.
     *
     * @return array<int, array>
     */
    function template_list(PDO $pdo, ?int $firmId, array $opts = []): array {
        $categoryId = isset($opts['category_id']) ? (int)$opts['category_id'] : 0;
        $search = trim((string)($opts['search'] ?? ''));
        $includeInactive = !empty($opts['include_inactive']);

        $sql = 'SELECT t.*, c.name_ar AS category_ar, c.name_en AS category_en, c.icon_key AS category_icon
                  FROM document_templates t
                  LEFT JOIN template_categories c ON c.id = t.category_id
                 WHERE (t.firm_id IS NULL' . ($firmId ? ' OR t.firm_id = ?' : '') . ')';
        $params = $firmId ? [$firmId] : [];

        if (!$includeInactive) $sql .= ' AND t.is_active = 1';
        if ($categoryId > 0) { $sql .= ' AND t.category_id = ?'; $params[] = $categoryId; }
        if ($search !== '') {
            $sql .= ' AND (t.title_ar LIKE ? OR t.title_en LIKE ? OR t.description_ar LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $sql .= ' ORDER BY c.sort_order, t.title_ar';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }

        // A firm's own template replaces the platform one that shares its slug.
        $bySlug = [];
        foreach ($rows as $row) {
            $slug = (string)$row['slug'];
            $isFirmRow = $row['firm_id'] !== null;
            if (!isset($bySlug[$slug]) || ($isFirmRow && $bySlug[$slug]['firm_id'] === null)) {
                $bySlug[$slug] = $row;
            }
        }
        return array_values($bySlug);
    }
}

if (!function_exists('template_get')) {
    /** One template, only if it is platform-wide or belongs to this firm. */
    function template_get(PDO $pdo, int $templateId, ?int $firmId): ?array {
        try {
            $stmt = $pdo->prepare(
                'SELECT t.*, c.name_ar AS category_ar, c.name_en AS category_en
                   FROM document_templates t
                   LEFT JOIN template_categories c ON c.id = t.category_id
                  WHERE t.id = ? AND (t.firm_id IS NULL' . ($firmId ? ' OR t.firm_id = ?' : '') . ')'
            );
            $stmt->execute($firmId ? [$templateId, $firmId] : [$templateId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('template_fields')) {
    /**
     * The dynamic fields a template asks for, as [{key,label_ar,label_en,type,required}].
     *
     * `fields_json` is free-form on disk because templates are authored, so a malformed
     * payload must degrade to "no fields" rather than break the generator page.
     */
    function template_fields(array $template): array {
        $raw = $template['fields_json'] ?? null;
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $i => $f) {
            if (!is_array($f)) continue;
            $key = (string)($f['key'] ?? '');
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) continue;
            $out[] = [
                'key' => $key,
                'label_ar' => (string)($f['label_ar'] ?? $key),
                'label_en' => (string)($f['label_en'] ?? $key),
                'type' => in_array($f['type'] ?? 'text', ['text', 'textarea', 'date', 'number'], true)
                    ? (string)$f['type'] : 'text',
                'required' => !empty($f['required']),
            ];
        }
        return $out;
    }
}

if (!function_exists('template_render')) {
    /**
     * Fill a template body. Every `{{key}}` is replaced; an unknown placeholder is left
     * visible rather than silently blanked, so a lawyer notices a missing field instead
     * of filing a document with a hole in it.
     */
    function template_render(array $template, array $values, bool $arabic = true): string {
        $body = $arabic ? (string)($template['body_ar'] ?? '') : (string)($template['body_en'] ?? '');
        if ($body === '') $body = (string)($template['body_ar'] ?? '');

        return (string)preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', function ($m) use ($values) {
            $key = strtolower($m[1]);
            if (!array_key_exists($key, $values)) return $m[0];
            $v = trim((string)$values[$key]);
            return $v === '' ? $m[0] : $v;
        }, $body);
    }
}

if (!function_exists('template_placeholders')) {
    /** Placeholder keys present in a body, so the editor can warn about unbound ones. */
    function template_placeholders(string $body): array {
        if (!preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', $body, $m)) return [];
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }
}

if (!function_exists('template_save')) {
    /**
     * Create or update a firm-owned template. Returns [ok, id, error].
     *
     * A firm may only write rows it owns: the UPDATE is scoped by `firm_id = ?` so a
     * guessed id cannot edit another firm's template or a platform row.
     */
    function template_save(PDO $pdo, ?int $firmId, array $data, ?int $lawyerId = null): array {
        if (!$firmId) return [false, null, 'firm_required'];

        $id = (int)($data['id'] ?? 0);
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        if ($slug === '') {
            $slug = 'tpl-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,118}$/', $slug)) return [false, null, 'bad_slug'];

        $titleAr = trim((string)($data['title_ar'] ?? ''));
        if ($titleAr === '') return [false, null, 'title_required'];

        $categoryId = (int)($data['category_id'] ?? 0);
        if ($categoryId > 0) {
            $chk = $pdo->prepare('SELECT id FROM template_categories WHERE id = ? AND is_active = 1');
            $chk->execute([$categoryId]);
            if (!$chk->fetchColumn()) $categoryId = 0;
        }
        // Normalise before the test, never inside it: `in_array($data['plan_tier'] ?? 'free', ...)`
        // reads the key the ?? only *tested*, so an omitted field yields null and the ENUM
        // truncates. Same defect class as affiliate_request_payout().
        $tier = (string)($data['plan_tier'] ?? 'free');
        if (!in_array($tier, ['free', 'paid', 'corporate'], true)) $tier = 'free';
        $lang = (string)($data['language'] ?? 'ar');
        if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

        $fields = $data['fields_json'] ?? null;
        if (is_array($fields)) $fields = json_encode(array_values($fields), JSON_UNESCAPED_UNICODE);

        try {
            if ($id > 0) {
                $own = $pdo->prepare('SELECT id FROM document_templates WHERE id = ? AND firm_id = ?');
                $own->execute([$id, $firmId]);
                if (!$own->fetchColumn()) return [false, null, 'not_found_or_forbidden'];

                $pdo->prepare(
                    'UPDATE document_templates
                        SET category_id=?, slug=?, title_ar=?, title_en=?, description_ar=?, description_en=?,
                            body_ar=?, body_en=?, doc_type=?, fields_json=?, plan_tier=?, language=?,
                            is_active=?, version=version+1
                      WHERE id=? AND firm_id=?'
                )->execute([
                    $categoryId ?: null, $slug, $titleAr, (string)($data['title_en'] ?? $titleAr),
                    (string)($data['description_ar'] ?? ''), (string)($data['description_en'] ?? ''),
                    (string)($data['body_ar'] ?? ''), (string)($data['body_en'] ?? ''),
                    (string)($data['doc_type'] ?? 'legal_memo'), $fields, $tier,
                    $lang,
                    !empty($data['is_active']) ? 1 : 0, $id, $firmId,
                ]);
                return [true, $id, null];
            }

            $pdo->prepare(
                'INSERT INTO document_templates
                    (category_id, firm_id, slug, title_ar, title_en, description_ar, description_en,
                     body_ar, body_en, doc_type, fields_json, plan_tier, language, is_active, created_by_lawyer_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $categoryId ?: null, $firmId, $slug, $titleAr, (string)($data['title_en'] ?? $titleAr),
                (string)($data['description_ar'] ?? ''), (string)($data['description_en'] ?? ''),
                (string)($data['body_ar'] ?? ''), (string)($data['body_en'] ?? ''),
                (string)($data['doc_type'] ?? 'legal_memo'), $fields, $tier,
                $lang,
                !empty($data['is_active']) ? 1 : 0, $lawyerId,
            ]);
            return [true, (int)$pdo->lastInsertId(), null];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) return [false, null, 'duplicate_slug'];
            error_log('template_save: ' . $e->getMessage());
            return [false, null, 'save_failed'];
        }
    }
}

if (!function_exists('template_delete')) {
    /** Delete only a firm's own template; platform rows are never removable from a firm. */
    function template_delete(PDO $pdo, int $templateId, int $firmId): bool {
        try {
            $stmt = $pdo->prepare('DELETE FROM document_templates WHERE id = ? AND firm_id = ?');
            $stmt->execute([$templateId, $firmId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
