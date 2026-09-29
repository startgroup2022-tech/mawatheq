<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/knowledge_center.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/audit_log.php';

$modules = [
    '*'=>'مشترك لكل الوحدات', 'legal_core'=>'المساعد القانوني', 'legal_research'=>'البحث القانوني',
    'case_intelligence'=>'ذكاء القضايا', 'case_analysis'=>'تحليل القضايا', 'evidence_intelligence'=>'تحليل الأدلة',
    'document_ai'=>'تحليل المستندات', 'legal_drafting'=>'الصياغة القانونية', 'contract_intelligence'=>'تحليل العقود',
    'firm_ai'=>'ذكاء المكتب', 'compliance'=>'الامتثال', 'opponent_analysis'=>'تحليل الخصم',
    'meeting_ai'=>'الاجتماعات', 'hearing_ai'=>'الجلسات', 'translation'=>'الترجمة',
    'summarization'=>'التلخيص', 'red_team'=>'مراجعة الخصم الآلي', 'agent'=>'الوكلاء',
];
$message = null; $error = null;
$isSuperAdmin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
$knowledgeDir = dirname(__DIR__) . '/uploads/knowledge';
$aiSettings = ai_load_settings($pdo);
if (!is_dir($knowledgeDir)) @mkdir($knowledgeDir, 0750, true);

