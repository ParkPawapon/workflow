<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../db/db.php';
require_once __DIR__ . '/../../config/state.php';
require_once __DIR__ . '/../system/positions.php';
require_once __DIR__ . '/status.php';

const MEMO_MODULE_NAME = 'memos';
const MEMO_ENTITY_NAME = 'dh_memos';
const MEMO_ROUTE_ENTITY_NAME = 'dh_memo_routes';

if (!function_exists('memo_get_table_columns')) {
    function memo_get_table_columns(mysqli $connection, string $table = MEMO_ENTITY_NAME): array
    {
        static $cached = [];
        $table = trim($table);

        if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return [];
        }

        if (isset($cached[$table])) {
            return $cached[$table];
        }

        $cached[$table] = [];
        $result = mysqli_query($connection, 'SHOW COLUMNS FROM `' . $table . '`');

        if ($result === false) {
            error_log('Database Error: ' . mysqli_error($connection));

            return $cached[$table];
        }

        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['Field'])) {
                $cached[$table][] = (string) $row['Field'];
            }
        }

        mysqli_free_result($result);

        return $cached[$table];
    }
}

if (!function_exists('memo_has_column')) {
    function memo_has_column(array $columns, string $column): bool
    {
        $column = trim($column);

        if ($column === '') {
            return false;
        }

        return in_array($column, $columns, true);
    }
}

if (!function_exists('memo_prepare_search')) {
    function memo_prepare_search(?string $term): array
    {
        $term = trim((string) $term);

        if ($term === '') {
            return ['', ''];
        }

        $max_len = 120;

        if (function_exists('mb_substr')) {
            $term = (string) mb_substr($term, 0, $max_len);
        } else {
            $term = (string) substr($term, 0, $max_len);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return [$term, '%' . $escaped . '%'];
    }
}

if (!function_exists('memo_reviewer_visibility_sql')) {
    function memo_reviewer_visibility_sql(string $memoAlias = '', string $routeAlias = 'mr'): string
    {
        $memoPrefix = $memoAlias !== '' ? $memoAlias . '.' : '';

        return '(' . $memoPrefix . 'toPID = ? OR EXISTS (
            SELECT 1
            FROM ' . MEMO_ROUTE_ENTITY_NAME . ' AS ' . $routeAlias . '
            WHERE ' . $routeAlias . '.memoID = ' . $memoPrefix . 'memoID
              AND ' . $routeAlias . '.actorPID = ?
        ))';
    }
}

if (!function_exists('memo_create_record')) {
    function memo_create_record(array $data): int
    {
        $connection = db_connection();
        $columns = memo_get_table_columns($connection);

        $fields = ['dh_year', 'subject', 'detail', 'status', 'createdByPID'];
        $placeholders = ['?', '?', '?', '?', '?'];
        $types = 'issss';
        $params = [
            (int) $data['dh_year'],
            (string) $data['subject'],
            $data['detail'] ?? null,
            (string) $data['status'],
            (string) $data['createdByPID'],
        ];

        $optional_map = [
            'memoNo' => ['type' => 's', 'value' => $data['memoNo'] ?? null],
            'memoSeq' => ['type' => 'i', 'value' => $data['memoSeq'] ?? null],
            'writeDate' => ['type' => 's', 'value' => $data['writeDate'] ?? null],
            'toType' => ['type' => 's', 'value' => $data['toType'] ?? null],
            'toPID' => ['type' => 's', 'value' => $data['toPID'] ?? null],
            'flowMode' => ['type' => 's', 'value' => $data['flowMode'] ?? 'CHAIN'],
            'flowStage' => ['type' => 's', 'value' => $data['flowStage'] ?? 'OWNER'],
            'headPID' => ['type' => 's', 'value' => $data['headPID'] ?? null],
            'deputyPID' => ['type' => 's', 'value' => $data['deputyPID'] ?? null],
            'directorPID' => ['type' => 's', 'value' => $data['directorPID'] ?? null],
            'submittedAt' => ['type' => 's', 'value' => $data['submittedAt'] ?? null],
            'firstReadAt' => ['type' => 's', 'value' => $data['firstReadAt'] ?? null],
            'reviewNote' => ['type' => 's', 'value' => $data['reviewNote'] ?? null],
            'reviewedAt' => ['type' => 's', 'value' => $data['reviewedAt'] ?? null],
            'signedFileID' => ['type' => 'i', 'value' => $data['signedFileID'] ?? null],
            'isArchived' => ['type' => 'i', 'value' => $data['isArchived'] ?? 0],
            'archivedAt' => ['type' => 's', 'value' => $data['archivedAt'] ?? null],
            'updatedByPID' => ['type' => 's', 'value' => $data['updatedByPID'] ?? null],
            'cancelledByPID' => ['type' => 's', 'value' => $data['cancelledByPID'] ?? null],
            'cancelledAt' => ['type' => 's', 'value' => $data['cancelledAt'] ?? null],
            // Legacy approval columns (kept for signer/decision metadata)
            'approvedByPID' => ['type' => 's', 'value' => $data['approvedByPID'] ?? null],
            'approvedAt' => ['type' => 's', 'value' => $data['approvedAt'] ?? null],
        ];

        foreach ($optional_map as $field => $meta) {
            if (!memo_has_column($columns, $field)) {
                continue;
            }
            $fields[] = $field;
            $placeholders[] = '?';
            $types .= (string) $meta['type'];
            $params[] = $meta['value'];
        }

        $sql = 'INSERT INTO dh_memos (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = db_query($sql, $types, ...$params);
        $id = db_last_insert_id();
        mysqli_stmt_close($stmt);

        return $id;
    }
}

