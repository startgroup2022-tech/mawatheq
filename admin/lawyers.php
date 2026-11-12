<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/phone.php';
require_once __DIR__ . '/../includes/specialization_field.php';
require_once __DIR__ . '/../includes/profile_completion.php';
require_once __DIR__ . '/../includes/lawyer_readiness.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/jurisdiction.php';
require_once __DIR__ . '/../includes/cities.php';
require_once __DIR__ . '/../includes/lawyer_experience.php';
require_once __DIR__ . '/../includes/lawyer_admin.php';

$createError = null;
$editError = null;
$createNotice = $_SESSION['flash_lawyers_create'] ?? null;
$editNotice = $_SESSION['flash_lawyers_edit'] ?? null;
unset($_SESSION['flash_lawyers_create'], $_SESSION['flash_lawyers_edit']);

$blankLawyer = [
    'full_name' => '',
    'email' => '',
    'phone_dial' => '+966',
    'phone_local' => '',
    'specialization' => '',
    'specializations' => [],
    'years_experience' => '0',
    'hourly_rate' => '',
    'jurisdiction' => 'السعودية',
    'country_code' => COUNTRY_DEFAULT,
    'city' => '',
    'status' => 'approved',
];
$oldCreate = $blankLawyer;

// ---------------------------------------------------------------- إضافة محامٍ جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $result = lawyer_admin_validate($pdo, $_POST, null, true);
    $oldCreate = $result['old'] + $blankLawyer;

    if (!$result['ok']) {
        $createError = $result['error'];
    } else {
        $d = $result['data'];
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO lawyers
                (full_name,email,phone,password_hash,specialization,specializations,years_experience,hourly_rate,
                 jurisdiction,country_code,city,status,email_verified)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)'
            );
            $stmt->execute([
                $d['full_name'], $d['email'], $d['phone'],
                password_hash($d['password'], PASSWORD_BCRYPT),
                $d['specialization'], $d['specializations'], $d['years_experience'], $d['hourly_rate'],
                $d['jurisdiction'], $d['country_code'], $d['city'], $d['status'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            log_action($pdo, "إضافة محامٍ جديد: '" . $d['full_name'] . "'", 'lawyer', $newId);
            $_SESSION['flash_lawyers_create'] = 'تمت إضافة المحامي «' . $d['full_name'] . '» بنجاح.';
            header('Location: /admin/lawyers.php');
            exit;
        } catch (PDOException $e) {
            // A duplicate email is the one race the pre-check cannot close; report it as
            // the same message rather than as a raw SQL error.
            $createError = ($e->getCode() === '23000')
                ? 'هذا البريد الإلكتروني مسجّل مسبقاً لحساب آخر.'
                : 'تعذّر حفظ المحامي. حاول مرة أخرى.';
            error_log('admin/lawyers create: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------- تعديل محامٍ
$editTarget = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $editId = (int)($_POST['lawyer_id'] ?? 0);
    $existingStmt = $pdo->prepare('SELECT * FROM lawyers WHERE id = ?');
    $existingStmt->execute([$editId]);
    $existing = $existingStmt->fetch();

    if (!$existing) {
        $editError = 'لم يتم العثور على المحامي المطلوب.';
    } else {
        $result = lawyer_admin_validate($pdo, $_POST, $editId, false);
        $oldEdit = $result['old'] + $blankLawyer;
        if (!$result['ok']) {
            $editError = $result['error'];
            $editTarget = $oldEdit + ['id' => $editId];
        } else {
            $d = $result['data'];
            try {
                $sql = 'UPDATE lawyers SET full_name=?, email=?, phone=?, specialization=?, specializations=?,
                        years_experience=?, hourly_rate=?, jurisdiction=?, country_code=?, city=?,
                        status=?';
                $params = [
                    $d['full_name'], $d['email'], $d['phone'], $d['specialization'], $d['specializations'],
                    $d['years_experience'], $d['hourly_rate'], $d['jurisdiction'],
                    $d['country_code'], $d['city'], $d['status'],
                ];
                if ($d['password'] !== '') {
                    $sql .= ', password_hash=?';
                    $params[] = password_hash($d['password'], PASSWORD_BCRYPT);
                }
                $sql .= ' WHERE id=?';
                $params[] = $editId;
                $pdo->prepare($sql)->execute($params);
                log_action($pdo, "تعديل بيانات المحامي '" . $d['full_name'] . "'", 'lawyer', $editId);
                $_SESSION['flash_lawyers_edit'] = 'تم حفظ تعديلات المحامي «' . $d['full_name'] . '».';
                header('Location: /admin/lawyers.php');
                exit;
            } catch (PDOException $e) {
                $editError = ($e->getCode() === '23000')
                    ? 'هذا البريد الإلكتروني مسجّل مسبقاً لحساب آخر.'
                    : 'تعذّر حفظ التعديلات. حاول مرة أخرى.';
                error_log('admin/lawyers edit: ' . $e->getMessage());
                $editTarget = $oldEdit + ['id' => $editId];
            }
        }
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

try {
    $lawyers = $pdo->query('SELECT l.*, f.name AS firm_name, m.member_role AS firm_role FROM lawyers l LEFT JOIN law_firm_members m ON m.lawyer_id=l.id AND m.status=\'active\' LEFT JOIN law_firms f ON f.id=m.firm_id ORDER BY l.id DESC')->fetchAll();
} catch (PDOException $e) {
    // Firm tables arrive with the v42 upgrade; the page must still work without them.
    $lawyers = $pdo->query('SELECT * FROM lawyers ORDER BY id DESC')->fetchAll();
}

$totalLawyers = count($lawyers);

// One batched pass for the per-lawyer counts the readiness signal needs, so the list
// does not run two queries per row.
$readinessCounts = lawyer_readiness_counts($pdo, array_map(fn($l) => (int)$l['id'], $lawyers));

// Readiness is evaluated once per lawyer and reused by both the ?readiness=incomplete
// filter and the row badge, so the two can never disagree. The dashboard's "approved
// but not ready to be booked" pill links here with that query parameter.
$readinessMap = [];
foreach ($lawyers as $lw) {
    $readinessMap[(int)$lw['id']] = lawyer_panel_readiness($pdo, $lw, $readinessCounts[(int)$lw['id']] ?? null);
}
$readinessFilter = ($_GET['readiness'] ?? '') === 'incomplete';
$unbookableCount = count(array_filter($readinessMap, fn($r) => empty($r['ready'])));
$visibleLawyers = $readinessFilter
    ? array_values(array_filter($lawyers, fn($l) => empty($readinessMap[(int)$l['id']]['ready'])))
    : $lawyers;
$visibleCount = count($visibleLawyers);
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
    font-size: 12px;
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
    font-size: 12px;
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
    font-size: 12px;
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
    font-size: 12px;
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
    /* 16px, matching the card's own `calc(100dvh - 32px)`. A 24px padding made the
       available height (100dvh - 48px) smaller than the card's max-height, so on a
       short viewport the card overflowed the centred flex line by 16px and clipped the
       footer — the "Add Lawyer button is cut off" defect in the screenshot. */
    padding: 16px;
    overflow-y: auto;
    background: rgba(15, 23, 42, .58);
    backdrop-filter: blur(5px);
}

.lawyers-modal.is-open {
    display: flex;
    animation: lawyersFadeIn .18s ease;
}

.lawyers-modal .modal-card {
    width: min(720px, 100%);
    max-height: calc(100vh - 32px);
    max-height: calc(100dvh - 32px);
    /* A flex column with a fixed max-height is what keeps the footer on screen: the
       header and footer are flex-shrink:0 and the body scrolls between them. The old
       layout gave the body its own `max-height: calc(100vh - 180px)`, a guess at the
       header+footer height that was wrong on a short viewport — the footer was pushed
       past the bottom edge and the Add button became unreachable. */
    display: flex;
    flex-direction: column;
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
    flex: 0 0 auto;
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
    /* flex:1 + min-height:0 is what lets the body scroll inside the flex column
       instead of stretching the card. */
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
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
    font-size: 13px;
    font-weight: 800;
}

.lawyers-modal .field input,
.lawyers-modal .field select,
.lawyers-modal .field textarea {
    width: 100%;
    min-height: 44px;
    border: 1px solid #dfe3ea;
    border-radius: 10px;
    background: #fff;
    color: #172033;
    padding: 10px 13px;
    outline: none;
    font-family: inherit;
    font-size: 14px;
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
    font-size: 12px;
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
    flex: 0 0 auto;
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

.lawyers-modal .submit-btn:disabled {
    opacity: .72;
    cursor: progress;
}

/* The busy label carries a spinner so "loading" is visible, not just a dimmed button. */
.lawyers-modal .submit-btn .btn-spinner,
.flash-alert .btn-spinner {
    display: inline-block;
    width: 13px;
    height: 13px;
    margin-inline-end: 7px;
    vertical-align: -1px;
    border: 2px solid rgba(255,255,255,.55);
    border-top-color: #fff;
    border-radius: 50%;
    animation: lawyersSpin .7s linear infinite;
}

@keyframes lawyersSpin {
    to { transform: rotate(360deg); }
}

.lawyers-modal .alert[hidden] { display: none; }

/* The alert is a real alert region; keep it readable at every width. */
.lawyers-modal .alert span { min-width: 0; overflow-wrap: anywhere; }

.lawyers-modal .field input,
.lawyers-modal .field select {
    /* min-width:0 is required for a grid child to shrink below its content width —
       without it a long Arabic name or a wide select forces the modal wider than the
       viewport and clips the field. */
    min-width: 0;
}

.lawyers-modal .field input[dir="ltr"] {
    text-align: left;
}

/* Specialization combobox: a search box above a native select. */
.lawyers-modal .spec-combo { display: flex; flex-direction: column; gap: 8px; }
.lawyers-modal .spec-combo-search {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}
.lawyers-modal .spec-combo-icon {
    position: absolute;
    inset-inline-start: 12px;
    font-size: 12px;
    pointer-events: none;
    opacity: .6;
}
.lawyers-modal .spec-combo-input { padding-inline-start: 34px; }
.lawyers-modal .spec-combo-toggle {
    flex: 0 0 42px;
    min-height: 42px;
    border: 1px solid #dfe3ea;
    border-radius: 10px;
    background: #fff;
    color: #475467;
    cursor: pointer;
    font-size: 14px;
}
.lawyers-modal .spec-combo-toggle:hover { border-color: #93c5fd; }
.lawyers-modal .spec-combo-empty {
    margin: 0;
    color: #b42318;
    font-size: 12px;
}
.lawyers-modal .spec-combo .pro-select[multiple] { min-height: 120px; }

/* A flash confirmation after a successful create/edit. */
.lawyers-page .flash-alert {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 18px;
    padding: 12px 15px;
    border: 1px solid #bbf7d0;
    border-radius: 12px;
    background: #f0fdf4;
    color: #15803d;
    font-size: 13px;
    font-weight: 700;
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
        max-height: calc(100dvh - 24px);
        border-radius: 16px;
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
            data-open-lawyer-modal
            aria-controls="addLawyerModal"
        >
            <span class="plus">+</span>
            <span>إضافة محامٍ</span>
        </button>
    </div>

    <?php if ($createNotice || $editNotice): ?>
        <div class="flash-alert success" role="status">
            <span aria-hidden="true">✓</span>
            <span><?= htmlspecialchars($createNotice ?: $editNotice) ?></span>
        </div>
    <?php endif; ?>

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
                <p class="list-card-caption"><?php if ($readinessFilter): ?><?= adm('المحامون غير الجاهزين للحجز', 'Lawyers not ready to be booked') ?> — <?= $visibleCount ?><?php else: ?><?= adm('جميع المحامين المسجلين في النظام', 'Every lawyer registered on the platform') ?> — <?= $totalLawyers ?><?php endif; ?></p>
            </div>

            <button type="button" class="primary-action" data-open-lawyer-modal>
                <span class="plus">+</span>
                <span>إضافة محامٍ</span>
            </button>
        </div>

        <?php if ($readinessFilter || $unbookableCount > 0): ?>
        <div class="readiness-filter" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:10px 18px;border-bottom:1px solid var(--lawyers-border, #e7eaf0)">
            <a class="status-pill <?= $readinessFilter ? 'status-default' : 'status-approved' ?>" href="/admin/lawyers.php" style="text-decoration:none"><?= adm('الكل', 'All') ?> (<?= $totalLawyers ?>)</a>
            <a class="status-pill <?= $readinessFilter ? 'status-approved' : 'status-default' ?>" href="/admin/lawyers.php?readiness=incomplete" style="text-decoration:none"><?= adm('غير جاهز للحجز', 'Not ready to be booked') ?> (<?= $unbookableCount ?>)</a>
        </div>
        <?php endif; ?>

        <div class="table-scroll">
            <table class="lawyers-table">
                <thead>
                    <tr>
                        <th>المحامي</th>
                        <th>البريد الإلكتروني</th>
                        <th>الجوال</th>
                        <th>التخصص</th>
                        <th>الدولة والمدينة</th>
                        <th>الخبرة</th>
                        <th>الأجر/ساعة</th>
                        <th>التقييم</th>
                        <th>اكتمال الملف</th>
                        <th>الجاهزية للحجز</th>
                        <th>المصدر</th>
                        <th>المكتب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($visibleLawyers as $lw): ?>
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
                                <?php $specLabels = lawyer_specialization_list($lw); ?>
                                <?= $specLabels ? htmlspecialchars(implode(' · ', $specLabels)) : '—' ?>
                            </div>
                        </td>

                        <td>
                            <?php $loc = lawyer_location_label(lawyer_country_code($lw), $lw['city'] ?? null); ?>
                            <div class="specialization-cell"><?= $loc !== '' ? htmlspecialchars($loc) : '—' ?></div>
                        </td>

                        <td><?= (int)$lw['years_experience'] ?> سنة</td>

                        <td>
                            <span class="rating" dir="ltr" style="unicode-bidi:isolate">
                                <?= $lw['hourly_rate'] !== null && (float)$lw['hourly_rate'] > 0
                                    ? htmlspecialchars(currency_number($lw['hourly_rate']) . ' ' . currency_symbol())
                                    : '—' ?>
                            </span>
                        </td>

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
                            <?php $ready = $readinessMap[(int)$lw['id']] ?? lawyer_panel_readiness($pdo, $lw, $readinessCounts[(int)$lw['id']] ?? null); ?>
                            <?php if ($ready['ready']): ?>
                                <span class="status-pill status-approved" title="<?= htmlspecialchars(adm('جاهز لاستقبال الحجوزات', 'Ready to take bookings')) ?>"><?= adm('جاهز', 'Ready') ?></span>
                            <?php else: ?>
                                <span class="status-pill status-default" title="<?= htmlspecialchars(lawyer_readiness_blocking_label($ready['blocking'])) ?>"><?= adm('غير مكتمل', 'Incomplete') ?></span>
                            <?php endif; ?>
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
                            <?php if (!empty($lw['firm_name'])): ?>
                                <div class="source-text"><?= htmlspecialchars($lw['firm_name']) ?><?php if (!empty($lw['firm_role'])): ?> · <?= htmlspecialchars($lw['firm_role']) ?><?php endif; ?></div>
                            <?php else: ?>
                                <span class="source-text">مستقل</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="status-pill <?= $statusClass ?>">
                                <?= htmlspecialchars($status ?: 'غير محدد') ?>
                            </span>
                        </td>

                        <td>
                            <div class="actions">
                                <button
                                    type="button"
                                    class="action-btn"
                                    data-edit-lawyer
                                    data-lawyer="<?= htmlspecialchars(json_encode([
                                        'id' => (int)$lw['id'],
                                        'full_name' => (string)($lw['full_name'] ?? ''),
                                        'email' => (string)($lw['email'] ?? ''),
                                        'specialization' => specialization_normalize_stored((string)($lw['specialization'] ?? '')),
                                        'specializations' => lawyer_specialization_list($lw),
                                        'years_experience' => (string)(int)($lw['years_experience'] ?? 0),
                                        'hourly_rate' => $lw['hourly_rate'] !== null ? (string)currency_number($lw['hourly_rate']) : '',
                                        'country_code' => lawyer_country_code($lw) ?? COUNTRY_DEFAULT,
                                        'city' => (string)($lw['city'] ?? ''),
                                        'status' => (string)($lw['status'] ?? 'approved'),
                                        'phone' => (string)($lw['phone'] ?? ''),
                                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                >تعديل</button>
                                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="lawyer_id" value="<?= (int)$lw['id'] ?>"><button class="action-btn" type="submit" title="<?= $status === 'approved' ? 'إيقاف الحساب' : 'تفعيل الحساب' ?>"><?= $status === 'approved' ? 'إيقاف' : 'تفعيل' ?></button></form>
                                <form method="post" style="display:inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا المحامي؟ لا يمكن التراجع عن هذه العملية.')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_lawyer"><input type="hidden" name="lawyer_id" value="<?= (int)$lw['id'] ?>"><button class="action-btn danger" type="submit">حذف</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($lawyers)): ?>
                    <tr>
                        <td colspan="13">
                            <div class="empty-state">
                                <div class="empty-icon">⚖</div>
                                <h3 class="empty-title">لا يوجد محامون بعد</h3>
                                <p class="empty-text">ابدأ بإضافة أول محامٍ إلى النظام باستخدام زر «إضافة محامٍ».</p>
                            </div>
                        </td>
                    </tr>
                <?php elseif ($readinessFilter && empty($visibleLawyers)): ?>
                    <tr>
                        <td colspan="13">
                            <div class="empty-state">
                                <div class="empty-icon">✓</div>
                                <h3 class="empty-title"><?= adm('كل المحامين جاهزون للحجز', 'Every lawyer is ready to be booked') ?></h3>
                                <p class="empty-text"><a href="/admin/lawyers.php"><?= adm('عرض الكل', 'Show all') ?></a></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// The create and edit forms are one form, switched between modes by the shared script.
// Two near-identical blocks would drift the moment a field is added to one of them.
$modalIsEdit = $editTarget !== null;
$modalOld = $modalIsEdit ? $editTarget : $oldCreate;
$modalError = $modalIsEdit ? $editError : $createError;
$modalPhone = split_phone((string)($modalOld['phone'] ?? ($modalOld['phone_dial'] ?? '+966')));
if (empty($modalOld['phone_local']) && !empty($modalPhone['local'])) {
    $modalOld['phone_dial'] = $modalPhone['dial'];
    $modalOld['phone_local'] = $modalPhone['local'];
}
$cityData = [];
foreach (city_options() as $code => $list) $cityData[$code] = array_values($list);
?>
<script type="application/json" id="mawatheq-city-data"><?= json_encode($cityData, JSON_UNESCAPED_UNICODE) ?></script>

<!-- Modal إضافة/تعديل محامٍ -->
<div
    class="lawyers-modal<?= $modalError ? ' is-open' : '' ?>"
    id="lawyerModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="lawyerModalTitle"
>
    <form class="modal-card" method="post" autocomplete="off" id="lawyerForm" data-lawyer-form data-submit-state="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" id="lawyerFormAction" value="<?= $modalIsEdit ? 'edit' : 'create' ?>">
        <input type="hidden" name="lawyer_id" id="lawyerFormId" value="<?= (int)($modalOld['id'] ?? 0) ?>">

        <div class="modal-head">
            <div class="modal-heading">
                <div class="modal-icon">⚖</div>
                <div>
                    <h2 class="modal-title" id="lawyerModalTitle"><?= $modalIsEdit ? 'تعديل بيانات المحامي' : 'إضافة محامٍ جديد' ?></h2>
                    <p class="modal-subtitle" id="lawyerModalSubtitle"><?= $modalIsEdit ? 'حدّث بيانات المحامي ثم احفظ التعديلات.' : 'أدخل البيانات الأساسية للمحامي لإنشاء حسابه.' ?></p>
                </div>
            </div>

            <button type="button" class="modal-close" data-close-lawyer-modal aria-label="إغلاق">✕</button>
        </div>

        <div class="modal-body">
            <div class="alert" id="lawyerFormError"<?= $modalError ? '' : ' hidden' ?> role="alert">
                <span aria-hidden="true">⚠</span>
                <span id="lawyerFormErrorText"><?= htmlspecialchars((string)$modalError) ?></span>
            </div>

            <div class="form-section">
                <h3 class="section-title">البيانات الأساسية</h3>

                <div class="form-grid">
                    <div class="field">
                        <label for="lawyer_full_name">الاسم الكامل</label>
                        <input
                            id="lawyer_full_name"
                            type="text"
                            name="full_name"
                            value="<?= htmlspecialchars((string)($modalOld['full_name'] ?? '')) ?>"
                            placeholder="مثال: محمد أحمد العبدالله"
                            maxlength="150"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="lawyer_email">البريد الإلكتروني</label>
                        <input
                            id="lawyer_email"
                            type="email"
                            name="email"
                            value="<?= htmlspecialchars((string)($modalOld['email'] ?? '')) ?>"
                            placeholder="name@example.com"
                            dir="ltr"
                            maxlength="150"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="lawyer_password">كلمة مرور مؤقتة</label>
                        <input
                            id="lawyer_password"
                            type="password"
                            name="password"
                            minlength="8"
                            maxlength="72"
                            placeholder="<?= $modalIsEdit ? 'اتركها فارغة للإبقاء على الحالية' : '8 أحرف على الأقل' ?>"
                            autocomplete="new-password"
                            <?= $modalIsEdit ? '' : 'required' ?>
                        >
                        <div class="form-hint">تُخزَّن مشفّرة ولا يمكن عرضها مرة أخرى. يمكن للمحامي تغييرها لاحقاً من حسابه.</div>
                    </div>

                    <div class="field">
                        <label>رقم الجوال</label>
                        <?php render_phone_field('phone', (string)($modalOld['phone_dial'] ?? '+966'), (string)($modalOld['phone_local'] ?? '')); ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3 class="section-title">التخصص والدولة</h3>

                <div class="form-grid">
                    <div class="field full">
                        <label>التخصصات القانونية</label>
                        <?php render_specialization_checklist('specializations', lawyer_specialization_list($modalOld)); ?>
                        <div class="form-hint">اختر تخصصاً واحداً أو أكثر. اكتب في مربّع البحث لتضييق القائمة.</div>
                    </div>

                    <div class="field">
                        <label for="lawyer_country">الدولة</label>
                        <?php render_lawyer_country_select('country_code', lawyer_country_code($modalOld), true, 'lawyer_country'); ?>
                    </div>

                    <div class="field">
                        <label for="lawyer_city">المدينة</label>
                        <?php render_city_select('city', lawyer_country_code($modalOld), (string)($modalOld['city'] ?? ''), true, 'lawyer_city'); ?>
                        <div class="form-hint">تظهر المدن المتاحة حسب الدولة المختارة.</div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3 class="section-title">الخبرة والأجر</h3>

                <div class="form-grid">
                    <div class="field">
                        <label for="lawyer_experience">سنوات الخبرة</label>
                        <input
                            id="lawyer_experience"
                            type="number"
                            min="0"
                            max="<?= (int)LAWYER_EXPERIENCE_MAX ?>"
                            step="1"
                            inputmode="numeric"
                            name="years_experience"
                            value="<?= htmlspecialchars((string)($modalOld['years_experience'] ?? '0')) ?>"
                            placeholder="مثال: 12"
                        >
                        <div class="form-hint">رقم صحيح غير سالب. تُقبل قيمة مثل «20+» وتُخزَّن 20.</div>
                    </div>

                    <div class="field">
                        <label for="lawyer_rate">السعر بالساعة (<?= currency_symbol() ?>)</label>
                        <input
                            id="lawyer_rate"
                            type="number"
                            step="<?= currency_step() ?>"
                            min="0"
                            inputmode="decimal"
                            dir="ltr"
                            name="hourly_rate"
                            value="<?= htmlspecialchars((string)($modalOld['hourly_rate'] ?? '')) ?>"
                            placeholder="0.00"
                        >
                        <div class="form-hint">اتركه فارغاً إذا كان الأجر حسب الاتفاق.</div>
                    </div>

                    <div class="field">
                        <label for="lawyer_status">حالة الحساب</label>
                        <div class="select-wrap">
                            <select id="lawyer_status" name="status" class="pro-select">
                                <?php foreach (['approved' => 'مفعّل', 'pending' => 'قيد المراجعة', 'suspended' => 'موقوف'] as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= (($modalOld['status'] ?? 'approved') === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="secondary-btn" data-close-lawyer-modal>إلغاء</button>
            <button type="submit" class="submit-btn" id="lawyerSubmitBtn" data-busy-label="<?= $modalIsEdit ? 'جارٍ الحفظ...' : 'جارٍ الإضافة...' ?>"><?= $modalIsEdit ? 'حفظ التعديلات' : 'إضافة المحامي' ?></button>
        </div>
    </form>
</div>

<script src="<?= asset_url('/assets/js/specialization-combo.js') ?>"></script>
<script src="<?= asset_url('/assets/js/lawyer-form.js') ?>"></script>
<script src="<?= asset_url('/assets/js/lawyer-modal.js') ?>"></script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
