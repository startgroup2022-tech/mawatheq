<?php
$activeAdmin = 'settings';
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/ngenius.php';
$saved = false; $pwError = null; $secretError=null;

/** حفظ آمن لأي مفتاح إعداد: يحدّث القيمة إن وُجد المفتاح، وإلا يُنشئه (يمنع فشل الحفظ الصامت). */
function save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settings') {
    foreach (['whatsapp_number','contact_email','site_name_ar','site_name_en'] as $key) {
        save_setting($pdo, $key, $_POST[$key] ?? '');
    }
    log_action($pdo, 'تحديث إعدادات الموقع العامة');
    $saved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_settings') {
    $provider=in_array($_POST['ai_provider'] ?? '',['','openai','anthropic'],true)?($_POST['ai_provider'] ?? ''):'';
    save_setting($pdo,'ai_provider',$provider); save_setting($pdo,'ai_model',trim($_POST['ai_model'] ?? ''));
    $newKey=trim($_POST['ai_api_key'] ?? '');
    if (isset($_POST['revoke_ai_key'])) { if(!secret_store_setting($pdo,'ai_api_key',''))$secretError='ضع مفتاح MAWATHEQ_ENCRYPTION_KEY بطول 32 حرفاً أو أكثر في config/secrets.php أولاً.'; }
    elseif($newKey!=='') { if(strlen($newKey)>500 || !secret_store_setting($pdo,'ai_api_key',$newKey))$secretError='تعذر تشفير المفتاح. اضبط MAWATHEQ_ENCRYPTION_KEY وتأكد من توفر OpenSSL.'; }
    foreach(['ai_endpoint','ai_backup_model','ai_max_tokens','ai_temperature','ai_timeout','ai_retries','ai_rate_limit'] as $key) if(isset($_POST[$key])) save_setting($pdo,$key,trim($_POST[$key]));
    save_setting($pdo, 'ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
    if(!$secretError){log_action($pdo,'تحديث إعدادات مزوّد الذكاء الاصطناعي (' . $provider . ')');$saved=true;}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'embedding_settings') {
    $embProvider = in_array($_POST['embedding_provider']??'', ['openai','disabled'], true) ? $_POST['embedding_provider'] : '';
    $embModel = trim((string)($_POST['embedding_model'] ?? 'text-embedding-3-small'));
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/',$embModel)) $embModel='text-embedding-3-small';
    save_setting($pdo,'embedding_provider',$embProvider); save_setting($pdo,'embedding_model',$embModel);
    $newEmbKey=trim((string)($_POST['embedding_api_key'] ?? ''));
    if (isset($_POST['revoke_embedding_key'])) { save_setting($pdo,'embedding_provider','disabled'); if(!secret_store_setting($pdo,'embedding_api_key',''))$secretError='اضبط MAWATHEQ_ENCRYPTION_KEY في config/secrets.php أولاً.'; }
    elseif($newEmbKey!=='') { if(strlen($newEmbKey)>500 || !secret_store_setting($pdo,'embedding_api_key',$newEmbKey))$secretError='تعذر تشفير مفتاح المتجهات؛ تحقق من مفتاح التشفير وOpenSSL.'; }
    if(!$secretError){log_action($pdo,'تحديث إعدادات مزوّد المتجهات');$saved=true;}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ngenius_settings') {
    save_setting($pdo, 'ngenius_environment', in_array($_POST['ngenius_environment'] ?? '', ['sandbox','production'], true) ? $_POST['ngenius_environment'] : 'sandbox');
    save_setting($pdo, 'ngenius_outlet_ref', trim($_POST['ngenius_outlet_ref'] ?? ''));
    $gatewayKey=trim($_POST['ngenius_api_key'] ?? ''); $webhookSecret=trim($_POST['ngenius_webhook_secret'] ?? '');
    if(isset($_POST['revoke_ngenius_keys'])) { secret_store_setting($pdo,'ngenius_api_key',''); secret_store_setting($pdo,'ngenius_webhook_secret',''); }
    else {
        if($gatewayKey!=='' && !secret_store_setting($pdo,'ngenius_api_key',$gatewayKey))$secretError='تعذر تشفير بيانات الدفع؛ اضبط مفتاح التشفير أولاً.';
        if($webhookSecret!=='' && !secret_store_setting($pdo,'ngenius_webhook_secret',$webhookSecret))$secretError='تعذر تشفير بيانات الدفع؛ اضبط مفتاح التشفير أولاً.';
    }
    save_setting($pdo, 'ngenius_realm_name', trim($_POST['ngenius_realm_name'] ?? 'ni'));
    save_setting($pdo, 'ngenius_enabled', isset($_POST['ngenius_enabled']) ? '1' : '0');
    log_action($pdo, 'تحديث إعدادات بوابة الدفع N-Genius (' . ($_POST['ngenius_environment'] ?? '') . ')');
    if(!$secretError)$saved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    if (strlen($_POST['new_password'] ?? '') < 8) {
        $pwError = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
    } else {
        $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([$hash, $_SESSION['admin_id']]);
        log_action($pdo, 'تغيير كلمة مرور حساب الإدارة الخاص به');
        $saved = true;
    }
}

