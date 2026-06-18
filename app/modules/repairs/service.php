<?php

declare(strict_types=1);

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/../../services/uploads.php';
require_once __DIR__ . '/../system/system.php';
require_once __DIR__ . '/../audit/logger.php';
require_once __DIR__ . '/../../rbac/roles.php';
require_once __DIR__ . '/../../db/db.php';

const REPAIR_STAFF_ROLE_ID = 7;
const REPAIR_STAFF_ROLE_NAME = 'เจ้าหน้าที่ซ่อมแซม';
const REPAIR_STAFF_FALLBACK_ROLE_ID = 6;

if (!function_exists('repair_form_defaults')) {
    function repair_form_defaults(): array
    {
        return [
            'subject' => '',
            'location' => '',
            'equipment' => '',
            'detail' => '',
        ];
    }
}

if (!function_exists('repair_normalize_form_data')) {
    function repair_normalize_form_data(array $input): array
    {
        return [
            'subject' => trim((string) ($input['subject'] ?? '')),
            'location' => trim((string) ($input['location'] ?? '')),
            'equipment' => trim((string) ($input['equipment'] ?? '')),
            'detail' => trim((string) ($input['detail'] ?? '')),
        ];
    }
}

if (!function_exists('repair_validate_create_data')) {
    function repair_validate_create_data(array $data): void
    {
        if (trim((string) ($data['subject'] ?? '')) === '') {
            throw new RuntimeException('กรุณากรอกหัวข้อ');
        }

        if (trim((string) ($data['location'] ?? '')) === '') {
            throw new RuntimeException('กรุณากรอกสถานที่');
        }

        if (trim((string) ($data['detail'] ?? '')) === '') {
            throw new RuntimeException('กรุณากรอกรายละเอียดเพิ่มเติม');
        }
    }
}

if (!function_exists('repair_has_uploads')) {
    function repair_has_uploads(array $files): bool
    {
        foreach (upload_normalize_files($files) as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('repair_count_uploads')) {
    function repair_count_uploads(array $files): int
    {
        $count = 0;

        foreach (upload_normalize_files($files) as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $count++;
            }
        }

        return $count;
    }
}

if (!function_exists('repair_build_audit_payload')) {
    function repair_build_audit_payload(array $data, array $files, string $actor_pid, array $extra = []): array
    {
        $payload = array_merge([
            'actorPID' => $actor_pid !== '' ? $actor_pid : null,
            'dhYear' => (int) system_get_dh_year(),
            'subject' => trim((string) ($data['subject'] ?? '')) ?: null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'equipment' => trim((string) ($data['equipment'] ?? '')) ?: null,
            'detailLength' => function_exists('mb_strlen')
                ? mb_strlen(trim((string) ($data['detail'] ?? '')), 'UTF-8')
                : strlen(trim((string) ($data['detail'] ?? ''))),
            'hasAttachments' => repair_has_uploads($files),
            'attachmentCount' => repair_count_uploads($files),
        ], $extra);

        return array_filter($payload, static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [];
        });
    }
}

if (!function_exists('repair_staff_role_id')) {
    function repair_staff_role_id(): int
    {
        $connection = db_connection();
        $role_ids = rbac_resolve_role_ids($connection, ROLE_REPAIR);

        foreach ($role_ids as $role_id) {
            $role_id = (int) $role_id;

            if ($role_id > 0) {
                return $role_id;
            }
        }

        return REPAIR_STAFF_ROLE_ID;
    }
}

if (!function_exists('repair_map_staff_member')) {
    function repair_map_staff_member(array $row): array
    {
        $name = trim((string) ($row['fName'] ?? ''));

        return [
            'pID' => (string) ($row['pID'] ?? ''),
            'name' => $name !== '' ? $name : 'ไม่ระบุชื่อ',
            'position_name' => trim((string) ($row['position_name'] ?? '')),
            'role_name' => trim((string) ($row['role_name'] ?? '')),
            'department_name' => trim((string) ($row['department_name'] ?? '')),
            'telephone' => trim((string) ($row['telephone'] ?? '')),
        ];
    }
}

