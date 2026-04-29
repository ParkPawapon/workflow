<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$vehicle_approval_alert = $vehicle_approval_alert ?? null;
$alert = $vehicle_approval_alert;
$vehicle_approval_mode = (string) ($vehicle_approval_mode ?? 'officer');
$vehicle_approval_can_assign = !empty($vehicle_approval_can_assign);
$vehicle_approval_can_finalize = !empty($vehicle_approval_can_finalize);
$vehicle_approval_query = (string) ($vehicle_approval_query ?? '');
$vehicle_approval_status = (string) ($vehicle_approval_status ?? 'all');
$vehicle_approval_vehicle = (string) ($vehicle_approval_vehicle ?? 'all');
$vehicle_approval_return_url = (string) ($vehicle_approval_return_url ?? 'vehicle-reservation-approval.php');
$vehicle_list = (array) ($vehicle_list ?? []);
$vehicle_driver_list = (array) ($vehicle_driver_list ?? []);
$vehicle_deputy_list = (array) ($vehicle_deputy_list ?? []);
$vehicle_booking_requests = (array) ($vehicle_booking_requests ?? []);
$vehicle_booking_attachments = (array) ($vehicle_booking_attachments ?? []);
$vehicle_approval_total = (int) ($vehicle_approval_total ?? 0);
$vehicle_approval_total_pages = (int) ($vehicle_approval_total_pages ?? 0);
$vehicle_approval_page = (int) ($vehicle_approval_page ?? 1);
$vehicle_approval_per_page = $vehicle_approval_per_page ?? 10;
$vehicle_approval_status_labels = (array) ($vehicle_approval_status_labels ?? []);
$format_thai_date_range = $format_thai_date_range ?? null;
$format_thai_datetime = $format_thai_datetime ?? null;

$show_rejected_filter = true;
$rejected_filter_label = $vehicle_approval_mode === 'director' ? 'ไม่อนุมัติ' : 'ไม่อนุมัติ/ยกเลิก';

$body_attrs = [
    'data-vehicle-approval-mode' => $vehicle_approval_mode,
    'data-vehicle-approval-can-assign' => $vehicle_approval_can_assign ? '1' : '0',
    'data-vehicle-approval-can-finalize' => $vehicle_approval_can_finalize ? '1' : '0',
];

ob_start();
?>

