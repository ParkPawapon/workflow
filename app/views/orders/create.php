<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$values = (array) ($values ?? []);
$display_order_no = trim((string) ($display_order_no ?? ''));
$issuer_name = trim((string) ($issuer_name ?? ''));
$faction_options = (array) ($faction_options ?? []);
$edit_order = $edit_order ?? null;
$edit_order_id = (int) ($edit_order_id ?? 0);
$is_edit_mode = $edit_order_id > 0 && !empty($edit_order);

$values = array_merge([
    'subject' => '',
    'effective_date' => '',
    'order_date' => '',
    'group_fid' => '',
], $values);

$page_title = 'ยินดีต้อนรับ';
$page_subtitle = 'คำสั่งราชการ / ออกเลขคำสั่งราชการ';
$submit_label = $is_edit_mode ? 'บันทึกการแก้ไข' : 'บันทึกออกเลข';
$is_track_active = (bool) ($is_track_active ?? false);
$filter_query = (string) ($filter_query ?? '');
$filter_status = (string) ($filter_status ?? 'all');
$filter_sort = (string) ($filter_sort ?? 'newest');
$sent_items = (array) ($sent_items ?? []);
$status_map = (array) ($status_map ?? []);
$read_stats_map = (array) ($read_stats_map ?? []);
$detail_map = (array) ($detail_map ?? []);
$edit_modal_attachments_map = (array) ($edit_modal_attachments_map ?? []);
$send_modal_payload_map = (array) ($send_modal_payload_map ?? []);
$send_modal_values = (array) ($send_modal_values ?? [
    'faction_ids' => [],
    'role_ids' => [],
    'person_ids' => [],
]);
$send_modal_open_order_id = (int) ($send_modal_open_order_id ?? 0);
$send_modal_summary = (array) ($send_modal_summary ?? [
    'selected_sources' => 0,
    'unique_recipients' => 0,
]);
$send_picker_factions = (array) ($send_picker_factions ?? []);
$send_picker_roles = (array) ($send_picker_roles ?? []);
$send_picker_all_teachers = (array) ($send_picker_all_teachers ?? []);
$send_picker_teachers = (array) ($send_picker_teachers ?? []);
if ($send_picker_all_teachers === []) {
    $send_picker_all_teachers = $send_picker_teachers;
}
$send_picker_faction_member_map = (array) ($send_picker_faction_member_map ?? []);
$send_picker_role_member_map = (array) ($send_picker_role_member_map ?? []);
$selected_send_faction_ids = array_map('strval', (array) ($send_modal_values['faction_ids'] ?? []));
$selected_send_role_ids = array_map('strval', (array) ($send_modal_values['role_ids'] ?? []));
$selected_send_person_ids = array_map('strval', (array) ($send_modal_values['person_ids'] ?? []));
$send_is_selected = static function (string $value, array $selected): bool {
    return in_array($value, $selected, true);
};
$send_teacher_name_map = [];
$send_teacher_faction_map = [];
$send_teacher_department_map = [];
$send_faction_groups = [];
$send_department_groups = [];
$send_special_groups = [];

foreach ($send_picker_all_teachers as $send_teacher_row) {
    $send_pid = trim((string) ($send_teacher_row['pID'] ?? ''));

    if ($send_pid === '') {
        continue;
    }
    $send_teacher_name_map[$send_pid] = trim((string) ($send_teacher_row['fName'] ?? ''));
    $send_teacher_faction_map[$send_pid] = trim((string) ($send_teacher_row['factionName'] ?? ''));
    $send_teacher_department_map[$send_pid] = trim((string) ($send_teacher_row['departmentName'] ?? ''));
}

foreach ($send_picker_factions as $send_faction_row) {
    $faction_id = (int) ($send_faction_row['fID'] ?? 0);
    $faction_name = trim((string) ($send_faction_row['fName'] ?? ''));

    if ($faction_id <= 0 || $faction_name === '' || mb_stripos($faction_name, 'ฝ่ายบริหาร') !== false) {
        continue;
    }

    $member_rows = [];
    $member_payload = [];
    $member_pid_list = [];
    $member_ids = (array) ($send_picker_faction_member_map[(string) $faction_id] ?? []);

    foreach ($member_ids as $member_pid_raw) {
        $member_pid = trim((string) $member_pid_raw);
        $member_name = trim((string) ($send_teacher_name_map[$member_pid] ?? ''));

        if ($member_pid === '' || $member_name === '') {
            continue;
        }

        $member_department = trim((string) ($send_teacher_department_map[$member_pid] ?? ''));
        $member_faction = trim((string) ($send_teacher_faction_map[$member_pid] ?? $faction_name));
        if ($member_faction === '') {
            $member_faction = $faction_name;
        }

        $member_rows[] = [
            'pID' => $member_pid,
            'name' => $member_name,
            'department' => $member_department,
            'faction' => $member_faction,
        ];
        $member_payload[] = [
            'pID' => $member_pid,
            'name' => $member_name,
            'department' => $member_department,
            'faction' => $member_faction,
        ];
        $member_pid_list[] = $member_pid;
    }

    $members_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($members_json === false) {
        $members_json = '[]';
    }

    $send_faction_groups[] = [
        'id' => (string) $faction_id,
        'name' => $faction_name,
        'members' => $member_rows,
        'members_json' => $members_json,
        'member_pids_attr' => implode(',', $member_pid_list),
    ];
}

$department_index = [];

foreach ($send_picker_teachers as $send_teacher_row) {
    $department_id = (int) ($send_teacher_row['dID'] ?? 0);
    $department_name = trim((string) ($send_teacher_row['departmentName'] ?? ''));
    $member_pid = trim((string) ($send_teacher_row['pID'] ?? ''));
    $member_name = trim((string) ($send_teacher_row['fName'] ?? ''));

    if ($department_id <= 0 || $department_name === '' || $member_pid === '' || $member_name === '') {
        continue;
    }

    $department_key = (string) $department_id;

    if (!isset($department_index[$department_key])) {
        $department_index[$department_key] = [
            'id' => $department_id,
            'name' => $department_name,
            'members' => [],
        ];
    }

    $department_index[$department_key]['members'][$member_pid] = [
        'pID' => $member_pid,
        'name' => $member_name,
        'department' => $department_name,
        'faction' => trim((string) ($send_teacher_faction_map[$member_pid] ?? '')),
    ];
}

foreach ($department_index as $department_row) {
    $department_members = array_values((array) ($department_row['members'] ?? []));
    $department_name = (string) ($department_row['name'] ?? '');

    if ($department_name === '' || empty($department_members)) {
        continue;
    }

    $member_payload = [];
    $member_pid_list = [];

    foreach ($department_members as $department_member) {
        $member_pid = trim((string) ($department_member['pID'] ?? ''));
        $member_name = trim((string) ($department_member['name'] ?? ''));

        if ($member_pid === '' || $member_name === '') {
            continue;
        }

        $member_department = trim((string) ($department_member['department'] ?? $department_name));
        if ($member_department === '') {
            $member_department = $department_name;
        }
        $member_faction = trim((string) ($department_member['faction'] ?? ''));

        $member_payload[] = [
            'pID' => $member_pid,
            'name' => $member_name,
            'department' => $member_department,
            'faction' => $member_faction,
        ];
        $member_pid_list[] = $member_pid;
    }

    if (empty($member_payload)) {
        continue;
    }

    $members_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($members_json === false) {
        $members_json = '[]';
    }

    $send_department_groups[] = [
        'id' => (int) ($department_row['id'] ?? 0),
        'name' => $department_name,
        'members' => $member_payload,
        'members_json' => $members_json,
        'member_pids_attr' => implode(',', $member_pid_list),
    ];
}

