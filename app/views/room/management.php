<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$room_status_classes = (array) ($room_status_classes ?? []);
$room_status_options = (array) ($room_status_options ?? []);
$room_management_rooms = (array) ($room_management_rooms ?? []);
$room_staff_members = (array) ($room_staff_members ?? []);
$room_candidate_members = (array) ($room_candidate_members ?? []);
$room_staff_count = (int) ($room_staff_count ?? count($room_staff_members));
$room_candidate_count = (int) ($room_candidate_count ?? count($room_candidate_members));
$room_management_alert = $room_management_alert ?? null;
$room_management_open_modal = (string) ($room_management_open_modal ?? '');
$room_filter_query = trim((string) ($room_filter_query ?? ''));
$room_filter_status = strtolower(trim((string) ($room_filter_status ?? 'all')));
$room_filter_room = trim((string) ($room_filter_room ?? 'all'));

$allowed_filter_statuses = ['all', 'available', 'paused', 'maintenance', 'unavailable'];

if (!in_array($room_filter_status, $allowed_filter_statuses, true)) {
    $room_filter_status = 'all';
}

if ($room_filter_room === '') {
    $room_filter_room = 'all';
}

$alert = $room_management_alert;

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

$room_status_filter_labels = [
    'all' => 'ทุกสถานะ',
    'available' => 'พร้อมใช้งาน',
    'paused' => 'ระงับชั่วคราว',
    'maintenance' => 'กำลังซ่อม',
    'unavailable' => 'ไม่พร้อมใช้งาน',
];
$room_status_filter_label = $room_status_filter_labels[$room_filter_status] ?? $room_status_filter_labels['all'];
$room_management_filter_params = [
    'q' => $room_filter_query,
    'status' => $room_filter_status,
    'room' => $room_filter_room,
];
$room_management_post_action = 'room-management.php?' . http_build_query($room_management_filter_params);

ob_start();
?>

