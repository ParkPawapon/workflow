<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/../system/system.php';
require_once __DIR__ . '/../audit/logger.php';
require_once __DIR__ . '/../../rbac/roles.php';
require_once __DIR__ . '/../../services/uploads.php';
require_once __DIR__ . '/../../services/document-service.php';

if (!function_exists('circular_document_number')) {
    function circular_document_number(int $circularID): string
    {
        return 'CIR-' . $circularID;
    }
}

if (!function_exists('circular_document_type')) {
    function circular_document_type(string $circularType): string
    {
        return strtoupper($circularType) === CIRCULAR_TYPE_EXTERNAL ? 'EXTERNAL' : 'INTERNAL';
    }
}

if (!function_exists('circular_sync_document')) {
    function circular_sync_document(int $circularID): ?int
    {
        $circular = circular_get($circularID);

        if (!$circular) {
            return null;
        }

        $documentType = circular_document_type((string) ($circular['circularType'] ?? CIRCULAR_TYPE_INTERNAL));
        $documentNumber = circular_document_number($circularID);
        $senderName = (string) ($circular['senderName'] ?? '');

        return document_upsert([
            'documentType' => $documentType,
            'documentNumber' => $documentNumber,
            'subject' => (string) ($circular['subject'] ?? ''),
            'content' => (string) ($circular['detail'] ?? ''),
            'status' => (string) ($circular['status'] ?? ''),
            'senderName' => $senderName !== '' ? $senderName : null,
            'createdByPID' => (string) ($circular['createdByPID'] ?? ''),
            'updatedByPID' => $circular['updatedByPID'] ?? null,
        ]);
    }
}

if (!function_exists('circular_resolve_person_ids')) {
    function circular_resolve_person_ids(array $factionIds, array $roleIds, array $personIds): array
    {
        $connection = db_connection();
        $pids = [];

        $personIds = array_values(array_filter(array_map('trim', $personIds), static function (string $pid): bool {
            return $pid !== '' && ctype_digit($pid);
        }));

        foreach ($personIds as $pid) {
            $pids[] = $pid;
        }

        $factionIds = array_values(array_filter(array_map('intval', $factionIds)));

        if (!empty($factionIds)) {
            $placeholders = implode(', ', array_fill(0, count($factionIds), '?'));
            $types = str_repeat('i', count($factionIds));
            $sql = 'SELECT pID FROM teacher WHERE status = 1 AND fID IN (' . $placeholders . ')';
            $stmt = db_query($sql, $types, ...$factionIds);
            $result = mysqli_stmt_get_result($stmt);

            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $pid = trim((string) ($row['pID'] ?? ''));

                if ($pid !== '' && ctype_digit($pid)) {
                    $pids[] = $pid;
                }
            }
            mysqli_stmt_close($stmt);
        }

        $roleIds = array_values(array_filter(array_map('intval', $roleIds)));

        if (!empty($roleIds)) {
            $role_condition = rbac_csv_role_condition('roleID', count($roleIds));
            $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds));
            $sql = 'SELECT pID FROM teacher WHERE status = 1 AND ' . $role_condition;
            $stmt = db_query($sql, $types, ...$roleIds);
            $result = mysqli_stmt_get_result($stmt);

            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $pid = trim((string) ($row['pID'] ?? ''));

                if ($pid !== '' && ctype_digit($pid)) {
                    $pids[] = $pid;
                }
            }
            mysqli_stmt_close($stmt);

            if (db_table_exists($connection, 'dh_user_roles')) {
                $sql = 'SELECT DISTINCT t.pID
                    FROM teacher AS t
                    INNER JOIN dh_user_roles AS ur ON ur.pID = t.pID
                    WHERE t.status = 1 AND ur.roleID IN (' . $placeholders . ')';
                $stmt = db_query($sql, $types, ...$roleIds);
                $result = mysqli_stmt_get_result($stmt);

                while ($result && ($row = mysqli_fetch_assoc($result))) {
                    $pid = trim((string) ($row['pID'] ?? ''));

                    if ($pid !== '' && ctype_digit($pid)) {
                        $pids[] = $pid;
                    }
                }
                mysqli_stmt_close($stmt);
            }

            if (db_table_exists($connection, 'user_roles')) {
                $sql = 'SELECT DISTINCT t.pID
                    FROM teacher AS t
                    INNER JOIN user_roles AS ur ON ur.teacher_id = t.pID
                    WHERE t.status = 1 AND ur.role_id IN (' . $placeholders . ')';
                $stmt = db_query($sql, $types, ...$roleIds);
                $result = mysqli_stmt_get_result($stmt);

                while ($result && ($row = mysqli_fetch_assoc($result))) {
                    $pid = trim((string) ($row['pID'] ?? ''));

                    if ($pid !== '' && ctype_digit($pid)) {
                        $pids[] = $pid;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }

        $pids = array_values(array_unique(array_filter($pids, static function ($pid): bool {
            return is_string($pid) && $pid !== '' && ctype_digit($pid);
        })));

        return $pids;
    }
}

