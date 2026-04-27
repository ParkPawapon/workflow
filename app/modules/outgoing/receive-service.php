<?php

declare(strict_types=1);

require_once __DIR__ . '/service.php';
require_once __DIR__ . '/../circulars/service.php';
require_once __DIR__ . '/../users/lists.php';
require_once __DIR__ . '/../system/system.php';
require_once __DIR__ . '/../../services/uploads.php';
require_once __DIR__ . '/../../db/db.php';

if (!function_exists('outgoing_receive_default_values')) {
    function outgoing_receive_default_values(): array
    {
        return [
            'extPriority' => 'ปกติ',
            'extBookNo' => '',
            'extIssuedDate' => '',
            'subject' => '',
            'extFromText' => '',
            'extGroupFID' => '',
            'linkURL' => '',
            'detail' => '',
            'reviewerPID' => '',
        ];
    }
}

if (!function_exists('outgoing_receive_track_status_map')) {
    function outgoing_receive_track_status_map(): array
    {
        return [
            EXTERNAL_STATUS_SUBMITTED => ['label' => 'รับเข้าแล้ว', 'pill' => 'pending'],
            EXTERNAL_STATUS_PENDING_REVIEW => ['label' => 'กำลังเสนอ', 'pill' => 'pending'],
            EXTERNAL_STATUS_REVIEWED => ['label' => 'พิจารณาแล้ว', 'pill' => 'considered'],
            EXTERNAL_STATUS_FORWARDED => ['label' => 'ส่งแล้ว', 'pill' => 'success'],
        ];
    }
}

if (!function_exists('outgoing_receive_normalize_track_filter_status')) {
    function outgoing_receive_normalize_track_filter_status(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'submitted', 'waiting_attachment' => 'submitted',
            'pending_review' => 'pending_review',
            'reviewed' => 'reviewed',
            'forwarded', 'complete' => 'forwarded',
            default => 'all',
        };
    }
}

if (!function_exists('outgoing_receive_normalize_track_filter_sort')) {
    function outgoing_receive_normalize_track_filter_sort(string $sort): string
    {
        return strtolower(trim($sort)) === 'oldest' ? 'oldest' : 'newest';
    }
}

if (!function_exists('outgoing_receive_list_registered')) {
    function outgoing_receive_list_registered(string $current_pid, string $search = '', string $status_filter = 'all', string $sort = 'newest'): array
    {
        $search = trim($search);
        $status_filter = outgoing_receive_normalize_track_filter_status($status_filter);
        $sort = outgoing_receive_normalize_track_filter_sort($sort);
        $can_manage_external = in_array(trim($current_pid), circular_external_manager_pids(), true);
        $params = [CIRCULAR_TYPE_EXTERNAL];
        $types = 's';
        $sql = 'SELECT
                c.circularID,
                c.subject,
                c.detail,
                c.linkURL,
                c.extPriority,
                c.extBookNo,
                c.extIssuedDate,
                c.extFromText,
                c.extGroupFID,
                c.status,
                c.createdAt,
                c.updatedAt,
                c.createdByPID,
                t.fName AS creatorName,
                f.fName AS groupName
            FROM dh_circulars AS c
            LEFT JOIN teacher AS t ON c.createdByPID = t.pID
            LEFT JOIN faction AS f ON c.extGroupFID = f.fID
            WHERE c.deletedAt IS NULL
              AND c.circularType = ?';

        if (!$can_manage_external) {
            $sql .= ' AND c.createdByPID = ?';
            $types .= 's';
            $params[] = $current_pid;
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (
                c.subject LIKE ?
                OR c.extBookNo LIKE ?
                OR c.extFromText LIKE ?
                OR c.detail LIKE ?
            )';
            $types .= 'ssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status_filter !== 'all') {
            $status_key = match ($status_filter) {
                'submitted' => EXTERNAL_STATUS_SUBMITTED,
                'pending_review' => EXTERNAL_STATUS_PENDING_REVIEW,
                'reviewed' => EXTERNAL_STATUS_REVIEWED,
                'forwarded' => EXTERNAL_STATUS_FORWARDED,
                default => '',
            };

            if ($status_key !== '') {
                $sql .= ' AND c.status = ?';
                $types .= 's';
                $params[] = $status_key;
            }
        }

        $sort_direction = $sort === 'oldest' ? 'ASC' : 'DESC';
        $sql .= ' ORDER BY COALESCE(c.updatedAt, c.createdAt) ' . $sort_direction . ', c.circularID ' . $sort_direction;

        return db_fetch_all($sql, $types, ...$params);
    }
}

