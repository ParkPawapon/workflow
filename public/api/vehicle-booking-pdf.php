<?php

declare(strict_types=1);

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

// Production-grade PDF responses must never be corrupted by PHP notices/warnings.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$__pdf_initial_ob_level = ob_get_level();
ob_start();

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log('PHP error [' . $severity . '] ' . $message . ' in ' . $file . ':' . $line);

    return true; // Prevent default handler from outputting to the response.
});

$__pdf_abort = static function (int $status) use ($__pdf_initial_ob_level): void {
    while (ob_get_level() > $__pdf_initial_ob_level) {
        ob_end_clean();
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    exit();
};

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../app/modules/audit/logger.php';

if (!function_exists('vehicle_pdf_runtime_report')) {
    function vehicle_pdf_runtime_report(string $cache_key): array
    {
        $required_extensions = ['mbstring', 'gd'];
        $missing_extensions = [];
        $extensions = [];

        foreach ($required_extensions as $extension) {
            $loaded = extension_loaded($extension);
            $extensions[$extension] = $loaded;

            if (!$loaded) {
                $missing_extensions[] = $extension;
            }
        }

        $class_map = [
            'mpdf' => class_exists(Mpdf::class),
            'config_variables' => class_exists(ConfigVariables::class),
            'font_variables' => class_exists(FontVariables::class),
        ];

        $missing_classes = [];

        foreach ($class_map as $label => $loaded) {
            if (!$loaded) {
                $missing_classes[] = $label;
            }
        }

        $system_temp = (string) sys_get_temp_dir();
        $temp_dir = rtrim($system_temp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'workflow-mpdf-' . $cache_key;
        $temp_dir_ready = false;

        if ($system_temp !== '') {
            if (!is_dir($temp_dir)) {
                @mkdir($temp_dir, 0777, true);
            }

            $temp_dir_ready = is_dir($temp_dir) && is_writable($temp_dir);

            if (!$temp_dir_ready) {
                $temp_dir = $system_temp;
                $temp_dir_ready = is_dir($temp_dir) && is_writable($temp_dir);
            }
        }

        $errors = [];

        if ($missing_extensions !== []) {
            $errors[] = 'missing_extensions:' . implode(',', $missing_extensions);
        }

        if ($missing_classes !== []) {
            $errors[] = 'missing_classes:' . implode(',', $missing_classes);
        }

        if (!$temp_dir_ready) {
            $errors[] = 'temp_dir_not_writable';
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'extensions' => $extensions,
            'classes' => $class_map,
            'temp_dir' => [
                'path' => $temp_dir,
                'writable' => $temp_dir_ready,
            ],
        ];
    }
}

if (empty($_SESSION['pID'])) {
    if (function_exists('audit_log')) {
        audit_log('security', 'AUTH_REQUIRED', 'DENY', null, null, 'vehicle_booking_pdf', [], 'GET', 401);
    }
    $__pdf_abort(401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', null, 'method_not_allowed', [], null, 405);
    }
    $__pdf_abort(405);
}

$booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$booking_id) {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', null, 'invalid_booking_id', [
            'bookingID' => $booking_id ?: null,
        ], 'GET', 400);
    }
    $__pdf_abort(400);
}

require_once __DIR__ . '/../../config/connection.php';
require_once __DIR__ . '/../../app/db/db.php';
require_once __DIR__ . '/../../app/rbac/roles.php';
require_once __DIR__ . '/../../app/modules/system/system.php';
require_once __DIR__ . '/../../app/modules/system/positions.php';
require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-utils.php';

$actor_pid = (string) ($_SESSION['pID'] ?? '');

// Authorization: requester OR director OR vehicle officer
$booking_row = null;

try {
    $booking_row = db_fetch_one(
        'SELECT bookingID, requesterPID, status FROM dh_vehicle_bookings WHERE bookingID = ? AND deletedAt IS NULL LIMIT 1',
        'i',
        $booking_id
    );
} catch (Throwable $e) {
    error_log('Database Exception (booking lookup pdf): ' . $e->getMessage());

    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'booking_lookup_failed', [], 'GET', 500);
    }
    $__pdf_abort(500);
}

if (!$booking_row) {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'booking_not_found', [], 'GET', 404);
    }
    $__pdf_abort(404);
}

