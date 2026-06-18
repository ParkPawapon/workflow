<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../modules/memos/status.php';

$values = $values ?? ['writeDate' => '', 'subject' => '', 'detail' => ''];
$current_user = (array) ($current_user ?? []);
$factions = (array) ($factions ?? []);
$teachers = (array) ($teachers ?? []);
$memo_director_label = 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน';
$current_pid = trim((string) ($current_user['pID'] ?? ''));
$memo_executive_position_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($memo_executive_position_ids ?? [])))));

if ($memo_executive_position_ids === []) {
    $memo_executive_position_ids = [1, 9, 2, 3, 4];
}

$selected_sender_fid = trim((string) ($values['sender_fid'] ?? ''));

if ($selected_sender_fid === '' && !empty($factions)) {
    $selected_sender_fid = (string) ($factions[0]['fID'] ?? '');
}

$current_sender_faction_name = '';
$current_sender_fid = trim((string) ($current_user['fID'] ?? ''));

foreach ($factions as $faction) {
    $fid = trim((string) ($faction['fID'] ?? ''));

    if ($fid !== '' && $fid === $current_sender_fid) {
        $current_sender_faction_name = trim((string) ($faction['fname'] ?? ''));
        break;
    }
}

if ($current_sender_faction_name === '') {
    foreach ($factions as $faction) {
        $fid = trim((string) ($faction['fID'] ?? ''));

        if ($fid !== '' && $fid === $selected_sender_fid) {
            $current_sender_faction_name = trim((string) ($faction['fname'] ?? ''));
            break;
        }
    }
}

$signature_src = trim((string) ($current_user['signature'] ?? ''));
$current_name = trim((string) ($current_user['fName'] ?? ''));
$current_position = trim((string) ($current_user['position_name'] ?? ''));
$parse_upload_size_to_bytes = static function (string $value): int {
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g' => (int) round($number * 1024 * 1024 * 1024),
        'm' => (int) round($number * 1024 * 1024),
        'k' => (int) round($number * 1024),
        default => (int) round((float) $value),
    };
};
$format_upload_limit_label = static function (int $bytes): string {
    if ($bytes <= 0) {
        return '0B';
    }

    if ($bytes >= 1024 * 1024 * 1024) {
        $value = $bytes / (1024 * 1024 * 1024);
        $formatted = fmod($value, 1.0) === 0.0 ? number_format($value, 0) : number_format($value, 1);
        return $formatted . 'GB';
    }

    if ($bytes >= 1024 * 1024) {
        $value = $bytes / (1024 * 1024);
        $formatted = fmod($value, 1.0) === 0.0 ? number_format($value, 0) : number_format($value, 1);
        return $formatted . 'MB';
    }

    if ($bytes >= 1024) {
        $value = $bytes / 1024;
        $formatted = fmod($value, 1.0) === 0.0 ? number_format($value, 0) : number_format($value, 1);
        return $formatted . 'KB';
    }

    return $bytes . 'B';
};
$memo_upload_max_size_bytes = $parse_upload_size_to_bytes((string) ini_get('upload_max_filesize'));
$memo_post_max_size_bytes = $parse_upload_size_to_bytes((string) ini_get('post_max_size'));

if ($memo_post_max_size_bytes > 0 && ($memo_upload_max_size_bytes <= 0 || $memo_post_max_size_bytes < $memo_upload_max_size_bytes)) {
    $memo_upload_max_size_bytes = $memo_post_max_size_bytes;
}

if ($memo_upload_max_size_bytes <= 0) {
    $memo_upload_max_size_bytes = 100 * 1024 * 1024;
}

$memo_upload_max_size_label = $format_upload_limit_label($memo_upload_max_size_bytes);
$selected_factions = array_map('strval', (array) ($values['faction_ids'] ?? []));
$selected_people = array_map('strval', (array) ($values['person_ids'] ?? []));
$selected_primary_pid = (string) ($selected_people[0] ?? '');
$memos = (array) ($memos ?? []);
$search = trim((string) ($search ?? ''));
$status_filter_raw = trim((string) ($status_filter ?? 'all'));
$status_filter = strtolower($status_filter_raw) === 'all' ? 'all' : strtoupper($status_filter_raw);
$sort = strtolower(trim((string) ($sort ?? 'newest')));

if ($status_filter === '') {
    $status_filter = 'all';
}

if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}

$truncate_subject = static function (string $value, int $limit = 70): string {
    $value = trim($value);

    if ($value === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit, 'UTF-8') . '...';
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit) . '...';
};

$teachers = array_values(array_filter($teachers, static function (array $teacher) use ($current_pid): bool {
    $pid = trim((string) ($teacher['pID'] ?? ''));

    if ($pid === '' || $pid === $current_pid) {
        return false;
    }

    return ctype_digit($pid);
}));

$is_selected = static function (string $value, array $selected): bool {
    return in_array($value, $selected, true);
};

$faction_members = [];
$department_groups = [];
$executive_members = [];
$subject_head_members = [];

foreach ($teachers as $teacher) {
    $position_id = (int) ($teacher['positionID'] ?? 0);
    $pid = trim((string) ($teacher['pID'] ?? ''));
    $name = trim((string) ($teacher['fName'] ?? ''));

    if ($pid === '' || $name === '') {
        continue;
    }

    if (in_array($position_id, $memo_executive_position_ids, true)) {
        $executive_members[$pid] = [
            'pID' => $pid,
            'name' => $name,
        ];
    }

    if ($position_id === 5) {
        $subject_head_members[$pid] = [
            'pID' => $pid,
            'name' => $name,
        ];
    }
}

$executive_members = array_values($executive_members);
$subject_head_members = array_values($subject_head_members);