function knowledge_upload_mime_ok(string $path, string $extension): array {
    if (!function_exists('finfo_open')) return [false,'fileinfo is unavailable'];
    $f = finfo_open(FILEINFO_MIME_TYPE); $mime = $f ? finfo_file($f,$path) : '';
    if ($f) finfo_close($f);
    $allowed = [
        'pdf'=>['application/pdf'], 'doc'=>['application/msword','application/octet-stream'],
        'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
        'xls'=>['application/vnd.ms-excel','application/octet-stream'],
        'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
        'txt'=>['text/plain'], 'md'=>['text/plain'], 'csv'=>['text/plain','text/csv','application/vnd.ms-excel'],
        'jpg'=>['image/jpeg'], 'jpeg'=>['image/jpeg'], 'png'=>['image/png'], 'webp'=>['image/webp'],
    ];
    if (!isset($allowed[$extension]) || !in_array($mime,$allowed[$extension],true)) return [false,'File extension and detected MIME type do not match.'];
    if ($extension==='pdf') { $h=fopen($path,'rb'); $sig=$h?fread($h,5):''; if($h)fclose($h); if($sig!=='%PDF-')return [false,'Invalid PDF signature.']; }
    if (in_array($extension,['jpg','jpeg','png','webp'],true) && !@getimagesize($path)) return [false,'Invalid image file.'];
    return [true,$mime];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action==='create') {
            $name=trim($_POST['source_name'] ?? ''); $module=$_POST['module_id'] ?? '*'; $type=$_POST['source_type'] ?? 'manual';
            $scope=$_POST['scope_type'] ?? 'global'; $scopeId=(int)($_POST['scope_id'] ?? 0);
            if (($name==='' && $type!=='website') || !isset($modules[$module]) || !in_array($type,['manual','website','file'],true) || !in_array($scope,['global','firm','case','private'],true)) throw new RuntimeException('تحقق من اسم المصدر والوحدة والنطاق.');
            if ($scope!=='global' && $scopeId<1) throw new RuntimeException('أدخل رقم المكتب أو القضية للنطاق المحدد.');
            $content=''; $sourceUrl=null; $domain=null; $filename=null; $mime=null; $filePath=null; $needsExtraction=false; $processingMessage=''; $retrieved=null; $ocrUsed=false;
            if ($type==='manual') {
                $content=knowledge_clean_text((string)($_POST['content'] ?? ''));
                if (mb_strlen($content,'UTF-8')<20) throw new RuntimeException('أدخل محتوى لا يقل عن 20 حرفاً.');
            } elseif ($type==='website') {
                $sourceUrl=trim($_POST['source_url'] ?? '');
                $fetched=knowledge_fetch_website($sourceUrl);
                if (empty($fetched['ok'])) throw new RuntimeException($fetched['error'] ?? 'تعذر استيراد الرابط.');
                $content=$fetched['content']; $domain=$fetched['domain']; $retrieved=$fetched['retrieved_at'];
                if ($name==='') $name=$fetched['title'];
            } else {
                $file=$_FILES['source_file'] ?? [];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('اختر ملفاً صالحاً للرفع.');
                if ((int)$file['size']<1 || (int)$file['size']>MAX_UPLOAD_SIZE_MB*1024*1024) throw new RuntimeException('حجم الملف غير مسموح؛ الحد الحالي ' . MAX_UPLOAD_SIZE_MB . ' ميغابايت.');
                $extension=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
                [$valid,$mimeResult]=knowledge_upload_mime_ok($file['tmp_name'],$extension);
                if (!$valid) throw new RuntimeException($mimeResult);
                $filename=basename((string)$file['name']); $mime=$mimeResult;
                $stored=bin2hex(random_bytes(24)) . '.' . $extension;
                if (!move_uploaded_file($file['tmp_name'],$knowledgeDir . DIRECTORY_SEPARATOR . $stored)) throw new RuntimeException('تعذر حفظ الملف في التخزين الخاص.');
                $filePath='uploads/knowledge/' . $stored;
                $extracted=knowledge_extract_file($knowledgeDir . DIRECTORY_SEPARATOR . $stored,$extension);
                if (!empty($extracted['ok'])) $content=$extracted['content'];
                elseif(isset($_POST['ai_extraction_consent']) && in_array($extension,['pdf','jpg','jpeg','png','webp'],true)) {
                    $ocr=extract_knowledge_text_with_ai((string)($aiSettings['ai_provider']??''),(string)($aiSettings['ai_api_key']??''),(string)($aiSettings['ai_model']??''),$knowledgeDir . DIRECTORY_SEPARATOR . $stored,$extension);
                    if(!empty($ocr['ok'])){$content=$ocr['text'];$ocrUsed=true;$processingMessage='OCR transcription generated by the configured AI provider; review accuracy before legal reliance.';}
                    else{$needsExtraction=true;$processingMessage=$ocr['error']??'OCR failed.';}
                } else { $needsExtraction=true; $processingMessage=$extracted['error'] ?? 'Text extraction is unavailable.'; }
                if ($name==='') $name=pathinfo($filename,PATHINFO_FILENAME);
            }
            $source=['module_id'=>$module,'source_type'=>$type,'source_name'=>$name,'source_url'=>$sourceUrl,'source_domain'=>$domain,
                'original_filename'=>$filename,'mime_type'=>$mime,'language'=>in_array($_POST['language'] ?? '',['ar','en'],true)?$_POST['language']:null,
                'jurisdiction'=>trim($_POST['jurisdiction'] ?? '') ?: null,'category'=>trim($_POST['category'] ?? '') ?: null,
                'tags'=>trim($_POST['tags'] ?? '') ?: null,'scope_type'=>$scope,'scope_id'=>$scope==='global'?null:$scopeId,
                'verified'=>$isSuperAdmin && !$ocrUsed && isset($_POST['verified']),'created_by_type'=>'admin','created_by'=>(int)$_SESSION['admin_id'],
                'needs_extraction'=>$needsExtraction,'processing_message'=>$processingMessage,'retrieved_at'=>$retrieved];
            $pdo->beginTransaction();
            $sourceId=knowledge_store_source($pdo,$source,$content,$filePath);
            $pdo->commit();
            if($content!=='')try{knowledge_index_embeddings($pdo,$sourceId);}catch(Throwable $e){error_log('Embedding indexing error: '.$e->getMessage());}
            if($type==='file'&&$content!==''&&$processingMessage!=='')knowledge_log($pdo,$sourceId,'ai_ocr','completed',$processingMessage);
            log_action($pdo,'إضافة مصدر معرفة: ' . $name,'knowledge_source',$sourceId);
            $message=$needsExtraction?'حُفظ المصدر بأمان لكنه غير متاح للذكاء الاصطناعي حتى إعداد استخراج النص/OCR.':($ocrUsed?'استخرج النظام النص بـOCR وحفظه كمصدر غير موثق؛ راجعه واعتمده يدوياً بعد التأكد.':'أضيف المصدر وفُهرس للبحث النصي.');
        } elseif ($action==='update_source') {
            $id=(int)($_POST['source_id']??0); $q=$pdo->prepare('SELECT * FROM knowledge_sources WHERE id=?'); $q->execute([$id]); $src=$q->fetch();
            if (!$src) throw new RuntimeException('المصدر غير موجود.');
            $name=trim((string)($_POST['source_name']??'')); $content=knowledge_clean_text((string)($_POST['content']??''));
            $module=isset($modules[$_POST['module_id']??''])?(string)$_POST['module_id']:''; $scope=(string)($_POST['scope_type']??''); $scopeId=(int)($_POST['scope_id']??0);
            if ($name===''||mb_strlen($name,'UTF-8')>255||$module===''||!in_array($scope,['global','firm','case','private'],true)||($scope!=='global'&&$scopeId<1)||mb_strlen($content,'UTF-8')<20||mb_strlen($content,'UTF-8')>500000) throw new RuntimeException('تحقق من الاسم والوحدة والنطاق والمحتوى (20 إلى 500000 حرف).');
            $newVersion=(int)$src['version']+1; $verified=$isSuperAdmin&&isset($_POST['verified'])?'verified':'unverified';
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO knowledge_source_versions (source_id,version,source_name,source_url,content,metadata_json,changed_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id,$newVersion,$name,$src['source_url'],$content,json_encode(['scope_type'=>$scope,'scope_id'=>$scope==='global'?null:$scopeId,'module_id'=>$module,'jurisdiction'=>trim((string)($_POST['jurisdiction']??''))?:null],JSON_UNESCAPED_UNICODE),(int)$_SESSION['admin_id']]);
            $pdo->prepare('UPDATE knowledge_sources SET source_name=?,module_id=?,scope_type=?,scope_id=?,jurisdiction=?,category=?,tags=?,version=?,verification_status=?,updated_by=? WHERE id=?')
                ->execute([$name,$module,$scope,$scope==='global'?null:$scopeId,trim((string)($_POST['jurisdiction']??''))?:null,trim((string)($_POST['category']??''))?:null,trim((string)($_POST['tags']??''))?:null,$newVersion,$verified,(int)$_SESSION['admin_id'],$id]);
            $count=knowledge_index_text($pdo,$id,$content); $pdo->commit();
            try{knowledge_index_embeddings($pdo,$id);}catch(Throwable $e){error_log('Embedding indexing error: '.$e->getMessage());}
            knowledge_log($pdo,$id,'version_update','completed','Saved version '.$newVersion.' and indexed '.$count.' chunks.');
            log_action($pdo,'تعديل مصدر معرفة وإنشاء إصدار '.$newVersion,'knowledge_source',$id);
            $message='تم حفظ الإصدار '.$newVersion.' وإعادة فهرسة المحتوى.';
        } elseif ($action==='reindex') {
            $id=(int)($_POST['source_id'] ?? 0); $q=$pdo->prepare('SELECT * FROM knowledge_sources WHERE id=?'); $q->execute([$id]); $src=$q->fetch();
            if (!$src) throw new RuntimeException('المصدر غير موجود.');
            $text=$src['content'] ?? ''; $sourceDomain=$src['source_domain']; $retrievedAt=$src['retrieved_at'];
            if ($src['source_type']==='file' && $src['storage_path']) {
                $base=realpath($knowledgeDir); $candidate=realpath(dirname(__DIR__) . '/' . $src['storage_path']);
                if (!$base || !$candidate || strpos($candidate,$base . DIRECTORY_SEPARATOR)!==0) throw new RuntimeException('مسار الملف غير صالح.');
                $ext=strtolower(pathinfo($candidate,PATHINFO_EXTENSION)); $extract=knowledge_extract_file($candidate,$ext);
                if (empty($extract['ok']) && isset($_POST['ai_extraction_consent']) && in_array($ext,['pdf','jpg','jpeg','png','webp'],true)) {
                    $ocr=extract_knowledge_text_with_ai((string)($aiSettings['ai_provider']??''),(string)($aiSettings['ai_api_key']??''),(string)($aiSettings['ai_model']??''),$candidate,$ext);
                    if(!empty($ocr['ok'])){$extract=['ok'=>true,'content'=>$ocr['text']];knowledge_log($pdo,$id,'ai_ocr','completed','OCR transcription generated; review accuracy before legal reliance.');}
                    else $extract['error']=$ocr['error']??'OCR failed.';
                }
                if (empty($extract['ok'])) { $pdo->prepare("UPDATE knowledge_sources SET processing_status='needs_extraction',indexing_status='pending' WHERE id=?")->execute([$id]); throw new RuntimeException($extract['error'] ?? 'تعذر استخراج النص.'); }
                $text=$extract['content'];
            } elseif ($src['source_type']==='website' && $src['source_url']) {
                $fetch=knowledge_fetch_website($src['source_url']);
                if (empty($fetch['ok'])) throw new RuntimeException($fetch['error'] ?? 'تعذر جلب الصفحة.');
                $text=$fetch['content']; $sourceDomain=$fetch['domain']; $retrievedAt=$fetch['retrieved_at'];
            }
            $text=knowledge_clean_text($text); $changed=hash('sha256',$text)!==(string)($src['content_hash']??''); $newVersion=(int)$src['version']+($changed?1:0);
            $pdo->beginTransaction();
            if($changed) $pdo->prepare('INSERT INTO knowledge_source_versions (source_id,version,source_name,source_url,content,metadata_json,changed_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id,$newVersion,$src['source_name'],$src['source_url'],$text,json_encode(['source_type'=>$src['source_type'],'retrieved_at'=>$retrievedAt],JSON_UNESCAPED_UNICODE),(int)$_SESSION['admin_id']]);
            $count=knowledge_index_text($pdo,$id,$text);
            if($changed) $pdo->prepare("UPDATE knowledge_sources SET version=?,verification_status='unverified',source_domain=?,retrieved_at=?,updated_by=? WHERE id=?")
                ->execute([$newVersion,$sourceDomain,$retrievedAt,(int)$_SESSION['admin_id'],$id]);
            $pdo->commit();
            try{knowledge_index_embeddings($pdo,$id);}catch(Throwable $e){error_log('Embedding indexing error: '.$e->getMessage());}
            knowledge_log($pdo,$id,'reindex','completed','Reindexed ' . $count . ' chunks.');
            log_action($pdo,'إعادة فهرسة مصدر معرفة','knowledge_source',$id);
            $message='تمت إعادة معالجة المصدر وفهرسته.';
        } elseif ($action==='delete') {
            $id=(int)($_POST['source_id'] ?? 0); $q=$pdo->prepare('SELECT storage_path,source_name FROM knowledge_sources WHERE id=?'); $q->execute([$id]); $src=$q->fetch();
            if ($src) {
                if ($src['storage_path']) { $base=realpath($knowledgeDir); $candidate=realpath(dirname(__DIR__) . '/' . $src['storage_path']); if ($base && $candidate && strpos($candidate,$base . DIRECTORY_SEPARATOR)===0) @unlink($candidate); }
                $pdo->prepare('DELETE FROM knowledge_sources WHERE id=?')->execute([$id]);
                log_action($pdo,'حذف مصدر معرفة: ' . $src['source_name'],'knowledge_source',$id);
            }
            $message='تم حذف المصدر ونسخه ومقاطع الفهرسة التابعة له.';
        } else throw new RuntimeException('إجراء غير معروف.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Knowledge center action failed: ' . $e->getMessage());
        $error=$e instanceof RuntimeException ? $e->getMessage() : 'تعذر إكمال العملية. راجع سجل PHP على الخادم.';
    }
}

