<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../../src/Services/security/security-service.php';
require_once __DIR__ . '/../../src/Services/teacher/teacher-profile.php';
require_once __DIR__ . '/../../src/Services/system/system-year.php';
require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-data.php';

if (!function_exists('vehicle_reservation_index')) {
    function vehicle_reservation_index(): void
    {
        global $connection, $teacher, $dh_year;

        $teacher_name = (string) ($teacher['fName'] ?? '');
        $current_pid = (string) ($_SESSION['pID'] ?? '');

        $currentThaiYear = (int) date('Y') + 543;
        $dh_year_value = (int) ($dh_year !== '' ? $dh_year : $currentThaiYear);

        if ($dh_year_value < 2500) {
            $dh_year_value = $currentThaiYear;
        }
        $vehicle_booking_year = $dh_year_value;
        $requester_pid = $current_pid;
        $today = date('Y-m-d');

        // NOTE: Keep internal status keys stable in DB (PENDING/ASSIGNED/APPROVED...)
        // but use user-facing labels/colors that make the requester flow clearer.
        $vehicle_reservation_status_labels = [
            'DRAFT' => ['label' => 'แบบร่าง', 'class' => 'pending'],
            'PENDING' => ['label' => 'ส่งเอกสารแล้ว', 'class' => 'pending'],
            'ASSIGNED' => ['label' => 'กำลังดำเนินการ', 'class' => 'processing'],
            'APPROVED' => ['label' => 'อนุมัติการจองสำเร็จ', 'class' => 'approved'],
            'REJECTED' => ['label' => 'ไม่อนุมัติ', 'class' => 'rejected'],
            'CANCELLED' => ['label' => 'ยกเลิก', 'class' => 'rejected'],
            'COMPLETED' => ['label' => 'เสร็จสิ้น', 'class' => 'approved'],
        ];

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
            $date = trim($date);

            if ($date === '' || strpos($date, '0000-00-00') === 0) {
                return '-';
            }

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
            $start = trim($start);
            $end = trim($end);

            if ($start === '') {
                return '-';
            }

            if ($end === '' || $start === $end) {
                return $format_thai_date($start);
            }

            $start_obj = DateTime::createFromFormat('Y-m-d', $start);
            $end_obj = DateTime::createFromFormat('Y-m-d', $end);

            if ($start_obj === false || $end_obj === false) {
                return trim($format_thai_date($start) . ' - ' . $format_thai_date($end));
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
            $datetime = trim($datetime);

            if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
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

        $format_thai_datetime_range = static function (string $start, string $end) use ($format_thai_datetime): string {
            $start_label = $format_thai_datetime($start);
            $end_label = $format_thai_datetime($end);

            if ($end_label === '-' || $end === $start) {
                return $start_label;
            }

            return $start_label . ' - ' . $end_label;
        };

        require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-update.php';
        require_once __DIR__ . '/../../src/Services/vehicle/vehicle-reservation-save.php';

        $vehicle_departments = vehicle_reservation_get_departments($connection);
        $vehicle_factions = vehicle_reservation_get_factions($connection);
        $vehicle_teachers = vehicle_reservation_get_teachers($connection);
        $vehicle_booking_history = vehicle_reservation_get_bookings($connection, $vehicle_booking_year, $requester_pid);
        $vehicle_booking_ids = array_values(array_filter(array_map(
            static fn (array $booking): int => (int) ($booking['bookingID'] ?? 0),
            $vehicle_booking_history
        )));
        $vehicle_booking_attachments = vehicle_reservation_get_booking_attachments($connection, $vehicle_booking_ids);

        $vehicle_teacher_map = [];

        foreach ($vehicle_teachers as $teacher_item) {
            $teacher_id = trim((string) ($teacher_item['id'] ?? ''));

            if ($teacher_id === '') {
                continue;
            }
            $vehicle_teacher_map[$teacher_id] = trim((string) ($teacher_item['name'] ?? ''));
        }

        $vehicle_booking_payload = [];

        foreach ($vehicle_booking_history as $booking_item) {
            $booking_id = (int) ($booking_item['bookingID'] ?? 0);
            $status_key = strtoupper((string) ($booking_item['status'] ?? 'PENDING'));
            $status_meta = $vehicle_reservation_status_labels[$status_key] ?? $vehicle_reservation_status_labels['PENDING'];
            $companion_ids = [];
            $raw_companions = $booking_item['companionIds'] ?? null;

            if (is_string($raw_companions) && $raw_companions !== '') {
                $decoded = json_decode($raw_companions, true);

                if (is_array($decoded)) {
                    $companion_ids = array_values(array_filter(array_map(
                        static fn ($id): string => trim((string) $id),
                        $decoded
                    )));
                }
            }
            $companion_names = [];

            foreach ($companion_ids as $companion_id) {
                $name = $vehicle_teacher_map[$companion_id] ?? '';

                if ($name !== '') {
                    $companion_names[] = $name;
                }
            }
            $stored_passenger_count = (int) ($booking_item['passengerCount'] ?? 0);
            $other_passenger_count = (int) ($booking_item['otherPassengerCount'] ?? 0);
            $other_passenger_names = (string) ($booking_item['otherPassengerNames'] ?? '');
            $other_passenger_name_count = count(array_filter(
                preg_split('/\r\n|\r|\n|,/', $other_passenger_names) ?: [],
                static fn ($name): bool => trim((string) $name) !== ''
            ));

            if ($other_passenger_count <= 0 && $other_passenger_name_count > 0) {
                $other_passenger_count = $other_passenger_name_count;
            } elseif ($other_passenger_count <= 0 && $stored_passenger_count > 0) {
                $other_passenger_count = max(0, $stored_passenger_count - count($companion_ids) - 1);
            }
            $passenger_count = max(1, count($companion_ids) + max(0, $other_passenger_count) + 1);
            $attachments = $vehicle_booking_attachments[(string) $booking_id] ?? [];
            $vehicle_type = trim((string) ($booking_item['vehicleType'] ?? ''));
            $vehicle_plate = trim((string) ($booking_item['vehiclePlate'] ?? ''));
            $vehicle_brand = trim((string) ($booking_item['vehicleBrand'] ?? ''));
            $vehicle_model = trim((string) ($booking_item['vehicleModel'] ?? ''));
            $vehicle_label = trim(($vehicle_type !== '' ? $vehicle_type : '') . ' ' . ($vehicle_plate !== '' ? $vehicle_plate : ''));

            if ($vehicle_label === '') {
                $vehicle_label = trim(($vehicle_brand !== '' ? $vehicle_brand : '') . ' ' . ($vehicle_model !== '' ? $vehicle_model : ''));
            }

            $updated_at_value = trim((string) ($booking_item['updatedAt'] ?? ''));

            if ($updated_at_value === '' || $updated_at_value === '0000-00-00 00:00:00') {
                $updated_at_value = (string) ($booking_item['createdAt'] ?? '');
            }

            $vehicle_booking_payload[] = [
                'id' => $booking_id,
                'department' => (string) ($booking_item['department'] ?? ''),
                'writeDate' => (string) ($booking_item['writeDate'] ?? ''),
                'purpose' => (string) ($booking_item['purpose'] ?? ''),
                'location' => (string) ($booking_item['location'] ?? ''),
                'passengerCount' => $passenger_count,
                'companionCount' => count($companion_ids),
                'otherPassengerCount' => $other_passenger_count,
                'otherPassengerNames' => $other_passenger_names,
                'startAt' => (string) ($booking_item['startAt'] ?? ''),
                'endAt' => (string) ($booking_item['endAt'] ?? ''),
                'fuelSource' => (string) ($booking_item['fuelSource'] ?? ''),
                'companionIds' => $companion_ids,
                'companionNames' => $companion_names,
                'status' => $status_key,
                'statusLabel' => $status_meta['label'],
                'statusClass' => $status_meta['class'],
                'statusReason' => (string) ($booking_item['statusReason'] ?? ''),
                'approvalNote' => (string) ($booking_item['approvalNote'] ?? ''),
                'vehicleLabel' => $vehicle_label,
                'vehiclePlate' => $vehicle_plate,
                'vehicleType' => $vehicle_type,
                'vehicleBrand' => $vehicle_brand,
                'vehicleModel' => $vehicle_model,
                'driverName' => (string) ($booking_item['driverName'] ?? ''),
                'driverTel' => (string) ($booking_item['driverTel'] ?? ''),
                'assignedNote' => (string) ($booking_item['assignedNote'] ?? ''),
                'assignedName' => (string) ($booking_item['assigned_name'] ?? ''),
                'assignedAt' => (string) ($booking_item['assignedAt'] ?? ''),
                'assignedAtLabel' => $format_thai_datetime((string) ($booking_item['assignedAt'] ?? '')),
                'attachments' => $attachments,
                'createdAt' => (string) ($booking_item['createdAt'] ?? ''),
                'updatedAt' => $updated_at_value,
                'createdAtLabel' => $format_thai_datetime((string) ($booking_item['createdAt'] ?? '')),
                'updatedAtLabel' => $format_thai_datetime($updated_at_value),
            ];
        }

        $vehicle_reservation_alert = $_SESSION['vehicle_reservation_alert'] ?? null;
        unset($_SESSION['vehicle_reservation_alert']);

        view_render('vehicle/reservation', [
            'teacher_name' => $teacher_name,
            'dh_year_value' => $dh_year_value,
            'requester_pid' => $requester_pid,
            'today' => $today,
            'vehicle_reservation_status_labels' => $vehicle_reservation_status_labels,
            'format_thai_date_range' => $format_thai_date_range,
            'format_thai_datetime' => $format_thai_datetime,
            'format_thai_datetime_range' => $format_thai_datetime_range,
            'vehicle_departments' => $vehicle_departments,
            'vehicle_factions' => $vehicle_factions,
            'vehicle_teachers' => $vehicle_teachers,
            'vehicle_booking_history' => $vehicle_booking_history,
            'vehicle_booking_payload' => $vehicle_booking_payload,
            'vehicle_reservation_alert' => $vehicle_reservation_alert,
            'teacher' => $teacher,
        ]);
    }
}
