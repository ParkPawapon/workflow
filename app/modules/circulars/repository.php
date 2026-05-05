<?php

declare(strict_types=1);

require_once __DIR__ . '/../../db/db.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../services/document-service.php';
require_once __DIR__ . '/../audit/logger.php';
require_once __DIR__ . '/../../config/constants.php';

if (!function_exists('circular_doc_number')) {
    function circular_doc_number(int $circularID): string
    {
        return 'CIR-' . $circularID;
    }
}

const CIRCULAR_MODULE_NAME = 'circulars';
const CIRCULAR_ENTITY_NAME = 'dh_circulars';

if (!function_exists('circular_create_record')) {
    function circular_create_record(array $data): int
    {
        $fields = [
            'dh_year',
            'circularType',
            'subject',
            'detail',
            'linkURL',
            'fromFID',
            'extPriority',
            'extBookNo',
            'extIssuedDate',
            'extFromText',
            'extGroupFID',
        ];
        $types = 'issssissssi';
        $params = [
            (int) $data['dh_year'],
            (string) $data['circularType'],
            (string) $data['subject'],
            $data['detail'] ?? null,
            $data['linkURL'] ?? null,
            $data['fromFID'] ?? null,
            $data['extPriority'] ?? null,
            $data['extBookNo'] ?? null,
            $data['extIssuedDate'] ?? null,
            $data['extFromText'] ?? null,
            $data['extGroupFID'] ?? null,
        ];

        if (array_key_exists('extReceiveSeq', $data)) {
            $fields[] = 'extReceiveSeq';
            $types .= 'i';
            $params[] = $data['extReceiveSeq'] !== null ? (int) $data['extReceiveSeq'] : null;
        }

        $fields[] = 'status';
        $fields[] = 'createdByPID';
        $fields[] = 'updatedByPID';
        $types .= 'sss';
        $params[] = (string) $data['status'];
        $params[] = (string) $data['createdByPID'];
        $params[] = $data['updatedByPID'] ?? null;

        $stmt = db_query(
            'INSERT INTO dh_circulars (' . implode(', ', $fields) . ')
             VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')',
            $types,
            ...$params
        );
        $id = db_last_insert_id();
        mysqli_stmt_close($stmt);

        return $id;
    }
}

