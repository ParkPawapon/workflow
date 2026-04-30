<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db/db.php';
require_once __DIR__ . '/../app/modules/system/system.php';
require_once __DIR__ . '/../app/modules/outgoing/repository.php';
require_once __DIR__ . '/../app/modules/circulars/repository.php';
require_once __DIR__ . '/../app/modules/memos/repository.php';
require_once __DIR__ . '/../app/modules/memos/service.php';
require_once __DIR__ . '/../app/modules/orders/repository.php';
require_once __DIR__ . '/../app/modules/orders/service.php';
require_once __DIR__ . '/../app/modules/repairs/service.php';
require_once __DIR__ . '/../app/modules/vehicle/calendar.php';
require_once __DIR__ . '/../src/Services/room/room-booking-utils.php';
require_once __DIR__ . '/testing/BaselineTestRunner.php';

app_bootstrap();

$runner = new BaselineTestRunner();
$connection = db_connection();

$runner->run('runtime: database and critical tables are available', static function (BaselineTestRunner $t) use ($connection): void {
    $t->assertTrue($connection instanceof mysqli, 'Expected an active mysqli connection');

    $required_tables = [
        'teacher',
        'thesystem',
        'dh_circulars',
        'dh_circular_inboxes',
        'dh_memos',
        'dh_orders',
        'dh_outgoing_letters',
        'dh_room_bookings',
        'dh_vehicle_bookings',
        'dh_repair_requests',
        'dh_files',
        'dh_file_refs',
    ];

    foreach ($required_tables as $table) {
        $t->assertTrue(db_table_exists($connection, $table), 'Missing required table ' . $table);
    }

    $upload_root = rtrim((string) app_env('UPLOAD_ROOT', __DIR__ . '/../storage/uploads'), '/');
    $t->assertTrue(is_dir($upload_root), 'Upload root directory is missing');
    $t->assertTrue(is_writable($upload_root), 'Upload root directory is not writable');

    foreach (['mysqli', 'openssl', 'mbstring', 'json', 'fileinfo'] as $extension) {
        $t->assertTrue(extension_loaded($extension), 'Missing PHP extension ' . $extension);
    }

    $session_expectations = [
        'character_set_connection' => 'utf8mb4',
        'character_set_results' => 'utf8mb4',
        'character_set_client' => 'utf8mb4',
        'collation_connection' => 'utf8mb4_general_ci',
    ];

    foreach ($session_expectations as $name => $expected) {
        $result = mysqli_query($connection, 'SHOW VARIABLES LIKE "' . $name . '"');
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $actual = trim((string) ($row['Value'] ?? ''));
        $t->assertSame($expected, $actual, 'Database session variable drifted for ' . $name);
    }
});

$runner->run('workflow state machine keeps required transitions intact', static function (BaselineTestRunner $t): void {
    $machine = workflow_state_machine();

    $t->assertSame([INTERNAL_STATUS_SENT], $machine['internal'][INTERNAL_STATUS_DRAFT] ?? [], 'Internal draft transition changed');
    $t->assertSame([INTERNAL_STATUS_RECALLED, INTERNAL_STATUS_ARCHIVED], $machine['internal'][INTERNAL_STATUS_SENT] ?? [], 'Internal sent transition changed');
    $t->assertSame([OUTGOING_STATUS_COMPLETE], $machine['outgoing'][OUTGOING_STATUS_WAITING_ATTACHMENT] ?? [], 'Outgoing transition changed');
    $t->assertSame([REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_COMPLETED, REPAIR_STATUS_CANCELLED], $machine['repairs'][REPAIR_STATUS_PENDING] ?? [], 'Repair pending transition changed');
    $t->assertTrue(in_array(MEMO_STATUS_SIGNED, $machine['memos'][MEMO_STATUS_IN_REVIEW] ?? [], true), 'Memo review path no longer reaches signed state');
});

