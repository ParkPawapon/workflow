<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['approval_action'])) {
    return;
}

require_once __DIR__ . '/../../../config/connection.php';
require_once __DIR__ . '/../teacher/teacher-profile.php';
require_once __DIR__ . '/../../../app/modules/system/system.php';
require_once __DIR__ . '/../../../app/modules/audit/logger.php';
require_once __DIR__ . '/../../../app/rbac/roles.php';
require_once __DIR__ . '/vehicle-reservation-utils.php';

// NOTE:
// This file is `require_once`'d inside a controller function. If `config/connection.php`
// (and/or `teacher-profile.php`) was already loaded earlier in the request, `require_once`
// will not re-run here and local `$connection`/`$teacher` may be undefined. Pull from
// $GLOBALS as a safe fallback to avoid fatal errors.
$connection = $connection ?? ($GLOBALS['connection'] ?? null);
$teacher = $teacher ?? ($GLOBALS['teacher'] ?? []);

if (!($connection instanceof mysqli)) {
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$vehicle_columns = vehicle_reservation_ensure_other_passenger_columns($connection);
$vehicle_master_columns = vehicle_reservation_get_table_columns($connection, 'dh_vehicles');
$vehicle_supports_soft_delete = vehicle_reservation_has_column($vehicle_master_columns, 'deletedAt');
$vehicle_supports_status = vehicle_reservation_has_column($vehicle_master_columns, 'vehicleStatus');

$redirect_url = 'vehicle-reservation-approval.php';
$return_url = trim((string) ($_POST['return_url'] ?? ''));

if ($return_url !== '' && strpos($return_url, $redirect_url) === 0) {
    $redirect_url = $return_url;
}

$set_vehicle_approval_alert = static function (string $type, string $title, string $message = '') use ($redirect_url): void {
    $_SESSION['vehicle_approval_alert'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'button_label' => 'ยืนยัน',
        'redirect' => '',
        'delay_ms' => 0,
    ];
    header('Location: ' . $redirect_url, true, 303);
    exit();
};

$raw_action = trim((string) ($_POST['approval_action'] ?? ''));
$raw_booking_id = (int) ($_POST['vehicle_booking_id'] ?? 0);

if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])
) {
    if (function_exists('audit_log')) {
        audit_log('security', 'CSRF_FAIL', 'DENY', 'dh_vehicle_bookings', $raw_booking_id > 0 ? $raw_booking_id : null, 'vehicle_reservation_approval_actions', [
            'action' => $raw_action,
        ]);
    }
    $set_vehicle_approval_alert('danger', 'ไม่สามารถยืนยันความปลอดภัย', 'กรุณาลองใหม่อีกครั้ง');
}

$action = $raw_action;

if (!in_array($action, ['approve', 'reject'], true)) {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'APPROVAL', 'FAIL', 'dh_vehicle_bookings', $raw_booking_id > 0 ? $raw_booking_id : null, 'invalid_action', [
            'action' => $action,
        ]);
    }
    $set_vehicle_approval_alert('danger', 'คำสั่งไม่ถูกต้อง', 'กรุณาลองใหม่อีกครั้ง');
}

$booking_id = $raw_booking_id;

if ($booking_id <= 0) {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'APPROVAL', 'FAIL', 'dh_vehicle_bookings', null, 'invalid_booking_id');
    }
    $set_vehicle_approval_alert('danger', 'ข้อมูลไม่ถูกต้อง', 'ไม่พบรายการจองที่ต้องการ');
}

$actor_pid = trim((string) ($_SESSION['pID'] ?? ''));

if ($actor_pid === '') {
    if (function_exists('audit_log')) {
        audit_log('security', 'AUTH_REQUIRED', 'DENY', null, null, 'vehicle_reservation_approval_actions');
    }
    header('Location: index.php', true, 302);
    exit();
}

$role_id = (int) ($teacher['roleID'] ?? 0);
$position_id = (int) ($teacher['positionID'] ?? 0);
$deputy_position_ids = system_position_deputy_ids($connection);
$acting_director_pid = system_get_acting_director_pid();
$is_deputy = in_array($position_id, $deputy_position_ids, true);
$is_acting_director = $acting_director_pid !== null && $acting_director_pid !== '' && $acting_director_pid === $actor_pid;
$is_final_approver = $is_deputy || $is_acting_director;
// roleID mapping (legacy): 1=ADMIN, 3=VEHICLE
$is_admin = $role_id === 1
    || (function_exists('rbac_user_has_role') && $actor_pid !== '' && rbac_user_has_role($connection, $actor_pid, ROLE_ADMIN));