usort($send_department_groups, static function (array $a, array $b): int {
    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$special_group_key = 'special-subject-head';
$special_group_name = 'หัวหน้ากลุ่มสาระ';
$special_member_payload = [];
$special_member_pid_list = [];

foreach ($send_picker_all_teachers as $send_teacher_row) {
    $member_pid = trim((string) ($send_teacher_row['pID'] ?? ''));
    $member_name = trim((string) ($send_teacher_row['fName'] ?? ''));
    $position_id = (int) ($send_teacher_row['positionID'] ?? 0);
    $position_name = trim((string) ($send_teacher_row['positionName'] ?? ''));
    $is_subject_head = mb_stripos($position_name, 'หัวหน้ากลุ่มสาระ') !== false || $position_id === 5;

    if ($member_pid === '' || $member_name === '' || !$is_subject_head) {
        continue;
    }
    if (isset($special_member_payload[$member_pid])) {
        continue;
    }

    $member_department = trim((string) ($send_teacher_row['departmentName'] ?? $send_teacher_department_map[$member_pid] ?? ''));
    $member_faction = trim((string) ($send_teacher_row['factionName'] ?? $send_teacher_faction_map[$member_pid] ?? ''));

    $special_member_payload[$member_pid] = [
        'pID' => $member_pid,
        'name' => $member_name,
        'department' => $member_department,
        'faction' => $member_faction,
    ];
    $special_member_pid_list[] = $member_pid;
}

if (!empty($special_member_payload)) {
    $special_member_payload_list = array_values($special_member_payload);
    usort($special_member_payload_list, static function (array $a, array $b): int {
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    $special_members_json = json_encode($special_member_payload_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($special_members_json === false) {
        $special_members_json = '[]';
    }

    $send_special_groups[] = [
        'key' => $special_group_key,
        'name' => $special_group_name,
        'members' => $special_member_payload_list,
        'members_json' => $special_members_json,
        'member_pids_attr' => implode(',', $special_member_pid_list),
    ];
}
$default_group_fid = '';

$issuer_display_name = $issuer_name !== '' ? $issuer_name : '-';

if (!empty($faction_options)) {
    $first_group_fid = array_key_first($faction_options);
    $default_group_fid = $first_group_fid !== null ? (string) $first_group_fid : '';
}

$selected_group_fid = trim((string) ($values['group_fid'] ?? ''));
if ($selected_group_fid === '' && $default_group_fid !== '') {
    $selected_group_fid = $default_group_fid;
}
$selected_group_name = (string) ($faction_options[$selected_group_fid] ?? '');

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

$format_thai_datetime_split = static function (?string $date_value) use ($thai_months_full): array {
    if ($date_value === null || trim($date_value) === '') {
        return [
            'full' => '-',
            'date' => '-',
            'time' => '-',
        ];
    }

    $timestamp = strtotime($date_value);

    if ($timestamp === false) {
        $fallback = trim((string) $date_value);
        return [
            'full' => $fallback !== '' ? $fallback : '-',
            'date' => $fallback !== '' ? $fallback : '-',
            'time' => '-',
        ];
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp) + 543;
    $month_label = $thai_months_full[$month] ?? '';
    $date_line = $day . ' ' . $month_label . ' ' . $year;
    $time_line = date('H:i', $timestamp) . ' น.';

    return [
        'full' => $date_line . ' ' . $time_line,
        'date' => $date_line,
        'time' => $time_line,
    ];
};

$format_thai_date = static function (?string $date_value) use ($thai_months): string {
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

    return $day . ' ' . $month_label . ' ' . $year;
};

$parse_order_meta = static function (?string $detail_text): array {
    $text = trim((string) $detail_text);
    $meta = [
        'effective_date' => '',
        'order_date' => '',
        'issuer_name' => '',
        'group_name' => '',
    ];

    if ($text === '') {
        return $meta;
    }

    if (preg_match('/^ทั้งนี้ตั้งแต่วันที่:\s*(.+)$/m', $text, $matches) === 1) {
        $meta['effective_date'] = trim((string) ($matches[1] ?? ''));
    }

    if (preg_match('/^สั่ง ณ วันที่:\s*(.+)$/m', $text, $matches) === 1) {
        $meta['order_date'] = trim((string) ($matches[1] ?? ''));
    }

    if (preg_match('/^ผู้(?:ออก|สร้าง)เลขคำสั่ง:\s*(.+)$/m', $text, $matches) === 1) {
        $value = trim((string) ($matches[1] ?? ''));
        $meta['issuer_name'] = $value !== '-' ? $value : '';
    }

    if (preg_match('/^กลุ่ม:\s*(.+)$/m', $text, $matches) === 1) {
        $value = trim((string) ($matches[1] ?? ''));
        $meta['group_name'] = $value !== '-' ? $value : '';
    }

    return $meta;
};

ob_start();
?>
<style>
    .content-order.create .order-create-subject-group {
        width: 100%;
    }

    .circular-track-modal-host {
        width: 0;
        height: 0;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec {
        align-items: flex-start;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .more-details {
        flex: 1;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input {
        width: 100%;
        height: 50px;
        border: none;
        border-radius: 8px;
        border: 1px solid var(--color-secondary);
        background-color: var(--color-neutral-lightest);
        padding: 10px 20px;
        font-size: var(--font-size-body-1);
        color: var(--color-secondary);
        transition: 0.4s;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input::placeholder {
        color: var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input:hover,
    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input:active,
    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input:focus {
        outline: none;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input:disabled {
        width: 100%;
        min-height: 50px;
        font-weight: 600;
        cursor: not-allowed;
        color: var(--color-neutral-dark);
        background-color: rgba(var(--rgb-neutral-medium), 0.25);
        border: none;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec input.order-no-display[disabled] {
        background-color: #eef3ff;
        color: var(--color-primary-dark);
        font-weight: 600;
        cursor: not-allowed;
        border: 1px solid var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal>.content-topic-sec:first-of-type {
        border-bottom: none;
        padding-bottom: 0;
        margin-bottom: 20px;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-select-wrapper {
        position: relative;
        width: 100%;
        -webkit-user-select: none;
        user-select: none;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-select-trigger {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 50px;
        padding: 0 20px;
        border-radius: 6px;
        background-color: var(--color-neutral-lightest);
        color: var(--color-secondary);
        border: 1px solid var(--color-secondary);
        cursor: pointer;
        transition: all 0.2s;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-select-trigger .select-value {
        margin: 0;
        font-size: var(--font-size-body-1);
        color: var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-select-wrapper i {
        font-size: var(--font-size-body-1);
        display: flex;
        justify-content: center;
        align-items: center;
        transition: transform 0.4s;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-select-wrapper.open i {
        transform: rotate(180deg);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-options {
        position: absolute;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        top: 80%;
        left: 0;
        right: 0;
        background: var(--color-neutral-lightest);
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(var(--rgb-neutral-dark), 0.25);
        z-index: 111;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-25px);
        transition: all 0.2s ease;
        overflow: hidden;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-select-wrapper.open .custom-options {
        opacity: 1;
        visibility: visible;
        transform: translateY(10px);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-option {
        padding: 12px 20px;
        margin: 0;
        font-size: var(--font-size-body-1);
        color: var(--color-secondary);
        cursor: pointer;
        transition: background 0.3s;
        width: 100%;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-option:hover {
        background-color: rgba(var(--rgb-primary-dark), 0.1);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-topic-sec .custom-option.selected {
        font-weight: bold;
        background-color: rgba(var(--rgb-primary-dark), 0.1);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec {
        margin: 20px 0 0;
        border-bottom: none !important;
        padding-bottom: 0;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .existing-file-section {
        margin: 0 0 12px;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec label {
        display: block;
        margin: 0 0 10px;
        font-size: var(--font-size-body-1);
        color: var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .existing-file-empty {
        margin: 0;
        font-size: var(--font-size-body-2);
        color: var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
        margin: 0 0 20px;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout input {
        display: none;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box {
        width: 50%;
        height: 440px;
        border-radius: 8px;
        background-color: var(--color-neutral-lightest);
        border: 2px dashed var(--color-secondary);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        cursor: pointer;
        transition: 0.3s;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box:hover,
    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box:active,
    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box:focus,
    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box.active {
        background-color: rgba(var(--rgb-neutral-dark), 0.04);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box i {
        font-size: var(--font-size-h1);
        color: var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .upload-layout .upload-box p {
        font-size: var(--font-size-body-1);
        font-weight: bold;
        color: var(--color-secondary);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .file-hint {
        font-size: var(--font-size-h1);
        color: var(--color-danger);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .form-group.row {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 20px;
        margin: 0 0 50px;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .form-group.row button {
        width: 140px;
        height: 40px;
        border-radius: 8px;
        border: none;
        background-color: var(--color-secondary);
        font-size: var(--font-size-body-1);
        color: var(--color-neutral-lightest);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: 0.3s;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .form-group.row button:hover {
        background-color: var(--color-primary-deep);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .form-group.row button p {
        margin: 0;
        color: var(--color-neutral-lightest);
        font-size: var(--font-size-body-2);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .form-group.row .file-hint {
        font-size: var(--font-size-body-1);
        font-weight: bold;
        color: var(--color-danger);
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .file-list {
        display: flex;
        flex-direction: column;
        flex-wrap: wrap;
        gap: 10px;
        margin: 0 0 20px;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .file-item-wrapper {
        display: flex;
        align-items: center;
        width: 425px;
        gap: 15px;
        animation: fadeIn 0.3s ease;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .delete-btn {
        background: none;
        border: none;
        color: var(--color-danger);
        font-size: var(--font-size-h4);
        cursor: pointer;
        transition: transform 0.2s;
    }

    .circular-track-modal-host #modalOrderEditOverlay .content-modal .content-file-sec .delete-btn:hover {
        transform: scale(1.2);
    }

    .circular-track-modal-host #modalOrderSendOverlay .content-modal {
        max-height: 72vh;
        overflow: auto;
    }

    .circular-track-modal-host #modalOrderSendOverlay .content-topic-sec {
        align-items: flex-start;
    }

    .circular-track-modal-host #modalOrderSendOverlay .content-topic-sec .more-details {
        flex: 1;
    }

    .circular-track-modal-host #modalOrderSendOverlay .content-topic-sec input {
        width: 100%;
        height: 50px;
        border-radius: 8px;
        border: 1px solid var(--color-secondary);
        background-color: var(--color-neutral-lightest);
        padding: 10px 20px;
        color: var(--color-secondary);
        font-size: var(--font-size-body-1);
    }

    .circular-track-modal-host #modalOrderSendOverlay .content-topic-sec input:disabled {
        background-color: #eef3ff;
        color: var(--color-primary-dark);
        font-weight: 600;
        cursor: not-allowed;
    }

    .circular-track-modal-host #modalOrderSendOverlay .orders-send-modal-shell .orders-send-summary {
        margin: 16px 0;
    }

    .status-pill.primary {
        background-color: rgba(var(--rgb-primary-dark), 0.15);
        border: 1px solid var(--color-primary-dark);
        color: var(--color-primary-dark);
    }

    .table-responsive.circular-my-table-wrap.order-create .circular-my-table th:nth-child(3),
    .table-responsive.circular-my-table-wrap.order-create .circular-my-table td:nth-child(3) {
        text-align: left !important;
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

    .circular-track-modal-host #modalOrderSendOverlay .orders-send-modal-shell .booking-actions {
        justify-content: flex-end;
        margin-top: 18px;
    }

    .circular-track-modal-host #modalOrderSendOverlay .orders-send-track-table td:nth-child(2),
    .circular-track-modal-host #modalOrderSendOverlay .orders-send-track-table td:nth-child(3),
    .circular-track-modal-host #modalOrderSendOverlay .orders-send-track-table th:nth-child(2),
    .circular-track-modal-host #modalOrderSendOverlay .orders-send-track-table th:nth-child(3) {
        text-align: center;
    }

    .circular-track-modal-host #modalOrderSendOverlay .orders-send-track-empty {
        text-align: center;
    }

    .circular-track-modal-host #modalOrderViewOverlay .content-modal {
        max-height: 72vh;
        overflow: auto;
    }

    .circular-track-modal-host #modalOrderViewOverlay .content-topic-sec {
        align-items: flex-start;
    }

    .circular-track-modal-host #modalOrderViewOverlay .content-topic-sec .more-details {
        flex: 1;
    }

    .circular-track-modal-host #modalOrderViewOverlay .content-topic-sec input {
        width: 100%;
        height: 50px;
        border-radius: 8px;
        border: 1px solid var(--color-secondary);
        background-color: var(--color-neutral-lightest);
        padding: 10px 20px;
        color: var(--color-secondary);
        font-size: var(--font-size-body-1);
    }

    .circular-track-modal-host #modalOrderViewOverlay .content-topic-sec input:disabled {
        background-color: #eef3ff;
        color: var(--color-primary-dark);
        font-weight: 600;
        cursor: not-allowed;
    }

    .circular-track-modal-host #modalOrderViewOverlay .orders-send-modal-shell .orders-send-summary {
        margin: 16px 0;
    }

    .circular-track-modal-host #modalOrderViewOverlay .orders-send-modal-shell .booking-actions {
        justify-content: flex-end;
        margin-top: 18px;
    }

    .circular-track-modal-host #modalOrderViewOverlay .orders-send-track-table td:nth-child(2),
    .circular-track-modal-host #modalOrderViewOverlay .orders-send-track-table td:nth-child(3),
    .circular-track-modal-host #modalOrderViewOverlay .orders-send-track-table th:nth-child(2),
    .circular-track-modal-host #modalOrderViewOverlay .orders-send-track-table th:nth-child(3) {
        text-align: center;
    }

    .circular-track-modal-host #modalOrderViewOverlay .orders-send-track-empty {
        text-align: center;
    }



    @media screen and (max-width: 1023px) {

        .content-order .form-group input.order-no-display[disabled],
        .content-order .form-group input:disabled,
        .content-order .form-group select:disabled {
            height: 30px !important;
            min-height: 30px !important;
            max-height: 30px !important;
        }

        .circular-track-modal-host #modalOrderSendOverlay .content-topic-sec input {
            font-size: var(--font-size-desc-1);
            padding: 0 10px;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-topic-sec:nth-child(2) {
            border-bottom-width: 1px;
            gap: 10px;
            margin: 0 0 10px;
        }

        .content-order .form-group {
            gap: 20px;
        }
    }

    @media screen and (max-width: 768px) {
        .circular-track-modal-host #modalOrderSendOverlay .content-topic-sec input {
            font-size: var(--font-size-desc-3);
            padding: 0 10px;
        }

        .content-order .form-group {
            gap: 10px;
        }

        .order-mine-list-header {
            margin-top: 20px;
        }

        #modalOrderTrackSection .custom-table tr td {
            padding: 0 5px;
        }
    }


    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(1),
    .table-circular-notice-index table thead th:nth-child(3),
    .table-circular-notice-index table tbody td:nth-child(3),
    .table-responsive.circular-my-table-wrap.order-create .circular-my-table th:nth-child(3),
    .table-responsive.circular-my-table-wrap.order-create .circular-my-table td:nth-child(3) {
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

    @media screen and (max-width: 1024px) {

        .table-circular-notice-index table thead th:nth-child(1),
        .table-circular-notice-index table tbody td:nth-child(1),
        .table-circular-notice-index table thead th:nth-child(3),
        .table-circular-notice-index table tbody td:nth-child(3),
        .table-responsive.circular-my-table-wrap.order-create .circular-my-table th:nth-child(3),
        .table-responsive.circular-my-table-wrap.order-create .circular-my-table td:nth-child(3) {
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
            width: 60px !important;
            min-width: 60px !important;
            max-width: 60px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .booking-table td:nth-child(2) {
            width: 500px !important;
            min-width: 500px !important;
            max-width: 500px !important;
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
    <h1><?= h($page_title) ?></h1>
    <p><?= h($page_subtitle) ?></p>
</div>

<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
            onclick="openTab('orderReceive', event)">ออกเลขคำสั่ง</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>" onclick="openTab('orderMine', event)">เลขคำสั่งของฉัน</button>
    </div>
</div>

<div class="content-order create tab-content <?= $is_track_active ? '' : 'active' ?>" id="orderReceive">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <?php if ($is_edit_mode): ?>
            <input type="hidden" name="order_id" value="<?= (int) $edit_order_id ?>">
        <?php endif; ?>

        <div class="form-group row">
            <div class="input-group order-create-subject-group">
                <p><strong>เรื่อง</strong></p>
                <input
                    type="text"
                    name="subject"
                    value="<?= h((string) ($values['subject'] ?? '')) ?>"
                    placeholder="ระบุหัวข้อคำสั่ง"
                    maxlength="300"
                    required>
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>ทั้งนี้ตั้งแต่วันที่</strong></p>
                <input
                    type="date"
                    name="effective_date"
                    value="<?= h((string) ($values['effective_date'] ?? '')) ?>"
                    required>
            </div>
            <div class="input-group">
                <p><strong>สั่ง ณ วันที่</strong></p>
                <input
                    type="date"
                    name="order_date"
                    value="<?= h((string) ($values['order_date'] ?? '')) ?>"
                    required>
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>ผู้สร้างเลขคำสั่ง</strong></p>
                <input type="text" class="order-no-display" value="<?= h($issuer_display_name) ?>" disabled>
            </div>
            <div class="input-group">
                <p><strong>กลุ่ม</strong></p>
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($selected_group_name !== '' ? $selected_group_name : 'เลือกกลุ่ม') ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($faction_options as $fid => $name): ?>
                            <?php $group_fid_value = (string) $fid; ?>
                            <div class="custom-option<?= $group_fid_value === $selected_group_fid ? ' selected' : '' ?>" data-value="<?= h($group_fid_value) ?>">
                                <?= h((string) $name) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="group_fid" value="<?= h($selected_group_fid) ?>">
                </div>
            </div>
        </div>

        <div class="form-group button">
            <div class="input-group">
                <button
                    class="submit"
                    type="submit"
                    data-confirm="<?= h($is_edit_mode ? 'ยืนยันการบันทึกการแก้ไขคำสั่งราชการนี้ใช่หรือไม่?' : 'ยืนยันการบันทึกออกเลขคำสั่งราชการนี้ใช่หรือไม่?') ?>"
                    data-confirm-title="<?= h($is_edit_mode ? 'ยืนยันการบันทึกการแก้ไข' : 'ยืนยันการบันทึกออกเลข') ?>"
                    data-confirm-ok="ยืนยัน"
                    data-confirm-cancel="ยกเลิก">
                    <p><?= h($submit_label) ?></p>
                </button>
            </div>
        </div>
    </form>
</div>

<section class="enterprise-card tab-content <?= $is_track_active ? 'active' : '' ?>" id="orderMine">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" class="circular-my-filter-grid">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($filter_query) ?>"
                    placeholder="ค้นหาเลขคำสั่งหรือเรื่อง" autocomplete="off">
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value">
                            <?php
                            $status_label = 'ทั้งหมด';

                            if ($filter_status === 'waiting_attachment') {
                                $status_label = 'รอการแนบไฟล์';
                            } elseif ($filter_status === 'complete') {
                                $status_label = 'แนบไฟล์สำเร็จ';
                            }
                            echo h($status_label);
                            ?>
                        </p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option" data-value="all">ทั้งหมด</div>
                        <div class="custom-option" data-value="waiting_attachment">รอการแนบไฟล์</div>
                        <div class="custom-option" data-value="complete">แนบไฟล์สำเร็จ</div>
                    </div>

                    <select class="form-input" name="status">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                        <option value="waiting_attachment" <?= $filter_status === 'waiting_attachment' ? 'selected' : '' ?>>รอการแนบไฟล์</option>
                        <option value="complete" <?= $filter_status === 'complete' ? 'selected' : '' ?>>แนบไฟล์สำเร็จ</option>
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
            <h2 class="enterprise-card-title">รายการคำสั่งของฉัน</h2>
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
                    <th>เรื่อง</th>
                    <th>สถานะ</th>
                    <th>วันที่ดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sent_items)) : ?>
                    <tr>
                        <td colspan="4" class="enterprise-empty">ไม่พบรายการคำสั่งราชการของฉัน</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sent_items as $item) : ?>
                        <?php
                        $order_id = (int) ($item['orderID'] ?? 0);
                        $order_no = trim((string) ($item['orderNo'] ?? ''));
                        $detail_text = trim((string) ($item['detail'] ?? ''));
                        $parsed_meta = $parse_order_meta($detail_text);
                        $effective_date_raw = trim((string) ($parsed_meta['effective_date'] ?? ''));
                        $order_date_raw = trim((string) ($parsed_meta['order_date'] ?? ''));
                        $effective_date_display = $format_thai_date((string) ($parsed_meta['effective_date'] ?? ''));
                        $order_date_display = $format_thai_date((string) ($parsed_meta['order_date'] ?? ''));
                        $issuer_name_from_detail = trim((string) ($parsed_meta['issuer_name'] ?? ''));
                        $issuer_for_modal = $issuer_name_from_detail !== '' ? $issuer_name_from_detail : $issuer_display_name;
                        $group_name = trim((string) ($parsed_meta['group_name'] ?? ''));
                        $group_fid_for_modal = '';
                        if ($group_name !== '') {
                            $group_fid_found = array_search($group_name, $faction_options, true);
                            if ($group_fid_found !== false) {
                                $group_fid_for_modal = (string) $group_fid_found;
                            }
                        }
                        if ($group_fid_for_modal === '') {
                            $group_fid_for_modal = $default_group_fid;
                        }
                        $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
                        $status_meta = $status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
                        $created_at = (string) ($item['createdAt'] ?? '');
                        $date_display_parts = $format_thai_datetime_split($created_at);
                        $date_display = (string) ($date_display_parts['full'] ?? '-');
                        $date_display_date = (string) ($date_display_parts['date'] ?? '-');
                        $date_display_time = (string) ($date_display_parts['time'] ?? '-');
                        $share_token = trim((string) ($item['shareToken'] ?? ''));
                        $share_url = $share_token !== '' && function_exists('orders_create_public_share_url')
                            ? orders_create_public_share_url($share_token)
                            : '';
                        $show_attach_action = $order_id > 0 && $status_key === ORDER_STATUS_WAITING_ATTACHMENT;
                        $show_send_action = $order_id > 0 && $status_key === ORDER_STATUS_COMPLETE;
                        $show_recipients_action = $order_id > 0 && $status_key === ORDER_STATUS_SENT;
                        $show_share_action = $order_id > 0 && in_array($status_key, [ORDER_STATUS_COMPLETE, ORDER_STATUS_SENT], true);
                        $read_done_for_row = 0;
                        $read_total_for_row = 0;
                        if ($show_recipients_action) {
                            $send_payload_for_row = (array) ($send_modal_payload_map[(string) $order_id] ?? []);
                            $read_done_for_row = max(0, (int) ($send_payload_for_row['readDone'] ?? 0));
                            $read_total_for_row = max(0, (int) ($send_payload_for_row['readTotal'] ?? 0));
                        }
                        $order_existing_files = (array) ($edit_modal_attachments_map[(string) $order_id] ?? []);
                        $order_existing_files_json = json_encode($order_existing_files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if ($order_existing_files_json === false) {
                            $order_existing_files_json = '[]';
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="circular-my-actions booking-action-group">
                                    <?php if ($show_share_action) : ?>
                                        <?php if ($share_url !== '') : ?>
                                            <button
                                                class="booking-action-btn secondary"
                                                type="button"
                                                data-order-share-copy="<?= h($share_url) ?>"
                                                title="คัดลอกลิงก์"
                                                aria-label="คัดลอกลิงก์">
                                                <i class="fa-solid fa-copy"></i>
                                                <span class="tooltip">คัดลอกลิงก์</span>
                                            </button>
                                        <?php else : ?>
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="order_action" value="share">
                                                <input type="hidden" name="send_order_id" value="<?= h((string) $order_id) ?>">
                                                <button
                                                    class="booking-action-btn secondary"
                                                    type="submit"
                                                    data-confirm="ยืนยันการสร้างลิงก์สาธารณะสำหรับแชร์คำสั่งราชการนี้ใช่หรือไม่?"
                                                    data-confirm-title="ยืนยันการสร้างลิงก์"
                                                    data-confirm-ok="ยืนยัน"
                                                    data-confirm-cancel="ยกเลิก"
                                                    title="สร้างลิงก์"
                                                    aria-label="สร้างลิงก์">
                                                    <i class="fa-solid fa-link"></i>
                                                    <span class="tooltip">สร้างลิงก์</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($show_attach_action) : ?>
                                        <button
                                            class="booking-action-btn secondary js-open-order-edit-modal"
                                            type="button"
                                            data-order-id="<?= h((string) $order_id) ?>"
                                            data-order-no="<?= h($order_no !== '' ? $order_no : '-') ?>"
                                            data-order-subject="<?= h((string) ($item['subject'] ?? '-')) ?>"
                                            data-order-issuer="<?= h($issuer_for_modal) ?>"
                                            data-order-detail="<?= h($detail_text !== '' ? $detail_text : '-') ?>"
                                            data-order-status="<?= h((string) ($status_meta['label'] ?? '-')) ?>"
                                            data-order-created="<?= h($date_display) ?>"
                                            data-order-date="<?= h($order_date_display) ?>"
                                            data-order-date-raw="<?= h($order_date_raw) ?>"
                                            data-order-effective-date="<?= h($effective_date_display) ?>"
                                            data-order-effective-date-raw="<?= h($effective_date_raw) ?>"
                                            data-order-group="<?= h($group_name !== '' ? $group_name : '-') ?>"
                                            data-order-group-fid="<?= h($group_fid_for_modal) ?>"
                                            data-order-files="<?= h($order_existing_files_json) ?>"
                                            title="ดู/แนบไฟล์"
                                            aria-label="ดู/แนบไฟล์">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                            <span class="tooltip">ดู/แนบไฟล์</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($show_send_action) : ?>
                                        <button
                                            class="booking-action-btn secondary js-open-order-send-modal"
                                            type="button"
                                            data-order-id="<?= h((string) $order_id) ?>">
                                            <i class="fa-solid fa-paper-plane"></i>
                                            <span class="tooltip">ส่งคำสั่งต่อ</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($show_recipients_action) : ?>
                                        <button
                                            class="booking-action-btn secondary js-open-order-send-modal"
                                            type="button"
                                            data-order-id="<?= h((string) $order_id) ?>">
                                            <i class="fa-solid fa-eye"></i>
                                            <span class="tooltip">ผู้รับเอกสาร</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($order_no !== '') : ?>
                                    <div class="circular-my-subject">คำสั่งที่ <?= h($order_no) ?></div>
                                    <div class="circular-my-meta"><?= h((string) ($item['subject'] ?? '-')) ?></div>
                                <?php else : ?>
                                    <div class="circular-my-subject"><?= h((string) ($item['subject'] ?? '-')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill <?= h((string) ($status_meta['pill'] ?? 'pending')) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span>
                                <?php if ($show_recipients_action) : ?>
                                    <p class="viewer">อ่านแล้ว <?= h((string) $read_done_for_row) ?> จาก <?= h((string) $read_total_for_row) ?> คน</p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="order-create-datetime">
                                    <span class="order-create-datetime-date"><?= h($date_display_date) ?></span>
                                    <span class="order-create-datetime-time"><?= h($date_display_time) ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    // $pagination_url = $build_url(['page' => null]);
    // component_render('pagination', [
    //     'page' => $page,
    //     'total_pages' => $total_pages,
    //     'base_url' => $pagination_url,
    //     'class' => 'u-mt-2',
    // ]);
    ?>
</section>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalOrderSendOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p id="modalOrderSendTitle">ส่งคำสั่งราชการต่อ</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeModalOrderSend"></i>
                </div>
            </div>

            <div class="content-modal">
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>คำสั่งที่</strong></p>
                        <input type="text" id="modalOrderSendNo" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>เรื่อง</strong></p>
                        <input type="text" id="modalOrderSendSubject" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ทั้งนี้ตั้งแต่วันที่</strong></p>
                        <input type="date" id="modalOrderSendEffectiveDate" class="order-no-display" value="" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>สั่ง ณ วันที่</strong></p>
                        <input type="date" id="modalOrderSendDate" class="order-no-display" value="" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ผู้สร้างเลขคำสั่ง</strong></p>
                        <input type="text" id="modalOrderSendIssuer" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>กลุ่ม</strong></p>
                        <input type="text" id="modalOrderSendGroup" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="content-file-sec">
                    <p><strong>ไฟล์เอกสารแนบจากระบบ</strong></p>
                    <div class="file-section" id="modalOrderSendFileSection"></div>
                </div>

                <div class="orders-send-modal-shell orders-send-card">
                    <div id="modalOrderSendFormSection">
                        <form method="POST" action="orders-create.php" class="orders-send-form" id="modalOrderSendMainForm">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="order_action" value="send">
                            <input type="hidden" name="send_order_id" id="modalOrderSendMainOrderId" value="">

                            <div class="form-group receive" data-order-send-recipients>
                                <label>ส่งถึง :</label>
                                <div class="dropdown-container">
                                    <div class="search-input-wrapper" id="orderSendRecipientToggle">
                                        <input type="text" id="orderSendMainInput" class="search-input" placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </div>

                                    <div class="dropdown-content" id="orderSendDropdownContent">
                                        <div class="dropdown-header">
                                            <label class="select-all-box">
                                                <input type="checkbox" id="orderSendSelectAll">เลือกทั้งหมด
                                            </label>
                                        </div>

                                        <div class="dropdown-list">
                                            <?php if (!empty($send_faction_groups)) : ?>
                                                <div class="category-group">
                                                    <div class="category-title">
                                                        <span>หน่วยงาน</span>
                                                    </div>
                                                    <div class="category-items">
                                                        <?php foreach ($send_faction_groups as $faction_group) : ?>
                                                            <?php
                                                            $faction_id = (string) ($faction_group['id'] ?? '');
                                                            $faction_name = trim((string) ($faction_group['name'] ?? ''));
                                                            $faction_members = (array) ($faction_group['members'] ?? []);
                                                            $faction_members_json = (string) ($faction_group['members_json'] ?? '[]');
                                                            $faction_member_pids_attr = (string) ($faction_group['member_pids_attr'] ?? '');

                                                            if ($faction_id === '' || $faction_name === '') {
                                                                continue;
                                                            }

                                                            $has_selected_member = false;

                                                            foreach ($faction_members as $faction_member) {
                                                                $member_pid = (string) ($faction_member['pID'] ?? '');

                                                                if ($member_pid !== '' && $send_is_selected($member_pid, $selected_send_person_ids)) {
                                                                    $has_selected_member = true;
                                                                    break;
                                                                }
                                                            }

                                                            $group_checked = $send_is_selected($faction_id, $selected_send_faction_ids);
                                                            $expanded_by_default = $group_checked || $has_selected_member;
                                                            ?>
                                                            <div class="item item-group<?= $expanded_by_default ? '' : ' is-collapsed' ?>" data-faction-id="<?= h($faction_id) ?>">
                                                                <div class="group-header">
                                                                    <label class="item-main">
                                                                        <input
                                                                            type="checkbox"
                                                                            class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                                            data-group="faction"
                                                                            data-group-key="faction-<?= h($faction_id) ?>"
                                                                            data-group-label="<?= h($faction_name) ?>"
                                                                            data-members="<?= h($faction_members_json) ?>"
                                                                            data-member-pids="<?= h($faction_member_pids_attr) ?>"
                                                                            data-recipient-option="faction"
                                                                            name="faction_ids[]"
                                                                            value="<?= h($faction_id) ?>"
                                                                            <?= $group_checked ? 'checked' : '' ?>>
                                                                        <span class="item-title"><?= h($faction_name) ?></span>
                                                                        <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) count($faction_members)) ?> คน</small>
                                                                    </label>
                                                                    <button type="button" class="group-toggle" aria-expanded="<?= $expanded_by_default ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                                    </button>
                                                                </div>

                                                                <ol class="member-sublist">
                                                                    <?php if (empty($faction_members)) : ?>
                                                                        <li>
                                                                            <span class="item-subtext">ไม่มีสมาชิกในหน่วยงานนี้</span>
                                                                        </li>
                                                                    <?php else : ?>
                                                                        <?php foreach ($faction_members as $faction_member) : ?>
                                                                            <?php
                                                                            $member_pid = trim((string) ($faction_member['pID'] ?? ''));
                                                                            $member_name = trim((string) ($faction_member['name'] ?? ''));
                                                                            $member_department = trim((string) ($faction_member['department'] ?? ''));
                                                                            $member_faction = trim((string) ($faction_member['faction'] ?? $faction_name));

                                                                            if ($member_pid === '' || $member_name === '') {
                                                                                continue;
                                                                            }
                                                                            if ($member_faction === '') {
                                                                                $member_faction = $faction_name;
                                                                            }
                                                                            ?>
                                                                            <li>
                                                                                <label class="item member-item">
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        class="member-checkbox"
                                                                                        data-member-group-key="faction-<?= h($faction_id) ?>"
                                                                                        data-member-name="<?= h($member_name) ?>"
                                                                                        data-group-label="<?= h($faction_name) ?>"
                                                                                        data-member-department="<?= h($member_department) ?>"
                                                                                        data-member-faction="<?= h($member_faction) ?>"
                                                                                        data-member-pids="<?= h($member_pid) ?>"
                                                                                        data-recipient-option="person"
                                                                                        name="person_ids[]"
                                                                                        value="<?= h($member_pid) ?>"
                                                                                        <?= $send_is_selected($member_pid, $selected_send_person_ids) ? 'checked' : '' ?>>
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

                                            <?php if (!empty($send_department_groups)) : ?>
                                                <div class="category-group">
                                                    <div class="category-title">
                                                        <span>กลุ่มสาระ</span>
                                                    </div>
                                                    <div class="category-items">
                                                        <?php foreach ($send_department_groups as $department_group) : ?>
                                                            <?php
                                                            $department_id = (int) ($department_group['id'] ?? 0);
                                                            $department_name = trim((string) ($department_group['name'] ?? ''));
                                                            $department_members = (array) ($department_group['members'] ?? []);
                                                            $department_members_json = (string) ($department_group['members_json'] ?? '[]');
                                                            $department_member_pids_attr = (string) ($department_group['member_pids_attr'] ?? '');

                                                            if ($department_id <= 0 || $department_name === '' || empty($department_members)) {
                                                                continue;
                                                            }

                                                            $has_selected_member = false;

                                                            foreach ($department_members as $department_member) {
                                                                $member_pid = (string) ($department_member['pID'] ?? '');

                                                                if ($member_pid !== '' && $send_is_selected($member_pid, $selected_send_person_ids)) {
                                                                    $has_selected_member = true;
                                                                    break;
                                                                }
                                                            }

                                                            $department_group_key = 'department-' . $department_id;
                                                            ?>
                                                            <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                                <div class="group-header">
                                                                    <label class="item-main">
                                                                        <input
                                                                            type="checkbox"
                                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                                            data-group="department"
                                                                            data-group-key="<?= h($department_group_key) ?>"
                                                                            data-group-label="<?= h($department_name) ?>"
                                                                            data-members="<?= h($department_members_json) ?>"
                                                                            data-member-pids="<?= h($department_member_pids_attr) ?>"
                                                                            data-recipient-option="department"
                                                                            value="<?= h($department_group_key) ?>"
                                                                            <?= $has_selected_member ? 'checked' : '' ?>>
                                                                        <span class="item-title"><?= h($department_name) ?></span>
                                                                        <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) count($department_members)) ?> คน</small>
                                                                    </label>
                                                                    <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                                    </button>
                                                                </div>

                                                                <ol class="member-sublist">
                                                                    <?php foreach ($department_members as $department_member) : ?>
                                                                        <?php
                                                                        $member_pid = trim((string) ($department_member['pID'] ?? ''));
                                                                        $member_name = trim((string) ($department_member['name'] ?? ''));
                                                                        $member_department = trim((string) ($department_member['department'] ?? $department_name));
                                                                        $member_faction = trim((string) ($department_member['faction'] ?? ''));

                                                                        if ($member_pid === '' || $member_name === '') {
                                                                            continue;
                                                                        }
                                                                        if ($member_department === '') {
                                                                            $member_department = $department_name;
                                                                        }
                                                                        ?>
                                                                        <li>
                                                                            <label class="item member-item">
                                                                                <input
                                                                                    type="checkbox"
                                                                                    class="member-checkbox"
                                                                                    data-member-group-key="<?= h($department_group_key) ?>"
                                                                                    data-member-name="<?= h($member_name) ?>"
                                                                                    data-group-label="<?= h($department_name) ?>"
                                                                                    data-member-department="<?= h($member_department) ?>"
                                                                                    data-member-faction="<?= h($member_faction) ?>"
                                                                                    data-member-pids="<?= h($member_pid) ?>"
                                                                                    data-recipient-option="person"
                                                                                    name="person_ids[]"
                                                                                    value="<?= h($member_pid) ?>"
                                                                                    <?= $send_is_selected($member_pid, $selected_send_person_ids) ? 'checked' : '' ?>>
                                                                                <span class="member-name"><?= h($member_name) ?></span>
                                                                            </label>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ol>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($send_special_groups)) : ?>
                                                <div class="category-group">
                                                    <div class="category-title">
                                                        <span>อื่นๆ</span>
                                                    </div>
                                                    <div class="category-items">
                                                        <?php foreach ($send_special_groups as $special_group) : ?>
                                                            <?php
                                                            $special_group_key = trim((string) ($special_group['key'] ?? ''));
                                                            $special_group_name = trim((string) ($special_group['name'] ?? ''));
                                                            $special_members = (array) ($special_group['members'] ?? []);
                                                            $special_members_json = (string) ($special_group['members_json'] ?? '[]');
                                                            $special_member_pids_attr = (string) ($special_group['member_pids_attr'] ?? '');

                                                            if ($special_group_key === '' || $special_group_name === '' || empty($special_members)) {
                                                                continue;
                                                            }

                                                            $has_selected_member = false;

                                                            foreach ($special_members as $special_member) {
                                                                $member_pid = (string) ($special_member['pID'] ?? '');

                                                                if ($member_pid !== '' && $send_is_selected($member_pid, $selected_send_person_ids)) {
                                                                    $has_selected_member = true;
                                                                    break;
                                                                }
                                                            }
                                                            ?>
                                                            <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                                <div class="group-header">
                                                                    <label class="item-main">
                                                                        <input
                                                                            type="checkbox"
                                                                            class="item-checkbox group-item-checkbox"
                                                                            data-group="special"
                                                                            data-group-key="<?= h($special_group_key) ?>"
                                                                            data-group-label="<?= h($special_group_name) ?>"
                                                                            data-members="<?= h($special_members_json) ?>"
                                                                            data-member-pids="<?= h($special_member_pids_attr) ?>"
                                                                            data-recipient-option="special"
                                                                            value="<?= h($special_group_key) ?>"
                                                                            <?= $has_selected_member ? 'checked' : '' ?>>
                                                                        <span class="item-title"><?= h($special_group_name) ?></span>
                                                                        <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) count($special_members)) ?> คน</small>
                                                                    </label>
                                                                    <button type="button" class="group-toggle" aria-expanded="<?= $has_selected_member ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                                    </button>
                                                                </div>

                                                                <ol class="member-sublist">
                                                                    <?php foreach ($special_members as $special_member) : ?>
                                                                        <?php
                                                                        $member_pid = trim((string) ($special_member['pID'] ?? ''));
                                                                        $member_name = trim((string) ($special_member['name'] ?? ''));
                                                                        $member_department = trim((string) ($special_member['department'] ?? ''));
                                                                        $member_faction = trim((string) ($special_member['faction'] ?? ''));

                                                                        if ($member_pid === '' || $member_name === '') {
                                                                            continue;
                                                                        }
                                                                        ?>
                                                                        <li>
                                                                            <label class="item member-item">
                                                                                <input
                                                                                    type="checkbox"
                                                                                    class="member-checkbox"
                                                                                    data-member-group-key="<?= h($special_group_key) ?>"
                                                                                    data-member-name="<?= h($member_name) ?>"
                                                                                    data-group-label="<?= h($special_group_name) ?>"
                                                                                    data-member-department="<?= h($member_department) ?>"
                                                                                    data-member-faction="<?= h($member_faction) ?>"
                                                                                    data-member-pids="<?= h($member_pid) ?>"
                                                                                    data-recipient-option="person"
                                                                                    name="person_ids[]"
                                                                                    value="<?= h($member_pid) ?>"
                                                                                    <?= $send_is_selected($member_pid, $selected_send_person_ids) ? 'checked' : '' ?>>
                                                                                <span class="member-name"><?= h($member_name) ?></span>
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
                                    <button id="modalOrderSendBtnShowRecipients" type="button">
                                        <p>แสดงผู้รับทั้งหมด</p>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div id="modalOrderTrackSection" style="display: none;">
                        <div class="table-responsive">
                            <table class="custom-table orders-send-track-table">
                                <thead>
                                    <tr>
                                        <th>ชื่อผู้รับ</th>
                                        <th>สถานะ</th>
                                        <th>เวลาอ่านล่าสุด</th>
                                    </tr>
                                </thead>
                                <tbody id="modalOrderTrackBody">
                                    <tr>
                                        <td colspan="3" class="orders-send-track-empty">ไม่พบข้อมูลผู้รับ</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>

            <div class="footer-modal">
                <button
                    type="submit"
                    form="modalOrderSendMainForm"
                    id="modalOrderSendSubmitBtn"
                    data-confirm="ยืนยันการส่งคำสั่งราชการต่อตามรายชื่อผู้รับที่เลือกใช่หรือไม่?"
                    data-confirm-title="ยืนยันการส่งคำสั่งราชการต่อ"
                    data-confirm-ok="ยืนยัน"
                    data-confirm-cancel="ยกเลิก">
                    <p>ส่งคำสั่งต่อ</p>
                </button>

            </div>
        </div>
    </div>
</div>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalOrderViewOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p>รายชื่อผู้รับเอกสาร</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeModalOrderView"></i>
                </div>
            </div>
            <div class="content-modal">
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>คำสั่งที่</strong></p>
                        <input type="text" id="modalOrderViewNo" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>เรื่อง</strong></p>
                        <input type="text" id="modalOrderViewSubject" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ทั้งนี้ตั้งแต่วันที่</strong></p>
                        <input type="date" id="modalOrderViewEffectiveDate" class="order-no-display" value="" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>สั่ง ณ วันที่</strong></p>
                        <input type="date" id="modalOrderViewDate" class="order-no-display" value="" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ผู้สร้างเลขคำสั่ง</strong></p>
                        <input type="text" id="modalOrderViewIssuer" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>กลุ่ม</strong></p>
                        <input type="text" id="modalOrderViewGroup" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="orders-send-modal-shell orders-send-card">
                    <div id="modalOrderViewFormSection">
                        <form method="POST" action="orders-create.php" class="orders-send-form" id="modalOrderViewForm">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="order_action" value="send">
                            <input type="hidden" name="send_order_id" id="modalOrderViewOrderId" value="">
                        </form>
                    </div>
                </div>

                <div class="content-file-sec">
                    <p><strong>ไฟล์เอกสารแนบจากระบบ</strong></p>
                    <div class="file-section" id="modalOrderViewFileSection"></div>
                </div>

                <div class="content-table-sec">
                    <div class="table-responsive">
                        <table class="custom-table orders-send-track-table">
                            <thead>
                                <tr>
                                    <th>ชื่อจริง-นามสกุล</th>
                                    <th style="width: 20%">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody id="modalOrderViewTrackBody">
                                <tr>
                                    <td colspan="2" class="orders-send-track-empty">ไม่พบข้อมูลผู้รับ</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="footer-modal">
                <button type="button" id="modalOrderViewCloseBtn">
                    <p>ปิดหน้าต่าง</p>
                </button>

            </div>
        </div>
    </div>
</div>

<div id="modalOrderSendRecipientModal" class="modal-overlay-recipient">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-users"></i>
                <span>รายชื่อผู้รับคำสั่งราชการ</span>
            </div>
            <button class="modal-close" id="modalOrderSendRecipientClose" type="button">
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
                <tbody id="modalOrderSendRecipientTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalOrderEditOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p>แก้ไขและแนบไฟล์คำสั่งราชการ</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeModalOrderEdit"></i>
                </div>
            </div>

            <div class="content-modal">
                <form method="POST" action="orders-create.php" enctype="multipart/form-data" id="modalOrderEditForm">

                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="order_id" id="modalOrderId" value="">
                    <input type="hidden" name="from_track_modal" value="1">

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>คำสั่งที่</strong></p>
                            <input type="text" id="modalOrderNo" class="order-no-display" placeholder="-" disabled>
                        </div>
                        <div class="more-details">
                            <p><strong>เรื่อง</strong></p>
                            <input type="text" id="modalOrderSubject" name="subject" placeholder="ระบุหัวข้อคำสั่ง" maxlength="300" required>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>ทั้งนี้ตั้งแต่วันที่</strong></p>
                            <input type="date" id="modalOrderEffectiveDate" name="effective_date" required>
                        </div>
                        <div class="more-details">
                            <p><strong>สั่ง ณ วันที่</strong></p>
                            <input type="date" id="modalOrderDate" name="order_date" required>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>ผู้สร้างเลขคำสั่ง</strong></p>
                            <input type="text" id="modalOrderIssuer" class="order-no-display" placeholder="-" disabled>
                        </div>
                        <div class="more-details">
                            <p><strong>กลุ่ม</strong></p>
                            <div class="custom-select-wrapper" id="modalOrderGroupWrapper">
                                <div class="custom-select-trigger">
                                    <p class="select-value"><?= h($selected_group_name !== '' ? $selected_group_name : 'เลือกกลุ่ม') ?></p>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="custom-options">
                                    <?php foreach ($faction_options as $fid => $name): ?>
                                        <?php $modal_group_fid = (string) $fid; ?>
                                        <div class="custom-option<?= $modal_group_fid === $selected_group_fid ? ' selected' : '' ?>" data-value="<?= h($modal_group_fid) ?>">
                                            <?= h((string) $name) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <input type="hidden" id="modalOrderGroupFid" name="group_fid" value="<?= h($selected_group_fid) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="content-file-sec">


                        <label>อัปโหลดไฟล์เอกสาร</label>
                        <section class="upload-layout">
                            <input
                                type="file"
                                id="fileInput_modal"
                                name="attachments[]"
                                multiple
                                accept="application/pdf,image/png,image/jpeg"
                                style="display: none;">

                            <div class="upload-box" id="dropzone_modal">
                                <i class="fa-solid fa-upload"></i>
                                <p>ลากไฟล์มาวางที่นี่</p>
                            </div>

                            <div class="existing-file-section">
                                <!-- <label>ไฟล์ที่แนบแล้ว</label> -->
                                <div class="file-list" id="existingFileListContainer_modal">
                                    <p class="existing-file-empty">ยังไม่มีไฟล์แนบ</p>
                                </div>
                            </div>

                        </section>

                        <div class="row form-group">
                            <button class="btn btn-upload-small" type="button" id="btnAddFiles_modal">
                                <p>เพิ่มไฟล์</p>
                            </button>
                            <div class="file-hint">
                                <p>* แนบไฟล์ได้สูงสุด 5 ไฟล์ (รวม PNG และ PDF) *</p>
                            </div>
                        </div>

                    </div>
                </form>
            </div>


            <div class="footer-modal">
                <button
                    type="submit"
                    form="modalOrderEditForm">
                    <p>บันทึกการแก้ไข</p>
                </button>
            </div>
        </div>
    </div>
</div>

<div id="imagePreviewModal" class="modal-overlay-preview">
    <span class="close-preview" id="closePreviewBtn">&times;</span>
    <img class="preview-content" id="previewImage" alt="">
    <div id="previewCaption"></div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        function setupFileUpload(inputId, listId, maxFiles = 1, options = {}) {
            const fileInput = document.getElementById(inputId);
            const fileList = document.getElementById(listId);
            const dropzone = options.dropzoneId ? document.getElementById(options.dropzoneId) : null;
            const addFilesBtn = options.addButtonId ? document.getElementById(options.addButtonId) : null;
            const previewModal = document.getElementById("imagePreviewModal");
            const previewImage = document.getElementById("previewImage");
            const previewCaption = document.getElementById("previewCaption");
            const closePreviewBtn = document.getElementById("closePreviewBtn");
            const allowedTypes = ["application/pdf", "image/jpeg", "image/png"];
            let selectedFiles = [];

            if (!fileInput) return null;

            const renderFiles = () => {
                if (!fileList) return;

                const newFileElements = fileList.querySelectorAll('.new-file-item');
                newFileElements.forEach(el => el.remove());

                let emptyMsg = fileList.querySelector('.existing-file-empty');
                if (selectedFiles.length === 0) {
                    const hasExistingFiles = fileList.querySelectorAll('.file-item-wrapper').length > 0;
                    if (!hasExistingFiles) {
                        if (!emptyMsg) {
                            emptyMsg = document.createElement('p');
                            emptyMsg.className = 'existing-file-empty';
                            emptyMsg.textContent = 'ยังไม่มีไฟล์แนบ';
                            fileList.appendChild(emptyMsg);
                        } else {
                            emptyMsg.style.display = 'block';
                        }
                    }
                    return;
                }

                if (emptyMsg) emptyMsg.style.display = 'none';

                selectedFiles.forEach((file, index) => {
                    const wrapper = document.createElement("div");
                    wrapper.className = "file-item-wrapper new-file-item";

                    const deleteBtn = document.createElement("button");
                    deleteBtn.type = "button";
                    deleteBtn.className = "delete-btn";
                    deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
                    deleteBtn.addEventListener("click", () => {
                        selectedFiles = selectedFiles.filter((_, i) => i !== index);
                        syncFiles();
                        renderFiles();
                    });

                    const banner = document.createElement("div");
                    banner.className = "file-banner";

                    const info = document.createElement("div");
                    info.className = "file-info";

                    const icon = document.createElement("div");
                    icon.className = "file-icon";
                    icon.innerHTML = file.type === "application/pdf" ?
                        '<i class="fa-solid fa-file-pdf"></i>' :
                        '<i class="fa-solid fa-file-image"></i>';

                    const text = document.createElement("div");
                    text.className = "file-text";

                    const name = document.createElement("div");
                    name.className = "file-name";
                    name.textContent = file.name;

                    const type = document.createElement("div");
                    type.className = "file-type";
                    type.textContent = file.type || "ไฟล์แนบ";

                    text.appendChild(name);
                    text.appendChild(type);
                    info.appendChild(icon);
                    info.appendChild(text);

                    const actions = document.createElement("div");
                    actions.className = "file-actions";

                    const view = document.createElement("a");
                    view.href = "#";
                    view.innerHTML = '<i class="fa-solid fa-eye"></i>';
                    view.addEventListener("click", (e) => {
                        e.preventDefault();
                        if (file.type.startsWith("image/")) {
                            const reader = new FileReader();
                            reader.onload = () => {
                                if (previewImage) previewImage.src = reader.result;
                                if (previewCaption) previewCaption.textContent = file.name;
                                previewModal?.classList.add("active");
                            };
                            reader.readAsDataURL(file);
                        } else {
                            const url = URL.createObjectURL(file);
                            window.open(url, "_blank", "noopener");
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
                const dt = new DataTransfer();
                selectedFiles.forEach((file) => dt.items.add(file));
                fileInput.files = dt.files;
            };

            const showOrderCreateFileAlert = (message) => {
                const alertsApi = window.AppAlerts && typeof window.AppAlerts.fire === 'function' ? window.AppAlerts : null;
                if (!alertsApi) {
                    console.warn('Orders create alert unavailable:', message);
                    return;
                }

                alertsApi.fire({
                    type: 'warning',
                    title: 'แจ้งเตือน',
                    message,
                });
            };

            const resetFiles = () => {
                selectedFiles = [];
                syncFiles();
                renderFiles();
            };

            const addFiles = (files) => {
                if (!files) return;
                const existing = new Set(selectedFiles.map((file) => `${file.name}-${file.size}-${file.lastModified}`));

                const existingDbFilesCount = fileList ? fileList.querySelectorAll('.file-item-wrapper:not(.new-file-item)').length : 0;

                let currentTotal = existingDbFilesCount + selectedFiles.length;
                let showLimitAlert = false;

                Array.from(files).forEach((file) => {
                    if (currentTotal >= maxFiles) {
                        showLimitAlert = true;
                        return;
                    }

                    const key = `${file.name}-${file.size}-${file.lastModified}`;
                    if (existing.has(key)) return;
                    if (!allowedTypes.includes(file.type)) return;

                    selectedFiles.push(file);
                    existing.add(key);
                    currentTotal++;
                });

                if (showLimitAlert) {
                    showOrderCreateFileAlert(`คุณสามารถแนบไฟล์ได้สูงสุดรวมกัน ${maxFiles} ไฟล์เท่านั้น`);
                }

                syncFiles();
                renderFiles();
            };

            fileInput.addEventListener("change", (e) => {
                addFiles(e.target.files);
            });

            if (dropzone) {
                dropzone.addEventListener("click", () => fileInput.click());
                dropzone.addEventListener("dragover", (e) => {
                    e.preventDefault();
                    dropzone.classList.add("active");
                });
                dropzone.addEventListener("dragleave", () => {
                    dropzone.classList.remove("active");
                });
                dropzone.addEventListener("drop", (e) => {
                    e.preventDefault();
                    dropzone.classList.remove("active");
                    addFiles(e.dataTransfer?.files || []);
                });
            }

            if (addFilesBtn) {
                addFilesBtn.addEventListener("click", () => fileInput.click());
            }

            if (closePreviewBtn) {
                closePreviewBtn.addEventListener("click", () => previewModal?.classList.remove("active"));
            }
            if (previewModal) {
                previewModal.addEventListener("click", (e) => {
                    if (e.target === previewModal) previewModal.classList.remove("active");
                });
            }

            renderFiles();
            return {
                getSelectedCount: () => selectedFiles.length,
                reset: resetFiles,
            };
        }

        const modalAttachmentUpload = setupFileUpload("fileInput_modal", "existingFileListContainer_modal", 5, {
            dropzoneId: "dropzone_modal",
            addButtonId: "btnAddFiles_modal",
        });

        const orderEditModal = document.getElementById('modalOrderEditOverlay');
        const closeOrderEditModalBtn = document.getElementById('closeModalOrderEdit');
        const modalOrderId = document.getElementById('modalOrderId');
        const modalOrderNo = document.getElementById('modalOrderNo');
        const modalOrderSubject = document.getElementById('modalOrderSubject');
        const modalOrderEffectiveDate = document.getElementById('modalOrderEffectiveDate');
        const modalOrderDate = document.getElementById('modalOrderDate');
        const modalOrderIssuer = document.getElementById('modalOrderIssuer');
        const modalOrderEditForm = document.getElementById('modalOrderEditForm');
        const modalOrderGroupFid = document.getElementById('modalOrderGroupFid');
        const modalExistingFileList = document.getElementById('existingFileListContainer_modal');
        const modalOrderGroupWrapper = document.getElementById('modalOrderGroupWrapper');
        const modalOrderGroupDisplay = modalOrderGroupWrapper?.querySelector('.select-value') ?? null;
        const modalOrderGroupOptions = modalOrderGroupWrapper ?
            Array.from(modalOrderGroupWrapper.querySelectorAll('.custom-option')) : [];
        const orderSendModal = document.getElementById('modalOrderSendOverlay');
        const closeOrderSendModalBtn = document.getElementById('closeModalOrderSend');
        const modalOrderSendTitle = document.getElementById('modalOrderSendTitle');
        const modalOrderSendNo = document.getElementById('modalOrderSendNo');
        const modalOrderSendSubject = document.getElementById('modalOrderSendSubject');
        const modalOrderSendEffectiveDate = document.getElementById('modalOrderSendEffectiveDate');
        const modalOrderSendDate = document.getElementById('modalOrderSendDate');
        const modalOrderSendIssuer = document.getElementById('modalOrderSendIssuer');
        const modalOrderSendGroup = document.getElementById('modalOrderSendGroup');
        const modalOrderSendFileSection = document.getElementById('modalOrderSendFileSection');
        const modalOrderSendFormSection = document.getElementById('modalOrderSendFormSection');
        const modalOrderTrackSection = document.getElementById('modalOrderTrackSection');
        const modalOrderSendForm = document.getElementById('modalOrderSendMainForm');
        const modalOrderSendOrderId = document.getElementById('modalOrderSendMainOrderId');
        const modalOrderTrackBody = document.getElementById('modalOrderTrackBody');
        const modalOrderSendSubmitBtn = document.getElementById('modalOrderSendSubmitBtn');
        const modalOrderSendBtnShowRecipients = document.getElementById('modalOrderSendBtnShowRecipients');
        const modalOrderSendRecipientModal = document.getElementById('modalOrderSendRecipientModal');
        const modalOrderSendRecipientClose = document.getElementById('modalOrderSendRecipientClose');
        const modalOrderSendRecipientTableBody = document.getElementById('modalOrderSendRecipientTableBody');
        const initialSendModalOrderId = <?= (int) $send_modal_open_order_id ?>;
        let orderSendModalData = {};
        const syncOrderSendModalData = () => {
            const mapElement = document.querySelector('#orderMine .js-order-send-map');
            if (!mapElement) {
                orderSendModalData = {};
                return;
            }
            try {
                const parsed = JSON.parse(mapElement.textContent || '{}');
                if (parsed && typeof parsed === 'object') {
                    orderSendModalData = parsed;
                    return;
                }
            } catch (error) {
                console.error('Invalid send modal data', error);
            }
            orderSendModalData = {};
        };

        const thaiMonthsFull = [
            '',
            'มกราคม',
            'กุมภาพันธ์',
            'มีนาคม',
            'เมษายน',
            'พฤษภาคม',
            'มิถุนายน',
            'กรกฎาคม',
            'สิงหาคม',
            'กันยายน',
            'ตุลาคม',
            'พฤศจิกายน',
            'ธันวาคม'
        ];

        const formatThaiTrackReadAt = (rawValue) => {
            const value = String(rawValue || '').trim();
            if (value === '') {
                return '-';
            }

            const match = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/);
            if (!match) {
                return value;
            }

            const year = Number(match[1]);
            const month = Number(match[2]);
            const day = Number(match[3]);
            const hour = String(match[4] ?? '00');
            const minute = String(match[5] ?? '00');

            if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day) || month < 1 || month > 12) {
                return value;
            }

            const monthLabel = thaiMonthsFull[month] || '';
            if (monthLabel === '') {
                return value;
            }

            const buddhistYear = year + 543;
            return `${day} ${monthLabel} ${buddhistYear} เวลา ${hour}:${minute} น.`;
        };

        const syncModalGroupSelect = (targetValue = '') => {
            if (!modalOrderGroupFid || !modalOrderGroupWrapper) {
                return;
            }

            const normalizedTarget = String(targetValue || '').trim();
            let matchedOption = null;

            if (normalizedTarget !== '') {
                matchedOption = modalOrderGroupOptions.find((option) => {
                    return String(option.getAttribute('data-value') || '') === normalizedTarget;
                }) || null;
            }

            if (!matchedOption && modalOrderGroupOptions.length > 0) {
                [matchedOption] = modalOrderGroupOptions;
            }

            const nextValue = matchedOption ? String(matchedOption.getAttribute('data-value') || '') : '';
            modalOrderGroupFid.value = nextValue;

            modalOrderGroupOptions.forEach((option) => {
                option.classList.toggle('selected', option === matchedOption);
            });

            if (modalOrderGroupDisplay) {
                modalOrderGroupDisplay.textContent = matchedOption ?
                    String(matchedOption.textContent || '').trim() :
                    'เลือกกลุ่ม';
            }

            modalOrderGroupWrapper.classList.remove('open');
        };

        syncModalGroupSelect(modalOrderGroupFid?.value || '');

        const setupOrderSendRecipientDropdown = () => {
            if (!modalOrderSendForm) {
                return () => {};
            }

            const recipientSection = modalOrderSendForm.querySelector('[data-order-send-recipients]');
            const dropdown = document.getElementById('orderSendDropdownContent');
            const toggle = document.getElementById('orderSendRecipientToggle');
            const searchInput = document.getElementById('orderSendMainInput');
            const selectAll = document.getElementById('orderSendSelectAll');

            if (!recipientSection || !dropdown || !toggle || !searchInput || !selectAll) {
                return () => {};
            }

            const groupChecks = Array.from(modalOrderSendForm.querySelectorAll('.group-item-checkbox'));
            const memberChecks = Array.from(modalOrderSendForm.querySelectorAll('.member-checkbox'));
            const groupItems = Array.from(modalOrderSendForm.querySelectorAll('.dropdown-list .item-group'));
            const directPersonItems = Array.from(modalOrderSendForm.querySelectorAll('.dropdown-list .category-items > label.item.member-item[data-search]'));
            const categoryGroups = Array.from(modalOrderSendForm.querySelectorAll('.dropdown-list .category-group'));

            const normalizeSearchText = (value) => String(value || '')
                .toLowerCase()
                .replace(/\s+/g, '')
                .replace(/[^0-9a-z\u0E00-\u0E7F]/gi, '');

            const getMemberChecksByGroupKey = (groupKey) => {
                return memberChecks.filter((el) => String(el.dataset.memberGroupKey || '') === String(groupKey));
            };

            const syncMemberByPid = (pid, checked, source) => {
                const normalizedPid = String(pid || '').trim();
                if (normalizedPid === '') {
                    return;
                }
                memberChecks.forEach((memberCheck) => {
                    if (memberCheck === source) {
                        return;
                    }
                    if (String(memberCheck.value || '') !== normalizedPid) {
                        return;
                    }
                    if (memberCheck.disabled) {
                        return;
                    }
                    memberCheck.checked = checked;
                });
            };

            const setGroupCollapsed = (groupItem, collapsed) => {
                if (!groupItem) {
                    return;
                }
                groupItem.classList.toggle('is-collapsed', collapsed);
                const toggleBtn = groupItem.querySelector('.group-toggle');
                if (toggleBtn) {
                    toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                }
            };

            const setDropdownVisible = (visible) => {
                dropdown.classList.toggle('show', visible);
                toggle.classList.toggle('active', visible);
            };

            const updateSelectAllState = () => {
                const allChecks = [...groupChecks, ...memberChecks];
                const checkedCount = allChecks.filter((el) => el.checked).length;
                selectAll.checked = allChecks.length > 0 && checkedCount === allChecks.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < allChecks.length;

                groupChecks.forEach((groupCheck) => {
                    const groupKey = groupCheck.getAttribute('data-group-key') || '';
                    const members = getMemberChecksByGroupKey(groupKey);
                    if (members.length <= 0) {
                        groupCheck.indeterminate = false;
                        return;
                    }
                    const checkedMembers = members.filter((el) => el.checked).length;
                    if (checkedMembers === 0) {
                        groupCheck.checked = false;
                        groupCheck.indeterminate = false;
                        return;
                    }
                    if (checkedMembers === members.length) {
                        groupCheck.checked = true;
                        groupCheck.indeterminate = false;
                        return;
                    }
                    groupCheck.checked = false;
                    groupCheck.indeterminate = true;
                });
            };

            const filterRecipientDropdown = (rawQuery) => {
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
                        const rowText = normalizeSearchText(row.textContent || '');
                        const matched = isGroupMatch || rowText.includes(query);
                        row.style.display = matched ? '' : 'none';
                        if (matched) {
                            hasMemberMatch = true;
                        }
                    });

                    const isVisible = isGroupMatch || hasMemberMatch;
                    groupItem.style.display = isVisible ? '' : 'none';
                    if (isVisible) {
                        setGroupCollapsed(groupItem, false);
                    }
                });

                directPersonItems.forEach((item) => {
                    if (query === '') {
                        item.style.display = '';
                        return;
                    }
                    const rowText = normalizeSearchText(item.textContent || '');
                    item.style.display = rowText.includes(query) ? '' : 'none';
                });

                categoryGroups.forEach((category) => {
                    const hasVisibleGroup = Array.from(category.querySelectorAll('.category-items .item-group'))
                        .some((item) => item.style.display !== 'none');
                    const hasVisiblePerson = Array.from(category.querySelectorAll('.category-items > label.item.member-item[data-search]'))
                        .some((item) => item.style.display !== 'none');
                    category.style.display = hasVisibleGroup || hasVisiblePerson ? '' : 'none';
                });
            };

            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                const clickedInput = event.target instanceof HTMLElement && (
                    event.target.matches('input.search-input') ||
                    !!event.target.closest('input.search-input')
                );
                if (clickedInput) {
                    setDropdownVisible(true);
                    return;
                }
                setDropdownVisible(!dropdown.classList.contains('show'));
            });

            document.addEventListener('click', (event) => {
                if (!dropdown.contains(event.target) && !toggle.contains(event.target)) {
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

            searchInput.addEventListener('focus', () => {
                setDropdownVisible(true);
            });
            searchInput.addEventListener('input', () => {
                setDropdownVisible(true);
                filterRecipientDropdown(searchInput.value || '');
            });
            searchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    setDropdownVisible(false);
                }
            });

            selectAll.addEventListener('change', () => {
                const checked = selectAll.checked;
                [...groupChecks, ...memberChecks].forEach((el) => {
                    if (!el.disabled) {
                        el.checked = checked;
                    }
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
                });
            });

            groupChecks.forEach((item) => {
                if (!item.checked) {
                    return;
                }
                const groupKey = item.getAttribute('data-group-key') || '';
                const members = getMemberChecksByGroupKey(groupKey);
                members.forEach((member) => {
                    member.checked = true;
                    syncMemberByPid(member.value || '', true, member);
                });
            });

            recipientSection.classList.remove('u-hidden');
            updateSelectAllState();
            filterRecipientDropdown('');

            return updateSelectAllState;
        };

        const escapeHtml = (value) => {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        };

        const renderOrderSendFiles = (orderId, files) => {
            const fileSections = modalOrderSendFileSection ? [modalOrderSendFileSection] : [];

            if (fileSections.length === 0) {
                return;
            }

            if (!Array.isArray(files) || files.length <= 0) {
                const emptyHtml = '<div class="file-banner"><div class="file-info"><div class="file-text"><span class="file-name">ไม่มีไฟล์แนบ</span></div></div></div>';
                fileSections.forEach(el => el.innerHTML = emptyHtml);
                return;
            }

            const safeOrderId = encodeURIComponent(String(orderId || '').trim());
            const html = files.map((file) => {
                const fileId = encodeURIComponent(String(file?.fileID || ''));
                const fileName = escapeHtml(String(file?.fileName || '-'));
                const mimeType = escapeHtml(String(file?.mimeType || 'ไฟล์แนบ'));
                const viewHref = `public/api/file-download.php?module=orders&entity_id=${safeOrderId}&file_id=${fileId}`;
                const iconHtml = String(file?.mimeType || '').toLowerCase() === 'application/pdf' ?
                    '<i class="fa-solid fa-file-pdf"></i>' :
                    '<i class="fa-solid fa-image"></i>';

                return `<div class="file-banner">
                    <div class="file-info">
                        <div class="file-icon">${iconHtml}</div>
                        <div class="file-text">
                            <span class="file-name">${fileName}</span>
                            <span class="file-type">${mimeType}</span>
                        </div>
                    </div>
                    <div class="file-actions">
                        <a href="${viewHref}" target="_blank" rel="noopener">
                            <i class="fa-solid fa-eye"></i>
                        </a>
                    </div>
                </div>`;
            }).join('');

            fileSections.forEach(el => el.innerHTML = html);
        };
        const renderExistingOrderFiles = (orderId, rawJson) => {
            if (!modalExistingFileList) {
                return;
            }

            let files = [];
            try {
                const parsed = JSON.parse(String(rawJson || '[]'));
                files = Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                files = [];
            }

            if (files.length <= 0) {
                modalExistingFileList.innerHTML = '<p class="existing-file-empty">ยังไม่มีไฟล์แนบ</p>';
                return;
            }

            const safeOrderId = encodeURIComponent(String(orderId || '').trim());

            const rowsHtml = files.map((file) => {
                const fileId = encodeURIComponent(String(file.fileID || ''));
                const fileName = escapeHtml(String(file.fileName || '-'));
                const mimeType = escapeHtml(String(file.mimeType || 'ไฟล์แนบ'));
                const viewHref = `public/api/file-download.php?module=orders&entity_id=${safeOrderId}&file_id=${fileId}`;
                const iconHtml = String(file.mimeType || '').toLowerCase() === 'application/pdf' ?
                    '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                    '<i class="fa-solid fa-file-image" aria-hidden="true"></i>';

                return `<div class="file-item-wrapper" id="existing-file-${fileId}">
                    <button type="button" class="delete-btn js-delete-existing" data-file-id="${fileId}" title="ลบไฟล์">
                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    </button>
                    <div class="file-banner">
                        <div class="file-info">
                            <div class="file-icon">${iconHtml}</div>
                            <div class="file-text">
                                <span class="file-name">${fileName}</span>
                                <span class="file-type">${mimeType}</span>
                            </div>
                        </div>
                        <div class="file-actions">
                            <a href="${viewHref}" target="_blank" rel="noopener" class="action-btn" title="ดูตัวอย่าง">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </div>`;
            }).join('');

            modalExistingFileList.innerHTML = rowsHtml;

            const deleteBtns = modalExistingFileList.querySelectorAll('.js-delete-existing');
            deleteBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const fId = this.getAttribute('data-file-id');
                    const wrapper = document.getElementById(`existing-file-${fId}`);

                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'deleted_existing_files[]';
                    hiddenInput.value = decodeURIComponent(fId);
                    document.getElementById('modalOrderEditForm').appendChild(hiddenInput);

                    if (wrapper) wrapper.remove();

                    if (modalExistingFileList.querySelectorAll('.file-item-wrapper').length === 0) {
                        let emptyMsg = modalExistingFileList.querySelector('.existing-file-empty');
                        if (!emptyMsg) {
                            const p = document.createElement('p');
                            p.className = 'existing-file-empty';
                            p.textContent = 'ยังไม่มีไฟล์แนบ';
                            modalExistingFileList.appendChild(p);
                        } else {
                            emptyMsg.style.display = 'block';
                        }
                    }
                });
            });
        };

        const collectRecipientSummary = () => {
            if (!modalOrderSendForm) {
                return {
                    selectedSources: 0,
                    uniqueRecipients: 0,
                };
            }

            const checkedFactionOptions = Array.from(modalOrderSendForm.querySelectorAll('input[name="faction_ids[]"]:checked'));
            const checkedRoleOptions = Array.from(modalOrderSendForm.querySelectorAll('input[name="role_ids[]"]:checked'));
            const checkedPersonOptions = Array.from(modalOrderSendForm.querySelectorAll('input[name="person_ids[]"]:checked'));
            const checkedPersonSources = new Set(
                checkedPersonOptions
                .map((input) => String(input.value || '').trim())
                .filter((value) => value !== '')
            );
            const checkedOptions = [...checkedFactionOptions, ...checkedRoleOptions, ...checkedPersonOptions];
            const recipients = new Set();

            checkedOptions.forEach((option) => {
                const memberAttr = String(option.getAttribute('data-member-pids') || '').trim();
                if (memberAttr === '') {
                    return;
                }
                memberAttr.split(',').map((pid) => pid.trim()).filter((pid) => pid !== '').forEach((pid) => recipients.add(pid));
            });

            return {
                selectedSources: checkedFactionOptions.length + checkedRoleOptions.length + checkedPersonSources.size,
                uniqueRecipients: recipients.size,
            };
        };

        const refreshRecipientSummary = () => {
            const summary = collectRecipientSummary();
            return summary;
        };

        const renderRecipients = () => {
            if (!modalOrderSendRecipientTableBody || !modalOrderSendForm) return;
            modalOrderSendRecipientTableBody.innerHTML = '';
            const checkedGroups = Array.from(modalOrderSendForm.querySelectorAll('.group-item-checkbox:checked'));
            const checkedMembers = Array.from(modalOrderSendForm.querySelectorAll('.member-checkbox:checked'));

            if (checkedGroups.length === 0 && checkedMembers.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="3" style="text-align:center; padding: 16px;">ไม่มีผู้รับที่เลือก</td>';
                modalOrderSendRecipientTableBody.appendChild(row);
                return;
            }

            const recipientsMap = new Map();
            const addRecipient = (pid, name, department, faction) => {
                const key = String(pid || '').trim();
                if (key === '') return;
                if (recipientsMap.has(key)) return;
                const departmentValue = String(department || '').trim();
                const factionValue = String(faction || '').trim();
                const groupLabel = departmentValue !== '' ? departmentValue : (factionValue !== '' ? factionValue : '-');
                recipientsMap.set(key, {
                    pid: key,
                    name: (name || '-').trim() || '-',
                    group: groupLabel,
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
                    addRecipient(
                        member && member.pID ? String(member.pID) : '',
                        member && member.name ? String(member.name) : '-',
                        member && member.department ? String(member.department) : '',
                        member && member.faction ? String(member.faction) : (item.getAttribute('data-group-label') || '-')
                    );
                });
            });

            checkedMembers.forEach((item) => {
                addRecipient(
                    item.value || '',
                    item.getAttribute('data-member-name') || '-',
                    item.getAttribute('data-member-department') || '',
                    item.getAttribute('data-member-faction') || item.getAttribute('data-group-label') || '-'
                );
            });

            const uniqueRecipients = Array.from(recipientsMap.values());
            uniqueRecipients.sort((a, b) => {
                if (a.group === b.group) {
                    return a.name.localeCompare(b.name, 'th');
                }
                return a.group.localeCompare(b.group, 'th');
            });

            uniqueRecipients.forEach((recipient, index) => {
                const row = document.createElement('tr');
                row.innerHTML = `<td>${index + 1}</td><td>${escapeHtml(recipient.name)}</td><td>${escapeHtml(recipient.group)}</td>`;
                modalOrderSendRecipientTableBody.appendChild(row);
            });
        };

        const closeOrderSendModal = () => {
            if (!orderSendModal) {
                return;
            }
            orderSendModal.style.display = 'none';
            modalOrderSendRecipientModal?.classList.remove('active');
        };

        const resetOrderSendSelections = () => {
            if (!modalOrderSendForm) {
                return;
            }

            modalOrderSendForm.querySelectorAll('input[name="faction_ids[]"], input[name="role_ids[]"], input[name="person_ids[]"]').forEach((input) => {
                if (!(input instanceof HTMLInputElement) || input.disabled) {
                    return;
                }
                input.checked = false;
                input.indeterminate = false;
            });

            const searchInput = document.getElementById('orderSendMainInput');
            const selectAll = document.getElementById('orderSendSelectAll');

            if (searchInput instanceof HTMLInputElement) {
                searchInput.value = '';
            }

            if (selectAll instanceof HTMLInputElement) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }

            modalOrderSendForm.querySelectorAll('.dropdown-list .item-group, .dropdown-list .category-group, .dropdown-list .category-items > label.item.member-item[data-search], .dropdown-list .member-sublist li').forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.style.display = '';
                }
            });

            modalOrderSendForm.querySelectorAll('.dropdown-list .item-group').forEach((groupNode) => {
                if (groupNode instanceof HTMLElement) {
                    groupNode.classList.add('is-collapsed');
                }
                const toggleBtn = groupNode.querySelector('.group-toggle');
                if (toggleBtn instanceof HTMLElement) {
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            });

            modalOrderSendRecipientModal?.classList.remove('active');
        };

        const openOrderSendModal = (orderIdRaw, options = {}) => {
            if (!orderSendModal) {
                return;
            }

            syncOrderSendModalData();

            const orderId = String(orderIdRaw || '').trim();
            if (orderId === '') {
                return;
            }

            const payload = orderSendModalData[orderId];
            if (!payload || typeof payload !== 'object') {
                return;
            }

            const preserveSelections = options && options.preserveSelections === true;
            if (!preserveSelections) {
                resetOrderSendSelections();
            }

            const orderNo = String(payload.orderNo || '').trim();
            const subject = String(payload.subject || '').trim();
            const effectiveDate = String(payload.effectiveDate || '').trim();
            const orderDate = String(payload.orderDate || '').trim();
            const issuerName = String(payload.issuerName || '').trim();
            const groupName = String(payload.groupName || '').trim();
            const attachments = Array.isArray(payload.attachments) ? payload.attachments : [];
            const status = String(payload.status || '').trim().toUpperCase();
            const readStats = Array.isArray(payload.readStats) ? payload.readStats : [];
            const readTotal = Number.isFinite(Number(payload.readTotal)) ? Number(payload.readTotal) : readStats.length;
            const readDone = Number.isFinite(Number(payload.readDone)) ? Number(payload.readDone) : readStats.filter((row) => Number(row.isRead) === 1).length;

            if (modalOrderSendNo) {
                modalOrderSendNo.value = orderNo !== '' ? orderNo : '-';
            }
            if (modalOrderSendSubject) {
                modalOrderSendSubject.value = subject !== '' ? subject : '-';
            }
            if (modalOrderSendEffectiveDate) {
                modalOrderSendEffectiveDate.value = /^\d{4}-\d{2}-\d{2}$/.test(effectiveDate) ? effectiveDate : '';
            }
            if (modalOrderSendDate) {
                modalOrderSendDate.value = /^\d{4}-\d{2}-\d{2}$/.test(orderDate) ? orderDate : '';
            }
            if (modalOrderSendIssuer) {
                modalOrderSendIssuer.value = issuerName !== '' ? issuerName : '-';
            }
            if (modalOrderSendGroup) {
                modalOrderSendGroup.value = groupName !== '' ? groupName : '-';
            }
            renderOrderSendFiles(orderId, attachments);

            if (modalOrderSendOrderId) {
                modalOrderSendOrderId.value = orderId;
            }
            const isSent = status === 'SENT';
            if (modalOrderSendTitle) {
                modalOrderSendTitle.textContent = isSent ? 'ติดตามการส่งคำสั่งราชการ' : 'ส่งคำสั่งราชการต่อ';
            }
            syncOrderSendRecipientState();

            if (modalOrderSendFormSection) {
                modalOrderSendFormSection.style.display = isSent ? 'none' : '';
            }
            if (modalOrderTrackSection) {
                modalOrderTrackSection.style.display = isSent ? '' : 'none';
            }
            if (modalOrderSendSubmitBtn) {
                modalOrderSendSubmitBtn.style.display = isSent ? 'none' : '';
            }

            if (isSent) {
                if (modalOrderTrackBody) {
                    if (readStats.length <= 0) {
                        modalOrderTrackBody.innerHTML = '<tr><td colspan="3" class="orders-send-track-empty">ไม่พบข้อมูลผู้รับ</td></tr>';
                    } else {
                        const rowsHtml = readStats.map((row) => {
                            const name = escapeHtml(row.name || '-');
                            const isRead = Number(row.isRead) === 1;
                            const readAtValue = isRead && String(row.readAt || '').trim() !== '' ?
                                formatThaiTrackReadAt(row.readAt) :
                                '-';
                            const readAt = escapeHtml(readAtValue);
                            const pill = `<span class="status-pill ${isRead ? 'approved' : 'pending'}">${isRead ? 'อ่านแล้ว' : 'ยังไม่อ่าน'}</span>`;
                            return `<tr><td>${name}</td><td>${pill}</td><td>${readAt}</td></tr>`;
                        }).join('');
                        modalOrderTrackBody.innerHTML = rowsHtml;
                    }
                }
            } else {
                refreshRecipientSummary();
            }

            orderSendModal.style.display = 'flex';
        };

        syncOrderSendModalData();
        const syncOrderSendRecipientState = setupOrderSendRecipientDropdown();
        refreshRecipientSummary();

        modalOrderSendForm?.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }
            if (!target.matches('input[data-recipient-option]')) {
                return;
            }
            refreshRecipientSummary();
        });

        modalOrderSendForm?.addEventListener('submit', (event) => {
            const summary = refreshRecipientSummary();
            if (summary.uniqueRecipients <= 0) {
                event.preventDefault();
            }
        });

        closeOrderSendModalBtn?.addEventListener('click', () => {
            closeOrderSendModal();
        });

        modalOrderSendBtnShowRecipients?.addEventListener('click', () => {
            renderRecipients();
            modalOrderSendRecipientModal?.classList.add('active');
        });

        modalOrderSendRecipientClose?.addEventListener('click', () => {
            modalOrderSendRecipientModal?.classList.remove('active');
        });

        modalOrderSendRecipientModal?.addEventListener('click', (event) => {
            if (event.target === modalOrderSendRecipientModal) {
                modalOrderSendRecipientModal.classList.remove('active');
            }
        });

        const openOrderEditModal = (trigger) => {
            if (!orderEditModal) return;

            const orderId = String(trigger.getAttribute('data-order-id') || '').trim();
            const orderNo = String(trigger.getAttribute('data-order-no') || '').trim();
            const orderSubject = String(trigger.getAttribute('data-order-subject') || '').trim();
            const orderIssuer = String(trigger.getAttribute('data-order-issuer') || '').trim();
            const orderDateRaw = String(trigger.getAttribute('data-order-date-raw') || '').trim();
            const orderEffectiveDateRaw = String(trigger.getAttribute('data-order-effective-date-raw') || '').trim();
            const orderGroupFid = String(trigger.getAttribute('data-order-group-fid') || '').trim();
            const orderFiles = String(trigger.getAttribute('data-order-files') || '[]');

            if (modalOrderId) modalOrderId.value = orderId;
            if (modalOrderNo) modalOrderNo.value = orderNo !== '' ? orderNo : '-';
            if (modalOrderSubject) modalOrderSubject.value = orderSubject !== '' ? orderSubject : '';
            if (modalOrderEffectiveDate) modalOrderEffectiveDate.value = /^\d{4}-\d{2}-\d{2}$/.test(orderEffectiveDateRaw) ? orderEffectiveDateRaw : '';
            if (modalOrderDate) modalOrderDate.value = /^\d{4}-\d{2}-\d{2}$/.test(orderDateRaw) ? orderDateRaw : '';
            if (modalOrderIssuer) modalOrderIssuer.value = orderIssuer !== '' ? orderIssuer : '-';
            syncModalGroupSelect(orderGroupFid);
            renderExistingOrderFiles(orderId, orderFiles);

            modalAttachmentUpload?.reset?.();

            orderEditModal.style.display = 'flex';
        };

        document.addEventListener('click', (event) => {
            const trigger = event.target instanceof Element ? event.target.closest('.js-open-order-edit-modal') : null;

            if (!trigger) {
                return;
            }

            event.preventDefault();
            openOrderEditModal(trigger);
        });

        closeOrderEditModalBtn?.addEventListener('click', () => {
            if (orderEditModal) {
                orderEditModal.style.display = 'none';
            }
        });

        let modalOrderEditConfirmApproved = false;
        modalOrderEditForm?.addEventListener('submit', (event) => {
            if (modalOrderEditConfirmApproved) {
                modalOrderEditConfirmApproved = false;
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const selectedCount = Number(modalAttachmentUpload?.getSelectedCount?.() || 0);
            if (selectedCount <= 0) {
                const alertsApi = window.AppAlerts && typeof window.AppAlerts.fire === 'function' ? window.AppAlerts : null;
                if (alertsApi) {
                    alertsApi.fire({
                        type: 'warning',
                        title: 'แจ้งเตือน',
                        message: 'กรุณาอัปโหลดไฟล์คำสั่งราชการก่อนบันทึกการแก้ไข',
                    });
                } else {
                    window.alert('กรุณาอัปโหลดไฟล์คำสั่งราชการก่อนบันทึกการแก้ไข');
                }
                return;
            }

            if (typeof modalOrderEditForm.reportValidity === 'function' && !modalOrderEditForm.reportValidity()) {
                return;
            }

            const confirmApi = window.AppAlerts && typeof window.AppAlerts.confirm === 'function' ? window.AppAlerts : null;
            const confirmPromise = confirmApi ?
                confirmApi.confirm('ยืนยันการบันทึกการแก้ไขและแนบไฟล์คำสั่งราชการนี้ใช่หรือไม่?', {
                    title: 'ยืนยันการบันทึกการแก้ไข',
                    confirmButtonText: 'ยืนยัน',
                    cancelButtonText: 'ยกเลิก',
                }) :
                Promise.resolve(window.confirm('ยืนยันการบันทึกการแก้ไข\nยืนยันการบันทึกการแก้ไขและแนบไฟล์คำสั่งราชการนี้ใช่หรือไม่?'));

            confirmPromise.then((approved) => {
                if (!approved) {
                    return;
                }
                modalOrderEditConfirmApproved = true;
                if (typeof modalOrderEditForm.requestSubmit === 'function') {
                    modalOrderEditForm.requestSubmit();
                    return;
                }
                modalOrderEditForm.submit();
            });
        });

        document.addEventListener('click', (event) => {
            const trigger = event.target instanceof Element ? event.target.closest('.js-open-order-send-modal') : null;
            if (!trigger) {
                return;
            }
            event.preventDefault();
            const orderId = String(trigger.getAttribute('data-order-id') || '').trim();
            openOrderSendModal(orderId);
        });

        window.addEventListener('click', (event) => {
            if (event.target === orderEditModal) {
                orderEditModal.style.display = 'none';
            }
            if (event.target === orderSendModal) {
                closeOrderSendModal();
            }
        });

        if (initialSendModalOrderId > 0) {
            openOrderSendModal(String(initialSendModalOrderId), {
                preserveSelections: true,
            });
        }

        const trackFilterForm = document.querySelector('#orderMine form.circular-my-filter-grid');
        const trackTableWrap = document.querySelector('#orderMine .table-responsive.circular-my-table-wrap');

        if (trackFilterForm && trackTableWrap) {
            const queryInput = trackFilterForm.querySelector('input[name="q"]');
            const statusInput = trackFilterForm.querySelector('select[name="status"]');
            const sortInput = trackFilterForm.querySelector('select[name="sort"]');
            let debounceTimer = null;
            let activeController = null;
            let latestRequestId = 0;

            const buildTrackUrl = () => {
                const params = new URLSearchParams(new FormData(trackFilterForm));

                return `${window.location.pathname}?${params.toString()}`;
            };

            const refreshTrackTable = (delayMs = 0) => {
                window.clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(async () => {
                    const requestUrl = buildTrackUrl();
                    window.history.replaceState({}, '', requestUrl);

                    if (activeController) {
                        activeController.abort();
                    }

                    const controller = new AbortController();
                    activeController = controller;
                    const requestId = ++latestRequestId;

                    trackTableWrap.style.opacity = '0.55';
                    trackTableWrap.style.pointerEvents = 'none';

                    try {
                        const response = await fetch(requestUrl, {
                            method: 'GET',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                            signal: controller.signal,
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }

                        const html = await response.text();

                        if (requestId !== latestRequestId) {
                            return;
                        }

                        const doc = new DOMParser().parseFromString(html, 'text/html');
                        const nextTableWrap = doc.querySelector('#orderMine .table-responsive.circular-my-table-wrap');

                        if (nextTableWrap) {
                            trackTableWrap.innerHTML = nextTableWrap.innerHTML;
                            syncOrderSendModalData();
                        }
                    } catch (error) {
                        if (error && error.name !== 'AbortError') {
                            console.error('Failed to refresh order list:', error);
                        }
                    } finally {
                        if (requestId === latestRequestId) {
                            trackTableWrap.style.opacity = '';
                            trackTableWrap.style.pointerEvents = '';
                        }
                    }
                }, delayMs);
            };

            trackFilterForm.addEventListener('submit', (event) => {
                event.preventDefault();
                refreshTrackTable(0);
            });

            if (queryInput) {
                queryInput.addEventListener('input', () => {
                    refreshTrackTable(280);
                });
                queryInput.addEventListener('search', () => {
                    refreshTrackTable(0);
                });
            }

            if (statusInput) {
                statusInput.addEventListener('change', () => {
                    refreshTrackTable(0);
                });
            }

            if (sortInput) {
                sortInput.addEventListener('change', () => {
                    refreshTrackTable(0);
                });
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        if (window.__ordersCreateModalFallbackBound) {
            return;
        }
        window.__ordersCreateModalFallbackBound = true;

        const editModal = document.getElementById('modalOrderEditOverlay');
        const sendModal = document.getElementById('modalOrderSendOverlay');
        const viewModal = document.getElementById('modalOrderViewOverlay');
        const closeEdit = document.getElementById('closeModalOrderEdit');
        const closeSend = document.getElementById('closeModalOrderSend');
        const closeView = document.getElementById('closeModalOrderView');
        const closeViewAction = document.getElementById('modalOrderViewCloseBtn');
        const sendSubmitButton = document.getElementById('modalOrderSendSubmitBtn');

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = value ?? '';
        };

        const parseSendPayload = (orderId) => {
            const mapEl = document.querySelector('#orderMine .js-order-send-map');
            if (!mapEl) return null;
            try {
                const parsed = JSON.parse(mapEl.textContent || '{}');
                if (!parsed || typeof parsed !== 'object') return null;
                return parsed[String(orderId)] || null;
            } catch (error) {
                return null;
            }
        };

        const openEditFallback = (trigger) => {
            if (!editModal || !trigger) return;
            setValue('modalOrderId', String(trigger.getAttribute('data-order-id') || '').trim());
            setValue('modalOrderNo', String(trigger.getAttribute('data-order-no') || '').trim() || '-');
            setValue('modalOrderSubject', String(trigger.getAttribute('data-order-subject') || '').trim());
            setValue('modalOrderEffectiveDate', String(trigger.getAttribute('data-order-effective-date-raw') || '').trim());
            setValue('modalOrderDate', String(trigger.getAttribute('data-order-date-raw') || '').trim());
            setValue('modalOrderIssuer', String(trigger.getAttribute('data-order-issuer') || '').trim() || '-');
            editModal.style.display = 'flex';
        };

        const openSendFallback = (trigger) => {
            if (!sendModal || !trigger) return;
            const orderId = String(trigger.getAttribute('data-order-id') || '').trim();
            const payload = parseSendPayload(orderId);
            if (payload && typeof payload === 'object') {
                document.querySelectorAll('#modalOrderSendMainForm input[name="faction_ids[]"], #modalOrderSendMainForm input[name="role_ids[]"], #modalOrderSendMainForm input[name="person_ids[]"]').forEach((input) => {
                    if (input instanceof HTMLInputElement && !input.disabled) {
                        input.checked = false;
                        input.indeterminate = false;
                    }
                });
                const searchInput = document.getElementById('orderSendMainInput');
                const selectAll = document.getElementById('orderSendSelectAll');
                if (searchInput instanceof HTMLInputElement) {
                    searchInput.value = '';
                }
                if (selectAll instanceof HTMLInputElement) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
                setValue('modalOrderSendMainOrderId', orderId);
                setValue('modalOrderSendNo', String(payload.orderNo || '').trim() || '-');
                setValue('modalOrderSendSubject', String(payload.subject || '').trim() || '-');
                setValue('modalOrderSendEffectiveDate', String(payload.effectiveDate || '').trim());
                setValue('modalOrderSendDate', String(payload.orderDate || '').trim());
                setValue('modalOrderSendIssuer', String(payload.issuerName || '').trim() || '-');
                setValue('modalOrderSendGroup', String(payload.groupName || '').trim() || '-');

                const status = String(payload.status || '').trim().toUpperCase();
                const isSent = status === 'SENT';
                const title = document.getElementById('modalOrderSendTitle');
                const formSection = document.getElementById('modalOrderSendFormSection');
                const trackSection = document.getElementById('modalOrderTrackSection');
                if (title) title.textContent = isSent ? 'ติดตามการส่งคำสั่งราชการ' : 'ส่งคำสั่งราชการต่อ';
                if (formSection) formSection.style.display = isSent ? 'none' : '';
                if (trackSection) trackSection.style.display = isSent ? '' : 'none';
                if (sendSubmitButton) sendSubmitButton.style.display = isSent ? 'none' : '';
            }
            sendModal.style.display = 'flex';
        };

        closeEdit?.addEventListener('click', () => {
            if (editModal) editModal.style.display = 'none';
        });
        closeSend?.addEventListener('click', () => {
            if (sendModal) sendModal.style.display = 'none';
        });
        closeView?.addEventListener('click', () => {
            if (viewModal) viewModal.style.display = 'none';
        });
        closeViewAction?.addEventListener('click', () => {
            if (viewModal) viewModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === editModal) {
                editModal.style.display = 'none';
            }
            if (event.target === sendModal) {
                sendModal.style.display = 'none';
            }
            if (event.target === viewModal) {
                viewModal.style.display = 'none';
            }
        });

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            const shareCopyTrigger = target.closest('[data-order-share-copy]');
            if (shareCopyTrigger) {
                const shareLink = String(shareCopyTrigger.getAttribute('data-order-share-copy') || '').trim();
                if (shareLink !== '') {
                    const notify = (message, type = 'success') => {
                        if (window.Swal && typeof window.Swal.fire === 'function') {
                            window.Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: type === 'error' ? 'error' : 'success',
                                title: message,
                                showConfirmButton: false,
                                timer: 1800,
                                timerProgressBar: true,
                            });
                            return;
                        }
                        if (window.App && window.App.toast && typeof window.App.toast.show === 'function') {
                            window.App.toast.show(message, type);
                        }
                    };
                    const fallbackCopy = () => {
                        const textarea = document.createElement('textarea');
                        textarea.value = shareLink;
                        textarea.setAttribute('readonly', 'readonly');
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        const copied = document.execCommand('copy');
                        textarea.remove();
                        notify(copied ? 'คัดลอกลิงก์สำเร็จ' : 'ไม่สามารถคัดลอกลิงก์ได้', copied ? 'success' : 'error');
                    };

                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(shareLink)
                            .then(() => notify('คัดลอกลิงก์สำเร็จ'))
                            .catch(fallbackCopy);
                    } else {
                        fallbackCopy();
                    }
                }
                return;
            }

            const editTrigger = target.closest('.js-open-order-edit-modal');
            if (editTrigger) {
                window.setTimeout(() => {
                    if (editModal && editModal.style.display !== 'flex') {
                        openEditFallback(editTrigger);
                    }
                }, 0);
            }

            const sendTrigger = target.closest('.js-open-order-send-modal');
            if (sendTrigger) {
                window.setTimeout(() => {
                    if (sendModal && sendModal.style.display !== 'flex') {
                        openSendFallback(sendTrigger);
                    }
                }, 0);
            }
            const viewTrigger = target.closest('.js-open-order-view-modal');
            if (viewTrigger) {
                window.setTimeout(() => {
                    if (viewModal && viewModal.style.display !== 'flex') {
                        viewModal.style.display = 'flex';
                    }
                }, 0);
            }
        }, true);
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