if (!function_exists('circular_update_record')) {
    function circular_update_record(int $circularID, array $data): void
    {
        $fields = [];
        $params = [];
        $types = '';

        foreach ($data as $field => $value) {
            $fields[] = $field . ' = ?';

            if (is_int($value)) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
            $params[] = $value;
        }

        if (empty($fields)) {
            return;
        }

        $types .= 'i';
        $params[] = $circularID;

        $sql = 'UPDATE dh_circulars SET ' . implode(', ', $fields) . ' WHERE circularID = ?';
        $stmt = db_query($sql, $types, ...$params);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('circular_add_route')) {
    function circular_add_route(int $circularID, string $action, ?string $fromPID, ?string $toPID, ?int $toFID, ?string $note): void
    {
        $stmt = db_query(
            'INSERT INTO dh_circular_routes (circularID, action, fromPID, toPID, toFID, note) VALUES (?, ?, ?, ?, ?, ?)',
            'isssis',
            $circularID,
            $action,
            $fromPID,
            $toPID,
            $toFID,
            $note
        );
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('circular_add_recipients')) {
    function circular_add_recipients(int $circularID, array $targets): void
    {
        foreach ($targets as $target) {
            $stmt = db_query(
                'INSERT INTO dh_circular_recipients (circularID, targetType, fID, roleID, pID, isCc) VALUES (?, ?, ?, ?, ?, ?)',
                'isiisi',
                $circularID,
                (string) $target['targetType'],
                $target['fID'] ?? null,
                $target['roleID'] ?? null,
                $target['pID'] ?? null,
                $target['isCc'] ?? 0
            );
            mysqli_stmt_close($stmt);
        }
    }
}

if (!function_exists('circular_add_inboxes')) {
    function circular_add_inboxes(int $circularID, array $recipientPIDs, string $inboxType, ?string $deliveredByPID): void
    {
        $recipientPIDs = array_values(array_unique(array_filter(array_map('trim', $recipientPIDs))));

        if (empty($recipientPIDs)) {
            return;
        }

        foreach ($recipientPIDs as $pid) {
            $stmt = db_query(
                'INSERT INTO dh_circular_inboxes (circularID, pID, inboxType, deliveredByPID) VALUES (?, ?, ?, ?)',
                'isss',
                $circularID,
                $pid,
                $inboxType,
                $deliveredByPID
            );
            mysqli_stmt_close($stmt);
        }
    }
}

if (!function_exists('circular_get_inbox_by_types')) {
    function circular_get_inbox_by_types(string $pID, array $inboxTypes, bool $archived = false): array
    {
        $inboxTypes = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $inboxTypes), static function (string $value): bool {
            return $value !== '';
        })));

        if ($inboxTypes === []) {
            return [];
        }

        $archivedFlag = $archived ? 1 : 0;
        $placeholders = implode(', ', array_fill(0, count($inboxTypes), '?'));
        $types = 's' . str_repeat('s', count($inboxTypes)) . 'i';
        $params = array_merge([$pID], $inboxTypes, [$archivedFlag]);
        $sql = 'SELECT i.inboxID, i.isRead, i.readAt, i.isArchived, i.deliveredAt, i.deliveredByPID,
                c.circularID, c.circularType, c.subject, c.detail, c.linkURL, c.status, c.createdAt,
                t.fName AS senderName,
                COALESCE(sf.fName, tf.fName, "") AS senderFactionName,
                COALESCE(dt.fName, "") AS deliveredByName,
                COALESCE(dp.positionName, "") AS deliveredByPositionName
            FROM dh_circular_inboxes AS i
            INNER JOIN dh_circulars AS c ON i.circularID = c.circularID
            LEFT JOIN teacher AS t ON c.createdByPID = t.pID
            LEFT JOIN faction AS sf ON c.fromFID = sf.fID
            LEFT JOIN faction AS tf ON t.fID = tf.fID
            LEFT JOIN teacher AS dt ON i.deliveredByPID = dt.pID
            LEFT JOIN dh_positions AS dp ON dt.positionID = dp.positionID
            WHERE i.pID = ? AND i.inboxType IN (' . $placeholders . ') AND i.isArchived = ?
            ORDER BY i.deliveredAt DESC, i.inboxID DESC';

        return db_fetch_all($sql, $types, ...$params);
    }
}

if (!function_exists('circular_get_inbox')) {
    function circular_get_inbox(string $pID, string $inboxType = 'NORMAL', bool $archived = false): array
    {
        return circular_get_inbox_by_types($pID, [$inboxType], $archived);
    }
}

if (!function_exists('circular_get_inbox_item')) {
    function circular_get_inbox_item(int $inboxID, string $pID): ?array
    {
        $sql = 'SELECT i.inboxID, i.circularID, i.isRead, i.readAt, i.inboxType, i.deliveredByPID,
                c.circularType, c.subject, c.detail, c.linkURL, c.fromFID, c.extPriority, c.extBookNo, c.extIssuedDate,
                c.extFromText, c.extGroupFID, c.status, c.createdByPID, c.createdAt,
                t.fName AS senderName,
                COALESCE(sf.fName, tf.fName, "") AS senderFactionName,
                COALESCE(dt.fName, "") AS deliveredByName,
                COALESCE(dp.positionName, "") AS deliveredByPositionName
            FROM dh_circular_inboxes AS i
            INNER JOIN dh_circulars AS c ON i.circularID = c.circularID
            LEFT JOIN teacher AS t ON c.createdByPID = t.pID
            LEFT JOIN faction AS sf ON c.fromFID = sf.fID
            LEFT JOIN faction AS tf ON t.fID = tf.fID
            LEFT JOIN teacher AS dt ON i.deliveredByPID = dt.pID
            LEFT JOIN dh_positions AS dp ON dt.positionID = dp.positionID
            WHERE i.inboxID = ? AND i.pID = ?
            LIMIT 1';

        return db_fetch_one($sql, 'is', $inboxID, $pID);
    }
}

