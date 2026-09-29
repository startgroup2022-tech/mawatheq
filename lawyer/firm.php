<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/tenant.php';
$lawyerId = (int)$_SESSION['lawyer_id'];
$error = null; $success = null;
$q = $pdo->prepare('SELECT f.*,m.member_role FROM law_firm_members m JOIN law_firms f ON f.id=m.firm_id WHERE m.lawyer_id=? AND m.status=\'active\' LIMIT 1');
$q->execute([$lawyerId]); $membership = $q->fetch();
$firmId = $membership ? (int)$membership['id'] : null;
$isOwner = $membership && $membership['member_role'] === 'owner';

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
                // Adopt the founder's solo records into the new firm so nothing disappears.
                tenant_adopt_lawyer_data($pdo, $firmId, $lawyerId);
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
    } elseif ($action === 'update_firm') {
        tenant_require($pdo, 'firm.edit');
        $fields = [
            'legal_name' => trim((string)($_POST['legal_name'] ?? '')),
            'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'address' => trim((string)($_POST['address'] ?? '')),
            'city' => trim((string)($_POST['city'] ?? '')),
            'country' => trim((string)($_POST['country'] ?? '')),
            'commercial_register' => trim((string)($_POST['commercial_register'] ?? '')),
            'license_number' => trim((string)($_POST['license_number'] ?? '')),
        ];
        $limits = ['legal_name'=>200,'email'=>150,'phone'=>30,'address'=>255,'city'=>100,'country'=>100,'commercial_register'=>80,'license_number'=>80];
        $valid = true;
        foreach ($fields as $key => $value) if (mb_strlen($value) > $limits[$key]) $valid = false;
        if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) $valid = false;
        if (!$valid) {
            $error = is_rtl() ? 'تحقق من بيانات المكتب المدخلة.' : 'Check the entered firm details.';
        } else {
            $pdo->prepare('UPDATE law_firms SET legal_name=?,email=?,phone=?,address=?,city=?,country=?,commercial_register=?,license_number=? WHERE id=?')
                ->execute([$fields['legal_name'] ?: null,$fields['email'] ?: null,$fields['phone'] ?: null,$fields['address'] ?: null,$fields['city'] ?: null,$fields['country'] ?: null,$fields['commercial_register'] ?: null,$fields['license_number'] ?: null,(int)$membership['id']]);
            log_action($pdo,'تحديث بيانات المكتب','law_firm',(int)$membership['id']);
            $success = is_rtl() ? 'تم حفظ بيانات المكتب.' : 'Firm details saved.';
        }
    } elseif ($action === 'add_member' && $isOwner) {
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
                    // Pull the new member's solo records into the firm so their history stays visible.
                    tenant_adopt_lawyer_data($pdo, (int)$membership['id'], (int)$target['id']);
                    log_action($pdo,'إضافة عضو إلى المكتب بدور '.$role,'law_firm_member',$memberRecordId);
                    $success = is_rtl() ? 'تمت إضافة عضو المكتب.' : 'Firm member added.';
                }
            }
        }
    } elseif ($action === 'remove_member' && $isOwner) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $remove = $pdo->prepare("DELETE FROM law_firm_members WHERE id=? AND firm_id=? AND member_role<>'owner'");
        $remove->execute([$memberId,(int)$membership['id']]);
        if ($remove->rowCount() > 0) log_action($pdo,'إزالة عضو من المكتب','law_firm_member',$memberId);
        $success = is_rtl() ? 'تم تحديث عضوية المكتب.' : 'Firm membership updated.';
    } elseif ($action === 'update_member_permissions') {
        // Only a member who may manage members can edit another member's permissions, and
        // nobody can grant a capability they do not themselves hold (no privilege escalation).
        tenant_require($pdo, 'firm.manage_members');
        $memberId = (int)($_POST['member_id'] ?? 0);
        $target = $pdo->prepare("SELECT m.id,m.member_role,m.lawyer_id FROM law_firm_members m WHERE m.id=? AND m.firm_id=? LIMIT 1");
        $target->execute([$memberId, (int)$membership['id']]); $row = $target->fetch();
        if (!$row || $row['member_role'] === 'owner') {
            $error = is_rtl() ? 'لا يمكن تعديل صلاحيات هذا العضو.' : 'This member\'s permissions cannot be edited.';
        } else {
            $requested = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];
            $valid = tenant_all_permissions();
            $grantable = [];
            foreach ($requested as $perm) {
                if (!is_string($perm) || !in_array($perm, $valid, true)) continue;
                // The actor must hold the capability to be allowed to delegate it.
                if (tenant_can($pdo, $perm)) $grantable[] = $perm;
            }
            $grantable = array_values(array_unique($grantable));
            // If the set matches the role default exactly, store NULL so role changes keep working.
            $roleDefault = tenant_firm_capabilities($row['member_role']);
            sort($grantable); $sortedDefault = $roleDefault; sort($sortedDefault);
            $store = $grantable === $sortedDefault ? null : json_encode($grantable);
            $pdo->prepare('UPDATE law_firm_members SET permissions_json=? WHERE id=? AND firm_id=?')->execute([$store, $memberId, (int)$membership['id']]);
            log_action($pdo,'تحديث صلاحيات عضو المكتب','law_firm_member',$memberId);
            $success = is_rtl() ? 'تم تحديث صلاحيات العضو.' : 'Member permissions updated.';
        }
    } elseif ($action === 'add_knowledge') {
        tenant_require($pdo, 'firm.view');
        $title = trim($_POST['title'] ?? ''); $content = trim($_POST['content'] ?? ''); $category = trim($_POST['category'] ?? '');
        if ($title === '' || $content === '') $error = is_rtl() ? 'أدخل العنوان والمحتوى.' : 'Enter a title and content.';
        elseif (mb_strlen($title) > 255 || mb_strlen($category) > 100) $error = is_rtl() ? 'العنوان أو التصنيف طويل جداً.' : 'The title or category is too long.';
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
<div class="dash-card"><div class="toolbar"><h3><?= htmlspecialchars($membership['name']) ?></h3><?php if($isOwner): ?><button type="button" class="btn btn-outline btn-sm" onclick="openModal('editFirmModal')">✎ <?= is_rtl()?'تعديل بيانات المكتب':'Edit firm details' ?></button><?php endif; ?></div><p><?= is_rtl()?'دورك في المكتب: ':'Your role: ' ?><?= htmlspecialchars($membership['member_role']) ?> · <?= is_rtl()?'مساحة خاصة بأعضاء المكتب':'Private to firm members' ?></p>
<?php if(!empty($membership['legal_name'])||!empty($membership['email'])||!empty($membership['city'])): ?><div class="field-hint"><?= htmlspecialchars(trim(implode(' · ', array_filter([$membership['legal_name'] ?? '', $membership['email'] ?? '', $membership['phone'] ?? '', $membership['city'] ?? '', $membership['country'] ?? ''])))) ?></div><?php endif; ?>
<?php if($isOwner): ?><div class="modal-backdrop<?= $error && ($_POST['action']??'')==='update_firm' ? ' is-open' : '' ?>" id="editFirmModal"><form class="modal-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="update_firm"><button type="button" class="modal-close" onclick="closeModal('editFirmModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button><h3><?= is_rtl()?'تعديل بيانات المكتب':'Edit firm details' ?></h3>
<div class="field"><label><?= is_rtl()?'الاسم القانوني':'Legal name' ?></label><input name="legal_name" maxlength="200" value="<?= htmlspecialchars($membership['legal_name'] ?? '') ?>"></div>
<div class="field"><label><?= is_rtl()?'بريد المكتب':'Firm email' ?></label><input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($membership['email'] ?? '') ?>"></div>
<div class="field"><label><?= is_rtl()?'الهاتف':'Phone' ?></label><input name="phone" maxlength="30" value="<?= htmlspecialchars($membership['phone'] ?? '') ?>"></div>
<div class="field"><label><?= is_rtl()?'العنوان':'Address' ?></label><input name="address" maxlength="255" value="<?= htmlspecialchars($membership['address'] ?? '') ?>"></div>
<div class="grid-2"><div class="field"><label><?= is_rtl()?'المدينة':'City' ?></label><input name="city" maxlength="100" value="<?= htmlspecialchars($membership['city'] ?? '') ?>"></div><div class="field"><label><?= is_rtl()?'الدولة':'Country' ?></label><input name="country" maxlength="100" value="<?= htmlspecialchars($membership['country'] ?? '') ?>"></div></div>
<div class="grid-2"><div class="field"><label><?= is_rtl()?'السجل التجاري':'Commercial register' ?></label><input name="commercial_register" maxlength="80" value="<?= htmlspecialchars($membership['commercial_register'] ?? '') ?>"></div><div class="field"><label><?= is_rtl()?'رقم الترخيص':'License number' ?></label><input name="license_number" maxlength="80" value="<?= htmlspecialchars($membership['license_number'] ?? '') ?>"></div></div>
<button class="btn btn-primary" type="submit"><?= is_rtl()?'حفظ':'Save' ?></button></form></div><?php endif; ?></div>
<?php
$members = $pdo->prepare('SELECT m.id,m.member_role,m.joined_at,m.permissions_json,l.full_name,l.email,l.specialization FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE m.firm_id=? ORDER BY FIELD(m.member_role,\'owner\',\'partner\',\'lawyer\',\'staff\'),l.full_name');
$members->execute([(int)$membership['id']]); $members = $members->fetchAll();
$permCatalog = tenant_permission_catalog();
$permLabels = [
    'firm' => [is_rtl()?'المكتب':'Firm', ['firm.view'=>is_rtl()?'عرض':'View','firm.edit'=>is_rtl()?'تعديل':'Edit','firm.manage_members'=>is_rtl()?'إدارة الأعضاء':'Manage members']],
    'clients' => [is_rtl()?'العملاء':'Clients', ['clients.view'=>is_rtl()?'عرض':'View','clients.create'=>is_rtl()?'إضافة':'Create','clients.edit'=>is_rtl()?'تعديل':'Edit','clients.delete'=>is_rtl()?'حذف':'Delete']],
    'cases' => [is_rtl()?'القضايا':'Cases', ['cases.view'=>is_rtl()?'عرض':'View','cases.create'=>is_rtl()?'إضافة':'Create','cases.edit'=>is_rtl()?'تعديل':'Edit','cases.delete'=>is_rtl()?'حذف':'Delete','cases.assign'=>is_rtl()?'إسناد':'Assign','cases.export'=>is_rtl()?'تصدير':'Export']],
    'documents' => [is_rtl()?'المستندات':'Documents', ['documents.view'=>is_rtl()?'عرض':'View','documents.upload'=>is_rtl()?'رفع':'Upload','documents.download'=>is_rtl()?'تنزيل':'Download','documents.delete'=>is_rtl()?'حذف':'Delete']],
    'evidence' => [is_rtl()?'الأدلة':'Evidence', ['evidence.view'=>is_rtl()?'عرض':'View','evidence.upload'=>is_rtl()?'رفع':'Upload','evidence.download'=>is_rtl()?'تنزيل':'Download','evidence.delete'=>is_rtl()?'حذف':'Delete']],
    'consultations' => [is_rtl()?'الاستشارات':'Consultations', ['consultations.view'=>is_rtl()?'عرض':'View','consultations.manage'=>is_rtl()?'إدارة':'Manage','consultations.respond'=>is_rtl()?'رد':'Respond']],
    'drafting' => [is_rtl()?'الصياغة':'Drafting', ['drafting.create'=>is_rtl()?'إنشاء':'Create','drafting.edit'=>is_rtl()?'تعديل':'Edit','drafting.review'=>is_rtl()?'مراجعة':'Review','drafting.approve'=>is_rtl()?'اعتماد':'Approve','drafting.export'=>is_rtl()?'تصدير':'Export']],
    'finance' => [is_rtl()?'المالية':'Finance', ['finance.view_invoices'=>is_rtl()?'عرض الفواتير':'View invoices','finance.create_invoices'=>is_rtl()?'إصدار فواتير':'Create invoices','finance.record_payments'=>is_rtl()?'تسجيل المدفوعات':'Record payments','finance.view_reports'=>is_rtl()?'عرض التقارير':'View reports']],
    'calendar' => [is_rtl()?'التقويم':'Calendar', ['calendar.manage'=>is_rtl()?'إدارة المواعيد':'Manage events']],
];
$memberPerms = function (array $m): array {
    $decoded = json_decode((string)($m['permissions_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : tenant_firm_capabilities($m['member_role']);
};
?>
<div class="dash-card"><h3><?= is_rtl()?'أعضاء المكتب':'Firm members' ?></h3><div class="table-wrap"><table class="admin-table"><thead><tr><th><?= is_rtl()?'الاسم':'Name' ?></th><th><?= is_rtl()?'البريد':'Email' ?></th><th><?= is_rtl()?'التخصص':'Specialization' ?></th><th><?= is_rtl()?'الدور':'Role' ?></th><th><?= is_rtl()?'الصلاحيات':'Permissions' ?></th><?php if($isOwner): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach($members as $member): $mPerms = $memberPerms($member); ?>
<tr><td><?= htmlspecialchars($member['full_name']) ?></td><td><?= htmlspecialchars($member['email']) ?></td><td><?= htmlspecialchars($member['specialization'] ?? '') ?></td><td><?= htmlspecialchars($member['member_role']) ?></td>
<td><small style="color:var(--muted)"><?= $member['member_role']==='owner' ? (is_rtl()?'كل الصلاحيات':'All permissions') : count($mPerms) . ' ' . (is_rtl()?'صلاحية':'permissions') ?></small><?php if($isOwner && $member['member_role']!=='owner'): ?> <button type="button" class="icon-btn" onclick="openModal('permModal<?= (int)$member['id'] ?>')"><?= is_rtl()?'تعديل':'Edit' ?></button><?php endif; ?></td>
<?php if($isOwner): ?><td><?php if($member['member_role']!=='owner'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>"><button class="icon-btn danger" type="submit"><?= is_rtl()?'إزالة':'Remove' ?></button></form><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?></tbody></table></div>
<?php if($isOwner): foreach($members as $member): if($member['member_role']==='owner') continue; $mPerms = $memberPerms($member); ?>
<div class="modal-backdrop" id="permModal<?= (int)$member['id'] ?>"><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="update_member_permissions"><input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
<button type="button" class="modal-close" onclick="closeModal('permModal<?= (int)$member['id'] ?>')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button>
<h3><?= is_rtl()?'صلاحيات ':'Permissions for ' ?><?= htmlspecialchars($member['full_name']) ?> <small style="color:var(--muted)">(<?= htmlspecialchars($member['member_role']) ?>)</small></h3>
<p class="field-hint"><?= is_rtl()?'لا يمكن منح صلاحية لا تملكها أنت.':'You can only grant permissions you hold yourself.' ?></p>
<?php foreach ($permCatalog as $groupKey => $perms): [$groupLabel, $labels] = $permLabels[$groupKey]; ?>
  <div class="field"><label><?= htmlspecialchars($groupLabel) ?></label>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
  <?php foreach ($perms as $perm): $hasIt = tenant_can($pdo, $perm); ?>
    <label class="chip<?= in_array($perm, $mPerms, true) ? ' on' : '' ?>" style="cursor:pointer;<?= $hasIt ? '' : 'opacity:.45' ?>" title="<?= $hasIt ? '' : htmlspecialchars(is_rtl()?'لا تملك هذه الصلاحية':'You do not hold this permission') ?>">
      <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($perm) ?>"<?= in_array($perm, $mPerms, true) ? ' checked' : '' ?><?= $hasIt ? '' : ' disabled' ?>> <?= htmlspecialchars($labels[$perm] ?? $perm) ?>
    </label>
  <?php endforeach; ?>
  </div></div>
<?php endforeach; ?>
<button class="btn btn-primary" type="submit"><?= is_rtl()?'حفظ الصلاحيات':'Save permissions' ?></button></form></div>
<?php endforeach; endif; ?>
<?php if($membership['member_role']==='owner'): ?></div><div class="modal-backdrop<?= $error && ($_POST['action']??'')==='add_member' ? ' is-open' : '' ?>" id="addFirmMemberModal"><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_member"><button type="button" class="modal-close" onclick="closeModal('addFirmMemberModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button><h3><?= is_rtl()?'إضافة عضو للمكتب':'Add a firm member' ?></h3><?php if($error && ($_POST['action']??'')==='add_member'): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><div class="field"><label><?= is_rtl()?'بريد المحامي المعتمد':'Approved lawyer email' ?></label><input type="email" name="email" required></div><div class="field"><label><?= is_rtl()?'الدور':'Role' ?></label><select name="member_role"><option value="lawyer">lawyer</option><option value="partner">partner</option><option value="staff">staff</option></select></div><button class="btn btn-primary" type="submit"><?= is_rtl()?'إضافة عضو':'Add member' ?></button></form></div><?php else: ?></div><?php endif; ?>
<div class="dash-card"><div class="toolbar"><h3>📚 <?= is_rtl()?'قاعدة معرفة المكتب':'Firm knowledge base' ?></h3><button type="button" class="btn btn-outline btn-sm" onclick="openModal('addFirmKnowledgeModal')">＋ <?= is_rtl()?'إضافة عنصر':'Add item' ?></button></div><div class="modal-backdrop<?= $error && ($_POST['action']??'')==='add_knowledge' ? ' is-open' : '' ?>" id="addFirmKnowledgeModal"><form method="post" class="modal-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="add_knowledge"><button type="button" class="modal-close" onclick="closeModal('addFirmKnowledgeModal')" aria-label="<?= is_rtl()?'إغلاق':'Close' ?>">✕</button><h3><?= is_rtl()?'إضافة معرفة للمكتب':'Add firm knowledge' ?></h3><?php if($error && ($_POST['action']??'')==='add_knowledge'): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><div class="field"><label><?= is_rtl()?'العنوان':'Title' ?></label><input name="title" required maxlength="255"></div><div class="field"><label><?= is_rtl()?'التصنيف':'Category' ?></label><input name="category" maxlength="100"></div><div class="field"><label><?= is_rtl()?'محتوى داخلي للمكتب':'Internal firm content' ?></label><textarea name="content" rows="6" required></textarea></div><button class="btn btn-primary" type="submit"><?= is_rtl()?'حفظ المعرفة':'Save knowledge' ?></button></form></div>
<?php $knowledge=$pdo->prepare('SELECT k.*,l.full_name author_name FROM firm_knowledge k JOIN lawyers l ON l.id=k.author_lawyer_id WHERE k.firm_id=? ORDER BY k.updated_at DESC');$knowledge->execute([(int)$membership['id']]);foreach($knowledge->fetchAll() as $item): ?><div class="rec-item"><div style="display:flex;justify-content:space-between;gap:10px"><strong><?= htmlspecialchars($item['title']) ?></strong><small><?= htmlspecialchars($item['category'] ?? '') ?> · <?= htmlspecialchars($item['author_name']) ?></small></div><div style="white-space:pre-wrap;margin-top:8px"><?= htmlspecialchars($item['content']) ?></div><?php if($membership['member_role']==='owner'||(int)$item['author_lawyer_id']===$lawyerId): ?><form method="post" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_knowledge"><input type="hidden" name="knowledge_id" value="<?= (int)$item['id'] ?>"><button class="icon-btn danger" type="submit"><?= is_rtl()?'حذف':'Delete' ?></button></form><?php endif; ?></div><?php endforeach; ?></div>
<?php endif; ?></div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