$runner->run('outgoing repository queries execute across mixed collations', static function (BaselineTestRunner $t): void {
    $rows = outgoing_list([]);
    $t->assertTrue(is_array($rows), 'Outgoing list did not return an array');

    foreach (array_slice($rows, 0, 10) as $row) {
        $t->assertArrayHasKey('outgoingID', $row, 'Outgoing row is missing outgoingID');
        $t->assertArrayHasKey('attachmentCount', $row, 'Outgoing row is missing attachmentCount');
        $t->assertTrue(((int) ($row['attachmentCount'] ?? -1)) >= 0, 'Outgoing attachmentCount must be numeric');
    }

    $ids = array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['outgoingID'] ?? 0);
    }, array_slice($rows, 0, 3))));

    if ($ids !== []) {
        $attachments_map = outgoing_list_attachments_map($ids);
        $t->assertTrue(is_array($attachments_map), 'Outgoing attachment map did not return an array');
        outgoing_get_attachments($ids[0]);
    }
});

$runner->run('circular sent listing keeps recipient and read counters consistent', static function (BaselineTestRunner $t): void {
    $sender_row = db_fetch_one(
        'SELECT createdByPID FROM dh_circulars WHERE deletedAt IS NULL AND createdByPID IS NOT NULL AND createdByPID <> "" ORDER BY circularID DESC LIMIT 1'
    );

    if (!$sender_row) {
        $t->skip('No circular sender data available');
    }

    $sender_pid = trim((string) ($sender_row['createdByPID'] ?? ''));

    if ($sender_pid === '') {
        $t->skip('Circular sender PID is empty');
    }

    $rows = circular_list_sent($sender_pid);
    $t->assertTrue(is_array($rows), 'Circular sent list did not return an array');

    foreach (array_slice($rows, 0, 20) as $row) {
        $recipient_count = (int) ($row['recipientCount'] ?? -1);
        $read_count = (int) ($row['readCount'] ?? -1);

        $t->assertTrue($recipient_count >= 0, 'Circular recipient count must be non-negative');
        $t->assertTrue($read_count >= 0, 'Circular read count must be non-negative');
        $t->assertTrue($read_count <= $recipient_count, 'Circular read count exceeded recipient count');
        $t->assertTrue(trim((string) ($row['status'] ?? '')) !== '', 'Circular status must not be empty');
        $t->assertTrue(trim((string) ($row['subject'] ?? '')) !== '', 'Circular subject must not be empty');
    }
});

