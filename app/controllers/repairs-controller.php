<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/repairs/repository.php';
require_once __DIR__ . '/../modules/repairs/service.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../services/uploads.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../modules/audit/logger.php';
require_once __DIR__ . '/../config/state.php';

if (!function_exists('repairs_mode_config')) {
    function repairs_mode_config(string $mode): array {
        $configs = [
            'report' => [
                'base_url' => 'repairs.php',
                'title' => 'ยินดีต้อนรับ',
                'subtitle' => 'แจ้งเหตุซ่อมแซม',
                'form_title' => 'แจ้งเหตุซ่อมแซม',
                'form_subtitle' => 'กรอกข้อมูลให้ครบถ้วนเพื่อส่งคำขอซ่อม',
                'list_title' => 'รายการแจ้งซ่อมของฉัน',
                'list_subtitle' => 'ติดตามสถานะคำขอซ่อมที่คุณแจ้งไว้',
                'empty_title' => 'ยังไม่มีรายการแจ้งซ่อม',
                'empty_message' => 'เมื่อมีการแจ้งซ่อม รายการจะแสดงที่หน้านี้',
                'show_form' => true,
                'show_requester_column' => false,
                'statuses' => [],
            ],
            'approval' => [
                'base_url' => 'repairs-approval.php',
                'title' => 'ยินดีต้อนรับ',
                'subtitle' => 'แจ้งเหตุซ่อมแซม / อนุมัติการซ่อมแซม',
                'form_title' => '',
                'form_subtitle' => '',
                'list_title' => 'รายการแจ้งเหตุซ่อมแซม',
                'list_subtitle' => '',
                'empty_title' => 'ยังไม่มีรายการรออนุมัติ',
                'empty_message' => 'เมื่อมีคำขอซ่อมใหม่ รายการจะปรากฏที่หน้านี้',
                'show_form' => false,
                'show_requester_column' => true,
                'statuses' => [REPAIR_STATUS_PENDING],
            ],
            'manage' => [
                'base_url' => 'repairs-management.php',
                'title' => 'ยินดีต้อนรับ',
                'subtitle' => 'แจ้งเหตุซ่อมแซม / จัดการงานซ่อม',
                'form_title' => '',
                'form_subtitle' => '',
                'list_title' => 'รายการงานซ่อมทั้งหมด',
                'list_subtitle' => 'ตรวจสอบสถานะและอัปเดตการดำเนินงานซ่อม',
                'empty_title' => 'ยังไม่มีรายการงานซ่อม',
                'empty_message' => 'เมื่อมีการแจ้งซ่อม รายการจะปรากฏที่หน้านี้',
                'show_form' => false,
                'show_requester_column' => true,
                'statuses' => [],
            ],
        ];

        return $configs[$mode] ?? $configs['report'];
    }
}

if (!function_exists('repair_can_transition')) {
    function repair_can_transition(string $from_status, string $to_status): bool
    {
        $machines = workflow_state_machine();
        $repair_machine = (array) ($machines['repairs'] ?? []);
        $allowed_targets = (array) ($repair_machine[$from_status] ?? []);

        return in_array($to_status, $allowed_targets, true);
    }
}

if (!function_exists('repairs_status_map')) {
    function repairs_status_map(): array
    {
        return [
            REPAIR_STATUS_PENDING => ['label' => 'ส่งคำร้องสำเร็จ', 'variant' => 'pending'],
            REPAIR_STATUS_IN_PROGRESS => ['label' => 'กำลังดำเนินการ', 'variant' => 'processing'],
            REPAIR_STATUS_COMPLETED => ['label' => 'ดำเนินการเสร็จสิ้น', 'variant' => 'approved'],
            REPAIR_STATUS_CANCELLED => ['label' => 'ยกเลิกคำร้อง', 'variant' => 'rejected'],
            REPAIR_STATUS_REJECTED => ['label' => 'ยกเลิกคำร้อง', 'variant' => 'rejected'],
        ];
    }
}

if (!function_exists('repairs_status_label')) {
    function repairs_status_label(string $status): string
    {
        $status_map = repairs_status_map();

        return (string) ($status_map[$status]['label'] ?? $status);
    }
}

if (!function_exists('repairs_track_status_filters')) {
    function repairs_track_status_filters(): array
    {
        return [
            'all' => 'ทั้งหมด',
            'pending' => repairs_status_label(REPAIR_STATUS_PENDING),
            'in_progress' => repairs_status_label(REPAIR_STATUS_IN_PROGRESS),
            'completed' => repairs_status_label(REPAIR_STATUS_COMPLETED),
            'cancelled' => repairs_status_label(REPAIR_STATUS_CANCELLED),
        ];
    }
}

if (!function_exists('repairs_default_filter_status')) {
    function repairs_default_filter_status(string $mode): string
    {
        return 'all';
    }
}

if (!function_exists('repairs_merge_uploaded_attachments')) {
    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $uploaded
     * @return array<int, array<string, mixed>>
     */
    function repairs_merge_uploaded_attachments(array $existing, array $uploaded, string $attached_by_pid): array
    {
        if ($uploaded === []) {
            return $existing;
        }

        $merged = [];
        $known_ids = [];

        foreach ($existing as $file) {
            $file_id = (int) ($file['fileID'] ?? 0);

            if ($file_id > 0) {
                $known_ids[$file_id] = true;
            }

            $merged[] = $file;
        }

        foreach ($uploaded as $file) {
            $file_id = (int) ($file['fileID'] ?? 0);

            if ($file_id > 0 && isset($known_ids[$file_id])) {
                continue;
            }

            $file['attachedByPID'] = $attached_by_pid;

            if (!isset($file['entityName']) || trim((string) $file['entityName']) === '') {
                $file['entityName'] = REPAIR_OFFICIAL_ATTACHMENT_ENTITY_NAME;
            }

            $merged[] = $file;

            if ($file_id > 0) {
                $known_ids[$file_id] = true;
            }
        }

        return $merged;
    }
}

