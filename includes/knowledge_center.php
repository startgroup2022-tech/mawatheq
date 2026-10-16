<?php
/** Modular source storage, safe processing, keyword indexing and permission-filtered retrieval. */
require_once __DIR__ . '/semantic_search.php';

function knowledge_clean_text(string $text): string {
    $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function knowledge_log(PDO $pdo, int $sourceId, string $stage, string $status, string $message = '', ?int $durationMs = null): void {
    try {
        $pdo->prepare('INSERT INTO knowledge_processing_logs (source_id,stage,status,message,duration_ms) VALUES (?,?,?,?,?)')
            ->execute([$sourceId, $stage, $status, $message !== '' ? mb_substr($message, 0, 1000, 'UTF-8') : null, $durationMs]);
    } catch (PDOException $e) { error_log('Knowledge processing log failed: ' . $e->getMessage()); }
}

function knowledge_index_text(PDO $pdo, int $sourceId, string $content): int {
    $content = knowledge_clean_text($content);
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE source_id=?')->execute([$sourceId]);
    try { $pdo->prepare("UPDATE knowledge_sources SET embedding_status='pending' WHERE id=?")->execute([$sourceId]); } catch (PDOException $e) { /* semantic search migration not applied yet */ }
    if ($content === '') {
        $pdo->prepare("UPDATE knowledge_sources SET processing_status='needs_extraction',indexing_status='pending',content=NULL WHERE id=?")->execute([$sourceId]);
        return 0;
    }
    // Legal-aware chunking: keep article/clause boundaries and record provenance so an
    // answer can cite the article and page, not just the document title.
    require_once __DIR__ . '/legal_chunker.php';
    $chunks = legal_chunk_content($content);
    if (!$chunks) $chunks = [['text'=>$content,'heading_path'=>null,'section_label'=>null,'article_label'=>null,'clause_label'=>null,'page_no'=>null,'chunk_kind'=>'paragraph']];

    // The provenance columns arrive with upgrade_v48; on a database that has not run it
    // yet we fall back to the original insert so indexing still works (deploy safety).
    $hasProvenance = false;
    try {
        $col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_chunks' AND COLUMN_NAME='heading_path'")->fetchColumn();
        $hasProvenance = ((int)$col) > 0;
    } catch (PDOException $e) { $hasProvenance = false; }

    if ($hasProvenance) {
        $insert = $pdo->prepare('INSERT INTO knowledge_chunks (source_id,chunk_order,chunk_text,heading_path,section_label,article_label,clause_label,page_no,chunk_kind,text_hash) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($chunks as $i => $chunk) {
            $insert->execute([$sourceId, $i + 1, $chunk['text'], $chunk['heading_path'], $chunk['section_label'],
                $chunk['article_label'], $chunk['clause_label'], $chunk['page_no'], $chunk['chunk_kind'], hash('sha256', $chunk['text'])]);
        }
    } else {
        $insert = $pdo->prepare('INSERT INTO knowledge_chunks (source_id,chunk_order,chunk_text,text_hash) VALUES (?,?,?,?)');
        foreach ($chunks as $i => $chunk) $insert->execute([$sourceId, $i + 1, $chunk['text'], hash('sha256', $chunk['text'])]);
    }
    $hash = hash('sha256', $content);
    $pdo->prepare("UPDATE knowledge_sources SET content=?,content_hash=?,processing_status='completed',indexing_status='indexed' WHERE id=?")
        ->execute([$content, $hash, $sourceId]);
    return count($chunks);
}

function knowledge_public_ip(string $host): ?string {
    if ($host === '' || in_array(strtolower($host), ['localhost','localhost.localdomain'], true) || substr(strtolower($host), -6) === '.local') return null;
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else $ips = gethostbynamel($host) ?: [];
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    return null;
}

function knowledge_fetch_website(string $url): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'cURL is unavailable'];
    $parts = parse_url(trim($url));
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https','http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))
        return ['ok'=>false,'error'=>'Enter a valid public HTTP or HTTPS URL.'];
    $host = strtolower($parts['host']); $ip = knowledge_public_ip($host);
    if (!$ip) return ['ok'=>false,'error'=>'Private, reserved, and local network addresses are not allowed.'];
    $port = (int)($parts['port'] ?? ((strtolower($parts['scheme']) === 'https') ? 443 : 80));
    $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12,
        CURLOPT_MAXFILESIZE=>4194304, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_RESOLVE=>[$host . ':' . $port . ':' . $resolveIp],
        CURLOPT_USERAGENT=>'MawatheqKnowledgeImporter/1.0',
        CURLOPT_HTTPHEADER=>['Accept: text/html,text/plain;q=0.9'],
    ]);
    $html = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $error = curl_error($ch); curl_close($ch);
    if (!is_string($html) || $status < 200 || $status >= 300) return ['ok'=>false,'error'=>'Website fetch failed (HTTP ' . $status . '). ' . $error];
    if (strlen($html) > 4194304 || (stripos($type, 'text/html') === false && stripos($type, 'text/plain') === false)) return ['ok'=>false,'error'=>'Only HTML and plain-text web pages up to 4 MB can be imported.'];
    $title = $host; $text = $html;
    if (stripos($type, 'text/html') !== false && class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        $titles = $dom->getElementsByTagName('title'); if ($titles->length) $title = trim($titles->item(0)->textContent);
        foreach (['script','style','nav','footer','header','noscript','svg'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) { $node = $nodes->item($i); if ($node && $node->parentNode) $node->parentNode->removeChild($node); }
        }
        $body = $dom->getElementsByTagName('body')->item(0); $text = $body ? $body->textContent : $dom->textContent;
    }
    $text = knowledge_clean_text(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($text, 'UTF-8') < 80) return ['ok'=>false,'error'=>'The page did not contain enough readable text to index.'];
    return ['ok'=>true,'title'=>mb_substr($title ?: $host, 0, 250, 'UTF-8'),'content'=>$text,'domain'=>$host,'retrieved_at'=>date('Y-m-d H:i:s')];
}

