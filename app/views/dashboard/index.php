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
$unread_internal_circulars = (int) ($dashboard_counts['unread_internal_circulars'] ?? 0);
$unread_memos = (int) ($dashboard_counts['unread_memos'] ?? 0);
$unread_orders = (int) ($dashboard_counts['unread_orders'] ?? 0);
$room_notifications = (int) ($dashboard_counts['room_notifications'] ?? 0);
$vehicle_notifications = (int) ($dashboard_counts['vehicle_notifications'] ?? $dashboard_counts['unread_vehicle_bookings'] ?? 0);
$repair_notifications = (int) ($dashboard_counts['repair_notifications'] ?? 0);

if ($dashboard_is_admin) {
    $room_notifications = 0;
    $vehicle_notifications = 0;
    $repair_notifications = 0;
}

$external_circular_href = 'outgoing-notice.php?box=clerk&type=external&read=all&sort=newest&view=table1';

if (!empty($dashboard_access['is_director_or_acting']) && empty($dashboard_access['can_manage_external_circular'])) {
    $external_circular_href = 'outgoing-notice.php?box=director&type=external&read=all&sort=newest&view=table1';
}

$dashboard_notifications = array_values(array_filter([
    [
        'label' => 'หนังสือเวียน',
        'count' => $unread_external_circulars,
        'href' => $external_circular_href,
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

    @media (max-width: 1023px) {
        .modal-header {
            margin: 0;
        }
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
                            ?>
                            <li>คุณมี <?php if ($notification_href !== ''): ?><a class="dashboard-notification-link" href="<?= h($notification_href) ?>"><?php endif; ?><b><?= h($notification_label) ?></b><?php if ($notification_href !== ''): ?></a><?php endif; ?>
                                <?= h((string) ($notification['message'] ?? 'ที่ยังไม่อ่าน')) ?>
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
                                    $announcement_title = trim((string) ($announcement['subject'] ?? ''));
                                    if ($announcement_title === '') {
                                        $announcement_title = 'ข่าวประชาสัมพันธ์';
                                    }
                                    ?>
                                    <li>
                                        <p><?= h($announcement_title) ?></p>
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
                <textarea id="roomBookingEventsData" class="hidden"
                    aria-hidden="true"><?= h($dashboard_calendar_events_json) ?></textarea>

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
            <div id="vehicle-driving-section" class="booking-section">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ทะเบียนรถ</th>
                                <th>วันที่เริ่มต้น</th>
                                <th>วันที่สิ้นสุด</th>
                                <th>เวลา</th>
                                <th>ผู้จองรถ</th>
                            </tr>
                        </thead>
                        <tbody id="vehicle-driving-table-body">
                            <?php if ($dashboard_vehicle_schedule === []): ?>
                                <tr>
                                    <td colspan="5" class="enterprise-empty">ไม่มีรายการขับรถ</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dashboard_vehicle_schedule as $schedule): ?>
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

            <div id="vehicle-driving-empty-message" class="hidden">
                ไม่มีรายการจองในวันนี้
            </div>

        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const vehicleModal = document.getElementById('vehicleBookingDetailModal');

        const closeBtn = document.querySelector('[data-vehicle-modal-close="vehicleBookingDetailModal"]');

        window.openVehicleDetail = function () {
            if (vehicleModal) {
                vehicleModal.classList.remove('hidden');
            }
        };

        window.closeVehicleDetail = function () {
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
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
