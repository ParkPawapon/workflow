<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../view.php';
require_once __DIR__ . '/../../rbac/current_user.php';

$values = $values ?? [];
$factions = $factions ?? [];
$teachers = $teachers ?? [];
$sent_items = (array) ($sent_items ?? []);
$filter_query = (string) ($filter_query ?? '');
$filter_status = (string) ($filter_status ?? 'all');
$filter_sort = (string) ($filter_sort ?? 'newest');
$page = (int) ($page ?? 1);
$per_page = (int) ($per_page ?? 10);
$total_pages = (int) ($total_pages ?? 1);
$filtered_total = (int) ($filtered_total ?? count($sent_items));
$query_params = (array) ($query_params ?? []);
$active_tab = (string) ($active_tab ?? 'compose');
$is_track_active = $active_tab === 'track';
$read_stats_map = (array) ($read_stats_map ?? []);
$detail_map = (array) ($detail_map ?? []);
$receipt_circular_id = (int) ($receipt_circular_id ?? 0);
$receipt_subject = (string) ($receipt_subject ?? '');
$receipt_sender_faction = (string) ($receipt_sender_faction ?? '');
$receipt_stats = (array) ($receipt_stats ?? []);

$current_user = current_user() ?? [];
$sender_name = trim((string) ($current_user['fName'] ?? ''));

if ($sender_name === '') {
    $sender_name = (string) ($current_user['pID'] ?? '');
}
$faction_name_map = [];

foreach ($factions as $faction) {
    $fid = (int) ($faction['fID'] ?? 0);

    if ($fid <= 0) {
        continue;
    }
    $faction_name_map[$fid] = trim((string) ($faction['fName'] ?? ''));
}
$sender_from_fid = (int) ($current_user['fID'] ?? 0);
$sender_faction_display = '';

if ($sender_from_fid > 0 && isset($faction_name_map[$sender_from_fid])) {
    $sender_faction_display = (string) $faction_name_map[$sender_from_fid];
} else {
    $sender_faction_display = trim((string) ($current_user['faction_name'] ?? ''));
}

if ($sender_faction_display === '') {
    $position_name = trim((string) ($current_user['position_name'] ?? ''));

    if ($position_name !== '') {
        $sender_faction_display = 'ตำแหน่ง ' . $position_name . ' (' . $sender_name . ')';
    } else {
        $sender_faction_display = 'ผู้ส่ง ' . $sender_name;
    }
}

$status_map = [
    INTERNAL_STATUS_DRAFT => ['label' => 'ร่าง', 'pill' => 'pending'],
    INTERNAL_STATUS_SENT => ['label' => 'ส่งแล้ว', 'pill' => 'approved'],
    INTERNAL_STATUS_RECALLED => ['label' => 'ดึงกลับ', 'pill' => 'rejected'],
    INTERNAL_STATUS_ARCHIVED => ['label' => 'จัดเก็บ', 'pill' => 'approved'],
];

$thai_months = [
    1 => 'ม.ค.',
    2 => 'ก.พ.',
    3 => 'มี.ค.',
    4 => 'เม.ย.',
    5 => 'พ.ค.',
    6 => 'มิ.ย.',
    7 => 'ก.ค.',
    8 => 'ส.ค.',
    9 => 'ก.ย.',
    10 => 'ต.ค.',
    11 => 'พ.ย.',
    12 => 'ธ.ค.',
];

$thai_months_full = [
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

$format_thai_datetime = static function (?string $date_value) use ($thai_months): string {
    if ($date_value === null || trim($date_value) === '') {
        return '-';
    }

    $timestamp = strtotime($date_value);

    if ($timestamp === false) {
        return $date_value;
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp) + 543;
    $month_label = $thai_months[$month] ?? '';

    return $day . ' ' . $month_label . ' ' . $year . ' ' . date('H:i', $timestamp) . ' น.';
};

$format_thai_date_long = static function (?string $date_value) use ($thai_months_full): string {
    if ($date_value === null || trim($date_value) === '') {
        return '-';
    }

    $timestamp = strtotime($date_value);

    if ($timestamp === false) {
        return $date_value;
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp) + 543;
    $month_label = $thai_months_full[$month] ?? '';

    if ($month_label === '') {
        return $date_value;
    }

    return $day . ' ' . $month_label . ' พ.ศ.' . $year;
};

$format_thai_datetime_lines = static function (?string $date_value) use ($thai_months_full): array {
    if ($date_value === null || trim($date_value) === '') {
        return ['-', '-'];
    }

    $timestamp = strtotime($date_value);

    if ($timestamp === false) {
        return [$date_value, '-'];
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp) + 543;
    $month_label = $thai_months_full[$month] ?? '';

    if ($month_label === '') {
        return [$date_value, '-'];
    }

    return [
        $day . ' ' . $month_label . ' ' . $year,
        date('H:i', $timestamp) . ' น.',
    ];
};

$build_track_url = static function (array $override = []) use ($query_params): string {
    $params = array_merge($query_params, $override);

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }
    $query = http_build_query($params);

    return 'circular-compose.php' . ($query !== '' ? ('?' . $query) : '');
};

$build_url = $build_track_url;
$receipt_total = count($receipt_stats);
$receipt_read = 0;

foreach ($receipt_stats as $stat) {
    if ((int) ($stat['isRead'] ?? 0) === 1) {
        $receipt_read++;
    }
}
$receipt_unread = max(0, $receipt_total - $receipt_read);

$selected_factions = array_map('strval', (array) ($values['faction_ids'] ?? []));
$selected_people = array_map('strval', (array) ($values['person_ids'] ?? []));

$is_selected = static function (string $value, array $selected): bool {
    return in_array($value, $selected, true);
};

$faction_members = [];
$department_groups = [];
$executive_members = [];
$subject_head_members = [];
$recipient_group_display_map = [];