if (!function_exists('repairs_resolve_filter_statuses')) {
    function repairs_resolve_filter_statuses(string $filter_status): array
    {
        switch ($filter_status) {
            case 'pending':
                return [REPAIR_STATUS_PENDING];
            case 'in_progress':
                return [REPAIR_STATUS_IN_PROGRESS];
            case 'completed':
                return [REPAIR_STATUS_COMPLETED];
            case 'cancelled':
                return [REPAIR_STATUS_CANCELLED, REPAIR_STATUS_REJECTED];
            default:
                return [];
        }
    }
}

if (!function_exists('repairs_controller_audit_payload')) {
    function repairs_controller_audit_payload(string $mode, array $payload = []): array
    {
        $view_id = (int) ($_GET['view_id'] ?? 0);
        $edit_id = (int) ($_GET['edit_id'] ?? 0);
        $page = (int) ($_GET['page'] ?? 1);
        $base_payload = [
            'mode' => $mode,
            'tab' => trim((string) ($_REQUEST['tab'] ?? '')) ?: null,
            'page' => $page > 0 ? $page : 1,
            'query' => trim((string) ($_GET['q'] ?? '')) ?: null,
            'statusFilter' => trim((string) ($_GET['status'] ?? '')) ?: null,
            'sort' => trim((string) ($_GET['sort'] ?? '')) ?: null,
            'viewID' => $view_id > 0 ? $view_id : null,
            'editID' => $edit_id > 0 ? $edit_id : null,
            'requestedAction' => trim((string) ($_POST['action'] ?? '')) ?: null,
        ];

        return array_filter(array_merge($base_payload, $payload), static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [];
        });
    }
}

if (!function_exists('repairs_controller_audit_log')) {
    function repairs_controller_audit_log(
        string $mode,
        string $action,
        string $status = 'SUCCESS',
        ?int $entity_id = null,
        ?string $message = null,
        array $payload = [],
        ?int $http_status = null,
        ?string $http_method = null
    ): void {
        if (!function_exists('audit_log')) {
            return;
        }

        audit_log(
            'repairs',
            $action,
            $status,
            REPAIR_ENTITY_NAME,
            $entity_id,
            $message,
            repairs_controller_audit_payload($mode, $payload),
            $http_method,
            $http_status
        );
    }
}

if (!function_exists('repairs_transition_actions')) {
    function repairs_transition_actions(string $mode, ?array $repair): array
    {
        if (!$repair) {
            return [];
        }

        $current_status = (string) ($repair['status'] ?? '');

        if ($mode === 'approval') {
            if ($current_status !== REPAIR_STATUS_PENDING) {
                return [];
            }

            return [
                [
                    'target_status' => REPAIR_STATUS_IN_PROGRESS,
                    'label' => 'กำลังดำเนินการ',
                    'variant' => 'primary',
                    'confirm' => 'ยืนยันการเปลี่ยนสถานะเป็นกำลังดำเนินการใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการรับคำร้อง',
                ],
                [
                    'target_status' => REPAIR_STATUS_COMPLETED,
                    'label' => 'ดำเนินการเสร็จสิ้น',
                    'variant' => 'primary',
                    'confirm' => 'ยืนยันการเปลี่ยนสถานะเป็นดำเนินการเสร็จสิ้นใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการปิดงาน',
                ],
                [
                    'target_status' => REPAIR_STATUS_CANCELLED,
                    'label' => 'ยกเลิกคำร้อง',
                    'variant' => 'danger',
                    'confirm' => 'ยืนยันการยกเลิกคำร้องนี้ใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการยกเลิกคำร้อง',
                ],
            ];
        }

        if ($mode === 'manage') {
            $actions = [];

            if ($current_status === REPAIR_STATUS_PENDING) {
                $actions[] = [
                    'target_status' => REPAIR_STATUS_IN_PROGRESS,
                    'label' => 'กำลังดำเนินการ',
                    'variant' => 'primary',
                    'confirm' => 'ยืนยันการเปลี่ยนสถานะเป็นกำลังดำเนินการใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการรับงาน',
                ];
                $actions[] = [
                    'target_status' => REPAIR_STATUS_COMPLETED,
                    'label' => 'ดำเนินการเสร็จสิ้น',
                    'variant' => 'primary',
                    'confirm' => 'ยืนยันการเปลี่ยนสถานะเป็นดำเนินการเสร็จสิ้นใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการปิดงาน',
                ];
                $actions[] = [
                    'target_status' => REPAIR_STATUS_CANCELLED,
                    'label' => 'ยกเลิกคำร้อง',
                    'variant' => 'secondary',
                    'confirm' => 'ยืนยันการยกเลิกคำร้องนี้ใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการยกเลิกคำร้อง',
                ];
            } elseif ($current_status === REPAIR_STATUS_IN_PROGRESS) {
                $actions[] = [
                    'target_status' => REPAIR_STATUS_COMPLETED,
                    'label' => 'ดำเนินการเสร็จสิ้น',
                    'variant' => 'primary',
                    'confirm' => 'ยืนยันการเปลี่ยนสถานะเป็นดำเนินการเสร็จสิ้นใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการปิดงาน',
                ];
                $actions[] = [
                    'target_status' => REPAIR_STATUS_CANCELLED,
                    'label' => 'ยกเลิกคำร้อง',
                    'variant' => 'secondary',
                    'confirm' => 'ยืนยันการยกเลิกคำร้องนี้ใช่หรือไม่?',
                    'confirm_title' => 'ยืนยันการยกเลิกคำร้อง',
                ];
            }

            return $actions;
        }

        return [];
    }
}

if (!function_exists('repairs_render_forbidden')) {
    function repairs_render_forbidden(): void
    {
        view_render('errors/403');
    }
}

