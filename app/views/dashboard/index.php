<?php
require_once __DIR__ . '/../../helpers.php';

$dashboard_counts = (array) ($dashboard_counts ?? []);
$dashboard_shortcuts = (array) ($dashboard_shortcuts ?? []);
$dashboard_user = (array) ($dashboard_user ?? []);
$dashboard_access = (array) ($dashboard_access ?? []);
$dashboard_calendar_events = (array) ($dashboard_calendar_events ?? []);
$dashboard_announcements = array_slice((array) ($dashboard_announcements ?? []), 0, 9);
$dashboard_current_date_label = trim((string) ($dashboard_current_date_label ?? ''));
$dashboard_name = trim((string) ($dashboard_user['fName'] ?? ''));
$dashboard_pid = trim((string) ($dashboard_user['pID'] ?? ($_SESSION['pID'] ?? '')));
$dashboard_position = trim((string) ($dashboard_user['position_name'] ?? ''));
$dashboard_role = trim((string) ($dashboard_user['role_name'] ?? ''));
$dashboard_role_ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', trim((string) ($dashboard_user['roleID'] ?? ''))) ?: []));
$dashboard_is_admin = in_array(1, $dashboard_role_ids, true) || str_contains($dashboard_role, 'ผู้ดูแลระบบ');
$dashboard_can_manage_external_circular = !empty($dashboard_access['can_manage_external_circular']) || $dashboard_is_admin;
$unread_external_circulars = (int) ($dashboard_counts['unread_external_circulars'] ?? $dashboard_counts['unread_circulars'] ?? 0);
$external_circular_review_notifications = (int) ($dashboard_counts['external_circular_notifications'] ?? 0);
$unread_internal_circulars = (int) ($dashboard_counts['unread_internal_circulars'] ?? 0);
$unread_memos = (int) ($dashboard_counts['unread_memos'] ?? 0);
$unread_orders = (int) ($dashboard_counts['unread_orders'] ?? 0);
$room_notifications = (int) ($dashboard_counts['room_notifications'] ?? 0);
$vehicle_notifications = (int) ($dashboard_counts['vehicle_notifications'] ?? $dashboard_counts['unread_vehicle_bookings'] ?? 0);
$repair_notifications = (int) ($dashboard_counts['repair_notifications'] ?? 0);
$is_track_active = (bool) ($is_track_active ?? false);

if ($dashboard_is_admin) {
    $room_notifications = 0;
    $vehicle_notifications = 0;
    $repair_notifications = 0;
}

$external_circular_href = 'outgoing-notice.php?box=normal&type=external&read=all&sort=newest&view=table1';
$external_circular_review_href = 'outgoing-notice.php?box=clerk&type=external&read=all&sort=newest&view=table1';

if (!empty($dashboard_access['is_director_or_acting']) && empty($dashboard_access['can_manage_external_circular'])) {
    $external_circular_review_href = 'outgoing-notice.php?box=director&type=external&read=all&sort=newest&view=table1';
} elseif (!empty($dashboard_access['can_review_external_circular']) && empty($dashboard_access['can_manage_external_circular'])) {
    $external_circular_review_href = 'outgoing-notice.php?box=director&type=external&read=all&sort=newest&view=table1';
}

$dashboard_notifications = array_values(array_filter([
    [
        'label' => 'หนังสือเวียน',
        'count' => $unread_external_circulars,
        'href' => $external_circular_href,
    ],
    [
        'label' => 'หนังสือเวียนให้พิจารณา',
        'count' => $external_circular_review_notifications,
        'message' => '',
        'href' => $external_circular_review_href,
    ],
    [
        'label' => 'หนังสือเวียน (ภายใน)',
        'count' => $unread_internal_circulars,
        'href' => 'circular-notice.php',
    ],
    [
        'label' => 'บันทึกข้อความ',
        'count' => $unread_memos,
        'href' => 'memo-inbox.php',
    ],
    [
        'label' => 'คำสั่งราชการ',
        'count' => $unread_orders,
        'href' => 'orders-inbox.php',
    ],
    [
        'label' => 'จองสถานที่/ห้อง',
        'count' => $room_notifications,
        'message' => 'ที่ต้องดำเนินการ',
        'href' => 'room-booking-approval.php',
    ],
    [
        'label' => 'จองยานพาหนะ',
        'count' => $vehicle_notifications,
        'message' => 'ที่ต้องดำเนินการ',
        'href' => 'vehicle-reservation-approval.php',
    ],
    [
        'label' => 'แจ้งเหตุซ่อมแซม',
        'count' => $repair_notifications,
        'message' => 'ที่ต้องดำเนินการ',
        'href' => 'repairs-approval.php',
    ],
], static function (array $item): bool {
    return (int) ($item['count'] ?? 0) > 0;
}));

