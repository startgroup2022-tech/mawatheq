<?php
require_once __DIR__ . '/config/config.php';
$id=(int)($_GET['id']??0);
if (!empty($_SESSION['admin_id'])) {
    // Admins may open any client's document from the console (RBAC is enforced on the
    // console route itself); a client may only download a document they own.
    $q=$pdo->prepare('SELECT id,original_name,stored_path,file_type FROM documents WHERE id=? LIMIT 1');
    $q->execute([$id]); $doc=$q->fetch();
} else {
    require_once __DIR__ . '/includes/auth_guard.php';
    $q=$pdo->prepare('SELECT id,original_name,stored_path,file_type FROM documents WHERE id=? AND user_id=? LIMIT 1');
    $q->execute([$id,(int)$_SESSION['user_id']]); $doc=$q->fetch();
}
if(!$doc){http_response_code(404);exit('Not found');}
$base=realpath(UPLOAD_DIR); $path=realpath(__DIR__.DIRECTORY_SEPARATOR.$doc['stored_path']);
$ext=strtolower((string)$doc['file_type']);
$allowed=['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
if(!$base||!$path||!isset($allowed[$ext])||strpos($path,$base.DIRECTORY_SEPARATOR)!==0||!preg_match('/^[a-f0-9]{40}\.'.$ext.'$/',basename($path))||!is_file($path)){http_response_code(404);exit('Not found');}
$name=preg_replace('/[^\p{L}\p{N}._ -]+/u','_',basename((string)$doc['original_name']));
header('Content-Type: '.$allowed[$ext]); header('Content-Length: '.filesize($path));
header("Content-Disposition: attachment; filename=\"document-{$id}.{$ext}\"; filename*=UTF-8''".rawurlencode($name));
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
readfile($path);
