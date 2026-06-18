<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['vehicle_reservation_save'])) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/connection.php';
require_once __DIR__ . '/vehicle-reservation-utils.php';
require_once __DIR__ . '/vehicle-reservation-data.php';
require_once __DIR__ . '/../../../app/modules/audit/logger.php';

$redirect_url = 'vehicle-reservation.php';

$set_vehicle_alert = static function (
    string $type,
    string $title,
    string $message = '',
    string $button_label = 'ยืนยัน'
) use ($redirect_url): void {
    $_SESSION['vehicle_reservation_alert'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'button_label' => $button_label,
        'redirect' => '',
        'delay_ms' => 0,
    ];
};

$text_preview = static function (string $value, int $max_len = 120): string {
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max_len);
    }

    return substr($value, 0, $max_len);
};

$abort = static function (
    string $alert_type,
    string $title,
    string $message,
    ?string $audit_reason = null,
    array $audit_payload = [],
    string $audit_status = 'FAIL'
) use ($set_vehicle_alert, $redirect_url): void {
    if ($audit_reason !== null && function_exists('audit_log')) {
        audit_log('vehicle', 'CREATE', $audit_status, 'dh_vehicle_bookings', null, $audit_reason, $audit_payload);
    }

    $set_vehicle_alert($alert_type, $title, $message);
    header('Location: ' . $redirect_url, true, 303);
    exit();
};

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    if (function_exists('audit_log')) {
        audit_log('security', 'CSRF_FAIL', 'DENY', null, null, 'vehicle_reservation_save');
    }
    $set_vehicle_alert('danger', 'ไม่สามารถยืนยันความปลอดภัย', 'กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$requester_pid = (string) ($_SESSION['pID'] ?? '');

if ($requester_pid === '') {
    if (function_exists('audit_log')) {
        audit_log('security', 'AUTH_REQUIRED', 'DENY', null, null, 'vehicle_reservation_save');
    }
    header('Location: index.php', true, 302);
    exit();
}

$vehicle_year = (int) ($_POST['dh_year'] ?? 0);

if ($vehicle_year <= 0) {
    $vehicle_year = (int) date('Y') + 543;
}

$department = trim((string) ($_POST['department'] ?? ''));

if ($department === '') {
    $abort('danger', 'ข้อมูลไม่ถูกต้อง', 'กรุณาเลือกส่วนราชการ', 'department_required');
}

$department_pool = [];

foreach (vehicle_reservation_get_departments($connection) as $dept) {
    if (!empty($dept['name'])) {
        $department_pool[$dept['name']] = true;
    }
}

foreach (vehicle_reservation_get_factions($connection) as $faction) {
    if (!empty($faction['name'])) {
        $department_pool[$faction['name']] = true;
    }
}

if (!isset($department_pool[$department])) {
    $abort('danger', 'ข้อมูลไม่ถูกต้อง', 'กรุณาเลือกส่วนราชการจากรายการที่กำหนด', 'department_invalid', [
        'department' => $department,
    ]);
}

$write_date_raw = trim((string) ($_POST['writeDate'] ?? ''));
$write_date = '';

if ($write_date_raw !== '') {
    $write_date_obj = DateTime::createFromFormat('Y-m-d', $write_date_raw);

    if ($write_date_obj === false) {
        $abort('danger', 'วันที่ไม่ถูกต้อง', 'กรุณาเลือกวันที่เขียนให้ถูกต้อง', 'write_date_invalid', [
            'writeDate' => $write_date_raw,
        ]);
    }
    $write_date = $write_date_obj->format('Y-m-d');
}

$purpose = trim((string) ($_POST['purpose'] ?? ''));

if ($purpose === '') {
    $abort('danger', 'ข้อมูลไม่ครบถ้วน', 'กรุณาระบุวัตถุประสงค์การใช้รถ', 'purpose_required');
}

$location = trim((string) ($_POST['location'] ?? ''));

if ($location === '') {
    $abort('danger', 'ข้อมูลไม่ครบถ้วน', 'กรุณาระบุสถานที่ปลายทาง', 'location_required');
}

$start_date_raw = trim((string) ($_POST['startDate'] ?? ''));
$end_date_raw = trim((string) ($_POST['endDate'] ?? ''));

$start_date_obj = DateTime::createFromFormat('Y-m-d', $start_date_raw);

if ($start_date_obj === false) {
    $abort('danger', 'วันที่ไม่ถูกต้อง', 'กรุณาเลือกวันที่เริ่มเดินทาง', 'start_date_invalid', [
        'startDate' => $start_date_raw,
    ]);
}

$end_date_obj = $end_date_raw !== ''
    ? DateTime::createFromFormat('Y-m-d', $end_date_raw)
    : clone $start_date_obj;

if ($end_date_obj === false) {
    $abort('danger', 'วันที่ไม่ถูกต้อง', 'กรุณาเลือกวันที่สิ้นสุดให้ถูกต้อง', 'end_date_invalid', [
        'endDate' => $end_date_raw,
    ]);
}

if ($end_date_obj < $start_date_obj) {
    $abort('danger', 'วันที่ไม่ถูกต้อง', 'วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มเดินทาง', 'date_range_invalid', [
        'startDate' => $start_date_obj->format('Y-m-d'),
        'endDate' => $end_date_obj->format('Y-m-d'),
    ]);
}

$start_time_raw = trim((string) ($_POST['startTime'] ?? ''));
$end_time_raw = trim((string) ($_POST['endTime'] ?? ''));

$start_time_obj = DateTime::createFromFormat('H:i', $start_time_raw);
$end_time_obj = DateTime::createFromFormat('H:i', $end_time_raw);

if ($start_time_obj === false || $end_time_obj === false) {
    $abort('danger', 'เวลาไม่ถูกต้อง', 'กรุณาเลือกช่วงเวลาให้ครบถ้วน', 'time_required', [
        'startTime' => $start_time_raw,
        'endTime' => $end_time_raw,
    ]);
}

$start_at_obj = DateTime::createFromFormat('Y-m-d H:i', $start_date_obj->format('Y-m-d') . ' ' . $start_time_raw);
$end_at_obj = DateTime::createFromFormat('Y-m-d H:i', $end_date_obj->format('Y-m-d') . ' ' . $end_time_raw);

if ($start_at_obj === false || $end_at_obj === false) {
    $abort('danger', 'เวลาไม่ถูกต้อง', 'กรุณาเลือกช่วงเวลาให้ถูกต้อง', 'datetime_parse_failed', [
        'startAt' => $start_date_obj->format('Y-m-d') . ' ' . $start_time_raw,
        'endAt' => $end_date_obj->format('Y-m-d') . ' ' . $end_time_raw,
    ]);
}

if ($end_at_obj <= $start_at_obj) {
    $abort('danger', 'เวลาไม่ถูกต้อง', 'เวลาเดินทางสิ้นสุดต้องมากกว่าเวลาเริ่มต้น', 'datetime_range_invalid', [
        'startAt' => $start_at_obj->format('Y-m-d H:i:s'),
        'endAt' => $end_at_obj->format('Y-m-d H:i:s'),
    ]);
}

$start_at = $start_at_obj->format('Y-m-d H:i:s');
$end_at = $end_at_obj->format('Y-m-d H:i:s');

$fuel_source = trim((string) ($_POST['fuelSource'] ?? ''));
$allowed_fuel_sources = ['central', 'project', 'user'];

if (!in_array($fuel_source, $allowed_fuel_sources, true)) {
    $abort('danger', 'ข้อมูลไม่ถูกต้อง', 'กรุณาเลือกแหล่งน้ำมันเชื้อเพลิง', 'fuel_source_invalid', [
        'fuelSource' => $fuel_source,
    ]);
}

$companion_ids = $_POST['companionIds'] ?? [];

if (!is_array($companion_ids)) {
    $companion_ids = [];
}
$companion_ids = array_values(array_unique(array_filter(array_map(
    static fn ($id): string => trim((string) $id),
    $companion_ids
))));

$teacher_ids = [];

foreach (vehicle_reservation_get_teachers($connection) as $teacher) {
    $teacher_id = trim((string) ($teacher['id'] ?? ''));

    if ($teacher_id !== '') {
        $teacher_ids[$teacher_id] = true;
    }
}

$companion_ids = array_values(array_filter(
    $companion_ids,
    static fn (string $id): bool => isset($teacher_ids[$id])
));

$companion_count = count($companion_ids);
$other_passenger_count = filter_input(INPUT_POST, 'otherPassengerCount', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0],
]);
$other_passenger_count = $other_passenger_count !== false && $other_passenger_count !== null
    ? (int) $other_passenger_count
    : 0;
