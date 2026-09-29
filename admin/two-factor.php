<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/audit_log.php';

$adminId=(int)$_SESSION['admin_id'];
$q=$pdo->prepare('SELECT id,email,full_name,password_hash,totp_secret,totp_enabled FROM admins WHERE id=?'); $q->execute([$adminId]); $admin=$q->fetch();
if(!$admin){http_response_code(403);exit('Forbidden');}
$error=null;$message=null;$recoveryCodes=[];
if(empty($admin['totp_enabled']) && empty($_SESSION['totp_enrollment_secret'])) $_SESSION['totp_enrollment_secret']=totp_new_secret();
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_is_valid($_POST['csrf_token']??null)){http_response_code(403);exit('Invalid request');}
    $action=$_POST['action']??'';
    if($action==='enable' && empty($admin['totp_enabled'])){
        $secret=(string)($_SESSION['totp_enrollment_secret']??'');
        if(!$secret || !totp_verify($secret,trim($_POST['totp_code']??''))) $error='رمز التحقق غير صحيح؛ أدخل الرمز الحالي من التطبيق.';
        else {
            for($i=0;$i<10;$i++){ $raw=bin2hex(random_bytes(5)); $recoveryCodes[]=strtoupper(substr($raw,0,5).'-'.substr($raw,5)); }
            $hashes=array_map(function($code){return hash('sha256',str_replace('-','',$code));},$recoveryCodes);
            $pdo->prepare('UPDATE admins SET totp_secret=?,totp_enabled=1,totp_verified_at=NOW(),totp_recovery_hashes=? WHERE id=?')
                ->execute([$secret,json_encode($hashes),$adminId]);
            unset($_SESSION['totp_enrollment_secret']);
            $_SESSION['admin_2fa_verified']=true;
            log_action($pdo,'تفعيل المصادقة الثنائية للحساب الإداري');
            $message='تم تفعيل المصادقة الثنائية. احفظ رموز الاسترداد الآن؛ لن تظهر مرة أخرى.';
            $admin['totp_enabled']=1;
        }
    } elseif($action==='disable' && !empty($admin['totp_enabled'])){
        $password=$_POST['password']??''; $code=trim($_POST['totp_code']??'');
        if(!password_verify($password,$admin['password_hash']) || !totp_verify((string)$admin['totp_secret'],$code)) $error='تعذر التحقق من كلمة المرور أو رمز المصادقة.';
        else {
            $pdo->prepare('UPDATE admins SET totp_secret=NULL,totp_enabled=0,totp_verified_at=NULL,totp_recovery_hashes=NULL WHERE id=?')->execute([$adminId]);
            $_SESSION['admin_2fa_verified']=false; log_action($pdo,'تعطيل المصادقة الثنائية للحساب الإداري');
            $message='تم تعطيل المصادقة الثنائية.'; $admin['totp_enabled']=0; $_SESSION['totp_enrollment_secret']=totp_new_secret();
        }
    }
}
$secret=(string)($_SESSION['totp_enrollment_secret']??'');
$otpauth='otpauth://totp/'.rawurlencode('Mawatheq:'.$admin['email']).'?secret='.$secret.'&issuer='.rawurlencode('Mawatheq').'&algorithm=SHA1&digits=6&period=30';
$activeAdmin='settings'; require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content"><div class="toolbar"><h2>المصادقة الثنائية لحساب الإدارة</h2></div>
<?php if($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if($message): ?><div class="form-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="dash-card"><p>تستخدم هذه الصفحة رمز TOTP متوافقاً مع Google Authenticator وMicrosoft Authenticator والتطبيقات المتوافقة. بعد التفعيل، لن يكتمل تسجيل دخول الإدارة قبل إدخال الرمز.</p>
<?php if(empty($admin['totp_enabled'])): ?><p><strong>1.</strong> أضف الحساب إلى تطبيق المصادقة باستخدام هذا المفتاح: <code style="user-select:all"><?= htmlspecialchars($secret) ?></code></p><p><a href="<?= htmlspecialchars($otpauth) ?>">فتح رابط إعداد تطبيق المصادقة</a></p><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="enable"><div class="field"><label>2. أدخل رمز الستة أرقام الظاهر في التطبيق لتأكيد الربط</label><input name="totp_code" inputmode="numeric" pattern="[0-9 ]{6,8}" required></div><button class="btn btn-primary" type="submit">تفعيل وحفظ الرموز</button></form>
<?php else: ?><div class="form-alert success">المصادقة الثنائية مفعّلة لهذا الحساب.</div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="disable"><div class="field"><label>كلمة المرور الحالية</label><input type="password" name="password" required autocomplete="current-password"></div><div class="field"><label>رمز المصادقة الحالي</label><input name="totp_code" inputmode="numeric" required></div><button class="btn btn-outline" type="submit">تعطيل المصادقة الثنائية</button></form><?php endif; ?>
<?php if($recoveryCodes): ?><h3>رموز الاسترداد — احفظها الآن في مكان آمن</h3><pre style="white-space:pre-wrap"><?= htmlspecialchars(implode("\n",$recoveryCodes)) ?></pre><?php endif; ?>
<p class="field-hint">كل رمز استرداد يستخدم مرة واحدة. قاعدة البيانات تخزن تجزئاتها فقط. لا تشارك مفتاح الإعداد أو الرموز مع أي شخص.</p></div></div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