// Admin is view-only for vehicle approval flows.
$is_vehicle_officer = !$is_admin
    && (
        $role_id === 3
        || (function_exists('rbac_user_has_role') && $actor_pid !== '' && rbac_user_has_role($connection, $actor_pid, ROLE_VEHICLE))
    );

$resolve_final_approver = static function (string $raw_pid, string $audit_action) use (
    $connection,
    $booking_id,
    $deputy_position_ids,
    $set_vehicle_approval_alert
): array {
    $final_approver_pid = trim($raw_pid);

    if ($final_approver_pid !== '' && !ctype_digit($final_approver_pid)) {
        $final_approver_pid = preg_replace('/\D+/', '', $final_approver_pid) ?? '';
    }

    if ($final_approver_pid === '') {
        if (function_exists('audit_log')) {
            audit_log('vehicle', $audit_action, 'FAIL', 'dh_vehicle_bookings', $booking_id, 'final_approver_required');
        }
        $set_vehicle_approval_alert('warning', 'กรุณาเลือกรองผู้อำนวยการ', 'โปรดเลือกรองผู้อำนวยการก่อนส่งต่อ');
    }

    if ($deputy_position_ids === []) {
        if (function_exists('audit_log')) {
            audit_log('vehicle', $audit_action, 'FAIL', 'dh_vehicle_bookings', $booking_id, 'deputy_position_missing');
        }
        $set_vehicle_approval_alert('danger', 'ไม่พบข้อมูลรองผู้อำนวยการ', 'กรุณาตรวจสอบข้อมูลตำแหน่งรองผู้อำนวยการในระบบ');
    }

    try {
        $placeholders = implode(', ', array_fill(0, count($deputy_position_ids), '?'));
        $sql = 'SELECT t.pID, t.fName
            FROM teacher AS t
            WHERE t.pID = ?
                AND t.status = 1
                AND t.positionID IN (' . $placeholders . ')
            LIMIT 1';
        $stmt = mysqli_prepare($connection, $sql);
        $types = 's' . str_repeat('i', count($deputy_position_ids));
        $params = [$final_approver_pid];

        foreach ($deputy_position_ids as $position_id_value) {
            $params[] = (int) $position_id_value;
        }

        $bind_params = [$stmt, $types];

        foreach ($params as $i => $v) {
            $bind_params[] = &$params[$i];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind_params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            if (function_exists('audit_log')) {
                audit_log('vehicle', $audit_action, 'FAIL', 'dh_vehicle_bookings', $booking_id, 'final_approver_not_found', [
                    'finalApproverPID' => $final_approver_pid,
                ]);
            }
            $set_vehicle_approval_alert('warning', 'ไม่พบรองผู้อำนวยการที่เลือก', 'กรุณาเลือกรองผู้อำนวยการที่ถูกต้อง');
        }

        return [
            'pID' => $final_approver_pid,
            'name' => preg_replace('/\s+/u', ' ', trim((string) ($row['fName'] ?? ''))) ?? '',
        ];
    } catch (mysqli_sql_exception $exception) {
        error_log('Database Exception: ' . $exception->getMessage());

        if (function_exists('audit_log')) {
            audit_log('vehicle', $audit_action, 'FAIL', 'dh_vehicle_bookings', $booking_id, 'final_approver_lookup_failed', [
                'finalApproverPID' => $final_approver_pid,
            ]);
        }
        $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถดึงข้อมูลรองผู้อำนวยการได้ในขณะนี้');
    }
};

$assigned_note_present = array_key_exists('assignedNote', $_POST);
$assigned_note = trim((string) ($_POST['assignedNote'] ?? ''));
// Final decision note (deputy/acting director only on ASSIGNED -> APPROVED/REJECTED).
$approval_note = trim((string) ($_POST['approvalNote'] ?? ''));

$current_status = 'PENDING';
$current_final_approver_pid = '';

try {
    $check_fields = ['bookingID', 'status'];

    if (vehicle_reservation_has_column($vehicle_columns, 'finalApproverPID')) {
        $check_fields[] = 'finalApproverPID';
    }

    $check_sql = 'SELECT ' . implode(', ', $check_fields) . ' FROM dh_vehicle_bookings WHERE bookingID = ? AND deletedAt IS NULL LIMIT 1';
    $check_stmt = mysqli_prepare($connection, $check_sql);

    mysqli_stmt_bind_param($check_stmt, 'i', $booking_id);
    mysqli_stmt_execute($check_stmt);

    $check_result = mysqli_stmt_get_result($check_stmt);
    $check_row = $check_result ? mysqli_fetch_assoc($check_result) : null;
    mysqli_stmt_close($check_stmt);

    if (!$check_row) {
        if (function_exists('audit_log')) {
            audit_log('vehicle', 'APPROVAL', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'booking_not_found');
        }
        $set_vehicle_approval_alert('warning', 'ไม่พบรายการ', 'รายการจองนี้อาจถูกลบไปแล้ว');
    }

    $current_status = strtoupper(trim((string) ($check_row['status'] ?? 'PENDING')));
    $current_final_approver_pid = trim((string) ($check_row['finalApproverPID'] ?? ''));
} catch (mysqli_sql_exception $exception) {
    error_log('Database Exception: ' . $exception->getMessage());

    if (function_exists('audit_log')) {
        audit_log('vehicle', 'APPROVAL', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'status_check_failed');
    }
    $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถตรวจสอบรายการจองได้ในขณะนี้');
}

