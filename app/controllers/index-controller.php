<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../modules/circulars/repository.php';
require_once __DIR__ . '/../modules/vehicle/calendar.php';
require_once __DIR__ . '/../modules/system/system.php';

if (!function_exists('index_page_index')) {
    function index_page_index(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        require_once __DIR__ . '/../../src/Services/security/security-service.php';
        require_once __DIR__ . '/../../src/Services/auth/login.php';
        require_once __DIR__ . '/../../src/Services/system/exec-duty-announcement.php';
        require_once __DIR__ . '/../../src/Services/system/system-year.php';

        $dh_year_value = system_get_dh_year();
        $room_booking_year = $dh_year_value;
        require __DIR__ . '/../../src/Services/room/room-booking-data.php';

        $announcement_items = circular_get_announcements(10);
        $vehicle_events = vehicle_booking_events($room_booking_year);
        $calendar_events = (array) ($room_booking_events ?? []);
        $dh_version_value = system_get_dh_version();

        foreach ($vehicle_events as $date_key => $events) {
            if (!isset($calendar_events[$date_key])) {
                $calendar_events[$date_key] = [];
            }
            $calendar_events[$date_key] = array_merge((array) $calendar_events[$date_key], (array) $events);
        }

        view_render('index/index', [
            'login_alert' => $login_alert ?? null,
            'exec_duty_announcement' => (string) ($exec_duty_announcement ?? ''),
            'announcement_items' => (array) $announcement_items,
            'room_booking_events' => (array) ($room_booking_events ?? []),
            'calendar_events' => $calendar_events,
            'dh_year_value' => $dh_year_value,
            'dh_version_value' => $dh_version_value,
        ]);
    }
}