function knowledge_extract_file(string $path, string $extension): array {
    $extension = strtolower($extension);
    if (in_array($extension, ['txt','csv','md'], true)) {
        $text = file_get_contents($path);
        return is_string($text) ? ['ok'=>true,'content'=>knowledge_clean_text($text)] : ['ok'=>false,'error'=>'Unable to read the uploaded file.'];
    }
    if (in_array($extension, ['docx','xlsx'], true) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return ['ok'=>false,'error'=>'The Office file is not a valid ZIP package.'];
        $xmlName = $extension === 'docx' ? 'word/document.xml' : 'xl/sharedStrings.xml';
        $xml = $zip->getFromName($xmlName); $zip->close();
        if (!is_string($xml)) return ['ok'=>false,'error'=>'Text content was not found in the Office file.'];
        $dom = new DOMDocument(); $old = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        if (!$loaded) return ['ok'=>false,'error'=>'Could not parse Office document text.'];
        $text = knowledge_clean_text($dom->textContent);
        return $text !== '' ? ['ok'=>true,'content'=>$text] : ['ok'=>false,'error'=>'The Office document contains no extractable text.'];
    }
    return ['ok'=>false,'needs_extraction'=>true,'error'=>'This file is stored securely, but PDF/image OCR is not configured on this server. The source remains unavailable to AI until text extraction is configured.'];
}

