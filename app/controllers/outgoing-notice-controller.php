<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../../src/Services/security/security-service.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/circulars/repository.php';
require_once __DIR__ . '/../modules/circulars/service.php';
require_once __DIR__ . '/../modules/users/lists.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../modules/audit/logger.php';

if (!function_exists('outgoing_notice_deputy_forward_pids')) {
    function outgoing_notice_deputy_forward_pids(mysqli $connection, array $deputy_position_ids, ?string $acting_pid): array
    {
        $deputy_position_ids = array_values(array_unique(array_filter(array_map('intval', $deputy_position_ids), static function (int $position_id): bool {
            return $position_id > 0;
        })));

        if ($deputy_position_ids === []) {
            return [];
        }

        $joins = [];
        $conditions = [];
        $types = '';
        $params = [];

        $append_position_condition = static function (string $column) use (&$conditions, &$types, &$params, $deputy_position_ids): void {
            $conditions[] = $column . ' IN (' . implode(', ', array_fill(0, count($deputy_position_ids), '?')) . ')';
            $types .= str_repeat('i', count($deputy_position_ids));
            array_push($params, ...$deputy_position_ids);
        };

        $append_position_condition('t.positionID');

        if (db_table_exists($connection, 'dh_user_positions')) {
            $joins[] = 'LEFT JOIN dh_user_positions AS dup ON dup.pID = t.pID';
            $append_position_condition('dup.positionID');
        }

        if (db_table_exists($connection, 'user_positions')) {
            $joins[] = 'LEFT JOIN user_positions AS up ON up.teacher_id = t.pID';
            $append_position_condition('up.position_id');
        }

        $rows = db_fetch_all(
            'SELECT DISTINCT t.pID, t.fName
             FROM teacher AS t
             ' . implode("\n", $joins) . '
             WHERE t.status = 1
               AND (' . implode(' OR ', $conditions) . ')
             ORDER BY t.fName ASC',
            $types,
            ...$params
        );

        $acting_pid = trim((string) $acting_pid);
        $pids = [];

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid === '' || !ctype_digit($pid) || ($acting_pid !== '' && $pid === $acting_pid)) {
                continue;
            }

            $pids[$pid] = true;
        }

        return array_keys($pids);
    }
}

