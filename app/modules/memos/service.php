<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/../system/system.php';
require_once __DIR__ . '/../audit/logger.php';
require_once __DIR__ . '/../../services/uploads.php';
require_once __DIR__ . '/../../services/document-service.php';

if (!function_exists('memo_document_number')) {
    function memo_document_number(array $memo): string
    {
        $memoNo = trim((string) ($memo['memoNo'] ?? ''));

        if ($memoNo !== '') {
            return $memoNo;
        }
        $memoID = (int) ($memo['memoID'] ?? 0);

        return $memoID > 0 ? 'MEMO-' . $memoID : '';
    }
}

if (!function_exists('memo_sync_document')) {
    function memo_sync_document(int $memoID): ?int
    {
        $memo = memo_get($memoID);

        if (!$memo) {
            return null;
        }

        $documentNumber = memo_document_number($memo);

        if ($documentNumber === '') {
            return null;
        }

        return document_upsert([
            'documentType' => 'MEMO',
            'documentNumber' => $documentNumber,
            'subject' => (string) ($memo['subject'] ?? ''),
            'content' => (string) ($memo['detail'] ?? ''),
            'status' => (string) ($memo['status'] ?? ''),
            'senderName' => (string) ($memo['creatorName'] ?? ''),
            'createdByPID' => (string) ($memo['createdByPID'] ?? ''),
            'updatedByPID' => $memo['updatedByPID'] ?? null,
        ]);
    }
}

if (!function_exists('memo_next_sequence_for_year')) {
    function memo_next_sequence_for_year(int $year): int
    {
        $year = (int) $year;

        if ($year <= 0) {
            $year = system_get_dh_year();
        }

        $connection = db_connection();

        if (db_table_exists($connection, 'dh_sequences')) {
            $seqKey = 'memo:' . $year;
            $row = db_fetch_one('SELECT currentValue FROM dh_sequences WHERE seqKey = ? FOR UPDATE', 's', $seqKey);

            if (!$row) {
                db_execute('INSERT INTO dh_sequences (seqKey, currentValue) VALUES (?, ?)', 'si', $seqKey, 1);

                return 1;
            }

            $next = (int) ($row['currentValue'] ?? 0) + 1;
            db_execute('UPDATE dh_sequences SET currentValue = ? WHERE seqKey = ?', 'is', $next, $seqKey);

            return $next;
        }

        $row = db_fetch_one(
            'SELECT memoSeq FROM dh_memos WHERE dh_year = ? AND memoSeq IS NOT NULL ORDER BY memoSeq DESC LIMIT 1 FOR UPDATE',
            'i',
            $year
        );

        return $row ? ((int) ($row['memoSeq'] ?? 0) + 1) : 1;
    }
}

if (!function_exists('memo_list_deputy_candidates')) {
    function memo_list_deputy_candidates(?string $excludePID = null): array
    {
        $connection = db_connection();
        $deputy_position_ids = system_position_deputy_ids($connection);

        if ($deputy_position_ids === []) {
            return [];
        }

        $position = system_position_join($connection, 't', 'p');
        $position_config = system_position_config($connection);
        $teacher_position_column = 't.' . (string) ($position_config['teacher_column'] ?? 'positionID');
        $excludePID = trim((string) $excludePID);

        $placeholders = implode(', ', array_fill(0, count($deputy_position_ids), '?'));
        $types = str_repeat('i', count($deputy_position_ids));
        $params = $deputy_position_ids;
        $where = 't.status = 1 AND ' . $teacher_position_column . ' IN (' . $placeholders . ')';

        if ($excludePID !== '') {
            $where .= ' AND t.pID <> ?';
            $types .= 's';
            $params[] = $excludePID;
        }

        $rows = db_fetch_all(
            'SELECT t.pID,
                    COALESCE(t.fName, "") AS name,
                    COALESCE(' . $position['name'] . ', "") AS positionName
             FROM teacher AS t
             ' . $position['join'] . '
             WHERE ' . $where . '
             ORDER BY FIELD(' . $teacher_position_column . ', 9, 2, 3, 4), ' . $position['name'] . ' ASC, t.fName ASC, t.pID ASC',
            $types,
            ...$params
        );

        $items = [];

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            if ($pid === '' || $name === '') {
                continue;
            }

            $items[] = [
                'pID' => $pid,
                'name' => $name,
                'positionName' => trim((string) ($row['positionName'] ?? '')),
            ];
        }

        return $items;
    }
}

