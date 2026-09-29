<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/specialization_field.php';
require_once __DIR__ . '/../includes/profile_completion.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/jurisdiction.php';

$createError = null;
$oldCreate = [
    'full_name' => '',
    'email' => '',
    'phone_dial' => '+966',
    'phone_local' => '',
    'specialization' => '',
    'years_experience' => '0',
    'hourly_rate' => '0',
    'jurisdiction' => 'السعودية'
];

// إضافة محامٍ جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $oldCreate = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone_dial' => $_POST['phone_dial'] ?? '+966',
        'phone_local' => $_POST['phone_local'] ?? '',
        'specialization' => $_POST['specialization'] ?? '',
        'years_experience' => $_POST['years_experience'] ?? '0',
        'hourly_rate' => $_POST['hourly_rate'] ?? '0',
        'jurisdiction' => $_POST['jurisdiction'] ?? 'السعودية',
    ];

    $phoneResult = validate_phone($oldCreate['phone_dial'], $oldCreate['phone_local']);

    if (
        !$oldCreate['full_name'] ||
        !filter_var($oldCreate['email'], FILTER_VALIDATE_EMAIL) ||
        strlen($_POST['password'] ?? '') < 8
    ) {
        $createError = 'يرجى تعبئة الاسم والبريد بصيغة صحيحة، وكلمة مرور 8 أحرف على الأقل.';
    } elseif (!$phoneResult['ok']) {
        $createError = $phoneResult['error'];
    } else {
        $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO lawyers
            (full_name,email,phone,password_hash,specialization,years_experience,hourly_rate,jurisdiction,status)
            VALUES (?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $oldCreate['full_name'],
            $oldCreate['email'],
            $phoneResult['value'],
            $hash,
            trim($oldCreate['specialization']),
            (int)$oldCreate['years_experience'],
            (float)$oldCreate['hourly_rate'],
            $oldCreate['jurisdiction'],
            'approved'
        ]);
        log_action($pdo, "إضافة محامٍ جديد: '" . $oldCreate['full_name'] . "'", 'lawyer', (int)$pdo->lastInsertId());

        header('Location: /admin/lawyers.php');
        exit;
    }
}

// تغيير الحالة عبر POST محمي بـCSRF
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='toggle_status') {
    $idInput = $_POST['lawyer_id'] ?? null;
    $id = is_scalar($idInput) ? (int)$idInput : 0;

    if ($id < 1) {
        header('Location: /admin/lawyers.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT status, full_name FROM lawyers WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        header('Location: /admin/lawyers.php');
        exit;
    }

    $cur = (string)($row['status'] ?? 'pending');
    $new = $cur === 'approved' ? 'suspended' : 'approved';

    $pdo->prepare('UPDATE lawyers SET status=? WHERE id=?')->execute([$new, $id]);
    log_action($pdo, "تغيير حالة المحامي '" . ($row['full_name'] ?? $id) . "' إلى $new", 'lawyer', $id);
    if ($new === 'approved') {
        try {
            notify($pdo, 'lawyer', $id, is_rtl() ? 'تم اعتماد حسابك 🎉' : 'Your account was approved 🎉', is_rtl() ? 'يمكنك الآن الدخول والبدء باستقبال الاستشارات.' : 'You can now log in and start receiving consultations.', '/lawyer/dashboard.php');
        } catch (Throwable $e) {
            // Approval must remain successful even if optional notifications
            // are unavailable (for example, on an incompletely upgraded DB).
            error_log('Lawyer approval notification failed for lawyer ' . $id . ': ' . $e->getMessage());
        }
    }

    header('Location: /admin/lawyers.php');
    exit;
}

// حذف محامٍ
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete_lawyer') {
    $delId = (int)($_POST['lawyer_id'] ?? 0);
    $nameStmt = $pdo->prepare('SELECT full_name FROM lawyers WHERE id=?');
    $nameStmt->execute([$delId]);
    $delName = $nameStmt->fetchColumn();
    $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$delId]);
    log_action($pdo, "حذف المحامي '" . ($delName ?: $delId) . "'", 'lawyer', $delId);

    header('Location: /admin/lawyers.php');
    exit;
}

$activeAdmin = 'lawyers';
require_once __DIR__ . '/includes/admin_header.php';

$lawyers = $pdo->query('SELECT * FROM lawyers ORDER BY id DESC')->fetchAll();

$totalLawyers = count($lawyers);
$activeLawyers = 0;
$suspendedLawyers = 0;

