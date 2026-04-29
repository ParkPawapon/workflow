<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../../src/Services/security/security-service.php';
require_once __DIR__ . '/../../src/Services/system/system-year.php';
require_once __DIR__ . '/../../src/Services/teacher/teacher-profile.php';
require_once __DIR__ . '/../../src/Services/system/exec-duty-current.php';
require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-utils.php';
require_once __DIR__ . '/../modules/audit/logger.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../rbac/roles.php';

if (!function_exists('vehicle_reservation_approval_index')) {
    function vehicle_reservation_approval_index(): void
    {
        global $teacher, $exec_duty_current_status, $exec_duty_current_pid, $dh_year;

        $actor_pid = (string) ($_SESSION['pID'] ?? '');
        $role_id = (int) ($teacher['roleID'] ?? 0);
        $position_id = (int) ($teacher['positionID'] ?? 0);
        $acting_pid = '';

        if (($exec_duty_current_status ?? 0) === 2 && !empty($exec_duty_current_pid)) {
            $acting_pid = (string) $exec_duty_current_pid;
        }

        // roleID mapping (legacy): 1=ADMIN, 3=VEHICLE
        $connection = db_connection();
        $vehicle_approval_is_deputy = in_array($position_id, system_position_deputy_ids($connection), true);
        $vehicle_approval_is_acting = $acting_pid !== '' && $acting_pid === $actor_pid;
        $vehicle_approval_is_final_approver = $vehicle_approval_is_deputy || $vehicle_approval_is_acting;
        $vehicle_approval_is_admin = $role_id === 1
            || ($actor_pid !== '' && rbac_user_has_role($connection, $actor_pid, ROLE_ADMIN));
        $vehicle_approval_is_vehicle_officer = $role_id === 3
            || ($actor_pid !== '' && rbac_user_has_role($connection, $actor_pid, ROLE_VEHICLE));

        if (!$vehicle_approval_is_final_approver && !$vehicle_approval_is_vehicle_officer && !$vehicle_approval_is_admin) {
            if (function_exists('audit_log')) {
                audit_log('vehicle', 'APPROVAL_ACCESS', 'DENY', null, null, 'not_authorized_role', [
                    'roleID' => $role_id,
                    'positionID' => $position_id,
                ]);
            }
            header('Location: dashboard.php', true, 302);
            exit();
        }

        $currentThaiYear = (int) date('Y') + 543;
        $dh_year_value = (int) ($dh_year !== '' ? $dh_year : $currentThaiYear);

        if ($dh_year_value < 2500) {
            $dh_year_value = $currentThaiYear;
        }

        require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-approval-actions.php';
        require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-approval-data.php';

        $thai_months = [
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

        $format_thai_date = static function (string $date) use ($thai_months): string {
            $date_obj = DateTime::createFromFormat('Y-m-d', $date);

            if ($date_obj === false) {
                return $date;
            }

            $day = (int) $date_obj->format('j');
            $month = (int) $date_obj->format('n');
            $year = (int) $date_obj->format('Y') + 543;
            $month_label = $thai_months[$month] ?? '';

            return trim($day . ' ' . $month_label . ' ' . $year);
        };

        $format_thai_date_range = static function (string $start, string $end) use ($format_thai_date, $thai_months): string {
            if ($end === '' || $start === $end) {
                return $format_thai_date($start);
            }

            $start_obj = DateTime::createFromFormat('Y-m-d', $start);
            $end_obj = DateTime::createFromFormat('Y-m-d', $end);

            if ($start_obj === false || $end_obj === false) {
                return $format_thai_date($start) . ' - ' . $format_thai_date($end);
            }

            $start_day = (int) $start_obj->format('j');
            $start_month = (int) $start_obj->format('n');
            $start_year = (int) $start_obj->format('Y') + 543;
            $end_day = (int) $end_obj->format('j');
            $end_month = (int) $end_obj->format('n');
            $end_year = (int) $end_obj->format('Y') + 543;
            $start_month_label = $thai_months[$start_month] ?? '';
            $end_month_label = $thai_months[$end_month] ?? '';

            if ($start_year === $end_year && $start_month === $end_month) {
                return trim($start_day . '-' . $end_day . ' ' . $start_month_label . ' ' . $start_year);
            }

            if ($start_year === $end_year) {
                return trim($start_day . ' ' . $start_month_label . ' - ' . $end_day . ' ' . $end_month_label . ' ' . $start_year);
            }

            return trim($start_day . ' ' . $start_month_label . ' ' . $start_year . ' - ' . $end_day . ' ' . $end_month_label . ' ' . $end_year);
        };

        $format_thai_datetime = static function (string $datetime) use ($thai_months): string {
            if ($datetime === '' || strpos($datetime, '0000-00-00') === 0) {
                return '-';
            }

            $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);

            if ($date_obj === false) {
                $date_obj = DateTime::createFromFormat('Y-m-d H:i', $datetime);
            }

            if ($date_obj === false) {
                return $datetime;
            }

            $day = (int) $date_obj->format('j');
            $month = (int) $date_obj->format('n');
            $year = (int) $date_obj->format('Y') + 543;
            $month_label = $thai_months[$month] ?? '';

            return trim($day . ' ' . $month_label . ' ' . $year . ' เวลา ' . $date_obj->format('H:i'));
        };

        // Keep internal status keys stable in DB (PENDING/ASSIGNED/APPROVED...)
        // Use the same user-facing labels/colors as the requester flow (vehicle-reservation.php).
        $vehicle_approval_status_labels = [
            'DRAFT' => ['label' => 'แบบร่าง', 'class' => 'pending'],
            'PENDING' => ['label' => 'ส่งเอกสารแล้ว', 'class' => 'pending'],
            'ASSIGNED' => ['label' => 'กำลังดำเนินการ', 'class' => 'processing'],
            'APPROVED' => ['label' => 'อนุมัติการจองสำเร็จ', 'class' => 'approved'],
            'REJECTED' => ['label' => 'ไม่อนุมัติ', 'class' => 'rejected'],
            'CANCELLED' => ['label' => 'ยกเลิก', 'class' => 'rejected'],
            'COMPLETED' => ['label' => 'เสร็จสิ้น', 'class' => 'approved'],
        ];

        $vehicle_approval_alert = null;

        if (isset($_SESSION['vehicle_approval_alert'])) {
            $vehicle_approval_alert = $_SESSION['vehicle_approval_alert'];
            unset($_SESSION['vehicle_approval_alert']);
        }

        $vehicle_approval_return_url = 'vehicle-reservation-approval.php';

        if (!empty($_SERVER['QUERY_STRING'])) {
            $vehicle_approval_return_url .= '?' . $_SERVER['QUERY_STRING'];
        }

        if (isset($_GET['ajax_filter'])) {
            header('Content-Type: application/json; charset=utf-8');

            ob_start();
            require __DIR__ . '/../../public/components/partials/vehicle-reservation-approval-table-rows.php';
            $rows_html = (string) ob_get_clean();

            ob_start();
            require __DIR__ . '/../../public/components/partials/vehicle-reservation-approval-pagination.php';
            $pagination_html = (string) ob_get_clean();

            echo json_encode([
                'rows_html' => $rows_html,
                'pagination_html' => $pagination_html,
                'total' => (int) ($vehicle_approval_total ?? 0),
                'page' => (int) ($vehicle_approval_page ?? 1),
                'total_pages' => (int) ($vehicle_approval_total_pages ?? 0),
                'per_page' => $vehicle_approval_per_page ?? 10,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        view_render('vehicle/reservation-approval', [
            'vehicle_approval_alert' => $vehicle_approval_alert,
            'vehicle_approval_mode' => $vehicle_approval_mode ?? 'officer',
            'vehicle_approval_can_assign' => $vehicle_approval_can_assign ?? false,
            'vehicle_approval_can_finalize' => $vehicle_approval_can_finalize ?? false,
            'vehicle_approval_query' => $vehicle_approval_query ?? '',
            'vehicle_approval_status' => $vehicle_approval_status ?? 'all',
            'vehicle_approval_vehicle' => $vehicle_approval_vehicle ?? 'all',
            'vehicle_approval_return_url' => $vehicle_approval_return_url,
            'vehicle_list' => $vehicle_list ?? [],
            'vehicle_driver_list' => $vehicle_driver_list ?? [],
            'vehicle_deputy_list' => $vehicle_deputy_list ?? [],
            'vehicle_booking_requests' => $vehicle_booking_requests ?? [],
            'vehicle_booking_attachments' => $vehicle_booking_attachments ?? [],
            'vehicle_approval_total' => $vehicle_approval_total ?? 0,
            'vehicle_approval_total_pages' => $vehicle_approval_total_pages ?? 0,
            'vehicle_approval_page' => $vehicle_approval_page ?? 1,
            'vehicle_approval_per_page' => $vehicle_approval_per_page ?? 10,
            'vehicle_approval_status_labels' => $vehicle_approval_status_labels,
            'format_thai_date_range' => $format_thai_date_range,
            'format_thai_datetime' => $format_thai_datetime,
            'teacher' => $teacher,
        ]);
    }
}