if (!function_exists('circular_registry_pids')) {
    function circular_registry_pids(): array
    {
        $connection = db_connection();
        $registry_ids = rbac_resolve_role_ids($connection, ROLE_REGISTRY);

        if (empty($registry_ids)) {
            return [];
        }

        $role_condition = rbac_csv_role_condition('roleID', count($registry_ids));
        $placeholders = implode(', ', array_fill(0, count($registry_ids), '?'));
        $types = str_repeat('i', count($registry_ids));
        $sql = 'SELECT pID FROM teacher WHERE status = 1 AND ' . $role_condition;
        $stmt = db_query($sql, $types, ...$registry_ids);
        $result = mysqli_stmt_get_result($stmt);
        $pids = [];

        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $pids[] = (string) $row['pID'];
        }
        mysqli_stmt_close($stmt);

        if (db_table_exists($connection, 'dh_user_roles')) {
            $sql = 'SELECT DISTINCT t.pID
                FROM teacher AS t
                INNER JOIN dh_user_roles AS ur ON ur.pID = t.pID
                WHERE t.status = 1 AND ur.roleID IN (' . $placeholders . ')';
            $stmt = db_query($sql, $types, ...$registry_ids);
            $result = mysqli_stmt_get_result($stmt);

            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $pids[] = (string) $row['pID'];
            }
            mysqli_stmt_close($stmt);
        }

        if (db_table_exists($connection, 'user_roles')) {
            $sql = 'SELECT DISTINCT t.pID
                FROM teacher AS t
                INNER JOIN user_roles AS ur ON ur.teacher_id = t.pID
                WHERE t.status = 1 AND ur.role_id IN (' . $placeholders . ')';
            $stmt = db_query($sql, $types, ...$registry_ids);
            $result = mysqli_stmt_get_result($stmt);

            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $pids[] = (string) $row['pID'];
            }
            mysqli_stmt_close($stmt);
        }

        return array_values(array_unique($pids));
    }
}

if (!function_exists('circular_external_manager_pids')) {
    function circular_external_manager_pids(): array
    {
        $connection = db_connection();
        $role_ids = array_values(array_unique(array_merge(
            rbac_resolve_role_ids($connection, ROLE_REGISTRY),
            rbac_resolve_role_ids($connection, ROLE_ADMIN)
        )));

        if (empty($role_ids)) {
            return [];
        }

        $role_condition = rbac_csv_role_condition('roleID', count($role_ids));
        $placeholders = implode(', ', array_fill(0, count($role_ids), '?'));
        $types = str_repeat('i', count($role_ids));
        $pids = [];

        $stmt = db_query('SELECT pID FROM teacher WHERE status = 1 AND ' . $role_condition, $types, ...$role_ids);
        $result = mysqli_stmt_get_result($stmt);

        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid !== '') {
                $pids[] = $pid;
            }
        }
        mysqli_stmt_close($stmt);

        if (db_table_exists($connection, 'dh_user_roles')) {
            $stmt = db_query(
                'SELECT DISTINCT t.pID
                 FROM teacher AS t
                 INNER JOIN dh_user_roles AS ur ON ur.pID = t.pID
                 WHERE t.status = 1 AND ur.roleID IN (' . $placeholders . ')',
                $types,
                ...$role_ids
            );
            $result = mysqli_stmt_get_result($stmt);

            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $pid = trim((string) ($row['pID'] ?? ''));

                if ($pid !== '') {
                    $pids[] = $pid;
                }
            }
            mysqli_stmt_close($stmt);
        }

        if (db_table_exists($connection, 'user_roles')) {
            $stmt = db_query(
                'SELECT DISTINCT t.pID
                 FROM teacher AS t
                 INNER JOIN user_roles AS ur ON ur.teacher_id = t.pID
                 WHERE t.status = 1 AND ur.role_id IN (' . $placeholders . ')',
                $types,
                ...$role_ids
            );
            $result = mysqli_stmt_get_result($stmt);

            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $pid = trim((string) ($row['pID'] ?? ''));

                if ($pid !== '') {
                    $pids[] = $pid;
                }
            }
            mysqli_stmt_close($stmt);
        }

        return array_values(array_unique($pids));
    }
}

if (!function_exists('circular_can_manage_external_workflow')) {
    function circular_can_manage_external_workflow(array $circular, string $actorPID): bool
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            return false;
        }

        if (trim((string) ($circular['createdByPID'] ?? '')) === $actorPID) {
            return true;
        }

        return in_array($actorPID, circular_external_manager_pids(), true);
    }
}

if (!function_exists('circular_add_registry_tracking_inboxes')) {
    /**
     * Ensure external circular has tracking inbox rows for all registry users.
     *
     * @return array<int, string> Registry PIDs that should track this circular.
     */
    function circular_add_registry_tracking_inboxes(int $circularID, string $deliveredByPID): array
    {
        $seedPIDs = circular_registry_pids();
        $actorPID = trim($deliveredByPID);

        if ($actorPID !== '' && ctype_digit($actorPID)) {
            $seedPIDs[] = $actorPID;
        }

        $registryPIDs = array_values(array_unique(array_filter(array_map('trim', $seedPIDs), static function (string $pid): bool {
            return $pid !== '' && ctype_digit($pid);
        })));

        if (empty($registryPIDs)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($registryPIDs), '?'));
        $types = 'is' . str_repeat('s', count($registryPIDs));
        $params = array_merge([$circularID, INBOX_TYPE_NORMAL], $registryPIDs);
        $rows = db_fetch_all(
            'SELECT pID
             FROM dh_circular_inboxes
             WHERE circularID = ? AND inboxType = ? AND pID IN (' . $placeholders . ')',
            $types,
            ...$params
        );

        $existing = [];

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid !== '') {
                $existing[$pid] = true;
            }
        }

        $missing = [];

        foreach ($registryPIDs as $pid) {
            if (!isset($existing[$pid])) {
                $missing[] = $pid;
            }
        }

        if (!empty($missing)) {
            circular_add_inboxes($circularID, $missing, INBOX_TYPE_NORMAL, $deliveredByPID);
        }

        return $registryPIDs;
    }
}