$filter=trim($_GET['q'] ?? ''); $moduleFilter=$_GET['module'] ?? '';
$sql='SELECT s.*, (SELECT COUNT(*) FROM knowledge_chunks k WHERE k.source_id=s.id) chunk_count FROM knowledge_sources s WHERE 1=1'; $params=[];
if ($filter!=='') { $sql.=' AND (s.source_name LIKE ? OR s.category LIKE ? OR s.tags LIKE ? OR s.source_url LIKE ?)'; $like='%'.$filter.'%'; array_push($params,$like,$like,$like,$like); }
if ($moduleFilter!=='' && isset($modules[$moduleFilter])) { $sql.=' AND s.module_id=?'; $params[]=$moduleFilter; }
$sql.=' ORDER BY s.updated_at DESC LIMIT 200'; $stmt=$pdo->prepare($sql); $stmt->execute($params); $sources=$stmt->fetchAll();
$versionHistory=[];
if($sources){$ids=array_map('intval',array_column($sources,'id'));$marks=implode(',',array_fill(0,count($ids),'?'));$vh=$pdo->prepare("SELECT source_id,version,source_name,created_at FROM knowledge_source_versions WHERE source_id IN ($marks) ORDER BY version DESC");$vh->execute($ids);foreach($vh->fetchAll() as $v){$sid=(int)$v['source_id'];if(!isset($versionHistory[$sid]))$versionHistory[$sid]=[];if(count($versionHistory[$sid])<4)$versionHistory[$sid][]=$v;}}
$stats=$pdo->query("SELECT COUNT(*) total,SUM(source_type='file') files,SUM(source_type='website') websites,SUM(source_type='manual') manual,SUM(processing_status='needs_extraction') needs_extraction,SUM(processing_status='failed') failed FROM knowledge_sources")->fetch();
$activeAdmin='ai-knowledge'; require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar"><h2>🧠 مركز معرفة الذكاء الاصطناعي <span class="count-badge"><?= (int)$stats['total'] ?></span></h2></div>
  <?php if($message): ?><div class="form-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <p class="field-hint">المحتوى المستورد يُعامل كبيانات غير موثوقة ولا يغيّر تعليمات النظام. المصادر غير الموثقة لا تدخل في الإجابات القانونية الموثقة. البحث الحالي فهرسة كلمات؛ البحث الدلالي يحتاج مزود فهرسة منفصلاً. OCR يرسل الملف إلى مزود AI فقط عند اختيار الموافقة في كل عملية.</p>
  <div class="stat-grid"><div class="stat-card"><div class="num"><?= (int)$stats['files'] ?></div><div class="label">ملفات</div></div><div class="stat-card"><div class="num"><?= (int)$stats['websites'] ?></div><div class="label">مواقع</div></div><div class="stat-card"><div class="num"><?= (int)$stats['manual'] ?></div><div class="label">إدخالات يدوية</div></div><div class="stat-card"><div class="num"><?= (int)$stats['needs_extraction'] ?></div><div class="label">تحتاج استخراج نص/OCR</div></div></div>
  <div class="dash-card"><h3>إضافة مصدر</h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="create"><div class="grid-2">
    <div class="field"><label>اسم المصدر (اختياري للرابط والملف)</label><input name="source_name" maxlength="255"></div>
    <div class="field"><label>نوع المصدر</label><select name="source_type" id="sourceType"><option value="manual">نص يدوي</option><option value="website">رابط موقع</option><option value="file">ملف</option></select></div>
    <div class="field"><label>وحدة AI</label><select name="module_id"><?php foreach($modules as $key=>$label): ?><option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>النطاق</label><select name="scope_type"><option value="global">عام (متاح وفق حالة التوثيق)</option><option value="firm">مكتب</option><option value="case">قضية</option><option value="private">خاص بالمدير</option></select></div>
    <div class="field"><label>رقم المكتب/القضية عند اختيار نطاق خاص</label><input type="number" min="1" name="scope_id"></div>
    <div class="field"><label>اللغة</label><select name="language"><option value="">غير محدد</option><option value="ar">العربية</option><option value="en">English</option></select></div>
    <div class="field"><label>الدولة/النطاق القضائي</label><input name="jurisdiction" maxlength="100" placeholder="السعودية، الإمارات..."></div>
    <div class="field"><label>التصنيف والوسوم</label><input name="category" maxlength="100" placeholder="تصنيف"><input name="tags" maxlength="1000" placeholder="وسوم مفصولة بفواصل"></div>
  </div>
  <div class="field source-panel" data-kind="manual"><label>المحتوى</label><textarea name="content" rows="7" maxlength="500000" placeholder="النص المصدر"></textarea></div>
  <div class="field source-panel" data-kind="website" hidden><label>رابط عام HTTP/HTTPS</label><input name="source_url" type="url" maxlength="2048" placeholder="https://example.org"></div>
  <div class="field source-panel" data-kind="file" hidden><label>الملف (حتى <?= MAX_UPLOAD_SIZE_MB ?> MB)</label><input type="file" name="source_file" accept=".doc,.docx,.pdf,.txt,.md,.csv,.xls,.xlsx,.jpg,.jpeg,.png,.webp"><small>يتم تخزين الملف خارج الوصول العام. الملفات المصورة وPDF تحتاج OCR.</small><label><input type="checkbox" name="ai_extraction_consent" value="1"> أوافق على إرسال PDF/الصورة إلى مزود AI الحالي لاستخراج النص؛ قد يتحمل الحساب تكلفة.</label></div>
  <?php if($isSuperAdmin): ?><label><input type="checkbox" name="verified" value="1"> تم التحقق من صحة هذا المصدر ومصدره الرسمي (OCR يحتاج مراجعة واعتماداً منفصلين)</label><?php else: ?><p class="field-hint">التحقق القانوني من المصدر متاح للمدير الأعلى فقط.</p><?php endif; ?>
  <div style="margin-top:12px"><button class="btn btn-primary" type="submit">حفظ المصدر ومعالجته</button></div></form></div>
  <form method="get" class="toolbar"><input name="q" value="<?= htmlspecialchars($filter) ?>" placeholder="بحث بالاسم أو الرابط أو الوسوم"><select name="module"><option value="">كل الوحدات</option><?php foreach($modules as $key=>$label): ?><option value="<?= htmlspecialchars($key) ?>" <?= $moduleFilter===$key?'selected':'' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select><button class="btn btn-outline btn-sm">بحث</button></form>
  <div class="table-wrap"><table class="admin-table"><thead><tr><th>المصدر</th><th>الوحدة والنطاق</th><th>النوع</th><th>الحالة</th><th>الفهرسة</th><th>التوثيق</th><th>الإصدار</th><th>عمليات</th></tr></thead><tbody>
  <?php foreach($sources as $s): ?><tr><td><strong><?= htmlspecialchars($s['source_name']) ?></strong><br><small><?= htmlspecialchars($s['source_url'] ?? $s['original_filename'] ?? '') ?></small></td><td><?= htmlspecialchars($modules[$s['module_id']] ?? $s['module_id']) ?><br><small><?= htmlspecialchars($s['scope_type'] . ($s['scope_id'] ? ' #' . $s['scope_id'] : '')) ?> · <?= htmlspecialchars($s['jurisdiction'] ?? 'دون نطاق قضائي') ?></small></td><td><?= htmlspecialchars($s['source_type']) ?></td><td><?= htmlspecialchars($s['processing_status']) ?></td><td><?= htmlspecialchars($s['indexing_status']) ?> · <?= (int)$s['chunk_count'] ?> مقطع</td><td><?= htmlspecialchars($s['verification_status']) ?></td><td><?= (int)$s['version'] ?></td><td><details><summary>تعديل</summary><form method="post" style="min-width:280px;margin:8px 0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="update_source"><input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>"><div class="field"><label>الاسم</label><input name="source_name" maxlength="255" required value="<?= htmlspecialchars($s['source_name']) ?>"></div><div class="field"><label>وحدة AI</label><select name="module_id"><?php foreach($modules as $k=>$v):?><option value="<?=htmlspecialchars($k)?>" <?=$s['module_id']===$k?'selected':''?>><?=htmlspecialchars($v)?></option><?php endforeach;?></select></div><div class="field"><label>النطاق</label><select name="scope_type"><?php foreach(['global','firm','case','private'] as $scopeOption):?><option value="<?=$scopeOption?>" <?=$s['scope_type']===$scopeOption?'selected':''?>><?=$scopeOption?></option><?php endforeach;?></select><input type="number" min="1" name="scope_id" value="<?= (int)$s['scope_id'] ?>" placeholder="رقم المكتب/القضية"></div><div class="field"><label>الدولة/النطاق القضائي</label><input name="jurisdiction" maxlength="100" value="<?=htmlspecialchars($s['jurisdiction']??'')?>"></div><div class="field"><label>التصنيف</label><input name="category" maxlength="100" value="<?=htmlspecialchars($s['category']??'')?>"></div><div class="field"><label>الوسوم</label><input name="tags" maxlength="1000" value="<?=htmlspecialchars($s['tags']??'')?>"></div><div class="field"><label>النص المفهرس (حفظ ينشئ إصداراً جديداً)</label><textarea name="content" rows="5" maxlength="500000" required><?=htmlspecialchars($s['content']??'')?></textarea></div><?php if($isSuperAdmin):?><label><input type="checkbox" name="verified" value="1" <?=$s['verification_status']==='verified'?'checked':''?>> اعتماد هذا الإصدار بعد التحقق</label><?php else:?><p class="field-hint">أي تعديل من محرر يعيد المصدر لحالة غير موثقة.</p><?php endif;?><button class="btn btn-outline btn-sm" type="submit">حفظ إصدار جديد</button></form></details><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="reindex"><input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>"><label title="موافقة على إرسال الملف إلى مزود AI"><input type="checkbox" name="ai_extraction_consent" value="1"> OCR AI</label><button class="icon-btn" type="submit">إعادة المعالجة</button></form><form method="post" style="display:inline" onsubmit="return confirm('حذف المصدر نهائياً؟')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>"><button class="icon-btn danger" type="submit">حذف</button></form></td></tr><?php endforeach; ?>
  <?php if(!$sources): ?><tr><td colspan="8" style="text-align:center">لا توجد مصادر مطابقة.</td></tr><?php endif; ?></tbody></table></div>
</div>
<script>document.getElementById('sourceType').addEventListener('change',function(){document.querySelectorAll('.source-panel').forEach(function(p){p.hidden=p.dataset.kind!==document.getElementById('sourceType').value;});});</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
