<?php
/**
 * روبوت محادثة المكتب — المحرّك الذي يخدم الزائر على موقع المكتب العام.
 *
 * `firm_chatbots`, `chatbot_conversations` and `chatbot_messages` shipped in v81 with a
 * complete schema, zero rows and no reader. This file is the reader and the writer: one
 * bot per firm, a public key the widget uses, and a conversation the firm can answer.
 *
 * The design constraints, each of which is a place a chatbot usually goes wrong:
 *
 *  - **A visitor is not a user.** There is no session and no account. The conversation is
 *    keyed by a random `visitor_key` cookie the widget sets, never by an IP address. The
 *    bot can therefore only ever see the firm's *published* material, never a client's
 *    case, document or invoice. `knowledge_scope` is enforced here, not by the caller.
 *
 *  - **The bot does not give legal advice.** It answers about the firm, its practice
 *    areas, its hours and how to book, and it refuses a request for a legal opinion with
 *    a hand-off to a human. That is a product rule, not a model limitation: an
 *    unauthenticated stranger must not receive advice the firm has not reviewed.
 *
 *  - **AI is optional and honest.** If no provider is configured, the bot still answers
 *    the structured questions (hours, practice areas, booking) from the database and
 *    says so; it never fabricates an answer. `ai_chat_provider_configured()` is the one
 *    predicate, per the platform rule.
 *
 *  - **A conversation is firm-scoped on every read.** A lawyer sees only conversations
 *    belonging to a bot of their own firm, so the inbox cannot become a cross-firm leak.
 *
 *  - **Conversion is a lead, not an account.** When a visitor leaves a phone number, the
 *    conversation is marked `converted` and a `contact_leads` row is created; nothing
 *    here creates a login or a case.
 */

require_once __DIR__ . '/tenant.php';

