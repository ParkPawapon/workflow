<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$teacher_name = (string) ($teacher_name ?? '');
$dh_year_value = (int) ($dh_year_value ?? 0);
$requester_pid = (string) ($requester_pid ?? '');
$today = (string) ($today ?? date('Y-m-d'));
$vehicle_departments = (array) ($vehicle_departments ?? []);
$vehicle_factions = (array) ($vehicle_factions ?? []);
$vehicle_teachers = (array) ($vehicle_teachers ?? []);
$vehicle_booking_history = (array) ($vehicle_booking_history ?? []);
$vehicle_booking_payload = (array) ($vehicle_booking_payload ?? []);
$vehicle_reservation_status_labels = (array) ($vehicle_reservation_status_labels ?? []);
$format_thai_date_range = $format_thai_date_range ?? null;
$format_thai_datetime = $format_thai_datetime ?? null;
$format_thai_datetime_range = $format_thai_datetime_range ?? null;
$vehicle_reservation_alert = $vehicle_reservation_alert ?? null;
$alert = $vehicle_reservation_alert;

ob_start();
?>

<style>
    .fa-solid.fa-xmark {
        cursor: pointer;
    }

    .vehicle-input-content {
        justify-content: space-between;
    }

    .vehicle-content .vehicle-companion-control .go-with-dropdown,
    .modal-overlay-vehicle-edit .vehicle-companion-control .go-with-dropdown {
        width: 100%;
        max-width: 100%;
        flex: 0 1 100%;
    }

    .vehicle-row.split {
        grid-template-columns: repeat(2, 1fr);
    }

    .vehicle-detail-form hr {
        background-color: var(--color-secondary);
        height: 4px;
        border: none;
        margin: 0 0 40px;
    }

    .sec-header {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .booking-table td:nth-child(3) {
        text-align: start;
        max-width: 450px;
        text-wrap: inherit;
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(3) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .table-circular-notice-index table tbody td:nth-child(2),
    .table-circular-notice-index table thead th:nth-child(5),
    .table-circular-notice-index table tbody td:nth-child(5) {
        text-align: start !important;
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .booking-table td:nth-child(1) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .booking-table td:nth-child(2) {
        width: 200px !important;
        min-width: 200px !important;
        max-width: 200px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3),
    .booking-table td:nth-child(3) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-index table thead th:nth-child(4),
    .booking-table td:nth-child(4) {
        width: 500px !important;
        min-width: 500px !important;
        max-width: 500px !important;
    }

    .table-circular-notice-index table thead th:nth-child(5),
    .booking-table td:nth-child(5) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    @media screen and (max-width: 1024px) {

        .table-circular-notice-index table thead th:nth-child(1),
        .table-circular-notice-index table tbody td:nth-child(1),
        .table-circular-notice-index table tbody td:nth-child(3) {
            text-align: center !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .table-circular-notice-index table tbody td:nth-child(2),
        .table-circular-notice-index table thead th:nth-child(5),
        .table-circular-notice-index table tbody td:nth-child(5) {
            text-align: start !important;
        }

        .table-circular-notice-index table thead th:nth-child(1),
        .booking-table td:nth-child(1) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .booking-table td:nth-child(2) {
            width: 180px !important;
            min-width: 180px !important;
            max-width: 180px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3),
        .booking-table td:nth-child(3) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4),
        .booking-table td:nth-child(4) {
            width: 500px !important;
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-index table thead th:nth-child(5),
        .booking-table td:nth-child(5) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>การจองยานพาหนะ / บันทึกการจองยานพาหนะ</p>
</div>

<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn active"
            onclick="openTab('vehicleReservationForm', event)">จองยานพาหนะ</button>
        <button class="tab-btn" onclick="openTab('vehicleHistory', event)">รายการจองของฉัน</button>
    </div>
</div>

<div class="vehicle-content">
    <form id="vehicleReservationForm" class="tab-content active" method="post"
        action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>"
        enctype="multipart/form-data" data-vehicle-form>
        <?= csrf_field() ?>
        <input type="hidden" name="dh_year"
            value="<?= htmlspecialchars((string) $dh_year_value, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="requesterPID"
            value="<?= htmlspecialchars($requester_pid, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="vehicle_reservation_save" value="1">
        <input type="hidden" name="companionCount" id="companionCount" value="0">

        <div class="vehicle-row split">
            <div class="vehicle-input-content">
                <label>ส่วนราชการ</label>
                <div class="custom-select-wrapper" id="dept-wrapper">
                    <input type="hidden" id="department" name="department" value="">

                    <div class="custom-select-trigger">
                        <span class="select-value">เลือกส่วนราชการ</span>
                        <i class="fa-solid fa-chevron-down arrow"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($vehicle_departments as $dept): ?>
                            <span class="custom-option"
                                data-value="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                        <?php foreach ($vehicle_factions as $faction): ?>
                            <span class="custom-option"
                                data-value="<?= htmlspecialchars($faction['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($faction['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="form-error hidden" id="departmentError">กรุณาเลือกส่วนราชการ</p>
            </div>

            <div class="vehicle-input-content">
                <label for="writeDate">วันที่เขียน</label>
                <input type="date" id="writeDate" name="writeDate"
                    value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
        </div>

        <div class="vehicle-row split">
            <div class="vehicle-input-content">
                <label>ข้าพเจ้าพร้อมด้วย</label>
                <div class="vehicle-companion-control">
                    <div class="go-with-dropdown">
                        <input type="text" id="searchInput" placeholder="ค้นหารายชื่อคุณครู" autocomplete="off"
                            onkeyup="filterDropdown()" onclick="openDropdown()" />

                        <div id="myDropdown" class="go-with-dropdown-content">
                            <?php foreach ($vehicle_teachers as $teacher_item): ?>
                                <label class="dropdown-item">
                                    <input type="checkbox"
                                        name="companionIds[]"
                                        value="<?= htmlspecialchars($teacher_item['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-name="<?= htmlspecialchars($teacher_item['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <p><?= htmlspecialchars($teacher_item['name'], ENT_QUOTES, 'UTF-8') ?></p>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="vehicle-input-content">
                <label>&nbsp;</label>
                <button class="show-member" type="button">
                    <p>แสดงผู้เดินทางทั้งหมด</p>
                </button>
            </div>
        </div>

        <div class="vehicle-row">
            <div class="vehicle-input-content">
                <label for="otherPassengerCount">จำนวนบุคลากรอื่นๆ</label>
                <input type="number" id="otherPassengerCount" name="otherPassengerCount" min="0" step="1" value="0"
                    inputmode="numeric" placeholder="ระบุจำนวนบุคลากรอื่นๆ">
            </div>
        </div>

        <div class="vehicle-row">
            <div class="vehicle-input-content">
                <label for="otherPassengerNames">รายชื่อบุคลากร</label>
                <textarea id="otherPassengerNames" name="otherPassengerNames" rows="3"
                    placeholder="พิมพ์รายชื่อบุคลากรอื่นๆ ตัวอย่าง นายภวพล ธรรมลังกา, นายรัชพล ธูปทอง"></textarea>
            </div>
        </div>

        <div id="memberModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="member-header">
                    <p>รายชื่อผู้เดินทางที่เลือก</p>
                    <i class="fa-solid fa-xmark close-modal"></i>
                </div>
                <div id="selectedMemberList" class="member-list-container">
                </div>
            </div>
        </div>

        <div class="vehicle-row">
            <div class="vehicle-input-content">
                <label for="purpose">ขออนุญาตใช้รถเพื่อ</label>
                <textarea id="purpose" name="purpose" rows="5" placeholder="ระบุวัตถุประสงค์" required></textarea>
            </div>
        </div>

        <div class="vehicle-row split">
            <div class="vehicle-input-content">
                <label for="location">ณ (สถานที่)</label>
                <input type="text" id="location" name="location" placeholder="ระบุสถานที่ปลายทาง" required>
            </div>

            <div class="vehicle-input-content">
                <label>มีคนนั่งจำนวนทั้งสิ้น</label>
                <div class="calculated-field" id="passengerCountDisplay">
                    <i class="fa-solid fa-users" aria-hidden="true"></i>
                    <p data-passenger-count aria-live="polite">1 คน</p>
                </div>
                <input type="hidden" id="passengerCount" name="passengerCount" value="1">
            </div>
        </div>

        <div class="vehicle-row split">
            <div class="vehicle-input-content">
                <label for="startDate">ในวันที่</label>
                <input type="date" id="startDate" name="startDate" required>
            </div>

            <div class="vehicle-input-content">
                <label for="startTime">เวลาเริ่มต้น</label>
                <!-- <input type="time" id="startTime" name="startTime" required> -->
                <div class="tp-wrapper" data-time-target="startTime">
                    <input id="startTimeDisplay" class="tp-input" readonly value="00:00">
                    <div class="tp-dropdown" id="startTimeDropdown">
                        <div class="tp-list">
                            <div class="tp-col tp-hour"></div>
                            <div class="tp-col tp-minute"></div>
                        </div>
                    </div>
                    <i class="fa-solid fa-clock"></i>
                </div>
                <input type="hidden" id="startTime" name="startTime" value="">
            </div>
        </div>

        <div class="vehicle-row">
            <div class="vehicle-input-content">
                <label for="endDate">ถึงวันที่</label>
                <input type="date" id="endDate" name="endDate" required>
            </div>

            <div class="vehicle-input-content">
                <label for="endTime">เวลาสิ้นสุด</label>
                <!-- <input type="time" id="endTime" name="endTime" required> -->
                <div class="tp-wrapper" data-time-target="endTime">
                    <input id="endTimeDisplay" class="tp-input" readonly value="00:00">
                    <div class="tp-dropdown" id="endTimeDropdown">
                        <div class="tp-list">
                            <div class="tp-col tp-hour"></div>
                            <div class="tp-col tp-minute"></div>
                        </div>
                    </div>
                    <i class="fa-solid fa-clock"></i>
                </div>
                <input type="hidden" id="endTime" name="endTime" value="">
            </div>

            <div class="vehicle-input-content">
                <label>จำนวนวัน</label>
                <div class="calculated-field" id="dayCount">
                    <i class="fa-regular fa-calendar"></i>
                    <p data-day-count aria-live="polite">-</p>
                </div>
            </div>
        </div>

        <div class="vehicle-row">
            <div class="vehicle-input-content">
                <label>ใช้น้ำมันเชื้อเพลิงจาก</label>
                <div class="radio-group">
                    <div class="radio-item">
                        <input type="radio" id="fuel-central" name="fuelSource" value="central" checked>
                        <label for="fuel-central">ส่วนกลาง</label>
                    </div>
                    <div class="radio-item">
                        <input type="radio" id="fuel-project" name="fuelSource" value="project">
                        <label for="fuel-project">โครงการ</label>
                    </div>
                    <div class="radio-item">
                        <input type="radio" id="fuel-user" name="fuelSource" value="user">
                        <label for="fuel-user">ผู้ใช้</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="vehicle-row file-sec">
            <div class="vehicle-input-content">
                <label>แนบเอกสาร <strong>(แนบเอกสารได้สูงสุด 5 ไฟล์)</strong></label>
                <div>
                    <button type="button" class="btn btn-upload-small"
                        onclick="document.getElementById('attachment').click()">
                        <p>เพิ่มไฟล์</p>
                    </button>
                </div>
                <input type="file" id="attachment" name="attachments[]" class="file-input" multiple
                    accept=".pdf,image/png,image/jpeg" hidden>
                <p class="form-error hidden" id="attachmentError">แนบได้สูงสุด 5 ไฟล์</p>
            </div>

            <div class="file-list" id="attachmentList" aria-live="polite"></div>
        </div>

        <div class="submit-section">
            <button
                type="submit"
                class="btn-submit"
                data-confirm="ยืนยันการบันทึกการจองยานพาหนะใช่หรือไม่?"
                data-confirm-title="ยืนยันการบันทึก"
                data-confirm-ok="ยืนยัน"
                data-confirm-cancel="ยกเลิก">บันทึกจองยานพาหนะ</button>
        </div>
    </form>

    <div class="vehicle-history tab-content" id="vehicleHistory">
        <div class="table-responsive table-circular-notice-index">
            <table class="custom-table booking-table vehicle-booking-history-table">
                <thead>
                    <tr>
                        <th>จัดการ</th>
                        <th>ช่วงเวลาใช้งาน</th>
                        <th>สถานะ</th>
                        <th>วัตถุประสงค์</th>
                        <th>อัปเดตล่าสุด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehicle_booking_history)) : ?>
                        <tr>
                            <td colspan="5" class="booking-empty">ยังไม่มีประวัติการจอง</td>
                        </tr>
                    <?php else : ?>
                        <?php
                        // Cache-busting so PDF viewer always loads the latest template after refactors.
                        $vehicle_pdf_mtime = @filemtime(__DIR__ . '/../../../public/api/vehicle-booking-pdf.php');
                        ?>
                        <?php foreach ($vehicle_booking_history as $booking) : ?>
                            <?php
                            $status_key = strtoupper((string) ($booking['status'] ?? 'PENDING'));
                            $status_meta = $vehicle_reservation_status_labels[$status_key] ?? $vehicle_reservation_status_labels['PENDING'];
                            $status_label = $status_meta['label'];
                            $status_class = $status_meta['class'];
                            $updated_at = trim((string) ($booking['updatedAt'] ?? ''));

                            if ($updated_at === '' || $updated_at === '0000-00-00 00:00:00') {
                                $updated_at = (string) ($booking['createdAt'] ?? '');
                            }
                            $updated_date = $updated_at !== '' ? substr($updated_at, 0, 10) : '';
                            $updated_time = $updated_at !== '' && strlen($updated_at) >= 16 ? substr($updated_at, 11, 5) : '';
                            $updated_date_label = '-';

                            if ($updated_date !== '') {
                                if (is_callable($format_thai_date_range)) {
                                    $updated_date_label = $format_thai_date_range($updated_date, $updated_date);
                                } else {
                                    $updated_date_label = $updated_date;
                                }
                            }
                            $updated_time_label = $updated_time !== '' ? $updated_time : '-';

                            $start_at = (string) ($booking['startAt'] ?? '');
                            $end_at = (string) ($booking['endAt'] ?? '');
                            $start_date = $start_at !== '' ? substr($start_at, 0, 10) : '';
                            $end_date = $end_at !== '' ? substr($end_at, 0, 10) : '';
                            $date_range = '-';

                            if ($start_date !== '') {
                                if (is_callable($format_thai_date_range)) {
                                    $date_range = $format_thai_date_range($start_date, $end_date !== '' ? $end_date : $start_date);
                                } elseif (is_callable($format_thai_datetime_range)) {
                                    $date_range = $format_thai_datetime_range($start_at, $end_at);
                                } else {
                                    $date_range = $start_date;
                                }
                            }

                            $time_range = '-';

                            if ($start_at !== '' && $end_at !== '') {
                                $start_time = substr($start_at, 11, 5);
                                $end_time = substr($end_at, 11, 5);
                                $time_range = trim($start_time . '-' . $end_time);
                            }

                            $purpose_text = trim((string) ($booking['purpose'] ?? ''));
                            $purpose_text = $purpose_text !== '' ? $purpose_text : '-';
                            ?>
                            <tr>
                                <td>
                                    <button type="button" class="booking-action-btn secondary" data-vehicle-approval-action="detail"
                                        data-vehicle-booking-action="detail"
                                        data-vehicle-booking-id="<?= htmlspecialchars((string) ($booking['bookingID'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                                        <i class="fa-solid fa-eye"></i>
                                        <span class="tooltip">ดูรายละเอียด</span>
                                    </button>
                                    <?php if ($status_key === 'APPROVED') : ?>
                                        <a href="public/api/vehicle-booking-pdf.php?booking_id=<?= urlencode((string) ($booking['bookingID'] ?? '')) ?>&v=<?= urlencode((string) ($vehicle_pdf_mtime ?: time())) ?>"
                                            class="booking-action-btn secondary" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-file-pdf"></i>
                                            <span class="tooltip">ดูเอกสาร PDF</span>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($date_range, ENT_QUOTES, 'UTF-8') ?><br>
                                    <span class="detail-subtext"><?= htmlspecialchars($time_range, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <span class="status-pill <?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($purpose_text, ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= htmlspecialchars($updated_date_label, ENT_QUOTES, 'UTF-8') ?><br>
                                    <span class="detail-subtext"><?= htmlspecialchars($updated_time_label, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div id="vehicleBookingDetailModal" class="modal-overlay modal-overlay-vehicle-edit hidden">
    <div class="modal-content">
        <div class="header-modal">
            <div class="first-header">
                <p>รายละเอียดการจองยานพาหนะ</p>
            </div>
            <div class="sec-header">
                <span class="status-pill pending" id="vehicleBookingDetailStatus">ส่งเอกสารแล้ว</span>
                <i class="fa-solid fa-xmark" data-vehicle-modal-close="vehicleBookingDetailModal"></i>
            </div>
        </div>

        <form id="vehicleReservationDetailForm" class="vehicle-detail-form is-readonly" data-vehicle-detail-form>
            <?= csrf_field() ?>
            <input type="hidden" name="vehicle_booking_id" value="">
            <input type="hidden" name="dh_year"
                value="<?= htmlspecialchars((string) $dh_year_value, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="requesterPID"
                value="<?= htmlspecialchars($requester_pid, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="companionCount" id="vehicleEditCompanionCount" value="0">
            <div class="vehicle-row split">
                <div class="vehicle-input-content">
                    <label>ส่วนราชการ</label>
                    <div class="custom-select-wrapper is-disabled" id="vehicleEditDeptWrapper">
                        <input type="hidden" id="vehicleEditDepartment" name="department" value="">

                        <div class="custom-select-trigger">
                            <span class="select-value">เลือกส่วนราชการ</span>
                            <i class="fa-solid fa-chevron-down arrow"></i>
                        </div>

                        <div class="custom-options">
                            <?php foreach ($vehicle_departments as $dept): ?>
                                <span class="custom-option"
                                    data-value="<?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($vehicle_factions as $faction): ?>
                                <span class="custom-option"
                                    data-value="<?= htmlspecialchars($faction['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($faction['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="vehicle-input-content">
                    <label for="vehicleEditWriteDate">วันที่เขียน</label>
                    <input type="date" id="vehicleEditWriteDate" name="writeDate"
                        value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" disabled>
                </div>
            </div>

            <div class="vehicle-row split">
                <div class="vehicle-input-content">
                    <label>ข้าพเจ้าพร้อมด้วย</label>
                    <div class="vehicle-companion-control">
                        <div class="go-with-dropdown">
                            <input type="text" id="vehicleDetailCompanionSummary" placeholder="ไม่มีผู้ร่วมเดินทาง"
                                autocomplete="off" readonly disabled>
                        </div>
                    </div>
                </div>
                <div class="vehicle-input-content">
                    <label>&nbsp;</label>
                    <button id="openShowMemberVehicle" class="show-member" type="button">
                        <p>แสดงผู้เดินทางทั้งหมด</p>
                    </button>
                </div>
            </div>

            <div class="vehicle-row">
                <div class="vehicle-input-content">
                    <label for="vehicleEditOtherPassengerCount">จำนวนบุคลากรอื่นๆ</label>
                    <input type="number" id="vehicleEditOtherPassengerCount" name="otherPassengerCount" min="0" step="1"
                        value="0" inputmode="numeric" disabled>
                </div>
            </div>

            <div class="vehicle-row">
                <div class="vehicle-input-content">
                    <label for="vehicleEditOtherPassengerNames">รายชื่อบุคลากร</label>
                    <textarea id="vehicleEditOtherPassengerNames" name="otherPassengerNames" rows="3"
                        placeholder="ไม่มีรายชื่อบุคลากร" disabled></textarea>
                </div>
            </div>

            <div id="memberModalVehicle" class="custom-modal">
                <div class="custom-modal-content">
                    <div class="member-header">
                        <p>รายชื่อผู้เดินทางที่เลือก</p>
                        <i class="fa-solid fa-xmark close-modal"></i>
                    </div>
                    <div id="selectedMemberListVehicle" class="member-list-container">
                    </div>
                </div>
            </div>

            <div class="vehicle-row">
                <div class="vehicle-input-content">
                    <label for="vehicleEditBookingPurpose">ขออนุญาตใช้รถเพื่อ</label>
                    <textarea id="vehicleEditBookingPurpose" name="purpose" rows="5" placeholder="ระบุวัตถุประสงค์" disabled></textarea>
                </div>
            </div>

            <div class="vehicle-row split">
                <div class="vehicle-input-content">
                    <label for="vehicleEditLocation">ณ (สถานที่)</label>
                    <input type="text" id="vehicleEditLocation" name="location" placeholder="ระบุสถานที่ปลายทาง" disabled>
                </div>

                <div class="vehicle-input-content">
                    <label>มีคนนั่งจำนวนทั้งสิ้น</label>
                    <div class="calculated-field" id="vehicleEditPassengerCountDisplay">
                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                        <p data-passenger-count aria-live="polite">1 คน</p>
                    </div>
                    <input type="hidden" id="vehicleEditPassengerCount" name="passengerCount" value="1" disabled>
                </div>
            </div>

            <div class="vehicle-row split">
                <div class="vehicle-input-content">
                    <label for="vehicleEditStartDate">ในวันที่</label>
                    <input type="date" id="vehicleEditStartDate" name="startDate" disabled>
                </div>

                <div class="vehicle-input-content">
                    <label for="vehicleEditStartTime">เวลาเริ่มต้น</label>
                    <input type="time" id="vehicleEditStartTime" name="startTime" disabled>
                </div>
            </div>

            <div class="vehicle-row">
                <div class="vehicle-input-content">
                    <label for="vehicleEditEndDate">ถึงวันที่</label>
                    <input type="date" id="vehicleEditEndDate" name="endDate" disabled>
                </div>

                <div class="vehicle-input-content">
                    <label for="vehicleEditEndTime">เวลาสิ้นสุด</label>
                    <input type="time" id="vehicleEditEndTime" name="endTime" disabled>
                </div>

                <div class="vehicle-input-content">
                    <label>จำนวนวัน</label>
                    <div class="calculated-field" id="vehicleEditDayCount">
                        <i class="fa-regular fa-calendar"></i>
                        <p data-day-count aria-live="polite">-</p>
                    </div>
                </div>
            </div>

            <div class="vehicle-row">
                <div class="vehicle-input-content">
                    <label>ใช้น้ำมันเชื้อเพลิงจาก</label>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="vehicleEditFuelCentral" name="fuelSource" value="central" checked disabled>
                            <label for="vehicleEditFuelCentral">ส่วนกลาง</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="vehicleEditFuelProject" name="fuelSource" value="project" disabled>
                            <label for="vehicleEditFuelProject">โครงการ</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="vehicleEditFuelUser" name="fuelSource" value="user" disabled>
                            <label for="vehicleEditFuelUser">ผู้ใช้</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="vehicle-row file-sec">
                <div class="vehicle-input-content">
                    <label>แนบเอกสาร</label>
                </div>

                <div class="file-list" id="vehicleAttachmentList" aria-live="polite"></div>
            </div>

            <hr>

            <div class="vehicle-row" id="vehicleBookingOfficerSection" style="display: none;">
                <div class="vehicle-input-content">
                    <label for="vehicleBookingOfficerNote">ความเห็นเจ้าหน้าที่ยานพาหนะ</label>
                    <textarea id="vehicleBookingOfficerNote" rows="5" placeholder="ความเห็นเจ้าหน้าที่ยานพาหนะ" disabled></textarea>
                </div>
            </div>

            <div class="vehicle-row" id="vehicleBookingDecisionSection" style="display: none;">
                <div class="vehicle-input-content">
                    <label for="vehicleBookingDecisionNote">รายละเอียดการ<span id="vehicleBookingDecisionLabelStatus" style="color: var(--color-success)">อนุมัติการจอง</span></label>
                    <textarea id="vehicleBookingDecisionNote" rows="5" placeholder="ระบุรายละเอียดการอนุมัติการจอง" disabled></textarea>
                </div>
            </div>

        </form>

    </div>
</div>

<script>
    window.vehicleBookingHistory = <?= json_encode($vehicle_booking_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.vehicleBookingFileEndpoint = 'public/api/vehicle-booking-file.php';
    window.vehicleReservationRequesterName = <?= json_encode($teacher_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const bookingData = Array.isArray(window.vehicleBookingHistory) ? window.vehicleBookingHistory : [];
        const bookingMap = bookingData.reduce((acc, item) => {
            if (item && item.id) acc[item.id] = item;
            return acc;
        }, {});

        const modal = document.getElementById('vehicleBookingDetailModal');
        const closeButtons = document.querySelectorAll('[data-vehicle-modal-close]');
        const openButtons = document.querySelectorAll('[data-vehicle-booking-action="detail"]');
        const form = modal ? modal.querySelector('[data-vehicle-detail-form]') : null;

        const departmentWrapper = document.getElementById('vehicleEditDeptWrapper');
        const departmentDisplay = departmentWrapper ? departmentWrapper.querySelector('.select-value') : null;
        const departmentOptions = departmentWrapper ? departmentWrapper.querySelectorAll('.custom-option') : [];
        const dayCountDisplay = modal ? modal.querySelector('#vehicleEditDayCount [data-day-count]') : null;
        const companionSummaryInput = document.getElementById('vehicleDetailCompanionSummary');

        const fieldMap = modal ? {
            bookingId: modal.querySelector('[name="vehicle_booking_id"]'),
            department: document.getElementById('vehicleEditDepartment'),
            departmentWrapper: departmentWrapper,
            departmentDisplay: departmentDisplay,
            departmentOptions: departmentOptions,
            writeDate: document.getElementById('vehicleEditWriteDate'),
            purpose: document.getElementById('vehicleEditBookingPurpose'),
            location: document.getElementById('vehicleEditLocation'),
            otherPassengerCount: document.getElementById('vehicleEditOtherPassengerCount'),
            otherPassengerNames: document.getElementById('vehicleEditOtherPassengerNames'),
            passengerCount: document.getElementById('vehicleEditPassengerCount'),
            passengerCountDisplay: modal.querySelector('#vehicleEditPassengerCountDisplay [data-passenger-count]'),
            startDate: document.getElementById('vehicleEditStartDate'),
            endDate: document.getElementById('vehicleEditEndDate'),
            startTime: document.getElementById('vehicleEditStartTime'),
            endTime: document.getElementById('vehicleEditEndTime'),
            fuelRadios: modal.querySelectorAll('input[name="fuelSource"]'),
            dayCount: dayCountDisplay,
            officerSection: document.getElementById('vehicleBookingOfficerSection'),
            officerNote: document.getElementById('vehicleBookingOfficerNote'),
            decisionSection: document.getElementById('vehicleBookingDecisionSection'),
            decisionLabelStatus: document.getElementById('vehicleBookingDecisionLabelStatus'),
            decisionNote: document.getElementById('vehicleBookingDecisionNote'),
            statusBadge: document.getElementById('vehicleBookingDetailStatus'),
        } : {};

        const editAttachmentsList = document.getElementById('vehicleAttachmentList');
        const companionModal = document.getElementById('memberModalVehicle');
        const companionModalTrigger = document.getElementById('openShowMemberVehicle');
        const companionModalClose = companionModal ? companionModal.querySelector('.close-modal') : null;
        const companionModalList = document.getElementById('selectedMemberListVehicle');
        let currentBooking = null;

        function formatFileSize(size) {
            if (!size) return '0 KB';
            const kb = size / 1024;
            if (kb < 1024) return `${Math.ceil(kb)} KB`;
            const mb = kb / 1024;
            return `${mb.toFixed(1)} MB`;
        }

        function setDepartmentValue(value) {
            if (!fieldMap.department) return;
            const safeValue = value || '';
            fieldMap.department.value = safeValue;
            if (fieldMap.departmentDisplay) {
                fieldMap.departmentDisplay.textContent = safeValue !== '' ? safeValue : 'เลือกส่วนราชการ';
            }
            if (fieldMap.departmentOptions && fieldMap.departmentOptions.length > 0) {
                fieldMap.departmentOptions.forEach((option) => {
                    option.classList.toggle('selected', option.getAttribute('data-value') === safeValue);
                });
            }
            if (fieldMap.departmentWrapper) {
                fieldMap.departmentWrapper.classList.remove('open');
            }
        }

        function updateModalDayCount() {
            if (!fieldMap.dayCount || !fieldMap.startDate || !fieldMap.endDate) return;
            const startDate = fieldMap.startDate.value;
            const endDate = fieldMap.endDate.value;

            if (startDate && fieldMap.endDate) {
                fieldMap.endDate.min = startDate;
            }

            if (!startDate || !endDate) {
                fieldMap.dayCount.textContent = '-';
                return;
            }

            const start = new Date(startDate);
            const end = new Date(endDate);
            const diffTime = end - start;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
            fieldMap.dayCount.textContent = diffDays > 0 ? `${diffDays} วัน` : 'วันที่ไม่ถูกต้อง';
        }

        function renderAttachmentList(editable) {
            if (!editAttachmentsList) return;
            editAttachmentsList.innerHTML = '';

            const existing = currentBooking && Array.isArray(currentBooking.attachments) ?
                currentBooking.attachments : [];

            if (existing.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'attachment-empty';
                empty.textContent = 'ไม่มีไฟล์แนบ';
                editAttachmentsList.appendChild(empty);
                return;
            }

            existing.forEach((file) => {
                const item = document.createElement('div');
                item.className = 'file-item-wrapper';
                item.dataset.fileId = String(file.fileID || '');

                const banner = document.createElement('div');
                banner.className = 'file-banner';

                const info = document.createElement('div');
                info.className = 'file-info';

                const iconWrap = document.createElement('div');
                iconWrap.className = 'file-icon';
                const icon = document.createElement('i');
                const isPdf = (file.mimeType || '') === 'application/pdf';
                const isImage = (file.mimeType || '') === 'image/jpeg' || (file.mimeType || '') === 'image/png';
                icon.className = isPdf ? 'fa-solid fa-file-pdf' : (isImage ? 'fa-solid fa-file-image' : 'fa-solid fa-file');
                icon.setAttribute('aria-hidden', 'true');
                iconWrap.appendChild(icon);

                const text = document.createElement('div');
                text.className = 'file-text';
                const name = document.createElement('span');
                name.className = 'file-name';
                name.textContent = file.fileName || 'ไฟล์แนบ';
                const type = document.createElement('span');
                type.className = 'file-type';
                type.textContent = `${file.mimeType || 'file'} • ${formatFileSize(Number(file.fileSize || 0))}`;
                text.appendChild(name);
                text.appendChild(type);

                info.appendChild(iconWrap);
                info.appendChild(text);

                const actions = document.createElement('div');
                actions.className = 'file-actions';

                const viewBtn = document.createElement('a');
                viewBtn.href = 'javascript:void(0)';
                viewBtn.className = 'action-btn';
                viewBtn.title = 'ดูตัวอย่าง';
                viewBtn.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
                viewBtn.addEventListener('click', () => {
                    const url = `${window.vehicleBookingFileEndpoint}?booking_id=${currentBooking.id}&file_id=${file.fileID}`;
                    window.open(url, '_blank', 'noopener');
                });

                actions.appendChild(viewBtn);

                banner.appendChild(info);
                banner.appendChild(actions);
                item.appendChild(banner);
                editAttachmentsList.appendChild(item);
            });
        }

        function openCompanionModal() {
            if (!companionModal) return;
            companionModal.style.display = 'flex';
            setTimeout(() => {
                companionModal.classList.add('show');
            }, 10);
        }

        function closeCompanionModal() {
            if (!companionModal) return;
            companionModal.classList.remove('show');
            setTimeout(() => {
                companionModal.style.display = 'none';
            }, 300);
        }

        function resetModal() {
            currentBooking = null;
            renderAttachmentList(false);
            if (fieldMap.bookingId) fieldMap.bookingId.value = '';
            setDepartmentValue('');
            if (fieldMap.writeDate) fieldMap.writeDate.value = '';
            if (fieldMap.purpose) fieldMap.purpose.value = '';
            if (fieldMap.location) fieldMap.location.value = '';
            if (fieldMap.otherPassengerCount) fieldMap.otherPassengerCount.value = '0';
            if (fieldMap.otherPassengerNames) fieldMap.otherPassengerNames.value = '';
            if (fieldMap.passengerCount) fieldMap.passengerCount.value = '1';
            if (fieldMap.passengerCountDisplay) fieldMap.passengerCountDisplay.textContent = '1 คน';
            if (companionSummaryInput) companionSummaryInput.value = '';
            if (fieldMap.startDate) fieldMap.startDate.value = '';
            if (fieldMap.endDate) fieldMap.endDate.value = '';
            if (fieldMap.startTime) fieldMap.startTime.value = '';
            if (fieldMap.endTime) fieldMap.endTime.value = '';
            if (fieldMap.dayCount) fieldMap.dayCount.textContent = '-';
            if (fieldMap.fuelRadios) {
                fieldMap.fuelRadios.forEach((radio) => {
                    radio.checked = false;
                });
            }
            if (fieldMap.officerSection) fieldMap.officerSection.style.display = 'none';
            if (fieldMap.officerNote) fieldMap.officerNote.value = '';
            if (fieldMap.decisionSection) fieldMap.decisionSection.style.display = 'none';
            if (fieldMap.decisionLabelStatus) {
                fieldMap.decisionLabelStatus.textContent = 'อนุมัติการจอง';
                fieldMap.decisionLabelStatus.style.color = 'var(--color-success)';
            }
            if (fieldMap.decisionNote) {
                fieldMap.decisionNote.value = '';
                fieldMap.decisionNote.placeholder = 'ระบุรายละเอียดการอนุมัติการจอง';
            }
            if (fieldMap.statusBadge) {
                fieldMap.statusBadge.className = 'status-pill pending';
                fieldMap.statusBadge.textContent = 'ส่งเอกสารแล้ว';
            }
            closeCompanionModal();
        }

        function countOtherPassengerNames(value) {
            return String(value || '')
                .split(/\r?\n|,/)
                .map((name) => name.trim())
                .filter(Boolean)
                .length;
        }

        function resolveCompanionCount(data) {
            if (Array.isArray(data?.companionIds)) {
                return data.companionIds.map((id) => String(id || '').trim()).filter(Boolean).length;
            }

            return Math.max(0, Number(data?.companionCount || 0));
        }

        function resolveOtherPassengerCount(data) {
            const storedOtherCount = Math.max(0, Number(data?.otherPassengerCount || 0));
            const nameCount = countOtherPassengerNames(data?.otherPassengerNames || '');

            return storedOtherCount > 0 ? storedOtherCount : nameCount;
        }

        function renderPassengerSummary(data) {
            if (!companionSummaryInput) return;
            const companionCount = resolveCompanionCount(data);

            if (companionCount <= 0) {
                companionSummaryInput.value = '';
                companionSummaryInput.placeholder = 'ไม่มีผู้ร่วมเดินทาง';
                return;
            }

            companionSummaryInput.value = `จำนวน ${companionCount} รายชื่อ`;
        }

        function resolvePassengerCount(data) {
            return Math.max(1, resolveCompanionCount(data) + resolveOtherPassengerCount(data) + 1);
        }

        function renderOfficerNote(data) {
            if (!fieldMap.officerSection || !fieldMap.officerNote) return;

            const vehicleLabel = String(data?.vehicleLabel || '').trim();
            const driverName = String(data?.driverName || '').trim();
            const driverTel = String(data?.driverTel || '').trim();
            const assignedNote = String(data?.assignedNote || '').trim();
            const assignedName = String(data?.assignedName || '').trim();
            const assignedAtLabel = String(data?.assignedAtLabel || '').trim();
            const lines = [];

            if (vehicleLabel !== '' || driverName !== '') {
                let summary = 'ควรอนุญาตให้ใช้รถยนต์ส่วนกลาง';

                if (vehicleLabel !== '') {
                    summary += ` หมายเลขทะเบียน ${vehicleLabel}`;
                }
                if (driverName !== '') {
                    summary += ` โดยมี ${driverName} ทำหน้าที่พนักงานขับรถ`;
                    if (driverTel !== '') {
                        summary += ` (${driverTel})`;
                    }
                }
                lines.push(summary);
            }

            if (assignedNote !== '') {
                lines.push(assignedNote);
            }

            if (assignedName !== '') {
                lines.push(`ผู้ตรวจสอบ: ${assignedName}${assignedAtLabel !== '' && assignedAtLabel !== '-' ? ` (${assignedAtLabel} น.)` : ''}`);
            }

            if (lines.length === 0) {
                fieldMap.officerSection.style.display = 'none';
                fieldMap.officerNote.value = '';
                return;
            }

            fieldMap.officerSection.style.display = '';
            fieldMap.officerNote.value = lines.join('\n');
        }

        function renderDecisionNote(data) {
            if (!fieldMap.decisionSection || !fieldMap.decisionLabelStatus || !fieldMap.decisionNote) return;

            const status = String(data?.status || '').toUpperCase();
            let label = '';
            let color = '';
            let placeholder = '';
            let note = '';

            if (status === 'APPROVED') {
                label = 'อนุมัติการจอง';
                color = 'var(--color-success)';
                placeholder = 'ระบุรายละเอียดการอนุมัติการจอง';
                note = String(data?.approvalNote || data?.statusReason || '').trim();
            } else if (status === 'REJECTED') {
                label = 'ไม่อนุมัติการจอง';
                color = 'var(--color-danger)';
                placeholder = 'ระบุรายละเอียดการไม่อนุมัติการจอง';
                note = String(data?.statusReason || data?.approvalNote || '').trim();
            }

            if (note === '') {
                fieldMap.decisionSection.style.display = 'none';
                fieldMap.decisionNote.value = '';
                return;
            }

            fieldMap.decisionSection.style.display = '';
            fieldMap.decisionLabelStatus.textContent = label;
            fieldMap.decisionLabelStatus.style.color = color;
            fieldMap.decisionNote.placeholder = placeholder;
            fieldMap.decisionNote.value = note;
        }

        function fillModal(data) {
            if (!data || !form) return;
            currentBooking = data;

            if (fieldMap.bookingId) fieldMap.bookingId.value = String(data.id || '');
            setDepartmentValue(data.department || '');
            if (fieldMap.writeDate) fieldMap.writeDate.value = data.writeDate || '';
            if (fieldMap.purpose) fieldMap.purpose.value = data.purpose || '';
            if (fieldMap.location) fieldMap.location.value = data.location || '';
            if (fieldMap.otherPassengerCount) {
                fieldMap.otherPassengerCount.value = String(data.otherPassengerCount || 0);
            }
            if (fieldMap.otherPassengerNames) {
                const otherCount = Number(data.otherPassengerCount || 0);
                fieldMap.otherPassengerNames.value = data.otherPassengerNames ||
                    (otherCount > 0 ? `ไม่ได้ระบุรายชื่อ (จำนวน ${otherCount} คน)` : '');
            }

            const startAt = data.startAt || '';
            const endAt = data.endAt || '';
            if (fieldMap.startDate) fieldMap.startDate.value = startAt ? startAt.split(' ')[0] : '';
            if (fieldMap.endDate) fieldMap.endDate.value = endAt ? endAt.split(' ')[0] : '';
            if (fieldMap.startTime) fieldMap.startTime.value = startAt ? startAt.split(' ')[1].slice(0, 5) : '';
            if (fieldMap.endTime) fieldMap.endTime.value = endAt ? endAt.split(' ')[1].slice(0, 5) : '';
            updateModalDayCount();

            const passengerValue = resolvePassengerCount(data);
            if (fieldMap.passengerCount) fieldMap.passengerCount.value = String(passengerValue);
            if (fieldMap.passengerCountDisplay) {
                fieldMap.passengerCountDisplay.textContent = passengerValue > 0 ? `${passengerValue} คน` : '-';
            }
            if (fieldMap.statusBadge) {
                const statusClass = String(data.statusClass || 'pending').trim() || 'pending';
                const statusLabel = String(data.statusLabel || 'ส่งเอกสารแล้ว').trim() || 'ส่งเอกสารแล้ว';
                fieldMap.statusBadge.className = `status-pill ${statusClass}`;
                fieldMap.statusBadge.textContent = statusLabel;
            }

            if (fieldMap.fuelRadios) {
                fieldMap.fuelRadios.forEach((radio) => {
                    radio.checked = radio.value === (data.fuelSource || 'central');
                });
            }

            renderPassengerSummary(data);
            renderAttachmentList(false);
            renderOfficerNote(data);
            renderDecisionNote(data);
        }

        if (companionModalTrigger && companionModalList) {
            companionModalTrigger.addEventListener('click', function(event) {
                event.preventDefault();
                companionModalList.innerHTML = '';
                const companionNames = currentBooking && Array.isArray(currentBooking.companionNames) ?
                    Array.from(new Set(currentBooking.companionNames.map((name) => String(name || '').trim()).filter(Boolean))) : [];
                const companionCount = currentBooking ? resolveCompanionCount(currentBooking) : 0;
                const missingCompanionCount = Math.max(0, companionCount - companionNames.length);

                if (companionCount > 0) {
                    const ul = document.createElement('ul');
                    companionNames.forEach((nameText) => {
                        const li = document.createElement('li');
                        li.textContent = nameText;
                        ul.appendChild(li);
                    });
                    if (missingCompanionCount > 0) {
                        const li = document.createElement('li');
                        li.textContent = `ผู้ร่วมเดินทางที่เลือก จำนวน ${missingCompanionCount} คน`;
                        ul.appendChild(li);
                    }
                    companionModalList.appendChild(ul);
                } else {
                    companionModalList.innerHTML = '<p style="text-align:center; color:#FF5050;">ยังไม่ได้เลือกรายชื่อผู้เดินทาง</p>';
                }
                openCompanionModal();
            });
        }

        if (companionModalClose) {
            companionModalClose.addEventListener('click', function(event) {
                event.preventDefault();
                closeCompanionModal();
            });
        }
        if (companionModal) {
            companionModal.addEventListener('click', function(event) {
                if (event.target === companionModal) {
                    closeCompanionModal();
                }
            });
        }

        openButtons.forEach((button) => {
            button.addEventListener('click', function() {
                const bookingId = parseInt(button.dataset.vehicleBookingId || '0', 10);
                const data = bookingMap[bookingId];
                if (!data || !modal) return;
                resetModal();
                fillModal(data);
                modal.classList.remove('hidden');
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', function() {
                const targetId = button.getAttribute('data-vehicle-modal-close');
                if (!targetId) return;
                const targetModal = document.getElementById(targetId);
                if (targetModal) {
                    targetModal.classList.add('hidden');
                }
                closeCompanionModal();
            });
        });

        if (modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.classList.add('hidden');
                    closeCompanionModal();
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
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
// vscode-write-test Sat Feb  7 21:17:32 +07 2026
