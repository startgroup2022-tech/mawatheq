<?php
/**
 * Affiliate applications review queue (Execution 32).
 *
 * The console that was missing: before this page existed, an application could only be
 * approved by knowing the numeric application id and calling the library directly —
 * affiliate_approve_application() and affiliate_reject_application() had no caller in
 * any route. A registration that arrived through the public form therefore sat in
 * `submitted` with nothing able to move it, which is why the journey stopped after
 * submission.
 *
 * Approval has two possible shapes, and the page handles both honestly:
 *   • the applicant registered an account (affiliate_id is set) — approving moves that
 *     account to `approved`, so the person can sign in, accept the terms and activate;
 *   • the applicant only submitted the public form (affiliate_id is null) — approving
 *     creates the account as `approved` with no password. That account cannot be signed
 *     into until the applicant is invited to set one, which the page states rather than
 *     pretending the affiliate is ready.
 *
 * Every state change goes through the library functions, so the transition rules and
 * the audit entry stay in one place.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/affiliate_admin.php';

$error = null;
$success = null;
$successNote = null;
$inviteLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
    $complianceApproved = !empty($_POST['compliance_approved']);

    if ($applicationId <= 0) {
        $error = 'معرّف الطلب غير صالح.';
    } elseif ($action === 'approve') {
        $result = affiliate_approve_application($pdo, $applicationId, [
            'note' => $note,
            'compliance_approved' => $complianceApproved,
        ]);
        if (!empty($result['ok'])) {
            $affiliateId = (int)($result['affiliate_id'] ?? 0);
            $linked = affiliate_find($pdo, $affiliateId);
            log_action($pdo, 'اعتماد طلب مسوّق #' . $applicationId, 'affiliate', $affiliateId);

            if ($linked && !empty($linked['password_hash'])) {
                $success = 'تم اعتماد الطلب. يمكن للمسوّق تسجيل الدخول وقبول الشروط للتفعيل.';
            } elseif ($linked) {
                // Created from a form-only application: no password exists yet.
                $success = 'تم اعتماد الطلب وإنشاء حساب المسوّق.';
                $successNote = 'لم يسجّل هذا المتقدّم حساباً بكلمة مرور، لذلك لا يمكنه تسجيل الدخول بعد. أرسل له دعوة من صفحة تفاصيل المسوّق ليتمكّن من تعيين كلمة مرور.';
            } else {
                $success = 'تم اعتماد الطلب.';
            }
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'already_reviewed' => 'تمت مراجعة هذا الطلب مسبقاً.',
                'not_found' => 'الطلب غير موجود.',
                'email_exists' => 'يوجد حساب مسوّق بهذا البريد بالفعل.',
                default => 'تعذّر اعتماد الطلب.',
            };
        }
    } elseif ($action === 'invite') {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_applications WHERE id=?');
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
        if (!$application) {
            $error = 'الطلب غير موجود.';
        } elseif (empty($application['affiliate_id'])) {
            $error = 'لا يوجد حساب لهذا الطلب بعد. اعتمد الطلب أولاً.';
        } else {
            $linked = affiliate_find($pdo, (int)$application['affiliate_id']);
            if ($linked && !empty($linked['password_hash'])) {
                $error = 'هذا الحساب لديه كلمة مرور بالفعل. استخدم «إعادة تعيين كلمة المرور» في صفحة تفاصيل المسوّق.';
            } else {
                $invitation = affiliate_create_invitation($pdo, [
                    'email' => (string)$application['email'],
                    'full_name' => (string)$application['full_name'],
                    'invite_type' => (string)$application['applicant_type'],
                    'preapproved' => true,
                ]);
                if (!empty($invitation['ok'])) {
                    log_action($pdo, 'دعوة مسوّق #' . $applicationId, 'affiliate', (int)$application['affiliate_id']);
                    $success = 'تم إنشاء دعوة لتعيين كلمة المرور.';
                    // The token is returned once, in plaintext, and only its hash is stored,
                    // so this is the only moment the link can be shown. It is displayed
                    // rather than emailed because delivery depends on the deployment's mail
                    // configuration, and a silently undelivered invitation would look like
                    // a working approval.
                    $inviteLink = rtrim(SITE_URL, '/') . '/affiliate/accept-invite.php?token=' . $invitation['token'];
                } else {
                    $error = match ((string)($invitation['error'] ?? '')) {
                        'email_exists' => 'يوجد حساب بهذا البريد بالفعل.',
                        default => 'تعذّر إنشاء الدعوة.',
                    };
                }
            }
        }
    } elseif ($action === 'reject') {
        $result = affiliate_reject_application($pdo, $applicationId, $note);
        if (!empty($result['ok'])) {
            log_action($pdo, 'رفض طلب مسوّق #' . $applicationId, 'affiliate', null);
            $success = 'تم رفض الطلب.';
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'reason_required' => 'يجب كتابة سبب الرفض.',
                default => 'تعذّر رفض الطلب.',
            };
        }
    }
}

$statusFilter = is_string($_GET['status'] ?? null) && $_GET['status'] !== '' ? $_GET['status'] : 'open';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

// `open` is the queue that needs a decision; the individual statuses let an operator
// look back at what was already decided.
$where = [];
$params = [];
if ($statusFilter === 'open') {
    $where[] = "a.status IN ('submitted','verification','compliance_review')";
} elseif (in_array($statusFilter, ['submitted', 'verification', 'compliance_review', 'approved', 'rejected', 'withdrawn'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(a.reference LIKE ? OR a.email LIKE ? OR a.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sql = 'SELECT a.*, f.code AS affiliate_code, f.status AS affiliate_status, f.password_hash IS NOT NULL AS has_password
        FROM affiliate_applications a
        LEFT JOIN affiliates f ON f.id = a.affiliate_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT 200';

$applications = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'تعذّر تحميل الطلبات: ' . $e->getMessage();
}

// Queue counts, so the operator can see how much is waiting without filtering.
$queueCounts = ['open' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $stmt = $pdo->query("SELECT
            SUM(status IN ('submitted','verification','compliance_review')) AS open_count,
            SUM(status = 'approved') AS approved_count,
            SUM(status = 'rejected') AS rejected_count
        FROM affiliate_applications");
    $row = $stmt->fetch() ?: [];
    $queueCounts = [
        'open' => (int)($row['open_count'] ?? 0),
        'approved' => (int)($row['approved_count'] ?? 0),
        'rejected' => (int)($row['rejected_count'] ?? 0),
    ];
} catch (Throwable $e) { /* table absent on a pre-v57 database */ }

