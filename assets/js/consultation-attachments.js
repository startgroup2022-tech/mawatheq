/* ============================================================
   Consultation attachments — drag-and-drop upload + preview.
   Vanilla JS, no dependency. Two modes:
     data-mode="draft"   the booking form: files are staged against a draft token and
                         claimed when the form is submitted.
     data-mode="attach"  a consultation detail page: files are stored against the
                         consultation id straight away.
   Uploads are one file per request via XHR so each file gets its own progress bar and
   its own error; a single rejected file never discards the rest.
   ============================================================ */
(function () {
  'use strict';

  var CATALOG = {
    document: { max: 20, ext: ['txt', 'doc', 'docx', 'pdf'] },
    image: { max: 20, ext: ['png', 'jpg', 'jpeg'] },
    audio: { max: 50, ext: ['mp3', 'wav', 'm4a', 'ogg'] },
    video: { max: 100, ext: ['mp4', 'mov', 'webm'] }
  };

  var TEXT = {
    ar: {
      tooLarge: function (name, mb) { return '«' + name + '» أكبر من الحد المسموح (' + mb + ' ميجابايت).'; },
      badType: function (name) { return 'صيغة «' + name + '» غير مدعومة.'; },
      empty: function (name) { return '«' + name + '» فارغ.'; },
      maxFiles: function (n) { return 'لا يمكن إضافة أكثر من ' + n + ' ملفات.'; },
      uploading: 'جارٍ الرفع…',
      done: 'تم',
      failed: 'فشل',
      removed: 'تم حذف الملف.',
      removeFailed: 'تعذّر حذف الملف.',
      network: 'تعذّر الاتصال بالخادم. تحقق من اتصالك وأعد المحاولة.',
      confirmRemove: 'حذف هذا المرفق؟',
      cancelled: 'أُلغي الرفع.'
    },
    en: {
      tooLarge: function (name, mb) { return '"' + name + '" is larger than the ' + mb + ' MB limit.'; },
      badType: function (name) { return 'The format of "' + name + '" is not supported.'; },
      empty: function (name) { return '"' + name + '" is empty.'; },
      maxFiles: function (n) { return 'You cannot add more than ' + n + ' files.'; },
      uploading: 'Uploading…',
      done: 'Done',
      failed: 'Failed',
      removed: 'The file was removed.',
      removeFailed: 'The file could not be removed.',
      network: 'Could not reach the server. Check your connection and try again.',
      confirmRemove: 'Remove this attachment?',
      cancelled: 'The upload was cancelled.'
    }
  };

  function lang() {
    return (document.documentElement.getAttribute('lang') || 'ar').indexOf('ar') === 0 ? 'ar' : 'en';
  }
  function t(key) { var a = arguments; return TEXT[lang()][key].apply(null, Array.prototype.slice.call(a, 1)); }

  function categoryOf(ext) {
    for (var cat in CATALOG) {
      if (CATALOG[cat].ext.indexOf(ext) !== -1) return cat;
    }
    return null;
  }
  function extensionOf(name) {
    var i = String(name).lastIndexOf('.');
    return i === -1 ? '' : String(name).slice(i + 1).toLowerCase();
  }
  function humanSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- preview lightbox (shared by every panel on the page) ---------- */
  var lightbox = document.querySelector('[data-ca-lightbox]');

  function openPreview(panel, id, name, mime) {
    if (!lightbox) return;
    var body = lightbox.querySelector('[data-ca-lightbox-body]');
    var foot = lightbox.querySelector('[data-ca-lightbox-foot]');
    var src = '/download-consultation-attachment.php?id=' + encodeURIComponent(id) + '&inline=1';
    var html = '';
    if (mime === 'application/pdf') {
      html = '<iframe src="' + src + '" title="' + escapeHtml(name) + '"></iframe>';
    } else if (mime === 'text/plain') {
      // Text is fetched and escaped rather than framed, so a file whose bytes look like
      // HTML is displayed as text and never parsed as markup in this origin.
      html = '<pre data-ca-text>…</pre>';
    } else if (mime.indexOf('image/') === 0) {
      html = '<img src="' + src + '" alt="' + escapeHtml(name) + '">';
    } else if (mime.indexOf('audio/') === 0) {
      html = '<audio controls preload="metadata" src="' + src + '"></audio>';
    } else if (mime.indexOf('video/') === 0) {
      html = '<video controls preload="metadata" src="' + src + '"></video>';
    } else {
      html = '<pre>' + escapeHtml(t('failed')) + '</pre>';
    }
    body.innerHTML = html;
    foot.innerHTML = '<span>' + escapeHtml(name) + '</span>' +
      '<a class="btn btn-primary btn-sm" href="/download-consultation-attachment.php?id=' + encodeURIComponent(id) + '">' +
      escapeHtml(lang() === 'ar' ? 'تنزيل' : 'Download') + '</a>';
    lightbox.hidden = false;

    var pre = body.querySelector('[data-ca-text]');
    if (pre) {
      fetch(src, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
        .then(function (text) { pre.textContent = text; })
        .catch(function () { pre.textContent = t('failed'); });
    }
  }

  if (lightbox) {
    lightbox.addEventListener('click', function (e) {
      if (e.target === lightbox || e.target.closest('[data-ca-close]')) {
        lightbox.hidden = true;
        lightbox.querySelector('[data-ca-lightbox-body]').innerHTML = '';
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !lightbox.hidden) {
        lightbox.hidden = true;
        lightbox.querySelector('[data-ca-lightbox-body]').innerHTML = '';
      }
    });
  }

  /* ---------- one panel ---------- */
  function initPanel(panel) {
    var uploader = panel.querySelector('[data-ca-uploader]');
    var list = panel.querySelector('[data-attachment-list]');
    var emptyMsg = panel.querySelector('[data-ca-empty]');
    var countBadge = panel.querySelector('[data-attachment-count]');
    var errorBox = panel.querySelector('[data-ca-errors]');
    var mode = uploader ? (uploader.getAttribute('data-mode') || 'attach') : null;
    var csrf = uploader ? uploader.getAttribute('data-csrf') : null;
    var endpoint = uploader ? uploader.getAttribute('data-endpoint') : null;
    var consultationId = panel.getAttribute('data-consultation-id');
    var maxFiles = 10;

    panel.addEventListener('click', function (e) {
      var preview = e.target.closest('[data-ca-preview]');
      if (preview) {
        var li = preview.closest('[data-attachment-id]');
        openPreview(panel, preview.getAttribute('data-ca-preview'), li.getAttribute('data-name'), li.getAttribute('data-mime'));
        return;
      }
      var remove = e.target.closest('[data-ca-remove]');
      if (remove) {
        e.preventDefault();
        if (!window.confirm(t('confirmRemove'))) return;
        removeAttachment(remove.getAttribute('data-ca-remove'), remove.closest('[data-attachment-id]'));
      }
    });

    function showErrors(messages) {
      if (!errorBox) return;
      if (!messages.length) { errorBox.innerHTML = ''; return; }
      errorBox.innerHTML = '<ul>' + messages.map(function (m) { return '<li>' + escapeHtml(m) + '</li>'; }).join('') + '</ul>';
    }
    function refreshCount() {
      if (!countBadge || !list) return;
      var n = list.querySelectorAll('[data-attachment-id]').length;
      countBadge.textContent = String(n);
      if (emptyMsg) emptyMsg.hidden = n > 0;
    }

    function removeAttachment(id, li) {
      var fd = new FormData();
      fd.append('action', mode === 'draft' ? 'discard' : 'remove');
      fd.append('attachment_id', id);
      fd.append('csrf_token', csrf);
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: t('removeFailed') }; }); })
        .then(function (data) {
          if (data.ok) {
            if (li) li.remove();
            refreshCount();
            showErrors([]);
          } else {
            showErrors([data.error || t('removeFailed')]);
          }
        })
        .catch(function () { showErrors([t('network')]); });
    }

    /* ---------- draft mode: restore files already staged in a previous view ---------- */
    if (mode === 'draft' && uploader) {
      var tokenField = document.querySelector('[data-ca-draft-token]');
      var token = tokenField ? tokenField.value : '';
      if (token) {
        fetch(endpoint + '?draft_token=' + encodeURIComponent(token), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok) return;
            maxFiles = data.limits ? data.limits.max_files : maxFiles;
            (data.files || []).forEach(function (f) { addDoneRow(f); });
            refreshCount();
          })
          .catch(function () { /* a restore failure is not worth an error banner */ });
      }
    }

    function addDoneRow(file) {
      if (!list) return;
      var li = document.createElement('li');
      li.className = 'ca-item';
      li.setAttribute('data-attachment-id', file.id);
      li.setAttribute('data-mime', file.mime || '');
      li.setAttribute('data-name', file.name);
      li.innerHTML =
        '<span class="ca-item-icon ca-cat-' + escapeHtml(file.category) + '">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
        '</span>' +
        '<span class="ca-item-body">' +
          '<span class="ca-item-name">' + escapeHtml(file.name) + '</span>' +
          '<span class="ca-item-meta"><span class="ca-badge ca-badge-' + escapeHtml(file.category) + '">' +
            escapeHtml(file.category) + '</span>' + escapeHtml(file.size_label || humanSize(file.size)) +
            ' · ' + escapeHtml(String(file.extension || '').toUpperCase()) + '</span>' +
        '</span>' +
        '<span class="ca-item-actions">' +
          (file.previewable ? '<button type="button" class="ca-btn" data-ca-preview="' + file.id + '"><span class="ca-sr">preview</span>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>' : '') +
          '<a class="ca-btn" href="/download-consultation-attachment.php?id=' + file.id + '"><span class="ca-sr">download</span>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></a>' +
          '<button type="button" class="ca-btn ca-btn-danger" data-ca-remove="' + file.id + '"><span class="ca-sr">remove</span>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>' +
        '</span>';
      list.appendChild(li);
    }

    if (!uploader) return;

    var drop = uploader.querySelector('[data-ca-drop]');
    var input = uploader.querySelector('[data-ca-input]');
    var queue = document.createElement('ul');
    queue.className = 'ca-queue';
    uploader.appendChild(queue);

    function addQueueRow(file) {
      var li = document.createElement('li');
      li.className = 'ca-queue-item';
      li.innerHTML =
        '<span class="ca-queue-body">' +
          '<span class="ca-queue-name">' + escapeHtml(file.name) + '</span>' +
          '<span class="ca-queue-size">' + escapeHtml(humanSize(file.size)) + '</span>' +
          '<span class="ca-progress"><span class="ca-progress-fill"></span></span>' +
        '</span>' +
        '<span class="ca-queue-state">0%</span>';
      queue.appendChild(li);
      return li;
    }

    function currentStagedCount() {
      return list ? list.querySelectorAll('[data-attachment-id]').length : 0;
    }

    /* Client-side validation runs BEFORE any bytes are sent, so an oversized or
       unsupported file is refused instantly instead of after a long upload. The server
       repeats every check; this is only to make the failure fast and legible. */
    function preflight(file) {
      var ext = extensionOf(file.name);
      var cat = categoryOf(ext);
      if (!cat) return t('badType', file.name);
      var limitMb = (uploader.getAttribute('data-limit-' + cat) | 0) || CATALOG[cat].max;
      if (file.size === 0) return t('empty', file.name);
      if (file.size > limitMb * 1048576) return t('tooLarge', file.name, limitMb);
      return null;
    }

    function uploadOne(file, onSettled) {
      var clientError = preflight(file);
      var row = addQueueRow(file);
      var fill = row.querySelector('.ca-progress-fill');
      var state = row.querySelector('.ca-queue-state');

      if (clientError) {
        row.classList.add('is-error');
        state.className = 'ca-queue-state bad';
        state.textContent = t('failed');
        fill.style.width = '100%';
        onSettled(clientError);
        return;
      }

      var fd = new FormData();
      fd.append('action', mode === 'draft' ? 'stage' : 'attach');
      fd.append('csrf_token', csrf);
      fd.append('file', file);
      if (mode === 'draft') {
        var tokenField = document.querySelector('[data-ca-draft-token]');
        fd.append('draft_token', tokenField ? tokenField.value : '');
      } else {
        fd.append('consultation_id', consultationId);
      }

      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.withCredentials = true;
      xhr.upload.addEventListener('progress', function (e) {
        if (!e.lengthComputable) return;
        var pct = Math.round((e.loaded / e.total) * 100);
        fill.style.width = pct + '%';
        state.textContent = pct + '%';
      });
      xhr.addEventListener('load', function () {
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (err) { data = null; }
        if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
          row.classList.add('is-done');
          fill.style.width = '100%';
          state.className = 'ca-queue-state ok';
          state.textContent = t('done');
          addDoneRow(data.file);
          refreshCount();
          window.setTimeout(function () { row.remove(); }, 1400);
          onSettled(null);
        } else {
          row.classList.add('is-error');
          fill.style.width = '100%';
          state.className = 'ca-queue-state bad';
          state.textContent = t('failed');
          onSettled((data && data.error) || t('network'));
        }
      });
      xhr.addEventListener('error', function () {
        row.classList.add('is-error');
        state.className = 'ca-queue-state bad';
        state.textContent = t('failed');
        onSettled(t('network'));
      });
      xhr.addEventListener('abort', function () {
        row.classList.add('is-error');
        state.className = 'ca-queue-state bad';
        state.textContent = t('cancelled');
        onSettled(t('cancelled'));
      });
      state.textContent = t('uploading');
      xhr.send(fd);
    }

    function handleFiles(files) {
      var errors = [];
      var list0 = Array.prototype.slice.call(files);
      if (!list0.length) return;
      var already = currentStagedCount();
      if (already + list0.length > maxFiles) {
        showErrors([t('maxFiles', maxFiles)]);
        return;
      }
      showErrors([]);
      // Sequential rather than parallel: the server enforces the per-consultation file
      // count and total-size quota, and two requests racing that check could both pass it
      // and then overshoot. Uploading one at a time makes the count authoritative.
      (function next(i) {
        if (i >= list0.length) {
          if (errors.length) showErrors(errors);
          return;
        }
        uploadOne(list0[i], function (err) {
          if (err) errors.push(err);
          next(i + 1);
        });
      })(0);
    }

    if (drop && input) {
      drop.addEventListener('click', function () { input.click(); });
      drop.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
      });
      input.addEventListener('change', function () {
        handleFiles(input.files);
        input.value = '';
      });

      ['dragenter', 'dragover'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) {
          e.preventDefault();
          e.stopPropagation();
          drop.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) {
          e.preventDefault();
          e.stopPropagation();
          drop.classList.remove('is-dragover');
        });
      });
      drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
      });

      // A drop anywhere else on the page would make the browser navigate away and lose
      // the form, which is a data-loss bug rather than a missing feature.
      ['dragover', 'drop'].forEach(function (evt) {
        window.addEventListener(evt, function (e) {
          if (!drop.contains(e.target)) e.preventDefault();
        });
      });
    }
  }

  function boot() {
    document.querySelectorAll('[data-attachments]').forEach(initPanel);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
