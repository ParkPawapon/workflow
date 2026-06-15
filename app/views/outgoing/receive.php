<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$values = array_merge([
    'extPriority' => 'ปกติ',
    'extBookNo' => '',
    'extIssuedDate' => '',
    'subject' => '',
    'extFromText' => '',
    'extGroupFID' => '',
    'linkURL' => '',
    'detail' => '',
    'reviewerPID' => '',
], (array) ($values ?? []));
$factions = (array) ($factions ?? []);
$reviewers = (array) ($reviewers ?? []);
$is_edit_mode = (bool) ($is_edit_mode ?? false);
$edit_circular_id = (int) ($edit_circular_id ?? 0);
$editable_circular = (array) ($editable_circular ?? []);
$existing_attachments = (array) ($existing_attachments ?? []);
$items = (array) ($items ?? []);
$filter_query = trim((string) ($filter_query ?? ''));
$filter_status = outgoing_receive_normalize_track_filter_status((string) ($filter_status ?? 'all'));
$filter_sort = outgoing_receive_normalize_track_filter_sort((string) ($filter_sort ?? 'newest'));
$is_track_active = (bool) ($is_track_active ?? false);
$track_status_map = (array) ($track_status_map ?? outgoing_receive_track_status_map());
$send_modal_payload_map = (array) ($send_modal_payload_map ?? []);
$current_user_name = trim((string) ($current_user_name ?? ''));

$priority_options = [
    'ปกติ' => 'ปกติ',
    'ด่วน' => 'ด่วน',
    'ด่วนมาก' => 'ด่วนมาก',
    'ด่วนที่สุด' => 'ด่วนที่สุด',
];

$faction_options = ['' => 'เลือกกลุ่ม/ฝ่าย'];

foreach ($factions as $faction) {
    $fid = (int) ($faction['fID'] ?? 0);
    $name = trim((string) ($faction['fName'] ?? ''));

    if ($fid <= 0 || $name === '') {
        continue;
    }

    $faction_options[(string) $fid] = $name;
}

$reviewer_options = ['' => 'เลือกผู้พิจารณา'];

foreach ($reviewers as $reviewer) {
    $pid = trim((string) ($reviewer['pID'] ?? ''));
    $label = trim((string) ($reviewer['label'] ?? ''));

    if ($pid === '' || $label === '') {
        continue;
    }

    $reviewer_options[$pid] = $label;
}

$default_group_fid = array_key_exists('4', $faction_options) ? '4' : '';
$selected_group_fid = $values['extGroupFID'] !== '' && array_key_exists($values['extGroupFID'], $faction_options)
    ? $values['extGroupFID']
    : $default_group_fid;
$selected_group_label = $faction_options[$selected_group_fid] ?? 'เลือกกลุ่ม/ฝ่าย';
$selected_reviewer_pid = trim((string) ($values['reviewerPID'] ?? ''));

if ($selected_reviewer_pid === '' || !isset($reviewer_options[$selected_reviewer_pid])) {
    foreach ($reviewers as $reviewer) {
        $fallback_pid = trim((string) ($reviewer['pID'] ?? ''));

        if ($fallback_pid !== '') {
            $selected_reviewer_pid = $fallback_pid;
            break;
        }
    }
}

$selected_reviewer_label = $reviewer_options[$selected_reviewer_pid] ?? '-';
$reviewer_options_json = json_encode($reviewers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!is_string($reviewer_options_json)) {
    $reviewer_options_json = '[]';
}

$current_status = trim((string) ($editable_circular['status'] ?? ''));
$status_label = $current_status !== '' ? $current_status : '-';
$status_variant = 'pending';