function knowledge_store_source(PDO $pdo, array $source, string $content, ?string $filePath = null): int {
    $stmt = $pdo->prepare('INSERT INTO knowledge_sources (tenant_id,workspace_id,module_id,source_type,source_name,source_url,source_domain,storage_path,original_filename,mime_type,content,language,jurisdiction,category,tags,status,verification_status,scope_type,scope_id,processing_status,indexing_status,created_by_type,created_by,retrieved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $source['tenant_id'] ?? null, $source['workspace_id'] ?? null, $source['module_id'] ?? '*', $source['source_type'], $source['source_name'],
        $source['source_url'] ?? null, $source['source_domain'] ?? null, $filePath, $source['original_filename'] ?? null, $source['mime_type'] ?? null,
        null, $source['language'] ?? null, $source['jurisdiction'] ?? null, $source['category'] ?? null, $source['tags'] ?? null,
        'active', !empty($source['verified']) ? 'verified' : 'unverified', $source['scope_type'] ?? 'global', $source['scope_id'] ?? null,
        $content !== '' ? 'processing' : (!empty($source['needs_extraction']) ? 'needs_extraction' : 'queued'), 'pending', $source['created_by_type'] ?? 'admin', $source['created_by'] ?? null, $source['retrieved_at'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO knowledge_source_versions (source_id,version,source_name,source_url,content,metadata_json,changed_by) VALUES (?,1,?,?,?,?,?)')
        ->execute([$id,$source['source_name'],$source['source_url'] ?? null,$content !== '' ? $content : null,json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$source['created_by'] ?? null]);
    if ($content !== '') {
        $count = knowledge_index_text($pdo,$id,$content);
        knowledge_log($pdo,$id,'text_extraction','completed','Extracted source text.');
        knowledge_log($pdo,$id,'keyword_indexing','completed','Created ' . $count . ' searchable chunks.');
    } else {
        $state = !empty($source['needs_extraction']) ? 'needs_extraction' : 'queued';
        $pdo->prepare('UPDATE knowledge_sources SET processing_status=? WHERE id=?')->execute([$state,$id]);
        knowledge_log($pdo,$id,'text_extraction',$state,$source['processing_message'] ?? 'No text extraction result is available yet.');
    }
    return $id;
}

function knowledge_retrieve(PDO $pdo, string $query, string $moduleId, string $actorType, int $actorId, ?int $caseId = null, bool $verifiedOnly = false, int $limit = 4, array $filters = []): array {
    $query = trim($query);
    if ($query === '' || !in_array($actorType,['client','lawyer'],true)) return [];
    $caseAllowed = false;
    if ($caseId) {
        $ownerColumn = $actorType === 'lawyer' ? 'lawyer_id' : 'user_id';
        $check = $pdo->prepare('SELECT id FROM cases WHERE id=? AND ' . $ownerColumn . '=?');
        $check->execute([$caseId,$actorId]); $caseAllowed = (bool)$check->fetchColumn();
    }
    $exactPhrase=null;
    if(preg_match('/^"(.+)"$/u',$query,$m)||preg_match('/^“(.+)”$/u',$query,$m))$exactPhrase=trim($m[1]);
    $terms=$exactPhrase===null?(preg_split('/\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY)?:[]):[$exactPhrase];
    $terms=array_slice(array_values(array_filter($terms,function($term){return mb_strlen($term,'UTF-8')>=2;})),0,8);
    if (!$terms) return [];
    $termSql = implode(' OR ', array_fill(0,count($terms),'k.chunk_text LIKE ?'));
    require_once __DIR__ . '/legal_chunker.php';
    $sql = "SELECT s.id,s.source_name,s.source_url,s.version,s.source_type,s.verification_status,s.jurisdiction,s.module_id,k.chunk_order,k.chunk_text" . legal_chunk_provenance_select($pdo) . " FROM knowledge_chunks k JOIN knowledge_sources s ON s.id=k.source_id WHERE s.status='active' AND s.processing_status='completed' AND s.indexing_status='indexed' AND s.module_id IN (?,'*')";
    $params = [$moduleId];
    if ($verifiedOnly) $sql .= " AND s.verification_status='verified'";
    if(!empty($filters['source_type'])&&in_array($filters['source_type'],['file','manual','website','external_api','database'],true)){$sql.=' AND s.source_type=?';$params[]=$filters['source_type'];}
    if(!empty($filters['jurisdiction'])){$sql.=' AND s.jurisdiction=?';$params[]=(string)$filters['jurisdiction'];}
    if(!empty($filters['date_from'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_from'])){$sql.=' AND s.created_at>=?';$params[]=$filters['date_from'].' 00:00:00';}
    if(!empty($filters['date_to'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_to'])){$sql.=' AND s.created_at<=?';$params[]=$filters['date_to'].' 23:59:59';}
    $sql .= " AND (s.scope_type='global'";
    if ($actorType === 'lawyer') {
        $sql .= " OR (s.scope_type='firm' AND EXISTS (SELECT 1 FROM law_firm_members fm WHERE fm.firm_id=s.scope_id AND fm.lawyer_id=?))";
        $params[] = $actorId;
    }
    if ($caseAllowed) { $sql .= " OR (s.scope_type='case' AND s.scope_id=?)"; $params[] = $caseId; }
    $sql .= " OR (s.scope_type='private' AND s.created_by_type=? AND s.created_by=?) )";
    $params[] = $actorType; $params[] = $actorId;
    $permissionSql = knowledge_permission_sql($pdo, $actorType, $actorId, 's.id');
    if ($permissionSql !== '') $params = array_merge($params, knowledge_permission_params($pdo, $actorType, $actorId));
    $sql .= $permissionSql . " AND ($termSql) LIMIT 200";
    foreach ($terms as $term) $params[] = '%' . $term . '%';
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $haystack = mb_strtolower($row['chunk_text'],'UTF-8'); $score = 0;
        foreach ($terms as $term) if (mb_stripos($haystack,$term,0,'UTF-8') !== false) $score++;
        if (mb_stripos($row['source_name'],$query,0,'UTF-8') !== false) $score += 2;
        $row['_score'] = $score;
    }
    unset($row);
    usort($rows,function($a,$b){ return $b['_score'] <=> $a['_score']; });
    $selected=[]; $seen=[];
    foreach ($rows as $row) {
        if ($row['_score'] < 1 || isset($seen[$row['id']])) continue;
        $seen[$row['id']]=true; unset($row['_score']); $selected[]=$row;
        if (count($selected)>=$limit) break;
    }
    if(count($selected)<$limit&&$exactPhrase===null){
        try {
            $semantic=knowledge_semantic_search($pdo,$query,$moduleId,$actorType,$actorId,$caseAllowed?$caseId:null,$verifiedOnly,$limit-count($selected),$filters);
            foreach($semantic as $row){if(!isset($seen[$row['id']])){ $seen[$row['id']]=true;unset($row['_score']);$selected[]=$row;}}
        } catch(Throwable $e){error_log('Semantic knowledge search unavailable: '.$e->getMessage());}
    }
    if ($selected) {
        try {
            $access = $pdo->prepare('INSERT INTO knowledge_access_logs (source_id,actor_type,actor_id,module_id,case_id,query_hash) VALUES (?,?,?,?,?,?)');
            foreach ($selected as $source) $access->execute([(int)$source['id'],$actorType,$actorId,$moduleId,$caseAllowed ? $caseId : null,hash('sha256',$query)]);
        } catch (PDOException $e) { error_log('Knowledge access logging failed: '.$e->getMessage()); }
    }
    return $selected;
}