if (!function_exists('circular_mark_read')) {
    function circular_mark_read(int $inboxID, string $pID): void
    {
        $stmt = db_query(
            'UPDATE dh_circular_inboxes SET isRead = 1, readAt = NOW() WHERE inboxID = ? AND pID = ? AND isRead = 0',
            'is',
            $inboxID,
            $pID
        );
        mysqli_stmt_close($stmt);

        $row = db_fetch_one('SELECT circularID FROM dh_circular_inboxes WHERE inboxID = ? AND pID = ? LIMIT 1', 'is', $inboxID, $pID);
        $circularID = (int) ($row['circularID'] ?? 0);

        if ($circularID > 0) {
            $circular = circular_get($circularID);

            if ($circular) {
                $documentType = strtoupper((string) ($circular['circularType'] ?? CIRCULAR_TYPE_INTERNAL)) === CIRCULAR_TYPE_EXTERNAL ? 'EXTERNAL' : 'INTERNAL';
                $documentNumber = circular_doc_number($circularID);
                $documentID = document_upsert([
                    'documentType' => $documentType,
                    'documentNumber' => $documentNumber,
                    'subject' => (string) ($circular['subject'] ?? ''),
                    'content' => (string) ($circular['detail'] ?? ''),
                    'status' => (string) ($circular['status'] ?? ''),
                    'senderName' => (string) ($circular['senderName'] ?? ''),
                    'createdByPID' => (string) ($circular['createdByPID'] ?? ''),
                    'updatedByPID' => $circular['updatedByPID'] ?? null,
                ]);

                if ($documentID) {
                    document_mark_read($documentID, $pID);
                    document_record_read_receipt($documentID, $pID);
                }

                if (function_exists('audit_log')) {
                    audit_log('circulars', 'READ', 'SUCCESS', 'dh_circulars', $circularID, null, [
                        'inbox_id' => $inboxID,
                        'request_id' => app_request_id(),
                    ]);
                }
            }
        }
    }
}

if (!function_exists('circular_archive_inbox')) {
    function circular_archive_inbox(int $inboxID, string $pID): void
    {
        $stmt = db_query(
            'UPDATE dh_circular_inboxes SET isArchived = 1, archivedAt = NOW() WHERE inboxID = ? AND pID = ?',
            'is',
            $inboxID,
            $pID
        );
        mysqli_stmt_close($stmt);

        $row = db_fetch_one('SELECT circularID, inboxType FROM dh_circular_inboxes WHERE inboxID = ? AND pID = ? LIMIT 1', 'is', $inboxID, $pID);
        $circularID = (int) ($row['circularID'] ?? 0);
        $inboxType = (string) ($row['inboxType'] ?? INBOX_TYPE_NORMAL);

        if ($circularID > 0) {
            $circular = circular_get($circularID);

            if ($circular) {
                $documentType = strtoupper((string) ($circular['circularType'] ?? CIRCULAR_TYPE_INTERNAL)) === CIRCULAR_TYPE_EXTERNAL ? 'EXTERNAL' : 'INTERNAL';
                $documentID = document_get_id($documentType, circular_doc_number($circularID));

                if ($documentID) {
                    document_set_recipient_status($documentID, $pID, $inboxType, 'ARCHIVED');
                }
            }
        }
    }
}

if (!function_exists('circular_unarchive_inbox')) {
    function circular_unarchive_inbox(int $inboxID, string $pID): void
    {
        $stmt = db_query(
            'UPDATE dh_circular_inboxes SET isArchived = 0, archivedAt = NULL WHERE inboxID = ? AND pID = ?',
            'is',
            $inboxID,
            $pID
        );
        mysqli_stmt_close($stmt);

        $row = db_fetch_one('SELECT circularID, inboxType, isRead FROM dh_circular_inboxes WHERE inboxID = ? AND pID = ? LIMIT 1', 'is', $inboxID, $pID);
        $circularID = (int) ($row['circularID'] ?? 0);
        $inboxType = (string) ($row['inboxType'] ?? INBOX_TYPE_NORMAL);
        $isRead = (int) ($row['isRead'] ?? 0) === 1;

        if ($circularID > 0) {
            $circular = circular_get($circularID);

            if ($circular) {
                $documentType = strtoupper((string) ($circular['circularType'] ?? CIRCULAR_TYPE_INTERNAL)) === CIRCULAR_TYPE_EXTERNAL ? 'EXTERNAL' : 'INTERNAL';
                $documentID = document_get_id($documentType, circular_doc_number($circularID));

                if ($documentID) {
                    document_set_recipient_status($documentID, $pID, $inboxType, $isRead ? 'READ' : 'UNREAD');
                }
            }
        }
    }
}