if (!function_exists('outgoing_receive_list_attachments_map')) {
    /**
     * @param array<int, int> $circular_ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    function outgoing_receive_list_attachments_map(array $circular_ids): array
    {
        $circular_ids = array_values(array_unique(array_filter(array_map('intval', $circular_ids), static function (int $id): bool {
            return $id > 0;
        })));

        if ($circular_ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($circular_ids), '?'));
        $types = str_repeat('i', count($circular_ids));
        $sql = 'SELECT
                CAST(r.entityID AS UNSIGNED) AS circularID,
                f.fileID,
                f.fileName,
                f.filePath,
                f.mimeType,
                f.fileSize,
                r.note AS fileNote
            FROM dh_file_refs AS r
            INNER JOIN dh_files AS f ON r.fileID = f.fileID
            WHERE r.moduleName = (\'' . CIRCULAR_MODULE_NAME . '\' COLLATE utf8mb4_general_ci)
              AND r.entityName = (\'' . CIRCULAR_ENTITY_NAME . '\' COLLATE utf8mb4_general_ci)
              AND CAST(r.entityID AS UNSIGNED) IN (' . $placeholders . ')
              AND f.deletedAt IS NULL
            ORDER BY r.refID ASC';
        $rows = db_fetch_all($sql, $types, ...$circular_ids);
        $map = [];

        foreach ($rows as $row) {
            $circular_id = (string) ((int) ($row['circularID'] ?? 0));

            if ($circular_id === '0') {
                continue;
            }

            if (!isset($map[$circular_id])) {
                $map[$circular_id] = [];
            }

            $map[$circular_id][] = [
                'fileID' => (int) ($row['fileID'] ?? 0),
                'fileName' => trim((string) ($row['fileName'] ?? '')),
                'filePath' => trim((string) ($row['filePath'] ?? '')),
                'mimeType' => trim((string) ($row['mimeType'] ?? '')),
                'fileSize' => (int) ($row['fileSize'] ?? 0),
                'fileNote' => trim((string) ($row['fileNote'] ?? '')),
            ];
        }

        return $map;
    }
}

if (!function_exists('outgoing_receive_get_reviewer_read_stats')) {
    function outgoing_receive_get_reviewer_read_stats(int $circular_id): array
    {
        $reviewer_pid = (string) (circular_external_last_reviewer_pid($circular_id) ?? '');

        if ($reviewer_pid === '') {
            return [];
        }

        $row = db_fetch_one(
            'SELECT
                t.pID,
                COALESCE(MAX(i.isRead), 0) AS isRead,
                MAX(i.readAt) AS readAt,
                t.fName
             FROM teacher AS t
             LEFT JOIN dh_circular_inboxes AS i
                ON i.pID = t.pID
                AND i.circularID = ?
             WHERE t.pID = ?
             GROUP BY t.pID, t.fName
             LIMIT 1',
            'is',
            $circular_id,
            $reviewer_pid
        );

        return $row ? [$row] : [];
    }
}

if (!function_exists('outgoing_receive_build_track_payload_map')) {
    function outgoing_receive_build_track_payload_map(array $items, array $attachments_map, array $track_status_map): array
    {
        $payload_map = [];

        foreach ($items as $item) {
            $circular_id = (int) ($item['circularID'] ?? 0);

            if ($circular_id <= 0) {
                continue;
            }

            $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
            $status_meta = $track_status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
            $priority_label = trim((string) ($item['extPriority'] ?? ''));

            $payload_map[(string) $circular_id] = [
                'outgoingID' => $circular_id,
                'outgoingNo' => trim((string) ($item['extBookNo'] ?? '')),
                'subject' => trim((string) ($item['subject'] ?? '')),
                'priorityKey' => outgoing_normalize_priority_key($priority_label),
                'priorityLabel' => $priority_label !== '' ? $priority_label : 'ปกติ',
                'effectiveDate' => trim((string) ($item['extIssuedDate'] ?? '')),
                'detail' => (string) ($item['detail'] ?? ''),
                'linkURL' => trim((string) ($item['linkURL'] ?? '')),
                'groupName' => trim((string) ($item['groupName'] ?? '')),
                'issuerName' => trim((string) ($item['creatorName'] ?? '')),
                'proposerName' => trim((string) ($item['creatorName'] ?? '')),
                'fromName' => trim((string) ($item['extFromText'] ?? '')),
                'destinationName' => trim((string) ($item['extFromText'] ?? '')),
                'ownerNames' => array_values(array_filter([trim((string) ($item['creatorName'] ?? ''))])),
                'status' => $status_key,
                'statusLabel' => trim((string) ($status_meta['label'] ?? '-')),
                'statusPill' => trim((string) ($status_meta['pill'] ?? 'pending')),
                'attachments' => array_values((array) ($attachments_map[(string) $circular_id] ?? [])),
                'readStats' => outgoing_receive_get_reviewer_read_stats($circular_id),
            ];
        }

        return $payload_map;
    }
}

if (!function_exists('outgoing_receive_merge_upload_sets')) {
    /**
     * @param array<int, array<string, mixed>> $file_sets
     */
    function outgoing_receive_merge_upload_sets(array ...$file_sets): array
    {
        $field_names = ['cover_file', 'cover_attachments', 'attachments'];
        $merged = [];

        foreach ($file_sets as $index => $set) {
            if (!is_array($set) || !isset($set['name'])) {
                continue;
            }

            $field_name = $field_names[$index] ?? ('attachments_' . (string) $index);
            $merged[$field_name] = $set;
        }

        return $merged;
    }
}