$settings = [];
foreach ($pdo->query('SELECT * FROM site_settings')->fetchAll() as $s) $settings[$s['setting_key']] = $s['setting_value'];
$aiSecureSettings=ai_load_settings($pdo); $settings=array_merge($settings,$aiSecureSettings);
$embSecureSettings=embedding_load_settings($pdo); $settings['embedding_provider']=$embSecureSettings['embedding_provider']; $settings['embedding_model']=$embSecureSettings['embedding_model'];
$embKeyConfigured=($embSecureSettings['embedding_provider']==='openai'&&!empty($embSecureSettings['embedding_api_key']));
$embKnowledgePending=(int)$pdo->query("SELECT COUNT(*) FROM knowledge_sources WHERE status='active' AND processing_status='completed' AND embedding_status IN ('pending','not_configured','failed')")->fetchColumn();
$embLawPending=(int)$pdo->query('SELECT COUNT(*) FROM law_articles WHERE embedding IS NULL')->fetchColumn();
$ngeniusSecureSettings=ngenius_settings($pdo); $aiKeyConfigured=!empty($settings['ai_api_key']); $ngeniusKeyConfigured=!empty($ngeniusSecureSettings['ngenius_api_key']);
$settings['ngenius_api_key']=''; $settings['ngenius_webhook_secret']='';
$aiEnabledFlag = ($settings['ai_enabled'] ?? '1') !== '0';
$aiConfigured = !empty($settings['ai_provider']) && !empty($settings['ai_api_key']);
$aiActive = $aiConfigured && $aiEnabledFlag;