if ($current_status === EXTERNAL_STATUS_SUBMITTED) {
    $status_label = 'กำลังเสนอ';
    $status_variant = 'primary';
} elseif ($current_status === EXTERNAL_STATUS_PENDING_REVIEW) {
    $status_label = 'รอพิจารณา';
    $status_variant = 'pending';
} elseif ($current_status === EXTERNAL_STATUS_REVIEWED) {
    $status_label = 'พิจารณาแล้ว';
    $status_variant = 'approved';
} elseif ($current_status === EXTERNAL_STATUS_FORWARDED) {
    $status_label = 'ส่งแล้ว';
    $status_variant = 'approved';
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

$format_thai_datetime_parts = static function (?string $datetime_value) use ($thai_months): array {
    $datetime_value = trim((string) $datetime_value);

    if ($datetime_value === '' || strpos($datetime_value, '0000-00-00') === 0) {
        return ['date' => '-', 'time' => '-'];
    }

    $timestamp = strtotime($datetime_value);

    if ($timestamp === false) {
        return ['date' => $datetime_value, 'time' => '-'];
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp) + 543;
    $month_label = $thai_months[$month] ?? '';

    return [
        'date' => trim($day . ' ' . $month_label . ' ' . $year),
        'time' => date('H:i', $timestamp) . ' น.',
    ];
};

ob_start();
?>

<style>
    .table-responsive.circular-my-table-wrap.order-create .status-pill.outgoing-complete {
        background-color: rgba(var(--rgb-primary-dark), 0.18);
        border: 1px solid var(--color-primary-dark);
        color: var(--color-primary-dark);
    }

    #outgoingMine .outgoing-receive-doc-line {
        color: var(--color-primary-dark);
        font-size: var(--font-size-desc-1);
        font-weight: 700;
        line-height: 1.45;
        word-break: break-word;
    }

    #outgoingMine .outgoing-receive-doc-line+.outgoing-receive-doc-line {
        margin-top: 2px;
    }

    #outgoingMine .outgoing-receive-doc-subject {
        color: var(--color-neutral-dark);
        font-size: var(--font-size-desc-1);
        font-weight: 600;
        line-height: 1.45;
        margin-top: 4px;
        min-width: 260px;
        max-width: 420px;
        word-break: break-word;
    }

    .form-group.receive button p {
        color: var(--color-neutral-lightest);
    }

    #outgointForm .form-group.receive .dropdown-container,
    #outgointForm .form-group.receive .search-input-wrapper,
    #outgointForm .form-group.receive .search-input {
        width: 100%;
    }

    #outgointForm .form-group.receive .dropdown-container {
        flex: 1;
    }

    .form-group .input-group {
        width: 100% !important;
    }

    .tox-tinymce {
        width: 100%;
    }

    .orders-send-modal-shell .orders-send-summary {
        margin: 16px 0;
    }

    .content-order .form-group.last {
        margin: 0;
    }

    .select-all-box input {
        width: 25px !important;
        height: 25px !important;
    }

    .category-items input[type="checkbox"] {
        width: 20px;
        height: 20px;
        margin-right: 15px;
        cursor: pointer;
    }

    .orders-send-modal-shell .orders-send-summary {
        margin: 16px 0;
    }

    .content-order .form-group.last {
        margin: 0;
    }

    .file-hint p {
        color: var(--color-danger) !important;
    }

    .btn-upload-small p {
        color: var(--color-neutral-lightest) !important;
    }

    .upload-layout {
        width: 100%;
    }

    .upload-layout .upload-box i {
        margin: 0 0 12px 0;
    }

    .delete-btn {
        background: none !important;
        border: none !important;
        color: var(--color-danger) !important;
        font-size: var(--font-size-title) !important;
        cursor: pointer !important;
        transition: transform 0.2s !important;
    }

    .delete-btn:hover {
        transform: scale(1.2) !important;
    }

    .form-group.row.label {
        margin: 0 0 10px;
        height: auto;
    }

    .table-responsive.circular-my-table-wrap.order-create .order-create-datetime {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        white-space: normal;
    }

    .table-responsive.circular-my-table-wrap.order-create .order-create-datetime .order-create-datetime-date {
        font-weight: 700;
        color: var(--color-secondary);
        line-height: 1.45;
    }

    .table-responsive.circular-my-table-wrap.order-create .order-create-datetime .order-create-datetime-time {
        color: var(--color-neutral-dark);
        font-size: var(--font-size-desc-2);
        line-height: 1.35;
    }

    .table-responsive td:last-child {
        text-align: center;
    }

    .table-responsive td:nth-child(2) {
        text-align: center;
    }

    .content-read-sec .table-responsive {
        margin: 0 0 40px;
    }

    #modalOrderEditOverlay .form-group.receive {
        margin: 40px 0;
    }

    #modalOrderEditOverlay .form-group.row .input-group {
        flex-direction: column;
        align-items: start;
    }

    #modalOrderEditOverlay .upload-layout {
        margin: 0;
    }

    #modalOrderEdirOverlay .form-group.row {
        margin: 0 0 40px;
    }

    .table-circular-notice-index table thead th:nth-child(1),

    .table-circular-notice-index table tbody td:nth-child(1) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(4),
    .table-circular-notice-index table tbody td:nth-child(4),
    .table-circular-notice-index table tbody td:nth-child(2),
    .table-responsive.order-create .custom-table tbody tr td:nth-child(3) {
        text-align: start !important;
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .booking-table td:nth-child(1) {
        width: 80px !important;
        min-width: 80px !important;
        max-width: 80px !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .booking-table td:nth-child(2) {
        width: 650px !important;
        min-width: 650px !important;
        max-width: 650px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3),
    .booking-table td:nth-child(3) {
        width: 190px !important;
        min-width: 190px !important;
        max-width: 190px !important;
    }

    .table-circular-notice-index table thead th:nth-child(4),
    .booking-table td:nth-child(4) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-index table thead th:nth-child(5),
    .booking-table td:nth-child(5) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }
    
    .circular-track-modal-host {
        width: 0 !important;
        height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: 0 !important;
        background: transparent !important;
    }

    @media screen and (max-width: 1024px) {

        .table-circular-notice-index table thead th:nth-child(1),

        .table-circular-notice-index table tbody td:nth-child(1) {
            text-align: center !important;
        }

        .table-circular-notice-index table thead th:nth-child(4),
        .table-circular-notice-index table tbody td:nth-child(4),
        .table-circular-notice-index table tbody td:nth-child(2),
        .table-responsive.order-create .custom-table tbody tr td:nth-child(3) {
            text-align: start !important;
        }

        .table-circular-notice-index table thead th:nth-child(1),
        .booking-table td:nth-child(1) {
            width: 60px !important;
            min-width: 60px !important;
            max-width: 60px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .booking-table td:nth-child(2) {
            width: 600px !important;
            min-width: 600px !important;
            max-width: 600px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3),
        .booking-table td:nth-child(3) {
            width: 170px !important;
            min-width: 170px !important;
            max-width: 170px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4),
        .booking-table td:nth-child(4) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
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
    <p>หนังสือเวียนภายนอก / ลงทะเบียนรับหนังสือ</p>
</div>

<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
            onclick="openTab('outgoing', event)">ลงทะเบียนรับ</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>"
            onclick="openTab('outgoingMine', event)">ติดตามการส่ง</button>
    </div>
</div>

<div class="content-order outgoing tab-content <?= $is_track_active ? '' : 'active' ?>" id="outgoing">
    <form method="POST" id="outgointForm" enctype="multipart/form-data">

        <div class="type-urgent">
            <p>ประเภท</p>
            <div class="radio-group-urgent">
                <input type="radio" name="extPriority" value="ปกติ" <?= $values['extPriority'] === 'ปกติ' ? 'checked' : '' ?> id="outgoingPriorityNormal"><label for="outgoingPriorityNormal">ปกติ</label>
                <input type="radio" name="extPriority" value="ด่วน" <?= $values['extPriority'] === 'ด่วน' ? 'checked' : '' ?> id="outgoingPriorityUrgent"><label for="outgoingPriorityUrgent">ด่วน</label>
                <input type="radio" name="extPriority" value="ด่วนมาก" <?= $values['extPriority'] === 'ด่วนมาก' ? 'checked' : '' ?> id="outgoingPriorityHigh"><label for="outgoingPriorityHigh">ด่วนมาก</label>
                <input type="radio" name="extPriority" value="ด่วนที่สุด" <?= $values['extPriority'] === 'ด่วนที่สุด' ? 'checked' : '' ?> id="outgoingPriorityHighest"><label for="outgoingPriorityHighest">ด่วนที่สุด</label>
            </div>
        </div>

        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="edit_circular_id" value="<?= $is_edit_mode ? $edit_circular_id : 0 ?>">

        <div class="form-group row">
            <div class="input-group">
                <p><strong>เลขที่หนังสือ</strong></p>
                <input
                    type="text"
                    name="extBookNo"
                    class="order-no-display"
                    value="<?= h($values['extBookNo']) ?>"
                    placeholder="เช่น สธ 04066/2"
                    required>
            </div>
            <div class="input-group">
                <p><strong>ลงวันที่</strong></p>
                <input
                    type="date"
                    name="extIssuedDate"
                    value="<?= h($values['extIssuedDate']) ?>"
                    required>
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>เรื่อง</strong></p>
                <input
                    type="text"
                    name="subject"
                    class="order-no-display"
                    value="<?= h($values['subject']) ?>"
                    placeholder="ระบุเรื่องที่จะลงทะเบียนหนังสือเวียน"
                    required>
            </div>
            <div class="input-group">
                <p><strong>จาก</strong></p>
                <input
                    type="text"
                    name="extFromText"
                    class="order-no-display"
                    value="<?= h($values['extFromText']) ?>"
                    placeholder="ระบุแหล่งที่มา"
                    required>
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>ถึงกลุ่ม</strong></p>
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($selected_group_label) ?></p>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($factions as $faction) : ?>
                            <?php
                            $faction_id = (string) ((int) ($faction['fID'] ?? 0));
                            $faction_name = trim((string) ($faction['fName'] ?? ''));
                            if ($faction_id === '0' || $faction_name === '') {
                                continue;
                            }
                            ?>
                            <div class="custom-option<?= $selected_group_fid === $faction_id ? ' selected' : '' ?>" data-value="<?= h($faction_id) ?>"><?= h($faction_name) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="extGroupFID" value="<?= h($selected_group_fid) ?>">
                </div>
            </div>
            <div class="input-group"></div>
        </div>

        <div class="form-group">
            <div class="input-group">
                <p><strong>เกษียณหนังสือ</strong></p>
                <textarea name="detail" id="memo_editor_compose"><?= h($values['detail']) ?></textarea>
            </div>
        </div>

        <div class="form-group receive">
            <label><strong>เสนอ :</strong></label>
            <div class="dropdown-container">
                <div class="search-input-wrapper">
                    <input type="text" class="search-input" value="<?= h($selected_reviewer_label) ?>" readonly aria-readonly="true">
                </div>
                <input type="hidden" name="reviewerPID" value="<?= h($selected_reviewer_pid) ?>">
            </div>
        </div>

        <?php if (false) : ?>
            <div class="dropdown-content" id="dropdownContent">
                <div class="dropdown-header">
                    <label class="select-all-box">
                        <input type="checkbox" id="selectAll">เลือกทั้งหมด
                    </label>
                </div>

                <div class="dropdown-list">
                    <div class="category-group">
                        <div class="category-title">
                            <span>หน่วยงาน</span>
                        </div>
                        <div class="category-items">
                            <div class="item item-group is-collapsed" data-faction-id="5">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-5" data-group-label="กลุ่มบริหารกิจการนักเรียน" data-members="[{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;}]" name="faction_ids[]" value="5">
                                        <span class="item-title">กลุ่มบริหารกิจการนักเรียน</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางชมทิศา ขันภักดี" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820400215231">
                                            <span class="member-name">นางชมทิศา ขันภักดี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางพวงทิพย์ ทวีรส" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3950300068146">
                                            <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสาวชาลิสา จิตต์พันธ์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900172052">
                                            <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสาวนิรัตน์ เพชรแก้ว" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820800038999">
                                            <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสาวอรบุษย์ หนักแน่น" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900170670">
                                            <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสุนิษา  จินดาพล" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3601000301019">
                                            <span class="member-name">นางสุนิษา จินดาพล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางเขมษิญากรณ์ อุดมคุณ" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820400309367">
                                            <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางเพ็ญแข หวานสนิท" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3930300329632">
                                            <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายจรุง  บำรุงเขตต์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820400261097">
                                            <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820700017680">
                                            <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายณัฐพงษ์ สัจจารักษ์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1319800069611">
                                            <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายธีรภัส  สฤษดิสุข" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1839900193629">
                                            <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายธีระวัฒน์ เพชรขุ้ม" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1841500136302">
                                            <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายพจนันท์  พรหมสงค์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900109890">
                                            <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายรชต  ปานบุญ" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900012446">
                                            <span class="member-name">นายรชต ปานบุญ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายวรานนท์ ภาระพฤติ" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820100028745">
                                            <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายศุภสวัสดิ์ กาญวิจิต" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900093446">
                                            <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายอนุสรณ์ ชูทอง" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3929900087867">
                                            <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1901100006087">
                                            <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายเอกพงษ์ สงวนทรัพย์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900072562">
                                            <span class="member-name">นายเอกพงษ์ สงวนทรัพย์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed" data-faction-id="4">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-4" data-group-label="กลุ่มบริหารงานทั่วไป" data-members="[{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;}]" name="faction_ids[]" value="4">
                                        <span class="item-title">กลุ่มบริหารงานทั่วไป</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 21 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางจิตติพร เกตุรักษ์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1820500007021">
                                            <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางพูนสุข ถิ่นลิพอน" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3820100172170">
                                            <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางภทรมน ลิ่มบุตร" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1809900084706">
                                            <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางวาสนา  สุทธจิตร์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3820100025495">
                                            <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวธารทิพย์ ภาระพฤติ" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3850100320012">
                                            <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวนิรชา ธรรมัสโร" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1829900174284">
                                            <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวปาณิสรา  มงคลบุตร" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3829900019706">
                                            <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวพรทิพย์ สมบัติบุญ" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1920100023843">
                                            <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวพรรณพนัช  คงผอม" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1101401730717">
                                            <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวรัตนาพร พรประสิทธิ์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1820500148121">
                                            <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวราศรี  อนันตมงคลกุล" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3820500121271">
                                            <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวสรัลรัตน์ จันทับ" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1809901015490">
                                            <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวสุดาทิพย์ ยกย่อง" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1820800031408">
                                            <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายนพดล วงศ์สุวัฒน์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1860700158147">
                                            <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายนพพร  ถิ่นไทย" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1860100007288">
                                            <span class="member-name">นายนพพร ถิ่นไทย</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายนรินทร์เพชร นิลเวช" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1102003266698">
                                            <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายวิศรุต ชามทอง" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1160100618291">
                                            <span class="member-name">นายวิศรุต ชามทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายสหัส เสือยืนยง" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3810500157631">
                                            <span class="member-name">นายสหัส เสือยืนยง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายอิสรพงศ์ สัตปานนท์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1829900162341">
                                            <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายเพลิน โอรักษ์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3180600191510">
                                            <span class="member-name">นายเพลิน โอรักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1809900094507">
                                            <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์ ผสารพจน์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed" data-faction-id="3">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-3" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;}]" name="faction_ids[]" value="3">
                                        <span class="item-title">กลุ่มบริหารงานบุคคลและงบประมาณ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 26 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางจริยาวดี  เวชจันทร์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="5800900028151">
                                            <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางจิราภรณ์  เสรีรักษ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3800400522290">
                                            <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3920100747937">
                                            <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางปวีณา  บำรุงภักดิ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900007736">
                                            <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางฝาติหม๊ะ ขนาดผล" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1930600099890">
                                            <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900179103">
                                            <span class="member-name">นางสาวกนกลักษณ์ พันธ์สวัสดิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวกานต์พิชชา ปากลาว" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1810500062871">
                                            <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวธนวรรณ พิทักษ์คง" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820500147966">
                                            <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวธิดารัตน์ ทองกอบ" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900119712">
                                            <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวนฤมล บุญถาวร" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1920600250041">
                                            <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวนัยน์เนตร ทองวล" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900118058">
                                            <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวนิลญา หมานมิตร" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1910300050321">
                                            <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวบงกชรัตน์  มาลี" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900051727">
                                            <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวบุษรา  เมืองชู" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1840100431373">
                                            <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวปริษา  แก้วเขียว" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900090897">
                                            <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวปาณิสรา  มัจฉาเวช" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820400055491">
                                            <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวปิยธิดา นิยมเดชา" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820600006469">
                                            <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3820100171700">
                                            <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวลภัสภาส์ หนูคง" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820500130320">
                                            <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาววรินญา โรจธนะวรรธน์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1940100013597">
                                            <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1840100326120">
                                            <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1102001245405">
                                            <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสุมณฑา  เกิดทรัพย์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3820700050342">
                                            <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางอรชา ชูเชื้อ" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820700004867">
                                            <span class="member-name">นางอรชา ชูเชื้อ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นายสราวุธ กุหลาบวรรณ" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1800800331088">
                                            <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นายไชยวัฒน์ สังข์ทอง" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1640700056303">
                                            <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed" data-faction-id="2">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-2" data-group-label="กลุ่มบริหารงานวิชาการ" data-members="[{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;}]" name="faction_ids[]" value="2">
                                        <span class="item-title">กลุ่มบริหารงานวิชาการ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 45 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางกนกวรรณ  ณ นคร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3810100580006">
                                            <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางจารุวรรณ ส่องศิริ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820100025592">
                                            <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางณิภาภรณ์  ไชยชนะ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3930300511171">
                                            <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางดวงกมล  เพ็ชรพรหม" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3840100521778">
                                            <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางดาริน ทรายทอง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820300027670">
                                            <span class="member-name">นางดาริน ทรายทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางธนิษฐา  ยงยุทธ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900063989">
                                            <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางประภาพร  อุดมผลชัยเจริญ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3331001384867">
                                            <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางผกาวรรณ  โชติวัฒนากร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1920600003469">
                                            <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางพนิดา ค้าของ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3940400027034">
                                            <span class="member-name">นางพนิดา ค้าของ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางพรพิมล แซ่เจี่ย" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1839900175043">
                                            <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางพิมพา ทองอุไร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900003064">
                                            <span class="member-name">นางพิมพา ทองอุไร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900054688">
                                            <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900059485">
                                            <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวจิราวัลย์  อินทร์อักษร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1930500083592">
                                            <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวชนิกานต์  สวัสดิวงค์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3829900033725">
                                            <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวณพสร สามสุวรรณ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3840200430855">
                                            <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1729900457121">
                                            <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวธนวรรณ สมัครการ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900202598">
                                            <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวนุชรีย์ หัศนี" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1820700006258">
                                            <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวประภัสสร  โอจันทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3840700282162">
                                            <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวประภาพรรณ กุลแก้ว" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1410100117524">
                                            <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวพิมพ์ประภา  ผลากิจ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900096909">
                                            <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820400028481">
                                            <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวรัชฎาพร สุวรรณสาม" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900012535">
                                            <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวรัชนีกร ผอมจีน" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1820500097624">
                                            <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820700136859">
                                            <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวศศิธร นาคสง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3801600044431">
                                            <span class="member-name">นางสาวศศิธร นาคสง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวศุลีพร ขันภักดี" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900099401">
                                            <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900065485">
                                            <span class="member-name">นางสาวองค์ปรางค์ แสงสุรินทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวอภิชญา จันทร์มา" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1909901558298">
                                            <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวอาตีนา  พัชนี" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1800100218262">
                                            <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวอินทิรา บุญนิสสัย" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1800800204043">
                                            <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1930500116202">
                                            <span class="member-name">นางสุรางค์รัศมิ์ ย้อยพระจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางเสาวลีย์ จันทร์ทอง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820700019381">
                                            <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายจตุรวิทย์ มิตรวงศ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1859900070560">
                                            <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1809901028575">
                                            <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายธันวิน  ณ นคร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1959900030702">
                                            <span class="member-name">นายธันวิน ณ นคร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายนพรัตน์ ย้อยพระจันทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1839900094990">
                                            <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายบพิธ มังคะลา" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1819900163142">
                                            <span class="member-name">นายบพิธ มังคะลา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายประสิทธิ์  สะไน" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1920400002230">
                                            <span class="member-name">นายประสิทธิ์ สะไน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายพิพัฒน์ ไชยชนะ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3940400221191">
                                            <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายศรายุทธ  มิตรวงค์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820800037747">
                                            <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900056460">
                                            <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายสมชาย สุทธจิตร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820700143669">
                                            <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายสุพัฒน์  เจริญฤทธิ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820400194578">
                                            <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed" data-faction-id="6">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-6" data-group-label="กลุ่มสนับสนุนการสอน" data-members="[{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;}]" name="faction_ids[]" value="6">
                                        <span class="item-title">กลุ่มสนับสนุนการสอน</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-6" data-member-name="นางสาวนัฐลิณี ทอสงค์" data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]" value="1820700059157">
                                            <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-6" data-member-name="นางสาวอุบลวรรณ คงสม" data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]" value="1829900149409">
                                            <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="faction-6" data-member-name="นายสิงหนาท  แต่งแก้ว" data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]" value="3810200084621">
                                            <span class="member-name">นายสิงหนาท แต่งแก้ว</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <div class="category-group">
                        <div class="category-title">
                            <span>กลุ่มสาระ</span>
                        </div>
                        <div class="category-items">
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-9" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" data-members="[{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;}]" value="department-9">
                                        <span class="item-title">กลุ่มกิจกรรมพัฒนาผู้เรียน</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-9" data-member-name="นางสาวนุชรีย์ หัศนี" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]" value="1820700006258">
                                            <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-9" data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]" value="1102001245405">
                                            <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-9" data-member-name="นายสิงหนาท  แต่งแก้ว" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]" value="3810200084621">
                                            <span class="member-name">นายสิงหนาท แต่งแก้ว</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-10" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" data-members="[{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;}]" value="department-10">
                                        <span class="item-title">กลุ่มคอมพิวเตอร์และเทคโนโลยี</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นางสาวจิราวัลย์  อินทร์อักษร" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1930500083592">
                                            <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นางสาวศศิธร นาคสง" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="3801600044431">
                                            <span class="member-name">นางสาวศศิธร นาคสง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายจตุรวิทย์ มิตรวงศ์" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1859900070560">
                                            <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายธันวิน  ณ นคร" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1959900030702">
                                            <span class="member-name">นายธันวิน ณ นคร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายบพิธ มังคะลา" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1819900163142">
                                            <span class="member-name">นายบพิธ มังคะลา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายสหัส เสือยืนยง" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="3810500157631">
                                            <span class="member-name">นายสหัส เสือยืนยง</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-11" data-group-label="กลุ่มธุรการ" data-members="[{&quot;pID&quot;:&quot;3820400234871&quot;,&quot;name&quot;:&quot;นางนวลน้อย  ชูสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1800700082485&quot;,&quot;name&quot;:&quot;นางสาว ณัฐชลียา ยิ่งคง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1829900082835&quot;,&quot;name&quot;:&quot;นางสาวจารุลักษณ์  ตรีศรี&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100155283&quot;,&quot;name&quot;:&quot;นางสาวจิราวรรณ ว่องปลูกศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;2800800033557&quot;,&quot;name&quot;:&quot;นางสาวธัญเรศ  วรศานต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820600035619&quot;,&quot;name&quot;:&quot;นางสาวนภัสสร  รัฐการ&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1810600075673&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร พันธ์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100140782&quot;,&quot;name&quot;:&quot;นางสาวศศิธร  มธุรส&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3810300076964&quot;,&quot;name&quot;:&quot;นายอดิศักดิ์  ธรรมจิตต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;}]" value="department-11">
                                        <span class="item-title">กลุ่มธุรการ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางนวลน้อย  ชูสงค์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820400234871">
                                            <span class="member-name">นางนวลน้อย ชูสงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาว ณัฐชลียา ยิ่งคง" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1800700082485">
                                            <span class="member-name">นางสาว ณัฐชลียา ยิ่งคง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวจารุลักษณ์  ตรีศรี" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1829900082835">
                                            <span class="member-name">นางสาวจารุลักษณ์ ตรีศรี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวจิราวรรณ ว่องปลูกศิลป์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820100155283">
                                            <span class="member-name">นางสาวจิราวรรณ ว่องปลูกศิลป์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวธัญเรศ  วรศานต์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="2800800033557">
                                            <span class="member-name">นางสาวธัญเรศ วรศานต์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวนภัสสร  รัฐการ" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820600035619">
                                            <span class="member-name">นางสาวนภัสสร รัฐการ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวประภัสสร พันธ์แก้ว" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1810600075673">
                                            <span class="member-name">นางสาวประภัสสร พันธ์แก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวศศิธร  มธุรส" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820100140782">
                                            <span class="member-name">นางสาวศศิธร มธุรส</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นายอดิศักดิ์  ธรรมจิตต์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3810300076964">
                                            <span class="member-name">นายอดิศักดิ์ ธรรมจิตต์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นายไชยวัฒน์ สังข์ทอง" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1640700056303">
                                            <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-7" data-group-label="กลุ่มสาระฯ การงานอาชีพ" data-members="[{&quot;pID&quot;:&quot;1829900062591&quot;,&quot;name&quot;:&quot;นางสาวจารุวรรณ ผลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3810500179350&quot;,&quot;name&quot;:&quot;นางสาวนงลักษณ์   แก้วสว่าง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1849900176813&quot;,&quot;name&quot;:&quot;นายชนม์กมล เพ็ขรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;}]" value="department-7">
                                        <span class="item-title">กลุ่มสาระฯ การงานอาชีพ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 9 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางสาวจารุวรรณ ผลแก้ว" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1829900062591">
                                            <span class="member-name">นางสาวจารุวรรณ ผลแก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางสาวนงลักษณ์   แก้วสว่าง" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="3810500179350">
                                            <span class="member-name">นางสาวนงลักษณ์ แก้วสว่าง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นายชนม์กมล เพ็ขรพรหม" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1849900176813">
                                            <span class="member-name">นายชนม์กมล เพ็ขรพรหม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางพิมพา ทองอุไร" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1829900003064">
                                            <span class="member-name">นางพิมพา ทองอุไร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางจริยาวดี  เวชจันทร์" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="5800900028151">
                                            <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางจิราภรณ์  เสรีรักษ์" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="3800400522290">
                                            <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางพูนสุข ถิ่นลิพอน" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="3820100172170">
                                            <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางภทรมน ลิ่มบุตร" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1809900084706">
                                            <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นายนพพร  ถิ่นไทย" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1860100007288">
                                            <span class="member-name">นายนพพร ถิ่นไทย</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-2" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" data-members="[{&quot;pID&quot;:&quot;1829900206275&quot;,&quot;name&quot;:&quot;นายภูมิวิชญ์ จีนนาพัฒ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;}]" value="department-2">
                                        <span class="item-title">กลุ่มสาระฯ คณิตศาสตร์</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายภูมิวิชญ์ จีนนาพัฒ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900206275">
                                            <span class="member-name">นายภูมิวิชญ์ จีนนาพัฒ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางกนกวรรณ  ณ นคร" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3810100580006">
                                            <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางประภาพร  อุดมผลชัยเจริญ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3331001384867">
                                            <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางผกาวรรณ  โชติวัฒนากร" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1920600003469">
                                            <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางพรพิมล แซ่เจี่ย" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1839900175043">
                                            <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวพิมพ์ประภา  ผลากิจ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900096909">
                                            <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวรัชนีกร ผอมจีน" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1820500097624">
                                            <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวอภิชญา จันทร์มา" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1909901558298">
                                            <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวอินทิรา บุญนิสสัย" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1800800204043">
                                            <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางเสาวลีย์ จันทร์ทอง" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3820700019381">
                                            <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1809901028575">
                                            <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายพิพัฒน์ ไชยชนะ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3940400221191">
                                            <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางฝาติหม๊ะ ขนาดผล" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1930600099890">
                                            <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวธิดารัตน์ ทองกอบ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900119712">
                                            <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวบงกชรัตน์  มาลี" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900051727">
                                            <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายสราวุธ กุหลาบวรรณ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1800800331088">
                                            <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวพรทิพย์ สมบัติบุญ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1920100023843">
                                            <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวรัตนาพร พรประสิทธิ์" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1820500148121">
                                            <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายอนุสรณ์ ชูทอง" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3929900087867">
                                            <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวนัฐลิณี ทอสงค์" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1820700059157">
                                            <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-8" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" data-members="[{&quot;pID&quot;:&quot;1820800093039&quot;,&quot;name&quot;:&quot;นางสาวปาริชาต เดชอาษา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1809900831358&quot;,&quot;name&quot;:&quot;นางสาวพลอยไพลิน เที่ยวแสวง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;}]" value="department-8">
                                        <span class="item-title">กลุ่มสาระฯ ภาษาต่างประเทศ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวปาริชาต เดชอาษา" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1820800093039">
                                            <span class="member-name">นางสาวปาริชาต เดชอาษา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวพลอยไพลิน เที่ยวแสวง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1809900831358">
                                            <span class="member-name">นางสาวพลอยไพลิน เที่ยวแสวง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางดาริน ทรายทอง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3820300027670">
                                            <span class="member-name">นางดาริน ทรายทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางพนิดา ค้าของ" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3940400027034">
                                            <span class="member-name">นางพนิดา ค้าของ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900054688">
                                            <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900059485">
                                            <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1729900457121">
                                            <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวธนวรรณ สมัครการ" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900202598">
                                            <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900065485">
                                            <span class="member-name">นางสาวองค์ปรางค์ แสงสุรินทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1930500116202">
                                            <span class="member-name">นางสุรางค์รัศมิ์ ย้อยพระจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวกานต์พิชชา ปากลาว" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1810500062871">
                                            <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวนิลญา หมานมิตร" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1910300050321">
                                            <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวปริษา  แก้วเขียว" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900090897">
                                            <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาววรินญา โรจธนะวรรธน์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1940100013597">
                                            <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นายอิสรพงศ์ สัตปานนท์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900162341">
                                            <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางพวงทิพย์ ทวีรส" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3950300068146">
                                            <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางเขมษิญากรณ์ อุดมคุณ" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3820400309367">
                                            <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางเพ็ญแข หวานสนิท" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3930300329632">
                                            <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3820700017680">
                                            <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นายธีระวัฒน์ เพชรขุ้ม" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1841500136302">
                                            <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-1" data-group-label="กลุ่มสาระฯ ภาษาไทย" data-members="[{&quot;pID&quot;:&quot;1829900103735&quot;,&quot;name&quot;:&quot;นางสาวจันทนี บุญนำ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900141980&quot;,&quot;name&quot;:&quot;นางสาวสุกานดา ปานมั่งคั่ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;}]" value="department-1">
                                        <span class="item-title">กลุ่มสาระฯ ภาษาไทย</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 14 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวจันทนี บุญนำ" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900103735">
                                            <span class="member-name">นางสาวจันทนี บุญนำ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวสุกานดา ปานมั่งคั่ง" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900141980">
                                            <span class="member-name">นางสาวสุกานดา ปานมั่งคั่ง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางดวงกมล  เพ็ชรพรหม" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3840100521778">
                                            <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวณพสร สามสุวรรณ" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3840200430855">
                                            <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3820400028481">
                                            <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3820700136859">
                                            <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวนัยน์เนตร ทองวล" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900118058">
                                            <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวบุษรา  เมืองชู" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1840100431373">
                                            <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางจิตติพร เกตุรักษ์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1820500007021">
                                            <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวพรรณพนัช  คงผอม" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1101401730717">
                                            <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวราศรี  อนันตมงคลกุล" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3820500121271">
                                            <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นายนพดล วงศ์สุวัฒน์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1860700158147">
                                            <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นายนรินทร์เพชร นิลเวช" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1102003266698">
                                            <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นายพจนันท์  พรหมสงค์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900109890">
                                            <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-3" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" data-members="[{&quot;pID&quot;:&quot;1819300006267&quot;,&quot;name&quot;:&quot;นายคุณากร ประดับศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400295111&quot;,&quot;name&quot;:&quot;นายนิมิตร สุสิมานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;}]" value="department-3">
                                        <span class="item-title">กลุ่มสาระฯ วิทยาศาสตร์</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 24 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายคุณากร ประดับศิลป์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1819300006267">
                                            <span class="member-name">นายคุณากร ประดับศิลป์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายนิมิตร สุสิมานนท์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820400295111">
                                            <span class="member-name">นายนิมิตร สุสิมานนท์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางณิภาภรณ์  ไชยชนะ" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3930300511171">
                                            <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางธนิษฐา  ยงยุทธ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900063989">
                                            <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวรัชฎาพร สุวรรณสาม" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900012535">
                                            <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวศุลีพร ขันภักดี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900099401">
                                            <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวอาตีนา  พัชนี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1800100218262">
                                            <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายนพรัตน์ ย้อยพระจันทร์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1839900094990">
                                            <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3920100747937">
                                            <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900179103">
                                            <span class="member-name">นางสาวกนกลักษณ์ พันธ์สวัสดิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวนฤมล บุญถาวร" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1920600250041">
                                            <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวปาณิสรา  มัจฉาเวช" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1820400055491">
                                            <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820100171700">
                                            <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1840100326120">
                                            <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสุมณฑา  เกิดทรัพย์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820700050342">
                                            <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางอรชา ชูเชื้อ" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1820700004867">
                                            <span class="member-name">นางอรชา ชูเชื้อ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางชมทิศา ขันภักดี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820400215231">
                                            <span class="member-name">นางชมทิศา ขันภักดี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวชาลิสา จิตต์พันธ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900172052">
                                            <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวอรบุษย์ หนักแน่น" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900170670">
                                            <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสุนิษา  จินดาพล" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3601000301019">
                                            <span class="member-name">นางสุนิษา จินดาพล</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายณัฐพงษ์ สัจจารักษ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1319800069611">
                                            <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายธีรภัส  สฤษดิสุข" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1839900193629">
                                            <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายรชต  ปานบุญ" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900012446">
                                            <span class="member-name">นายรชต ปานบุญ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวอุบลวรรณ คงสม" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900149409">
                                            <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-6" data-group-label="กลุ่มสาระฯ ศิลปะ" data-members="[{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;}]" value="department-6">
                                        <span class="item-title">กลุ่มสาระฯ ศิลปะ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 7 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นางสาวประภัสสร  โอจันทร์" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3840700282162">
                                            <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="1829900056460">
                                            <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายสุพัฒน์  เจริญฤทธิ์" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3820400194578">
                                            <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นางสาวธารทิพย์ ภาระพฤติ" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3850100320012">
                                            <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นางสาวปาณิสรา  มงคลบุตร" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3829900019706">
                                            <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายวิศรุต ชามทอง" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="1160100618291">
                                            <span class="member-name">นายวิศรุต ชามทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="1901100006087">
                                            <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-4" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" data-members="[{&quot;pID&quot;:&quot;1830101156953&quot;,&quot;name&quot;:&quot;นางสาวนัสรีน สุวิสัน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1810300103434&quot;,&quot;name&quot;:&quot;นางสาวปณิดา คลองรั้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820501214179&quot;,&quot;name&quot;:&quot;นายมงคล ตันเจริญรัตน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;}]" value="department-4">
                                        <span class="item-title">กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 18 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวนัสรีน สุวิสัน" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1830101156953">
                                            <span class="member-name">นางสาวนัสรีน สุวิสัน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวปณิดา คลองรั้ว" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1810300103434">
                                            <span class="member-name">นางสาวปณิดา คลองรั้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายมงคล ตันเจริญรัตน์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820501214179">
                                            <span class="member-name">นายมงคล ตันเจริญรัตน์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางจารุวรรณ ส่องศิริ" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3820100025592">
                                            <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวชนิกานต์  สวัสดิวงค์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3829900033725">
                                            <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวประภาพรรณ กุลแก้ว" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1410100117524">
                                            <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางปวีณา  บำรุงภักดิ์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1829900007736">
                                            <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวธนวรรณ พิทักษ์คง" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820500147966">
                                            <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวปิยธิดา นิยมเดชา" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820600006469">
                                            <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวลภัสภาส์ หนูคง" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820500130320">
                                            <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางวาสนา  สุทธจิตร์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3820100025495">
                                            <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวนิรชา ธรรมัสโร" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1829900174284">
                                            <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวสรัลรัตน์ จันทับ" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1809901015490">
                                            <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวสุดาทิพย์ ยกย่อง" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820800031408">
                                            <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายเพลิน โอรักษ์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3180600191510">
                                            <span class="member-name">นายเพลิน โอรักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1809900094507">
                                            <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์ ผสารพจน์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายวรานนท์ ภาระพฤติ" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3820100028745">
                                            <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายศุภสวัสดิ์ กาญวิจิต" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1829900093446">
                                            <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-5" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" data-members="[{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;}]" value="department-5">
                                        <span class="item-title">กลุ่มสาระฯ สุขศึกษาและพลศึกษา</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายประสิทธิ์  สะไน" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="1920400002230">
                                            <span class="member-name">นายประสิทธิ์ สะไน</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายศรายุทธ  มิตรวงค์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820800037747">
                                            <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายสมชาย สุทธจิตร์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820700143669">
                                            <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นางสาวนิรัตน์ เพชรแก้ว" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820800038999">
                                            <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายจรุง  บำรุงเขตต์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820400261097">
                                            <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายเอกพงษ์ สงวนทรัพย์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="1829900072562">
                                            <span class="member-name">นายเอกพงษ์ สงวนทรัพย์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <div class="category-group">
                        <div class="category-title">
                            <span>อื่นๆ</span>
                        </div>
                        <div class="category-items">
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox" data-group="special" data-group-key="special-executive" data-group-label="คณะผู้บริหารสถานศึกษา" data-members="[{&quot;pID&quot;:&quot;1820500005169&quot;,&quot;name&quot;:&quot;นางสาวศริญญา  ผั้วผดุง&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3810500334835&quot;,&quot;name&quot;:&quot;นายดลยวัฒน์ สันติพิทักษ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;1820500004103&quot;,&quot;name&quot;:&quot;นายยุทธนา สุวรรณวิสุทธิ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3430200354125&quot;,&quot;name&quot;:&quot;นายไกรวิชญ์ อ่อนแก้ว&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;}]" value="special-executive">
                                        <span class="item-title">คณะผู้บริหารสถานศึกษา</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 4 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นางสาวศริญญา  ผั้วผดุง" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="1820500005169">
                                            <span class="member-name">นางสาวศริญญา ผั้วผดุง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นายดลยวัฒน์ สันติพิทักษ์" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="3810500334835">
                                            <span class="member-name">นายดลยวัฒน์ สันติพิทักษ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นายยุทธนา สุวรรณวิสุทธิ์" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="1820500004103">
                                            <span class="member-name">นายยุทธนา สุวรรณวิสุทธิ์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นายไกรวิชญ์ อ่อนแก้ว" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="3430200354125">
                                            <span class="member-name">นายไกรวิชญ์ อ่อนแก้ว</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                            <div class="item item-group is-collapsed">
                                <div class="group-header">
                                    <label class="item-main">
                                        <input type="checkbox" class="item-checkbox group-item-checkbox" data-group="special" data-group-key="special-subject-head" data-group-label="หัวหน้ากลุ่มสาระ" data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;}]" value="special-subject-head">
                                        <span class="item-title">หัวหน้ากลุ่มสาระ</span>
                                        <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                    </label>
                                    <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <ol class="member-sublist">
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางจริยาวดี  เวชจันทร์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="5800900028151">
                                            <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางดวงกมล  เพ็ชรพรหม" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3840100521778">
                                            <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางพนิดา ค้าของ" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3940400027034">
                                            <span class="member-name">นางพนิดา ค้าของ</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางสาวนุชรีย์ หัศนี" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1820700006258">
                                            <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางสาวสุดาทิพย์ ยกย่อง" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1820800031408">
                                            <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางเสาวลีย์ จันทร์ทอง" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3820700019381">
                                            <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายธันวิน  ณ นคร" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1959900030702">
                                            <span class="member-name">นายธันวิน ณ นคร</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายนพรัตน์ ย้อยพระจันทร์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1839900094990">
                                            <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายสมชาย สุทธจิตร์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3820700143669">
                                            <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                        </label>
                                    </li>
                                    <li>
                                        <label class="item member-item">
                                            <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายสุพัฒน์  เจริญฤทธิ์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3820400194578">
                                            <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                        </label>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
