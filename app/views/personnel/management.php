<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$personnel_rows = (array) ($personnel_rows ?? []);
$open_modal = (string) ($open_modal ?? '');
$form_values = array_merge([
    'original_pid' => '',
    'pID' => '',
    'fName' => '',
    'fID' => 0,
    'dID' => 0,
    'lID' => 0,
    'oID' => 0,
    'positionID' => 0,
    'roleIDs' => [6],
    'telephone' => '',
    'picture' => '',
    'signature' => '',
    'passWord' => '',
    'LineID' => '',
    'status' => 1,
], (array) ($form_values ?? []));
$edit_values = array_merge([
    'original_pid' => '',
    'pID' => '',
    'fName' => '',
    'fID' => 0,
    'dID' => 0,
    'lID' => 0,
    'oID' => 0,
    'positionID' => 0,
    'roleIDs' => [6],
    'telephone' => '',
    'picture' => '',
    'signature' => '',
    'passWord' => '',
    'LineID' => '',
    'status' => 1,
], (array) ($edit_values ?? []));
$faction_options = (array) ($faction_options ?? [0 => 'ไม่กำหนด']);
$department_options = (array) ($department_options ?? [0 => 'ไม่กำหนด']);
$level_options = (array) ($level_options ?? [0 => 'ไม่กำหนด']);
$legacy_position_options = (array) ($legacy_position_options ?? [0 => 'ไม่กำหนด']);
$position_options = (array) ($position_options ?? [0 => 'ไม่กำหนด']);
$role_rows = (array) ($role_rows ?? []);
$active_count = (int) ($active_count ?? 0);
$inactive_count = (int) ($inactive_count ?? 0);

$format_role_ids = static function ($value): array {
    $ids = [];

    foreach ((array) $value as $item) {
        foreach (preg_split('/\s*,\s*/', trim((string) $item)) ?: [] as $part) {
            $part = trim($part);

            if ($part === '' || !ctype_digit($part)) {
                continue;
            }

            $role_id = (int) $part;

            if ($role_id > 0) {
                $ids[] = $role_id;
            }
        }
    }

    return array_values(array_unique($ids));
};

$form_role_ids = $format_role_ids($form_values['roleIDs'] ?? []);
$edit_role_ids = $format_role_ids($edit_values['roleIDs'] ?? []);

