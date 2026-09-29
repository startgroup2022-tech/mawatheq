<?php
/**
 * يتوقع أن يكون معرّفاً قبل الإدراج:
 * $consultation (صف من جدول consultations)
 * $role ('client' أو 'lawyer')
 * $pdo
 */
if (empty($consultation['room_code'])) {
    $roomCode = 'mawatheq-' . $consultation['id'] . '-' . bin2hex(random_bytes(4));
    $pdo->prepare('UPDATE consultations SET room_code=? WHERE id=?')->execute([$roomCode, $consultation['id']]);
    $consultation['room_code'] = $roomCode;
}
$type = $consultation['consultation_type'];
?>
<div class="dash-card" style="display:flex;justify-content:space-between;align-items:center">
  <div>
    <strong><?= htmlspecialchars($consultation['topic'] ?: (is_rtl() ? 'استشارة قانونية' : 'Legal consultation')) ?></strong>
    <div style="font-size:.82rem;color:var(--text-muted)"><?= date('Y-m-d H:i', strtotime($consultation['scheduled_at'])) ?> · <?= htmlspecialchars($type) ?></div>
  </div>
  <span class="status-pill status-<?= $consultation['status'] ?>"><?= htmlspecialchars($consultation['status']) ?></span>
</div>

<?php if ($type === 'video' || $type === 'voice'): ?>
  <div class="dash-card" style="padding:0;overflow:hidden">
    <iframe
      src="https://meet.jit.si/<?= htmlspecialchars($consultation['room_code']) ?>#config.startWithVideoMuted=<?= $type === 'voice' ? 'true' : 'false' ?>&config.prejoinPageEnabled=false&config.disableDeepLinking=true"
      style="width:100%;height:640px;border:0;display:block"
      allow="camera; microphone; fullscreen; display-capture; autoplay">
    </iframe>
  </div>
  <p style="color:var(--text-muted);font-size:.82rem;text-align:center">
    <?= is_rtl() ? 'مكالمة مباشرة ومشفّرة — لا حاجة لتثبيت أي برنامج.' : 'A live, encrypted call — no software to install.' ?>
  </p>
<?php else: ?>
  <div class="chat-window" style="height:55vh">
    <div class="chat-messages" id="roomMessages"></div>
    <form class="chat-input-row" id="roomChatForm">
      <input type="text" id="roomChatInput" placeholder="<?= is_rtl() ? 'اكتب رسالتك...' : 'Type your message...' ?>" autocomplete="off" required>
      <button type="submit">➤</button>
    </form>
  </div>
  <script>
  (function(){
    const consultationId = <?= (int)$consultation['id'] ?>;
    const role = "<?= $role ?>";
    let lastId = 0;
    const box = document.getElementById('roomMessages');
    function render(messages){
      messages.forEach(m => {
        const mine = m.sender_type === role;
        box.innerHTML += `<div class="chat-row ${mine ? 'user' : 'bot'}"><div class="chat-bubble"></div></div>`;
        box.lastElementChild.querySelector('.chat-bubble').textContent = m.message;
        lastId = Math.max(lastId, parseInt(m.id));
      });
      if (messages.length) box.scrollTop = box.scrollHeight;
    }
    async function poll(){
      try {
        const res = await fetch(`/api/consultation_messages.php?consultation_id=${consultationId}&after_id=${lastId}`);
        const data = await res.json();
        if (data.messages) render(data.messages);
      } catch(e) {}
    }
    document.getElementById('roomChatForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const input = document.getElementById('roomChatInput');
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      await fetch('/api/consultation_messages.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({consultation_id: consultationId, message: text})
      });
      poll();
    });
    poll();
    setInterval(poll, 4000);
  })();
  </script>
<?php endif; ?>