$visible_shortcuts = array_values(array_filter($dashboard_shortcuts, static function ($shortcut): bool {
    return !empty($shortcut['visible']);
}));
$dashboard_calendar_events_json = json_encode($dashboard_calendar_events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($dashboard_calendar_events_json === false) {
    $dashboard_calendar_events_json = '{}';
}

$dashboard_plain_text = static function ($value): string {
    $text = (string) ($value ?? '');

    if ($text === '') {
        return '';
    }

    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
    $text = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

    return trim($text);
};

$dashboard_announcement_payloads = [];

foreach ($dashboard_announcements as $announcement) {
    $announcement_id = (int) ($announcement['announcementID'] ?? 0);
    $circular_id = (int) ($announcement['circularID'] ?? 0);
    $payload_key = $announcement_id > 0 ? (string) $announcement_id : 'circular-' . (string) $circular_id;
    $files = [];

    foreach ((array) ($announcement['files'] ?? []) as $file) {
        $file_id = (int) ($file['fileID'] ?? 0);

        if ($file_id <= 0 || $circular_id <= 0) {
            continue;
        }

        $files[] = [
            'fileID' => $file_id,
            'fileName' => trim((string) ($file['fileName'] ?? '')),
            'mimeType' => trim((string) ($file['mimeType'] ?? '')),
            'fileNote' => trim((string) ($file['fileNote'] ?? $file['note'] ?? '')),
            'url' => 'public/api/file-download.php?module=circulars&entity_id=' . rawurlencode((string) $circular_id) . '&file_id=' . rawurlencode((string) $file_id),
        ];
    }

    $subject = trim((string) ($announcement['subject'] ?? ''));
    $announcement_position = trim((string) ($announcement['announcementByPositionName'] ?? ''));
    $announcement_comment_label = str_contains($announcement_position, 'รองผู้อำนวยการ')
        ? 'ความคิดเห็นของรองผู้อำนวยการ'
        : 'ความคิดเห็นของผู้ส่งขึ้นข่าวประชาสัมพันธ์';

    $dashboard_announcement_payloads[$payload_key] = [
        'announcementID' => $announcement_id,
        'circularID' => $circular_id,
        'subject' => $subject !== '' ? $subject : 'ข่าวประชาสัมพันธ์',
        'detailText' => $dashboard_plain_text($announcement['detail'] ?? ''),
        'linkURL' => trim((string) ($announcement['linkURL'] ?? '')),
        'announcementCommentText' => $dashboard_plain_text($announcement['announcementComment'] ?? ''),
        'announcementCommentLabel' => $announcement_comment_label,
        'directorCommentText' => $dashboard_plain_text($announcement['announcementComment'] ?? ''),
        'downloadAllURL' => $circular_id > 0 ? 'public/api/file-download.php?module=circulars&entity_id=' . rawurlencode((string) $circular_id) . '&all=1' : '',
        'files' => $files,
    ];
}

$dashboard_announcement_payload_json = json_encode($dashboard_announcement_payloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

if ($dashboard_announcement_payload_json === false) {
    $dashboard_announcement_payload_json = '{}';
}

$dashboard_vehicle_schedule = [];

foreach ($dashboard_calendar_events as $event_date => $events) {
    if (!is_array($events)) {
        continue;
    }

    foreach ($events as $event) {
        if (!is_array($event) || (string) ($event['type'] ?? '') !== 'car') {
            continue;
        }

        $driver_pid = trim((string) ($event['driverPid'] ?? ''));

        if ($driver_pid === '' || $dashboard_pid === '' || $driver_pid !== $dashboard_pid) {
            continue;
        }

        $booking_id = trim((string) ($event['bookingId'] ?? ''));
        $schedule_key = $booking_id !== ''
            ? $booking_id
            : sha1((string) $event_date . '|' . (string) ($event['title'] ?? '') . '|' . (string) ($event['time'] ?? '') . '|' . (string) ($event['owner'] ?? ''));

        if (isset($dashboard_vehicle_schedule[$schedule_key])) {
            continue;
        }

        $dashboard_vehicle_schedule[$schedule_key] = [
            'title' => trim((string) ($event['title'] ?? '-')) ?: '-',
            'start_date' => trim((string) ($event['startDate'] ?? '')) ?: (string) $event_date,
            'end_date' => trim((string) ($event['endDate'] ?? '')) ?: (string) $event_date,
            'start_key' => trim((string) ($event['startKey'] ?? '')) ?: (string) $event_date,
            'time' => trim((string) ($event['time'] ?? '-')) ?: '-',
            'owner' => trim((string) ($event['owner'] ?? '-')) ?: '-',
        ];
    }
}

uasort($dashboard_vehicle_schedule, static function (array $left, array $right): int {
    $left_key = (string) ($left['start_key'] ?? '');
    $right_key = (string) ($right['start_key'] ?? '');

    if ($left_key === $right_key) {
        return strcmp((string) ($left['time'] ?? ''), (string) ($right['time'] ?? ''));
    }

    return strcmp($left_key, $right_key);
});

$dashboard_vehicle_schedule = array_values($dashboard_vehicle_schedule);

$present_schedule = [];
for ($mock_p = 1; $mock_p <= 22; $mock_p++) {
    $present_schedule[] = [
        'title'      => 'กข-' . $mock_p . ' นนทบุรี',
        'start_date' => '2026-06-30',
        'end_date'   => '2026-06-30',
        'time'       => '09:00 - 12:00 น.',
        'owner'      => 'ฝ่ายไอที รายการที่ ' . $mock_p
    ];
}

$history_schedule = [];
for ($mock_i = 1; $mock_i <= 35; $mock_i++) {
    $history_schedule[] = [
        'title'      => 'กจ-' . $mock_i . ' กรุงเทพฯ',
        'start_date' => '2026-06-' . sprintf('%02d', $mock_i),
        'end_date'   => '2026-06-' . sprintf('%02d', $mock_i),
        'time'       => '08:30 - 16:30 น.',
        'owner'      => 'ผู้จำลองคนที่ ' . $mock_i
    ];
}

ob_start();
?>

<style>
    .modal-header {
        margin: 0 40px;
    }

    .modal-title {
        color: var(--color-secondary);
    }

    .close-modal-btn {
        color: var(--color-secondary);
    }

    .custom-table td {
        text-align: start !important;
    }

    .custom-table th:nth-child(3) {
        width: 15% !important;
    }

    .custom-table th:nth-child(4) {
        border-right: 3px solid var(--color-neutral-lightest);
    }

    #event-modal-overlay .custom-table th:nth-child(3) {
        width: 50% !important;
    }

    #room-booking-section .custom-table th:nth-child(5) {
        width: 20%;
    }

    #room-booking-section .custom-table th:nth-child(4) {
        width: 0;
    }

    .notification-list ul li a.dashboard-notification-link {
        color: inherit;
        text-decoration: none;
    }

    .notification-list ul li a.dashboard-notification-link b {
        color: var(--color-danger);
    }

    .file-banner {
        max-width: 400px;
        min-width: 400px;
    }

    .tox-tinymce {
        width: 100%;
    }
    
    #vehicle-driving-section .custom-table th:nth-child(4) {
        border-right: none;
    }
    
    #vehicle-driving-section-present .custom-table th:nth-child(4) {
        border-right: none;
    }
    
    .tabs-container.setting-page, .tabs-container.setting-page .button-container.vehicle {
        margin: 0;
    }
    
    .tabs-container.setting-page {
        margin-bottom: 20px;
    }

    @media screen and (max-width: 768px) {
        .file-list {
            margin: 5px 0;
        }

        .file-banner {
            max-width: 250px;
            min-width: 250px;
        }
    }

    @media (max-width: 1023px) {
        .modal-header {
            margin: 0;
        }
    }
    
    .booking-section.tab-content {
        display: none;
    }
    .booking-section.tab-content.active {
        display: block;
    }