if (!function_exists('outgoing_notice_index')) {
    function outgoing_notice_index(): void
    {
        $is_internal_only_notice_page = false;
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');
        $factions = user_list_factions();
        $roles = user_list_roles();
        $teachers = user_list_teachers();

        $connection = db_connection();
        $is_registry = rbac_user_has_role($connection, $current_pid, ROLE_REGISTRY);
        $is_admin = rbac_user_has_role($connection, $current_pid, ROLE_ADMIN);
        $position_ids = current_user_position_ids();
        $deputy_position_ids = system_position_deputy_ids($connection);
        $is_deputy_reviewer = !empty(array_intersect($position_ids, $deputy_position_ids));

        if (!$is_registry && (int) ($current_user['roleID'] ?? 0) === 2) {
            $is_registry = true;
        }
        if (!$is_admin && (int) ($current_user['roleID'] ?? 0) === 1) {
            $is_admin = true;
        }
        $can_manage_external = $is_registry || $is_admin;

        $acting_pid = system_get_acting_director_pid();
        $director_pid = system_get_current_director_pid();
        $deputy_forward_pids = outgoing_notice_deputy_forward_pids($connection, $deputy_position_ids, $acting_pid);
        $is_director_box = $director_pid !== null && $director_pid === $current_pid;
        $is_acting_director = $acting_pid !== null && $acting_pid !== '' && $acting_pid === $current_pid;
        $is_reviewer_box = $is_director_box || $is_acting_director || $is_deputy_reviewer;

        $default_box = 'normal';

        if ($can_manage_external) {
            $default_box = 'clerk';
        } elseif ($is_reviewer_box) {
            $default_box = 'director';
        }

        $box = (string) ($_GET['box'] ?? $default_box);
        $legacy_clerk_return_box = $box === 'clerk_return';

        if ($legacy_clerk_return_box) {
            $box = 'clerk';
        }

        $archived = isset($_GET['archived']) && $_GET['archived'] === '1';

        $director_inbox_type = ($acting_pid !== null && $acting_pid !== '' && $acting_pid === $current_pid)
            ? INBOX_TYPE_ACTING_PRINCIPAL
            : INBOX_TYPE_SPECIAL_PRINCIPAL;

        $allowed_boxes = ['normal'];

        if (!$is_internal_only_notice_page) {
            if ($can_manage_external) {
                $allowed_boxes[] = 'clerk';
            }

            if ($is_reviewer_box) {
                $allowed_boxes[] = 'director';
            }
        }

        if (!in_array($box, $allowed_boxes, true)) {
            $box = $default_box;
        }

        $box_map = [
            'normal' => INBOX_TYPE_NORMAL,
            'director' => $director_inbox_type,
            'clerk' => INBOX_TYPE_NORMAL,
        ];
        $box_key = array_key_exists($box, $box_map) ? $box : 'normal';
        $inbox_type = $box_map[$box_key];

        $is_outside_view = !$is_internal_only_notice_page && in_array($box_key, ['director', 'clerk'], true);
        $default_type = $is_internal_only_notice_page ? 'internal' : 'external';

        $filter_type = (string) ($_GET['type'] ?? $default_type);
        $filter_sort = (string) ($_GET['sort'] ?? 'newest');
        $filter_view = (string) ($_GET['view'] ?? ($legacy_clerk_return_box ? 'table2' : 'table1'));
        $filter_search = trim((string) ($_GET['q'] ?? ''));
        $selected_dh_year = (int) ($_GET['dh_year'] ?? 0);

        $allowed_types = $is_internal_only_notice_page ? ['internal'] : ['external'];
        $allowed_sort = ['newest', 'oldest'];
        $allowed_views = ['table1', 'table2'];

        if (!in_array($filter_type, $allowed_types, true)) {
            $filter_type = $default_type;
        }

        if ($is_internal_only_notice_page) {
            $filter_type = 'internal';
        }

        if (!in_array($filter_sort, $allowed_sort, true)) {
            $filter_sort = 'newest';
        }

        if (!in_array($filter_view, $allowed_views, true)) {
            $filter_view = 'table1';
        }

        if ($legacy_clerk_return_box && $can_manage_external) {
            $filter_view = 'table2';
        }

        $table_status_map = [
            'director' => [
                'table1' => [EXTERNAL_STATUS_PENDING_REVIEW],
                'table2' => [EXTERNAL_STATUS_REVIEWED, EXTERNAL_STATUS_FORWARDED, EXTERNAL_STATUS_SUBMITTED],
            ],
            'clerk' => [
                'table1' => [EXTERNAL_STATUS_PENDING_REVIEW],
                'table2' => [EXTERNAL_STATUS_REVIEWED, EXTERNAL_STATUS_FORWARDED, EXTERNAL_STATUS_SUBMITTED],
            ],
            'default' => [
                'table1' => [INTERNAL_STATUS_SENT, EXTERNAL_STATUS_FORWARDED],
                'table2' => [INTERNAL_STATUS_DRAFT, INTERNAL_STATUS_RECALLED, INTERNAL_STATUS_ARCHIVED, EXTERNAL_STATUS_SUBMITTED, EXTERNAL_STATUS_PENDING_REVIEW, EXTERNAL_STATUS_REVIEWED],
            ],
        ];

        $table_status_filter = $table_status_map[$box_key] ?? $table_status_map['default'];
        $table_status_filter = $table_status_filter[$filter_view] ?? [];
        $registry_tracking_sender_pids = [];

        if ($box_key === 'normal' && $filter_type === 'external') {
            $registry_tracking_sender_pids = array_fill_keys(circular_registry_pids(), true);
        }

        $alert = flash_get('circular_notice_alert');
        $forward_open_inbox_id = (int) (flash_get('circular_notice_forward_open_inbox_id') ?? 0);

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

                if ($action === 'archive' && $inbox_id > 0) {
                    circular_archive_inbox($inbox_id, $current_pid);

                    if (function_exists('audit_log')) {
                        audit_log('circulars', 'ARCHIVE', 'SUCCESS', 'dh_circular_inboxes', $inbox_id);
                    }
                    $alert = [
                        'type' => 'success',
                        'title' => 'จัดเก็บเรียบร้อย',
                        'message' => '',
                    ];
                } elseif ($action === 'unarchive' && $inbox_id > 0) {
                    circular_unarchive_inbox($inbox_id, $current_pid);

                    if (function_exists('audit_log')) {
                        audit_log('circulars', 'UNARCHIVE', 'SUCCESS', 'dh_circular_inboxes', $inbox_id);
                    }
                    $alert = [
                        'type' => 'success',
                        'title' => 'ย้ายกลับเรียบร้อย',
                        'message' => '',
                    ];
                } elseif ($action === 'archive_selected') {
                    if (empty($selected_ids)) {
                        $alert = [
                            'type' => 'warning',
                            'title' => 'กรุณาเลือกรายการ',
                            'message' => '',
                        ];
                    } else {
                        foreach ($selected_ids as $selected_id) {
                            circular_archive_inbox($selected_id, $current_pid);

                            if (function_exists('audit_log')) {
                                audit_log('circulars', 'ARCHIVE', 'SUCCESS', 'dh_circular_inboxes', $selected_id);
                            }
                        }
                        $alert = [
                            'type' => 'success',
                            'title' => 'จัดเก็บรายการที่เลือกแล้ว',
                            'message' => '',
                        ];
                    }
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
                } elseif ($action === 'forward') {
                    $circular_id = (int) ($_POST['circular_id'] ?? 0);
                    $forward_open_inbox_id = $inbox_id;
                    $selected_factions = array_values(array_filter(array_map('intval', (array) ($_POST['faction_ids'] ?? []))));
                    $selected_roles = array_values(array_filter(array_map('intval', (array) ($_POST['role_ids'] ?? [])), static function (int $role_id): bool {
                        return $role_id > 0;
                    }));
                    $selected_people = array_values(array_filter(array_map(static function ($value): string {
                        return trim((string) $value);
                    }, (array) ($_POST['person_ids'] ?? [])), static function (string $value): bool {
                        return $value !== '';
                    }));
                    $can_reviewer_return_to_registry = $box_key === 'director' && $is_reviewer_box && !$archived;
                    $can_registry_forward_to_deputies = $can_manage_external && $box_key === 'clerk' && !$archived;

                    if ($can_reviewer_return_to_registry) {
                        $inbox_row = db_fetch_one(
                            'SELECT i.inboxID, i.circularID, i.inboxType, c.circularType, c.status
                             FROM dh_circular_inboxes AS i
                             INNER JOIN dh_circulars AS c ON c.circularID = i.circularID
                             WHERE i.inboxID = ? AND i.pID = ? AND i.isArchived = 0
                             LIMIT 1',
                            'is',
                            $inbox_id,
                            $current_pid
                        );

                        if (
                            !$inbox_row
                            || (int) ($inbox_row['circularID'] ?? 0) !== $circular_id
                            || (string) ($inbox_row['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
                            || (string) ($inbox_row['status'] ?? '') !== EXTERNAL_STATUS_PENDING_REVIEW
                            || !in_array((string) ($inbox_row['inboxType'] ?? ''), [INBOX_TYPE_SPECIAL_PRINCIPAL, INBOX_TYPE_ACTING_PRINCIPAL], true)
                        ) {
                            $alert = [
                                'type' => 'danger',
                                'title' => 'ไม่มีสิทธิ์ดำเนินการ',
                                'message' => 'ไม่พบรายการที่ต้องการส่งต่อ',
                            ];
                        } else {
                            $comment = trim((string) ($_POST['comment'] ?? ''));
                            $new_fid = (int) ($_POST['extGroupFID'] ?? 0);

                            try {
                                circular_director_review($circular_id, $current_pid, $comment !== '' ? $comment : null, $new_fid > 0 ? $new_fid : null);

                                flash_set('circular_notice_alert', [
                                    'type' => 'success',
                                    'title' => 'พิจารณาเรียบร้อย',
                                    'message' => '',
                                ]);
                                header('Location: ' . (string) ($_SERVER['REQUEST_URI'] ?? 'outgoing-notice.php'));
                                exit;
                            } catch (Throwable $exception) {
                                $alert = [
                                    'type' => 'danger',
                                    'title' => $exception->getMessage(),
                                    'message' => '',
                                ];
                            }
                        }
                    } elseif ($can_registry_forward_to_deputies) {
                        $inbox_row = db_fetch_one(
                            'SELECT i.inboxID, i.circularID, c.circularType, c.status
                             FROM dh_circular_inboxes AS i
                             INNER JOIN dh_circulars AS c ON c.circularID = i.circularID
                             WHERE i.inboxID = ? AND i.pID = ? AND i.isArchived = 0
                             LIMIT 1',
                            'is',
                            $inbox_id,
                            $current_pid
                        );

                        if (
                            !$inbox_row
                            || (int) ($inbox_row['circularID'] ?? 0) !== $circular_id
                            || (string) ($inbox_row['circularType'] ?? '') !== CIRCULAR_TYPE_EXTERNAL
                            || (string) ($inbox_row['status'] ?? '') !== EXTERNAL_STATUS_REVIEWED
                        ) {
                            $alert = [
                                'type' => 'danger',
                                'title' => 'ไม่มีสิทธิ์ดำเนินการ',
                                'message' => 'ไม่พบรายการที่ต้องการส่งต่อ',
                            ];
                        } else {
                            $allowed_deputy_pids = array_fill_keys($deputy_forward_pids, true);

                            $target_deputy_pids = array_values(array_filter($selected_people, static function (string $pid) use ($allowed_deputy_pids): bool {
                                return isset($allowed_deputy_pids[$pid]);
                            }));

                            try {
                                circular_registry_forward_to_deputies($circular_id, $current_pid, $target_deputy_pids);

                                flash_set('circular_notice_alert', [
                                    'type' => 'success',
                                    'title' => 'ส่งต่อเรียบร้อย',
                                    'message' => '',
                                ]);
                                flash_set('circular_notice_forward_open_inbox_id', $inbox_id);
                                header('Location: ' . (string) ($_SERVER['REQUEST_URI'] ?? 'outgoing-notice.php'));
                                exit;
                            } catch (Throwable $exception) {
                                $alert = [
                                    'type' => 'danger',
                                    'title' => $exception->getMessage(),
                                    'message' => '',
                                ];
                            }
                        }
                    } elseif ($is_outside_view || $archived) {
                        $alert = [
                            'type' => 'warning',
                            'title' => 'ไม่สามารถส่งต่อได้',
                            'message' => 'หน้านี้ไม่รองรับการส่งต่อหนังสือเวียน',
                        ];
                    } else {
                        $inbox_row = db_fetch_one(
                            'SELECT i.inboxID, i.circularID, i.inboxType, c.circularType, c.status, c.createdByPID
                             FROM dh_circular_inboxes AS i
                             INNER JOIN dh_circulars AS c ON c.circularID = i.circularID
                             WHERE i.inboxID = ? AND i.pID = ? AND i.isArchived = 0
                             LIMIT 1',
                            'is',
                            $inbox_id,
                            $current_pid
                        );

                        if (!$inbox_row || (int) ($inbox_row['circularID'] ?? 0) !== $circular_id) {
                            $alert = [
                                'type' => 'danger',
                                'title' => 'ไม่มีสิทธิ์ดำเนินการ',
                                'message' => 'ไม่พบรายการที่ต้องการส่งต่อ',
                            ];
                        } else {
                            $owner_pid = trim((string) ($inbox_row['createdByPID'] ?? ''));
                            $resolved_pids = circular_resolve_person_ids($selected_factions, $selected_roles, $selected_people);
                            $resolved_pids = array_values(array_filter(array_map(static function ($value): string {
                                return trim((string) $value);
                            }, $resolved_pids), static function (string $value): bool {
                                return $value !== '';
                            }));

                            if ($owner_pid !== '') {
                                $resolved_pids = array_values(array_filter($resolved_pids, static function (string $pid) use ($owner_pid): bool {
                                    return $pid !== $owner_pid;
                                }));
                            }

                            $resolved_pids = array_values(array_unique($resolved_pids));
                            $targets = [];

                            foreach ($resolved_pids as $person_id) {
                                $targets[] = [
                                    'targetType' => 'PERSON',
                                    'fID' => null,
                                    'roleID' => null,
                                    'pID' => $person_id,
                                    'isCc' => 0,
                                ];
                            }

                            foreach ($selected_factions as $faction_id) {
                                if ($faction_id <= 0) {
                                    continue;
                                }

                                $targets[] = [
                                    'targetType' => 'UNIT',
                                    'fID' => $faction_id,
                                    'roleID' => null,
                                    'pID' => null,
                                    'isCc' => 0,
                                ];
                            }

                            foreach ($selected_roles as $role_id) {
                                if ($role_id <= 0) {
                                    continue;
                                }

                                $targets[] = [
                                    'targetType' => 'ROLE',
                                    'fID' => null,
                                    'roleID' => $role_id,
                                    'pID' => null,
                                    'isCc' => 0,
                                ];
                            }

                            try {
                                $item_type = strtoupper((string) ($inbox_row['circularType'] ?? ''));
                                $item_status = strtoupper((string) ($inbox_row['status'] ?? ''));
                                $item_inbox_type = (string) ($inbox_row['inboxType'] ?? '');
                                $can_deputy_distribute = $is_deputy_reviewer
                                    && $item_type === CIRCULAR_TYPE_EXTERNAL
                                    && $item_status === EXTERNAL_STATUS_FORWARDED
                                    && $item_inbox_type === INBOX_TYPE_NORMAL;

                                if ($can_deputy_distribute) {
                                    $comment = trim((string) ($_POST['deputy_comment'] ?? ''));
                                    $publish_announcement = (string) ($_POST['publish_announcement'] ?? '') === '1';
                                    $recipient_payload = $publish_announcement
                                        ? ['pids' => [], 'targets' => [], 'allow_empty' => true]
                                        : ['pids' => $resolved_pids, 'targets' => $targets, 'allow_empty' => false];

                                    circular_deputy_distribute($circular_id, $current_pid, $recipient_payload, $comment !== '' ? $comment : null);

                                    if ($publish_announcement) {
                                        circular_set_announcement($circular_id, $current_pid);
                                    }
                                } else {
                                    circular_forward($circular_id, $current_pid, [
                                        'pids' => $resolved_pids,
                                        'targets' => $targets,
                                    ]);
                                }

                                circular_mark_read($inbox_id, $current_pid);

                                flash_set('circular_notice_alert', [
                                    'type' => 'success',
                                    'title' => ($can_deputy_distribute && $publish_announcement) ? 'ขึ้นข่าวประชาสัมพันธ์เรียบร้อย' : ($can_deputy_distribute ? 'กระจายหนังสือเรียบร้อย' : 'ส่งต่อเรียบร้อย'),
                                    'message' => '',
                                ]);
                                if (!$can_deputy_distribute || !$publish_announcement) {
                                    flash_set('circular_notice_forward_open_inbox_id', $inbox_id);
                                }
                                header('Location: ' . (string) ($_SERVER['REQUEST_URI'] ?? 'outgoing-notice.php'));
                                exit;
                            } catch (Throwable $exception) {
                                $alert = [
                                    'type' => 'danger',
                                    'title' => $exception->getMessage(),
                                    'message' => '',
                                ];
                            }
                        }
                    }
                }
            }
        }

        $circular_inbox = circular_get_inbox($current_pid, $inbox_type, $archived);
        $circular_ids = array_values(array_unique(array_map(static function (array $item): int {
            return (int) ($item['circularID'] ?? 0);
        }, $circular_inbox)));

        $detail_map = [];

        if (!empty($circular_ids)) {
            $placeholders = implode(', ', array_fill(0, count($circular_ids), '?'));
            $types = str_repeat('i', count($circular_ids));
            $has_receive_seq = db_column_exists($connection, 'dh_circulars', 'extReceiveSeq');
            $sql = 'SELECT c.circularID, c.dh_year, c.circularType, c.subject, c.detail, c.linkURL,
                    c.extPriority, c.extBookNo, c.extIssuedDate, c.extFromText, c.extGroupFID,
                    ' . ($has_receive_seq ? 'c.extReceiveSeq' : 'NULL AS extReceiveSeq') . ',
                    COALESCE(f.fName, "") AS extGroupName,
                    c.status, c.createdAt, c.createdByPID
                FROM dh_circulars AS c
                LEFT JOIN faction AS f ON c.extGroupFID = f.fID
                WHERE c.circularID IN (' . $placeholders . ')';
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
            $item['extReceiveSeq'] = $detail['extReceiveSeq'] ?? null;
            $item['extIssuedDate'] = $detail['extIssuedDate'] ?? '';
            $item['extFromText'] = $detail['extFromText'] ?? '';
            $item['extGroupFID'] = $detail['extGroupFID'] ?? '';
            $item['extGroupName'] = $detail['extGroupName'] ?? '';
            $item['dh_year'] = $detail['dh_year'] ?? ($item['dh_year'] ?? null);
            $item['status'] = $detail['status'] ?? ($item['status'] ?? '');
            $item['createdByPID'] = $detail['createdByPID'] ?? ($item['createdByPID'] ?? '');
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

        $items = array_values(array_filter($items, static function (array $item) use ($filter_type, $filter_search, $selected_dh_year, $box_key, $current_pid, $registry_tracking_sender_pids, $can_manage_external): bool {
            $type = strtoupper((string) ($item['circularType'] ?? ''));

            if ($filter_type === 'internal' && $type !== 'INTERNAL') {
                return false;
            }

            if ($filter_type === 'external' && $type !== 'EXTERNAL') {
                return false;
            }

            if ($box_key === 'normal' && $filter_type === 'external') {
                $delivered_by_pid = trim((string) ($item['deliveredByPID'] ?? ''));

                if ($delivered_by_pid === '' || $delivered_by_pid === $current_pid) {
                    return false;
                }

                if ($can_manage_external && isset($registry_tracking_sender_pids[$delivered_by_pid])) {
                    return false;
                }
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

        if ($is_outside_view && !empty($table_status_filter)) {
            $items = array_values(array_filter($items, static function (array $item) use ($table_status_filter): bool {
                $status = strtoupper((string) ($item['status'] ?? ''));

                return in_array($status, $table_status_filter, true);
            }));
        }

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
        $forwarded_recipients_map = [];
        $director_review_map = [];
        $latest_sender_comment_map = [];
        $system_route_notes = [
            'CLERK_FORWARD_TO_DEPUTY',
            'REVIEWER_FORWARD_TO_DEPUTY',
            'EDIT_RESEND',
            'RESEND',
            'EXTERNAL_BEFORE_REVIEW',
        ];

        if (!empty($items) && db_table_exists($connection, 'dh_file_refs')) {
            $entity_ids = array_values(array_unique(array_filter(array_map(static function (array $item): int {
                return (int) ($item['circularID'] ?? 0);
            }, $items), static function (int $id): bool {
                return $id > 0;
            })));

            if (!empty($entity_ids)) {
                $placeholders = implode(', ', array_fill(0, count($entity_ids), '?'));
                $types = str_repeat('i', count($entity_ids));
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
                $rows = db_fetch_all($sql, $types, ...$entity_ids);

                foreach ($rows as $row) {
                    $entity_id = (string) ((int) ($row['circularID'] ?? 0));

                    if ($entity_id === '0') {
                        continue;
                    }

                    if (!isset($attachments_map[$entity_id])) {
                        $attachments_map[$entity_id] = [];
                    }

                    $attachments_map[$entity_id][] = [
                        'fileID' => (int) ($row['fileID'] ?? 0),
                        'fileName' => trim((string) ($row['fileName'] ?? '')),
                        'filePath' => trim((string) ($row['filePath'] ?? '')),
                        'mimeType' => trim((string) ($row['mimeType'] ?? '')),
                        'fileSize' => (int) ($row['fileSize'] ?? 0),
                        'fileNote' => trim((string) ($row['fileNote'] ?? '')),
                    ];
                }
            }
        }

        if (!empty($items) && db_table_exists($connection, 'dh_circular_routes')) {
            $entity_ids = array_values(array_unique(array_filter(array_map(static function (array $item): int {
                return (int) ($item['circularID'] ?? 0);
            }, $items), static function (int $id): bool {
                return $id > 0;
            })));

            if (!empty($entity_ids)) {
                $placeholders = implode(', ', array_fill(0, count($entity_ids), '?'));
                $types = str_repeat('i', count($entity_ids));
                $rows = db_fetch_all(
                    'SELECT r.circularID, r.note, r.fromPID, COALESCE(t.fName, "") AS reviewerName
                     FROM dh_circular_routes AS r
                     INNER JOIN (
                         SELECT circularID, MAX(routeID) AS routeID
                         FROM dh_circular_routes
                         WHERE action = \'RETURN\'
                           AND circularID IN (' . $placeholders . ')
                         GROUP BY circularID
                     ) AS latest ON latest.routeID = r.routeID
                     LEFT JOIN teacher AS t ON t.pID = r.fromPID',
                    $types,
                    ...$entity_ids
                );

                foreach ($rows as $row) {
                    $circular_id_key = (string) ((int) ($row['circularID'] ?? 0));

                    if ($circular_id_key === '0') {
                        continue;
                    }

                    $director_review_map[$circular_id_key] = [
                        'comment' => trim((string) ($row['note'] ?? '')),
                        'reviewer_pid' => trim((string) ($row['fromPID'] ?? '')),
                        'reviewer_name' => trim((string) ($row['reviewerName'] ?? '')),
                    ];
                }
            }

            if (!empty($entity_ids)) {
                $placeholders = implode(', ', array_fill(0, count($entity_ids), '?'));
                $types = str_repeat('i', count($entity_ids));
                $rows = db_fetch_all(
                    'SELECT r.circularID, r.routeID, r.action, r.note, r.fromPID,
                            COALESCE(t.fName, "") AS senderName,
                            COALESCE(p.positionName, "") AS senderPositionName
                     FROM dh_circular_routes AS r
                     LEFT JOIN teacher AS t ON t.pID = r.fromPID
                     LEFT JOIN dh_positions AS p ON p.positionID = t.positionID
                     WHERE r.circularID IN (' . $placeholders . ')
                       AND r.fromPID IS NOT NULL
                       AND r.fromPID <> \'\'
                     ORDER BY r.routeID ASC',
                    $types,
                    ...$entity_ids
                );

                foreach ($rows as $row) {
                    $circular_id_key = (string) ((int) ($row['circularID'] ?? 0));
                    $from_pid = trim((string) ($row['fromPID'] ?? ''));
                    $route_note = trim((string) ($row['note'] ?? ''));

                    if (
                        $circular_id_key === '0'
                        || $from_pid === ''
                        || $route_note === ''
                        || in_array(strtoupper($route_note), $system_route_notes, true)
                    ) {
                        continue;
                    }

                    if (!isset($latest_sender_comment_map[$circular_id_key])) {
                        $latest_sender_comment_map[$circular_id_key] = [];
                    }

                    $latest_sender_comment_map[$circular_id_key][$from_pid] = [
                        'action' => strtoupper(trim((string) ($row['action'] ?? ''))),
                        'comment' => $route_note,
                        'sender_pid' => $from_pid,
                        'sender_name' => trim((string) ($row['senderName'] ?? '')),
                        'sender_position_name' => trim((string) ($row['senderPositionName'] ?? '')),
                    ];
                }
            }
        }

        if (!empty($items)) {
            $entity_ids = array_values(array_unique(array_map(static function (array $item): string {
                return (string) ($item['circularID'] ?? '');
            }, $items)));

            if (!empty($entity_ids)) {
                $placeholders = implode(', ', array_fill(0, count($entity_ids), '?'));
                $types = 's' . str_repeat('i', count($entity_ids));
                $params = array_merge([$current_pid], array_map('intval', $entity_ids));
                $rows = db_fetch_all(
                    'SELECT circularID, pID
                     FROM dh_circular_inboxes
                     WHERE deliveredByPID = ? AND isArchived = 0 AND circularID IN (' . $placeholders . ')
                     ORDER BY inboxID ASC',
                    $types,
                    ...$params
                );

                foreach ($rows as $row) {
                    $circular_id_key = (string) ($row['circularID'] ?? '');
                    $recipient_pid = trim((string) ($row['pID'] ?? ''));

                    if ($circular_id_key === '' || $recipient_pid === '') {
                        continue;
                    }

                    if (!isset($forwarded_recipients_map[$circular_id_key])) {
                        $forwarded_recipients_map[$circular_id_key] = [];
                    }

                    $forwarded_recipients_map[$circular_id_key][$recipient_pid] = true;
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

        $director_pids_for_label = array_values(array_filter(array_unique([
            (string) (system_get_director_pid() ?? ''),
            (string) (system_get_acting_director_pid() ?? ''),
            (string) (system_get_current_director_pid() ?? ''),
        ])));

        $latest_comment_label = static function (array $route) use ($director_pids_for_label): string {
            $action = strtoupper(trim((string) ($route['action'] ?? '')));
            $sender_pid = trim((string) ($route['sender_pid'] ?? ''));
            $position_name = trim((string) ($route['sender_position_name'] ?? ''));
            $is_deputy_sender = str_contains($position_name, 'รองผู้อำนวยการ');
            $is_director_sender = ($sender_pid !== '' && in_array($sender_pid, $director_pids_for_label, true))
                || (!$is_deputy_sender && str_contains($position_name, 'ผู้อำนวยการ'));

            if ($action === 'RETURN' && $is_director_sender) {
                return 'ความคิดเห็นของผู้อำนวยการโรงเรียน';
            }

            if ($action === 'APPROVE' || $is_deputy_sender) {
                return 'ความคิดเห็นของรองผู้อำนวยการ';
            }

            if ($action === 'RETURN') {
                return 'ความคิดเห็นของผู้พิจารณา';
            }

            if (in_array($action, ['CREATE', 'SEND'], true)) {
                return 'ความคิดเห็นของเจ้าหน้าที่สารบรรณ';
            }

            return 'ความคิดเห็นของผู้ส่งล่าสุด';
        };
        $append_comment_section = static function (array &$sections, string $label, string $comment): void {
            $comment = trim($comment);

            if ($comment === '') {
                return;
            }

            $sections[] = '<p><strong>' . h($label) . '</strong></p>' . $comment;
        };

        $display_items = [];

        foreach ($items as $item) {
            $circular_id = (int) ($item['circularID'] ?? 0);
            $priority = trim((string) ($item['extPriority'] ?? ''));
            $priority_label = $priority !== '' ? $priority : 'ปกติ';
            $urgency_class = $urgency_class_map[$priority_label] ?? 'normal';
            $status_key = strtoupper((string) ($item['status'] ?? ''));
            $can_deputy_distribute_item = $box_key === 'normal'
                && $filter_type === 'external'
                && $is_deputy_reviewer
                && strtoupper((string) ($item['circularType'] ?? '')) === CIRCULAR_TYPE_EXTERNAL
                && $status_key === EXTERNAL_STATUS_FORWARDED;
            $status_label = $resolve_status_label($status_key, $box_key);
            $consider_class = $consider_class_map[$status_key] ?? 'considering';
            $files = $attachments_map[(string) $circular_id] ?? [];
            $director_review = $director_review_map[(string) $circular_id] ?? [];
            $delivered_by_pid = trim((string) ($item['deliveredByPID'] ?? ''));
            $latest_sender_comment = $latest_sender_comment_map[(string) $circular_id][$delivered_by_pid] ?? [];
            $latest_sender_comment_text = trim((string) ($latest_sender_comment['comment'] ?? ''));
            $registry_route = $latest_sender_comment_map[(string) $circular_id][trim((string) ($item['createdByPID'] ?? ''))] ?? [];
            $registry_comment_text = trim((string) ($registry_route['comment'] ?? ''));
            $director_comment_text = trim((string) ($director_review['comment'] ?? ''));
            $comment_display_text = $latest_sender_comment_text;
            $comment_display_label = $latest_sender_comment_text !== '' ? $latest_comment_label($latest_sender_comment) : '';
            $show_review_chain_comments = strtoupper((string) ($item['circularType'] ?? '')) === CIRCULAR_TYPE_EXTERNAL
                && (
                    ($box_key === 'clerk' && $status_key === EXTERNAL_STATUS_REVIEWED)
                    || $can_deputy_distribute_item
                );

            if ($show_review_chain_comments) {
                $comment_sections = [];
                $append_comment_section($comment_sections, 'ความคิดเห็นของเจ้าหน้าที่สารบรรณ', $registry_comment_text);
                $append_comment_section($comment_sections, 'ความคิดเห็นของผู้อำนวยการโรงเรียน', $director_comment_text);

                if (!empty($comment_sections)) {
                    $comment_display_text = implode('<hr>', $comment_sections);
                    $comment_display_label = 'ความคิดเห็นประกอบการพิจารณา';
                }
            } elseif ($box_key === 'director' && $status_key === EXTERNAL_STATUS_REVIEWED && $director_comment_text !== '') {
                $comment_display_text = $director_comment_text;
                $comment_display_label = 'ความคิดเห็นของผู้อำนวยการโรงเรียน';
            }
            $files_json = json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $forwarded_recipient_pids = array_keys($forwarded_recipients_map[(string) $circular_id] ?? []);
            $forwarded_recipient_pids_json = json_encode($forwarded_recipient_pids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($files_json === false) {
                $files_json = '[]';
            }

            if ($forwarded_recipient_pids_json === false) {
                $forwarded_recipient_pids_json = '[]';
            }

            $read_stats_json = '[]';

            if ($circular_id > 0) {
                $read_stats = circular_get_read_stats($circular_id);

                if ($can_deputy_distribute_item) {
                    $allowed_read_stat_pids = array_fill_keys(array_map(static function ($pid): string {
                        return 'pid:' . trim((string) $pid);
                    }, $forwarded_recipient_pids), true);

                    $read_stats = array_values(array_filter($read_stats, static function (array $entry) use ($allowed_read_stat_pids): bool {
                        $pid = trim((string) ($entry['pID'] ?? ''));
                        return $pid !== '' && isset($allowed_read_stat_pids['pid:' . $pid]);
                    }));
                }

                $read_stats = array_map(static function (array $entry) use ($format_thai_date_long, $format_thai_time): array {
                    $read_at = trim((string) ($entry['readAt'] ?? ''));

                    if ($read_at !== '') {
                        $entry['readAtDisplay'] = $format_thai_date_long($read_at) . ' ' . $format_thai_time($read_at);
                    } else {
                        $entry['readAtDisplay'] = '-';
                    }

                    return $entry;
                }, $read_stats);
                $read_stats_json = json_encode($read_stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($read_stats_json === false) {
                    $read_stats_json = '[]';
                }
            }

            $delivered_at = $item['deliveredAt'] ?? $item['createdAt'] ?? '';
            $created_at = $item['createdAt'] ?? '';
            $received_date = $format_thai_date($delivered_at);
            $received_date_long = $format_thai_date_long($delivered_at);
            $received_date_plain = str_replace('พ.ศ.', '', $received_date_long);
            $received_time = $format_thai_time($delivered_at);
            $created_date_long = $format_thai_date_long($created_at);
            $sender_name = trim((string) ($item['senderName'] ?? ''));
            $sender_faction_name = trim((string) ($item['senderFactionName'] ?? ''));
            $sender_display = '-';

            if ($sender_name !== '' && $sender_faction_name !== '') {
                $sender_display = $sender_name . ' / ' . $sender_faction_name;
            } elseif ($sender_name !== '') {
                $sender_display = $sender_name;
            } elseif ($sender_faction_name !== '') {
                $sender_display = $sender_faction_name;
            }

            $display_items[] = [
                'inbox_id' => (int) ($item['inboxID'] ?? 0),
                'circular_id' => $circular_id,
                'is_read' => (int) ($item['isRead'] ?? 0) === 1,
                'type' => strtoupper((string) ($item['circularType'] ?? '')),
                'type_label' => strtoupper((string) ($item['circularType'] ?? '')) === 'EXTERNAL' ? 'ภายนอก' : 'ภายใน',
                'subject' => (string) ($item['subject'] ?? ''),
                'dh_year' => (int) ($item['dh_year'] ?? 0),
                'sender_name' => $sender_name !== '' ? $sender_name : '-',
                'sender_faction_name' => $sender_faction_name,
                'sender_display' => $sender_display,
                'owner_pid' => (string) ($item['createdByPID'] ?? ''),
                'delivered_by_pid' => $delivered_by_pid,
                'detail' => (string) ($item['detail'] ?? ''),
                'link_url' => (string) ($item['linkURL'] ?? ''),
                'delivered_date' => $received_date,
                'delivered_date_long' => $received_date_long,
                'delivered_date_plain' => $received_date_plain,
                'delivered_time' => $received_time,
                'created_date_long' => $created_date_long,
                'files_json' => $files_json,
                'forwarded_recipient_pids_json' => $forwarded_recipient_pids_json,
                'read_stats_json' => $read_stats_json,
                'ext_priority' => $priority,
                'ext_priority_label' => $priority_label,
                'urgency_class' => $urgency_class,
                'ext_receive_seq' => (int) ($item['extReceiveSeq'] ?? 0),
                'ext_book_no' => (string) ($item['extBookNo'] ?? ''),
                'ext_issued_date' => $format_thai_date((string) ($item['extIssuedDate'] ?? '')),
                'ext_issued_date_raw' => (string) ($item['extIssuedDate'] ?? ''),
                'ext_from_text' => (string) ($item['extFromText'] ?? ''),
                'ext_group_fid' => (int) ($item['extGroupFID'] ?? 0),
                'ext_group_name' => trim((string) ($item['extGroupName'] ?? '')),
                'director_comment' => $director_comment_text,
                'director_reviewer_name' => (string) ($director_review['reviewer_name'] ?? ''),
                'latest_sender_comment' => $comment_display_text,
                'latest_sender_comment_label' => $comment_display_label,
                'latest_sender_name' => (string) ($latest_sender_comment['sender_name'] ?? ''),
                'latest_sender_position_name' => (string) ($latest_sender_comment['sender_position_name'] ?? ''),
                'status_key' => $status_key,
                'status_label' => $status_label,
                'consider_class' => $consider_class,
            ];
        }

        $page_box_label = match ($box_key) {
            'director' => 'กล่องรอพิจารณา',
            'clerk' => 'กล่องกำลังเสนอ',
            default => $archived ? 'หนังสือเวียนที่จัดเก็บ' : 'กล่องหนังสือเวียน',
        };

        view_render('outgoing/notice', [
            'alert' => $alert,
            'items' => $display_items,
            'factions' => $factions,
            'roles' => $roles,
            'teachers' => $teachers,
            'box_key' => $box_key,
            'archived' => $archived,
            'dh_year_options' => $dh_year_options,
            'selected_dh_year' => $selected_dh_year,
            'filter_type' => $filter_type,
            'filter_sort' => $filter_sort,
            'filter_view' => $filter_view,
            'filter_search' => $filter_search,
            'is_outside_view' => $is_outside_view,
            'director_label' => $director_label,
            'is_registry' => $is_registry,
            'can_manage_external' => $can_manage_external,
            'is_director_box' => $is_director_box,
            'is_acting_director' => $is_acting_director,
            'is_deputy_reviewer' => $is_deputy_reviewer,
            'acting_pid' => $acting_pid,
            'deputy_position_ids' => $deputy_position_ids,
            'deputy_forward_pids' => $deputy_forward_pids,
            'forward_open_inbox_id' => $forward_open_inbox_id,
            'show_type_filter' => false,
            'show_book_type_column' => false,
            'detail_workflow_page' => 'outgoing-view.php',
            'page_section_label' => 'หนังสือเวียนภายนอก',
            'page_box_label' => $page_box_label,
        ]);
    }
}