foreach ($teachers as $teacher) {
    $fid = (int) ($teacher['fID'] ?? 0);
    $did = (int) ($teacher['dID'] ?? 0);
    $position_id = (int) ($teacher['positionID'] ?? 0);
    $pid = trim((string) ($teacher['pID'] ?? ''));
    $name = trim((string) ($teacher['fName'] ?? ''));
    $department_name = trim((string) ($teacher['departmentName'] ?? ''));
    $faction_name = $fid > 0 ? trim((string) ($faction_name_map[$fid] ?? '')) : '';

    if ($pid === '' || $name === '') {
        continue;
    }

    if ($fid > 0) {
        if (!isset($faction_members[$fid])) {
            $faction_members[$fid] = [];
        }
        $faction_members[$fid][] = [
            'pID' => $pid,
            'name' => $name,
        ];
    }

    if (in_array($position_id, [1, 2, 3, 4], true)) {
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

    $normalized_department_name = preg_replace('/\s+/u', '', $department_name);
    $has_subject_group = $did > 0
        && $department_name !== ''
        && strpos((string) $normalized_department_name, 'ผู้บริหาร') === false
        && strpos((string) $normalized_department_name, 'ฝ่ายบริหาร') === false;

    $recipient_group_display_map[$pid] = $has_subject_group
        ? $department_name
        : ($faction_name !== '' ? $faction_name : '');

    if ($has_subject_group) {
        if (!isset($department_groups[$did])) {
            $department_groups[$did] = [
                'dID' => $did,
                'name' => $department_name,
                'members' => [],
            ];
        }
        $department_groups[$did]['members'][] = [
            'pID' => $pid,
            'name' => $name,
        ];
    }
}

if (!empty($department_groups)) {
    uasort($department_groups, static function (array $a, array $b): int {
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
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

ob_start();
?>
<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>หนังสือเวียน / ส่งหนังสือเวียน</p>
</div>

<style>
    .circular-my-table th.circular-track-sent-date,
    .circular-my-table td.circular-track-sent-date {
        text-align: left;
    }

    .circular-my-table .circular-track-sent-date-display {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
        line-height: 1.35;
    }

    .circular-my-table .circular-track-sent-date-display span {
        display: block;
        text-align: left;
        white-space: nowrap;
    }

    .circular-my-table .circular-track-sent-date-display span:last-child {
        color: #111111;
    }

    @media (max-width: 900px) {
        .container-circular-notice-sending .sender-row {
            grid-template-columns: 1fr;
        }
    }

    .container-circular-notice-sending .enterprise-checkbox-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 6px 0;
        cursor: pointer;
    }

    /* -------------------------------------------------------- */
    .circular-my-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .circular-my-summary-card {
        border: 1px solid rgba(var(--rgb-secondary), 0.16);
        border-radius: 12px;
        padding: 12px 14px;
        background: linear-gradient(180deg, #fbfdff 0%, #f3f7ff 100%);
    }

    .circular-my-summary-card p {
        margin: 0;
        font-size: var(--font-size-desc-2);
        color: var(--color-neutral-medium);
        font-weight: 600;
    }

    .circular-my-summary-card h3 {
        margin: 4px 0 0;
        font-size: 24px;
        line-height: 1.1;
        color: var(--color-secondary);
    }

    .circular-my-filter-grid {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
        flex-direction: row;
        margin: 0 0 40px;
    }

    .circular-my-filter-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .circular-my-filter-field label {
        margin: 0;
        font-size: var(--font-size-desc-3);
        color: var(--color-neutral-medium);
        font-weight: 700;
    }

    .circular-my-table-wrap {
        margin-top: 8px;
    }

    .circular-my-table td {
        vertical-align: top;
    }

    .circular-my-table td:nth-child(n+2) {
        vertical-align: middle;
        text-align: center;
    }

    .circular-my-table th:nth-child(n+5) {
        text-align: center;
    }

    .circular-my-subject {
        min-width: 260px;
        max-width: 380px;
        font-weight: 700;
        color: var(--color-secondary);
        line-height: 1.45;
        word-break: break-word;
    }

    .circular-my-meta {
        color: var(--color-neutral-dark);
        font-size: var(--font-size-desc-2);
        margin-top: 2px;
    }

    .circular-my-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 6px;
        min-width: 0px;
    }

    .circular-my-actions form {
        margin: 0;
    }

    .circular-track-modal-host {
        width: 0;
        height: 0;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
        display: block;
        overflow: visible;
    }

    .circular-track-modal-host .modal-overlay-circular-notice-index.outside-person .modal-content {
        width: 95%;
        height: 90%;
    }

    .circular-track-modal-host .modal-overlay-circular-notice-index.outside-person .content-read-sec {
        padding-top: 30px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .circular-track-modal-host .modal-overlay-circular-notice-index.outside-person .content-read-sec th,
    .circular-track-modal-host .modal-overlay-circular-notice-index.outside-person .content-read-sec td {
        text-align: left;
        vertical-align: middle;
    }

    .circular-track-modal-host .modal-overlay-circular-notice-index.outside-person .content-read-sec td:nth-child(2),
    .circular-track-modal-host .modal-overlay-circular-notice-index.outside-person .content-read-sec th:nth-child(2) {
        text-align: center;
        padding: 5px 0;
    }

    .circular-track-modal-host .track-detail-link-display {
        display: flex;
        align-items: center;
        width: 100%;
        min-height: 60px;
        background-color: var(--color-neutral-lightest);
        border: 1px solid var(--color-primary-dark);
        border-radius: 8px;
        outline: none;
        padding: 8px 20px;
        margin: 0 0 20px;
        font-size: var(--font-size-body-1);
        color: var(--color-secondary);
        text-decoration: none;
        transition: 0.4s;
        word-break: break-word;
        overflow-wrap: anywhere;
        cursor: default;
    }

    .circular-track-modal-host .track-detail-link-display.is-clickable {
        cursor: pointer;
    }

    .circular-track-modal-host .track-detail-link-display.is-clickable:hover,
    .circular-track-modal-host .track-detail-link-display.is-clickable:focus-visible {
        text-decoration: underline;
    }

    .delete-btn {
        font-size: var(--font-size-h3);
    }

    .modal-title {
        color: var(--color-secondary);
    }

    .container-circular-notice-sending .sender-row input[disabled] {
        background-color: rgba(var(--rgb-secondary), 0.2);
        border-color: transparent;
        box-shadow: none;
        cursor: not-allowed;
        opacity: 1;
        -webkit-text-fill-color: var(--color-secondary);
        color: var(--color-secondary);
        border: 1px solid rgba(var(--rgb-secondary), 0.5);
        transition: 0.3s;
    }

    .category-title span {
        font-size: var(--font-size-body-1);
    }

    .custom-table {
        min-width: 970px;
    }

    @media screen and (min-width: 769px) and (max-width: 1023px) {
        .container-circular-notice-sending .form-group {
            margin: 0px 0 10px;
        }

        .container-circular-notice-sending .sender-row {
            gap: 10px;
        }

        .container-circular-notice-sending .sent-notice-btn {
            width: 120px;
        }

        .category-title span {
            font-size: var(--font-size-desc-1);
        }

        .container-circular-notice-sending .form-group input[type="text"],
        .container-circular-notice-sending .form-group textarea {
            margin: 0;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec .file-section {
            margin: 0;
            padding: 0;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec {
            border-bottom-width: 1px;
            padding: 0 0 10px;
        }

        .circular-track-modal-host .track-detail-link-display {
            min-height: 30px;
            padding: 0 10px;
            margin: 0 0 10px;
            font-size: var(--font-size-desc-1);
        }
    }

    @media (max-width: 1280px) {
        .circular-my-filter-grid {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }

        .circular-my-filter-grid .circular-my-filter-actions {
            grid-column: span 2;
        }
    }

    @media (max-width: 900px) {
        .circular-my-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .circular-my-filter-grid {
            grid-template-columns: 1fr;
        }

        .circular-my-filter-grid .circular-my-filter-actions {
            grid-column: span 1;
        }
    }

    @media screen and (max-width: 768px) {
        .container-circular-notice-sending .form-group.row {
            margin: 10px 0;
            height: 25px;
        }

        .container-circular-notice-sending .form-group {
            gap: 0px;
            margin: 0 0 10px;
        }

        .container-circular-notice-sending .sender-row .form-group label {
            line-height: 30px;
        }

        .container-circular-notice-sending .form-group.receive {
            margin: 10px 0;
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 10px;
        }

        .container-circular-notice-sending .sent-notice-btn {
            width: 90px;
        }

        .category-title span {
            font-size: var(--font-size-desc-3) !important;
        }

        .circular-track-modal-host .track-detail-link-display {
            min-height: 25px;
            padding: 0 10px;
            margin: 0 0 10px;
            font-size: var(--font-size-desc-3);
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec {
            border-bottom-width: 1px;
            padding: 0 0 10px;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec .file-section {
            padding: 0;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index .sender-row .form-group {
            gap: 10px;
            margin: 0 0 10px;
        }
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table thead th:nth-child(3),
    .table-circular-notice-index table tbody td:nth-child(1) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .table-circular-notice-index table tbody td:nth-child(2) {
        text-align: start !important;
    }

    .table-circular-notice-index table thead th:nth-child(1) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index table thead th:nth-child(2) {

        width: 700px !important;
        min-width: 700px !important;
        max-width: 700px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3) {
        min-width: 160px !important;
        max-width: 160px !important;

    }

    .table-circular-notice-index table thead th:nth-child(4) {
        width: 180px !important;
        min-width: 180px !important;
        max-width: 180px !important;

    }

    .table-circular-notice-index table thead th:nth-child(5) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;

    }

    @media screen and (min-width: 769px) and (max-width: 1023px) {

        .table-circular-notice-index table thead th:nth-child(1),
        .table-circular-notice-index table thead th:nth-child(3),
        .table-circular-notice-index table tbody td:nth-child(1) {
            text-align: center !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .table-circular-notice-index table tbody td:nth-child(2) {
            text-align: start !important;
        }

        .table-circular-notice-index table thead th:nth-child(1) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2) {

            width: 600px !important;
            min-width: 600px !important;
            max-width: 600px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3) {
            min-width: 140px !important;
            max-width: 140px !important;

        }

        .table-circular-notice-index table thead th:nth-child(4) {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 160px !important;

        }

        .table-circular-notice-index table thead th:nth-child(5) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;

        }

    }
</style>

<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
            onclick="openTab('circularComposeForm', event)">ส่งหนังสือเวียน</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>" onclick="openTab('circularTrack', event)">ติดตามการส่ง</button>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" data-validate class="tab-content container-circular-notice-sending <?= $is_track_active ? '' : 'active' ?>" id="circularComposeForm">
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="subject">หัวเรื่อง</label>
        <input type="text" name="subject" id="subject" placeholder="กรุณากรอกหัวเรื่อง" value="<?= h((string) ($values['subject'] ?? '')) ?>" required>
    </div>

    <div class="form-group">
        <label for="detail">รายละเอียด</label>
        <textarea name="detail" id="detail" rows="4" placeholder="กรุณากรอกรายละเอียด"><?= h((string) ($values['detail'] ?? '')) ?></textarea>
    </div>

    <div class="form-group">
        <label>อัปโหลดไฟล์เอกสาร</label>
        <section class="upload-layout">
            <input type="file" id="fileInput" name="attachments[]" multiple accept="application/pdf,image/png,image/jpeg" style="display: none;" />

            <div class="upload-box" id="dropzone">
                <i class="fa-solid fa-upload"></i>
                <p>ลากไฟล์มาวางที่นี่</p>
            </div>

            <div class="file-list" id="fileListContainer"></div>
        </section>
    </div>

    <div class="row form-group">
        <button class="btn btn-upload-small" type="button" id="btnAddFiles">
            <p>เพิ่มไฟล์</p>
        </button>
        <div class="file-hint">
            <p>* แนบไฟล์ได้สูงสุด 5 ไฟล์ (รวม PNG และ PDF) *</p>
        </div>
    </div>

    <div id="imagePreviewModal" class="modal-overlay-preview">
        <span class="close-preview" id="closePreviewBtn">&times;</span>
        <img class="preview-content" id="previewImage" alt="">
        <div id="previewCaption"></div>
    </div>

    <div class="form-group">
        <label for="linkURL">แนบลิ้งก์</label>
        <input type="text" id="linkURL" name="linkURL" placeholder="กรุณาแนบลิ้งก์ที่เกี่ยวข้อง" value="<?= h((string) ($values['linkURL'] ?? '')) ?>" />
    </div>

    <div class="sender-row">
        <div class="form-group sender-field">
            <label for="senderDisplay">ผู้ส่ง</label>
            <input id="senderDisplay" type="text" value="<?= h($sender_name) ?>" disabled>
        </div>
        <div class="form-group">
            <label for="fromFIDDisplay">ในนามของ</label>
            <input id="fromFIDDisplay" type="text" value="<?= h($sender_faction_display) ?>" disabled>
            <input type="hidden" name="fromFID" value="<?= h($sender_from_fid > 0 ? (string) $sender_from_fid : '') ?>">
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
                    <label class="select-all-box">
                        <input type="checkbox" id="selectAll">เลือกทั้งหมด
                    </label>
                </div>

                <div class="dropdown-list">
                    <?php if (!empty($factions)) : ?>
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
                                        $member_pid = (string) ($member['pID'] ?? '');
                                        $member_payload[] = [
                                            'pID' => $member_pid,
                                            'name' => (string) ($member['name'] ?? ''),
                                            'faction' => $faction_name,
                                            'preferredGroup' => (string) ($recipient_group_display_map[$member_pid] ?? $faction_name),
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
                                                                data-preferred-group-label="<?= h((string) ($recipient_group_display_map[$member_pid] ?? $faction_name)) ?>"
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

                    <?php if (!empty($department_groups)) : ?>
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
                                            'preferredGroup' => (string) ($recipient_group_display_map[$member_pid] ?? $department_name),
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
                                                            data-preferred-group-label="<?= h((string) ($recipient_group_display_map[(string) ($member['pID'] ?? '')] ?? $department_name)) ?>"
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
                                            'preferredGroup' => (string) ($recipient_group_display_map[$member_pid] ?? $group_name),
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
                                                            data-preferred-group-label="<?= h((string) ($recipient_group_display_map[(string) ($member['pID'] ?? '')] ?? $group_name)) ?>"
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
            <button id="btnShowRecipients" type="button">
                <p>แสดงผู้รับทั้งหมด</p>
            </button>
        </div>
    </div>

    <button id="btnSendNotice" class="sent-notice-btn" type="submit">
        <p>ส่งหนังสือเวียน</p>
    </button>

    <div id="confirmModal" class="modal-overlay-confirm">
        <div class="confirm-box">
            <div class="confirm-header">
                <div class="icon-circle">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
            </div>
            <div class="confirm-body">
                <h3>ยืนยันการส่งหนังสือเวียน</h3>
                <div class="confirm-actions">
                    <button id="btnConfirmYes" class="btn-yes" type="button">ยืนยัน</button>
                    <button id="btnConfirmNo" class="btn-no" type="button">ยกเลิก</button>
                </div>
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
</form>

<section class="enterprise-card tab-content <?= $is_track_active ? 'active' : '' ?>" id="circularTrack">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" class="circular-my-filter-grid" id="circularTrackFilterForm">
        <input type="hidden" name="tab" value="track">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($filter_query) ?>"
                    placeholder="ค้นหารายการหนังสือเวียน" autocomplete="off">
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value">
                            <?php
                            $status_label = 'ทั้งหมด';

                            if ($filter_status === strtolower(INTERNAL_STATUS_SENT)) {
                                $status_label = 'ส่งแล้ว';
                            } elseif ($filter_status === strtolower(INTERNAL_STATUS_RECALLED)) {
                                $status_label = 'ดึงกลับ';
                            }
                            echo h($status_label);
                            ?>
                        </p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option" data-value="all">ทั้งหมด</div>
                        <div class="custom-option" data-value="<?= h(strtolower(INTERNAL_STATUS_SENT)) ?>">ส่งแล้ว</div>
                        <div class="custom-option" data-value="<?= h(strtolower(INTERNAL_STATUS_RECALLED)) ?>">ดึงกลับ</div>
                    </div>

                    <select class="form-input" name="status">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                        <option value="<?= h(strtolower(INTERNAL_STATUS_SENT)) ?>" <?= $filter_status === strtolower(INTERNAL_STATUS_SENT) ? 'selected' : '' ?>>ส่งแล้ว</option>
                        <option value="<?= h(strtolower(INTERNAL_STATUS_RECALLED)) ?>" <?= $filter_status === strtolower(INTERNAL_STATUS_RECALLED) ? 'selected' : '' ?>>ดึงกลับ</option>
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

    <div id="circularTrackResults">
        <div class="enterprise-card-header">
            <div class="enterprise-card-title-group">
                <h2 class="enterprise-card-title">รายการหนังสือเวียนของฉัน</h2>
            </div>
        </div>

        <div class="table-responsive table-circular-notice-index circular-my-table-wrap">
            <table class="custom-table circular-my-table">
                <thead>
                    <tr>
                        <th>จัดการ</th>
                        <th>เรื่อง</th>
                        <th>อ่านแล้ว/ทั้งหมด</th>
                        <th class="circular-track-sent-date">วันที่ส่ง</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sent_items)) : ?>
                        <tr>
                            <td colspan="5" class="enterprise-empty">ไม่มีรายการหนังสือเวียนตามเงื่อนไข</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($sent_items as $item) : ?>
                            <?php
                            $circular_id = (int) ($item['circularID'] ?? 0);
                            $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
                            $status_meta = $status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
                            $item_type = strtoupper((string) ($item['circularType'] ?? ''));
                            $read_count = (int) ($item['readCount'] ?? 0);
                            $recipient_count = (int) ($item['recipientCount'] ?? 0);
                            $created_at = (string) ($item['createdAt'] ?? '');
                            $date_display = $format_thai_datetime($created_at);
                            [$date_line_display, $time_line_display] = $format_thai_datetime_lines($created_at);
                            $date_long_display = $format_thai_date_long($created_at);
                            $sender_faction_name = (string) ($item['senderFactionName'] ?? '');
                            $detail_row = (array) ($detail_map[$circular_id] ?? []);
                            $detail_text = trim((string) ($detail_row['detail'] ?? ''));
                            $detail_link = trim((string) ($detail_row['linkURL'] ?? ''));
                            $detail_sender_name = trim((string) ($detail_row['senderName'] ?? ''));
                            $detail_sender_faction = trim((string) ($detail_row['senderFactionName'] ?? $sender_faction_name));
                            $attachments = (array) ($detail_row['files'] ?? []);
                            $detail_faction_ids = (array) ($detail_row['factionIDs'] ?? []);
                            $detail_person_ids = (array) ($detail_row['personIDs'] ?? []);
                            $files_json = json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $faction_ids_json = json_encode($detail_faction_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $person_ids_json = json_encode($detail_person_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                            if ($files_json === false) {
                                $files_json = '[]';
                            }
                            if ($faction_ids_json === false) {
                                $faction_ids_json = '[]';
                            }
                            if ($person_ids_json === false) {
                                $person_ids_json = '[]';
                            }
                            $consider_class = 'considering';

                            if (in_array($status_key, [INTERNAL_STATUS_RECALLED], true)) {
                                $consider_class = 'considered';
                            } elseif (in_array($status_key, [INTERNAL_STATUS_SENT, INTERNAL_STATUS_ARCHIVED], true)) {
                                $consider_class = 'success';
                            }
                            $stats_rows = [];
                            $has_any_read = $read_count > 0;

                            foreach ((array) ($read_stats_map[$circular_id] ?? []) as $stat) {
                                $is_read = (int) ($stat['isRead'] ?? 0) === 1;

                                if ($is_read) {
                                    $has_any_read = true;
                                }
                                $stats_rows[] = [
                                    'name' => (string) ($stat['fName'] ?? '-'),
                                    'status' => $is_read ? 'อ่านแล้ว' : 'ยังไม่อ่าน',
                                    'pill' => $is_read ? 'approved' : 'pending',
                                    'readAt' => $is_read ? $format_thai_datetime((string) ($stat['readAt'] ?? '')) : '-',
                                ];
                            }
                            $stats_json = json_encode($stats_rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                            if ($stats_json === false) {
                                $stats_json = '[]';
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="circular-my-actions">
                                        <?php if ($item_type === 'INTERNAL' && $status_key === INTERNAL_STATUS_SENT && !$has_any_read) : ?>
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="recall">
                                                <input type="hidden" name="circular_id" value="<?= h((string) $circular_id) ?>">
                                                <button type="submit" class="booking-action-btn secondary">
                                                    <i class="fa-solid fa-rotate-left"></i>
                                                    <span class="tooltip">ดึงกลับ</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($item_type === 'INTERNAL' && $status_key === INTERNAL_STATUS_RECALLED) : ?>
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="resend">
                                                <input type="hidden" name="circular_id" value="<?= h((string) $circular_id) ?>">
                                                <button type="submit" class="booking-action-btn secondary">
                                                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                                    <span class="tooltip">ส่งใหม่</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!($item_type === 'INTERNAL' && $status_key === INTERNAL_STATUS_RECALLED)) : ?>
                                            <button
                                                class="booking-action-btn secondary js-open-circular-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) $circular_id) ?>"
                                                data-type="<?= h($item_type) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '-')) ?>"
                                                data-detail="<?= h($detail_text) ?>"
                                                data-link="<?= h($detail_link) ?>"
                                                data-sender-name="<?= h($detail_sender_name !== '' ? $detail_sender_name : $sender_name) ?>"
                                                data-sender-faction="<?= h($detail_sender_faction !== '' ? $detail_sender_faction : $sender_faction_display) ?>"
                                                data-bookno="<?= h('#' . (string) $circular_id) ?>"
                                                data-issued="<?= h($date_long_display) ?>"
                                                data-from="<?= h(($detail_sender_name !== '' ? $detail_sender_name : $sender_name) . (($detail_sender_faction !== '' ? $detail_sender_faction : $sender_faction_display) !== '' ? (' / ' . ($detail_sender_faction !== '' ? $detail_sender_faction : $sender_faction_display)) : '')) ?>"
                                                data-to="<?= h('ผู้รับทั้งหมด ' . (string) $recipient_count . ' คน') ?>"
                                                data-status="<?= h((string) ($status_meta['label'] ?? '-')) ?>"
                                                data-consider="<?= h($consider_class) ?>"
                                                data-received-time="<?= h($date_display) ?>"
                                                data-files="<?= h($files_json) ?>"
                                                data-read-stats="<?= h($stats_json) ?>">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูรายละเอียด</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($item_type === 'INTERNAL' && $status_key === INTERNAL_STATUS_RECALLED) : ?>
                                            <button
                                                class="booking-action-btn secondary js-open-edit-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) $circular_id) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '-')) ?>"
                                                data-detail="<?= h($detail_text) ?>"
                                                data-link="<?= h($detail_link) ?>"
                                                data-files="<?= h($files_json) ?>"
                                                data-faction-ids="<?= h($faction_ids_json) ?>"
                                                data-person-ids="<?= h($person_ids_json) ?>">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                                <span class="tooltip">แก้ไข</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="circular-my-subject"><?= h((string) ($item['subject'] ?? '-')) ?></div>
                                    <?php if (!empty($item['senderFactionName'])) : ?>
                                        <div class="circular-my-meta">ในนาม <?= h((string) $item['senderFactionName']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= h((string) $read_count) ?>/<?= h((string) $recipient_count) ?></td>
                                <td class="circular-track-sent-date">
                                    <div class="circular-track-sent-date-display" aria-label="<?= h($date_display) ?>">
                                        <span><?= h($date_line_display) ?></span>
                                        <span><?= h($time_line_display) ?></span>
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
    </div>
</section>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="trackDetailModalOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p>รายละเอียดของหนังสือเวียน</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeTrackDetailModal"></i>
                </div>
            </div>

            <div class="content-modal">
                <form method="" enctype="" data-validate class="container-circular-notice-sending" id="" style="box-shadow:none; padding: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="edit_circular_id" id="trackDetailCircularId" value="">

                    <div class="form-group">
                        <label for="track_detail_subject"><b>หัวเรื่อง</b></label>
                        <input type="text" name="subject" id="track_detail_subject" placeholder="กรุณากรอกหัวเรื่อง" disabled>
                    </div>

                    <div class="form-group">
                        <label for="track_detail_detail"><b>รายละเอียด</b></label>
                        <textarea name="detail" id="track_detail_detail" rows="4" placeholder="กรุณากรอกรายละเอียด" disabled></textarea>
                    </div>

                    <div class="content-file-sec">
                        <p><strong>ไฟล์เอกสารแนบจากระบบ</strong></p>
                        <div class="file-section" id="trackModalFileSection"></div>
                    </div>

                    <div class="form-group"><br>
                        <label for="track_detail_linkURL"><b>แนบลิ้งก์</b></label>
                        <a id="track_detail_linkURL" class="track-detail-link-display" href="#" target="_blank" rel="noopener noreferrer" aria-disabled="true">-</a>
                    </div>

                    <div class="sender-row">
                        <div class="form-group sender-field">
                            <label for="track_detail_senderDisplay"><b>ผู้ส่ง</b></label>
                            <input id="track_detail_senderDisplay" type="text" value="<?= h($sender_name) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="track_detail_fromFIDDisplay"><b>ในนามของ</b></label>
                            <input id="track_detail_fromFIDDisplay" type="text" value="<?= h($sender_faction_display) ?>" disabled>
                            <input type="hidden" name="fromFID" value="<?= h($sender_from_fid > 0 ? (string) $sender_from_fid : '') ?>">
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
                                        <td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalEditOverlay" style="display: none;">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p>แก้ไขหนังสือเวียน</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeModalEdit" style="cursor: pointer;"></i>
                </div>
            </div>

            <div class="content-modal">
                <form method="POST" enctype="multipart/form-data" data-validate class="container-circular-notice-sending" id="circularEditForm" style="box-shadow:none; padding: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab" value="track">
                    <input type="hidden" name="edit_circular_id" id="editTargetCircularId" value="">
                    <input type="hidden" name="return_q" value="<?= h($filter_query) ?>">
                    <input type="hidden" name="return_status" value="<?= h(strtolower($filter_status)) ?>">
                    <input type="hidden" name="return_sort" value="<?= h($filter_sort) ?>">
                    <input type="hidden" name="return_page" value="<?= h((string) $page) ?>">
                    <input type="hidden" name="return_per_page" value="<?= h((string) $per_page) ?>">

                    <div class="form-group">
                        <label for="edit_subject"><b>หัวเรื่อง</b></label>
                        <input type="text" name="subject" id="edit_subject" placeholder="กรุณากรอกหัวเรื่อง" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_detail"><b>รายละเอียด</b></label>
                        <textarea name="detail" id="edit_detail" rows="4" placeholder="กรุณากรอกรายละเอียด"></textarea>
                    </div>

                    <div class="form-group">
                        <label><b>อัปโหลดไฟล์เอกสารใหม่</b></label>
                        <section class="upload-layout">
                            <input type="file" id="edit_fileInput" name="attachments[]" multiple accept="application/pdf,image/png,image/jpeg" style="display: none;" />
                            <div class="upload-box" id="edit_dropzone">
                                <i class="fa-solid fa-upload"></i>
                                <p>ลากไฟล์มาวางที่นี่</p>
                            </div>
                            <div class="file-list" id="edit_fileListContainer"></div>
                        </section>
                    </div>

                    <div class="row form-group">
                        <button class="btn btn-upload-small" type="button" id="edit_btnAddFiles">
                            <p>เพิ่มไฟล์</p>
                        </button>
                        <div class="file-hint">
                            <p>* แนบไฟล์ได้สูงสุด 5 ไฟล์ (รวม PNG และ PDF) *</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_linkURL"><b>แนบลิ้งก์</b></label>
                        <input type="text" id="edit_linkURL" name="linkURL" placeholder="กรุณาแนบลิ้งก์ที่เกี่ยวข้อง" />
                    </div>

                    <div class="sender-row">
                        <div class="form-group sender-field">
                            <label for="edit_senderDisplay"><b>ผู้ส่ง</b></label>
                            <input id="edit_senderDisplay" type="text" value="<?= h($sender_name) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="edit_fromFIDDisplay"><b>ในนามของ</b></label>
                            <input id="edit_fromFIDDisplay" type="text" value="<?= h($sender_faction_display) ?>" disabled>
                            <input type="hidden" name="fromFID" value="<?= h($sender_from_fid > 0 ? (string) $sender_from_fid : '') ?>">
                        </div>
                    </div>

                    <div class="form-group receive" data-recipients-section>
                        <label><b>ส่งถึง :</b></label>
                        <div class="dropdown-container">
                            <div class="search-input-wrapper" id="edit_recipientToggle">
                                <input type="text" id="edit_mainInput" class="search-input" placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>

                            <div class="dropdown-content" id="edit_dropdownContent">
                                <div class="dropdown-header">
                                    <label class="select-all-box" for="edit_selectAll">
                                        <input type="checkbox" id="edit_selectAll">เลือกทั้งหมด
                                    </label>
                                </div>
                                <div class="dropdown-list">
                                    <?php if (!empty($factions)) : ?>
                                        <div class="category-group">
                                            <div class="category-title">
                                                <span>หน่วยงาน</span>
                                            </div>
                                            <div class="category-items">
                                                <?php foreach ($factions as $faction) : ?>
                                                    <?php
                                                    $fid = (int) ($faction['fID'] ?? 0);
                                                    if ($fid <= 0) continue;

                                                    $fid_value = (string) $fid;
                                                    $faction_name = trim((string) ($faction['fName'] ?? ''));

                                                    if ($faction_name === '' || strpos($faction_name, 'ฝ่ายบริหาร') !== false) continue;

                                                    $members = $faction_members[$fid] ?? [];
                                                    $member_payload = [];

                                                    foreach ($members as $member) {
                                                        $member_pid = (string) ($member['pID'] ?? '');
                                                        $member_payload[] = [
                                                            'pID' => $member_pid,
                                                            'name' => (string) ($member['name'] ?? ''),
                                                            'faction' => $faction_name,
                                                            'preferredGroup' => (string) ($recipient_group_display_map[$member_pid] ?? $faction_name),
                                                        ];
                                                    }
                                                    $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
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

                                                    // สร้าง key พิเศษสำหรับ edit
                                                    $edit_group_key = 'edit-faction-' . $fid_value;
                                                    ?>
                                                    <div class="item item-group<?= $expanded_by_default ? '' : ' is-collapsed' ?>" data-faction-id="<?= h($fid_value) ?>">
                                                        <div class="group-header">
                                                            <label class="item-main" for="edit_group_faction_<?= h($fid_value) ?>">
                                                                <input type="checkbox" id="edit_group_faction_<?= h($fid_value) ?>" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction"
                                                                    data-group-key="<?= h($edit_group_key) ?>"
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
                                                                <li><span class="item-subtext">ไม่มีสมาชิกในฝ่ายนี้</span></li>
                                                            <?php else : ?>
                                                                <?php foreach ($members as $member) : ?>
                                                                    <?php
                                                                    $member_pid = (string) ($member['pID'] ?? '');
                                                                    $member_name = (string) ($member['name'] ?? '');
                                                                    if ($member_pid === '' || $member_name === '') continue;
                                                                    ?>
                                                                    <li>
                                                                        <label class="item member-item" for="edit_member_faction_<?= h($fid_value) ?>_<?= h($member_pid) ?>">
                                                                            <input type="checkbox" id="edit_member_faction_<?= h($fid_value) ?>_<?= h($member_pid) ?>" class="member-checkbox"
                                                                                data-member-group-key="<?= h($edit_group_key) ?>"
                                                                                data-member-name="<?= h($member_name) ?>"
                                                                                data-group-label="<?= h($faction_name) ?>"
                                                                                data-preferred-group-label="<?= h((string) ($recipient_group_display_map[$member_pid] ?? $faction_name)) ?>"
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

                                    <?php if (!empty($department_groups)) : ?>
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

                                                    if ($did <= 0 || $department_name === '' || empty($members)) continue;

                                                    $member_payload = [];
                                                    $has_selected_member = false;

                                                    foreach ($members as $member) {
                                                        $member_pid = (string) ($member['pID'] ?? '');
                                                        $member_name = (string) ($member['name'] ?? '');
                                                        if ($member_pid === '' || $member_name === '') continue;

                                                        if ($is_selected($member_pid, $selected_people)) {
                                                            $has_selected_member = true;
                                                        }
                                                        $member_payload[] = [
                                                            'pID' => $member_pid,
                                                            'name' => $member_name,
                                                            'faction' => $department_name,
                                                            'preferredGroup' => (string) ($recipient_group_display_map[$member_pid] ?? $department_name),
                                                        ];
                                                    }

                                                    if (empty($member_payload)) continue;

                                                    $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
                                                    $member_total = count($member_payload);

                                                    $group_key = 'department-' . $did;
                                                    // สร้าง key พิเศษสำหรับ edit
                                                    $edit_group_key = 'edit-department-' . $did;
                                                    ?>
                                                    <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                        <div class="group-header">
                                                            <label class="item-main" for="edit_group_dept_<?= h($did) ?>">
                                                                <input type="checkbox" id="edit_group_dept_<?= h($did) ?>" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department"
                                                                    data-group-key="<?= h($edit_group_key) ?>"
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
                                                                    <label class="item member-item" for="edit_member_dept_<?= h($did) ?>_<?= h((string) ($member['pID'] ?? '')) ?>">
                                                                        <input type="checkbox" id="edit_member_dept_<?= h($did) ?>_<?= h((string) ($member['pID'] ?? '')) ?>" class="member-checkbox"
                                                                            data-member-group-key="<?= h($edit_group_key) ?>"
                                                                            data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                            data-group-label="<?= h($department_name) ?>"
                                                                            data-preferred-group-label="<?= h((string) ($recipient_group_display_map[(string) ($member['pID'] ?? '')] ?? $department_name)) ?>"
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

                                                    if ($group_key === '' || $group_name === '' || empty($members)) continue;

                                                    $member_payload = [];
                                                    $has_selected_member = false;

                                                    foreach ($members as $member) {
                                                        $member_pid = (string) ($member['pID'] ?? '');
                                                        $member_name = (string) ($member['name'] ?? '');
                                                        if ($member_pid === '' || $member_name === '') continue;

                                                        if ($is_selected($member_pid, $selected_people)) {
                                                            $has_selected_member = true;
                                                        }
                                                        $member_payload[] = [
                                                            'pID' => $member_pid,
                                                            'name' => $member_name,
                                                            'faction' => $group_name,
                                                            'preferredGroup' => (string) ($recipient_group_display_map[$member_pid] ?? $group_name),
                                                        ];
                                                    }

                                                    if (empty($member_payload)) continue;

                                                    $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
                                                    $member_total = count($member_payload);

                                                    // สร้าง key พิเศษสำหรับ edit
                                                    $edit_group_key = 'edit-special-' . $group_key;
                                                    ?>
                                                    <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                        <div class="group-header">
                                                            <label class="item-main" for="edit_group_special_<?= h($group_key) ?>">
                                                                <input type="checkbox" id="edit_group_special_<?= h($group_key) ?>" class="item-checkbox group-item-checkbox" data-group="special"
                                                                    data-group-key="<?= h($edit_group_key) ?>"
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
                                                                    <label class="item member-item" for="edit_member_special_<?= h($group_key) ?>_<?= h((string) ($member['pID'] ?? '')) ?>">
                                                                        <input type="checkbox" id="edit_member_special_<?= h($group_key) ?>_<?= h((string) ($member['pID'] ?? '')) ?>" class="member-checkbox"
                                                                            data-member-group-key="<?= h($edit_group_key) ?>"
                                                                            data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                            data-group-label="<?= h($group_name) ?>"
                                                                            data-preferred-group-label="<?= h((string) ($recipient_group_display_map[(string) ($member['pID'] ?? '')] ?? $group_name)) ?>"
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
                            <button id="edit_btnShowRecipients" type="button">
                                <p>แสดงผู้รับทั้งหมด</p>
                            </button>
                        </div>
                    </div>

                    <div id="edit_confirmModal" class="modal-overlay-confirm">
                        <div class="confirm-box">
                            <div class="confirm-header">
                                <div class="icon-circle"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            </div>
                            <div class="confirm-body">
                                <h3>ยืนยันการแก้ไขและส่งใหม่</h3>
                                <div class="confirm-actions">
                                    <button id="edit_btnConfirmYes" class="btn-yes" type="button">ยืนยัน</button>
                                    <button id="edit_btnConfirmNo" class="btn-no" type="button">ยกเลิก</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="edit_recipientModal" class="modal-overlay-recipient">
                        <div class="modal-container">
                            <div class="modal-header">
                                <div class="modal-title">
                                    <i class="fa-solid fa-users"></i><span>รายชื่อผู้รับหนังสือเวียน</span>
                                </div>
                                <button class="modal-close" id="edit_closeModalBtn" type="button"><i class="fa-solid fa-xmark"></i></button>
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
                                    <tbody id="edit_recipientTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="footer-modal">
                <button id="edit_btnSendNotice" type="button">
                    <p>ยืนยัน</p>
                </button>
            </div>

        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const previewModal = document.getElementById('imagePreviewModal');
        const previewImage = document.getElementById('previewImage');
        const previewCaption = document.getElementById('previewCaption');
        const closePreviewBtn = document.getElementById('closePreviewBtn');

        closePreviewBtn?.addEventListener('click', () => previewModal?.classList.remove('active'));
        previewModal?.addEventListener('click', (e) => {
            if (e.target === previewModal) previewModal.classList.remove('active');
        });

        function setupCircularForm(prefix, formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const fileInput = document.getElementById(prefix + 'fileInput');
            const fileList = document.getElementById(prefix + 'fileListContainer');
            const dropzone = document.getElementById(prefix + 'dropzone');
            const addFilesBtn = document.getElementById(prefix + 'btnAddFiles');
            const removedFilesContainer = form.querySelector('[data-remove-file-inputs]') || document.createElement('div');

            const maxFiles = 5;
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            let selectedFiles = [];
            let existingFiles = [];
            let existingEntityId = '';
            let removedExistingFileIds = [];

            if (!removedFilesContainer.parentNode) {
                removedFilesContainer.setAttribute('data-remove-file-inputs', 'true');
                removedFilesContainer.style.display = 'none';
                form.appendChild(removedFilesContainer);
            }

            const syncRemovedFileInputs = () => {
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
                return normalizedMime.includes('pdf') ? '<i class="fa-solid fa-file-pdf"></i>' : '<i class="fa-solid fa-image"></i>';
            };

            const buildExistingFileUrl = (file) => {
                const fileId = String(file?.fileID || '').trim();

                if (existingEntityId === '' || fileId === '') {
                    return '';
                }

                return `/public/api/file-download.php?module=circulars&entity_id=${encodeURIComponent(existingEntityId)}&file_id=${encodeURIComponent(fileId)}`;
            };

            const appendSelectedFileItem = (file, index) => {
                if (!fileList) {
                    return;
                }

                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper';

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

                const view = document.createElement('a');
                view.href = '#';
                view.innerHTML = '<i class="fa-solid fa-eye"></i>';
                view.addEventListener('click', (e) => {
                    e.preventDefault();
                    const url = URL.createObjectURL(file);
                    window.open(url, '_blank', 'noopener');
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                });

                actions.appendChild(view);
                banner.appendChild(info);
                banner.appendChild(actions);
                wrapper.appendChild(deleteBtn);
                wrapper.appendChild(banner);
                fileList.appendChild(wrapper);
            };

            const appendExistingFileItem = (file) => {
                if (!fileList) {
                    return;
                }

                const fileId = String(file?.fileID || '').trim();
                const fileUrl = buildExistingFileUrl(file);
                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper';

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'delete-btn';
                deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
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
            };

            const renderFiles = () => {
                if (!fileList) return;
                fileList.innerHTML = '';
                existingFiles.forEach((file) => appendExistingFileItem(file));
                selectedFiles.forEach((file, index) => appendSelectedFileItem(file, index));
            };

            const syncFiles = () => {
                if (!fileInput) return;
                const dt = new DataTransfer();
                selectedFiles.forEach((file) => dt.items.add(file));
                fileInput.files = dt.files;
            };

            const addFiles = (files) => {
                if (!files) return;
                const existing = new Set(selectedFiles.map((f) => `${f.name}-${f.size}-${f.lastModified}`));
                Array.from(files).forEach((file) => {
                    const key = `${file.name}-${file.size}-${file.lastModified}`;
                    if (!existing.has(key) && allowedTypes.includes(file.type) && (existingFiles.length + selectedFiles.length) < maxFiles) {
                        selectedFiles.push(file);
                        existing.add(key);
                    }
                });
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

            if (fileInput) fileInput.addEventListener('change', (e) => addFiles(e.target.files));
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

            const dropdown = document.getElementById(prefix + 'dropdownContent');
            const toggle = document.getElementById(prefix + 'recipientToggle');
            const searchInput = document.getElementById(prefix + 'mainInput');
            const selectAll = document.getElementById(prefix + 'selectAll');

            const groupChecks = Array.from(form.querySelectorAll('.group-item-checkbox'));
            const memberChecks = Array.from(form.querySelectorAll('.member-checkbox'));
            const groupItems = Array.from(form.querySelectorAll('.dropdown-list .item-group'));
            const categoryGroups = Array.from(form.querySelectorAll('.dropdown-list .category-group'));

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

            const setSelectedRecipients = (factionIds, personIds) => {
                const factionSet = new Set((Array.isArray(factionIds) ? factionIds : []).map((value) => String(value).trim()).filter((value) => value !== ''));
                const personSet = new Set((Array.isArray(personIds) ? personIds : []).map((value) => String(value).trim()).filter((value) => value !== ''));

                recipientSearchRequestNo++;
                if (recipientSearchTimer) {
                    clearTimeout(recipientSearchTimer);
                }
                if (searchInput) {
                    searchInput.value = '';
                }
                filterRecipientDropdown('');

                groupChecks.forEach((groupCheck) => {
                    groupCheck.checked = false;
                    groupCheck.indeterminate = false;
                });
                memberChecks.forEach((memberCheck) => {
                    memberCheck.checked = false;
                });

                groupChecks.forEach((groupCheck) => {
                    const groupValue = String(groupCheck.value || '').trim();

                    if (groupValue === '' || !factionSet.has(groupValue)) {
                        return;
                    }

                    groupCheck.checked = true;
                    const groupKey = groupCheck.getAttribute('data-group-key') || '';
                    const members = getMemberChecksByGroupKey(groupKey);
                    members.forEach((memberCheck) => {
                        if (!memberCheck.disabled) {
                            memberCheck.checked = true;
                            syncMemberByPid(memberCheck.value || '', true, memberCheck);
                        }
                    });
                    setGroupCollapsed(groupCheck.closest('.item-group'), false);
                });

                memberChecks.forEach((memberCheck) => {
                    const memberPid = String(memberCheck.value || '').trim();

                    if (memberPid === '' || !personSet.has(memberPid)) {
                        return;
                    }

                    memberCheck.checked = true;
                    syncMemberByPid(memberPid, true, memberCheck);
                    setGroupCollapsed(memberCheck.closest('.item-group'), false);
                });

                groupItems.forEach((groupItem) => {
                    const hasCheckedRecipient = groupItem.querySelector('.group-item-checkbox:checked, .member-checkbox:checked') !== null;
                    setGroupCollapsed(groupItem, !hasCheckedRecipient);
                });

                updateSelectAllState();
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
                    if (item.checked) setGroupCollapsed(item.closest('.item-group'), false);
                    item.indeterminate = false;
                    updateSelectAllState();
                });
            });

            memberChecks.forEach((item) => {
                item.addEventListener('change', () => {
                    syncMemberByPid(item.value || '', item.checked, item);
                    updateSelectAllState();
                });
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
            form.__circularFormApi = {
                setExistingFiles,
                setSelectedRecipients,
            };

            const btnSend = document.getElementById(prefix + 'btnSendNotice');
            const confirmModal = document.getElementById(prefix + 'confirmModal');
            const confirmYes = document.getElementById(prefix + 'btnConfirmYes');
            const confirmNo = document.getElementById(prefix + 'btnConfirmNo');
            const confirmTitle = prefix === 'edit_' ? 'ยืนยันการแก้ไขและส่งใหม่' : 'ยืนยันการส่งหนังสือเวียน';
            const requestFormSubmit = () => {
                if (!form) {
                    return;
                }

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.submit();
            };

            btnSend?.addEventListener('click', (e) => {
                e.preventDefault();

                if (window.AppAlerts && typeof window.AppAlerts.confirm === 'function') {
                    window.AppAlerts.confirm('', {
                        title: confirmTitle,
                        type: 'warning',
                        confirmButtonText: 'ยืนยัน',
                        cancelButtonText: 'ยกเลิก',
                    }).then((approved) => {
                        if (approved) {
                            requestFormSubmit();
                        }
                    });
                    return;
                }

                confirmModal?.classList.add('active');
            });
            confirmNo?.addEventListener('click', () => confirmModal?.classList.remove('active'));
            confirmModal?.addEventListener('click', (e) => {
                if (e.target === confirmModal) confirmModal.classList.remove('active');
            });
            confirmYes?.addEventListener('click', () => requestFormSubmit());

            const recipientModal = document.getElementById(prefix + 'recipientModal');
            const recipientTableBody = document.getElementById(prefix + 'recipientTableBody');
            const btnShowRecipients = document.getElementById(prefix + 'btnShowRecipients');
            const closeRecipients = document.getElementById(prefix + 'closeModalBtn');

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
                    members.forEach((member) => addRecipient(
                        member?.pID,
                        member?.name,
                        member?.preferredGroup || member?.faction || item.getAttribute('data-group-label')
                    ));
                });

                checkedMembers.forEach((item) => addRecipient(
                    item.value,
                    item.getAttribute('data-member-name'),
                    item.getAttribute('data-preferred-group-label') || item.getAttribute('data-group-label')
                ));

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
            closeRecipients?.addEventListener('click', () => recipientModal?.classList.remove('active'));
            recipientModal?.addEventListener('click', (e) => {
                if (e.target === recipientModal) recipientModal.classList.remove('active');
            });
        }

        setupCircularForm('', 'circularComposeForm');
        setupCircularForm('edit_', 'circularEditForm');

        const detailModal = document.getElementById('trackDetailModalOverlay');
        const closeDetailModalBtn = document.getElementById('closeTrackDetailModal');
        const modalSubjectInput = detailModal ? detailModal.querySelector('input[name="subject"]') : null;
        const modalDetailInput = detailModal ? detailModal.querySelector('textarea[name="detail"]') : null;
        const modalLinkInput = detailModal ? detailModal.querySelector('#track_detail_linkURL') : null;
        const modalSenderInput = detailModal ? detailModal.querySelector('#track_detail_senderDisplay') : null;
        const modalSenderFactionInput = detailModal ? detailModal.querySelector('#track_detail_fromFIDDisplay') : null;
        const modalFileSection = detailModal ? detailModal.querySelector('#trackModalFileSection') : null;
        const receiptStatusTableBody = detailModal ? detailModal.querySelector('#trackReceiptStatusTableBody') : null;

        const buildModalFileItem = (file, entityId) => {
            const container = document.createElement('div');
            container.className = 'file-banner';

            const info = document.createElement('div');
            info.className = 'file-info';

            const iconWrap = document.createElement('div');
            iconWrap.className = 'file-icon';
            const mime = String(file?.mimeType || '').toLowerCase();
            iconWrap.innerHTML = mime.includes('pdf') ? '<i class="fa-solid fa-file-pdf"></i>' : mime.includes('image') ? '<i class="fa-solid fa-file-image"></i>' : '<i class="fa-solid fa-file"></i>';

            const text = document.createElement('div');
            text.className = 'file-text';
            const fileName = document.createElement('span');
            fileName.className = 'file-name';
            fileName.textContent = file?.fileName || '-';

            const fileType = document.createElement('span');
            fileType.className = 'file-type';
            fileType.textContent = file?.mimeType || '';

            text.appendChild(fileName);
            text.appendChild(fileType);

            info.appendChild(iconWrap);
            info.appendChild(text);

            const fileUrl = `/public/api/file-download.php?module=circulars&entity_id=${encodeURIComponent(entityId)}&file_id=${encodeURIComponent(file?.fileID || '')}`;

            const viewAction = document.createElement('div');
            viewAction.className = 'file-actions';

            // const viewLink = document.createElement('a');
            // viewLink.href = '#'; 
            // viewLink.innerHTML = '<i class="fa-solid fa-eye"></i>';

            // viewLink.addEventListener('click', (e) => {
            //     e.preventDefault();
            //     if (mime.includes('image')) {

            //     if (previewImage) previewImage.src = fileUrl;
            //         if (previewCaption) previewCaption.textContent = file?.fileName || '-';
            //         if (previewModal) {
            //             previewModal.classList.add('active');
            //             previewModal.style.zIndex = '99999';
            //         }
            //     } else {
            //         window.open(fileUrl, '_blank', 'noopener');
            //     }
            // });

            const viewLink = document.createElement('a');
            viewLink.href = `/public/api/file-download.php?module=circulars&entity_id=${encodeURIComponent(entityId)}&file_id=${encodeURIComponent(file?.fileID || '')}`;
            viewLink.target = '_blank';
            viewLink.rel = 'noopener';
            viewLink.innerHTML = '<i class="fa-solid fa-eye"></i>';

            viewAction.appendChild(viewLink);

            const downloadAction = document.createElement('div');
            downloadAction.className = 'file-actions';
            downloadAction.innerHTML = `<a href="${fileUrl}&download=1"><i class="fa-solid fa-download"></i></a>`;

            container.appendChild(info);
            container.appendChild(viewAction);
            container.appendChild(downloadAction);
            return container;
        };

        const renderModalFiles = (files, entityId) => {
            if (!modalFileSection) return;
            modalFileSection.innerHTML = '';
            if (!Array.isArray(files) || files.length === 0) {
                modalFileSection.innerHTML = '<div class="content-details-sec" style="margin: 0;"><p id="modalDetail">-</p></div>';
                return;
            }
            files.forEach((file) => modalFileSection.appendChild(buildModalFileItem(file, entityId)));
        };

        const renderReceiptRows = (stats) => {
            if (!receiptStatusTableBody) return;
            receiptStatusTableBody.innerHTML = '';
            if (!Array.isArray(stats) || stats.length === 0) {
                receiptStatusTableBody.innerHTML = '<tr><td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td></tr>';
                return;
            }
            stats.forEach((item) => {
                const row = document.createElement('tr');
                const nameCell = document.createElement('td');
                nameCell.textContent = item?.name || '-';

                const statusCell = document.createElement('td');
                const statusPill = document.createElement('span');
                statusPill.className = `status-pill ${item?.pill || 'pending'}`;
                statusPill.textContent = item?.status || 'ยังไม่อ่าน';
                statusCell.appendChild(statusPill);

                const readAtCell = document.createElement('td');
                readAtCell.textContent = item?.readAt || '-';

                row.appendChild(nameCell);
                row.appendChild(statusCell);
                row.appendChild(readAtCell);
                receiptStatusTableBody.appendChild(row);
            });
        };

        const buildModalLinkHref = (rawValue) => {
            const value = String(rawValue || '').trim();

            if (value === '' || value === '-') {
                return '';
            }

            if (/^https?:\/\//i.test(value)) {
                return value;
            }

            if (/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) {
                return value;
            }

            if (/\s/.test(value)) {
                return '';
            }

            return `https://${value}`;
        };

        const openTrackDetailModal = (btn) => {
            if (!detailModal || !btn) {
                return;
            }

            const circularId = String(btn.getAttribute('data-circular-id') || '').trim();
            let stats = [];
            let files = [];

            try {
                stats = JSON.parse(String(btn.getAttribute('data-read-stats') || '[]'));
            } catch (e) {
                stats = [];
            }

            try {
                files = JSON.parse(String(btn.getAttribute('data-files') || '[]'));
            } catch (e) {
                files = [];
            }

            if (modalSubjectInput) {
                modalSubjectInput.value = btn.getAttribute('data-subject') || '-';
            }
            if (modalDetailInput) {
                modalDetailInput.value = btn.getAttribute('data-detail') || '-';
            }
            if (modalLinkInput) {
                const rawLink = String(btn.getAttribute('data-link') || '').trim();
                const displayLink = rawLink !== '' ? rawLink : '-';
                const hrefLink = buildModalLinkHref(rawLink);
                modalLinkInput.textContent = displayLink;
                modalLinkInput.setAttribute('title', displayLink);

                if (hrefLink !== '') {
                    modalLinkInput.href = hrefLink;
                    modalLinkInput.classList.add('is-clickable');
                    modalLinkInput.removeAttribute('aria-disabled');
                } else {
                    modalLinkInput.href = '#';
                    modalLinkInput.classList.remove('is-clickable');
                    modalLinkInput.setAttribute('aria-disabled', 'true');
                }
            }
            if (modalSenderInput) {
                modalSenderInput.value = btn.getAttribute('data-sender-name') || '-';
            }
            if (modalSenderFactionInput) {
                modalSenderFactionInput.value = btn.getAttribute('data-sender-faction') || '-';
            }

            renderModalFiles(files, circularId);
            renderReceiptRows(stats);
            detailModal.style.display = 'flex';
        };

        const editModal = document.getElementById('modalEditOverlay');
        const closeEditModalBtn = document.getElementById('closeModalEdit');
        const editForm = document.getElementById('circularEditForm');
        const editTargetInput = document.getElementById('editTargetCircularId');

        const openTrackEditModal = (btn) => {
            if (!editModal || !btn) {
                return;
            }

            const circularId = String(btn.getAttribute('data-circular-id') || '').trim();
            if (editTargetInput) editTargetInput.value = circularId;

            const subjectInput = editModal.querySelector('input[name="subject"]');
            const detailInput = editModal.querySelector('textarea[name="detail"]');
            const linkInput = editModal.querySelector('#edit_linkURL');
            const editFormApi = editForm && typeof editForm.__circularFormApi === 'object' ? editForm.__circularFormApi : null;
            let factionIds = [];
            let personIds = [];
            let files = [];

            try {
                factionIds = JSON.parse(String(btn.getAttribute('data-faction-ids') || '[]'));
            } catch (e) {
                factionIds = [];
            }

            try {
                personIds = JSON.parse(String(btn.getAttribute('data-person-ids') || '[]'));
            } catch (e) {
                personIds = [];
            }

            try {
                files = JSON.parse(String(btn.getAttribute('data-files') || '[]'));
            } catch (e) {
                files = [];
            }

            if (subjectInput) subjectInput.value = String(btn.getAttribute('data-subject') || '').trim();
            if (detailInput) detailInput.value = String(btn.getAttribute('data-detail') || '').trim();
            if (linkInput) linkInput.value = String(btn.getAttribute('data-link') || '').trim();

            if (editFormApi?.setSelectedRecipients) {
                editFormApi.setSelectedRecipients(factionIds, personIds);
            }

            if (editFormApi?.setExistingFiles) {
                editFormApi.setExistingFiles(files, circularId);
            }

            editModal.style.display = 'flex';
        };

        document.addEventListener('click', (event) => {
            const detailTrigger = event.target.closest('.js-open-circular-modal');
            if (detailTrigger) {
                event.preventDefault();
                openTrackDetailModal(detailTrigger);
                return;
            }

            const editTrigger = event.target.closest('.js-open-edit-modal');
            if (editTrigger) {
                event.preventDefault();
                openTrackEditModal(editTrigger);
            }
        });

        closeDetailModalBtn?.addEventListener('click', () => {
            if (detailModal) detailModal.style.display = 'none';
        });
        detailModal?.addEventListener('click', (event) => {
            if (event.target === detailModal) detailModal.style.display = 'none';
        });
        modalLinkInput?.addEventListener('click', (event) => {
            if (modalLinkInput.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
            }
        });

        closeEditModalBtn?.addEventListener('click', () => {
            if (editModal) editModal.style.display = 'none';
        });
        editModal?.addEventListener('click', (event) => {
            if (event.target === editModal) editModal.style.display = 'none';
        });

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
