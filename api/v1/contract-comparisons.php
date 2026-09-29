<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/contract_comparison.php';
$auth=api_authenticate($pdo);
if ($auth['type']!=='client') api_error('client account required',403);
$userId=(int)$auth['id'];
if ($_SERVER['REQUEST_METHOD']==='GET') {
    $id=(int)($_GET['id']??0);
    if ($id) {
        $q=$pdo->prepare('SELECT cc.id,cc.first_document_id,cc.second_document_id,cc.comparison_result,cc.comparison_status,cc.model,cc.created_at,d1.original_name AS first_name,d2.original_name AS second_name FROM contract_comparisons cc JOIN documents d1 ON d1.id=cc.first_document_id JOIN documents d2 ON d2.id=cc.second_document_id WHERE cc.id=? AND cc.user_id=?');
        $q->execute([$id,$userId]); $row=$q->fetch(); if(!$row) api_error('comparison not found',404);
        api_json(['ok'=>true,'comparison'=>$row]);
    }
    $q=$pdo->prepare('SELECT cc.id,cc.first_document_id,cc.second_document_id,cc.comparison_status,cc.model,cc.created_at,d1.original_name AS first_name,d2.original_name AS second_name FROM contract_comparisons cc JOIN documents d1 ON d1.id=cc.first_document_id JOIN documents d2 ON d2.id=cc.second_document_id WHERE cc.user_id=? ORDER BY cc.created_at DESC LIMIT 50');
    $q->execute([$userId]); api_json(['ok'=>true,'comparisons'=>$q->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') api_error('method not allowed',405);
$input=api_input();
$consent=in_array($input['ai_consent']??null,[true,1,'1'],true);
$result=compare_user_contracts($pdo,$userId,(int)($input['first_document_id']??0),(int)($input['second_document_id']??0),$consent);
if(!$result['ok']) api_error($result['error'],422);
api_json(['ok'=>true,'comparison_id'=>$result['comparison_id'],'comparison'=>$result['comparison']],201);
