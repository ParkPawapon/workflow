<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$teacher_name = (string) ($teacher_name ?? '');
$dh_year_value = (int) ($dh_year_value ?? 0);
$room_booking_room_list = (array) ($room_booking_room_list ?? []);
$room_booking_total = (int) ($room_booking_total ?? 0);
$room_booking_approved_total = (int) ($room_booking_approved_total ?? 0);
$room_booking_pending_total = (int) ($room_booking_pending_total ?? 0);
$my_booking_subtitle = (string) ($my_booking_subtitle ?? '');
$my_bookings_latest = (array) ($my_bookings_latest ?? []);
$my_bookings_sorted = (array) ($my_bookings_sorted ?? []);
$room_booking_events = (array) ($room_booking_events ?? []);
$booking_alert = $booking_alert ?? null;
$alert = $booking_alert;
$room_booking_selected_room_id = trim((string) ($_POST['roomID'] ?? ''));

$currentThaiYear = (int) date('Y') + 543;

if ($dh_year_value < 2500) {
    $dh_year_value = $currentThaiYear;
}

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
    if ($datetime === '') {
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

$status_labels = [
    0 => ['label' => 'รออนุมัติ', 'class' => 'pending'],
    1 => ['label' => 'อนุมัติ', 'class' => 'approved'],
    2 => ['label' => 'ไม่อนุมัติ', 'class' => 'rejected'],
];

$room_booking_events_json = json_encode($room_booking_events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($room_booking_events_json === false) {
    $room_booking_events_json = '{}';
}

$body_attrs = [
    'data-calendar-mode' => 'room',
    'data-calendar-thai-year' => 'true',
];

ob_start();
?>

<style>
    .content-area .tab-content.active {
        display: grid;
    }

    .modal-header {
        margin: 0 40px;
    }

    .modal-title {
        color: var(--color-secondary);
    }

    .close-modal-btn {
        color: var(--color-secondary);
    }

    .booking-detail-modal .modal-header {
        margin: 0;
    }

    .booking-detail-modal .modal-header div {
        color: var(--color-neutral-lightest);
    }

    .date .event-icons {
        width: 95%;
    }

    @media (max-width: 1023px) {
        .modal-header {
            margin: 0;
        }
    }

    @media screen and (max-width: 1440px) {
        .content-area .tab-content.active {
            grid-template-columns: 1fr;
            gap: 0px;
        }
    }

    @media screen and (min-width: 769px) and (max-width: 1023px) {
        .modal-body {
            padding: 0 20px;
        }
        .custom-table {
            min-width: 900px;
        }
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>จองสถานที่/ห้อง</p>
</div>

<div class="content-area booking-page" data-room-booking data-delete-endpoint="public/api/room-booking-delete.php"
    data-check-endpoint="public/api/room-booking-check.php" data-csrf="<?= h(csrf_token()) ?>">

    <div class="tabs-container setting-page">
        <div class="button-container vehicle">
            <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
                onclick="openTab('booking', event)">สร้างการจอง</button>
            <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>"
                onclick="openTab('bookingMine', event)">การจองของฉัน</button>
        </div>
    </div>

    <div class="booking-layout tab-content active" id="booking">
        <section class="booking-card booking-form-card">
            <div class="booking-card-header">
                <div class="booking-card-title-group">
                    <h2 class="booking-card-title">สร้างรายการจอง</h2>
                </div>
            </div>

            <form class="booking-form" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? 'room-booking.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="dh_year" value="<?= h((string) $dh_year_value) ?>">
                <input type="hidden" name="requesterPID" value="<?= h($_SESSION['pID'] ?? '') ?>">
                <input type="hidden" name="status" value="0">

                <div class="booking-form-grid">
                    <div class="form-group full">
                        <label class="form-label" for="bookingRoom">ห้อง/สถานที่</label>
                        <div class="page-selector">
                            <div class="custom-select-wrapper">
                                <div class="custom-select-trigger">
                                    <p class="select-value">เลือกห้องหรือสถานที่</p>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="custom-options">
                                    <div class="custom-option" data-value="">เลือกห้องหรือสถานที่</div>
                                    <?php foreach ($room_booking_room_list as $room_item): ?>
                                        <?php
                                        $room_id = trim((string) ($room_item['roomID'] ?? ''));

                                        if ($room_id === '') {
                                            continue;
                                        }
                                        $room_name = trim((string) ($room_item['roomName'] ?? ''));
                                        $room_name = $room_name !== '' ? $room_name : $room_id;
                                        ?>
                                        <div class="custom-option" data-value="<?= h($room_id) ?>"><?= h($room_name) ?></div>
                                    <?php endforeach; ?>
                                </div>

                                <select class="form-input" id="bookingRoom" name="roomID" required>
                                    <option value="" <?= $room_booking_selected_room_id === '' ? 'selected' : '' ?> disabled>เลือกห้องหรือสถานที่</option>
                                    <?php foreach ($room_booking_room_list as $room_item): ?>
                                        <?php
                                        $room_id = trim((string) ($room_item['roomID'] ?? ''));

                                        if ($room_id === '') {
                                            continue;
                                        }
                                        $room_name = trim((string) ($room_item['roomName'] ?? ''));
                                        $room_name = $room_name !== '' ? $room_name : $room_id;
                                        ?>
                                        <option value="<?= h($room_id) ?>" <?= $room_booking_selected_room_id === $room_id ? 'selected' : '' ?>>
                                            <?= h($room_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bookingStartDate">วันที่เริ่มใช้</label>
                        <input class="form-input" type="date" id="bookingStartDate" name="startDate" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">ช่วงเวลา</label>
                        <!-- <input class="form-input" type="time" name="startTime" required> -->
                        <div class="tp-wrapper" data-time-target="startTime">
                            <input id="roomStartTimeDisplay" class="tp-input" readonly value="00:00">
                            <div class="tp-dropdown" id="roomStartTimeDropdown">
                                <div class="tp-list">
                                    <div class="tp-col tp-hour"></div>
                                    <div class="tp-col tp-minute"></div>
                                </div>
                            </div>
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <input type="hidden" id="startTime" name="startTime" value="">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="bookingEndDate">วันที่สิ้นสุด</label>
                        <input class="form-input" type="date" id="bookingEndDate" name="endDate">
                    </div>

                    <div class="form-group">
                        <label class="form-label">ถึง</label>
                        <!-- <input class="form-input" type="time" name="endTime" required> -->
                        <div class="tp-wrapper" data-time-target="endTime">
                            <input id="roomEndTimeDisplay" class="tp-input" readonly value="00:00">
                            <div class="tp-dropdown" id="roomEndTimeDropdown">
                                <div class="tp-list">
                                    <div class="tp-col tp-hour"></div>
                                    <div class="tp-col tp-minute"></div>
                                </div>
                            </div>
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <input type="hidden" id="endTime" name="endTime" value="">
                    </div>

                    <div class="form-group full">
                        <label class="form-label" for="bookingCapacity">จำนวนผู้เข้าร่วม</label>
                        <input class="form-input" type="number" id="bookingCapacity" name="attendeeCount" min="1"
                            placeholder="ระบุจำนวนคน" required>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="bookingTopic">หัวข้อการจอง</label>
                        <input class="form-input" type="text" id="bookingTopic" name="bookingTopic"
                            placeholder="เช่น ประชุมกลุ่มสาระ/อบรม" required>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="bookingDetail">รายละเอียด/วัตถุประสงค์</label>
                        <textarea class="form-input booking-textarea" id="bookingDetail" name="bookingDetail" rows="4"
                            placeholder="ระบุรายละเอียดการใช้งาน" required></textarea>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="bookingEquipment">อุปกรณ์ที่ต้องการ</label>
                        <textarea class="form-input booking-textarea" id="bookingEquipment" name="equipmentDetail" rows="3"
                            placeholder="โปรเจคเตอร์, ไมโครโฟน" required></textarea>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="bookingOwner">ผู้จองสถานที่ / ห้อง</label>
                        <input class="form-input" type="text" id="bookingOwner" name="requesterDisplayName"
                            value="<?= h($teacher_name) ?>" readonly>
                    </div>
                </div>

                <div class="booking-actions">
                    <button type="submit" class="btn-outline" name="room_booking_check" value="1">ตรวจสอบเวลาว่าง</button>
                    <button
                        type="submit"
                        class="btn-confirm"
                        name="room_booking_save"
                        value="1"
                        data-confirm="ยืนยันการบันทึกการจองใช่หรือไม่?"
                        data-confirm-title="ยืนยันการบันทึก"
                        data-confirm-ok="ยืนยัน"
                        data-confirm-cancel="ยกเลิก">บันทึกการจอง</button>
                </div>

            </form>
        </section>

        <section class="booking-card booking-calendar-card">
            <div class="booking-card-header">
                <div class="booking-card-title-group">
                    <h2 class="booking-card-title">ปฏิทินการจอง</h2>
                </div>
            </div>

            <div class="booking-calendar">
                <div class="container-calendar">
                    <div class="calendar">
                        <div class="header-calendar">
                            <div class="month-year" id="month-year"></div>
                            <div class="interact-button-calendar">
                                <button id="prev-btn" type="button">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>
                                <button id="next-btn" type="button">
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
            </div>
        </section>
    </div>

    <section class="booking-card booking-list-card booking-list-row tab-content" id="bookingMine">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">รายการจองของฉัน</h2>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table booking-table">
                <thead>
                    <tr>
                        <th>ห้อง</th>
                        <th>ช่วงเวลาที่ใช้</th>
                        <th>รายการ</th>
                        <th>จำนวน</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_bookings_sorted)) : ?>
                        <tr>
                            <td colspan="6" class="booking-empty">ยังไม่มีรายการจอง</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($my_bookings_sorted as $booking_item) : ?>
                            <?php
                            $status_value = (int) ($booking_item['status'] ?? 0);
                            $status_label = $status_labels[$status_value]['label'] ?? $status_labels[0]['label'];
                            $status_class = $status_labels[$status_value]['class'] ?? $status_labels[0]['class'];
                            $detail_text = trim((string) ($booking_item['bookingDetail'] ?? ''));
                            $detail_text = $detail_text !== '' ? $detail_text : 'ไม่มีรายละเอียดเพิ่มเติม';
                            $equipment_text = trim((string) ($booking_item['equipmentDetail'] ?? ''));
                            $equipment_text = $equipment_text !== '' ? $equipment_text : 'ไม่มีอุปกรณ์เพิ่มเติม';
                            $requester_name = trim((string) ($booking_item['requesterName'] ?? ''));
                            $requester_name = $requester_name !== '' ? $requester_name : '-';
                            // Requester view: show only status (do not display rejection reason).
                            $status_reason_label = '-';
                            $approver_name = trim((string) ($booking_item['approvedByName'] ?? ''));

                            if ($approver_name === '' && !empty($booking_item['approvedByPID'])) {
                                $approver_name = 'เจ้าหน้าที่ระบบ';
                            }
                            $approval_label = $status_value === 2 ? 'ผู้ไม่อนุมัติ' : 'ผู้อนุมัติ';
                            $approval_name = $status_value === 0 ? 'รอการอนุมัติ' : ($approver_name !== '' ? $approver_name : 'เจ้าหน้าที่ระบบ');
                            $approval_time = $format_thai_datetime((string) ($booking_item['approvedAt'] ?? ''));

                            if ($approval_time === '-' || $approval_time === '') {
                                $approval_at_label = '-';
                            } else {
                                $approval_at_label = ($status_value === 2 ? 'ไม่อนุมัติเมื่อ ' : 'อนุมัติเมื่อ ') . $approval_time;
                            }
                            $date_range = $format_thai_date_range(
                                (string) ($booking_item['startDate'] ?? ''),
                                (string) ($booking_item['endDate'] ?? '')
                            );
                            $time_range = trim((string) ($booking_item['startTime'] ?? '') . '-' . (string) ($booking_item['endTime'] ?? ''));
                            $created_label = $format_thai_datetime((string) ($booking_item['createdAt'] ?? ''));
                            $updated_label = $format_thai_datetime((string) ($booking_item['updatedAt'] ?? ''));
                            ?>
                            <tr>
                                <td><?= h($booking_item['roomName'] ?? '-') ?></td>
                                <td>
                                    <?= h($date_range) ?><br>
                                    <span class="detail-subtext"><?= h($time_range !== '' ? $time_range : '-') ?></span>
                                </td>
                                <td><?= h($booking_item['bookingTopic'] ?? 'ประชุม/อบรม') ?></td>
                                <td><?= h((string) ($booking_item['attendeeCount'] ?? '-')) ?></td>
                                <td>
                                    <span class="status-pill <?= h($status_class) ?>"><?= h($status_label) ?></span>
                                </td>
                                <td class="booking-action-cell">
                                    <div class="booking-action-group">
                                        <button type="button" class="booking-action-btn secondary" data-booking-action="detail"
                                            data-booking-id="<?= h((string) ($booking_item['roomBookingID'] ?? '')) ?>"
                                            data-booking-room="<?= h($booking_item['roomName'] ?? '-') ?>"
                                            data-booking-date="<?= h($date_range) ?>"
                                            data-booking-time="<?= h($time_range) ?>"
                                            data-booking-topic="<?= h($booking_item['bookingTopic'] ?? 'ประชุม/อบรม') ?>"
                                            data-booking-detail="<?= h($detail_text) ?>"
                                            data-booking-equipment="<?= h($equipment_text) ?>"
                                            data-booking-attendees="<?= h((string) ($booking_item['attendeeCount'] ?? '-')) ?>"
                                            data-booking-requester="<?= h($requester_name) ?>"
                                            data-booking-status="<?= h((string) $status_value) ?>"
                                            data-booking-status-label="<?= h($status_label) ?>"
                                            data-booking-status-class="<?= h($status_class) ?>"
                                            data-booking-status-reason="<?= h($status_reason_label) ?>"
                                            data-booking-approval-label="<?= h($approval_label) ?>"
                                            data-booking-approval-name="<?= h($approval_name) ?>"
                                            data-booking-approval-at="<?= h($approval_at_label) ?>"
                                            data-booking-created="<?= h($created_label) ?>"
                                            data-booking-updated="<?= h($updated_label) ?>">
                                            <i class="fa-solid fa-eye"></i>
                                            <span class="tooltip">ดูรายละเอียด</span>
                                        </button>
                                        <?php if ($status_value === 0): ?>
                                            <button type="button" class="booking-action-btn danger" data-booking-action="delete"
                                                data-booking-id="<?= h((string) ($booking_item['roomBookingID'] ?? '')) ?>">
                                                <i class="fa-solid fa-trash"></i>
                                                <span class="tooltip danger">ลบข้อมูลการจอง</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div id="bookingDetailModal" class="modal-overlay hidden">
    <div class="modal-content booking-detail-modal">
        <header class="modal-header">
            <div class="modal-title">
                <span>รายละเอียดการจอง</span>
            </div>
            <div>
                <span class="status-pill" data-booking-detail="status">-</span>
                <div class="close-modal-btn" data-booking-modal-close="bookingDetailModal">
                    <i class="fa-solid fa-xmark"></i>
                </div>
            </div>
        </header>
        <div class="modal-body booking-detail-body">
            <div class="booking-detail-row">
                <div class="booking-detail-content">
                    <label>ห้อง/สถานที่</label>
                    <input type="text" data-booking-detail="room" placeholder="-" disabled>
                </div>
                <div class="booking-detail-content">
                    <label>วันที่ใช้</label>
                    <input type="text" data-booking-detail="date" placeholder="-" disabled>
                </div>
            </div>

            <div class="booking-detail-row">
                <div class="booking-detail-content">
                    <label>เวลา</label>
                    <input type="text" data-booking-detail="time" placeholder="-" disabled>
                </div>
                <div class="booking-detail-content">
                    <label>จำนวนผู้เข้าร่วม</label>
                    <input type="text" data-booking-detail="attendees" placeholder="-" disabled>
                </div>
                <div class="booking-detail-content">
                    <label>ผู้จองสถานที่ / ห้อง</label>
                    <input type="text" data-booking-detail="requester" placeholder="-" disabled>
                </div>
            </div>

            <div class="booking-detail-row">
                <div class="booking-detail-content">
                    <label>หัวข้อการจอง</label>
                    <input type="text" data-booking-detail="topic" placeholder="-" disabled>
                </div>
            </div>

            <div class="booking-detail-row">
                <div class="booking-detail-content">
                    <label>รายละเอียด/วัตถุประสงค์</label>
                    <textarea class="form-input booking-textarea" data-booking-detail="detail" rows="4" placeholder="-" disabled></textarea>
                </div>
            </div>

            <div class="booking-detail-row">
                <div class="booking-detail-content">
                    <label>อุปกรณ์ที่ต้องการ</label>
                    <textarea class="form-input booking-textarea" data-booking-detail="equipment" rows="3" placeholder="-" disabled></textarea>
                </div>
            </div>

            <div class="booking-detail-row">
                <div class="booking-detail-content">
                    <label>สร้างรายการเมื่อ</label>
                    <input type="text" data-booking-detail="created" placeholder="-" disabled>
                </div>
            </div>

            <div class="booking-detail-row hidden" data-booking-detail="approval-item">
                <div class="booking-detail-content">
                    <label data-booking-detail="approval-label">ผู้อนุมัติ</label>
                    <input type="text" data-booking-detail="approval-name" placeholder="-" disabled>
                </div>
                <div class="booking-detail-content">
                    <label>วันที่ดำเนินการ</label>
                    <input type="text" data-booking-detail="approval-at" placeholder="-" disabled>
                </div>
            </div>

        </div>
        <!-- <div class="booking-detail-actions" style="flex-grow: 1; align-items: flex-end;">
            <button type="button" class="booking-action-btn" data-booking-modal-close="bookingDetailModal">ปิดหน้าต่าง</button>
        </div> -->
    </div>
</div>

<div id="event-modal-overlay" class="modal-overlay hidden">
    <div class="modal-content">
        <header class="modal-header">
            <div class="modal-title">
                <!-- <i class="fa-regular fa-calendar-days"></i> -->
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
                ไม่มีรายการจองห้องในวันนี้
            </div>
        </div>
    </div>
</div>

<textarea id="roomBookingEventsData" class="hidden" aria-hidden="true"><?= h($room_booking_events_json) ?></textarea>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