if (!function_exists('repair_staff_members')) {
    function repair_staff_members(): array
    {
        $connection = db_connection();
        $position = system_position_join($connection, 't', 'p');
        $role_name_select = rbac_role_names_select('t') . ' AS role_name';
        $staff_role_id = repair_staff_role_id();
        $staff_role_condition = rbac_csv_role_condition('t.roleID', 1);
        $rows = db_fetch_all(
            'SELECT t.pID, t.fName, t.positionID, t.roleID, t.telephone,
                ' . $position['name'] . ' AS position_name,
                ' . $role_name_select . ',
                d.dName AS department_name
             FROM teacher AS t
             ' . $position['join'] . '
             LEFT JOIN department AS d ON t.dID = d.dID
             WHERE t.status = 1 AND ' . $staff_role_condition . '
             ORDER BY t.fName ASC, t.pID ASC',
            'i',
            $staff_role_id
        );

        return array_map('repair_map_staff_member', $rows);
    }
}

if (!function_exists('repair_staff_candidates')) {
    function repair_staff_candidates(): array
    {
        $connection = db_connection();
        $position = system_position_join($connection, 't', 'p');
        $role_name_select = rbac_role_names_select('t') . ' AS role_name';
        $staff_role_id = repair_staff_role_id();
        $staff_role_condition = rbac_csv_role_condition('t.roleID', 1);
        $rows = db_fetch_all(
            'SELECT t.pID, t.fName, t.positionID, t.roleID, t.telephone,
                ' . $position['name'] . ' AS position_name,
                ' . $role_name_select . ',
                d.dName AS department_name
             FROM teacher AS t
             ' . $position['join'] . '
             LEFT JOIN department AS d ON t.dID = d.dID
             WHERE t.status = 1 AND NOT ' . $staff_role_condition . '
             ORDER BY t.fName ASC, t.pID ASC',
            'i',
            $staff_role_id
        );

        return array_map('repair_map_staff_member', $rows);
    }
}

if (!function_exists('repair_assign_staff_role')) {
    function repair_assign_staff_role(string $member_pid): bool
    {
        $member_pid = trim($member_pid);

        if ($member_pid === '') {
            return false;
        }

        $connection = db_connection();

        return rbac_add_teacher_role_id($connection, $member_pid, repair_staff_role_id());
    }
}

if (!function_exists('repair_remove_staff_role')) {
    function repair_remove_staff_role(string $member_pid): bool
    {
        $member_pid = trim($member_pid);

        if ($member_pid === '') {
            return false;
        }

        $connection = db_connection();

        return rbac_remove_teacher_role_id($connection, $member_pid, repair_staff_role_id(), REPAIR_STAFF_FALLBACK_ROLE_ID);
    }
}

if (!function_exists('repair_staff_position_id')) {
    function repair_staff_position_id(): int
    {
        return repair_staff_role_id();
    }
}

if (!function_exists('repair_assign_staff_position')) {
    function repair_assign_staff_position(string $member_pid): bool
    {
        return repair_assign_staff_role($member_pid);
    }
}

if (!function_exists('repair_remove_staff_position')) {
    function repair_remove_staff_position(string $member_pid): bool
    {
        return repair_remove_staff_role($member_pid);
    }
}

if (!function_exists('repair_timeline_status_label')) {
    function repair_timeline_status_label(string $status): string
    {
        $status = strtoupper(trim($status));
        $labels = [
            REPAIR_STATUS_PENDING => 'ส่งคำร้องสำเร็จ',
            REPAIR_STATUS_IN_PROGRESS => 'กำลังดำเนินการ',
            REPAIR_STATUS_COMPLETED => 'ดำเนินการเสร็จสิ้น',
            REPAIR_STATUS_CANCELLED => 'ยกเลิกคำร้อง',
            REPAIR_STATUS_REJECTED => 'ยกเลิกคำร้อง',
        ];

        return $labels[$status] ?? $status;
    }
}

if (!function_exists('repair_timeline_title')) {
    function repair_timeline_title(string $status): string
    {
        $status = strtoupper(trim($status));
        $titles = [
            REPAIR_STATUS_PENDING => 'รับเรื่องคำร้องแล้ว',
            REPAIR_STATUS_IN_PROGRESS => 'กำลังดำเนินการ',
            REPAIR_STATUS_COMPLETED => 'ดำเนินการเสร็จสิ้น',
            REPAIR_STATUS_CANCELLED => 'ยกเลิกคำร้อง',
            REPAIR_STATUS_REJECTED => 'ยกเลิกคำร้อง',
        ];

        return $titles[$status] ?? repair_timeline_status_label($status);
    }
}