<style>
    @media screen and (max-width: 768px) {
        .room-admin-search .form-input {
            padding: 0 10px 0 20px;
        }
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>จัดการสถานที่/ห้อง</p>
</div>

<div class="content-area room-admin-page" data-room-management data-room-open-modal="<?= h($room_management_open_modal) ?>">
    <section class="booking-card booking-list-card room-admin-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">รายการสถานที่/ห้อง</h2>
            </div>
            <form class="room-admin-actions" method="get" action="room-management.php" data-room-filter data-room-filter-form>
                <div class="room-admin-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input class="form-input" type="search" name="q" value="<?= h($room_filter_query) ?>" placeholder="ค้นหาชื่อห้องหรือสถานที่" autocomplete="off"
                        data-room-search-input>
                </div>
                <div class="room-admin-filter">
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value"><?= h($room_status_filter_label) ?></p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <div class="custom-option<?= $room_filter_status === 'all' ? ' selected' : '' ?>" data-value="all">ทุกสถานะ</div>
                            <div class="custom-option<?= $room_filter_status === 'available' ? ' selected' : '' ?>" data-value="available">พร้อมใช้งาน</div>
                            <div class="custom-option<?= $room_filter_status === 'paused' ? ' selected' : '' ?>" data-value="paused">ระงับชั่วคราว</div>
                            <div class="custom-option<?= $room_filter_status === 'maintenance' ? ' selected' : '' ?>" data-value="maintenance">กำลังซ่อม</div>
                            <div class="custom-option<?= $room_filter_status === 'unavailable' ? ' selected' : '' ?>" data-value="unavailable">ไม่พร้อมใช้งาน</div>
                        </div>

                        <select class="form-input" name="status" data-room-status-filter>
                            <option value="all" <?= $room_filter_status === 'all' ? 'selected' : '' ?>>ทุกสถานะ</option>
                            <option value="available" <?= $room_filter_status === 'available' ? 'selected' : '' ?>>พร้อมใช้งาน</option>
                            <option value="paused" <?= $room_filter_status === 'paused' ? 'selected' : '' ?>>ระงับชั่วคราว</option>
                            <option value="maintenance" <?= $room_filter_status === 'maintenance' ? 'selected' : '' ?>>กำลังซ่อม</option>
                            <option value="unavailable" <?= $room_filter_status === 'unavailable' ? 'selected' : '' ?>>ไม่พร้อมใช้งาน</option>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="room" value="<?= h($room_filter_room) ?>" data-room-filter-room>
                <button type="button" class="btn-confirm" data-room-modal-open="roomAddModal">เพิ่มสถานที่/ห้องใหม่</button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="custom-table booking-table room-admin-table">
                <thead>
                    <tr>
                        <th>ห้อง/สถานที่</th>
                        <th>สถานะ</th>
                        <th>หมายเหตุ</th>
                        <th>อัปเดตล่าสุด</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($room_management_rooms)) : ?>
                        <?php foreach ($room_management_rooms as $room) : ?>
                            <?php
                            $room_id = (int) ($room['roomID'] ?? 0);
                            $room_name = trim((string) ($room['roomName'] ?? ''));
                            $room_status = trim((string) ($room['roomStatus'] ?? ''));
                            $room_note = trim((string) ($room['roomNote'] ?? ''));
                            $created_at = (string) ($room['createdAt'] ?? '');
                            $updated_at = (string) ($room['updatedAt'] ?? '');
                            $display_updated = $updated_at !== '' && strpos($updated_at, '0000-00-00') !== 0
                                ? $updated_at
                                : $created_at;
                            $status_label = $room_status !== '' ? $room_status : 'ไม่พร้อมใช้งาน';
                            $status_class = $room_status_classes[$status_label] ?? 'paused';
                            $updated_label = $format_thai_datetime($display_updated);
                            $row_search = trim(implode(' ', [
                                $room_name,
                                $room_note,
                                $updated_label,
                                $status_label,
                            ]));
                            ?>
                            <tr data-room-row data-room-id="<?= h((string) $room_id) ?>" data-room-status="<?= h($status_class) ?>"
                                data-room-name="<?= h($room_name) ?>" data-room-status-label="<?= h($status_label) ?>"
                                data-room-note="<?= h($room_note) ?>" data-room-search="<?= h($row_search) ?>">
                                <td>
                                    <div class="room-admin-room-name">
                                        <?= h($room_name !== '' ? $room_name : 'ไม่ระบุชื่อห้อง') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="room-status-pill <?= h($status_class) ?>">
                                        <?= h($status_label) ?>
                                    </span>
                                </td>
                                <td><?= h($room_note !== '' ? $room_note : '-') ?></td>
                                <td><?= h($updated_label) ?></td>
                                <td class="room-admin-actions-cell">
                                    <div class="booking-action-group">
                                        <button type="button" class="booking-action-btn secondary" data-room-edit="true">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                            <span class="tooltip">แก้ไขข้อมูล</span>
                                        </button>
                                        <form method="POST" action="<?= h($room_management_post_action) ?>"
                                            data-room-delete-form>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="room_action" value="delete">
                                            <input type="hidden" name="room_id" value="<?= h((string) $room_id) ?>">
                                            <button type="button" class="booking-action-btn danger" data-room-delete-btn>
                                                <i class="fa-solid fa-trash"></i>
                                                <span class="tooltip danger">ลบข้อมูลสถานที่</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="booking-empty hidden" data-room-empty>
                        <td colspan="5">ไม่พบรายการที่ค้นหา</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="booking-card booking-list-card room-admin-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">ทีมผู้ดูแลสถานที่</h2>
            </div>
            <div>
                <div class="room-admin-member-count">ทั้งหมด <?= h((string) $room_staff_count) ?> คน</div>
                <button type="button" class="btn-outline" data-room-modal-open="roomMemberModal">เพิ่มสมาชิก</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table booking-table room-admin-member-table">
                <thead>
                    <tr>
                        <th>ชื่อ-สกุล</th>
                        <th>ตำแหน่ง</th>
                        <th>บทบาท</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($room_staff_members)) : ?>
                        <tr>
                            <td colspan="5" class="booking-empty">ยังไม่มีผู้รับผิดชอบห้อง</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($room_staff_members as $member) : ?>
                            <?php
                            $member_pid = (string) ($member['pID'] ?? '');
                            $member_name = trim((string) ($member['name'] ?? ''));
                            $member_position = trim((string) ($member['position_name'] ?? ''));
                            $member_role = trim((string) ($member['role_name'] ?? ''));
                            $member_department = trim((string) ($member['department_name'] ?? ''));
                            ?>
                            <tr>
                                <td><strong><?= h($member_name !== '' ? $member_name : 'ไม่ระบุชื่อ') ?></strong></td>
                                <td>
                                    <div class="room-admin-member-position"><?= h($member_position !== '' ? $member_position : '-') ?></div>
                                    <?php if ($member_department !== '') : ?>
                                        <div class="room-admin-member-subtext"><?= h($member_department) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="room-admin-member-tag">เจ้าหน้าที่สถานที่</span></td>
                                <td><span class="member-status-pill">กำลังปฏิบัติหน้าที่</span></td>
                                <td>
                                    <div class="booking-action-group">
                                        <form class="booking-action-form" data-member-remove-form method="POST"
                                            action="<?= h($room_management_post_action) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="member_action" value="remove">
                                            <input type="hidden" name="member_pid" value="<?= h($member_pid) ?>">
                                            <button type="submit" class="booking-action-btn danger">
                                                <i class="fa-solid fa-trash"></i>
                                                <span class="tooltip danger">นำออกจากบทบาท</span>
                                            </button>
                                        </form>
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

