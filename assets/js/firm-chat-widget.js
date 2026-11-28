/**
 * ويدجت روبوت محادثة المكتب — يعمل على أي صفحة بوسم script واحد.
 *
 * Usage (the firm settings page prints this snippet):
 *   <script src="/assets/js/firm-chat-widget.js"
 *           data-key="<32-hex public key>"
 *           data-name="اسم المساعد"></script>
 *
 * Constraints this file respects, because the platform's CSP is `script-src 'self'`:
 *  - no inline event handlers and no eval; every listener is added here;
 *  - no third-party script, font or stylesheet is fetched;
 *  - the only network call is to /api/firm-chat.php on this origin.
 *
 * The visitor key is random and stored in localStorage. It is deliberately NOT an IP
 * address: the server must never need to know who the visitor is to serve them.
 */
(function () {
  'use strict';

  var script = document.currentScript || (function () {
    var all = document.querySelectorAll('script[data-key]');
    return all.length ? all[all.length - 1] : null;
  })();
  if (!script) return;

  var key = (script.getAttribute('data-key') || '').trim();
  if (!/^[a-f0-9]{32}$/i.test(key)) return;

  var botName = script.getAttribute('data-name') || 'المساعد';
  var endpoint = '/api/firm-chat.php';
  var storageKey = 'mwq_chat_visitor_' + key;

  function visitorKey() {
    var v = '';
    try { v = localStorage.getItem(storageKey) || ''; } catch (e) { v = ''; }
    if (!/^[a-f0-9]{32}$/i.test(v)) {
      var bytes = new Uint8Array(16);
      if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(bytes);
      } else {
        for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
      }
      v = Array.prototype.map.call(bytes, function (b) {
        return ('0' + b.toString(16)).slice(-2);
      }).join('');
      try { localStorage.setItem(storageKey, v); } catch (e) {}
    }
    return v;
  }

  var visitor = visitorKey();
  var conversation = null;
  var busy = false;

  // ---- styles injected once, scoped to the widget's own class names ---------------
  var css = ''
    + '.mwq-chat-btn{position:fixed;inset-inline-end:20px;inset-block-end:20px;z-index:9998;'
    + 'background:#0F1F33;color:#fff;border:0;border-radius:999px;padding:14px 20px;font:600 14px/1 inherit;'
    + 'cursor:pointer;box-shadow:0 8px 24px rgba(15,31,51,.28)}'
    + '.mwq-chat-btn:hover{background:#16324f}'
    + '.mwq-chat-panel{position:fixed;inset-inline-end:20px;inset-block-end:20px;z-index:9999;width:360px;'
    + 'max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 40px);display:none;flex-direction:column;'
    + 'background:#fff;border:1px solid #d8e0ea;border-radius:16px;overflow:hidden;'
    + 'box-shadow:0 18px 48px rgba(15,31,51,.22);font:14px/1.6 system-ui,"Segoe UI",Tahoma,sans-serif}'
    + '.mwq-chat-panel.is-open{display:flex}'
    + '.mwq-chat-head{background:#0F1F33;color:#fff;padding:14px 16px;display:flex;align-items:center;'
    + 'justify-content:space-between;gap:8px}'
    + '.mwq-chat-head strong{font-size:14px}'
    + '.mwq-chat-close{background:transparent;border:0;color:#fff;font-size:20px;line-height:1;cursor:pointer;padding:4px 8px}'
    + '.mwq-chat-body{flex:1;overflow-y:auto;padding:14px;background:#f6f8fb}'
    + '.mwq-msg{margin-bottom:10px;padding:10px 12px;border-radius:12px;max-width:88%;white-space:pre-wrap;word-wrap:break-word}'
    + '.mwq-msg-bot{background:#fff;border:1px solid #e2e8f0;color:#1c2b3a}'
    + '.mwq-msg-me{background:#0f6b5c;color:#fff;margin-inline-start:auto}'
    + '.mwq-chat-foot{border-top:1px solid #e2e8f0;padding:10px;display:flex;gap:8px;background:#fff}'
    + '.mwq-chat-foot input{flex:1;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font:inherit}'
    + '.mwq-chat-foot button{background:#0f6b5c;color:#fff;border:0;border-radius:10px;padding:10px 16px;'
    + 'font:600 13px/1 inherit;cursor:pointer}'
    + '.mwq-chat-foot button:disabled{opacity:.55;cursor:default}'
    + '.mwq-chat-contact{border-top:1px solid #e2e8f0;padding:10px;background:#fbfcfe;display:none;gap:6px;flex-wrap:wrap}'
    + '.mwq-chat-contact.is-open{display:flex}'
    + '.mwq-chat-contact input{flex:1 1 120px;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font:inherit}'
    + '.mwq-chat-contact button{background:#0F1F33;color:#fff;border:0;border-radius:8px;padding:8px 14px;'
    + 'font:600 12px/1 inherit;cursor:pointer}'
    + '.mwq-chat-note{font-size:12px;color:#64748b;padding:0 14px 10px;background:#f6f8fb}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  // ---- markup -------------------------------------------------------------------
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'mwq-chat-btn';
  btn.setAttribute('aria-label', 'فتح المحادثة');
  btn.textContent = '💬 ' + botName;

  var panel = document.createElement('div');
  panel.className = 'mwq-chat-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', botName);
  panel.innerHTML = ''
    + '<div class="mwq-chat-head"><strong></strong><button type="button" class="mwq-chat-close" aria-label="إغلاق">×</button></div>'
    + '<div class="mwq-chat-body" role="log" aria-live="polite"></div>'
    + '<div class="mwq-chat-contact">'
    + '  <input type="text" name="name" placeholder="الاسم" autocomplete="name">'
    + '  <input type="tel" name="phone" placeholder="رقم الجوال" autocomplete="tel" inputmode="tel">'
    + '  <button type="button" data-action="contact">إرسال</button>'
    + '</div>'
    + '<div class="mwq-chat-foot">'
    + '  <input type="text" name="message" placeholder="اكتب رسالتك…" autocomplete="off" maxlength="2000">'
    + '  <button type="button" data-action="send">إرسال</button>'
    + '</div>'
    + '<div class="mwq-chat-note">هذا المساعد يعرّف بالمكتب ولا يقدّم رأياً قانونياً.</div>';

  panel.querySelector('.mwq-chat-head strong').textContent = botName;
  document.body.appendChild(btn);
  document.body.appendChild(panel);

  var body = panel.querySelector('.mwq-chat-body');
  var input = panel.querySelector('input[name="message"]');
  var sendBtn = panel.querySelector('[data-action="send"]');
  var contactBox = panel.querySelector('.mwq-chat-contact');

  function addMsg(text, mine) {
    var d = document.createElement('div');
    d.className = 'mwq-msg ' + (mine ? 'mwq-msg-me' : 'mwq-msg-bot');
    d.textContent = text;
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
    return d;
  }

  function post(payload) {
    return fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().then(function (j) { return { status: r.status, body: j }; });
    });
  }

  function open() {
    panel.classList.add('is-open');
    btn.style.display = 'none';
    if (conversation === null) {
      post({ action: 'start', key: key, visitor: visitor }).then(function (res) {
        if (!res.body || !res.body.ok) {
          addMsg('تعذّر بدء المحادثة. حاول لاحقاً.', false);
          return;
        }
        conversation = res.body.conversation;
        var msgs = res.body.messages || [];
        if (msgs.length) {
          msgs.forEach(function (m) { addMsg(m.message, m.sender === 'visitor'); });
        } else {
          addMsg(res.body.greeting, false);
        }
        input.focus();
      }).catch(function () {
        addMsg('تعذّر الاتصال بالخادم.', false);
      });
    } else {
      input.focus();
    }
  }

  function close() {
    panel.classList.remove('is-open');
    btn.style.display = '';
  }

  function send() {
    var text = (input.value || '').trim();
    if (!text || busy || conversation === null) return;
    busy = true;
    sendBtn.disabled = true;
    addMsg(text, true);
    input.value = '';
    post({ action: 'send', key: key, visitor: visitor, conversation: conversation, message: text })
      .then(function (res) {
        if (res.body && res.body.ok) {
          addMsg(res.body.reply, false);
          if (res.body.offer_handoff) contactBox.classList.add('is-open');
        } else if (res.body && res.body.error === 'rate_limited') {
          addMsg('تم إرسال عدد كبير من الرسائل. انتظر قليلاً ثم أعد المحاولة.', false);
        } else {
          addMsg('تعذّر إرسال الرسالة.', false);
        }
      })
      .catch(function () { addMsg('تعذّر الاتصال بالخادم.', false); })
      .then(function () { busy = false; sendBtn.disabled = false; input.focus(); });
  }

  function sendContact() {
    var name = (contactBox.querySelector('input[name="name"]').value || '').trim();
    var phone = (contactBox.querySelector('input[name="phone"]').value || '').trim();
    if (!phone) return;
    post({ action: 'contact', key: key, visitor: visitor, conversation: conversation, name: name, phone: phone })
      .then(function (res) {
        if (res.body && res.body.ok) {
          addMsg(res.body.message, false);
          contactBox.classList.remove('is-open');
        } else {
          addMsg('تعذّر حفظ رقم الجوال. تأكد من الرقم وحاول مجدداً.', false);
        }
      })
      .catch(function () { addMsg('تعذّر الاتصال بالخادم.', false); });
  }

  btn.addEventListener('click', open);
  panel.querySelector('.mwq-chat-close').addEventListener('click', close);
  sendBtn.addEventListener('click', send);
  panel.querySelector('[data-action="contact"]').addEventListener('click', sendContact);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); send(); }
  });
})();