foreach ($lawyers as $lawyer) {
    if (($lawyer['status'] ?? '') === 'approved') {
        $activeLawyers++;
    } else {
        $suspendedLawyers++;
    }
}
?>

<style>
/* =========================================================
   Lawyers Admin Page
   التصميم محصور داخل الصفحة حتى لا يؤثر على بقية لوحة التحكم
   ========================================================= */

.lawyers-page {
    --lawyers-primary: #2563eb;
    --lawyers-primary-dark: #1d4ed8;
    --lawyers-success: #16a34a;
    --lawyers-danger: #dc2626;
    --lawyers-warning: #d97706;
    --lawyers-text: #172033;
    --lawyers-muted: #667085;
    --lawyers-border: #e7eaf0;
    --lawyers-bg: #f6f8fb;
    --lawyers-card: #ffffff;

    direction: rtl;
    color: var(--lawyers-text);
    padding-bottom: 32px;
}

.lawyers-page * {
    box-sizing: border-box;
}

.lawyers-page .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
}

.lawyers-page .page-title-wrap {
    min-width: 0;
}

.lawyers-page .page-title {
    margin: 0;
    font-size: 26px;
    line-height: 1.3;
    font-weight: 800;
    letter-spacing: -0.3px;
}

.lawyers-page .page-subtitle {
    margin: 7px 0 0;
    color: var(--lawyers-muted);
    font-size: 14px;
}

.lawyers-page .count-badge {
    display: inline-flex;
    min-width: 32px;
    height: 27px;
    padding: 0 9px;
    align-items: center;
    justify-content: center;
    margin-right: 7px;
    border-radius: 999px;
    background: #eff6ff;
    color: var(--lawyers-primary);
    font-size: 13px;
    font-weight: 800;
    vertical-align: middle;
}

.lawyers-page .primary-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 46px;
    padding: 0 18px;
    border: 0;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--lawyers-primary), #3b82f6);
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(37, 99, 235, .18);
    transition: .18s ease;
}

.lawyers-page .primary-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 11px 24px rgba(37, 99, 235, .24);
    background: var(--lawyers-primary-dark);
}

.lawyers-page .primary-action .plus {
    font-size: 21px;
    line-height: 1;
    font-weight: 400;
}

/* Statistics */
.lawyers-page .stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.lawyers-page .stat-card {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 92px;
    padding: 17px;
    border: 1px solid var(--lawyers-border);
    border-radius: 16px;
    background: var(--lawyers-card);
    box-shadow: 0 5px 20px rgba(16, 24, 40, .035);
}

.lawyers-page .stat-icon {
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    background: #eff6ff;
    color: var(--lawyers-primary);
    font-size: 21px;
}

.lawyers-page .stat-card:nth-child(2) .stat-icon {
    background: #ecfdf3;
    color: var(--lawyers-success);
}

.lawyers-page .stat-card:nth-child(3) .stat-icon {
    background: #fff7ed;
    color: var(--lawyers-warning);
}

.lawyers-page .stat-label {
    color: var(--lawyers-muted);
    font-size: 12px;
    margin-bottom: 3px;
}

.lawyers-page .stat-value {
    font-size: 23px;
    line-height: 1.1;
    font-weight: 800;
}

/* Table Card */
.lawyers-page .list-card {
    overflow: hidden;
    border: 1px solid var(--lawyers-border);
    border-radius: 17px;
    background: var(--lawyers-card);
    box-shadow: 0 7px 25px rgba(16, 24, 40, .045);
}

.lawyers-page .list-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    min-height: 66px;
    padding: 14px 18px;
    border-bottom: 1px solid var(--lawyers-border);
}

.lawyers-page .list-card-title {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}

.lawyers-page .list-card-caption {
    margin: 4px 0 0;
    color: var(--lawyers-muted);
    font-size: 12px;
}

.lawyers-page .table-scroll {
    width: 100%;
    overflow-x: auto;
}

.lawyers-page .lawyers-table {
    width: 100%;
    min-width: 1120px;
    border-collapse: separate;
    border-spacing: 0;
}

.lawyers-page .lawyers-table th {
    padding: 13px 15px;
    background: #fafbfc;
    color: #667085;
    font-size: 11px;
    font-weight: 800;
    text-align: right;
    white-space: nowrap;
    border-bottom: 1px solid var(--lawyers-border);
}