$authorized = (string) ($booking_row['requesterPID'] ?? '') === $actor_pid;

if (!$authorized) {
    $is_director = system_get_current_director_pid() === $actor_pid;
    $is_vehicle_officer = rbac_user_has_role($connection, $actor_pid, ROLE_VEHICLE)
        || rbac_user_has_role($connection, $actor_pid, ROLE_ADMIN);

    // Backward-compatible roles (legacy teacher.roleID)
    if (!$is_vehicle_officer) {
        try {
            $legacy_role = db_fetch_one('SELECT roleID FROM teacher WHERE pID = ? AND status = 1 LIMIT 1', 's', $actor_pid);
            $legacy_role_id = (int) ($legacy_role['roleID'] ?? 0);

            if (in_array($legacy_role_id, [1, 3], true)) {
                $is_vehicle_officer = true;
            }
        } catch (Throwable $e) {
            error_log('Database Exception (legacy role pdf): ' . $e->getMessage());
        }
    }

    $authorized = $is_director || $is_vehicle_officer;
}

if (!$authorized) {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'DENY', 'dh_vehicle_bookings', $booking_id, 'not_authorized', [], 'GET', 403);
    }
    $__pdf_abort(403);
}

$booking_status = strtoupper(trim((string) ($booking_row['status'] ?? '')));

if ($booking_status !== 'APPROVED') {
    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'DENY', 'dh_vehicle_bookings', $booking_id, 'status_not_approved', [
            'status' => $booking_status !== '' ? $booking_status : null,
        ], 'GET', 403);
    }
    $__pdf_abort(403);
}

$thai_months = [
    1 => 'มกราคม',
    2 => 'กุมภาพันธ์',
    3 => 'มีนาคม',
    4 => 'เมษายน',
    5 => 'พฤษภาคม',
    6 => 'มิถุนายน',
    7 => 'กรกฎาคม',
    8 => 'สิงหาคม',
    9 => 'กันยายน',
    10 => 'ตุลาคม',
    11 => 'พฤศจิกายน',
    12 => 'ธันวาคม',
];

$format_thai_date = static function (?string $date) use ($thai_months): string {
    $date = trim((string) $date);

    if ($date === '' || strpos($date, '0000-00-00') === 0) {
        return '-';
    }
    $obj = DateTime::createFromFormat('Y-m-d', $date);

    if ($obj === false) {
        return $date;
    }
    $day = (int) $obj->format('j');
    $month = (int) $obj->format('n');
    $year = (int) $obj->format('Y') + 543;
    $month_label = $thai_months[$month] ?? '';

    return trim($day . ' ' . $month_label . ' ' . $year);
};

$format_thai_time = static function (?string $datetime): string {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || strpos($datetime, '0000-00-00') === 0) {
        return '-';
    }
    $obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);

    if ($obj === false) {
        $obj = DateTime::createFromFormat('Y-m-d H:i', $datetime);
    }

    if ($obj === false) {
        return $datetime;
    }

    return str_replace(':', '.', $obj->format('H:i'));
};

$fuel_label = static function (?string $fuel): string {
    $fuel = strtolower(trim((string) $fuel));

    return match ($fuel) {
        'central' => 'ส่วนกลาง',
        'project' => 'โครงการ',
        'user' => 'ผู้ใช้',
        default => $fuel !== '' ? $fuel : '-',
    };
};

$normalize_inline_text = static function (?string $value): string {
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    return preg_replace('/\s+/u', ' ', $value) ?? $value;
};

$safe_file_to_data_uri = static function (?string $relative_path): ?string {
    $relative_path = trim((string) $relative_path);

    if ($relative_path === '') {
        return null;
    }

    // Only allow local project files.
    $project_root = realpath(__DIR__ . '/../..');

    if ($project_root === false) {
        return null;
    }

    $relative_path = ltrim($relative_path, '/');

    // Allowlist signature paths only (prevents reading arbitrary local files).
    if (!preg_match('#^assets/img/signature/#', $relative_path)) {
        return null;
    }
    $candidate = realpath($project_root . '/' . $relative_path);

    if ($candidate === false || strpos($candidate, $project_root) !== 0 || !is_file($candidate)) {
        return null;
    }

    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => null,
    };

    if ($mime === null) {
        return null;
    }

    $contents = @file_get_contents($candidate);

    if ($contents === false || $contents === '') {
        return null;
    }

    return 'data:' . $mime . ';base64,' . base64_encode($contents);
};