<style>
    .booking-detail-modal .modal-header div {
        color: var(--color-neutral-lightest);
    }

    @media screen and (max-width: 1400px) {
        .approval-detail-layout {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
    }

    [data-vehicle-approval-driver-dropdown] {
        position: relative;
        width: 100%;
    }

    [data-vehicle-approval-driver-dropdown] .go-with-dropdown-content {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 8px);
        z-index: 1500;
        max-height: 360px;
        overflow: auto;
        padding: 8px 0;
        background-color: var(--color-neutral-lightest);
        border: 1px solid rgba(var(--rgb-secondary), 0.2);
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(var(--rgb-neutral-dark), 0.18);
    }

    [data-vehicle-approval-driver-dropdown] .go-with-dropdown-content.show {
        display: block;
    }

    [data-vehicle-approval-driver-dropdown] .dropdown-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        min-height: 46px;
        gap: 12px;
        padding: 10px 14px;
        border: 0;
        background: transparent;
        color: var(--color-secondary);
        font-size: var(--font-size-body-2);
        line-height: 1.25;
        text-align: left;
        cursor: pointer;
    }

    [data-vehicle-approval-driver-dropdown] .dropdown-item:hover,
    [data-vehicle-approval-driver-dropdown] .dropdown-item[aria-selected="true"] {
        background-color: rgba(var(--rgb-secondary), 0.12);
    }

    [data-vehicle-approval-driver-dropdown] .dropdown-item-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    [data-vehicle-approval-driver-dropdown] .dropdown-item-tel {
        color: rgba(var(--rgb-secondary), 0.7);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>อนุมัติการจองยานพาหนะ</p>
</div>

<div class="content-area booking-page">
    <section class="booking-card booking-list-card approval-filter-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">ค้นหาและกรองรายการ</h2>
            </div>
        </div>

        <form class="approval-toolbar" method="get" action="vehicle-reservation-approval.php"
            data-approval-filter-form id="vehicleApprovalFilterForm">
            <div class="approval-filter-group">
                <div class="room-admin-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input class="form-input" type="search" name="q"
                        value="<?= htmlspecialchars($vehicle_approval_query, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="ค้นหาผู้ขอจอง/รถ/ทะเบียน" autocomplete="off">
                </div>
                <div class="room-admin-filter">

                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value">ทั้งหมด</p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <div class="custom-option" data-value="all">ทุกสถานะ</div>
                            <div class="custom-option" data-value="pending">รออนุมัติ</div>
                            <div class="custom-option" data-value="approved">อนุมัติแล้ว</div>
                            <?php if ($show_rejected_filter) : ?>
                                <div class="custom-option" data-value="rejected"><?= htmlspecialchars($rejected_filter_label, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>

                        <select class="form-input" name="status">
                            <option value="all">ทุกสถานะ</option>
                            <option value="pending" <?= $vehicle_approval_status === 'pending' ? 'selected' : '' ?>>รออนุมัติ</option>
                            <option value="approved" <?= $vehicle_approval_status === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                            <?php if ($show_rejected_filter) : ?>
                                <option value="rejected" <?= $vehicle_approval_status === 'rejected' ? 'selected' : '' ?>><?= htmlspecialchars($rejected_filter_label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                </div>
                <div class="room-admin-filter">
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value">ทั้งหมด</p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <div class="custom-option" data-value="all">ทุกยานพาหนะ</div>
                            <?php foreach ($vehicle_list as $vehicle_item): ?>
                                <?php
                                $vehicle_id = (string) ($vehicle_item['vehicleID'] ?? '');

                                if ($vehicle_id === '') {
                                    continue;
                                }
                                $plate = trim((string) ($vehicle_item['vehiclePlate'] ?? ''));
                                $type = trim((string) ($vehicle_item['vehicleType'] ?? ''));
                                $model = trim((string) ($vehicle_item['vehicleModel'] ?? ''));
                                $label = $plate !== '' ? $plate : $type;

                                if ($label === '') {
                                    $label = $vehicle_id;
                                }
                                $detail = trim($type . ' ' . $model);

                                if ($detail !== '') {
                                    $label .= ' - ' . $detail;
                                }
                                ?>
                                <div class="custom-option" data-value="<?= htmlspecialchars($vehicle_id, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <select class="form-input" name="vehicle">
                            <option value="all">ทุกยานพาหนะ</option>
                            <?php foreach ($vehicle_list as $vehicle_item): ?>
                                <?php
                                $vehicle_id = (string) ($vehicle_item['vehicleID'] ?? '');

                                if ($vehicle_id === '') {
                                    continue;
                                }
                                $plate = trim((string) ($vehicle_item['vehiclePlate'] ?? ''));
                                $type = trim((string) ($vehicle_item['vehicleType'] ?? ''));
                                $label = $plate !== '' ? $plate : $type;

                                if ($label === '') {
                                    $label = $vehicle_id;
                                }
                                ?>
                                <option value="<?= htmlspecialchars($vehicle_id, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $vehicle_approval_vehicle === $vehicle_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

            </div>
        </form>
    </section>

    <section class="booking-card booking-list-card booking-list-row approval-table-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">รายการคำขอจองรถ</h2>
            </div>
        </div>

        <div class="table-responsive approval-table-wrapper">
            <table class="custom-table booking-table approval-table">
                <thead>
                    <tr>
                        <th>วัน/เวลา</th>
                        <th>ผู้ขอจอง</th>
                        <th>รถ/ทะเบียน</th>
                        <th>วัตถุประสงค์</th>
                        <th>สถานที่</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php require __DIR__ . '/../../../public/components/partials/vehicle-reservation-approval-table-rows.php'; ?>
                </tbody>
            </table>
        </div>

    </section>
</div>

<div id="vehicleApprovalDetailModal" class="modal-overlay hidden">
    <div class="modal-content booking-detail-modal approval-detail-modal">
        <header class="modal-header">
            <div class="modal-title">
                <span>รายละเอียดการอนุมัติการจองรถ</span>
            </div>
            <div>
                <span class="status-pill" data-vehicle-approval-detail="status">-</span>
                <div class="close-modal-btn" data-vehicle-approval-close="vehicleApprovalDetailModal"
                    aria-label="ปิดหน้าต่าง">
                    <i class="fa-solid fa-xmark"></i>
                </div>
            </div>
        </header>
        <div class="modal-body booking-detail-body approval-detail-body">
            <div class="approval-detail-layout">
                <section class="approval-panel approval-panel--request">
                    <div class="approval-panel-header">
                        <h4 class="approval-panel-title">ข้อมูลคำขอ</h4>
                        <span class="approval-panel-subtitle">รายละเอียดการใช้รถ</span>
                    </div>

                    <div class="booking-detail-grid approval-request-grid">
                        <div class="detail-item">
                            <p class="detail-label">ผู้ขอจอง</p>
                            <p class="detail-value" data-vehicle-approval-detail="requester">-</p>
                            <span class="detail-subtext" data-vehicle-approval-detail="department">-</span>
                        </div>
                        <div class="detail-item">
                            <p class="detail-label">ติดต่อ</p>
                            <p class="detail-value" data-vehicle-approval-detail="contact">-</p>
                        </div>
                        <div class="detail-item">
                            <p class="detail-label">จำนวนผู้เดินทาง</p>
                            <p class="detail-value" data-vehicle-approval-detail="passengers">-</p>
                        </div>
                        <div class="detail-item">
                            <p class="detail-label">วันที่</p>
                            <p class="detail-value" data-vehicle-approval-detail="date">-</p>
                        </div>
                        <div class="detail-item">
                            <p class="detail-label">เวลา</p>
                            <p class="detail-value" data-vehicle-approval-detail="time">-</p>
                        </div>
                    </div>

                    <div class="booking-detail-section">
                        <h4>วัตถุประสงค์</h4>
                        <p data-vehicle-approval-detail="purpose">-</p>
                    </div>

                    <div class="booking-detail-section">
                        <h4>สถานที่</h4>
                        <p data-vehicle-approval-detail="location">-</p>
                    </div>

                    <div class="booking-detail-section">
                        <h4>ไฟล์แนบ</h4>
                        <div class="attachment-list" data-vehicle-approval-detail="attachments">
                        </div>
                    </div>
                </section>

                <section class="approval-panel approval-panel--decision">
                    <div class="approval-panel-header">
                        <h4 class="approval-panel-title">การพิจารณา</h4>
                        <span class="approval-panel-subtitle">ผลล่าสุดและการมอบหมาย</span>
                    </div>

                    <div class="booking-detail-section" data-vehicle-approval-detail="assigned-note-row">
                        <h4>ความคิดเห็นจากเจ้าหน้าที่ควบคุมยานพาหนะ</h4>
                        <p data-vehicle-approval-detail="assigned-note">-</p>
                    </div>

                    <form class="approval-decision-form" method="post" action="vehicle-reservation-approval.php"
                        data-vehicle-approval-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="vehicle_booking_id" value="">
                        <input type="hidden" name="approval_action" value="">
                        <input type="hidden" name="return_url"
                            value="<?= htmlspecialchars($vehicle_approval_return_url, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="approval-decision hidden" data-vehicle-approval-assign>
                            <div class="approval-decision-head">
                                <h4>มอบหมายรถและคนขับ</h4>
                            </div>
                            <div class="booking-detail-grid approval-request-grid">
                                <div class="detail-item detail-full">
                                    <p class="detail-label">ยานพาหนะ</p>

                                    <div class="custom-select-wrapper">
                                        <div class="custom-select-trigger">
                                            <p class="select-value">เลือกยานพาหนะ</p>
                                            <i class="fa-solid fa-chevron-down"></i>
                                        </div>

                                        <div class="custom-options">
                                            <?php foreach ($vehicle_list as $vehicle_item): ?>
                                                <?php
                                                $vehicle_id = (string) ($vehicle_item['vehicleID'] ?? '');

                                                if ($vehicle_id === '') {
                                                    continue;
                                                }
                                                $plate = trim((string) ($vehicle_item['vehiclePlate'] ?? ''));
                                                $type = trim((string) ($vehicle_item['vehicleType'] ?? ''));
                                                $model = trim((string) ($vehicle_item['vehicleModel'] ?? ''));
                                                $label = $plate !== '' ? $plate : $type;

                                                if ($label === '') {
                                                    $label = $vehicle_id;
                                                }
                                                $detail = trim($type . ' ' . $model);

                                                if ($detail !== '') {
                                                    $label .= ' - ' . $detail;
                                                }
                                                ?>
                                                <div class="custom-option" data-value="<?= htmlspecialchars($vehicle_id, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <select class="form-input" id="assignVehicleSelect" name="assign_vehicle_id">
                                            <option value="">เลือกยานพาหนะ</option>
                                            <?php foreach ($vehicle_list as $vehicle_item): ?>
                                                <?php
                                                $vehicle_id = (string) ($vehicle_item['vehicleID'] ?? '');

                                                if ($vehicle_id === '') {
                                                    continue;
                                                }
                                                $plate = trim((string) ($vehicle_item['vehiclePlate'] ?? ''));
                                                $type = trim((string) ($vehicle_item['vehicleType'] ?? ''));
                                                $model = trim((string) ($vehicle_item['vehicleModel'] ?? ''));
                                                $label = $plate !== '' ? $plate : $type;

                                                if ($label === '') {
                                                    $label = $vehicle_id;
                                                }
                                                $detail = trim($type . ' ' . $model);

                                                if ($detail !== '') {
                                                    $label .= ' - ' . $detail;
                                                }
                                                ?>
                                                <option value="<?= htmlspecialchars($vehicle_id, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="detail-item detail-full">
                                    <p class="detail-label">ผู้ขับรถ</p>
                                    <div class="go-with-dropdown approval-driver-dropdown"
                                        data-vehicle-approval-driver-dropdown>
                                        <input class="form-input" type="text" id="assignDriverSearchInput"
                                            placeholder="ค้นหาผู้ขับรถ" autocomplete="off"
                                            aria-autocomplete="list" aria-expanded="false"
                                            aria-controls="assignDriverDropdown">

                                        <div id="assignDriverDropdown" class="go-with-dropdown-content" role="listbox">
                                            <?php foreach ($vehicle_driver_list as $driver_item): ?>
                                                <?php
                                                $driver_id = (string) ($driver_item['pID'] ?? '');
                                                $driver_name = trim((string) ($driver_item['name'] ?? ''));
                                                $driver_tel = trim((string) ($driver_item['telephone'] ?? ''));

                                                if ($driver_id === '' || $driver_name === '') {
                                                    continue;
                                                }
                                                ?>
                                                <button type="button" class="dropdown-item"
                                                    data-driver-option
                                                    data-value="<?= htmlspecialchars($driver_id, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-driver-name="<?= htmlspecialchars($driver_name, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-driver-tel="<?= htmlspecialchars($driver_tel, ENT_QUOTES, 'UTF-8') ?>"
                                                    role="option" aria-selected="false">
                                                    <span class="dropdown-item-name"><?= htmlspecialchars($driver_name, ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php if ($driver_tel !== ''): ?>
                                                        <span class="dropdown-item-tel"><?= htmlspecialchars($driver_tel, ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endforeach; ?>
                                            <div class="dropdown-empty hidden" data-driver-empty>ไม่พบรายชื่อ</div>
                                        </div>
                                    </div>
                                    <select class="form-input hidden" id="assignDriverSelect" name="assign_driver_pid"
                                        aria-hidden="true" tabindex="-1">
                                        <option value="">เลือกผู้ขับรถ</option>
                                        <?php foreach ($vehicle_driver_list as $driver_item): ?>
                                            <?php
                                            $driver_id = (string) ($driver_item['pID'] ?? '');
                                            $driver_name = trim((string) ($driver_item['name'] ?? ''));
                                            $driver_tel = trim((string) ($driver_item['telephone'] ?? ''));

                                            if ($driver_id === '' || $driver_name === '') {
                                                continue;
                                            }
                                            ?>
                                            <option value="<?= htmlspecialchars($driver_id, ENT_QUOTES, 'UTF-8') ?>"
                                                data-driver-name="<?= htmlspecialchars($driver_name, ENT_QUOTES, 'UTF-8') ?>"
                                                data-driver-tel="<?= htmlspecialchars($driver_tel, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($driver_name, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="detail-label">เบอร์โทรคนขับ</p>
                                    <input class="form-input" id="assignDriverTel" type="tel"
                                        name="assign_driver_tel" placeholder="-" disabled>
                                </div>
                                <div class="detail-item detail-full">
                                    <p class="detail-label">ส่งให้รองผู้อำนวยการ</p>

                                    <div class="custom-select-wrapper">
                                        <div class="custom-select-trigger">
                                            <p class="select-value">เลือกรองผู้อำนวยการ</p>
                                            <i class="fa-solid fa-chevron-down"></i>
                                        </div>

                                        <div class="custom-options">
                                            <?php foreach ($vehicle_deputy_list as $deputy_item): ?>
                                                <?php
                                                $deputy_id = (string) ($deputy_item['pID'] ?? '');
                                                $deputy_name = trim((string) ($deputy_item['name'] ?? ''));
                                                $deputy_position = trim((string) ($deputy_item['positionName'] ?? ''));

                                                if ($deputy_id === '' || $deputy_name === '') {
                                                    continue;
                                                }
                                                $deputy_label = $deputy_position !== '' ? $deputy_name . ' - ' . $deputy_position : $deputy_name;
                                                ?>
                                                <div class="custom-option" data-value="<?= htmlspecialchars($deputy_id, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($deputy_label, ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <select class="form-input" id="assignFinalApproverSelect" name="assign_final_approver_pid">
                                            <option value="">เลือกรองผู้อำนวยการ</option>
                                            <?php foreach ($vehicle_deputy_list as $deputy_item): ?>
                                                <?php
                                                $deputy_id = (string) ($deputy_item['pID'] ?? '');
                                                $deputy_name = trim((string) ($deputy_item['name'] ?? ''));
                                                $deputy_position = trim((string) ($deputy_item['positionName'] ?? ''));

                                                if ($deputy_id === '' || $deputy_name === '') {
                                                    continue;
                                                }
                                                $deputy_label = $deputy_position !== '' ? $deputy_name . ' - ' . $deputy_position : $deputy_name;
                                                ?>
                                                <option value="<?= htmlspecialchars($deputy_id, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($deputy_label, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div class="booking-detail-section approval-decision" data-vehicle-approval-finalize>
                            <div class="approval-decision-head">
                                <h4>บันทึกผล</h4>
                            </div>
                            <p class="detail-label">ความคิดเห็นจากผู้บริหาร</p>
                            <textarea class="form-input booking-textarea" id="vehicleApprovalNote"
                                name="approvalNote" rows="3" placeholder="ระบุความคิดเห็น/เหตุผล (จำเป็น)"
                                required></textarea>
                        </div>

                        <div class="booking-detail-meta">
                            <div class="detail-meta-item">
                                <span>สร้างรายการ</span>
                                <strong data-vehicle-approval-detail="created">-</strong>
                            </div>
                            <div class="detail-meta-item">
                                <span>อัปเดตล่าสุด</span>
                                <strong data-vehicle-approval-detail="updated">-</strong>
                            </div>
                        </div>

                        <div class="booking-actions">
                            <button type="submit" class="btn-outline"
                                data-vehicle-approval-submit="reject">ไม่อนุมัติ</button>
                            <button type="submit" class="btn-confirm"
                                data-vehicle-approval-submit="approve">อนุมัติรายการ</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</div>

<script>
    window.vehicleBookingFileEndpoint = 'public/api/vehicle-booking-file.php';

    document.addEventListener('DOMContentLoaded', function() {
        const detailModal = document.getElementById('vehicleApprovalDetailModal');
        const closeButtons = document.querySelectorAll('[data-vehicle-approval-close]');

        const approvalForm = detailModal ? detailModal.querySelector('[data-vehicle-approval-form]') : null;
        const approvalIdInput = approvalForm ? approvalForm.querySelector('[name="vehicle_booking_id"]') : null;
        const approvalActionInput = approvalForm ? approvalForm.querySelector('[name="approval_action"]') : null;
        const approvalNoteInput = approvalForm ? approvalForm.querySelector('[name="approvalNote"]') : null;
        const approvalActionButtons = detailModal ? detailModal.querySelectorAll('[data-vehicle-approval-submit]') : [];
        const approveButton = detailModal ? detailModal.querySelector('[data-vehicle-approval-submit="approve"]') : null;
        const rejectButton = detailModal ? detailModal.querySelector('[data-vehicle-approval-submit="reject"]') : null;

        const approvalAssignSection = detailModal ? detailModal.querySelector('[data-vehicle-approval-assign]') : null;
        const approvalFinalizeSection = detailModal ? detailModal.querySelector('[data-vehicle-approval-finalize]') : null;
        const assignVehicleSelect = approvalForm ? approvalForm.querySelector('[name=\"assign_vehicle_id\"]') : null;
        const assignDriverSelect = approvalForm ? approvalForm.querySelector('[name=\"assign_driver_pid\"]') : null;
        const assignFinalApproverSelect = approvalForm ? approvalForm.querySelector('[name=\"assign_final_approver_pid\"]') : null;
        const assignDriverTelInput = approvalForm ? approvalForm.querySelector('[name=\"assign_driver_tel\"]') : null;
        const assignNoteInput = approvalForm ? approvalForm.querySelector('[name=\"assignedNote\"]') : null;

        const driverDropdownWrapper = approvalForm ? approvalForm.querySelector('[data-vehicle-approval-driver-dropdown]') : null;
        const driverSearchInput = driverDropdownWrapper ? driverDropdownWrapper.querySelector('#assignDriverSearchInput') : null;
        const driverDropdown = driverDropdownWrapper ? driverDropdownWrapper.querySelector('#assignDriverDropdown') : null;
        const driverEmptyState = driverDropdown ? driverDropdown.querySelector('[data-driver-empty]') : null;
        const driverItems = driverDropdown ? Array.from(driverDropdown.querySelectorAll('[data-driver-option]')) : [];

        const canAssign = document.body?.dataset.vehicleApprovalCanAssign === '1';
        const canFinalize = document.body?.dataset.vehicleApprovalCanFinalize === '1';

        let currentStatus = '';
        let canAssignStage = false;
        let canEditAssignedStage = false;
        let canFinalizeStage = false;
        let canApproveStage = false;
        let canRejectStage = false;

        const getAlertsApi = () => window.AppAlerts || null;

        const showVehicleApprovalAlert = (message, type = 'warning', title = 'แจ้งเตือน') => {
            const alertsApi = getAlertsApi();
            if (!alertsApi || typeof alertsApi.fire !== 'function') {
                console.warn('Vehicle approval alert unavailable:', title, message);
                return;
            }

            alertsApi.fire({
                type,
                title,
                message,
            });
        };

        function submitApprovalForm() {
            if (!approvalForm) return false;
            if (typeof approvalForm.reportValidity === 'function' && !approvalForm.reportValidity()) {
                return false;
            }
            if (typeof approvalForm.requestSubmit === 'function') {
                approvalForm.requestSubmit();
            } else {
                approvalForm.submit();
            }
            return true;
        }

        const filterForm = document.querySelector('[data-approval-filter-form]');
        const tableBody = document.querySelector('.booking-list-card table tbody');
        const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
        const perPageSelect = document.getElementById('vehicleApprovalPerPageSelect');
        const filterSelects = filterForm ? filterForm.querySelectorAll('select, input[type="date"]') : [];
        const pageInput = filterForm ? filterForm.querySelector('input[name="page"]') : null;
        const paginationContainer = document.querySelector('[data-vehicle-approval-pagination]');
        let searchTimeout;

        function fetchResults() {
            if (!filterForm || !tableBody) return;
            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);
            if (perPageSelect && perPageSelect.value) {
                params.set('per_page', perPageSelect.value);
            }
            params.append('ajax_filter', '1');

            const url = filterForm.action;
            const fullUrl = url + '?' + params.toString();
            const historyParams = new URLSearchParams(formData);
            if (perPageSelect && perPageSelect.value) {
                historyParams.set('per_page', perPageSelect.value);
            }
            const historyUrl = url + '?' + historyParams.toString();
            window.history.pushState({}, '', historyUrl);

            fetch(fullUrl)
                .then(response => response.json())
                .then(payload => {
                    if (!payload || typeof payload !== 'object') return;
                    tableBody.innerHTML = payload.rows_html || '';

                    if (paginationContainer) {
                        paginationContainer.innerHTML = payload.pagination_html || '';
                    }

                    if (pageInput && payload.page) {
                        pageInput.value = String(payload.page);
                    }
                })
                .catch(error => console.error('Error loading data:', error));
        }

        if (filterForm) {
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(fetchResults, 400);
                    if (pageInput) pageInput.value = '1';
                });
            }
            const selectControls = Array.from(filterSelects || []);
            if (perPageSelect) {
                selectControls.push(perPageSelect);
            }
            selectControls.forEach(function(select) {
                select.addEventListener('change', function() {
                    if (pageInput) pageInput.value = '1';
                    fetchResults();
                });
            });
            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (pageInput) pageInput.value = '1';
                fetchResults();
            });
        }

        if (paginationContainer && pageInput) {
            paginationContainer.addEventListener('click', function(e) {
                const btn = e.target.closest('button[data-page]');
                if (!btn || btn.disabled) return;
                const nextPage = btn.getAttribute('data-page') || '1';
                pageInput.value = nextPage;
                fetchResults();
            });
        }

        const detailFields = detailModal ? {
            vehicle: detailModal.querySelector('[data-vehicle-approval-detail="vehicle"]'),
            vehicleCode: detailModal.querySelector('[data-vehicle-approval-detail="vehicle-code"]'),
            date: detailModal.querySelector('[data-vehicle-approval-detail="date"]'),
            time: detailModal.querySelector('[data-vehicle-approval-detail="time"]'),
            requester: detailModal.querySelector('[data-vehicle-approval-detail="requester"]'),
            department: detailModal.querySelector('[data-vehicle-approval-detail="department"]'),
            contact: detailModal.querySelector('[data-vehicle-approval-detail="contact"]'),
            passengers: detailModal.querySelector('[data-vehicle-approval-detail="passengers"]'),
            purpose: detailModal.querySelector('[data-vehicle-approval-detail="purpose"]'),
            location: detailModal.querySelector('[data-vehicle-approval-detail="location"]'),
            driver: detailModal.querySelector('[data-vehicle-approval-detail="driver"]'),
            attachments: detailModal.querySelector('[data-vehicle-approval-detail="attachments"]'),
            status: detailModal.querySelector('[data-vehicle-approval-detail="status"]'),
            assignedNoteRow: detailModal.querySelector('[data-vehicle-approval-detail="assigned-note-row"]'),
            assignedNote: detailModal.querySelector('[data-vehicle-approval-detail="assigned-note"]'),
            approvalNoteRow: detailModal.querySelector('[data-vehicle-approval-detail="approval-note-row"]'),
            approvalNote: detailModal.querySelector('[data-vehicle-approval-detail="approval-note"]'),
            approvalItem: detailModal.querySelector('[data-vehicle-approval-detail="approval-item"]'),
            approvalLabel: detailModal.querySelector('[data-vehicle-approval-detail="approval-label"]'),
            approvalName: detailModal.querySelector('[data-vehicle-approval-detail="approval-name"]'),
            approvalAt: detailModal.querySelector('[data-vehicle-approval-detail="approval-at"]'),
            created: detailModal.querySelector('[data-vehicle-approval-detail="created"]'),
            updated: detailModal.querySelector('[data-vehicle-approval-detail="updated"]'),
        } : {};

        function openDetailModal(button) {
            if (!detailModal || !button) return;

            const data = button.dataset;
            const statusClass = data.approvalStatusClass || 'pending';
            const statusValue = (data.approvalStatus || '').toUpperCase();
            const isPending = statusValue === '' || statusValue === 'PENDING' || statusValue === 'DRAFT';
            const isAssigned = statusValue === 'ASSIGNED';
            const isRejected = statusValue === 'REJECTED' || statusValue === 'CANCELLED';
            const isApproved = statusValue === 'APPROVED' || statusValue === 'COMPLETED';
            const isFinalized = isApproved || isRejected;
            const attachmentsRaw = data.approvalAttachments || '[]';
            let attachments = [];
            try {
                attachments = JSON.parse(attachmentsRaw);
            } catch (e) {
                attachments = [];
            }

            if (detailFields.vehicle) detailFields.vehicle.textContent = data.approvalVehicle || '-';
            if (detailFields.vehicleCode) {
                detailFields.vehicleCode.textContent = data.approvalCode ?
                    'รหัสคำขอ ' + data.approvalCode :
                    '-';
            }
            if (detailFields.date) detailFields.date.textContent = data.approvalDate || '-';
            if (detailFields.time) detailFields.time.textContent = data.approvalTime || '-';
            if (detailFields.requester) detailFields.requester.textContent = data.approvalRequester || '-';
            if (detailFields.department) detailFields.department.textContent = data.approvalDepartment || '-';
            if (detailFields.contact) detailFields.contact.textContent = data.approvalContact || '-';
            if (detailFields.passengers) detailFields.passengers.textContent = data.approvalPassengers || '-';
            if (detailFields.purpose) detailFields.purpose.textContent = data.approvalPurpose || '-';
            if (detailFields.location) detailFields.location.textContent = data.approvalLocation || '-';
            if (detailFields.driver) detailFields.driver.textContent = data.approvalDriver || '-';
            if (detailFields.status) {
                detailFields.status.textContent = data.approvalStatusLabel || (isPending ? 'ส่งเอกสารแล้ว' : '-');
                detailFields.status.className = `status-pill ${statusClass}`;
            }
            const assignedNoteText = (data.approvalAssignedNote || '').trim();
            const vehicleLabelRaw = (data.approvalVehicle || '').trim();
            const driverLabelRaw = (data.approvalDriver || '').trim();
            const vehicleLabel = vehicleLabelRaw !== '-' ? vehicleLabelRaw : '';
            const driverLabel = driverLabelRaw !== '-' ? driverLabelRaw : '';
            const showOfficerBox = isFinalized || (isAssigned && canFinalize);
            if (detailFields.assignedNoteRow) {
                // Executives should see the vehicle officer box in ASSIGNED + finalized states.
                detailFields.assignedNoteRow.classList.toggle('hidden', !showOfficerBox);
            }
            if (detailFields.assignedNote) {
                if (showOfficerBox) {
                    const note2 = assignedNoteText !== '' ? assignedNoteText : '-';
                    detailFields.assignedNote.textContent =
                        `1. ควรอนุญาตให้ใช้รถยนต์ส่วนกลาง หมายเลขทะเบียน ${vehicleLabel !== '' ? vehicleLabel : '-'} โดยมี ${driverLabel !== '' ? driverLabel : '-'} ทำหน้าที่พนักงานขับรถ\n` +
                        `2. อื่นๆ: ${note2}`;
                } else {
                    detailFields.assignedNote.textContent = '-';
                }
            }
            const approvalNoteText = (data.approvalApprovalNote || '').trim();
            if (detailFields.approvalNoteRow) {
                // In finalized states, always show the management note box (even if empty).
                detailFields.approvalNoteRow.classList.toggle('hidden', !isFinalized);
            }
            if (detailFields.approvalNote) {
                detailFields.approvalNote.textContent = isFinalized && approvalNoteText !== '' ? approvalNoteText : '-';
            }
            if (detailFields.approvalLabel && detailFields.approvalName && detailFields.approvalAt) {
                if (isPending) {
                    detailFields.approvalLabel.textContent = 'ผู้อนุมัติ';
                    detailFields.approvalName.textContent = 'รอการอนุมัติ';
                    detailFields.approvalAt.textContent = '-';
                } else if (isAssigned) {
                    detailFields.approvalLabel.textContent = 'ผู้มอบหมาย';
                    detailFields.approvalName.textContent = data.approvalName || '-';
                    detailFields.approvalAt.textContent = data.approvalAt || '-';
                } else {
                    detailFields.approvalLabel.textContent = isRejected ? 'ผู้ไม่อนุมัติ' : 'ผู้อนุมัติ';
                    detailFields.approvalName.textContent = data.approvalName || '-';
                    detailFields.approvalAt.textContent = data.approvalAt || '-';
                }
            }
            if (detailFields.created) detailFields.created.textContent = data.approvalCreated || '-';
            if (detailFields.updated) detailFields.updated.textContent = data.approvalUpdated || '-';

            if (detailFields.attachments) {
                detailFields.attachments.innerHTML = '';
                if (!attachments.length) {
                    const empty = document.createElement('p');
                    empty.className = 'attachment-empty';
                    empty.textContent = 'ไม่มีไฟล์แนบ';
                    detailFields.attachments.appendChild(empty);
                } else {
                    const fileEndpoint = window.vehicleBookingFileEndpoint || 'public/api/vehicle-booking-file.php';
                    const list = document.createElement('div');
                    list.className = 'file-list';

                    attachments.forEach((file) => {
                        const item = document.createElement('div');
                        item.className = 'file-item-wrapper';

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
                        type.textContent = file.mimeType || 'file';
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
                            const url = `${fileEndpoint}?booking_id=${data.approvalId}&file_id=${file.fileID}`;
                            window.open(url, '_blank', 'noopener');
                        });

                        // const downloadBtn = document.createElement('a');
                        // downloadBtn.href = 'javascript:void(0)';
                        // downloadBtn.className = 'action-btn';
                        // downloadBtn.title = 'ดาวน์โหลด';
                        // downloadBtn.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i>';
                        // downloadBtn.addEventListener('click', () => {
                        //     const url = `${fileEndpoint}?booking_id=${data.approvalId}&file_id=${file.fileID}&download=1`;
                        //     window.open(url, '_blank', 'noopener');
                        // });

                        actions.appendChild(viewBtn);
                        // actions.appendChild(downloadBtn);

                        banner.appendChild(info);
                        banner.appendChild(actions);
                        item.appendChild(banner);
                        list.appendChild(item);
                    });

                    detailFields.attachments.appendChild(list);
                }
            }

            if (approvalIdInput) approvalIdInput.value = data.approvalId || '';
            if (approvalActionInput) approvalActionInput.value = '';
            if (approvalNoteInput) {
                approvalNoteInput.value = data.approvalApprovalNote || '';
            }
            if (assignNoteInput) {
                assignNoteInput.value = data.approvalAssignedNote || '';
            }

            currentStatus = statusValue || 'PENDING';
            const isPendingStage = currentStatus === 'PENDING' || currentStatus === 'DRAFT';
            const isAssignedStage = currentStatus === 'ASSIGNED';
            const isFinalizedStage = currentStatus === 'APPROVED' || currentStatus === 'REJECTED';

            canAssignStage = canAssign && isPendingStage;
            // Allow vehicle officers to edit assignment while the booking is "กำลังดำเนินการ" (ASSIGNED).
            // Do not enable this for final approvers to avoid ambiguity (approve should finalize).
            canEditAssignedStage = canAssign && !canFinalize && isAssignedStage;
            // Final approvers can record the final decision in ASSIGNED, and may also edit/change the decision
            // after it was already recorded (APPROVED/REJECTED).
            canFinalizeStage = canFinalize && (isAssignedStage || isFinalizedStage);

            canApproveStage = canAssignStage || canEditAssignedStage || canFinalizeStage;
            // Only final approvers can record the final decision (reject/approve).
            canRejectStage = canFinalizeStage;

            if (approvalAssignSection) {
                approvalAssignSection.classList.toggle('hidden', !(canAssignStage || canEditAssignedStage));
            }
            if (approvalFinalizeSection) {
                // Show the management note field:
                // - Final approvers: can edit in ASSIGNED/APPROVED/REJECTED.
                // - Other roles: show read-only after a final decision is recorded.
                approvalFinalizeSection.classList.toggle('hidden', !(canFinalizeStage || isFinalizedStage));
            }

            const assignInputs = [assignVehicleSelect, assignDriverSelect, assignFinalApproverSelect];
            assignInputs.forEach(function(input) {
                if (!input) return;
                input.disabled = !(canAssignStage || canEditAssignedStage);
            });
            if (driverSearchInput) {
                driverSearchInput.disabled = !(canAssignStage || canEditAssignedStage);
            }

            if (approvalNoteInput) {
                approvalNoteInput.disabled = !canFinalizeStage;
            }

            if (approveButton) {
                approveButton.classList.toggle('hidden', !canApproveStage);
                if (canAssignStage) {
                    approveButton.textContent = 'ส่งต่อรองผู้อำนวยการ';
                } else if (canEditAssignedStage) {
                    approveButton.textContent = 'บันทึกการมอบหมาย';
                } else if (canFinalizeStage && currentStatus === 'APPROVED') {
                    approveButton.textContent = 'บันทึกผล (อนุมัติ)';
                } else if (canFinalizeStage && currentStatus === 'REJECTED') {
                    approveButton.textContent = 'เปลี่ยนเป็นอนุมัติ';
                } else {
                    approveButton.textContent = 'อนุมัติรายการ';
                }
            }
            if (rejectButton) {
                rejectButton.classList.toggle('hidden', !canRejectStage);
                if (canRejectStage && currentStatus === 'APPROVED') {
                    rejectButton.textContent = 'เปลี่ยนเป็นไม่อนุมัติ';
                } else if (canRejectStage && currentStatus === 'REJECTED') {
                    rejectButton.textContent = 'บันทึกผล (ไม่อนุมัติ)';
                } else {
                    rejectButton.textContent = 'ไม่อนุมัติ';
                }
            }

            if (assignVehicleSelect) {
                assignVehicleSelect.value = data.approvalVehicleId || '';
                assignVehicleSelect.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }
            if (assignDriverSelect) {
                assignDriverSelect.value = data.approvalDriverId || '';
                assignDriverSelect.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }
            if (assignFinalApproverSelect) {
                assignFinalApproverSelect.value = data.approvalFinalApproverId || '';
                assignFinalApproverSelect.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }
            if (assignDriverTelInput) {
                const telValue = (data.approvalDriverTel || '').trim();
                assignDriverTelInput.value = telValue !== '' ? telValue : '-';
            }
            if (driverSearchInput) {
                const selected = assignDriverSelect ? assignDriverSelect.options[assignDriverSelect.selectedIndex] : null;
                const name = selected ? (selected.dataset.driverName || '') : '';
                driverSearchInput.value = name !== '' ? name : '';
            }
            if (driverItems.length > 0 && assignDriverSelect) {
                const selectedId = assignDriverSelect.value || '';
                driverItems.forEach((item) => {
                    item.setAttribute('aria-selected', item.dataset.value === selectedId ? 'true' : 'false');
                });
            }

            if (approvalActionButtons.length > 0) {
                const allowAction = canApproveStage || canRejectStage;
                approvalActionButtons.forEach(function(btn) {
                    const action = btn.getAttribute('data-vehicle-approval-submit');
                    const canUse = action === 'approve' ? canApproveStage : canRejectStage;
                    btn.disabled = !canUse;
                    btn.classList.toggle('disabled', !canUse);
                });
            }

            detailModal.classList.remove('hidden');
        }

        function closeDetailModal() {
            if (!detailModal) return;
            detailModal.classList.add('hidden');
            if (driverDropdown) {
                driverDropdown.classList.remove('show');
            }
        }

        function openConfirm(action) {
            const isAssignAction = action === 'approve' && canAssignStage;
            const isEditAssignAction = action === 'approve' && canEditAssignedStage;
            const isFinalApprove = action === 'approve' && canFinalizeStage;
            const isFinalReject = action === 'reject' && canFinalizeStage;
            const isFinalDecision = isFinalApprove || isFinalReject;
            const isDecisionOverride = canFinalizeStage && (currentStatus === 'APPROVED' || currentStatus === 'REJECTED');
            const alertType = action === 'reject' ? 'danger' : (isFinalApprove ? 'success' : 'warning');

            let title = 'ยืนยันการอนุมัติ';
            if (isAssignAction) {
                title = 'ยืนยันการมอบหมายรถ';
            } else if (isEditAssignAction) {
                title = 'ยืนยันการแก้ไขการมอบหมาย';
            } else if (isDecisionOverride) {
                if (action === 'approve') {
                    title = currentStatus === 'REJECTED' ?
                        'ยืนยันการเปลี่ยนผลเป็นอนุมัติ' :
                        'ยืนยันการบันทึกผล';
                } else {
                    title = currentStatus === 'APPROVED' ?
                        'ยืนยันการเปลี่ยนผลเป็นไม่อนุมัติ' :
                        'ยืนยันการบันทึกผล';
                }
            } else if (isFinalApprove) {
                title = 'ยืนยันการอนุมัติ';
            } else if (isFinalReject) {
                title = 'ยืนยันการไม่อนุมัติ';
            }

            let message = '';
            if (!(isFinalDecision || isDecisionOverride)) {
                if (isAssignAction) {
                    message = 'ต้องการมอบหมายรถและส่งต่อให้รองผู้อำนวยการพิจารณาใช่หรือไม่';
                } else if (isEditAssignAction) {
                    message = 'ต้องการบันทึกการแก้ไขยานพาหนะและผู้ขับรถใช่หรือไม่';
                }
            }

            let confirmButtonText = 'ตกลง';
            if (isAssignAction) {
                confirmButtonText = 'ยืนยันส่งต่อ';
            } else if (isEditAssignAction) {
                confirmButtonText = 'ยืนยันบันทึก';
            }

            const alertsApi = getAlertsApi();
            if (alertsApi && typeof alertsApi.confirm === 'function') {
                return alertsApi.confirm(message, {
                    title: title,
                    type: alertType,
                    confirmButtonText: confirmButtonText,
                    cancelButtonText: 'ยกเลิก',
                });
            }

            console.warn('Vehicle approval confirm dialog unavailable:', title, message);
            return Promise.resolve(false);
        }

        document.addEventListener('click', function(event) {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;

            const detailBtn = target.closest('[data-vehicle-approval-action="detail"]');
            if (detailBtn) {
                openDetailModal(detailBtn);
                return;
            }

            if (target.closest('[data-vehicle-approval-close]')) {
                closeDetailModal();
            }
        });

        closeButtons.forEach(function(btn) {
            btn.addEventListener('click', closeDetailModal);
        });

        if (detailModal) {
            detailModal.addEventListener('click', function(event) {
                if (event.target === detailModal) {
                    closeDetailModal();
                }
            });
        }

        approvalActionButtons.forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const action = button.getAttribute('data-vehicle-approval-submit');
                if (!action || !approvalActionInput) return;

                if (action === 'approve' && !canApproveStage) {
                    return;
                }
                if (action === 'reject' && !canRejectStage) {
                    return;
                }

                if (action === 'approve' && (canAssignStage || canEditAssignedStage)) {
                    const hasVehicle = assignVehicleSelect && assignVehicleSelect.value !== '';
                    const hasDriver = assignDriverSelect && assignDriverSelect.value !== '';
                    const hasFinalApprover = assignFinalApproverSelect && assignFinalApproverSelect.value !== '';
                    if (!hasVehicle || !hasDriver || !hasFinalApprover) {
                        showVehicleApprovalAlert('กรุณาเลือกยานพาหนะ ผู้ขับรถ และรองผู้อำนวยการก่อนบันทึก');
                        return;
                    }
                }

                approvalActionInput.value = action;
                openConfirm(action).then(function(approved) {
                    if (!approved) {
                        return;
                    }
                    submitApprovalForm();
                });
            });
        });

        if (assignDriverSelect && assignDriverTelInput) {
            assignDriverSelect.addEventListener('change', function() {
                const selected = assignDriverSelect.options[assignDriverSelect.selectedIndex];
                if (!selected || !selected.value) {
                    assignDriverTelInput.value = '-';
                    if (driverSearchInput) {
                        driverSearchInput.value = '';
                    }
                    driverItems.forEach((item) => item.setAttribute('aria-selected', 'false'));
                    if (driverEmptyState) {
                        driverEmptyState.classList.add('hidden');
                    }
                    return;
                }
                const tel = selected.dataset.driverTel || '';
                assignDriverTelInput.value = tel !== '' ? tel : '-';
                if (driverSearchInput) {
                    const name = selected.dataset.driverName || '';
                    driverSearchInput.value = name !== '' ? name : '';
                }
                const selectedId = selected.value || '';
                driverItems.forEach((item) => {
                    item.setAttribute('aria-selected', item.dataset.value === selectedId ? 'true' : 'false');
                    item.style.display = '';
                });
                if (driverEmptyState) driverEmptyState.classList.add('hidden');
            });
        }

        function openDriverDropdown() {
            if (!driverDropdown || !driverSearchInput) return;
            if (driverSearchInput.disabled) return;
            driverDropdown.classList.add('show');
            driverSearchInput.setAttribute('aria-expanded', 'true');
        }

        function closeDriverDropdown(restoreSelection) {
            if (!driverDropdown || !driverSearchInput) return;
            driverDropdown.classList.remove('show');
            driverSearchInput.setAttribute('aria-expanded', 'false');
            if (restoreSelection !== false && assignDriverSelect) {
                const selected = assignDriverSelect.options[assignDriverSelect.selectedIndex];
                const name = selected ? (selected.dataset.driverName || '') : '';
                driverSearchInput.value = name !== '' ? name : '';
            }
            if (driverEmptyState) driverEmptyState.classList.add('hidden');
            driverItems.forEach((item) => {
                item.style.display = '';
            });
        }

        function filterDriverDropdown(keyword) {
            const q = (keyword || '').toLowerCase().trim();
            let visibleCount = 0;
            driverItems.forEach((item) => {
                const name = (item.dataset.driverName || item.textContent || '').toLowerCase();
                const isMatch = q === '' || name.includes(q);
                item.style.display = isMatch ? '' : 'none';
                if (isMatch) visibleCount += 1;
            });
            if (driverEmptyState) {
                driverEmptyState.classList.toggle('hidden', q === '' || visibleCount > 0);
            }
        }

        if (driverSearchInput && driverDropdown) {
            driverSearchInput.addEventListener('focus', function() {
                if (assignDriverSelect) {
                    const selected = assignDriverSelect.options[assignDriverSelect.selectedIndex];
                    const selectedName = selected ? (selected.dataset.driverName || '') : '';
                    if (selectedName !== '' && driverSearchInput.value.trim() === selectedName) {
                        driverSearchInput.value = '';
                    }
                }
                openDriverDropdown();
                filterDriverDropdown(driverSearchInput.value);
            });
            driverSearchInput.addEventListener('click', function() {
                openDriverDropdown();
            });
            driverSearchInput.addEventListener('input', function() {
                openDriverDropdown();
                filterDriverDropdown(driverSearchInput.value);
            });
            driverSearchInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    // Prevent accidental form submit while searching/selecting.
                    event.preventDefault();
                    return;
                }
                if (event.key === 'Escape') {
                    closeDriverDropdown();
                }
            });
        }

        driverItems.forEach((item) => {
            item.addEventListener('click', function(event) {
                event.preventDefault();
                const driverId = item.dataset.value || '';
                if (!assignDriverSelect || driverId === '' || assignDriverSelect.disabled) {
                    closeDriverDropdown();
                    return;
                }
                assignDriverSelect.value = driverId;
                assignDriverSelect.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
                closeDriverDropdown(false);
            });
        });

        document.addEventListener('click', function(event) {
            if (!driverDropdownWrapper || !driverSearchInput) return;
            if (driverDropdownWrapper && !driverDropdownWrapper.contains(event.target)) {
                closeDriverDropdown();
            }
        });
    });
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