if (
    $is_final_approver
    && $current_status === 'ASSIGNED'
    && $current_final_approver_pid !== ''
    && $current_final_approver_pid !== $actor_pid
) {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'FINAL_DECISION', 'DENY', 'dh_vehicle_bookings', $booking_id, 'wrong_final_approver', [
            'finalApproverPID' => $current_final_approver_pid,
        ]);
    }
    $set_vehicle_approval_alert('danger', 'ไม่มีสิทธิ์ดำเนินการ', 'รายการนี้ถูกส่งให้รองผู้อำนวยการท่านอื่นพิจารณา');
}

$approved_at = date('Y-m-d H:i:s');

// When final approvers record (or edit) the final decision, require a note (matches UI `required`).
if ($is_final_approver && in_array($current_status, ['ASSIGNED', 'APPROVED', 'REJECTED'], true) && $approval_note === '') {
    if (function_exists('audit_log')) {
        $audit_action = 'FINAL_DECISION';

        if ($action === 'approve') {
            $audit_action = $current_status === 'ASSIGNED' ? 'FINAL_APPROVE' : 'FINAL_APPROVE_OVERRIDE';
        } elseif ($action === 'reject') {
            $audit_action = $current_status === 'ASSIGNED' ? 'REJECT' : 'FINAL_REJECT_OVERRIDE';
        }
        audit_log('vehicle', $audit_action, 'FAIL', 'dh_vehicle_bookings', $booking_id, 'approval_note_required', [
            'status' => $current_status,
        ]);
    }
    $set_vehicle_approval_alert('warning', 'กรุณาระบุความเห็น', 'โปรดระบุความเห็นก่อนบันทึกผลการพิจารณา');
}