$runner->run('memo repository preserves creator and reviewer visibility rules', static function (BaselineTestRunner $t): void {
    $allowed_reviewer_statuses = [
        MEMO_STATUS_SUBMITTED,
        MEMO_STATUS_IN_REVIEW,
        MEMO_STATUS_RETURNED,
        MEMO_STATUS_APPROVED_UNSIGNED,
        MEMO_STATUS_SIGNED,
        MEMO_STATUS_REJECTED,
    ];

    $creator_row = db_fetch_one(
        'SELECT createdByPID FROM dh_memos WHERE deletedAt IS NULL AND createdByPID IS NOT NULL AND createdByPID <> "" ORDER BY memoID DESC LIMIT 1'
    );

    if (!$creator_row) {
        $t->skip('No memo data available');
    }

    $creator_pid = trim((string) ($creator_row['createdByPID'] ?? ''));

    if ($creator_pid === '') {
        $t->skip('Memo creator PID is empty');
    }

    $creator_rows = memo_list_by_creator_page($creator_pid, false, 'all', '', 1000, 0, 'newest', null);
    $creator_count = memo_count_by_creator($creator_pid, false, 'all', '', null);
    $t->assertSame($creator_count, count($creator_rows), 'Memo creator count drifted from paged list size');

    foreach (array_slice($creator_rows, 0, 20) as $row) {
        $t->assertTrue(trim((string) ($row['subject'] ?? '')) !== '', 'Memo creator row is missing subject');
        $t->assertTrue(trim((string) ($row['status'] ?? '')) !== '', 'Memo creator row is missing status');
    }

    if ($creator_rows !== []) {
        $memo_id = (int) ($creator_rows[0]['memoID'] ?? 0);

        if ($memo_id > 0) {
            $memo = memo_get($memo_id);
            $t->assertTrue($memo !== null, 'Memo detail lookup failed');
            $attachments = memo_get_attachments($memo_id);
            $routes = memo_list_routes($memo_id);
            $t->assertTrue(is_array($attachments), 'Memo attachments lookup failed');
            $t->assertTrue(is_array($routes), 'Memo routes lookup failed');
        }
    }

    $reviewer_row = db_fetch_one(
        'SELECT toPID FROM dh_memos WHERE deletedAt IS NULL AND toPID IS NOT NULL AND toPID <> "" ORDER BY memoID DESC LIMIT 1'
    );

    if (!$reviewer_row) {
        return;
    }

    $reviewer_pid = trim((string) ($reviewer_row['toPID'] ?? ''));

    if ($reviewer_pid === '') {
        return;
    }

    $reviewer_rows = memo_list_by_reviewer_page($reviewer_pid, 'all', '', 100, 0, null);
    $reviewer_count = memo_count_by_reviewer($reviewer_pid, 'all', '', null);
    $t->assertTrue($reviewer_count >= count($reviewer_rows), 'Memo reviewer count is lower than reviewer page size');

    foreach (array_slice($reviewer_rows, 0, 20) as $row) {
        $memo_id = (int) ($row['memoID'] ?? 0);
        $memo = $memo_id > 0 ? memo_get($memo_id) : null;
        $t->assertTrue($memo !== null, 'Memo reviewer detail lookup failed');
        $t->assertSame($reviewer_pid, trim((string) ($memo['toPID'] ?? '')), 'Memo reviewer row leaked a different reviewer');
        $t->assertTrue(trim((string) ($memo['createdByPID'] ?? '')) !== $reviewer_pid, 'Memo reviewer inbox exposed self-created memo');
        $t->assertTrue(in_array((string) ($memo['status'] ?? ''), $allowed_reviewer_statuses, true), 'Memo reviewer inbox exposed an invalid workflow status');
    }

    foreach (memo_list_creator_years($creator_pid, false) as $year) {
        $t->assertTrue((int) $year >= 2568, 'Memo creator year list contains an invalid year');
    }

    foreach (memo_list_reviewer_years($reviewer_pid) as $year) {
        $t->assertTrue((int) $year >= 2568, 'Memo reviewer year list contains an invalid year');
    }
});

$runner->run('room booking uses approved status for conflicts and calendar events only', static function (BaselineTestRunner $t) use ($connection): void {
    $approved = room_booking_status_to_db($connection, 1);
    $conflict = room_booking_get_conflict_status_value($connection);

    $t->assertSame($approved['type'] ?? null, $conflict['type'] ?? null, 'Room conflict type drifted from approved status type');
    $t->assertSame((string) ($approved['value'] ?? ''), (string) ($conflict['value'] ?? ''), 'Room conflict status drifted from approved status');

    $events = room_booking_build_events([
        [
            'roomBookingID' => '100',
            'roomID' => 'R1',
            'bookingTopic' => 'Pending should not appear',
            'requesterName' => 'Requester A',
            'attendeeCount' => '5',
            'startDate' => '2026-03-27',
            'endDate' => '2026-03-27',
            'startTime' => '09:00:00',
            'endTime' => '10:00:00',
            'status' => 0,
        ],
        [
            'roomBookingID' => '101',
            'roomID' => 'R1',
            'bookingTopic' => 'Approved event',
            'requesterName' => 'Requester B',
            'attendeeCount' => '10',
            'startDate' => '2026-03-27',
            'endDate' => '2026-03-27',
            'startTime' => '10:00:00',
            'endTime' => '11:00:00',
            'status' => 1,
        ],
        [
            'roomBookingID' => '102',
            'roomID' => 'R1',
            'bookingTopic' => 'Rejected should not appear',
            'requesterName' => 'Requester C',
            'attendeeCount' => '2',
            'startDate' => '2026-03-27',
            'endDate' => '2026-03-27',
            'startTime' => '13:00:00',
            'endTime' => '14:00:00',
            'status' => 2,
        ],
    ], ['R1' => 'ห้องประชุมใหญ่']);

    $day_events = $events['2026-3-27'] ?? [];
    $t->assertSame(1, count($day_events), 'Room calendar should include approved bookings only');
    $t->assertSame('101', (string) ($day_events[0]['bookingId'] ?? ''), 'Room calendar kept the wrong booking');
    $t->assertSame(1, (int) ($day_events[0]['status'] ?? 0), 'Room calendar event status drifted from approved state');
});