if (!function_exists('outgoing_receive_uploaded_files_count')) {
    function outgoing_receive_uploaded_files_count(array $files): int
    {
        $normalized = upload_normalize_files($files);
        $count = 0;

        foreach ($normalized as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $count++;
            }
        }

        return $count;
    }
}

if (!function_exists('outgoing_receive_get_reviewers')) {
    function outgoing_receive_get_reviewers(): array
    {
        $connection = db_connection();
        $reviewers = [];
        $seen = [];

        $current_director_pid = (string) (system_get_current_director_pid() ?? '');
        $acting_pid = (string) (system_get_acting_director_pid() ?? '');
        $director_pid = (string) (system_get_director_pid() ?? '');

        if ($current_director_pid !== '') {
            $director_row = db_fetch_one(
                'SELECT pID, fName, positionID FROM teacher WHERE pID = ? AND status = 1 LIMIT 1',
                's',
                $current_director_pid
            );

            if ($director_row) {
                $label = trim((string) ($director_row['fName'] ?? ''));

                if ($current_director_pid === $acting_pid) {
                    $label .= ' (รักษาการแทนผู้อำนวยการโรงเรียน)';
                } elseif ($current_director_pid === $director_pid) {
                    $label .= ' (ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน)';
                }

                $reviewers[] = [
                    'pID' => (string) ($director_row['pID'] ?? ''),
                    'label' => trim($label),
                ];
                $seen[(string) ($director_row['pID'] ?? '')] = true;
            }
        }

        $deputy_position_ids = system_position_deputy_ids($connection);

        if (!empty($deputy_position_ids)) {
            $placeholders = implode(', ', array_fill(0, count($deputy_position_ids), '?'));
            $types = str_repeat('i', count($deputy_position_ids));
            $sql = 'SELECT pID, fName FROM teacher WHERE status = 1 AND positionID IN (' . $placeholders . ') ORDER BY FIELD(positionID, 9, 2, 3, 4), fName ASC';
            $deputies = db_fetch_all($sql, $types, ...$deputy_position_ids);

            foreach ($deputies as $deputy) {
                $pid = trim((string) ($deputy['pID'] ?? ''));

                if ($pid === '' || isset($seen[$pid])) {
                    continue;
                }

                $label = trim((string) ($deputy['fName'] ?? ''));

                if ($pid === $acting_pid) {
                    $label .= ' (รักษาการแทนผู้อำนวยการโรงเรียน)';
                } else {
                    $label .= ' (รองผู้อำนวยการ)';
                }

                $reviewers[] = [
                    'pID' => $pid,
                    'label' => trim($label),
                ];
                $seen[$pid] = true;
            }
        }

        return $reviewers;
    }
}

