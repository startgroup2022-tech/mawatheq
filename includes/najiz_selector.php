<?php
/**
 * Cascading Najiz classification selector.
 *
 * One implementation, used by the lawyer case form and the client consultation form,
 * so the two cannot drift. It renders four linked <select> elements and one hidden
 * input carrying the chosen lawsuit-type id; the JavaScript fills each level from
 * /api/najiz_classify.php as the level above changes.
 *
 * Progressive enhancement: the markup is a real form control set with no JavaScript
 * required to submit. Without JS the user can still submit a case or consultation —
 * only the dependent levels stay empty, and the server treats the classification as
 * optional. Nothing here is required for the platform to function.
 */

/**
 * @param string $name        name of the hidden field that carries najiz_case_type_id
 * @param int|null $selected  currently selected case-type id
 * @param bool $showRequests  render the optional judicial-request multi-select
 * @param string $requestsName field name for the request checkboxes
 */
function render_najiz_selector(string $name = 'najiz_case_type_id', ?int $selected = null, bool $showRequests = false, string $requestsName = 'najiz_request_ids'): void {
    $sel = $selected ? (int)$selected : 0;
    $uid = 'njs' . substr(md5($name . $sel . ($showRequests ? 'r' : '')), 0, 8);
    ?>
<div class="najiz-selector" id="<?= $uid ?>" data-api="<?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/api/najiz_classify.php"
     data-show-requests="<?= $showRequests ? '1' : '0' ?>" data-selected="<?= $sel ?>">
  <div class="najiz-grid">
    <div class="field">
      <label for="<?= $uid ?>-cat"><?= is_rtl() ? 'التصنيف الرئيسي' : 'Main category' ?></label>
      <select id="<?= $uid ?>-cat" class="najiz-cat"><option value=""><?= is_rtl() ? '— اختر —' : '— select —' ?></option></select>
    </div>
    <div class="field">
      <label for="<?= $uid ?>-sub"><?= is_rtl() ? 'التصنيف الفرعي' : 'Subcategory' ?></label>
      <select id="<?= $uid ?>-sub" class="najiz-sub" disabled><option value=""><?= is_rtl() ? '— اختر التصنيف الرئيسي أولاً —' : '— select a category first —' ?></option></select>
    </div>
    <div class="field">
      <label for="<?= $uid ?>-type"><?= is_rtl() ? 'نوع الدعوى' : 'Lawsuit type' ?></label>
      <select id="<?= $uid ?>-type" class="najiz-type" disabled><option value=""><?= is_rtl() ? '— اختر التصنيف الفرعي أولاً —' : '— select a subcategory first —' ?></option></select>
    </div>
  </div>
  <input type="hidden" name="<?= htmlspecialchars($name) ?>" class="najiz-value" value="<?= $sel ?: '' ?>">
  <p class="najiz-def" hidden></p>

  <?php if ($showRequests): ?>
  <div class="najiz-requests" hidden>
    <label><?= is_rtl() ? 'الطلبات القضائية المتاحة (اختياري)' : 'Available judicial requests (optional)' ?></label>
    <div class="najiz-req-list"></div>
  </div>
  <?php endif; ?>
</div>
<style>
.najiz-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:760px){.najiz-grid{grid-template-columns:1fr}}
.najiz-selector select{width:100%}
.najiz-def{margin:10px 0 0;font-size:13px;line-height:1.7;color:#475569;background:#f8fafc;border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:10px 12px}
.najiz-requests{margin-top:12px}
.najiz-req-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.najiz-req-list label{display:inline-flex;align-items:center;gap:6px;font-size:13px;background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:20px;padding:6px 12px;cursor:pointer}
.najiz-req-list input{width:auto;margin:0}
</style>
<script>
(function(){
  var root = document.getElementById('<?= $uid ?>');
  if (!root) return;
  var api = root.dataset.api;
  var cat = root.querySelector('.najiz-cat'), sub = root.querySelector('.najiz-sub'),
      type = root.querySelector('.najiz-type'), val = root.querySelector('.najiz-value'),
      def = root.querySelector('.najiz-def');
  var showReq = root.dataset.showRequests === '1';
  var reqBox = root.querySelector('.najiz-requests'), reqList = root.querySelector('.najiz-req-list');
  var pre = parseInt(root.dataset.selected || '0', 10) || 0;

  function get(url){ return fetch(url, {credentials:'same-origin'}).then(function(r){ return r.json(); }); }
  function fill(sel, items, ph){
    sel.innerHTML = '';
    var o = document.createElement('option'); o.value=''; o.textContent = ph; sel.appendChild(o);
    items.forEach(function(it){ var x=document.createElement('option'); x.value=it.id; x.textContent=it.name_ar; sel.appendChild(x); });
    sel.disabled = false;
  }

  function loadSubs(cid, keep){
    if(!cid){ sub.innerHTML='<option value=""><?= is_rtl() ? "— اختر التصنيف الرئيسي أولاً —" : "— select a category first —" ?></option>'; sub.disabled=true;
      type.innerHTML='<option value=""><?= is_rtl() ? "— اختر التصنيف الفرعي أولاً —" : "— select a subcategory first —" ?></option>'; type.disabled=true; val.value=''; def.hidden=true; if(reqBox)reqBox.hidden=true; return Promise.resolve(); }
    return get(api+'?action=subcategories&category_id='+encodeURIComponent(cid)).then(function(d){
      fill(sub, d.subcategories||[], '<?= is_rtl() ? "— اختر —" : "— select —" ?>');
      if(keep) sub.value = keep;
    });
  }
  function loadTypes(sid, keep){
    if(!sid){ type.innerHTML='<option value=""><?= is_rtl() ? "— اختر التصنيف الفرعي أولاً —" : "— select a subcategory first —" ?></option>'; type.disabled=true; val.value=''; def.hidden=true; if(reqBox)reqBox.hidden=true; return Promise.resolve(); }
    return get(api+'?action=types&subcategory_id='+encodeURIComponent(sid)).then(function(d){
      fill(type, d.types||[], '<?= is_rtl() ? "— اختر —" : "— select —" ?>');
      if(keep) type.value = keep;
      return d.types||[];
    });
  }
  function loadReqs(tid){
    if(!showReq || !reqBox) return;
    if(!tid){ reqBox.hidden=true; reqList.innerHTML=''; return; }
    get(api+'?action=requests&case_type_id='+encodeURIComponent(tid)).then(function(d){
      var items = d.requests||[];
      if(!items.length){ reqBox.hidden=true; reqList.innerHTML=''; return; }
      reqList.innerHTML='';
      items.forEach(function(it){
        var l=document.createElement('label');
        var c=document.createElement('input'); c.type='checkbox'; c.name='<?= htmlspecialchars($requestsName) ?>[]'; c.value=it.id;
        l.appendChild(c); l.appendChild(document.createTextNode(it.name_ar));
        reqList.appendChild(l);
      });
      reqBox.hidden=false;
    });
  }

  cat.addEventListener('change', function(){ loadSubs(cat.value).then(function(){ loadTypes('').then(function(){ loadReqs(''); }); }); });
  sub.addEventListener('change', function(){ loadTypes(sub.value).then(function(){ loadReqs(''); }); });
  type.addEventListener('change', function(){
    val.value = type.value || '';
    var opt = type.options[type.selectedIndex];
    var txt = opt ? (opt.dataset && opt.dataset.def) : '';
    if(txt){ def.textContent = txt; def.hidden=false; } else { def.hidden=true; }
    loadReqs(type.value);
  });

  // Initial load.
  get(api+'?action=categories').then(function(d){
    fill(cat, d.categories||[], '<?= is_rtl() ? "— اختر —" : "— select —" ?>');
    if(!pre) return;
    // Re-open the tree to the preselected type so an edit form shows its current value.
    return get(api+'?action=types&subcategory_id=0').catch(function(){ return null; }).then(function(){
      // Locate the preselected type's parents by walking the tree once.
      return get(api+'?action=tree').then(function(t){
        var t0 = (t.types||[]).filter(function(x){ return String(x.id)===String(pre); })[0];
        if(!t0) return;
        var s0 = (t.subcategories||[]).filter(function(x){ return String(x.id)===String(t0.subcategory_id); })[0];
        if(!s0) return;
        cat.value = s0.category_id;
        return loadSubs(s0.category_id, s0.id).then(function(){
          return loadTypes(s0.id, pre).then(function(types){
            var tdef = (types||[]).filter(function(x){ return String(x.id)===String(pre); })[0];
            if(tdef && tdef.definition){ def.textContent=tdef.definition; def.hidden=false; }
            loadReqs(pre);
          });
        });
      });
    });
  });
})();
</script>
    <?php
}
