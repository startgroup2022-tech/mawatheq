<?php
/**
 * HR, attendance and leave (Executive 08).
 *
 * Covers the operational side of running an office: employee records, who is in on a
 * given day, leave balances and requests, staff schedules, and the workload/absence
 * reporting a manager needs. Payroll is deliberately absent — no approved requirement
 * covers it, and inventing a salary calculation would be a liability.
 *
 * Confidentiality: every function is scoped to the session firm and, where the record
 * carries a branch, to the branches the caller may see (includes/branch_access.php).
 * HR data is never exposed on public pages or through AI retrieval.
 *
 * Privacy: `hr_employee_view()` returns contact/identity columns; the reporting helpers
 * return aggregates only, so an employee's absence history is not splashed into a firm-wide
 * report beyond counts.
 */

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/audit_log.php';

if (!function_exists('hr_leave_types')) {
    function hr_leave_types(): array { return ['annual', 'sick', 'unpaid', 'emergency', 'maternity', 'paternity', 'other']; }
}

if (!function_exists('hr_leave_type_label')) {
    function hr_leave_type_label(string $type, bool $rtl = true): string {
        $ar = ['annual' => 'سنوية', 'sick' => 'مرضية', 'unpaid' => 'بدون راتب', 'emergency' => 'طارئة',
               'maternity' => 'أمومة', 'paternity' => 'أبوة', 'other' => 'أخرى'];
        $en = ['annual' => 'Annual', 'sick' => 'Sick', 'unpaid' => 'Unpaid', 'emergency' => 'Emergency',
               'maternity' => 'Maternity', 'paternity' => 'Paternity', 'other' => 'Other'];
        return ($rtl ? $ar : $en)[$type] ?? $type;
    }
}

if (!function_exists('hr_employment_status_label')) {
    function hr_employment_status_label(string $s, bool $rtl = true): string {
        $ar = ['active' => 'على رأس العمل', 'on_leave' => 'في إجازة', 'terminated' => 'منتهية خدمته'];
        $en = ['active' => 'Active', 'on_leave' => 'On leave', 'terminated' => 'Terminated'];
        return ($rtl ? $ar : $en)[$s] ?? $s;
    }
}

if (!function_exists('hr_attendance_status_label')) {
    function hr_attendance_status_label(string $s, bool $rtl = true): string {
        $ar = ['present' => 'حاضر', 'late' => 'متأخر', 'absent' => 'غائب', 'leave' => 'إجازة', 'holiday' => 'عطلة', 'remote' => 'عن بُعد'];
        $en = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'leave' => 'Leave', 'holiday' => 'Holiday', 'remote' => 'Remote'];
        return ($rtl ? $ar : $en)[$s] ?? $s;
    }
}

if (!function_exists('hr_firm_id')) {
    /** Resolve the firm for an HR operation, or fail closed. */
    function hr_firm_id(PDO $pdo): int {
        return (int)(tenant_current($pdo)['firm_id'] ?? 0);
    }
}

// ---------------------------------------------------------------- employees

