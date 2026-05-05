<?php
require_once __DIR__ . '/../../../app/db/db.php';
require_once __DIR__ . '/../../../app/rbac/roles.php';
require_once __DIR__ . '/../../../app/modules/dashboard/metrics.php';
require_once __DIR__ . '/../../../app/modules/system/system.php';
require_once __DIR__ . '/../../../src/Services/teacher/teacher-profile.php';
require_once __DIR__ . '/../../../src/Services/system/exec-duty-current.php';

$role_ids = rbac_parse_role_ids($teacher['roleID'] ?? '');
$position_id = (int) ($teacher['positionID'] ?? 0);
$repair_staff_role_id = 7;
$actor_pid = (string) ($_SESSION['pID'] ?? '');

// Exec duty: dutyStatus 2 means "acting director" (รองรักษาการแทน).
$acting_pid = '';

if (($exec_duty_current_status ?? 0) === 2 && !empty($exec_duty_current_pid)) {
    $acting_pid = (string) $exec_duty_current_pid;
}

$sidebar_connection = db_connection();
$strict_role_ids = $actor_pid !== '' ? rbac_get_user_role_ids($sidebar_connection, $actor_pid) : $role_ids;
$actor_position_ids = $actor_pid !== '' ? rbac_get_user_position_ids($sidebar_connection, $actor_pid) : [$position_id];
$is_admin_user = in_array(1, $strict_role_ids, true);
$is_registry_user = in_array(2, $strict_role_ids, true);
$is_vehicle_user = in_array(3, $strict_role_ids, true);
$is_facility_user = in_array(5, $strict_role_ids, true);
$is_repair_staff_user = in_array($repair_staff_role_id, $strict_role_ids, true);

if ($actor_pid !== '') {
    $is_admin_user = rbac_user_has_role($sidebar_connection, $actor_pid, ROLE_ADMIN) || $is_admin_user;
    $is_registry_user = (!$is_admin_user && rbac_user_has_role($sidebar_connection, $actor_pid, ROLE_REGISTRY)) || $is_registry_user;
    $is_vehicle_user = (!$is_admin_user && rbac_user_has_role($sidebar_connection, $actor_pid, ROLE_VEHICLE)) || $is_vehicle_user;
    $is_facility_user = (!$is_admin_user && rbac_user_has_role($sidebar_connection, $actor_pid, ROLE_FACILITY)) || $is_facility_user;
    $is_repair_staff_user = (!$is_admin_user && rbac_user_has_role($sidebar_connection, $actor_pid, ROLE_REPAIR)) || $is_repair_staff_user;
}

$is_director_or_acting = in_array(1, $actor_position_ids, true) || ($acting_pid !== '' && $acting_pid === $actor_pid);
$can_manage_external_circular = $is_admin_user || $is_registry_user;
$is_deputy_user = !empty(array_intersect($actor_position_ids, system_position_deputy_ids($sidebar_connection)));
$is_budget_deputy_user = !empty(array_intersect($actor_position_ids, system_position_budget_deputy_ids($sidebar_connection)));
$is_vehicle_final_approver = $is_budget_deputy_user || ($acting_pid !== '' && $acting_pid === $actor_pid);
$can_review_external_circular = $is_director_or_acting || $is_deputy_user;
$can_access_external_circular_menu = $actor_pid !== '';
$can_approve_room_module = $is_admin_user || $is_facility_user;
$can_manage_room_module = $is_admin_user;
$can_manage_vehicle_module = $is_admin_user || $is_vehicle_user;
$can_approve_vehicle_module = $is_admin_user || $is_vehicle_user || $is_vehicle_final_approver;
$can_manage_vehicle_records = $is_admin_user;
$can_access_settings = $is_admin_user || $is_registry_user;
$can_approve_repair_module = $is_admin_user || $is_repair_staff_user;
$can_manage_repair_module = $is_admin_user;

$director_inbox_type = ($acting_pid !== '' && $acting_pid === $actor_pid)
    ? 'acting_principal_inbox'
    : 'special_principal_inbox';