ob_start();
?>
<style>
    .room-admin-table td {
        vertical-align: top;
    }

    .form-group.full {
        grid-column: 1 / -1;
    }

    .input-group {
        flex-direction: column;
        align-items: flex-start;
        gap: 0;
    }

    .search-input-wrapper .search-input:focus,
    .search-input-wrapper .search-input:active {
        outline: none;
    }

    #recipientModal .modal-title {
        color: var(--color-secondary);
    }

    .content-area .btn-upload {
        background-color: var(--color-secondary);
        color: var(--color-neutral-lightest);
        width: auto;
        padding: 0px 20px;
    }

    .signature-upload-btn p {
        margin: 0;
    }

    .signature-content {
        display: flex;
        gap: 20px;
        align-items: center;
        margin-top: 0;
    }

    .signature-box {
        width: 200px;
        height: 100px;
        border: 1px dashed var(--color-neutral-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        overflow: hidden;
        background: var(--color-neutral-lightest);
    }

    .signature-preview {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    .signature-preview.hidden {
        display: none;
    }

    .signature-field {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    @media (max-width: 900px) {
        .signature-content {
            flex-direction: column;
            align-items: flex-start;
        }

        #personnelAddModal [data-personnel-role-section],
        #personnelEditModal [data-personnel-role-section] {
            flex-direction: column;
            align-items: stretch;
        }

        #personnelAddModal [data-personnel-role-section] .dropdown-container,
        #personnelEditModal [data-personnel-role-section] .dropdown-container {
            width: 50%;
            flex-basis: auto;
        }
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>จัดการบุคลากร</p>
</div>

<div class="content-area room-admin-page personnel-admin-page" data-personnel-management data-personnel-open-modal="<?= h($open_modal) ?>">
    <section class="booking-card booking-list-card room-admin-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">รายชื่อบุคลากรภายในโรงเรียน</h2>
            </div>
            <div class="room-admin-actions" data-personnel-filter>
                <div class="room-admin-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input class="form-input" type="search" placeholder="ค้นหาชื่อ รหัสประชาชน กลุ่ม หน่วยงาน หรือบทบาท"
                        autocomplete="off" data-personnel-search-input>
                </div>
                <div class="room-admin-filter">
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value">ทุกสถานะ</p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <div class="custom-option selected" data-value="all">ทุกสถานะ</div>
                            <div class="custom-option" data-value="1">กำลังใช้งาน</div>
                            <div class="custom-option" data-value="0">ปิดใช้งาน</div>
                        </div>

                        <select class="form-input" data-personnel-status-filter>
                            <option value="all" selected>ทุกสถานะ</option>
                            <option value="1">กำลังใช้งาน</option>
                            <option value="0">ปิดใช้งาน</option>
                        </select>
                    </div>
                </div>
                <button type="button" class="btn-confirm" data-personnel-modal-open="personnelAddModal">เพิ่มบุคลากรใหม่</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table booking-table room-admin-table personnel-admin-table">
                <thead>
                    <tr>
                        <th>ชื่อ-นามสกุล</th>
                        <th>กลุ่ม/หน่วยงาน</th>
                        <th>ตำแหน่ง</th>
                        <th>บทบาท</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($personnel_rows)) : ?>
                        <tr>
                            <td colspan="6" class="booking-empty">ไม่พบข้อมูลบุคลากร</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($personnel_rows as $row) : ?>
                            <?php
                            $pid = trim((string) ($row['pID'] ?? ''));
                            $name = trim((string) ($row['fName'] ?? ''));
                            $faction_name = trim((string) ($row['factionName'] ?? ''));
                            $department_name = trim((string) ($row['departmentName'] ?? ''));
                            $level_name = trim((string) ($row['levelName'] ?? ''));
                            $legacy_position_name = trim((string) ($row['legacyPositionName'] ?? ''));
                            $system_position_name = trim((string) ($row['systemPositionName'] ?? ''));
                            $role_name = trim((string) ($row['roleName'] ?? ''));
                            $telephone = trim((string) ($row['telephone'] ?? ''));
                            $picture = trim((string) ($row['picture'] ?? ''));
                            $signature = trim((string) ($row['signature'] ?? ''));
                            $line_id = trim((string) ($row['LineID'] ?? ''));
                            $status = (int) ($row['status'] ?? 0);
                            $role_ids_csv = trim((string) ($row['roleID'] ?? ''));
                            $search_text = trim(implode(' ', [
                                $pid,
                                $name,
                                $faction_name,
                                $department_name,
                                $level_name,
                                $legacy_position_name,
                                $system_position_name,
                                $role_name,
                                $telephone,
                            ]));
                            $position_display = $system_position_name !== '' ? $system_position_name : ($legacy_position_name !== '' ? $legacy_position_name : '-');
                            ?>
                            <tr
                                data-personnel-row
                                data-personnel-search="<?= h($search_text) ?>"
                                data-personnel-status="<?= h((string) $status) ?>"
                                data-pid="<?= h($pid) ?>"
                                data-name="<?= h($name) ?>"
                                data-fid="<?= h((string) ((int) ($row['fID'] ?? 0))) ?>"
                                data-did="<?= h((string) ((int) ($row['dID'] ?? 0))) ?>"
                                data-lid="<?= h((string) ((int) ($row['lID'] ?? 0))) ?>"
                                data-oid="<?= h((string) ((int) ($row['oID'] ?? 0))) ?>"
                                data-position-id="<?= h((string) ((int) ($row['positionID'] ?? 0))) ?>"
                                data-role-ids="<?= h($role_ids_csv) ?>"
                                data-telephone="<?= h($telephone) ?>"
                                data-picture="<?= h($picture) ?>"
                                data-signature="<?= h($signature) ?>"
                                data-line-id="<?= h($line_id) ?>"
                                data-status-value="<?= h((string) $status) ?>">
                                <td>
                                    <div class="room-admin-room-name"><?= h($name !== '' ? $name : '-') ?></div>
                                </td>
                                <td>
                                    <div class="room-admin-room-name"><?= h($faction_name !== '' ? $faction_name : '-') ?></div>
                                </td>
                                <td>
                                    <div class="room-admin-room-name"><?= h($position_display) ?></div>
                                </td>
                                <td><?= h($role_name !== '' ? $role_name : '-') ?></td>
                                <td>
                                    <span class="room-status-pill <?= $status === 1 ? 'available' : 'unavailable' ?>">
                                        <?= h($status === 1 ? 'กำลังใช้งาน' : 'ปิดใช้งาน') ?>
                                    </span>
                                </td>
                                <td class="room-admin-actions-cell">
                                    <div class="booking-action-group">
                                        <button type="button" class="booking-action-btn secondary" data-personnel-edit="true">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                            <span class="tooltip">แก้ไขข้อมูลบุคลากร</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="hidden" data-personnel-empty>
                        <td colspan="6" class="booking-empty">ไม่พบข้อมูลตามเงื่อนไขที่ค้นหา</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div id="personnelAddModal" class="modal-overlay hidden">
        <div class="modal-content room-admin-modal">
            <header class="modal-header">
                <div class="modal-title">
                    <span>เพิ่มบุคลากรใหม่</span>
                </div>
                <div class="close-modal-btn" data-personnel-modal-close="personnelAddModal">
                    <i class="fa-solid fa-xmark"></i>
                </div>
            </header>

            <div class="modal-body room-admin-modal-body personnel-modal-scroll">
                <form class="room-admin-form" style="grid-template-columns: none;" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? 'personnel-management.php') ?>" id="personnelAddForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="personnel_action" value="create">

                    <div class="personnel-form-grid">
                        <div class="form-group">
                            <label class="form-label" for="">เลขประจำตัวประชาชน</label>
                            <input class="form-input" type="text" id="" name="pID" value="<?= h((string) ($form_values['pID'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">ชื่อจริง-นามสกุล</label>
                            <input class="form-input" type="text" id="" name="fName" value="" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">รหัสผ่าน</label>
                            <input class="form-input" type="text" id="" name="passWord" value="" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">โทรศัพท์</label>
                            <input class="form-input" type="tel" id="" name="telephone" value="<?= h((string) ($form_values['telephone'] ?? '')) ?>" inputmode="numeric" maxlength="10" pattern="\d{0,10}" title="กรอกเฉพาะตัวเลขไม่เกิน 10 หลัก">
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">กลุ่มงาน</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($faction_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="fID" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">กลุ่มสาระฯ</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($department_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="dID" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">วิทยฐานะ</label>
                            <div class="custom-select-wrapper">
                                <div class="custom-select-trigger">
                                    <p class="select-value"><?= h((string) ($level_options[1] ?? 'ไม่กำหนด')) ?></p>
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="custom-options">
                                    <?php foreach ($level_options as $id => $label) : ?>
                                        <div class="custom-option <?= (int) $id === 1 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="lID" value="1">
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">ตำแหน่ง</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($position_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="positionID" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">ประเภทบุคลากร</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($legacy_position_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="oID" value="0">
                                </div>
                            </div>
                        </div>

                        <div class="form-group full" data-personnel-role-section>
                            <label><strong>บทบาท :</strong></label>
                            <div class="dropdown-container">
                                <div class="search-input-wrapper">
                                    <input type="text" class="search-input" value="เลือกบทบาท" placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </div>

                                <div class="dropdown-content">
                                    <div class="dropdown-header">
                                        <label class="select-all-box">
                                            <input type="checkbox" data-role-select-all>เลือกทั้งหมด
                                        </label>
                                    </div>

                                    <div class="dropdown-list">
                                        <div class="category-group">
                                            <div class="category-title">
                                                <span>บทบาท</span>
                                            </div>
                                            <div class="category-items">
                                                <?php foreach ($role_rows as $role_row) : ?>
                                                    <?php
                                                    $role_id = (int) ($role_row['id'] ?? 0);
                                                    $role_name = trim((string) ($role_row['name'] ?? ''));

                                                    if ($role_id <= 0 || $role_name === '') {
                                                        continue;
                                                    }
                                                    ?>
                                                    <label class="item role-item">
                                                        <input type="checkbox" class="role-checkbox"
                                                            name="role_ids[]" value="<?= h((string) $role_id) ?>"
                                                            data-role-name="<?= h($role_name) ?>"
                                                            <?= in_array($role_id, $form_role_ids, true) ? 'checked' : '' ?>>
                                                        <span class="item-title"><?= h($role_name) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php if (false && !empty($factions)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>หน่วยงาน</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($factions as $faction) : ?>
                                                        <?php
                                                        $fid = (int) ($faction['fID'] ?? 0);

                                                        if ($fid <= 0) {
                                                            continue;
                                                        }
                                                        $fid_value = (string) $fid;
                                                        $faction_name = trim((string) ($faction['fName'] ?? ''));

                                                        if ($faction_name === '' || strpos($faction_name, 'ฝ่ายบริหาร') !== false) {
                                                            continue;
                                                        }
                                                        $members = $faction_members[$fid] ?? [];
                                                        $member_payload = [];

                                                        foreach ($members as $member) {
                                                            $member_payload[] = [
                                                                'pID' => (string) ($member['pID'] ?? ''),
                                                                'name' => (string) ($member['name'] ?? ''),
                                                                'faction' => $faction_name,
                                                            ];
                                                        }
                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                        if ($member_payload_json === false) {
                                                            $member_payload_json = '[]';
                                                        }
                                                        $member_total = count($members);
                                                        $has_selected_member = false;

                                                        foreach ($members as $member) {
                                                            $member_pid = (string) ($member['pID'] ?? '');

                                                            if ($member_pid !== '' && $is_selected($member_pid, $selected_people)) {
                                                                $has_selected_member = true;
                                                                break;
                                                            }
                                                        }
                                                        $expanded_by_default = $is_selected($fid_value, $selected_factions) || $has_selected_member;
                                                        ?>
                                                        <div class="item item-group<?= $expanded_by_default ? '' : ' is-collapsed' ?>" data-faction-id="<?= h($fid_value) ?>">
                                                            <div class="group-header">
                                                                <label class="item-main">
                                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction"
                                                                        data-group-key="faction-<?= h($fid_value) ?>"
                                                                        data-group-label="<?= h($faction_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        name="faction_ids[]" value="<?= h($fid_value) ?>" <?= h($is_selected($fid_value, $selected_factions) ? 'checked' : '') ?>>
                                                                    <span class="item-title"><?= h($faction_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $expanded_by_default ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php if ($member_total === 0) : ?>
                                                                    <li>
                                                                        <span class="item-subtext">ไม่มีสมาชิกในฝ่ายนี้</span>
                                                                    </li>
                                                                <?php else : ?>
                                                                    <?php foreach ($members as $member) : ?>
                                                                        <?php
                                                                        $member_pid = (string) ($member['pID'] ?? '');
                                                                        $member_name = (string) ($member['name'] ?? '');

                                                                        if ($member_pid === '' || $member_name === '') {
                                                                            continue;
                                                                        }
                                                                        ?>
                                                                        <li>
                                                                            <label class="item member-item">
                                                                                <input type="checkbox" class="member-checkbox"
                                                                                    data-member-group-key="faction-<?= h($fid_value) ?>"
                                                                                    data-member-name="<?= h($member_name) ?>"
                                                                                    data-group-label="<?= h($faction_name) ?>"
                                                                                    name="person_ids[]" value="<?= h($member_pid) ?>" <?= h($is_selected($member_pid, $selected_people) ? 'checked' : '') ?>>
                                                                                <span class="member-name"><?= h($member_name) ?></span>
                                                                            </label>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </ol>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (false && !empty($department_groups)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>กลุ่มสาระ</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($department_groups as $department_group) : ?>
                                                        <?php
                                                        $did = (int) ($department_group['dID'] ?? 0);
                                                        $department_name = trim((string) ($department_group['name'] ?? ''));
                                                        $members = (array) ($department_group['members'] ?? []);

                                                        if ($did <= 0 || $department_name === '' || empty($members)) {
                                                            continue;
                                                        }

                                                        $member_payload = [];
                                                        $has_selected_member = false;

                                                        foreach ($members as $member) {
                                                            $member_pid = (string) ($member['pID'] ?? '');
                                                            $member_name = (string) ($member['name'] ?? '');

                                                            if ($member_pid === '' || $member_name === '') {
                                                                continue;
                                                            }

                                                            if ($is_selected($member_pid, $selected_people)) {
                                                                $has_selected_member = true;
                                                            }
                                                            $member_payload[] = [
                                                                'pID' => $member_pid,
                                                                'name' => $member_name,
                                                                'faction' => $department_name,
                                                            ];
                                                        }

                                                        if (empty($member_payload)) {
                                                            continue;
                                                        }
                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                        if ($member_payload_json === false) {
                                                            $member_payload_json = '[]';
                                                        }
                                                        $member_total = count($member_payload);
                                                        $group_key = 'department-' . $did;
                                                        ?>
                                                        <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main">
                                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department"
                                                                        data-group-key="<?= h($group_key) ?>"
                                                                        data-group-label="<?= h($department_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        value="<?= h($group_key) ?>">
                                                                    <span class="item-title"><?= h($department_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php foreach ($member_payload as $member) : ?>
                                                                    <li>
                                                                        <label class="item member-item">
                                                                            <input type="checkbox" class="member-checkbox"
                                                                                data-member-group-key="<?= h($group_key) ?>"
                                                                                data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                                data-group-label="<?= h($department_name) ?>"
                                                                                name="person_ids[]" value="<?= h((string) ($member['pID'] ?? '')) ?>" <?= h($is_selected((string) ($member['pID'] ?? ''), $selected_people) ? 'checked' : '') ?>>
                                                                            <span class="member-name"><?= h((string) ($member['name'] ?? '')) ?></span>
                                                                        </label>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ol>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (false && !empty($special_groups)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>อื่นๆ</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($special_groups as $special_group) : ?>
                                                        <?php
                                                        $group_key = trim((string) ($special_group['key'] ?? ''));
                                                        $group_name = trim((string) ($special_group['name'] ?? ''));
                                                        $members = (array) ($special_group['members'] ?? []);

                                                        if ($group_key === '' || $group_name === '' || empty($members)) {
                                                            continue;
                                                        }

                                                        $member_payload = [];
                                                        $has_selected_member = false;

                                                        foreach ($members as $member) {
                                                            $member_pid = (string) ($member['pID'] ?? '');
                                                            $member_name = (string) ($member['name'] ?? '');

                                                            if ($member_pid === '' || $member_name === '') {
                                                                continue;
                                                            }

                                                            if ($is_selected($member_pid, $selected_people)) {
                                                                $has_selected_member = true;
                                                            }
                                                            $member_payload[] = [
                                                                'pID' => $member_pid,
                                                                'name' => $member_name,
                                                                'faction' => $group_name,
                                                            ];
                                                        }

                                                        if (empty($member_payload)) {
                                                            continue;
                                                        }

                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                        if ($member_payload_json === false) {
                                                            $member_payload_json = '[]';
                                                        }
                                                        $member_total = count($member_payload);
                                                        ?>
                                                        <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main">
                                                                    <input type="checkbox" class="item-checkbox group-item-checkbox" data-group="special"
                                                                        data-group-key="<?= h($group_key) ?>"
                                                                        data-group-label="<?= h($group_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        value="<?= h($group_key) ?>">
                                                                    <span class="item-title"><?= h($group_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php foreach ($member_payload as $member) : ?>
                                                                    <li>
                                                                        <label class="item member-item">
                                                                            <input type="checkbox" class="member-checkbox"
                                                                                data-member-group-key="<?= h($group_key) ?>"
                                                                                data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                                data-group-label="<?= h($group_name) ?>"
                                                                                name="person_ids[]" value="<?= h((string) ($member['pID'] ?? '')) ?>" <?= h($is_selected((string) ($member['pID'] ?? ''), $selected_people) ? 'checked' : '') ?>>
                                                                            <span class="member-name"><?= h((string) ($member['name'] ?? '')) ?></span>
                                                                        </label>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ol>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="sent-notice-selected">
                                <button type="button">
                                    <p>แสดงบทบาทที่เลือก</p>
                                </button>
                            </div>
                        </div>

                        <div class="modal-overlay-recipient">
                            <div class="modal-container">
                                <div class="modal-header">
                                    <div class="modal-title">
                                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                                        <span>บทบาทที่เลือก</span>
                                    </div>
                                    <button class="modal-close" type="button">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <table class="recipient-table">
                                        <thead>
                                            <tr>
                                                <th>ลำดับ</th>
                                                <th>ชื่อบทบาท</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="form-group full">
                            <div class="signature-content">
                                <div class="signature-box">
                                    <img class="signature-preview hidden" src="" alt="Signature">
                                    <p class="no-signature-text">ไม่มีลายเซ็นในระบบ</p>
                                </div>
                                <div class="signature-field">
                                    <input type="file" name="signature_file" accept="image/jpeg, image/png" class="hidden-signature-input" style="display: none;">
                                    <button type="button" class="btn-upload signature-upload-btn">
                                        แนบลายเซ็น
                                    </button>
                                    <div class="file-hint">รองรับไฟล์นามสกุล .JPG , .PNG เท่านั้น ขนาดไม่เกิน 2MB</div>
                                </div>
                            </div>
                        </div>

                    </div>
                </form>
            </div>
            <div class="footer-modal">
                <form method="POST" action="" class="orders-send-form">
                    <button type="submit" form="personnelAddForm"
                        data-confirm="ยืนยันการเพิ่มบุคลากรใหม่ใช่หรือไม่?"
                        data-confirm-title="ยืนยันการบันทึกข้อมูล"
                        data-confirm-ok="ยืนยัน"
                        data-confirm-cancel="ยกเลิก">
                        <p>บันทึกข้อมูล</p>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="personnelEditModal" class="modal-overlay hidden">
        <div class="modal-content room-admin-modal">
            <header class="modal-header">
                <div class="modal-title">
                    <span>แก้ไขข้อมูลบุคลากร</span>
                </div>
                <div class="close-modal-btn" data-personnel-modal-close="personnelEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </div>
            </header>

            <div class="modal-body room-admin-modal-body personnel-modal-scroll">
                <form class="room-admin-form" style="grid-template-columns: none;" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? 'personnel-management.php') ?>" id="personnelEditForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="personnel_action" value="update">
                    <input type="hidden" name="original_pid" id="personnelEditOriginalPid" value="<?= h((string) ($edit_values['original_pid'] ?? '')) ?>">
                    <input type="hidden" name="pID" id="personnelEditPid" value="<?= h((string) ($edit_values['pID'] ?? '')) ?>">

                    <div class="personnel-form-grid">
                        <div class="form-group">
                            <label class="form-label" for="">เลขประจำตัวประชาชน</label>
                            <input class="form-input" type="text" id="personnelEditPidDisplay" value="" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">ชื่อจริง-นามสกุล</label>
                            <input class="form-input" type="text" id="personnelEditName" name="fName" value="" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">โทรศัพท์</label>
                            <input class="form-input" type="tel" id="personnelEditTelephone" name="telephone" value="<?= h((string) ($edit_values['telephone'] ?? '')) ?>" inputmode="numeric" maxlength="10" pattern="\d{0,10}" title="กรอกเฉพาะตัวเลขไม่เกิน 10 หลัก">
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">กลุ่มงาน</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($faction_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="fID" value="0" id="personnelEditFaction">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">กลุ่มสาระฯ</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($department_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="dID" value="0" id="personnelEditDepartment">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="">วิทยฐานะ</label>
                            <div class="custom-select-wrapper">
                                <div class="custom-select-trigger">
                                    <p class="select-value">ไม่กำหนด</p>
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="custom-options">
                                    <?php foreach ($level_options as $id => $label) : ?>
                                        <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="lID" value="0" id="personnelEditLevel">
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">ตำแหน่ง</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($position_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="positionID" value="0" id="personnelEditPosition">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                                <label class="form-label" for="">ประเภทบุคลากร</label>
                                <div class="custom-select-wrapper">
                                    <div class="custom-select-trigger">
                                        <p class="select-value">ไม่กำหนด</p>
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </div>
                                    <div class="custom-options">
                                        <?php foreach ($legacy_position_options as $id => $label) : ?>
                                            <div class="custom-option <?= $id == 0 ? 'selected' : '' ?>" data-value="<?= h((string) $id) ?>"><?= h((string) $label) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="oID" value="0" id="personnelEditLegacyPosition">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="">สถานะการใช้งาน</label>
                            <div class="custom-select-wrapper">
                                <div class="custom-select-trigger">
                                    <p class="select-value">กำลังใช้งาน</p>
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="custom-options">
                                    <div class="custom-option selected" data-value="1">กำลังใช้งาน</div>
                                    <div class="custom-option" data-value="0">ปิดใช้งาน</div>
                                </div>
                                <input type="hidden" name="status" value="1" id="personnelEditStatus">
                            </div>
                        </div>

                        <div class="form-group full" data-personnel-role-section>
                            <label><strong>บทบาท :</strong></label>
                            <div class="dropdown-container">
                                <div class="search-input-wrapper">
                                    <input type="text" class="search-input" value="เลือกบทบาท" placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </div>

                                <div class="dropdown-content">
                                    <div class="dropdown-header">
                                        <label class="select-all-box">
                                            <input type="checkbox" data-role-select-all>เลือกทั้งหมด
                                        </label>
                                    </div>

                                    <div class="dropdown-list">
                                        <div class="category-group">
                                            <div class="category-title">
                                                <span>บทบาท</span>
                                            </div>
                                            <div class="category-items">
                                                <?php foreach ($role_rows as $role_row) : ?>
                                                    <?php
                                                    $role_id = (int) ($role_row['id'] ?? 0);
                                                    $role_name = trim((string) ($role_row['name'] ?? ''));

                                                    if ($role_id <= 0 || $role_name === '') {
                                                        continue;
                                                    }
                                                    ?>
                                                    <label class="item role-item">
                                                        <input type="checkbox" class="role-checkbox"
                                                            name="role_ids[]" value="<?= h((string) $role_id) ?>"
                                                            data-role-name="<?= h($role_name) ?>"
                                                            <?= in_array($role_id, $edit_role_ids, true) ? 'checked' : '' ?>>
                                                        <span class="item-title"><?= h($role_name) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php if (false && !empty($factions)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>หน่วยงาน</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($factions as $faction) : ?>
                                                        <?php
                                                        $fid = (int) ($faction['fID'] ?? 0);

                                                        if ($fid <= 0) {
                                                            continue;
                                                        }
                                                        $fid_value = (string) $fid;
                                                        $faction_name = trim((string) ($faction['fName'] ?? ''));

                                                        if ($faction_name === '' || strpos($faction_name, 'ฝ่ายบริหาร') !== false) {
                                                            continue;
                                                        }
                                                        $members = $faction_members[$fid] ?? [];
                                                        $member_payload = [];

                                                        foreach ($members as $member) {
                                                            $member_payload[] = [
                                                                'pID' => (string) ($member['pID'] ?? ''),
                                                                'name' => (string) ($member['name'] ?? ''),
                                                                'faction' => $faction_name,
                                                            ];
                                                        }
                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                        if ($member_payload_json === false) {
                                                            $member_payload_json = '[]';
                                                        }
                                                        $member_total = count($members);
                                                        $has_selected_member = false;

                                                        foreach ($members as $member) {
                                                            $member_pid = (string) ($member['pID'] ?? '');

                                                            if ($member_pid !== '' && $is_selected($member_pid, $selected_people)) {
                                                                $has_selected_member = true;
                                                                break;
                                                            }
                                                        }
                                                        $expanded_by_default = $is_selected($fid_value, $selected_factions) || $has_selected_member;
                                                        ?>
                                                        <div class="item item-group<?= $expanded_by_default ? '' : ' is-collapsed' ?>" data-faction-id="<?= h($fid_value) ?>">
                                                            <div class="group-header">
                                                                <label class="item-main">
                                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction"
                                                                        data-group-key="faction-<?= h($fid_value) ?>"
                                                                        data-group-label="<?= h($faction_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        name="faction_ids[]" value="<?= h($fid_value) ?>" <?= h($is_selected($fid_value, $selected_factions) ? 'checked' : '') ?>>
                                                                    <span class="item-title"><?= h($faction_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $expanded_by_default ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php if ($member_total === 0) : ?>
                                                                    <li>
                                                                        <span class="item-subtext">ไม่มีสมาชิกในฝ่ายนี้</span>
                                                                    </li>
                                                                <?php else : ?>
                                                                    <?php foreach ($members as $member) : ?>
                                                                        <?php
                                                                        $member_pid = (string) ($member['pID'] ?? '');
                                                                        $member_name = (string) ($member['name'] ?? '');

                                                                        if ($member_pid === '' || $member_name === '') {
                                                                            continue;
                                                                        }
                                                                        ?>
                                                                        <li>
                                                                            <label class="item member-item">
                                                                                <input type="checkbox" class="member-checkbox"
                                                                                    data-member-group-key="faction-<?= h($fid_value) ?>"
                                                                                    data-member-name="<?= h($member_name) ?>"
                                                                                    data-group-label="<?= h($faction_name) ?>"
                                                                                    name="person_ids[]" value="<?= h($member_pid) ?>" <?= h($is_selected($member_pid, $selected_people) ? 'checked' : '') ?>>
                                                                                <span class="member-name"><?= h($member_name) ?></span>
                                                                            </label>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </ol>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (false && !empty($department_groups)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>กลุ่มสาระ</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($department_groups as $department_group) : ?>
                                                        <?php
                                                        $did = (int) ($department_group['dID'] ?? 0);
                                                        $department_name = trim((string) ($department_group['name'] ?? ''));
                                                        $members = (array) ($department_group['members'] ?? []);

                                                        if ($did <= 0 || $department_name === '' || empty($members)) {
                                                            continue;
                                                        }

                                                        $member_payload = [];
                                                        $has_selected_member = false;

                                                        foreach ($members as $member) {
                                                            $member_pid = (string) ($member['pID'] ?? '');
                                                            $member_name = (string) ($member['name'] ?? '');

                                                            if ($member_pid === '' || $member_name === '') {
                                                                continue;
                                                            }

                                                            if ($is_selected($member_pid, $selected_people)) {
                                                                $has_selected_member = true;
                                                            }
                                                            $member_payload[] = [
                                                                'pID' => $member_pid,
                                                                'name' => $member_name,
                                                                'faction' => $department_name,
                                                            ];
                                                        }

                                                        if (empty($member_payload)) {
                                                            continue;
                                                        }
                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                        if ($member_payload_json === false) {
                                                            $member_payload_json = '[]';
                                                        }
                                                        $member_total = count($member_payload);
                                                        $group_key = 'department-' . $did;
                                                        ?>
                                                        <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main">
                                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department"
                                                                        data-group-key="<?= h($group_key) ?>"
                                                                        data-group-label="<?= h($department_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        value="<?= h($group_key) ?>">
                                                                    <span class="item-title"><?= h($department_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php foreach ($member_payload as $member) : ?>
                                                                    <li>
                                                                        <label class="item member-item">
                                                                            <input type="checkbox" class="member-checkbox"
                                                                                data-member-group-key="<?= h($group_key) ?>"
                                                                                data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                                data-group-label="<?= h($department_name) ?>"
                                                                                name="person_ids[]" value="<?= h((string) ($member['pID'] ?? '')) ?>" <?= h($is_selected((string) ($member['pID'] ?? ''), $selected_people) ? 'checked' : '') ?>>
                                                                            <span class="member-name"><?= h((string) ($member['name'] ?? '')) ?></span>
                                                                        </label>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ol>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (false && !empty($special_groups)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>อื่นๆ</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($special_groups as $special_group) : ?>
                                                        <?php
                                                        $group_key = trim((string) ($special_group['key'] ?? ''));
                                                        $group_name = trim((string) ($special_group['name'] ?? ''));
                                                        $members = (array) ($special_group['members'] ?? []);

                                                        if ($group_key === '' || $group_name === '' || empty($members)) {
                                                            continue;
                                                        }

                                                        $member_payload = [];
                                                        $has_selected_member = false;

                                                        foreach ($members as $member) {
                                                            $member_pid = (string) ($member['pID'] ?? '');
                                                            $member_name = (string) ($member['name'] ?? '');

                                                            if ($member_pid === '' || $member_name === '') {
                                                                continue;
                                                            }

                                                            if ($is_selected($member_pid, $selected_people)) {
                                                                $has_selected_member = true;
                                                            }
                                                            $member_payload[] = [
                                                                'pID' => $member_pid,
                                                                'name' => $member_name,
                                                                'faction' => $group_name,
                                                            ];
                                                        }

                                                        if (empty($member_payload)) {
                                                            continue;
                                                        }

                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                        if ($member_payload_json === false) {
                                                            $member_payload_json = '[]';
                                                        }
                                                        $member_total = count($member_payload);
                                                        ?>
                                                        <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main">
                                                                    <input type="checkbox" class="item-checkbox group-item-checkbox" data-group="special"
                                                                        data-group-key="<?= h($group_key) ?>"
                                                                        data-group-label="<?= h($group_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        value="<?= h($group_key) ?>">
                                                                    <span class="item-title"><?= h($group_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php foreach ($member_payload as $member) : ?>
                                                                    <li>
                                                                        <label class="item member-item">
                                                                            <input type="checkbox" class="member-checkbox"
                                                                                data-member-group-key="<?= h($group_key) ?>"
                                                                                data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                                data-group-label="<?= h($group_name) ?>"
                                                                                name="person_ids[]" value="<?= h((string) ($member['pID'] ?? '')) ?>" <?= h($is_selected((string) ($member['pID'] ?? ''), $selected_people) ? 'checked' : '') ?>>
                                                                            <span class="member-name"><?= h((string) ($member['name'] ?? '')) ?></span>
                                                                        </label>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ol>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </div>

                            <div class="sent-notice-selected">
                                <button type="button">
                                    <p>แสดงบทบาทที่เลือก</p>
                                </button>
                            </div>
                        </div>

                        <div class="modal-overlay-recipient">
                            <div class="modal-container">
                                <div class="modal-header">
                                    <div class="modal-title">
                                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                                        <span>บทบาทที่เลือก</span>
                                    </div>
                                    <button class="modal-close" type="button">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <table class="recipient-table">
                                        <thead>
                                            <tr>
                                                <th>ลำดับ</th>
                                                <th>ชื่อบทบาท</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="form-group full">
                            <div class="signature-content">
                                <div class="signature-box">
                                    <img class="signature-preview hidden" src="" alt="Signature">
                                    <p class="no-signature-text">ไม่มีลายเซ็นในระบบ</p>
                                </div>
                                <div class="signature-field">
                                    <input type="file" name="signature_file" accept="image/jpeg, image/png" class="hidden-signature-input" style="display: none;">
                                    <button type="button" class="btn-upload signature-upload-btn">
                                        แนบลายเซ็น
                                    </button>
                                    <div class="file-hint">รองรับไฟล์นามสกุล .JPG , .PNG เท่านั้น ขนาดไม่เกิน 2MB</div>
                                </div>
                            </div>
                        </div>

                    </div>

                </form>
            </div>
            <div class="footer-modal">
                <form method="POST" action="" class="orders-send-form">
                    <button type="submit" form="personnelEditForm"
                        data-confirm="ยืนยันการบันทึกข้อมูลบุคลากรใช่หรือไม่?"
                        data-confirm-title="ยืนยันการบันทึกข้อมูล"
                        data-confirm-ok="ยืนยัน"
                        data-confirm-cancel="ยกเลิก">
                        <p>บันทึกข้อมูล</p>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const root = document.querySelector('[data-personnel-management]');
        if (!root) return;

        const openModalKey = String(root.dataset.personnelOpenModal || '').trim();
        const searchInput = root.querySelector('[data-personnel-search-input]');
        const statusFilter = root.querySelector('[data-personnel-status-filter]');
        const rows = Array.from(root.querySelectorAll('[data-personnel-row]'));
        const emptyRow = root.querySelector('[data-personnel-empty]');
        const addModal = document.getElementById('personnelAddModal');
        const editModal = document.getElementById('personnelEditModal');
        const editForm = document.getElementById('personnelEditForm');

        const modalMap = {
            personnelAddModal: addModal,
            personnelEditModal: editModal,
        };

        const closeModal = (modal) => {
            if (!modal) return;
            modal.classList.add('hidden');
        };

        const openModal = (modal) => {
            if (!modal) return;
            modal.classList.remove('hidden');
        };

        root.querySelectorAll('[data-personnel-modal-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = String(button.getAttribute('data-personnel-modal-open') || '');
                openModal(modalMap[key] || null);
            });
        });

        root.querySelectorAll('[data-personnel-modal-close]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = String(button.getAttribute('data-personnel-modal-close') || '');
                closeModal(modalMap[key] || null);
            });
        });

        Object.values(modalMap).forEach((modal) => {
            modal?.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal(modal);
                }
            });
        });

        const setRoleSelections = (form, roleCsv) => {
            const roleIds = String(roleCsv || '')
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value !== '');

            form.querySelectorAll('input[name="role_ids[]"]').forEach((checkbox) => {
                checkbox.checked = roleIds.includes(String(checkbox.value || '').trim());
            });

            if (typeof form.__personnelRoleSync === 'function') {
                form.__personnelRoleSync();
            }
        };

        const setupSignature = (container) => {
            if (!container) return;
            const fileInput = container.querySelector('.hidden-signature-input');
            const uploadBtn = container.querySelector('.signature-upload-btn');
            const previewImg = container.querySelector('.signature-preview');
            const noSigText = container.querySelector('.no-signature-text');

            uploadBtn?.addEventListener('click', () => {
                fileInput?.click();
            });

            fileInput?.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;

                if (!['image/jpeg', 'image/png'].includes(file.type)) {
                    alert('รองรับไฟล์นามสกุล .JPG , .PNG เท่านั้น');
                    fileInput.value = '';
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    alert('ขนาดไฟล์ต้องไม่เกิน 2MB');
                    fileInput.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = (e) => {
                    if (previewImg) {
                        previewImg.src = e.target.result;
                        previewImg.classList.remove('hidden');
                    }
                    if (noSigText) noSigText.style.display = 'none';
                    if (uploadBtn) uploadBtn.innerHTML = '<p>เปลี่ยนลายเซ็น</p>';
                };
                reader.readAsDataURL(file);
            });
        };

        setupSignature(addModal);
        setupSignature(editModal);

        root.querySelectorAll('[data-personnel-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('[data-personnel-row]');
                if (!row || !editForm) return;

                const setValue = (selector, value) => {
                    const field = editForm.querySelector(selector);
                    if (!field) return;
                    field.value = String(value || '');
                };

                const setCustomSelectValue = (selector, value) => {
                    const field = editForm.querySelector(selector);
                    if (!field) return;

                    const normalizedValue = String(value || '');
                    field.value = normalizedValue;

                    const wrapper = field.closest('.custom-select-wrapper');
                    if (!wrapper) return;

                    let selectedLabel = '';
                    wrapper.querySelectorAll('.custom-option').forEach((option) => {
                        const isSelected = String(option.getAttribute('data-value') || '') === normalizedValue;
                        option.classList.toggle('selected', isSelected);

                        if (isSelected) {
                            selectedLabel = String(option.textContent || '').trim();
                        }
                    });

                    const valueLabel = wrapper.querySelector('.select-value');
                    if (valueLabel && selectedLabel !== '') {
                        valueLabel.textContent = selectedLabel;
                    }
                };

                setValue('#personnelEditOriginalPid', row.getAttribute('data-pid') || '');
                setValue('#personnelEditPid', row.getAttribute('data-pid') || '');
                setValue('#personnelEditPidDisplay', row.getAttribute('data-pid') || '');
                setValue('#personnelEditName', row.getAttribute('data-name') || '');
                setValue('#personnelEditTelephone', row.getAttribute('data-telephone') || '');
                setCustomSelectValue('#personnelEditFaction', row.getAttribute('data-fid') || '0');
                setCustomSelectValue('#personnelEditDepartment', row.getAttribute('data-did') || '0');
                setCustomSelectValue('#personnelEditLevel', row.getAttribute('data-lid') || '0');
                setCustomSelectValue('#personnelEditLegacyPosition', row.getAttribute('data-oid') || '0');
                setCustomSelectValue('#personnelEditPosition', row.getAttribute('data-position-id') || '0');
                setValue('#personnelEditLineId', row.getAttribute('data-line-id') || '');
                setCustomSelectValue('#personnelEditStatus', row.getAttribute('data-status-value') || '1');
                setRoleSelections(editForm, row.getAttribute('data-role-ids') || '');

                const signaturePath = row.getAttribute('data-signature') || '';
                const sigPreview = editForm.querySelector('.signature-preview');
                const sigText = editForm.querySelector('.no-signature-text');
                const sigBtn = editForm.querySelector('.signature-upload-btn');
                const sigExistingInput = editForm.querySelector('.existing-signature-input');
                const sigFileInput = editForm.querySelector('.hidden-signature-input');

                if (sigFileInput) sigFileInput.value = '';
                if (sigExistingInput) sigExistingInput.value = signaturePath;

                if (signaturePath) {
                    if (sigPreview) {
                        sigPreview.src = signaturePath;
                        sigPreview.classList.remove('hidden');
                    }
                    if (sigText) sigText.style.display = 'none';
                    if (sigBtn) sigBtn.innerHTML = '<p>เปลี่ยนลายเซ็น</p>';
                } else {
                    if (sigPreview) {
                        sigPreview.src = '';
                        sigPreview.classList.add('hidden');
                    }
                    if (sigText) sigText.style.display = '';
                    if (sigBtn) sigBtn.innerHTML = '<p>แนบลายเซ็น</p>';
                }

                openModal(editModal);
            });
        });

        const applyFilters = () => {
            const query = String(searchInput?.value || '').trim().toLowerCase();
            const statusValue = String(statusFilter?.value || 'all');
            let visibleCount = 0;

            rows.forEach((row) => {
                const rowSearch = String(row.getAttribute('data-personnel-search') || '').toLowerCase();
                const rowStatus = String(row.getAttribute('data-personnel-status') || '');
                const matchedQuery = query === '' || rowSearch.includes(query);
                const matchedStatus = statusValue === 'all' || rowStatus === statusValue;
                const isVisible = matchedQuery && matchedStatus;
                row.style.display = isVisible ? '' : 'none';

                if (isVisible) {
                    visibleCount += 1;
                }
            });

            if (emptyRow) {
                emptyRow.style.display = visibleCount === 0 ? '' : 'none';
            }
        };

        searchInput?.addEventListener('input', applyFilters);
        statusFilter?.addEventListener('change', applyFilters);
        applyFilters();

        if (openModalKey !== '' && modalMap[openModalKey]) {
            openModal(modalMap[openModalKey]);
        }
    })();

    document.addEventListener('DOMContentLoaded', function() {
        const limitOutgoingOwnerDepartmentOptions = (section) => {
            if (!section || section.getAttribute('data-owner-flat-list') !== 'true') {
                return;
            }
            const allowedDepartmentLabel = 'กลุ่มสาระฯ ภาษาต่างประเทศ';
            const departmentGroups = Array.from(section.querySelectorAll('.department-item-checkbox'))
                .map((checkbox) => checkbox.closest('.item-group'))
                .filter((groupItem) => groupItem instanceof HTMLElement);

            departmentGroups.forEach((groupItem) => {
                const checkbox = groupItem.querySelector('.department-item-checkbox');
                const label = String(checkbox?.getAttribute('data-group-label') || '').trim();
                if (label !== allowedDepartmentLabel) {
                    groupItem.remove();
                }
            });

            section.querySelectorAll('.category-group').forEach((categoryGroup) => {
                if (!categoryGroup.querySelector('.category-items .item-group')) {
                    categoryGroup.remove();
                }
            });
        };

        function setupPersonnelRoleDropdown(container) {
            if (!container) return;

            const section = container.querySelector('[data-personnel-role-section]');
            if (!section) return;

            const dropdown = section.querySelector('.dropdown-content');
            const toggle = section.querySelector('.search-input-wrapper');
            const searchInput = section.querySelector('.search-input');
            const selectAll = section.querySelector('[data-role-select-all]');
            const roleChecks = Array.from(section.querySelectorAll('.role-checkbox'));
            const roleItems = Array.from(section.querySelectorAll('.role-item'));
            const roleModal = container.querySelector('.modal-overlay-recipient');
            const roleTableBody = container.querySelector('.recipient-table tbody');
            const showRolesButton = container.querySelector('.sent-notice-selected button');
            const closeModalBtn = container.querySelector('.modal-close');

            const setDropdownVisible = (visible) => dropdown?.classList.toggle('show', visible);
            const roleName = (checkbox) => String(checkbox?.getAttribute('data-role-name') || '').trim();
            const selectedRoleChecks = () => roleChecks.filter((checkbox) => checkbox.checked);

            const filterRoles = (query) => {
                const normalizedQuery = String(query || '').trim().toLowerCase();
                roleItems.forEach((item) => {
                    const text = String(item.textContent || '').trim().toLowerCase();
                    item.style.display = normalizedQuery === '' || text.includes(normalizedQuery) ? '' : 'none';
                });
            };

            const updateSelectAllState = () => {
                if (!selectAll) return;
                const checkedCount = selectedRoleChecks().length;
                selectAll.checked = roleChecks.length > 0 && checkedCount === roleChecks.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < roleChecks.length;
            };

            const updateSummary = () => {
                if (!searchInput) return;
                const selectedNames = selectedRoleChecks().map(roleName).filter((name) => name !== '');
                searchInput.value = selectedNames.length > 0 ? selectedNames.join(', ') : 'เลือกบทบาท';
            };

            const syncState = () => {
                updateSelectAllState();
                updateSummary();
            };

            container.__personnelRoleSync = syncState;

            toggle?.addEventListener('click', (event) => {
                event.stopPropagation();
                setDropdownVisible(!dropdown?.classList.contains('show'));
            });

            document.addEventListener('click', (event) => {
                if (dropdown && !dropdown.contains(event.target) && !toggle?.contains(event.target)) {
                    setDropdownVisible(false);
                    syncState();
                }
            });

            searchInput?.addEventListener('focus', () => {
                setDropdownVisible(true);
                if (selectedRoleChecks().length > 0) {
                    searchInput.value = '';
                    filterRoles('');
                }
            });

            searchInput?.addEventListener('input', () => {
                setDropdownVisible(true);
                filterRoles(searchInput.value);
            });

            roleChecks.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    filterRoles('');
                    syncState();
                });
            });

            selectAll?.addEventListener('change', () => {
                roleChecks.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                filterRoles('');
                syncState();
            });

            const renderSelectedRoles = () => {
                if (!roleTableBody) return;
                const selected = selectedRoleChecks();
                roleTableBody.innerHTML = '';

                if (selected.length === 0) {
                    roleTableBody.innerHTML = '<tr><td colspan="2" style="text-align:center; padding: 16px;">ไม่มีบทบาทที่เลือก</td></tr>';
                    return;
                }

                selected.forEach((checkbox, index) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `<td>${index + 1}</td><td>${roleName(checkbox) || '-'}</td>`;
                    roleTableBody.appendChild(row);
                });
            };

            showRolesButton?.addEventListener('click', () => {
                renderSelectedRoles();
                roleModal?.classList.add('active');
            });

            closeModalBtn?.addEventListener('click', () => {
                roleModal?.classList.remove('active');
            });

            roleModal?.addEventListener('click', (event) => {
                if (event.target === roleModal) {
                    roleModal.classList.remove('active');
                }
            });

            syncState();
        }

        function setupRecipientDropdown(container) {
            if (!container) return;

            const initialSelectedPersonIds = new Set();
            const ownerSection = container.querySelector('[data-recipients-section][data-owner-flat-list="true"]');
            const reviewerHiddenInput = container.querySelector('[data-reviewer-hidden]');
            const reviewerOptionsRaw = ownerSection?.getAttribute('data-reviewer-options') || '[]';
            let reviewerOptions = [];

            try {
                reviewerOptions = JSON.parse(reviewerOptionsRaw);
            } catch (error) {
                reviewerOptions = [];
            }

            const reviewerMap = new Map(
                Array.isArray(reviewerOptions) ?
                reviewerOptions
                .map((reviewer) => {
                    const pid = String(reviewer?.pID || '').trim();
                    const label = String(reviewer?.label || '').trim();
                    return pid !== '' && label !== '' ? [pid, label] : null;
                })
                .filter((entry) => Array.isArray(entry)) : []
            );

            const initialReviewerPid = String(reviewerHiddenInput?.value || '').trim();
            if (initialReviewerPid !== '') {
                initialSelectedPersonIds.add(initialReviewerPid);
            }

            if (ownerSection) {
                limitOutgoingOwnerDepartmentOptions(ownerSection);
            }

            const dropdown = container.querySelector('.dropdown-content');
            const toggle = container.querySelector('.search-input-wrapper');
            const searchInput = container.querySelector('.search-input');
            const selectAll = container.querySelector('.select-all-box input[type="checkbox"]');

            const groupChecks = Array.from(container.querySelectorAll('.group-item-checkbox'));
            const memberChecks = Array.from(container.querySelectorAll('.member-checkbox'));
            const groupItems = Array.from(container.querySelectorAll('.dropdown-list .item-group'));
            const categoryGroups = Array.from(container.querySelectorAll('.dropdown-list .category-group'));

            const setDropdownVisible = (visible) => dropdown?.classList.toggle('show', visible);

            toggle?.addEventListener('click', (e) => {
                e.stopPropagation();
                if (e.target.matches('input.search-input') || e.target.closest('input.search-input')) {
                    setDropdownVisible(true);
                } else {
                    setDropdownVisible(!dropdown?.classList.contains('show'));
                }
            });

            document.addEventListener('click', (e) => {
                if (dropdown && !dropdown.contains(e.target) && !toggle?.contains(e.target)) {
                    setDropdownVisible(false);
                }
            });

            const setGroupCollapsed = (groupItem, collapsed) => {
                if (!groupItem) return;
                groupItem.classList.toggle('is-collapsed', collapsed);
                const toggleBtn = groupItem.querySelector('.group-toggle');
                if (toggleBtn) {
                    toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                }
            };

            groupItems.forEach((groupItem) => {
                const toggleBtn = groupItem.querySelector('.group-toggle');
                toggleBtn?.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const isCollapsed = groupItem.classList.contains('is-collapsed');
                    setGroupCollapsed(groupItem, !isCollapsed);
                });
            });

            const normalizeSearchText = (value) => String(value || '').toLowerCase().replace(/\s+/g, '').replace(/[^0-9a-z\u0E00-\u0E7F]/gi, '');

            const filterRecipientDropdown = (rawQuery, remoteMatchedPids = null) => {
                const query = normalizeSearchText(rawQuery);
                groupItems.forEach((groupItem) => {
                    const titleEl = groupItem.querySelector('.item-title');
                    const titleText = normalizeSearchText(titleEl?.textContent || '');
                    const memberRows = Array.from(groupItem.querySelectorAll('.member-sublist li'));
                    const isGroupMatch = query !== '' && titleText.includes(query);

                    if (query === '') {
                        groupItem.style.display = '';
                        memberRows.forEach((row) => row.style.display = '');
                        return;
                    }

                    let hasMemberMatch = false;
                    memberRows.forEach((row) => {
                        const memberCheckbox = row.querySelector('.member-checkbox');
                        const memberPid = String(memberCheckbox?.value || '').trim();
                        const isRemoteMatched = remoteMatchedPids instanceof Set ? remoteMatchedPids.has(memberPid) : null;
                        const rowText = normalizeSearchText(row.textContent || '');
                        const matchedByText = rowText.includes(query);

                        const matched = isGroupMatch || matchedByText || isRemoteMatched === true;
                        row.style.display = matched ? '' : 'none';
                        if (matched) hasMemberMatch = true;
                    });

                    const isVisible = isGroupMatch || hasMemberMatch;
                    groupItem.style.display = isVisible ? '' : 'none';
                    if (isVisible) setGroupCollapsed(groupItem, false);
                });

                categoryGroups.forEach((category) => {
                    const hasVisibleItem = Array.from(category.querySelectorAll('.category-items .item-group')).some((item) => item.style.display !== 'none');
                    category.style.display = hasVisibleItem ? '' : 'none';
                });
            };

            let recipientSearchTimer = null;
            let recipientSearchRequestNo = 0;
            const recipientSearchEndpoint = 'public/api/circular-recipient-search.php';

            const requestRecipientSearch = (query) => {
                const requestNo = ++recipientSearchRequestNo;
                const url = `${recipientSearchEndpoint}?q=${encodeURIComponent(query)}`;
                fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        credentials: 'same-origin'
                    })
                    .then((res) => {
                        if (!res.ok) throw new Error();
                        return res.json();
                    })
                    .then((payload) => {
                        if (requestNo !== recipientSearchRequestNo) return;
                        const pids = Array.isArray(payload?.pids) ? payload.pids : [];
                        filterRecipientDropdown(query, new Set(pids.map(pid => String(pid))));
                    })
                    .catch(() => {
                        if (requestNo !== recipientSearchRequestNo) return;
                        filterRecipientDropdown(query);
                    });
            };

            searchInput?.addEventListener('focus', () => setDropdownVisible(true));
            searchInput?.addEventListener('input', () => {
                setDropdownVisible(true);
                const query = String(searchInput.value || '').trim();
                if (recipientSearchTimer) clearTimeout(recipientSearchTimer);
                if (query === '') {
                    recipientSearchRequestNo++;
                    filterRecipientDropdown('');
                    return;
                }
                recipientSearchTimer = window.setTimeout(() => requestRecipientSearch(query), 180);
            });
            searchInput?.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') setDropdownVisible(false);
            });

            const getMemberChecksByGroupKey = (groupKey) => memberChecks.filter((el) => (el.dataset.memberGroupKey || '') === String(groupKey));

            const syncMemberByPid = (pid, checked, source) => {
                const normalizedPid = String(pid || '').trim();
                if (normalizedPid === '') return;
                memberChecks.forEach((memberCheck) => {
                    if (memberCheck === source) return;
                    if (String(memberCheck.value || '') !== normalizedPid) return;
                    if (memberCheck.disabled) return;
                    memberCheck.checked = checked;
                });
            };

            const updateSelectAllState = () => {
                if (!selectAll) return;
                const allChecks = [...groupChecks, ...memberChecks];
                const checked = allChecks.filter((el) => el.checked).length;
                selectAll.checked = allChecks.length > 0 && checked === allChecks.length;
                selectAll.indeterminate = checked > 0 && checked < allChecks.length;

                groupChecks.forEach((groupCheck) => {
                    const groupKey = groupCheck.getAttribute('data-group-key') || '';
                    const members = getMemberChecksByGroupKey(groupKey);
                    if (members.length === 0) {
                        groupCheck.indeterminate = false;
                        return;
                    }
                    const memberChecked = members.filter((el) => el.checked).length;
                    if (memberChecked === 0) {
                        groupCheck.checked = false;
                        groupCheck.indeterminate = false;
                        return;
                    }
                    if (memberChecked === members.length) {
                        groupCheck.checked = true;
                        groupCheck.indeterminate = false;
                        return;
                    }
                    groupCheck.checked = false;
                    groupCheck.indeterminate = true;
                });
            };

            const syncReviewerSelection = () => {
                if (!reviewerHiddenInput) return;

                const selectedReviewer = memberChecks.find((memberCheck) => memberCheck.checked && reviewerMap.has(String(memberCheck.value || '').trim()));
                const reviewerPid = selectedReviewer ? String(selectedReviewer.value || '').trim() : '';
                reviewerHiddenInput.value = reviewerPid;

                if (searchInput) {
                    searchInput.value = reviewerPid !== '' ? (reviewerMap.get(reviewerPid) || '') : '';
                }
            };

            selectAll?.addEventListener('change', () => {
                const checked = selectAll.checked;
                [...groupChecks, ...memberChecks].forEach((el) => {
                    if (!el.disabled) el.checked = checked;
                });
                updateSelectAllState();
                syncReviewerSelection();
            });

            groupChecks.forEach((item) => {
                item.addEventListener('change', () => {
                    const groupKey = item.getAttribute('data-group-key') || '';
                    const members = getMemberChecksByGroupKey(groupKey);
                    members.forEach((member) => {
                        if (!member.disabled) {
                            member.checked = item.checked;
                            syncMemberByPid(member.value || '', item.checked, member);
                        }
                    });
                    if (item.checked) setGroupCollapsed(item.closest('.item-group'), false);
                    item.indeterminate = false;
                    updateSelectAllState();
                    syncReviewerSelection();
                });
            });

            memberChecks.forEach((item) => {
                item.addEventListener('change', () => {
                    syncMemberByPid(item.value || '', item.checked, item);
                    updateSelectAllState();
                    syncReviewerSelection();
                });
            });

            memberChecks.forEach((item) => {
                const pid = String(item.value || '').trim();
                if (initialSelectedPersonIds.has(pid) && !item.disabled) {
                    item.checked = true;
                }
            });

            groupChecks.forEach((item) => {
                if (!item.checked) return;
                const groupKey = item.getAttribute('data-group-key') || '';
                const members = getMemberChecksByGroupKey(groupKey);
                members.forEach((member) => {
                    member.checked = true;
                    syncMemberByPid(member.value || '', true, member);
                });
            });

            updateSelectAllState();
            syncReviewerSelection();

            const recipientModal = container.querySelector('.modal-overlay-recipient');
            const recipientTableBody = container.querySelector('.recipient-table tbody');
            const btnShowRecipients = container.querySelector('.sent-notice-selected button');
            const closeModalBtn = container.querySelector('.modal-close');

            const renderRecipients = () => {
                if (!recipientTableBody) return;
                recipientTableBody.innerHTML = '';

                const checkedGroups = groupChecks.filter((item) => item.checked);
                const checkedMembers = memberChecks.filter((item) => item.checked);

                if (checkedGroups.length === 0 && checkedMembers.length === 0) {
                    recipientTableBody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding: 16px;">ไม่มีผู้รับที่เลือก</td></tr>';
                    return;
                }

                const recipientsMap = new Map();

                const addRecipient = (pid, name, faction) => {
                    const key = String(pid || '').trim();
                    if (key === '' || recipientsMap.has(key)) return;
                    recipientsMap.set(key, {
                        pid: key,
                        name: (name || '-').trim() || '-',
                        faction: (faction || '-').trim() || '-'
                    });
                };

                checkedGroups.forEach((item) => {
                    let members = [];
                    try {
                        members = JSON.parse(item.getAttribute('data-members') || '[]');
                    } catch (e) {
                        members = [];
                    }
                    if (!Array.isArray(members)) return;
                    members.forEach((member) => addRecipient(member?.pID, member?.name, item.getAttribute('data-group-label')));
                });

                checkedMembers.forEach((item) => addRecipient(item.value, item.getAttribute('data-member-name'), item.getAttribute('data-group-label')));

                const uniqueRecipients = Array.from(recipientsMap.values()).sort((a, b) => a.faction === b.faction ? a.name.localeCompare(b.name, 'th') : a.faction.localeCompare(b.faction, 'th'));

                uniqueRecipients.forEach((recipient, index) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `<td>${index + 1}</td><td>${recipient.name}</td><td>${recipient.faction}</td>`;
                    recipientTableBody.appendChild(row);
                });
            };

            btnShowRecipients?.addEventListener('click', () => {
                renderRecipients();
                recipientModal?.classList.add('active');
            });

            closeModalBtn?.addEventListener('click', () => {
                recipientModal?.classList.remove('active');
            });

            recipientModal?.addEventListener('click', (e) => {
                if (e.target === recipientModal) {
                    recipientModal.classList.remove('active');
                }
            });
        }

        const addModalContainer = document.getElementById('personnelAddForm');
        if (addModalContainer) {
            setupPersonnelRoleDropdown(addModalContainer);
        }

        const editModalContainer = document.getElementById('personnelEditForm');
        if (editModalContainer) {
            setupPersonnelRoleDropdown(editModalContainer);
        }

        document.querySelectorAll('#personnelAddForm input[name="telephone"], #personnelEditForm input[name="telephone"]').forEach((input) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 10);
            });
        });
    });
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
