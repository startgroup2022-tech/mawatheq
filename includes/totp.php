<?php
/** RFC 6238 TOTP helpers (SHA-1, 30-second period, six digits). */
function totp_base32_encode(string $data): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits='';
    foreach (str_split($data) as $ch) $bits.=str_pad(decbin(ord($ch)),8,'0',STR_PAD_LEFT);
    $out=''; foreach (str_split($bits,5) as $part) $out.=$alphabet[bindec(str_pad($part,5,'0',STR_PAD_RIGHT))];
    return $out;
}
function totp_base32_decode(string $text): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $text=strtoupper(rtrim($text,'=')); $bits='';
    foreach (str_split($text) as $ch) { $pos=strpos($alphabet,$ch); if($pos===false)return ''; $bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT); }
    $out=''; foreach(str_split($bits,8) as $part) if(strlen($part)===8)$out.=chr(bindec($part));
    return $out;
}
function totp_new_secret(): string { return totp_base32_encode(random_bytes(20)); }
function totp_code(string $secret, ?int $time=null): string {
    $key=totp_base32_decode($secret); if($key==='')return '';
    $counter=(int)floor(($time ?? time())/30); $high=(int)floor($counter/4294967296); $low=$counter%4294967296;
    $hash=hash_hmac('sha1',pack('N2',$high,$low),$key,true); $offset=ord($hash[19])&0x0f;
    $value=((ord($hash[$offset])&0x7f)<<24)|((ord($hash[$offset+1])&0xff)<<16)|((ord($hash[$offset+2])&0xff)<<8)|(ord($hash[$offset+3])&0xff);
    return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
}
function totp_verify(string $secret, string $code, ?int $time=null): bool {
    $code=preg_replace('/\s+/','',$code); if(!preg_match('/^\d{6}$/',$code))return false;
    $time=$time ?? time();
    for($step=-1;$step<=1;$step++) if(hash_equals(totp_code($secret,$time+$step*30),$code))return true;
    return false;
}