$runner->run('repairs validation enforces required production fields', static function (BaselineTestRunner $t): void {
    $defaults = repair_form_defaults();
    $t->assertArrayHasKey('subject', $defaults, 'Repair defaults missing subject');
    $t->assertArrayHasKey('location', $defaults, 'Repair defaults missing location');
    $t->assertArrayHasKey('equipment', $defaults, 'Repair defaults missing equipment');
    $t->assertArrayHasKey('detail', $defaults, 'Repair defaults missing detail');

    $normalized = repair_normalize_form_data([
        'subject' => '  ระบบไฟเสีย  ',
        'location' => '  อาคาร 1  ',
        'equipment' => '  หลอดไฟ  ',
        'detail' => '  ตรวจสอบด่วน  ',
    ]);

    $t->assertSame('ระบบไฟเสีย', $normalized['subject'], 'Repair subject normalization failed');
    $t->assertSame('อาคาร 1', $normalized['location'], 'Repair location normalization failed');
    $t->assertSame('หลอดไฟ', $normalized['equipment'], 'Repair equipment normalization failed');
    $t->assertSame('ตรวจสอบด่วน', $normalized['detail'], 'Repair detail normalization failed');

    $invalid_cases = [
        ['subject' => '', 'location' => 'A', 'detail' => 'B'],
        ['subject' => 'A', 'location' => '', 'detail' => 'B'],
        ['subject' => 'A', 'location' => 'B', 'detail' => ''],
    ];

    foreach ($invalid_cases as $case) {
        $failed = false;

        try {
            repair_validate_create_data($case);
        } catch (RuntimeException $exception) {
            $failed = true;
        }

        $t->assertTrue($failed, 'Repair validation accepted an incomplete payload');
    }

    repair_validate_create_data([
        'subject' => 'ระบบไฟเสีย',
        'location' => 'อาคาร 1',
        'detail' => 'ตรวจสอบด่วน',
    ]);
});