if ($action === 'approve') {
    if ($current_status === 'PENDING') {
        if (!$is_vehicle_officer) {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'ASSIGN', 'DENY', 'dh_vehicle_bookings', $booking_id, 'not_vehicle_officer', [
                    'roleID' => $role_id,
                ]);
            }
            $set_vehicle_approval_alert('danger', 'ไม่มีสิทธิ์ดำเนินการ', 'เฉพาะเจ้าหน้าที่งานยานพาหนะเท่านั้น');
        }

        $assign_vehicle_id = (int) ($_POST['assign_vehicle_id'] ?? 0);

        if ($assign_vehicle_id <= 0) {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'vehicle_required');
            }
            $set_vehicle_approval_alert('warning', 'กรุณาเลือกยานพาหนะ', 'โปรดเลือกยานพาหนะก่อนส่งต่อรองผู้อำนวยการ');
        }

        $vehicle_exists = false;

        try {
            $vehicle_check_sql = 'SELECT vehicleID FROM dh_vehicles WHERE vehicleID = ?';

            if ($vehicle_supports_soft_delete) {
                $vehicle_check_sql .= ' AND deletedAt IS NULL';
            }

            if ($vehicle_supports_status) {
                $vehicle_check_sql .= " AND vehicleStatus = 'พร้อมใช้งาน'";
            }
            $vehicle_check_sql .= ' LIMIT 1';

            $vehicle_stmt = mysqli_prepare($connection, $vehicle_check_sql);
            mysqli_stmt_bind_param($vehicle_stmt, 'i', $assign_vehicle_id);
            mysqli_stmt_execute($vehicle_stmt);
            $vehicle_result = mysqli_stmt_get_result($vehicle_stmt);
            $vehicle_exists = $vehicle_result && mysqli_fetch_assoc($vehicle_result);
            mysqli_stmt_close($vehicle_stmt);
        } catch (mysqli_sql_exception $exception) {
            error_log('Database Exception: ' . $exception->getMessage());
        }

        if (!$vehicle_exists) {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'vehicle_not_available', [
                    'vehicleID' => $assign_vehicle_id,
                ]);
            }
            $set_vehicle_approval_alert('warning', 'ยานพาหนะไม่พร้อมใช้งาน', 'กรุณาเลือกยานพาหนะที่พร้อมใช้งาน');
        }

        $assign_driver_pid = trim((string) ($_POST['assign_driver_pid'] ?? ''));

        if ($assign_driver_pid !== '' && !ctype_digit($assign_driver_pid)) {
            $assign_driver_pid = preg_replace('/\D+/', '', $assign_driver_pid);
        }

        if ($assign_driver_pid === '') {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_required');
            }
            $set_vehicle_approval_alert('warning', 'กรุณาเลือกผู้ขับรถ', 'โปรดเลือกผู้ขับรถก่อนส่งต่อรองผู้อำนวยการ');
        }

        $assign_driver_name = '';
        $assign_driver_tel = '';

        try {
            $driver_sql = 'SELECT pID, fName, telephone FROM teacher WHERE pID = ? AND status = 1 LIMIT 1';
            $driver_stmt = mysqli_prepare($connection, $driver_sql);
            mysqli_stmt_bind_param($driver_stmt, 's', $assign_driver_pid);
            mysqli_stmt_execute($driver_stmt);
            $driver_result = mysqli_stmt_get_result($driver_stmt);
            $driver_row = $driver_result ? mysqli_fetch_assoc($driver_result) : null;
            mysqli_stmt_close($driver_stmt);

            if (!$driver_row) {
                if (function_exists('audit_log')) {
                    audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_not_found', [
                        'driverPID' => $assign_driver_pid,
                    ]);
                }
                $set_vehicle_approval_alert('warning', 'ไม่พบผู้ขับรถ', 'กรุณาเลือกผู้ขับรถที่ถูกต้อง');
            }

            $assign_driver_name = preg_replace('/\s+/u', ' ', trim((string) ($driver_row['fName'] ?? ''))) ?? '';
            $assign_driver_tel = preg_replace('/\s+/u', ' ', trim((string) ($driver_row['telephone'] ?? ''))) ?? '';
        } catch (mysqli_sql_exception $exception) {
            error_log('Database Exception: ' . $exception->getMessage());

            if (function_exists('audit_log')) {
                audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_lookup_failed', [
                    'driverPID' => $assign_driver_pid,
                ]);
            }
            $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถดึงข้อมูลผู้ขับรถได้ในขณะนี้');
        }

        if ($assign_driver_name === '') {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_name_missing', [
                    'driverPID' => $assign_driver_pid,
                ]);
            }
            $set_vehicle_approval_alert('warning', 'ข้อมูลไม่ครบถ้วน', 'ไม่พบชื่อผู้ขับรถ');
        }

        $driver_pid_param = $assign_driver_pid !== '' ? $assign_driver_pid : null;
        // Avoid duplicating driver phone number in booking records when it can be derived from teacher.telephone.
        // We only persist driverTel for manual drivers (no driverPID).
        $driver_tel_param = null;
        // No manual driver entry in approval flow.
        $assign_final_approver = ['pID' => null, 'name' => ''];

        if (vehicle_reservation_has_column($vehicle_columns, 'finalApproverPID')) {
            $assign_final_approver = $resolve_final_approver(
                (string) ($_POST['assign_final_approver_pid'] ?? ''),
                'ASSIGN'
            );
        }

        try {
            $status_value = 'ASSIGNED';
            $status_reason = null;
            $set_fields = [
                'status = ?',
                'statusReason = ?',
                'vehicleID = ?',
                'driverPID = ?',
                'driverName = ?',
                'approvedByPID = ?',
                'approvedAt = ?',
                'updatedAt = CURRENT_TIMESTAMP',
            ];
            $bind_values = [
                $status_value,
                $status_reason,
                $assign_vehicle_id,
                $driver_pid_param,
                $assign_driver_name,
                $actor_pid,
                $approved_at,
            ];
            $types = 'ssissss';

            if (vehicle_reservation_has_column($vehicle_columns, 'driverTel')) {
                $set_fields[] = 'driverTel = ?';
                $bind_values[] = $driver_tel_param;
                $types .= 's';
            }

            if (vehicle_reservation_has_column($vehicle_columns, 'assignedByPID')) {
                $set_fields[] = 'assignedByPID = ?';
                $bind_values[] = $actor_pid;
                $types .= 's';
            }

            if (vehicle_reservation_has_column($vehicle_columns, 'assignedAt')) {
                $set_fields[] = 'assignedAt = ?';
                $bind_values[] = $approved_at;
                $types .= 's';
            }

            if ($assigned_note_present && vehicle_reservation_has_column($vehicle_columns, 'assignedNote')) {
                $set_fields[] = 'assignedNote = ?';
                $bind_values[] = $assigned_note !== '' ? $assigned_note : null;
                $types .= 's';
            }

            if (vehicle_reservation_has_column($vehicle_columns, 'finalApproverPID')) {
                $set_fields[] = 'finalApproverPID = ?';
                $bind_values[] = $assign_final_approver['pID'];
                $types .= 's';
            }

            $update_sql = 'UPDATE dh_vehicle_bookings SET ' . implode(', ', $set_fields)
                . ' WHERE bookingID = ? AND deletedAt IS NULL';
            $bind_values[] = $booking_id;
            $types .= 'i';

            $update_stmt = mysqli_prepare($connection, $update_sql);

            $bind_params = [];
            $bind_params[] = $update_stmt;
            $bind_params[] = $types;

            foreach ($bind_values as $i => $v) {
                $bind_params[] = &$bind_values[$i];
            }
            call_user_func_array('mysqli_stmt_bind_param', $bind_params);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);

            audit_log('vehicle', 'ASSIGN', 'SUCCESS', 'dh_vehicle_bookings', $booking_id, null, [
                'vehicleID' => $assign_vehicle_id,
                'driverPID' => $driver_pid_param,
                'driverName' => $assign_driver_name,
                'finalApproverPID' => $assign_final_approver['pID'],
            ]);

            $set_vehicle_approval_alert('success', 'ส่งต่อรองผู้อำนวยการแล้ว', 'มอบหมายรถและคนขับเรียบร้อยแล้ว');
        } catch (mysqli_sql_exception $exception) {
            error_log('Database Exception: ' . $exception->getMessage());
            audit_log('vehicle', 'ASSIGN', 'FAIL', 'dh_vehicle_bookings', $booking_id, $exception->getMessage());
            $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกการมอบหมายได้ในขณะนี้');
        }
    }

    if ($current_status === 'ASSIGNED') {
        // Deputy/acting director final decision (approve -> APPROVED)
        if ($is_final_approver) {
            try {
                $status_value = 'APPROVED';
                $status_reason = null;
                $set_fields = [
                    'status = ?',
                    'statusReason = ?',
                    'approvedByPID = ?',
                    'approvedAt = ?',
                    'updatedAt = CURRENT_TIMESTAMP',
                ];
                $bind_values = [
                    $status_value,
                    $status_reason,
                    $actor_pid,
                    $approved_at,
                ];
                $types = 'ssss';

                if (vehicle_reservation_has_column($vehicle_columns, 'approvalNote')) {
                    $set_fields[] = 'approvalNote = ?';
                    $bind_values[] = $approval_note !== '' ? $approval_note : null;
                    $types .= 's';
                }

                $update_sql = 'UPDATE dh_vehicle_bookings SET ' . implode(', ', $set_fields)
                    . ' WHERE bookingID = ? AND deletedAt IS NULL';
                $bind_values[] = $booking_id;
                $types .= 'i';

                $update_stmt = mysqli_prepare($connection, $update_sql);

                $bind_params = [];
                $bind_params[] = $update_stmt;
                $bind_params[] = $types;

                foreach ($bind_values as $i => $v) {
                    $bind_params[] = &$bind_values[$i];
                }
                call_user_func_array('mysqli_stmt_bind_param', $bind_params);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);

                audit_log('vehicle', 'FINAL_APPROVE', 'SUCCESS', 'dh_vehicle_bookings', $booking_id);

                $set_vehicle_approval_alert('success', 'อนุมัติสำเร็จ', 'บันทึกผลการอนุมัติเรียบร้อยแล้ว');
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());
                audit_log('vehicle', 'FINAL_APPROVE', 'FAIL', 'dh_vehicle_bookings', $booking_id, $exception->getMessage());
                $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกผลการพิจารณาได้ในขณะนี้');
            }
        }

        // Vehicle officer may update assigned vehicle/driver while status is ASSIGNED ("กำลังดำเนินการ").
        if ($is_vehicle_officer) {
            $assign_vehicle_id = (int) ($_POST['assign_vehicle_id'] ?? 0);

            if ($assign_vehicle_id <= 0) {
                if (function_exists('audit_log')) {
                    audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'vehicle_required');
                }
                $set_vehicle_approval_alert('warning', 'กรุณาเลือกยานพาหนะ', 'โปรดเลือกยานพาหนะก่อนบันทึกการแก้ไข');
            }

            $vehicle_exists = false;

            try {
                $vehicle_check_sql = 'SELECT vehicleID FROM dh_vehicles WHERE vehicleID = ?';

                if ($vehicle_supports_soft_delete) {
                    $vehicle_check_sql .= ' AND deletedAt IS NULL';
                }

                if ($vehicle_supports_status) {
                    $vehicle_check_sql .= " AND vehicleStatus = 'พร้อมใช้งาน'";
                }
                $vehicle_check_sql .= ' LIMIT 1';

                $vehicle_stmt = mysqli_prepare($connection, $vehicle_check_sql);
                mysqli_stmt_bind_param($vehicle_stmt, 'i', $assign_vehicle_id);
                mysqli_stmt_execute($vehicle_stmt);
                $vehicle_result = mysqli_stmt_get_result($vehicle_stmt);
                $vehicle_exists = $vehicle_result && mysqli_fetch_assoc($vehicle_result);
                mysqli_stmt_close($vehicle_stmt);
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());
            }

            if (!$vehicle_exists) {
                if (function_exists('audit_log')) {
                    audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'vehicle_not_available', [
                        'vehicleID' => $assign_vehicle_id,
                    ]);
                }
                $set_vehicle_approval_alert('warning', 'ยานพาหนะไม่พร้อมใช้งาน', 'กรุณาเลือกยานพาหนะที่พร้อมใช้งาน');
            }

            $assign_driver_pid = trim((string) ($_POST['assign_driver_pid'] ?? ''));

            if ($assign_driver_pid !== '' && !ctype_digit($assign_driver_pid)) {
                $assign_driver_pid = preg_replace('/\D+/', '', $assign_driver_pid);
            }

            if ($assign_driver_pid === '') {
                if (function_exists('audit_log')) {
                    audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_required');
                }
                $set_vehicle_approval_alert('warning', 'กรุณาเลือกผู้ขับรถ', 'โปรดเลือกผู้ขับรถก่อนบันทึกการแก้ไข');
            }

            $assign_driver_name = '';
            $assign_driver_tel = '';

            try {
                $driver_sql = 'SELECT pID, fName, telephone FROM teacher WHERE pID = ? AND status = 1 LIMIT 1';
                $driver_stmt = mysqli_prepare($connection, $driver_sql);
                mysqli_stmt_bind_param($driver_stmt, 's', $assign_driver_pid);
                mysqli_stmt_execute($driver_stmt);
                $driver_result = mysqli_stmt_get_result($driver_stmt);
                $driver_row = $driver_result ? mysqli_fetch_assoc($driver_result) : null;
                mysqli_stmt_close($driver_stmt);

                if (!$driver_row) {
                    if (function_exists('audit_log')) {
                        audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_not_found', [
                            'driverPID' => $assign_driver_pid,
                        ]);
                    }
                    $set_vehicle_approval_alert('warning', 'ไม่พบผู้ขับรถ', 'กรุณาเลือกผู้ขับรถที่ถูกต้อง');
                }

                $assign_driver_name = preg_replace('/\s+/u', ' ', trim((string) ($driver_row['fName'] ?? ''))) ?? '';
                $assign_driver_tel = preg_replace('/\s+/u', ' ', trim((string) ($driver_row['telephone'] ?? ''))) ?? '';
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());

                if (function_exists('audit_log')) {
                    audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_lookup_failed', [
                        'driverPID' => $assign_driver_pid,
                    ]);
                }
                $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถดึงข้อมูลผู้ขับรถได้ในขณะนี้');
            }

            if ($assign_driver_name === '') {
                if (function_exists('audit_log')) {
                    audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'driver_name_missing', [
                        'driverPID' => $assign_driver_pid,
                    ]);
                }
                $set_vehicle_approval_alert('warning', 'ข้อมูลไม่ครบถ้วน', 'ไม่พบชื่อผู้ขับรถ');
            }

            $driver_pid_param = $assign_driver_pid !== '' ? $assign_driver_pid : null;
            // Avoid duplicating driver phone number in booking records when it can be derived from teacher.telephone.
            // We only persist driverTel for manual drivers (no driverPID).
            $driver_tel_param = null;
            // No manual driver entry in approval flow.
            $assign_final_approver = ['pID' => null, 'name' => ''];

            if (vehicle_reservation_has_column($vehicle_columns, 'finalApproverPID')) {
                $assign_final_approver = $resolve_final_approver(
                    (string) ($_POST['assign_final_approver_pid'] ?? ''),
                    'ASSIGN_UPDATE'
                );
            }

            try {
                $status_value = 'ASSIGNED';
                $status_reason = null;
                $set_fields = [
                    'status = ?',
                    'statusReason = ?',
                    'vehicleID = ?',
                    'driverPID = ?',
                    'driverName = ?',
                    // Keep the legacy behavior: before final approval, approvedBy/approvedAt
                    // represent the latest officer action for display fallback.
                    'approvedByPID = ?',
                    'approvedAt = ?',
                    'updatedAt = CURRENT_TIMESTAMP',
                ];
                $bind_values = [
                    $status_value,
                    $status_reason,
                    $assign_vehicle_id,
                    $driver_pid_param,
                    $assign_driver_name,
                    $actor_pid,
                    $approved_at,
                ];
                $types = 'ssissss';

                if (vehicle_reservation_has_column($vehicle_columns, 'driverTel')) {
                    $set_fields[] = 'driverTel = ?';
                    $bind_values[] = $driver_tel_param;
                    $types .= 's';
                }

                if (vehicle_reservation_has_column($vehicle_columns, 'assignedByPID')) {
                    $set_fields[] = 'assignedByPID = ?';
                    $bind_values[] = $actor_pid;
                    $types .= 's';
                }

                if (vehicle_reservation_has_column($vehicle_columns, 'assignedAt')) {
                    $set_fields[] = 'assignedAt = ?';
                    $bind_values[] = $approved_at;
                    $types .= 's';
                }

                if ($assigned_note_present && vehicle_reservation_has_column($vehicle_columns, 'assignedNote')) {
                    $set_fields[] = 'assignedNote = ?';
                    $bind_values[] = $assigned_note !== '' ? $assigned_note : null;
                    $types .= 's';
                }

                if (vehicle_reservation_has_column($vehicle_columns, 'finalApproverPID')) {
                    $set_fields[] = 'finalApproverPID = ?';
                    $bind_values[] = $assign_final_approver['pID'];
                    $types .= 's';
                }

                $update_sql = 'UPDATE dh_vehicle_bookings SET ' . implode(', ', $set_fields)
                    . ' WHERE bookingID = ? AND deletedAt IS NULL';
                $bind_values[] = $booking_id;
                $types .= 'i';

                $update_stmt = mysqli_prepare($connection, $update_sql);

                $bind_params = [];
                $bind_params[] = $update_stmt;
                $bind_params[] = $types;

                foreach ($bind_values as $i => $v) {
                    $bind_params[] = &$bind_values[$i];
                }
                call_user_func_array('mysqli_stmt_bind_param', $bind_params);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);

                audit_log('vehicle', 'ASSIGN_UPDATE', 'SUCCESS', 'dh_vehicle_bookings', $booking_id, null, [
                    'vehicleID' => $assign_vehicle_id,
                    'driverPID' => $driver_pid_param,
                    'driverName' => $assign_driver_name,
                    'finalApproverPID' => $assign_final_approver['pID'],
                ]);

                $set_vehicle_approval_alert('success', 'บันทึกสำเร็จ', 'อัปเดตการมอบหมายรถและคนขับเรียบร้อยแล้ว');
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());
                audit_log('vehicle', 'ASSIGN_UPDATE', 'FAIL', 'dh_vehicle_bookings', $booking_id, $exception->getMessage());
                $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกการมอบหมายได้ในขณะนี้');
            }
        }

        if (function_exists('audit_log')) {
            audit_log('vehicle', 'APPROVAL', 'DENY', 'dh_vehicle_bookings', $booking_id, 'not_allowed', [
                'status' => $current_status,
                'roleID' => $role_id,
            ]);
        }
        $set_vehicle_approval_alert('danger', 'ไม่มีสิทธิ์ดำเนินการ', 'เฉพาะเจ้าหน้าที่งานยานพาหนะหรือผู้บริหารเท่านั้น');
    }

    if (in_array($current_status, ['APPROVED', 'REJECTED'], true)) {
        // Deputy/acting director may edit/change the final decision after it was already recorded.
        if (!$is_final_approver) {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'FINAL_APPROVE_OVERRIDE', 'DENY', 'dh_vehicle_bookings', $booking_id, 'not_final_approver', [
                    'status' => $current_status,
                    'roleID' => $role_id,
                ]);
            }
            $set_vehicle_approval_alert('danger', 'ไม่มีสิทธิ์ดำเนินการ', 'เฉพาะผู้บริหารเท่านั้น');
        }

        try {
            $from_status = $current_status;

            $status_value = 'APPROVED';
            $status_reason = null;
            $set_fields = [
                'status = ?',
                'statusReason = ?',
                'approvedByPID = ?',
                'approvedAt = ?',
                'updatedAt = CURRENT_TIMESTAMP',
            ];
            $bind_values = [
                $status_value,
                $status_reason,
                $actor_pid,
                $approved_at,
            ];
            $types = 'ssss';

            if (vehicle_reservation_has_column($vehicle_columns, 'approvalNote')) {
                $set_fields[] = 'approvalNote = ?';
                $bind_values[] = $approval_note !== '' ? $approval_note : null;
                $types .= 's';
            }

            $update_sql = 'UPDATE dh_vehicle_bookings SET ' . implode(', ', $set_fields)
                . ' WHERE bookingID = ? AND deletedAt IS NULL';
            $bind_values[] = $booking_id;
            $types .= 'i';

            $update_stmt = mysqli_prepare($connection, $update_sql);

            $bind_params = [];
            $bind_params[] = $update_stmt;
            $bind_params[] = $types;

            foreach ($bind_values as $i => $v) {
                $bind_params[] = &$bind_values[$i];
            }
            call_user_func_array('mysqli_stmt_bind_param', $bind_params);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);

            audit_log('vehicle', 'FINAL_APPROVE_OVERRIDE', 'SUCCESS', 'dh_vehicle_bookings', $booking_id, null, [
                'from' => $from_status,
                'to' => $status_value,
            ]);

            $set_vehicle_approval_alert('success', 'บันทึกสำเร็จ', 'อัปเดตผลการอนุมัติเรียบร้อยแล้ว');
        } catch (mysqli_sql_exception $exception) {
            error_log('Database Exception: ' . $exception->getMessage());
            audit_log('vehicle', 'FINAL_APPROVE_OVERRIDE', 'FAIL', 'dh_vehicle_bookings', $booking_id, $exception->getMessage());
            $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกผลการพิจารณาได้ในขณะนี้');
        }
    }

    if (function_exists('audit_log')) {
        audit_log('vehicle', 'APPROVAL', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'invalid_status_for_approve', [
            'status' => $current_status,
        ]);
    }
    $set_vehicle_approval_alert('warning', 'ไม่สามารถดำเนินการได้', 'สถานะรายการไม่รองรับการอนุมัติ');
}

