<?php

declare(strict_types=1);

require_once __DIR__ . '/../../db/db.php';
require_once __DIR__ . '/../../../src/Services/room/room-booking-utils.php';
require_once __DIR__ . '/../vehicle/calendar.php';

if (!function_exists('dashboard_zero_counts')) {
    function dashboard_zero_counts(): array
    {
        return [
            'unread_circulars' => 0,
            'unread_external_circulars' => 0,
            'unread_internal_circulars' => 0,
            'unread_memos' => 0,
            'unread_orders' => 0,
            'unread_vehicle_bookings' => 0,
            'vehicle_notifications' => 0,
            'external_circular_notifications' => 0,
            'room_notifications' => 0,
            'repair_notifications' => 0,
            'pending_manager' => 0,
            'pending_approvals' => 0,
        ];
    }
}

if (!function_exists('dashboard_count_unread_circulars_by_type')) {
    function dashboard_count_unread_circulars_by_type(mysqli $connection, string $pID, string $circularType): int
    {
        if (
            !db_table_exists($connection, 'dh_circular_inboxes')
            || !db_table_exists($connection, 'dh_circulars')
            || !db_column_exists($connection, 'dh_circulars', 'circularType')
        ) {
            return 0;
        }

        $row = db_fetch_one(
            'SELECT COUNT(*) AS total
            FROM dh_circular_inboxes AS i
            INNER JOIN dh_circulars AS c ON c.circularID = i.circularID
            WHERE i.pID = ?
                AND i.isRead = 0
                AND i.isArchived = 0
                AND UPPER(c.circularType) = ?',
            'ss',
            $pID,
            strtoupper($circularType)
        );

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_external_circular_notifications')) {
    function dashboard_count_external_circular_notifications(mysqli $connection, string $pID, array $access, string $directorInboxType = 'special_principal_inbox'): int
    {
        $pID = trim($pID);

        if (
            $pID === ''
            || !db_table_exists($connection, 'dh_circular_inboxes')
            || !db_table_exists($connection, 'dh_circulars')
            || !db_column_exists($connection, 'dh_circulars', 'circularType')
            || !db_column_exists($connection, 'dh_circulars', 'status')
        ) {
            return 0;
        }

        $role_conditions = [];
        $types = 'ss';
        $params = [$pID, 'EXTERNAL'];

        if (!empty($access['can_manage_external_circular']) || !empty($access['is_registry_user']) || !empty($access['is_admin_user'])) {
            $role_conditions[] = '(i.inboxType = ? AND c.status = ?)';
            $types .= 'ss';
            $params[] = 'normal_inbox';
            $params[] = 'EXTERNAL_PENDING_REVIEW';
        }

        if (!empty($access['is_director_or_acting'])) {
            $role_conditions[] = '(i.inboxType = ? AND c.status = ?)';
            $types .= 'ss';
            $params[] = $directorInboxType !== '' ? $directorInboxType : 'special_principal_inbox';
            $params[] = 'EXTERNAL_PENDING_REVIEW';
        }

        if ($role_conditions === []) {
            return 0;
        }

        $row = db_fetch_one(
            'SELECT COUNT(*) AS total
            FROM dh_circular_inboxes AS i
            INNER JOIN dh_circulars AS c ON c.circularID = i.circularID
            WHERE i.pID = ?
                AND UPPER(c.circularType) = ?
                AND i.isArchived = 0
                AND (' . implode(' OR ', $role_conditions) . ')',
            $types,
            ...$params
        );

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_unread_memos')) {
    function dashboard_count_unread_memos(mysqli $connection, string $pID): int
    {
        if (
            !db_table_exists($connection, 'dh_memos')
            || !db_column_exists($connection, 'dh_memos', 'firstReadAt')
            || !db_column_exists($connection, 'dh_memos', 'toPID')
        ) {
            return 0;
        }

        $where = 'toPID = ? AND createdByPID <> ? AND firstReadAt IS NULL';
        $types = 'ss';
        $params = [$pID, $pID];

        if (db_column_exists($connection, 'dh_memos', 'deletedAt')) {
            $where .= ' AND deletedAt IS NULL';
        }

        if (db_column_exists($connection, 'dh_memos', 'isArchived')) {
            $where .= ' AND isArchived = 0';
        }

        if (db_column_exists($connection, 'dh_memos', 'submittedAt')) {
            $where .= ' AND (submittedAt IS NOT NULL OR status IN ("SUBMITTED","IN_REVIEW","APPROVED_UNSIGNED"))';
        }

        $where .= ' AND status IN ("SUBMITTED","IN_REVIEW","APPROVED_UNSIGNED")';

        $row = db_fetch_one(
            'SELECT COUNT(*) AS total FROM dh_memos WHERE ' . $where,
            $types,
            ...$params
        );

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_unread_orders')) {
    function dashboard_count_unread_orders(mysqli $connection, string $pID): int
    {
        if (!db_table_exists($connection, 'dh_order_inboxes')) {
            return 0;
        }

        $row = db_fetch_one(
            'SELECT COUNT(*) AS total FROM dh_order_inboxes WHERE pID = ? AND isRead = 0 AND isArchived = 0',
            's',
            $pID
        );

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_room_notifications')) {
    function dashboard_count_room_notifications(mysqli $connection, array $access): int
    {
        if (!empty($access['is_admin_user']) || empty($access['is_facility_user'])) {
            return 0;
        }

        if (!db_table_exists($connection, 'dh_room_bookings')) {
            return 0;
        }

        $pending_status = room_booking_status_to_db($connection, 0);
        $where = 'status = ?';

        if (db_column_exists($connection, 'dh_room_bookings', 'deletedAt')) {
            $where .= ' AND deletedAt IS NULL';
        }

        $row = db_fetch_one(
            'SELECT COUNT(*) AS total FROM dh_room_bookings WHERE ' . $where,
            $pending_status['type'],
            $pending_status['value']
        );

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_vehicle_notifications')) {
    function dashboard_count_vehicle_notifications(mysqli $connection, array $access, string $pID = ''): int
    {
        if (!db_table_exists($connection, 'dh_vehicle_bookings')) {
            return 0;
        }

        if (!empty($access['is_admin_user'])) {
            return 0;
        }

        $conditions = [];
        $types = '';
        $params = [];

        // Vehicle officers should be notified only when a requester has just submitted a booking.
        if (!empty($access['is_vehicle_user'])) {
            $conditions[] = 'status = ?';
            $types .= 's';
            $params[] = 'PENDING';
        }

        // Deputies/acting executives should be notified only for bookings assigned to them.
        if (!empty($access['is_vehicle_final_approver'])) {
            if ($pID !== '' && db_column_exists($connection, 'dh_vehicle_bookings', 'finalApproverPID')) {
                $conditions[] = "(status = ? AND (finalApproverPID = ? OR finalApproverPID IS NULL OR finalApproverPID = ''))";
                $types .= 'ss';
                $params[] = 'ASSIGNED';
                $params[] = $pID;
            } else {
                $conditions[] = 'status = ?';
                $types .= 's';
                $params[] = 'ASSIGNED';
            }
        }

        if ($conditions === []) {
            return 0;
        }

        $where = '(' . implode(' OR ', $conditions) . ')';

        if (db_column_exists($connection, 'dh_vehicle_bookings', 'deletedAt')) {
            $where .= ' AND deletedAt IS NULL';
        }

        $row = db_fetch_one(
            'SELECT COUNT(*) AS total FROM dh_vehicle_bookings WHERE ' . $where,
            $types,
            ...$params
        );

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_repair_notifications')) {
    function dashboard_count_repair_notifications(mysqli $connection, array $access): int
    {
        if (!db_table_exists($connection, 'dh_repair_requests')) {
            return 0;
        }

        if (!empty($access['is_admin_user']) || empty($access['is_repair_staff_user'])) {
            return 0;
        }

        $where = "status IN ('PENDING', 'IN_PROGRESS')";

        if (db_column_exists($connection, 'dh_repair_requests', 'deletedAt')) {
            $where .= ' AND deletedAt IS NULL';
        }

        $row = db_fetch_one('SELECT COUNT(*) AS total FROM dh_repair_requests WHERE ' . $where);

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('dashboard_count_pending_bookings')) {
    function dashboard_count_pending_bookings(mysqli $connection): int
    {
        $pending_room = 0;
        $pending_vehicle = 0;

        if (db_table_exists($connection, 'dh_room_bookings')) {
            $row = db_fetch_one("SELECT COUNT(*) AS total FROM dh_room_bookings WHERE status = 'PENDING' AND deletedAt IS NULL");
            $pending_room = (int) ($row['total'] ?? 0);
        }

        if (db_table_exists($connection, 'dh_vehicle_bookings')) {
            $row = db_fetch_one("SELECT COUNT(*) AS total FROM dh_vehicle_bookings WHERE status = 'PENDING' AND deletedAt IS NULL");
            $pending_vehicle = (int) ($row['total'] ?? 0);
        }

        return $pending_room + $pending_vehicle;
    }
}

if (!function_exists('dashboard_merge_calendar_events')) {
    function dashboard_merge_calendar_events(array $base, array $incoming): array
    {
        foreach ($incoming as $date_key => $events) {
            if (!isset($base[$date_key])) {
                $base[$date_key] = [];
            }

            $base[$date_key] = array_merge((array) $base[$date_key], (array) $events);
        }

        ksort($base);

        return $base;
    }
}

if (!function_exists('dashboard_room_calendar_events')) {
    function dashboard_room_calendar_events(mysqli $connection, int $dh_year): array
    {
        if (!db_table_exists($connection, 'dh_room_bookings')) {
            return [];
        }

        $columns = room_booking_get_table_columns($connection, 'dh_room_bookings');
        $select_fields = [
            'b.roomBookingID',
            'b.requesterPID',
            'b.roomID',
            'b.startDate',
            'b.endDate',
            'b.startTime',
            'b.endTime',
            'b.attendeeCount',
            'b.status',
            'b.createdAt',
        ];
        $optional_columns = [
            'dh_year',
            'bookingTopic',
            'bookingDetail',
            'requesterDisplayName',
            'deletedAt',
        ];

        foreach ($optional_columns as $column) {
            if (room_booking_has_column($columns, $column)) {
                $select_fields[] = 'b.' . $column;
            }
        }

        $select_fields[] = 'req.fName AS requester_name';
        $where = [];
        $types = '';
        $params = [];

        if (room_booking_has_column($columns, 'deletedAt')) {
            $where[] = 'b.deletedAt IS NULL';
        }

        if (room_booking_has_column($columns, 'dh_year')) {
            $where[] = 'b.dh_year = ?';
            $types .= 'i';
            $params[] = $dh_year;
        }

        $sql = 'SELECT ' . implode(', ', array_values(array_unique($select_fields))) . '
            FROM dh_room_bookings AS b
            LEFT JOIN teacher AS req ON b.requesterPID = req.pID';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY b.startDate ASC, b.startTime ASC, b.roomBookingID ASC';

        try {
            $rows = db_fetch_all($sql, $types, ...$params);
        } catch (Throwable $exception) {
            error_log('Dashboard room calendar events failed: ' . $exception->getMessage());

            return [];
        }

        $bookings = [];

        foreach ($rows as $row) {
            $status = room_booking_status_to_int($connection, $row['status'] ?? 0);

            if ($status !== 1) {
                continue;
            }

            $requester_name = trim((string) ($row['requester_name'] ?? ''));

            if ($requester_name === '') {
                $requester_name = trim((string) ($row['requesterDisplayName'] ?? ''));
            }

            $row['status'] = $status;
            $row['requesterName'] = $requester_name !== '' ? $requester_name : '-';
            $row['startTime'] = room_booking_normalize_time($row['startTime'] ?? '');
            $row['endTime'] = room_booking_normalize_time($row['endTime'] ?? '');

            if (empty($row['endDate'])) {
                $row['endDate'] = $row['startDate'];
            }

            $bookings[] = $row;
        }

        return room_booking_build_events($bookings, room_booking_get_room_map($connection));
    }
}

if (!function_exists('dashboard_calendar_events')) {
    function dashboard_calendar_events(int $dh_year): array
    {
        $connection = db_connection();
        $events = dashboard_room_calendar_events($connection, $dh_year);

        try {
            $events = dashboard_merge_calendar_events($events, vehicle_booking_events($dh_year));
        } catch (Throwable $exception) {
            error_log('Dashboard vehicle calendar events failed: ' . $exception->getMessage());
        }

        return $events;
    }
}

if (!function_exists('dashboard_counts')) {
    function dashboard_counts(string $pID, array $access = []): array
    {
        $pID = trim($pID);
        $counts = dashboard_zero_counts();

        if ($pID === '') {
            return $counts;
        }

        $connection = db_connection();

        $counts['unread_external_circulars'] = dashboard_count_unread_circulars_by_type($connection, $pID, 'EXTERNAL');
        $counts['unread_internal_circulars'] = dashboard_count_unread_circulars_by_type($connection, $pID, 'INTERNAL');
        $counts['unread_circulars'] = $counts['unread_external_circulars'] + $counts['unread_internal_circulars'];
        $counts['unread_memos'] = dashboard_count_unread_memos($connection, $pID);
        $counts['unread_orders'] = dashboard_count_unread_orders($connection, $pID);
        $counts['external_circular_notifications'] = dashboard_count_external_circular_notifications($connection, $pID, $access);
        $counts['room_notifications'] = dashboard_count_room_notifications($connection, $access);
        $counts['vehicle_notifications'] = dashboard_count_vehicle_notifications($connection, $access, $pID);
        $counts['unread_vehicle_bookings'] = $counts['vehicle_notifications'];
        $counts['repair_notifications'] = dashboard_count_repair_notifications($connection, $access);
        $counts['pending_manager'] = dashboard_count_pending_bookings($connection);
        $counts['pending_approvals'] = $counts['pending_manager'];

        return $counts;
    }
}