$vehicle_columns = vehicle_reservation_get_table_columns($connection, 'dh_vehicle_bookings');
$has_assigned = vehicle_reservation_has_column($vehicle_columns, 'assignedByPID');
$has_final_approver = vehicle_reservation_has_column($vehicle_columns, 'finalApproverPID');

$select_fields = [
    'b.bookingID',
    'b.requesterPID',
    'b.vehicleID',
    'b.driverPID',
    'b.driverName',
    vehicle_reservation_has_column($vehicle_columns, 'driverTel')
        ? "COALESCE(NULLIF(drv.telephone, ''), b.driverTel) AS driverTel"
        : 'drv.telephone AS driverTel',
    'b.startAt',
    'b.endAt',
    'b.status',
    'b.statusReason',
    'b.approvedByPID',
    'b.approvedAt',
    'b.createdAt',
    'b.updatedAt',
];

$optional_columns = [
    'department',
    'purpose',
    'location',
    'passengerCount',
    'fuelSource',
    'writeDate',
    'companionIds',
    'otherPassengerCount',
    'otherPassengerNames',
    'requesterDisplayName',
    'assignedByPID',
    'assignedAt',
    'assignedNote',
    'finalApproverPID',
    'approvalNote',
];

foreach ($optional_columns as $column) {
    if (vehicle_reservation_has_column($vehicle_columns, $column)) {
        $select_fields[] = 'b.' . $column;
    }
}

$req_position = system_position_join($connection, 'req', 'preq');
$asg_position = system_position_join($connection, 'asg', 'pasg');
$app_position = system_position_join($connection, 'app', 'papp');
$fin_position = system_position_join($connection, 'fin', 'pfin');

$assigned_join = '';
$assigned_select = '';
$final_approver_join = '';
$final_approver_select = ",
        '' AS final_approver_name,
        '' AS final_approver_signature,
        '' AS final_approver_position";

if ($has_assigned) {
    $assigned_select = ',
        asg.fName AS assigned_name,
        asg.signature AS assigned_signature,
        ' . $asg_position['name'] . ' AS assigned_position';
    $assigned_join = 'LEFT JOIN teacher AS asg ON b.assignedByPID = asg.pID
        ' . $asg_position['join'];
}

if ($has_final_approver) {
    $final_approver_select = ',
        fin.fName AS final_approver_name,
        fin.signature AS final_approver_signature,
        ' . $fin_position['name'] . ' AS final_approver_position';
    $final_approver_join = 'LEFT JOIN teacher AS fin ON b.finalApproverPID = fin.pID
        ' . $fin_position['join'];
}

$sql = 'SELECT ' . implode(', ', $select_fields) . ',
        req.fName AS requester_name,
        req.telephone AS requester_phone,
        req.signature AS requester_signature,
        ' . $req_position['name'] . ' AS requester_position,
        dep.dName AS requester_department,
        v.vehiclePlate,
        v.vehicleType,
        v.vehicleBrand,
        v.vehicleModel
        ' . $assigned_select . ',
        app.fName AS approver_name,
        app.signature AS approver_signature,
        ' . $app_position['name'] . ' AS approver_position
        ' . $final_approver_select . '
    FROM dh_vehicle_bookings AS b
    LEFT JOIN teacher AS req ON b.requesterPID = req.pID
    LEFT JOIN teacher AS drv ON b.driverPID = drv.pID
    LEFT JOIN department AS dep ON req.dID = dep.dID
    ' . $req_position['join'] . '
    LEFT JOIN dh_vehicles AS v ON b.vehicleID = v.vehicleID
    ' . $assigned_join . '
    LEFT JOIN teacher AS app ON b.approvedByPID = app.pID
    ' . $app_position['join'] . '
    ' . $final_approver_join . '
    WHERE b.bookingID = ? AND b.deletedAt IS NULL
    LIMIT 1';

$row = null;

