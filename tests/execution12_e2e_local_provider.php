<?php
/**
 * End-to-end demonstration on the local dev DB: point the app at the mock
 * OpenAI-compatible server as a KEYLESS self-hosted provider, then prove the
 * path that used to show "activate an AI provider" now works — and restore
 * the original settings afterwards.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/legal_ai.php';
require_once __DIR__ . '/../includes/ai_gateway.php';

$KEYS = ['ai_provider', 'ai_model', 'ai_endpoint', 'ai_enabled', 'ai_api_key'];
$before = [];
foreach ($KEYS as $k) {
    $before[$k] = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='" . $k . "'")->fetchColumn();
}
$before[$k] = null;
echo "  BEFORE: " . json_encode(array_map(fn($v) => $v === false ? null : $v, $before), JSON_UNESCAPED_UNICODE) . "\n";

$set = $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
$set->execute(['ai_provider', 'openai_compatible']);
$set->execute(['ai_model', 'mock-legal-model']);
$set->execute(['ai_endpoint', 'http://127.0.0.1:11434/v1']);
$set->execute(['ai_enabled', '1']);
$set->execute(['ai_api_key', '']);

try {
    $s = ai_load_settings($pdo);
    echo "  configured (keyless local): " . (ai_chat_provider_configured($s) ? 'TRUE' : 'FALSE') . "\n";

    // The exact call the drafting page makes before it would show the Arabic warning.
    $draftingGate = ai_chat_provider_configured($s);
    echo "  drafting page would show 'activate a provider' warning: " . ($draftingGate ? 'NO (fixed)' : 'YES') . "\n";

    // A real completion through the real provider dispatch.
    $r = ai_provider_chat($s, 'You are a legal assistant.', 'اكتب سطراً واحداً', 32);
    echo "  real chat ok: " . (!empty($r['ok']) ? 'TRUE' : 'FALSE') . " | reply: " . trim((string)($r['text'] ?? '')) . "\n";

    // Grounded legal answer path (uses the same provider dispatch).
    $ans = generate_grounded_legal_answer('ما هي مدة الإيجار؟', true, $s, $pdo, 0, 'client');
    echo "  grounded legal answer produced: " . (isset($ans['reply']) && $ans['reply'] !== '' ? 'YES' : 'NO') . "\n";
} finally {
    foreach ($KEYS as $k) {
        if ($before[$k] === false || $before[$k] === null) {
            $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$k]);
        } else {
            $set->execute([$k, (string)$before[$k]]);
        }
    }
    $after = [];
    foreach ($KEYS as $k) $after[$k] = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='" . $k . "'")->fetchColumn();
    echo "  AFTER (restored): " . json_encode(array_map(fn($v) => $v === false ? null : $v, $after), JSON_UNESCAPED_UNICODE) . "\n";
}