if (!function_exists('circular_find_deputy_by_fid')) {
    function circular_find_deputy_by_fid(?int $fID): ?string
    {
        $connection = db_connection();
        $director_pid = system_get_director_pid();
        $deputy_ids = system_position_deputy_ids($connection);

        if (!empty($deputy_ids)) {
            $placeholders = implode(', ', array_fill(0, count($deputy_ids), '?'));
            $types = str_repeat('i', count($deputy_ids));
            $params = $deputy_ids;

            if ($fID === null || $fID <= 0) {
                $sql = 'SELECT pID FROM teacher WHERE positionID IN (' . $placeholders . ') AND status = 1 AND (? = "" OR pID <> ?) ORDER BY pID ASC LIMIT 1';
                $params[] = (string) $director_pid;
                $params[] = (string) $director_pid;
                $row = db_fetch_one($sql, $types . 'ss', ...$params);
            } else {
                $sql = 'SELECT pID FROM teacher WHERE positionID IN (' . $placeholders . ') AND fID = ? AND status = 1 AND (? = "" OR pID <> ?) ORDER BY pID ASC LIMIT 1';
                $params[] = $fID;
                $params[] = (string) $director_pid;
                $params[] = (string) $director_pid;
                $row = db_fetch_one($sql, $types . 'iss', ...$params);
            }

            if ($row && !empty($row['pID'])) {
                return (string) $row['pID'];
            }
        }

        $fallback = $fID === null || $fID <= 0
            ? db_fetch_one('SELECT pID FROM teacher WHERE positionID = 2 AND status = 1 ORDER BY pID ASC LIMIT 1')
            : db_fetch_one('SELECT pID FROM teacher WHERE positionID = 2 AND fID = ? AND status = 1 ORDER BY pID ASC LIMIT 1', 'i', $fID);

        return $fallback ? (string) ($fallback['pID'] ?? '') : null;
    }
}