usort($executive_members, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
usort($subject_head_members, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$special_groups = [];

if (!empty($executive_members)) {
    $special_groups[] = [
        'key' => 'special-executive',
        'name' => 'คณะผู้บริหารสถานศึกษา',
        'members' => $executive_members,
    ];
}

if (!empty($subject_head_members)) {
    $special_groups[] = [
        'key' => 'special-subject-head',
        'name' => 'หัวหน้ากลุ่มสาระ',
        'members' => $subject_head_members,
    ];
}

$status_sort_priority_map = memo_status_sort_priority_map();
$status_options = memo_status_options();

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

$format_thai_datetime = static function (?string $date_value) use ($thai_months): array {
    $date_value = trim((string) $date_value);

    if ($date_value === '' || $date_value === '0000-00-00 00:00:00') {
        return ['-', '-'];
    }

    try {
        $date = new DateTime($date_value);
    } catch (Throwable $exception) {
        return [$date_value, '-'];
    }

    $day = (int) $date->format('j');
    $month = (int) $date->format('n');
    $year = (int) $date->format('Y') + 543;
    $hour = $date->format('H');
    $minute = $date->format('i');

    return [
        trim($day . ' ' . ($thai_months[$month] ?? '') . ' ' . $year),
        trim($hour . ':' . $minute . ' น.'),
    ];
};

ob_start();
?>
<style>
    .content-memo .memo-detail {
        --memo-label-width: 56px;
    }

    .content-memo .memo-detail .form-group-row.memo-subject-row {
        gap: 10px;
    }

    .content-memo .memo-detail .form-group-row.memo-to-row {
        gap: 10px;
    }

    .content-memo .memo-detail .form-group-row.memo-subject-row>p:first-child,
    .content-memo .memo-detail .form-group-row.memo-to-row>p:first-child {
        width: var(--memo-label-width);
        min-width: var(--memo-label-width);
    }

    .content-memo .memo-detail .form-group-row.memo-subject-row input[name="subject"] {
        flex: 1 1 auto;
        min-width: 0;
    }

    .circular-my-filter-grid {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
        flex-direction: row;
        margin: 0 0 40px;
    }

    .content-my-memo .memo-mine-table tbody td:first-child p {
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .content-my-memo .memo-mine-table th.memo-date-col,
    .content-my-memo .memo-mine-table td.memo-date-col {
        text-align: left;
    }

    .content-memo .memo-detail .file-list {
        max-width: 420px;
    }

    .modal-overlay-memo.suggest .form-group.receive {
        align-items: flex-end;
    }

    .modal-overlay-memo.suggest .form-group.receive .dropdown-container {
        width: 450px;
        max-width: 450px;
    }

    .modal-overlay-memo.suggest .form-group.receive .search-input-wrapper {
        height: 40px;
    }

    .modal-overlay-memo.suggest .form-group.receive .search-input-wrapper i {
        position: absolute;
        right: 0;
        transform: translateX(-20px);
    }

    .modal-overlay-memo.suggest .dropdown-content {
        width: 100%;
        right: 0;
        border-radius: 8px;
    }

    .modal-overlay-memo.suggest .dropdown-content .dropdown-list {
        max-height: 360px;
    }

    .modal-overlay-memo.suggest .dropdown-content .category-title {
        padding: 8px 10px;
        border-bottom: 1px solid rgba(var(--rgb-primary-dark), 0.12);
    }

    .modal-overlay-memo.suggest .dropdown-content .category-title span {
        display: inline-flex;
        align-items: center;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.35;
        color: var(--color-secondary);
    }

    .modal-overlay-memo.suggest .dropdown-content .category-items {
        padding: 0;
    }

    .modal-overlay-memo.suggest .dropdown-content .item.item-group {
        display: block;
        width: 100%;
        padding: 10px 14px;
        box-sizing: border-box;
        cursor: default;
        border-top: 1px solid rgba(var(--rgb-primary-dark), 0.12);
    }

    .modal-overlay-memo.suggest .dropdown-content .item.item-group:hover {
        background: transparent;
    }

    .modal-overlay-memo.suggest .dropdown-content .item-group .group-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .modal-overlay-memo.suggest .dropdown-content .item-group .item-main {
        display: block;
        min-width: 0;
        width: 100%;
        padding: 4px 0;
        margin: 0;
    }

    .modal-overlay-memo.suggest .dropdown-content .item-group .item-title {
        display: block;
        min-width: 0;
        font-weight: 700;
        font-size: 16px;
        line-height: 1.4;
        white-space: normal;
        word-break: normal;
        overflow-wrap: break-word;
        color: var(--color-secondary);
    }

    .modal-overlay-memo.suggest .dropdown-content .item-group .item-subtext {
        display: block;
        min-width: 0;
        margin-top: 3px;
        font-size: 13px;
        line-height: 1.35;
        white-space: normal;
        word-break: normal;
        overflow-wrap: break-word;
        color: rgba(var(--rgb-primary-dark), 0.8);
    }

    .modal-overlay-memo.suggest .dropdown-content .item-group .member-sublist {
        margin: 8px 0 4px 0;
        padding: 0 0 0 30px;
        list-style: none;
    }

    .modal-overlay-memo.suggest .dropdown-content .item-group .member-sublist li {
        display: block;
        width: 100%;
        margin: 0 0 6px 0;
    }

    .modal-overlay-memo.suggest .dropdown-content .item.member-item {
        display: grid;
        grid-template-columns: 18px minmax(0, 1fr);
        align-items: start;
        gap: 10px;
        width: 100%;
        padding: 6px 0;
        box-sizing: border-box;
        margin: 0;
        cursor: pointer;
        border-radius: 6px;
    }

    .modal-overlay-memo.suggest .dropdown-content .item.member-item input[type="radio"] {
        width: 16px;
        height: 16px;
        margin: 2px 0 0 0;
    }

    .modal-overlay-memo.suggest .dropdown-content .item.member-item .member-name {
        display: block;
        min-width: 0;
        width: 100%;
        line-height: 1.55;
        font-size: 15px;
        font-weight: 500;
        white-space: normal;
        word-break: normal;
        overflow-wrap: break-word;
        color: var(--color-primary-dark);
    }

    .modal-overlay-memo.suggest .dropdown-content .item.member-item:hover {
        background: rgba(var(--rgb-primary-dark), 0.06);
    }

    .modal-overlay-memo.suggest .dropdown-content .category-items label.member-item {
        width: 100%;
        overflow: visible;
        text-overflow: clip;
        white-space: normal;
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(1) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .table-circular-notice-index table tbody td:nth-child(2),
    .table-circular-notice-index table thead th:nth-child(4),
    .table-circular-notice-index table tbody td:nth-child(4) {
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

    @media screen and (max-width: 1024px) {

        .table-circular-notice-index table thead th:nth-child(1),
        .table-circular-notice-index table tbody td:nth-child(1) {
            text-align: center !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .table-circular-notice-index table tbody td:nth-child(2),
        .table-circular-notice-index table thead th:nth-child(4),
        .table-circular-notice-index table tbody td:nth-child(4) {
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
            width: 500px !important;
            min-width: 500px !important;
            max-width: 500px !important;
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
    }
</style>
<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>บันทึกข้อความ</p>
</div>

<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
            onclick="openTab('memoBook', event)">บันทึกข้อความ</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>" onclick="openTab('memoMine', event)">บันทึกข้อความของฉัน</button>
    </div>
</div>

<div class="content-memo tab-content <?= $is_track_active ? '' : 'active' ?>" id="memoBook">
    <div class="memo-header">
        <img src="assets/img/garuda-logo.png" alt="">
        <p>บันทึกข้อความ</p>
        <div></div>
    </div>

    <form
        method="POST"
        id="circularComposeForm">
        <?= csrf_field() ?>
        <input type="hidden" name="flow_mode" value="CHAIN">
        <input type="hidden" name="to_choice" value="DIRECTOR">

        <div class="memo-detail">
            <div class="form-group-row">
                <p><strong>ส่วนราชการ</strong></p>

                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value">
                            <?php
                            $selected_faction_name = '';

                            foreach ($factions as $faction) {
                                if ((string) ($faction['fID'] ?? '') === $selected_sender_fid) {
                                    $selected_faction_name = (string) ($faction['fname'] ?? '');
                                    break;
                                }
                            }
                            echo h($selected_faction_name !== '' ? $selected_faction_name : 'เลือกส่วนราชการ');
                            ?>
                        </p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($factions as $faction) : ?>
                            <?php $fid = (string) ($faction['fID'] ?? ''); ?>
                            <div class="custom-option<?= $fid === $selected_sender_fid ? ' selected' : '' ?>" data-value="<?= h($fid) ?>">
                                <?= h((string) ($faction['fname'] ?? '')) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="sender_fid" value="<?= h($selected_sender_fid) ?>">
                </div>

                <p><strong>โรงเรียนดีบุกพังงาวิทยายน</strong></p>
            </div>

            <div class="form-group-row memo-subject-row">
                <p><strong>เรื่อง</strong></p>
                <input type="text" name="subject" value="<?= h((string) ($values['subject'] ?? '')) ?>" required>
            </div>

            <div class="form-group-row memo-to-row">
                <p><strong>เรียน</strong></p>
                <p><?= h($memo_director_label) ?></p>
            </div>

            <div class="content-editor">
                <p><strong>รายละเอียด:</strong></p>
                <textarea name="detail" id="memo_editor_compose"><?= h((string) ($values['detail'] ?? '')) ?></textarea>
            </div>

            <div class="form-group-row signature">
                <img src="<?= h($signature_src) ?>" alt="">
                <p>(<?= h($current_name !== '' ? $current_name : '-') ?>)</p>
                <p><?= h($current_position !== '' ? $current_position : '-') ?></p>
            </div>

            <div class="form-group-row submit">
                <button
                    type="submit"
                    data-confirm="ยืนยันการบันทึกเอกสารนี้ใช่หรือไม่"
                    data-confirm-title="ยืนยันการบันทึก"
                    data-confirm-ok="ยืนยัน"
                    data-confirm-cancel="ยกเลิก">บันทึกเอกสาร</button>
            </div>
        </div>
    </form>
</div>

<div class="content-my-memo enterprise-card tab-content <?= $is_track_active ? 'active' : '' ?>" id="memoMine">

    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" action="memo.php" class="circular-my-filter-grid" id="memoTrackFilterForm">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($search) ?>"
                    placeholder="ค้นหาเลขที่หรือเรื่อง" autocomplete="off">
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($status_options[$status_filter] ?? 'ทั้งหมด') ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($status_options as $option_value => $option_label) : ?>
                            <div class="custom-option<?= $status_filter === $option_value ? ' selected' : '' ?>" data-value="<?= h($option_value) ?>"><?= h($option_label) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <select class="form-input" name="status">
                        <?php foreach ($status_options as $option_value => $option_label) : ?>
                            <option value="<?= h($option_value) ?>" <?= $status_filter === $option_value ? 'selected' : '' ?>><?= h($option_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($sort === 'oldest' ? 'เก่าไปใหม่' : 'ใหม่ไปเก่า') ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option<?= $sort === 'newest' ? ' selected' : '' ?>" data-value="newest">ใหม่ไปเก่า</div>
                        <div class="custom-option<?= $sort === 'oldest' ? ' selected' : '' ?>" data-value="oldest">เก่าไปใหม่</div>
                    </div>

                    <select class="form-input" name="sort">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>ใหม่ไปเก่า</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>เก่าไปใหม่</option>
                    </select>
                </div>
            </div>
        </div>
    </form>

    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">รายการบันทึกข้อความ</h2>
        </div>
    </div>

    <div class="table-responsive table-circular-notice-index">
        <table class="custom-table booking-table memo-mine-table">
            <thead>
                <tr>
                    <th>จัดการ</th>
                    <th>เรื่อง</th>
                    <th>สถานะ</th>
                    <th class="memo-date-col">วันที่ส่ง</th>
                    <th class="memo-date-col">อัปเดตล่าสุด</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($memos)) : ?>
                    <tr data-memo-empty-row="1">
                        <td colspan="5" class="booking-empty">ไม่พบรายการบันทึกข้อความของฉัน</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($memos as $memo) : ?>
                        <?php
                        $memo_id = (int) ($memo['memoID'] ?? 0);
                        $subject = trim((string) ($memo['subject'] ?? ''));
                        $detail = trim((string) ($memo['detail'] ?? ''));
                        $detail_for_attr = $detail !== '' ? $detail : '-';
                        $detail_b64 = base64_encode($detail_for_attr);
                        $status = strtoupper(trim((string) ($memo['status'] ?? '')));
                        $status_meta = memo_status_meta_for_record($memo);
                        $status_sort_priority = (int) ($status_meta['priority'] ?? 99);
                        $submitted_at = trim((string) ($memo['submittedAt'] ?? ''));
                        $updated_at = trim((string) ($memo['updatedAt'] ?? ''));
                        $created_at = trim((string) ($memo['createdAt'] ?? ''));
                        $sent_at = $created_at !== '' ? $created_at : $submitted_at;
                        [$date_line, $time_line] = $format_thai_datetime($sent_at);
                        [$updated_date_line, $updated_time_line] = $format_thai_datetime($updated_at !== '' ? $updated_at : $created_at);

                        if ($updated_date_line === '-' || $updated_date_line === '') {
                            $updated_date_line = $date_line;
                        }

                        if ($updated_time_line === '-' || $updated_time_line === '') {
                            $updated_time_line = $time_line;
                        }
                        $sent_sort_raw = $sent_at;
                        $sent_sort_ts = strtotime($sent_sort_raw);

                        if ($sent_sort_ts === false) {
                            $sent_sort_ts = 0;
                        }
                        $memo_no = trim((string) ($memo['memoNo'] ?? ''));
                        $book_no_display = $memo_no !== '' ? $memo_no : ('#' . $memo_id);
                        $to_label = $memo_director_label;
                        $sender_faction_name = trim((string) ($memo['senderFactionName'] ?? ''));

                        if ($sender_faction_name === '') {
                            $sender_faction_name = trim((string) ($memo['creatorFactionName'] ?? ''));
                        }

                        if ($sender_faction_name === '') {
                            $sender_faction_name = $current_sender_faction_name;
                        }
                        $sender_fid_attr = trim((string) ($memo['senderFID'] ?? ''));

                        if ($sender_fid_attr === '' || !ctype_digit($sender_fid_attr) || (int) $sender_fid_attr <= 0) {
                            $sender_fid_attr = '';
                        }
                        $attachment_count = $memo_id > 0 ? count(memo_get_attachments($memo_id)) : 0;
                        $memo_files = $memo_id > 0 ? memo_get_attachments($memo_id) : [];
                        $memo_files_json = json_encode($memo_files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $head_note_b64 = base64_encode((string) ($memo['headNote'] ?? ''));
                        $deputy_note_b64 = base64_encode((string) ($memo['deputyNote'] ?? ''));
                        $director_note_b64 = base64_encode((string) ($memo['directorNote'] ?? ''));
                        $returned_reviewer_pid = trim((string) ($memo['returnedReviewerPID'] ?? ''));
                        $returned_reviewer_name = trim((string) ($memo['returnedReviewerName'] ?? ''));
                        $can_preview_pdf = in_array($status, [MEMO_STATUS_APPROVED_UNSIGNED, MEMO_STATUS_SIGNED, MEMO_STATUS_REJECTED], true);
                        $can_edit_and_submit = in_array($status, ['DRAFT', MEMO_STATUS_RETURNED], true)
                            || !empty($memo['ownerCanEditBeforeHeadForward']);
                        $memo_pdf_preview_href = $memo_id > 0
                            ? ('memo-pdf.php?memo_id=' . rawurlencode((string) $memo_id))
                            : '';

                        if ($memo_files_json === false) {
                            $memo_files_json = '[]';
                        }
                        ?>
                        <tr
                            data-memo-track-row="1"
                            data-memo-id="<?= h((string) $memo_id) ?>"
                            data-memo-status="<?= h($status) ?>"
                            data-memo-status-order="<?= h((string) $status_sort_priority) ?>"
                            data-memo-subject="<?= h($subject) ?>"
                            data-memo-no="<?= h($memo_no) ?>"
                            data-memo-sent-ts="<?= h((string) $sent_sort_ts) ?>">
                            <td>
                                <button
                                    type="button"
                                    class="booking-action-btn secondary js-open-view-modal"
                                    data-type="INTERNAL"
                                    data-circular-id="<?= h((string) $memo_id) ?>"
                                    data-detail="<?= h($detail_for_attr) ?>"
                                    data-detail-b64="<?= h($detail_b64) ?>"
                                    data-subject="<?= h($subject !== '' ? $subject : '-') ?>"
                                    data-bookno="<?= h($book_no_display) ?>"
                                    data-issued="<?= h((string) ($memo['writeDate'] ?? '-')) ?>"
                                    data-from="<?= h($current_name !== '' ? $current_name : '-') ?>"
                                    data-sender="<?= h($sender_faction_name !== '' ? $sender_faction_name : '-') ?>"
                                    data-to="<?= h($to_label) ?>"
                                    data-status="<?= h((string) ($status_meta['label'] ?? '-')) ?>"
                                    data-consider="considering"
                                    data-received-time="<?= h(trim($date_line . ' ' . $time_line)) ?>"
                                    data-read-stats="[]"
                                    data-head-name="<?= h((string) ($memo['headName'] ?? '')) ?>"
                                    data-head-position="<?= h((string) ($memo['headPositionName'] ?? '')) ?>"
                                    data-head-signature="<?= h((string) ($memo['headSignature'] ?? '')) ?>"
                                    data-head-note-b64="<?= h($head_note_b64) ?>"
                                    data-head-action="<?= h((string) ($memo['headAction'] ?? '')) ?>"
                                    data-deputy-name="<?= h((string) ($memo['deputyName'] ?? '')) ?>"
                                    data-deputy-position="<?= h((string) ($memo['deputyPositionName'] ?? '')) ?>"
                                    data-deputy-signature="<?= h((string) ($memo['deputySignature'] ?? '')) ?>"
                                    data-deputy-note-b64="<?= h($deputy_note_b64) ?>"
                                    data-deputy-action="<?= h((string) ($memo['deputyAction'] ?? '')) ?>"
                                    data-director-name="<?= h((string) ($memo['directorName'] ?? '')) ?>"
                                    data-director-position="<?= h((string) ($memo['directorPositionName'] ?? '')) ?>"
                                    data-director-signature="<?= h((string) ($memo['directorSignature'] ?? '')) ?>"
                                    data-director-note-b64="<?= h($director_note_b64) ?>"
                                    data-director-action="<?= h((string) ($memo['directorAction'] ?? '')) ?>"
                                    data-files="<?= h($memo_files_json) ?>">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    <span class="tooltip">ดูรายละเอียด</span>
                                </button>
                                <?php if ($can_edit_and_submit) : ?>
                                    <button
                                        type="button"
                                        class="booking-action-btn secondary js-open-suggest-modal"
                                        data-memo-id="<?= h((string) $memo_id) ?>"
                                        data-memo-subject="<?= h($subject !== '' ? $subject : '-') ?>"
                                        data-memo-detail="<?= h($detail !== '' ? $detail : '-') ?>"
                                        data-memo-attachments="<?= h((string) $attachment_count) ?>"
                                        data-memo-to="<?= h($to_label) ?>"
                                        data-memo-sender-fid="<?= h($sender_fid_attr) ?>"
                                        data-memo-sender-name="<?= h($sender_faction_name !== '' ? $sender_faction_name : '-') ?>"
                                        data-memo-owner-edit-before-head-forward="<?= !empty($memo['ownerCanEditBeforeHeadForward']) ? '1' : '0' ?>"
                                        data-memo-is-returned="<?= $status === MEMO_STATUS_RETURNED ? '1' : '0' ?>"
                                        data-memo-returned-reviewer-pid="<?= h($returned_reviewer_pid) ?>"
                                        data-memo-returned-reviewer-name="<?= h($returned_reviewer_name) ?>"
                                        data-files="<?= h($memo_files_json) ?>">
                                        <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                                        <span class="tooltip">แก้ไข / เสนอแฟ้ม</span>
                                    </button>

                                    <?php if ($status === 'DRAFT') : ?>
                                        <form method="POST" class="enterprise-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="memo_id" value="<?= h((string) $memo_id) ?>">
                                            <button
                                                type="submit"
                                                class="booking-action-btn danger"
                                                data-confirm="ยืนยันการลบข้อมูลรายการนี้ใช่หรือไม่"
                                                data-confirm-title="ยืนยันการลบข้อมูล"
                                                data-confirm-ok="ยืนยัน"
                                                data-confirm-cancel="ยกเลิก">
                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                <span class="tooltip danger">ลบข้อมูล</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($status === 'CANCELLED' || $status === 'SUBMITTED') : ?>
                                    <?php if ($can_preview_pdf && $memo_pdf_preview_href !== '') : ?>
                                        <a
                                            class="booking-action-btn secondary"
                                            href="<?= h($memo_pdf_preview_href) ?>"
                                            target="_blank"
                                            rel="noopener">
                                            <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                                            <span class="tooltip">ดู PDF</span>
                                        </a>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <?php if ($can_preview_pdf && $memo_pdf_preview_href !== '') : ?>
                                        <a
                                            class="booking-action-btn secondary"
                                            href="<?= h($memo_pdf_preview_href) ?>"
                                            target="_blank"
                                            rel="noopener">
                                            <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                                            <span class="tooltip">ดู PDF</span>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <p><?= h($truncate_subject($subject)) ?></p>
                            </td>
                            <td>
                                <span class="status-pill <?= h((string) ($status_meta['pill_variant'] ?? 'pending')) ?>">
                                    <?= h((string) ($status_meta['label'] ?? '-')) ?>
                                </span>
                            </td>
                            <td class="memo-date-col">
                                <?= h($date_line) ?><br>
                                <span class="detail-subtext"><?= h($time_line) ?></span>
                            </td>
                            <td class="memo-date-col">
                                <?= h($updated_date_line) ?><br>
                                <span class="detail-subtext"><?= h($updated_time_line) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay-memo details" id="modalViewOverlay" style="display: none;">
    <div class="modal-content">
        <div class="header-modal">
            <p id="modalTypeLabel">รายละเอียดบันทึกข้อความ</p>
            <i class="fa-solid fa-xmark" id="closeModalView" aria-hidden="true"></i>
        </div>

        <div class="content-modal">
            <div class="content-memo">
                <div class="memo-header">
                    <img src="assets/img/garuda-logo.png" alt="">
                    <p>บันทึกข้อความ</p>
                    <div></div>
                </div>

                <form method="POST" id="memoViewForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="flow_mode" value="CHAIN">
                    <input type="hidden" name="to_choice" value="DIRECTOR">

                    <div class="memo-detail">
                        <div class="form-group-row row-format-inline" style="display: flex !important;" id="memoViewSenderRow">
                            <p><strong>ส่วนราชการ</strong></p>
                            <p id="memoViewSenderFaction">-</p>
                            <p><strong>โรงเรียนดีบุกพังงาวิทยายน</strong></p>
                        </div>

                        <div class="form-group-row memo-subject-row row-format" id="memoViewSubjectRow">
                            <p><strong>เรื่อง</strong></p>
                            <p id="memoViewSubject">-</p>
                        </div>

                        <div class="form-group-row memo-to-row row-format" id="memoViewToRow">
                            <p><strong>เรียน</strong></p>
                            <p id="memoViewToLabel"><?= h($memo_director_label) ?></p>
                        </div>

                        <div class="content-editor column-format" id="memoViewDetailWrap">
                            <p><strong>รายละเอียด:</strong></p>
                            <br>
                            <p id="memo_editor_view">-</p>
                        </div>


                        <div class="memo-file-row file-sec" id="memoViewFileRow">
                            <div class="memo-input-content">
                                <label>ไฟล์เอกสาร <strong>(เอกสารได้สูงสุด 5 ไฟล์)</strong></label>
                                <!-- <div>
                                    <button type="button" class="btn btn-upload-small" onclick="document.getElementById('attachment').click()">
                                        <p>เพิ่มไฟล์</p>
                                    </button>
                                </div> -->
                                <!-- <input type="file" id="attachment" name="attachments[]" class="file-input" multiple="" accept=".pdf,image/png,image/jpeg" hidden=""> -->
                                <!-- <p class="form-error hidden" id="attachmentError">แนบได้สูงสุด 5 ไฟล์</p> -->
                            </div>

                            <div class="file-list" id="attachmentListView" aria-live="polite">
                                <p class="attachment-empty">ยังไม่มีไฟล์แนบ</p>
                            </div>
                        </div>

                        <div class="form-group-row signature">
                            <?php if ($signature_src !== '') : ?>
                                <img src="<?= h($signature_src) ?>" alt="">
                            <?php endif; ?>
                            <p id="memoViewSignerName">(<?= h($current_name !== '' ? $current_name : '-') ?>)</p>
                            <p id="memoViewSignerPosition"><?= h($current_position !== '' ? $current_position : '-') ?></p>
                        </div>


                        <div class="form-group-row secondary u-hidden" data-memo-optional="1" id="memoViewHeadNoteRow">
                            <p><strong>ความคิดเห็นและข้อเสนอแนะของหัวหน้ากลุ่มสาระการเรียนรู้</strong></p>
                            <div class="content-editor" style="width:100%">
                                <textarea id="memoViewHeadNote" disabled rows="7"></textarea>
                            </div>
                        </div>

                        <div class="form-group-row signature secondary u-hidden" data-memo-optional="1" id="memoViewHeadSignatureRow">
                            <img id="memoViewHeadSignatureImage" src="" alt="" style="display:none;">
                            <p id="memoViewHeadSignatureName">(-)</p>
                            <p id="memoViewHeadSignaturePosition">-</p>
                        </div>

                        <div class="form-group-row comment u-hidden" data-memo-optional="1" id="memoViewDeputyActionRow">
                            <p><strong>เสนอ :</strong></p>
                            <input type="text" id="memoViewDeputyAction" disabled>
                        </div>

                        <div class="form-group-row primary u-hidden" data-memo-optional="1" id="memoViewDeputyNoteRow">
                            <p><strong>ความคิดเห็นและข้อเสนอแนะของรองผู้อำนวยการ</strong></p>
                            <div class="content-editor" style="width:100%">
                                <textarea id="memoViewDeputyNote" disabled rows="7"></textarea>
                            </div>
                        </div>

                        <div class="form-group-row signature primary u-hidden" data-memo-optional="1" id="memoViewDeputySignatureRow">
                            <img id="memoViewDeputySignatureImage" src="" alt="" style="display:none;">
                            <p id="memoViewDeputySignatureName">(-)</p>
                            <p id="memoViewDeputySignaturePosition">-</p>
                        </div>


                        <div class="form-group-row comment secondary u-hidden" data-memo-optional="1" id="memoViewDirectorActionRow">
                            <p><strong>ผู้บริหารดำเนินการต่อ</strong></p>
                            <input type="text" id="memoViewDirectorAction" disabled>
                        </div>

                        <div class="form-group-row secondary u-hidden" data-memo-optional="1" id="memoViewDirectorNoteRow">
                            <p><strong>ความคิดเห็นและข้อเสนอแนะของผู้อำนวยการโรงเรียน</strong></p>
                            <div class="content-editor" style="width:100%">
                                <textarea id="memoViewDirectorNote" disabled rows="7"></textarea>
                            </div>
                        </div>

                        <div class="form-group-row signature secondary u-hidden" data-memo-optional="1" id="memoViewDirectorSignatureRow">
                            <img id="memoViewDirectorSignatureImage" src="" alt="" style="display:none;">
                            <p id="memoViewDirectorSignatureName">(-)</p>
                            <p id="memoViewDirectorSignaturePosition">-</p>
                        </div>

                    </div>
                </form>
            </div>
        </div>

    </div>

</div>

<div class="modal-overlay-memo suggest" id="modalSuggOverlay" style="display: none;">
    <div class="modal-content">
        <div class="header-modal">
            <p>เสนอแฟ้มบันทึกข้อความ</p>
            <i class="fa-solid fa-xmark" id="closeModalSugg" aria-hidden="true"></i>
        </div>

        <div class="content-modal">

            <div class="content-memo">
                <div class="memo-header">
                    <img src="assets/img/garuda-logo.png" alt="">
                    <p>บันทึกข้อความ</p>
                    <div></div>
                </div>

                <form method="POST" id="memoSuggestForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="flow_mode" value="CHAIN">
                    <input type="hidden" name="to_choice" id="memoSuggestToChoice" value="DIRECTOR">
                    <input type="hidden" name="memo_id" id="memoSuggestMemoId" value="">
                    <input type="hidden" name="action" value="submit">

                    <div class="memo-detail">
                        <div class="form-group-row">
                            <p><strong>ส่วนราชการ</strong></p>

                            <div class="custom-select-wrapper">
                                <div class="custom-select-trigger">
                                    <p class="select-value">
                                        <?php
                                        $selected_faction_name = '';

                                        foreach ($factions as $faction) {
                                            if ((string) ($faction['fID'] ?? '') === $selected_sender_fid) {
                                                $selected_faction_name = (string) ($faction['fname'] ?? '');
                                                break;
                                            }
                                        }
                                        echo h($selected_faction_name !== '' ? $selected_faction_name : 'เลือกส่วนราชการ');
                                        ?>
                                    </p>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="custom-options">
                                    <?php foreach ($factions as $faction) : ?>
                                        <?php $fid = (string) ($faction['fID'] ?? ''); ?>
                                        <div class="custom-option<?= $fid === $selected_sender_fid ? ' selected' : '' ?>" data-value="<?= h($fid) ?>">
                                            <?= h((string) ($faction['fname'] ?? '')) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <input type="hidden" name="sender_fid" value="<?= h($selected_sender_fid) ?>">
                            </div>

                            <p><strong>โรงเรียนดีบุกพังงาวิทยายน</strong></p>
                        </div>

                        <div class="form-group-row memo-subject-row">
                            <p><strong>เรื่อง</strong></p>
                            <input type="text" name="subject" value="<?= h((string) ($values['subject'] ?? '')) ?>" data-memo-suggest-subject required>
                        </div>

                        <div class="form-group-row memo-to-row">
                            <p><strong>เรียน</strong></p>
                            <p data-memo-suggest-to><?= h($memo_director_label) ?></p>
                        </div>

                        <div class="content-editor">
                            <p><strong>รายละเอียด:</strong></p>
                            <br>
                            <textarea name="detail" id="memo_editor_suggest" data-memo-suggest-detail><?= h((string) ($values['detail'] ?? '')) ?></textarea>
                        </div>


                        <div class="memo-file-row file-sec">
                            <div class="memo-input-content">
                                <label>แนบเอกสาร <strong>(แนบเอกสารได้สูงสุด 5 ไฟล์)</strong></label>
                                <div>
                                    <button type="button" class="btn btn-upload-small" onclick="document.getElementById('attachment').click()">
                                        <p>เพิ่มไฟล์</p>
                                    </button>
                                </div>
                                <input type="file" id="attachment" name="attachments[]" class="file-input" multiple="" accept=".pdf,.jpg,.jpeg,.png,.zip,.rar,application/pdf,image/png,image/jpeg,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/x-rar,application/vnd.rar" hidden="" data-max-size-bytes="<?= h((string) $memo_upload_max_size_bytes) ?>" data-max-size-label="<?= h($memo_upload_max_size_label) ?>">
                                <p class="form-error hidden" id="attachmentError">รองรับเฉพาะ PDF, JPG, PNG, ZIP, RAR ขนาดไม่เกิน <?= h($memo_upload_max_size_label) ?></p>
                            </div>

                            <div class="file-list" id="attachmentList" aria-live="polite">
                                <p class="attachment-empty">ยังไม่มีไฟล์แนบ</p>
                            </div>
                        </div>


                        <div class="form-group receive" data-recipients-section>
                            <label>ส่งถึง :</label>
                            <div class="dropdown-container">
                                <div class="search-input-wrapper" id="recipientToggle">
                                    <input type="text" id="mainInput" class="search-input"
                                        placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="dropdown-content" id="dropdownContent">
                                    <div class="dropdown-header">
                                        <span class="select-all-box">เลือกผู้รับได้ 1 คน</span>
                                    </div>

                                    <div class="dropdown-list">
                                        <?php if (!empty($special_groups)) : ?>
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
                                                                <div class="item-main">
                                                                    <span class="item-title"><?= h($group_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </div>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php foreach ($member_payload as $member) : ?>
                                                                    <li>
                                                                        <label class="item member-item">
                                                                            <input type="radio" class="member-checkbox"
                                                                                data-member-group-key="<?= h($group_key) ?>"
                                                                                data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                                data-group-label="<?= h($group_name) ?>"
                                                                                name="memo_to_pid" value="<?= h((string) ($member['pID'] ?? '')) ?>" <?= h(((string) ($member['pID'] ?? '')) === $selected_primary_pid ? 'checked' : '') ?>>
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
                                <button id="btnShowRecipients" type="button">
                                    <p>แสดงผู้รับทั้งหมด</p>
                                </button>
                            </div>
                        </div>

                        <div class="form-group-row signature">
                            <img src="<?= h($signature_src) ?>" alt="">
                            <p>(<?= h($current_name !== '' ? $current_name : '-') ?>)</p>
                            <p><?= h($current_position !== '' ? $current_position : '-') ?></p>
                        </div>

                        <!-- <div class="form-group-row submit">
                            <button type="submit">บันทึกเอกสาร</button>
                        </div> -->
                    </div>
                </form>
            </div>

        </div>

        <div class="footer-modal">
            <button type="submit" form="memoSuggestForm" id="memoSuggestSubmitButton">
                <p>เสนอแฟ้ม</p>
            </button>
        </div>

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

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
<script>
    if (window.tinymce && typeof window.tinymce.init === 'function') {
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
            selector: '#memoViewHeadNote, #memoViewDeputyNote, #memoViewDirectorNote',
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
    }

    document.addEventListener('DOMContentLoaded', function() {
        return;
    });

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('memoSuggestForm') || document.getElementById('circularComposeForm');
        const trackFilterForm = document.getElementById('memoTrackFilterForm');
        const trackSearchInput = trackFilterForm ? trackFilterForm.querySelector('input[name="q"]') : null;
        const trackStatusSelect = trackFilterForm ? trackFilterForm.querySelector('select[name="status"]') : null;
        const trackSortSelect = trackFilterForm ? trackFilterForm.querySelector('select[name="sort"]') : null;
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileListContainer');
        const dropzone = document.getElementById('dropzone');
        const addFilesBtn = document.getElementById('btnAddFiles');
        const previewModal = document.getElementById('imagePreviewModal');
        const previewImage = document.getElementById('previewImage');
        const previewCaption = document.getElementById('previewCaption');
        const closePreviewBtn = document.getElementById('closePreviewBtn');

        const maxFiles = 5;
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/x-rar', 'application/vnd.rar'];
        const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'zip', 'rar'];
        let selectedFiles = [];
        let trackSearchTimer = null;
        const trackTableBody = document.querySelector('#memoMine .memo-mine-table tbody');
        const trackDataRows = trackTableBody ? Array.from(trackTableBody.querySelectorAll('tr[data-memo-track-row="1"]')) : [];
        let trackEmptyRow = trackTableBody ? trackTableBody.querySelector('tr[data-memo-empty-row="1"]') : null;
        const memoStatusPriorityMap = <?= json_encode($status_sort_priority_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const isAllowedFile = (file) => {
            const mimeType = String(file?.type || '').toLowerCase();
            const extension = String(file?.name || '').toLowerCase().split('.').pop() || '';

            return allowedTypes.includes(mimeType) || allowedExtensions.includes(extension);
        };

        const normalizeTrackFilterText = (value) => String(value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();

        const ensureTrackEmptyRow = () => {
            if (!trackTableBody) {
                return null;
            }

            if (trackEmptyRow && trackEmptyRow.parentNode) {
                return trackEmptyRow;
            }
            trackEmptyRow = document.createElement('tr');
            trackEmptyRow.setAttribute('data-memo-empty-row', '1');
            trackEmptyRow.innerHTML = '<td colspan="5" class="booking-empty">ไม่พบรายการบันทึกข้อความของฉัน</td>';
            return trackEmptyRow;
        };

        const updateTrackFilterUrl = () => {
            if (!trackFilterForm) {
                return;
            }

            const formData = new FormData(trackFilterForm);
            const qValue = String(formData.get('q') || '').trim();
            const statusValue = String(formData.get('status') || 'all').trim() || 'all';
            const sortValue = String(formData.get('sort') || 'newest').trim() || 'newest';
            const params = new URLSearchParams();
            if (qValue !== '') {
                params.set('q', qValue);
            }
            if (statusValue !== 'all') {
                params.set('status', statusValue);
            }
            if (sortValue !== 'newest') {
                params.set('sort', sortValue);
            }

            const query = params.toString();
            const nextUrl = query !== '' ? `${window.location.pathname}?${query}` : window.location.pathname;
            window.history.replaceState({}, '', nextUrl);
        };

        const applyTrackFiltersClientSide = () => {
            if (!trackFilterForm) {
                return;
            }

            const qValue = normalizeTrackFilterText(trackSearchInput ? trackSearchInput.value : '');
            const statusValue = String(trackStatusSelect ? trackStatusSelect.value : 'all').trim() || 'all';
            const sortValue = String(trackSortSelect ? trackSortSelect.value : 'newest').trim() || 'newest';

            if (!trackTableBody || trackDataRows.length === 0) {
                updateTrackFilterUrl();
                return;
            }

            const filteredRows = trackDataRows.filter((row) => {
                const rowStatus = String(row.getAttribute('data-memo-status') || '').toUpperCase();
                const rowSubject = normalizeTrackFilterText(row.getAttribute('data-memo-subject') || '');
                const rowMemoNo = normalizeTrackFilterText(row.getAttribute('data-memo-no') || '');
                const matchesStatus = statusValue === 'all' || rowStatus === statusValue.toUpperCase();
                const matchesSearch = qValue === '' || rowSubject.includes(qValue) || rowMemoNo.includes(qValue);
                return matchesStatus && matchesSearch;
            });

            filteredRows.sort((leftRow, rightRow) => {
                const leftTs = Number(leftRow.getAttribute('data-memo-sent-ts') || '0');
                const rightTs = Number(rightRow.getAttribute('data-memo-sent-ts') || '0');

                if (leftTs === rightTs) {
                    const leftId = Number(leftRow.getAttribute('data-memo-id') || '0');
                    const rightId = Number(rightRow.getAttribute('data-memo-id') || '0');
                    return sortValue === 'oldest' ? leftId - rightId : rightId - leftId;
                }

                return sortValue === 'oldest' ? leftTs - rightTs : rightTs - leftTs;
            });

            trackDataRows.forEach((row) => {
                row.remove();
            });

            if (trackEmptyRow && trackEmptyRow.parentNode) {
                trackEmptyRow.parentNode.removeChild(trackEmptyRow);
            }

            if (filteredRows.length === 0) {
                const emptyRow = ensureTrackEmptyRow();
                if (emptyRow) {
                    trackTableBody.appendChild(emptyRow);
                }
                updateTrackFilterUrl();
                return;
            }

            filteredRows.forEach((row) => {
                trackTableBody.appendChild(row);
            });
            updateTrackFilterUrl();
        };

        if (trackSearchInput && trackFilterForm) {
            trackFilterForm.addEventListener('submit', (event) => {
                event.preventDefault();

                if (trackSearchTimer) {
                    clearTimeout(trackSearchTimer);
                }
                applyTrackFiltersClientSide();
            });
            trackSearchInput.addEventListener('input', () => {
                if (trackSearchTimer) {
                    clearTimeout(trackSearchTimer);
                }
                trackSearchTimer = window.setTimeout(() => {
                    applyTrackFiltersClientSide();
                }, 300);
            });
            trackSearchInput.addEventListener('search', () => applyTrackFiltersClientSide());
            trackSearchInput.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }
                event.preventDefault();

                if (trackSearchTimer) {
                    clearTimeout(trackSearchTimer);
                }
                applyTrackFiltersClientSide();
            });
        }
        trackStatusSelect?.addEventListener('change', () => applyTrackFiltersClientSide());
        trackSortSelect?.addEventListener('change', () => applyTrackFiltersClientSide());
        applyTrackFiltersClientSide();

        const renderFiles = () => {
            if (!fileList) return;
            fileList.innerHTML = '';
            if (selectedFiles.length === 0) return;

            selectedFiles.forEach((file, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper';

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'delete-btn';
                deleteBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
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
                const mimeType = String(file.type || '').toLowerCase();
                const extension = String(file.name || '').toLowerCase().split('.').pop() || '';
                icon.innerHTML = mimeType === 'application/pdf' || extension === 'pdf' ?
                    '<i class="fa-solid fa-file-pdf"></i>' :
                    (mimeType.startsWith('image/') || ['jpg', 'jpeg', 'png'].includes(extension) ? '<i class="fa-solid fa-image"></i>' : '<i class="fa-solid fa-file"></i>');

                const text = document.createElement('div');
                text.className = 'file-text';

                const name = document.createElement('div');
                name.className = 'file-name';
                name.textContent = file.name;

                const type = document.createElement('div');
                type.className = 'file-type';
                type.textContent = file.type || 'ไฟล์แนบ';

                text.appendChild(name);
                text.appendChild(type);

                info.appendChild(icon);
                info.appendChild(text);

                const actions = document.createElement('div');
                actions.className = 'file-actions';

                const view = document.createElement('a');
                view.href = '#';
                view.innerHTML = '<i class="fa-solid fa-eye"></i>';
                view.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = () => {
                            if (previewImage) previewImage.src = reader.result;
                            if (previewCaption) previewCaption.textContent = file.name;
                            previewModal?.classList.add('active');
                        };
                        reader.readAsDataURL(file);
                    } else {
                        const url = URL.createObjectURL(file);
                        window.open(url, '_blank', 'noopener');
                        setTimeout(() => URL.revokeObjectURL(url), 1000);
                    }
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
            if (!fileInput) return;
            const dt = new DataTransfer();
            selectedFiles.forEach((file) => dt.items.add(file));
            fileInput.files = dt.files;
        };

        const addFiles = (files) => {
            if (!files) return;
            const existing = new Set(selectedFiles.map((file) => `${file.name}-${file.size}-${file.lastModified}`));
            Array.from(files).forEach((file) => {
                const key = `${file.name}-${file.size}-${file.lastModified}`;
                if (existing.has(key)) return;
                if (!isAllowedFile(file)) return;
                if (selectedFiles.length >= maxFiles) return;
                selectedFiles.push(file);
                existing.add(key);
            });
            syncFiles();
            renderFiles();
        };

        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                addFiles(e.target.files);
            });
        }

        if (dropzone) {
            dropzone.addEventListener('click', () => fileInput?.click());
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

        addFilesBtn?.addEventListener('click', () => fileInput?.click());

        closePreviewBtn?.addEventListener('click', () => previewModal?.classList.remove('active'));
        previewModal?.addEventListener('click', (e) => {
            if (e.target === previewModal) previewModal.classList.remove('active');
        });

        const suggestModalRoot = document.getElementById('modalSuggOverlay');
        const recipientSection = suggestModalRoot ? suggestModalRoot.querySelector('[data-recipients-section]') : null;
        if (recipientSection) {
            recipientSection.classList.remove('u-hidden');
        }

        const dropdown = suggestModalRoot ? suggestModalRoot.querySelector('#dropdownContent') : null;
        const toggle = suggestModalRoot ? suggestModalRoot.querySelector('#recipientToggle') : null;
        const searchInput = suggestModalRoot ? suggestModalRoot.querySelector('#mainInput') : null;
        const selectAll = suggestModalRoot ? suggestModalRoot.querySelector('#selectAll') : null;
        const groupChecks = suggestModalRoot ? Array.from(suggestModalRoot.querySelectorAll('.group-item-checkbox')) : [];
        const memberChecks = suggestModalRoot ? Array.from(suggestModalRoot.querySelectorAll('.member-checkbox')) : [];
        const groupItems = suggestModalRoot ? Array.from(suggestModalRoot.querySelectorAll('.dropdown-list .item-group')) : [];
        const categoryGroups = suggestModalRoot ? Array.from(suggestModalRoot.querySelectorAll('.dropdown-list .category-group')) : [];

        const normalizeSearchText = (value) => String(value || '')
            .toLowerCase()
            .replace(/\s+/g, '')
            .replace(/[^0-9a-z\u0E00-\u0E7F]/gi, '');

        const getMemberChecksByGroupKey = (groupKey) => memberChecks.filter((el) => (el.dataset.memberGroupKey || '') === String(groupKey));
        const syncMemberByPid = (pid, checked, source) => {
            const normalizedPid = String(pid || '').trim();
            if (normalizedPid === '') return;
            const isSingleRadio = Boolean(source && source.type === 'radio');
            memberChecks.forEach((memberCheck) => {
                if (memberCheck === source) return;
                if (String(memberCheck.value || '') !== normalizedPid) return;
                if (memberCheck.disabled) return;

                // For single-recipient radio mode, keep the clicked row active only.
                if (isSingleRadio) {
                    memberCheck.checked = false;
                    return;
                }

                memberCheck.checked = checked;
            });
        };
        const setGroupCollapsed = (groupItem, collapsed) => {
            if (!groupItem) return;
            groupItem.classList.toggle('is-collapsed', collapsed);
            const toggleBtn = groupItem.querySelector('.group-toggle');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }
        };

        const setDropdownVisible = (visible) => {
            if (!dropdown) return;
            dropdown.classList.toggle('show', visible);
            toggle?.classList.toggle('active', visible);
        };

        const resetSuggestRecipientDropdown = () => {
            if (searchInput) {
                searchInput.value = '';
            }
            recipientSearchRequestNo++;
            filterRecipientDropdown('');
            groupItems.forEach((groupItem) => {
                const hasCheckedMember = !!groupItem.querySelector('.member-checkbox:checked');
                setGroupCollapsed(groupItem, !hasCheckedMember);
            });
            setDropdownVisible(false);
        };

        toggle?.addEventListener('click', (e) => {
            e.stopPropagation();
            const clickedInput = e.target instanceof HTMLElement && (
                e.target.matches('input.search-input') ||
                !!e.target.closest('input.search-input')
            );
            if (clickedInput) {
                setDropdownVisible(true);
                return;
            }
            setDropdownVisible(!dropdown?.classList.contains('show'));
        });

        document.addEventListener('click', (e) => {
            if (!dropdown) return;
            if (!dropdown.contains(e.target) && !toggle?.contains(e.target)) {
                setDropdownVisible(false);
            }
        });

        groupItems.forEach((groupItem) => {
            const toggleBtn = groupItem.querySelector('.group-toggle');
            toggleBtn?.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const isCollapsed = groupItem.classList.contains('is-collapsed');
                setGroupCollapsed(groupItem, !isCollapsed);
            });
        });

        const filterRecipientDropdown = (rawQuery, remoteMatchedPids = null) => {
            const query = normalizeSearchText(rawQuery);
            groupItems.forEach((groupItem) => {
                const titleEl = groupItem.querySelector('.item-title');
                const titleText = normalizeSearchText(titleEl?.textContent || '');
                const memberRows = Array.from(groupItem.querySelectorAll('.member-sublist li'));
                const isGroupMatch = query !== '' && titleText.includes(query);

                if (query === '') {
                    groupItem.style.display = '';
                    memberRows.forEach((row) => {
                        row.style.display = '';
                    });
                    return;
                }

                let hasMemberMatch = false;
                memberRows.forEach((row) => {
                    const memberCheckbox = row.querySelector('.member-checkbox');
                    const memberPid = String(memberCheckbox?.value || '').trim();
                    const isRemoteMatched = remoteMatchedPids instanceof Set ?
                        remoteMatchedPids.has(memberPid) :
                        null;
                    const rowText = normalizeSearchText(row.textContent || '');
                    const matchedByText = rowText.includes(query);
                    const matched = isGroupMatch || matchedByText || isRemoteMatched === true;
                    row.style.display = matched ? '' : 'none';
                    if (matched) hasMemberMatch = true;
                });

                const isVisible = isGroupMatch || hasMemberMatch;
                groupItem.style.display = isVisible ? '' : 'none';
                if (isVisible) {
                    setGroupCollapsed(groupItem, false);
                }
            });

            categoryGroups.forEach((category) => {
                const hasVisibleItem = Array.from(category.querySelectorAll('.category-items .item-group'))
                    .some((item) => item.style.display !== 'none');
                category.style.display = hasVisibleItem ? '' : 'none';
            });
        };

        let recipientSearchTimer = null;
        let recipientSearchRequestNo = 0;
        const recipientSearchEndpoint = 'public/api/circular-recipient-search.php';

        const requestRecipientSearch = (query) => {
            const requestNo = ++recipientSearchRequestNo;
            const url = `${recipientSearchEndpoint}?q=${encodeURIComponent(query)}`;
            return fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('search_failed');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (requestNo !== recipientSearchRequestNo) {
                        return;
                    }
                    const pids = Array.isArray(payload?.pids) ? payload.pids : [];
                    filterRecipientDropdown(query, new Set(pids.map((pid) => String(pid))));
                })
                .catch(() => {
                    if (requestNo !== recipientSearchRequestNo) {
                        return;
                    }
                    filterRecipientDropdown(query);
                });
        };

        searchInput?.addEventListener('focus', () => {
            setDropdownVisible(true);
        });

        searchInput?.addEventListener('input', () => {
            setDropdownVisible(true);
            const query = String(searchInput.value || '').trim();
            if (recipientSearchTimer) {
                clearTimeout(recipientSearchTimer);
            }
            if (query === '') {
                recipientSearchRequestNo++;
                filterRecipientDropdown('');
                return;
            }
            recipientSearchTimer = window.setTimeout(() => {
                requestRecipientSearch(query);
            }, 180);
        });

        searchInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setDropdownVisible(false);
            }
        });

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

        selectAll?.addEventListener('change', () => {
            const checked = selectAll.checked;
            [...groupChecks, ...memberChecks].forEach((el) => {
                if (!el.disabled) el.checked = checked;
            });
            updateSelectAllState();
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
                const parentGroup = item.closest('.item-group');
                if (item.checked) {
                    setGroupCollapsed(parentGroup, false);
                }
                item.indeterminate = false;
                updateSelectAllState();
            });
        });
        memberChecks.forEach((item) => {
            item.addEventListener('change', () => {
                syncMemberByPid(item.value || '', item.checked, item);
                updateSelectAllState();
                if (item.checked) {
                    setDropdownVisible(false);
                }
            });
        });

        // Keep pre-selected group behavior consistent: when a group is selected,
        // treat all members in that group as selected.
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
        resetSuggestRecipientDropdown();

        const btnSend = document.getElementById('btnSendNotice');
        const confirmModal = document.getElementById('confirmModal');
        const confirmYes = document.getElementById('btnConfirmYes');
        const confirmNo = document.getElementById('btnConfirmNo');

        btnSend?.addEventListener('click', (e) => {
            e.preventDefault();
            confirmModal?.classList.add('active');
        });
        confirmNo?.addEventListener('click', () => confirmModal?.classList.remove('active'));
        confirmModal?.addEventListener('click', (e) => {
            if (e.target === confirmModal) confirmModal.classList.remove('active');
        });
        confirmYes?.addEventListener('click', () => form?.submit());

        const recipientModal = document.getElementById('recipientModal');
        const recipientTableBody = document.getElementById('recipientTableBody');
        const btnShowRecipients = document.getElementById('btnShowRecipients');
        const closeRecipients = document.getElementById('closeModalBtn');

        const renderRecipients = () => {
            if (!recipientTableBody) return;
            recipientTableBody.innerHTML = '';
            const checkedGroups = groupChecks.filter((item) => item.checked);
            const checkedMembers = memberChecks.filter((item) => item.checked);
            if (checkedGroups.length === 0 && checkedMembers.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan=\"3\" style=\"text-align:center; padding: 16px;\">ไม่มีผู้รับที่เลือก</td>';
                recipientTableBody.appendChild(row);
                return;
            }

            const recipientsMap = new Map();
            const addRecipient = (pid, name, faction) => {
                const key = String(pid || '').trim();
                if (key === '') return;
                if (recipientsMap.has(key)) return;
                recipientsMap.set(key, {
                    pid: key,
                    name: (name || '-').trim() || '-',
                    faction: (faction || '-').trim() || '-',
                });
            };

            checkedGroups.forEach((item) => {
                let members = [];
                try {
                    members = JSON.parse(item.getAttribute('data-members') || '[]');
                } catch (error) {
                    members = [];
                }
                if (!Array.isArray(members)) return;
                members.forEach((member) => {
                    addRecipient(member && member.pID ? String(member.pID) : '', member && member.name ? String(member.name) : '-', item.getAttribute('data-group-label') || '-');
                });
            });

            checkedMembers.forEach((item) => {
                addRecipient(item.value || '', item.getAttribute('data-member-name') || '-', item.getAttribute('data-group-label') || '-');
            });

            const uniqueRecipients = Array.from(recipientsMap.values());
            uniqueRecipients.sort((a, b) => {
                if (a.faction === b.faction) {
                    return a.name.localeCompare(b.name, 'th');
                }
                return a.faction.localeCompare(b.faction, 'th');
            });

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
        closeRecipients?.addEventListener('click', () => recipientModal?.classList.remove('active'));
        recipientModal?.addEventListener('click', (e) => {
            if (e.target === recipientModal) recipientModal.classList.remove('active');
        });

    });



    const viewModal = document.getElementById('modalViewOverlay');
    const suggModal = document.getElementById('modalSuggOverlay');

    const closeViewBtn = document.getElementById('closeModalView');
    const closeSuggBtn = document.getElementById('closeModalSugg');
    const memoSuggestSubmitButton = document.getElementById('memoSuggestSubmitButton');

    const openViewBtns = document.querySelectorAll('.js-open-view-modal');
    const openSuggBtns = document.querySelectorAll('.js-open-suggest-modal');
    const memoViewSenderRow = viewModal ? viewModal.querySelector('#memoViewSenderRow') : null;
    const memoViewSenderInput = viewModal ? viewModal.querySelector('#memoViewSenderFaction') : null;
    const memoViewSubjectRow = viewModal ? viewModal.querySelector('#memoViewSubjectRow') : null;
    const memoViewSubjectInput = viewModal ? viewModal.querySelector('#memoViewSubject') : null;
    const memoViewToRow = viewModal ? viewModal.querySelector('#memoViewToRow') : null;
    const memoViewToLabel = viewModal ? viewModal.querySelector('#memoViewToLabel') : null;
    const memoViewDetailWrap = viewModal ? viewModal.querySelector('#memoViewDetailWrap') : null;
    const memoViewDetailInput = viewModal ? viewModal.querySelector('#memo_editor_view') : null;
    const memoViewFileRow = viewModal ? viewModal.querySelector('#memoViewFileRow') : null;
    const memoViewFileList = viewModal ? viewModal.querySelector('#attachmentListView') : null;
    const memoViewOptionalBlocks = viewModal ? Array.from(viewModal.querySelectorAll('[data-memo-optional="1"]')) : [];
    const memoViewHeadNoteRow = viewModal ? viewModal.querySelector('#memoViewHeadNoteRow') : null;
    const memoViewHeadNote = viewModal ? viewModal.querySelector('#memoViewHeadNote') : null;
    const memoViewHeadSignatureRow = viewModal ? viewModal.querySelector('#memoViewHeadSignatureRow') : null;
    const memoViewHeadSignatureImage = viewModal ? viewModal.querySelector('#memoViewHeadSignatureImage') : null;
    const memoViewHeadSignatureName = viewModal ? viewModal.querySelector('#memoViewHeadSignatureName') : null;
    const memoViewHeadSignaturePosition = viewModal ? viewModal.querySelector('#memoViewHeadSignaturePosition') : null;
    const memoViewDeputyActionRow = viewModal ? viewModal.querySelector('#memoViewDeputyActionRow') : null;
    const memoViewDeputyAction = viewModal ? viewModal.querySelector('#memoViewDeputyAction') : null;
    const memoViewDeputyNoteRow = viewModal ? viewModal.querySelector('#memoViewDeputyNoteRow') : null;
    const memoViewDeputyNote = viewModal ? viewModal.querySelector('#memoViewDeputyNote') : null;
    const memoViewDeputySignatureRow = viewModal ? viewModal.querySelector('#memoViewDeputySignatureRow') : null;
    const memoViewDeputySignatureImage = viewModal ? viewModal.querySelector('#memoViewDeputySignatureImage') : null;
    const memoViewDeputySignatureName = viewModal ? viewModal.querySelector('#memoViewDeputySignatureName') : null;
    const memoViewDeputySignaturePosition = viewModal ? viewModal.querySelector('#memoViewDeputySignaturePosition') : null;
    const memoViewDirectorActionRow = viewModal ? viewModal.querySelector('#memoViewDirectorActionRow') : null;
    const memoViewDirectorAction = viewModal ? viewModal.querySelector('#memoViewDirectorAction') : null;
    const memoViewDirectorNoteRow = viewModal ? viewModal.querySelector('#memoViewDirectorNoteRow') : null;
    const memoViewDirectorNote = viewModal ? viewModal.querySelector('#memoViewDirectorNote') : null;
    const memoViewDirectorSignatureRow = viewModal ? viewModal.querySelector('#memoViewDirectorSignatureRow') : null;
    const memoViewDirectorSignatureImage = viewModal ? viewModal.querySelector('#memoViewDirectorSignatureImage') : null;
    const memoViewDirectorSignatureName = viewModal ? viewModal.querySelector('#memoViewDirectorSignatureName') : null;
    const memoViewDirectorSignaturePosition = viewModal ? viewModal.querySelector('#memoViewDirectorSignaturePosition') : null;
    const suggestSubjectInput = suggModal ? suggModal.querySelector('[data-memo-suggest-subject]') : null;
    const suggestDetailInput = suggModal ? suggModal.querySelector('[data-memo-suggest-detail]') : null;
    const suggestToText = suggModal ? suggModal.querySelector('[data-memo-suggest-to]') : null;
    const suggestToChoiceInput = suggModal ? suggModal.querySelector('#memoSuggestToChoice') : null;
    const suggestMemoIdInput = suggModal ? suggModal.querySelector('#memoSuggestMemoId') : null;
    const suggestForm = document.getElementById('memoSuggestForm');
    const suggestSenderInput = suggestForm ? suggestForm.querySelector('input[name="sender_fid"]') : null;
    const suggestSenderWrapper = suggestSenderInput ? suggestSenderInput.closest('.custom-select-wrapper') : null;
    const suggestSenderValue = suggestSenderWrapper ? suggestSenderWrapper.querySelector('.select-value') : null;
    const suggestSenderOptions = suggestSenderWrapper ? Array.from(suggestSenderWrapper.querySelectorAll('.custom-option')) : [];
    const suggestAttachmentInput = suggestForm ? suggestForm.querySelector('input[name="attachments[]"]') : null;
    const suggestAttachmentList = suggestForm ? suggestForm.querySelector('#attachmentList') : null;
    const suggestRecipientRadios = suggModal ? Array.from(suggModal.querySelectorAll('.member-checkbox')) : [];
    const memoDirectorLabel = <?= json_encode($memo_director_label, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const showSuggestValidationAlert = (message) => {
        const safeMessage = String(message || '').trim();

        if (safeMessage === '') {
            return;
        }

        if (window.AppAlerts && typeof window.AppAlerts.fire === 'function') {
            window.AppAlerts.fire({
                type: 'warning',
                title: 'ข้อมูลไม่ครบถ้วน',
                message: safeMessage,
                confirmButtonText: 'ตกลง',
            });
            return;
        }

        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'warning',
                title: 'ข้อมูลไม่ครบถ้วน',
                text: safeMessage,
                confirmButtonText: 'ตกลง',
            });
            return;
        }

        alert(safeMessage);
    };
    const setSuggestSenderFaction = (senderFid, senderName) => {
        const fid = String(senderFid || '').trim();
        const fallbackName = String(senderName || '').trim();
        let selectedName = '';

        if (suggestSenderInput) {
            suggestSenderInput.value = fid;
        }

        suggestSenderOptions.forEach((option) => {
            const isSelected = fid !== '' && String(option.getAttribute('data-value') || '').trim() === fid;
            option.classList.toggle('selected', isSelected);

            if (isSelected) {
                selectedName = String(option.textContent || '').trim();
            }
        });

        if (suggestSenderValue) {
            const displayName = selectedName !== '' ? selectedName : fallbackName;
            suggestSenderValue.textContent = displayName !== '' && displayName !== '-' ?
                displayName :
                'เลือกส่วนราชการ';
        }
    };
    const confirmSuggestSubmit = () => {
        const message = 'ยืนยันการเสนอแฟ้มบันทึกข้อความใช่หรือไม่?';

        if (window.AppAlerts && typeof window.AppAlerts.confirm === 'function') {
            return window.AppAlerts.confirm(message, {
                title: 'ยืนยันการเสนอแฟ้ม',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
            });
        }

        return Promise.resolve(window.confirm('ยืนยันการเสนอแฟ้ม\n' + message));
    };
    const resetSuggestRecipientDropdownUi = () => {
        if (!suggModal) {
            return;
        }

        const dropdown = suggModal.querySelector('#dropdownContent');
        const toggle = suggModal.querySelector('#recipientToggle');
        const searchInput = suggModal.querySelector('#mainInput');
        const groupItems = Array.from(suggModal.querySelectorAll('.dropdown-list .item-group'));
        const categoryGroups = Array.from(suggModal.querySelectorAll('.dropdown-list .category-group'));

        if (searchInput) {
            searchInput.value = '';
        }

        groupItems.forEach((groupItem) => {
            groupItem.style.display = '';

            const memberRows = Array.from(groupItem.querySelectorAll('.member-sublist li'));
            memberRows.forEach((row) => {
                row.style.display = '';
            });

            const hasCheckedMember = !!groupItem.querySelector('.member-checkbox:checked');
            groupItem.classList.toggle('is-collapsed', !hasCheckedMember);
            const toggleBtn = groupItem.querySelector('.group-toggle');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', hasCheckedMember ? 'true' : 'false');
            }
        });

        categoryGroups.forEach((category) => {
            category.style.display = '';
        });

        dropdown?.classList.remove('show');
        toggle?.classList.remove('active');
    };
    const syncSuggestRecipientSelection = () => {
        const selectedRadio = suggestRecipientRadios.find((radio) => radio.checked) || null;
        const selectedPid = selectedRadio ? String(selectedRadio.value || '').trim() : '';
        const selectedName = selectedRadio ? String(selectedRadio.getAttribute('data-member-name') || '').trim() : '';

        if (suggestToChoiceInput) {
            suggestToChoiceInput.value = selectedPid !== '' ? `PERSON:${selectedPid}` : 'DIRECTOR';
        }
        if (suggestToText) {
            suggestToText.textContent = selectedName !== '' ? selectedName : memoDirectorLabel;
        }
    };

    const refreshSuggestAttachmentList = (files, memoId, shouldReset = false) => {
        if (suggestAttachmentList) {
            suggestAttachmentList.dataset.existingFiles = JSON.stringify(Array.isArray(files) ? files : []);
            suggestAttachmentList.dataset.entityId = String(memoId || '').trim();
            suggestAttachmentList.dataset.module = 'memos';
        }

        window.dispatchEvent(new CustomEvent('app:attachment-list:refresh', {
            detail: {
                reset: shouldReset,
            },
        }));
    };

    const normalizeMemoText = (value) => {
        const raw = String(value || '').trim();

        if (raw === '' || raw === '-') {
            return '';
        }

        const parser = document.createElement('div');
        parser.innerHTML = raw;
        const text = String(parser.textContent || parser.innerText || '')
            .replace(/\u00A0/g, ' ')
            .trim();

        return text !== '' ? text : raw;
    };

    const normalizeMemoDetailText = (value) => {
        const raw = String(value || '').trim();

        if (raw === '' || raw === '-') {
            return '';
        }

        const normalizePlainText = (input) => String(input || '')
            .replace(/\u00A0/g, ' ')
            .replace(/\r\n?/g, '\n')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n[ \t]+/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .split('\n')
            .map((line) => line.replace(/[ \t]+$/g, ''))
            .join('\n')
            .trim();

        if (!/<[^>]+>/.test(raw)) {
            const decoder = document.createElement('textarea');
            decoder.innerHTML = raw;
            return normalizePlainText(decoder.value);
        }

        const parser = new DOMParser();
        const doc = parser.parseFromString(`<div>${raw}</div>`, 'text/html');
        const root = doc.body.firstElementChild || doc.body;
        const blockTags = new Set(['p', 'div', 'section', 'article', 'header', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6']);

        const getTextFromChildren = (node, renderNode) => Array.from(node.childNodes)
            .map((childNode) => renderNode(childNode))
            .join('');

        const renderNode = (node) => {
            if (!node) {
                return '';
            }

            if (node.nodeType === Node.TEXT_NODE) {
                return String(node.textContent || '');
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                return '';
            }

            const tag = String(node.tagName || '').toLowerCase();

            if (tag === 'br') {
                return '\n';
            }

            if (tag === 'table') {
                const rows = Array.from(node.querySelectorAll('tr'))
                    .map((row) => {
                        const cells = Array.from(row.querySelectorAll('th,td'))
                            .map((cell) => normalizePlainText(getTextFromChildren(cell, renderNode)).replace(/\n+/g, ' '))
                            .filter((cellText) => cellText !== '');
                        return cells.join(' | ');
                    })
                    .filter((line) => line !== '');

                return rows.length > 0 ? `${rows.join('\n')}\n\n` : '';
            }

            if (tag === 'ol' || tag === 'ul') {
                const listItems = Array.from(node.children)
                    .filter((child) => String(child.tagName || '').toLowerCase() === 'li');

                if (listItems.length === 0) {
                    const fallback = normalizePlainText(getTextFromChildren(node, renderNode));
                    return fallback !== '' ? `${fallback}\n\n` : '';
                }

                const renderedItems = listItems
                    .map((listItem, index) => {
                        const itemText = normalizePlainText(getTextFromChildren(listItem, renderNode));

                        if (itemText === '') {
                            return '';
                        }

                        const itemLines = itemText
                            .split('\n')
                            .map((line) => line.trim())
                            .filter((line) => line !== '');

                        if (itemLines.length === 0) {
                            return '';
                        }

                        const prefix = tag === 'ol' ? `${index + 1}. ` : '• ';
                        const firstLine = `${prefix}${itemLines[0]}`;
                        const nestedLines = itemLines.slice(1).map((line) => `   ${line}`);

                        return [firstLine, ...nestedLines].join('\n');
                    })
                    .filter((line) => line !== '');

                return renderedItems.length > 0 ? `${renderedItems.join('\n')}\n\n` : '';
            }

            if (tag === 'li') {
                const liText = normalizePlainText(getTextFromChildren(node, renderNode));
                return liText !== '' ? `${liText}\n` : '';
            }

            const content = getTextFromChildren(node, renderNode);

            if (blockTags.has(tag)) {
                const blockText = normalizePlainText(content);
                return blockText !== '' ? `${blockText}\n\n` : '';
            }

            return content;
        };

        const rendered = normalizePlainText(getTextFromChildren(root, renderNode));
        return rendered;
    };

    const decodeBase64Utf8 = (base64Value) => {
        const payload = String(base64Value || '').trim();

        if (payload === '') {
            return '';
        }

        try {
            const binary = window.atob(payload);
            const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
            return new TextDecoder('utf-8').decode(bytes);
        } catch (error) {
            try {
                return decodeURIComponent(escape(window.atob(payload)));
            } catch (_fallbackError) {
                return '';
            }
        }
    };

    const isMemoViewFormControl = (element) => {
        if (!element) {
            return false;
        }

        const tagName = String(element.tagName || '').toUpperCase();
        return tagName === 'TEXTAREA' || tagName === 'INPUT';
    };

    const setMemoViewTextField = (element, value) => {
        if (!element) {
            return;
        }

        const content = String(value || '').trim() || '-';

        if (isMemoViewFormControl(element)) {
            element.value = content;
            return;
        }

        element.textContent = content;
    };

    const autoResizeTextarea = (textarea) => {
        if (!textarea || String(textarea.tagName || '').toUpperCase() !== 'TEXTAREA') {
            return;
        }

        textarea.style.minHeight = '0px';
        textarea.style.height = 'auto';
        const nextHeight = Math.max(textarea.scrollHeight, 120);
        textarea.style.height = `${nextHeight}px`;
        textarea.style.overflowY = 'hidden';
    };

    const setMemoReadonlyEditorContent = (textarea, value) => {
        if (!textarea) {
            return;
        }

        const content = String(value || '').trim();

        if (!isMemoViewFormControl(textarea)) {
            textarea.textContent = content !== '' ? content : '-';
            return;
        }

        textarea.value = content;

        if (!window.tinymce || typeof window.tinymce.get !== 'function' || textarea.id === '') {
            return;
        }

        const editor = tinymce.get(textarea.id);

        if (!editor) {
            return;
        }

        const html = content !== '' && content !== '-' ?
            content.replace(/\n/g, '<br>') :
            content;
        editor.setContent(html);
        editor.mode.set('readonly');
    };

    const formatMemoViewSignatureName = (value) => {
        const cleanValue = String(value || '').replace(/^\(|\)$/g, '').trim();
        return '(' + (cleanValue || '-') + ')';
    };

    const formatMemoViewPosition = (value) => {
        const cleanValue = String(value || '').trim();
        const normalizedValue = typeof cleanValue.normalize === 'function' ?
            cleanValue.normalize('NFC') :
            cleanValue.replace('อํานวย', 'อำนวย');

        if (normalizedValue === 'ผู้อำนวยการโรงเรียน') {
            return 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน';
        }

        return cleanValue || '-';
    };

    const memoViewActionLabelMap = {
        FORWARD: 'เสนอผู้อำนวยการ',
        APPROVE_UNSIGNED: 'ลงนามแล้ว',
        RETURN: 'กลับไปแก้ไข',
        REJECT: 'ไม่อนุมัติ',
        SIGN: 'ลงนามแล้ว',
        DIRECTOR_SIGNED: 'ลงนามแล้ว',
        DIRECTOR_ACKNOWLEDGED: 'ทราบ',
        DIRECTOR_AGREED: 'ชอบ',
        DIRECTOR_NOTIFIED: 'แจ้ง',
        DIRECTOR_ASSIGNED: 'มอบ',
        DIRECTOR_SCHEDULED: 'ลงนัด',
        DIRECTOR_PERMITTED: 'อนุญาต',
        DIRECTOR_APPROVED: 'อนุมัติ',
        DIRECTOR_REJECTED: 'ไม่อนุมัติ',
        DIRECTOR_REQUEST_MEETING: 'ขอพบ',
        DIRECTOR_APPROVE: 'อนุมัติ',
        DIRECTOR_REJECT: 'ไม่อนุมัติ',
    };

    const setMemoViewSignatureBlock = (row, image, nameEl, positionEl, payload) => {
        const signature = String(payload?.signature || '').trim();
        const name = String(payload?.name || '').trim();
        const position = String(payload?.position || '').trim();
        const hasReviewData = Boolean(String(payload?.action || '').trim() || normalizeMemoDetailText(payload?.note || ''));
        const shouldShow = hasReviewData && signature !== '';

        if (image) {
            if (shouldShow) {
                image.setAttribute('src', signature);
                image.style.display = '';
            } else {
                image.removeAttribute('src');
                image.style.display = 'none';
            }
        }

        if (nameEl) {
            nameEl.textContent = formatMemoViewSignatureName(name);
        }

        if (positionEl) {
            positionEl.textContent = formatMemoViewPosition(position);
        }

        setMemoViewVisible(row, shouldShow);
    };

    const setMemoViewTextarea = (row, textarea, value, forceVisible = false) => {
        const normalized = normalizeMemoDetailText(value);
        const shouldShow = normalized !== '' || forceVisible;
        const displayValue = normalized !== '' ? normalized : '-';

        setMemoReadonlyEditorContent(textarea, displayValue);

        setMemoViewVisible(row, shouldShow);

        if (shouldShow && (!window.tinymce || typeof window.tinymce.get !== 'function' || !textarea || !tinymce.get(textarea.id))) {
            autoResizeTextarea(textarea);
        }
    };

    const setMemoViewActionField = (row, input, value) => {
        const actionKey = String(value || '').trim().toUpperCase();
        const label = memoViewActionLabelMap[actionKey] || '';

        if (input) {
            input.value = label;
        }

        setMemoViewVisible(row, label !== '');
    };

    const readMemoViewStagePayload = (button, prefix) => {
        const key = String(prefix || '').trim();
        const noteFromBase64 = decodeBase64Utf8(button.getAttribute('data-' + key + '-note-b64'));
        return {
            name: String(button.getAttribute('data-' + key + '-name') || '').trim(),
            position: String(button.getAttribute('data-' + key + '-position') || '').trim(),
            signature: String(button.getAttribute('data-' + key + '-signature') || '').trim(),
            note: noteFromBase64 !== '' ? noteFromBase64 : String(button.getAttribute('data-' + key + '-note') || ''),
            action: String(button.getAttribute('data-' + key + '-action') || '').trim(),
        };
    };

    const hasMemoViewStagePayload = (payload) => {
        return Boolean(
            String(payload?.action || '').trim() ||
            normalizeMemoDetailText(payload?.note || '')
        );
    };

    const setMemoViewVisible = (element, visible) => {
        if (!element) {
            return;
        }
        element.classList.toggle('u-hidden', !visible);
    };

    const renderMemoViewFiles = (files, memoId) => {
        if (!memoViewFileRow || !memoViewFileList) {
            return;
        }

        memoViewFileList.innerHTML = '';
        const memoEntityId = String(memoId || '').trim();
        const normalizedFiles = Array.isArray(files) ? files : [];

        if (memoEntityId === '' || normalizedFiles.length === 0) {
            setMemoViewVisible(memoViewFileRow, true);
            memoViewFileList.innerHTML = '<p class="attachment-empty">ยังไม่มีไฟล์แนบ</p>';
            return;
        }

        setMemoViewVisible(memoViewFileRow, true);
        normalizedFiles.forEach((file) => {
            const fileId = String(file?.fileID || '').trim();
            const fileName = String(file?.fileName || '').trim();
            const mimeType = String(file?.mimeType || '').trim();

            if (fileId === '' || fileName === '') {
                return;
            }

            const fileBanner = document.createElement('div');
            fileBanner.className = 'file-banner';

            const fileInfo = document.createElement('div');
            fileInfo.className = 'file-info';

            const fileIcon = document.createElement('div');
            fileIcon.className = 'file-icon';

            const icon = document.createElement('i');
            const mimeLower = mimeType.toLowerCase();
            if (mimeLower.includes('pdf')) {
                icon.className = 'fa-solid fa-file-pdf';
            } else if (mimeLower.includes('image')) {
                icon.className = 'fa-solid fa-file-image';
            } else {
                icon.className = 'fa-solid fa-file';
            }
            fileIcon.appendChild(icon);

            const fileText = document.createElement('div');
            fileText.className = 'file-text';
            const fileNameEl = document.createElement('span');
            fileNameEl.className = 'file-name';
            fileNameEl.textContent = fileName;
            const fileTypeEl = document.createElement('span');
            fileTypeEl.className = 'file-type';
            fileTypeEl.textContent = mimeType !== '' ? mimeType : '-';
            fileText.appendChild(fileNameEl);
            fileText.appendChild(fileTypeEl);

            fileInfo.appendChild(fileIcon);
            fileInfo.appendChild(fileText);

            const viewAction = document.createElement('div');
            viewAction.className = 'file-actions';
            const viewLink = document.createElement('a');
            viewLink.href = 'public/api/file-download.php?module=memos&entity_id=' +
                encodeURIComponent(memoEntityId) +
                '&file_id=' + encodeURIComponent(fileId);
            viewLink.target = '_blank';
            viewLink.rel = 'noopener';
            viewLink.innerHTML = '<i class="fa-solid fa-eye"></i>';
            viewAction.appendChild(viewLink);

            const downloadAction = document.createElement('div');
            downloadAction.className = 'file-actions';
            const downloadLink = document.createElement('a');
            downloadLink.href = 'public/api/file-download.php?module=memos&entity_id=' +
                encodeURIComponent(memoEntityId) +
                '&file_id=' + encodeURIComponent(fileId) +
                '&download=1';
            downloadLink.innerHTML = '<i class="fa-solid fa-download"></i>';
            downloadAction.appendChild(downloadLink);

            fileBanner.appendChild(fileInfo);
            fileBanner.appendChild(viewAction);
            fileBanner.appendChild(downloadAction);
            memoViewFileList.appendChild(fileBanner);
        });

        if (!memoViewFileList.children.length) {
            setMemoViewVisible(memoViewFileRow, true);
            memoViewFileList.innerHTML = '<p class="attachment-empty">ยังไม่มีไฟล์แนบ</p>';
        }
    };

    openViewBtns.forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            const memoId = String(btn.getAttribute('data-circular-id') || '').trim();
            const senderText = normalizeMemoText(btn.getAttribute('data-sender'));
            const subjectText = normalizeMemoText(btn.getAttribute('data-subject'));
            const toText = normalizeMemoText(btn.getAttribute('data-to'));
            const detailRawFromBase64 = decodeBase64Utf8(btn.getAttribute('data-detail-b64'));
            const detailRaw = detailRawFromBase64 !== '' ? detailRawFromBase64 : String(btn.getAttribute('data-detail') || '');
            const detailText = normalizeMemoDetailText(detailRaw);
            let files = [];

            try {
                files = JSON.parse(String(btn.getAttribute('data-files') || '[]'));
            } catch (error) {
                files = [];
            }

            setMemoViewTextField(memoViewSenderInput, senderText);
            setMemoViewTextField(memoViewSubjectInput, subjectText);
            if (memoViewToLabel) {
                memoViewToLabel.textContent = toText !== '' ? toText : memoDirectorLabel;
            }
            if (memoViewDetailInput) {
                setMemoReadonlyEditorContent(memoViewDetailInput, detailText);
            }

            setMemoViewVisible(memoViewSenderRow, senderText !== '');
            setMemoViewVisible(memoViewSubjectRow, true);
            setMemoViewVisible(memoViewToRow, (toText !== '' || memoDirectorLabel !== ''));
            setMemoViewVisible(memoViewDetailWrap, true);

            memoViewOptionalBlocks.forEach((block) => {
                block.classList.add('u-hidden');
            });

            const headPayload = readMemoViewStagePayload(btn, 'head');
            const deputyPayload = readMemoViewStagePayload(btn, 'deputy');
            const directorPayload = readMemoViewStagePayload(btn, 'director');
            const hasHeadStage = hasMemoViewStagePayload(headPayload);
            const hasDeputyStage = hasMemoViewStagePayload(deputyPayload);
            const hasDirectorStage = hasMemoViewStagePayload(directorPayload);

            setMemoViewTextarea(memoViewHeadNoteRow, memoViewHeadNote, headPayload.note, hasHeadStage);
            setMemoViewSignatureBlock(
                memoViewHeadSignatureRow,
                memoViewHeadSignatureImage,
                memoViewHeadSignatureName,
                memoViewHeadSignaturePosition,
                headPayload
            );

            setMemoViewActionField(memoViewDeputyActionRow, memoViewDeputyAction, deputyPayload.action);
            setMemoViewTextarea(memoViewDeputyNoteRow, memoViewDeputyNote, deputyPayload.note, hasDeputyStage);
            setMemoViewSignatureBlock(
                memoViewDeputySignatureRow,
                memoViewDeputySignatureImage,
                memoViewDeputySignatureName,
                memoViewDeputySignaturePosition,
                deputyPayload
            );

            setMemoViewActionField(memoViewDirectorActionRow, memoViewDirectorAction, directorPayload.action);
            setMemoViewTextarea(memoViewDirectorNoteRow, memoViewDirectorNote, directorPayload.note, hasDirectorStage);
            setMemoViewSignatureBlock(
                memoViewDirectorSignatureRow,
                memoViewDirectorSignatureImage,
                memoViewDirectorSignatureName,
                memoViewDirectorSignaturePosition,
                directorPayload
            );

            renderMemoViewFiles(files, memoId);

            if (viewModal) {
                viewModal.style.display = 'flex';

                // Recalculate after modal becomes visible; hidden elements report wrong scrollHeight.
                window.requestAnimationFrame(() => {
                    autoResizeTextarea(memoViewDetailInput);
                    autoResizeTextarea(memoViewHeadNote);
                    autoResizeTextarea(memoViewDeputyNote);
                    autoResizeTextarea(memoViewDirectorNote);
                });
            }
        });
    });

    openSuggBtns.forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();

            const memoId = String(btn.getAttribute('data-memo-id') || '').trim();
            const memoSubject = String(btn.getAttribute('data-memo-subject') || '').trim();
            const memoDetail = String(btn.getAttribute('data-memo-detail') || '').trim();
            const memoAttachmentCount = Number(btn.getAttribute('data-memo-attachments') || '0');
            const memoSenderFid = String(btn.getAttribute('data-memo-sender-fid') || '').trim();
            const memoSenderName = String(btn.getAttribute('data-memo-sender-name') || '').trim();
            const isOwnerEditBeforeHeadForward = String(btn.getAttribute('data-memo-owner-edit-before-head-forward') || '') === '1';
            const isReturnedResubmit = String(btn.getAttribute('data-memo-is-returned') || '') === '1';
            const returnedReviewerPid = String(btn.getAttribute('data-memo-returned-reviewer-pid') || '').trim();
            const returnedReviewerName = String(btn.getAttribute('data-memo-returned-reviewer-name') || '').trim();
            let memoFiles = [];
            const detailValue = memoDetail !== '' ? memoDetail : '-';

            try {
                memoFiles = JSON.parse(String(btn.getAttribute('data-files') || '[]'));
            } catch (error) {
                memoFiles = [];
            }

            if (suggestMemoIdInput) {
                suggestMemoIdInput.value = memoId;
            }

            if (suggestSubjectInput) {
                suggestSubjectInput.value = memoSubject !== '' ? memoSubject : '-';
            }

            if (suggestDetailInput) {
                suggestDetailInput.value = detailValue;
            }

            if (suggestToText) {
                suggestToText.textContent = memoDirectorLabel;
            }
            if (suggestToChoiceInput) {
                suggestToChoiceInput.value = 'DIRECTOR';
            }
            setSuggestSenderFaction(memoSenderFid, memoSenderName);
            if (suggestForm) {
                const normalizedAttachmentCount = memoFiles.length > 0 ?
                    memoFiles.length :
                    (Number.isFinite(memoAttachmentCount) && memoAttachmentCount > 0 ? memoAttachmentCount : 0);
                suggestForm.dataset.existingAttachmentCount = String(normalizedAttachmentCount);
                suggestForm.dataset.ownerEditBeforeHeadForward = isOwnerEditBeforeHeadForward ? '1' : '0';
                suggestForm.dataset.returnedResubmit = isReturnedResubmit ? '1' : '0';
            }
            if (suggestAttachmentInput) {
                suggestAttachmentInput.value = '';
            }
            refreshSuggestAttachmentList(memoFiles, memoId, true);
            suggestRecipientRadios.forEach((radio) => {
                radio.checked = false;
            });

            let returnedReviewerRadio = null;

            if (isReturnedResubmit && returnedReviewerPid !== '') {
                returnedReviewerRadio = suggestRecipientRadios.find((radio) => String(radio.value || '').trim() === returnedReviewerPid) || null;

                if (returnedReviewerRadio) {
                    returnedReviewerRadio.checked = true;
                }
            }
            resetSuggestRecipientDropdownUi();

            const suggestSearchInput = suggModal ? suggModal.querySelector('#mainInput') : null;
            const selectedReturnedName = returnedReviewerName !== '' ?
                returnedReviewerName :
                String(returnedReviewerRadio?.getAttribute('data-member-name') || '').trim();

            if (isReturnedResubmit && selectedReturnedName !== '' && suggestSearchInput) {
                suggestSearchInput.value = selectedReturnedName;
            }

            if (window.tinymce) {
                const suggestEditor = tinymce.get('memo_editor_suggest');
                if (suggestEditor) {
                    suggestEditor.setContent(detailValue);
                }
            }
            syncSuggestRecipientSelection();

            if (isReturnedResubmit && returnedReviewerPid !== '' && !returnedReviewerRadio) {
                if (suggestToChoiceInput) {
                    suggestToChoiceInput.value = `PERSON:${returnedReviewerPid}`;
                }
                if (suggestToText) {
                    suggestToText.textContent = selectedReturnedName !== '' ? selectedReturnedName : memoDirectorLabel;
                }
            }

            if (suggModal) suggModal.style.display = 'flex';
        });
    });

    suggestRecipientRadios.forEach((radio) => {
        radio.addEventListener('change', () => {
            syncSuggestRecipientSelection();
        });
    });

    let suggestSubmitConfirmed = false;

    suggestForm?.addEventListener('submit', (event) => {
        const selectedRadio = suggestRecipientRadios.find((radio) => radio.checked) || null;
        const isOwnerEditBeforeHeadForward = suggestForm.dataset.ownerEditBeforeHeadForward === '1';
        const isReturnedResubmit = suggestForm.dataset.returnedResubmit === '1';

        if (!isOwnerEditBeforeHeadForward && !isReturnedResubmit && !selectedRadio) {
            event.preventDefault();
            showSuggestValidationAlert('กรุณาเลือกผู้รับเอกสารอย่างน้อย 1 คน');
            return;
        }

        if (suggestSubmitConfirmed) {
            suggestSubmitConfirmed = false;
            return;
        }

        event.preventDefault();

        confirmSuggestSubmit().then((confirmed) => {
            if (!confirmed) {
                return;
            }

            if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                window.tinymce.triggerSave();
            }

            suggestSubmitConfirmed = true;

            if (memoSuggestSubmitButton && typeof memoSuggestSubmitButton.click === 'function') {
                memoSuggestSubmitButton.click();
                return;
            }

            if (typeof suggestForm.requestSubmit === 'function') {
                suggestForm.requestSubmit();
                return;
            }

            suggestForm.submit();
        }).catch(() => {
            suggestSubmitConfirmed = false;
        });
    });

    closeViewBtn?.addEventListener('click', () => {
        if (viewModal) viewModal.style.display = 'none';
    });

    closeSuggBtn?.addEventListener('click', () => {
        if (suggModal) suggModal.style.display = 'none';
        refreshSuggestAttachmentList([], '', true);
        resetSuggestRecipientDropdownUi();
    });

    window.addEventListener('click', (event) => {
        if (event.target === viewModal) {
            viewModal.style.display = 'none';
        }
        if (event.target === suggModal) {
            suggModal.style.display = 'none';
            refreshSuggestAttachmentList([], '', true);
            resetSuggestRecipientDropdownUi();
        }
    });


    const recipientModal = document.getElementById('recipientModal');
    const recipientTableBody = document.getElementById('recipientTableBody');
    const btnShowRecipients = document.getElementById('btnShowRecipients');
    const closeRecipients = document.getElementById('closeModalBtn');

    const renderRecipients = () => {
        if (!recipientTableBody) return;
        recipientTableBody.innerHTML = '';
        const checkedGroups = groupChecks.filter((item) => item.checked);
        const checkedMembers = memberChecks.filter((item) => item.checked);
        if (checkedGroups.length === 0 && checkedMembers.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan=\"3\" style=\"text-align:center; padding: 16px;\">ไม่มีผู้รับที่เลือก</td>';
            recipientTableBody.appendChild(row);
            return;
        }

        const recipientsMap = new Map();
        const addRecipient = (pid, name, faction) => {
            const key = String(pid || '').trim();
            if (key === '') return;
            if (recipientsMap.has(key)) return;
            recipientsMap.set(key, {
                pid: key,
                name: (name || '-').trim() || '-',
                faction: (faction || '-').trim() || '-',
            });
        };

        checkedGroups.forEach((item) => {
            let members = [];
            try {
                members = JSON.parse(item.getAttribute('data-members') || '[]');
            } catch (error) {
                members = [];
            }
            if (!Array.isArray(members)) return;
            members.forEach((member) => {
                addRecipient(member && member.pID ? String(member.pID) : '', member && member.name ? String(member.name) : '-', item.getAttribute('data-group-label') || '-');
            });
        });

        checkedMembers.forEach((item) => {
            addRecipient(item.value || '', item.getAttribute('data-member-name') || '-', item.getAttribute('data-group-label') || '-');
        });

        const uniqueRecipients = Array.from(recipientsMap.values());
        uniqueRecipients.sort((a, b) => {
            if (a.faction === b.faction) {
                return a.name.localeCompare(b.name, 'th');
            }
            return a.faction.localeCompare(b.faction, 'th');
        });

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
    closeRecipients?.addEventListener('click', () => recipientModal?.classList.remove('active'));
    recipientModal?.addEventListener('click', (e) => {
        if (e.target === recipientModal) recipientModal.classList.remove('active');
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