</div>
<div class="sent-notice-selected">
    <button id="btnShowRecipients" type="button">
        <p>แสดงผู้รับทั้งหมด</p>
    </button>
</div>
</div>

<div id="recipientModal" class="modal-overlay-recipient">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-users"></i>
                <span>รายชื่อผู้รับหนังสือเวียน</span>
            </div>
            <button class="modal-close" id="closeModalBtn" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <table class="recipient-table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>ชื่อจริง-นามสกุล</th>
                        <th>กลุ่ม/ฝ่าย</th>
                    </tr>
                </thead>
                <tbody id="recipientTableBody"></tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="form-group row">
    <div class="input-group">
        <p><strong>แนบลิ้งก์</strong></p>
        <input
            type="url"
            name="linkURL"
            class="order-no-display"
            value="<?= h((string) ($values['linkURL'] ?? '')) ?>"
            placeholder="แนบลิ้งก์ที่เกี่ยวข้อง (ถ้ามี)">
    </div>
</div>

<div class="form-group row label">
    <div class="input-group">
        <p><strong>อัปโหลดไฟล์หนังสือนำ</strong></p>
    </div>
</div>

<div class="form-group row">
    <section class="upload-layout">
        <input type="file" id="coverFileInput" name="cover_file" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" style="display: none;">
        <div class="row form-group">
            <button class="btn btn-upload-small" type="button" id="btnCoverAddFile">
                <p>เพิ่มไฟล์</p>
            </button>
            <div class="file-hint">
                <p>* แนบไฟล์หนังสือนำได้ 1 ไฟล์ *</p>
            </div>
        </div>
        <div class="existing-file-section">
            <div class="file-list" id="coverFileListContainer"></div>
        </div>
    </section>
