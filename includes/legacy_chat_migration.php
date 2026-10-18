<?php
/**
 * Migrate legacy AI chat history (ai_conversations / ai_messages) into the archived tables.
 *
 * The archive is a faithful copy, not a reinterpretation:
 *   - the ORIGINAL owner (`user_id`) is carried over unchanged, so a migration can never widen
 *     who can read an old conversation;
 *   - `firm_id` is only filled when the owner can be tied to a firm through an existing
 *     case, consultation or invoice — otherwise it stays NULL and the archive is user-private;
 *   - each conversation is copied once (UNIQUE on legacy_conversation_id), so a re-run is a
 *     no-op rather than a duplication.
 *
 * Originals are not modified or deleted here. Removing them is a separate, deliberate decision.
 */

if (!function_exists('legacy_chat_firm_for_user')) {
    function legacy_chat_firm_for_user(PDO $pdo, int $userId): ?int {
        foreach (['cases', 'consultations', 'invoices'] as $table) {
            try {
                $st = $pdo->prepare("SELECT firm_id FROM $table WHERE user_id=? AND firm_id IS NOT NULL ORDER BY id DESC LIMIT 1");
                $st->execute([$userId]);
                $firm = $st->fetchColumn();
                if ($firm !== false && $firm !== null) return (int)$firm;
            } catch (Throwable $e) {
                // Table may not exist on an older schema; try the next source.
            }
        }
        return null;
    }
}

if (!function_exists('legacy_chat_migrate')) {
    /**
     * @return array{seen:int,migrated:int,messages:int,skipped:int}
     */
    function legacy_chat_migrate(PDO $pdo, string $runBy = 'cli', int $limit = 0): array {
        $sql = 'SELECT id, user_id, title, created_at FROM ai_conversations ORDER BY id ASC';
        if ($limit > 0) $sql .= ' LIMIT ' . $limit;
        $conversations = $pdo->query($sql)->fetchAll();

        $seen = 0; $migrated = 0; $messages = 0; $skipped = 0;

        foreach ($conversations as $conv) {
            $seen++;
            $convId = (int)$conv['id'];
            $exists = $pdo->prepare('SELECT id FROM ai_chat_archives WHERE legacy_conversation_id=? LIMIT 1');
            $exists->execute([$convId]);
            if ($exists->fetchColumn()) { $skipped++; continue; }

            $userId = (int)$conv['user_id'];
            $firmId = legacy_chat_firm_for_user($pdo, $userId);

            $pdo->beginTransaction();
            try {
                // Re-check inside the transaction so two concurrent runs cannot both insert.
                $exists->execute([$convId]);
                if ($exists->fetchColumn()) {
                    $pdo->rollBack();
                    $skipped++;
                    continue;
                }

                $pdo->prepare('INSERT INTO ai_chat_archives (firm_id, user_id, legacy_conversation_id, title, message_count, migrated_at, created_at) VALUES (?,?,?,?,?,NOW(),?)')
                    ->execute([$firmId, $userId, $convId, $conv['title'], 0, $conv['created_at'] ?? date('Y-m-d H:i:s')]);
                $archiveId = (int)$pdo->lastInsertId();

                $msgs = $pdo->prepare('SELECT sender, message, reference_law, source_citations_json, created_at FROM ai_messages WHERE conversation_id=? ORDER BY id ASC');
                $msgs->execute([$convId]);
                $rows = $msgs->fetchAll();

                $ins = $pdo->prepare('INSERT INTO ai_chat_archive_messages (archive_id, sender, message, reference_law, source_citations_json, original_created_at) VALUES (?,?,?,?,?,?)');
                foreach ($rows as $m) {
                    $ins->execute([
                        $archiveId,
                        $m['sender'] === 'assistant' ? 'assistant' : 'user',
                        (string)$m['message'],
                        $m['reference_law'] ?? null,
                        $m['source_citations_json'] ?? null,
                        $m['created_at'] ?? null,
                    ]);
                }
                $pdo->prepare('UPDATE ai_chat_archives SET message_count=? WHERE id=?')->execute([count($rows), $archiveId]);
                $pdo->commit();

                $migrated++; $messages += count($rows);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Legacy chat migration failed for conversation ' . $convId . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        $pdo->prepare('INSERT INTO legacy_chat_migration_log (run_by, conversations_seen, conversations_migrated, messages_migrated, conversations_skipped, detail) VALUES (?,?,?,?,?,?)')
            ->execute([$runBy, $seen, $migrated, $messages, $skipped, 'legacy ai_conversations -> ai_chat_archives']);

        return ['seen' => $seen, 'migrated' => $migrated, 'messages' => $messages, 'skipped' => $skipped];
    }
}
