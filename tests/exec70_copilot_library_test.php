<?php
/**
 * Execution 70 — the copilot is grounded in the legal library, and only in what the
 * signed-in lawyer may see.
 *
 * Before this, `ai_copilot_context_text()` was built from the case's own columns, so the
 * assistant could not cite a statute. The fix calls `knowledge_retrieve()`; this suite
 * proves three things that a code read cannot:
 *
 *   1. a firm-scoped library source reaches the context for a lawyer of that firm;
 *   2. it does NOT reach a lawyer of another firm (the retrieval is tenant-scoped, and
 *      the copilot must not become a way to read another firm's library);
 *   3. an empty / missing library degrades to no passages instead of breaking the run.
 *
 * Runs inside a transaction and rolls back, so it leaves no residue.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai_copilot.php';
require_once __DIR__ . '/../includes/knowledge_center.php';

$checks = 0;
$failures = 0;

function chk(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

echo "Execution 70 — copilot legal-library grounding\n";
echo "==============================================\n";

$pdo->beginTransaction();
try {
    $firmId = (int)$pdo->query('SELECT id FROM law_firms ORDER BY id LIMIT 1')->fetchColumn();
    $otherFirm = (int)$pdo->query('SELECT id FROM law_firms ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!$firmId || !$otherFirm || $firmId === $otherFirm) {
        throw new RuntimeException('need two distinct firms');
    }

    $lawyerA = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$firmId LIMIT 1")->fetchColumn();
    $lawyerB = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$otherFirm LIMIT 1")->fetchColumn();

    $clientId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

    // A case owned by lawyer A, so knowledge_retrieve's case check passes for A.
    $pdo->prepare('INSERT INTO cases (lawyer_id, firm_id, user_id, title, case_type, status)
                   VALUES (?,?,?,?,?,?)')
        ->execute([$lawyerA, $firmId, $clientId, 'قضية مطالبة تعاقدية', 'تجاري', 'open']);
    $caseId = (int)$pdo->lastInsertId();
    $case = $pdo->query("SELECT * FROM cases WHERE id=$caseId")->fetch();

    // --- 1. a firm-scoped source is retrieved for a lawyer of that firm ---------------
    $marker = 'مادة-تمييز-' . bin2hex(random_bytes(3));
    $body = "المادة ٩٩: $marker — يلتزم المدين بتنفيذ التزامه التعاقدي، ويستحق المتضرر التعويض.";
    $pdo->prepare('INSERT INTO knowledge_sources
        (tenant_id, module_id, source_type, source_name, content, language, jurisdiction, category,
         status, verification_status, scope_type, scope_id, processing_status, indexing_status,
         created_by_type, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$firmId, 'case_copilot', 'manual', "نظام $marker", $body, 'ar', 'السعودية',
                   'أنظمة', 'active', 'verified', 'firm', $firmId, 'completed', 'indexed', 'admin', 1]);
    $sourceId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO knowledge_chunks (source_id, chunk_order, chunk_text, text_hash)
                   VALUES (?,?,?,?)')
        ->execute([$sourceId, 1, $body, hash('sha256', $body)]);

    // Pretend we are lawyer A for the duration of the context build.
    $_SESSION['lawyer_id'] = $lawyerA;
    $ctxA = ai_copilot_library_context($pdo, $case, 'research', $lawyerA, true);

    chk('library context is non-empty for the owning firm',
        $ctxA['text'] !== '', 'text was empty');
    chk('retrieved text contains the seeded passage',
        str_contains($ctxA['text'], $marker));
    chk('retrieved sources name the seeded source',
        (bool)array_filter($ctxA['sources'], fn($s) => str_contains($s['name'], $marker)));

    // --- 2. another firm's lawyer must not receive it -------------------------------
    $ctxB = ai_copilot_library_context($pdo, $case, 'research', $lawyerB, true);
    chk('other firm does not receive the passage',
        !str_contains($ctxB['text'], $marker));
    chk('other firm source list does not name it',
        !array_filter($ctxB['sources'], fn($s) => str_contains($s['name'], $marker)));

    // --- 3. degradation: a case with no library material returns no text ------------
    // Remove every source visible to this firm inside the transaction, so the check
    // measures the code and not whatever else the database happens to hold.
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_id IN
                   (SELECT id FROM knowledge_sources WHERE scope_type=? AND scope_id=? OR scope_type=?)')
        ->execute(['firm', $firmId, 'global']);
    $pdo->prepare('DELETE FROM knowledge_sources WHERE (scope_type=? AND scope_id=?) OR scope_type=?')
        ->execute(['firm', $firmId, 'global']);

    $emptyCase = ['id' => $caseId, 'title' => 'ZZZ-' . bin2hex(random_bytes(4)),
                  'case_type' => 'ZZZ', 'firm_id' => $firmId, 'branch_id' => null];
    $ctxEmpty = ai_copilot_library_context($pdo, $emptyCase, 'research', $lawyerA, true);
    chk('no library material yields empty text (not an error)',
        $ctxEmpty['text'] === '' && $ctxEmpty['sources'] === [],
        'text=' . mb_substr($ctxEmpty['text'], 0, 80));

    // A non-existent lawyer must also degrade, not throw.
    $ctxNone = ai_copilot_library_context($pdo, $case, 'research', 999999, true);
    chk('unknown lawyer degrades to empty', $ctxNone['text'] === '');

} catch (Throwable $e) {
    echo "  FAIL exception: " . $e->getMessage() . "\n";
    $failures++;
    $checks++;
} finally {
    $pdo->rollBack();
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