$sidebar_access = [
    'is_admin_user' => $is_admin_user,
    'is_registry_user' => $is_registry_user,
    'is_vehicle_user' => $is_vehicle_user,
    'is_facility_user' => $is_facility_user,
    'is_repair_staff_user' => $is_repair_staff_user,
    'is_director_or_acting' => $is_director_or_acting,
    'is_deputy_user' => $is_deputy_user,
    'is_budget_deputy_user' => $is_budget_deputy_user,
    'is_vehicle_final_approver' => $is_vehicle_final_approver,
    'can_manage_external_circular' => $can_manage_external_circular,
    'can_review_external_circular' => $can_review_external_circular,
    'can_approve_room_module' => $can_approve_room_module,
    'can_manage_room_module' => $can_manage_room_module,
    'can_manage_vehicle_module' => $can_manage_vehicle_module,
    'can_approve_vehicle_module' => $can_approve_vehicle_module,
    'can_manage_vehicle_records' => $can_manage_vehicle_records,
    'can_approve_repair_module' => $can_approve_repair_module,
    'can_manage_repair_module' => $can_manage_repair_module,
];
$sidebar_counts = $actor_pid !== '' ? dashboard_counts($actor_pid, $sidebar_access) : dashboard_zero_counts();
$sidebar_counts['external_circular_notifications'] = $actor_pid !== ''
    ? dashboard_count_external_circular_notifications($sidebar_connection, $actor_pid, $sidebar_access, $director_inbox_type)
    : 0;
$sidebar_alerts = [
    'home' => false,
    'news' => false,
    'internal_circular' => (int) ($sidebar_counts['unread_internal_circulars'] ?? 0) > 0,
    'external_circular' => (int) ($sidebar_counts['external_circular_notifications'] ?? 0) > 0
        || (int) ($sidebar_counts['unread_external_circulars'] ?? 0) > 0,
    'memo' => (int) ($sidebar_counts['unread_memos'] ?? 0) > 0,
    'orders' => (int) ($sidebar_counts['unread_orders'] ?? 0) > 0,
    'room' => (int) ($sidebar_counts['room_notifications'] ?? 0) > 0,
    'vehicle' => (int) ($sidebar_counts['vehicle_notifications'] ?? $sidebar_counts['unread_vehicle_bookings'] ?? 0) > 0,
    'repairs' => (int) ($sidebar_counts['repair_notifications'] ?? 0) > 0,
];
$sidebar_alerts['home'] = (int) ($sidebar_counts['unread_external_circulars'] ?? 0) > 0
    || $sidebar_alerts['internal_circular']
    || $sidebar_alerts['external_circular']
    || $sidebar_alerts['memo']
    || $sidebar_alerts['orders']
    || $sidebar_alerts['room']
    || $sidebar_alerts['vehicle']
    || $sidebar_alerts['repairs'];