</style>

<div class="dashboard-container">
    <div class="notification-system">
        <section>
            <div class="notification-list">
                <ul>
                    <?php if ($dashboard_vehicle_schedule !== []): ?>
                        <li onclick="openVehicleDetail()">คุณมีตารางขับรถ</li>
                    <?php endif; ?>
                    <?php if ($dashboard_notifications === [] && $dashboard_vehicle_schedule === []): ?>
                        <li>ไม่พบรายการเอกสารที่ต้องดำเนินการ</li>
                    <?php else: ?>
                        <?php foreach ($dashboard_notifications as $notification): ?>
                            <?php
                            $notification_label = (string) ($notification['label'] ?? '-');
                            $notification_href = trim((string) ($notification['href'] ?? ''));
                            $notification_message = trim((string) ($notification['message'] ?? 'ที่ยังไม่อ่าน'));
                            ?>
                            <li>คุณมี <?php if ($notification_href !== ''): ?><a class="dashboard-notification-link" href="<?= h($notification_href) ?>"><?php endif; ?><b><?= h($notification_label) ?></b><?php if ($notification_href !== ''): ?></a><?php endif; ?>
                                <?php if ($notification_message !== ''): ?><?= h($notification_message) ?><?php endif; ?>
                                <b><?= h((string) ((int) ($notification['count'] ?? 0))) ?></b> ฉบับ
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <a href="#news-paper">
                <img src="public/assets/img/icon/news-paper.png" alt="">
                <p>กดเพื่อดูข่าวประชาสัมพันธ์</p>
            </a>
        </section>
        <main class="profile-section">
            <?php
            $profile_picture_raw = trim((string) ($dashboard_user['picture'] ?? ''));
            $profile_picture = '';

            if ($profile_picture_raw !== '' && strtoupper($profile_picture_raw) !== 'EMPTY') {
                $profile_picture = $profile_picture_raw;
            }
            ?>
            <section>
                <div class="profile-image">
                    <?php if ($profile_picture !== ''): ?>
                        <img src="<?= h($profile_picture) ?>" alt="Profile image">
                    <?php else: ?>
                        <i class="fa-solid fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="proflie-text">
                    <p><b>ชื่อ : <?= h($dashboard_name !== '' ? $dashboard_name : '-') ?></b></p>
                    <p>ตำแหน่ง : <?= h($dashboard_position !== '' ? $dashboard_position : '-') ?></p>
                    <p>หน้าที่ : <?= h($dashboard_role !== '' ? $dashboard_role : '-') ?></p>
                </div>
            </section>
            <div class="profile-date">
                <i class="fa-solid fa-clock"></i>
                <p><?= h($dashboard_current_date_label !== '' ? $dashboard_current_date_label : '-') ?></p>
            </div>
        </main>
    </div>

    <main class="panel-system">
        <section>
            <div class="dashboard-header">
                <p><strong>แผนผังระบบสำนักงานอิเล็กทรอนิกส์</strong></p>
            </div>

            <div class="dashboard-content">
                <?php if ($dashboard_can_manage_external_circular): ?>
                    <a href="outgoing-receive.php">
                        <div class="card-shortcut">
                            <img src="public/assets/img/icon/member.png" alt="">
                            <p><strong>ลงทะเบียนรับ</strong></p>
                        </div>
                    </a>
                    <a href="outgoing.php">
                        <div class="card-shortcut">
                            <img src="public/assets/img/icon/clipboard.png" alt="">
                            <p><strong>ออกเลขทะเบียนส่ง</strong></p>
                        </div>
                    </a>
                <?php endif; ?>
                <a href="memo.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/memo.png" alt="">
                        <p><strong>บันทึกข้อความ</strong></p>
                    </div>
                </a>
                <a href="circular-compose.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/envelope.png" alt="">
                        <p><strong>ส่งหนังสือเวียน</strong></p>
                    </div>
                </a>
                <a href="orders-create.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/files.png" alt="">
                        <p><strong>คำสั่งราชการ</strong></p>
                    </div>
                </a>
                <a href="vehicle-reservation.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/car.png" alt="">
                        <p><strong>การจองพาหนะ</strong></p>
                    </div>
                </a>
                <a href="room-booking.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/building.png" alt="">
                        <p><strong>การจองสถานที่/ห้อง</strong></p>
                    </div>
                </a>
                <a href="repairs.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/repair.png" alt="">
                        <p><strong>แจ้งเหตุซ่อมแซม</strong></p>
                    </div>
                </a>
                <a href="certificates.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/certificate.png" alt="">
                        <p><strong>ออกเลขเกียรติบัตร</strong></p>
                    </div>
                </a>
                <a href="teacher-phone-directory.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/phone.png" alt="">
                        <p><strong>สมุดโทรศัพท์</strong></p>
                    </div>
                </a>
                <a href="profile.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/user.png" alt="">
                        <p><strong>โปรไฟล์</strong></p>
                    </div>
                </a>
                <a href="setting.php">
                    <div class="card-shortcut">
                        <img src="public/assets/img/icon/setting.png" alt="">
                        <p><strong>การตั้งค่า</strong></p>
                    </div>
                </a>
            </div>
        </section>

        <section>
            <div class="dashboard-header" id="news-paper">
                <img src="public/assets/img/icon/news-paper.png" alt="">
                <p><strong>ข่าวประชาสัมพันธ์ และตารางนัดหมาย</strong></p>
            </div>

            <aside class="container-notification-section dashboard">
                <div class="news-bar">
                    <div class="details-news-bar">
                        <ul>
                            <?php if ($dashboard_announcements === []) : ?>
                                <li>
                                    <p>ยังไม่มีข่าวประชาสัมพันธ์</p>
                                </li>
                            <?php else : ?>
                                <?php foreach ($dashboard_announcements as $announcement) : ?>
                                    <?php
                                    $announcement_id = (int) ($announcement['announcementID'] ?? 0);
                                    $circular_id = (int) ($announcement['circularID'] ?? 0);
                                    $payload_key = $announcement_id > 0 ? (string) $announcement_id : 'circular-' . (string) $circular_id;
                                    $announcement_title = trim((string) ($announcement['subject'] ?? ''));
                                    if ($announcement_title === '') {
                                        $announcement_title = 'ข่าวประชาสัมพันธ์';
                                    }
                                    ?>
                                    <li>
                                        <p class="js-open-order-view-modal" role="button" tabindex="0" data-announcement-id="<?= h($payload_key) ?>"><?= h($announcement_title) ?></p>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="container-calendar">
                    <div class="calendar">
                        <div class="header-calendar">
                            <div class="month-year" id="month-year"></div>
                            <div class="interact-button-calendar">
                                <button id="prev-btn">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>
                                <button id="next-btn">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>

                        <div class="days-calendar">
                            <div class="day">อา</div>
                            <div class="day">จ</div>
                            <div class="day">อ</div>
                            <div class="day">พ</div>
                            <div class="day">พฤ</div>
                            <div class="day">ศ</div>
                            <div class="day">ส</div>
                        </div>

                        <div class="dates-calendar" id="dates-calendar"></div>
                    </div>
                </div>
                <textarea id="roomBookingEventsData" class="hidden" aria-hidden="true"><?= h($dashboard_calendar_events_json) ?></textarea>
            </aside>
        </section>
    </main>

    <div id="event-modal-overlay" class="modal-overlay hidden">
        <div class="modal-content">
            <header class="modal-header">
                <div class="modal-title">
                    <span id="modal-date-title">วันที่ ...</span>
                </div>
                <div class="close-modal-btn">
                    <i class="fa-solid fa-xmark" id="close-modal-btn"></i>
                </div>
            </header>

            <div class="modal-body">
                <div id="room-booking-section" class="booking-section">
                    <h4 class="section-title">ตารางการจองห้องประชุม</h4>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>ห้อง</th>
                                    <th>เวลา</th>
                                    <th>รายการประชุม</th>
                                    <th>จำนวน</th>
                                    <th>ผู้จองห้อง</th>
                                </tr>
                            </thead>
                            <tbody id="room-table-body"></tbody>
                        </table>
                    </div>
                </div>

                <div id="car-booking-section" class="booking-section">
                    <h4 class="section-title">ตารางการจองรถยนต์</h4>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>ทะเบียนรถ</th>
                                    <th>เวลา</th>
                                    <th>รายละเอียด</th>
                                    <th>ผู้จองรถ</th>
                                </tr>
                            </thead>
                            <tbody id="car-table-body"></tbody>
                        </table>
                    </div>
                </div>

                <div id="no-event-message" class="hidden">
                    ไม่มีรายการจองในวันนี้
                </div>
            </div>
        </div>
    </div>
