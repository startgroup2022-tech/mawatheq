<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
$lawyerId = (int)$_SESSION['lawyer_id'];
$error = null; $success = null;
$q = $pdo->prepare('SELECT f.*,m.member_role FROM law_firm_members m JOIN law_firms f ON f.id=m.firm_id WHERE m.lawyer_id=? LIMIT 1');
$q->execute([$lawyerId]); $membership = $q->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'create_firm' && !$membership) {
        $name = trim($_POST['firm_name'] ?? '');
        if ($name === '' || mb_strlen($name) > 180) {
            $error = is_rtl() ? 'أدخل اسماً صحيحاً للمكتب.' : 'Enter a valid firm name.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute([$name,$lawyerId]);
                $firmId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,'owner')")->execute([$firmId,$lawyerId]);
                $pdo->commit();
                log_action($pdo,'إنشاء مساحة مكتب قانوني','law_firm',$firmId);
                header('Location: /lawyer/firm.php'); exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Firm setup failed: ' . $e->getMessage());
                $error = is_rtl() ? 'تعذر إنشاء المكتب. حاول مجدداً.' : 'Could not create the firm. Try again.';
            }
        }
    } elseif (!$membership) {
        http_response_code(403); exit('No firm membership');
    } elseif ($action === 'add_member' && $membership['member_role'] === 'owner') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['member_role'] ?? 'lawyer';
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,['partner','lawyer','staff'],true)) {
            $error = is_rtl() ? 'البريد أو الدور غير صحيح.' : 'Invalid email or role.';
        } else {
            $lawyer = $pdo->prepare("SELECT id FROM lawyers WHERE email=? AND status='approved' AND email_verified=1 LIMIT 1");
            $lawyer->execute([$email]); $target = $lawyer->fetch();
            if (!$target) $error = is_rtl() ? 'لم نجد محامياً معتمداً ومؤكد البريد بهذا العنوان.' : 'No approved, email-verified lawyer was found for that address.';
            else {
                $already = $pdo->prepare('SELECT firm_id FROM law_firm_members WHERE lawyer_id=? LIMIT 1'); $already->execute([(int)$target['id']]);
                if ($already->fetch()) $error = is_rtl() ? 'المحامي عضو في مكتب آخر أو في هذا المكتب.' : 'This lawyer already belongs to a firm.';
                else {
                    $pdo->prepare('INSERT INTO law_firm_members (firm_id,lawyer_id,member_role) VALUES (?,?,?)')->execute([(int)$membership['id'],(int)$target['id'],$role]);
                    $memberRecordId = (int)$pdo->lastInsertId();
                    log_action($pdo,'إضافة عضو إلى المكتب بدور '.$role,'law_firm_member',$memberRecordId);
                    $success = is_rtl() ? 'تمت إضافة عضو المكتب.' : 'Firm member added.';
                }
            }
        }
    } elseif ($action === 'remove_member' && $membership['member_role'] === 'owner') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $remove = $pdo->prepare("DELETE FROM law_firm_members WHERE id=? AND firm_id=? AND member_role<>'owner'");
        $remove->execute([$memberId,(int)$membership['id']]);
        if ($remove->rowCount() > 0) log_action($pdo,'إزالة عضو من المكتب','law_firm_member',$memberId);
        $success = is_rtl() ? 'تم تحديث عضوية المكتب.' : 'Firm membership updated.';
    } elseif ($action === 'add_knowledge') {
        $title = trim($_POST['title'] ?? ''); $content = trim($_POST['content'] ?? ''); $category = trim($_POST['category'] ?? '');
        if ($title === '' || $content === '') $error = is_rtl() ? 'أدخل العنوان والمحتوى.' : 'Enter a title and content.';
        else {
            $pdo->prepare('INSERT INTO firm_knowledge (firm_id,author_lawyer_id,title,category,content) VALUES (?,?,?,?,?)')->execute([(int)$membership['id'],$lawyerId,$title,$category ?: null,$content]);
            log_action($pdo,'إضافة عنصر إلى قاعدة معرفة المكتب','firm_knowledge',(int)$pdo->lastInsertId());
        }
    } elseif ($action === 'delete_knowledge') {
        $knowledgeId = (int)($_POST['knowledge_id'] ?? 0);
        $delete = $pdo->prepare('DELETE FROM firm_knowledge WHERE id=? AND firm_id=? AND (author_lawyer_id=? OR ?=1)');
        $delete
            ->execute([$knowledgeId,(int)$membership['id'],$lawyerId,$membership['member_role']==='owner'?1:0]);
        if ($delete->rowCount() > 0) log_action($pdo,'حذف عنصر من قاعدة معرفة المكتب','firm_knowledge',$knowledgeId);
    }
    if (!$error && $action !== 'create_firm') { header('Location: /lawyer/firm.php'); exit; }
}