.lawyers-page .lawyers-table td {
    padding: 14px 15px;
    color: #344054;
    font-size: 12px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f2f5;
}

.lawyers-page .lawyers-table tbody tr {
    transition: background .15s ease;
}

.lawyers-page .lawyers-table tbody tr:hover {
    background: #fbfdff;
}

.lawyers-page .lawyers-table tbody tr:last-child td {
    border-bottom: 0;
}

.lawyers-page .lawyer-cell {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 180px;
}

.lawyers-page .lawyer-avatar {
    width: 39px;
    height: 39px;
    flex: 0 0 39px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: linear-gradient(135deg, #dbeafe, #eff6ff);
    color: var(--lawyers-primary);
    font-size: 14px;
    font-weight: 900;
}

.lawyers-page .lawyer-name {
    color: #172033;
    font-weight: 800;
    font-size: 12px;
}

.lawyers-page .lawyer-id {
    color: #98a2b3;
    font-size: 10px;
    margin-top: 2px;
}

.lawyers-page .email-cell {
    color: #475467;
    direction: ltr;
    text-align: right;
}

.lawyers-page .specialization-cell {
    max-width: 160px;
    line-height: 1.7;
}

.lawyers-page .rating {
    white-space: nowrap;
    color: #b45309;
    font-weight: 800;
}

.lawyers-page .rating-star {
    color: #f59e0b;
    margin-left: 2px;
}

.lawyers-page .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.lawyers-page .status-pill::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.lawyers-page .status-approved {
    background: #ecfdf3;
    color: #15803d;
}

.lawyers-page .status-suspended {
    background: #fef2f2;
    color: #b91c1c;
}

.lawyers-page .status-default {
    background: #f2f4f7;
    color: #667085;
}

.lawyers-page .source-text {
    max-width: 125px;
    color: #667085;
    font-size: 10px;
    line-height: 1.6;
}

.lawyers-page .completion-cell {
    min-width: 125px;
}

.lawyers-page .actions {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 6px;
    white-space: nowrap;
}

.lawyers-page .action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 0 9px;
    border: 1px solid var(--lawyers-border);
    border-radius: 8px;
    background: #fff;
    color: #475467;
    text-decoration: none;
    font-size: 10px;
    font-weight: 800;
    transition: .15s ease;
}

.lawyers-page .action-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: var(--lawyers-primary);
}

.lawyers-page .action-btn.danger {
    color: var(--lawyers-danger);
}

.lawyers-page .action-btn.danger:hover {
    background: #fff5f5;
    border-color: #fecaca;
}

.lawyers-page .empty-state {
    padding: 58px 20px;
    text-align: center;
}

.lawyers-page .empty-icon {
    width: 54px;
    height: 54px;
    margin: 0 auto 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    background: #f2f4f7;
    color: #98a2b3;
    font-size: 22px;
}

.lawyers-page .empty-title {
    margin: 0 0 5px;
    font-size: 14px;
    font-weight: 800;
}

.lawyers-page .empty-text {
    margin: 0;
    color: var(--lawyers-muted);
    font-size: 12px;
}

/* Modal */
.lawyers-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, .58);
    backdrop-filter: blur(5px);
}

.lawyers-modal.is-open {
    display: flex;
    animation: lawyersFadeIn .18s ease;
}

.lawyers-modal .modal-card {
    width: min(720px, 100%);
    max-height: calc(100vh - 48px);
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.35);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 30px 80px rgba(15, 23, 42, .24);
    animation: lawyersModalIn .2s ease;
}

.lawyers-modal .modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 21px;
    border-bottom: 1px solid var(--lawyers-border);
}

.lawyers-modal .modal-heading {
    display: flex;
    align-items: center;
    gap: 12px;
}

.lawyers-modal .modal-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #eff6ff;
    color: var(--lawyers-primary);
    font-size: 19px;
}

.lawyers-modal .modal-title {
    margin: 0;
    color: #172033;
    font-size: 17px;
    font-weight: 900;
}

.lawyers-modal .modal-subtitle {
    margin: 3px 0 0;
    color: var(--lawyers-muted);
    font-size: 11px;
}

.lawyers-modal .modal-close {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    border: 0;
    border-radius: 10px;
    background: #f2f4f7;
    color: #667085;
    cursor: pointer;
    font-size: 16px;
    transition: .15s ease;
}

.lawyers-modal .modal-close:hover {
    background: #e4e7ec;
    color: #344054;
}