if (!function_exists('circular_get')) {
    function circular_get(int $circularID): ?array
    {
        $sql = 'SELECT c.*, t.fName AS senderName, COALESCE(sf.fName, tf.fName, "") AS senderFactionName
            FROM dh_circulars AS c
            LEFT JOIN teacher AS t ON c.createdByPID = t.pID
            LEFT JOIN faction AS sf ON c.fromFID = sf.fID
            LEFT JOIN faction AS tf ON t.fID = tf.fID
            WHERE c.circularID = ?
            LIMIT 1';

        return db_fetch_one($sql, 'i', $circularID);
    }
}

if (!function_exists('circular_list_sent')) {
    function circular_list_sent(string $pID): array
    {
        $sql = 'SELECT c.circularID, c.circularType, c.subject, c.status, c.createdAt,
                COALESCE(sf.fName, tf.fName, "") AS senderFactionName,
                (SELECT COUNT(*) FROM dh_circular_inboxes WHERE circularID = c.circularID) AS recipientCount,
                (SELECT COUNT(*) FROM dh_circular_inboxes WHERE circularID = c.circularID AND isRead = 1) AS readCount
            FROM dh_circulars AS c
            LEFT JOIN teacher AS t ON c.createdByPID = t.pID
            LEFT JOIN faction AS sf ON c.fromFID = sf.fID
            LEFT JOIN faction AS tf ON t.fID = tf.fID
            WHERE c.createdByPID = ? AND c.deletedAt IS NULL
            ORDER BY c.createdAt DESC, c.circularID DESC';

        return db_fetch_all($sql, 's', $pID);
    }
}