if (!function_exists('outgoing_receive_default_reviewer_pid')) {
    function outgoing_receive_default_reviewer_pid(array $reviewers): string
    {
        $current_director_pid = trim((string) (system_get_current_director_pid() ?? ''));

        foreach ($reviewers as $reviewer) {
            $pid = trim((string) ($reviewer['pID'] ?? ''));

            if ($pid !== '' && $pid === $current_director_pid) {
                return $pid;
            }
        }

        foreach ($reviewers as $reviewer) {
            $pid = trim((string) ($reviewer['pID'] ?? ''));

            if ($pid !== '') {
                return $pid;
            }
        }

        return '';
    }
}

if (!function_exists('outgoing_receive_build_state')) {
    function outgoing_receive_build_state(string $current_pid, int $edit_circular_id, bool $populate_values = true): array
    {
        $factions = user_list_factions();
        $allowed_faction_ids = [];

        foreach ($factions as $faction) {
            $fid = (int) ($faction['fID'] ?? 0);

            if ($fid > 0) {
                $allowed_faction_ids[$fid] = true;
            }
        }

        $reviewers = outgoing_receive_get_reviewers();
        $reviewer_ids = [];

        foreach ($reviewers as $reviewer) {
            $pid = trim((string) ($reviewer['pID'] ?? ''));

            if ($pid !== '') {
                $reviewer_ids[$pid] = true;
            }
        }

        $state = [
            'alert' => null,
            'values' => outgoing_receive_default_values(),
            'factions' => $factions,
            'allowed_faction_ids' => $allowed_faction_ids,
            'reviewers' => $reviewers,
            'reviewer_ids' => $reviewer_ids,
            'is_edit_mode' => false,
            'edit_circular_id' => $edit_circular_id,
            'editable_circular' => null,
            'existing_attachments' => [],
        ];
        $state['values']['reviewerPID'] = outgoing_receive_default_reviewer_pid($reviewers);

        if ($edit_circular_id <= 0) {
            return $state;
        }

        $candidate = circular_get($edit_circular_id);

        if (
            !$candidate
            || !circular_can_manage_external_workflow($candidate, $current_pid)
            || (string) ($candidate['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
            || (string) ($candidate['status'] ?? '') !== EXTERNAL_STATUS_SUBMITTED
        ) {
            $state['alert'] = [
                'type' => 'warning',
                'title' => 'ไม่สามารถแก้ไขรายการนี้ได้',
                'message' => 'ต้องเป็นหนังสือเวียนภายนอกสถานะรับเข้าแล้ว และผู้ใช้ต้องมีสิทธิ์สารบรรณ',
            ];

            return $state;
        }

        $state['is_edit_mode'] = true;
        $state['editable_circular'] = $candidate;
        $state['existing_attachments'] = circular_get_attachments($edit_circular_id);

        if (!$populate_values) {
            return $state;
        }

        $values = outgoing_receive_default_values();
        $values['extPriority'] = (string) ($candidate['extPriority'] ?? 'ปกติ');
        $values['extBookNo'] = (string) ($candidate['extBookNo'] ?? '');
        $values['extIssuedDate'] = (string) ($candidate['extIssuedDate'] ?? '');
        $values['subject'] = (string) ($candidate['subject'] ?? '');
        $values['extFromText'] = (string) ($candidate['extFromText'] ?? '');
        $candidate_fid = (int) ($candidate['extGroupFID'] ?? 0);
        $values['extGroupFID'] = ($candidate_fid > 0 && isset($allowed_faction_ids[$candidate_fid])) ? (string) $candidate_fid : '';
        $values['linkURL'] = (string) ($candidate['linkURL'] ?? '');
        $values['detail'] = (string) ($candidate['detail'] ?? '');
        $values['reviewerPID'] = outgoing_receive_default_reviewer_pid($reviewers);

        $state['values'] = $values;

        return $state;
    }
}

if (!function_exists('outgoing_receive_normalize_values')) {
    function outgoing_receive_normalize_values(array $input, array $allowed_faction_ids): array
    {
        $values = outgoing_receive_default_values();

        $values['extPriority'] = trim((string) ($input['extPriority'] ?? 'ปกติ'));
        $values['extBookNo'] = trim((string) ($input['extBookNo'] ?? ''));
        $values['extIssuedDate'] = trim((string) ($input['extIssuedDate'] ?? ''));
        $values['subject'] = trim((string) ($input['subject'] ?? ''));
        $values['extFromText'] = trim((string) ($input['extFromText'] ?? ''));
        $values['extGroupFID'] = trim((string) ($input['extGroupFID'] ?? ''));
        $values['linkURL'] = trim((string) ($input['linkURL'] ?? ''));
        $values['detail'] = trim((string) ($input['detail'] ?? ($input['memo_detail'] ?? '')));
        $values['reviewerPID'] = trim((string) ($input['reviewerPID'] ?? ''));

        $ext_group_fid_int = (int) $values['extGroupFID'];

        if ($ext_group_fid_int <= 0 || !isset($allowed_faction_ids[$ext_group_fid_int])) {
            $values['extGroupFID'] = '';
        } else {
            $values['extGroupFID'] = (string) $ext_group_fid_int;
        }

        return $values;
    }
}

if (!function_exists('outgoing_receive_validate_values')) {
    function outgoing_receive_validate_values(array $values, array $reviewer_ids): ?array
    {
        if (!in_array($values['extPriority'], ['ปกติ', 'ด่วน', 'ด่วนมาก', 'ด่วนที่สุด'], true)) {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ถูกต้อง',
                'message' => 'กรุณาเลือกประเภทความเร่งด่วน',
            ];
        }

        if ($values['extBookNo'] === '' || $values['subject'] === '' || $values['extFromText'] === '' || $values['extIssuedDate'] === '') {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ครบถ้วน',
                'message' => 'กรุณากรอก เลขที่หนังสือ ลงวันที่ เรื่อง และจาก',
            ];
        }

        if (strtotime($values['extIssuedDate']) === false) {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ถูกต้อง',
                'message' => 'รูปแบบวันที่ไม่ถูกต้อง',
            ];
        }

        if ($values['reviewerPID'] === '' || !isset($reviewer_ids[$values['reviewerPID']])) {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ครบถ้วน',
                'message' => 'กรุณาเลือกผู้พิจารณา (ผอ./รอง/รักษาการ)',
            ];
        }

        return null;
    }
}

