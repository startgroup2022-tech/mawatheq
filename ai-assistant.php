<?php
$activePage = 'ai-assistant';
require_once __DIR__ . '/includes/app_header.php';

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT id FROM ai_conversations WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$conv = $stmt->fetch();
if (!$conv) {
    $pdo->prepare('INSERT INTO ai_conversations (user_id, title) VALUES (?,?)')
        ->execute([$userId, is_rtl() ? 'محادثة جديدة' : 'New conversation']);
    $convId = $pdo->lastInsertId();
} else {
    $convId = $conv['id'];
}

$messages = $pdo->prepare('SELECT * FROM ai_messages WHERE conversation_id=? ORDER BY id ASC');
$messages->execute([$convId]);
$messages = $messages->fetchAll();
?>
<div class="app-content">
  <h2><?= t('feat1_title') ?></h2>
  <p style="margin-bottom:12px"><?= t('feat1_body') ?></p>
  <p class="field-hint"><?= is_rtl() ? 'عند تفعيل مزود AI، يُرسل سؤالك إليه لمعالجته. لا تكتب معلومات حساسة لا تريد مشاركتها.' : 'When an AI provider is enabled, your question is sent to it for processing. Do not enter sensitive details you do not wish to share.' ?></p>
  <div class="form-alert" style="background:#FFF8E6;color:#8A6D1D;font-size:.82rem;margin-bottom:20px">
    ⚠️ <?= is_rtl() ? 'إجابات المساعد الذكي إرشادية أولية ولا تُغني عن استشارة محامٍ مرخّص؛ الاستشهادات المرفقة قابلة للتحقق بالنقر عليها.' : 'AI answers are preliminary guidance and do not replace advice from a licensed lawyer; attached citations are clickable and verifiable.' ?>
  </div>

  <div class="chat-window">
    <div class="chat-messages" id="chatMessages">
      <?php if (empty($messages)): ?>
        <div style="text-align:center;color:var(--text-muted);padding:40px 0">
          <p><?= is_rtl() ? 'اسأل عن أي نظام أو حكم سعودي، أو ارفع مستنداً من صفحة' : 'Ask about any Saudi law or ruling, or upload a document from the' ?>
             <a href="/documents.php" style="color:var(--teal);font-weight:700"><?= t('feat2_title') ?></a></p>
          <div style="margin-top:16px">
            <span class="suggestion-chip" onclick="askSuggestion(this)"><?= is_rtl() ? 'ما شروط إنهاء عقد العمل؟' : 'What are the conditions for ending an employment contract?' ?></span>
            <span class="suggestion-chip" onclick="askSuggestion(this)"><?= is_rtl() ? 'كيف أؤسس شركة ذات مسؤولية محدودة؟' : 'How do I establish an LLC?' ?></span>
          </div>
        </div>
      <?php else: foreach ($messages as $m): ?>
        <div class="chat-row <?= $m['sender'] === 'user' ? 'user' : 'bot' ?>">
          <div class="chat-bubble"><?= nl2br(htmlspecialchars($m['message'])) ?>
            <?php if ($m['reference_law']): ?>
              <div style="margin-top:6px;font-size:.78rem;opacity:.8">
                📌 <?php if (!empty($m['reference_article_id'])): ?><a href="/laws.php?article=<?= (int)$m['reference_article_id'] ?>" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline"><?= htmlspecialchars($m['reference_law']) ?></a><?php else: ?><?= htmlspecialchars($m['reference_law']) ?><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php $messageSources=json_decode($m['source_citations_json'] ?? '[]',true); if(is_array($messageSources)): foreach($messageSources as $source): ?>
              <div style="margin-top:5px;font-size:.78rem;opacity:.82">📚 <?php if(!empty($source['url']) && preg_match('#^https?://#i',$source['url'])): ?><a href="<?= htmlspecialchars($source['url']) ?>" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline"><?= htmlspecialchars($source['name'] ?? 'Source') ?></a><?php else: ?><?= htmlspecialchars($source['name'] ?? 'Source') ?><?php endif; ?><?php if(!empty($source['version'])): ?> · v<?= (int)$source['version'] ?><?php endif; ?><?= empty($source['verified'])?' · '.(is_rtl()?'غير موثق':'unverified'):'' ?></div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <form class="chat-input-row" id="chatForm" method="post" action="/api/ai_chat.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="conversation_id" value="<?= $convId ?>">
      <input type="text" name="message" id="chatInput" placeholder="<?= is_rtl() ? 'اكتب سؤالك القانوني هنا...' : 'Type your legal question...' ?>" autocomplete="off" required>
      <button type="submit">➤</button>
    </form>
  </div>
</div>

<script>
function askSuggestion(el){
  document.getElementById('chatInput').value = el.textContent;
  document.getElementById('chatForm').requestSubmit();
}
document.getElementById('chatForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const input = document.getElementById('chatInput');
  const text = input.value.trim();
  if (!text) return;
  const box = document.getElementById('chatMessages');
  const userRow = document.createElement('div'); userRow.className = 'chat-row user';
  const userBubble = document.createElement('div'); userBubble.className = 'chat-bubble'; userBubble.textContent = text;
  userRow.appendChild(userBubble); box.appendChild(userRow);
  input.value = '';
  box.scrollTop = box.scrollHeight;

  const res = await fetch('/api/ai_chat.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams(new FormData(this))
  });
  const data = await res.json();
  const botRow = document.createElement('div'); botRow.className = 'chat-row bot';
  const botBubble = document.createElement('div'); botBubble.className = 'chat-bubble';
  botBubble.textContent = data.reply || '<?= is_rtl() ? "تعذّر الحصول على رد." : "Could not get a response." ?>';
  if (data.reference) {
    const referenceBox = document.createElement('div'); referenceBox.style.cssText = 'margin-top:6px;font-size:.78rem;opacity:.8';
    const articleId = Number.parseInt(data.reference_article_id, 10);
    if (Number.isSafeInteger(articleId) && articleId > 0) {
      const link = document.createElement('a'); link.href = '/laws.php?article=' + articleId; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.style.cssText = 'color:inherit;text-decoration:underline'; link.textContent = '📌 ' + data.reference; referenceBox.appendChild(link);
    } else { referenceBox.textContent = '📌 ' + data.reference; }
    botBubble.appendChild(referenceBox);
  }
  if (Array.isArray(data.sources)) data.sources.forEach(function(source){
    const line=document.createElement('div'); line.style.cssText='margin-top:5px;font-size:.78rem;opacity:.82'; line.appendChild(document.createTextNode('📚 '));
    if (source.url && /^https?:\/\//i.test(source.url)) { const a=document.createElement('a'); a.href=source.url; a.target='_blank'; a.rel='noopener noreferrer'; a.style.cssText='color:inherit;text-decoration:underline'; a.textContent=source.name || 'Source'; line.appendChild(a); }
    else line.appendChild(document.createTextNode(source.name || 'Source'));
    if (source.version) line.appendChild(document.createTextNode(' · v'+source.version));
    botBubble.appendChild(line);
  });
  botRow.appendChild(botBubble); box.appendChild(botRow);
  box.scrollTop = box.scrollHeight;
});
</script>

<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