$other_passenger_names = trim((string) ($_POST['otherPassengerNames'] ?? ''));
$other_passenger_names = preg_replace("/\r\n|\r/", "\n", $other_passenger_names) ?? '';
$other_passenger_names = trim(preg_replace("/[ \t]+/", ' ', $other_passenger_names) ?? $other_passenger_names);

if ($other_passenger_count > 0 && $other_passenger_names === '') {
    $abort('warning', 'ข้อมูลไม่ครบถ้วน', 'กรุณาระบุรายชื่อบุคลากร', 'other_passenger_names_required', [
        'otherPassengerCount' => $other_passenger_count,
    ]);
}

if ($other_passenger_count === 0 && $other_passenger_names !== '') {
    $abort('warning', 'ข้อมูลไม่ครบถ้วน', 'กรุณาระบุจำนวนบุคลากรอื่นๆ', 'other_passenger_count_required');
}

$passenger_input = filter_input(INPUT_POST, 'passengerCount', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$passenger_input = $passenger_input ? (int) $passenger_input : 0;
$min_passengers = max(1, $companion_count + $other_passenger_count + 1);
$passenger_count = $passenger_input > 0 ? max($passenger_input, $min_passengers) : $min_passengers;

$companion_ids_json = null;

if ($companion_count > 0) {
    $encoded = json_encode($companion_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($encoded !== false) {
        $companion_ids_json = $encoded;
    }
}

$uploaded_files = [];
$attachments = $_FILES['attachments'] ?? null;
$max_attachments = 5;
$max_file_size = 10 * 1024 * 1024;
$allowed_mime = [
    'application/pdf' => ['pdf'],
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => ['png'],
    'application/zip' => ['zip'],
    'application/x-zip-compressed' => ['zip'],
    'application/x-rar-compressed' => ['rar'],
    'application/x-rar' => ['rar'],
    'application/vnd.rar' => ['rar'],
];
$cleanup_uploads = static function (array $files): void {
    foreach ($files as $file) {
        $path = __DIR__ . '/../../../' . ($file['filePath'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
};

if (is_array($attachments) && isset($attachments['name']) && is_array($attachments['name'])) {
    $total_files = count($attachments['name']);
    $valid_files = 0;

    for ($i = 0; $i < $total_files; $i++) {
        if (($attachments['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $valid_files++;
    }

    if ($valid_files > $max_attachments) {
        $abort('warning', 'แนบไฟล์เกินจำนวนที่กำหนด', 'แนบไฟล์ได้สูงสุด 5 ไฟล์', 'attachments_too_many', [
            'max' => $max_attachments,
            'count' => $valid_files,
        ]);
    }

    $finfo = null;

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
    }

    $upload_dir = __DIR__ . '/../../../assets/uploads/vehicle-bookings';

    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        error_log('Upload directory create failed: ' . $upload_dir);
        $abort('danger', 'ระบบขัดข้อง', 'ไม่สามารถแนบไฟล์ได้ในขณะนี้', 'upload_dir_create_failed');
    }

    for ($i = 0; $i < $total_files; $i++) {
        $error = $attachments['error'][$i] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            $cleanup_uploads($uploaded_files);
            $abort('danger', 'แนบไฟล์ไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง', 'upload_error', [
                'errorCode' => $error,
            ]);
        }

        $size = (int) ($attachments['size'][$i] ?? 0);

        if ($size > $max_file_size) {
            $cleanup_uploads($uploaded_files);
            $abort('warning', 'ไฟล์มีขนาดใหญ่เกินไป', 'รองรับไฟล์ขนาดไม่เกิน 10MB ต่อไฟล์', 'file_too_large', [
                'fileSize' => $size,
                'maxSize' => $max_file_size,
            ]);
        }

        $tmp_name = $attachments['tmp_name'][$i] ?? '';
        $file_mime = '';

        if ($finfo && $tmp_name !== '') {
            $file_mime = (string) $finfo->file($tmp_name);
        }

        if ($file_mime === '') {
            $file_mime = (string) ($attachments['type'][$i] ?? '');
        }

        $original_extension = strtolower(pathinfo((string) ($attachments['name'][$i] ?? ''), PATHINFO_EXTENSION));

        if ($file_mime === 'application/octet-stream' && in_array($original_extension, ['zip', 'rar'], true)) {
            $file_mime = $original_extension === 'zip' ? 'application/zip' : 'application/vnd.rar';
        }

        if (!isset($allowed_mime[$file_mime]) || !in_array($original_extension, $allowed_mime[$file_mime], true)) {
            $cleanup_uploads($uploaded_files);
            $abort('warning', 'รูปแบบไฟล์ไม่ถูกต้อง', 'รองรับเฉพาะไฟล์ .pdf, .jpg, .png, .zip, .rar', 'mime_not_allowed', [
                'mimeType' => $file_mime,
            ]);
        }

        $extension = $original_extension;
        $filename = 'vehicle_booking_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target_path = $upload_dir . '/' . $filename;

        if (!move_uploaded_file($tmp_name, $target_path)) {
            $cleanup_uploads($uploaded_files);
            $abort('danger', 'ระบบขัดข้อง', 'ไม่สามารถแนบไฟล์ได้ในขณะนี้', 'move_uploaded_file_failed');
        }

        @chmod($target_path, 0644);

        $uploaded_files[] = [
            'fileName' => $attachments['name'][$i] ?? $filename,
            'filePath' => 'assets/uploads/vehicle-bookings/' . $filename,
            'mimeType' => $file_mime,
            'fileSize' => $size,
            'checksumSHA256' => hash_file('sha256', $target_path) ?: null,
        ];
    }
}

$vehicle_columns = vehicle_reservation_ensure_other_passenger_columns($connection);

$columns = ['dh_year', 'requesterPID', 'startAt', 'endAt', 'status'];
$placeholders = ['?', '?', '?', '?', '?'];
$values = [$vehicle_year, $requester_pid, $start_at, $end_at, 'PENDING'];
$types = 'issss';

$add_param = static function (string $column, string $type, $value) use (
    &$columns,
    &$placeholders,
    &$values,
    &$types,
    $vehicle_columns
): void {
    if (!vehicle_reservation_has_column($vehicle_columns, $column)) {
        return;
    }
    $columns[] = $column;
    $placeholders[] = '?';
    $values[] = $value;
    $types .= $type;
};

$add_param('department', 's', $department);
$add_param('purpose', 's', $purpose);
$add_param('location', 's', $location);
$add_param('passengerCount', 'i', $passenger_count);
$add_param('fuelSource', 's', $fuel_source);

if ($write_date !== '') {
    $add_param('writeDate', 's', $write_date);
}
$add_param('companionCount', 'i', $companion_count);
$add_param('otherPassengerCount', 'i', $other_passenger_count);
$add_param('otherPassengerNames', 's', $other_passenger_names);

if ($companion_ids_json !== null) {
    $add_param('companionIds', 's', $companion_ids_json);
}

$requester_display_name = trim((string) ($teacher_name ?? ''));

if ($requester_display_name !== '') {
    $add_param('requesterDisplayName', 's', $requester_display_name);
}

$insert_sql = 'INSERT INTO dh_vehicle_bookings (' . implode(', ', $columns) . ')
    VALUES (' . implode(', ', $placeholders) . ')';

if (mysqli_begin_transaction($connection) === false) {
    error_log('Database Error: ' . mysqli_error($connection));
    $cleanup_uploads($uploaded_files);
    $abort('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกคำขอจองได้ในขณะนี้', 'db_begin_failed');
}

try {
    $insert_stmt = mysqli_prepare($connection, $insert_sql);

    if ($insert_stmt === false) {
        throw new RuntimeException('Failed to prepare booking insert.');
    }

    $bind_params = array_merge([$insert_stmt, $types], $values);
    $bind_refs = [];

    foreach ($bind_params as $index => $value) {
        $bind_refs[$index] = &$bind_params[$index];
    }

    call_user_func_array('mysqli_stmt_bind_param', $bind_refs);

    if (mysqli_stmt_execute($insert_stmt) === false) {
        mysqli_stmt_close($insert_stmt);
        throw new RuntimeException('Failed to insert booking.');
    }

    $booking_id = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($insert_stmt);

    $attachment_file_ids = [];

    foreach ($uploaded_files as $uploaded_file) {
        $file_sql = 'INSERT INTO dh_files (fileName, filePath, mimeType, fileSize, checksumSHA256, storageProvider, version, uploadedByPID)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $file_stmt = mysqli_prepare($connection, $file_sql);

        if ($file_stmt === false) {
            throw new RuntimeException('Failed to prepare file insert.');
        }

        $storage_provider = 'local';
        $version = 1;
        mysqli_stmt_bind_param(
            $file_stmt,
            'sssissis',
            $uploaded_file['fileName'],
            $uploaded_file['filePath'],
            $uploaded_file['mimeType'],
            $uploaded_file['fileSize'],
            $uploaded_file['checksumSHA256'],
            $storage_provider,
            $version,
            $requester_pid
        );

        if (mysqli_stmt_execute($file_stmt) === false) {
            mysqli_stmt_close($file_stmt);
            throw new RuntimeException('Failed to insert file.');
        }

        $attachment_file_id = (int) mysqli_insert_id($connection);
        mysqli_stmt_close($file_stmt);
        $attachment_file_ids[] = $attachment_file_id;

        $ref_sql = 'INSERT INTO dh_file_refs (fileID, moduleName, entityName, entityID, note, attachedByPID)
            VALUES (?, ?, ?, ?, ?, ?)';
        $ref_stmt = mysqli_prepare($connection, $ref_sql);

        if ($ref_stmt === false) {
            throw new RuntimeException('Failed to prepare file reference insert.');
        }

        $module_name = 'vehicle';
        $entity_name = 'dh_vehicle_bookings';
        $entity_id = (string) $booking_id;
        $note = 'vehicle_reservation_attachment';
        mysqli_stmt_bind_param($ref_stmt, 'isssss', $attachment_file_id, $module_name, $entity_name, $entity_id, $note, $requester_pid);

        if (mysqli_stmt_execute($ref_stmt) === false) {
            mysqli_stmt_close($ref_stmt);
            throw new RuntimeException('Failed to insert file reference.');
        }
        mysqli_stmt_close($ref_stmt);
    }

    if (!empty($attachment_file_ids) && vehicle_reservation_has_column($vehicle_columns, 'attachmentFileID')) {
        $update_sql = 'UPDATE dh_vehicle_bookings SET attachmentFileID = ? WHERE bookingID = ?';
        $update_stmt = mysqli_prepare($connection, $update_sql);

        if ($update_stmt === false) {
            throw new RuntimeException('Failed to prepare attachment update.');
        }
        $first_attachment_id = (int) $attachment_file_ids[0];
        mysqli_stmt_bind_param($update_stmt, 'ii', $first_attachment_id, $booking_id);

        if (mysqli_stmt_execute($update_stmt) === false) {
            mysqli_stmt_close($update_stmt);
            throw new RuntimeException('Failed to update attachment reference.');
        }
        mysqli_stmt_close($update_stmt);
    }

    mysqli_commit($connection);
} catch (Throwable $e) {
    mysqli_rollback($connection);
    error_log('Vehicle Booking Error: ' . $e->getMessage());

    if (function_exists('audit_log')) {
        audit_log('vehicle', 'CREATE', 'FAIL', 'dh_vehicle_bookings', null, $e->getMessage());
    }

    $cleanup_uploads($uploaded_files);

    $set_vehicle_alert('danger', 'บันทึกไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

if (function_exists('audit_log')) {
    audit_log('vehicle', 'CREATE', 'SUCCESS', 'dh_vehicle_bookings', $booking_id, null, [
        'department' => $department,
        'writeDate' => $write_date !== '' ? $write_date : null,
        'purposePreview' => $text_preview($purpose, 120),
        'locationPreview' => $text_preview($location, 120),
        'startAt' => $start_at,
        'endAt' => $end_at,
        'fuelSource' => $fuel_source,
        'passengerCount' => $passenger_count,
        'companionCount' => $companion_count,
        'otherPassengerCount' => $other_passenger_count,
        'attachmentCount' => count($uploaded_files),
    ]);
}
$set_vehicle_alert('success', 'ส่งคำขอจองเรียบร้อยแล้ว', 'ระบบจะส่งคำขอให้ผู้ดูแลพิจารณา');
header('Location: ' . $redirect_url, true, 303);
exit();