?>
<aside class="sidebar close">
    <header class="logo-details">
        <a href="#">
            <img src="assets/img/DBsarabun_banner.png" alt="DB Sarabun">
        </a>
        <i class="fa-solid fa-angle-left" id="btn-toggle"></i>
    </header>
    <hr>
    <div class="navigation-links">
        <li>
            <a href="dashboard.php">
                <img src="public/assets/img/icon/home.png" alt="">
                <p class="link-name">หน้าหลัก</p>
            </a>
        </li>

        <li>
            <a href="dashboard.php#news-paper">
                <img src="public/assets/img/icon/news-paper.png" alt="">
                <p class="link-name">ข่าวประชาสัมพันธ์ </p>
            </a>
        </li>

        <?php if ($can_access_external_circular_menu): ?>
            <li class="navigation-links-has-sub">
                <div class="icon-link">
                    <a href="#">
                        <?php if ($sidebar_alerts['external_circular']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                        <img src="public/assets/img/icon/files.png" alt="">
                        <p class="link-name">หนังสือเวียน</p>
                    </a>
                    <i class="fa-solid fa-caret-down"></i>
                </div>
                <ul class="navigation-links-sub-menu">
                    <li><a href="outgoing-notice.php?box=normal&type=external&read=all&sort=newest&view=table1">กล่องหนังสือเวียน</a></li>
                    <?php if ($can_manage_external_circular): ?>
                        <li><a href="outgoing-receive.php">ลงทะเบียนรับหนังสือ</a></li>
                        <li><a href="outgoing-notice.php?box=clerk&type=external&read=all&sort=newest&view=table1">กล่องกำลังเสนอ</a></li>
                    <?php endif; ?>
                    <?php if ($can_review_external_circular): ?>
                        <li><a href="outgoing-notice.php?box=director&type=external&read=all&sort=newest&view=table1">กล่องรอพิจารณา</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <li class="navigation-links-has-sub">
            <div class="icon-link">
                <a href="#">
                    <?php if ($sidebar_alerts['internal_circular']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                    <img src="public/assets/img/icon/envelope.png" alt="">
                    <p class="link-name">หนังสือเวียน (ภายใน)</p>
                </a>
                <i class="fa-solid fa-caret-down"></i>
            </div>
            <ul class="navigation-links-sub-menu">
                <li><a href="circular-notice.php">กล่องหนังสือเวียน</a></li>
                <li><a href="circular-archive.php">หนังสือเวียนที่จัดเก็บ</a></li>
                <li><a href="circular-compose.php">ส่งหนังสือเวียน</a></li>
            </ul>
        </li>

        <?php if ($can_manage_external_circular): ?>
            <li>
                <a href="outgoing.php">
                    <img src="public/assets/img/icon/clipboard.png" alt="">
                    <p class="link-name">ออกเลขทะเบียนส่ง</p>
                </a>
            </li>
        <?php endif; ?>

        <li class="navigation-links-has-sub">
            <div class="icon-link">
                <a href="#">
                    <?php if ($sidebar_alerts['memo']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                    <img src="public/assets/img/icon/memo.png" alt="">
                    <p class="link-name">บันทึกข้อความ</p>
                </a>
                <i class="fa-solid fa-caret-down"></i>
            </div>
            <ul class="navigation-links-sub-menu">
                <li><a href="memo-inbox.php">กล่องบันทึกข้อความ</a></li>
                <li><a href="memo-archive.php">บันทึกข้อความที่จัดเก็บ</a></li>
                <li><a href="memo.php">บันทึกข้อความ</a></li>
            </ul>
        </li>

        <li class="navigation-links-has-sub">
            <div class="icon-link">
                <a href="#">
                    <?php if ($sidebar_alerts['orders']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                     <img src="public/assets/img/icon/files.png" alt="">
                    <p class="link-name">คำสั่งราชการ</p>
                </a>
                <i class="fa-solid fa-caret-down"></i>
            </div>
            <ul class="navigation-links-sub-menu">
                <li><a href="orders-inbox.php">กล่องคำสั่งราชการ</a></li>
                <li><a href="orders-archive.php">คำสั่งราชการที่จัดเก็บ</a></li>
                <li><a href="orders-create.php">ออกเลขคำสั่งราชการ</a></li>
            </ul>
        </li>

        <?php if ($can_approve_room_module): ?>
            <li class="navigation-links-has-sub">
                <div class="icon-link">
                    <a href="#">
                        <?php if ($sidebar_alerts['room']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                         <img src="public/assets/img/icon/building.png" alt="">
                        <p class="link-name">การจองสถานที่/ห้อง</p>
                    </a>
                    <i class="fa-solid fa-caret-down"></i>
                </div>
                <ul class="navigation-links-sub-menu">
                    <li><a href="room-booking.php">จองสถานที่/ห้อง</a></li>
                    <li><a href="room-booking-approval.php">อนุมัติการจองสถานที่/ห้อง</a></li>
                    <?php if ($can_manage_room_module): ?>
                        <li><a href="room-management.php">จัดการสถานที่/ห้อง</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php else: ?>
            <li>
                <a href="room-booking.php">
                    <?php if ($sidebar_alerts['room']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                    <img src="public/assets/img/icon/building.png" alt="">
                    <p class="link-name">จองสถานที่/ห้อง</p>
                </a>
            </li>
        <?php endif; ?>

        <?php if ($can_approve_vehicle_module): ?>
            <li class="navigation-links-has-sub">
                <div class="icon-link">
                    <a href="#">
                        <?php if ($sidebar_alerts['vehicle']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                        <img src="public/assets/img/icon/car.png" alt="">
                        <p class="link-name">การจองยานพาหนะ</p>
                    </a>
                    <i class="fa-solid fa-caret-down"></i>
                </div>
                <ul class="navigation-links-sub-menu">
                    <li><a href="vehicle-reservation.php">จองยานพาหนะ</a></li>
                    <li><a href="vehicle-reservation-approval.php">อนุมัติการจองยานพาหนะ</a></li>
                    <?php if ($can_manage_vehicle_records): ?>
                        <li><a href="vehicle-management.php">จัดการยานพาหนะ</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php else: ?>
            <li>
                <a href="vehicle-reservation.php">
                    <?php if ($sidebar_alerts['vehicle']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                    <img src="public/assets/img/icon/car.png" alt="">
                    <p class="link-name">จองยานพาหนะ</p>
                </a>
            </li>
        <?php endif; ?>
        <?php if ($can_approve_repair_module): ?>
            <li class="navigation-links-has-sub">
                <div class="icon-link">
                    <a href="#">
                        <?php if ($sidebar_alerts['repairs']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                        <img src="public/assets/img/icon/repair.png" alt="">
                        <p class="link-name">แจ้งเหตุซ่อมแซม</p>
                    </a>
                    <i class="fa-solid fa-caret-down"></i>
                </div>
                <ul class="navigation-links-sub-menu">
                    <li><a href="repairs.php">แจ้งเหตุซ่อมแซม</a></li>
                    <li><a href="repairs-approval.php">อนุมัติการซ่อมแซม</a></li>
                    <?php if ($can_manage_repair_module): ?>
                        <li><a href="repairs-management.php">จัดการงานซ่อม</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php else: ?>
            <li>
                <a href="repairs.php">
                    <?php if ($sidebar_alerts['repairs']): ?><span class="red-dot-alert pulse-shadow"></span><?php endif; ?>
                    <img src="public/assets/img/icon/repair.png" alt="">
                    <p class="link-name">แจ้งเหตุซ่อมแซม</p>
                </a>
            </li>
        <?php endif; ?>
        <li>
            <a href="teacher-phone-directory.php">
                <img src="public/assets/img/icon/phone.png" alt="">
                <p class="link-name">สมุดโทรศัพท์</p>
            </a>
        </li>

        <?php if ($is_admin_user): ?>
            <li>
                <a href="personnel-management.php">
                    <img src="public/assets/img/icon/personnel.png" alt="">
                    <p class="link-name">จัดการบุคลากร</p>
                </a>
            </li>
        <?php endif; ?>

        <li>
            <a href="profile.php">
                 <img src="public/assets/img/icon/user.png" alt="">
                <p class="link-name">โปรไฟล์</p>
            </a>
        </li>
        <?php if ($can_access_settings): ?>
            <li>
                <a href="setting.php">
                    <img src="public/assets/img/icon/setting.png" alt="">
                    <p class="link-name">การตั้งค่า</p>
                </a>
            </li>
        <?php endif; ?>
    </div>

    <div class="logout-section">
        <a href="logout.php" class="logout-btn"
            data-confirm="ยืนยันการออกจากระบบใช่หรือไม่?"
            data-confirm-title="ยืนยันการออกจากระบบ"
            data-confirm-ok="ยืนยัน"
            data-confirm-cancel="ยกเลิก">
            <i class="fa-solid fa-right-from-bracket"></i>
            <p>ออกจากระบบ</p>
        </a>
    </div>
</aside>