if (!function_exists('firm_chatbot_ready')) {
    function firm_chatbot_ready(PDO $pdo): bool {
        try {
            $pdo->query('SELECT 1 FROM firm_chatbots LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('firm_chatbot_for_firm')) {
    /** The firm's bot row, or null. One bot per firm (`uq_firm_chatbots_firm`). */
    function firm_chatbot_for_firm(PDO $pdo, int $firmId): ?array {
        if ($firmId <= 0) return null;
        try {
            $stmt = $pdo->prepare('SELECT * FROM firm_chatbots WHERE firm_id = ? LIMIT 1');
            $stmt->execute([$firmId]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('firm_chatbot_by_key')) {
    /** Resolve a public key to its bot. The key is the only public identifier. */
    function firm_chatbot_by_key(PDO $pdo, string $publicKey): ?array {
        $publicKey = strtolower(trim($publicKey));
        if (!preg_match('/^[a-f0-9]{32}$/', $publicKey)) return null;
        try {
            $stmt = $pdo->prepare(
                'SELECT b.*, f.name AS firm_name, f.display_name AS firm_display_name,
                        f.public_slug AS firm_slug, f.is_public AS firm_is_public,
                        f.practice_areas, f.working_hours, f.phone AS firm_phone,
                        f.email AS firm_email, f.plan_id
                   FROM firm_chatbots b
                   JOIN law_firms f ON f.id = b.firm_id
                  WHERE b.public_key = ? LIMIT 1'
            );
            $stmt->execute([$publicKey]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('firm_chatbot_plan_allows')) {
    /**
     * Is the bot available on the firm's plan?
     *
     * `plans.feature_chatbot` is the flag the pricing page already advertises. A firm with
     * no plan is treated as `free`, which does not include the chatbot — the same
     * conservative direction the template tier gate takes. A hidden plan does not count.
     */
    function firm_chatbot_plan_allows(PDO $pdo, int $firmId): bool {
        if ($firmId <= 0) return false;
        try {
            $stmt = $pdo->prepare(
                'SELECT p.feature_chatbot FROM law_firms f
                   JOIN plans p ON p.id = f.plan_id
                  WHERE f.id = ? AND p.status = ? LIMIT 1'
            );
            $stmt->execute([$firmId, 'active']);
            return (int)($stmt->fetchColumn() ?: 0) === 1;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('firm_chatbot_enabled')) {
    /** Enabled and allowed by the plan, and the firm's public site is live. */
    function firm_chatbot_enabled(PDO $pdo, array $bot): bool {
        if (empty($bot['is_enabled'])) return false;
        if (!(int)($bot['firm_is_public'] ?? 0)) return false;
        return firm_chatbot_plan_allows($pdo, (int)$bot['firm_id']);
    }
}

if (!function_exists('firm_chatbot_ensure')) {
    /**
     * Get or create the firm's bot row, with a fresh random public key.
     *
     * Creation is an owner/partner action; the caller checks the role. The key is
     * `bin2hex(random_bytes(16))` — 32 hex chars, unguessable, and the only thing the
     * public widget carries.
     */
    function firm_chatbot_ensure(PDO $pdo, int $firmId, int $lawyerId): ?array {
        $existing = firm_chatbot_for_firm($pdo, $firmId);
        if ($existing) return $existing;
        if ($firmId <= 0) return null;
        try {
            $key = bin2hex(random_bytes(16));
            $pdo->prepare(
                'INSERT INTO firm_chatbots (firm_id, lawyer_id, public_key, display_name_ar, display_name_en,
                                            greeting_ar, greeting_en, knowledge_scope, allow_booking, allow_handoff, is_enabled)
                 VALUES (?,?,?,?,?,?,?,?,?,?,0)'
            )->execute([
                $firmId, $lawyerId ?: null, $key,
                'المساعد القانوني', 'Legal assistant',
                'مرحباً بك. كيف يمكننا مساعدتك؟ يمكنني التعريف بالمكتب وحجز موعد مع أحد محامينا.',
                'Welcome. How can we help? I can tell you about the firm and book an appointment with one of our lawyers.',
                'firm_and_public', 1, 1,
            ]);
            return firm_chatbot_for_firm($pdo, $firmId);
        } catch (PDOException $e) {
            error_log('firm_chatbot_ensure: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('firm_chatbot_save')) {
    /** Update the firm's bot configuration. Firm-scoped: the WHERE carries firm_id. */
    function firm_chatbot_save(PDO $pdo, int $firmId, array $data): bool {
        $bot = firm_chatbot_for_firm($pdo, $firmId);
        if (!$bot) return false;

        $scope = in_array($data['knowledge_scope'] ?? '', ['firm_only', 'firm_and_public', 'public_only'], true)
            ? (string)$data['knowledge_scope'] : 'firm_and_public';
        $whatsapp = preg_replace('/[^0-9+]/', '', (string)($data['whatsapp_number'] ?? ''));

        try {
            $pdo->prepare(
                'UPDATE firm_chatbots
                    SET display_name_ar=?, display_name_en=?, greeting_ar=?, greeting_en=?,
                        knowledge_scope=?, allow_booking=?, allow_handoff=?, whatsapp_number=?, is_enabled=?
                  WHERE id=? AND firm_id=?'
            )->execute([
                trim((string)($data['display_name_ar'] ?? $bot['display_name_ar'])),
                trim((string)($data['display_name_en'] ?? $bot['display_name_en'])),
                trim((string)($data['greeting_ar'] ?? $bot['greeting_ar'])),
                trim((string)($data['greeting_en'] ?? $bot['greeting_en'])),
                $scope,
                !empty($data['allow_booking']) ? 1 : 0,
                !empty($data['allow_handoff']) ? 1 : 0,
                $whatsapp !== '' ? $whatsapp : null,
                !empty($data['is_enabled']) ? 1 : 0,
                (int)$bot['id'], $firmId,
            ]);
            return true;
        } catch (PDOException $e) {
            error_log('firm_chatbot_save: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('firm_chatbot_rotate_key')) {
    /** Replace the public key, invalidating any embedded widget on an old site. */
    function firm_chatbot_rotate_key(PDO $pdo, int $firmId): ?string {
        try {
            $key = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare('UPDATE firm_chatbots SET public_key = ? WHERE firm_id = ?');
            $stmt->execute([$key, $firmId]);
            return $stmt->rowCount() > 0 ? $key : null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('firm_chatbot_start_conversation')) {
    /**
     * Find or create the open conversation for this visitor key on this bot.
     *
     * A visitor returning to the page keeps one thread while it is `open`; once it is
     * closed or converted, a new one starts, so an old converted thread is not reopened.
     */
    function firm_chatbot_start_conversation(PDO $pdo, int $botId, string $visitorKey): ?array {
        $visitorKey = strtolower(trim($visitorKey));
        if (!preg_match('/^[a-f0-9]{32}$/', $visitorKey)) return null;
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM chatbot_conversations
                  WHERE chatbot_id = ? AND visitor_key = ? AND status IN ('open','handoff_requested')
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$botId, $visitorKey]);
            $row = $stmt->fetch();
            if ($row) return $row;

            $pdo->prepare('INSERT INTO chatbot_conversations (chatbot_id, visitor_key, status) VALUES (?,?,?)')
                ->execute([$botId, $visitorKey, 'open']);
            $id = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM chatbot_conversations WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log('firm_chatbot_start_conversation: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('firm_chatbot_conversation')) {
    /** One conversation, only if it belongs to a bot of this firm. */
    function firm_chatbot_conversation(PDO $pdo, int $conversationId, int $firmId): ?array {
        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, b.firm_id FROM chatbot_conversations c
                   JOIN firm_chatbots b ON b.id = c.chatbot_id
                  WHERE c.id = ? AND b.firm_id = ? LIMIT 1'
            );
            $stmt->execute([$conversationId, $firmId]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('firm_chatbot_messages')) {
    function firm_chatbot_messages(PDO $pdo, int $conversationId, int $limit = 100): array {
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM chatbot_messages WHERE conversation_id = ? ORDER BY id ASC LIMIT ' . max(1, min(500, $limit))
            );
            $stmt->execute([$conversationId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('firm_chatbot_add_message')) {
    function firm_chatbot_add_message(PDO $pdo, int $conversationId, string $sender, string $message, ?array $citations = null): void {
        if (!in_array($sender, ['visitor', 'assistant', 'lawyer', 'system'], true)) return;
        $message = trim($message);
        if ($message === '') return;
        try {
            $pdo->prepare('INSERT INTO chatbot_messages (conversation_id, sender, message, citations_json) VALUES (?,?,?,?)')
                ->execute([
                    $conversationId, $sender, mb_substr($message, 0, 4000, 'UTF-8'),
                    $citations ? json_encode($citations, JSON_UNESCAPED_UNICODE) : null,
                ]);
        } catch (PDOException $e) {
            error_log('firm_chatbot_add_message: ' . $e->getMessage());
        }
    }
}

if (!function_exists('firm_chatbot_intent')) {
    /**
     * Classify a visitor message by keyword, so the bot answers the structured questions
     * from the database without a model at all.
     *
     * This runs before any AI call. It is what makes the bot useful on a deployment with
     * no provider configured, and it is also the guard that refuses a request for legal
     * advice: `legal_advice` is matched explicitly and answered with a hand-off.
     */
    function firm_chatbot_intent(string $message): string {
        $m = mb_strtolower(trim($message), 'UTF-8');
        if ($m === '') return 'unknown';

        $has = fn(array $words) => (bool)array_filter($words, fn($w) => $w !== '' && mb_strpos($m, $w) !== false);

        // Advice comes first: a request for a legal opinion must win over every other
        // keyword, so "كم أتعاب قضيتي؟" is a hand-off, not a price quote.
        if ($has(['حكم', 'رأي قانوني', 'استشارة قانونية', 'هل يحق لي', 'هل أستطيع رفع', 'قضيتي', 'دعوى ضدي',
                  'legal advice', 'advise me', 'will i win', 'my case'])) {
            return 'legal_advice';
        }
        if ($has(['موعد', 'حجز', 'أحجز', 'استشارة', 'مقابلة', 'appointment', 'book', 'booking', 'consult'])) {
            return 'booking';
        }
        if ($has(['ساعات', 'دوام', 'متى تفتحون', 'أوقات العمل', 'hours', 'open', 'working hours'])) {
            return 'hours';
        }
        if ($has(['تخصص', 'تخصصات', 'خدمات', 'مجالات', 'ماذا تقدمون', 'practice', 'services', 'specialt'])) {
            return 'services';
        }
        if ($has(['عنوان', 'موقع', 'فرع', 'أين', 'address', 'location', 'branch', 'where'])) {
            return 'location';
        }
        if ($has(['سعر', 'أسعار', 'تكلفة', 'أتعاب', 'كم', 'price', 'cost', 'fee', 'how much'])) {
            return 'pricing';
        }
        if ($has(['تواصل', 'هاتف', 'جوال', 'ايميل', 'بريد', 'contact', 'phone', 'email', 'whatsapp'])) {
            return 'contact';
        }
        return 'unknown';
    }
}

if (!function_exists('firm_chatbot_structured_reply')) {
    /**
     * Answer a structured intent from the firm's own published fields.
     *
     * Returns null when the intent needs the model or is unknown, so the caller can fall
     * through. Nothing here reads a client table.
     */
    function firm_chatbot_structured_reply(array $bot, string $intent, bool $arabic): ?string {
        $firmName = (string)($bot['firm_display_name'] ?: $bot['firm_name']);

        switch ($intent) {
            case 'hours':
                $hours = trim((string)($bot['working_hours'] ?? ''));
                if ($hours === '') return null;
                return $arabic
                    ? "ساعات عمل $firmName:\n$hours"
                    : "Working hours of $firmName:\n$hours";

            case 'services':
                $areas = trim((string)($bot['practice_areas'] ?? ''));
                if ($areas === '') return null;
                $list = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/u', $areas))));
                $rendered = implode('، ', $list);
                return $arabic
                    ? "مجالات عمل $firmName:\n$rendered"
                    : "Practice areas of $firmName:\n" . implode(', ', $list);

            case 'contact':
                $bits = [];
                if (!empty($bot['firm_phone'])) $bits[] = ($arabic ? 'هاتف: ' : 'Phone: ') . $bot['firm_phone'];
                if (!empty($bot['firm_email'])) $bits[] = ($arabic ? 'بريد إلكتروني: ' : 'Email: ') . $bot['firm_email'];
                if (!empty($bot['whatsapp_number'])) $bits[] = 'WhatsApp: ' . $bot['whatsapp_number'];
                if (!$bits) return null;
                return ($arabic ? "يمكنك التواصل مع $firmName عبر:\n" : "You can reach $firmName via:\n") . implode("\n", $bits);

            case 'location':
                // The public firm page carries the branches; point there rather than
                // duplicating address data the page already publishes.
                if (empty($bot['firm_slug'])) return null;
                return $arabic
                    ? "تجد عناوين الفروع على صفحة المكتب: /firm.php?firm=" . $bot['firm_slug']
                    : "Branch addresses are on the firm page: /firm.php?firm=" . $bot['firm_slug'];

            case 'booking':
                if (empty($bot['allow_booking'])) return null;
                return $arabic
                    ? "يمكنني مساعدتك في حجز موعد. اترك رقم جوالك هنا وسيتواصل معك فريق {$firmName}، أو احجز مباشرة من صفحة الاستشارة: /consultation-journey.php"
                    : "I can help you book an appointment. Leave your phone number here and the $firmName team will contact you, or book directly at /consultation-journey.php";

            case 'pricing':
                // Prices belong to the plan/consultation tables, not to a chat guess.
                return $arabic
                    ? "تختلف الأتعاب حسب نوع العمل. اترك رقم جوالك وسيتواصل معك الفريق بعرض واضح، أو راجع صفحة الاستشارات."
                    : "Fees depend on the type of work. Leave your phone number and the team will contact you with a clear quote, or see the consultations page.";

            case 'legal_advice':
                return $arabic
                    ? "لا أستطيع تقديم رأي قانوني هنا، لأن ذلك يتطلب مراجعة مستنداتك من محامٍ مرخّص. يسعدني حجز استشارة مع أحد محامي $firmName."
                    : "I cannot give a legal opinion here, as that requires a licensed lawyer to review your documents. I can book a consultation with one of $firmName's lawyers.";
        }
        return null;
    }
}

if (!function_exists('firm_chatbot_reply')) {
    /**
     * Produce the assistant's reply to one visitor message.
     *
     * Order of resolution: structured answer → AI (if configured) → honest fallback.
     * The AI prompt is bounded to the firm's published profile and the configured
     * knowledge scope; it is never given a case, a document or a client record.
     *
     * @return array{reply:string, intent:string, source:string, citations:array}
     */
    function firm_chatbot_reply(PDO $pdo, array $bot, string $message, bool $arabic = true): array {
        $intent = firm_chatbot_intent($message);

        $structured = firm_chatbot_structured_reply($bot, $intent, $arabic);
        if ($structured !== null) {
            return ['reply' => $structured, 'intent' => $intent, 'source' => 'firm_profile', 'citations' => []];
        }

        // AI is optional. `ai_chat_provider_configured()` is the platform's one predicate:
        // a self-hosted keyless model is configured, a hosted one needs a key.
        $settings = firm_chatbot_ai_settings($pdo);
        if (!function_exists('ai_chat_provider_configured') || !ai_chat_provider_configured($settings)) {
            return [
                'reply' => $arabic
                    ? 'أستطيع التعريف بالمكتب ومجالات عمله وحجز المواعيد. للاستفسار عن تفاصيل أخرى، اترك رقم جوالك وسيتواصل معك الفريق.'
                    : 'I can describe the firm, its practice areas and book appointments. For anything else, leave your phone number and the team will contact you.',
                'intent' => $intent, 'source' => 'fallback_no_ai', 'citations' => [],
            ];
        }

        $context = firm_chatbot_profile_text($bot, $arabic);
        $library = firm_chatbot_library_text($pdo, $bot, $message, $arabic);
        if ($library !== '') $context .= "\n\n" . $library;

        $system = ($arabic
            ? 'أنت مساعد مكتب محاماة على موقعه العام. أجب فقط من المعلومات المقدمة عن المكتب. لا تقدم رأياً قانونياً ولا تخترع أنظمة أو أرقام مواد. إن سُئلت عن رأي قانوني فاعتذر بلطف واعرض حجز استشارة.'
            : 'You are a law firm assistant on the firm public site. Answer only from the supplied firm information. Give no legal opinion and invent no statutes or article numbers. If asked for legal advice, decline politely and offer a consultation.');

        try {
            if (!function_exists('ai_gateway_call') || !function_exists('ai_gateway_tenant_context')) {
                return ['reply' => firm_chatbot_structured_reply($bot, 'booking', $arabic) ?: '', 'intent' => $intent,
                        'source' => 'fallback_no_gateway', 'citations' => []];
            }
            // The bot speaks for the firm, so the gateway tenant is the firm, and the
            // actor is the firm's owner — the same context a lawyer page uses. This keeps
            // usage accounting and the tenant privacy gate meaningful for a public chat.
            $ownerId = (int)$pdo->query('SELECT owner_lawyer_id FROM law_firms WHERE id=' . (int)$bot['firm_id'])->fetchColumn();
            if ($ownerId <= 0) {
                return ['reply' => '', 'intent' => $intent, 'source' => 'fallback_no_owner', 'citations' => []];
            }
            $tenant = ai_gateway_tenant_context($pdo, 'lawyer', $ownerId);
            $res = ai_gateway_call(
                $pdo, $settings, $tenant, 'firm_chatbot', 'public_firm_information',
                $ownerId, 'lawyer', $system,
                $context . "\n\n" . ($arabic ? 'سؤال الزائر: ' : 'Visitor question: ') . $message,
                400, 0.3
            );
            if (!empty($res['ok']) && trim((string)($res['text'] ?? '')) !== '') {
                return ['reply' => trim((string)$res['text']), 'intent' => $intent, 'source' => 'ai', 'citations' => []];
            }
        } catch (Throwable $e) {
            error_log('firm_chatbot_reply AI failed: ' . $e->getMessage());
        }

        return [
            'reply' => $arabic
                ? 'تعذّر الوصول إلى المساعد الآن. يمكنك ترك رقم جوالك وسيتواصل معك الفريق.'
                : 'The assistant is unavailable right now. You can leave your phone number and the team will contact you.',
            'intent' => $intent, 'source' => 'fallback_error', 'citations' => [],
        ];
    }
}

if (!function_exists('firm_chatbot_ai_settings')) {
    function firm_chatbot_ai_settings(PDO $pdo): array {
        $out = [];
        try {
            foreach ($pdo->query('SELECT setting_key, setting_value FROM site_settings') as $r) {
                $out[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) {
        }
        return $out;
    }
}

if (!function_exists('firm_chatbot_profile_text')) {
    /** The firm's published profile as prompt context — nothing private. */
    function firm_chatbot_profile_text(array $bot, bool $arabic): string {
        $lines = [];
        $name = (string)($bot['firm_display_name'] ?: $bot['firm_name']);
        $lines[] = ($arabic ? 'اسم المكتب: ' : 'Firm: ') . $name;
        if (!empty($bot['practice_areas'])) {
            $lines[] = ($arabic ? 'مجالات العمل: ' : 'Practice areas: ') . $bot['practice_areas'];
        }
        if (!empty($bot['working_hours'])) {
            $lines[] = ($arabic ? 'ساعات العمل: ' : 'Working hours: ') . $bot['working_hours'];
        }
        if (!empty($bot['firm_phone'])) {
            $lines[] = ($arabic ? 'هاتف: ' : 'Phone: ') . $bot['firm_phone'];
        }
        return ($arabic ? "معلومات المكتب:\n" : "Firm information:\n") . implode("\n", $lines);
    }
}

if (!function_exists('firm_chatbot_library_text')) {
    /**
     * Public knowledge for the bot, bounded by `knowledge_scope`.
     *
     * `firm_only` deliberately retrieves nothing here: the bot serves an unauthenticated
     * stranger, and a firm-only source is not public. `firm_and_public` and `public_only`
     * retrieve `global` sources. This is the gate that keeps a firm's internal knowledge
     * out of a public chat window.
     */
    function firm_chatbot_library_text(PDO $pdo, array $bot, string $query, bool $arabic): string {
        $scope = (string)($bot['knowledge_scope'] ?? 'firm_and_public');
        if ($scope === 'firm_only') return '';
        if (!function_exists('knowledge_retrieve')) return '';

        try {
            // An unauthenticated visitor has no actor; retrieve as the firm's owner so the
            // global/public scope applies, then keep only global rows below.
            $ownerId = (int)$pdo->query('SELECT owner_lawyer_id FROM law_firms WHERE id=' . (int)$bot['firm_id'])->fetchColumn();
            if ($ownerId <= 0) return '';
            $rows = knowledge_retrieve($pdo, $query, 'firm_chatbot', 'lawyer', $ownerId, null, true, 3);
        } catch (Throwable $e) {
            return '';
        }

        $out = [];
        foreach ($rows as $row) {
            if (($row['scope_type'] ?? '') !== 'global') continue;
            $chunk = trim((string)($row['chunk_text'] ?? ''));
            if ($chunk === '') continue;
            $out[] = mb_substr($chunk, 0, 800, 'UTF-8');
        }
        if (!$out) return '';
        return ($arabic ? "مقتطفات مرجعية عامة:\n" : "Public reference passages:\n") . implode("\n\n", $out);
    }
}

if (!function_exists('firm_chatbot_convert')) {
    /**
     * Turn the conversation into a lead when the visitor leaves contact details.
     *
     * This is the only write that crosses from the anonymous chat into the firm's CRM.
     * It creates a `contact_leads` row (the existing table and enum) and links it back on
     * the conversation. No account, case or client row is created.
     */
    function firm_chatbot_convert(PDO $pdo, array $conversation, array $bot, string $name, string $phone, string $email = ''): array {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strlen($phone) < 8) return ['ok' => false, 'error' => 'phone_invalid'];

        try {
            $pdo->prepare(
                'INSERT INTO contact_leads (lead_type, full_name, email, phone, message, status, utm_source, utm_medium, source_page)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                'contact',
                mb_substr(trim($name) !== '' ? trim($name) : 'زائر الموقع', 0, 150, 'UTF-8'),
                mb_substr(trim($email), 0, 150, 'UTF-8'),
                $phone,
                'محادثة روبوت المكتب #' . (int)$conversation['id'],
                'new',
                'firm_chatbot',
                'website',
                '/firm.php?firm=' . (string)($bot['firm_slug'] ?? ''),
            ]);
            $leadId = (int)$pdo->lastInsertId();

            $pdo->prepare('UPDATE chatbot_conversations SET status=?, lead_id=?, visitor_name=?, visitor_phone=?, visitor_email=? WHERE id=?')
                ->execute(['converted', $leadId, mb_substr(trim($name), 0, 160, 'UTF-8') ?: null,
                           $phone, mb_substr(trim($email), 0, 190, 'UTF-8') ?: null, (int)$conversation['id']]);

            return ['ok' => true, 'lead_id' => $leadId];
        } catch (PDOException $e) {
            error_log('firm_chatbot_convert: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'save_failed'];
        }
    }
}

if (!function_exists('firm_chatbot_conversations')) {
    /** The firm's inbox: conversations for any bot of this firm, newest first. */
    function firm_chatbot_conversations(PDO $pdo, int $firmId, string $status = '', int $limit = 100): array {
        $sql = 'SELECT c.*, b.display_name_ar, b.public_key,
                       (SELECT COUNT(*) FROM chatbot_messages m WHERE m.conversation_id = c.id) AS message_count,
                       (SELECT m2.message FROM chatbot_messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_message
                  FROM chatbot_conversations c
                  JOIN firm_chatbots b ON b.id = c.chatbot_id
                 WHERE b.firm_id = ?';
        $params = [$firmId];
        if (in_array($status, ['open', 'handoff_requested', 'converted', 'closed'], true)) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY c.updated_at DESC LIMIT ' . max(1, min(500, $limit));
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}