if (!function_exists('outgoing_receive_submit')) {
    function outgoing_receive_submit(array $state, array $input, array $attachments, string $current_pid, array $current_user): array
    {
        $state['values'] = outgoing_receive_normalize_values($input, (array) ($state['allowed_faction_ids'] ?? []));

        if ((int) ($state['edit_circular_id'] ?? 0) > 0 && empty($state['is_edit_mode'])) {
            $state['alert'] = [
                'type' => 'danger',
                'title' => 'ไม่สามารถแก้ไขรายการนี้ได้',
                'message' => 'สิทธิ์ไม่ถูกต้องหรือสถานะรายการไม่รองรับ',
            ];

            return $state;
        }

        $validation_alert = outgoing_receive_validate_values(
            $state['values'],
            (array) ($state['reviewer_ids'] ?? [])
        );

        if ($validation_alert !== null) {
            $state['alert'] = $validation_alert;

            return $state;
        }

        try {
            $dh_year = system_get_dh_year();
            $edit_circular_id = (int) ($state['edit_circular_id'] ?? 0);
            $is_edit_mode = !empty($state['is_edit_mode']);
            $values = (array) ($state['values'] ?? []);

            if ($is_edit_mode && $edit_circular_id > 0) {
                $existing = db_fetch_one(
                    'SELECT circularID FROM dh_circulars WHERE dh_year = ? AND extBookNo = ? AND deletedAt IS NULL AND circularID <> ? LIMIT 1',
                    'isi',
                    $dh_year,
                    $values['extBookNo'],
                    $edit_circular_id
                );
            } else {
                $existing = db_fetch_one(
                    'SELECT circularID FROM dh_circulars WHERE dh_year = ? AND extBookNo = ? AND deletedAt IS NULL LIMIT 1',
                    'is',
                    $dh_year,
                    $values['extBookNo']
                );
            }

            if ($existing) {
                throw new RuntimeException('เลขที่หนังสือนี้ถูกใช้งานแล้วในปีสารบรรณปัจจุบัน');
            }

            if ($is_edit_mode && $edit_circular_id > 0) {
                $allowed_file_ids = [];

                foreach ((array) ($state['existing_attachments'] ?? []) as $attachment) {
                    $file_id = (int) ($attachment['fileID'] ?? 0);

                    if ($file_id > 0) {
                        $allowed_file_ids[$file_id] = true;
                    }
                }

                $remove_file_ids = array_values(array_unique(array_filter(array_map(static function ($value): int {
                    return (int) $value;
                }, (array) ($input['remove_file_ids'] ?? [])), static function (int $file_id) use ($allowed_file_ids): bool {
                    return $file_id > 0 && isset($allowed_file_ids[$file_id]);
                })));

                $remaining_files_count = max(0, count((array) ($state['existing_attachments'] ?? [])) - count($remove_file_ids));
                $uploading_files_count = outgoing_receive_uploaded_files_count($attachments);

                if (($remaining_files_count + $uploading_files_count) > 5) {
                    throw new RuntimeException('แนบไฟล์รวมได้สูงสุด 5 ไฟล์');
                }

                $updated = circular_edit_and_resend_external(
                    $edit_circular_id,
                    $current_pid,
                    [
                        'subject' => $values['subject'],
                        'detail' => $values['detail'],
                        'linkURL' => $values['linkURL'],
                        'extPriority' => $values['extPriority'],
                        'extBookNo' => $values['extBookNo'],
                        'extIssuedDate' => $values['extIssuedDate'],
                        'extFromText' => $values['extFromText'],
                        'extGroupFID' => $values['extGroupFID'] !== '' ? (int) $values['extGroupFID'] : null,
                        'reviewerPID' => $values['reviewerPID'],
                        'registryNote' => $values['detail'] !== '' ? $values['detail'] : null,
                    ],
                    $attachments,
                    $remove_file_ids
                );

                if (!$updated) {
                    throw new RuntimeException('ไม่สามารถแก้ไขและส่งใหม่ได้ในสถานะปัจจุบัน');
                }

                $state['alert'] = [
                    'type' => 'success',
                    'title' => 'แก้ไขและส่งใหม่เรียบร้อย',
                    'message' => 'เลขที่รายการ #' . $edit_circular_id,
                ];
                $state['values'] = outgoing_receive_default_values();
                $state['values']['reviewerPID'] = outgoing_receive_default_reviewer_pid((array) ($state['reviewers'] ?? []));
                $state['is_edit_mode'] = false;
                $state['edit_circular_id'] = 0;
                $state['editable_circular'] = null;
                $state['existing_attachments'] = [];

                return $state;
            }

            $circular_id = circular_create_external([
                'dh_year' => $dh_year,
                'circularType' => CIRCULAR_TYPE_EXTERNAL,
                'subject' => $values['subject'],
                'detail' => $values['detail'] !== '' ? $values['detail'] : null,
                'linkURL' => $values['linkURL'] !== '' ? $values['linkURL'] : null,
                'fromFID' => !empty($current_user['fID']) ? (int) $current_user['fID'] : null,
                'extPriority' => $values['extPriority'],
                'extBookNo' => $values['extBookNo'],
                'extIssuedDate' => $values['extIssuedDate'],
                'extFromText' => $values['extFromText'],
                'extGroupFID' => $values['extGroupFID'] !== '' ? (int) $values['extGroupFID'] : null,
                'status' => EXTERNAL_STATUS_SUBMITTED,
                'createdByPID' => $current_pid,
                'registryNote' => $values['detail'] !== '' ? $values['detail'] : null,
            ], $current_pid, true, $attachments, $values['reviewerPID']);

            $state['alert'] = [
                'type' => 'success',
                'title' => 'ลงทะเบียนรับหนังสือเรียบร้อย',
                'message' => 'เลขที่รายการ #' . $circular_id,
            ];
            $state['values'] = outgoing_receive_default_values();
            $state['values']['reviewerPID'] = outgoing_receive_default_reviewer_pid((array) ($state['reviewers'] ?? []));

            return $state;
        } catch (Throwable $e) {
            $state['alert'] = [
                'type' => 'danger',
                'title' => 'เกิดข้อผิดพลาด',
                'message' => $e->getMessage(),
            ];

            return $state;
        }
    }
}