</div>

<div id="vehicleBookingDetailModal" class="modal-overlay hidden">
    <div class="modal-content">
        <header class="modal-header">
            <div class="modal-title">
                <span id="vehicle-driving-title">ตารางการขับรถของฉัน</span>
            </div>
            <div class="close-modal-btn">
                <i class="fa-solid fa-xmark" id="vehicle-driving-close-btn" data-vehicle-modal-close="vehicleBookingDetailModal" aria-hidden="true"></i>
            </div>
        </header>

        <div class="modal-body modal-overlay-vehicle-edit">
            
            <div class="tabs-container setting-page">
                <div class="button-container vehicle">
                    <button type="button" class="tab-btn active" onclick="switchVehicleTab('present', event)">การจองรถปัจจุบัน</button>
                    <button type="button" class="tab-btn" onclick="switchVehicleTab('history', event)">ประวัติการจอง</button>
                </div>
            </div>
            
            <div id="vehicle-driving-section-present" class="booking-section tab-content active">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ทะเบียนรถ</th>
                                <th>วันที่เริ่มต้น</th>
                                <th>วันที่สิ้นสุด</th>
                                <th>สถานที่</th>
                                <th>ผู้จองรถ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($present_schedule === []): ?>
                                <tr>
                                    <td colspan="5" class="enterprise-empty">ไม่มีรายการขับรถปัจจุบัน</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($present_schedule as $schedule): ?>
                                    <tr>
                                        <td><?= h((string) ($schedule['title'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['start_date'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['end_date'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['time'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['owner'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="vehicle-driving-section-history" class="booking-section tab-content">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ทะเบียนรถ</th>
                                <th>วันที่เริ่มต้น</th>
                                <th>วันที่สิ้นสุด</th>
                                <th>สถานที่</th>
                                <th>ผู้จองรถ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history_schedule === []): ?>
                                <tr>
                                    <td colspan="5" class="enterprise-empty">ไม่มีรายการประวัติการขับรถ</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history_schedule as $schedule): ?>
                                    <tr class="history-row">
                                        <td><?= h((string) ($schedule['title'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['start_date'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['end_date'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['time'] ?? '-')) ?></td>
                                        <td><?= h((string) ($schedule['owner'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="teacher-phone-footer-control">
                <div class="count-text" id="count-text">
                    <p>จำนวน 0 รายการ</p>
                </div>
                <div class="teacher-phone-pagination" id="pagination">
                </div>
            </div>

            <div id="vehicle-driving-empty-message" class="hidden">
                ไม่มีรายการจองในวันนี้
            </div>
        </div>
    </div>
</div>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person js-modal-overlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p id="modalOutgoingViewTitle">รายละเอียดประชาสัมพันธ์</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark js-modal-close-btn"></i>
                </div>
            </div>

            <div class="formal-form">
                <div class="header">
                    <p><span>เรื่อง: </span><span id="dashboardAnnouncementViewSubject">-</span></p>
                </div>
                <div class="formal-row">
                    <div class="group row" id="dashboardAnnouncementViewLink">
                        <label for="">แนบลิ้งก์</label>
                        <span>-</span>
                    </div>
                </div>
                <div class="formal-row">
                    <div class="group row">
                        <label for="" id="dashboardAnnouncementCommentLabel">ความคิดเห็นของรองผู้อำนวยการ</label>
                        <p id="dashboardAnnouncementDirectorComment">-</p>
                    </div>
                </div>

                <hr>

                <section class="sharing-table">
                    <div class="header">
                        <p>ไฟล์เอกสารแนบจากระบบ</p>
                        <a href="#" id="dashboardAnnouncementDownloadAll">ดาวน์โหลดไฟล์ทั้งหมด</a>
                    </div>
                    <div class="table-responsive table-circular-notice-index">
                        <table class="custom-table booking-table">
                            <thead>
                                <tr>
                                    <th>ชื่อไฟล์</th>
                                    <th>จัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="dashboardAnnouncementViewFiles">
                                <tr>
                                    <td colspan="2" class="enterprise-empty">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>

<script>
    if (window.tinymce && typeof window.tinymce.init === 'function') {
        tinymce.init({
            selector: '#memo_editor_compose',
            height: 500,
            menubar: false,
            language: 'th_TH',
            plugins: 'searchreplace autolink directionality visualblocks visualchars image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists wordcount help charmap emoticons',
            toolbar: 'undo redo | fontfamily | fontsize | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | outdent indent |  numlist bullist | forecolor backcolor removeformat | pagebreak | charmap emoticons',
            font_family_formats: 'TH Sarabun New=Sarabun, sans-serif;',
            font_size_formats: '8pt 9pt 10pt 12pt 14pt 16pt 18pt 20pt 22pt 24pt 26pt 36pt 48pt 72pt',
            content_style: `
            @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap');
            body {
                font-family: 'Sarabun', sans-serif;
                font-size: 16pt;
                line-height: 1.5;
                color: #000;
                background-color: #fff;
                padding: 0 20px;
                margin: 0 auto;
            }
            p {
                margin-bottom: 0px;
            }
        `,
            nonbreaking_force_tab: true,
            promotion: false,
            branding: false
        });
    }
</script>

<script type="application/json" id="dashboardAnnouncementPayloads">
    <?= $dashboard_announcement_payload_json ?>
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const payloadElement = document.getElementById('dashboardAnnouncementPayloads');
        const announcementPayloads = (() => {
            try {
                return JSON.parse(payloadElement?.textContent || '{}') || {};
            } catch (error) {
                return {};
            }
        })();
        const modal = document.querySelector('.js-modal-overlay');
        const subjectElement = document.getElementById('dashboardAnnouncementViewSubject');
        const coverList = document.getElementById('dashboardAnnouncementViewCover');
        const attachmentList = document.getElementById('dashboardAnnouncementViewAttachments');
        const fileTableBody = document.getElementById('dashboardAnnouncementViewFiles');
        const downloadAllLink = document.getElementById('dashboardAnnouncementDownloadAll');
        const linkContainer = document.getElementById('dashboardAnnouncementViewLink');
        const directorCommentElement = document.getElementById('dashboardAnnouncementDirectorComment');
        const announcementCommentLabelElement = document.getElementById('dashboardAnnouncementCommentLabel');

        const escapeHtml = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const renderPlainText = (value) => {
            const text = String(value || '').trim();
            return text !== '' ? escapeHtml(text).replace(/\n/g, '<br>') : '-';
        };

        const isCoverFile = (file) => {
            const note = String(file?.fileNote || file?.note || '').trim().toLowerCase();
            return ['cover_file', 'cover_attachments', 'cover', 'lead_file', 'หนังสือนำ'].includes(note);
        };

        const splitFiles = (files) => {
            const normalized = Array.isArray(files) ? files : [];
            const coverFiles = normalized.filter((file) => isCoverFile(file));
            const attachmentFiles = normalized.filter((file) => !isCoverFile(file));

            if (coverFiles.length === 0 && normalized.length > 0) {
                return {
                    coverFiles: [normalized[0]],
                    attachmentFiles: normalized.slice(1),
                };
            }

            return {
                coverFiles,
                attachmentFiles,
            };
        };

        const fileIconClass = (mimeType) => {
            const mime = String(mimeType || '').toLowerCase();

            if (mime.includes('pdf')) {
                return 'fa-file-pdf';
            }

            if (mime.startsWith('image/')) {
                return 'fa-file-image';
            }

            return 'fa-file-lines';
        };

        const fileNoteLabel = (file) => {
            return isCoverFile(file) ? 'ไฟล์หนังสือนำ' : 'ไฟล์เอกสารเพิ่มเติม';
        };

        const renderFiles = (container, files) => {
            if (!container) {
                return;
            }

            const normalized = Array.isArray(files) ? files : [];

            if (normalized.length === 0) {
                container.innerHTML = '<p>-</p>';
                return;
            }

            container.innerHTML = normalized.map((file) => {
                const fileName = String(file?.fileName || '').trim() || 'ไฟล์แนบ';
                const mimeType = String(file?.mimeType || '').trim() || '-';
                const url = String(file?.url || '').trim();
                const actionHtml = url !== '' ?
                    `<div class="file-actions"><a href="${escapeHtml(url)}" target="_blank" rel="noopener"><i class="fa-solid fa-eye" aria-hidden="true"></i></a></div>` :
                    '';

                return `
                    <div class="file-banner">
                        <div class="file-info">
                            <div class="file-icon"><i class="fa-solid ${fileIconClass(mimeType)}" aria-hidden="true"></i></div>
                            <div class="file-text">
                                <span class="file-name">${escapeHtml(fileName)}</span>
                                <span class="file-type">${escapeHtml(mimeType)}</span>
                            </div>
                        </div>
                        ${actionHtml}
                    </div>
                `;
            }).join('');
        };

        const renderAnnouncementFiles = (container, files) => {
            if (!container) {
                return;
            }

            const normalized = Array.isArray(files) ? files : [];

            if (normalized.length === 0) {
                container.innerHTML = '<tr><td colspan="2" class="enterprise-empty">-</td></tr>';
                return;
            }

            container.innerHTML = normalized.map((file) => {
                const fileName = String(file?.fileName || '').trim() || 'ไฟล์แนบ';
                const mimeType = String(file?.mimeType || '').trim();
                const url = String(file?.url || '').trim();
                const downloadUrl = url !== '' ? `${url}${url.includes('?') ? '&' : '?'}download=1` : '';
                const actions = url !== '' ? `
                    <a class="booking-action-btn secondary" href="${escapeHtml(url)}" target="_blank" rel="noopener">
                        <i class="fa-solid fa-eye"></i>
                        <span class="tooltip">ดูไฟล์</span>
                    </a>
                    <a class="booking-action-btn secondary" href="${escapeHtml(downloadUrl)}">
                        <i class="fa-solid fa-download"></i>
                        <span class="tooltip">ดาวน์โหลด</span>
                    </a>
                ` : '-';

                return `
                    <tr>
                        <td>${escapeHtml(fileName)} (${escapeHtml(fileNoteLabel(file))})</td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        };

        const renderDownloadAllLink = (url, files) => {
            if (!downloadAllLink) {
                return;
            }

            const link = String(url || '').trim();
            const hasFiles = Array.isArray(files) && files.length > 0;

            if (link === '' || !hasFiles) {
                downloadAllLink.style.display = 'none';
                downloadAllLink.href = '#';
                return;
            }

            downloadAllLink.style.display = '';
            downloadAllLink.href = link;
        };

        const renderLink = (url) => {
            if (!linkContainer) {
                return;
            }

            const link = String(url || '').trim();
            const label = link !== '' ? escapeHtml(link) : '-';
            const linkMarkup = link !== '' ?
                `<a href="${escapeHtml(link)}" target="_blank" rel="noopener">${label}</a>` :
                '<span>-</span>';

            linkContainer.innerHTML = `<label for="">แนบลิ้งก์</label>${linkMarkup}`;
        };

        const openAnnouncementModal = (payloadKey) => {
            const payload = announcementPayloads[payloadKey] || {};
            const subject = String(payload.subject || '').trim() || 'ข่าวประชาสัมพันธ์';
            const {
                coverFiles,
                attachmentFiles,
            } = splitFiles(payload.files);

            if (subjectElement) {
                subjectElement.innerHTML = renderPlainText(subject);
            }

            renderFiles(coverList, coverFiles);
            renderFiles(attachmentList, attachmentFiles);
            renderAnnouncementFiles(fileTableBody, Array.isArray(payload.files) ? payload.files : []);
            renderDownloadAllLink(payload.downloadAllURL, Array.isArray(payload.files) ? payload.files : []);
            renderLink(payload.linkURL);

            if (announcementCommentLabelElement) {
                announcementCommentLabelElement.textContent = String(payload.announcementCommentLabel || '').trim() || 'ความคิดเห็นของรองผู้อำนวยการ';
            }

            if (directorCommentElement) {
                directorCommentElement.innerHTML = renderPlainText(payload.announcementCommentText || payload.directorCommentText);
            }

            if (modal) {
                modal.style.display = 'flex';
            }
        };

        document.addEventListener('click', (event) => {
            const viewButton = event.target.closest('.js-open-order-view-modal');
            if (viewButton) {
                event.preventDefault();
                openAnnouncementModal(viewButton.getAttribute('data-announcement-id') || '');
            }

            const closeBtn = event.target.closest('.js-modal-close-btn');
            if (closeBtn) {
                const modal = closeBtn.closest('.js-modal-overlay');
                if (modal) {
                    modal.style.display = 'none';
                }
            }

            if (event.target.classList.contains('js-modal-overlay')) {
                event.target.style.display = 'none';
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                const openModals = document.querySelectorAll('.js-modal-overlay[style*="display: flex"]');
                openModals.forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const vehicleModal = document.getElementById('vehicleBookingDetailModal');
        const closeBtn = document.querySelector('[data-vehicle-modal-close="vehicleBookingDetailModal"]');

        window.openVehicleDetail = function() {
            if (vehicleModal) {
                vehicleModal.classList.remove('hidden');
                vehicleCurrentPage = 1; 
                
                initVehiclePagination();
                
                setTimeout(() => {
                    initVehiclePagination();
                }, 80);
            }
        };

        window.closeVehicleDetail = function() {
            if (vehicleModal) {
                vehicleModal.classList.add('hidden');
            }
        };

        if (closeBtn) {
            closeBtn.addEventListener('click', closeVehicleDetail);
        }

        if (vehicleModal) {
            vehicleModal.addEventListener('click', (e) => {
                if (e.target === vehicleModal) {
                    closeVehicleDetail();
                }
            });
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const slider = document.querySelector('.table-circular-notice-index');

        if (!slider) return;

        let isDown = false;
        let startX;
        let scrollLeft;

        slider.addEventListener('mousedown', (e) => {
            isDown = true;
            slider.classList.add('is-dragging');
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener('mouseleave', () => {
            isDown = false;
            slider.classList.remove('is-dragging');
        });

        slider.addEventListener('mouseup', () => {
            isDown = false;
            slider.classList.remove('is-dragging');
        });

        slider.addEventListener('mousemove', (e) => {
            if (!isDown) return;

            e.preventDefault();

            const x = e.pageX - slider.offsetLeft;
            const walk = (x - startX) * 1.5;

            slider.scrollLeft = scrollLeft - walk;
        });
    });
        
    let vehicleCurrentPage = 1;
    const vehicleRowsPerPage = 15;
    let activeTabMode = 'present';

    window.switchVehicleTab = function(mode, event) {
        if (event) event.preventDefault();
        activeTabMode = mode;
        
        const container = document.querySelector('.button-container.vehicle');
        container.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        if (event) {
            event.target.classList.add('active');
        } else {
            const btns = container.querySelectorAll('.tab-btn');
            if (mode === 'present') btns[0].classList.add('active');
            if (mode === 'history') btns[1].classList.add('active');
        }

        document.getElementById('vehicle-driving-section-present').classList.remove('active');
        document.getElementById('vehicle-driving-section-history').classList.remove('active');
        
        if (mode === 'present') {
            document.getElementById('vehicle-driving-section-present').classList.add('active');
        } else {
            document.getElementById('vehicle-driving-section-history').classList.add('active');
        }

        vehicleCurrentPage = 1;
        initVehiclePagination();
    };

    function initVehiclePagination() {
        const activeSectionId = activeTabMode === 'present' ? 'vehicle-driving-section-present' : 'vehicle-driving-section-history';
        const targetTableBody = document.querySelector(`#${activeSectionId} tbody`);
        if (!targetTableBody) return;

        const rows = Array.from(targetTableBody.querySelectorAll('tr:not(.enterprise-empty)'));
        const countText = document.getElementById('count-text');
        const paginationContainer = document.getElementById('pagination');

        if (countText) {
            countText.innerHTML = `<p>จำนวน ${rows.length} รายการ</p>`;
        }

        if (rows.length === 0) {
            if (paginationContainer) paginationContainer.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(rows.length / vehicleRowsPerPage);
        
        const startIndex = (vehicleCurrentPage - 1) * vehicleRowsPerPage;
        const endIndex = startIndex + vehicleRowsPerPage;

        rows.forEach((row, index) => {
            row.style.display = (index >= startIndex && index < endIndex) ? '' : 'none';
        });

        const getRange = () => {
            let start = 1;
            let end = totalPages;
            if (totalPages > 7) {
                if (vehicleCurrentPage <= 4) {
                    end = 5;
                } else if (vehicleCurrentPage >= totalPages - 3) {
                    start = totalPages - 4;
                } else {
                    start = vehicleCurrentPage - 2;
                    end = vehicleCurrentPage + 2;
                }
            }
            return { start, end };
        };

        if (!paginationContainer) return;
        paginationContainer.innerHTML = '';

        if (totalPages <= 1) return;

        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.disabled = vehicleCurrentPage === 1;
        prevBtn.innerHTML = '<i class="fas fa-chevron-left" aria-hidden="true"></i>';
        prevBtn.addEventListener('click', () => {
            if (vehicleCurrentPage > 1) {
                vehicleCurrentPage--;
                initVehiclePagination();
            }
        });
        paginationContainer.appendChild(prevBtn);

        const { start, end } = getRange();

        if (start > 1) {
            paginationContainer.appendChild(createNumBtn(1));
            if (start > 2) {
                const dot = document.createElement('span');
                dot.className = 'enterprise-ellipsis';
                dot.textContent = '...';
                paginationContainer.appendChild(dot);
            }
        }

        for (let i = start; i <= end; i++) {
            paginationContainer.appendChild(createNumBtn(i));
        }

        if (end < totalPages) {
            if (end < totalPages - 1) {
                const dot = document.createElement('span');
                dot.className = 'enterprise-ellipsis';
                dot.textContent = '...';
                paginationContainer.appendChild(dot);
            }
            paginationContainer.appendChild(createNumBtn(totalPages));
        }

        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.disabled = vehicleCurrentPage === totalPages;
        nextBtn.innerHTML = '<i class="fas fa-chevron-right" aria-hidden="true"></i>';
        nextBtn.addEventListener('click', () => {
            if (vehicleCurrentPage < totalPages) {
                vehicleCurrentPage++;
                initVehiclePagination();
            }
        });
        paginationContainer.appendChild(nextBtn);
    }

    function createNumBtn(page) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = page;
        if (page === vehicleCurrentPage) {
            btn.classList.add('active');
        }
        btn.addEventListener('click', () => {
            vehicleCurrentPage = page;
            initVehiclePagination();
        });
        return btn;
    }
    initVehiclePagination();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';