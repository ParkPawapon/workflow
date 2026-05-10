<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/circulars/repository.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../modules/audit/logger.php';

if (!function_exists('circular_archive_index')) {
    function circular_archive_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');

        $connection = db_connection();
        $is_registry = rbac_user_has_role($connection, $current_pid, ROLE_REGISTRY);

        if (!$is_registry && (int) ($current_user['roleID'] ?? 0) === 2) {
            $is_registry = true;
        }

        $director_pid = system_get_current_director_pid();
        $is_director_box = $director_pid !== null && $director_pid === $current_pid;

        $box = (string) ($_GET['box'] ?? 'normal');
        $acting_pid = system_get_acting_director_pid();
        $director_inbox_type = ($acting_pid !== null && $acting_pid !== '' && $acting_pid === $current_pid)
            ? INBOX_TYPE_ACTING_PRINCIPAL
            : INBOX_TYPE_SPECIAL_PRINCIPAL;

        $box_map = [
            'normal' => INBOX_TYPE_NORMAL,
            'director' => $director_inbox_type,
            'clerk' => INBOX_TYPE_NORMAL,
            'clerk_return' => INBOX_TYPE_SARABAN_RETURN,
        ];
        $box_key = array_key_exists($box, $box_map) ? $box : 'normal';
        $inbox_type = $box_map[$box_key];
        $is_outside_view = $box_key !== 'normal';

        $default_type = $is_outside_view ? 'external' : 'internal';
        $filter_type = (string) ($_GET['type'] ?? $default_type);
        $filter_read = (string) ($_GET['read'] ?? 'all');
        $filter_sort = (string) ($_GET['sort'] ?? 'newest');
        $filter_view = (string) ($_GET['view'] ?? 'table1');
        $filter_search = trim((string) ($_GET['q'] ?? ''));
        $selected_dh_year = (int) ($_GET['dh_year'] ?? 0);

        $allowed_types = $is_outside_view ? ['external'] : ['internal'];
        $allowed_reads = ['all', 'read', 'unread'];
        $allowed_sort = ['newest', 'oldest'];
        $allowed_views = ['table1', 'table2'];

        if (!in_array($filter_type, $allowed_types, true)) {
            $filter_type = $default_type;
        }

        if (!$is_outside_view) {
            $filter_type = 'internal';
        }

        if (!in_array($filter_read, $allowed_reads, true)) {
            $filter_read = 'all';
        }

        if (!in_array($filter_sort, $allowed_sort, true)) {
            $filter_sort = 'newest';
        }

        if (!in_array($filter_view, $allowed_views, true)) {
            $filter_view = 'table1';
        }

        $alert = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ];
            } else {
                $action = (string) ($_POST['action'] ?? '');
                $inbox_id = (int) ($_POST['inbox_id'] ?? 0);
                $selected_ids = array_values(array_filter(array_map('intval', (array) ($_POST['selected_ids'] ?? [])), static function (int $id): bool {
                    return $id > 0;
                }));

                if ($action === 'unarchive' && $inbox_id > 0) {
                    circular_unarchive_inbox($inbox_id, $current_pid);

                    if (function_exists('audit_log')) {
                        audit_log('circulars', 'UNARCHIVE', 'SUCCESS', 'dh_circular_inboxes', $inbox_id);
                    }
                    $alert = [
                        'type' => 'success',
                        'title' => 'ย้ายกลับเรียบร้อย',
                        'message' => '',
                    ];
                } elseif ($action === 'unarchive_selected') {
                    if (empty($selected_ids)) {
                        $alert = [
                            'type' => 'warning',
                            'title' => 'กรุณาเลือกรายการ',
                            'message' => '',
                        ];
                    } else {
                        foreach ($selected_ids as $selected_id) {
                            circular_unarchive_inbox($selected_id, $current_pid);

                            if (function_exists('audit_log')) {
                                audit_log('circulars', 'UNARCHIVE', 'SUCCESS', 'dh_circular_inboxes', $selected_id);
                            }
                        }
                        $alert = [
                            'type' => 'success',
                            'title' => 'ย้ายกลับรายการที่เลือกแล้ว',
                            'message' => '',
                        ];
                    }
                }
            }
        }

        $circular_inbox = $box_key === 'director'
            ? circular_get_inbox_by_types($current_pid, [INBOX_TYPE_SPECIAL_PRINCIPAL, INBOX_TYPE_ACTING_PRINCIPAL], true)
            : circular_get_inbox($current_pid, $inbox_type, true);

        if ($box_key === 'director') {
            $seen_director_circular_ids = [];
            $circular_inbox = array_values(array_filter($circular_inbox, static function (array $item) use (&$seen_director_circular_ids): bool {
                $circular_id = (int) ($item['circularID'] ?? 0);

                if ($circular_id <= 0 || isset($seen_director_circular_ids[$circular_id])) {
                    return false;
                }

                $seen_director_circular_ids[$circular_id] = true;
                return true;
            }));
        }
        $circular_ids = array_values(array_unique(array_map(static function (array $item): int {
            return (int) ($item['circularID'] ?? 0);
        }, $circular_inbox)));

        $detail_map = [];

        if (!empty($circular_ids)) {
            $placeholders = implode(', ', array_fill(0, count($circular_ids), '?'));
            $types = str_repeat('i', count($circular_ids));
            $sql = 'SELECT circularID, dh_year, circularType, subject, detail, linkURL, extPriority, extBookNo, extIssuedDate, extFromText, extGroupFID, status, createdAt
                FROM dh_circulars
                WHERE circularID IN (' . $placeholders . ')';
            $rows = db_fetch_all($sql, $types, ...$circular_ids);

            foreach ($rows as $row) {
                $detail_map[(int) $row['circularID']] = $row;
            }
        }

        $items = [];

        foreach ($circular_inbox as $item) {
            $circular_id = (int) ($item['circularID'] ?? 0);
            $detail = $detail_map[$circular_id] ?? [];
            $item['detail'] = $detail['detail'] ?? '';
            $item['linkURL'] = $detail['linkURL'] ?? '';
            $item['extPriority'] = $detail['extPriority'] ?? '';
            $item['extBookNo'] = $detail['extBookNo'] ?? '';
            $item['extIssuedDate'] = $detail['extIssuedDate'] ?? '';
            $item['extFromText'] = $detail['extFromText'] ?? '';
            $item['extGroupFID'] = $detail['extGroupFID'] ?? '';
            $item['dh_year'] = $detail['dh_year'] ?? ($item['dh_year'] ?? null);
            $item['status'] = $detail['status'] ?? ($item['status'] ?? '');
            $items[] = $item;
        }

        $dh_year_options = [];

        foreach ($items as $item) {
            $type = strtoupper((string) ($item['circularType'] ?? ''));

            if ($filter_type === 'internal' && $type !== 'INTERNAL') {
                continue;
            }

            if ($filter_type === 'external' && $type !== 'EXTERNAL') {
                continue;
            }

            $year = (int) ($item['dh_year'] ?? 0);

            if ($year > 0) {
                $dh_year_options[$year] = true;
            }
        }

        $dh_year_options = array_keys($dh_year_options);
        rsort($dh_year_options);

        if ($selected_dh_year > 0 && !in_array($selected_dh_year, $dh_year_options, true)) {
            $selected_dh_year = 0;
        }

        $items = array_values(array_filter($items, static function (array $item) use ($filter_type, $filter_read, $filter_search, $selected_dh_year): bool {
            $type = strtoupper((string) ($item['circularType'] ?? ''));
            $is_read = (int) ($item['isRead'] ?? 0) === 1;

            if ($filter_type === 'internal' && $type !== 'INTERNAL') {
                return false;
            }

            if ($filter_type === 'external' && $type !== 'EXTERNAL') {
                return false;
            }

            if ($filter_read === 'read' && !$is_read) {
                return false;
            }

            if ($filter_read === 'unread' && $is_read) {
                return false;
            }

            if ($selected_dh_year > 0 && (int) ($item['dh_year'] ?? 0) !== $selected_dh_year) {
                return false;
            }

            if ($filter_search !== '') {
                $haystack = strtolower(trim(($item['subject'] ?? '') . ' ' . ($item['senderName'] ?? '') . ' ' . ($item['extBookNo'] ?? '')));

                if (strpos($haystack, strtolower($filter_search)) === false) {
                    return false;
                }
            }

            return true;
        }));

        usort($items, static function (array $a, array $b) use ($filter_sort): int {
            $time_a = strtotime((string) ($a['deliveredAt'] ?? $a['createdAt'] ?? '')) ?: 0;
            $time_b = strtotime((string) ($b['deliveredAt'] ?? $b['createdAt'] ?? '')) ?: 0;

            if ($time_a === $time_b) {
                return 0;
            }

            if ($filter_sort === 'oldest') {
                return $time_a <=> $time_b;
            }

            return $time_b <=> $time_a;
        });

        $attachments_map = [];

        if (!empty($items) && db_table_exists($connection, 'dh_file_refs')) {
            $entity_ids = array_values(array_unique(array_map(static function (array $item): string {
                return (string) ($item['circularID'] ?? '');
            }, $items)));

            if (!empty($entity_ids)) {
                $placeholders = implode(', ', array_fill(0, count($entity_ids), '?'));
                $types = 'ss' . str_repeat('s', count($entity_ids));
                $params = array_merge([CIRCULAR_MODULE_NAME, CIRCULAR_ENTITY_NAME], $entity_ids);
                $sql = 'SELECT r.entityID, f.fileID, f.fileName, f.mimeType, f.fileSize
                    FROM dh_file_refs AS r
                    INNER JOIN dh_files AS f ON r.fileID = f.fileID
                    WHERE r.moduleName = ? AND r.entityName = ? AND r.entityID IN (' . $placeholders . ') AND f.deletedAt IS NULL
                    ORDER BY r.refID ASC';
                $rows = db_fetch_all($sql, $types, ...$params);

                foreach ($rows as $row) {
                    $entity_id = (string) ($row['entityID'] ?? '');

                    if ($entity_id === '') {
                        continue;
                    }

                    if (!isset($attachments_map[$entity_id])) {
                        $attachments_map[$entity_id] = [];
                    }
                    $attachments_map[$entity_id][] = $row;
                }
            }
        }

        $urgency_class_map = [
            'ปกติ' => 'normal',
            'ด่วน' => 'urgen',
            'ด่วนมาก' => 'very-urgen',
            'ด่วนที่สุด' => 'extremly-urgen',
        ];

        $status_label_map = [
            INTERNAL_STATUS_DRAFT => 'ร่าง',
            INTERNAL_STATUS_SENT => 'ส่งแล้ว',
            INTERNAL_STATUS_RECALLED => 'ดึงกลับ',
            INTERNAL_STATUS_ARCHIVED => 'จัดเก็บ',
            EXTERNAL_STATUS_SUBMITTED => 'รับเข้าแล้ว',
            EXTERNAL_STATUS_PENDING_REVIEW => 'รอพิจารณา',
            EXTERNAL_STATUS_REVIEWED => 'พิจารณาแล้ว',
            EXTERNAL_STATUS_FORWARDED => 'ส่งแล้ว',
        ];

        $resolve_status_label = static function (string $status_key, string $box_key) use ($status_label_map): string {
            if ($status_key === '') {
                return '-';
            }

            if ($status_key === EXTERNAL_STATUS_PENDING_REVIEW) {
                if ($box_key === 'clerk') {
                    return 'กำลังเสนอ';
                }

                if ($box_key === 'director') {
                    return 'รอพิจารณา';
                }

                return 'รอพิจารณา';
            }

            return $status_label_map[$status_key] ?? $status_key;
        };

        $consider_class_map = [
            INTERNAL_STATUS_DRAFT => 'considering',
            INTERNAL_STATUS_SENT => 'success',
            INTERNAL_STATUS_RECALLED => 'considered',
            INTERNAL_STATUS_ARCHIVED => 'success',
            EXTERNAL_STATUS_SUBMITTED => 'considering',
            EXTERNAL_STATUS_PENDING_REVIEW => 'considering',
            EXTERNAL_STATUS_REVIEWED => 'considered',
            EXTERNAL_STATUS_FORWARDED => 'success',
        ];

        $exec_duty = system_get_exec_duty();
        $director_label = 'ผอ./รักษาการ';

        if (!empty($exec_duty['pID']) && (int) ($exec_duty['dutyStatus'] ?? 0) === 2) {
            $name = trim((string) ($exec_duty['name'] ?? ''));
            $director_label = $name !== '' ? $name . ' (รักษาราชการแทน)' : $director_label;
        } else {
            $director_pid = system_get_director_pid();

            if ($director_pid) {
                $row = db_fetch_one('SELECT fName FROM teacher WHERE pID = ? LIMIT 1', 's', $director_pid);
                $name = $row ? (string) ($row['fName'] ?? '') : '';

                if ($name !== '') {
                    $director_label = 'ผอ. ' . $name;
                }
            }
        }

        $format_thai_date = static function (?string $date_value): string {
            if ($date_value === null || trim($date_value) === '') {
                return '-';
            }
            $timestamp = strtotime($date_value);

            if ($timestamp === false) {
                return $date_value;
            }
            $year = (int) date('Y', $timestamp) + 543;

            return date('d/m/', $timestamp) . $year;
        };

        $format_thai_date_long = static function (?string $date_value): string {
            if ($date_value === null || trim($date_value) === '') {
                return '-';
            }

            $timestamp = strtotime($date_value);

            if ($timestamp === false) {
                return $date_value;
            }

            $month_names = [
                1 => 'มกราคม',
                2 => 'กุมภาพันธ์',
                3 => 'มีนาคม',
                4 => 'เมษายน',
                5 => 'พฤษภาคม',
                6 => 'มิถุนายน',
                7 => 'กรกฎาคม',
                8 => 'สิงหาคม',
                9 => 'กันยายน',
                10 => 'ตุลาคม',
                11 => 'พฤศจิกายน',
                12 => 'ธันวาคม',
            ];

            $day = (int) date('j', $timestamp);
            $month = (int) date('n', $timestamp);
            $year_be = (int) date('Y', $timestamp) + 543;
            $month_label = $month_names[$month] ?? '';

            if ($month_label === '') {
                return $date_value;
            }

            return $day . ' ' . $month_label . ' พ.ศ.' . $year_be;
        };

        $format_thai_time = static function (?string $date_value): string {
            if ($date_value === null || trim($date_value) === '') {
                return '-';
            }
            $timestamp = strtotime($date_value);

            if ($timestamp === false) {
                return $date_value;
            }

            return date('H:i', $timestamp) . ' น.';
        };

        $display_items = [];

        foreach ($items as $item) {
            $circular_id = (int) ($item['circularID'] ?? 0);
            $priority = trim((string) ($item['extPriority'] ?? ''));
            $priority_label = $priority !== '' ? $priority : 'ปกติ';
            $urgency_class = $urgency_class_map[$priority_label] ?? 'normal';
            $status_key = strtoupper((string) ($item['status'] ?? ''));
            $status_label = $resolve_status_label($status_key, $box_key);
            $consider_class = $consider_class_map[$status_key] ?? 'considering';
            $files = $attachments_map[(string) $circular_id] ?? [];
            $files_json = json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($files_json === false) {
                $files_json = '[]';
            }

            $delivered_at = $item['deliveredAt'] ?? $item['createdAt'] ?? '';
            $received_date = $format_thai_date($delivered_at);
            $received_date_long = $format_thai_date_long($delivered_at);
            $received_time = $format_thai_time($delivered_at);

            $display_items[] = [
                'inbox_id' => (int) ($item['inboxID'] ?? 0),
                'circular_id' => $circular_id,
                'is_read' => (int) ($item['isRead'] ?? 0) === 1,
                'type' => strtoupper((string) ($item['circularType'] ?? '')),
                'type_label' => strtoupper((string) ($item['circularType'] ?? '')) === 'EXTERNAL' ? 'ภายนอก' : 'ภายใน',
                'dh_year' => (int) ($item['dh_year'] ?? 0),
                'subject' => (string) ($item['subject'] ?? ''),
                'sender_name' => (string) ($item['senderName'] ?? '-'),
                'detail' => (string) ($item['detail'] ?? ''),
                'link_url' => (string) ($item['linkURL'] ?? ''),
                'delivered_date' => $received_date,
                'delivered_date_long' => $received_date_long,
                'delivered_time' => $received_time,
                'files_json' => $files_json,
                'ext_priority' => $priority,
                'ext_priority_label' => $priority_label,
                'urgency_class' => $urgency_class,
                'ext_book_no' => (string) ($item['extBookNo'] ?? ''),
                'ext_issued_date' => $format_thai_date((string) ($item['extIssuedDate'] ?? '')),
                'ext_from_text' => (string) ($item['extFromText'] ?? ''),
                'status_key' => $status_key,
                'status_label' => $status_label,
                'consider_class' => $consider_class,
            ];
        }

        view_render('circular/archive', [
            'alert' => $alert,
            'items' => $display_items,
            'box_key' => $box_key,
            'dh_year_options' => $dh_year_options,
            'selected_dh_year' => $selected_dh_year,
            'filter_type' => $filter_type,
            'filter_read' => $filter_read,
            'filter_sort' => $filter_sort,
            'filter_view' => $filter_view,
            'filter_search' => $filter_search,
            'is_outside_view' => $is_outside_view,
            'director_label' => $director_label,
            'is_registry' => $is_registry,
            'is_director_box' => $is_director_box,
        ]);
    }
}