if (!function_exists('repairs_index')) {
    function repairs_index(): void
    {
        repairs_handle_mode('report');
    }
}

if (!function_exists('repairs_approval_index')) {
    function repairs_approval_index(): void
    {
        repairs_handle_mode('approval');
    }
}

if (!function_exists('repairs_management_index')) {
    function repairs_management_index(): void
    {
        repairs_handle_mode('manage');
    }
}

if (!function_exists('repairs_handle_mode')) {
    function repairs_handle_mode(string $mode): void
    {
        $mode = in_array($mode, ['report', 'approval', 'manage'], true) ? $mode : 'report';
        $config = repairs_mode_config($mode);
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');
        $current_role_ids = rbac_parse_role_ids($current_user['roleID'] ?? '');
        $connection = db_connection();
        $has_table = db_table_exists($connection, 'dh_repair_requests');
        $has_equipment_column = $has_table && db_column_exists($connection, 'dh_repair_requests', 'equipment');
        $repair_staff_role_id = repair_staff_role_id();

        $is_admin = rbac_user_has_role($connection, $current_pid, ROLE_ADMIN)
            || in_array(1, $current_role_ids, true);
        $is_facility = $is_admin
            || rbac_user_has_role($connection, $current_pid, ROLE_FACILITY)
            || in_array(5, $current_role_ids, true)
            || rbac_user_has_role($connection, $current_pid, ROLE_REPAIR)
            || in_array($repair_staff_role_id, $current_role_ids, true);

        $audit_request_payload = static function (array $payload = []) use ($mode, $current_pid, $is_admin, $is_facility): array {
            return repairs_controller_audit_payload($mode, array_merge([
                'actorPID' => $current_pid !== '' ? $current_pid : null,
                'isAdmin' => $is_admin,
                'isFacility' => $is_facility,
            ], $payload));
        };

        if (($mode === 'approval' && !$is_facility) || ($mode === 'manage' && !$is_admin)) {
            repairs_controller_audit_log($mode, 'ACCESS', 'DENY', null, 'repairs_access_denied', [
                'requiredRole' => $mode === 'approval' ? ROLE_FACILITY : ROLE_ADMIN,
            ], 403);
            repairs_render_forbidden();

            return;
        }

        $alert = flash_get('repairs_alert');
        $values = repair_form_defaults();
        $view_id = (int) ($_GET['view_id'] ?? 0);
        $edit_id = $mode === 'report' ? (int) ($_GET['edit_id'] ?? 0) : 0;
        $requested_tab = trim((string) ($_REQUEST['tab'] ?? ''));
        $is_track_active = $requested_tab === 'track' && in_array($mode, ['report', 'approval'], true);

        if ($mode === 'report' && ($view_id > 0 || $edit_id > 0)) {
            $is_track_active = true;
        }

        $filter_query = trim((string) ($_REQUEST['q'] ?? ''));
        $filter_status = strtolower(trim((string) ($_REQUEST['status'] ?? 'all')));
        $filter_sort = strtolower(trim((string) ($_REQUEST['sort'] ?? 'newest')));
        $status_map = repairs_status_map();
        $status_filter_options = repairs_track_status_filters();
        $view_item = null;
        $view_attachments = [];
        $view_transition_note = '';
        $edit_item = null;
        $edit_attachments = [];
        $default_filter_status = repairs_default_filter_status($mode);

        if (!isset($status_filter_options[$filter_status])) {
            $filter_status = $default_filter_status;
        }

        if (!in_array($filter_sort, ['newest', 'oldest'], true)) {
            $filter_sort = 'newest';
        }

        if ($edit_id > 0) {
            $view_id = 0;
        }

        $can_access_repair = static function (?array $repair) use ($mode, $current_pid, $is_facility, $is_admin): bool {
            if (!$repair) {
                return false;
            }

            if ($mode === 'manage') {
                return $is_admin;
            }

            if ($mode === 'approval') {
                return $is_facility;
            }

            return (string) ($repair['requesterPID'] ?? '') === $current_pid;
        };

        if ($view_id > 0 && $has_table) {
            $view_item = repair_get($view_id);

            if (!$view_item) {
                repairs_controller_audit_log($mode, 'DETAIL_VIEW', 'FAIL', $view_id, 'not_found', [], null, 'GET');
            } elseif (!$can_access_repair($view_item)) {
                repairs_controller_audit_log($mode, 'DETAIL_VIEW', 'DENY', $view_id, 'not_authorized', [], null, 'GET');
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่มีสิทธิ์เข้าถึง',
                    'message' => 'คุณไม่มีสิทธิ์ดูรายการนี้',
                ];
                $view_item = null;
            } else {
                $view_attachments = repair_get_attachments($view_id);
                repairs_controller_audit_log($mode, 'DETAIL_VIEW', 'SUCCESS', $view_id, null, [
                    'status' => (string) ($view_item['status'] ?? ''),
                    'attachmentCount' => count($view_attachments),
                ], 200, 'GET');
            }
        }

        if ($edit_id > 0 && $has_table) {
            $edit_item = repair_get($edit_id);

            if (!$edit_item) {
                repairs_controller_audit_log($mode, 'EDIT_VIEW', 'FAIL', $edit_id, 'not_found', [], null, 'GET');
            } elseif (!$can_access_repair($edit_item)) {
                repairs_controller_audit_log($mode, 'EDIT_VIEW', 'DENY', $edit_id, 'not_authorized', [], null, 'GET');
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่มีสิทธิ์แก้ไข',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขรายการนี้',
                ];
                $edit_item = null;
            } elseif ((string) ($edit_item['status'] ?? '') !== REPAIR_STATUS_PENDING) {
                repairs_controller_audit_log($mode, 'EDIT_VIEW', 'FAIL', $edit_id, 'invalid_status_for_edit', [
                    'status' => (string) ($edit_item['status'] ?? ''),
                    ], null, 'GET');
                $alert = [
                    'type' => 'warning',
                    'title' => 'ไม่สามารถแก้ไขได้',
                    'message' => 'แก้ไขได้เฉพาะรายการที่มีสถานะ ' . repairs_status_label(REPAIR_STATUS_PENDING) . ' เท่านั้น',
                ];
                $edit_item = null;
            } else {
                $values = [
                    'subject' => (string) ($edit_item['subject'] ?? ''),
                    'location' => (string) ($edit_item['location'] ?? ''),
                    'equipment' => (string) ($edit_item['equipment'] ?? ''),
                    'detail' => (string) ($edit_item['detail'] ?? ''),
                ];
                $edit_attachments = repair_get_attachments($edit_id);
                repairs_controller_audit_log($mode, 'EDIT_VIEW', 'SUCCESS', $edit_id, null, [
                    'status' => (string) ($edit_item['status'] ?? ''),
                    'attachmentCount' => count($edit_attachments),
                ], 200, 'GET');
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string) ($_POST['action'] ?? 'create');
            $member_action = trim((string) ($_POST['member_action'] ?? ''));
            $member_pid = trim((string) ($_POST['member_pid'] ?? ''));
            $repair_id = (int) ($_POST['repair_id'] ?? 0);
            $target_status = trim((string) ($_POST['target_status'] ?? ''));
            $transition_note = trim((string) ($_POST['transition_note'] ?? ''));
            $has_transition_attachments = !empty($_FILES['attachments']) && repair_has_uploads((array) $_FILES['attachments']);
            $values = repair_normalize_form_data($_POST);
            $post_audit_payload = [
                'repairID' => $repair_id > 0 ? $repair_id : null,
                'memberAction' => $member_action !== '' ? $member_action : null,
                'memberPID' => $member_pid !== '' ? $member_pid : null,
                'targetStatus' => $target_status !== '' ? $target_status : null,
                'transitionNote' => $transition_note !== '' ? $transition_note : null,
                'transitionNoteLength' => $transition_note !== ''
                    ? (function_exists('mb_strlen') ? mb_strlen($transition_note, 'UTF-8') : strlen($transition_note))
                    : 0,
                'subject' => $values['subject'] !== '' ? $values['subject'] : null,
                'location' => $values['location'] !== '' ? $values['location'] : null,
                'equipment' => $values['equipment'] !== '' ? $values['equipment'] : null,
                'detailLength' => function_exists('mb_strlen')
                    ? mb_strlen((string) ($values['detail'] ?? ''), 'UTF-8')
                    : strlen((string) ($values['detail'] ?? '')),
                'hasAttachments' => $has_transition_attachments,
                'attachmentCount' => !empty($_FILES['attachments']) ? repair_count_uploads((array) $_FILES['attachments']) : 0,
            ];

            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ];
                audit_log('security', 'CSRF_FAIL', 'DENY', REPAIR_ENTITY_NAME, $repair_id > 0 ? $repair_id : null, 'repairs_controller', $audit_request_payload($post_audit_payload));
            } elseif ($mode === 'manage' && $member_action !== '') {
                $staff_role_id = repair_staff_role_id();

                if ($member_pid === '' || !preg_match('/^[A-Za-z0-9]{1,64}$/', $member_pid)) {
                    audit_log('repairs', 'REPAIR_STAFF_MANAGE', 'FAIL', 'teacher', null, 'invalid_member_pid', array_merge($post_audit_payload, [
                        'staffRoleID' => $staff_role_id,
                    ]));
                    flash_set('repairs_alert', [
                        'type' => 'danger',
                        'title' => 'ข้อมูลไม่ถูกต้อง',
                        'message' => 'ไม่พบรหัสบุคลากรที่ต้องการ',
                    ]);
                } else {
                    try {
                        if ($member_action === 'add') {
                            $updated = repair_assign_staff_role($member_pid);
                            audit_log('repairs', 'ASSIGN_REPAIR_STAFF', $updated ? 'SUCCESS' : 'FAIL', 'teacher', null, $updated ? null : 'no_rows_affected', array_merge($post_audit_payload, [
                                'staffRoleID' => $staff_role_id,
                                'actorPID' => $current_pid !== '' ? $current_pid : null,
                            ]));
                            flash_set('repairs_alert', $updated ? [
                                'type' => 'success',
                                'title' => 'เพิ่มสมาชิกสำเร็จ',
                                'message' => 'อัปเดตสิทธิ์เป็นเจ้าหน้าที่ซ่อมแซมแล้ว',
                            ] : [
                                'type' => 'warning',
                                'title' => 'ไม่สามารถเพิ่มสมาชิก',
                                'message' => 'บุคลากรนี้อาจถูกเพิ่มแล้วหรือไม่อยู่ในระบบ',
                            ]);
                        } elseif ($member_action === 'remove') {
                            $updated = repair_remove_staff_role($member_pid);
                            audit_log('repairs', 'REMOVE_REPAIR_STAFF', $updated ? 'SUCCESS' : 'FAIL', 'teacher', null, $updated ? null : 'no_rows_affected', array_merge($post_audit_payload, [
                                'staffRoleID' => $staff_role_id,
                                'fallbackRoleID' => REPAIR_STAFF_FALLBACK_ROLE_ID,
                                'actorPID' => $current_pid !== '' ? $current_pid : null,
                            ]));
                            flash_set('repairs_alert', $updated ? [
                                'type' => 'success',
                                'title' => 'ลบสมาชิกสำเร็จ',
                                'message' => 'นำสิทธิ์เจ้าหน้าที่ซ่อมแซมออกแล้ว',
                            ] : [
                                'type' => 'warning',
                                'title' => 'ไม่สามารถลบสมาชิก',
                                'message' => 'ไม่พบบุคลากรในสิทธิ์เจ้าหน้าที่ซ่อมแซม',
                            ]);
                        } else {
                            audit_log('repairs', 'REPAIR_STAFF_MANAGE', 'FAIL', 'teacher', null, 'invalid_member_action', array_merge($post_audit_payload, [
                                'staffRoleID' => $staff_role_id,
                            ]));
                            flash_set('repairs_alert', [
                                'type' => 'danger',
                                'title' => 'ข้อมูลไม่ถูกต้อง',
                                'message' => 'ไม่พบคำสั่งที่ต้องการ',
                            ]);
                        }
                    } catch (Throwable $exception) {
                        audit_log('repairs', 'REPAIR_STAFF_MANAGE', 'FAIL', 'teacher', null, $exception->getMessage(), array_merge($post_audit_payload, [
                            'staffRoleID' => $staff_role_id,
                            'actorPID' => $current_pid !== '' ? $current_pid : null,
                        ]));
                        flash_set('repairs_alert', [
                            'type' => 'danger',
                            'title' => 'ระบบขัดข้อง',
                            'message' => 'ไม่สามารถจัดการทีมเจ้าหน้าที่ซ่อมแซมได้ในขณะนี้',
                        ]);
                    }
                }

                header('Location: ' . $config['base_url'], true, 303);
                exit;
            } elseif (!$has_table || !$has_equipment_column) {
                repairs_controller_audit_log($mode, strtoupper($action !== '' ? $action : 'ACTION'), 'FAIL', $repair_id > 0 ? $repair_id : null, 'schema_not_ready', $post_audit_payload);
                $alert = system_not_ready_alert('ยังไม่พบโครงสร้าง repairs ล่าสุด กรุณารัน migrations/019_add_repair_equipment_column.sql');
            } elseif ($action === 'delete') {
                $target = $repair_id > 0 ? repair_get($repair_id) : null;

                if (!$target) {
                    repairs_controller_audit_log($mode, 'DELETE', 'FAIL', $repair_id > 0 ? $repair_id : null, 'not_found', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ไม่พบรายการ',
                        'message' => 'ไม่พบคำร้องที่ต้องการลบ',
                    ];
                } elseif (!$can_access_repair($target)) {
                    repairs_controller_audit_log($mode, 'DELETE', 'DENY', $repair_id, 'not_authorized', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ไม่มีสิทธิ์ลบคำร้อง',
                        'message' => 'คุณไม่มีสิทธิ์ลบคำร้องนี้',
                    ];
                } elseif ((string) ($target['status'] ?? '') !== REPAIR_STATUS_PENDING) {
                    repairs_controller_audit_log($mode, 'DELETE', 'FAIL', $repair_id, 'invalid_status_for_delete', array_merge($post_audit_payload, [
                        'status' => (string) ($target['status'] ?? ''),
                        'deleteStrategy' => 'soft_delete',
                    ]));
                    $alert = [
                        'type' => 'warning',
                        'title' => 'ไม่สามารถลบคำร้องได้',
                        'message' => 'ลบคำร้องได้เฉพาะรายการที่มีสถานะ ' . repairs_status_label(REPAIR_STATUS_PENDING) . ' เท่านั้น',
                    ];
                } else {
                    repair_soft_delete_record($repair_id);
                    repairs_controller_audit_log($mode, 'DELETE', 'SUCCESS', $repair_id, null, array_merge($post_audit_payload, [
                        'status' => (string) ($target['status'] ?? ''),
                        'deleteStrategy' => 'soft_delete',
                    ]));
                    $alert = [
                        'type' => 'success',
                        'title' => 'ลบคำร้องสำเร็จ',
                        'message' => '',
                    ];
                    $view_id = 0;
                    $edit_id = 0;
                    $view_item = null;
                    $edit_item = null;
                }
            } elseif ($action === 'update') {
                $target = $repair_id > 0 ? repair_get($repair_id) : null;

                if (!$target) {
                    repairs_controller_audit_log($mode, 'UPDATE', 'FAIL', $repair_id > 0 ? $repair_id : null, 'not_found', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ไม่พบรายการ',
                        'message' => 'ไม่พบคำร้องที่ต้องการแก้ไข',
                    ];
                } elseif (!$can_access_repair($target)) {
                    repairs_controller_audit_log($mode, 'UPDATE', 'DENY', $repair_id, 'not_authorized', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ไม่มีสิทธิ์แก้ไข',
                        'message' => 'คุณไม่มีสิทธิ์แก้ไขรายการนี้',
                    ];
                } elseif ((string) ($target['status'] ?? '') !== REPAIR_STATUS_PENDING) {
                    repairs_controller_audit_log($mode, 'UPDATE', 'FAIL', $repair_id, 'invalid_status_for_update', array_merge($post_audit_payload, [
                        'status' => (string) ($target['status'] ?? ''),
                    ]));
                    $alert = [
                        'type' => 'warning',
                        'title' => 'ไม่สามารถแก้ไขได้',
                        'message' => 'แก้ไขได้เฉพาะรายการที่มีสถานะ ' . repairs_status_label(REPAIR_STATUS_PENDING) . ' เท่านั้น',
                    ];
                } elseif ($values['subject'] === '') {
                    repairs_controller_audit_log($mode, 'UPDATE', 'FAIL', $repair_id, 'missing_subject', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'กรุณากรอกหัวข้อ',
                        'message' => '',
                    ];
                } else {
                    repair_update_record($repair_id, [
                        'subject' => $values['subject'],
                        'detail' => $values['detail'],
                        'location' => $values['location'],
                        'equipment' => $values['equipment'],
                    ]);
                    repairs_controller_audit_log($mode, 'UPDATE', 'SUCCESS', $repair_id, null, array_merge($post_audit_payload, [
                        'status' => (string) ($target['status'] ?? ''),
                    ]));

                    try {
                        if (!empty($_FILES['attachments']) && repair_has_uploads((array) $_FILES['attachments'])) {
                            upload_store_files($_FILES['attachments'], REPAIR_MODULE_NAME, REPAIR_ENTITY_NAME, (string) $repair_id, $current_pid, [
                                'max_files' => 0,
                                'allowed_mimes' => upload_allowed_mimes(),
                            ]);
                            repairs_controller_audit_log($mode, 'ATTACH', 'SUCCESS', $repair_id, null, $post_audit_payload);
                        }
                    } catch (RuntimeException $exception) {
                        repairs_controller_audit_log($mode, 'ATTACH', 'FAIL', $repair_id, $exception->getMessage(), $post_audit_payload);
                        $alert = [
                            'type' => 'danger',
                            'title' => 'แนบไฟล์ไม่สำเร็จ',
                            'message' => $exception->getMessage(),
                        ];
                    }

                    if ($alert === null || $alert['type'] === 'success') {
                        $alert = [
                            'type' => 'success',
                            'title' => 'แก้ไขรายการแล้ว',
                            'message' => '',
                        ];
                        $edit_id = 0;
                        $edit_item = null;
                    }
                }
            } elseif ($action === 'transition') {
                $target = $repair_id > 0 ? repair_get($repair_id) : null;
                $current_status = (string) ($target['status'] ?? '');
                $is_same_status_note_update = $target_status !== ''
                    && $current_status !== ''
                    && $current_status !== REPAIR_STATUS_PENDING
                    && $target_status === $current_status
                    && ($transition_note !== '' || $has_transition_attachments);

                if (!$target) {
                    repairs_controller_audit_log($mode, 'TRANSITION', 'FAIL', $repair_id > 0 ? $repair_id : null, 'not_found', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ไม่พบรายการ',
                        'message' => 'ไม่พบคำร้องที่ต้องการเปลี่ยนสถานะ',
                    ];
                } elseif (!$can_access_repair($target)) {
                    repairs_controller_audit_log($mode, 'TRANSITION', 'DENY', $repair_id, 'not_authorized', $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ไม่มีสิทธิ์ดำเนินการ',
                        'message' => 'คุณไม่มีสิทธิ์เปลี่ยนสถานะรายการนี้',
                    ];
                } elseif ($target_status === '') {
                    repairs_controller_audit_log($mode, 'TRANSITION', 'FAIL', $repair_id, 'missing_target_status', array_merge($post_audit_payload, [
                        'currentStatus' => $current_status !== '' ? $current_status : null,
                    ]));
                    $alert = [
                        'type' => 'warning',
                        'title' => 'กรุณาเลือกสถานะการดำเนินงาน',
                        'message' => '',
                    ];
                } elseif (!$is_same_status_note_update && !repair_can_transition($current_status, $target_status)) {
                    repairs_controller_audit_log($mode, 'TRANSITION', 'FAIL', $repair_id, 'invalid_transition', array_merge($post_audit_payload, [
                        'currentStatus' => $current_status !== '' ? $current_status : null,
                    ]));
                    $alert = [
                        'type' => 'warning',
                        'title' => 'ไม่สามารถเปลี่ยนสถานะได้',
                        'message' => 'สถานะที่เลือกไม่ถูกต้องสำหรับรายการนี้',
                    ];
                } elseif ($mode === 'approval' && !$is_same_status_note_update && !in_array($target_status, [REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_COMPLETED, REPAIR_STATUS_CANCELLED], true)) {
                    repairs_controller_audit_log($mode, 'TRANSITION', 'FAIL', $repair_id, 'invalid_approval_transition', array_merge($post_audit_payload, [
                        'currentStatus' => $current_status !== '' ? $current_status : null,
                    ]));
                    $alert = [
                        'type' => 'warning',
                        'title' => 'ไม่สามารถดำเนินการได้',
                        'message' => 'หน้าเจ้าหน้าที่รองรับเฉพาะการเปลี่ยนเป็นกำลังดำเนินการ ดำเนินการเสร็จสิ้น หรือยกเลิกคำร้องเท่านั้น',
                    ];
                } elseif ($is_same_status_note_update) {
                    $attachment_upload_error = null;
                    $uploaded_attachments = [];

                    repair_update_record($repair_id, [
                        'updatedAt' => date('Y-m-d H:i:s'),
                    ]);
                    repairs_controller_audit_log($mode, 'NOTE_UPDATE', 'SUCCESS', $repair_id, $current_status, array_merge($post_audit_payload, [
                        'status' => $current_status,
                    ]));
                    repair_log_timeline_event($repair_id, $current_pid, 'NOTE_UPDATE', $current_status, $current_status, [
                        'mode' => $mode,
                        'assignedToPID' => (string) ($target['assignedToPID'] ?? '') ?: null,
                        'note' => $transition_note,
                    ]);

                    if ($has_transition_attachments) {
                        try {
                            $uploaded_attachments = upload_store_files($_FILES['attachments'], REPAIR_MODULE_NAME, REPAIR_OFFICIAL_ATTACHMENT_ENTITY_NAME, (string) $repair_id, $current_pid, [
                                'max_files' => 5,
                                'allowed_mimes' => upload_allowed_mimes(),
                            ]);
                            repairs_controller_audit_log($mode, 'ATTACH', 'SUCCESS', $repair_id, null, $post_audit_payload);
                        } catch (RuntimeException $exception) {
                            $attachment_upload_error = $exception->getMessage();
                            repairs_controller_audit_log($mode, 'ATTACH', 'FAIL', $repair_id, $attachment_upload_error, $post_audit_payload);
                        }
                    }

                    $alert = $attachment_upload_error === null ? [
                        'type' => 'success',
                        'title' => 'บันทึกรายละเอียดแล้ว',
                        'message' => '',
                    ] : [
                        'type' => 'warning',
                        'title' => 'บันทึกรายละเอียดแล้ว',
                        'message' => 'แต่แนบไฟล์ไม่สำเร็จ: ' . $attachment_upload_error,
                    ];

                    if ($mode === 'approval') {
                        $view_id = $repair_id;
                        $view_item = repair_get($repair_id);
                        $view_attachments = repair_get_attachments($repair_id);
                        $view_attachments = repairs_merge_uploaded_attachments($view_attachments, $uploaded_attachments, $current_pid);
                        $latest_notes = repair_get_latest_timeline_notes_map([$repair_id]);
                        $view_transition_note = (string) ($latest_notes[$repair_id] ?? $transition_note);
                    } else {
                        $view_id = $repair_id;
                        $view_item = repair_get($repair_id);
                        $view_attachments = repair_get_attachments($repair_id);
                        $view_attachments = repairs_merge_uploaded_attachments($view_attachments, $uploaded_attachments, $current_pid);
                    }
                } else {
                    $attachment_upload_error = null;
                    $uploaded_attachments = [];
                    $update_data = [
                        'status' => $target_status,
                    ];

                    if ($target_status === REPAIR_STATUS_IN_PROGRESS) {
                        $update_data['assignedToPID'] = $current_pid;
                        $update_data['resolvedAt'] = null;
                    } elseif (in_array($target_status, [REPAIR_STATUS_COMPLETED, REPAIR_STATUS_REJECTED, REPAIR_STATUS_CANCELLED], true)) {
                        $update_data['assignedToPID'] = (string) ($target['assignedToPID'] ?? '') !== '' ? (string) $target['assignedToPID'] : $current_pid;
                        $update_data['resolvedAt'] = date('Y-m-d H:i:s');
                    }

                    repair_update_record($repair_id, $update_data);
                    repairs_controller_audit_log($mode, 'TRANSITION', 'SUCCESS', $repair_id, $target_status, array_merge($post_audit_payload, [
                        'fromStatus' => $current_status !== '' ? $current_status : null,
                        'toStatus' => $target_status,
                    ]));
                    repair_log_timeline_event($repair_id, $current_pid, 'TRANSITION', $current_status, $target_status, [
                        'mode' => $mode,
                        'assignedToPID' => (string) ($update_data['assignedToPID'] ?? ($target['assignedToPID'] ?? '')) ?: null,
                        'resolvedAt' => (string) ($update_data['resolvedAt'] ?? '') ?: null,
                        'note' => $transition_note !== '' ? $transition_note : null,
                    ]);

                    if ($has_transition_attachments) {
                        try {
                            $uploaded_attachments = upload_store_files($_FILES['attachments'], REPAIR_MODULE_NAME, REPAIR_OFFICIAL_ATTACHMENT_ENTITY_NAME, (string) $repair_id, $current_pid, [
                                'max_files' => 5,
                                'allowed_mimes' => upload_allowed_mimes(),
                            ]);
                            repairs_controller_audit_log($mode, 'ATTACH', 'SUCCESS', $repair_id, null, $post_audit_payload);
                        } catch (RuntimeException $exception) {
                            $attachment_upload_error = $exception->getMessage();
                            repairs_controller_audit_log($mode, 'ATTACH', 'FAIL', $repair_id, $attachment_upload_error, $post_audit_payload);
                        }
                    }

                    $alert = $attachment_upload_error === null ? [
                        'type' => 'success',
                        'title' => 'อัปเดตสถานะแล้ว',
                        'message' => '',
                    ] : [
                        'type' => 'warning',
                        'title' => 'อัปเดตสถานะแล้ว',
                        'message' => 'แต่แนบไฟล์ไม่สำเร็จ: ' . $attachment_upload_error,
                    ];

                    if ($mode === 'approval') {
                        $view_id = $repair_id;
                        $view_item = repair_get($repair_id);
                        $view_attachments = repair_get_attachments($repair_id);
                        $view_attachments = repairs_merge_uploaded_attachments($view_attachments, $uploaded_attachments, $current_pid);
                        $latest_notes = repair_get_latest_timeline_notes_map([$repair_id]);
                        $view_transition_note = (string) ($latest_notes[$repair_id] ?? $transition_note);
                    } else {
                        $view_id = $repair_id;
                        $view_item = repair_get($repair_id);
                        $view_attachments = repair_get_attachments($repair_id);
                        $view_attachments = repairs_merge_uploaded_attachments($view_attachments, $uploaded_attachments, $current_pid);
                    }
                }
            } elseif ($mode !== 'report') {
                repairs_controller_audit_log($mode, 'ACTION', 'FAIL', $repair_id > 0 ? $repair_id : null, 'invalid_action_for_mode', $post_audit_payload);
                $alert = [
                    'type' => 'warning',
                    'title' => 'ไม่สามารถทำรายการได้',
                    'message' => 'หน้านี้รองรับเฉพาะการดำเนินการตาม workflow',
                ];
            } else {
                try {
                    repair_create_request($_POST, $_FILES['attachments'] ?? [], $current_pid);
                    flash_set('repairs_alert', [
                        'type' => 'success',
                        'title' => repairs_status_label(REPAIR_STATUS_PENDING),
                        'message' => '',
                    ]);
                    header('Location: ' . $config['base_url']);
                    exit;
                } catch (Throwable $exception) {
                    $alert = [
                        'type' => 'danger',
                        'title' => $exception->getMessage(),
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        if ((!$has_table || !$has_equipment_column) && $alert === null) {
            $alert = system_not_ready_alert('ยังไม่พบโครงสร้าง repairs ล่าสุด กรุณารัน migrations/019_add_repair_equipment_column.sql');
        }

        $page = (int) ($_REQUEST['page'] ?? 1);
        $page = $page > 0 ? $page : 1;
        $per_page = 10;
        $total_pages = 1;
        $total_count = 0;
        $statuses = (array) ($config['statuses'] ?? []);

        if (!$has_table || !$has_equipment_column) {
            $requests = [];
        } elseif ($mode === 'report') {
            $filter_statuses = repairs_resolve_filter_statuses($filter_status);
            $total_count = repair_count_filtered($current_pid, $filter_statuses, $filter_query, true);
            $total_pages = max(1, (int) ceil($total_count / $per_page));
            $page = min($page, $total_pages);
            $offset = ($page - 1) * $per_page;
            $requests = repair_list_filtered_page($current_pid, $filter_statuses, $per_page, $offset, $filter_query, $filter_sort, true);
        } elseif ($mode === 'approval') {
            $filter_statuses = repairs_resolve_filter_statuses($filter_status);
            $total_count = repair_count_filtered(null, $filter_statuses, $filter_query);
            $total_pages = max(1, (int) ceil($total_count / $per_page));
            $page = min($page, $total_pages);
            $offset = ($page - 1) * $per_page;
            $requests = repair_list_filtered_page(null, $filter_statuses, $per_page, $offset, $filter_query, $filter_sort);
        } else {
            $total_count = repair_count_filtered(null, $statuses);
            $total_pages = max(1, (int) ceil($total_count / $per_page));
            $page = min($page, $total_pages);
            $offset = ($page - 1) * $per_page;
            $requests = repair_list_filtered_page(null, $statuses, $per_page, $offset);
        }

        $request_attachments_map = [];
        $request_timeline_map = [];
        $request_timeline_note_map = [];
        $all_repair_requests = [];
        $all_request_attachments_map = [];
        $all_request_timeline_map = [];
        $repair_staff_members = [];
        $repair_candidate_members = [];
        $repair_staff_count = 0;
        $repair_candidate_count = 0;

        if (!empty($requests)) {
            $request_ids = array_column($requests, 'repairID');
            $request_attachments_map = repair_get_attachments_map($request_ids);
            $request_timeline_map = repair_get_timeline_map($request_ids);
            $request_timeline_note_map = repair_get_latest_timeline_notes_map($request_ids);
        }

        if ($mode === 'approval' && $has_table && $has_equipment_column) {
            $filter_statuses = repairs_resolve_filter_statuses($filter_status);
            $all_repair_limit = max(1, $total_count);
            $all_repair_requests = repair_list_filtered_page(null, $filter_statuses, $all_repair_limit, 0, $filter_query, $filter_sort, false, true);

            if (!empty($all_repair_requests)) {
                $all_request_ids = array_column($all_repair_requests, 'repairID');
                $all_request_attachments_map = repair_get_attachments_map($all_request_ids);
                $all_request_timeline_map = repair_get_timeline_map($all_request_ids);
            }
        }

        if ($mode === 'manage') {
            try {
                $repair_staff_members = repair_staff_members();
                $repair_candidate_members = repair_staff_candidates();
                $repair_staff_count = count($repair_staff_members);
                $repair_candidate_count = count($repair_candidate_members);
            } catch (Throwable $exception) {
                error_log('Repair staff data error: ' . $exception->getMessage());

                if ($alert === null) {
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ระบบขัดข้อง',
                        'message' => 'ไม่สามารถโหลดข้อมูลเจ้าหน้าที่ซ่อมแซมได้ในขณะนี้',
                    ];
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $is_filtered_request = $filter_query !== ''
                || $filter_status !== $default_filter_status
                || $filter_sort !== 'newest'
                || $page > 1;
            repairs_controller_audit_log(
                $mode,
                $is_filtered_request ? 'SEARCH' : 'VIEW',
                'SUCCESS',
                null,
                null,
                [
                    'resultCount' => count($requests),
                    'totalCount' => $total_count,
                    'totalPages' => $total_pages,
                    'activeTab' => $is_track_active ? 'track' : 'form',
                ],
                200,
                'GET'
            );
        }

        $view_template = 'repairs/index';

        if ($mode === 'approval') {
            $view_template = 'repairs/approval';
        } elseif ($mode === 'manage') {
            $view_template = 'repairs/manage';
        }

        view_render($view_template, [
            'alert' => $alert,
            'values' => $values,
            'requests' => $requests,
            'request_attachments_map' => $request_attachments_map,
            'request_timeline_map' => $request_timeline_map,
            'request_timeline_note_map' => $request_timeline_note_map,
            'all_repair_requests' => $all_repair_requests,
            'all_request_attachments_map' => $all_request_attachments_map,
            'all_request_timeline_map' => $all_request_timeline_map,
            'repair_staff_members' => $repair_staff_members,
            'repair_candidate_members' => $repair_candidate_members,
            'repair_staff_count' => $repair_staff_count,
            'repair_candidate_count' => $repair_candidate_count,
            'current_pid' => $current_pid,
            'view_item' => $view_item,
            'view_attachments' => $view_attachments,
            'view_transition_note' => $view_transition_note,
            'edit_item' => $edit_item,
            'edit_attachments' => $edit_attachments,
            'page' => $page,
            'total_pages' => $total_pages,
            'total_count' => $total_count,
            'mode' => $mode,
            'base_url' => (string) $config['base_url'],
            'page_title' => (string) $config['title'],
            'page_subtitle' => (string) $config['subtitle'],
            'form_title' => (string) $config['form_title'],
            'form_subtitle' => (string) $config['form_subtitle'],
            'list_title' => (string) $config['list_title'],
            'list_subtitle' => (string) $config['list_subtitle'],
            'empty_title' => (string) $config['empty_title'],
            'empty_message' => (string) $config['empty_message'],
            'show_form' => (bool) $config['show_form'],
            'show_requester_column' => (bool) $config['show_requester_column'],
            'transition_actions' => repairs_transition_actions($mode, $view_item),
            'is_track_active' => $is_track_active,
            'filter_query' => $filter_query,
            'filter_status' => $filter_status,
            'filter_sort' => $filter_sort,
            'status_map' => $status_map,
            'status_filter_options' => $status_filter_options,
        ]);
    }
}
