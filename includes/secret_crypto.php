<?php
/** Encrypt deployment/API credentials at rest with AES-256-GCM. */
function secret_encryption_key(): ?string {
    $configured=defined('MAWATHEQ_ENCRYPTION_KEY')?MAWATHEQ_ENCRYPTION_KEY:(getenv('MAWATHEQ_ENCRYPTION_KEY') ?: '');
    if(!is_string($configured)||strlen($configured)<32)return null;
    return hash('sha256',$configured,true);
}
function secret_encrypt_value(string $plain): ?string {
    $key=secret_encryption_key(); if(!$key)return null;
    if($plain==='')return '';
    if(!function_exists('openssl_encrypt'))return null;
    $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
    if($cipher===false)return null;
    return 'enc:v1:'.base64_encode($nonce.$tag.$cipher);
}
function secret_decrypt_value(?string $stored): string {
    if(!$stored)return '';
    if(strpos($stored,'enc:v1:')!==0)return $stored;
    $key=secret_encryption_key();$blob=base64_decode(substr($stored,7),true);
    if(!$key||$blob===false||strlen($blob)<29||!function_exists('openssl_decrypt'))return '';
    $plain=openssl_decrypt(substr($blob,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($blob,0,12),substr($blob,12,16));
    return is_string($plain)?$plain:'';
}
function secret_store_setting(PDO $pdo,string $settingKey,string $plain): bool {
    $encrypted=secret_encrypt_value($plain);
    if($encrypted===null)return false;
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$settingKey,$encrypted]);
    return true;
}
function ai_load_settings(PDO $pdo): array {
    $settings=[];
    foreach($pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('ai_provider','ai_api_key','ai_model','ai_enabled','ai_endpoint','ai_backup_model','ai_max_tokens','ai_temperature','ai_timeout','ai_retries','ai_rate_limit')")->fetchAll() as $row) $settings[$row['setting_key']]=$row['setting_value'];
    if(isset($settings['ai_api_key'])) {
        $stored=$settings['ai_api_key'];$settings['ai_api_key']=secret_decrypt_value($stored);
        if($settings['ai_api_key']!=='' && strpos((string)$stored,'enc:v1:')!==0 && secret_encryption_key()) secret_store_setting($pdo,'ai_api_key',$settings['ai_api_key']);
    }
    return $settings;
}

/** Settings for semantic embeddings are separate from chat; fall back only to a saved OpenAI chat key. */
function embedding_load_settings(PDO $pdo): array {
    $settings=[];
    foreach($pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('embedding_provider','embedding_api_key','embedding_model')")->fetchAll() as $row) $settings[$row['setting_key']]=$row['setting_value'];
    if(isset($settings['embedding_api_key'])) {
        $stored=$settings['embedding_api_key']; $settings['embedding_api_key']=secret_decrypt_value($stored);
        if($settings['embedding_api_key']!=='' && strpos((string)$stored,'enc:v1:')!==0 && secret_encryption_key()) secret_store_setting($pdo,'embedding_api_key',$settings['embedding_api_key']);
    }
    $settings['embedding_provider']=$settings['embedding_provider']??'';
    $settings['embedding_api_key']=$settings['embedding_api_key']??'';
    $settings['embedding_model']=$settings['embedding_model']??'text-embedding-3-small';
    if($settings['embedding_provider']!=='disabled'&&($settings['embedding_provider']===''||$settings['embedding_api_key']==='')) {
        $ai=ai_load_settings($pdo);
        if(($ai['ai_provider']??'')==='openai'&&!empty($ai['ai_api_key'])) {
            if($settings['embedding_provider']==='')$settings['embedding_provider']='openai';
            if($settings['embedding_api_key']===''&&$settings['embedding_provider']==='openai')$settings['embedding_api_key']=$ai['ai_api_key'];
        }
    }
    return $settings;
}