if (!function_exists('repair_log_timeline_event')) {
    function repair_log_timeline_event(int $repair_id, string $actor_pid, string $event, ?string $from_status, string $to_status, array $payload = []): void
    {
        if (!function_exists('audit_log') || $repair_id <= 0) {
            return;
        }

        $from_status = $from_status !== null ? strtoupper(trim($from_status)) : null;
        $to_status = strtoupper(trim($to_status));
        $title = repair_timeline_title($to_status);
        $timeline_payload = [
            'actorPID' => trim($actor_pid) !== '' ? trim($actor_pid) : null,
            'event' => strtoupper(trim($event)),
            'fromStatus' => $from_status,
            'fromLabel' => $from_status !== null && $from_status !== '' ? repair_timeline_status_label($from_status) : null,
            'toStatus' => $to_status,
            'toLabel' => repair_timeline_status_label($to_status),
            'timelineTitle' => $title,
        ];

        $timeline_payload = array_filter(array_merge($timeline_payload, $payload), static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [];
        });

        audit_log('repairs', 'TIMELINE', 'SUCCESS', REPAIR_ENTITY_NAME, $repair_id, $title, $timeline_payload);
    }
}

if (!function_exists('repair_get_timeline')) {
    function repair_get_timeline(int $repair_id): array
    {
        if ($repair_id <= 0) {
            return [];
        }

        $connection = db_connection();

        if (!db_table_exists($connection, 'dh_logs')) {
            return [];
        }

        $rows = db_fetch_all(
            'SELECT l.logID, l.actorPID, COALESCE(actor.fName, "") AS actorName, l.actionName, l.logMessage, l.payloadData, l.created_at
             FROM dh_logs AS l
             LEFT JOIN teacher AS actor ON l.actorPID = actor.pID
             WHERE l.moduleName = ? AND l.actionName = ? AND l.actionStatus = ? AND l.entityName = ? AND l.entityID = ?
             ORDER BY l.created_at ASC, l.logID ASC',
            'ssssi',
            'repairs',
            'TIMELINE',
            'SUCCESS',
            REPAIR_ENTITY_NAME,
            $repair_id
        );

        return array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payloadData'] ?? ''), true);

            if (!is_array($payload)) {
                $payload = [];
            }

            return [
                'logID' => (int) ($row['logID'] ?? 0),
                'actorPID' => (string) ($row['actorPID'] ?? ''),
                'actorName' => (string) ($row['actorName'] ?? ''),
                'title' => (string) ($row['logMessage'] ?? ''),
                'event' => (string) ($payload['event'] ?? ''),
                'fromStatus' => (string) ($payload['fromStatus'] ?? ''),
                'fromLabel' => (string) ($payload['fromLabel'] ?? ''),
                'toStatus' => (string) ($payload['toStatus'] ?? ''),
                'toLabel' => (string) ($payload['toLabel'] ?? ''),
                'createdAt' => (string) ($row['created_at'] ?? ''),
                'payload' => $payload,
            ];
        }, $rows);
    }
}

if (!function_exists('repair_get_timeline_map')) {
    function repair_get_timeline_map(array $repair_ids): array
    {
        $repair_ids = array_values(array_unique(array_filter(array_map('intval', $repair_ids), static function (int $repair_id): bool {
            return $repair_id > 0;
        })));

        if ($repair_ids === []) {
            return [];
        }

        $connection = db_connection();

        if (!db_table_exists($connection, 'dh_logs')) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($repair_ids), '?'));
        $rows = db_fetch_all(
            'SELECT l.entityID, l.logID, l.actorPID, COALESCE(actor.fName, "") AS actorName, l.actionName, l.logMessage, l.payloadData, l.created_at
             FROM dh_logs AS l
             LEFT JOIN teacher AS actor ON l.actorPID = actor.pID
             WHERE l.moduleName = ? AND l.actionName = ? AND l.actionStatus = ? AND l.entityName = ? AND l.entityID IN (' . $placeholders . ')
             ORDER BY l.entityID ASC, l.created_at ASC, l.logID ASC',
            'ssss' . str_repeat('i', count($repair_ids)),
            'repairs',
            'TIMELINE',
            'SUCCESS',
            REPAIR_ENTITY_NAME,
            ...$repair_ids
        );

        $timeline_map = [];

        foreach ($rows as $row) {
            $repair_id = (int) ($row['entityID'] ?? 0);

            if ($repair_id <= 0) {
                continue;
            }

            $payload = json_decode((string) ($row['payloadData'] ?? ''), true);

            if (!is_array($payload)) {
                $payload = [];
            }

            $timeline_map[$repair_id][] = [
                'logID' => (int) ($row['logID'] ?? 0),
                'actorPID' => (string) ($row['actorPID'] ?? ''),
                'actorName' => (string) ($row['actorName'] ?? ''),
                'title' => (string) ($row['logMessage'] ?? ''),
                'event' => (string) ($payload['event'] ?? ''),
                'fromStatus' => (string) ($payload['fromStatus'] ?? ''),
                'fromLabel' => (string) ($payload['fromLabel'] ?? ''),
                'toStatus' => (string) ($payload['toStatus'] ?? ''),
                'toLabel' => (string) ($payload['toLabel'] ?? ''),
                'createdAt' => (string) ($row['created_at'] ?? ''),
                'payload' => $payload,
            ];
        }

        return $timeline_map;
    }
}