.lawyers-modal .modal-body {
    max-height: calc(100vh - 180px);
    overflow-y: auto;
    padding: 20px 21px 8px;
}

.lawyers-modal .form-section {
    margin-bottom: 20px;
}

.lawyers-modal .form-section:last-child {
    margin-bottom: 8px;
}

.lawyers-modal .section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 12px;
    color: #344054;
    font-size: 12px;
    font-weight: 900;
}

.lawyers-modal .section-title::before {
    content: "";
    width: 3px;
    height: 15px;
    border-radius: 99px;
    background: var(--lawyers-primary);
}

.lawyers-modal .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 13px;
}

.lawyers-modal .field {
    min-width: 0;
}

.lawyers-modal .field.full {
    grid-column: 1 / -1;
}

.lawyers-modal .field > label {
    display: block;
    margin: 0 0 6px;
    color: #344054;
    font-size: 11px;
    font-weight: 800;
}

.lawyers-modal .field input,
.lawyers-modal .field select,
.lawyers-modal .field textarea {
    width: 100%;
    min-height: 42px;
    border: 1px solid #dfe3ea;
    border-radius: 10px;
    background: #fff;
    color: #172033;
    padding: 9px 11px;
    outline: none;
    font-family: inherit;
    font-size: 12px;
    transition: .15s ease;
}

.lawyers-modal .field input:focus,
.lawyers-modal .field select:focus,
.lawyers-modal .field textarea:focus {
    border-color: #93c5fd;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .09);
}

.lawyers-modal .form-hint {
    margin: 5px 0 0;
    color: #98a2b3;
    font-size: 10px;
}

.lawyers-modal .alert {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 16px;
    padding: 11px 12px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fff5f5;
    color: #b42318;
    font-size: 11px;
    line-height: 1.7;
}

.lawyers-modal .modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 9px;
    padding: 15px 21px;
    border-top: 1px solid var(--lawyers-border);
    background: #fafbfc;
}

.lawyers-modal .secondary-btn,
.lawyers-modal .submit-btn {
    min-height: 41px;
    padding: 0 16px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}

.lawyers-modal .secondary-btn {
    border: 1px solid #dfe3ea;
    background: #fff;
    color: #475467;
}