</div>

<div class="form-group row">
    <div class="input-group">
        <p><strong>อัปโหลดไฟล์เอกสาร</strong></p>
        <section class="upload-layout">
            <input type="file" id="fileInput" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" style="display: none;" />

            <div class="upload-box" id="dropzone">
                <i class="fa-solid fa-upload"></i>
                <p>ลากไฟล์มาวางที่นี่</p>
            </div>

            <div class="file-list" id="fileListContainer"></div>
        </section>
    </div>
</div>

<div class="form-group row">
    <button class="btn btn-upload-small" type="button" id="btnAddFiles">
        <p>เพิ่มไฟล์</p>
    </button>
    <div class="file-hint">
        <p>* แนบไฟล์ได้สูงสุด 5 ไฟล์ (รวม PNG และ PDF) ถ้ามี *</p>
    </div>
</div>

<div class="form-group row">
    <div class="input-group">
        <p><strong>ผู้รับหนังสือ</strong></p>
        <input
            type="text"
            class="order-no-display"
            value="<?= h($current_user_name !== '' ? $current_user_name : '-') ?>"
            disabled>
    </div>
    <div class="input-group"></div>
</div>

<div class="form-group last button">
    <div class="input-group">
        <button
            class="submit"
            type="submit"
            data-confirm="ยืนยันการบันทึกเอกสารใช่หรือไม่?"
            data-confirm-title="ยืนยันการบันทึก"
            data-confirm-ok="ยืนยัน"
            data-confirm-cancel="ยกเลิก">
            <p>บันทึกเอกสาร</p>
        </button>
    </div>
</div>

</form>
</div>