if (!function_exists('circular_create_internal')) {
    function circular_create_internal(array $data, array $recipients, array $files = []): int
    {
        $connection = db_connection();
        $sender = (string) $data['createdByPID'];

        db_begin();

        try {
            $circularID = circular_create_record($data);
            circular_add_route($circularID, 'CREATE', $sender, null, null, null);

            if (!empty($recipients['targets'])) {
                circular_add_recipients($circularID, $recipients['targets']);
            }

            $recipientPIDs = array_filter(array_unique(array_diff($recipients['pids'], [$sender])));

            if (!empty($recipientPIDs)) {
                circular_add_inboxes($circularID, $recipientPIDs, INBOX_TYPE_NORMAL, $sender);
                circular_add_route($circularID, 'SEND', $sender, null, null, null);
                circular_update_record($circularID, [
                    'status' => INTERNAL_STATUS_SENT,
                    'updatedByPID' => $sender,
                ]);
            }

            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, $recipientPIDs, INBOX_TYPE_NORMAL);
            }

            if (!empty($files)) {
                upload_store_files($files, CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME, (string) $circularID, $sender, [
                    'max_files' => 5,
                ]);
            }

            db_commit();
            audit_log('circulars', 'CREATE_INTERNAL', 'SUCCESS', 'dh_circulars', $circularID, null, ['type' => 'internal']);

            return $circularID;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Circular internal create failed: ' . $e->getMessage());
            audit_log('circulars', 'CREATE_INTERNAL', 'FAIL', 'dh_circulars', null, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_create_external')) {
    function circular_create_external(array $data, string $registryPID, bool $sendNow, array $files = [], ?string $initialReviewerPID = null): int
    {
        $registryNote = $data['registryNote'] ?? null;
        $directorPID = null;
        $acting_pid = null;
        $registryTrackingPIDs = [];
        db_begin();

        try {
            $circularID = circular_create_record($data);
            circular_add_route($circularID, 'CREATE', $registryPID, null, null, $registryNote ? (string) $registryNote : null);

            if (!empty($files)) {
                upload_store_files($files, CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME, (string) $circularID, $registryPID, [
                    'max_files' => 5,
                ]);
            }

            if ($sendNow) {
                $directorPID = trim((string) ($initialReviewerPID ?? ''));

                if ($directorPID === '') {
                    $directorPID = (string) (system_get_current_director_pid() ?? '');
                }

                if ($directorPID === '') {
                    throw new RuntimeException('ไม่พบผู้พิจารณา (ผอ./รักษาการ)');
                }

                $acting_pid = system_get_acting_director_pid();
                $director_inbox_type = ($acting_pid !== null && $acting_pid !== '' && $acting_pid === $directorPID)
                    ? INBOX_TYPE_ACTING_PRINCIPAL
                    : INBOX_TYPE_SPECIAL_PRINCIPAL;
                circular_add_inboxes($circularID, [$directorPID], $director_inbox_type, $registryPID);
                $registryTrackingPIDs = circular_add_registry_tracking_inboxes($circularID, $registryPID);
                circular_update_record($circularID, [
                    'status' => EXTERNAL_STATUS_PENDING_REVIEW,
                    'updatedByPID' => $registryPID,
                ]);
                circular_add_route($circularID, 'SEND', $registryPID, $directorPID, null, $registryNote ? (string) $registryNote : null);
            }

            $documentID = circular_sync_document($circularID);

            if ($documentID && !empty($directorPID)) {
                $inboxType = ($acting_pid !== null && $acting_pid !== '' && $acting_pid === $directorPID)
                    ? INBOX_TYPE_ACTING_PRINCIPAL
                    : INBOX_TYPE_SPECIAL_PRINCIPAL;
                document_add_recipients($documentID, [$directorPID], $inboxType);
            }

            if ($documentID && !empty($registryTrackingPIDs)) {
                document_add_recipients($documentID, $registryTrackingPIDs, INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('circulars', 'CREATE_EXTERNAL', 'SUCCESS', 'dh_circulars', $circularID, null, ['send' => $sendNow]);

            return $circularID;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Circular external create failed: ' . $e->getMessage());
            audit_log('circulars', 'CREATE_EXTERNAL', 'FAIL', 'dh_circulars', null, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_director_review')) {
    function circular_director_review(int $circularID, string $directorPID, ?string $comment, ?int $newFID): void
    {
        db_begin();

        try {
            $current = circular_get($circularID);

            if (!$current || (string) ($current['status'] ?? '') !== EXTERNAL_STATUS_PENDING_REVIEW) {
                throw new RuntimeException('สถานะเอกสารไม่ถูกต้องสำหรับการพิจารณา');
            }
            $update = [
                'status' => EXTERNAL_STATUS_REVIEWED,
                'updatedByPID' => $directorPID,
            ];

            if ($newFID !== null && $newFID > 0) {
                $update['extGroupFID'] = $newFID;
            }
            circular_update_record($circularID, $update);
            circular_add_route($circularID, 'RETURN', $directorPID, null, $newFID, $comment);

            $registryPIDs = circular_registry_pids();

            if (!empty($registryPIDs)) {
                circular_add_inboxes($circularID, $registryPIDs, INBOX_TYPE_SARABAN_RETURN, $directorPID);
            }

            $documentID = circular_sync_document($circularID);

            if ($documentID && !empty($registryPIDs)) {
                document_add_recipients($documentID, $registryPIDs, INBOX_TYPE_SARABAN_RETURN);
            }

            db_commit();
            audit_log('circulars', 'DIRECTOR_REVIEW', 'SUCCESS', 'dh_circulars', $circularID, null, ['note' => $comment]);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Director review failed: ' . $e->getMessage());
            audit_log('circulars', 'DIRECTOR_REVIEW', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_registry_forward_to_deputy')) {
    function circular_registry_forward_to_deputy(int $circularID, string $registryPID, ?int $fID): ?string
    {
        db_begin();

        try {
            $current = circular_get($circularID);

            if (!$current || (string) ($current['status'] ?? '') !== EXTERNAL_STATUS_REVIEWED) {
                throw new RuntimeException('สถานะเอกสารไม่ถูกต้องสำหรับการส่งต่อ');
            }
            $deputyPID = circular_find_deputy_by_fid($fID);

            if (!$deputyPID) {
                throw new RuntimeException('ไม่พบรองผู้อำนวยการตามฝ่ายที่ระบุ');
            }
            circular_add_inboxes($circularID, [$deputyPID], INBOX_TYPE_NORMAL, $registryPID);
            circular_update_record($circularID, [
                'status' => EXTERNAL_STATUS_FORWARDED,
                'updatedByPID' => $registryPID,
            ]);
            circular_add_route($circularID, 'FORWARD', $registryPID, $deputyPID, $fID, null);
            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, [$deputyPID], INBOX_TYPE_NORMAL);
            }
            db_commit();
            audit_log('circulars', 'CLERK_FORWARD', 'SUCCESS', 'dh_circulars', $circularID, null, ['deputy' => $deputyPID]);

            return $deputyPID;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Registry forward failed: ' . $e->getMessage());
            audit_log('circulars', 'CLERK_FORWARD', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_reviewer_forward_to_deputies')) {
    function circular_reviewer_forward_to_deputies(int $circularID, string $reviewerPID, array $deputyPIDs): void
    {
        $deputyPIDs = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $deputyPIDs), static function (string $pid) use ($reviewerPID): bool {
            return $pid !== '' && ctype_digit($pid) && $pid !== $reviewerPID;
        })));

        if (empty($deputyPIDs)) {
            throw new RuntimeException('กรุณาเลือกรองผู้อำนวยการอย่างน้อย 1 คน');
        }

        db_begin();

        try {
            $current = circular_get($circularID);

            if (
                !$current
                || (string) ($current['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
                || (string) ($current['status'] ?? '') !== EXTERNAL_STATUS_PENDING_REVIEW
            ) {
                throw new RuntimeException('สถานะเอกสารไม่ถูกต้องสำหรับการส่งต่อ');
            }

            $targets = [];

            foreach ($deputyPIDs as $deputyPID) {
                $targets[] = [
                    'targetType' => 'PERSON',
                    'fID' => null,
                    'roleID' => null,
                    'pID' => $deputyPID,
                    'isCc' => 0,
                ];
            }

            circular_add_recipients($circularID, $targets);
            circular_add_inboxes($circularID, $deputyPIDs, INBOX_TYPE_NORMAL, $reviewerPID);
            circular_update_record($circularID, [
                'status' => EXTERNAL_STATUS_FORWARDED,
                'updatedByPID' => $reviewerPID,
            ]);

            foreach ($deputyPIDs as $deputyPID) {
                circular_add_route($circularID, 'FORWARD', $reviewerPID, $deputyPID, null, 'REVIEWER_FORWARD_TO_DEPUTY');
            }

            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, $deputyPIDs, INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('circulars', 'REVIEWER_FORWARD', 'SUCCESS', 'dh_circulars', $circularID, null, ['deputies' => $deputyPIDs]);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Reviewer forward failed: ' . $e->getMessage());
            audit_log('circulars', 'REVIEWER_FORWARD', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_registry_forward_to_deputies')) {
    function circular_registry_forward_to_deputies(int $circularID, string $registryPID, array $deputyPIDs): void
    {
        $deputyPIDs = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $deputyPIDs), static function (string $pid) use ($registryPID): bool {
            return $pid !== '' && ctype_digit($pid) && $pid !== $registryPID;
        })));

        if (empty($deputyPIDs)) {
            throw new RuntimeException('กรุณาเลือกรองผู้อำนวยการอย่างน้อย 1 คน');
        }

        db_begin();

        try {
            $current = circular_get($circularID);

            if (
                !$current
                || (string) ($current['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
                || (string) ($current['status'] ?? '') !== EXTERNAL_STATUS_REVIEWED
            ) {
                throw new RuntimeException('สถานะเอกสารไม่ถูกต้องสำหรับการส่งต่อ');
            }

            $targets = [];

            foreach ($deputyPIDs as $deputyPID) {
                $targets[] = [
                    'targetType' => 'PERSON',
                    'fID' => null,
                    'roleID' => null,
                    'pID' => $deputyPID,
                    'isCc' => 0,
                ];
            }

            circular_add_recipients($circularID, $targets);
            circular_add_inboxes($circularID, $deputyPIDs, INBOX_TYPE_NORMAL, $registryPID);
            circular_update_record($circularID, [
                'status' => EXTERNAL_STATUS_FORWARDED,
                'updatedByPID' => $registryPID,
            ]);

            foreach ($deputyPIDs as $deputyPID) {
                circular_add_route($circularID, 'FORWARD', $registryPID, $deputyPID, null, 'CLERK_FORWARD_TO_DEPUTY');
            }

            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, $deputyPIDs, INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('circulars', 'CLERK_FORWARD', 'SUCCESS', 'dh_circulars', $circularID, null, ['deputies' => $deputyPIDs]);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Registry forward failed: ' . $e->getMessage());
            audit_log('circulars', 'CLERK_FORWARD', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_deputy_distribute')) {
    function circular_deputy_distribute(int $circularID, string $deputyPID, array $recipients, ?string $note = null): void
    {
        db_begin();

        try {
            $current = circular_get($circularID);

            if (!$current || (string) ($current['status'] ?? '') !== EXTERNAL_STATUS_FORWARDED) {
                throw new RuntimeException('สถานะเอกสารไม่ถูกต้องสำหรับการกระจายหนังสือ');
            }

            if (!empty($recipients['targets'])) {
                circular_add_recipients($circularID, $recipients['targets']);
            }

            $recipientPIDs = array_filter(array_unique(array_diff($recipients['pids'], [$deputyPID])));

            if (empty($recipientPIDs)) {
                throw new RuntimeException('กรุณาเลือกผู้รับอย่างน้อย 1 คน');
            }

            if (!empty($recipientPIDs)) {
                circular_add_inboxes($circularID, $recipientPIDs, INBOX_TYPE_NORMAL, $deputyPID);
            }
            circular_update_record($circularID, [
                'status' => EXTERNAL_STATUS_FORWARDED,
                'updatedByPID' => $deputyPID,
            ]);
            circular_add_route($circularID, 'APPROVE', $deputyPID, null, null, $note);
            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, $recipientPIDs, INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('circulars', 'DEPUTY_DISTRIBUTE', 'SUCCESS', 'dh_circulars', $circularID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Deputy distribute failed: ' . $e->getMessage());
            audit_log('circulars', 'DEPUTY_DISTRIBUTE', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_external_last_reviewer_pid')) {
    function circular_external_last_reviewer_pid(int $circularID): ?string
    {
        $row = db_fetch_one(
            'SELECT toPID
             FROM dh_circular_routes
             WHERE circularID = ? AND action = "SEND" AND toPID IS NOT NULL AND toPID <> ""
             ORDER BY routeID DESC
             LIMIT 1',
            'i',
            $circularID
        );

        $pid = trim((string) ($row['toPID'] ?? ''));

        return $pid !== '' ? $pid : null;
    }
}

if (!function_exists('circular_recall_external_before_review')) {
    function circular_recall_external_before_review(int $circularID, string $registryPID): bool
    {
        $circular = circular_get($circularID);

        if (
            !$circular
            || !circular_can_manage_external_workflow($circular, $registryPID)
            || (string) ($circular['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
            || (string) ($circular['status'] ?? '') !== EXTERNAL_STATUS_PENDING_REVIEW
        ) {
            return false;
        }

        db_begin();

        try {
            circular_update_record($circularID, [
                'status' => EXTERNAL_STATUS_SUBMITTED,
                'updatedByPID' => $registryPID,
            ]);

            $stmt = db_query(
                'UPDATE dh_circular_inboxes
                 SET isArchived = 1, archivedAt = NOW()
                 WHERE circularID = ? AND inboxType IN (?, ?) AND isArchived = 0',
                'iss',
                $circularID,
                INBOX_TYPE_SPECIAL_PRINCIPAL,
                INBOX_TYPE_ACTING_PRINCIPAL
            );
            mysqli_stmt_close($stmt);

            circular_add_route($circularID, 'RECALL', $registryPID, null, null, 'EXTERNAL_BEFORE_REVIEW');

            $documentID = circular_sync_document($circularID);
            $connection = db_connection();

            if ($documentID && db_table_exists($connection, 'dh_document_recipients')) {
                $stmt = db_query(
                    'UPDATE dh_document_recipients
                     SET inboxStatus = "ARCHIVED"
                     WHERE documentID = ? AND inboxType IN (?, ?)',
                    'iss',
                    $documentID,
                    INBOX_TYPE_SPECIAL_PRINCIPAL,
                    INBOX_TYPE_ACTING_PRINCIPAL
                );
                mysqli_stmt_close($stmt);
            }

            db_commit();
            audit_log('circulars', 'RECALL_EXTERNAL', 'SUCCESS', 'dh_circulars', $circularID, null, [
                'mode' => 'before_review',
            ]);

            return true;
        } catch (Throwable $e) {
            db_rollback();
            error_log('External recall failed: ' . $e->getMessage());
            audit_log('circulars', 'RECALL_EXTERNAL', 'FAIL', 'dh_circulars', $circularID, $e->getMessage(), [
                'mode' => 'before_review',
            ]);
            throw $e;
        }
    }
}

if (!function_exists('circular_edit_and_resend_external')) {
    function circular_edit_and_resend_external(
        int $circularID,
        string $registryPID,
        array $data,
        array $files = [],
        array $removeFileIDs = []
    ): bool {
        $circular = circular_get($circularID);

        if (
            !$circular
            || !circular_can_manage_external_workflow($circular, $registryPID)
            || (string) ($circular['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
            || (string) ($circular['status'] ?? '') !== EXTERNAL_STATUS_SUBMITTED
        ) {
            return false;
        }

        $reviewerPID = trim((string) ($data['reviewerPID'] ?? ''));

        if ($reviewerPID === '') {
            $reviewerPID = (string) (circular_external_last_reviewer_pid($circularID) ?? '');
        }

        if ($reviewerPID === '') {
            $reviewerPID = (string) (system_get_current_director_pid() ?? '');
        }

        if ($reviewerPID === '') {
            throw new RuntimeException('ไม่พบผู้พิจารณา กรุณาเลือกผู้พิจารณาอีกครั้ง');
        }

        $registryNote = trim((string) ($data['registryNote'] ?? ''));
        $acting_pid = system_get_acting_director_pid();
        $director_inbox_type = ($acting_pid !== null && $acting_pid !== '' && $acting_pid === $reviewerPID)
            ? INBOX_TYPE_ACTING_PRINCIPAL
            : INBOX_TYPE_SPECIAL_PRINCIPAL;
        $registryTrackingPIDs = [];

        db_begin();

        try {
            circular_update_record($circularID, [
                'subject' => trim((string) ($data['subject'] ?? '')),
                'detail' => ($data['detail'] ?? '') !== '' ? trim((string) $data['detail']) : null,
                'linkURL' => ($data['linkURL'] ?? '') !== '' ? trim((string) $data['linkURL']) : null,
                'extPriority' => ($data['extPriority'] ?? '') !== '' ? trim((string) $data['extPriority']) : null,
                'extBookNo' => ($data['extBookNo'] ?? '') !== '' ? trim((string) $data['extBookNo']) : null,
                'extIssuedDate' => ($data['extIssuedDate'] ?? '') !== '' ? trim((string) $data['extIssuedDate']) : null,
                'extFromText' => ($data['extFromText'] ?? '') !== '' ? trim((string) $data['extFromText']) : null,
                'extGroupFID' => !empty($data['extGroupFID']) ? (int) $data['extGroupFID'] : null,
                'status' => EXTERNAL_STATUS_PENDING_REVIEW,
                'updatedByPID' => $registryPID,
            ]);

            $stmt = db_query(
                'DELETE FROM dh_circular_inboxes WHERE circularID = ? AND inboxType IN (?, ?)',
                'iss',
                $circularID,
                INBOX_TYPE_SPECIAL_PRINCIPAL,
                INBOX_TYPE_ACTING_PRINCIPAL
            );
            mysqli_stmt_close($stmt);
            circular_add_inboxes($circularID, [$reviewerPID], $director_inbox_type, $registryPID);
            $registryTrackingPIDs = circular_add_registry_tracking_inboxes($circularID, $registryPID);
            circular_add_route($circularID, 'SEND', $registryPID, $reviewerPID, !empty($data['extGroupFID']) ? (int) $data['extGroupFID'] : null, $registryNote !== '' ? $registryNote : 'EDIT_RESEND');

            if (!empty($removeFileIDs)) {
                circular_soft_delete_attachments($circularID, $removeFileIDs);
            }

            if (!empty($files)) {
                upload_store_files($files, CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME, (string) $circularID, $registryPID, [
                    'max_files' => 5,
                ]);
            }

            $documentID = circular_sync_document($circularID);
            $connection = db_connection();

            if ($documentID && db_table_exists($connection, 'dh_document_recipients')) {
                $stmt = db_query(
                    'UPDATE dh_document_recipients
                     SET inboxStatus = "ARCHIVED"
                     WHERE documentID = ? AND inboxType IN (?, ?)',
                    'iss',
                    $documentID,
                    INBOX_TYPE_SPECIAL_PRINCIPAL,
                    INBOX_TYPE_ACTING_PRINCIPAL
                );
                mysqli_stmt_close($stmt);
                document_add_recipients($documentID, [$reviewerPID], $director_inbox_type);
            }

            if ($documentID && !empty($registryTrackingPIDs)) {
                document_add_recipients($documentID, $registryTrackingPIDs, INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('circulars', 'EDIT_RESEND_EXTERNAL', 'SUCCESS', 'dh_circulars', $circularID, null, [
                'reviewer' => $reviewerPID,
            ]);

            return true;
        } catch (Throwable $e) {
            db_rollback();
            error_log('External edit and resend failed: ' . $e->getMessage());
            audit_log('circulars', 'EDIT_RESEND_EXTERNAL', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_recall_internal')) {
    function circular_recall_internal(int $circularID, string $senderPID): bool
    {
        $owner = db_fetch_one('SELECT createdByPID, circularType FROM dh_circulars WHERE circularID = ? LIMIT 1', 'i', $circularID);

        if (!$owner || (string) ($owner['createdByPID'] ?? '') !== $senderPID || (string) ($owner['circularType'] ?? '') !== CIRCULAR_TYPE_INTERNAL) {
            return false;
        }

        $current = circular_get($circularID);

        if (!$current || (string) ($current['status'] ?? '') !== INTERNAL_STATUS_SENT) {
            return false;
        }

        $row = db_fetch_one('SELECT COUNT(*) AS readCount FROM dh_circular_inboxes WHERE circularID = ? AND isRead = 1', 'i', $circularID);
        $readCount = $row ? (int) $row['readCount'] : 0;

        if ($readCount > 0) {
            return false;
        }

        db_begin();

        try {
            circular_update_record($circularID, [
                'status' => INTERNAL_STATUS_RECALLED,
                'updatedByPID' => $senderPID,
            ]);
            $stmt = db_query('UPDATE dh_circular_inboxes SET isArchived = 1, archivedAt = NOW() WHERE circularID = ?', 'i', $circularID);
            mysqli_stmt_close($stmt);
            circular_add_route($circularID, 'RECALL', $senderPID, null, null, null);
            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                db_query('UPDATE dh_document_recipients SET inboxStatus = "ARCHIVED" WHERE documentID = ?', 'i', $documentID);
            }
            db_commit();
            audit_log('circulars', 'RECALL', 'SUCCESS', 'dh_circulars', $circularID);

            return true;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Recall failed: ' . $e->getMessage());
            audit_log('circulars', 'RECALL', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_forward')) {
    function circular_forward(int $circularID, string $fromPID, array $recipients): void
    {
        db_begin();

        try {
            $current = circular_get($circularID);

            if ($current && (string) ($current['circularType'] ?? '') === CIRCULAR_TYPE_INTERNAL) {
                if ((string) ($current['status'] ?? '') !== INTERNAL_STATUS_SENT) {
                    throw new RuntimeException('สถานะเอกสารไม่ถูกต้องสำหรับการส่งต่อ');
                }
            }

            if (!empty($recipients['targets'])) {
                circular_add_recipients($circularID, $recipients['targets']);
            }
            $recipientPIDs = array_filter(array_unique(array_diff($recipients['pids'], [$fromPID])));

            if (empty($recipientPIDs)) {
                throw new RuntimeException('กรุณาเลือกผู้รับอย่างน้อย 1 คน');
            }

            if (!empty($recipientPIDs)) {
                circular_add_inboxes($circularID, $recipientPIDs, INBOX_TYPE_NORMAL, $fromPID);
            }
            circular_add_route($circularID, 'FORWARD', $fromPID, null, null, null);
            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, $recipientPIDs, INBOX_TYPE_NORMAL);
            }
            db_commit();
            audit_log('circulars', 'FORWARD', 'SUCCESS', 'dh_circulars', $circularID);
        } catch (Throwable $e) {
            db_rollback();
            error_log('Forward failed: ' . $e->getMessage());
            audit_log('circulars', 'FORWARD', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_resend_internal')) {
    function circular_resend_internal(int $circularID, string $senderPID): bool
    {
        $circular = circular_get($circularID);

        if (!$circular || (string) ($circular['createdByPID'] ?? '') !== $senderPID) {
            return false;
        }

        if ((string) ($circular['circularType'] ?? '') !== CIRCULAR_TYPE_INTERNAL) {
            return false;
        }

        if ((string) ($circular['status'] ?? '') !== INTERNAL_STATUS_RECALLED) {
            return false;
        }

        $targets = circular_get_recipient_targets($circularID);

        if (empty($targets)) {
            return false;
        }

        $factions = [];
        $roles = [];
        $persons = [];

        foreach ($targets as $target) {
            $type = (string) ($target['targetType'] ?? '');

            if ($type === 'UNIT' && !empty($target['fID'])) {
                $factions[] = (int) $target['fID'];
            } elseif ($type === 'ROLE' && !empty($target['roleID'])) {
                $roles[] = (int) $target['roleID'];
            } elseif ($type === 'PERSON' && !empty($target['pID'])) {
                $persons[] = (string) $target['pID'];
            }
        }

        $recipientPIDs = circular_resolve_person_ids($factions, $roles, $persons);

        if (empty($recipientPIDs)) {
            return false;
        }

        db_begin();

        try {
            $stmt = db_query('DELETE FROM dh_circular_inboxes WHERE circularID = ?', 'i', $circularID);
            mysqli_stmt_close($stmt);
            circular_add_inboxes($circularID, $recipientPIDs, INBOX_TYPE_NORMAL, $senderPID);
            circular_update_record($circularID, [
                'status' => INTERNAL_STATUS_SENT,
                'updatedByPID' => $senderPID,
            ]);
            circular_add_route($circularID, 'SEND', $senderPID, null, null, 'RESEND');
            $documentID = circular_sync_document($circularID);

            if ($documentID) {
                document_add_recipients($documentID, $recipientPIDs, INBOX_TYPE_NORMAL);
            }
            db_commit();
            audit_log('circulars', 'RESEND', 'SUCCESS', 'dh_circulars', $circularID);

            return true;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Resend failed: ' . $e->getMessage());
            audit_log('circulars', 'RESEND', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('circular_soft_delete_attachments')) {
    function circular_soft_delete_attachments(int $circularID, array $fileIDs): int
    {
        $connection = db_connection();
        $normalized = array_values(array_unique(array_filter(array_map(static function ($value): int {
            return (int) $value;
        }, $fileIDs), static function (int $value): bool {
            return $value > 0;
        })));

        if (empty($normalized)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
        $types = 'sss' . str_repeat('i', count($normalized));
        $sql = 'UPDATE dh_files AS f
            INNER JOIN dh_file_refs AS r ON r.fileID = f.fileID
            SET f.deletedAt = NOW()
            WHERE r.moduleName = ? AND r.entityName = ? AND r.entityID = ? AND f.deletedAt IS NULL
              AND f.fileID IN (' . $placeholders . ')';

        $params = array_merge([CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME, (string) $circularID], $normalized);
        $stmt = db_query($sql, $types, ...$params);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        return max(0, (int) $affected);
    }
}

if (!function_exists('circular_edit_and_resend_internal')) {
    function circular_edit_and_resend_internal(
        int $circularID,
        string $senderPID,
        array $data,
        array $recipients,
        array $files = [],
        array $removeFileIDs = []
    ): bool {
        $circular = circular_get($circularID);

        if (!$circular || (string) ($circular['createdByPID'] ?? '') !== $senderPID) {
            return false;
        }

        if ((string) ($circular['circularType'] ?? '') !== CIRCULAR_TYPE_INTERNAL) {
            return false;
        }

        if ((string) ($circular['status'] ?? '') !== INTERNAL_STATUS_RECALLED) {
            return false;
        }

        $recipientPIDs = array_filter(array_unique(array_diff((array) ($recipients['pids'] ?? []), [$senderPID])));

        if (empty($recipientPIDs)) {
            throw new RuntimeException('กรุณาเลือกผู้รับอย่างน้อย 1 รายการ');
        }

        db_begin();

        try {
            circular_update_record($circularID, [
                'subject' => (string) ($data['subject'] ?? ''),
                'detail' => ($data['detail'] ?? '') !== '' ? (string) $data['detail'] : null,
                'linkURL' => ($data['linkURL'] ?? '') !== '' ? (string) $data['linkURL'] : null,
                'fromFID' => !empty($data['fromFID']) ? (int) $data['fromFID'] : null,
                'status' => INTERNAL_STATUS_SENT,
                'updatedByPID' => $senderPID,
            ]);

            $stmt = db_query('DELETE FROM dh_circular_recipients WHERE circularID = ?', 'i', $circularID);
            mysqli_stmt_close($stmt);

            if (!empty($recipients['targets'])) {
                circular_add_recipients($circularID, (array) $recipients['targets']);
            }

            $stmt = db_query('DELETE FROM dh_circular_inboxes WHERE circularID = ?', 'i', $circularID);
            mysqli_stmt_close($stmt);
            circular_add_inboxes($circularID, $recipientPIDs, INBOX_TYPE_NORMAL, $senderPID);
            circular_add_route($circularID, 'SEND', $senderPID, null, null, 'EDIT_RESEND');

            if (!empty($removeFileIDs)) {
                circular_soft_delete_attachments($circularID, $removeFileIDs);
            }

            if (!empty($files)) {
                upload_store_files($files, CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME, (string) $circularID, $senderPID, [
                    'max_files' => 5,
                ]);
            }

            $documentID = circular_sync_document($circularID);
            $connection = db_connection();

            if ($documentID && db_table_exists($connection, 'dh_document_recipients')) {
                $stmt = db_query('DELETE FROM dh_document_recipients WHERE documentID = ?', 'i', $documentID);
                mysqli_stmt_close($stmt);
                document_add_recipients($documentID, $recipientPIDs, INBOX_TYPE_NORMAL);
            }

            db_commit();
            audit_log('circulars', 'EDIT_RESEND_INTERNAL', 'SUCCESS', 'dh_circulars', $circularID);

            return true;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Circular edit and resend failed: ' . $e->getMessage());
            audit_log('circulars', 'EDIT_RESEND_INTERNAL', 'FAIL', 'dh_circulars', $circularID, $e->getMessage());
            throw $e;
        }
    }
}