try {
    $stmt = mysqli_prepare($connection, $sql);

    if ($stmt === false) {
        error_log('Database Error (prepare pdf): ' . mysqli_error($connection));
        $__pdf_abort(500);
    }
    mysqli_stmt_bind_param($stmt, 'i', $booking_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
} catch (mysqli_sql_exception $exception) {
    error_log('Database Exception (pdf): ' . $exception->getMessage());
}

if (!$row) {
    $__pdf_abort(404);
}

$school_name = 'โรงเรียนดีบุกพังงาวิทยายน';

$status_key = strtoupper(trim((string) ($row['status'] ?? 'PENDING')));
$is_approved = in_array($status_key, ['APPROVED', 'COMPLETED'], true);
$is_rejected = in_array($status_key, ['REJECTED', 'CANCELLED'], true);

$created_at = trim((string) ($row['createdAt'] ?? ''));
$write_date = (string) ($row['writeDate'] ?? '');

if ($write_date === '' || strpos($write_date, '0000-00-00') === 0) {
    $write_date = $created_at !== '' ? substr($created_at, 0, 10) : '';
}

if ($write_date === '') {
    $start_at_fallback = (string) ($row['startAt'] ?? '');
    $write_date = $start_at_fallback !== '' ? substr($start_at_fallback, 0, 10) : '';
}

$start_at = (string) ($row['startAt'] ?? '');
$end_at = (string) ($row['endAt'] ?? '');
$start_date = $start_at !== '' ? substr($start_at, 0, 10) : '';
$end_date = $end_at !== '' ? substr($end_at, 0, 10) : $start_date;

$day_count_label = '-';

try {
    if ($start_date !== '' && $end_date !== '') {
        $start_obj = DateTime::createFromFormat('Y-m-d', $start_date);
        $end_obj = DateTime::createFromFormat('Y-m-d', $end_date);

        if ($start_obj && $end_obj) {
            $diff = $start_obj->diff($end_obj);
            $days = (int) $diff->days + 1;
            $day_count_label = (string) max(1, $days);
        }
    }
} catch (Exception $e) {
    // ignore
}

$requester_name = trim((string) ($row['requesterDisplayName'] ?? ''));

if ($requester_name === '') {
    $requester_name = trim((string) ($row['requester_name'] ?? ''));
}

$requester_position = trim((string) ($row['requester_position'] ?? ''));
$requester_department = trim((string) ($row['department'] ?? ''));

if ($requester_department === '') {
    $requester_department = trim((string) ($row['requester_department'] ?? ''));
}
$requester_phone = trim((string) ($row['requester_phone'] ?? ''));

$purpose = trim((string) ($row['purpose'] ?? ''));
$location = trim((string) ($row['location'] ?? ''));
$passengers = (string) ($row['passengerCount'] ?? $row['companionCount'] ?? '');
$passengers = $passengers !== '' ? $passengers : '-';
$fuel = $fuel_label((string) ($row['fuelSource'] ?? ''));

$companion_names = [];
$companion_ids_raw = (string) ($row['companionIds'] ?? '');
$companion_ids = [];

if ($companion_ids_raw !== '') {
    $decoded = json_decode($companion_ids_raw, true);

    if (is_array($decoded)) {
        foreach ($decoded as $pid) {
            $pid = trim((string) $pid);

            if ($pid !== '') {
                $companion_ids[] = $pid;
            }
        }
    }
}
$companion_ids = array_values(array_unique(array_filter($companion_ids)));

if (!empty($companion_ids)) {
    try {
        $placeholders = implode(', ', array_fill(0, count($companion_ids), '?'));
        $types = str_repeat('s', count($companion_ids));

        $stmt = mysqli_prepare($connection, 'SELECT pID, fName FROM teacher WHERE status = 1 AND pID IN (' . $placeholders . ')');

        if ($stmt) {
            $bind_params = array_merge([$stmt, $types], $companion_ids);
            $refs = [];

            foreach ($bind_params as $index => $value) {
                $refs[$index] = &$bind_params[$index];
            }

            if (call_user_func_array('mysqli_stmt_bind_param', $refs) !== false) {
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $name_map = [];

                while ($result && ($r = mysqli_fetch_assoc($result))) {
                    $pid = trim((string) ($r['pID'] ?? ''));

                    if ($pid !== '') {
                        $name_map[$pid] = trim((string) ($r['fName'] ?? ''));
                    }
                }

                foreach ($companion_ids as $pid) {
                    if (!empty($name_map[$pid])) {
                        $companion_names[] = $name_map[$pid];
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Database Exception (companion pdf): ' . $exception->getMessage());
    }
}

$companion_label = '';

if (!empty($companion_names)) {
    $companion_label = implode(', ', $companion_names);
}

$other_passenger_count = (int) ($row['otherPassengerCount'] ?? 0);

if ($other_passenger_count <= 0) {
    $other_passenger_count = max(0, (int) ($row['passengerCount'] ?? 0) - count($companion_ids) - 1);
}

$other_passenger_names = trim((string) ($row['otherPassengerNames'] ?? ''));
$other_passenger_label = '';

if ($other_passenger_names !== '') {
    $other_passenger_lines = preg_split('/\r\n|\r|\n|,/', $other_passenger_names) ?: [];
    $other_passenger_lines = array_values(array_filter(array_map(
        static fn ($name): string => trim((string) $name),
        $other_passenger_lines
    )));

    if (!empty($other_passenger_lines)) {
        $other_passenger_label = implode(', ', $other_passenger_lines);
    }
}

$vehicle_plate = trim((string) ($row['vehiclePlate'] ?? ''));
$vehicle_type = trim((string) ($row['vehicleType'] ?? ''));
$vehicle_model = trim((string) ($row['vehicleModel'] ?? ''));
$vehicle_label = trim(($vehicle_type !== '' ? $vehicle_type : '') . ' ' . ($vehicle_plate !== '' ? $vehicle_plate : ''));
$vehicle_label = trim($vehicle_label) !== '' ? trim($vehicle_label) : '-';

$driver_name = trim((string) ($row['driverName'] ?? ''));
$driver_tel = trim((string) ($row['driverTel'] ?? ''));

$assigned_name = trim((string) ($row['assigned_name'] ?? ''));
$assigned_position = trim((string) ($row['assigned_position'] ?? ''));
$assigned_note = trim((string) ($row['assignedNote'] ?? ''));

$approved_by_pid = trim((string) ($row['approvedByPID'] ?? ''));
$approved_at = trim((string) ($row['approvedAt'] ?? ''));
$approved_at = ($approved_at === '' || strpos($approved_at, '0000-00-00') === 0) ? '' : $approved_at;

$approval_note = trim((string) ($row['approvalNote'] ?? ''));

$requester_sig = $safe_file_to_data_uri((string) ($row['requester_signature'] ?? ''));
$assigned_sig = $safe_file_to_data_uri((string) ($row['assigned_signature'] ?? ''));

$boss_pid = trim((string) ($row['finalApproverPID'] ?? ''));
$boss_name = '';
$boss_signature_path = '';
$boss_position_line_1 = '';

if ($boss_pid !== '') {
    $boss_name = trim((string) ($row['final_approver_name'] ?? ''));
    $boss_signature_path = trim((string) ($row['final_approver_signature'] ?? ''));
    $boss_position_line_1 = trim((string) ($row['final_approver_position'] ?? ''));
}

if ($boss_pid === '' || $boss_name === '') {
    try {
        $budget_deputy_ids = system_position_budget_deputy_ids($connection);

        if ($budget_deputy_ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($budget_deputy_ids), '?'));
            $boss_position = system_position_join($connection, 't', 'p');
            $sql = 'SELECT t.pID, t.fName, t.signature, ' . $boss_position['name'] . ' AS position_name
                FROM teacher AS t
                ' . $boss_position['join'] . '
                WHERE t.status = 1
                    AND t.positionID IN (' . $placeholders . ')
                ORDER BY FIELD(t.positionID, ' . implode(', ', array_map('intval', $budget_deputy_ids)) . '), t.fName ASC, t.pID ASC
                LIMIT 1';
            $types = str_repeat('i', count($budget_deputy_ids));
            $boss_row = db_fetch_one(
                $sql,
                $types,
                ...array_map('intval', $budget_deputy_ids)
            );
        } elseif ($boss_pid !== '') {
            $boss_position = system_position_join($connection, 't', 'p');
            $boss_row = db_fetch_one(
            'SELECT t.fName, t.signature, ' . $boss_position['name'] . ' AS position_name
                FROM teacher AS t
                ' . $boss_position['join'] . '
                WHERE t.pID = ? AND t.status = 1
                LIMIT 1',
            's',
            $boss_pid
        );
        } else {
            $boss_row = null;
        }

        if ($boss_row) {
            $boss_pid = trim((string) ($boss_row['pID'] ?? $boss_pid));
            $boss_name = trim((string) ($boss_row['fName'] ?? ''));
            $boss_signature_path = trim((string) ($boss_row['signature'] ?? ''));
            $boss_position_line_1 = trim((string) ($boss_row['position_name'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('Database Exception (boss profile pdf): ' . $e->getMessage());
    }
}

$boss_signature = $safe_file_to_data_uri($boss_signature_path);
$boss_decision_by_budget_deputy = $boss_pid !== '' && $approved_by_pid !== '' && $approved_by_pid === $boss_pid && ($is_approved || $is_rejected);
$boss_signature_for_doc = $boss_decision_by_budget_deputy ? $boss_signature : null;
$boss_note_for_doc = $boss_decision_by_budget_deputy ? $approval_note : '';
$boss_position_line_1 = $boss_position_line_1 !== '' ? $boss_position_line_1 : 'รองผู้อำนวยการ' . $school_name;
$boss_position_line_2 = '';

$order_allow_checked = $boss_decision_by_budget_deputy && $is_approved;
$order_deny_checked = $boss_decision_by_budget_deputy && $is_rejected;
$order_pending_label = !$boss_decision_by_budget_deputy ? 'รอพิจารณา' : '';

$requester_position_label = $normalize_inline_text($requester_position !== '' ? $requester_position : '-');
$requester_department_label = $normalize_inline_text($requester_department !== '' ? $requester_department : '');
$purpose_label = $normalize_inline_text($purpose !== '' ? $purpose : '-');
$location_label = $normalize_inline_text($location !== '' ? $location : '-');
$companion_label = $normalize_inline_text($companion_label);
$other_passenger_label = $normalize_inline_text($other_passenger_label);
$requester_name = $normalize_inline_text($requester_name !== '' ? $requester_name : '-');
$companion_inline = '';
$additional_passenger_inline = '';

if ($companion_label !== '') {
    $companion_inline = 'พร้อมด้วย ' . $companion_label . ' ';
}

if ($other_passenger_label !== '') {
    $additional_passenger_inline = 'และมีบุคลากรเพิ่มเติม ดังต่อไปนี้ ' . $other_passenger_label . ' ';
} elseif ($other_passenger_count > 0) {
    $additional_passenger_inline = 'และมีบุคลากรเพิ่มเติม ดังต่อไปนี้ จำนวน ' . $other_passenger_count . ' คน ';
}

$paragraph_lines = [];
$paragraph_lines[] = trim('ข้าพเจ้า ' . ($requester_name !== '' ? $requester_name : '-') . ' ตำแหน่ง ' . $requester_position_label
    . ($requester_department_label !== '' ? (' ' . $requester_department_label) : '')
    . ' สังกัด ' . $school_name);
$paragraph_lines[] = trim($companion_inline . $additional_passenger_inline . 'ขออนุญาตใช้รถเพื่อ ' . $purpose_label);
$paragraph_lines[] = trim('ณ ' . $location_label . ' มีคนนั่ง ' . $passengers . ' คน');
$paragraph_lines[] = trim('ตั้งแต่วันที่ ' . $format_thai_date($start_date) . ' เวลา ' . $format_thai_time($start_at) . ' น. ถึงวันที่ ' . $format_thai_date($end_date) . ' เวลา ' . $format_thai_time($end_at) . ' น.');
$paragraph_lines[] = trim('จำนวน ' . $day_count_label . ' วัน โดยใช้น้ำมันเชื้อเพลิงจาก ' . $fuel);
$order_status_label = $order_allow_checked ? 'อนุญาต' : ($order_deny_checked ? 'ไม่อนุญาต' : 'รอพิจารณา');

require_once __DIR__ . '/../../app/views/vehicle/vehicle-booking-pdf-template.php';
$html = vehicle_booking_pdf_render_html([
    'school_name' => $school_name,
    'write_date_label' => $format_thai_date($write_date),
    'requester_department' => $requester_department_label,
    'purpose_label' => $purpose_label,
    'location_label' => $location_label,
    'start_date_label' => $format_thai_date($start_date),
    'end_date_label' => $format_thai_date($end_date),
    'start_time_label' => $format_thai_time($start_at),
    'end_time_label' => $format_thai_time($end_at),
    'day_count_label' => $day_count_label,
    'passengers_label' => $passengers,
    'fuel_label' => $fuel,
    'companion_label' => $companion_label,
    'paragraph_lines' => $paragraph_lines,
    'requester_signature' => $requester_sig,
    'requester_name' => $requester_name !== '' ? $requester_name : '-',
    'requester_position' => $requester_position !== '' ? $requester_position : '-',
    'vehicle_label' => $vehicle_label,
    'driver_name' => $driver_name,
    'driver_tel' => $driver_tel,
    'assigned_note' => $assigned_note,
    'assigned_signature' => $assigned_sig,
    'assigned_name' => $assigned_name !== '' ? $assigned_name : '-',
    'assigned_position' => $assigned_position !== '' ? $assigned_position : '-',
    'boss_note' => $boss_note_for_doc,
    'boss_name' => $boss_name !== '' ? $boss_name : '-',
    'boss_position_line_1' => $boss_position_line_1,
    'boss_position_line_2' => $boss_position_line_2,
    'boss_signature' => $boss_signature_for_doc,
    'order_allow_checked' => $order_allow_checked,
    'order_deny_checked' => $order_deny_checked,
    'order_status_label' => $order_status_label,
]);

try {
    $mpdf_font_dir = __DIR__ . '/../../assets/fonts/sarabun';
    $has_sarabun = is_dir($mpdf_font_dir)
        && is_file($mpdf_font_dir . '/Sarabun-Regular.ttf')
        && is_file($mpdf_font_dir . '/Sarabun-Bold.ttf')
        && is_file($mpdf_font_dir . '/Sarabun-Italic.ttf')
        && is_file($mpdf_font_dir . '/Sarabun-BoldItalic.ttf');

    // Use a versioned temp dir to avoid stale/corrupt font-cache artifacts across font updates.
    // Cache key changes automatically when Sarabun font binaries change.
    $font_sig_parts = [];

    foreach (['Sarabun-Regular.ttf', 'Sarabun-Bold.ttf', 'Sarabun-Italic.ttf', 'Sarabun-BoldItalic.ttf'] as $font_file) {
        $path = $mpdf_font_dir . '/' . $font_file;

        if (is_file($path)) {
            $font_sig_parts[] = $font_file . ':' . filesize($path) . ':' . filemtime($path);
        }
    }
    $cache_key = substr(sha1(implode('|', $font_sig_parts) . '|sarabun|otl=255|winTypo'), 0, 12);
    $runtime_report = vehicle_pdf_runtime_report($cache_key);

    if (!$runtime_report['ready']) {
        $runtime_payload = [
            'runtime' => $runtime_report,
        ];

        error_log('Vehicle PDF runtime not ready: ' . (json_encode($runtime_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'runtime_check_failed'));

        if (function_exists('audit_log')) {
            audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'pdf_runtime_not_ready', $runtime_payload, 'GET', 500);
        }

        while (ob_get_level() > $__pdf_initial_ob_level) {
            ob_end_clean();
        }

        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF runtime is not ready: ' . implode(', ', $runtime_report['errors']);
        exit();
    }

    $mpdf_tmp = (string) ($runtime_report['temp_dir']['path'] ?? sys_get_temp_dir());

    $config_vars = (new ConfigVariables())->getDefaults();
    $font_dirs = $config_vars['fontDir'];

    $font_vars = (new FontVariables())->getDefaults();
    $font_data = $font_vars['fontdata'];

    if ($has_sarabun) {
        $font_dirs[] = $mpdf_font_dir;
        $font_data['sarabun'] = [
            'R' => 'Sarabun-Regular.ttf',
            'B' => 'Sarabun-Bold.ttf',
            'I' => 'Sarabun-Italic.ttf',
            'BI' => 'Sarabun-BoldItalic.ttf',
            // Enable OTL shaping for correct Thai vowels/tone marks placement.
            // We patch Sarabun fonts to include an invisible U+200B glyph so mPDF Thai shaper won't show tofu squares.
            'useOTL' => 0xFF,
        ];
    }

    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => $has_sarabun ? 'sarabun' : 'garuda',
        // All inputs are UTF-8 already; disabling conversion avoids iconv warnings corrupting PDF output.
        'allow_charset_conversion' => false,
        // Use Thai dictionary line breaking so long Thai strings wrap naturally in PDFs.
        // Sarabun fonts in this project are patched to avoid visible tofu squares from ZWSP.
        'useDictionaryLBR' => true,
        'tempDir' => $mpdf_tmp,
        // Better vertical metrics for Thai (prevents tone marks/combining marks from clipping).
        'fontDescriptor' => 'winTypo',
        'fontDir' => $font_dirs,
        'fontdata' => $font_data,
        // Narrower body column (closer to official form layout)
        'margin_left' => 24,
        'margin_right' => 24,
        'margin_top' => 16,
        'margin_bottom' => 16,
    ]);
} catch (Throwable $e) {
    error_log('PDF init failed: ' . $e->getMessage());

    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'pdf_init_failed', [], 'GET', 500);
    }
    $__pdf_abort(500);
}

$mpdf->SetTitle('Vehicle booking #' . $booking_id);

try {
    // Guard against stray zero-width spaces from copy/paste which can render as tofu squares in PDFs.
    $html_clean = preg_replace('/[\\x{200B}\\x{FEFF}]/u', '', $html);

    if (is_string($html_clean) && $html_clean !== '') {
        $html = $html_clean;
    }
    $mpdf->WriteHTML($html);

    $download = isset($_GET['download']) && $_GET['download'] === '1';
    $disposition = $download ? 'attachment' : 'inline';

    // Official-friendly filename (Thai + ASCII fallback) for government documents.
    $filename_date = '';

    try {
        if ($write_date !== '' && strpos($write_date, '0000-00-00') !== 0) {
            $dt = DateTime::createFromFormat('Y-m-d', $write_date);

            if ($dt instanceof DateTime) {
                $filename_date = ((int) $dt->format('Y') + 543) . $dt->format('md');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    $filename_date_suffix_th = $filename_date !== '' ? ('_วันที่' . $filename_date) : '';
    $filename_date_suffix_en = $filename_date !== '' ? ('_' . $filename_date) : '';

    $school_slug = preg_replace('/\\s+/u', '', (string) $school_name);
    $booking_no = str_pad((string) $booking_id, 4, '0', STR_PAD_LEFT);
    $filename_th = 'แบบขออนุญาตใช้รถยนต์ราชการ_' . $school_slug . '_เลขที่คำขอ' . $booking_no . ($filename_date !== '' ? ('_ลงวันที่' . $filename_date) : '') . '.pdf';
    $filename_ascii = 'gov_vehicle_request_' . $booking_no . $filename_date_suffix_en . '.pdf';
    $filename_ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $filename_ascii);

    $mpdf->SetTitle('แบบขออนุญาตใช้รถยนต์ราชการ เลขที่คำขอ ' . $booking_no);

    // Output as string so we can set a robust Content-Disposition with UTF-8 filename*.
    $pdf = $mpdf->Output('', 'S');

    if (!is_string($pdf) || $pdf === '') {
        throw new RuntimeException('PDF output is empty');
    }

    // Drop any stray output so the PDF stream always starts with %PDF.
    while (ob_get_level() > $__pdf_initial_ob_level) {
        ob_end_clean();
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }

    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . strlen($pdf));

    if (function_exists('audit_log')) {
        $audit_action = $download ? 'PDF_DOWNLOAD' : 'PDF_VIEW';
        audit_log('vehicle', $audit_action, 'SUCCESS', 'dh_vehicle_bookings', $booking_id, null, [
            'disposition' => $disposition,
        ], 'GET', 200);
    }

    // RFC 5987 filename* for UTF-8; keep ASCII fallback for older clients.
    $filename_star = rawurlencode($filename_th);
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename_ascii . '"; filename*=UTF-8\'\'' . $filename_star);

    echo $pdf;
} catch (Throwable $e) {
    error_log('PDF render failed: ' . $e->getMessage());

    if (function_exists('audit_log')) {
        audit_log('vehicle', 'PDF_VIEW', 'FAIL', 'dh_vehicle_bookings', $booking_id, 'pdf_render_failed', [], 'GET', 500);
    }
    $__pdf_abort(500);
}
exit();