$activeLawyer = 'firm';
require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="app-content"><div class="toolbar"><h2>🏢 <?= is_rtl()?'المكتب وقاعدة المعرفة':'Firm & knowledge base' ?></h2><?php if($membership): ?><div style="display:flex;gap:8px;flex-wrap:wrap"><?php if($membership['member_role']==='owner'): ?><button type="button" class="btn btn-outline" onclick="openModal('addFirmMemberModal')">＋ <?= is_rtl()?'إضافة عضو':'Add member' ?></button><?php endif; ?><button type="button" class="btn btn-primary" onclick="openModal('addFirmKnowledgeModal')">＋ <?= is_rtl()?'إضافة معرفة':'Add knowledge' ?></button></div><?php endif; ?></div>
<?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if (!$membership): ?>
<div class="dash-card"><h3><?= is_rtl()?'إنشاء مساحة مكتب':'Create a firm workspace' ?></h3><p class="field-hint"><?= is_rtl()?'يمكن للمحامي المعتمد إنشاء مكتب خاص ثم إضافة محامين معتمدين وقاعدة معرفة لا يراها إلا أعضاء المكتب.':'An approved lawyer can create a private firm workspace, add approved lawyers, and maintain knowledge visible only to its members.' ?></p><button type="button" class="btn btn-primary" onclick="openModal('createFirmModal')"><?= is_rtl()?'إنشاء مكتب':'Create firm' ?></button></div>
<div class="modal-backdrop<?= $error ? ' is-open' : '' ?>" id="createFirmModal"><form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="create_firm"><button type="button" class="modal-close" onclick="closeModal('createFirmModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button><h3><?= is_rtl()?'إنشاء مساحة مكتب':'Create firm workspace' ?></h3><div class="field"><label><?= is_rtl()?'اسم المكتب':'Firm name' ?></label><input name="firm_name" maxlength="180" required></div><button class="btn btn-primary" type="submit"><?= is_rtl()?'إنشاء المكتب':'Create firm' ?></button></form></div>
<?php else: ?>
<div class="dash-card"><h3><?= htmlspecialchars($membership['name']) ?></h3><p><?= is_rtl()?'دورك في المكتب: ':'Your role: ' ?><?= htmlspecialchars($membership['member_role']) ?> · <?= is_rtl()?'مساحة خاصة بأعضاء المكتب':'Private to firm members' ?></p></div>
<?php
$members = $pdo->prepare('SELECT m.id,m.member_role,m.joined_at,l.full_name,l.email,l.specialization FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE m.firm_id=? ORDER BY FIELD(m.member_role,\'owner\',\'partner\',\'lawyer\',\'staff\'),l.full_name');
$members->execute([(int)$membership['id']]); $members = $members->fetchAll();
?>
<div class="dash-card"><h3><?= is_rtl()?'أعضاء المكتب':'Firm members' ?></h3><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= is_rtl()?'الاسم':'Name' ?></th><th><?= is_rtl()?'البريد':'Email' ?></th><th><?= is_rtl()?'التخصص':'Specialization' ?></th><th><?= is_rtl()?'الدور':'Role' ?></th><?php if($membership['member_role']==='owner'): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach($members as $member): ?><tr><td><?= htmlspecialchars($member['full_name']) ?></td><td><?= htmlspecialchars($member['email']) ?></td><td><?= htmlspecialchars($member['specialization'] ?? '') ?></td><td><?= htmlspecialchars($member['member_role']) ?></td><?php if($membership['member_role']==='owner'): ?><td><?php if($member['member_role']!=='owner'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>"><button class="icon-btn danger" type="submit"><?= is_rtl()?'إزالة':'Remove' ?></button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div>
<?php if($membership['member_role']==='owner'): ?></div><div class="modal-backdrop<?= $error && ($_POST['action']??'')==='add_member' ? ' is-open' : '' ?>" id="addFirmMemberModal"><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_member"><button type="button" class="modal-close" onclick="closeModal('addFirmMemberModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button><h3><?= is_rtl()?'إضافة عضو للمكتب':'Add a firm member' ?></h3><?php if($error && ($_POST['action']??'')==='add_member'): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><div class="field"><label><?= is_rtl()?'بريد المحامي المعتمد':'Approved lawyer email' ?></label><input type="email" name="email" required></div><div class="field"><label><?= is_rtl()?'الدور':'Role' ?></label><select name="member_role"><option value="lawyer">lawyer</option><option value="partner">partner</option><option value="staff">staff</option></select></div><button class="btn btn-primary" type="submit"><?= is_rtl()?'إضافة عضو':'Add member' ?></button></form></div><?php else: ?></div><?php endif; ?>
<div class="dash-card"><div class="toolbar"><h3>📚 <?= is_rtl()?'قاعدة معرفة المكتب':'Firm knowledge base' ?></h3><button type="button" class="btn btn-outline btn-sm" onclick="openModal('addFirmKnowledgeModal')">＋ <?= is_rtl()?'إضافة عنصر':'Add item' ?></button></div><div class="modal-backdrop<?= $error && ($_POST['action']??'')==='add_knowledge' ? ' is-open' : '' ?>" id="addFirmKnowledgeModal"><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_knowledge"><button type="button" class="modal-close" onclick="closeModal('addFirmKnowledgeModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button><h3><?= is_rtl()?'إضافة معرفة للمكتب':'Add firm knowledge' ?></h3><?php if($error && ($_POST['action']??'')==='add_knowledge'): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><div class="field"><label><?= is_rtl()?'العنوان':'Title' ?></label><input name="title" required maxlength="255"></div><div class="field"><label><?= is_rtl()?'التصنيف':'Category' ?></label><input name="category" maxlength="100"></div><div class="field"><label><?= is_rtl()?'محتوى داخلي للمكتب':'Internal firm content' ?></label><textarea name="content" rows="6" required></textarea></div><button class="btn btn-primary" type="submit"><?= is_rtl()?'حفظ المعرفة':'Save knowledge' ?></button></form></div>
<?php $knowledge=$pdo->prepare('SELECT k.*,l.full_name author_name FROM firm_knowledge k JOIN lawyers l ON l.id=k.author_lawyer_id WHERE k.firm_id=? ORDER BY k.updated_at DESC');$knowledge->execute([(int)$membership['id']]);foreach($knowledge->fetchAll() as $item): ?><div class="rec-item"><div style="display:flex;justify-content:space-between;gap:10px"><strong><?= htmlspecialchars($item['title']) ?></strong><small><?= htmlspecialchars($item['category'] ?? '') ?> · <?= htmlspecialchars($item['author_name']) ?></small></div><div style="white-space:pre-wrap;margin-top:8px"><?= htmlspecialchars($item['content']) ?></div><?php if($membership['member_role']==='owner'||(int)$item['author_lawyer_id']===$lawyerId): ?><form method="post" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_knowledge"><input type="hidden" name="knowledge_id" value="<?= (int)$item['id'] ?>"><button class="icon-btn danger" type="submit"><?= is_rtl()?'حذف':'Delete' ?></button></form><?php endif; ?></div><?php endforeach; ?></div>
<?php endif; ?></div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