<div id="roomAddModal" class="modal-overlay hidden">
    <div class="modal-content room-admin-modal">
        <header class="modal-header">
            <div class="modal-title">
                <span>เพิ่มสถานที่/ห้องใหม่</span>
            </div>
            <div class="close-modal-btn" data-room-modal-close="roomAddModal">
                <i class="fa-solid fa-xmark"></i>
            </div>
        </header>
        <div class="modal-body room-admin-modal-body">
            <form id="roomAddForm" class="room-admin-form" method="POST" action="<?= h($room_management_post_action) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="room_action" value="add">
                <div class="form-group full">
                    <label class="form-label">ชื่อห้อง/สถานที่</label>
                    <input class="form-input" type="text" name="room_name" maxlength="150" required
                        placeholder="เช่น ห้องประชุมใหญ่">
                </div>
                <div class="form-group full">
                    <label class="form-label">สถานะ</label>
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value">พร้อมใช้งาน</p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <?php foreach ($room_status_options as $status_label): ?>
                                <div class="custom-option" data-value="<?= h($status_label) ?>"><?= h($status_label) ?></div>
                            <?php endforeach; ?>
                        </div>

                        <select class="form-input" name="room_status">
                            <?php foreach ($room_status_options as $status_label): ?>
                                <option value="<?= h($status_label) ?>" <?= $status_label === 'พร้อมใช้งาน' ? 'selected' : '' ?>>
                                    <?= h($status_label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group full">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea class="form-input" name="room_note" rows="3" placeholder="ระบุรายละเอียดเพิ่มเติม"></textarea>
                </div>
            </form>
        </div>
        <div class="footer-modal">
            <button type="submit"
                form="roomAddForm"
                data-confirm="ยืนยันการบันทึกสถานที่/ห้องใหม่ใช่หรือไม่?"
                data-confirm-title="ยืนยันการบันทึก"
                data-confirm-ok="ยืนยัน"
                data-confirm-cancel="ยกเลิก">
                <p>บันทึก</p>
            </button>
        </div>
    </div>
</div>

<div id="roomEditModal" class="modal-overlay hidden">
    <div class="modal-content room-admin-modal">
        <header class="modal-header">
            <div class="modal-title">
                <span>แก้ไขข้อมูลห้อง</span>
            </div>
            <div class="close-modal-btn" data-room-modal-close="roomEditModal">
                <i class="fa-solid fa-xmark"></i>
            </div>
        </header>
        <div class="modal-body room-admin-modal-body">
            <form id="roomEditForm" class="room-admin-form" method="POST" action="<?= h($room_management_post_action) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="room_action" value="edit">
                <input type="hidden" name="room_id" data-room-edit-id>
                <div class="form-group full">
                    <label class="form-label">ชื่อห้อง/สถานที่</label>
                    <input class="form-input" type="text" name="room_name" maxlength="150" required
                        data-room-edit-name>
                </div>
                <div class="form-group full">
                    <label class="form-label">สถานะ</label>
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value">สถานะ</p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <?php foreach ($room_status_options as $status_label): ?>
                                <div class="custom-option" data-value="<?= h($status_label) ?>"><?= h($status_label) ?></div>
                            <?php endforeach; ?>
                        </div>

                        <select class="form-input" name="room_status" data-room-edit-status>
                            <?php foreach ($room_status_options as $status_label): ?>
                                <option value="<?= h($status_label) ?>"><?= h($status_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group full">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea class="form-input" name="room_note" rows="3" data-room-edit-note placeholder="ระบุรายละเอียดเพิ่มเติม"></textarea>
                </div>
            </form>
        </div>
        <div class="footer-modal">
            <button type="submit"
                form="roomEditForm"
                data-confirm="ยืนยันการบันทึกการเปลี่ยนแปลงข้อมูลห้องใช่หรือไม่?"
                data-confirm-title="ยืนยันการบันทึก"
                data-confirm-ok="ยืนยัน"
                data-confirm-cancel="ยกเลิก">
                <p>บันทึก</p>
            </button>
        </div>
    </div>
</div>

<div id="roomMemberModal" class="modal-overlay hidden">
    <div class="modal-content room-admin-modal room-admin-member-modal">
        <header class="modal-header">
            <div class="modal-title">
                <span>เพิ่มสมาชิกทีมผู้ดูแล</span>
            </div>
            <div class="close-modal-btn" data-room-modal-close="roomMemberModal">
                <i class="fa-solid fa-xmark"></i>
            </div>
        </header>
        <div class="modal-body room-admin-modal-body">
            <form class="room-admin-search-form" data-member-search-form>
                <div class="room-admin-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input class="form-input" type="search" placeholder="ค้นหาบุคลากร" autocomplete="off" data-member-search>
                </div>
            </form>

            <div class="room-admin-member-count" data-member-count>
                ทั้งหมด <?= h((string) $room_candidate_count) ?> คน
            </div>

            <div class="table-responsive">
                <table class="custom-table booking-table room-admin-member-table">
                    <thead>
                        <tr>
                            <th>ชื่อ-สกุล</th>
                            <th>กลุ่มสาระฯ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $candidate_seen = [];

                        foreach ($room_candidate_members as $candidate):
                            $candidate_pid = trim((string) ($candidate['pID'] ?? ''));

                            if ($candidate_pid === '' || isset($candidate_seen[$candidate_pid])) {
                                continue;
                            }
                            $candidate_seen[$candidate_pid] = true;

                            $candidate_name = trim((string) ($candidate['name'] ?? ''));
                            $candidate_name = $candidate_name !== '' ? $candidate_name : 'ไม่ระบุชื่อ';
                            $candidate_department = trim((string) ($candidate['department_name'] ?? ''));
                            $candidate_department = $candidate_department !== '' ? $candidate_department : '-';
                            $candidate_position = trim((string) ($candidate['position_name'] ?? ''));
                            $candidate_tel = trim((string) ($candidate['telephone'] ?? ''));

                            $member_search = trim(implode(' ', array_filter([
                                $candidate_pid,
                                $candidate_name,
                                $candidate_department,
                                $candidate_position,
                                $candidate_tel,
                            ])));
                        ?>
                            <tr data-member-row data-member-search="<?= h($member_search) ?>">
                                <td>
                                    <strong><?= h($candidate_name) ?></strong>
                                    <?php if ($candidate_position !== ''): ?>
                                        <div class="detail-subtext"><?= h($candidate_position) ?></div>
                                    <?php endif; ?>
                                    <?php if ($candidate_tel !== ''): ?>
                                        <div class="detail-subtext">โทร <?= h($candidate_tel) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span><?= h($candidate_department) ?></span></td>
                                <td>
                                    <div class="booking-action-group">
                                        <form class="booking-action-form" method="POST"
                                            action="<?= h($room_management_post_action) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="member_action" value="add">
                                            <input type="hidden" name="member_pid" value="<?= h($candidate_pid) ?>">
                                            <button type="submit" class="booking-action-btn add" data-member-add-btn>
                                                <i class="fa-solid fa-plus"></i>
                                                <span class="tooltip">เพิ่มสมาชิก</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="booking-empty <?= empty($room_candidate_members) ? '' : 'hidden' ?>" data-member-empty>
                            <td colspan="3">ไม่พบบุคลากรที่สามารถเพิ่มได้</td>
                        </tr>

                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
