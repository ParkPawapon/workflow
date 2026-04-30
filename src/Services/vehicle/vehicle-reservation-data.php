<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/connection.php';
require_once __DIR__ . '/vehicle-reservation-utils.php';

function vehicle_reservation_get_departments(mysqli $connection): array
{
    $departments = [];
    $sql = 'SELECT dID, dName FROM department WHERE dID != 12 ORDER BY dName ASC';

    try {
        $result = mysqli_query($connection, $sql);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $departments[] = [
                    'id' => $row['dID'],
                    'name' => $row['dName'],
                    'type' => 'department'
                ];
            }
            mysqli_free_result($result);
        }
    } catch (Exception $e) {
        error_log('Error fetching departments: ' . $e->getMessage());
    }

    return $departments;
}

function vehicle_reservation_get_factions(mysqli $connection): array
{
    $factions = [];
    $sql = 'SELECT fID, fName FROM faction ORDER BY fName ASC';

    try {
        $result = mysqli_query($connection, $sql);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $factions[] = [
                    'id' => $row['fID'],
                    'name' => $row['fName'],
                    'type' => 'faction'
                ];
            }
            mysqli_free_result($result);
        }
    } catch (Exception $e) {
        error_log('Error fetching factions: ' . $e->getMessage());
    }

    return $factions;
}

function vehicle_reservation_get_teachers(mysqli $connection): array
{
    $teachers = [];
    $sql = 'SELECT pID, fName, picture FROM teacher WHERE status = 1 ORDER BY fName ASC';

    try {
        $result = mysqli_query($connection, $sql);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $teachers[] = [
                    'id' => $row['pID'],
                    'name' => trim($row['fName']),
                    'picture' => $row['picture'] ?? null
                ];
            }
            mysqli_free_result($result);
        }
    } catch (Exception $e) {
        error_log('Error fetching teachers: ' . $e->getMessage());
    }

    return $teachers;
}

function vehicle_reservation_get_bookings(mysqli $connection, int $year, string $requester_pid): array
{
    $requester_pid = trim($requester_pid);

    if ($requester_pid === '') {
        return [];
    }

    if ($year <= 0) {
        $year = (int) date('Y') + 543;
    }

    $columns = vehicle_reservation_ensure_other_passenger_columns($connection);
    $select_fields = [
        'b.bookingID',
        'b.dh_year',
        'b.requesterPID',
        'b.vehicleID',
        'b.driverPID',
        'b.driverName',
        vehicle_reservation_has_column($columns, 'driverTel')
            ? "COALESCE(NULLIF(drv.telephone, ''), b.driverTel) AS driverTel"
            : 'drv.telephone AS driverTel',
        'b.startAt',
        'b.endAt',
        'b.status',
        'b.statusReason',
        'b.approvedByPID',
        'b.approvedAt',
        'b.deletedAt',
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
        'companionCount',
        'otherPassengerCount',
        'otherPassengerNames',
        'companionIds',
        'requesterDisplayName',
        'attachmentFileID',
        'assignedByPID',
        'assignedAt',
        'assignedNote',
        'finalApproverPID',
        'approvalNote',
    ];

    foreach ($optional_columns as $column) {
        if (vehicle_reservation_has_column($columns, $column)) {
            $select_fields[] = 'b.' . $column;
        }
    }

    $select_fields[] = 'app.fName AS approver_name';
    $assigned_join = '';

    if (vehicle_reservation_has_column($columns, 'assignedByPID')) {
        $select_fields[] = 'asg.fName AS assigned_name';
        $assigned_join = 'LEFT JOIN teacher AS asg ON b.assignedByPID = asg.pID';
    } else {
        $select_fields[] = "'' AS assigned_name";
    }

    $select_fields[] = 'v.vehiclePlate';
    $select_fields[] = 'v.vehicleType';
    $select_fields[] = 'v.vehicleBrand';
    $select_fields[] = 'v.vehicleModel';

    $sql = 'SELECT ' . implode(', ', $select_fields) . ' FROM dh_vehicle_bookings AS b
        LEFT JOIN teacher AS drv ON b.driverPID = drv.pID
        LEFT JOIN teacher AS app ON b.approvedByPID = app.pID
        ' . $assigned_join . '
        LEFT JOIN dh_vehicles AS v ON b.vehicleID = v.vehicleID
        WHERE b.deletedAt IS NULL AND b.dh_year = ? AND b.requesterPID = ?
        ORDER BY b.createdAt DESC, b.bookingID DESC';

    $bookings = [];
    $stmt = mysqli_prepare($connection, $sql);

    if ($stmt === false) {
        error_log('Database Error: ' . mysqli_error($connection));

        return [];
    }

    mysqli_stmt_bind_param($stmt, 'is', $year, $requester_pid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['status'] = strtoupper(trim((string) ($row['status'] ?? 'PENDING')));
            $row['approverName'] = trim((string) ($row['approver_name'] ?? ''));
            $bookings[] = $row;
        }
    }

    mysqli_stmt_close($stmt);

    return $bookings;
}

function vehicle_reservation_get_booking_attachments(mysqli $connection, array $booking_ids): array
{
    $booking_ids = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => preg_replace('/\D+/', '', (string) $id),
        $booking_ids
    ))));

    if (empty($booking_ids)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($booking_ids), '?'));
    $sql = 'SELECT r.entityID, f.fileID, f.fileName, f.filePath, f.mimeType, f.fileSize
        FROM dh_file_refs AS r
        INNER JOIN dh_files AS f ON r.fileID = f.fileID
        WHERE r.moduleName = ? AND r.entityName = ? AND r.entityID IN (' . $placeholders . ')
            AND f.deletedAt IS NULL
        ORDER BY r.refID ASC';

    $stmt = mysqli_prepare($connection, $sql);

    if ($stmt === false) {
        error_log('Database Error: ' . mysqli_error($connection));

        return [];
    }

    $module_name = 'vehicle';
    $entity_name = 'dh_vehicle_bookings';
    $types = 'ss' . str_repeat('s', count($booking_ids));
    $params = array_merge([$stmt, $types, $module_name, $entity_name], $booking_ids);
    $bind_refs = [];

    foreach ($params as $index => $value) {
        $bind_refs[$index] = &$params[$index];
    }

    try {
        call_user_func_array('mysqli_stmt_bind_param', $bind_refs);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } catch (mysqli_sql_exception $exception) {
        mysqli_stmt_close($stmt);
        error_log('Database Exception: ' . $exception->getMessage());

        return [];
    }

    $attachments = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $booking_id = (string) ($row['entityID'] ?? '');

            if ($booking_id === '') {
                continue;
            }

            if (!isset($attachments[$booking_id])) {
                $attachments[$booking_id] = [];
            }
            $attachments[$booking_id][] = [
                'fileID' => (int) ($row['fileID'] ?? 0),
                'fileName' => (string) ($row['fileName'] ?? ''),
                'filePath' => (string) ($row['filePath'] ?? ''),
                'mimeType' => (string) ($row['mimeType'] ?? ''),
                'fileSize' => (int) ($row['fileSize'] ?? 0),
            ];
        }
    }

    mysqli_stmt_close($stmt);

    return $attachments;
}