if (!function_exists('circular_get_read_stats')) {
    function circular_get_read_stats(int $circularID): array
    {
        $sql = 'SELECT
                i.pID,
                MAX(CASE WHEN i.isRead = 1 OR rr.routeID IS NOT NULL THEN 1 ELSE 0 END) AS isRead,
                MAX(CASE WHEN i.readAt IS NOT NULL THEN i.readAt ELSE rr.actionAt END) AS readAt,
                t.fName
            FROM dh_circular_inboxes AS i
            INNER JOIN teacher AS t ON i.pID = t.pID
            LEFT JOIN dh_circular_routes AS rr
                ON rr.circularID = i.circularID
               AND rr.fromPID = i.pID
               AND rr.action IN (\'RETURN\', \'APPROVE\', \'FORWARD\')
            WHERE i.circularID = ?
            GROUP BY i.pID, t.fName
            ORDER BY t.fName ASC';

        return db_fetch_all($sql, 'i', $circularID);
    }
}

if (!function_exists('circular_get_recipient_targets')) {
    function circular_get_recipient_targets(int $circularID): array
    {
        $sql = 'SELECT targetType, fID, roleID, pID, isCc FROM dh_circular_recipients WHERE circularID = ?';

        return db_fetch_all($sql, 'i', $circularID);
    }
}

if (!function_exists('circular_get_attachments')) {
    function circular_get_attachments(int $circularID): array
    {
        $sql = 'SELECT f.fileID, f.fileName, f.filePath, f.mimeType, f.fileSize, r.note AS fileNote
            FROM dh_file_refs AS r
            INNER JOIN dh_files AS f ON r.fileID = f.fileID
            WHERE r.moduleName = ? AND r.entityName = ? AND r.entityID = ? AND f.deletedAt IS NULL
            ORDER BY r.refID ASC';

        return db_fetch_all($sql, 'sss', CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME, (string) $circularID);
    }
}

if (!function_exists('circular_get_announcements')) {
    function circular_get_announcements(int $limit = 10): array
    {
        if (!db_table_exists(db_connection(), 'dh_circular_announcements')) {
            return [];
        }

        $limit = max(1, $limit);
        $sql = 'SELECT a.announcementID, a.selectedAt, c.circularID, c.circularType, c.subject,
                c.detail, c.linkURL, c.extPriority, c.extBookNo, c.extIssuedDate, c.extFromText, c.extGroupFID,
                COALESCE(f.fName, "") AS extGroupName,
                COALESCE(announce_route.note, a.note, "") AS announcementComment,
                COALESCE(selected_by.fName, "") AS announcementByName,
                COALESCE(selected_pos.positionName, "") AS announcementByPositionName,
                COALESCE(review.note, "") AS directorComment,
                COALESCE(review.reviewerName, "") AS directorReviewerName
            FROM (
                SELECT circularID, MAX(announcementID) AS announcementID
                FROM dh_circular_announcements
                WHERE isActive = 1
                GROUP BY circularID
            ) AS latest
            INNER JOIN dh_circular_announcements AS a ON a.announcementID = latest.announcementID
            INNER JOIN dh_circulars AS c ON a.circularID = c.circularID
            LEFT JOIN faction AS f ON c.extGroupFID = f.fID
            LEFT JOIN teacher AS selected_by ON selected_by.pID = a.selectedByPID
            LEFT JOIN dh_positions AS selected_pos ON selected_pos.positionID = selected_by.positionID
            LEFT JOIN (
                SELECT r.circularID, r.fromPID, r.note
                FROM dh_circular_routes AS r
                INNER JOIN (
                    SELECT circularID, fromPID, MAX(routeID) AS routeID
                    FROM dh_circular_routes
                    WHERE action = \'APPROVE\'
                    GROUP BY circularID, fromPID
                ) AS latest_announce ON latest_announce.routeID = r.routeID
            ) AS announce_route ON announce_route.circularID = c.circularID AND announce_route.fromPID = a.selectedByPID
            LEFT JOIN (
                SELECT r.circularID, r.note, r.fromPID, COALESCE(t.fName, "") AS reviewerName
                FROM dh_circular_routes AS r
                INNER JOIN (
                    SELECT circularID, MAX(routeID) AS routeID
                    FROM dh_circular_routes
                    WHERE action = \'RETURN\'
                    GROUP BY circularID
                ) AS latest_review ON latest_review.routeID = r.routeID
                LEFT JOIN teacher AS t ON t.pID = r.fromPID
            ) AS review ON review.circularID = c.circularID
            WHERE a.isActive = 1 AND c.deletedAt IS NULL
            ORDER BY a.selectedAt DESC, a.announcementID DESC
            LIMIT ' . $limit;

        $announcements = db_fetch_all($sql);

        foreach ($announcements as &$announcement) {
            $announcement['files'] = circular_get_attachments((int) ($announcement['circularID'] ?? 0));
        }
        unset($announcement);

        return $announcements;
    }
}

if (!function_exists('circular_set_announcement')) {
    function circular_set_announcement(int $circularID, string $selectedByPID, ?string $note = null): void
    {
        if (!db_table_exists(db_connection(), 'dh_circular_announcements')) {
            return;
        }

        $stmt = db_query(
            'INSERT INTO dh_circular_announcements (circularID, selectedByPID, note, isActive) VALUES (?, ?, ?, 1)',
            'iss',
            $circularID,
            $selectedByPID,
            $note
        );
        mysqli_stmt_close($stmt);

        if (function_exists('audit_log')) {
            $announcement_id = db_last_insert_id();
            audit_log('circulars', 'ANNOUNCE', 'SUCCESS', 'dh_circular_announcements', $announcement_id, null, [
                'circularID' => $circularID,
            ]);
        }
    }
}

if (!function_exists('circular_remove_announcement')) {
    function circular_remove_announcement(int $announcementID, string $selectedByPID): void
    {
        if (!db_table_exists(db_connection(), 'dh_circular_announcements')) {
            return;
        }

        $stmt = db_query(
            'UPDATE dh_circular_announcements SET isActive = 0 WHERE announcementID = ?',
            'i',
            $announcementID
        );
        mysqli_stmt_close($stmt);

        if (function_exists('audit_log')) {
            audit_log('circulars', 'UNANNOUNCE', 'SUCCESS', 'dh_circular_announcements', $announcementID, null, [
                'selectedByPID' => $selectedByPID,
            ]);
        }
    }
}