$statusLabels = [
    'submitted' => 'مُقدَّم',
    'verification' => 'قيد التحقّق',
    'compliance_review' => 'مراجعة الالتزام',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض',
    'withdrawn' => 'مسحوب',
];

$pageTitle = 'طلبات الانضمام للشركاء';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="adm-page">
  <div class="adm-page-head">
    <div>
      <h1>طلبات الانضمام للشركاء</h1>
      <p class="muted">الطلبات الواردة من صفحة الشركاء ومن التسجيل المباشر. الاعتماد ينقل الحساب إلى «معتمد»، وبعد قبول الشروط يصبح «نشطاً» وتعمل روابط الإحالة.</p>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars($success) ?>
      <?php if ($successNote): ?><p style="margin:6px 0 0"><?= htmlspecialchars($successNote) ?></p><?php endif; ?>
      <?php if ($inviteLink): ?>
        <p style="margin:8px 0 0">أرسل هذا الرابط للمتقدّم ليتمكّن من تعيين كلمة المرور وقبول الشروط:</p>
        <p style="margin:6px 0 0"><input type="text" readonly value="<?= htmlspecialchars($inviteLink) ?>" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()"></p>
        <p style="margin:6px 0 0"><small class="muted">يُعرض الرابط مرة واحدة فقط. إن فُقد، أنشئ دعوة جديدة.</small></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="adm-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
    <?php
    $tabs = [
      'open' => ['قيد المراجعة', $queueCounts['open']],
      'approved' => ['معتمدة', $queueCounts['approved']],
      'rejected' => ['مرفوضة', $queueCounts['rejected']],
      'all' => ['الكل', null],
    ];
    foreach ($tabs as $key => [$label, $count]):
        $active = $statusFilter === $key;
    ?>
      <a class="btn <?= $active ? 'btn-primary' : 'btn-outline' ?> btn-sm"
         href="?status=<?= urlencode($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
        <?= htmlspecialchars($label) ?><?= $count !== null ? ' (' . (int)$count . ')' : '' ?>
      </a>
    <?php endforeach; ?>
    <form method="get" style="display:flex;gap:6px;margin-inline-start:auto">
      <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="رقم المرجع أو البريد أو الاسم" style="min-width:240px">
      <button class="btn btn-outline btn-sm" type="submit">بحث</button>
    </form>
  </div>

  <div class="adm-card">
    <table class="adm-table" data-sortable>
      <thead><tr>
        <th>المرجع</th><th>المتقدّم</th><th>البريد</th><th>النوع</th><th>الحساب</th>
        <th>الحالة</th><th>تاريخ التقديم</th><th>الإجراءات</th>
      </tr></thead>
      <tbody>
        <?php if (!$applications): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">لا توجد طلبات مطابقة.</td></tr>
        <?php else: foreach ($applications as $a):
            $status = (string)$a['status'];
            $decidable = in_array($status, ['submitted', 'verification', 'compliance_review'], true);
            $hasAccount = !empty($a['affiliate_id']);
        ?>
        <tr>
          <td data-label="المرجع" style="font-family:monospace"><?= htmlspecialchars((string)$a['reference']) ?></td>
          <td data-label="المتقدّم"><?= htmlspecialchars((string)$a['full_name']) ?></td>
          <td data-label="البريد" style="font-family:monospace;font-size:12px"><?= htmlspecialchars((string)$a['email']) ?></td>
          <td data-label="النوع"><?= htmlspecialchars((string)$a['applicant_type']) ?></td>
          <td data-label="الحساب">
            <?php if ($hasAccount): ?>
              <a href="/admin/affiliate-detail.php?id=<?= (int)$a['affiliate_id'] ?>" style="font-family:monospace"><?= htmlspecialchars((string)$a['affiliate_code']) ?></a>
              <?php if (empty($a['has_password'])): ?><br><small class="muted">بلا كلمة مرور</small><?php endif; ?>
            <?php else: ?>
              <span class="muted">لا يوجد</span>
            <?php endif; ?>
          </td>
          <td data-label="الحالة"><span class="status-pill <?= $status === 'approved' ? 'status-confirmed' : ($status === 'rejected' ? 'status-cancelled' : 'status-pending') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status) ?></span></td>
          <td data-label="تاريخ التقديم"><?= htmlspecialchars(date('Y-m-d', strtotime((string)$a['created_at']))) ?></td>
          <td data-label="الإجراءات">
            <?php if ($decidable && admin_role_allows('affiliate-applications.php', $adminRole)): ?>
              <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                <input type="text" name="note" placeholder="ملاحظة / سبب الرفض" style="min-width:150px">
                <label style="display:flex;gap:4px;align-items:center;font-size:12px">
                  <input type="checkbox" name="compliance_approved" value="1"> الالتزام معتمد
                </label>
                <button class="btn btn-primary btn-sm" type="submit" name="action" value="approve">اعتماد</button>
                <button class="btn btn-outline btn-sm" type="submit" name="action" value="reject">رفض</button>
              </form>
            <?php elseif ($hasAccount && empty($a['has_password']) && admin_role_allows('affiliate-applications.php', $adminRole)): ?>
              <?php // Approved from the public form, so the account has no password. An
                    // invitation is the only way for this applicant to sign in. ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit" name="action" value="invite">إنشاء دعوة لتعيين كلمة المرور</button>
              </form>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