$runner->run('orders repository keeps numbering and inbox counters consistent', static function (BaselineTestRunner $t): void {
    $year = system_get_dh_year();
    $seq_row = db_fetch_one(
        'SELECT MAX(orderSeq) AS maxSeq FROM dh_orders WHERE deletedAt IS NULL AND dh_year = ?',
        'i',
        $year
    );
    $expected_next_seq = ((int) ($seq_row['maxSeq'] ?? 0)) + 1;
    $t->assertSame($expected_next_seq . '/' . $year, order_preview_number($year), 'Order preview number drifted from current sequence');

    $creator_row = db_fetch_one(
        'SELECT createdByPID FROM dh_orders WHERE deletedAt IS NULL AND createdByPID IS NOT NULL AND createdByPID <> "" ORDER BY orderID DESC LIMIT 1'
    );

    if (!$creator_row) {
        $t->skip('No order data available');
    }

    $creator_pid = trim((string) ($creator_row['createdByPID'] ?? ''));

    if ($creator_pid === '') {
        $t->skip('Order creator PID is empty');
    }

    $draft_filters = [
        'status' => 'all',
        'q' => '',
        'date_from' => '',
        'date_to' => '',
        'sort' => 'newest',
    ];
    $draft_rows = order_list_drafts_page_filtered($creator_pid, $draft_filters, 1000, 0);
    $draft_counts = order_count_drafts_by_status($creator_pid, $draft_filters);

    $t->assertSame((int) ($draft_counts['all'] ?? 0), count($draft_rows), 'Order draft totals drifted from draft list size');
    $t->assertSame(
        (int) ($draft_counts['all'] ?? 0),
        (int) (($draft_counts['waiting'] ?? 0) + ($draft_counts['complete'] ?? 0) + ($draft_counts['sent'] ?? 0)),
        'Order draft status buckets do not add up to the total'
    );

    foreach (array_slice($draft_rows, 0, 20) as $row) {
        $recipient_count = (int) ($row['recipientCount'] ?? -1);
        $read_count = (int) ($row['readCount'] ?? -1);

        $t->assertTrue(trim((string) ($row['subject'] ?? '')) !== '', 'Order draft row is missing subject');
        $t->assertTrue(in_array((string) ($row['status'] ?? ''), [ORDER_STATUS_WAITING_ATTACHMENT, ORDER_STATUS_COMPLETE, ORDER_STATUS_SENT], true), 'Order draft row exposed an invalid status');
        $t->assertTrue($recipient_count >= 0, 'Order recipient count must be non-negative');
        $t->assertTrue($read_count >= 0, 'Order read count must be non-negative');
        $t->assertTrue($read_count <= $recipient_count, 'Order read count exceeded recipient count');
    }

    if ($draft_rows !== []) {
        $order_id = (int) ($draft_rows[0]['orderID'] ?? 0);

        if ($order_id > 0) {
            $order = order_get_for_owner($order_id, $creator_pid);
            $t->assertTrue($order !== null, 'Order owner lookup failed');
            $attachments = order_get_attachments($order_id);
            $routes = order_list_routes($order_id);
            $t->assertTrue(is_array($attachments), 'Order attachments lookup failed');
            $t->assertTrue(is_array($routes), 'Order routes lookup failed');
        }
    }

    $inbox_row = db_fetch_one('SELECT pID FROM dh_order_inboxes ORDER BY inboxID DESC LIMIT 1');

    if (!$inbox_row) {
        return;
    }

    $inbox_pid = trim((string) ($inbox_row['pID'] ?? ''));

    if ($inbox_pid === '') {
        return;
    }

    $summary = order_inbox_read_summary($inbox_pid, false, '', null);
    $inbox_count = order_count_inbox_filtered($inbox_pid, false, '', 'all', null);
    $inbox_rows = order_list_inbox_page_filtered($inbox_pid, false, '', 'all', 100, 0, 'newest', null);

    $t->assertSame($inbox_count, (int) ($summary['total'] ?? -1), 'Order inbox total drifted from read summary');
    $t->assertSame(
        (int) ($summary['total'] ?? -1),
        (int) (($summary['read'] ?? 0) + ($summary['unread'] ?? 0)),
        'Order inbox read and unread counters do not add up'
    );
    $t->assertTrue($inbox_count >= count($inbox_rows), 'Order inbox count is lower than inbox page size');

    foreach (array_slice($inbox_rows, 0, 20) as $row) {
        $t->assertTrue(((int) ($row['orderID'] ?? 0)) > 0, 'Order inbox row is missing orderID');
        $t->assertTrue(((int) ($row['inboxID'] ?? 0)) > 0, 'Order inbox row is missing inboxID');
        $t->assertTrue(trim((string) ($row['subject'] ?? '')) !== '', 'Order inbox row is missing subject');
    }
});

$runner->run('vehicle calendar publishes approved bookings only', static function (BaselineTestRunner $t) use ($connection): void {
    $year = system_get_dh_year();
    $row = db_fetch_one(
        'SELECT COUNT(*) AS total FROM dh_vehicle_bookings WHERE deletedAt IS NULL AND dh_year = ? AND status = ?',
        'is',
        $year,
        'APPROVED'
    );

    $approved_total = (int) ($row['total'] ?? 0);

    if ($approved_total === 0) {
        $t->skip('No approved vehicle bookings available for the current year');
    }

    $events = vehicle_booking_events($year);
    $t->assertTrue($events !== [], 'Vehicle calendar did not return any approved events');

    foreach ($events as $day => $day_events) {
        $t->assertTrue(is_array($day_events) && $day_events !== [], 'Vehicle calendar returned an empty day bucket for ' . $day);

        foreach ($day_events as $event) {
            $t->assertSame('APPROVED', (string) ($event['status'] ?? ''), 'Vehicle calendar leaked a non-approved booking');
            $t->assertTrue(trim((string) ($event['bookingId'] ?? '')) !== '', 'Vehicle calendar event is missing bookingId');
        }
    }
});

exit($runner->summary());