if (!function_exists('hr_employee_create')) {
    function hr_employee_create(PDO $pdo, array $d): int {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) throw new RuntimeException('hr_employee_create requires an active firm tenant');
        $name = trim((string)($d['full_name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Employee full_name is required');
        $branchId = isset($d['branch_id']) && $d['branch_id'] !== '' ? (int)$d['branch_id'] : null;
        branch_access_require_branch($pdo, $branchId);
        $stmt = $pdo->prepare('INSERT INTO hr_employees
            (firm_id, lawyer_id, user_id, employee_no, full_name, job_title, department_id, branch_id,
             employment_type, employment_status, hire_date, work_email, work_phone, manager_lawyer_id, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $firmId,
            !empty($d['lawyer_id']) ? (int)$d['lawyer_id'] : null,
            !empty($d['user_id']) ? (int)$d['user_id'] : null,
            ($d['employee_no'] ?? '') !== '' ? (string)$d['employee_no'] : null,
            $name,
            ($d['job_title'] ?? '') !== '' ? (string)$d['job_title'] : null,
            !empty($d['department_id']) ? (int)$d['department_id'] : null,
            $branchId,
            in_array($d['employment_type'] ?? '', ['full_time','part_time','contract','intern'], true) ? $d['employment_type'] : 'full_time',
            in_array($d['employment_status'] ?? '', ['active','on_leave','terminated'], true) ? $d['employment_status'] : 'active',
            ($d['hire_date'] ?? '') !== '' ? (string)$d['hire_date'] : null,
            ($d['work_email'] ?? '') !== '' ? (string)$d['work_email'] : null,
            ($d['work_phone'] ?? '') !== '' ? (string)$d['work_phone'] : null,
            !empty($d['manager_lawyer_id']) ? (int)$d['manager_lawyer_id'] : null,
            ($d['notes'] ?? '') !== '' ? (string)$d['notes'] : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        log_action($pdo, 'إضافة موظف: ' . $name, 'hr_employee', $id);
        return $id;
    }
}

if (!function_exists('hr_employee_update')) {
    function hr_employee_update(PDO $pdo, int $employeeId, array $d): bool {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return false;
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return false;
        $branchId = isset($d['branch_id']) && $d['branch_id'] !== '' ? (int)$d['branch_id'] : null;
        branch_access_require_branch($pdo, $branchId);
        $stmt = $pdo->prepare('UPDATE hr_employees SET full_name=?, job_title=?, department_id=?, branch_id=?,
            employment_type=?, employment_status=?, hire_date=?, termination_date=?, work_email=?, work_phone=?, manager_lawyer_id=?, notes=?
            WHERE id=? AND firm_id=?');
        $stmt->execute([
            trim((string)($d['full_name'] ?? $emp['full_name'])),
            ($d['job_title'] ?? '') !== '' ? (string)$d['job_title'] : null,
            !empty($d['department_id']) ? (int)$d['department_id'] : null,
            $branchId,
            in_array($d['employment_type'] ?? '', ['full_time','part_time','contract','intern'], true) ? $d['employment_type'] : $emp['employment_type'],
            in_array($d['employment_status'] ?? '', ['active','on_leave','terminated'], true) ? $d['employment_status'] : $emp['employment_status'],
            ($d['hire_date'] ?? '') !== '' ? (string)$d['hire_date'] : null,
            ($d['termination_date'] ?? '') !== '' ? (string)$d['termination_date'] : null,
            ($d['work_email'] ?? '') !== '' ? (string)$d['work_email'] : null,
            ($d['work_phone'] ?? '') !== '' ? (string)$d['work_phone'] : null,
            !empty($d['manager_lawyer_id']) ? (int)$d['manager_lawyer_id'] : null,
            ($d['notes'] ?? '') !== '' ? (string)$d['notes'] : null,
            $employeeId, $firmId,
        ]);
        log_action($pdo, 'تحديث بيانات موظف', 'hr_employee', $employeeId);
        return true;
    }
}

if (!function_exists('hr_employee_get')) {
    function hr_employee_get(PDO $pdo, int $employeeId): ?array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return null;
        $stmt = $pdo->prepare('SELECT e.*, d.name_ar AS department_name_ar, d.name_en AS department_name_en,
                                      b.name_ar AS branch_name_ar, b.name_en AS branch_name_en
                               FROM hr_employees e
                               LEFT JOIN firm_departments d ON d.id=e.department_id
                               LEFT JOIN firm_branches b ON b.id=e.branch_id
                               WHERE e.id=? AND e.firm_id=? LIMIT 1');
        $stmt->execute([$employeeId, $firmId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if (!branch_access_can_access_branch($pdo, $row['branch_id'] !== null ? (int)$row['branch_id'] : null)) return null;
        return $row;
    }
}

if (!function_exists('hr_employee_list')) {
    function hr_employee_list(PDO $pdo, array $opts = []): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        $where = ['e.firm_id=?'];
        $params = [$firmId];
        if (empty($opts['include_terminated'])) $where[] = "e.employment_status<>'terminated'";
        if (!empty($opts['department_id'])) { $where[] = 'e.department_id=?'; $params[] = (int)$opts['department_id']; }
        if (!empty($opts['branch_id'])) { $where[] = 'e.branch_id=?'; $params[] = (int)$opts['branch_id']; }
        if (!empty($opts['q'])) { $where[] = 'e.full_name LIKE ?'; $params[] = '%' . $opts['q'] . '%'; }
        [$bsql, $bparams] = branch_access_sql($pdo, 'e.branch_id');
        $sql = 'SELECT e.*, d.name_ar AS department_name_ar, d.name_en AS department_name_en,
                       b.name_ar AS branch_name_ar, b.name_en AS branch_name_en
                FROM hr_employees e
                LEFT JOIN firm_departments d ON d.id=e.department_id
                LEFT JOIN firm_branches b ON b.id=e.branch_id
                WHERE ' . implode(' AND ', $where) . $bsql . ' ORDER BY e.full_name LIMIT ' . (int)($opts['limit'] ?? 300);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));
        return $stmt->fetchAll();
    }
}

// ---------------------------------------------------------------- attendance

if (!function_exists('hr_attendance_check_in')) {
    function hr_attendance_check_in(PDO $pdo, int $employeeId, ?string $at = null, string $source = 'self'): bool {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return false;
        $at = $at ?: date('Y-m-d H:i:s');
        $day = substr($at, 0, 10);
        // Lateness is judged from the recorded time, not from "now" — a manager back-filling
        // yesterday's attendance must get yesterday's status.
        $hour = (int)substr($at, 11, 2);
        $stmt = $pdo->prepare('INSERT INTO hr_attendance (firm_id, employee_id, work_date, check_in, status, source, branch_id, recorded_by_lawyer_id)
                               VALUES (?,?,?,?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), status=VALUES(status), source=VALUES(source)');
        $stmt->execute([(int)$emp['firm_id'], $employeeId, $day, $at,
            $hour >= 9 ? 'late' : 'present', $source,
            $emp['branch_id'] !== null ? (int)$emp['branch_id'] : null,
            (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null]);
        log_action($pdo, 'تسجيل حضور', 'hr_attendance', $employeeId);
        return true;
    }
}

if (!function_exists('hr_attendance_check_out')) {
    function hr_attendance_check_out(PDO $pdo, int $employeeId, ?string $at = null): bool {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return false;
        $at = $at ?: date('Y-m-d H:i:s');
        $day = substr($at, 0, 10);
        $stmt = $pdo->prepare('UPDATE hr_attendance SET check_out=?, worked_minutes=TIMESTAMPDIFF(MINUTE, check_in, ?)
                               WHERE firm_id=? AND employee_id=? AND work_date=? AND check_in IS NOT NULL');
        $stmt->execute([$at, $at, (int)$emp['firm_id'], $employeeId, $day]);
        if ($stmt->rowCount() < 1) return false;
        log_action($pdo, 'تسجيل انصراف', 'hr_attendance', $employeeId);
        return true;
    }
}

if (!function_exists('hr_attendance_record')) {
    /** Manual entry/override by a manager. */
    function hr_attendance_record(PDO $pdo, int $employeeId, string $date, string $status, ?string $in = null, ?string $out = null, ?string $note = null): bool {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return false;
        if (!in_array($status, ['present','late','absent','leave','holiday','remote'], true)) return false;
        $stmt = $pdo->prepare('INSERT INTO hr_attendance (firm_id, employee_id, work_date, check_in, check_out, status, source, note, branch_id, recorded_by_lawyer_id)
                               VALUES (?,?,?,?,?,?,\'manual\',?,?,?)
                               ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out), status=VALUES(status), note=VALUES(note)');
        $stmt->execute([(int)$emp['firm_id'], $employeeId, $date, $in, $out, $status, $note,
            $emp['branch_id'] !== null ? (int)$emp['branch_id'] : null,
            (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null]);
        log_action($pdo, 'تسجيل حضور يدوي: ' . $status, 'hr_attendance', $employeeId);
        return true;
    }
}

if (!function_exists('hr_attendance_list')) {
    function hr_attendance_list(PDO $pdo, array $opts = []): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        $where = ['a.firm_id=?'];
        $params = [$firmId];
        if (!empty($opts['from'])) { $where[] = 'a.work_date>=?'; $params[] = $opts['from']; }
        if (!empty($opts['to'])) { $where[] = 'a.work_date<=?'; $params[] = $opts['to']; }
        if (!empty($opts['employee_id'])) { $where[] = 'a.employee_id=?'; $params[] = (int)$opts['employee_id']; }
        if (!empty($opts['status'])) { $where[] = 'a.status=?'; $params[] = $opts['status']; }
        [$bsql, $bparams] = branch_access_sql($pdo, 'a.branch_id');
        $sql = 'SELECT a.*, e.full_name FROM hr_attendance a JOIN hr_employees e ON e.id=a.employee_id
                WHERE ' . implode(' AND ', $where) . $bsql . ' ORDER BY a.work_date DESC, e.full_name LIMIT ' . (int)($opts['limit'] ?? 500);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));
        return $stmt->fetchAll();
    }
}

if (!function_exists('hr_attendance_today_summary')) {
    /** Counts by status for a date — aggregate only, no per-person detail. */
    function hr_attendance_today_summary(PDO $pdo, ?string $date = null): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        $date = $date ?: date('Y-m-d');
        [$bsql, $bparams] = branch_access_sql($pdo, 'a.branch_id');
        $sql = "SELECT a.status, COUNT(*) c FROM hr_attendance a WHERE a.firm_id=? AND a.work_date=?" . $bsql . ' GROUP BY a.status';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$firmId, $date], $bparams));
        $out = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'remote' => 0];
        foreach ($stmt->fetchAll() as $r) { $out[$r['status']] = (int)$r['c']; }
        return $out;
    }
}

// ---------------------------------------------------------------- leave

if (!function_exists('hr_leave_balance_get')) {
    function hr_leave_balance_get(PDO $pdo, int $employeeId, string $type, ?int $year = null): array {
        $firmId = hr_firm_id($pdo);
        $year = $year ?: (int)date('Y');
        $default = ['entitled' => 0.0, 'used' => 0.0, 'remaining' => 0.0];
        if (!$firmId) return $default;
        $stmt = $pdo->prepare('SELECT entitled, used FROM hr_leave_balances WHERE firm_id=? AND employee_id=? AND leave_type=? AND balance_year=?');
        $stmt->execute([$firmId, $employeeId, $type, $year]);
        $row = $stmt->fetch();
        if (!$row) return $default;
        $entitled = (float)$row['entitled']; $used = (float)$row['used'];
        return ['entitled' => $entitled, 'used' => $used, 'remaining' => $entitled - $used];
    }
}

if (!function_exists('hr_leave_balance_set')) {
    function hr_leave_balance_set(PDO $pdo, int $employeeId, string $type, float $entitled, ?int $year = null): bool {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return false;
        $year = $year ?: (int)date('Y');
        if (!in_array($type, hr_leave_types(), true)) return false;
        $stmt = $pdo->prepare('INSERT INTO hr_leave_balances (firm_id, employee_id, leave_type, balance_year, entitled)
                               VALUES (?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE entitled=VALUES(entitled)');
        $stmt->execute([(int)$emp['firm_id'], $employeeId, $type, $year, $entitled]);
        log_action($pdo, 'تعديل رصيد إجازة', 'hr_leave_balance', $employeeId);
        return true;
    }
}

if (!function_exists('hr_leave_days')) {
    /** Inclusive day count between two dates (calendar days; a working-day model would
     *  need an approved holiday calendar, which is out of scope here). */
    function hr_leave_days(string $start, string $end): float {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) return 0.0;
        return (float)((int)(($b - $a) / 86400) + 1);
    }
}

if (!function_exists('hr_leave_request_create')) {
    /**
     * Submit a leave request. Balance is checked when a balance row exists; an employee
     * with no configured balance is not silently blocked (the firm may not have set
     * entitlements yet) but the overdraw is surfaced to the approver.
     */
    function hr_leave_request_create(PDO $pdo, int $employeeId, string $type, string $start, string $end, ?string $reason = null): array {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return ['ok' => false, 'error' => 'employee_not_found'];
        if (!in_array($type, hr_leave_types(), true)) return ['ok' => false, 'error' => 'bad_leave_type'];
        $days = hr_leave_days($start, $end);
        if ($days <= 0) return ['ok' => false, 'error' => 'bad_dates'];
        $year = (int)substr($start, 0, 4);
        $bal = hr_leave_balance_get($pdo, $employeeId, $type, $year);
        $overdraw = ($bal['entitled'] > 0 && ($bal['used'] + $days) > $bal['entitled']);

        $stmt = $pdo->prepare('INSERT INTO hr_leave_requests
            (firm_id, employee_id, leave_type, start_date, end_date, days, reason, status, branch_id, created_by_lawyer_id)
            VALUES (?,?,?,?,?,?,?,\'pending\',?,?)');
        $stmt->execute([(int)$emp['firm_id'], $employeeId, $type, $start, $end, $days, $reason,
            $emp['branch_id'] !== null ? (int)$emp['branch_id'] : null,
            (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null]);
        $id = (int)$pdo->lastInsertId();
        log_action($pdo, 'طلب إجازة: ' . $type, 'hr_leave_request', $id);
        return ['ok' => true, 'id' => $id, 'days' => $days, 'overdraw' => $overdraw, 'remaining' => $bal['remaining']];
    }
}

if (!function_exists('hr_leave_request_decide')) {
    function hr_leave_request_decide(PDO $pdo, int $requestId, string $decision, ?string $note = null): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return ['ok' => false, 'error' => 'no_tenant'];
        if (!in_array($decision, ['approved', 'rejected', 'cancelled'], true)) return ['ok' => false, 'error' => 'bad_decision'];
        $stmt = $pdo->prepare('SELECT * FROM hr_leave_requests WHERE id=? AND firm_id=? LIMIT 1');
        $stmt->execute([$requestId, $firmId]);
        $req = $stmt->fetch();
        if (!$req) return ['ok' => false, 'error' => 'not_found'];
        if (!branch_access_can_access_branch($pdo, $req['branch_id'] !== null ? (int)$req['branch_id'] : null)) return ['ok' => false, 'error' => 'not_found'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'already_decided'];

        $actorId = (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null;
        $upd = $pdo->prepare('UPDATE hr_leave_requests SET status=?, reviewed_by_lawyer_id=?, reviewed_at=NOW(), review_note=? WHERE id=? AND firm_id=? AND status=\'pending\'');
        $upd->execute([$decision, $actorId, $note, $requestId, $firmId]);
        if ($upd->rowCount() < 1) return ['ok' => false, 'error' => 'race'];

        if ($decision === 'approved') {
            $year = (int)substr((string)$req['start_date'], 0, 4);
            $pdo->prepare('INSERT INTO hr_leave_balances (firm_id, employee_id, leave_type, balance_year, entitled, used)
                           VALUES (?,?,?,?,0,?)
                           ON DUPLICATE KEY UPDATE used = used + VALUES(used)')
                ->execute([$firmId, (int)$req['employee_id'], $req['leave_type'], $year, (float)$req['days']]);
            // Mark the covered days as leave in the attendance register.
            $d = strtotime((string)$req['start_date']); $end = strtotime((string)$req['end_date']);
            $ins = $pdo->prepare('INSERT INTO hr_attendance (firm_id, employee_id, work_date, status, source, branch_id)
                                  VALUES (?,?,?,\'leave\',\'system\',?)
                                  ON DUPLICATE KEY UPDATE status=\'leave\'');
            for (; $d !== false && $d <= $end; $d += 86400) {
                $ins->execute([$firmId, (int)$req['employee_id'], date('Y-m-d', $d), $req['branch_id']]);
            }
        }
        log_action($pdo, 'قرار طلب إجازة: ' . $decision, 'hr_leave_request', $requestId);
        return ['ok' => true, 'status' => $decision];
    }
}

if (!function_exists('hr_leave_request_list')) {
    function hr_leave_request_list(PDO $pdo, array $opts = []): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        $where = ['r.firm_id=?'];
        $params = [$firmId];
        if (!empty($opts['status'])) { $where[] = 'r.status=?'; $params[] = $opts['status']; }
        if (!empty($opts['employee_id'])) { $where[] = 'r.employee_id=?'; $params[] = (int)$opts['employee_id']; }
        [$bsql, $bparams] = branch_access_sql($pdo, 'r.branch_id');
        $sql = 'SELECT r.*, e.full_name FROM hr_leave_requests r JOIN hr_employees e ON e.id=r.employee_id
                WHERE ' . implode(' AND ', $where) . $bsql . ' ORDER BY r.created_at DESC LIMIT ' . (int)($opts['limit'] ?? 300);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));
        return $stmt->fetchAll();
    }
}

// ---------------------------------------------------------------- schedules

if (!function_exists('hr_schedule_create')) {
    function hr_schedule_create(PDO $pdo, int $employeeId, array $d): int {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) throw new RuntimeException('Employee not found in tenant');
        $branchId = isset($d['branch_id']) && $d['branch_id'] !== '' ? (int)$d['branch_id'] : ($emp['branch_id'] !== null ? (int)$emp['branch_id'] : null);
        branch_access_require_branch($pdo, $branchId);
        $stmt = $pdo->prepare('INSERT INTO hr_schedules (firm_id, employee_id, branch_id, schedule_type, title, start_at, end_at, location, created_by_lawyer_id)
                               VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)$emp['firm_id'], $employeeId, $branchId,
            in_array($d['schedule_type'] ?? '', ['shift','court','duty','remote','training','other'], true) ? $d['schedule_type'] : 'shift',
            trim((string)($d['title'] ?? '')), $d['start_at'] ?? date('Y-m-d H:i:s'),
            ($d['end_at'] ?? '') !== '' ? $d['end_at'] : null, ($d['location'] ?? '') !== '' ? $d['location'] : null,
            (int)(tenant_current($pdo)['lawyer_id'] ?? 0) ?: null]);
        $id = (int)$pdo->lastInsertId();
        log_action($pdo, 'إضافة جدول عمل', 'hr_schedule', $id);
        return $id;
    }
}

if (!function_exists('hr_schedule_list')) {
    function hr_schedule_list(PDO $pdo, array $opts = []): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        $where = ['s.firm_id=?'];
        $params = [$firmId];
        if (!empty($opts['from'])) { $where[] = 's.start_at>=?'; $params[] = $opts['from']; }
        if (!empty($opts['to'])) { $where[] = 's.start_at<=?'; $params[] = $opts['to']; }
        if (!empty($opts['employee_id'])) { $where[] = 's.employee_id=?'; $params[] = (int)$opts['employee_id']; }
        [$bsql, $bparams] = branch_access_sql($pdo, 's.branch_id');
        $sql = 'SELECT s.*, e.full_name FROM hr_schedules s JOIN hr_employees e ON e.id=s.employee_id
                WHERE ' . implode(' AND ', $where) . $bsql . ' ORDER BY s.start_at LIMIT ' . (int)($opts['limit'] ?? 300);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));
        return $stmt->fetchAll();
    }
}

// ---------------------------------------------------------------- reports

if (!function_exists('hr_absence_report')) {
    /** Per-employee absence/late counts over a window. Aggregates only. */
    function hr_absence_report(PDO $pdo, string $from, string $to): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        [$bsql, $bparams] = branch_access_sql($pdo, 'a.branch_id');
        $sql = "SELECT e.id, e.full_name, e.branch_id,
                       COALESCE(SUM(a.status='absent'),0) absent_days,
                       COALESCE(SUM(a.status='late'),0) late_days,
                       COALESCE(SUM(a.status='leave'),0) leave_days,
                       COALESCE(SUM(a.worked_minutes),0) worked_minutes
                FROM hr_employees e
                LEFT JOIN hr_attendance a ON a.employee_id=e.id AND a.work_date BETWEEN ? AND ?
                WHERE e.firm_id=? AND e.employment_status<>'terminated'" . $bsql . '
                GROUP BY e.id, e.full_name, e.branch_id ORDER BY absent_days DESC, e.full_name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to, $firmId], $bparams));
        return $stmt->fetchAll();
    }
}