$ngeniusConfigured = !empty($settings['ngenius_outlet_ref']) && $ngeniusKeyConfigured;
$ngeniusActive = ngenius_is_enabled($ngeniusSecureSettings);
?>
<div class="app-content">
  <div class="pg-head"><div><h1>الإعدادات</h1><p>إعدادات المنصة، مزوّد الذكاء الاصطناعي، بوابة الدفع، وحسابك الشخصي</p></div></div>
  <?php if ($saved): ?><div class="form-alert success">تم الحفظ بنجاح — التغييرات سارية فوراً على النظام.</div><?php endif; ?>
  <?php if ($secretError): ?><div class="form-alert error"><?= htmlspecialchars($secretError) ?></div><?php endif; ?>

  <form class="dash-card" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="settings">
    <h3 style="margin-bottom:14px">إعدادات الموقع العامة</h3>
    <div class="grid-2">
      <div class="field"><label>اسم الموقع (عربي)</label><input type="text" name="site_name_ar" value="<?= htmlspecialchars($settings['site_name_ar'] ?? '') ?>"></div>
      <div class="field"><label>اسم الموقع (إنجليزي)</label><input type="text" name="site_name_en" value="<?= htmlspecialchars($settings['site_name_en'] ?? '') ?>"></div>
      <div class="field"><label>رقم واتساب (بدون +)</label><input type="text" name="whatsapp_number" value="<?= htmlspecialchars($settings['whatsapp_number'] ?? '') ?>"></div>
      <div class="field"><label>بريد التواصل</label><input type="email" name="contact_email" value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>"></div>
    </div>
    <button class="btn btn-primary" type="submit">حفظ الإعدادات</button>
  </form>

  <form class="dash-card" method="post" id="aiForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="ai_settings">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <h3 style="margin:0">ربط مزوّد الذكاء الاصطناعي</h3>
      <span class="status-pill status-<?= $aiActive ? 'confirmed' : 'pending' ?>">
        <?= $aiActive ? '✓ مفعّل ويعمل' : ($aiConfigured ? '⏸ موقوف يدوياً' : '— غير مربوط بعد') ?>
      </span>
    </div>
    <p style="font-size:.85rem;margin-bottom:16px">المزوّدان المدعومان حالياً بالمشروع فقط: <strong>Anthropic (Claude)</strong> و<strong>OpenAI (GPT)</strong>. لا تُستخدم أي خدمة أخرى. الصق مفتاح API الخاص بك واحفظ ليعمل المساعد فوراً.</p>
    <div class="form-alert" style="background:#EAF6F9;color:#1D6E82;font-size:.82rem">
      💡 لتقليل تكلفة التوكن: المساعد يبحث أولاً في مكتبة الأنظمة المحلية مجاناً، وإن وجد مادة ذات صلة يرسلها كسياق مختصر للنموذج بدل ترك النموذج "يبحث" بحرية.
    </div>

    <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:700;font-size:.9rem">
      <input type="checkbox" name="ai_enabled" value="1" <?= $aiEnabledFlag ? 'checked' : '' ?> style="width:20px;height:20px">
      تفعيل التكامل (يمكنك إيقافه مؤقتاً هنا دون حذف المفتاح)
    </label>

    <div class="grid-2">
      <div class="field">
        <label>المزوّد</label>
        <div class="select-wrap">
        <select name="ai_provider" id="aiProvider" class="pro-select" onchange="syncModelOptions()">
          <option value="" <?= empty($settings['ai_provider']) ? 'selected' : '' ?>>— اختر —</option>
          <option value="anthropic" <?= ($settings['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude)</option>
          <option value="openai" <?= ($settings['ai_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI (GPT)</option>
        </select>
        </div>
      </div>
      <div class="field">
        <label>نموذج جاهز (اختياري)</label>
        <div class="select-wrap">
        <select id="aiModelPreset" class="pro-select" onchange="document.getElementById('aiModelInput').value = this.value">
          <option value="">— اختر نموذجاً جاهزاً أو اكتب اسماً مخصصاً بالأسفل —</option>
          <optgroup label="Anthropic (Claude)" id="grp-anthropic">
            <option value="claude-3-5-haiku-latest">Claude 3.5 Haiku (أسرع وأرخص)</option>
            <option value="claude-3-5-sonnet-latest">Claude 3.5 Sonnet (متوازن)</option>
            <option value="claude-3-opus-latest">Claude 3 Opus (الأقوى)</option>
          </optgroup>
          <optgroup label="OpenAI (GPT)" id="grp-openai">
            <option value="gpt-4o-mini">GPT-4o mini (أسرع وأرخص)</option>
            <option value="gpt-4o">GPT-4o (متوازن)</option>
          </optgroup>
        </select>
        </div>
      </div>
    </div>
    <div class="field">
      <label>اسم النموذج المستخدم فعلياً</label>
      <input type="text" name="ai_model" id="aiModelInput" value="<?= htmlspecialchars($settings['ai_model'] ?? '') ?>" placeholder="مثال: claude-3-5-haiku-latest أو gpt-4o-mini">
    </div>
    <div class="field">
      <label>مفتاح API</label>
      <input type="password" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?= $aiKeyConfigured ? 'مفتاح محفوظ — أدخل قيمة جديدة للتدوير' : 'أدخل مفتاح API' ?>">
      <?php if($aiKeyConfigured): ?><label><input type="checkbox" name="revoke_ai_key" value="1"> إلغاء وحذف المفتاح المحفوظ</label><?php endif; ?>
      <small>المفتاح محفوظ مشفراً ولا يُعاد عرضه. أدخل مفتاحاً جديداً لتدويره. يتطلب ذلك MAWATHEQ_ENCRYPTION_KEY في config/secrets.php.</small>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">حفظ</button>
      <button class="btn btn-outline" type="button" id="testBtn" onclick="testConnection()">اختبار الاتصال</button>
      <span id="testResult" style="font-size:.85rem"></span>
    </div>
    <p style="font-size:.78rem;color:var(--text-muted);margin-top:10px">بدون تفعيل، يستمر المساعد بالعمل بردود مبنية على مكتبة الأنظمة المحلية فقط (مجاني بالكامل) حتى لا يتوقف الموقع. زر "اختبار الاتصال" يختبر آخر إعدادات محفوظة فعلياً — احفظ أولاً بعد أي تعديل ثم اختبر.</p>
  </form>
  <form class="dash-card" method="post" id="embForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <input type="hidden" name="action" value="embedding_settings">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">

      <h3 style="margin:0">البحث الدلالي (Semantic Search / Embeddings)</h3>

      <span class="status-pill status-<?= $embKeyConfigured ? 'confirmed' : 'pending' ?>">

        <?= $embKeyConfigured ? '✓ مفعّل' : '— غير مربوط بعد' ?>

      </span>

    </div>

    <p style="font-size:.85rem;margin-bottom:16px">يحوّل نصوص مكتبة الأنظمة والمعرفة إلى متجهات (Embeddings) لإيجاد المحتوى ذي الصلة حتى لو اختلفت صياغة سؤال العميل عن نص المصدر تماماً — مكمّل للبحث بالكلمات المفتاحية وليس بديلاً عنه. المزوّد المدعوم حالياً: <strong>OpenAI</strong> فقط (نموذج <code>text-embedding-3-small</code> افتراضياً).</p>

    <div class="grid-2">

      <div class="field">

        <label>المزوّد</label>

        <div class="select-wrap">

        <select name="embedding_provider" class="pro-select">

          <option value="disabled" <?= ($settings['embedding_provider']??'') === 'disabled' ? 'selected' : '' ?>>— إيقاف البحث الدلالي —</option>
          <option value="" <?= empty($settings['embedding_provider']) ? 'selected' : '' ?>>استخدم مفتاح دردشة OpenAI إن كان متاحاً</option>
          <option value="openai" <?= ($settings['embedding_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>

        </select>

        </div>

      </div>

      <div class="field">

        <label>اسم نموذج المتجهات</label>

        <input type="text" name="embedding_model" value="<?= htmlspecialchars($settings['embedding_model'] ?? 'text-embedding-3-small') ?>" placeholder="text-embedding-3-small">

      </div>

    </div>

    <div class="field">

      <label>مفتاح API لمزوّد المتجهات</label>

      <input type="password" name="embedding_api_key" value="" autocomplete="new-password" placeholder="<?= $embKeyConfigured ? 'مفتاح محفوظ — أدخل قيمة جديدة للتدوير' : 'أدخل مفتاح OpenAI API' ?>">

      <?php if($embKeyConfigured): ?><label><input type="checkbox" name="revoke_embedding_key" value="1"> إلغاء وحذف المفتاح المحفوظ</label><?php endif; ?>

      <small>إن تُرك فارغاً وكان مزوّد الدردشة أعلاه OpenAI بمفتاح محفوظ، يُستخدم نفس المفتاح تلقائياً للمتجهات.</small>

    </div>

    <button class="btn btn-primary" type="submit">حفظ إعدادات المتجهات</button>

    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border,#eee)">

      <p style="font-size:.85rem;margin-bottom:10px">

        مواد نظامية بدون متجه: <strong><?= $embLawPending ?></strong> — مصادر معرفة بدون متجه: <strong><?= $embKnowledgePending ?></strong>

      </p>

      <button class="btn btn-outline" type="button" id="reindexBtn" onclick="runReindex()" <?= $embKeyConfigured ? '' : 'disabled' ?>>إعادة فهرسة المتجهات الآن</button>

      <span id="reindexResult" style="font-size:.85rem;margin-inline-start:8px"></span>

      <p style="font-size:.78rem;color:var(--text-muted);margin-top:8px">تُعالج كل ضغطة دفعة محدودة (لتفادي انتهاء مهلة الاستضافة المشتركة) — كرّر الضغط حتى يصل العدادان أعلاه للصفر بعد تحديث الصفحة.</p>

    </div>

  </form>



  <div class="dash-card"><h3>المصادقة الثنائية للإدارة</h3><p>اربط تطبيق مصادقة برمز TOTP لحماية تسجيل دخول لوحة التحكم.</p><a class="btn btn-outline" href="/admin/two-factor.php">إعداد المصادقة الثنائية</a></div>
  <script>
  function syncModelOptions(){
    var provider = document.getElementById('aiProvider').value;
    document.getElementById('grp-anthropic').style.display = provider === 'anthropic' ? '' : 'none';
    document.getElementById('grp-openai').style.display = provider === 'openai' ? '' : 'none';
  }
  syncModelOptions();

  async function runReindex(){

    var btn = document.getElementById('reindexBtn');

    var out = document.getElementById('reindexResult');

    btn.disabled = true; var original = btn.textContent; btn.textContent = 'جارٍ الفهرسة...';

    out.style.color = 'var(--text-muted)'; out.textContent = '';

    try {

      const res = await fetch('/admin/reindex-embeddings.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'csrf_token=<?= urlencode(csrf_token()) ?>'});

      const data = await res.json();

      if (data.ok) {

        out.style.color = 'var(--success)';

        out.textContent = 'تمت معالجة ' + data.processed + ' — المتبقي: مواد ' + data.law_remaining + '، مصادر ' + data.knowledge_remaining + '.';

      } else {

        out.style.color = 'var(--danger)'; out.textContent = data.message || 'فشلت إعادة الفهرسة.';

      }

    } catch(e) { out.style.color = 'var(--danger)'; out.textContent = 'تعذّر الاتصال بالخادم.'; }

    btn.disabled = false; btn.textContent = original;

  }



  async function testConnection(){
    var btn = document.getElementById('testBtn');
    var out = document.getElementById('testResult');
    btn.disabled = true; btn.textContent = 'جارٍ الاختبار...';
    out.style.color = 'var(--text-muted)'; out.textContent = '';
    try {
      const res = await fetch('/admin/ai_test.php');
      const data = await res.json();
      out.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
      out.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
    } catch(e) {
      out.style.color = 'var(--danger)';
      out.textContent = '✗ تعذّر تنفيذ الاختبار.';
    }
    btn.disabled = false; btn.textContent = 'اختبار الاتصال';
  }
  </script>

  <form class="dash-card" method="post" id="ngeniusForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="ngenius_settings">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <h3 style="margin:0">بوابة الدفع السعودية (N-Genius — سداد / مدى / فيزا / ماستركارد)</h3>
      <span class="status-pill status-<?= $ngeniusActive ? 'confirmed' : 'pending' ?>">
        <?= $ngeniusActive ? '✓ مفعّلة' : ($ngeniusConfigured ? '⏸ موقوفة يدوياً' : '— غير مربوطة بعد') ?>
      </span>
    </div>
    <p style="font-size:.85rem;margin-bottom:16px">اربط حساب N-Genius الخاص بكم ليتمكن العملاء من دفع الفواتير مباشرة ببطاقتهم (مدى/فيزا/ماستركارد) من الموقع والتطبيق. بيانات الاتصال (Outlet Reference ومفتاح API) تجدونها من بوابة N-Genius: Settings ← Integrations ← Service Accounts، و Settings ← Organizational Hierarchy للـ Outlet Reference.</p>

    <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-weight:700;font-size:.9rem">
      <input type="checkbox" name="ngenius_enabled" value="1" <?= ($settings['ngenius_enabled'] ?? '0') === '1' ? 'checked' : '' ?> style="width:20px;height:20px">
      تفعيل الدفع الإلكتروني عبر N-Genius
    </label>

    <div class="grid-2">
      <div class="field">
        <label>البيئة</label>
        <div class="select-wrap"><select name="ngenius_environment" class="pro-select">
          <option value="sandbox" <?= ($settings['ngenius_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>تجريبية (Sandbox)</option>
          <option value="production" <?= ($settings['ngenius_environment'] ?? '') === 'production' ? 'selected' : '' ?>>حقيقية (Production — أموال فعلية)</option>
        </select></div>
      </div>
      <div class="field">
        <label>Outlet Reference</label>
        <input type="text" name="ngenius_outlet_ref" value="<?= htmlspecialchars($settings['ngenius_outlet_ref'] ?? '') ?>" placeholder="مثال: 5edab6d7-5946-43f4-b8c7-06b29c272bdd">
      </div>
      <div class="field">
        <label>Service Account API Key</label>
        <input type="password" name="ngenius_api_key" value="" autocomplete="new-password" placeholder="<?= $ngeniusKeyConfigured ? 'مفتاح محفوظ — أدخل قيمة جديدة للتدوير' : 'أدخل Service Account API Key' ?>">
      </div>
      <div class="field">
        <label>Realm Name</label>
        <input type="text" name="ngenius_realm_name" value="<?= htmlspecialchars($settings['ngenius_realm_name'] ?? 'ni') ?>" placeholder="ni">
        <p class="field-hint">القيمة الافتراضية "ni" — إن لم تعمل، تأكد من القيمة الصحيحة مع دعم N-Genius.</p>
      </div>
    </div>
    <div class="field">
      <label>Webhook Secret Header (اختياري لكن مستحسن)</label>
      <input type="password" name="ngenius_webhook_secret" value="" autocomplete="new-password" placeholder="<?= !empty($ngeniusSecureSettings['ngenius_webhook_secret']) ? 'سرّ محفوظ — أدخل قيمة جديدة للتدوير' : 'قيمة سرّية عشوائية' ?>">
      <?php if($ngeniusKeyConfigured || !empty($ngeniusSecureSettings['ngenius_webhook_secret'])): ?><label><input type="checkbox" name="revoke_ngenius_keys" value="1"> إلغاء مفاتيح الدفع والـWebhook المحفوظة</label><?php endif; ?>
      <p class="field-hint">أنشئ Webhook من بوابة N-Genius (Settings ← Integrations ← Webhooks) على الرابط: <code><?= rtrim(SITE_URL,'/') ?>/ngenius-webhook.php</code>، وضع نفس القيمة هنا وبحقل "Header Value" هناك باسم الترويسة <code>X-Webhook-Token</code>، لتأكيد أن الإشعار فعلاً من N-Genius.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">حفظ</button>
      <button class="btn btn-outline" type="button" id="ngeniusTestBtn" onclick="testNgenius()">اختبار الاتصال</button>
      <span id="ngeniusTestResult" style="font-size:.85rem"></span>
    </div>
  </form>
  <script>
  async function testNgenius(){
    var btn = document.getElementById('ngeniusTestBtn');
    var out = document.getElementById('ngeniusTestResult');
    btn.disabled = true; btn.textContent = 'جارٍ الاختبار...';
    out.textContent = '';
    try {
      const res = await fetch('/admin/payment-test.php');
      const data = await res.json();
      out.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
      out.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
    } catch(e) {
      out.style.color = 'var(--danger)';
      out.textContent = '✗ تعذّر تنفيذ الاختبار.';
    }
    btn.disabled = false; btn.textContent = 'اختبار الاتصال';
  }
  </script>

  <form class="dash-card" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <h3 style="margin-bottom:14px">تغيير كلمة مرور المدير</h3>
    <?php if ($pwError): ?><div class="form-alert error"><?= htmlspecialchars($pwError) ?></div><?php endif; ?>
    <div class="field"><label>كلمة مرور جديدة</label><input type="password" name="new_password" minlength="8" required></div>
    <button class="btn btn-outline" type="submit">تحديث كلمة المرور</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