if (!function_exists('memo_list_by_creator')) {
    function memo_list_by_creator(string $pID): array
    {
        $sql = 'SELECT memoID, memoNo, writeDate, subject, status, toPID, firstReadAt, isArchived, createdAt
            FROM dh_memos
            WHERE createdByPID = ? AND deletedAt IS NULL
            ORDER BY createdAt DESC, memoID DESC';

        return db_fetch_all($sql, 's', $pID);
    }
}

if (!function_exists('memo_count_by_creator')) {
    function memo_count_by_creator(string $pID, bool $archived = false, ?string $status = null, ?string $search = null, ?int $dh_year = null): int
    {
        $archivedFlag = $archived ? 1 : 0;
        $status = trim((string) $status);

        $where = 'createdByPID = ? AND deletedAt IS NULL AND isArchived = ?';
        $types = 'si';
        $params = [$pID, $archivedFlag];

        if ($status === 'signed_all') {
            $where .= ' AND status IN (?, ?)';
            $types .= 'ss';
            $params[] = MEMO_STATUS_APPROVED_UNSIGNED;
            $params[] = MEMO_STATUS_SIGNED;
        } elseif ($status !== '' && $status !== 'all') {
            $where .= ' AND status = ?';
            $types .= 's';
            $params[] = $status;
        }

        if ($dh_year !== null && $dh_year > 0) {
            $where .= ' AND dh_year = ?';
            $types .= 'i';
            $params[] = $dh_year;
        }

        [$term, $like] = memo_prepare_search($search);

        if ($term !== '') {
            $where .= ' AND (subject LIKE ? ESCAPE \'\\\\\' OR memoNo LIKE ? ESCAPE \'\\\\\')';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $row = db_fetch_one('SELECT COUNT(*) AS total FROM dh_memos WHERE ' . $where, $types, ...$params);

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('memo_list_by_creator_page')) {
    function memo_list_by_creator_page(string $pID, bool $archived, ?string $status, ?string $search, int $limit, int $offset, ?string $sort = null, ?int $dh_year = null): array
    {
        $archivedFlag = $archived ? 1 : 0;
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $status = trim((string) $status);
        $sort = strtolower(trim((string) $sort));

        $where = 'm.createdByPID = ? AND m.deletedAt IS NULL AND m.isArchived = ?';
        $types = 'si';
        $params = [$pID, $archivedFlag];

        if ($status === 'signed_all') {
            $where .= ' AND m.status IN (?, ?)';
            $types .= 'ss';
            $params[] = MEMO_STATUS_APPROVED_UNSIGNED;
            $params[] = MEMO_STATUS_SIGNED;
        } elseif ($status !== '' && $status !== 'all') {
            $where .= ' AND m.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        if ($dh_year !== null && $dh_year > 0) {
            $where .= ' AND m.dh_year = ?';
            $types .= 'i';
            $params[] = $dh_year;
        }

        [$term, $like] = memo_prepare_search($search);

        if ($term !== '') {
            $where .= ' AND (m.subject LIKE ? ESCAPE \'\\\\\' OR m.memoNo LIKE ? ESCAPE \'\\\\\')';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $status_order_sql = memo_status_order_case_sql('m.status');
        $timeline_order_sql = 'COALESCE(m.submittedAt, m.createdAt)';
        $timeline_direction = $sort === 'oldest' ? 'ASC' : 'DESC';
        $memo_id_direction = $sort === 'oldest' ? 'ASC' : 'DESC';
        $order_by = $status_order_sql . ' ASC, ' . $timeline_order_sql . ' ' . $timeline_direction . ', m.memoID ' . $memo_id_direction;

        $sql = 'SELECT m.memoID, m.memoNo, m.writeDate, m.subject, m.detail, m.reviewNote, m.status, m.toType, m.toPID, m.firstReadAt, m.submittedAt, m.reviewedAt, m.updatedAt, m.createdAt,
                m.createdByPID, m.flowMode, m.flowStage,
                m.headPID, m.deputyPID, m.directorPID, m.approvedByPID,
                t.fName AS approverName
            FROM dh_memos AS m
            LEFT JOIN teacher AS t ON m.toPID = t.pID
            WHERE ' . $where . '
            ORDER BY ' . $order_by . '
            LIMIT ? OFFSET ?';

        return db_fetch_all($sql, $types . 'ii', ...array_merge($params, [$limit, $offset]));
    }
}

if (!function_exists('memo_list_creator_years')) {
    function memo_list_creator_years(string $pID, bool $archived = false): array
    {
        $archivedFlag = $archived ? 1 : 0;
        $sql = 'SELECT DISTINCT m.dh_year
            FROM dh_memos AS m
            WHERE m.createdByPID = ? AND m.deletedAt IS NULL AND m.isArchived = ?
              AND m.dh_year IS NOT NULL AND m.dh_year >= 2568
            ORDER BY m.dh_year DESC';

        $rows = db_fetch_all($sql, 'si', $pID, $archivedFlag);
        $years = [];

        foreach ($rows as $row) {
            $year = (int) ($row['dh_year'] ?? 0);

            if ($year > 0) {
                $years[] = $year;
            }
        }

        return array_values(array_unique($years));
    }
}

if (!function_exists('memo_count_by_reviewer')) {
    function memo_count_by_reviewer(string $pID, ?string $status = null, ?string $search = null, ?int $dh_year = null): int
    {
        $status = trim((string) $status);

        // Reviewer inbox must not expose drafts or "cancelled-before-submit" records.
        // `submittedAt` may be NULL for legacy rows, so we also allow canonical workflow statuses.
        $where = 'createdByPID <> ? AND deletedAt IS NULL
            AND ' . memo_reviewer_visibility_sql('', 'mr_count') . '
            AND (submittedAt IS NOT NULL OR status IN ("SUBMITTED","IN_REVIEW","RETURNED","APPROVED_UNSIGNED","SIGNED","REJECTED"))';
        $types = 'sss';
        $params = [$pID, $pID, $pID];

        if ($status === 'signed_all') {
            $where .= ' AND status IN (?, ?)';
            $types .= 'ss';
            $params[] = MEMO_STATUS_APPROVED_UNSIGNED;
            $params[] = MEMO_STATUS_SIGNED;
        } elseif ($status !== '' && $status !== 'all') {
            $where .= ' AND status = ?';
            $types .= 's';
            $params[] = $status;
        }

        if ($dh_year !== null && $dh_year > 0) {
            $where .= ' AND dh_year = ?';
            $types .= 'i';
            $params[] = $dh_year;
        }

        [$term, $like] = memo_prepare_search($search);

        if ($term !== '') {
            $where .= ' AND (subject LIKE ? ESCAPE \'\\\\\' OR memoNo LIKE ? ESCAPE \'\\\\\')';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $row = db_fetch_one('SELECT COUNT(*) AS total FROM dh_memos WHERE ' . $where, $types, ...$params);

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('memo_list_by_reviewer_page')) {
    function memo_list_by_reviewer_page(string $pID, ?string $status, ?string $search, int $limit, int $offset, ?int $dh_year = null, ?string $sort = null): array
    {
        $connection = db_connection();
        $creator_position = system_position_join($connection, 'c', 'cp');
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $status = trim((string) $status);
        $sort = strtolower(trim((string) $sort));
        $timeline_direction = $sort === 'oldest' ? 'ASC' : 'DESC';
        $memo_id_direction = $sort === 'oldest' ? 'ASC' : 'DESC';

        // Reviewer inbox must not expose drafts or "cancelled-before-submit" records.
        // `submittedAt` may be NULL for legacy rows, so we also allow canonical workflow statuses.
        $where = 'm.createdByPID <> ? AND m.deletedAt IS NULL
            AND ' . memo_reviewer_visibility_sql('m', 'mr_page') . '
            AND (m.submittedAt IS NOT NULL OR m.status IN ("SUBMITTED","IN_REVIEW","RETURNED","APPROVED_UNSIGNED","SIGNED","REJECTED"))';
        $types = 'sss';
        $params = [$pID, $pID, $pID];

        if ($status === 'signed_all') {
            $where .= ' AND m.status IN (?, ?)';
            $types .= 'ss';
            $params[] = MEMO_STATUS_APPROVED_UNSIGNED;
            $params[] = MEMO_STATUS_SIGNED;
        } elseif ($status !== '' && $status !== 'all') {
            $where .= ' AND m.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        if ($dh_year !== null && $dh_year > 0) {
            $where .= ' AND m.dh_year = ?';
            $types .= 'i';
            $params[] = $dh_year;
        }

        [$term, $like] = memo_prepare_search($search);

        if ($term !== '') {
            $where .= ' AND (m.subject LIKE ? ESCAPE \'\\\\\' OR m.memoNo LIKE ? ESCAPE \'\\\\\')';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT m.memoID, m.memoNo, m.writeDate, m.subject, m.detail, m.reviewNote, m.status,
                m.createdAt, m.firstReadAt, m.submittedAt, m.reviewedAt, m.toType, m.toPID, m.flowMode, m.flowStage,
                m.createdByPID, m.headPID, m.deputyPID, m.directorPID, m.approvedByPID,
                c.fName AS creatorName,
                COALESCE(c.signature, "") AS creatorSignature,
                COALESCE(cf.fName, "") AS creatorFactionName,
                COALESCE(cd.dName, "") AS creatorDepartmentName,
                COALESCE(' . $creator_position['name'] . ', "") AS creatorPositionName,
                a.fName AS approverName
            FROM dh_memos AS m
            LEFT JOIN teacher AS c ON m.createdByPID = c.pID
            LEFT JOIN faction AS cf ON c.fID = cf.fID
            LEFT JOIN department AS cd ON c.dID = cd.dID
            ' . $creator_position['join'] . '
            LEFT JOIN teacher AS a ON m.toPID = a.pID
            WHERE ' . $where . '
            ORDER BY COALESCE(m.submittedAt, m.reviewedAt, m.createdAt) ' . $timeline_direction . ', m.memoID ' . $memo_id_direction . '
            LIMIT ? OFFSET ?';

        return db_fetch_all($sql, $types . 'ii', ...array_merge($params, [$limit, $offset]));
    }
}

if (!function_exists('memo_list_reviewer_years')) {
    function memo_list_reviewer_years(string $pID): array
    {
        $sql = 'SELECT DISTINCT m.dh_year
            FROM dh_memos AS m
            WHERE m.createdByPID <> ? AND m.deletedAt IS NULL
              AND ' . memo_reviewer_visibility_sql('m', 'mr_year') . '
              AND m.dh_year IS NOT NULL AND m.dh_year >= 2568
              AND (m.submittedAt IS NOT NULL OR m.status IN ("SUBMITTED","IN_REVIEW","RETURNED","APPROVED_UNSIGNED","SIGNED","REJECTED"))
            ORDER BY m.dh_year DESC';

        $rows = db_fetch_all($sql, 'sss', $pID, $pID, $pID);
        $years = [];

        foreach ($rows as $row) {
            $year = (int) ($row['dh_year'] ?? 0);

            if ($year > 0) {
                $years[] = $year;
            }
        }

        return array_values(array_unique($years));
    }
}

if (!function_exists('memo_get')) {
    function memo_get(int $memoID): ?array
    {
        $sql = 'SELECT m.*,
                c.fName AS creatorName,
                a.fName AS approverName,
                s.fName AS signerName,
                h.fName AS headName,
                d.fName AS deputyName,
                r.fName AS directorName
            FROM dh_memos AS m
            LEFT JOIN teacher AS c ON m.createdByPID = c.pID
            LEFT JOIN teacher AS a ON m.toPID = a.pID
            LEFT JOIN teacher AS s ON m.approvedByPID = s.pID
            LEFT JOIN teacher AS h ON m.headPID = h.pID
            LEFT JOIN teacher AS d ON m.deputyPID = d.pID
            LEFT JOIN teacher AS r ON m.directorPID = r.pID
            WHERE m.memoID = ?
            LIMIT 1';

        return db_fetch_one($sql, 'i', $memoID);
    }
}

if (!function_exists('memo_update_record')) {
    function memo_update_record(int $memoID, array $data): void
    {
        $connection = db_connection();
        $columns = memo_get_table_columns($connection);
        $fields = [];
        $params = [];
        $types = '';

        foreach ($data as $field => $value) {
            if (!memo_has_column($columns, (string) $field)) {
                continue;
            }
            $fields[] = $field . ' = ?';
            $types .= is_int($value) ? 'i' : 's';
            $params[] = $value;
        }

        if (empty($fields)) {
            return;
        }

        $types .= 'i';
        $params[] = $memoID;

        $sql = 'UPDATE dh_memos SET ' . implode(', ', $fields) . ' WHERE memoID = ?';
        $stmt = db_query($sql, $types, ...$params);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('memo_get_attachments')) {
    function memo_get_attachments(int $memoID): array
    {
        $sql = 'SELECT f.fileID, f.fileName, f.filePath, f.mimeType, f.fileSize
            FROM dh_file_refs AS r
            INNER JOIN dh_files AS f ON r.fileID = f.fileID
            WHERE r.moduleName = ? AND r.entityName = ? AND r.entityID = ? AND f.deletedAt IS NULL
            ORDER BY r.refID ASC';

        return db_fetch_all($sql, 'sss', MEMO_MODULE_NAME, MEMO_ENTITY_NAME, (string) $memoID);
    }
}

if (!function_exists('memo_get_signed_file')) {
    function memo_get_signed_file(int $memoID): ?array
    {
        $memo = memo_get($memoID);

        if (!$memo) {
            return null;
        }

        $fileID = (int) ($memo['signedFileID'] ?? 0);

        if ($fileID <= 0) {
            return null;
        }

        $sql = 'SELECT fileID, fileName, filePath, mimeType, fileSize
            FROM dh_files
            WHERE fileID = ? AND deletedAt IS NULL
            LIMIT 1';

        return db_fetch_one($sql, 'i', $fileID);
    }
}

if (!function_exists('memo_add_route')) {
    function memo_add_route(int $memoID, string $action, ?string $fromStatus, ?string $toStatus, string $actorPID, ?string $note = null): void
    {
        $requestID = app_request_id();

        if (strlen($requestID) > 26) {
            $requestID = substr($requestID, 0, 26);
        }
        $stmt = db_query(
            'INSERT INTO dh_memo_routes (memoID, action, fromStatus, toStatus, actorPID, note, requestID)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss',
            $memoID,
            $action,
            $fromStatus,
            $toStatus,
            $actorPID,
            $note,
            $requestID
        );
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('memo_list_routes')) {
    function memo_list_routes(int $memoID): array
    {
        $sql = 'SELECT r.*, t.fName AS actorName
            FROM dh_memo_routes AS r
            LEFT JOIN teacher AS t ON r.actorPID = t.pID
            WHERE r.memoID = ?
            ORDER BY r.createdAt ASC, r.routeID ASC';

        return db_fetch_all($sql, 'i', $memoID);
    }
}