if (!function_exists('hr_lawyer_workload_report')) {
    /**
     * Open-case and logged-minutes workload per lawyer in the firm. Uses the existing
     * cases/case_time_entries tables rather than a duplicate workload store.
     */
    function hr_lawyer_workload_report(PDO $pdo, string $from, string $to): array {
        $firmId = hr_firm_id($pdo);
        if (!$firmId) return [];
        $sql = "SELECT l.id, l.full_name,
                       COALESCE(cd.open_cases,0) open_cases,
                       COALESCE(cd.total_cases,0) total_cases,
                       COALESCE(te.minutes,0) logged_minutes
                FROM law_firm_members m
                JOIN lawyers l ON l.id=m.lawyer_id
                LEFT JOIN (
                    SELECT lawyer_id, SUM(status='open') open_cases, COUNT(*) total_cases
                    FROM cases WHERE firm_id=? GROUP BY lawyer_id
                ) cd ON cd.lawyer_id=l.id
                LEFT JOIN (
                    SELECT c.lawyer_id, SUM(t.minutes) minutes
                    FROM case_time_entries t JOIN cases c ON c.id=t.case_id
                    WHERE c.firm_id=? AND t.work_date BETWEEN ? AND ?
                    GROUP BY c.lawyer_id
                ) te ON te.lawyer_id=l.id
                WHERE m.firm_id=? AND m.status='active'
                ORDER BY open_cases DESC, l.full_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$firmId, $firmId, $from, $to, $firmId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('hr_employee_indicators')) {
    /**
     * Performance indicators per employee, computed from operational records only
     * (attendance punctuality + assigned task completion). No subjective scoring.
     */
    function hr_employee_indicators(PDO $pdo, int $employeeId, string $from, string $to): array {
        $emp = hr_employee_get($pdo, $employeeId);
        if (!$emp) return [];
        $firmId = (int)$emp['firm_id'];
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(status='present'),0) present, COALESCE(SUM(status='late'),0) late,
                                      COALESCE(SUM(status='absent'),0) absent, COALESCE(AVG(NULLIF(worked_minutes,0)),0) avg_minutes
                               FROM hr_attendance WHERE firm_id=? AND employee_id=? AND work_date BETWEEN ? AND ?");
        $stmt->execute([$firmId, $employeeId, $from, $to]);
        $att = $stmt->fetch() ?: ['present' => 0, 'late' => 0, 'absent' => 0, 'avg_minutes' => 0];
        $present = (int)$att['present']; $late = (int)$att['late']; $absent = (int)$att['absent'];
        $total = $present + $late + $absent;
        return [
            'present' => $present, 'late' => $late, 'absent' => $absent,
            'attendance_rate' => $total > 0 ? round((($present + $late) / $total) * 100, 1) : null,
            'punctuality_rate' => ($present + $late) > 0 ? round(($present / ($present + $late)) * 100, 1) : null,
            'avg_worked_minutes' => (int)round((float)$att['avg_minutes']),
            'leave_days' => (float)(hr_leave_request_list($pdo, ['employee_id' => $employeeId, 'status' => 'approved', 'limit' => 1000]) ? array_sum(array_map(fn($r) => (float)$r['days'], hr_leave_request_list($pdo, ['employee_id' => $employeeId, 'status' => 'approved', 'limit' => 1000]))) : 0),
        ];
    }
}