if ($action === 'reject') {
    $can_reject = false;

    if (in_array($current_status, ['ASSIGNED', 'APPROVED', 'REJECTED'], true) && $is_final_approver) {
        $can_reject = true;
    }

    if (!$can_reject) {
        if (function_exists('audit_log')) {
            audit_log('vehicle', 'REJECT', 'DENY', 'dh_vehicle_bookings', $booking_id, 'cannot_reject', [
                'status' => $current_status,
                'roleID' => $role_id,
            ]);
        }
        $set_vehicle_approval_alert('danger', 'ไม่มีสิทธิ์ดำเนินการ', 'ไม่สามารถปฏิเสธรายการนี้ได้');
    }

    try {
        $from_status = $current_status;
        $status_value = 'REJECTED';
        // Use the same note as the rejection reason (single textarea in UI).
        $status_reason = $approval_note;
        $set_fields = [
            'status = ?',
            'statusReason = ?',
            'approvedByPID = ?',
            'approvedAt = ?',
            'updatedAt = CURRENT_TIMESTAMP',
        ];
        $bind_values = [
            $status_value,
            $status_reason,
            $actor_pid,
            $approved_at,
        ];
        $types = 'ssss';

        if (vehicle_reservation_has_column($vehicle_columns, 'approvalNote')) {
            $set_fields[] = 'approvalNote = ?';
            $bind_values[] = $approval_note !== '' ? $approval_note : null;
            $types .= 's';
        }

        $update_sql = 'UPDATE dh_vehicle_bookings SET ' . implode(', ', $set_fields)
            . ' WHERE bookingID = ? AND deletedAt IS NULL';
        $bind_values[] = $booking_id;
        $types .= 'i';

        $update_stmt = mysqli_prepare($connection, $update_sql);

        $bind_params = [];
        $bind_params[] = $update_stmt;
        $bind_params[] = $types;

        foreach ($bind_values as $i => $v) {
            $bind_params[] = &$bind_values[$i];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind_params);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);

        $audit_action = $from_status === 'ASSIGNED' ? 'REJECT' : 'FINAL_REJECT_OVERRIDE';
        audit_log('vehicle', $audit_action, 'SUCCESS', 'dh_vehicle_bookings', $booking_id, null, [
            'from' => $from_status,
            'to' => $status_value,
        ]);

        $set_vehicle_approval_alert('success', 'บันทึกสำเร็จ', 'บันทึกการไม่อนุมัติเรียบร้อยแล้ว');
    } catch (mysqli_sql_exception $exception) {
        error_log('Database Exception: ' . $exception->getMessage());
        $audit_action = ($current_status === 'ASSIGNED') ? 'REJECT' : 'FINAL_REJECT_OVERRIDE';
        audit_log('vehicle', $audit_action, 'FAIL', 'dh_vehicle_bookings', $booking_id, $exception->getMessage());
        $set_vehicle_approval_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกผลการพิจารณาได้ในขณะนี้');
    }
}