if (!function_exists('repair_get_latest_timeline_notes_map')) {
    function repair_get_latest_timeline_notes_map(array $repair_ids): array
    {
        $repair_ids = array_values(array_unique(array_filter(array_map('intval', $repair_ids), static function (int $repair_id): bool {
            return $repair_id > 0;
        })));

        if ($repair_ids === []) {
            return [];
        }

        $connection = db_connection();

        if (!db_table_exists($connection, 'dh_logs')) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($repair_ids), '?'));
        $rows = db_fetch_all(
            'SELECT entityID, payloadData
             FROM dh_logs
             WHERE moduleName = ? AND actionName = ? AND actionStatus = ? AND entityName = ? AND entityID IN (' . $placeholders . ')
             ORDER BY created_at DESC, logID DESC',
            'ssss' . str_repeat('i', count($repair_ids)),
            'repairs',
            'TIMELINE',
            'SUCCESS',
            REPAIR_ENTITY_NAME,
            ...$repair_ids
        );

        $notes = [];

        foreach ($rows as $row) {
            $repair_id = (int) ($row['entityID'] ?? 0);

            if ($repair_id <= 0 || array_key_exists($repair_id, $notes)) {
                continue;
            }

            $payload = json_decode((string) ($row['payloadData'] ?? ''), true);

            if (!is_array($payload)) {
                continue;
            }

            $note = trim((string) ($payload['note'] ?? ''));

            if ($note !== '') {
                $notes[$repair_id] = $note;
            }
        }

        return $notes;
    }
}

if (!function_exists('repair_create_request')) {
    function repair_create_request(array $input, array $files, string $actor_pid): int
    {
        $data = repair_normalize_form_data($input);
        $audit_payload = repair_build_audit_payload($data, $files, $actor_pid);
        $transaction_started = false;

        try {
            repair_validate_create_data($data);

            db_begin();
            $transaction_started = true;

            $repair_id = repair_create_record([
                'dh_year' => system_get_dh_year(),
                'requesterPID' => $actor_pid,
                'subject' => $data['subject'],
                'detail' => $data['detail'],
                'location' => $data['location'],
                'equipment' => $data['equipment'] !== '' ? $data['equipment'] : null,
                'status' => REPAIR_STATUS_PENDING,
                'assignedToPID' => null,
            ]);

            if (repair_has_uploads($files)) {
                upload_store_files($files, REPAIR_MODULE_NAME, REPAIR_ENTITY_NAME, (string) $repair_id, $actor_pid, [
                    'max_files' => 0,
                    'allowed_mimes' => upload_allowed_mimes(),
                ]);
            }

            db_commit();

            if (function_exists('audit_log')) {
                audit_log('repairs', 'CREATE', 'SUCCESS', REPAIR_ENTITY_NAME, $repair_id, null, $audit_payload);
            }

            repair_log_timeline_event($repair_id, $actor_pid, 'CREATE', null, REPAIR_STATUS_PENDING, [
                'subject' => $data['subject'],
                'location' => $data['location'],
                'equipment' => $data['equipment'] !== '' ? $data['equipment'] : null,
            ]);

            return $repair_id;
        } catch (Throwable $exception) {
            if ($transaction_started) {
                db_rollback();
            }

            if (function_exists('audit_log')) {
                audit_log('repairs', 'CREATE', 'FAIL', REPAIR_ENTITY_NAME, null, $exception->getMessage(), $audit_payload);
            }

            throw $exception;
        }
    }
}
