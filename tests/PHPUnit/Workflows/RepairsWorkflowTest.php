<?php

declare(strict_types=1);

namespace Tests\PHPUnit\Workflows;

use RuntimeException;
use Tests\Support\WorkflowTestCase;

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'PHPUnit Repairs Workflow Bootstrap';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/phpunit/repairs-workflow/bootstrap';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['pID'])) {
    $bootstrapTeacher = db_fetch_one('SELECT pID FROM teacher WHERE status = 1 ORDER BY pID ASC LIMIT 1');

    if (is_array($bootstrapTeacher) && isset($bootstrapTeacher['pID'])) {
        $_SESSION['pID'] = (string) $bootstrapTeacher['pID'];
    }
}

$connection = db_connection();
$GLOBALS['connection'] = $GLOBALS['connection'] ?? $connection;

require_once dirname(__DIR__, 3) . '/app/controllers/repairs-controller.php';

final class RepairsWorkflowTest extends WorkflowTestCase
{
    private const AUDIT_USER_AGENT = 'PHPUnit Repairs Workflow Test';

    /** @var list<int> */
    private array $createdRepairIds = [];

    /** @var list<int> */
    private array $createdFileIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'CLI';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = self::AUDIT_USER_AGENT;
        $_SERVER['REQUEST_URI'] = '/phpunit/repairs-workflow';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['pID'] = $this->requireActiveTeacherPid('No active teacher available to bootstrap repairs controller');
    }

    protected function tearDown(): void
    {
        $this->cleanupAttachments();
        $this->cleanupRepairs();
        $this->cleanupAuditLogs();

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['pID']);
        }

        parent::tearDown();
    }

    public function testModeConfigurationsMatchReportApprovalAndManageRoles(): void
    {
        $report = repairs_mode_config('report');
        $approval = repairs_mode_config('approval');
        $manage = repairs_mode_config('manage');

        $this->assertTrue((bool) ($report['show_form'] ?? false));
        $this->assertFalse((bool) ($report['show_requester_column'] ?? true));
        $this->assertSame([], $report['statuses'] ?? null);

        $this->assertFalse((bool) ($approval['show_form'] ?? true));
        $this->assertTrue((bool) ($approval['show_requester_column'] ?? false));
        $this->assertSame([REPAIR_STATUS_PENDING], $approval['statuses'] ?? null);

        $this->assertFalse((bool) ($manage['show_form'] ?? true));
        $this->assertTrue((bool) ($manage['show_requester_column'] ?? false));
        $this->assertSame([], $manage['statuses'] ?? null);
    }

    public function testRoleFixturesMatchConfiguredRepairsRoles(): void
    {
        $connection = $this->connection();
        $adminPid = $this->requireRoleTeacherPid(ROLE_ADMIN, 'No active admin available for repairs workflow test');
        $facilityPid = $this->requireRoleTeacherPid(ROLE_FACILITY, 'No active facility officer available for repairs workflow test');
        $generalPid = $this->requireRoleTeacherPid(ROLE_GENERAL, 'No active general staff available for repairs workflow test');

        $this->assertTrue(rbac_user_has_role($connection, $adminPid, ROLE_ADMIN));
        $this->assertTrue(rbac_user_has_role($connection, $adminPid, ROLE_FACILITY));
        $this->assertTrue(rbac_user_has_role($connection, $adminPid, ROLE_VEHICLE));
        $this->assertTrue(rbac_user_has_any_role($connection, $adminPid, [ROLE_FACILITY]));
        $this->assertTrue(rbac_user_has_role($connection, $facilityPid, ROLE_FACILITY));
        $this->assertTrue(rbac_user_has_role($connection, $generalPid, ROLE_GENERAL));
        $this->assertFalse(rbac_user_has_role($connection, $generalPid, ROLE_ADMIN));
        $this->assertFalse(rbac_user_has_role($connection, $generalPid, ROLE_FACILITY));
    }

    public function testStatusHelpersAndTransitionMatrixStayConsistent(): void
    {
        $statusMap = repairs_status_map();
        $filters = repairs_track_status_filters();

        $this->assertSame('ส่งคำร้องสำเร็จ', $statusMap[REPAIR_STATUS_PENDING]['label'] ?? null);
        $this->assertSame('กำลังดำเนินการ', $statusMap[REPAIR_STATUS_IN_PROGRESS]['label'] ?? null);
        $this->assertSame('ดำเนินการเสร็จสิ้น', $statusMap[REPAIR_STATUS_COMPLETED]['label'] ?? null);
        $this->assertSame('ยกเลิกคำร้อง', $statusMap[REPAIR_STATUS_CANCELLED]['label'] ?? null);
        $this->assertSame('ยกเลิกคำร้อง', $statusMap[REPAIR_STATUS_REJECTED]['label'] ?? null);

        $this->assertSame('ทั้งหมด', $filters['all'] ?? null);
        $this->assertSame('ส่งคำร้องสำเร็จ', $filters['pending'] ?? null);
        $this->assertSame('กำลังดำเนินการ', $filters['in_progress'] ?? null);
        $this->assertSame('ดำเนินการเสร็จสิ้น', $filters['completed'] ?? null);
        $this->assertSame('ยกเลิกคำร้อง', $filters['cancelled'] ?? null);
        $this->assertSame('all', repairs_default_filter_status('approval'));
        $this->assertSame('all', repairs_default_filter_status('report'));
        $this->assertSame([1, 5, 6], rbac_parse_role_ids('1,5,6'));
        $this->assertSame([5, 6], rbac_parse_role_ids(['5', '6,5']));

        $this->assertSame([REPAIR_STATUS_CANCELLED, REPAIR_STATUS_REJECTED], repairs_resolve_filter_statuses('cancelled'));
        $this->assertSame([], repairs_resolve_filter_statuses('unknown'));

        $this->assertTrue(repair_can_transition(REPAIR_STATUS_PENDING, REPAIR_STATUS_IN_PROGRESS));
        $this->assertTrue(repair_can_transition(REPAIR_STATUS_PENDING, REPAIR_STATUS_COMPLETED));
        $this->assertTrue(repair_can_transition(REPAIR_STATUS_PENDING, REPAIR_STATUS_CANCELLED));
        $this->assertTrue(repair_can_transition(REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_COMPLETED));
        $this->assertFalse(repair_can_transition(REPAIR_STATUS_COMPLETED, REPAIR_STATUS_PENDING));
    }

    public function testReportModeHasNoTransitionActions(): void
    {
        $this->assertSame([], repairs_transition_actions('report', ['status' => REPAIR_STATUS_PENDING]));
        $this->assertSame([], repairs_transition_actions('report', ['status' => REPAIR_STATUS_IN_PROGRESS]));
        $this->assertSame([], repairs_transition_actions('report', null));
    }

    public function testApprovalModeProvidesFacilityActionsForPendingRequestsOnly(): void
    {
        $pendingActions = repairs_transition_actions('approval', ['status' => REPAIR_STATUS_PENDING]);
        $completedActions = repairs_transition_actions('approval', ['status' => REPAIR_STATUS_COMPLETED]);

        $this->assertSame(
            [REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_COMPLETED, REPAIR_STATUS_CANCELLED],
            array_column($pendingActions, 'target_status')
        );
        $this->assertSame(['primary', 'primary', 'danger'], array_column($pendingActions, 'variant'));
        $this->assertSame([], $completedActions);
    }

    public function testManageModeProvidesAdminActionsAcrossPendingAndInProgress(): void
    {
        $pendingActions = repairs_transition_actions('manage', ['status' => REPAIR_STATUS_PENDING]);
        $inProgressActions = repairs_transition_actions('manage', ['status' => REPAIR_STATUS_IN_PROGRESS]);
        $cancelledActions = repairs_transition_actions('manage', ['status' => REPAIR_STATUS_CANCELLED]);

        $this->assertSame(
            [REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_COMPLETED, REPAIR_STATUS_CANCELLED],
            array_column($pendingActions, 'target_status')
        );
        $this->assertSame(
            [REPAIR_STATUS_COMPLETED, REPAIR_STATUS_CANCELLED],
            array_column($inProgressActions, 'target_status')
        );
        $this->assertSame([], $cancelledActions);
    }

    public function testRequesterCreateRequestPersistsPendingRepairAndAuditLog(): void
    {
        $requesterPid = $this->requireRoleTeacherPid(ROLE_GENERAL, 'No active requester available for repairs create test');
        $subject = $this->uniqueText('create-subject');
        $location = $this->uniqueText('create-location');
        $detail = $this->uniqueText('create-detail');
        $equipment = $this->uniqueText('create-equipment');

        $this->actAs($requesterPid);
        $repairId = repair_create_request([
            'subject' => $subject,
            'location' => $location,
            'equipment' => $equipment,
            'detail' => $detail,
        ], [], $requesterPid);

        $this->createdRepairIds[] = $repairId;

        $repair = repair_get($repairId);
        $log = $this->fetchLatestAuditLog('CREATE', 'SUCCESS', $repairId);
        $timelineLog = $this->fetchLatestAuditLog('TIMELINE', 'SUCCESS', $repairId);
        $payload = $this->decodePayload($log['payloadData'] ?? null);
        $timelinePayload = $this->decodePayload($timelineLog['payloadData'] ?? null);
        $timeline = repair_get_timeline($repairId);
        $timelineMap = repair_get_timeline_map([$repairId]);

        $this->assertNotNull($repair);
        $this->assertSame($requesterPid, (string) ($repair['requesterPID'] ?? ''));
        $this->assertSame(REPAIR_STATUS_PENDING, (string) ($repair['status'] ?? ''));
        $this->assertSame($subject, (string) ($repair['subject'] ?? ''));
        $this->assertSame($location, (string) ($repair['location'] ?? ''));
        $this->assertSame($equipment, (string) ($repair['equipment'] ?? ''));

        $this->assertNotNull($log);
        $this->assertSame('repairs', (string) ($log['moduleName'] ?? ''));
        $this->assertSame('CREATE', (string) ($log['actionName'] ?? ''));
        $this->assertSame('SUCCESS', (string) ($log['actionStatus'] ?? ''));
        $this->assertSame($requesterPid, (string) ($payload['actorPID'] ?? ''));
        $this->assertSame($subject, (string) ($payload['subject'] ?? ''));
        $this->assertSame($location, (string) ($payload['location'] ?? ''));
        $this->assertSame($equipment, (string) ($payload['equipment'] ?? ''));
        $this->assertSame(0, (int) ($payload['attachmentCount'] ?? -1));
        $this->assertGreaterThan(0, (int) ($payload['detailLength'] ?? 0));

        $this->assertNotNull($timelineLog);
        $this->assertSame('TIMELINE', (string) ($timelineLog['actionName'] ?? ''));
        $this->assertSame('รับเรื่องคำร้องแล้ว', (string) ($timelineLog['logMessage'] ?? ''));
        $this->assertSame('CREATE', (string) ($timelinePayload['event'] ?? ''));
        $this->assertSame(REPAIR_STATUS_PENDING, (string) ($timelinePayload['toStatus'] ?? ''));
        $this->assertSame('ส่งคำร้องสำเร็จ', (string) ($timelinePayload['toLabel'] ?? ''));
        $this->assertNotEmpty($timeline);
        $this->assertSame('รับเรื่องคำร้องแล้ว', (string) ($timeline[0]['title'] ?? ''));
        $this->assertNotSame('', trim((string) ($timeline[0]['actorName'] ?? '')));
        $this->assertArrayHasKey($repairId, $timelineMap);
        $this->assertSame('รับเรื่องคำร้องแล้ว', (string) ($timelineMap[$repairId][0]['title'] ?? ''));
        $this->assertNotSame('', trim((string) ($timelineMap[$repairId][0]['actorName'] ?? '')));
    }

    public function testCreateValidationFailureWritesFailAuditLog(): void
    {
        $requesterPid = $this->requireRoleTeacherPid(ROLE_GENERAL, 'No active requester available for repairs validation test');
        $location = $this->uniqueText('validation-location');
        $equipment = $this->uniqueText('validation-equipment');

        $this->actAs($requesterPid);

        try {
            repair_create_request([
                'subject' => '',
                'location' => $location,
                'equipment' => $equipment,
                'detail' => 'detail for validation failure',
            ], [], $requesterPid);
            $this->fail('Expected repairs validation to reject empty subject');
        } catch (RuntimeException $exception) {
            $this->assertSame('กรุณากรอกหัวข้อ', $exception->getMessage());
        }

        $log = $this->fetchLatestAuditLogByPayload('CREATE', 'FAIL', $location);
        $payload = $this->decodePayload($log['payloadData'] ?? null);

        $this->assertNotNull($log);
        $this->assertSame('กรุณากรอกหัวข้อ', (string) ($log['logMessage'] ?? ''));
        $this->assertSame($location, (string) ($payload['location'] ?? ''));
        $this->assertSame($equipment, (string) ($payload['equipment'] ?? ''));
    }

    public function testRequesterFilteredListingScopesByOwnerSearchStatusAndSortAcrossFields(): void
    {
        $requesterPid = $this->requireRoleTeacherPid(ROLE_GENERAL, 'No active requester available for repairs listing test');
        $otherPid = $this->requireDifferentActiveTeacherPid($requesterPid, 'No secondary requester available for repairs listing test');
        $marker = $this->uniqueText('listing');

        $subjectMatchId = $this->createRepairRecord($requesterPid, [
            'subject' => $marker . ' subject',
            'detail' => 'detail-one',
            'location' => 'building-a',
            'equipment' => 'projector',
            'status' => REPAIR_STATUS_PENDING,
        ]);
        $detailMatchId = $this->createRepairRecord($requesterPid, [
            'subject' => 'detail-match',
            'detail' => $marker . ' detail',
            'location' => 'building-b',
            'equipment' => 'printer',
            'status' => REPAIR_STATUS_IN_PROGRESS,
        ]);
        $locationMatchId = $this->createRepairRecord($requesterPid, [
            'subject' => 'location-match',
            'detail' => 'detail-three',
            'location' => $marker . ' location',
            'equipment' => 'scanner',
            'status' => REPAIR_STATUS_COMPLETED,
        ]);
        $equipmentMatchId = $this->createRepairRecord($requesterPid, [
            'subject' => 'equipment-match',
            'detail' => 'detail-four',
            'location' => 'building-c',
            'equipment' => $marker . ' equipment',
            'status' => REPAIR_STATUS_CANCELLED,
        ]);
        $this->createRepairRecord($otherPid, [
            'subject' => $marker . ' outsider',
            'detail' => 'detail-outsider',
            'location' => 'other-building',
            'equipment' => 'other-equipment',
            'status' => REPAIR_STATUS_PENDING,
        ]);
        $deletedId = $this->createRepairRecord($requesterPid, [
            'subject' => $marker . ' deleted',
            'detail' => 'detail-deleted',
            'location' => 'building-deleted',
            'equipment' => 'deleted-equipment',
            'status' => REPAIR_STATUS_PENDING,
        ]);
        repair_soft_delete_record($deletedId);
        $deletedRow = db_fetch_one('SELECT repairID, deletedAt FROM dh_repair_requests WHERE repairID = ? LIMIT 1', 'i', $deletedId);

        $this->assertNotNull($deletedRow);
        $this->assertSame($deletedId, (int) ($deletedRow['repairID'] ?? 0));
        $this->assertNotEmpty((string) ($deletedRow['deletedAt'] ?? ''));
        $this->assertNull(repair_get($deletedId));

        $this->assertSame(4, repair_count_filtered($requesterPid, [], $marker));
        $this->assertSame(5, repair_count_filtered($requesterPid, [], $marker, true));
        $this->assertSame(1, repair_count_filtered($requesterPid, [REPAIR_STATUS_PENDING], $marker));
        $this->assertSame(1, repair_count_filtered($requesterPid, [REPAIR_STATUS_PENDING], $marker, true));
        $this->assertSame(1, repair_count_filtered($requesterPid, [REPAIR_STATUS_COMPLETED], $marker));

        $oldest = repair_list_filtered_page($requesterPid, [], 10, 0, $marker, 'oldest');
        $newest = repair_list_filtered_page($requesterPid, [], 10, 0, $marker, 'newest');
        $withDeleted = repair_list_filtered_page($requesterPid, [], 10, 0, $marker, 'newest', true);
        $pendingOnly = repair_list_filtered_page($requesterPid, [REPAIR_STATUS_PENDING], 10, 0, $marker, 'newest');
        $pendingOnlyWithDeleted = repair_list_filtered_page($requesterPid, [REPAIR_STATUS_PENDING], 10, 0, $marker, 'newest', true);
        $completedOnly = repair_list_filtered_page($requesterPid, [REPAIR_STATUS_COMPLETED], 10, 0, $marker, 'newest');

        $this->assertSame(
            [$subjectMatchId, $detailMatchId, $locationMatchId, $equipmentMatchId],
            array_column($oldest, 'repairID')
        );
        $this->assertSame(
            [$equipmentMatchId, $locationMatchId, $detailMatchId, $subjectMatchId],
            array_column($newest, 'repairID')
        );
        $this->assertSame(
            [$deletedId, $equipmentMatchId, $locationMatchId, $detailMatchId, $subjectMatchId],
            array_column($withDeleted, 'repairID')
        );
        $this->assertNotEmpty((string) ($withDeleted[0]['deletedAt'] ?? ''));
        $this->assertSame([$subjectMatchId], array_column($pendingOnly, 'repairID'));
        $this->assertSame([$subjectMatchId], array_column($pendingOnlyWithDeleted, 'repairID'));
        $this->assertSame([$locationMatchId], array_column($completedOnly, 'repairID'));
    }

    public function testCancelledFilterIncludesCancelledAndLegacyRejectedRows(): void
    {
        $requesterPid = $this->requireRoleTeacherPid(ROLE_GENERAL, 'No active requester available for repairs cancelled filter test');
        $marker = $this->uniqueText('cancelled-filter');

        $cancelledId = $this->createRepairRecord($requesterPid, [
            'subject' => $marker . ' cancelled',
            'detail' => 'cancelled-detail',
            'location' => 'cancelled-location',
            'equipment' => 'cancelled-equipment',
            'status' => REPAIR_STATUS_CANCELLED,
        ]);
        $rejectedId = $this->createRepairRecord($requesterPid, [
            'subject' => $marker . ' rejected',
            'detail' => 'rejected-detail',
            'location' => 'rejected-location',
            'equipment' => 'rejected-equipment',
            'status' => REPAIR_STATUS_REJECTED,
        ]);
        $this->createRepairRecord($requesterPid, [
            'subject' => $marker . ' completed',
            'detail' => 'completed-detail',
            'location' => 'completed-location',
            'equipment' => 'completed-equipment',
            'status' => REPAIR_STATUS_COMPLETED,
        ]);

        $statuses = repairs_resolve_filter_statuses('cancelled');
        $rows = repair_list_filtered_page($requesterPid, $statuses, 10, 0, $marker, 'newest');

        $this->assertSame(2, repair_count_filtered($requesterPid, $statuses, $marker));
        $this->assertSame([$rejectedId, $cancelledId], array_column($rows, 'repairID'));
    }

    public function testAttachmentQueriesReturnOnlyFilesForRequestedRepairs(): void
    {
        $requesterPid = $this->requireRoleTeacherPid(ROLE_GENERAL, 'No active requester available for repairs attachment test');
        $repairA = $this->createRepairRecord($requesterPid, ['subject' => $this->uniqueText('attachment-a')]);
        $repairB = $this->createRepairRecord($requesterPid, ['subject' => $this->uniqueText('attachment-b')]);
        $repairOther = $this->createRepairRecord($requesterPid, ['subject' => $this->uniqueText('attachment-other')]);

        $fileA1 = $this->createAttachmentRef($repairA, $requesterPid, 'repair-a-1.png');
        $fileA2 = $this->createAttachmentRef($repairA, $requesterPid, 'repair-a-2.png');
        $fileB1 = $this->createAttachmentRef($repairB, $requesterPid, 'repair-b-1.png');
        $this->createAttachmentRef($repairOther, $requesterPid, 'repair-other-1.png');

        $attachmentsA = repair_get_attachments($repairA);
        $attachmentsMap = repair_get_attachments_map([$repairA, $repairB]);

        $this->assertSame([$fileA1, $fileA2], array_column($attachmentsA, 'fileID'));
        $this->assertSame([$fileA1, $fileA2], array_column($attachmentsMap[$repairA] ?? [], 'fileID'));
        $this->assertSame([$fileB1], array_column($attachmentsMap[$repairB] ?? [], 'fileID'));
        $this->assertArrayNotHasKey($repairOther, $attachmentsMap);
    }

    public function testControllerAuditPayloadCapturesRequestContextDeterministically(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [
            'page' => '3',
            'q' => 'pump-room',
            'status' => 'pending',
            'sort' => 'oldest',
            'view_id' => '12',
        ];
        $_POST = [
            'action' => 'update',
        ];
        $_REQUEST = [
            'tab' => 'track',
            'page' => '3',
            'q' => 'pump-room',
            'status' => 'pending',
            'sort' => 'oldest',
            'view_id' => '12',
            'action' => 'update',
        ];

        $payload = repairs_controller_audit_payload('report', ['repairID' => 45, 'probe' => 'ok']);

        $this->assertSame('report', $payload['mode'] ?? null);
        $this->assertSame('track', $payload['tab'] ?? null);
        $this->assertSame(3, $payload['page'] ?? null);
        $this->assertSame('pump-room', $payload['query'] ?? null);
        $this->assertSame('pending', $payload['statusFilter'] ?? null);
        $this->assertSame('oldest', $payload['sort'] ?? null);
        $this->assertSame(12, $payload['viewID'] ?? null);
        $this->assertSame('update', $payload['requestedAction'] ?? null);
        $this->assertSame(45, $payload['repairID'] ?? null);
        $this->assertSame('ok', $payload['probe'] ?? null);
    }

    public function testControllerAuditLogPersistsRepairsEntryWithMergedPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'tab' => 'track',
            'page' => '1',
            'q' => 'audit-probe',
            'status' => 'completed',
            'sort' => 'newest',
        ];
        $_POST = [];
        $_REQUEST = $_GET;

        repairs_controller_audit_log(
            'manage',
            'TRANSITION',
            'SUCCESS',
            123456,
            'completed',
            ['fromStatus' => REPAIR_STATUS_IN_PROGRESS, 'toStatus' => REPAIR_STATUS_COMPLETED],
            200,
            'GET'
        );

        $log = $this->fetchLatestAuditLog('TRANSITION', 'SUCCESS', 123456);
        $payload = $this->decodePayload($log['payloadData'] ?? null);

        $this->assertNotNull($log);
        $this->assertSame('repairs', (string) ($log['moduleName'] ?? ''));
        $this->assertSame('TRANSITION', (string) ($log['actionName'] ?? ''));
        $this->assertSame('SUCCESS', (string) ($log['actionStatus'] ?? ''));
        $this->assertSame('completed', (string) ($log['logMessage'] ?? ''));
        $this->assertSame(200, (int) ($log['httpStatus'] ?? 0));
        $this->assertSame('manage', $payload['mode'] ?? null);
        $this->assertSame('track', $payload['tab'] ?? null);
        $this->assertSame('audit-probe', $payload['query'] ?? null);
        $this->assertSame(REPAIR_STATUS_IN_PROGRESS, $payload['fromStatus'] ?? null);
        $this->assertSame(REPAIR_STATUS_COMPLETED, $payload['toStatus'] ?? null);
    }

    public function testRepairTimelineLogHelperPersistsReadableStatusHistory(): void
    {
        $actorPid = $this->requireRoleTeacherPid(ROLE_FACILITY, 'No active facility officer available for timeline test');
        $repairId = 987654321;

        $this->actAs($actorPid);
        repair_log_timeline_event($repairId, $actorPid, 'TRANSITION', REPAIR_STATUS_PENDING, REPAIR_STATUS_IN_PROGRESS, [
            'mode' => 'approval',
            'note' => 'ตรวจสอบและเริ่มดำเนินการซ่อมแล้ว',
        ]);

        $log = $this->fetchLatestAuditLog('TIMELINE', 'SUCCESS', $repairId);
        $payload = $this->decodePayload($log['payloadData'] ?? null);
        $timeline = repair_get_timeline($repairId);
        $timelineMap = repair_get_timeline_map([$repairId]);
        $latestNotes = repair_get_latest_timeline_notes_map([$repairId]);

        $this->assertNotNull($log);
        $this->assertSame('กำลังดำเนินการ', (string) ($log['logMessage'] ?? ''));
        $this->assertSame('TRANSITION', (string) ($payload['event'] ?? ''));
        $this->assertSame(REPAIR_STATUS_PENDING, (string) ($payload['fromStatus'] ?? ''));
        $this->assertSame('ส่งคำร้องสำเร็จ', (string) ($payload['fromLabel'] ?? ''));
        $this->assertSame(REPAIR_STATUS_IN_PROGRESS, (string) ($payload['toStatus'] ?? ''));
        $this->assertSame('กำลังดำเนินการ', (string) ($payload['toLabel'] ?? ''));
        $this->assertSame('approval', (string) ($payload['mode'] ?? ''));
        $this->assertSame('ตรวจสอบและเริ่มดำเนินการซ่อมแล้ว', (string) ($payload['note'] ?? ''));
        $this->assertNotEmpty($timeline);
        $this->assertSame(REPAIR_STATUS_IN_PROGRESS, (string) ($timeline[0]['toStatus'] ?? ''));
        $this->assertSame(REPAIR_STATUS_IN_PROGRESS, (string) ($timelineMap[$repairId][0]['toStatus'] ?? ''));
        $this->assertNotSame('', trim((string) ($timelineMap[$repairId][0]['actorName'] ?? '')));
        $this->assertSame('ตรวจสอบและเริ่มดำเนินการซ่อมแล้ว', (string) ($latestNotes[$repairId] ?? ''));

        repair_log_timeline_event($repairId, $actorPid, 'NOTE_UPDATE', REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_IN_PROGRESS, [
            'mode' => 'approval',
            'note' => 'เปลี่ยนชุดหลอดไฟและตรวจสอบระบบแล้ว',
        ]);

        $latestNotes = repair_get_latest_timeline_notes_map([$repairId]);
        $this->assertSame('เปลี่ยนชุดหลอดไฟและตรวจสอบระบบแล้ว', (string) ($latestNotes[$repairId] ?? ''));
    }

    private function actAs(string $pid): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['pID'] = $pid;
    }

    private function requireActiveTeacherPid(string $message): string
    {
        return $this->requireScalarValue(
            'SELECT pID FROM teacher WHERE status = 1 ORDER BY pID ASC LIMIT 1',
            $message
        );
    }

    private function requireDifferentActiveTeacherPid(string $excludedPid, string $message): string
    {
        return $this->requireScalarValue(
            'SELECT pID FROM teacher WHERE status = 1 AND pID <> ? ORDER BY pID ASC LIMIT 1',
            $message,
            's',
            $excludedPid
        );
    }

    private function requireRoleTeacherPid(string $roleKey, string $message): string
    {
        $connection = $this->connection();
        $roleIds = rbac_resolve_role_ids($connection, $roleKey);

        if ($roleIds === []) {
            $this->markTestSkipped($message);
        }

        $types = str_repeat('i', count($roleIds));
        $params = $roleIds;
        $roleCondition = rbac_csv_role_condition('roleID', count($roleIds));

        return $this->requireScalarValue(
            'SELECT pID FROM teacher WHERE status = 1 AND ' . $roleCondition . ' ORDER BY pID ASC LIMIT 1',
            $message,
            $types,
            ...$params
        );
    }

    private function createRepairRecord(string $requesterPid, array $overrides = []): int
    {
        $data = array_merge([
            'dh_year' => $this->currentDhYear(),
            'requesterPID' => $requesterPid,
            'subject' => $this->uniqueText('repair-subject'),
            'detail' => $this->uniqueText('repair-detail'),
            'location' => $this->uniqueText('repair-location'),
            'equipment' => $this->uniqueText('repair-equipment'),
            'status' => REPAIR_STATUS_PENDING,
            'assignedToPID' => null,
        ], $overrides);

        $repairId = repair_create_record($data);
        $this->createdRepairIds[] = $repairId;

        return $repairId;
    }

    private function createAttachmentRef(int $repairId, string $uploaderPid, string $fileName): int
    {
        $relativePath = 'storage/uploads/repairs/phpunit/' . $this->uniqueText('file-path') . '.png';
        $checksum = hash('sha256', $fileName . '|' . $relativePath);

        db_execute(
            'INSERT INTO dh_files (fileName, filePath, mimeType, fileSize, checksumSHA256, storageProvider, uploadedByPID)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'sssisss',
            $fileName,
            $relativePath,
            'image/png',
            1024,
            $checksum,
            'local',
            $uploaderPid
        );

        $fileId = db_last_insert_id();
        $this->createdFileIds[] = $fileId;

        db_execute(
            'INSERT INTO dh_file_refs (fileID, moduleName, entityName, entityID, attachedByPID)
             VALUES (?, ?, ?, ?, ?)',
            'issss',
            $fileId,
            REPAIR_MODULE_NAME,
            REPAIR_ENTITY_NAME,
            (string) $repairId,
            $uploaderPid
        );

        return $fileId;
    }

    private function fetchLatestAuditLog(string $action, string $status, ?int $entityId = null): ?array
    {
        $sql = 'SELECT * FROM dh_logs WHERE moduleName = ? AND actionName = ? AND actionStatus = ? AND userAgent = ?';
        $types = 'ssss';
        $params = ['repairs', $action, $status, self::AUDIT_USER_AGENT];

        if ($entityId !== null) {
            $sql .= ' AND entityID = ?';
            $types .= 'i';
            $params[] = $entityId;
        }

        $sql .= ' ORDER BY logID DESC LIMIT 1';

        return db_fetch_one($sql, $types, ...$params);
    }

    private function fetchLatestAuditLogByPayload(string $action, string $status, string $needle): ?array
    {
        return db_fetch_one(
            'SELECT * FROM dh_logs
             WHERE moduleName = ? AND actionName = ? AND actionStatus = ? AND userAgent = ? AND payloadData LIKE ?
             ORDER BY logID DESC
             LIMIT 1',
            'sssss',
            'repairs',
            $action,
            $status,
            self::AUDIT_USER_AGENT,
            '%' . $needle . '%'
        );
    }

    private function decodePayload(?string $payloadJson): array
    {
        if ($payloadJson === null || trim($payloadJson) === '') {
            return [];
        }

        $decoded = json_decode($payloadJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uniqueText(string $prefix): string
    {
        return '[PHPUNIT][repairs] ' . $prefix . ' ' . bin2hex(random_bytes(4));
    }

    private function cleanupAttachments(): void
    {
        foreach (array_reverse(array_values(array_unique($this->createdFileIds))) as $fileId) {
            db_execute('DELETE FROM dh_file_refs WHERE fileID = ?', 'i', $fileId);
            db_execute('DELETE FROM dh_files WHERE fileID = ?', 'i', $fileId);
        }

        $this->createdFileIds = [];
    }

    private function cleanupRepairs(): void
    {
        foreach (array_reverse(array_values(array_unique($this->createdRepairIds))) as $repairId) {
            db_execute('DELETE FROM dh_file_refs WHERE moduleName = ? AND entityName = ? AND entityID = ?', 'sss', REPAIR_MODULE_NAME, REPAIR_ENTITY_NAME, (string) $repairId);
            db_execute('DELETE FROM dh_repair_requests WHERE repairID = ?', 'i', $repairId);
        }

        $this->createdRepairIds = [];
    }

    private function cleanupAuditLogs(): void
    {
        db_execute('DELETE FROM dh_logs WHERE userAgent = ?', 's', self::AUDIT_USER_AGENT);
    }
}