<section class="enterprise-card tab-content <?= $is_track_active ? 'active' : '' ?>" id="outgoingMine">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" class="circular-my-filter-grid">
        <input type="hidden" name="tab" value="track">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($filter_query) ?>"
                    placeholder="ค้นหาลงทะเบียนรับหนังสือของฉัน" autocomplete="off">
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value">
                            <?php
                            $status_label = 'ทั้งหมด';

                            if ($filter_status === 'submitted') {
                                $status_label = 'รับเข้าแล้ว';
                            } elseif ($filter_status === 'pending_review') {
                                $status_label = 'กำลังเสนอ';
                            } elseif ($filter_status === 'reviewed') {
                                $status_label = 'พิจารณาแล้ว';
                            } elseif ($filter_status === 'forwarded') {
                                $status_label = 'ส่งแล้ว';
                            }
                            echo h($status_label);
                            ?>
                        </p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option<?= $filter_status === 'all' ? ' selected' : '' ?>" data-value="all">ทั้งหมด</div>
                        <div class="custom-option<?= $filter_status === 'submitted' ? ' selected' : '' ?>" data-value="submitted">รับเข้าแล้ว</div>
                        <div class="custom-option<?= $filter_status === 'pending_review' ? ' selected' : '' ?>" data-value="pending_review">กำลังเสนอ</div>
                        <div class="custom-option<?= $filter_status === 'reviewed' ? ' selected' : '' ?>" data-value="reviewed">พิจารณาแล้ว</div>
                        <div class="custom-option<?= $filter_status === 'forwarded' ? ' selected' : '' ?>" data-value="forwarded">ส่งแล้ว</div>
                    </div>

                    <select class="form-input" name="status">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                        <option value="submitted" <?= $filter_status === 'submitted' ? 'selected' : '' ?>>รับเข้าแล้ว</option>
                        <option value="pending_review" <?= $filter_status === 'pending_review' ? 'selected' : '' ?>>กำลังเสนอ</option>
                        <option value="reviewed" <?= $filter_status === 'reviewed' ? 'selected' : '' ?>>พิจารณาแล้ว</option>
                        <option value="forwarded" <?= $filter_status === 'forwarded' ? 'selected' : '' ?>>ส่งแล้ว</option>
                    </select>
                </div>
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($filter_sort === 'oldest' ? 'เก่าไปใหม่' : 'ใหม่ไปเก่า') ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option" data-value="newest">ใหม่ไปเก่า</div>
                        <div class="custom-option" data-value="oldest">เก่าไปใหม่</div>
                    </div>

                    <select class="form-input" name="sort">
                        <option value="newest" <?= $filter_sort === 'newest' ? 'selected' : '' ?>>ใหม่ไปเก่า</option>
                        <option value="oldest" <?= $filter_sort === 'oldest' ? 'selected' : '' ?>>เก่าไปใหม่</option>
                    </select>
                </div>
            </div>
        </div>
    </form>

    <div class="enterprise-card-header order-mine-list-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">รายการลงทะเบียนรับหนังสือของฉัน</h2>
        </div>
    </div>

    <div class="table-responsive table-circular-notice-index circular-my-table-wrap order-create">
        <script type="application/json" class="js-order-send-map">
            <?= (string) json_encode($send_modal_payload_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        </script>
        <table class="custom-table circular-my-table">
            <thead>
                <tr>
                    <th>จัดการ</th>
                    <th>เลขรับ / เลขที่ / เรื่อง</th>
                    <th>จาก</th>
                    <th>วันที่ดำเนินการ</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="5" class="booking-empty">ไม่พบรายการลงทะเบียนรับหนังสือของฉัน</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $circular_id = (int) ($item['circularID'] ?? 0);
                        $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
                        $status_meta = $track_status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
                        $date_display_parts = $format_thai_datetime_parts((string) ($item['createdAt'] ?? ''));
                        $receive_seq = (int) ($item['extReceiveSeq'] ?? 0);
                        $book_no = trim((string) ($item['extBookNo'] ?? ''));
                        $priority_key = outgoing_normalize_priority_key((string) ($item['extPriority'] ?? 'ปกติ'));
                        $is_editable = $status_key === EXTERNAL_STATUS_SUBMITTED;
                        ?>
                        <tr>
                            <td>
                                <div class="circular-my-actions">
                                    <?php if ($is_editable) : ?>
                                        <a
                                            class="booking-action-btn secondary"
                                            href="outgoing-receive.php?tab=track&amp;edit=<?= h((string) $circular_id) ?>"
                                            title="แก้ไขส่งใหม่"
                                            aria-label="แก้ไขส่งใหม่">
                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                            <span class="tooltip">แก้ไขส่งใหม่</span>
                                        </a>
                                    <?php else : ?>
                                        <button class="booking-action-btn secondary js-open-order-view-modal" type="button" data-outgoing-id="<?= h((string) $circular_id) ?>" data-outgoing-priority-key="<?= h($priority_key) ?>" title="ดูรายละเอียด" aria-label="ดูรายละเอียด">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                            <span class="tooltip">ดูรายละเอียด</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($book_no !== '') : ?>
                                    <div class="outgoing-receive-doc-line">#<?= h((string) $receive_seq) ?>, <?= h($book_no) ?></div>
                                <?php endif; ?>
                                <div class="outgoing-receive-doc-subject"><?= h((string) ($item['subject'] ?? '-')) ?></div>
                            </td>
                            <td><?= h(trim((string) ($item['extFromText'] ?? '')) !== '' ? (string) ($item['extFromText'] ?? '') : '-') ?></td>
                            <td>
                                <div class="order-create-datetime">
                                    <span class="order-create-datetime-date"><?= h((string) ($date_display_parts['date'] ?? '-')) ?></span>
                                    <span class="order-create-datetime-time"><?= h((string) ($date_display_parts['time'] ?? '-')) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-pill <?= h((string) ($status_meta['pill'] ?? 'pending')) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalOrderEditOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p id="modalOutgoingEditTitle">แก้ไขเอกสารลงทะเบียนรับหนังสือ</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeModalOrderSend"></i>
                </div>
            </div>

            <div class="content-modal">
                <div class="type-urgent">
                    <p>ประเภท</p>
                    <div class="radio-group-urgent">
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="normal" checked id="modalOutgoingViewUrgentNormal"><label for="modalOutgoingViewUrgentNormal">ปกติ</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="urgent" id="modalOutgoingViewUrgentUrgent"><label for="modalOutgoingViewUrgentUrgent">ด่วน</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="high" id="modalOutgoingViewUrgentHigh"><label for="modalOutgoingViewUrgentHigh">ด่วนมาก</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="highest" id="modalOutgoingViewUrgentHighest"><label for="modalOutgoingViewUrgentHighest">ด่วนที่สุด</label>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เลขที่หนังสือ</strong></p>
                        <input type="text" id="modalOutgoingViewNo" class="" value="" placeholder="เช่น สธ 04066/2">
                    </div>
                    <div class="more-details">
                        <p><strong>ลงวันที่</strong></p>
                        <input type="date" id="modalOutgoingViewSubject" class="" value="">
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เรื่อง</strong></p>
                        <input type="text" id="modalOutgoingViewNo" class="" value="" placeholder="ระบุเรื่องที่จะลงทะเบียนหนังสือเวียน">
                    </div>
                    <div class="more-details">
                        <p><strong>จาก</strong></p>
                        <input type="text" id="modalOutgoingViewSubject" class="" value="" placeholder="ระบุแหล่งที่มา">
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ถึงกลุ่ม</strong></p>
                        <div class="custom-select-wrapper">
                            <div class="custom-select-trigger">
                                <p class="select-value">กลุ่มบริหารงานทั่วไป</p>
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </div>

                            <div class="custom-options">
                                <div class="custom-option" data-value="5">กลุ่มบริหารกิจการนักเรียน</div>
                                <div class="custom-option selected" data-value="4">กลุ่มบริหารงานทั่วไป</div>
                                <div class="custom-option" data-value="3">กลุ่มบริหารงานบุคคลและงบประมาณ</div>
                                <div class="custom-option" data-value="2">กลุ่มบริหารงานวิชาการ</div>
                                <div class="custom-option" data-value="6">กลุ่มสนับสนุนการสอน</div>
                                <div class="custom-option" data-value="1">ฝ่ายบริหาร</div>
                            </div>

                            <input type="hidden" name="group_fid" value="4">
                        </div>
                    </div>
                    <div class="more-details"></div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เกษียณหนังสือ</strong></p>
                        <textarea id="memo_editor_suggest"></textarea>
                    </div>
                </div>

                <div class="form-group receive" data-recipients-section="" data-owner-flat-list="true">
                    <label><strong>เสนอ :</strong></label>
                    <div class="dropdown-container">
                        <div class="search-input-wrapper" id="recipientToggle">
                            <input type="text" id="mainInput" class="search-input" placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </div>

                        <div class="dropdown-content" id="dropdownContent">
                            <div class="dropdown-header">
                                <label class="select-all-box">
                                    <input type="checkbox" id="selectAll">เลือกทั้งหมด
                                </label>
                            </div>

                            <div class="dropdown-list">
                                <div class="category-group">
                                    <div class="category-title">
                                        <span>หน่วยงาน</span>
                                    </div>
                                    <div class="category-items">
                                        <div class="item item-group is-collapsed" data-faction-id="5">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-5" data-group-label="กลุ่มบริหารกิจการนักเรียน" data-members="[{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;}]" name="faction_ids[]" value="5">
                                                    <span class="item-title">กลุ่มบริหารกิจการนักเรียน</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางชมทิศา ขันภักดี" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820400215231">
                                                        <span class="member-name">นางชมทิศา ขันภักดี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางพวงทิพย์ ทวีรส" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3950300068146">
                                                        <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสาวชาลิสา จิตต์พันธ์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900172052">
                                                        <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสาวนิรัตน์ เพชรแก้ว" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820800038999">
                                                        <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสาวอรบุษย์ หนักแน่น" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900170670">
                                                        <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางสุนิษา  จินดาพล" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3601000301019">
                                                        <span class="member-name">นางสุนิษา จินดาพล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางเขมษิญากรณ์ อุดมคุณ" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820400309367">
                                                        <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นางเพ็ญแข หวานสนิท" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3930300329632">
                                                        <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายจรุง  บำรุงเขตต์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820400261097">
                                                        <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820700017680">
                                                        <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายณัฐพงษ์ สัจจารักษ์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1319800069611">
                                                        <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายธีรภัส  สฤษดิสุข" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1839900193629">
                                                        <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายธีระวัฒน์ เพชรขุ้ม" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1841500136302">
                                                        <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายพจนันท์  พรหมสงค์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900109890">
                                                        <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายรชต  ปานบุญ" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900012446">
                                                        <span class="member-name">นายรชต ปานบุญ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายวรานนท์ ภาระพฤติ" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3820100028745">
                                                        <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายศุภสวัสดิ์ กาญวิจิต" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900093446">
                                                        <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายอนุสรณ์ ชูทอง" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="3929900087867">
                                                        <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1901100006087">
                                                        <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-5" data-member-name="นายเอกพงษ์ สงวนทรัพย์" data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]" value="1829900072562">
                                                        <span class="member-name">นายเอกพงษ์ สงวนทรัพย์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed" data-faction-id="4">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-4" data-group-label="กลุ่มบริหารงานทั่วไป" data-members="[{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;}]" name="faction_ids[]" value="4">
                                                    <span class="item-title">กลุ่มบริหารงานทั่วไป</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 21 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางจิตติพร เกตุรักษ์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1820500007021">
                                                        <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางพูนสุข ถิ่นลิพอน" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3820100172170">
                                                        <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางภทรมน ลิ่มบุตร" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1809900084706">
                                                        <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางวาสนา  สุทธจิตร์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3820100025495">
                                                        <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวธารทิพย์ ภาระพฤติ" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3850100320012">
                                                        <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวนิรชา ธรรมัสโร" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1829900174284">
                                                        <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวปาณิสรา  มงคลบุตร" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3829900019706">
                                                        <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวพรทิพย์ สมบัติบุญ" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1920100023843">
                                                        <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวพรรณพนัช  คงผอม" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1101401730717">
                                                        <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวรัตนาพร พรประสิทธิ์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1820500148121">
                                                        <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวราศรี  อนันตมงคลกุล" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3820500121271">
                                                        <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวสรัลรัตน์ จันทับ" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1809901015490">
                                                        <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นางสาวสุดาทิพย์ ยกย่อง" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1820800031408">
                                                        <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายนพดล วงศ์สุวัฒน์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1860700158147">
                                                        <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายนพพร  ถิ่นไทย" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1860100007288">
                                                        <span class="member-name">นายนพพร ถิ่นไทย</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายนรินทร์เพชร นิลเวช" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1102003266698">
                                                        <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายวิศรุต ชามทอง" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1160100618291">
                                                        <span class="member-name">นายวิศรุต ชามทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายสหัส เสือยืนยง" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3810500157631">
                                                        <span class="member-name">นายสหัส เสือยืนยง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายอิสรพงศ์ สัตปานนท์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1829900162341">
                                                        <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="นายเพลิน โอรักษ์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="3180600191510">
                                                        <span class="member-name">นายเพลิน โอรักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-4" data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์" data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]" value="1809900094507">
                                                        <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์ ผสารพจน์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed" data-faction-id="3">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-3" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;}]" name="faction_ids[]" value="3">
                                                    <span class="item-title">กลุ่มบริหารงานบุคคลและงบประมาณ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 26 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางจริยาวดี  เวชจันทร์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="5800900028151">
                                                        <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางจิราภรณ์  เสรีรักษ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3800400522290">
                                                        <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3920100747937">
                                                        <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางปวีณา  บำรุงภักดิ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900007736">
                                                        <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางฝาติหม๊ะ ขนาดผล" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1930600099890">
                                                        <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900179103">
                                                        <span class="member-name">นางสาวกนกลักษณ์ พันธ์สวัสดิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวกานต์พิชชา ปากลาว" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1810500062871">
                                                        <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวธนวรรณ พิทักษ์คง" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820500147966">
                                                        <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวธิดารัตน์ ทองกอบ" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900119712">
                                                        <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวนฤมล บุญถาวร" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1920600250041">
                                                        <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวนัยน์เนตร ทองวล" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900118058">
                                                        <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวนิลญา หมานมิตร" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1910300050321">
                                                        <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวบงกชรัตน์  มาลี" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900051727">
                                                        <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวบุษรา  เมืองชู" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1840100431373">
                                                        <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวปริษา  แก้วเขียว" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1829900090897">
                                                        <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวปาณิสรา  มัจฉาเวช" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820400055491">
                                                        <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวปิยธิดา นิยมเดชา" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820600006469">
                                                        <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3820100171700">
                                                        <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวลภัสภาส์ หนูคง" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820500130320">
                                                        <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาววรินญา โรจธนะวรรธน์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1940100013597">
                                                        <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1840100326120">
                                                        <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1102001245405">
                                                        <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางสุมณฑา  เกิดทรัพย์" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="3820700050342">
                                                        <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นางอรชา ชูเชื้อ" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1820700004867">
                                                        <span class="member-name">นางอรชา ชูเชื้อ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นายสราวุธ กุหลาบวรรณ" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1800800331088">
                                                        <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-3" data-member-name="นายไชยวัฒน์ สังข์ทอง" data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ" name="person_ids[]" value="1640700056303">
                                                        <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed" data-faction-id="2">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-2" data-group-label="กลุ่มบริหารงานวิชาการ" data-members="[{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;}]" name="faction_ids[]" value="2">
                                                    <span class="item-title">กลุ่มบริหารงานวิชาการ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 45 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางกนกวรรณ  ณ นคร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3810100580006">
                                                        <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางจารุวรรณ ส่องศิริ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820100025592">
                                                        <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางณิภาภรณ์  ไชยชนะ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3930300511171">
                                                        <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางดวงกมล  เพ็ชรพรหม" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3840100521778">
                                                        <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางดาริน ทรายทอง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820300027670">
                                                        <span class="member-name">นางดาริน ทรายทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางธนิษฐา  ยงยุทธ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900063989">
                                                        <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางประภาพร  อุดมผลชัยเจริญ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3331001384867">
                                                        <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางผกาวรรณ  โชติวัฒนากร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1920600003469">
                                                        <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางพนิดา ค้าของ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3940400027034">
                                                        <span class="member-name">นางพนิดา ค้าของ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางพรพิมล แซ่เจี่ย" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1839900175043">
                                                        <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางพิมพา ทองอุไร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900003064">
                                                        <span class="member-name">นางพิมพา ทองอุไร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900054688">
                                                        <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900059485">
                                                        <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวจิราวัลย์  อินทร์อักษร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1930500083592">
                                                        <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวชนิกานต์  สวัสดิวงค์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3829900033725">
                                                        <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวณพสร สามสุวรรณ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3840200430855">
                                                        <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1729900457121">
                                                        <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวธนวรรณ สมัครการ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900202598">
                                                        <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวนุชรีย์ หัศนี" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1820700006258">
                                                        <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวประภัสสร  โอจันทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3840700282162">
                                                        <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวประภาพรรณ กุลแก้ว" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1410100117524">
                                                        <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวพิมพ์ประภา  ผลากิจ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900096909">
                                                        <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820400028481">
                                                        <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวรัชฎาพร สุวรรณสาม" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900012535">
                                                        <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวรัชนีกร ผอมจีน" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1820500097624">
                                                        <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820700136859">
                                                        <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวศศิธร นาคสง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3801600044431">
                                                        <span class="member-name">นางสาวศศิธร นาคสง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวศุลีพร ขันภักดี" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900099401">
                                                        <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900065485">
                                                        <span class="member-name">นางสาวองค์ปรางค์ แสงสุรินทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวอภิชญา จันทร์มา" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1909901558298">
                                                        <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวอาตีนา  พัชนี" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1800100218262">
                                                        <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสาวอินทิรา บุญนิสสัย" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1800800204043">
                                                        <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1930500116202">
                                                        <span class="member-name">นางสุรางค์รัศมิ์ ย้อยพระจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นางเสาวลีย์ จันทร์ทอง" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820700019381">
                                                        <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายจตุรวิทย์ มิตรวงศ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1859900070560">
                                                        <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1809901028575">
                                                        <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายธันวิน  ณ นคร" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1959900030702">
                                                        <span class="member-name">นายธันวิน ณ นคร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายนพรัตน์ ย้อยพระจันทร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1839900094990">
                                                        <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายบพิธ มังคะลา" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1819900163142">
                                                        <span class="member-name">นายบพิธ มังคะลา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายประสิทธิ์  สะไน" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1920400002230">
                                                        <span class="member-name">นายประสิทธิ์ สะไน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายพิพัฒน์ ไชยชนะ" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3940400221191">
                                                        <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายศรายุทธ  มิตรวงค์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820800037747">
                                                        <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="1829900056460">
                                                        <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายสมชาย สุทธจิตร์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820700143669">
                                                        <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-2" data-member-name="นายสุพัฒน์  เจริญฤทธิ์" data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]" value="3820400194578">
                                                        <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed" data-faction-id="6">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction" data-group-key="faction-6" data-group-label="กลุ่มสนับสนุนการสอน" data-members="[{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;}]" name="faction_ids[]" value="6">
                                                    <span class="item-title">กลุ่มสนับสนุนการสอน</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-6" data-member-name="นางสาวนัฐลิณี ทอสงค์" data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]" value="1820700059157">
                                                        <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-6" data-member-name="นางสาวอุบลวรรณ คงสม" data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]" value="1829900149409">
                                                        <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="faction-6" data-member-name="นายสิงหนาท  แต่งแก้ว" data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]" value="3810200084621">
                                                        <span class="member-name">นายสิงหนาท แต่งแก้ว</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>

                                <div class="category-group">
                                    <div class="category-title">
                                        <span>กลุ่มสาระ</span>
                                    </div>
                                    <div class="category-items">
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-9" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" data-members="[{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;}]" value="department-9">
                                                    <span class="item-title">กลุ่มกิจกรรมพัฒนาผู้เรียน</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-9" data-member-name="นางสาวนุชรีย์ หัศนี" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]" value="1820700006258">
                                                        <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-9" data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]" value="1102001245405">
                                                        <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-9" data-member-name="นายสิงหนาท  แต่งแก้ว" data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]" value="3810200084621">
                                                        <span class="member-name">นายสิงหนาท แต่งแก้ว</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-10" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" data-members="[{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;}]" value="department-10">
                                                    <span class="item-title">กลุ่มคอมพิวเตอร์และเทคโนโลยี</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นางสาวจิราวัลย์  อินทร์อักษร" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1930500083592">
                                                        <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นางสาวศศิธร นาคสง" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="3801600044431">
                                                        <span class="member-name">นางสาวศศิธร นาคสง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายจตุรวิทย์ มิตรวงศ์" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1859900070560">
                                                        <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายธันวิน  ณ นคร" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1959900030702">
                                                        <span class="member-name">นายธันวิน ณ นคร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายบพิธ มังคะลา" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="1819900163142">
                                                        <span class="member-name">นายบพิธ มังคะลา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-10" data-member-name="นายสหัส เสือยืนยง" data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]" value="3810500157631">
                                                        <span class="member-name">นายสหัส เสือยืนยง</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-11" data-group-label="กลุ่มธุรการ" data-members="[{&quot;pID&quot;:&quot;3820400234871&quot;,&quot;name&quot;:&quot;นางนวลน้อย  ชูสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1800700082485&quot;,&quot;name&quot;:&quot;นางสาว ณัฐชลียา ยิ่งคง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1829900082835&quot;,&quot;name&quot;:&quot;นางสาวจารุลักษณ์  ตรีศรี&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100155283&quot;,&quot;name&quot;:&quot;นางสาวจิราวรรณ ว่องปลูกศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;2800800033557&quot;,&quot;name&quot;:&quot;นางสาวธัญเรศ  วรศานต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820600035619&quot;,&quot;name&quot;:&quot;นางสาวนภัสสร  รัฐการ&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1810600075673&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร พันธ์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100140782&quot;,&quot;name&quot;:&quot;นางสาวศศิธร  มธุรส&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3810300076964&quot;,&quot;name&quot;:&quot;นายอดิศักดิ์  ธรรมจิตต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;}]" value="department-11">
                                                    <span class="item-title">กลุ่มธุรการ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางนวลน้อย  ชูสงค์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820400234871">
                                                        <span class="member-name">นางนวลน้อย ชูสงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาว ณัฐชลียา ยิ่งคง" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1800700082485">
                                                        <span class="member-name">นางสาว ณัฐชลียา ยิ่งคง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวจารุลักษณ์  ตรีศรี" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1829900082835">
                                                        <span class="member-name">นางสาวจารุลักษณ์ ตรีศรี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวจิราวรรณ ว่องปลูกศิลป์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820100155283">
                                                        <span class="member-name">นางสาวจิราวรรณ ว่องปลูกศิลป์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวธัญเรศ  วรศานต์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="2800800033557">
                                                        <span class="member-name">นางสาวธัญเรศ วรศานต์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวนภัสสร  รัฐการ" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820600035619">
                                                        <span class="member-name">นางสาวนภัสสร รัฐการ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวประภัสสร พันธ์แก้ว" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1810600075673">
                                                        <span class="member-name">นางสาวประภัสสร พันธ์แก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นางสาวศศิธร  มธุรส" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3820100140782">
                                                        <span class="member-name">นางสาวศศิธร มธุรส</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นายอดิศักดิ์  ธรรมจิตต์" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="3810300076964">
                                                        <span class="member-name">นายอดิศักดิ์ ธรรมจิตต์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-11" data-member-name="นายไชยวัฒน์ สังข์ทอง" data-group-label="กลุ่มธุรการ" name="person_ids[]" value="1640700056303">
                                                        <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-7" data-group-label="กลุ่มสาระฯ การงานอาชีพ" data-members="[{&quot;pID&quot;:&quot;1829900062591&quot;,&quot;name&quot;:&quot;นางสาวจารุวรรณ ผลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3810500179350&quot;,&quot;name&quot;:&quot;นางสาวนงลักษณ์   แก้วสว่าง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1849900176813&quot;,&quot;name&quot;:&quot;นายชนม์กมล เพ็ขรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;}]" value="department-7">
                                                    <span class="item-title">กลุ่มสาระฯ การงานอาชีพ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 9 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางสาวจารุวรรณ ผลแก้ว" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1829900062591">
                                                        <span class="member-name">นางสาวจารุวรรณ ผลแก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางสาวนงลักษณ์   แก้วสว่าง" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="3810500179350">
                                                        <span class="member-name">นางสาวนงลักษณ์ แก้วสว่าง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นายชนม์กมล เพ็ขรพรหม" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1849900176813">
                                                        <span class="member-name">นายชนม์กมล เพ็ขรพรหม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางพิมพา ทองอุไร" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1829900003064">
                                                        <span class="member-name">นางพิมพา ทองอุไร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางจริยาวดี  เวชจันทร์" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="5800900028151">
                                                        <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางจิราภรณ์  เสรีรักษ์" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="3800400522290">
                                                        <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางพูนสุข ถิ่นลิพอน" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="3820100172170">
                                                        <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นางภทรมน ลิ่มบุตร" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1809900084706">
                                                        <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-7" data-member-name="นายนพพร  ถิ่นไทย" data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]" value="1860100007288">
                                                        <span class="member-name">นายนพพร ถิ่นไทย</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-2" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" data-members="[{&quot;pID&quot;:&quot;1829900206275&quot;,&quot;name&quot;:&quot;นายภูมิวิชญ์ จีนนาพัฒ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;}]" value="department-2">
                                                    <span class="item-title">กลุ่มสาระฯ คณิตศาสตร์</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายภูมิวิชญ์ จีนนาพัฒ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900206275">
                                                        <span class="member-name">นายภูมิวิชญ์ จีนนาพัฒ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางกนกวรรณ  ณ นคร" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3810100580006">
                                                        <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางประภาพร  อุดมผลชัยเจริญ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3331001384867">
                                                        <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางผกาวรรณ  โชติวัฒนากร" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1920600003469">
                                                        <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางพรพิมล แซ่เจี่ย" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1839900175043">
                                                        <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวพิมพ์ประภา  ผลากิจ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900096909">
                                                        <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวรัชนีกร ผอมจีน" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1820500097624">
                                                        <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวอภิชญา จันทร์มา" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1909901558298">
                                                        <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวอินทิรา บุญนิสสัย" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1800800204043">
                                                        <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางเสาวลีย์ จันทร์ทอง" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3820700019381">
                                                        <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1809901028575">
                                                        <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายพิพัฒน์ ไชยชนะ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3940400221191">
                                                        <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางฝาติหม๊ะ ขนาดผล" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1930600099890">
                                                        <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวธิดารัตน์ ทองกอบ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900119712">
                                                        <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวบงกชรัตน์  มาลี" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1829900051727">
                                                        <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายสราวุธ กุหลาบวรรณ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1800800331088">
                                                        <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวพรทิพย์ สมบัติบุญ" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1920100023843">
                                                        <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวรัตนาพร พรประสิทธิ์" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1820500148121">
                                                        <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นายอนุสรณ์ ชูทอง" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="3929900087867">
                                                        <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-2" data-member-name="นางสาวนัฐลิณี ทอสงค์" data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]" value="1820700059157">
                                                        <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-8" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" data-members="[{&quot;pID&quot;:&quot;1820800093039&quot;,&quot;name&quot;:&quot;นางสาวปาริชาต เดชอาษา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1809900831358&quot;,&quot;name&quot;:&quot;นางสาวพลอยไพลิน เที่ยวแสวง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;}]" value="department-8">
                                                    <span class="item-title">กลุ่มสาระฯ ภาษาต่างประเทศ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวปาริชาต เดชอาษา" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1820800093039">
                                                        <span class="member-name">นางสาวปาริชาต เดชอาษา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวพลอยไพลิน เที่ยวแสวง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1809900831358">
                                                        <span class="member-name">นางสาวพลอยไพลิน เที่ยวแสวง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางดาริน ทรายทอง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3820300027670">
                                                        <span class="member-name">นางดาริน ทรายทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางพนิดา ค้าของ" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3940400027034">
                                                        <span class="member-name">นางพนิดา ค้าของ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900054688">
                                                        <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900059485">
                                                        <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1729900457121">
                                                        <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวธนวรรณ สมัครการ" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900202598">
                                                        <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900065485">
                                                        <span class="member-name">นางสาวองค์ปรางค์ แสงสุรินทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1930500116202">
                                                        <span class="member-name">นางสุรางค์รัศมิ์ ย้อยพระจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวกานต์พิชชา ปากลาว" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1810500062871">
                                                        <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวนิลญา หมานมิตร" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1910300050321">
                                                        <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาวปริษา  แก้วเขียว" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900090897">
                                                        <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางสาววรินญา โรจธนะวรรธน์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1940100013597">
                                                        <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นายอิสรพงศ์ สัตปานนท์" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1829900162341">
                                                        <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางพวงทิพย์ ทวีรส" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3950300068146">
                                                        <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางเขมษิญากรณ์ อุดมคุณ" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3820400309367">
                                                        <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นางเพ็ญแข หวานสนิท" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3930300329632">
                                                        <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="3820700017680">
                                                        <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-8" data-member-name="นายธีระวัฒน์ เพชรขุ้ม" data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]" value="1841500136302">
                                                        <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-1" data-group-label="กลุ่มสาระฯ ภาษาไทย" data-members="[{&quot;pID&quot;:&quot;1829900103735&quot;,&quot;name&quot;:&quot;นางสาวจันทนี บุญนำ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900141980&quot;,&quot;name&quot;:&quot;นางสาวสุกานดา ปานมั่งคั่ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;}]" value="department-1">
                                                    <span class="item-title">กลุ่มสาระฯ ภาษาไทย</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 14 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวจันทนี บุญนำ" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900103735">
                                                        <span class="member-name">นางสาวจันทนี บุญนำ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวสุกานดา ปานมั่งคั่ง" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900141980">
                                                        <span class="member-name">นางสาวสุกานดา ปานมั่งคั่ง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางดวงกมล  เพ็ชรพรหม" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3840100521778">
                                                        <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวณพสร สามสุวรรณ" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3840200430855">
                                                        <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3820400028481">
                                                        <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3820700136859">
                                                        <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวนัยน์เนตร ทองวล" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900118058">
                                                        <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวบุษรา  เมืองชู" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1840100431373">
                                                        <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางจิตติพร เกตุรักษ์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1820500007021">
                                                        <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวพรรณพนัช  คงผอม" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1101401730717">
                                                        <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นางสาวราศรี  อนันตมงคลกุล" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="3820500121271">
                                                        <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นายนพดล วงศ์สุวัฒน์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1860700158147">
                                                        <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นายนรินทร์เพชร นิลเวช" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1102003266698">
                                                        <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-1" data-member-name="นายพจนันท์  พรหมสงค์" data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]" value="1829900109890">
                                                        <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-3" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" data-members="[{&quot;pID&quot;:&quot;1819300006267&quot;,&quot;name&quot;:&quot;นายคุณากร ประดับศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400295111&quot;,&quot;name&quot;:&quot;นายนิมิตร สุสิมานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;}]" value="department-3">
                                                    <span class="item-title">กลุ่มสาระฯ วิทยาศาสตร์</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 24 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายคุณากร ประดับศิลป์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1819300006267">
                                                        <span class="member-name">นายคุณากร ประดับศิลป์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายนิมิตร สุสิมานนท์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820400295111">
                                                        <span class="member-name">นายนิมิตร สุสิมานนท์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางณิภาภรณ์  ไชยชนะ" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3930300511171">
                                                        <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางธนิษฐา  ยงยุทธ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900063989">
                                                        <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวรัชฎาพร สุวรรณสาม" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900012535">
                                                        <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวศุลีพร ขันภักดี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900099401">
                                                        <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวอาตีนา  พัชนี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1800100218262">
                                                        <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายนพรัตน์ ย้อยพระจันทร์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1839900094990">
                                                        <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3920100747937">
                                                        <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900179103">
                                                        <span class="member-name">นางสาวกนกลักษณ์ พันธ์สวัสดิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวนฤมล บุญถาวร" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1920600250041">
                                                        <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวปาณิสรา  มัจฉาเวช" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1820400055491">
                                                        <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820100171700">
                                                        <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1840100326120">
                                                        <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสุมณฑา  เกิดทรัพย์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820700050342">
                                                        <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางอรชา ชูเชื้อ" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1820700004867">
                                                        <span class="member-name">นางอรชา ชูเชื้อ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางชมทิศา ขันภักดี" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3820400215231">
                                                        <span class="member-name">นางชมทิศา ขันภักดี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวชาลิสา จิตต์พันธ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900172052">
                                                        <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวอรบุษย์ หนักแน่น" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900170670">
                                                        <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสุนิษา  จินดาพล" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="3601000301019">
                                                        <span class="member-name">นางสุนิษา จินดาพล</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายณัฐพงษ์ สัจจารักษ์" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1319800069611">
                                                        <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายธีรภัส  สฤษดิสุข" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1839900193629">
                                                        <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นายรชต  ปานบุญ" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900012446">
                                                        <span class="member-name">นายรชต ปานบุญ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-3" data-member-name="นางสาวอุบลวรรณ คงสม" data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]" value="1829900149409">
                                                        <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-6" data-group-label="กลุ่มสาระฯ ศิลปะ" data-members="[{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;}]" value="department-6">
                                                    <span class="item-title">กลุ่มสาระฯ ศิลปะ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 7 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นางสาวประภัสสร  โอจันทร์" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3840700282162">
                                                        <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="1829900056460">
                                                        <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายสุพัฒน์  เจริญฤทธิ์" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3820400194578">
                                                        <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นางสาวธารทิพย์ ภาระพฤติ" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3850100320012">
                                                        <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นางสาวปาณิสรา  มงคลบุตร" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="3829900019706">
                                                        <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายวิศรุต ชามทอง" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="1160100618291">
                                                        <span class="member-name">นายวิศรุต ชามทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-6" data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต" data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]" value="1901100006087">
                                                        <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-4" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" data-members="[{&quot;pID&quot;:&quot;1830101156953&quot;,&quot;name&quot;:&quot;นางสาวนัสรีน สุวิสัน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1810300103434&quot;,&quot;name&quot;:&quot;นางสาวปณิดา คลองรั้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820501214179&quot;,&quot;name&quot;:&quot;นายมงคล ตันเจริญรัตน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;}]" value="department-4">
                                                    <span class="item-title">กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 18 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวนัสรีน สุวิสัน" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1830101156953">
                                                        <span class="member-name">นางสาวนัสรีน สุวิสัน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวปณิดา คลองรั้ว" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1810300103434">
                                                        <span class="member-name">นางสาวปณิดา คลองรั้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายมงคล ตันเจริญรัตน์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820501214179">
                                                        <span class="member-name">นายมงคล ตันเจริญรัตน์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางจารุวรรณ ส่องศิริ" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3820100025592">
                                                        <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวชนิกานต์  สวัสดิวงค์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3829900033725">
                                                        <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวประภาพรรณ กุลแก้ว" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1410100117524">
                                                        <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางปวีณา  บำรุงภักดิ์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1829900007736">
                                                        <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวธนวรรณ พิทักษ์คง" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820500147966">
                                                        <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวปิยธิดา นิยมเดชา" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820600006469">
                                                        <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวลภัสภาส์ หนูคง" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820500130320">
                                                        <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางวาสนา  สุทธจิตร์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3820100025495">
                                                        <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวนิรชา ธรรมัสโร" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1829900174284">
                                                        <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวสรัลรัตน์ จันทับ" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1809901015490">
                                                        <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นางสาวสุดาทิพย์ ยกย่อง" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1820800031408">
                                                        <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายเพลิน โอรักษ์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3180600191510">
                                                        <span class="member-name">นายเพลิน โอรักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1809900094507">
                                                        <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์ ผสารพจน์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายวรานนท์ ภาระพฤติ" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="3820100028745">
                                                        <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-4" data-member-name="นายศุภสวัสดิ์ กาญวิจิต" data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม" name="person_ids[]" value="1829900093446">
                                                        <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department" data-group-key="department-5" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" data-members="[{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;}]" value="department-5">
                                                    <span class="item-title">กลุ่มสาระฯ สุขศึกษาและพลศึกษา</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายประสิทธิ์  สะไน" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="1920400002230">
                                                        <span class="member-name">นายประสิทธิ์ สะไน</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายศรายุทธ  มิตรวงค์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820800037747">
                                                        <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายสมชาย สุทธจิตร์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820700143669">
                                                        <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นางสาวนิรัตน์ เพชรแก้ว" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820800038999">
                                                        <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายจรุง  บำรุงเขตต์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="3820400261097">
                                                        <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="department-5" data-member-name="นายเอกพงษ์ สงวนทรัพย์" data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]" value="1829900072562">
                                                        <span class="member-name">นายเอกพงษ์ สงวนทรัพย์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>

                                <div class="category-group">
                                    <div class="category-title">
                                        <span>อื่นๆ</span>
                                    </div>
                                    <div class="category-items">
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox" data-group="special" data-group-key="special-executive" data-group-label="คณะผู้บริหารสถานศึกษา" data-members="[{&quot;pID&quot;:&quot;1820500005169&quot;,&quot;name&quot;:&quot;นางสาวศริญญา  ผั้วผดุง&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3810500334835&quot;,&quot;name&quot;:&quot;นายดลยวัฒน์ สันติพิทักษ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;1820500004103&quot;,&quot;name&quot;:&quot;นายยุทธนา สุวรรณวิสุทธิ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3430200354125&quot;,&quot;name&quot;:&quot;นายไกรวิชญ์ อ่อนแก้ว&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;}]" value="special-executive">
                                                    <span class="item-title">คณะผู้บริหารสถานศึกษา</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 4 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นางสาวศริญญา  ผั้วผดุง" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="1820500005169">
                                                        <span class="member-name">นางสาวศริญญา ผั้วผดุง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นายดลยวัฒน์ สันติพิทักษ์" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="3810500334835">
                                                        <span class="member-name">นายดลยวัฒน์ สันติพิทักษ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นายยุทธนา สุวรรณวิสุทธิ์" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="1820500004103">
                                                        <span class="member-name">นายยุทธนา สุวรรณวิสุทธิ์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-executive" data-member-name="นายไกรวิชญ์ อ่อนแก้ว" data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]" value="3430200354125">
                                                        <span class="member-name">นายไกรวิชญ์ อ่อนแก้ว</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                        <div class="item item-group is-collapsed">
                                            <div class="group-header">
                                                <label class="item-main">
                                                    <input type="checkbox" class="item-checkbox group-item-checkbox" data-group="special" data-group-key="special-subject-head" data-group-label="หัวหน้ากลุ่มสาระ" data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;}]" value="special-subject-head">
                                                    <span class="item-title">หัวหน้ากลุ่มสาระ</span>
                                                    <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                                </label>
                                                <button type="button" class="group-toggle" aria-expanded="false" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>

                                            <ol class="member-sublist">
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางจริยาวดี  เวชจันทร์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="5800900028151">
                                                        <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางดวงกมล  เพ็ชรพรหม" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3840100521778">
                                                        <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางพนิดา ค้าของ" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3940400027034">
                                                        <span class="member-name">นางพนิดา ค้าของ</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางสาวนุชรีย์ หัศนี" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1820700006258">
                                                        <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางสาวสุดาทิพย์ ยกย่อง" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1820800031408">
                                                        <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นางเสาวลีย์ จันทร์ทอง" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3820700019381">
                                                        <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายธันวิน  ณ นคร" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1959900030702">
                                                        <span class="member-name">นายธันวิน ณ นคร</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายนพรัตน์ ย้อยพระจันทร์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="1839900094990">
                                                        <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายสมชาย สุทธจิตร์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3820700143669">
                                                        <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                                    </label>
                                                </li>
                                                <li>
                                                    <label class="item member-item">
                                                        <input type="checkbox" class="member-checkbox" data-member-group-key="special-subject-head" data-member-name="นายสุพัฒน์  เจริญฤทธิ์" data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]" value="3820400194578">
                                                        <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                                    </label>
                                                </li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="sent-notice-selected">
                        <button id="btnShowRecipients" type="button">
                            <p>แสดงผู้รับทั้งหมด</p>
                        </button>
                    </div>
                </div>

                <div id="recipientModal" class="modal-overlay-recipient">
                    <div class="modal-container">
                        <div class="modal-header">
                            <div class="modal-title">
                                <i class="fa-solid fa-users"></i>
                                <span>รายชื่อผู้รับหนังสือเวียน</span>
                            </div>
                            <button class="modal-close" id="closeModalBtn" type="button">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <table class="recipient-table">
                                <thead>
                                    <tr>
                                        <th>ลำดับ</th>
                                        <th>ชื่อจริง-นามสกุล</th>
                                        <th>กลุ่ม/ฝ่าย</th>
                                    </tr>
                                </thead>
                                <tbody id="recipientTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>แนบลิ้งก์</strong></p>
                        <input
                            type="text"
                            id="modalOutgoingEditLink"
                            name="linkURL"
                            class="order-no-display"
                            value="" placeholder="แนบลิ้งก์ที่เกี่ยวข้อง (ถ้ามี)">
                    </div>
                </div>

                <div class="form-group row label">
                    <div class="input-group">
                        <p><strong>อัปโหลดไฟล์หนังสือนำ</strong></p>
                    </div>
                </div>

                <div class="form-group row">
                    <section class="upload-layout">
                        <input
                            type="file"
                            id="coverFileInput_modal"
                            name="cover_file"
                            accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                            style="display: none;">

                        <div class="row form-group">
                            <button class="btn btn-upload-small" type="button" id="btnCoverAddFile_modal">
                                <p>เพิ่มไฟล์</p>
                            </button>
                            <div class="file-hint">
                                <p>* แนบไฟล์หนังสือนำได้ 1 ไฟล์ *</p>
                            </div>
                        </div>

                        <div class="existing-file-section">
                            <div class="file-list" id="coverFileListContainer_modal"></div>
                        </div>
                    </section>
                </div>

                <div class="form-group row">
                    <div class="input-group">
                        <p><strong>อัปโหลดไฟล์เอกสาร</strong></p>
                        <section class="upload-layout">
                            <input type="file" id="fileInput_modal" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" style="display: none;" />

                            <div class="upload-box" id="dropzone_modal">
                                <i class="fa-solid fa-upload"></i>
                                <p>ลากไฟล์มาวางที่นี่</p>
                            </div>

                            <div class="file-list" id="fileListContainer_modal"></div>
                        </section>
                    </div>
                </div>

                <div class="form-group row">
                    <button class="btn btn-upload-small" type="button" id="btnAddFiles_modal">
                        <p>เพิ่มไฟล์</p>
                    </button>
                    <div class="file-hint">
                        <p>* แนบไฟล์ได้สูงสุด 5 ไฟล์ (รวม PNG และ PDF) ถ้ามี *</p>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ผู้รับหนังสือ</strong></p>
                        <input
                            type="text"
                            class="order-no-display"
                            value="<?= h($current_user_name !== '' ? $current_user_name : '-') ?>"
                            disabled>
                    </div>
                </div>

            </div>

            <div class="footer-modal">
                <form method="POST" id="modalOutgoingAttachForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="attach">
                    <input type="hidden" name="outgoing_id" id="modalOutgoingEditOutgoingId" value="">
                    <button type="submit" id="modalOrderEditSaveBtn">
                        <p>บันทึก</p>
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalOrderViewOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p id="modalOutgoingViewTitle">ดูรายละเอียดลงทะเบียนรับหนังสือ</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="modalOrderViewCloseBtn"></i>
                </div>
            </div>

            <div class="content-modal">
                <div class="type-urgent">
                    <p>ประเภท</p>
                    <div class="radio-group-urgent">
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="normal" checked disabled id="modalOutgoingViewUrgentNormal"><label for="modalOutgoingViewUrgentNormal">ปกติ</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="urgent" disabled id="modalOutgoingViewUrgentUrgent"><label for="modalOutgoingViewUrgentUrgent">ด่วน</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="high" disabled id="modalOutgoingViewUrgentHigh"><label for="modalOutgoingViewUrgentHigh">ด่วนมาก</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="highest" disabled id="modalOutgoingViewUrgentHighest"><label for="modalOutgoingViewUrgentHighest">ด่วนที่สุด</label>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เลขที่หนังสือ</strong></p>
                        <input type="text" id="modalOutgoingViewBookNo" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>ลงวันที่</strong></p>
                        <input type="date" id="modalOutgoingViewIssuedDate" class="order-no-display" value="" disabled>
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เรื่อง</strong></p>
                        <input type="text" id="modalOutgoingViewSubjectText" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>จาก</strong></p>
                        <input type="text" id="modalOutgoingViewFrom" class="order-no-display" value="-" disabled>
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>กลุ่ม</strong></p>
                        <input type="text" id="modalOutgoingViewGroup" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เกษียณหนังสือ</strong></p>
                        <textarea id="memo_editor_view"></textarea>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>แนบลิ้งก์</strong></p>
                        <input type="text" id="modalOutgoingViewLink" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="vehicle-row file-sec" id="modalOutgoingViewCoverSection" style="display: none;">
                    <div class="vehicle-input-content">
                        <p><strong>ไฟล์หนังสือนำ</strong></p>
                    </div>

                    <div class="file-list" id="modalOutgoingViewCoverList" aria-live="polite"></div>
                </div>

                <div class="vehicle-row file-sec" id="modalOutgoingViewAttachmentSection" style="display: none;">
                    <div class="vehicle-input-content">
                        <p><strong>ไฟล์เอกสาร</strong></p>
                    </div>

                    <div class="file-list" id="modalOutgoingViewAttachmentList" aria-live="polite"></div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ผู้รับหนังสือ</strong></p>
                        <input type="text" id="modalOutgoingViewProposer" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                    </div>
                </div>

                <div class="content-read-sec">
                    <p><strong>สถานะการอ่านรายบุคคล</strong></p>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>ชื่อผู้รับ</th>
                                    <th>สถานะ</th>
                                    <th>เวลาอ่านล่าสุด</th>
                                </tr>
                            </thead>
                            <tbody id="trackReceiptStatusTableBody">
                                <tr>
                                    <td colspan="3" class="booking-empty">ไม่พบข้อมูลสถานะการอ่านรายบุคคล</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="footer-modal">
                <!-- <button type="button" id="modalOrderViewCloseBtn">
                    <p>ปิดหน้าต่าง</p>
                </button> -->
            </div>

        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#memo_editor_compose, #memo_editor_suggest',
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

    tinymce.init({
        selector: '#memo_editor_view',
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
        branding: false,
        readonly: true
    });
    
    window.addEventListener('load', function() {
      var iframe = document.getElementById('memo_editor_compose_ifr'); 
      
      if (iframe) {
        var doc = iframe.contentDocument || iframe.contentWindow.document;
        var style = doc.createElement('style');
        doc.head.appendChild(style);
        
        var mediaDesktop = window.matchMedia('(min-width: 1024px)');
        var mediaTablet = window.matchMedia('(min-width: 768px)');
    
        function ดึงขนาดหน้าจอหลัก() {
          var fontSize = '8px';
    
          if (mediaDesktop.matches) {
            fontSize = '16px';
          } else if (mediaTablet.matches) {
            fontSize = '12px';
          }
    
          style.innerHTML = 'body#tinymce p { font-size: ' + fontSize + ' !important; }';
        }
    
        ดึงขนาดหน้าจอหลัก();
    
        if (mediaDesktop.addEventListener) {
          mediaDesktop.addEventListener('change', ดึงขนาดหน้าจอหลัก);
          mediaTablet.addEventListener('change', ดึงขนาดหน้าจอหลัก);
        } else {
          mediaDesktop.addListener(ดึงขนาดหน้าจอหลัก);
          mediaTablet.addListener(ดึงขนาดหน้าจอหลัก);
        }
      }
    });
    
    window.addEventListener('load', function() {
      var iframe = document.getElementById('memo_editor_view_ifr'); 
      
      if (iframe) {
        var doc = iframe.contentDocument || iframe.contentWindow.document;
        var style = doc.createElement('style');
        doc.head.appendChild(style);
        
        var mediaDesktop = window.matchMedia('(min-width: 1024px)');
        var mediaTablet = window.matchMedia('(min-width: 768px)');
    
        function ดึงขนาดหน้าจอหลัก() {
          var fontSize = '8px';
    
          if (mediaDesktop.matches) {
            fontSize = '16px';
          } else if (mediaTablet.matches) {
            fontSize = '12px';
          }
    
          style.innerHTML = 'body#tinymce p { font-size: ' + fontSize + ' !important; }';
        }
    
        ดึงขนาดหน้าจอหลัก();
    
        if (mediaDesktop.addEventListener) {
          mediaDesktop.addEventListener('change', ดึงขนาดหน้าจอหลัก);
          mediaTablet.addEventListener('change', ดึงขนาดหน้าจอหลัก);
        } else {
          mediaDesktop.addListener(ดึงขนาดหน้าจอหลัก);
          mediaTablet.addListener(ดึงขนาดหน้าจอหลัก);
        }
      }
    });
    

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

        const mainForm = document.getElementById('outgointForm');
        if (mainForm) {
            setupRecipientDropdown(mainForm);
        }

        const editModalContainer = document.getElementById('modalOrderEditOverlay');
        if (editModalContainer) {
            setupRecipientDropdown(editModalContainer);
        }

        function setupFileUpload(inputId, listId, maxFiles = 1, options = {}) {
            const fileInput = document.getElementById(inputId);
            const fileList = document.getElementById(listId);
            const dropzone = options.dropzoneId ? document.getElementById(options.dropzoneId) : null;
            const addFilesBtn = options.addButtonId ? document.getElementById(options.addButtonId) : null;
            const form = fileInput ? fileInput.closest('form') : null;

            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
            const getFileExtension = (file) => String(file?.name || '').split('.').pop().toLowerCase();
            const isAllowedFile = (file) => allowedTypes.includes(file.type) || allowedExtensions.includes(getFileExtension(file));
            let selectedFiles = [];
            let existingFiles = [];
            let existingEntityId = '';
            let removedExistingFileIds = [];

            let removedFilesContainer = form ? form.querySelector('[data-remove-file-inputs]') : null;
            if (form && !removedFilesContainer) {
                removedFilesContainer = document.createElement('div');
                removedFilesContainer.setAttribute('data-remove-file-inputs', 'true');
                removedFilesContainer.style.display = 'none';
                form.appendChild(removedFilesContainer);
            }

            if (!fileInput) return null;

            const syncRemovedFileInputs = () => {
                if (!removedFilesContainer) return;
                removedFilesContainer.innerHTML = '';
                removedExistingFileIds.forEach((fileId) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'remove_file_ids[]';
                    input.value = String(fileId);
                    removedFilesContainer.appendChild(input);
                });
            };

            const buildFileIconMarkup = (mimeType) => {
                const normalizedMime = String(mimeType || '').toLowerCase();
                return normalizedMime.includes('pdf') ? '<i class="fa-solid fa-file-pdf"></i>' : '<i class="fa-solid fa-file-image"></i>';
            };

            const buildExistingFileUrl = (file) => {
                const fileId = String(file?.fileID || '').trim();
                if (existingEntityId === '' || fileId === '') {
                    return '';
                }
                return `/public/api/file-download.php?module=circulars&entity_id=${encodeURIComponent(existingEntityId)}&file_id=${encodeURIComponent(fileId)}`;
            };

            const renderFiles = () => {
                if (!fileList) return;
                fileList.innerHTML = '';

                existingFiles.forEach((file) => {
                    const fileId = String(file?.fileID || '').trim();
                    const fileUrl = buildExistingFileUrl(file);
                    const wrapper = document.createElement('div');
                    wrapper.className = 'file-item-wrapper';

                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'delete-btn';
                    deleteBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
                    deleteBtn.addEventListener('click', () => {
                        if (fileId !== '' && !removedExistingFileIds.includes(fileId)) {
                            removedExistingFileIds.push(fileId);
                        }
                        existingFiles = existingFiles.filter((existingFile) => String(existingFile?.fileID || '').trim() !== fileId);
                        syncRemovedFileInputs();
                        renderFiles();
                    });

                    const banner = document.createElement('div');
                    banner.className = 'file-banner';

                    const info = document.createElement('div');
                    info.className = 'file-info';

                    const icon = document.createElement('div');
                    icon.className = 'file-icon';
                    icon.innerHTML = buildFileIconMarkup(file?.mimeType);

                    const text = document.createElement('div');
                    text.className = 'file-text';
                    text.innerHTML = `<div class="file-name">${String(file?.fileName || '-')}</div><div class="file-type">${String(file?.mimeType || 'ไฟล์แนบ')}</div>`;

                    info.appendChild(icon);
                    info.appendChild(text);
                    banner.appendChild(info);

                    if (fileUrl !== '') {
                        const actions = document.createElement('div');
                        actions.className = 'file-actions';

                        const view = document.createElement('a');
                        view.href = fileUrl;
                        view.target = '_blank';
                        view.rel = 'noopener';
                        view.innerHTML = '<i class="fa-solid fa-eye"></i>';
                        actions.appendChild(view);
                        banner.appendChild(actions);
                    }

                    wrapper.appendChild(deleteBtn);
                    wrapper.appendChild(banner);
                    fileList.appendChild(wrapper);
                });

                selectedFiles.forEach((file, index) => {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'file-item-wrapper new-file-item';

                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'delete-btn';
                    deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
                    deleteBtn.addEventListener('click', () => {
                        selectedFiles = selectedFiles.filter((_, i) => i !== index);
                        syncFiles();
                        renderFiles();
                    });

                    const banner = document.createElement('div');
                    banner.className = 'file-banner';

                    const info = document.createElement('div');
                    info.className = 'file-info';

                    const icon = document.createElement('div');
                    icon.className = 'file-icon';
                    icon.innerHTML = buildFileIconMarkup(file.type);

                    const text = document.createElement('div');
                    text.className = 'file-text';
                    text.innerHTML = `<div class="file-name">${file.name}</div><div class="file-type">${file.type || 'ไฟล์แนบ'}</div>`;

                    info.appendChild(icon);
                    info.appendChild(text);

                    const actions = document.createElement('div');
                    actions.className = 'file-actions';

                    const fileUrl = URL.createObjectURL(file);
                    const view = document.createElement('a');
                    view.href = fileUrl;
                    view.target = '_blank';
                    view.rel = 'noopener';
                    view.innerHTML = '<i class="fa-solid fa-eye"></i>';

                    view.addEventListener('click', () => {
                        setTimeout(() => URL.revokeObjectURL(fileUrl), 100);
                    });

                    actions.appendChild(view);
                    banner.appendChild(info);
                    banner.appendChild(actions);
                    wrapper.appendChild(deleteBtn);
                    wrapper.appendChild(banner);
                    fileList.appendChild(wrapper);
                });
            };

            const syncFiles = () => {
                const dt = new DataTransfer();
                selectedFiles.forEach((file) => dt.items.add(file));
                fileInput.files = dt.files;
            };

            const addFiles = (files) => {
                if (!files || files.length === 0) return;

                if (maxFiles === 1) {
                    const file = files[0];
                    if (isAllowedFile(file)) {
                        selectedFiles = [file];
                    } else {
                        alert('ประเภทไฟล์ไม่ได้รับอนุญาต');
                    }
                } else {
                    const existing = new Set(selectedFiles.map((f) => `${f.name}-${f.size}-${f.lastModified}`));
                    let currentTotal = existingFiles.length + selectedFiles.length;

                    Array.from(files).forEach((file) => {
                        const key = `${file.name}-${file.size}-${file.lastModified}`;
                        if (!existing.has(key) && isAllowedFile(file) && currentTotal < maxFiles) {
                            selectedFiles.push(file);
                            existing.add(key);
                            currentTotal++;
                        } else if (!isAllowedFile(file)) {
                            console.warn('ประเภทไฟล์ไม่ได้รับอนุญาต:', file.name);
                        } else if (currentTotal >= maxFiles) {
                            console.warn('เกินจำนวนไฟล์สูงสุดแล้ว');
                        }
                    });
                }
                syncFiles();
                renderFiles();
            };

            const setExistingFiles = (files, entityId) => {
                existingFiles = Array.isArray(files) ? files : [];
                existingEntityId = String(entityId || '').trim();
                selectedFiles = [];
                removedExistingFileIds = [];
                syncRemovedFileInputs();
                syncFiles();
                renderFiles();
            };

            fileInput.addEventListener('change', (e) => {
                addFiles(e.target.files);
            });

            if (dropzone) {
                dropzone.addEventListener('click', () => fileInput.click());
                dropzone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropzone.classList.add('active');
                });
                dropzone.addEventListener('dragleave', () => dropzone.classList.remove('active'));
                dropzone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dropzone.classList.remove('active');
                    addFiles(e.dataTransfer?.files || []);
                });
            }

            if (addFilesBtn) {
                addFilesBtn.addEventListener('click', () => fileInput.click());
            }

            renderFiles();

            return {
                setExistingFiles,
                reset: () => {
                    selectedFiles = [];
                    existingFiles = [];
                    removedExistingFileIds = [];
                    syncRemovedFileInputs();
                    syncFiles();
                    renderFiles();
                }
            };
        }

        window.__outgoingMainCoverUpload = setupFileUpload(
            'coverFileInput',
            'coverFileListContainer',
            1, {
                addButtonId: 'btnCoverAddFile'
            }
        );

        window.__outgoingMainAttachmentUpload = setupFileUpload(
            'fileInput',
            'fileListContainer',
            5, {
                dropzoneId: 'dropzone',
                addButtonId: 'btnAddFiles'
            }
        );

        window.__outgoingModalCoverUpload = setupFileUpload(
            'coverFileInput_modal',
            'coverFileListContainer_modal',
            1, {
                addButtonId: 'btnCoverAddFile_modal'
            }
        );

        window.__outgoingModalAttachmentUpload = setupFileUpload(
            'fileInput_modal',
            'fileListContainer_modal',
            5, {
                dropzoneId: 'dropzone_modal',
                addButtonId: 'btnAddFiles_modal'
            }
        );

        const modalForm = document.getElementById('modalOutgoingAttachForm');
        if (modalForm) {
            modalForm.__circularFormApi = {
                ...(modalForm.__circularFormApi || {}),
                setExistingFiles: window.__outgoingModalAttachmentUpload?.setExistingFiles,
                resetFiles: window.__outgoingModalAttachmentUpload?.reset
            };
        }

        const sendMapElement = document.querySelector('.js-order-send-map');
        let outgoingViewPayloadMap = {};
        if (sendMapElement) {
            try {
                outgoingViewPayloadMap = JSON.parse(sendMapElement.textContent || '{}') || {};
            } catch (error) {
                console.error('Failed to parse outgoing receive modal payload map', error);
                outgoingViewPayloadMap = {};
            }
        }

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const formatThaiDateTime = (value) => {
            const raw = String(value || '').trim();
            if (raw === '') {
                return '-';
            }

            const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
            const parsed = new Date(normalized);

            if (Number.isNaN(parsed.getTime())) {
                return raw;
            }

            const datePart = parsed.toLocaleDateString('th-TH', {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            });
            const timePart = parsed.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit'
            });

            return `${datePart} ${timePart} น.`;
        };

        const formatFileSize = (bytes) => {
            const size = Number(bytes || 0);
            if (!Number.isFinite(size) || size <= 0) {
                return '0 KB';
            }
            if (size >= 1024 * 1024) {
                return `${(size / (1024 * 1024)).toFixed(1)} MB`;
            }
            return `${Math.max(1, Math.round(size / 1024))} KB`;
        };

        const outgoingPayloadLink = (payload) => String(payload?.linkURL || payload?.linkUrl || payload?.link || '').trim();

        const setTextInputValue = (input, value) => {
            if (!input) {
                return;
            }

            const displayValue = String(value || '').trim() || '-';
            input.value = displayValue;
            input.setAttribute('title', displayValue);
        };

        const viewModal = document.getElementById('modalOrderViewOverlay');
        const modalOutgoingViewBookNo = document.getElementById('modalOutgoingViewBookNo');
        const modalOutgoingViewIssuedDate = document.getElementById('modalOutgoingViewIssuedDate');
        const modalOutgoingViewSubjectText = document.getElementById('modalOutgoingViewSubjectText');
        const modalOutgoingViewFrom = document.getElementById('modalOutgoingViewFrom');
        const modalOutgoingViewGroup = document.getElementById('modalOutgoingViewGroup');
        const modalOutgoingViewLink = document.getElementById('modalOutgoingViewLink');
        const modalOutgoingEditLink = document.getElementById('modalOutgoingEditLink');
        const modalOutgoingViewProposer = document.getElementById('modalOutgoingViewProposer');
        const modalOutgoingViewUrgentRadios = viewModal ? Array.from(viewModal.querySelectorAll('[data-outgoing-view-urgent]')) : [];
        const modalOutgoingViewCoverSection = document.getElementById('modalOutgoingViewCoverSection');
        const modalOutgoingViewCoverList = document.getElementById('modalOutgoingViewCoverList');
        const modalOutgoingViewAttachmentSection = document.getElementById('modalOutgoingViewAttachmentSection');
        const modalOutgoingViewAttachmentList = document.getElementById('modalOutgoingViewAttachmentList');
        const modalOutgoingViewReadStatsBody = document.getElementById('trackReceiptStatusTableBody');

        const setOutgoingPriorityRadio = (radios, key) => {
            const normalizedKey = String(key || 'normal').trim().toLowerCase();
            let matched = false;
            radios.forEach((radio) => {
                const isMatched = String(radio.dataset.outgoingViewUrgent || '').trim().toLowerCase() === normalizedKey;
                radio.checked = isMatched;
                matched = matched || isMatched;
            });

            if (!matched && radios[0]) {
                radios[0].checked = true;
            }
        };

        const setOutgoingViewEditorContent = (html) => {
            const detailHtml = String(html || '').trim();
            const editor = window.tinymce ? window.tinymce.get('memo_editor_view') : null;
            const normalizedHtml = detailHtml !== '' ? detailHtml : '<p>-</p>';

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            const textarea = document.getElementById('memo_editor_view');
            if (textarea) {
                textarea.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get('memo_editor_view') : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const renderOutgoingViewReadStats = (rows) => {
            if (!modalOutgoingViewReadStatsBody) {
                return;
            }

            const list = Array.isArray(rows) ? rows : [];
            if (list.length === 0) {
                modalOutgoingViewReadStatsBody.innerHTML = '<tr><td colspan="3" class="booking-empty">ไม่พบข้อมูลสถานะการอ่านรายบุคคล</td></tr>';
                return;
            }

            modalOutgoingViewReadStatsBody.innerHTML = list.map((row) => {
                const isRead = Number(row?.isRead || 0) === 1;
                return `<tr>
                    <td>${escapeHtml(String(row?.fName || '-'))}</td>
                    <td><span class="status-pill ${isRead ? 'approved' : 'pending'}">${isRead ? 'อ่านแล้ว' : 'ยังไม่อ่าน'}</span></td>
                    <td>${escapeHtml(isRead ? formatThaiDateTime(row?.readAt || '') : '-')}</td>
                </tr>`;
            }).join('');
        };

        const renderOutgoingViewFiles = (circularId, files) => {
            if (!modalOutgoingViewAttachmentSection || !modalOutgoingViewAttachmentList) {
                return;
            }

            const list = Array.isArray(files) ? files : [];
            if (modalOutgoingViewCoverSection && modalOutgoingViewCoverList) {
                modalOutgoingViewCoverSection.style.display = 'none';
                modalOutgoingViewCoverList.innerHTML = '';
            }

            if (list.length === 0) {
                modalOutgoingViewAttachmentSection.style.display = 'none';
                modalOutgoingViewAttachmentList.innerHTML = '';
                return;
            }

            const safeCircularId = encodeURIComponent(String(circularId || '').trim());
            modalOutgoingViewAttachmentSection.style.display = '';
            modalOutgoingViewAttachmentList.innerHTML = list.map((file) => {
                const fileId = encodeURIComponent(String(file?.fileID || ''));
                const fileName = escapeHtml(String(file?.fileName || '-'));
                const mimeType = String(file?.mimeType || '').trim();
                const typeLabel = escapeHtml(`${mimeType !== '' ? mimeType : 'ไฟล์แนบ'} • ${formatFileSize(file?.fileSize || 0)}`);
                const viewHref = `public/api/file-download.php?module=circulars&entity_id=${safeCircularId}&file_id=${fileId}`;
                const iconHtml = mimeType.toLowerCase() === 'application/pdf' ?
                    '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                    '<i class="fa-solid fa-file-image" aria-hidden="true"></i>';

                return `<div class="file-item-wrapper">
                    <div class="file-banner">
                        <div class="file-info">
                            <div class="file-icon">${iconHtml}</div>
                            <div class="file-text">
                                <span class="file-name">${fileName}</span>
                                <span class="file-type">${typeLabel}</span>
                            </div>
                        </div>
                        <div class="file-actions">
                            <a href="${viewHref}" class="action-btn" target="_blank" rel="noopener" title="ดูตัวอย่าง">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </div>`;
            }).join('');
        };

        const resetOutgoingViewModal = () => {
            if (modalOutgoingViewBookNo) modalOutgoingViewBookNo.value = '-';
            if (modalOutgoingViewIssuedDate) modalOutgoingViewIssuedDate.value = '';
            if (modalOutgoingViewSubjectText) modalOutgoingViewSubjectText.value = '-';
            if (modalOutgoingViewFrom) modalOutgoingViewFrom.value = '-';
            if (modalOutgoingViewGroup) modalOutgoingViewGroup.value = '-';
            if (modalOutgoingViewLink) modalOutgoingViewLink.value = '-';
            if (modalOutgoingEditLink) modalOutgoingEditLink.value = '';
            if (modalOutgoingViewProposer) modalOutgoingViewProposer.value = '-';

            setOutgoingPriorityRadio(modalOutgoingViewUrgentRadios, 'normal');
            setOutgoingViewEditorContent('');
            renderOutgoingViewReadStats([]);
            renderOutgoingViewFiles('', []);
        };

        const editModal = document.getElementById('modalOrderEditOverlay');
        const closeEditBtn = document.getElementById('closeModalOrderSend');
        const openEditBtns = document.querySelectorAll('.js-open-order-edit-modal');

        if (editModal) {
            const openEditModal = (outgoingId) => {
                const payload = outgoingViewPayloadMap[String(outgoingId || '').trim()] || {};

                if (outgoingId) {
                    const idInput = document.getElementById('modalOutgoingEditOutgoingId');
                    if (idInput) idInput.value = outgoingId;
                }
                if (modalOutgoingEditLink) {
                    modalOutgoingEditLink.value = outgoingPayloadLink(payload);
                }
                editModal.style.display = 'flex';
            };

            const closeEditModal = () => {
                editModal.style.display = 'none';
            };

            openEditBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const outgoingId = btn.getAttribute('data-outgoing-id');
                    openEditModal(outgoingId);
                });
            });

            if (closeEditBtn) {
                closeEditBtn.addEventListener('click', closeEditModal);
            }

            window.addEventListener('click', (event) => {
                if (event.target === editModal) {
                    closeEditModal();
                }
            });
        }

        const closeViewBtn = document.getElementById('modalOrderViewCloseBtn');
        const openViewBtns = document.querySelectorAll('.js-open-order-view-modal');

        if (viewModal) {
            const openViewModal = (outgoingId) => {
                const payload = outgoingViewPayloadMap[String(outgoingId || '').trim()] || {};

                resetOutgoingViewModal();

                if (modalOutgoingViewBookNo) {
                    modalOutgoingViewBookNo.value = String(payload.outgoingNo || '').trim() || '-';
                }
                if (modalOutgoingViewIssuedDate) {
                    const effectiveDate = String(payload.effectiveDate || '').trim();
                    modalOutgoingViewIssuedDate.value = /^\d{4}-\d{2}-\d{2}$/.test(effectiveDate) ? effectiveDate : '';
                }
                if (modalOutgoingViewSubjectText) {
                    modalOutgoingViewSubjectText.value = String(payload.subject || '').trim() || '-';
                }
                if (modalOutgoingViewFrom) {
                    modalOutgoingViewFrom.value = String(payload.fromName || payload.destinationName || '').trim() || '-';
                }
                if (modalOutgoingViewGroup) {
                    modalOutgoingViewGroup.value = String(payload.groupName || '').trim() || '-';
                }
                setTextInputValue(modalOutgoingViewLink, outgoingPayloadLink(payload));
                if (modalOutgoingViewProposer) {
                    modalOutgoingViewProposer.value = String(payload.proposerName || payload.issuerName || '').trim() || '-';
                }

                setOutgoingPriorityRadio(modalOutgoingViewUrgentRadios, String(payload.priorityKey || 'normal'));
                setOutgoingViewEditorContent(payload.detail || '');
                renderOutgoingViewReadStats(payload.readStats || []);
                renderOutgoingViewFiles(outgoingId, payload.attachments || []);

                viewModal.style.display = 'flex';
            };

            const closeViewModal = () => {
                viewModal.style.display = 'none';
            };

            openViewBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const outgoingId = btn.getAttribute('data-outgoing-id');
                    openViewModal(outgoingId);
                });
            });

            if (closeViewBtn) {
                closeViewBtn.addEventListener('click', closeViewModal);
            }

            window.addEventListener('click', (event) => {
                if (event.target === viewModal) {
                    closeViewModal();
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