if (!function_exists('memo_is_valid_deputy_candidate')) {
    function memo_is_valid_deputy_candidate(string $targetPID, ?string $excludePID = null): bool
    {
        $targetPID = trim($targetPID);

        if ($targetPID === '') {
            return false;
        }

        foreach (memo_list_deputy_candidates($excludePID) as $candidate) {
            if ($targetPID === trim((string) ($candidate['pID'] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('memo_generate_number')) {
    function memo_generate_number(int $year): array
    {
        $seq = memo_next_sequence_for_year($year);
        $memoNo = $year . '/' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        return [$memoNo, $seq];
    }
}

if (!function_exists('memo_resolve_chain_approvers')) {
    function memo_resolve_chain_approvers(string $creatorPID): array
    {
        $creatorPID = trim($creatorPID);

        if ($creatorPID === '') {
            throw new RuntimeException('ไม่พบผู้สร้างเอกสาร');
        }

        $creator = db_fetch_one(
            'SELECT pID, fID, positionID FROM teacher WHERE pID = ? AND status = 1 LIMIT 1',
            's',
            $creatorPID
        );

        if (!$creator) {
            throw new RuntimeException('ไม่พบข้อมูลผู้สร้างเอกสาร');
        }

        $fID = (int) ($creator['fID'] ?? 0);
        $creatorPositionID = (int) ($creator['positionID'] ?? 0);
        $creatorIsSubjectHead = in_array($creatorPositionID, [5, 6], true);
        $headPID = '';
        $deputyPID = '';
        $directorPID = trim((string) (system_get_current_director_pid() ?? ''));

        // ผู้ช่วย/หัวหน้ากลุ่ม/หัวหน้ากลุ่มสาระ ในกลุ่มเดียวกันก่อน
        if ($fID > 0 && !$creatorIsSubjectHead) {
            $head = db_fetch_one(
                'SELECT pID
                 FROM teacher
                 WHERE status = 1
                   AND pID <> ?
                   AND fID = ?
                   AND positionID IN (6, 5)
                 ORDER BY CASE positionID WHEN 6 THEN 1 WHEN 5 THEN 2 ELSE 9 END, pID ASC
                 LIMIT 1',
                'si',
                $creatorPID,
                $fID
            );

            if ($head && !empty($head['pID'])) {
                $headPID = trim((string) $head['pID']);
            }
        }

        // รองผู้อำนวยการ (ตามสายงานเดียวกันก่อน) จากนั้นค่อย fallback
        $connection = db_connection();
        $deputyPositionIDs = system_position_deputy_ids($connection);

        if (!empty($deputyPositionIDs)) {
            $placeholders = implode(', ', array_fill(0, count($deputyPositionIDs), '?'));
            $types = str_repeat('i', count($deputyPositionIDs));
            $params = $deputyPositionIDs;

            if ($fID > 0) {
                $sql = 'SELECT pID
                        FROM teacher
                        WHERE status = 1
                          AND pID <> ?
                          AND fID = ?
                          AND positionID IN (' . $placeholders . ')
                        ORDER BY FIELD(positionID, 9, 2, 3, 4), positionID ASC, pID ASC
                        LIMIT 1';
                $typesWith = 'si' . $types;
                $paramsWith = array_merge([$creatorPID, $fID], $params);
                $row = db_fetch_one($sql, $typesWith, ...$paramsWith);

                if ($row && !empty($row['pID'])) {
                    $deputyPID = trim((string) $row['pID']);
                }
            }

            if ($deputyPID === '') {
                $sql = 'SELECT pID
                        FROM teacher
                        WHERE status = 1
                          AND pID <> ?
                          AND positionID IN (' . $placeholders . ')
                        ORDER BY FIELD(positionID, 9, 2, 3, 4), positionID ASC, pID ASC
                        LIMIT 1';
                $typesWith = 's' . $types;
                $paramsWith = array_merge([$creatorPID], $params);
                $row = db_fetch_one($sql, $typesWith, ...$paramsWith);

                if ($row && !empty($row['pID'])) {
                    $deputyPID = trim((string) $row['pID']);
                }
            }
        }

        return [
            'creatorPID' => $creatorPID,
            'headPID' => $headPID,
            'deputyPID' => $deputyPID,
            'directorPID' => $directorPID,
        ];
    }
}

if (!function_exists('memo_resolve_first_reviewer')) {
    function memo_resolve_first_reviewer(array $chain): array
    {
        $headPID = trim((string) ($chain['headPID'] ?? ''));
        $deputyPID = trim((string) ($chain['deputyPID'] ?? ''));
        $directorPID = trim((string) ($chain['directorPID'] ?? ''));

        if ($headPID !== '') {
            return [$headPID, 'HEAD'];
        }

        if ($deputyPID !== '') {
            return [$deputyPID, 'DEPUTY'];
        }

        if ($directorPID !== '') {
            return [$directorPID, 'DIRECTOR'];
        }

        throw new RuntimeException('ไม่พบผู้พิจารณาตามลำดับเสนอแฟ้ม');
    }
}

if (!function_exists('memo_resolve_direct_recipient_stage')) {
    function memo_resolve_direct_recipient_stage(string $targetPID, ?string $directorPID = null): string
    {
        $targetPID = trim($targetPID);
        $directorPID = trim((string) ($directorPID ?? ''));

        if ($directorPID === '') {
            $directorPID = trim((string) (system_get_current_director_pid() ?? ''));
        }

        if ($targetPID !== '' && $directorPID !== '' && $targetPID === $directorPID) {
            return 'DIRECTOR';
        }

        if ($targetPID !== '' && memo_is_valid_deputy_candidate($targetPID)) {
            return 'DEPUTY';
        }

        return 'HEAD';
    }
}

if (!function_exists('memo_latest_return_actor_pid')) {
    function memo_latest_return_actor_pid(array $routes): string
    {
        $latestActorPID = '';

        foreach ($routes as $route) {
            $action = strtoupper(trim((string) ($route['action'] ?? '')));

            if ($action !== 'RETURN') {
                continue;
            }

            $actorPID = trim((string) ($route['actorPID'] ?? ''));

            if ($actorPID !== '') {
                $latestActorPID = $actorPID;
            }
        }

        return $latestActorPID;
    }
}

if (!function_exists('memo_resolve_return_reviewer_stage')) {
    function memo_resolve_return_reviewer_stage(array $memo, string $reviewerPID): string
    {
        $reviewerPID = trim($reviewerPID);

        if ($reviewerPID === '') {
            return '';
        }

        $stage = memo_infer_chain_stage_by_actor($memo, $reviewerPID);

        if (in_array($stage, ['HEAD', 'DEPUTY', 'DIRECTOR'], true)) {
            return $stage;
        }

        $directorPID = trim((string) ($memo['directorPID'] ?? ''));

        if ($directorPID === '') {
            $directorPID = trim((string) (system_get_current_director_pid() ?? ''));
        }

        if ($directorPID !== '' && $reviewerPID === $directorPID) {
            return 'DIRECTOR';
        }

        if (memo_is_valid_deputy_candidate($reviewerPID)) {
            return 'DEPUTY';
        }

        return 'HEAD';
    }
}

if (!function_exists('memo_is_chain_mode')) {
    function memo_is_chain_mode(array $memo): bool
    {
        $mode = strtoupper(trim((string) ($memo['flowMode'] ?? '')));

        if ($mode === '') {
            return true;
        }

        return $mode !== 'DIRECT';
    }
}

if (!function_exists('memo_chain_skips_head_stage')) {
    function memo_chain_skips_head_stage(array $memo): bool
    {
        $headPID = trim((string) ($memo['headPID'] ?? ''));
        $deputyPID = trim((string) ($memo['deputyPID'] ?? ''));
        $toPID = trim((string) ($memo['toPID'] ?? ''));

        $creatorPID = trim((string) ($memo['createdByPID'] ?? ''));

        if ($headPID === '' && $deputyPID !== '') {
            return true;
        }

        if ($creatorPID === '') {
            return false;
        }

        try {
            $chain = memo_resolve_chain_approvers($creatorPID);
        } catch (Throwable $ignored) {
            return false;
        }

        $resolvedHeadPID = trim((string) ($chain['headPID'] ?? ''));
        $resolvedDeputyPID = trim((string) ($chain['deputyPID'] ?? ''));

        if ($resolvedHeadPID !== '' || $resolvedDeputyPID === '') {
            return false;
        }

        return $headPID === ''
            || $headPID === $resolvedDeputyPID
            || ($toPID !== '' && $headPID === $toPID);
    }
}

if (!function_exists('memo_normalize_chain_stage')) {
    function memo_normalize_chain_stage(array $memo, string $stage, string $actorPID = ''): string
    {
        $stage = strtoupper(trim($stage));

        if ($stage !== 'HEAD' || !memo_chain_skips_head_stage($memo)) {
            return $stage;
        }

        $actorPID = trim($actorPID);
        $deputyPID = trim((string) ($memo['deputyPID'] ?? ''));
        $toPID = trim((string) ($memo['toPID'] ?? ''));

        if ($actorPID === '' || $actorPID === $deputyPID || $actorPID === $toPID) {
            return 'DEPUTY';
        }

        return $stage;
    }
}

if (!function_exists('memo_infer_chain_stage_by_actor')) {
    function memo_infer_chain_stage_by_actor(array $memo, string $actorPID): string
    {
        $actorPID = trim($actorPID);
        $stage = strtoupper(trim((string) ($memo['flowStage'] ?? '')));

        if ($stage !== '' && $stage !== 'OWNER') {
            return memo_normalize_chain_stage($memo, $stage, $actorPID);
        }

        if ($actorPID !== '' && $actorPID === trim((string) ($memo['headPID'] ?? ''))) {
            return 'HEAD';
        }

        if ($actorPID !== '' && $actorPID === trim((string) ($memo['deputyPID'] ?? ''))) {
            return 'DEPUTY';
        }

        if ($actorPID !== '' && $actorPID === trim((string) ($memo['directorPID'] ?? ''))) {
            return 'DIRECTOR';
        }

        return $stage !== '' ? $stage : 'OWNER';
    }
}

if (!function_exists('memo_owner_can_edit_before_head_forward')) {
    function memo_owner_can_edit_before_head_forward(array $memo, string $actorPID, ?array $routes = null): bool
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '' || trim((string) ($memo['createdByPID'] ?? '')) !== $actorPID) {
            return false;
        }

        if (!memo_is_chain_mode($memo)) {
            return false;
        }

        $status = strtoupper(trim((string) ($memo['status'] ?? '')));

        if (!in_array($status, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
            return false;
        }

        $flowStage = strtoupper(trim((string) ($memo['flowStage'] ?? '')));

        if ($flowStage !== 'HEAD') {
            return false;
        }

        $headPID = trim((string) ($memo['headPID'] ?? ''));
        $toPID = trim((string) ($memo['toPID'] ?? ''));

        if ($headPID === '') {
            $headPID = $toPID;
        }

        if ($headPID === '' || ($toPID !== '' && $toPID !== $headPID)) {
            return false;
        }

        if ($routes === null) {
            $memoID = (int) ($memo['memoID'] ?? 0);
            $routes = $memoID > 0 ? memo_list_routes($memoID) : [];
        }

        foreach ($routes as $route) {
            $action = strtoupper(trim((string) ($route['action'] ?? '')));
            $routeActorPID = trim((string) ($route['actorPID'] ?? ''));

            if ($action === 'FORWARD' && $routeActorPID === $headPID) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('memo_resolve_next_chain_reviewer')) {
    function memo_resolve_next_chain_reviewer(array $memo, string $currentStage): array
    {
        $currentStage = strtoupper(trim($currentStage));
        $deputyPID = trim((string) ($memo['deputyPID'] ?? ''));
        $directorPID = trim((string) ($memo['directorPID'] ?? ''));

        if ($directorPID === '') {
            $directorPID = trim((string) (system_get_current_director_pid() ?? ''));
        }

        if ($currentStage === 'HEAD') {
            if ($deputyPID !== '') {
                return [$deputyPID, 'DEPUTY'];
            }

            if ($directorPID !== '') {
                return [$directorPID, 'DIRECTOR'];
            }
            throw new RuntimeException('ไม่พบรองผู้อำนวยการ/ผู้อำนวยการสำหรับส่งต่อ');
        }

        if ($currentStage === 'DEPUTY') {
            if ($directorPID !== '') {
                return [$directorPID, 'DIRECTOR'];
            }
            throw new RuntimeException('ไม่พบผู้อำนวยการสำหรับส่งต่อ');
        }

        throw new RuntimeException('ไม่สามารถส่งต่อในสถานะปัจจุบัน');
    }
}

if (!function_exists('memo_create_draft')) {
    function memo_create_draft(array $data, array $files = []): int
    {
        $creatorPID = trim((string) ($data['createdByPID'] ?? ''));

        if ($creatorPID === '') {
            throw new RuntimeException('ไม่พบผู้สร้างเอกสาร');
        }

        $flowMode = strtoupper(trim((string) ($data['flowMode'] ?? 'CHAIN')));

        if ($flowMode !== 'DIRECT') {
            $flowMode = 'CHAIN';
        }

        $headPID = null;
        $deputyPID = null;
        $directorPID = null;
        $toType = $data['toType'] ?? null;
        $toPID = $data['toPID'] ?? null;

        if ($flowMode === 'CHAIN') {
            $chain = memo_resolve_chain_approvers($creatorPID);
            $headPID = $chain['headPID'] !== '' ? $chain['headPID'] : null;
            $deputyPID = $chain['deputyPID'] !== '' ? $chain['deputyPID'] : null;
            $directorPID = $chain['directorPID'] !== '' ? $chain['directorPID'] : null;
            $toType = null;
            $toPID = null;
        }

        db_begin();

        try {
            $memoID = memo_create_record([
                'dh_year' => (int) $data['dh_year'],
                'writeDate' => $data['writeDate'] ?? null,
                'subject' => (string) ($data['subject'] ?? ''),
                'detail' => $data['detail'] ?? null,
                'status' => MEMO_STATUS_DRAFT,
                'createdByPID' => $creatorPID,
                'toType' => $toType,
                'toPID' => $toPID,
                'flowMode' => $flowMode,
                'flowStage' => 'OWNER',
                'headPID' => $headPID,
                'deputyPID' => $deputyPID,
                'directorPID' => $directorPID,
                'updatedByPID' => $creatorPID,
            ]);
            memo_add_route($memoID, 'CREATE', null, MEMO_STATUS_DRAFT, $creatorPID, null);

            if (!empty($files)) {
                upload_store_files($files, MEMO_MODULE_NAME, MEMO_ENTITY_NAME, (string) $memoID, $creatorPID, [
                    'max_files' => 5,
                ]);
            }

            db_commit();
            audit_log('memos', 'CREATE', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);

            return $memoID;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo create failed: ' . $e->getMessage());
            audit_log('memos', 'CREATE', 'FAIL', MEMO_ENTITY_NAME, null, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_update_draft')) {
    function memo_update_draft(int $memoID, string $actorPID, array $data, array $files = []): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if ((string) ($memo['createdByPID'] ?? '') !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์แก้ไขรายการนี้');
            }

            $status = (string) ($memo['status'] ?? '');

            $canOwnerEditBeforeHeadForward = memo_owner_can_edit_before_head_forward($memo, $actorPID);

            if (!in_array($status, [MEMO_STATUS_DRAFT, MEMO_STATUS_RETURNED], true) && !$canOwnerEditBeforeHeadForward) {
                throw new RuntimeException('แก้ไขได้เฉพาะรายการ ร่าง/ตีกลับแก้ไข');
            }

            $subject = trim((string) ($data['subject'] ?? ''));

            if ($subject === '') {
                throw new RuntimeException('กรุณากรอกหัวข้อ');
            }

            $update_payload = [
                'subject' => $subject,
                'detail' => $data['detail'] ?? null,
                'updatedByPID' => $actorPID,
            ];

            if (array_key_exists('writeDate', $data)) {
                $update_payload['writeDate'] = $data['writeDate'] ?? null;
            }

            if (!$canOwnerEditBeforeHeadForward && (array_key_exists('toType', $data) || array_key_exists('toPID', $data))) {
                $update_payload['toType'] = $data['toType'] ?? null;
                $update_payload['toPID'] = $data['toPID'] ?? null;
            }

            $flow_mode = $canOwnerEditBeforeHeadForward ? '' : strtoupper(trim((string) ($data['flowMode'] ?? '')));

            if ($flow_mode !== '') {
                if (!in_array($flow_mode, ['CHAIN', 'DIRECT'], true)) {
                    throw new RuntimeException('รูปแบบการส่งเอกสารไม่ถูกต้อง');
                }
                $update_payload['flowMode'] = $flow_mode;
                $update_payload['flowStage'] = 'OWNER';

                if ($flow_mode === 'DIRECT') {
                    $update_payload['headPID'] = null;
                    $update_payload['deputyPID'] = null;
                    $update_payload['directorPID'] = null;
                }
            }

            memo_update_record($memoID, $update_payload);
            memo_add_route($memoID, 'UPDATE', $status, $status, $actorPID, null);

            if (!empty($files)) {
                $existing = memo_get_attachments($memoID);
                $normalized = upload_normalize_files($files);
                $upload_count = 0;

                foreach ($normalized as $file) {
                    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $upload_count++;
                    }
                }

                if (count($existing) + $upload_count > 5) {
                    throw new RuntimeException('แนบไฟล์ได้สูงสุด 5 ไฟล์');
                }
                upload_store_files($files, MEMO_MODULE_NAME, MEMO_ENTITY_NAME, (string) $memoID, $actorPID, [
                    'max_files' => 5,
                ]);
            }

            db_commit();
            audit_log('memos', 'UPDATE', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo update failed: ' . $e->getMessage());
            audit_log('memos', 'UPDATE', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_submit')) {
    function memo_submit(int $memoID, string $actorPID): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if ((string) ($memo['createdByPID'] ?? '') !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์ส่งเสนอ');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_DRAFT, MEMO_STATUS_RETURNED], true)) {
                throw new RuntimeException('ไม่สามารถส่งเสนอได้ในสถานะปัจจุบัน');
            }

            $flowMode = memo_is_chain_mode($memo) ? 'CHAIN' : 'DIRECT';
            $flowStage = 'OWNER';
            $toType = (string) ($memo['toType'] ?? '');
            $toPID = trim((string) ($memo['toPID'] ?? ''));

            $headPID = trim((string) ($memo['headPID'] ?? ''));
            $deputyPID = trim((string) ($memo['deputyPID'] ?? ''));
            $directorPID = trim((string) ($memo['directorPID'] ?? ''));

            $returnReviewerPID = '';

            if ($fromStatus === MEMO_STATUS_RETURNED) {
                $returnReviewerPID = memo_latest_return_actor_pid(memo_list_routes($memoID));
            }

            if ($flowMode === 'CHAIN') {
                $chain = [
                    'headPID' => $headPID,
                    'deputyPID' => $deputyPID,
                    'directorPID' => $directorPID,
                ];

                if ($chain['headPID'] === '' && $chain['deputyPID'] === '' && $chain['directorPID'] === '') {
                    $chain = memo_resolve_chain_approvers($actorPID);
                } else {
                    if ($chain['directorPID'] === '') {
                        $chain['directorPID'] = trim((string) (system_get_current_director_pid() ?? ''));
                    }
                }

                $headPID = trim((string) ($chain['headPID'] ?? ''));
                $deputyPID = trim((string) ($chain['deputyPID'] ?? ''));
                $directorPID = trim((string) ($chain['directorPID'] ?? ''));
                $stageMemo = [
                    ...$memo,
                    'headPID' => $headPID,
                    'deputyPID' => $deputyPID,
                    'directorPID' => $directorPID,
                    'flowStage' => 'OWNER',
                ];

                if ($returnReviewerPID !== '' && preg_match('/^\d{1,13}$/', $returnReviewerPID)) {
                    $flowStage = memo_resolve_return_reviewer_stage($stageMemo, $returnReviewerPID);
                    $toPID = $returnReviewerPID;

                    if ($flowStage === 'HEAD') {
                        $headPID = $returnReviewerPID;
                    } elseif ($flowStage === 'DEPUTY') {
                        $deputyPID = $returnReviewerPID;
                    } elseif ($flowStage === 'DIRECTOR') {
                        $directorPID = $returnReviewerPID;
                    }
                } else {
                    [$toPID, $flowStage] = memo_resolve_first_reviewer($chain);
                }

                $toType = $flowStage === 'DIRECTOR' ? 'DIRECTOR' : 'PERSON';
            } else {
                if ($directorPID === '') {
                    $directorPID = trim((string) (system_get_current_director_pid() ?? ''));
                }

                if ($returnReviewerPID !== '' && preg_match('/^\d{1,13}$/', $returnReviewerPID)) {
                    $toPID = $returnReviewerPID;
                    $flowStage = memo_resolve_direct_recipient_stage($returnReviewerPID, $directorPID);
                    $toType = $flowStage === 'DIRECTOR' ? 'DIRECTOR' : 'PERSON';
                } elseif ($toType === 'DIRECTOR') {
                    $toPID = $directorPID;
                } elseif ($toType !== 'PERSON') {
                    // For older records, treat as PERSON when PID is set.
                    $toType = $toPID !== '' ? 'PERSON' : '';
                }

                if ($toPID === '' || !preg_match('/^\\d{1,13}$/', $toPID)) {
                    throw new RuntimeException('กรุณาเลือกผู้พิจารณา (เรียน)');
                }

                if ($flowStage === 'OWNER') {
                    $flowStage = $toType === 'DIRECTOR'
                        ? 'DIRECTOR'
                        : memo_resolve_direct_recipient_stage($toPID, $directorPID);
                }

                if ($flowStage === 'DIRECTOR') {
                    $flowMode = 'CHAIN';
                    $toType = 'DIRECTOR';
                    $directorPID = $toPID;
                } elseif ($flowStage === 'DEPUTY') {
                    $flowMode = 'CHAIN';
                    $toType = 'PERSON';
                    $deputyPID = $toPID;

                    if ($directorPID === '') {
                        $directorPID = trim((string) (system_get_current_director_pid() ?? ''));
                    }
                } else {
                    $toType = 'PERSON';
                }
            }

            if ($toPID === '' || !preg_match('/^\\d{1,13}$/', $toPID)) {
                throw new RuntimeException('ไม่พบผู้พิจารณาในลำดับการเสนอแฟ้ม');
            }

            $memoNo = trim((string) ($memo['memoNo'] ?? ''));
            $memoSeq = (int) ($memo['memoSeq'] ?? 0);

            if ($memoNo === '' || $memoSeq <= 0) {
                [$memoNo, $memoSeq] = memo_generate_number((int) ($memo['dh_year'] ?? system_get_dh_year()));
            }

            memo_update_record($memoID, [
                'memoNo' => $memoNo,
                'memoSeq' => $memoSeq,
                'toType' => $toType !== '' ? $toType : 'PERSON',
                'toPID' => $toPID,
                'flowMode' => $flowMode,
                'flowStage' => $flowStage,
                'headPID' => $headPID !== '' ? $headPID : null,
                'deputyPID' => $deputyPID !== '' ? $deputyPID : null,
                'directorPID' => $directorPID !== '' ? $directorPID : null,
                'status' => MEMO_STATUS_SUBMITTED,
                'submittedAt' => date('Y-m-d H:i:s'),
                // Resubmission should be considered "unread" again.
                'firstReadAt' => null,
                'updatedByPID' => $actorPID,
            ]);

            memo_add_route($memoID, $fromStatus === MEMO_STATUS_RETURNED ? 'RESUBMIT' : 'SUBMIT', $fromStatus, MEMO_STATUS_SUBMITTED, $actorPID, null);

            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_add_recipients($documentID, [$toPID], INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('memos', 'SUBMIT', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo submit failed: ' . $e->getMessage());
            audit_log('memos', 'SUBMIT', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_mark_in_review')) {
    function memo_mark_in_review(int $memoID, string $actorPID): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            return;
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT memoID, status, toPID, firstReadAt FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                db_commit();

                return;
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                db_commit();

                return;
            }

            $status = (string) ($memo['status'] ?? '');

            if (!in_array($status, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
                db_commit();

                return;
            }

            $updates = [];
            $toStatus = $status;

            if ($status === MEMO_STATUS_SUBMITTED) {
                $updates['status'] = MEMO_STATUS_IN_REVIEW;
                $toStatus = MEMO_STATUS_IN_REVIEW;
            }

            if (empty($memo['firstReadAt'])) {
                $updates['firstReadAt'] = date('Y-m-d H:i:s');
            }

            if (!empty($updates)) {
                $updates['updatedByPID'] = $actorPID;
                memo_update_record($memoID, $updates);
                memo_add_route($memoID, 'OPEN', $status, $toStatus, $actorPID, null);
            }

            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);
            }

            db_commit();
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo open failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('memo_return')) {
    function memo_return(int $memoID, string $actorPID, string $note): void
    {
        $actorPID = trim($actorPID);
        $note = trim($note);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        if ($note === '') {
            throw new RuntimeException('กรุณากรอกความเห็น');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์พิจารณารายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
                throw new RuntimeException('ไม่สามารถตีกลับได้ในสถานะปัจจุบัน');
            }

            $now = date('Y-m-d H:i:s');
            $toPID = trim((string) ($memo['createdByPID'] ?? ''));
            $toType = 'PERSON';
            $flowStage = 'OWNER';

            $updates = [
                'status' => MEMO_STATUS_RETURNED,
                'reviewNote' => $note,
                'reviewedAt' => $now,
                'toType' => $toType,
                'toPID' => $toPID,
                'flowStage' => $flowStage,
                'updatedByPID' => $actorPID,
            ];

            if (empty($memo['firstReadAt'])) {
                $updates['firstReadAt'] = $now;
            }
            memo_update_record($memoID, [
                ...$updates,
            ]);
            memo_add_route($memoID, 'RETURN', $fromStatus, MEMO_STATUS_RETURNED, $actorPID, $note);
            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);

                if ($toPID !== '') {
                    document_add_recipients($documentID, [$toPID], INBOX_TYPE_NORMAL);
                }
            }

            db_commit();
            audit_log('memos', 'RETURN', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo return failed: ' . $e->getMessage());
            audit_log('memos', 'RETURN', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_recall')) {
    function memo_recall(int $memoID, string $actorPID): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['createdByPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์ดึงกลับรายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW, MEMO_STATUS_APPROVED_UNSIGNED], true)) {
                throw new RuntimeException('ดึงกลับได้เฉพาะรายการที่อยู่ระหว่างพิจารณา');
            }

            memo_update_record($memoID, [
                'status' => MEMO_STATUS_DRAFT,
                'flowStage' => 'OWNER',
                'toType' => 'PERSON',
                'toPID' => $actorPID,
                'updatedByPID' => $actorPID,
            ]);
            memo_add_route($memoID, 'RECALL', $fromStatus, MEMO_STATUS_DRAFT, $actorPID, null);
            memo_sync_document($memoID);

            db_commit();
            audit_log('memos', 'RECALL', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo recall failed: ' . $e->getMessage());
            audit_log('memos', 'RECALL', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_forward')) {
    function memo_forward(int $memoID, string $actorPID, string $note = '', string $targetPID = ''): void
    {
        $actorPID = trim($actorPID);
        $note = trim($note);
        $targetPID = trim($targetPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์ส่งต่อรายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
                throw new RuntimeException('ไม่สามารถส่งต่อได้ในสถานะปัจจุบัน');
            }

            $headPID = trim((string) ($memo['headPID'] ?? ''));
            $deputyPID = trim((string) ($memo['deputyPID'] ?? ''));
            $directorPID = trim((string) ($memo['directorPID'] ?? ''));
            $flowStage = strtoupper(trim((string) ($memo['flowStage'] ?? '')));
            $currentToPID = trim((string) ($memo['toPID'] ?? ''));
            $flowStage = memo_normalize_chain_stage($memo, $flowStage, $currentToPID !== '' ? $currentToPID : $actorPID);

            if ($currentToPID !== '' && in_array($flowStage, ['HEAD', 'DEPUTY', 'DIRECTOR'], true)) {
                if ($flowStage === 'HEAD') {
                    $headPID = $currentToPID;
                } elseif ($flowStage === 'DEPUTY') {
                    $deputyPID = $currentToPID;
                } elseif ($flowStage === 'DIRECTOR') {
                    $directorPID = $currentToPID;
                }
            }

            if ($headPID === '' || $deputyPID === '' || $directorPID === '') {
                try {
                    $resolved = memo_resolve_chain_approvers(trim((string) ($memo['createdByPID'] ?? '')));

                    if ($headPID === '') {
                        $headPID = trim((string) ($resolved['headPID'] ?? ''));
                    }

                    if ($deputyPID === '') {
                        $deputyPID = trim((string) ($resolved['deputyPID'] ?? ''));
                    }

                    if ($directorPID === '') {
                        $directorPID = trim((string) ($resolved['directorPID'] ?? ''));
                    }
                } catch (Throwable $ignored) {
                }
            }

            if ($directorPID === '') {
                $directorPID = trim((string) (system_get_current_director_pid() ?? ''));
            }

            $is_chain_mode = memo_is_chain_mode($memo);
            $actor_is_deputy = memo_is_valid_deputy_candidate($actorPID);
            $target_is_director = $directorPID !== '' && ($targetPID === '' || $targetPID === $directorPID);
            $memo = [
                ...$memo,
                'headPID' => $headPID,
                'deputyPID' => $deputyPID,
                'directorPID' => $directorPID,
            ];

            if ($is_chain_mode) {
                $currentStage = memo_infer_chain_stage_by_actor($memo, $actorPID);

                if (!in_array($currentStage, ['HEAD', 'DEPUTY'], true)) {
                    throw new RuntimeException('ส่งต่อได้เฉพาะหัวหน้ากลุ่ม/รองผู้อำนวยการ');
                }

                if ($currentStage === 'HEAD' && $actor_is_deputy && $target_is_director) {
                    $currentStage = 'DEPUTY';
                    $deputyPID = $actorPID;
                    $memo['deputyPID'] = $deputyPID;

                    if ($headPID === $actorPID) {
                        $headPID = '';
                        $memo['headPID'] = '';
                    }
                }

                if ($currentStage === 'HEAD' && $targetPID !== '') {
                    if (!preg_match('/^\d{1,13}$/', $targetPID) || !memo_is_valid_deputy_candidate($targetPID, $actorPID)) {
                        throw new RuntimeException('ไม่พบรองผู้อำนวยการที่เลือก');
                    }

                    $deputyPID = $targetPID;
                    $memo['deputyPID'] = $deputyPID;
                }
            } else {
                if ($actor_is_deputy && $target_is_director) {
                    $currentStage = 'DEPUTY';
                    $deputyPID = $actorPID;
                    $memo['flowMode'] = 'CHAIN';
                    $memo['deputyPID'] = $deputyPID;

                    if ($headPID === $actorPID) {
                        $headPID = '';
                        $memo['headPID'] = '';
                    }
                } else {
                    if (!preg_match('/^\d{1,13}$/', $targetPID) || !memo_is_valid_deputy_candidate($targetPID, $actorPID)) {
                        throw new RuntimeException('ไม่พบรองผู้อำนวยการที่เลือก');
                    }

                    $currentStage = 'HEAD';
                    $headPID = $actorPID;
                    $deputyPID = $targetPID;
                    $memo['flowMode'] = 'CHAIN';
                    $memo['headPID'] = $headPID;
                    $memo['deputyPID'] = $deputyPID;
                }
            }

            [$nextPID, $nextStage] = memo_resolve_next_chain_reviewer($memo, $currentStage);

            if ($nextPID === '' || !preg_match('/^\\d{1,13}$/', $nextPID)) {
                throw new RuntimeException('ไม่พบผู้รับผิดชอบลำดับถัดไป');
            }

            $now = date('Y-m-d H:i:s');
            memo_update_record($memoID, [
                'status' => MEMO_STATUS_SUBMITTED,
                'flowMode' => 'CHAIN',
                'flowStage' => $nextStage,
                'toType' => $nextStage === 'DIRECTOR' ? 'DIRECTOR' : 'PERSON',
                'toPID' => $nextPID,
                'reviewNote' => $note !== '' ? $note : ($memo['reviewNote'] ?? null),
                'reviewedAt' => $now,
                'firstReadAt' => null,
                'headPID' => $headPID !== '' ? $headPID : null,
                'deputyPID' => $deputyPID !== '' ? $deputyPID : null,
                'directorPID' => $directorPID !== '' ? $directorPID : null,
                'updatedByPID' => $actorPID,
            ]);
            memo_add_route($memoID, 'FORWARD', $fromStatus, MEMO_STATUS_SUBMITTED, $actorPID, $note !== '' ? $note : null);

            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);
                document_add_recipients($documentID, [$nextPID], INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('memos', 'FORWARD', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo forward failed: ' . $e->getMessage());
            audit_log('memos', 'FORWARD', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_director_approve')) {
    function memo_director_decision_catalog(): array
    {
        return [
            'director_signed' => [
                'routeAction' => 'DIRECTOR_SIGNED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'ลงนามแล้ว',
            ],
            'director_acknowledged' => [
                'routeAction' => 'DIRECTOR_ACKNOWLEDGED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'ทราบ',
            ],
            'director_agreed' => [
                'routeAction' => 'DIRECTOR_AGREED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'ชอบ',
            ],
            'director_notified' => [
                'routeAction' => 'DIRECTOR_NOTIFIED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'แจ้ง',
            ],
            'director_assigned' => [
                'routeAction' => 'DIRECTOR_ASSIGNED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'มอบ',
            ],
            'director_scheduled' => [
                'routeAction' => 'DIRECTOR_SCHEDULED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'ลงนัด',
            ],
            'director_permitted' => [
                'routeAction' => 'DIRECTOR_PERMITTED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'อนุญาต',
            ],
            'director_approved' => [
                'routeAction' => 'DIRECTOR_APPROVED',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'อนุมัติ',
            ],
            'director_rejected' => [
                'routeAction' => 'DIRECTOR_REJECTED',
                'status' => MEMO_STATUS_REJECTED,
                'label' => 'ไม่อนุมัติ',
            ],
            'director_request_meeting' => [
                'routeAction' => 'DIRECTOR_REQUEST_MEETING',
                'status' => MEMO_STATUS_SIGNED,
                'label' => 'ขอพบ',
            ],
        ];
    }
}

if (!function_exists('memo_director_resolve_decision')) {
    function memo_director_resolve_decision(string $decisionKey): array
    {
        $decisionKey = strtolower(trim($decisionKey));
        $catalog = memo_director_decision_catalog();

        if ($decisionKey === 'director_approve') {
            $decisionKey = 'director_approved';
        } elseif ($decisionKey === 'director_reject') {
            $decisionKey = 'director_rejected';
        }

        if (!isset($catalog[$decisionKey])) {
            throw new RuntimeException('รูปแบบการดำเนินการของผู้อำนวยการไม่ถูกต้อง');
        }

        return $catalog[$decisionKey];
    }
}

if (!function_exists('memo_director_process')) {
    function memo_director_process(int $memoID, string $actorPID, string $decisionKey, string $note = ''): void
    {
        $actorPID = trim($actorPID);
        $note = trim($note);
        $decision = memo_director_resolve_decision($decisionKey);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์พิจารณารายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
                throw new RuntimeException('ไม่สามารถอนุมัติได้ในสถานะปัจจุบัน');
            }

            if (memo_is_chain_mode($memo)) {
                $stage = memo_infer_chain_stage_by_actor($memo, $actorPID);

                if ($stage !== 'DIRECTOR') {
                    throw new RuntimeException('อนุมัติขั้นสุดท้ายได้เฉพาะผู้อำนวยการ/ผู้รักษาการ');
                }
            }

            $now = date('Y-m-d H:i:s');
            $ownerPID = trim((string) ($memo['createdByPID'] ?? ''));
            $storedNote = $note !== '' ? $note : (string) ($decision['label'] ?? '');
            memo_update_record($memoID, [
                'status' => (string) ($decision['status'] ?? MEMO_STATUS_SIGNED),
                'flowStage' => 'OWNER',
                'toType' => 'PERSON',
                'toPID' => $ownerPID,
                'reviewNote' => $storedNote,
                'reviewedAt' => $now,
                'firstReadAt' => empty($memo['firstReadAt']) ? $now : ($memo['firstReadAt'] ?? null),
                'approvedByPID' => $actorPID,
                'approvedAt' => $now,
                'updatedByPID' => $actorPID,
            ]);
            memo_add_route(
                $memoID,
                (string) ($decision['routeAction'] ?? 'DIRECTOR_APPROVED'),
                $fromStatus,
                (string) ($decision['status'] ?? MEMO_STATUS_SIGNED),
                $actorPID,
                $storedNote !== '' ? $storedNote : null
            );

            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);

                if ($ownerPID !== '') {
                    document_add_recipients($documentID, [$ownerPID], INBOX_TYPE_NORMAL);
                }
            }

            db_commit();
            audit_log('memos', (string) ($decision['routeAction'] ?? 'DIRECTOR_APPROVED'), 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo director process failed: ' . $e->getMessage());
            audit_log('memos', (string) ($decision['routeAction'] ?? 'DIRECTOR_APPROVED'), 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_director_approve')) {
    function memo_director_approve(int $memoID, string $actorPID, string $note = ''): void
    {
        memo_director_process($memoID, $actorPID, 'director_approved', $note);
    }
}

if (!function_exists('memo_director_reject')) {
    function memo_director_reject(int $memoID, string $actorPID, string $note): void
    {
        memo_director_process($memoID, $actorPID, 'director_rejected', $note);
    }
}

if (!function_exists('memo_reject')) {
    function memo_reject(int $memoID, string $actorPID, string $note): void
    {
        $actorPID = trim($actorPID);
        $note = trim($note);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        if ($note === '') {
            throw new RuntimeException('กรุณากรอกเหตุผล/ความเห็น');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์พิจารณารายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
                throw new RuntimeException('ไม่สามารถไม่อนุมัติได้ในสถานะปัจจุบัน');
            }

            if (memo_is_chain_mode($memo)) {
                $stage = memo_infer_chain_stage_by_actor($memo, $actorPID);

                if ($stage !== 'DIRECTOR') {
                    throw new RuntimeException('ไม่อนุมัติขั้นสุดท้ายได้เฉพาะผู้อำนวยการ/ผู้รักษาการ');
                }
            }

            $now = date('Y-m-d H:i:s');
            $updates = [
                'status' => MEMO_STATUS_REJECTED,
                'reviewNote' => $note,
                'reviewedAt' => $now,
                'flowStage' => memo_is_chain_mode($memo) ? 'OWNER' : ($memo['flowStage'] ?? null),
                'toType' => memo_is_chain_mode($memo) ? 'PERSON' : ($memo['toType'] ?? null),
                'toPID' => memo_is_chain_mode($memo) ? (string) ($memo['createdByPID'] ?? '') : ($memo['toPID'] ?? null),
                'approvedByPID' => $actorPID,
                'approvedAt' => $now,
                'updatedByPID' => $actorPID,
            ];

            if (empty($memo['firstReadAt'])) {
                $updates['firstReadAt'] = $now;
            }
            memo_update_record($memoID, [
                ...$updates,
            ]);
            memo_add_route($memoID, 'REJECT', $fromStatus, MEMO_STATUS_REJECTED, $actorPID, $note);
            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);
            }

            db_commit();
            audit_log('memos', 'REJECT', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo reject failed: ' . $e->getMessage());
            audit_log('memos', 'REJECT', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_approve_unsigned')) {
    function memo_approve_unsigned(int $memoID, string $actorPID, string $note): void
    {
        $actorPID = trim($actorPID);
        $note = trim($note);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        if ($note === '') {
            $note = 'ลงนามแล้ว';
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์พิจารณารายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
                throw new RuntimeException('ไม่สามารถอนุมัติได้ในสถานะปัจจุบัน');
            }

            $now = date('Y-m-d H:i:s');
            $ownerPID = trim((string) ($memo['createdByPID'] ?? ''));
            $directorPID = trim((string) ($memo['directorPID'] ?? ''));
            $actorStage = memo_resolve_direct_recipient_stage($actorPID, $directorPID);
            $shouldReturnToOwner = memo_is_chain_mode($memo) || $actorStage === 'DEPUTY';
            $updates = [
                'status' => MEMO_STATUS_APPROVED_UNSIGNED,
                'reviewNote' => $note,
                'reviewedAt' => $now,
                'approvedByPID' => $actorPID,
                'approvedAt' => $now,
                'updatedByPID' => $actorPID,
            ];

            if ($shouldReturnToOwner) {
                $stage = memo_is_chain_mode($memo) ? memo_infer_chain_stage_by_actor($memo, $actorPID) : $actorStage;

                if ($stage !== 'DEPUTY') {
                    throw new RuntimeException('โหมดเสนอแฟ้มตามลำดับรองรับสถานะ "ลงนามแล้ว" เฉพาะรองผู้อำนวยการ');
                }

                $updates['flowMode'] = 'CHAIN';
                $updates['flowStage'] = 'OWNER';
                $updates['toType'] = 'PERSON';
                $updates['toPID'] = $ownerPID;
                $updates['deputyPID'] = trim((string) ($memo['deputyPID'] ?? '')) !== ''
                    ? trim((string) $memo['deputyPID'])
                    : $actorPID;

                if ($directorPID === '') {
                    $updates['directorPID'] = trim((string) (system_get_current_director_pid() ?? ''));
                }
            }

            if (empty($memo['firstReadAt'])) {
                $updates['firstReadAt'] = $now;
            }
            memo_update_record($memoID, [
                ...$updates,
            ]);
            memo_add_route($memoID, 'APPROVE_UNSIGNED', $fromStatus, MEMO_STATUS_APPROVED_UNSIGNED, $actorPID, $note);
            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);

                if ($shouldReturnToOwner && $ownerPID !== '') {
                    document_add_recipients($documentID, [$ownerPID], INBOX_TYPE_NORMAL);
                }
            }

            db_commit();
            audit_log('memos', 'APPROVE_UNSIGNED', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo approve unsigned failed: ' . $e->getMessage());
            audit_log('memos', 'APPROVE_UNSIGNED', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_sign_with_upload')) {
    function memo_sign_with_upload(int $memoID, string $actorPID, array $file, ?string $note = null): void
    {
        $actorPID = trim($actorPID);
        $note = trim((string) $note);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        if (empty($file)) {
            throw new RuntimeException('กรุณาแนบไฟล์ฉบับลงนามแล้ว');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if (trim((string) ($memo['toPID'] ?? '')) !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์ลงนามรายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (!in_array($fromStatus, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW, MEMO_STATUS_APPROVED_UNSIGNED], true)) {
                throw new RuntimeException('ไม่สามารถลงนามได้ในสถานะปัจจุบัน');
            }

            if (memo_is_chain_mode($memo)) {
                throw new RuntimeException('โหมดเสนอแฟ้มตามลำดับไม่รองรับการลงนามด้วยการอัปโหลดไฟล์');
            }

            $stored = upload_store_files($file, MEMO_MODULE_NAME, MEMO_ENTITY_NAME, (string) $memoID, $actorPID, [
                'max_files' => 1,
            ]);
            $fileID = (int) ($stored[0]['fileID'] ?? 0);

            if ($fileID <= 0) {
                throw new RuntimeException('แนบไฟล์ไม่สำเร็จ');
            }

            $now = date('Y-m-d H:i:s');
            memo_update_record($memoID, [
                'status' => MEMO_STATUS_SIGNED,
                'reviewNote' => $note !== '' ? $note : ($memo['reviewNote'] ?? null),
                'reviewedAt' => $now,
                'firstReadAt' => empty($memo['firstReadAt']) ? $now : ($memo['firstReadAt'] ?? null),
                'signedFileID' => $fileID,
                'approvedByPID' => $actorPID,
                'approvedAt' => $now,
                'updatedByPID' => $actorPID,
            ]);
            memo_add_route($memoID, 'SIGN', $fromStatus, MEMO_STATUS_SIGNED, $actorPID, $note !== '' ? $note : null);

            $documentID = memo_sync_document($memoID);

            if ($documentID) {
                document_mark_read($documentID, $actorPID);
                document_record_read_receipt($documentID, $actorPID);
            }

            db_commit();
            audit_log('memos', 'SIGN', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo sign failed: ' . $e->getMessage());
            audit_log('memos', 'SIGN', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_cancel')) {
    function memo_cancel(int $memoID, string $actorPID): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT * FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if ((string) ($memo['createdByPID'] ?? '') !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์ยกเลิกรายการนี้');
            }

            $fromStatus = (string) ($memo['status'] ?? '');

            if (in_array($fromStatus, [MEMO_STATUS_SIGNED, MEMO_STATUS_REJECTED], true)) {
                throw new RuntimeException('ไม่สามารถยกเลิกได้ เนื่องจากปิดงานแล้ว');
            }

            $now = date('Y-m-d H:i:s');
            memo_update_record($memoID, [
                'status' => MEMO_STATUS_CANCELLED,
                'cancelledByPID' => $actorPID,
                'cancelledAt' => $now,
                'updatedByPID' => $actorPID,
            ]);
            memo_add_route($memoID, 'CANCEL', $fromStatus, MEMO_STATUS_CANCELLED, $actorPID, null);

            if (trim((string) ($memo['memoNo'] ?? '')) !== '') {
                memo_sync_document($memoID);
            }

            db_commit();
            audit_log('memos', 'CANCEL', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo cancel failed: ' . $e->getMessage());
            audit_log('memos', 'CANCEL', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_set_archived')) {
    function memo_set_archived(int $memoID, string $actorPID, bool $archived): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        db_begin();

        try {
            $memo = db_fetch_one('SELECT memoID, createdByPID, status, isArchived FROM dh_memos WHERE memoID = ? FOR UPDATE', 'i', $memoID);

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if ((string) ($memo['createdByPID'] ?? '') !== $actorPID) {
                throw new RuntimeException('ไม่มีสิทธิ์จัดเก็บรายการนี้');
            }

            $status = (string) ($memo['status'] ?? '');

            if ($archived && !in_array($status, [MEMO_STATUS_SIGNED, MEMO_STATUS_REJECTED, MEMO_STATUS_CANCELLED], true)) {
                throw new RuntimeException('สามารถจัดเก็บได้เฉพาะรายการที่ปิดงานแล้ว');
            }

            $flag = $archived ? 1 : 0;
            $existing = (int) ($memo['isArchived'] ?? 0);

            if ($existing === $flag) {
                db_commit();

                return;
            }

            memo_update_record($memoID, [
                'isArchived' => $flag,
                'archivedAt' => $archived ? date('Y-m-d H:i:s') : null,
                'updatedByPID' => $actorPID,
            ]);
            memo_add_route($memoID, 'ARCHIVE', (string) ($memo['status'] ?? ''), (string) ($memo['status'] ?? ''), $actorPID, $archived ? 'archive' : 'unarchive');

            if (trim((string) ($memo['memoNo'] ?? '')) !== '') {
                memo_sync_document($memoID);
            }

            db_commit();
            audit_log('memos', $archived ? 'ARCHIVE' : 'UNARCHIVE', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo archive failed: ' . $e->getMessage());
            audit_log('memos', 'ARCHIVE', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('memo_set_reviewer_archived')) {
    function memo_set_reviewer_archived(int $memoID, string $actorPID, bool $archived): void
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งาน');
        }

        memo_ensure_inbox_archives_table();
        db_begin();

        try {
            $memo = db_fetch_one(
                'SELECT memoID, createdByPID, status
                 FROM dh_memos
                 WHERE memoID = ? AND deletedAt IS NULL
                 FOR UPDATE',
                'i',
                $memoID
            );

            if (!$memo) {
                throw new RuntimeException('ไม่พบบันทึกข้อความ');
            }

            if ((string) ($memo['createdByPID'] ?? '') === $actorPID) {
                throw new RuntimeException('รายการนี้เป็นบันทึกข้อความของคุณ กรุณาจัดเก็บจากหน้าบันทึกข้อความของฉัน');
            }

            $visible = db_fetch_one(
                'SELECT 1 AS ok
                 FROM dh_memos AS m
                 WHERE m.memoID = ? AND ' . memo_reviewer_visibility_sql('m', 'mr_archive') . '
                 LIMIT 1',
                'iss',
                $memoID,
                $actorPID,
                $actorPID
            );

            if (!$visible) {
                throw new RuntimeException('ไม่มีสิทธิ์จัดเก็บรายการนี้');
            }

            $flag = $archived ? 1 : 0;
            $archivedAt = $archived ? date('Y-m-d H:i:s') : null;

            db_query(
                'INSERT INTO dh_memo_inbox_archives (memoID, pID, isArchived, archivedAt)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE isArchived = VALUES(isArchived), archivedAt = VALUES(archivedAt)',
                'isis',
                $memoID,
                $actorPID,
                $flag,
                $archivedAt
            );

            memo_add_route(
                $memoID,
                'ARCHIVE',
                (string) ($memo['status'] ?? ''),
                (string) ($memo['status'] ?? ''),
                $actorPID,
                $archived ? 'archive_inbox' : 'unarchive_inbox'
            );

            db_commit();
            audit_log('memos', $archived ? 'ARCHIVE_INBOX' : 'UNARCHIVE_INBOX', 'SUCCESS', MEMO_ENTITY_NAME, $memoID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Memo inbox archive failed: ' . $e->getMessage());
            audit_log('memos', 'ARCHIVE_INBOX', 'FAIL', MEMO_ENTITY_NAME, $memoID, $e->getMessage());
            throw $e;
        }
    }
}
