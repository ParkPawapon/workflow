<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../modules/dashboard/metrics.php';
require_once __DIR__ . '/../modules/circulars/repository.php';

if (!function_exists('dashboard_resolve_access')) {
    function dashboard_resolve_access(array $current_user): array
    {
        $actor_pid = trim((string) ($current_user['pID'] ?? ($_SESSION['pID'] ?? '')));
        $role_ids = rbac_parse_role_ids($current_user['roleID'] ?? '');
        $position_id = (int) ($current_user['positionID'] ?? 0);

        $connection = db_connection();
        $strict_role_ids = $actor_pid !== '' ? rbac_get_user_role_ids($connection, $actor_pid) : $role_ids;

        $is_admin_user = in_array(1, $strict_role_ids, true);
        $is_registry_user = in_array(2, $strict_role_ids, true);
        $is_vehicle_user = in_array(3, $strict_role_ids, true);
        $is_facility_user = in_array(5, $strict_role_ids, true);
        $is_repair_staff_user = in_array(7, $strict_role_ids, true);

        if ($actor_pid !== '') {
            $is_admin_user = rbac_user_has_role($connection, $actor_pid, ROLE_ADMIN) || $is_admin_user;
            $is_registry_user = (!$is_admin_user && rbac_user_has_role($connection, $actor_pid, ROLE_REGISTRY)) || $is_registry_user;
            $is_vehicle_user = (!$is_admin_user && rbac_user_has_role($connection, $actor_pid, ROLE_VEHICLE)) || $is_vehicle_user;
            $is_facility_user = (!$is_admin_user && rbac_user_has_role($connection, $actor_pid, ROLE_FACILITY)) || $is_facility_user;
            $is_repair_staff_user = (!$is_admin_user && rbac_user_has_role($connection, $actor_pid, ROLE_REPAIR)) || $is_repair_staff_user;
        }

        $acting_pid = (string) (system_get_acting_director_pid() ?? '');
        $is_director_or_acting = $position_id === 1
            || ($acting_pid !== '' && $actor_pid !== '' && $acting_pid === $actor_pid);
        $is_deputy_user = in_array($position_id, system_position_deputy_ids($connection), true);
        $is_budget_deputy_user = in_array($position_id, system_position_budget_deputy_ids($connection), true);
        $is_vehicle_final_approver = $is_budget_deputy_user || ($acting_pid !== '' && $actor_pid !== '' && $acting_pid === $actor_pid);

        return [
            'is_admin_user' => $is_admin_user,
            'is_registry_user' => $is_registry_user,
            'is_vehicle_user' => $is_vehicle_user,
            'is_facility_user' => $is_facility_user,
            'is_repair_staff_user' => $is_repair_staff_user,
            'is_director_or_acting' => $is_director_or_acting,
            'is_deputy_user' => $is_deputy_user,
            'is_budget_deputy_user' => $is_budget_deputy_user,
            'is_vehicle_final_approver' => $is_vehicle_final_approver,
            'can_review_external_circular' => $is_director_or_acting || $is_deputy_user,
            'can_manage_external_circular' => $is_admin_user || $is_registry_user,
            'can_approve_room_module' => $is_admin_user || $is_facility_user,
            'can_manage_room_module' => $is_admin_user,
            'can_manage_vehicle_module' => $is_admin_user || $is_vehicle_user,
            'can_approve_vehicle_module' => $is_admin_user || $is_vehicle_user || $is_vehicle_final_approver,
            'can_approve_repair_module' => $is_admin_user || $is_repair_staff_user,
            'can_manage_repair_module' => $is_admin_user,
            'can_access_settings' => $is_admin_user || $is_registry_user,
        ];
    }
}

if (!function_exists('dashboard_shortcuts')) {
    function dashboard_shortcuts(array $access): array
    {
        $director_review_url = 'outgoing-notice.php?box=director&type=external&read=all&sort=newest&view=table1';
        $vehicle_url = !empty($access['is_vehicle_user']) || !empty($access['is_vehicle_final_approver'])
            ? 'vehicle-reservation-approval.php'
            : 'vehicle-reservation.php';

        return [
            [
                'image' => 'public/assets/img/icon/member.png',
                'label' => 'ลงทะเบียนรับ',
                'href' => 'outgoing-receive.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/memo.png',
                'label' => 'บันทึกข้อความ',
                'href' => 'memo.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/envelope.png',
                'label' => 'ส่งหนังสือเวียน',
                'href' => 'circular-compose.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/files.png',
                'label' => 'คำสั่งราชการ',
                'href' => 'orders-create.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/car.png',
                'label' => 'การจองพาหนะ',
                'href' => $vehicle_url,
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/building.png',
                'label' => 'การจองสถานที่/ห้อง',
                'href' => 'room-booking.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/repair.png',
                'label' => 'แจ้งเหตุซ่อมแซม',
                'href' => 'repairs.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/phone.png',
                'label' => 'สมุดโทรศัพท์',
                'href' => 'teacher-phone-directory.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/user.png',
                'label' => 'โปรไฟล์',
                'href' => 'profile.php',
                'visible' => true,
            ],
            [
                'image' => 'public/assets/img/icon/setting.png',
                'label' => 'การตั้งค่า',
                'href' => 'setting.php',
                'visible' => true,
            ],
        ];
    }
}

if (!function_exists('dashboard_current_thai_date_label')) {
    function dashboard_current_thai_date_label(): string
    {
        $months = [
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
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y') + 543;

        return 'วันที่ ' . (int) $now->format('j') . ' ' . ($months[$month] ?? '') . ' พ.ศ.' . $year;
    }
}

if (!function_exists('dashboard_index')) {
    function dashboard_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = trim((string) ($current_user['pID'] ?? ($_SESSION['pID'] ?? '')));
        $access = dashboard_resolve_access($current_user);
        $shortcuts = dashboard_shortcuts($access);
        $counts = dashboard_counts($current_pid, $access);
        $dh_year = system_get_dh_year();
        $announcements = circular_get_announcements(9);

        view_render('dashboard/index', [
            'dashboard_counts' => $counts,
            'dashboard_shortcuts' => $shortcuts,
            'dashboard_access' => $access,
            'dashboard_user' => $current_user,
            'dashboard_current_date_label' => dashboard_current_thai_date_label(),
            'dashboard_calendar_events' => dashboard_calendar_events($dh_year),
            'dashboard_announcements' => $announcements,
        ]);
    }
}