.lawyers-modal .submit-btn {
    border: 0;
    background: linear-gradient(135deg, var(--lawyers-primary), #3b82f6);
    color: #fff;
    box-shadow: 0 7px 17px rgba(37, 99, 235, .18);
}

.lawyers-modal .submit-btn:hover {
    background: var(--lawyers-primary-dark);
}

body.lawyers-modal-open {
    overflow: hidden;
}

@keyframes lawyersFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes lawyersModalIn {
    from { opacity: 0; transform: translateY(12px) scale(.985); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

@media (max-width: 850px) {
    .lawyers-page .page-head {
        align-items: stretch;
        flex-direction: column;
    }

    .lawyers-page .primary-action {
        width: 100%;
    }

    .lawyers-page .stats-grid {
        grid-template-columns: 1fr;
    }

    .lawyers-modal {
        padding: 12px;
    }

    .lawyers-modal .modal-card {
        max-height: calc(100vh - 24px);
        border-radius: 16px;
    }

    .lawyers-modal .modal-body {
        max-height: calc(100vh - 170px);
    }
}

@media (max-width: 600px) {
    .lawyers-page .page-title {
        font-size: 22px;
    }

    .lawyers-page .list-card-head {
        align-items: flex-start;
        flex-direction: column;
    }

    .lawyers-modal .form-grid {
        grid-template-columns: 1fr;
    }

    .lawyers-modal .field.full {
        grid-column: auto;
    }

    .lawyers-modal .modal-footer {
        flex-direction: column-reverse;
    }

    .lawyers-modal .secondary-btn,
    .lawyers-modal .submit-btn {
        width: 100%;
    }
}
</style>

<div class="app-content lawyers-page">

    <div class="page-head">
        <div class="page-title-wrap">
            <h1 class="page-title">
                إدارة المحامين
                <span class="count-badge"><?= $totalLawyers ?></span>
            </h1>
            <p class="page-subtitle">إدارة بيانات المحامين وحالات الحسابات وملفاتهم من مكان واحد.</p>
        </div>

        <button
            type="button"
            class="primary-action"
            onclick="openLawyerModal()"
            aria-controls="addLawyerModal"
        >
            <span class="plus">+</span>
            <span>إضافة محامٍ</span>
        </button>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">⚖</div>
            <div>
                <div class="stat-label">إجمالي المحامين</div>
                <div class="stat-value"><?= $totalLawyers ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <div class="stat-label">الحسابات المفعلة</div>
                <div class="stat-value"><?= $activeLawyers ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">!</div>
            <div>
                <div class="stat-label">الموقوفون</div>
                <div class="stat-value"><?= $suspendedLawyers ?></div>
            </div>
        </div>
    </div>

    <div class="list-card">
        <div class="list-card-head">
            <div>
                <h2 class="list-card-title">قائمة المحامين</h2>
                <p class="list-card-caption">جميع المحامين المسجلين في النظام</p>
            </div>

            <button type="button" class="primary-action" onclick="openLawyerModal()">
                <span class="plus">+</span>
                <span>إضافة محامٍ</span>
            </button>
        </div>

        <div class="table-scroll">
            <table class="lawyers-table">
                <thead>
                    <tr>
                        <th>المحامي</th>
                        <th>البريد الإلكتروني</th>
                        <th>الجوال</th>
                        <th>التخصص</th>
                        <th>الخبرة</th>
                        <th>التقييم</th>
                        <th>اكتمال الملف</th>
                        <th>المصدر</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($lawyers as $lw): ?>
                    <?php
                    $pct = lawyer_completion_percent($lw);
                    $status = $lw['status'] ?? '';
                    $statusClass = $status === 'approved'
                        ? 'status-approved'
                        : ($status === 'suspended' ? 'status-suspended' : 'status-default');

                    $name = trim((string)($lw['full_name'] ?? ''));
                    $avatarLetter = function_exists('mb_substr')
                        ? mb_substr($name ?: 'م', 0, 1, 'UTF-8')
                        : substr($name ?: 'م', 0, 1);
                    ?>
                    <tr>
                        <td>
                            <div class="lawyer-cell">
                                <div class="lawyer-avatar"><?= htmlspecialchars($avatarLetter) ?></div>
                                <div>
                                    <div class="lawyer-name"><?= htmlspecialchars($name) ?></div>
                                    <div class="lawyer-id">#<?= (int)$lw['id'] ?></div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <div class="email-cell"><?= htmlspecialchars($lw['email']) ?></div>
                        </td>

                        <td><?= display_phone($lw['phone'] ?? null) ?></td>

                        <td>
                            <div class="specialization-cell">
                                <?= htmlspecialchars($lw['specialization'] ?: '—') ?>
                            </div>
                        </td>

                        <td><?= (int)$lw['years_experience'] ?> سنة</td>

                        <td>
                            <span class="rating">
                                <span class="rating-star">★</span>
                                <?= htmlspecialchars((string)$lw['rating']) ?>
                            </span>
                        </td>

                        <td class="completion-cell">
                            <?php render_progress_bar($pct); ?>
                        </td>

                        <td>
                            <div class="source-text">
                                <?= htmlspecialchars(
                                    format_lead_source(
                                        $lw['utm_source'] ?? null,
                                        $lw['utm_medium'] ?? null,
                                        $lw['utm_campaign'] ?? null
                                    )
                                ) ?>
                            </div>
                        </td>

                        <td>
                            <span class="status-pill <?= $statusClass ?>">
                                <?= htmlspecialchars($status ?: 'غير محدد') ?>
                            </span>
                        </td>

                        <td>
                            <div class="actions">
                                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="lawyer_id" value="<?= (int)$lw['id'] ?>"><button class="action-btn" type="submit" title="<?= $status === 'approved' ? 'إيقاف الحساب' : 'تفعيل الحساب' ?>"><?= $status === 'approved' ? 'إيقاف' : 'تفعيل' ?></button></form>
                                <form method="post" style="display:inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا المحامي؟ لا يمكن التراجع عن هذه العملية.')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_lawyer"><input type="hidden" name="lawyer_id" value="<?= (int)$lw['id'] ?>"><button class="action-btn danger" type="submit">حذف</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($lawyers)): ?>
                    <tr>
                        <td colspan="10">
                            <div class="empty-state">
                                <div class="empty-icon">⚖</div>
                                <h3 class="empty-title">لا يوجد محامون بعد</h3>
                                <p class="empty-text">ابدأ بإضافة أول محامٍ إلى النظام باستخدام زر «إضافة محامٍ».</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal إضافة محامٍ -->
<div
    class="lawyers-modal<?= $createError ? ' is-open' : '' ?>"
    id="addLawyerModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="addLawyerTitle"
    onclick="handleLawyerModalBackdrop(event)"
>
    <form class="modal-card" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div class="modal-head">
            <div class="modal-heading">
                <div class="modal-icon">⚖</div>
                <div>
                    <h2 class="modal-title" id="addLawyerTitle">إضافة محامٍ جديد</h2>
                    <p class="modal-subtitle">أدخل البيانات الأساسية للمحامي لإنشاء حسابه.</p>
                </div>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeLawyerModal()"
                aria-label="إغلاق"
            >✕</button>
        </div>

        <div class="modal-body">
            <input type="hidden" name="action" value="create">

            <?php if ($createError): ?>
                <div class="alert">
                    <span>⚠</span>
                    <span><?= htmlspecialchars($createError) ?></span>
                </div>
            <?php endif; ?>

            <div class="form-section">
                <h3 class="section-title">البيانات الأساسية</h3>

                <div class="form-grid">
                    <div class="field">
                        <label for="lawyer_full_name">الاسم الكامل</label>
                        <input
                            id="lawyer_full_name"
                            type="text"
                            name="full_name"
                            value="<?= htmlspecialchars($oldCreate['full_name']) ?>"
                            placeholder="مثال: محمد أحمد"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="lawyer_email">البريد الإلكتروني</label>
                        <input
                            id="lawyer_email"
                            type="email"
                            name="email"
                            value="<?= htmlspecialchars($oldCreate['email']) ?>"
                            placeholder="name@example.com"
                            dir="ltr"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>رقم الجوال</label>
                        <?php render_phone_field('phone', $oldCreate['phone_dial'], $oldCreate['phone_local']); ?>
                    </div>

                    <div class="field">
                        <label for="lawyer_password">كلمة مرور مؤقتة</label>
                        <input
                            id="lawyer_password"
                            type="text"
                            name="password"
                            minlength="8"
                            placeholder="8 أحرف على الأقل"
                            required
                        >
                        <div class="form-hint">يمكن للمحامي تغييرها لاحقًا من حسابه.</div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3 class="section-title">التخصص والخبرة</h3>

                <div class="form-grid">
                    <div class="field full">
                        <label>التخصص</label>
                        <?php render_specialization_field('specialization', $oldCreate['specialization']); ?>
                        <div class="form-hint">يمكن اختيار أكثر من تخصص إذا كانت الأداة الحالية تدعم ذلك.</div>
                    </div>

                    <div class="field">
                        <label>الدولة / الاختصاص القضائي</label>
                        <?php render_jurisdiction_select('jurisdiction', $oldCreate['jurisdiction'] ?? 'السعودية'); ?>
                    </div>

                    <div class="field">
                        <label for="lawyer_experience">سنوات الخبرة</label>
                        <input
                            id="lawyer_experience"
                            type="number"
                            min="0"
                            name="years_experience"
                            value="<?= htmlspecialchars($oldCreate['years_experience']) ?>"
                            placeholder="0"
                        >
                    </div>

                    <div class="field">
                        <label for="lawyer_rate">السعر بالساعة (د.ب)</label>
                        <input
                            id="lawyer_rate"
                            type="number"
                            step="0.001"
                            min="0"
                            name="hourly_rate"
                            value="<?= htmlspecialchars($oldCreate['hourly_rate']) ?>"
                            placeholder="0.00"
                        >
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="secondary-btn" onclick="closeLawyerModal()">إلغاء</button>
            <button type="submit" class="submit-btn">إضافة المحامي</button>
        </div>
    </form>
</div>

<script>
(function () {
    const modal = document.getElementById('addLawyerModal');

    window.openLawyerModal = function () {
        if (!modal) return;
        modal.classList.add('is-open');
        document.body.classList.add('lawyers-modal-open');

        setTimeout(function () {
            const firstInput = document.getElementById('lawyer_full_name');
            if (firstInput) firstInput.focus();
        }, 80);
    };

    window.closeLawyerModal = function () {
        if (!modal) return;
        modal.classList.remove('is-open');
        document.body.classList.remove('lawyers-modal-open');
    };

    window.handleLawyerModalBackdrop = function (event) {
        if (event.target === modal) {
            closeLawyerModal();
        }
    };

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) {
            closeLawyerModal();
        }
    });

    <?php if ($createError): ?>
    document.body.classList.add('lawyers-modal-open');
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
