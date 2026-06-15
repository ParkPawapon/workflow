<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../view.php';

$items = (array) ($items ?? []);
$box_key = (string) ($box_key ?? 'normal');
$archived = (bool) ($archived ?? false);
$dh_year_options = array_values(array_filter(array_map('intval', (array) ($dh_year_options ?? [])), static function (int $year): bool {
    return $year > 0;
}));
$selected_dh_year = (int) ($selected_dh_year ?? 0);
$dh_year_label = $selected_dh_year > 0 ? (string) $selected_dh_year : 'ทั้งหมด';
$filter_type = (string) ($filter_type ?? 'all');
$filter_sort = (string) ($filter_sort ?? 'newest');
$filter_view = (string) ($filter_view ?? 'table1');
$filter_search = (string) ($filter_search ?? '');
$is_outside_view = (bool) ($is_outside_view ?? false);
$director_label = (string) ($director_label ?? 'ผอ./รักษาการ');
$show_type_filter = (bool) ($show_type_filter ?? true);
$show_book_type_column = (bool) ($show_book_type_column ?? true);
$page_section_label = (string) ($page_section_label ?? 'หนังสือเวียน');
$page_box_label = (string) ($page_box_label ?? ($archived ? 'หนังสือเวียนที่จัดเก็บ' : 'กล่องข้อความ'));
$forward_open_inbox_id = (int) ($forward_open_inbox_id ?? 0);
$detail_workflow_page = (string) ($detail_workflow_page ?? ($is_outside_view ? 'outgoing-view.php' : 'circular-view.php'));
$table_filter_title = 'สถานะรายการ';
$table_filter_label_map = [
    'director' => [
        'table1' => 'รอพิจารณา',
        'table2' => 'พิจารณาแล้ว',
    ],
    'clerk' => [
        'table1' => 'กำลังเสนอ',
        'table2' => 'ดำเนินการแล้ว',
    ],
    'normal' => [
        'table1' => 'กล่องข้อความ',
        'table2' => 'รายการอื่น',
    ],
];
$table_filter_labels = $table_filter_label_map[$box_key] ?? $table_filter_label_map['normal'];

require_once __DIR__ . '/../../rbac/current_user.php';

$values = $values ?? [];
$factions = $factions ?? [];
$roles = $roles ?? [];
$teachers = $teachers ?? [];
$is_edit_mode = (bool) ($is_edit_mode ?? false);
$edit_circular_id = (int) ($edit_circular_id ?? 0);
$existing_attachments = (array) ($existing_attachments ?? []);

$current_user = current_user() ?? [];
$current_pid = trim((string) ($current_user['pID'] ?? ''));
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
    $faction_name_map[$fid] = trim((string) ($faction['fName'] ?? $faction['fname'] ?? ''));
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

$selected_factions = array_map('strval', (array) ($values['faction_ids'] ?? []));
$selected_roles = array_map('strval', (array) ($values['role_ids'] ?? []));
$selected_people = array_map('strval', (array) ($values['person_ids'] ?? []));

$is_selected = static function (string $value, array $selected): bool {
    return in_array($value, $selected, true);
};

$faction_members = [];
$department_groups = [];
$role_groups = [];
$executive_members = [];
$subject_head_members = [];
$role_name_map = [];

foreach ($roles as $role) {
    $role_id = (int) ($role['roleID'] ?? 0);
    $role_name = trim((string) ($role['roleName'] ?? ''));

    if ($role_id <= 0 || $role_name === '') {
        continue;
    }

    $role_name_map[$role_id] = $role_name;
}

foreach ($teachers as $teacher) {
    $fid = (int) ($teacher['fID'] ?? 0);
    $did = (int) ($teacher['dID'] ?? 0);
    $position_id = (int) ($teacher['positionID'] ?? 0);
    $pid = trim((string) ($teacher['pID'] ?? ''));
    $name = trim((string) ($teacher['fName'] ?? ''));
    $department_name = trim((string) ($teacher['departmentName'] ?? ''));

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

    if (in_array($position_id, [1, 9, 2, 3, 4], true)) {
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

    if ($did > 0 && $department_name !== '') {
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

    $teacher_role_ids = function_exists('rbac_parse_role_ids')
        ? rbac_parse_role_ids($teacher['roleID'] ?? '')
        : array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', trim((string) ($teacher['roleID'] ?? ''))) ?: [])));

    foreach ($teacher_role_ids as $role_id) {
        $role_name = $role_name_map[(int) $role_id] ?? '';

        if ($role_name === '') {
            continue;
        }

        if (!isset($role_groups[$role_id])) {
            $role_groups[$role_id] = [
                'roleID' => (int) $role_id,
                'name' => $role_name,
                'members' => [],
            ];
        }

        $role_groups[$role_id]['members'][$pid] = [
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

if (!empty($role_groups)) {
    uasort($role_groups, static function (array $a, array $b): int {
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    foreach ($role_groups as $role_id => $role_group) {
        $members = array_values((array) ($role_group['members'] ?? []));
        usort($members, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
        $role_groups[$role_id]['members'] = $members;
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

$deputy_position_ids_for_forward = array_values(array_filter(array_map('intval', (array) ($deputy_position_ids ?? [])), static function (int $position_id): bool {
    return $position_id > 0;
}));
$forward_is_reviewer_return = $box_key === 'director'
    && ((bool) ($is_director_box ?? false) || (bool) ($is_acting_director ?? false) || (bool) ($is_deputy_reviewer ?? false));
$forward_is_registry_handoff = (bool) ($can_manage_external ?? false)
    && $box_key === 'clerk'
    && $filter_view === 'table2';
$forward_restrict_to_deputies = $forward_is_registry_handoff;
$forward_show_recipient_controls = !$forward_is_reviewer_return;
$forward_show_deputy_distribute_controls = !$is_outside_view
    && $box_key === 'normal'
    && (bool) ($is_deputy_reviewer ?? false)
    && !$archived;
$format_external_doc_heading = static function (array $item): string {
    $parts = [];
    $receive_seq = (int) ($item['ext_receive_seq'] ?? 0);
    $book_no = trim((string) ($item['ext_book_no'] ?? ''));

    if ($receive_seq > 0) {
        $parts[] = '#' . $receive_seq;
    }

    if ($book_no !== '') {
        $parts[] = $book_no;
    }

    return implode(' , ', $parts);
};
$deputy_forward_pids_for_forward = array_fill_keys(array_filter(array_map(static function ($pid): string {
    return trim((string) $pid);
}, (array) ($deputy_forward_pids ?? [])), static function (string $pid): bool {
    return $pid !== '';
}), true);

if ($forward_restrict_to_deputies) {
    $forward_deputy_members = [];

    foreach ($teachers as $teacher) {
        $pid = trim((string) ($teacher['pID'] ?? ''));
        $name = trim((string) ($teacher['fName'] ?? ''));
        $position_id = (int) ($teacher['positionID'] ?? 0);

        if (
            $pid === ''
            || $name === ''
            || (!isset($deputy_forward_pids_for_forward[$pid]) && !in_array($position_id, $deputy_position_ids_for_forward, true))
        ) {
            continue;
        }

        $forward_deputy_members[$pid] = [
            'pID' => $pid,
            'name' => $name,
        ];
    }

    $forward_deputy_members = array_values($forward_deputy_members);
    usort($forward_deputy_members, static function (array $a, array $b): int {
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
}

$sender_factions = [];

foreach ($factions as $faction) {
    $fid = (int) ($faction['fID'] ?? 0);
    $faction_name = trim((string) ($faction['fName'] ?? $faction['fname'] ?? ''));

    if ($fid <= 0 || $faction_name === '') {
        continue;
    }
    $normalized_faction_name = preg_replace('/\s+/u', '', $faction_name);

    if (strpos((string) $normalized_faction_name, 'ฝ่ายบริหาร') !== false) {
        continue;
    }
    $sender_factions[] = [
        'fID' => $fid,
        'fName' => $faction_name,
    ];
}

if ($forward_restrict_to_deputies) {
    $factions = [];
    $department_groups = [];
    $role_groups = [];
    $special_groups = [];

    if (!empty($forward_deputy_members)) {
        $special_groups[] = [
            'key' => 'forward-deputies',
            'name' => 'รองผู้อำนวยการ',
            'members' => $forward_deputy_members,
        ];
    }
}

ob_start();
?>
<style>
    .circular-action-stack {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .circular-action-stack .button-more-details,
    .circular-action-stack .button-open-workflow {
        min-width: 92px;
    }

    .circular-action-stack .button-open-workflow {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 32px;
        border: 1px solid rgba(var(--rgb-secondary), 0.4);
        border-radius: 8px;
        background: #fff;
        color: var(--color-secondary);
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        padding: 0 10px;
        line-height: 1;
    }

    .circular-action-stack .button-open-workflow:hover {
        background: rgba(var(--rgb-secondary), 0.08);
    }

    .circular-sender-stack {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
        line-height: 1.25;
    }

    .circular-sender-name {
        font-weight: 700;
        color: var(--color-primary-dark);
    }

    .circular-sender-faction {
        font-size: 12px;
        color: var(--color-neutral-dark);
    }

    #modalSender {
        white-space: pre-line;
    }

    .content-circular-notice-index .modal-overlay-circular-notice-index .modal-content .content-modal .content-topic-sec:nth-child(3) {
        border: none;
        margin: 30px 0 0;
        flex-direction: column;
        align-items: start;
    }

    .content-circular-notice-index .modal-overlay-circular-notice-index.keep-sending .content-modal .content-topic-sec input.subject-disabled-solid[disabled] {
        opacity: 1;
        color: var(--color-secondary);
        -webkit-text-fill-color: var(--color-secondary);
        background-color: rgba(var(--rgb-neutral-medium), 0.25);
        cursor: not-allowed;
    }

    .sender-row.margin {
        margin: 20px 0 0;
    }

    #forwardReceiptStatusSection table th:first-child,
    #forwardReceiptStatusSection table td:first-child,
    #noticeDetailReceiptStatusSection table th:first-child,
    #noticeDetailReceiptStatusSection table td:first-child {
        text-align: left;
    }

    #forwardReceiptStatusSection table th:nth-child(2),
    #forwardReceiptStatusSection table td:nth-child(2),
    #noticeDetailReceiptStatusSection table th:nth-child(2),
    #noticeDetailReceiptStatusSection table td:nth-child(2),
    .table-circular-notice-index table thead th:nth-child(2) {
        text-align: center;
    }

    #forwardReceiptStatusSection table th:nth-child(3),
    #forwardReceiptStatusSection table td:nth-child(3),
    #noticeDetailReceiptStatusSection table th:nth-child(3),
    #noticeDetailReceiptStatusSection table td:nth-child(3) {
        text-align: left;
    }

    #forwardViewGroupFid.native-forward-group-select {
        width: 100%;
        height: 50px;
        padding: 8px 20px;
        border: 1px solid var(--color-secondary);
        border-radius: 6px;
        background-color: var(--color-neutral-lightest);
        color: var(--color-secondary);
        font-size: var(--font-size-body-1);
        cursor: pointer;
        outline: none;
    }

    #forwardAnnouncementSection .select-all-box {
        align-items: center;
        font-size: var(--font-size-body-1);
        font-weight: 700;
        gap: 12px;
        min-height: 46px;
    }

    #forwardAnnouncementSection .select-all-box input[type="checkbox"] {
        transform: scale(1.25);
        transform-origin: center;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(1) {
        text-align: center;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(2) {
        text-align: start;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(3),
    .table-circular-notice-index.outside-person table tbody td:nth-child(3) {
        text-align: start;
    }


    .table-circular-notice-index.first-table table thead th:nth-child(1) {
        width: 45px !important;
        min-width: 45px !important;
        max-width: 45px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(2) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(3) {
        width: 720px !important;
        min-width: 720px !important;
        max-width: 720px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(4) {
        width: 210px !important;
        min-width: 210px !important;
        max-width: 210px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(5) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(6) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(2) {
        width: 180px !important;
        min-width: 180px !important;
        max-width: 180px !important;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(1) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(3) {
        width: 780px !important;
        min-width: 780px !important;
        max-width: 780px !important;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(4) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-index.outside-person table thead th:nth-child(5) {
        width: 200px !important;
        min-width: 200px !important;
        max-width: 200px !important;
    }

    @media screen and (max-width: 1024px) {
        .table-circular-notice-index.first-table table thead th:nth-child(1) {
            width: 45px !important;
            min-width: 45px !important;
            max-width: 45px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(2) {
            width: 100px !important;
            min-width: 100px !important;
            max-width: 100px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(3) {
            width: 500px !important;
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(4) {
            width: 190px !important;
            min-width: 190px !important;
            max-width: 190px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(5) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(6) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(1) {
            width: 150px !important;
            min-width: 150px !important;
            max-width: 150px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(2) {
            width: 100px !important;
            min-width: 100px !important;
            max-width: 100px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(3) {
            width: 500px !important;
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(4) {
            width: 130px !important;
            min-width: 130px !important;
            max-width: 130px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(5) {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 160px !important;
        }
    }

    @media screen and (max-width: 768px) {
        .table-circular-notice-index.first-table table thead th:nth-child(2) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(1) {
            width: 40px !important;
            min-width: 40px !important;
            max-width: 40px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(3) {
            width: 350px !important;
            min-width: 350px !important;
            max-width: 350px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(4) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(5) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }

        .table-circular-notice-index.first-table table thead th:nth-child(6) {
            width: 100px !important;
            min-width: 100px !important;
            max-width: 100px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(2) {
            width: 110px !important;
            min-width: 110px !important;
            max-width: 110px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(1) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(3) {
            width: 400px !important;
            min-width: 400px !important;
            max-width: 400px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(4) {
            width: 90px !important;
            min-width: 90px !important;
            max-width: 90px !important;
        }

        .table-circular-notice-index.outside-person table thead th:nth-child(5) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }

        .table-circular-notice-index.first-table table tbody td {
            line-height: 2;
        }

        .circular-sender-faction {
            font-size: 8px;
        }
    }

    <?php if ($archived) : ?>.table-circular-notice-index.first-table table thead th:nth-child(1),
    .table-circular-notice-index.first-table table tbody td:nth-child(1),
    .table-circular-notice-index.first-table table thead th:nth-child(5),
    .table-circular-notice-index.first-table table tbody td:nth-child(5) {
        text-align: center;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(2),
    .table-circular-notice-index.first-table table tbody td:nth-child(2),
    .table-circular-notice-index.first-table table thead th:nth-child(3),
    .table-circular-notice-index.first-table table tbody td:nth-child(3),
    .table-circular-notice-index.first-table table thead th:nth-child(4),
    .table-circular-notice-index.first-table table tbody td:nth-child(4) {
        text-align: start;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(1) {
        width: 80px !important;
        min-width: 80px !important;
        max-width: 80px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(2) {
        width: 600px !important;
        min-width: 600px !important;
        max-width: 600px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(3) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(4) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-index.first-table table thead th:nth-child(5) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    <?php endif; ?>
</style>
<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p><?= h($page_section_label) ?> / <?= h($page_box_label) ?></p>
</div>

<form id="circularFilterForm" method="GET">
    <input type="hidden" name="box" value="<?= h($box_key) ?>">
    <?php if ($archived) : ?>
        <input type="hidden" name="archived" value="1">
    <?php endif; ?>
    <input type="hidden" name="dh_year" id="filterYearInput" value="<?= h((string) $selected_dh_year) ?>">
    <input type="hidden" name="type" id="filterTypeInput" value="<?= h($filter_type) ?>">
    <input type="hidden" name="sort" id="filterSortInput" value="<?= h($filter_sort) ?>">
    <input type="hidden" name="view" id="filterViewInput" value="<?= h($filter_view) ?>">
</form>
<input type="hidden" id="csrfToken" value="<?= h(csrf_token()) ?>">

<header class="header-circular-notice-index<?= h($is_outside_view ? ' outside-person' : '') ?>">
    <div class="circular-notice-index-control">
        <?php if ($show_type_filter) : ?>
            <div class="page-selector">
                <p>แสดงตามประเภทหนังสือ</p>

                <div class="custom-select-wrapper" data-target="filterTypeInput">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($filter_type === 'internal' ? 'ภายใน' : ($filter_type === 'external' ? 'ภายนอก' : 'ทั้งหมด')) ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option<?= h($filter_type === 'external' ? ' selected' : '') ?>" data-value="external">ภายนอก</div>
                        <div class="custom-option<?= h($filter_type === 'internal' ? ' selected' : '') ?>" data-value="internal">ภายใน</div>
                        <div class="custom-option<?= h($filter_type === 'all' ? ' selected' : '') ?>" data-value="all">ทั้งหมด</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="page-selector">
            <p>แสดงตามปีสารบรรณ</p>

            <div class="custom-select-wrapper" data-target="filterYearInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($dh_year_label) ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="custom-options">
                    <div class="custom-option<?= $selected_dh_year <= 0 ? ' selected' : '' ?>" data-value="0">ทั้งหมด</div>
                    <?php foreach ($dh_year_options as $year_option) : ?>
                        <div class="custom-option<?= $selected_dh_year === (int) $year_option ? ' selected' : '' ?>" data-value="<?= h((string) $year_option) ?>"><?= h((string) $year_option) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="page-selector">
            <p>แสดงตาม</p>

            <div class="custom-select-wrapper" data-target="filterSortInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($filter_sort === 'oldest' ? 'เก่าไปใหม่' : 'ใหม่ไปเก่า') ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="custom-options">
                    <div class="custom-option<?= h($filter_sort === 'newest' ? ' selected' : '') ?>" data-value="newest">ใหม่ไปเก่า</div>
                    <div class="custom-option<?= h($filter_sort === 'oldest' ? ' selected' : '') ?>" data-value="oldest">เก่าไปใหม่</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_outside_view) : ?>
        <div class="table-change">
            <p><?= h($table_filter_title) ?></p>
            <div class="button-table">
                <button class="<?= h($filter_view === 'table1' ? 'active' : '') ?>" type="button" data-view="table1"><?= h((string) ($table_filter_labels['table1'] ?? 'รายการหลัก')) ?></button>
                <button class="<?= h($filter_view === 'table2' ? 'active' : '') ?>" type="button" data-view="table2"><?= h((string) ($table_filter_labels['table2'] ?? 'รายการอื่น')) ?></button>
            </div>
        </div>
    <?php endif; ?>
</header>

<section class="content-circular-notice-index" data-outgoing-notice data-ajax-filter="true">
    <div class="search-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="search-input" name="q" form="circularFilterForm" value="<?= h($filter_search) ?>" placeholder="ค้นหาข้อความด้วย..." data-auto-submit="true" data-auto-submit-delay="450" autocomplete="off">
        </div>
    </div>

    <?php if (!$is_outside_view) : ?>
        <?php $show_bulk_archive_controls = !$archived; ?>
        <form
            id="bulkActionForm"
            method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="archive_selected">
            <div class="table-circular-notice-index first-table">
                <table>
                    <thead>
                        <tr>
                            <?php if ($show_bulk_archive_controls) : ?>
                                <th>
                                    <input type="checkbox" class="check-table checkall" id="checkAllCircular">
                                </th>
                            <?php endif; ?>
                            <th>จัดการ</th>
                            <?php if ($show_book_type_column) : ?>
                                <th>ประเภทหนังสือ</th>
                            <?php endif; ?>
                            <th>เลขที่ / หัวเรื่อง</th>
                            <th>ผู้ส่ง</th>
                            <th>วันที่ส่ง</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)) : ?>
                            <tr>
                                <td colspan="<?= h((string) (($show_book_type_column ? 6 : 5) + ($show_bulk_archive_controls ? 1 : 0))) ?>" class="enterprise-empty">ไม่มีรายการ</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($items as $item) : ?>
                                <?php
                                $is_read = (bool) ($item['is_read'] ?? false);
                                $file_json = (string) ($item['files_json'] ?? '[]');
                                $read_stats_json = (string) ($item['read_stats_json'] ?? '[]');
                                $sender_modal_text = trim((string) ($item['sender_name'] ?? '-'));
                                $ext_group_id = (int) ($item['ext_group_fid'] ?? 0);
                                $ext_group_name = trim((string) ($item['ext_group_name'] ?? ''));
                                $external_doc_heading = $format_external_doc_heading($item);
                                $can_deputy_distribute_item = $box_key === 'normal'
                                    && (bool) ($is_deputy_reviewer ?? false)
                                    && strtoupper((string) ($item['type'] ?? '')) === 'EXTERNAL'
                                    && strtoupper((string) ($item['status_key'] ?? '')) === EXTERNAL_STATUS_FORWARDED;

                                if ($ext_group_name === '' && $ext_group_id > 0) {
                                    $ext_group_name = (string) ($faction_name_map[$ext_group_id] ?? '');
                                }

                                if (!empty($item['sender_faction_name'])) {
                                    $sender_modal_text .= ' (' . trim((string) $item['sender_faction_name']) . ')';
                                }
                                ?>
                                <tr>
                                    <?php if ($show_bulk_archive_controls) : ?>
                                        <td>
                                            <input type="checkbox" class="check-table" name="selected_ids[]" value="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>">
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="circular-action-stack">
                                            <button
                                                class="booking-action-btn secondary js-open-circular-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                data-sender="<?= h($sender_modal_text) ?>"
                                                data-sender-name="<?= h((string) ($item['modal_sender_name'] ?? $item['sender_name'] ?? '-')) ?>"
                                                data-sender-faction="<?= h((string) ($item['sender_faction_name'] ?? '')) ?>"
                                                data-date="<?= h((string) ($item['delivered_date_long'] ?? $item['delivered_date'] ?? '-')) ?>"
                                                data-urgency="<?= h((string) ($item['ext_priority_label'] ?? 'ปกติ')) ?>"
                                                data-urgency-class="<?= h((string) ($item['urgency_class'] ?? 'normal')) ?>"
                                                data-bookno="<?= h((string) ($item['ext_book_no'] ?? '')) ?>"
                                                data-issued="<?= h((string) ($item['ext_issued_date'] ?? '-')) ?>"
                                                data-issued-raw="<?= h((string) ($item['ext_issued_date_raw'] ?? '')) ?>"
                                                data-from="<?= h((string) ($item['ext_from_text'] ?? '')) ?>"
                                                data-to="<?= h($director_label) ?>"
                                                data-group="<?= h($ext_group_name !== '' ? $ext_group_name : '-') ?>"
                                                data-group-fid="<?= h((string) $ext_group_id) ?>"
                                                data-detail="<?= h((string) ($item['detail_display'] ?? $item['detail'] ?? '')) ?>"
                                                data-link="<?= h((string) ($item['link_url'] ?? '')) ?>"
                                                data-type="<?= h((string) ($item['type_label'] ?? '')) ?>"
                                                data-status="<?= h((string) ($item['status_label'] ?? '-')) ?>"
                                                data-consider="<?= h((string) ($item['consider_class'] ?? 'considering')) ?>"
                                                data-files="<?= h($file_json) ?>"
                                                data-read-stats="<?= h($read_stats_json) ?>"
                                                data-show-read-stats="<?= h($can_deputy_distribute_item ? '1' : '0') ?>"
                                                data-review-chain-registry-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['registry_comment'] ?? '') : '') ?>"
                                                data-review-chain-director-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['director_comment'] ?? '') : '') ?>"
                                                data-review-chain-director-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                data-director-comment="<?= h((string) ($item['director_comment'] ?? '')) ?>"
                                                data-director-comment-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                data-latest-comment="<?= h((string) ($item['latest_sender_comment'] ?? '')) ?>"
                                                data-latest-comment-label="<?= h((string) ($item['latest_sender_comment_label'] ?? '')) ?>"
                                                data-announcement-comment="<?= h((string) ($item['announcement_comment'] ?? '')) ?>"
                                                data-announcement-comment-label="<?= h((string) ($item['announcement_comment_label'] ?? '')) ?>"
                                                data-deputy-comment="<?= h((string) ($item['deputy_comment'] ?? '')) ?>"
                                                data-deputy-comment-label="<?= h((string) ($item['deputy_comment_label'] ?? 'ความคิดเห็นของรองผู้อำนวยการ')) ?>"
                                                data-deputy-forwarded="<?= h(!empty($item['has_deputy_distributed']) ? '1' : '0') ?>"
                                                data-announced="<?= h(!empty($item['is_announced']) ? '1' : '0') ?>"
                                                data-hide-memo-detail="<?= h($box_key === 'normal' && strtoupper((string) ($item['type'] ?? '')) === CIRCULAR_TYPE_EXTERNAL ? '1' : '0') ?>">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูรายละเอียด</span>
                                            </button>
                                            <?php if (empty($item['is_announced']) && !($archived && $box_key === 'normal' && $filter_type === 'external' && $filter_view === 'table1')) : ?>
                                                <button
                                                    class="booking-action-btn secondary js-open-circular-send-modal"
                                                    type="button"
                                                    data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                    data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                    data-urgency="<?= h((string) ($item['ext_priority_label'] ?? 'ปกติ')) ?>"
                                                    data-urgency-class="<?= h((string) ($item['urgency_class'] ?? 'normal')) ?>"
                                                    data-bookno="<?= h((string) ($item['ext_book_no'] ?? '')) ?>"
                                                    data-issued="<?= h((string) ($item['ext_issued_date'] ?? '-')) ?>"
                                                    data-issued-raw="<?= h((string) ($item['ext_issued_date_raw'] ?? '')) ?>"
                                                    data-from="<?= h((string) ($item['ext_from_text'] ?? '')) ?>"
                                                    data-group="<?= h($ext_group_name !== '' ? $ext_group_name : '-') ?>"
                                                    data-group-fid="<?= h((string) $ext_group_id) ?>"
                                                    data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                    data-sender="<?= h($sender_modal_text) ?>"
                                                    data-sender-name="<?= h((string) ($item['modal_sender_name'] ?? $item['sender_name'] ?? '-')) ?>"
                                                    data-sender-faction="<?= h((string) ($item['sender_faction_name'] ?? '')) ?>"
                                                    data-date="<?= h((string) ($item['created_date_long'] ?? $item['delivered_date_long'] ?? $item['delivered_date'] ?? '-')) ?>"
                                                    data-detail="<?= h((string) ($item['detail_display'] ?? $item['detail'] ?? '')) ?>"
                                                    data-link="<?= h((string) ($item['link_url'] ?? '')) ?>"
                                                    data-owner-pid="<?= h((string) ($item['owner_pid'] ?? '')) ?>"
                                                    data-files="<?= h($file_json) ?>"
                                                    data-forwarded-pids="<?= h($forward_is_registry_handoff ? '[]' : (string) ($item['forwarded_recipient_pids_json'] ?? '[]')) ?>"
                                                    data-read-stats="<?= h($read_stats_json) ?>"
                                                    data-review-chain-registry-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['registry_comment'] ?? '') : '') ?>"
                                                    data-review-chain-director-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['director_comment'] ?? '') : '') ?>"
                                                    data-review-chain-director-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                    data-director-comment="<?= h((string) ($item['director_comment'] ?? '')) ?>"
                                                    data-director-comment-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                    data-latest-comment="<?= h((string) ($item['latest_sender_comment'] ?? '')) ?>"
                                                    data-latest-comment-label="<?= h((string) ($item['latest_sender_comment_label'] ?? '')) ?>"
                                                    data-announcement-comment="<?= h((string) ($item['announcement_comment'] ?? '')) ?>"
                                                    data-announcement-comment-label="<?= h((string) ($item['announcement_comment_label'] ?? '')) ?>"
                                                    data-deputy-comment="<?= h((string) ($item['deputy_comment'] ?? '')) ?>"
                                                    data-deputy-comment-label="<?= h((string) ($item['deputy_comment_label'] ?? 'ความคิดเห็นของรองผู้อำนวยการ')) ?>"
                                                    data-deputy-forwarded="<?= h(!empty($item['has_deputy_distributed']) ? '1' : '0') ?>"
                                                    data-announced="<?= h(!empty($item['is_announced']) ? '1' : '0') ?>"
                                                    data-deputy-distribute="<?= h($can_deputy_distribute_item ? '1' : '0') ?>"
                                                    data-registry-handoff="<?= h($forward_is_registry_handoff ? '1' : '0') ?>"
                                                    data-hide-memo-detail="<?= h($box_key === 'normal' && strtoupper((string) ($item['type'] ?? '')) === CIRCULAR_TYPE_EXTERNAL ? '1' : '0') ?>">
                                                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                                    <span class="tooltip">ส่งหนังสือต่อ</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php if ($show_book_type_column) : ?>
                                        <td><?= h((string) ($item['type_label'] ?? '')) ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($external_doc_heading !== '') : ?>
                                            <p><?= h($external_doc_heading) ?></p>
                                        <?php endif; ?>
                                        <p><?= h((string) ($item['subject'] ?? '')) ?></p>
                                    </td>
                                    <td>
                                        <div class="circular-sender-stack">
                                            <span class="circular-sender-name"><?= h((string) ($item['list_sender_name'] ?? $item['sender_name'] ?? '-')) ?></span>
                                            <?php if (!empty($item['list_sender_faction_name'] ?? $item['sender_faction_name'] ?? '')) : ?>
                                                <span class="circular-sender-faction"><?= h((string) ($item['list_sender_faction_name'] ?? $item['sender_faction_name'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= h((string) ($item['delivered_date'] ?? '-')) ?></td>
                                    <td><span class="status-badge <?= h($is_read ? 'read' : 'unread') ?>"><?= h($is_read ? 'อ่านแล้ว' : 'ยังไม่อ่าน') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php else : ?>
        <div class="table-circular-notice-index outside-person">
            <table>
                <thead>
                    <tr>
                        <th>จัดการ</th>
                        <th>วันที่รับ</th>
                        <th>เลขที่ / เรื่อง</th>
                        <th>ความเร่งด่วน</th>
                        <th>สถานะปัจุบัน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)) : ?>
                        <tr>
                            <td colspan="5" class="enterprise-empty">ไม่มีรายการ</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($items as $item) : ?>
                            <?php
                            $file_json = (string) ($item['files_json'] ?? '[]');
                            $read_stats_json = (string) ($item['read_stats_json'] ?? '[]');
                            $priority_label = (string) ($item['ext_priority_label'] ?? 'ปกติ');
                            $ext_group_id = (int) ($item['ext_group_fid'] ?? 0);
                            $ext_group_name = trim((string) ($item['ext_group_name'] ?? ''));
                            $external_doc_heading = $format_external_doc_heading($item);

                            if ($ext_group_name === '' && $ext_group_id > 0) {
                                $ext_group_name = (string) ($faction_name_map[$ext_group_id] ?? '');
                            }

                            $status_key_for_action = strtoupper((string) ($item['status_key'] ?? ''));
                            $show_workflow_action = !($box_key === 'clerk' && $filter_view === 'table1');

                            if ((bool) ($can_manage_external ?? false) && $box_key === 'clerk') {
                                $show_workflow_action = $status_key_for_action === EXTERNAL_STATUS_REVIEWED;
                            }

                            if ($box_key === 'director' && $filter_view === 'table2') {
                                $show_workflow_action = false;
                            }

                            if (!empty($item['is_announced'])) {
                                $show_workflow_action = false;
                            }

                            if ($archived && $box_key === 'normal' && $filter_type === 'external' && $filter_view === 'table1') {
                                $show_workflow_action = false;
                            }

                            $show_detail_action = !($box_key === 'director' && $filter_view === 'table1' && $show_workflow_action);
                            ?>
                            <tr>
                                <td>
                                    <div class="circular-action-stack">
                                        <?php if ($show_detail_action) : ?>
                                            <button
                                                class="booking-action-btn secondary js-open-circular-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                data-urgency="<?= h($priority_label) ?>"
                                                data-urgency-class="<?= h((string) ($item['urgency_class'] ?? 'normal')) ?>"
                                                data-bookno="<?= h((string) ($item['ext_book_no'] ?? '')) ?>"
                                                data-issued="<?= h((string) ($item['ext_issued_date'] ?? '-')) ?>"
                                                data-issued-raw="<?= h((string) ($item['ext_issued_date_raw'] ?? '')) ?>"
                                                data-from="<?= h((string) ($item['ext_from_text'] ?? '')) ?>"
                                                data-to="<?= h($director_label) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                data-group="<?= h($ext_group_name !== '' ? $ext_group_name : '-') ?>"
                                                data-group-fid="<?= h((string) $ext_group_id) ?>"
                                                data-detail="<?= h((string) ($item['detail_display'] ?? $item['detail'] ?? '')) ?>"
                                                data-link="<?= h((string) ($item['link_url'] ?? '')) ?>"
                                                data-sender-name="<?= h((string) ($item['modal_sender_name'] ?? $item['sender_name'] ?? '-')) ?>"
                                                data-sender-faction="<?= h((string) ($item['sender_faction_name'] ?? '')) ?>"
                                                data-status="<?= h((string) ($item['status_label'] ?? '-')) ?>"
                                                data-consider="<?= h((string) ($item['consider_class'] ?? 'considering')) ?>"
                                                data-files="<?= h($file_json) ?>"
                                                data-review-chain-registry-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['registry_comment'] ?? '') : '') ?>"
                                                data-review-chain-director-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['director_comment'] ?? '') : '') ?>"
                                                data-review-chain-director-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                data-director-comment="<?= h((string) ($item['director_comment'] ?? '')) ?>"
                                                data-director-comment-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                data-latest-comment="<?= h((string) ($item['latest_sender_comment'] ?? '')) ?>"
                                                data-latest-comment-label="<?= h((string) ($item['latest_sender_comment_label'] ?? '')) ?>"
                                                data-announcement-comment="<?= h((string) ($item['announcement_comment'] ?? '')) ?>"
                                                data-announcement-comment-label="<?= h((string) ($item['announcement_comment_label'] ?? '')) ?>"
                                                data-deputy-comment="<?= h((string) ($item['deputy_comment'] ?? '')) ?>"
                                                data-deputy-comment-label="<?= h((string) ($item['deputy_comment_label'] ?? 'ความคิดเห็นของรองผู้อำนวยการ')) ?>"
                                                data-deputy-forwarded="<?= h(!empty($item['has_deputy_distributed']) ? '1' : '0') ?>"
                                                data-announced="<?= h(!empty($item['is_announced']) ? '1' : '0') ?>"
                                                data-hide-memo-detail="<?= h($box_key === 'normal' && strtoupper((string) ($item['type'] ?? '')) === CIRCULAR_TYPE_EXTERNAL ? '1' : '0') ?>"
                                                data-received-time="<?= h((string) ($item['delivered_time'] ?? '-')) ?>">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูรายละเอียด</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($show_workflow_action) : ?>
                                            <button
                                                class="booking-action-btn secondary js-open-circular-send-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                data-urgency="<?= h($priority_label) ?>"
                                                data-urgency-class="<?= h((string) ($item['urgency_class'] ?? 'normal')) ?>"
                                                data-bookno="<?= h((string) ($item['ext_book_no'] ?? '')) ?>"
                                                data-issued="<?= h((string) ($item['ext_issued_date'] ?? '-')) ?>"
                                                data-issued-raw="<?= h((string) ($item['ext_issued_date_raw'] ?? '')) ?>"
                                                data-from="<?= h((string) ($item['ext_from_text'] ?? '')) ?>"
                                                data-group="<?= h($ext_group_name !== '' ? $ext_group_name : '-') ?>"
                                                data-group-fid="<?= h((string) $ext_group_id) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                data-sender-name="<?= h((string) ($item['modal_sender_name'] ?? $item['sender_name'] ?? '-')) ?>"
                                                data-sender-faction="<?= h((string) ($item['sender_faction_name'] ?? '')) ?>"
                                                data-detail="<?= h((string) ($item['detail_display'] ?? $item['detail'] ?? '')) ?>"
                                                data-link="<?= h((string) ($item['link_url'] ?? '')) ?>"
                                                data-owner-pid="<?= h((string) ($item['owner_pid'] ?? '')) ?>"
                                                data-files="<?= h($file_json) ?>"
                                                data-forwarded-pids="<?= h($forward_is_registry_handoff ? '[]' : (string) ($item['forwarded_recipient_pids_json'] ?? '[]')) ?>"
                                                data-read-stats="<?= h($read_stats_json) ?>"
                                                data-review-chain-registry-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['registry_comment'] ?? '') : '') ?>"
                                                data-review-chain-director-comment="<?= h((bool) ($item['show_review_chain_comments'] ?? false) ? (string) ($item['director_comment'] ?? '') : '') ?>"
                                                data-review-chain-director-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                data-director-comment="<?= h((string) ($item['director_comment'] ?? '')) ?>"
                                                data-director-comment-label="<?= h((string) ($item['director_comment_label'] ?? 'ความคิดเห็นของผู้อำนวยการโรงเรียน')) ?>"
                                                data-latest-comment="<?= h((string) ($item['latest_sender_comment'] ?? '')) ?>"
                                                data-latest-comment-label="<?= h((string) ($item['latest_sender_comment_label'] ?? '')) ?>"
                                                data-announcement-comment="<?= h((string) ($item['announcement_comment'] ?? '')) ?>"
                                                data-announcement-comment-label="<?= h((string) ($item['announcement_comment_label'] ?? '')) ?>"
                                                data-deputy-comment="<?= h((string) ($item['deputy_comment'] ?? '')) ?>"
                                                data-deputy-comment-label="<?= h((string) ($item['deputy_comment_label'] ?? 'ความคิดเห็นของรองผู้อำนวยการ')) ?>"
                                                data-deputy-forwarded="<?= h(!empty($item['has_deputy_distributed']) ? '1' : '0') ?>"
                                                data-announced="<?= h(!empty($item['is_announced']) ? '1' : '0') ?>"
                                                data-deputy-distribute="<?= h($can_deputy_distribute_item ? '1' : '0') ?>"
                                                data-registry-handoff="<?= h($forward_is_registry_handoff ? '1' : '0') ?>"
                                                data-hide-memo-detail="<?= h($box_key === 'normal' && strtoupper((string) ($item['type'] ?? '')) === CIRCULAR_TYPE_EXTERNAL ? '1' : '0') ?>">
                                                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                                <span class="tooltip">อ่าน/ดำเนินการ</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <p><?= h((string) ($item['delivered_date_plain'] ?? $item['delivered_date_long'] ?? $item['delivered_date'] ?? '-')) ?></p>
                                    <p><?= h((string) ($item['delivered_time'] ?? '-')) ?></p>
                                </td>
                                <td>
                                    <p><?= h($external_doc_heading !== '' ? $external_doc_heading : '-') ?></p>
                                    <p><?= h((string) ($item['subject'] ?? '')) ?></p>
                                </td>
                                <td><button class="urgency-status <?= h((string) ($item['urgency_class'] ?? 'normal')) ?>">
                                        <p><?= h($priority_label) ?></p>
                                    </button></td>
                                <td><span class="status-pill <?= h((string) ($item['status_pill_class'] ?? 'pending')) ?>"><?= h((string) ($item['status_label'] ?? '-')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!$is_outside_view) : ?>
        <div class="modal-overlay-circular-notice-index keep-sending" id="modalNoticeKeepOverlay">
            <div class="modal-content">
                <div class="header-modal">
                    <div class="first-header">
                        <p>แสดงข้อความรายละเอียดหนังสือเวียน</p>
                    </div>
                    <div class="sec-header">
                        <div class="consider-status considering" id="modalConsiderStatus">ส่งแล้ว</div>
                        <i class="fa-solid fa-xmark" id="closeModalNoticeKeep"></i>
                    </div>
                </div>

                <div class="content-modal">
                    <form method="POST" enctype="multipart/form-data" data-validate class="container-circular-notice-sending" id="circularDetailForm" style="box-shadow:none; padding: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="edit_circular_id" id="editTargetCircularId" value="">
                        <div class="type-urgent">
                            <p>ประเภท</p>
                            <div class="radio-group-urgent">
                                <input type="radio" name="noticeViewPriority" value="normal" data-notice-view-urgent="normal" id="noticeOutgoingPriorityNormal" checked disabled>
                                <label for="noticeOutgoingPriorityNormal">ปกติ</label>
                                <input type="radio" name="noticeViewPriority" value="urgent" data-notice-view-urgent="urgent" id="noticeOutgoingPriorityUrgent" disabled>
                                <label for="noticeOutgoingPriorityUrgent">ด่วน</label>
                                <input type="radio" name="noticeViewPriority" value="high" data-notice-view-urgent="high" id="noticeOutgoingPriorityHigh" disabled>
                                <label for="noticeOutgoingPriorityHigh">ด่วนมาก</label>
                                <input type="radio" name="noticeViewPriority" value="highest" data-notice-view-urgent="highest" id="noticeOutgoingPriorityHighest" disabled>
                                <label for="noticeOutgoingPriorityHighest">ด่วนที่สุด</label>
                            </div>
                        </div>

                        <div class="sender-row">
                            <div class="form-group sender-field">
                                <label><b>เลขที่หนังสือ</b></label>
                                <input type="text" id="noticeOutgoingViewBookNo" value="-" disabled>
                            </div>
                            <div class="form-group">
                                <label><b>ลงวันที่</b></label>
                                <input type="text" id="noticeOutgoingViewIssuedDate" value="-" disabled>
                            </div>
                        </div>

                        <div class="sender-row">
                            <div class="form-group">
                                <label><b>เรื่อง</b></label>
                                <input type="text" id="noticeOutgoingViewSubjectText" value="-" disabled>
                            </div>
                            <div class="form-group">
                                <label><b>จาก</b></label>
                                <input type="text" id="noticeOutgoingViewFrom" value="-" disabled>
                            </div>
                        </div>

                        <div class="form-group sender-field">
                            <label><b>ถึงกลุ่ม</b></label>
                            <input type="text" id="noticeOutgoingViewGroup" value="-" disabled>
                        </div>

                        <div class="form-group" id="noticeOutgoingViewDetailSection">
                            <label><b>เกษียณหนังสือ</b></label>
                            <textarea id="notice_memo_editor_view" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="content-file-sec" id="noticeOutgoingViewCoverSection" style="display: none;">
                            <p><strong>ไฟล์หนังสือนำ</strong></p>
                            <div class="file-section" id="noticeOutgoingViewCoverList"></div>
                        </div>

                        <div class="content-file-sec" id="noticeOutgoingViewAttachmentSection" style="display: none;">
                            <p><strong>ไฟล์เอกสารแนบเพิ่มเติม</strong></p>
                            <div class="file-section" id="noticeOutgoingViewAttachmentList"></div>
                        </div>

                        <div class="form-group sender-field">
                            <label><b>แนบลิงก์</b></label>
                            <input type="text" id="noticeOutgoingViewLink" value="-" disabled />
                        </div>

                        <div class="form-group sender-field">
                            <label><b>ผู้รับหนังสือ</b></label>
                            <input type="text" id="noticeOutgoingViewProposer" value="-" disabled>
                        </div>

                        <div class="form-group" id="noticeOutgoingDirectorCommentSection">
                            <label><b id="noticeOutgoingLatestCommentLabel">ความคิดเห็นของผู้ส่งล่าสุด</b></label>
                            <textarea id="noticeOutgoingDirectorComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="form-group" id="noticeOutgoingRegistryCommentSection" style="display: none;">
                            <label><b>ความคิดเห็นของเจ้าหน้าที่สารบรรณ</b></label>
                            <textarea id="noticeOutgoingRegistryComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="form-group" id="noticeOutgoingReviewCommentSection" style="display: none;">
                            <label><b id="noticeOutgoingReviewCommentLabel">ความคิดเห็นของผู้อำนวยการโรงเรียน</b></label>
                            <textarea id="noticeOutgoingReviewComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="form-group" id="noticeOutgoingDeputyCommentSection" style="display: none;">
                            <label><b id="noticeOutgoingDeputyCommentLabel">ความคิดเห็นของรองผู้อำนวยการ</b></label>
                            <textarea id="noticeOutgoingDeputyComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="content-read-sec" id="noticeDetailReceiptStatusSection" style="display: none;">
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
                                    <tbody id="noticeDetailReceiptStatusTableBody">
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
    <?php else : ?>

        <div class="modal-overlay-circular-notice-index outside-person" id="modalNoticeKeepOverlay">
            <div class="modal-content">
                <div class="header-modal">
                    <div class="first-header">
                        <p>แสดงข้อความรายละเอียดหนังสือเวียน</p>
                    </div>
                    <div class="sec-header">
                        <div class="consider-status considering" id="modalConsiderStatus">กำลังเสนอ</div>
                        <i class="fa-solid fa-xmark" id="closeModalNoticeKeep"></i>
                    </div>
                </div>

                <div class="content-modal">
                    <form method="POST" enctype="multipart/form-data" data-validate class="container-circular-notice-sending" id="circularEditForm" style="box-shadow:none; padding: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="inbox_id" data-send-inbox-id value="">
                        <input type="hidden" name="circular_id" data-send-circular-id value="">
                        <input type="hidden" name="action" value="forward">
                        <input type="hidden" name="edit_circular_id" id="editTargetCircularId" value="">
                        <div class="type-urgent">
                            <p>ประเภท</p>
                            <div class="radio-group-urgent">
                                <input type="radio" name="noticeViewPriority" value="normal" data-notice-view-urgent="normal" id="noticeOutgoingPriorityNormal" checked disabled>
                                <label for="noticeOutgoingPriorityNormal">ปกติ</label>
                                <input type="radio" name="noticeViewPriority" value="urgent" data-notice-view-urgent="urgent" id="noticeOutgoingPriorityUrgent" disabled>
                                <label for="noticeOutgoingPriorityUrgent">ด่วน</label>
                                <input type="radio" name="noticeViewPriority" value="high" data-notice-view-urgent="high" id="noticeOutgoingPriorityHigh" disabled>
                                <label for="noticeOutgoingPriorityHigh">ด่วนมาก</label>
                                <input type="radio" name="noticeViewPriority" value="highest" data-notice-view-urgent="highest" id="noticeOutgoingPriorityHighest" disabled>
                                <label for="noticeOutgoingPriorityHighest">ด่วนที่สุด</label>
                            </div>
                        </div>

                        <div class="sender-row">
                            <div class="form-group sender-field">
                                <label><b>เลขที่หนังสือ</b></label>
                                <input type="text" id="noticeOutgoingViewBookNo" value="-" disabled>
                            </div>
                            <div class="form-group">
                                <label><b>ลงวันที่</b></label>
                                <input type="text" id="noticeOutgoingViewIssuedDate" value="-" disabled>
                            </div>
                        </div>

                        <div class="sender-row">
                            <div class="form-group">
                                <label><b>เรื่อง</b></label>
                                <input type="text" id="noticeOutgoingViewSubjectText" value="-" disabled>
                            </div>
                            <div class="form-group">
                                <label><b>จาก</b></label>
                                <input type="text" id="noticeOutgoingViewFrom" value="-" disabled>
                            </div>
                        </div>

                        <div class="form-group sender-field">
                            <label><b>ถึงกลุ่ม</b></label>
                            <input type="text" id="noticeOutgoingViewGroup" value="-" disabled>
                        </div>

                        <div class="form-group" id="noticeOutgoingViewDetailSection">
                            <label><b>เกษียณหนังสือ</b></label>
                            <textarea id="notice_memo_editor_view" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="content-file-sec" id="noticeOutgoingViewCoverSection" style="display: none;">
                            <p><strong>ไฟล์หนังสือนำ</strong></p>
                            <div class="file-section" id="noticeOutgoingViewCoverList"></div>
                        </div>

                        <div class="content-file-sec" id="noticeOutgoingViewAttachmentSection" style="display: none;">
                            <p><strong>ไฟล์เอกสารแนบเพิ่มเติม</strong></p>
                            <div class="file-section" id="noticeOutgoingViewAttachmentList"></div>
                        </div>

                        <div class="form-group sender-field">
                            <label><b>แนบลิงก์</b></label>
                            <input type="text" id="noticeOutgoingViewLink" value="-" disabled />
                        </div>

                        <div class="form-group sender-field">
                            <label><b>ผู้รับหนังสือ</b></label>
                            <input type="text" id="noticeOutgoingViewProposer" value="-" disabled>
                        </div>

                        <div class="form-group" id="noticeOutgoingDirectorCommentSection">
                            <label><b id="noticeOutgoingLatestCommentLabel">ความคิดเห็นของผู้ส่งล่าสุด</b></label>
                            <textarea id="noticeOutgoingDirectorComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="form-group" id="noticeOutgoingRegistryCommentSection" style="display: none;">
                            <label><b>ความคิดเห็นของเจ้าหน้าที่สารบรรณ</b></label>
                            <textarea id="noticeOutgoingRegistryComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="form-group" id="noticeOutgoingReviewCommentSection" style="display: none;">
                            <label><b id="noticeOutgoingReviewCommentLabel">ความคิดเห็นของผู้อำนวยการโรงเรียน</b></label>
                            <textarea id="noticeOutgoingReviewComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                        <div class="form-group" id="noticeOutgoingDeputyCommentSection" style="display: none;">
                            <label><b id="noticeOutgoingDeputyCommentLabel">ความคิดเห็นของรองผู้อำนวยการ</b></label>
                            <textarea id="noticeOutgoingDeputyComment" class="js-memo-editor" rows="5" data-editor-readonly disabled>-</textarea>
                        </div>

                    </form>
                </div>
            </div>

        </div>

        <?php if (false) : ?>
            <div class="modal-overlay-circular-notice-index outside-person" id="modalNoticeKeepOverlay">
                <div class="modal-content">
                    <div class="header-modal">
                        <div class="first-header">
                            <p>แสดงข้อความรายละเอียดหนังสือเวียน</p>
                        </div>
                        <div class="sec-header">
                            <div class="consider-status considering" id="modalConsiderStatus">กำลังเสนอ</div>
                            <i class="fa-solid fa-xmark" id="closeModalNoticeKeep"></i>
                        </div>
                    </div>

                    <div class="content-modal">
                        <form method="POST" enctype="multipart/form-data" data-validate class="container-circular-notice-sending" id="circularEditForm" style="box-shadow:none; padding: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="inbox_id" data-send-inbox-id value="">
                            <input type="hidden" name="circular_id" data-send-circular-id value="">
                            <input type="hidden" name="action" value="forward">
                            <input type="hidden" name="edit_circular_id" id="editTargetCircularId" value="">
                            <div class="type-urgent">
                                <p>ประเภท</p>
                                <div class="radio-group-urgent">
                                    <input type="radio" name="priority" value="normal" id="outgoingPriorityNormal" checked disabled>
                                    <label for="outgoingPriorityNormal">ปกติ</label>
                                    <input type="radio" name="priority" value="urgent" id="outgoingPriorityUrgent" disabled>
                                    <label for="outgoingPriorityUrgent">ด่วน</label>
                                    <input type="radio" name="priority" value="high" id="outgoingPriorityHigh" disabled>
                                    <label for="outgoingPriorityHigh">ด่วนมาก</label>
                                    <input type="radio" name="priority" value="highest" id="outgoingPriorityHighest" disabled>
                                    <label for="outgoingPriorityHighest">ด่วนที่สุด</label>
                                </div>
                            </div>

                            <div class="sender-row">
                                <div class="form-group sender-field">
                                    <label><b>เลขที่หนังสือ</b></label>
                                    <input type="text" value="ศธ 1045.2/2567" disabled>
                                </div>
                                <div class="form-group">
                                    <label><b>ลงวันที่</b></label>
                                    <input type="text" value="26 เมษายน 2567" disabled>
                                </div>
                            </div>

                            <div class="sender-row">
                                <div class="form-group">
                                    <label><b>เรื่อง</b></label>
                                    <input type="text" value="ขอเชิญร่วมประชุมคณะกรรมการบริหารสถานศึกษา ประจำเดือนพฤษภาคม" disabled>
                                </div>
                                <div class="form-group">
                                    <label><b>จาก</b></label>
                                    <input type="text" value="ขอเชิญร่วมประชุมคณะกรรมการบริหารสถานศึกษา ประจำเดือนพฤษภาคม" disabled>
                                </div>
                            </div>

                            <div class="form-group sender-field">
                                <label><b>ถึงกลุ่ม</b></label>
                                <input type="text" value="ศธ 1045.2/2567" disabled>
                            </div>

                            <div class="form-group">
                                <label><b>เกษียณหนังสือ</b></label>
                                <textarea rows="5" disabled>เรียน คณะกรรมการบริหารสถานศึกษาทุกท่าน ด้วยทางโรงเรียนจะจัดการประชุมเพื่อสรุปผลการดำเนินงานประจำเดือน และวางแผนกิจกรรมในเดือนถัดไป จึงขอเรียนเชิญทุกท่านเข้าร่วมประชุมตามวันและเวลาที่ระบุไว้ในเอกสารแนบ</textarea>
                            </div>

                            <div class="content-file-sec">
                                <p><strong>ไฟล์หนังสือนำ</strong></p>
                                <div class="file-section">
                                    <div class="file-banner">
                                        <div class="file-info">
                                            <div class="file-icon"><i class="fa-solid fa-file-pdf"></i></div>
                                            <div class="file-text">
                                                <div class="file-name">cover_letter_signed_01.pdf</div>
                                                <div class="file-type">application/pdf</div>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <a href="#" target="_blank"><i class="fa-solid fa-eye"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="content-file-sec">
                                <p><strong>ไฟล์เอกสารแนบเพิ่มเติม</strong></p>
                                <div class="file-section">
                                    <div class="file-banner">
                                        <div class="file-info">
                                            <div class="file-icon"><i class="fa-solid fa-file-pdf"></i></div>
                                            <div class="file-text">
                                                <div class="file-name">meeting_agenda_may.pdf</div>
                                                <div class="file-type">application/pdf</div>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <a href="#" target="_blank"><i class="fa-solid fa-eye"></i></a>
                                        </div>
                                    </div>
                                    <div class="file-banner">
                                        <div class="file-info">
                                            <div class="file-icon"><i class="fa-solid fa-image"></i></div>
                                            <div class="file-text">
                                                <div class="file-name">reference_schedule.png</div>
                                                <div class="file-type">image/png</div>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <a href="#" target="_blank"><i class="fa-solid fa-eye"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="sender-row margin">
                                <div class="form-group">
                                    <label><b>แนบลิ้งก์</b></label>
                                    <input type="text" value="https://drive.google.com/drive/folders/mock-folder-id" disabled />
                                </div>

                                <div class="form-group">
                                    <label><b>ผู้เสนอ</b></label>
                                    <input type="text" value="นางสาวทิพยรัตน์ บุญมณี" disabled>
                                </div>
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

                        </form>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="modal-overlay-circular-notice-index keep-sending" id="modalNoticeSendOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <p id=""><?= h($forward_is_reviewer_return ? 'พิจารณาหนังสือเวียน' : 'ส่งหนังสือเวียนต่อ') ?></p>
                <i class="fa-solid fa-xmark" id="closeModalNoticeSend"></i>
            </div>

            <div class="content-modal">
                <form method="POST" enctype="multipart/form-data" data-validate class="container-circular-notice-sending" id="circularForwardForm" data-registry-handoff="<?= h($forward_is_registry_handoff ? '1' : '0') ?>" style="box-shadow:none; padding: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="inbox_id" data-send-inbox-id value="">
                    <input type="hidden" name="circular_id" data-send-circular-id value="">
                    <input type="hidden" name="action" value="forward">
                    <input type="hidden" name="edit_circular_id" id="forwardTargetCircularId" value="">

                    <div class="type-urgent">
                        <p>ประเภท</p>
                        <div class="radio-group-urgent">
                            <input type="radio" name="priority" value="normal" data-forward-urgent="normal" id="outgoingPriorityNormal" checked disabled>
                            <label for="outgoingPriorityNormal">ปกติ</label>
                            <input type="radio" name="priority" value="urgent" data-forward-urgent="urgent" id="outgoingPriorityUrgent" disabled>
                            <label for="outgoingPriorityUrgent">ด่วน</label>
                            <input type="radio" name="priority" value="high" data-forward-urgent="high" id="outgoingPriorityHigh" disabled>
                            <label for="outgoingPriorityHigh">ด่วนมาก</label>
                            <input type="radio" name="priority" value="highest" data-forward-urgent="highest" id="outgoingPriorityHighest" disabled>
                            <label for="outgoingPriorityHighest">ด่วนที่สุด</label>
                        </div>
                    </div>

                    <div class="sender-row">
                        <div class="form-group sender-field">
                            <label><b>เลขที่หนังสือ</b></label>
                            <input type="text" id="forwardViewBookNo" value="-" disabled>
                        </div>
                        <div class="form-group">
                            <label><b>ลงวันที่</b></label>
                            <input type="text" id="forwardViewIssuedDate" value="-" disabled>
                        </div>
                    </div>

                    <div class="sender-row">
                        <div class="form-group">
                            <label><b>เรื่อง</b></label>
                            <input type="text" id="forwardViewSubject" value="-" disabled>
                        </div>
                        <div class="form-group">
                            <label><b>จาก</b></label>
                            <input type="text" id="forwardViewFrom" value="-" disabled>
                        </div>
                    </div>

                    <div class="form-group sender-field">
                        <label><b>ถึงกลุ่ม</b></label>
                        <?php if ($forward_is_reviewer_return) : ?>
                            <select name="extGroupFID" id="forwardViewGroupFid" class="native-forward-group-select">
                                <option value="">เลือกกลุ่ม/ฝ่าย</option>
                                <?php foreach ($factions as $faction) : ?>
                                    <?php
                                    $faction_id = (int) ($faction['fID'] ?? 0);
                                    $faction_name = trim((string) ($faction['fName'] ?? $faction['fname'] ?? ''));

                                    if ($faction_id <= 0 || $faction_name === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?= h((string) $faction_id) ?>"><?= h($faction_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <input type="text" id="forwardViewGroup" value="-" disabled>
                        <?php endif; ?>
                    </div>

                    <div class="form-group" id="forwardViewDetailSection">
                        <label><b>เกษียณหนังสือ</b></label>
                        <textarea rows="5" id="forwardViewDetail" class="js-memo-editor" data-editor-readonly disabled>-</textarea>
                    </div>

                    <div class="content-file-sec" id="forwardViewCoverSection">
                        <p><strong>ไฟล์หนังสือนำ</strong></p>
                        <div class="file-section" id="forwardViewCoverList"></div>
                    </div>

                    <div class="content-file-sec" id="forwardViewAttachmentSection">
                        <p><strong>ไฟล์เอกสารแนบเพิ่มเติม</strong></p>
                        <div class="file-section" id="forwardViewAttachmentList"></div>
                    </div>

                    <div class="form-group sender-field">
                        <label><b>แนบลิงก์</b></label>
                        <input type="text" id="forwardViewLink" value="-" disabled />
                    </div>

                    <div class="form-group sender-field">
                        <label><b>ผู้รับหนังสือ</b></label>
                        <input type="text" id="forwardViewProposer" value="-" disabled>
                    </div>

                    <?php if ($forward_is_reviewer_return || $forward_is_registry_handoff || $forward_show_deputy_distribute_controls) : ?>
                        <div class="enterprise-divider" id="forwardDirectorCommentDivider"></div>

                        <div class="form-group" id="forwardDirectorCommentSection">
                            <label><b id="forwardLatestCommentLabel"><?= h($forward_is_reviewer_return ? 'ความคิดเห็นของผู้อำนวยการโรงเรียน' : 'ความคิดเห็นของผู้ส่งล่าสุด') ?></b></label>
                            <textarea rows="5" id="forwardDirectorComment" <?= $forward_is_reviewer_return ? ' name="comment"' : ' data-editor-readonly disabled' ?> class="js-memo-editor"></textarea>
                        </div>

                        <?php if (!$forward_is_reviewer_return) : ?>
                            <div class="enterprise-divider" id="forwardRegistryCommentDivider" style="display: none;"></div>
                            <div class="form-group" id="forwardRegistryCommentSection" style="display: none;">
                                <label><b>ความคิดเห็นของเจ้าหน้าที่สารบรรณ</b></label>
                                <textarea rows="5" id="forwardRegistryComment" data-editor-readonly disabled class="js-memo-editor"></textarea>
                            </div>

                            <div class="enterprise-divider" id="forwardReviewCommentDivider" style="display: none;"></div>
                            <div class="form-group" id="forwardReviewCommentSection" style="display: none;">
                                <label><b id="forwardReviewCommentLabel">ความคิดเห็นของผู้อำนวยการโรงเรียน</b></label>
                                <textarea rows="5" id="forwardReviewComment" data-editor-readonly disabled class="js-memo-editor"></textarea>
                            </div>
                        <?php endif; ?>

                        <?php if ($forward_is_registry_handoff) : ?>
                            <div class="enterprise-divider"></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($forward_show_deputy_distribute_controls) : ?>
                        <div class="enterprise-divider" id="forwardDeputyCommentDivider" style="display: none;"></div>

                        <div class="form-group" id="forwardDeputyCommentSection" style="display: none;">
                            <label><b>ความคิดเห็นของรองผู้อำนวยการ</b></label>
                            <textarea rows="5" id="forwardDeputyComment" name="deputy_comment" class="js-memo-editor"></textarea>
                        </div>

                        <div class="form-group sender-field" id="forwardAnnouncementSection" style="display: none;">
                            <label class="select-all-box" for="forwardPublishAnnouncement">
                                <input type="checkbox" id="forwardPublishAnnouncement" name="publish_announcement" value="1">
                                ส่งเป็นข่าวประชาสัมพันธ์
                            </label>
                        </div>
                    <?php endif; ?>

                    <?php if ($forward_show_recipient_controls) : ?>
                        <div class="form-group receive" data-recipients-section>
                            <label><b>ส่งถึง :</b></label>
                            <div class="dropdown-container">
                                <div class="search-input-wrapper" id="forward_recipientToggle">
                                    <input type="text" id="forward_mainInput" class="search-input" placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="dropdown-content" id="forward_dropdownContent">
                                    <div class="dropdown-header">
                                        <label class="select-all-box" for="forward_selectAll">
                                            <input type="checkbox" id="forward_selectAll">เลือกทั้งหมด
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
                                                        $faction_name = trim((string) ($faction['fName'] ?? $faction['fname'] ?? ''));

                                                        if ($faction_name === '') continue;

                                                        $members = $faction_members[$fid] ?? [];
                                                        $member_payload = [];

                                                        foreach ($members as $member) {
                                                            $member_payload[] = [
                                                                'pID' => (string) ($member['pID'] ?? ''),
                                                                'name' => (string) ($member['name'] ?? ''),
                                                                'faction' => $faction_name,
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
                                                        $forward_group_key = 'forward-faction-' . $fid_value;
                                                        ?>
                                                        <div class="item item-group<?= $expanded_by_default ? '' : ' is-collapsed' ?>" data-faction-id="<?= h($fid_value) ?>">
                                                            <div class="group-header">
                                                                <label class="item-main" for="forward_group_faction_<?= h($fid_value) ?>">
                                                                    <input type="checkbox" id="forward_group_faction_<?= h($fid_value) ?>" class="item-checkbox group-item-checkbox faction-item-checkbox" data-group="faction"
                                                                        data-group-key="<?= h($forward_group_key) ?>"
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
                                                                            <label class="item member-item" for="forward_member_faction_<?= h($fid_value) ?>_<?= h($member_pid) ?>">
                                                                                <input type="checkbox" id="forward_member_faction_<?= h($fid_value) ?>_<?= h($member_pid) ?>" class="member-checkbox"
                                                                                    data-member-group-key="<?= h($forward_group_key) ?>"
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
                                                            ];
                                                        }

                                                        if (empty($member_payload)) continue;

                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
                                                        $member_total = count($member_payload);

                                                        $group_key = 'department-' . $did;
                                                        // สร้าง key พิเศษสำหรับ edit
                                                        $forward_group_key = 'forward-department-' . $did;
                                                        ?>
                                                        <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main" for="forward_group_dept_<?= h($did) ?>">
                                                                    <input type="checkbox" id="forward_group_dept_<?= h($did) ?>" class="item-checkbox group-item-checkbox department-item-checkbox" data-group="department"
                                                                        data-group-key="<?= h($forward_group_key) ?>"
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
                                                                        <label class="item member-item" for="forward_member_dept_<?= h($did) ?>_<?= h((string) ($member['pID'] ?? '')) ?>">
                                                                            <input type="checkbox" id="forward_member_dept_<?= h($did) ?>_<?= h((string) ($member['pID'] ?? '')) ?>" class="member-checkbox"
                                                                                data-member-group-key="<?= h($forward_group_key) ?>"
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

                                        <?php if (!empty($role_groups)) : ?>
                                            <div class="category-group">
                                                <div class="category-title">
                                                    <span>บทบาท</span>
                                                </div>
                                                <div class="category-items">
                                                    <?php foreach ($role_groups as $role_group) : ?>
                                                        <?php
                                                        $role_id = (int) ($role_group['roleID'] ?? 0);
                                                        $role_name = trim((string) ($role_group['name'] ?? ''));
                                                        $members = (array) ($role_group['members'] ?? []);

                                                        if ($role_id <= 0 || $role_name === '' || empty($members)) continue;

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
                                                                'faction' => $role_name,
                                                            ];
                                                        }

                                                        if (empty($member_payload)) continue;

                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
                                                        $member_total = count($member_payload);
                                                        $role_value = (string) $role_id;
                                                        $forward_group_key = 'forward-role-' . $role_value;
                                                        $expanded_by_default = $is_selected($role_value, $selected_roles) || $has_selected_member;
                                                        ?>
                                                        <div class="item item-group<?= $expanded_by_default ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main" for="forward_group_role_<?= h($role_value) ?>">
                                                                    <input type="checkbox" id="forward_group_role_<?= h($role_value) ?>" class="item-checkbox group-item-checkbox role-item-checkbox" data-group="role"
                                                                        data-group-key="<?= h($forward_group_key) ?>"
                                                                        data-group-label="<?= h($role_name) ?>"
                                                                        data-members="<?= h($member_payload_json) ?>"
                                                                        name="role_ids[]" value="<?= h($role_value) ?>" <?= h($is_selected($role_value, $selected_roles) ? 'checked' : '') ?>>
                                                                    <span class="item-title"><?= h($role_name) ?></span>
                                                                    <small class="item-subtext">สมาชิกทั้งหมด <?= h((string) $member_total) ?> คน</small>
                                                                </label>
                                                                <button type="button" class="group-toggle" aria-expanded="<?= $expanded_by_default ? 'true' : 'false' ?>" title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                                    <i class="fa-solid fa-chevron-down"></i>
                                                                </button>
                                                            </div>

                                                            <ol class="member-sublist">
                                                                <?php foreach ($member_payload as $member) : ?>
                                                                    <li>
                                                                        <label class="item member-item" for="forward_member_role_<?= h($role_value) ?>_<?= h((string) ($member['pID'] ?? '')) ?>">
                                                                            <input type="checkbox" id="forward_member_role_<?= h($role_value) ?>_<?= h((string) ($member['pID'] ?? '')) ?>" class="member-checkbox"
                                                                                data-member-group-key="<?= h($forward_group_key) ?>"
                                                                                data-member-name="<?= h((string) ($member['name'] ?? '')) ?>"
                                                                                data-group-label="<?= h($role_name) ?>"
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
                                                    <span><?= h($forward_restrict_to_deputies ? 'รองผู้อำนวยการ' : 'อื่นๆ') ?></span>
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
                                                            ];
                                                        }

                                                        if (empty($member_payload)) continue;

                                                        $member_payload_json = json_encode($member_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
                                                        $member_total = count($member_payload);

                                                        // สร้าง key พิเศษสำหรับ edit
                                                        $forward_group_key = 'forward-special-' . $group_key;
                                                        ?>
                                                        <div class="item item-group<?= $has_selected_member ? '' : ' is-collapsed' ?>">
                                                            <div class="group-header">
                                                                <label class="item-main" for="forward_group_special_<?= h($group_key) ?>">
                                                                    <input type="checkbox" id="forward_group_special_<?= h($group_key) ?>" class="item-checkbox group-item-checkbox" data-group="special"
                                                                        data-group-key="<?= h($forward_group_key) ?>"
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
                                                                        <label class="item member-item" for="forward_member_special_<?= h($group_key) ?>_<?= h((string) ($member['pID'] ?? '')) ?>">
                                                                            <input type="checkbox" id="forward_member_special_<?= h($group_key) ?>_<?= h((string) ($member['pID'] ?? '')) ?>" class="member-checkbox"
                                                                                data-member-group-key="<?= h($forward_group_key) ?>"
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
                                <button id="forward_btnShowRecipients" type="button">
                                    <p>แสดงผู้รับทั้งหมด</p>
                                </button>
                            </div>
                        </div>

                        <div class="content-read-sec" id="forwardReceiptStatusSection">
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
                                    <tbody id="forwardReceiptStatusTableBody">
                                        <tr>
                                            <td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div id="forward_confirmModal" class="modal-overlay-confirm">
                        <div class="confirm-box">
                            <div class="confirm-header">
                                <div class="icon-circle"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            </div>
                            <div class="confirm-body">
                                <h3><?= h($forward_is_reviewer_return ? 'ยืนยันการพิจารณา' : 'ยืนยันการส่งหนังสือต่อ') ?></h3>
                                <div class="confirm-actions">
                                    <button id="forward_btnConfirmYes" class="btn-yes" type="button">ยืนยัน</button>
                                    <button id="forward_btnConfirmNo" class="btn-no" type="button">ยกเลิก</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($forward_show_recipient_controls) : ?>
                        <div id="forward_recipientModal" class="modal-overlay-recipient">
                            <div class="modal-container">
                                <div class="modal-header">
                                    <div class="modal-title">
                                        <i class="fa-solid fa-users"></i><span>รายชื่อผู้รับหนังสือเวียน</span>
                                    </div>
                                    <button class="modal-close" id="forward_closeModalBtn" type="button"><i class="fa-solid fa-xmark"></i></button>
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
                                        <tbody id="forward_recipientTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>

            </div>

            <div class="footer-modal">
                <form id="modalSendForwardForm">
                    <button type="button" id="forward_btnSendNotice" form="circularForwardForm" data-confirm-title="<?= h($forward_is_reviewer_return ? 'ยืนยันการพิจารณา' : 'ยืนยันการส่งหนังสือต่อ') ?>">
                        <p><?= h($forward_is_reviewer_return ? 'พิจารณา' : 'ส่งหนังสือต่อ') ?></p>
                    </button>
                </form>
            </div>
        </div>
    </div>

</section>

<?php if (!$is_outside_view && !$archived) : ?>
    <div class="button-circular-notice-index">
        <button
            class="button-keep"
            type="submit"
            form="bulkActionForm"
            data-confirm="ต้องการจัดเก็บหนังสือเวียนที่เลือกหรือไม่"
            data-confirm-title="ยืนยันการจัดเก็บ"
            data-confirm-ok="ยืนยัน"
            data-confirm-cancel="ยกเลิก">
            <i class="fa-solid fa-file-import"></i>
            <p>จัดเก็บ</p>
        </button>
    </div>
<?php else : ?>
    <div class="button-circular-notice-index"></div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>

<script>
    tinymce.init({
        selector: ".js-memo-editor",
        height: 500,
        menubar: false,
        language: "th_TH",
        plugins: "searchreplace autolink directionality visualblocks visualchars image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists wordcount help charmap emoticons",
        toolbar: "undo redo | fontfamily | fontsize | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | outdent indent |  numlist bullist | forecolor backcolor removeformat | pagebreak | charmap emoticons",
        font_family_formats: "TH Sarabun New=Sarabun, sans-serif;",
        font_size_formats: "8pt 9pt 10pt 12pt 14pt 16pt 18pt 20pt 22pt 24pt 26pt 36pt 48pt 72pt",
        content_style: `
            @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap');
            body { font-family: 'Sarabun', sans-serif; font-size: 16pt; line-height: 1.5; color: #000; background-color: #fff; padding: 0 20px; margin: 0 auto; }
            p { margin-bottom: 0px; }
        `,
        nonbreaking_force_tab: true,
        promotion: false,
        branding: false,
        setup: (editor) => {
            editor.on('init', () => {
                if (editor.targetElm?.hasAttribute('data-editor-readonly')) {
                    editor.mode.set('readonly');
                }
            });
        },
    });
    
    window.addEventListener('load', function() {
      var iframe = document.getElementById('noticeOutgoingDirectorComment_ifr'); 
    
    if (iframe) {
        var doc = iframe.contentDocument || iframe.contentWindow.document;
        var style = doc.createElement('style');
      
        style.innerHTML = 'body#tinymce p { font-size: clamp(8px, 1.5vw, 18px) !important; }';
      
        doc.head.appendChild(style);
      }
    });

    document.addEventListener("DOMContentLoaded", function() {
        const forwardOpenInboxId = <?= json_encode($forward_open_inbox_id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function setupCircularForm(prefix, formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const fileInput = document.getElementById(prefix + 'fileInput');
            const fileList = document.getElementById(prefix + 'fileListContainer');
            const dropzone = document.getElementById(prefix + 'dropzone');
            const addFilesBtn = document.getElementById(prefix + 'btnAddFiles');
            const previewModal = document.getElementById('imagePreviewModal');
            const previewImage = document.getElementById('previewImage');
            const previewCaption = document.getElementById('previewCaption');
            const closePreviewBtn = document.getElementById('closePreviewBtn');
            const isRegistryHandoff = () => String(form.dataset.registryHandoff || '').trim() === '1';

            const maxFiles = 5;
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            let selectedFiles = [];

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
                    icon.innerHTML = file.type === 'application/pdf' ? '<i class="fa-solid fa-file-pdf"></i>' : '<i class="fa-solid fa-image"></i>';

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
                const existing = new Set(selectedFiles.map((f) => `${f.name}-${f.size}-${f.lastModified}`));
                Array.from(files).forEach((file) => {
                    const key = `${file.name}-${file.size}-${file.lastModified}`;
                    if (!existing.has(key) && allowedTypes.includes(file.type) && selectedFiles.length < maxFiles) {
                        selectedFiles.push(file);
                        existing.add(key);
                    }
                });
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
            closePreviewBtn?.addEventListener('click', () => previewModal?.classList.remove('active'));
            previewModal?.addEventListener('click', (e) => {
                if (e.target === previewModal) previewModal.classList.remove('active');
            });

            const dropdown = document.getElementById(prefix + 'dropdownContent');
            const toggle = document.getElementById(prefix + 'recipientToggle');
            const searchInput = document.getElementById(prefix + 'mainInput');
            const selectAll = document.getElementById(prefix + 'selectAll');
            const receiptStatusSection = form.querySelector('#sendReceiptStatusSection, #forwardReceiptStatusSection');
            const receiptStatusTableBody = form.querySelector('#sendReceiptStatusTableBody, #forwardReceiptStatusTableBody');

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
                if (toggleBtn) toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
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
                    const availableMemberRows = memberRows.filter((row) => {
                        const memberCheckbox = row.querySelector('.member-checkbox');
                        return !!memberCheckbox && !memberCheckbox.disabled;
                    });
                    const isGroupMatch = query !== '' && titleText.includes(query);

                    if (query === '') {
                        groupItem.style.display = availableMemberRows.length > 0 ? '' : 'none';
                        memberRows.forEach((row) => {
                            const memberCheckbox = row.querySelector('.member-checkbox');
                            row.style.display = memberCheckbox && !memberCheckbox.disabled ? '' : 'none';
                        });
                        return;
                    }

                    let hasMemberMatch = false;
                    memberRows.forEach((row) => {
                        const memberCheckbox = row.querySelector('.member-checkbox');
                        if (!memberCheckbox || memberCheckbox.disabled) {
                            row.style.display = 'none';
                            return;
                        }
                        const memberPid = String(memberCheckbox?.value || '').trim();
                        const isRemoteMatched = remoteMatchedPids instanceof Set ? remoteMatchedPids.has(memberPid) : null;
                        const rowText = normalizeSearchText(row.textContent || '');
                        const matchedByText = rowText.includes(query);
                        const matched = isGroupMatch || matchedByText || isRemoteMatched === true;
                        row.style.display = matched ? '' : 'none';
                        if (matched) hasMemberMatch = true;
                    });

                    const isVisible = availableMemberRows.length > 0 && (isGroupMatch || hasMemberMatch);
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
                const excludePid = String(form.dataset.excludePid || '').trim();
                const url = `${recipientSearchEndpoint}?q=${encodeURIComponent(query)}${excludePid !== '' ? `&exclude_pid=${encodeURIComponent(excludePid)}` : ''}`;
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
                const allChecks = [...groupChecks, ...memberChecks].filter((el) => !el.disabled);
                const checked = allChecks.filter((el) => el.checked).length;
                selectAll.checked = allChecks.length > 0 && checked === allChecks.length;
                selectAll.indeterminate = checked > 0 && checked < allChecks.length;

                groupChecks.forEach((groupCheck) => {
                    const groupKey = groupCheck.getAttribute('data-group-key') || '';
                    const members = getMemberChecksByGroupKey(groupKey).filter((el) => !el.disabled);
                    if (members.length === 0) {
                        groupCheck.checked = false;
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

            const getCurrentReadStats = () => {
                try {
                    const parsed = JSON.parse(String(form.dataset.readStats || '[]'));
                    return Array.isArray(parsed) ? parsed : [];
                } catch (error) {
                    return [];
                }
            };

            const renderSelectedRecipientStatuses = () => {
                if (!receiptStatusSection || !receiptStatusTableBody) {
                    return;
                }

                const checkedMembers = memberChecks.filter((item) => item.checked && !item.disabled);
                const readStatsMap = new Map();
                const currentReadStats = getCurrentReadStats();

                currentReadStats.forEach((item) => {
                    const pid = String(item?.pID || '').trim();
                    if (pid !== '') {
                        readStatsMap.set(pid, item);
                    }
                });

                const recipientsMap = new Map();
                let recipientOrder = 0;
                const addRecipient = (pid, name, faction, stat = null) => {
                    const key = String(pid || '').trim();
                    if (key === '') return;

                    if (recipientsMap.has(key)) {
                        const existing = recipientsMap.get(key);
                        if (stat && !existing.stat) {
                            existing.stat = stat;
                        }
                        return;
                    }

                    recipientsMap.set(key, {
                        pid: key,
                        name: String(name || '-').trim() || '-',
                        faction: String(faction || '-').trim() || '-',
                        stat,
                        order: recipientOrder++,
                    });
                };

                checkedMembers.forEach((item) => {
                    const pid = String(item.value || '').trim();
                    addRecipient(pid, item.getAttribute('data-member-name'), item.getAttribute('data-group-label'), readStatsMap.get(pid) || null);
                });

                if (recipientsMap.size === 0) {
                    receiptStatusSection.style.display = 'none';
                    receiptStatusTableBody.innerHTML = '<tr><td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td></tr>';
                    return;
                }

                const recipients = Array.from(recipientsMap.values()).sort((a, b) => a.order - b.order);

                receiptStatusSection.style.display = '';
                receiptStatusTableBody.innerHTML = '';

                recipients.forEach((recipient) => {
                    const stat = recipient.stat || readStatsMap.get(recipient.pid) || null;
                    const isRead = Number(stat?.isRead || 0) === 1;

                    const row = document.createElement('tr');

                    const nameCell = document.createElement('td');
                    nameCell.textContent = recipient.name;

                    const statusCell = document.createElement('td');
                    const statusPill = document.createElement('span');
                    statusPill.className = `status-pill ${isRead ? 'approved' : 'pending'}`;
                    statusPill.textContent = isRead ? 'อ่านแล้ว' : 'ยังไม่อ่าน';
                    statusCell.appendChild(statusPill);

                    const readAtCell = document.createElement('td');
                    readAtCell.textContent = String(stat?.readAtDisplay || '-').trim() || '-';

                    row.appendChild(nameCell);
                    row.appendChild(statusCell);
                    row.appendChild(readAtCell);
                    receiptStatusTableBody.appendChild(row);
                });
            };

            selectAll?.addEventListener('change', () => {
                const checked = selectAll.checked;
                [...groupChecks, ...memberChecks].forEach((el) => {
                    if (!el.disabled) el.checked = checked;
                });
                updateSelectAllState();
                renderSelectedRecipientStatuses();
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
                    renderSelectedRecipientStatuses();
                });
            });

            memberChecks.forEach((item) => {
                item.addEventListener('change', () => {
                    syncMemberByPid(item.value || '', item.checked, item);
                    updateSelectAllState();
                    renderSelectedRecipientStatuses();
                });
            });

            groupChecks.forEach((item) => {
                if (!item.checked) return;
                const groupKey = item.getAttribute('data-group-key') || '';
                const members = getMemberChecksByGroupKey(groupKey);
                members.forEach((member) => {
                    if (member.disabled) return;
                    member.checked = true;
                    syncMemberByPid(member.value || '', true, member);
                });
            });
            updateSelectAllState();
            renderSelectedRecipientStatuses();

            const clearRecipientSelection = () => {
                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }

                [...groupChecks, ...memberChecks].forEach((item) => {
                    item.checked = false;
                    item.indeterminate = false;
                });

                updateSelectAllState();
                renderSelectedRecipientStatuses();
            };

            form.resetCircularFormState = () => {
                selectedFiles = [];
                syncFiles();
                renderFiles();
                form.reset();
                delete form.dataset.excludePid;
                const sourceFileSection = form.querySelector('#sendModalFileSection, #forwardModalFileSection');
                if (sourceFileSection) sourceFileSection.innerHTML = '';
                const sendReceiptStatusSection = form.querySelector('#sendReceiptStatusSection, #forwardReceiptStatusSection');
                const sendReceiptStatusTableBody = form.querySelector('#sendReceiptStatusTableBody, #forwardReceiptStatusTableBody');
                if (sendReceiptStatusSection) sendReceiptStatusSection.style.display = 'none';
                if (sendReceiptStatusTableBody) {
                    sendReceiptStatusTableBody.innerHTML = '<tr><td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td></tr>';
                }
                delete form.dataset.readStats;
                if (form.id === 'circularForwardForm') {
                    form.dataset.deputyDistributeAction = '0';
                }
                if (searchInput) searchInput.value = '';
                [forwardDirectorCommentDivider, forwardDirectorCommentSection, forwardDeputyCommentDivider, forwardDeputyCommentSection, forwardAnnouncementSection].forEach((element) => {
                    if (element) {
                        element.style.display = 'none';
                    }
                });
                setForwardDirectorCommentContent('');
                setForwardDeputyCommentContent('');
                if (forwardPublishAnnouncement) {
                    forwardPublishAnnouncement.checked = false;
                    forwardPublishAnnouncement.disabled = true;
                }
                if (typeof updateForwardActionButtonState === 'function') {
                    updateForwardActionButtonState();
                }
                recipientSearchRequestNo++;
                filterRecipientDropdown('');
                clearRecipientSelection();
                setDropdownVisible(false);
            };

            form.applyRecipientOwnerFilter = (ownerPid) => {
                const excludedPid = String(ownerPid || '').trim();
                if (excludedPid !== '') {
                    form.dataset.excludePid = excludedPid;
                } else {
                    delete form.dataset.excludePid;
                }

                memberChecks.forEach((item) => {
                    const isExcluded = excludedPid !== '' && String(item.value || '').trim() === excludedPid;
                    const row = item.closest('li');

                    item.checked = isExcluded ? false : item.checked;
                    item.disabled = isExcluded;
                    item.dataset.ownerExcluded = isExcluded ? '1' : '0';

                    if (row) {
                        row.style.display = isExcluded ? 'none' : '';
                    }
                });

                groupChecks.forEach((groupCheck) => {
                    const groupKey = groupCheck.getAttribute('data-group-key') || '';
                    const members = getMemberChecksByGroupKey(groupKey).filter((el) => !el.disabled);
                    const groupItem = groupCheck.closest('.item-group');

                    groupCheck.checked = false;
                    groupCheck.indeterminate = false;
                    groupCheck.disabled = members.length === 0;

                    if (groupItem) {
                        groupItem.style.display = members.length === 0 ? 'none' : '';
                    }
                });

                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }

                if (searchInput) {
                    searchInput.value = '';
                }

                recipientSearchRequestNo++;
                filterRecipientDropdown('');
                updateSelectAllState();
                renderSelectedRecipientStatuses();
            };

            form.applyRecipientSelection = (selectedPids) => {
                const selectedSet = new Set(
                    Array.isArray(selectedPids) ?
                    selectedPids
                    .map((value) => String(value || '').trim())
                    .filter((value) => value !== '') : []
                );

                memberChecks.forEach((item) => {
                    if (item.disabled) {
                        item.checked = false;
                        return;
                    }

                    item.checked = selectedSet.has(String(item.value || '').trim());
                });

                updateSelectAllState();
                renderSelectedRecipientStatuses();
            };

            form.clearRecipientSelection = clearRecipientSelection;

            form.setReadStats = (rawStats) => {
                form.dataset.readStats = String(rawStats || '[]');
                renderSelectedRecipientStatuses();
            };

            const requestFormSubmit = () => {
                if (!form) return;

                if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                    window.tinymce.triggerSave();
                }

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.submit();
            };

            const btnSend = document.getElementById(prefix + 'btnSendNotice');
            const confirmModal = document.getElementById(prefix + 'confirmModal');
            const confirmYes = document.getElementById(prefix + 'btnConfirmYes');
            const confirmNo = document.getElementById(prefix + 'btnConfirmNo');
            btnSend?.addEventListener('click', (e) => {
                e.preventDefault();
                const confirmTitle = String(btnSend?.getAttribute('data-confirm-title') || '').trim() || 'ยืนยันการทำรายการ';

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

                const confirmHeading = confirmModal?.querySelector('h3');
                if (confirmHeading) confirmHeading.textContent = confirmTitle;
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
                const checkedMembers = memberChecks.filter((item) => item.checked && !item.disabled);
                if (checkedMembers.length === 0) {
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
            closeRecipients?.addEventListener('click', () => recipientModal?.classList.remove('active'));
            recipientModal?.addEventListener('click', (e) => {
                if (e.target === recipientModal) recipientModal.classList.remove('active');
            });
        }

        setupCircularForm('', 'circularComposeForm');
        setupCircularForm('edit_', 'circularEditForm');

        const editModal = document.getElementById('modalEditOverlay');
        const closeEditModalBtn = document.getElementById('closeModalEdit');
        const editTargetInput = document.getElementById('editTargetCircularId');
        const openEditBtns = document.querySelectorAll('.js-open-edit-modal');

        openEditBtns.forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                const circularId = String(btn.getAttribute('data-circular-id') || '').trim();
                if (editTargetInput) editTargetInput.value = circularId;

                const subjectInput = document.getElementById('edit_subject');
                const detailInput = document.getElementById('edit_detail');
                if (subjectInput) subjectInput.value = String(btn.getAttribute('data-subject') || '').trim();
                if (detailInput) detailInput.value = String(btn.getAttribute('data-detail') || '').trim();

                if (editModal) editModal.style.display = 'flex';
            });
        });

        closeEditModalBtn?.addEventListener('click', () => {
            if (editModal) editModal.style.display = 'none';
        });
        editModal?.addEventListener('click', (event) => {
            if (event.target === editModal) editModal.style.display = 'none';
        });

        const detailModalKeep = document.getElementById('modalNoticeKeepOverlay');
        const detailModalExt = document.getElementById('modalNoticeExtOverlay');
        const closeDetailKeepBtn = document.getElementById('closeModalNoticeKeep');
        const closeDetailExtBtn = document.getElementById('closeModalNoticeExt');
        const noticeDetailUsesOutgoingLayout = Boolean(document.getElementById('noticeOutgoingViewBookNo'));
        const noticeDetailStatus = document.getElementById('modalConsiderStatus');
        const noticeDetailBookNo = document.getElementById('noticeOutgoingViewBookNo');
        const noticeDetailIssuedDate = document.getElementById('noticeOutgoingViewIssuedDate');
        const noticeDetailSubject = document.getElementById('noticeOutgoingViewSubjectText');
        const noticeDetailFrom = document.getElementById('noticeOutgoingViewFrom');
        const noticeDetailGroup = document.getElementById('noticeOutgoingViewGroup');
        const noticeDetailLink = document.getElementById('noticeOutgoingViewLink');
        const noticeDetailProposer = document.getElementById('noticeOutgoingViewProposer');
        const noticeDetailMemoSection = document.getElementById('noticeOutgoingViewDetailSection');
        const noticeDetailCoverSection = document.getElementById('noticeOutgoingViewCoverSection');
        const noticeDetailCoverList = document.getElementById('noticeOutgoingViewCoverList');
        const noticeDetailAttachmentSection = document.getElementById('noticeOutgoingViewAttachmentSection');
        const noticeDetailAttachmentList = document.getElementById('noticeOutgoingViewAttachmentList');
        const noticeDetailDirectorComment = document.getElementById('noticeOutgoingDirectorComment');
        const noticeDetailLatestCommentSection = document.getElementById('noticeOutgoingDirectorCommentSection');
        const noticeDetailLatestCommentLabel = document.getElementById('noticeOutgoingLatestCommentLabel');
        const noticeDetailRegistryComment = document.getElementById('noticeOutgoingRegistryComment');
        const noticeDetailRegistryCommentSection = document.getElementById('noticeOutgoingRegistryCommentSection');
        const noticeDetailReviewComment = document.getElementById('noticeOutgoingReviewComment');
        const noticeDetailReviewCommentSection = document.getElementById('noticeOutgoingReviewCommentSection');
        const noticeDetailReviewCommentLabel = document.getElementById('noticeOutgoingReviewCommentLabel');
        const noticeDetailDeputyComment = document.getElementById('noticeOutgoingDeputyComment');
        const noticeDetailDeputyCommentSection = document.getElementById('noticeOutgoingDeputyCommentSection');
        const noticeDetailDeputyCommentLabel = document.getElementById('noticeOutgoingDeputyCommentLabel');
        const noticeDetailReceiptStatusSection = document.getElementById('noticeDetailReceiptStatusSection');
        const noticeDetailReceiptStatusTableBody = document.getElementById('noticeDetailReceiptStatusTableBody');
        const noticeDetailUrgentRadios = detailModalKeep ? Array.from(detailModalKeep.querySelectorAll('[data-notice-view-urgent]')) : [];

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const parseJsonList = (raw) => {
            try {
                const parsed = JSON.parse(String(raw || '[]'));
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
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

        const isCoverFile = (file) => {
            const note = String(file?.fileNote || file?.note || file?.field || '').trim().toLowerCase();
            return ['cover_file', 'cover_attachments', 'cover', 'lead_file', 'หนังสือนำ'].includes(note);
        };

        const splitNoticeFiles = (files) => {
            const normalizedFiles = Array.isArray(files) ? files : [];
            const coverFiles = normalizedFiles.filter((file) => isCoverFile(file));
            const attachmentFiles = normalizedFiles.filter((file) => !isCoverFile(file));

            if (coverFiles.length === 0 && normalizedFiles.length > 0) {
                return {
                    coverFiles: [normalizedFiles[0]],
                    attachmentFiles: normalizedFiles.slice(1),
                };
            }

            return {
                coverFiles,
                attachmentFiles,
            };
        };

        const normalizeNoticeUrgency = (value) => {
            const raw = String(value || '').trim().toLowerCase();
            if (['urgent', 'high', 'highest'].includes(raw)) {
                return raw;
            }
            return 'normal';
        };

        const setNoticeInput = (input, value) => {
            if (!input) {
                return;
            }

            const displayValue = String(value || '').trim() || '-';
            input.value = displayValue;
            input.setAttribute('title', displayValue);
        };

        const setNoticePriorityRadios = (radios, key) => {
            const normalizedKey = normalizeNoticeUrgency(key);
            let matched = false;

            radios.forEach((radio) => {
                const dataKey = radio.dataset.noticeViewUrgent || radio.dataset.forwardUrgent || '';
                const isMatched = String(dataKey).trim().toLowerCase() === normalizedKey;
                radio.checked = isMatched;
                matched = matched || isMatched;
            });

            if (!matched && radios[0]) {
                radios[0].checked = true;
            }
        };

        const setNoticePriorityRadio = (key) => {
            setNoticePriorityRadios(noticeDetailUrgentRadios, key);
        };

        const setNoticeDetailEditorContent = (html) => {
            const normalizedHtml = String(html || '').trim() || '<p>-</p>';
            const editor = window.tinymce ? window.tinymce.get('notice_memo_editor_view') : null;

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            const textarea = document.getElementById('notice_memo_editor_view');
            if (textarea) {
                textarea.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get('notice_memo_editor_view') : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const setNoticeReadonlyEditorContent = (editorId, textarea, html) => {
            const normalizedHtml = String(html || '').trim() || '<p>-</p>';
            const editor = window.tinymce ? window.tinymce.get(editorId) : null;

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            if (textarea) {
                textarea.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get(editorId) : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const setNoticeDirectorCommentContent = (html) => {
            setNoticeReadonlyEditorContent('noticeOutgoingDirectorComment', noticeDetailDirectorComment, html);
        };

        const setNoticeRegistryCommentContent = (html) => {
            setNoticeReadonlyEditorContent('noticeOutgoingRegistryComment', noticeDetailRegistryComment, html);
        };

        const setNoticeReviewCommentContent = (html) => {
            setNoticeReadonlyEditorContent('noticeOutgoingReviewComment', noticeDetailReviewComment, html);
        };

        const setNoticeDeputyCommentContent = (html) => {
            setNoticeReadonlyEditorContent('noticeOutgoingDeputyComment', noticeDetailDeputyComment, html);
        };

        const setForwardViewEditorContent = (html) => {
            const normalizedHtml = String(html || '').trim() || '<p>-</p>';
            const editor = window.tinymce ? window.tinymce.get('forwardViewDetail') : null;

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            if (forwardViewDetail) {
                forwardViewDetail.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get('forwardViewDetail') : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const setForwardDirectorCommentContent = (html, fallback = '') => {
            let normalizedHtml = String(html || '').trim();
            const fallbackHtml = String(fallback || '').trim();
            if (normalizedHtml === '' && fallbackHtml !== '') {
                normalizedHtml = fallbackHtml;
            }
            const editor = window.tinymce ? window.tinymce.get('forwardDirectorComment') : null;

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            if (forwardDirectorComment) {
                forwardDirectorComment.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get('forwardDirectorComment') : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const setForwardReadonlyCommentContent = (editorId, textarea, html) => {
            const normalizedHtml = String(html || '').trim();
            const editor = window.tinymce ? window.tinymce.get(editorId) : null;

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            if (textarea) {
                textarea.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get(editorId) : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const setForwardRegistryCommentContent = (html) => {
            setForwardReadonlyCommentContent('forwardRegistryComment', forwardRegistryComment, html);
        };

        const setForwardReviewCommentContent = (html) => {
            setForwardReadonlyCommentContent('forwardReviewComment', forwardReviewComment, html);
        };

        const setForwardDeputyCommentContent = (html) => {
            const normalizedHtml = String(html || '').trim();
            const editor = window.tinymce ? window.tinymce.get('forwardDeputyComment') : null;

            if (editor) {
                editor.setContent(normalizedHtml);
                return;
            }

            if (forwardDeputyComment) {
                forwardDeputyComment.value = normalizedHtml;
            }

            window.setTimeout(() => {
                const delayedEditor = window.tinymce ? window.tinymce.get('forwardDeputyComment') : null;
                if (delayedEditor) {
                    delayedEditor.setContent(normalizedHtml);
                }
            }, 50);
        };

        const setForwardGroupSelection = (value, fallbackLabel) => {
            const groupWrapper = document.getElementById('forwardViewGroupSelectWrapper');
            const groupInput = document.getElementById('forwardViewGroupFid');
            const groupLabel = document.getElementById('forwardViewGroupSelectLabel');

            if (!groupInput) {
                return;
            }

            let normalizedValue = String(value || '').trim();
            const normalizedFallback = String(fallbackLabel || '').trim().replace(/\s+/g, ' ');
            const isNativeSelect = groupInput.tagName === 'SELECT';

            if (isNativeSelect) {
                const selectOptions = Array.from(groupInput.options || []);

                if (normalizedValue === '' && normalizedFallback !== '' && normalizedFallback !== '-') {
                    const matchedOption = selectOptions.find((option) => {
                        const optionLabel = String(option.textContent || '').trim().replace(/\s+/g, ' ');
                        return optionLabel === normalizedFallback;
                    }) || null;
                    normalizedValue = matchedOption ? String(matchedOption.value || '').trim() : '';
                }

                groupInput.value = normalizedValue;
                groupInput.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
                return;
            }

            if (!groupWrapper || !groupLabel) {
                return;
            }

            const options = Array.from(groupWrapper.querySelectorAll('.custom-option'));
            let selectedOption = null;

            if (normalizedValue === '' && normalizedFallback !== '' && normalizedFallback !== '-') {
                selectedOption = options.find((option) => {
                    const optionLabel = String(option.getAttribute('data-label') || option.textContent || '').trim().replace(/\s+/g, ' ');
                    return optionLabel === normalizedFallback;
                }) || null;
                normalizedValue = selectedOption ? String(selectedOption.getAttribute('data-value') || '').trim() : '';
            }

            options.forEach((option) => {
                const isSelected = option.getAttribute('data-value') === normalizedValue;
                option.classList.toggle('selected', isSelected);

                if (isSelected) {
                    selectedOption = option;
                }
            });

            groupInput.value = normalizedValue;
            const selectedLabel = selectedOption ?
                String(selectedOption.getAttribute('data-label') || selectedOption.textContent || '').trim() :
                '';
            const fallbackText = String(fallbackLabel || '').trim();
            const resolvedLabel = selectedLabel || (fallbackText !== '-' ? fallbackText : '') || 'เลือกกลุ่ม/ฝ่าย';

            groupLabel.replaceChildren(document.createTextNode(resolvedLabel));
            groupLabel.textContent = resolvedLabel;
            groupLabel.dataset.currentLabel = resolvedLabel;
            groupLabel.setAttribute('title', resolvedLabel);
            groupLabel.setAttribute('aria-label', resolvedLabel);
            groupWrapper.dataset.selectedLabel = resolvedLabel;
            groupWrapper.setAttribute('data-selected-value', normalizedValue);
            groupInput.setAttribute('value', normalizedValue);
            groupInput.dispatchEvent(new Event('input', {
                bubbles: true
            }));
            groupInput.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        };

        const scheduleForwardGroupSelection = (value, fallbackLabel) => {
            setForwardGroupSelection(value, fallbackLabel);
            [0, 50, 150].forEach((delay) => {
                window.setTimeout(() => setForwardGroupSelection(value, fallbackLabel), delay);
            });
        };

        const renderNoticeDetailFileList = (section, list, circularId, files) => {
            if (!section || !list) {
                return;
            }

            if (files.length === 0) {
                section.style.display = '';
                list.innerHTML = '<p class="enterprise-empty">-</p>';
                return;
            }

            const safeCircularId = encodeURIComponent(String(circularId || '').trim());
            section.style.display = '';
            list.innerHTML = files.map((file) => {
                const fileId = encodeURIComponent(String(file?.fileID || ''));
                const fileName = escapeHtml(String(file?.fileName || '-'));
                const mimeType = String(file?.mimeType || '').trim();
                const typeLabel = escapeHtml(`${mimeType !== '' ? mimeType : 'ไฟล์แนบ'} • ${formatFileSize(file?.fileSize || 0)}`);
                const iconHtml = mimeType.toLowerCase() === 'application/pdf' ?
                    '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                    '<i class="fa-solid fa-file-image" aria-hidden="true"></i>';
                const viewHref = `public/api/file-download.php?module=circulars&entity_id=${safeCircularId}&file_id=${fileId}`;

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

        const renderNoticeDetailFiles = (circularId, rawFiles) => {
            const files = parseJsonList(rawFiles);
            const groupedFiles = splitNoticeFiles(files);

            renderNoticeDetailFileList(noticeDetailCoverSection, noticeDetailCoverList, circularId, groupedFiles.coverFiles);
            renderNoticeDetailFileList(noticeDetailAttachmentSection, noticeDetailAttachmentList, circularId, groupedFiles.attachmentFiles);
        };

        const renderNoticeDetailReadStats = (rawStats, shouldShow) => {
            if (!noticeDetailReceiptStatusSection || !noticeDetailReceiptStatusTableBody) {
                return;
            }

            const isEnabled = String(shouldShow || '').trim() === '1';
            const stats = parseJsonList(rawStats).filter((item) => String(item?.pID || '').trim() !== '');

            if (!isEnabled || stats.length === 0) {
                noticeDetailReceiptStatusSection.style.display = 'none';
                noticeDetailReceiptStatusTableBody.innerHTML = '<tr><td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td></tr>';
                return;
            }

            noticeDetailReceiptStatusSection.style.display = '';
            noticeDetailReceiptStatusTableBody.innerHTML = '';

            stats.forEach((item) => {
                const isRead = Number(item?.isRead || 0) === 1;
                const row = document.createElement('tr');
                const nameCell = document.createElement('td');
                const statusCell = document.createElement('td');
                const readAtCell = document.createElement('td');
                const statusPill = document.createElement('span');

                nameCell.textContent = String(item?.fName || '-').trim() || '-';
                statusPill.className = `status-pill ${isRead ? 'approved' : 'pending'}`;
                statusPill.textContent = isRead ? 'อ่านแล้ว' : 'ยังไม่อ่าน';
                statusCell.appendChild(statusPill);
                readAtCell.textContent = String(item?.readAtDisplay || '-').trim() || '-';

                row.appendChild(nameCell);
                row.appendChild(statusCell);
                row.appendChild(readAtCell);
                noticeDetailReceiptStatusTableBody.appendChild(row);
            });
        };

        const populateNoticeDetailModal = (button) => {
            if (!button || !noticeDetailUsesOutgoingLayout) {
                return;
            }

            const circularId = String(button.getAttribute('data-circular-id') || '').trim();
            const statusText = String(button.getAttribute('data-status') || '').trim() || '-';
            const statusClass = String(button.getAttribute('data-consider') || 'considering').trim().replace(/[^a-zA-Z0-9_-]/g, '') || 'considering';

            if (noticeDetailStatus) {
                noticeDetailStatus.className = `consider-status ${statusClass}`;
                noticeDetailStatus.textContent = statusText;
            }

            setNoticePriorityRadio(button.getAttribute('data-urgency-class'));
            setNoticeInput(noticeDetailBookNo, button.getAttribute('data-bookno'));
            if (noticeDetailIssuedDate) {
                setNoticeInput(noticeDetailIssuedDate, button.getAttribute('data-issued'));
                noticeDetailIssuedDate.dataset.rawValue = String(button.getAttribute('data-issued-raw') || '').trim();
            }
            setNoticeInput(noticeDetailSubject, button.getAttribute('data-subject'));
            setNoticeInput(noticeDetailFrom, button.getAttribute('data-from'));
            setNoticeInput(noticeDetailGroup, button.getAttribute('data-group'));
            setNoticeInput(noticeDetailLink, button.getAttribute('data-link'));
            setNoticeInput(noticeDetailProposer, button.getAttribute('data-sender-name'));
            setNoticeDetailEditorContent(button.getAttribute('data-detail'));
            const hasLatestComment = button.hasAttribute('data-latest-comment');
            const latestComment = hasLatestComment ?
                String(button.getAttribute('data-latest-comment') || '').trim() :
                '';
            const latestCommentLabel = String(button.getAttribute('data-latest-comment-label') || 'ความคิดเห็นของผู้ส่งล่าสุด').trim() || 'ความคิดเห็นของผู้ส่งล่าสุด';
            const announcementComment = String(button.getAttribute('data-announcement-comment') || '').trim();
            const announcementCommentLabel = String(button.getAttribute('data-announcement-comment-label') || 'ความคิดเห็นของรองผู้อำนวยการ').trim() || 'ความคิดเห็นของรองผู้อำนวยการ';
            const deputyComment = String(button.getAttribute('data-deputy-comment') || announcementComment).trim();
            const deputyCommentLabel = String(button.getAttribute('data-deputy-comment-label') || announcementCommentLabel || 'ความคิดเห็นของรองผู้อำนวยการ').trim() || 'ความคิดเห็นของรองผู้อำนวยการ';
            const registryComment = String(button.getAttribute('data-review-chain-registry-comment') || '').trim();
            const reviewComment = String(button.getAttribute('data-review-chain-director-comment') || '').trim();
            const reviewCommentLabel = String(button.getAttribute('data-review-chain-director-label') || button.getAttribute('data-director-comment-label') || 'ความคิดเห็นของผู้อำนวยการโรงเรียน').trim() || 'ความคิดเห็นของผู้อำนวยการโรงเรียน';
            const isAnnounced = String(button.getAttribute('data-announced') || '').trim() === '1';
            const isDeputyForwarded = String(button.getAttribute('data-deputy-forwarded') || '').trim() === '1';
            const displayLatestComment = isAnnounced && announcementComment !== '' ? announcementComment : latestComment;
            const displayLatestCommentLabel = isAnnounced && announcementComment !== '' ? announcementCommentLabel : latestCommentLabel;
            const shouldSplitReviewComments = registryComment !== '' || reviewComment !== '';
            const shouldShowDeputyComment = (isAnnounced || isDeputyForwarded) && deputyComment !== '';
            const shouldShowLatestComment = !shouldShowDeputyComment &&
                displayLatestComment !== '' &&
                !shouldSplitReviewComments;
            const shouldHideMemoDetail = shouldSplitReviewComments ||
                String(button.getAttribute('data-hide-memo-detail') || '').trim() === '1';

            if (noticeDetailMemoSection) {
                noticeDetailMemoSection.style.display = shouldHideMemoDetail ? 'none' : '';
            }

            if (noticeDetailLatestCommentLabel) {
                noticeDetailLatestCommentLabel.textContent = displayLatestCommentLabel;
            }

            if (noticeDetailLatestCommentSection) {
                noticeDetailLatestCommentSection.style.display = shouldShowLatestComment ? '' : 'none';
            }

            if (noticeDetailRegistryCommentSection) {
                noticeDetailRegistryCommentSection.style.display = shouldSplitReviewComments && registryComment !== '' ? '' : 'none';
            }

            if (noticeDetailReviewCommentSection) {
                noticeDetailReviewCommentSection.style.display = shouldSplitReviewComments && reviewComment !== '' ? '' : 'none';
            }

            if (noticeDetailReviewCommentLabel) {
                noticeDetailReviewCommentLabel.textContent = reviewCommentLabel;
            }

            if (noticeDetailDeputyCommentSection) {
                noticeDetailDeputyCommentSection.style.display = shouldShowDeputyComment ? '' : 'none';
            }

            if (noticeDetailDeputyCommentLabel) {
                noticeDetailDeputyCommentLabel.textContent = deputyCommentLabel;
            }

            setNoticeDirectorCommentContent(shouldShowLatestComment ? displayLatestComment : '');
            setNoticeRegistryCommentContent(registryComment);
            setNoticeReviewCommentContent(reviewComment);
            setNoticeDeputyCommentContent(shouldShowDeputyComment ? deputyComment : '');
            renderNoticeDetailFiles(circularId, button.getAttribute('data-files'));
            renderNoticeDetailReadStats(button.getAttribute('data-read-stats'), button.getAttribute('data-show-read-stats'));
        };

        document.querySelectorAll('.js-open-circular-modal').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (noticeDetailUsesOutgoingLayout && detailModalKeep) {
                    populateNoticeDetailModal(this);
                    detailModalKeep.style.display = 'flex';
                } else if (this.hasAttribute('data-urgency') && detailModalExt) {
                    detailModalExt.style.display = 'flex';
                } else if (detailModalKeep) {
                    detailModalKeep.style.display = 'flex';
                }
            });
        });

        closeDetailKeepBtn?.addEventListener('click', () => {
            if (detailModalKeep) detailModalKeep.style.display = 'none';
        });
        detailModalKeep?.addEventListener('click', (e) => {
            if (e.target === detailModalKeep) detailModalKeep.style.display = 'none';
        });
        closeDetailExtBtn?.addEventListener('click', () => {
            if (detailModalExt) detailModalExt.style.display = 'none';
        });
        detailModalExt?.addEventListener('click', (e) => {
            if (e.target === detailModalExt) detailModalExt.style.display = 'none';
        });

        setupCircularForm('forward_', 'circularForwardForm');

        const sendModal = document.getElementById('modalNoticeSendOverlay');
        const closeSendBtn = document.getElementById('closeModalNoticeSend');
        const sendForm = sendModal?.querySelector('#circularForwardForm');
        const forwardViewUrgentRadios = sendModal ? Array.from(sendModal.querySelectorAll('[data-forward-urgent]')) : [];
        const forwardViewBookNo = document.getElementById('forwardViewBookNo');
        const forwardViewIssuedDate = document.getElementById('forwardViewIssuedDate');
        const forwardViewSubject = document.getElementById('forwardViewSubject');
        const forwardViewFrom = document.getElementById('forwardViewFrom');
        const forwardViewGroup = document.getElementById('forwardViewGroup');
        const forwardViewGroupSelectWrapper = document.getElementById('forwardViewGroupSelectWrapper');
        const forwardViewGroupFid = document.getElementById('forwardViewGroupFid');
        const forwardViewGroupSelectLabel = document.getElementById('forwardViewGroupSelectLabel');
        const forwardViewDetailSection = document.getElementById('forwardViewDetailSection');
        const forwardViewDetail = document.getElementById('forwardViewDetail');
        const forwardDirectorComment = document.getElementById('forwardDirectorComment');
        const forwardDirectorCommentSection = document.getElementById('forwardDirectorCommentSection');
        const forwardDirectorCommentDivider = document.getElementById('forwardDirectorCommentDivider');
        const forwardLatestCommentLabel = document.getElementById('forwardLatestCommentLabel');
        const forwardRegistryComment = document.getElementById('forwardRegistryComment');
        const forwardRegistryCommentSection = document.getElementById('forwardRegistryCommentSection');
        const forwardRegistryCommentDivider = document.getElementById('forwardRegistryCommentDivider');
        const forwardReviewComment = document.getElementById('forwardReviewComment');
        const forwardReviewCommentSection = document.getElementById('forwardReviewCommentSection');
        const forwardReviewCommentDivider = document.getElementById('forwardReviewCommentDivider');
        const forwardReviewCommentLabel = document.getElementById('forwardReviewCommentLabel');
        const forwardDeputyComment = document.getElementById('forwardDeputyComment');
        const forwardDeputyCommentSection = document.getElementById('forwardDeputyCommentSection');
        const forwardDeputyCommentDivider = document.getElementById('forwardDeputyCommentDivider');
        const forwardAnnouncementSection = document.getElementById('forwardAnnouncementSection');
        const forwardPublishAnnouncement = document.getElementById('forwardPublishAnnouncement');
        const forwardSendButton = document.getElementById('forward_btnSendNotice');
        const forwardSendButtonText = forwardSendButton?.querySelector('p');
        const forwardIsReviewerReturnPage = <?= json_encode($forward_is_reviewer_return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const forwardDefaultButtonLabel = <?= json_encode($forward_is_reviewer_return ? 'พิจารณา' : 'ส่งหนังสือต่อ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const forwardDefaultConfirmTitle = <?= json_encode($forward_is_reviewer_return ? 'ยืนยันการพิจารณา' : 'ยืนยันการส่งหนังสือต่อ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const forwardAnnouncementButtonLabel = 'ขึ้นข่าวประชาสัมพันธ์';
        const forwardAnnouncementConfirmTitle = 'ยืนยันการขึ้นข่าวประชาสัมพันธ์';
        const forwardViewLink = document.getElementById('forwardViewLink');
        const forwardViewProposer = document.getElementById('forwardViewProposer');
        const forwardViewCoverSection = document.getElementById('forwardViewCoverSection');
        const forwardViewCoverList = document.getElementById('forwardViewCoverList');
        const forwardViewAttachmentSection = document.getElementById('forwardViewAttachmentSection');
        const forwardViewAttachmentList = document.getElementById('forwardViewAttachmentList');

        const updateForwardActionButtonState = () => {
            if (!forwardSendButton) return;

            const isAnnouncementLocked = String(sendForm?.dataset.announcementLocked || '').trim() === '1';
            const isDeputyDistributeAction = String(sendForm?.dataset.deputyDistributeAction || '').trim() === '1';
            const isDeputyForwarded = String(sendForm?.dataset.deputyForwarded || '').trim() === '1';
            const canPublishAnnouncement = isDeputyDistributeAction && !isAnnouncementLocked && !isDeputyForwarded;
            const shouldPublishAnnouncement = canPublishAnnouncement && Boolean(forwardPublishAnnouncement?.checked);
            const label = shouldPublishAnnouncement ? forwardAnnouncementButtonLabel : forwardDefaultButtonLabel;
            const confirmTitle = shouldPublishAnnouncement ? forwardAnnouncementConfirmTitle : forwardDefaultConfirmTitle;
            const recipientSection = sendForm?.querySelector('[data-recipients-section]');
            const receiptStatusSection = sendForm?.querySelector('#forwardReceiptStatusSection');

            forwardSendButton.style.display = isAnnouncementLocked ? 'none' : '';

            if (forwardSendButtonText) {
                forwardSendButtonText.textContent = label;
            }
            forwardSendButton.setAttribute('data-confirm-title', confirmTitle);

            if (recipientSection) {
                recipientSection.style.display = (isAnnouncementLocked || shouldPublishAnnouncement) ? 'none' : '';
            }
            if (forwardAnnouncementSection) {
                forwardAnnouncementSection.style.display = canPublishAnnouncement ? '' : 'none';
            }
            if (forwardPublishAnnouncement) {
                forwardPublishAnnouncement.disabled = !canPublishAnnouncement;
                if (!canPublishAnnouncement) {
                    forwardPublishAnnouncement.checked = false;
                }
            }
            if (receiptStatusSection && (isAnnouncementLocked || shouldPublishAnnouncement)) {
                receiptStatusSection.style.display = 'none';
            } else if (sendForm && typeof sendForm.setReadStats === 'function') {
                sendForm.setReadStats(sendForm.dataset.readStats || '[]');
            }
        };

        forwardPublishAnnouncement?.addEventListener('change', updateForwardActionButtonState);

        const syncForwardReviewFormFields = () => {
            if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                window.tinymce.triggerSave();
            }

            if (forwardViewGroupFid && forwardViewGroupFid.tagName === 'SELECT') {
                const selectedOption = forwardViewGroupFid.options[forwardViewGroupFid.selectedIndex] || null;
                const selectedValue = String(selectedOption?.value || forwardViewGroupFid.value || '').trim();

                if (selectedValue !== '') {
                    forwardViewGroupFid.value = selectedValue;
                }
            }
        };

        sendForm?.addEventListener('submit', syncForwardReviewFormFields);

        const renderSendModalFiles = (circularId, rawFiles) => {
            if (!sendModal) return;
            const files = parseJsonList(rawFiles);
            const groupedFiles = splitNoticeFiles(files);

            renderNoticeDetailFileList(forwardViewCoverSection, forwardViewCoverList, circularId, groupedFiles.coverFiles);
            renderNoticeDetailFileList(forwardViewAttachmentSection, forwardViewAttachmentList, circularId, groupedFiles.attachmentFiles);
        };

        const openSendModal = (button, options = {}) => {
            if (!sendModal || !button) return;

            if (sendForm && typeof sendForm.resetCircularFormState === 'function') {
                sendForm.resetCircularFormState();
            }

            const circularId = String(button.getAttribute('data-circular-id') || '').trim();
            const inboxId = String(button.getAttribute('data-inbox-id') || '').trim();
            const subject = String(button.getAttribute('data-subject') || '').trim();
            const detail = String(button.getAttribute('data-detail') || '').trim();
            const linkUrl = String(button.getAttribute('data-link') || '').trim();
            const ownerPid = String(button.getAttribute('data-owner-pid') || '').trim();
            const senderName = String(button.getAttribute('data-sender-name') || '').trim();
            const rawFiles = button.getAttribute('data-files') || '[]';
            const rawReadStats = button.getAttribute('data-read-stats') || '[]';
            const rawForwardedPids = button.getAttribute('data-forwarded-pids') || '[]';
            const directorComment = String(button.getAttribute('data-director-comment') || '').trim();
            const directorCommentLabel = String(button.getAttribute('data-director-comment-label') || 'ความคิดเห็นของผู้อำนวยการโรงเรียน').trim() || 'ความคิดเห็นของผู้อำนวยการโรงเรียน';
            const latestComment = button.hasAttribute('data-latest-comment') ?
                String(button.getAttribute('data-latest-comment') || '').trim() :
                '';
            const latestCommentLabel = String(button.getAttribute('data-latest-comment-label') || 'ความคิดเห็นของผู้ส่งล่าสุด').trim() || 'ความคิดเห็นของผู้ส่งล่าสุด';
            const announcementComment = String(button.getAttribute('data-announcement-comment') || '').trim();
            const announcementCommentLabel = String(button.getAttribute('data-announcement-comment-label') || 'ความคิดเห็นของรองผู้อำนวยการ').trim() || 'ความคิดเห็นของรองผู้อำนวยการ';
            const deputyComment = String(button.getAttribute('data-deputy-comment') || announcementComment).trim();
            const isRegistryHandoffAction = String(button.getAttribute('data-registry-handoff') || '').trim() === '1';
            const isDeputyDistributeAction = String(button.getAttribute('data-deputy-distribute') || '').trim() === '1';
            const isAnnounced = String(button.getAttribute('data-announced') || '').trim() === '1';
            const isDeputyForwarded = String(button.getAttribute('data-deputy-forwarded') || '').trim() === '1';
            const displayLatestComment = isAnnounced && announcementComment !== '' ? announcementComment : latestComment;
            const displayLatestCommentLabel = isAnnounced && announcementComment !== '' ? announcementCommentLabel : latestCommentLabel;
            const registryComment = String(button.getAttribute('data-review-chain-registry-comment') || '').trim();
            const reviewComment = String(button.getAttribute('data-review-chain-director-comment') || '').trim();
            const reviewCommentLabel = String(button.getAttribute('data-review-chain-director-label') || directorCommentLabel).trim() || directorCommentLabel;
            const shouldSplitReviewComments = !forwardIsReviewerReturnPage &&
                (isRegistryHandoffAction || isDeputyDistributeAction) &&
                (registryComment !== '' || reviewComment !== '');
            const shouldHideMemoDetail = shouldSplitReviewComments ||
                String(button.getAttribute('data-hide-memo-detail') || '').trim() === '1';
            const groupFid = String(button.getAttribute('data-group-fid') || '').trim();
            const groupLabel = String(button.getAttribute('data-group') || '').trim();

            if (forwardViewDetailSection) {
                forwardViewDetailSection.style.display = shouldHideMemoDetail ? 'none' : '';
            }

            const targetInput = sendModal.querySelector('#forwardTargetCircularId');
            const inboxInput = sendModal.querySelector('[data-send-inbox-id]');
            const circularInput = sendModal.querySelector('[data-send-circular-id]');
            const subjectInput = sendModal.querySelector('input[name="subject"]');
            const detailInput = sendModal.querySelector('textarea[name="detail"]');
            const linkInput = sendModal.querySelector('input[name="linkURL"]');

            if (sendForm) {
                sendForm.dataset.registryHandoff = isRegistryHandoffAction ? '1' : '0';
                sendForm.dataset.deputyDistributeAction = isDeputyDistributeAction ? '1' : '0';
                sendForm.dataset.deputyForwarded = isDeputyForwarded ? '1' : '0';
                sendForm.dataset.announcementLocked = (isDeputyDistributeAction && isAnnounced) ? '1' : '0';
            }

            if (targetInput) targetInput.value = circularId;
            if (inboxInput) inboxInput.value = inboxId;
            if (circularInput) circularInput.value = circularId;
            if (subjectInput) subjectInput.value = subject;
            if (detailInput) detailInput.value = detail;
            if (linkInput) linkInput.value = linkUrl !== '' ? linkUrl : '-';

            setNoticePriorityRadios(forwardViewUrgentRadios, button.getAttribute('data-urgency-class'));
            setNoticeInput(forwardViewBookNo, button.getAttribute('data-bookno'));
            setNoticeInput(forwardViewIssuedDate, button.getAttribute('data-issued'));
            setNoticeInput(forwardViewSubject, subject);
            setNoticeInput(forwardViewFrom, button.getAttribute('data-from'));
            setNoticeInput(forwardViewGroup, groupLabel);
            scheduleForwardGroupSelection(groupFid, groupLabel);
            setForwardViewEditorContent(detail);
            const shouldShowLatestComment = !forwardIsReviewerReturnPage &&
                (!shouldSplitReviewComments || isAnnounced) &&
                (isRegistryHandoffAction || isDeputyDistributeAction) &&
                displayLatestComment !== '';
            const shouldShowDirectorComment = forwardIsReviewerReturnPage || shouldShowLatestComment;
            [forwardDirectorCommentDivider, forwardDirectorCommentSection].forEach((element) => {
                if (element) {
                    element.style.display = shouldShowDirectorComment ? '' : 'none';
                }
            });
            if (forwardLatestCommentLabel) {
                forwardLatestCommentLabel.textContent = forwardIsReviewerReturnPage ? directorCommentLabel : displayLatestCommentLabel;
            }
            setForwardDirectorCommentContent(forwardIsReviewerReturnPage ? directorComment : (shouldShowLatestComment ? displayLatestComment : ''), '');
            [forwardRegistryCommentDivider, forwardRegistryCommentSection].forEach((element) => {
                if (element) {
                    element.style.display = shouldSplitReviewComments && registryComment !== '' ? '' : 'none';
                }
            });
            [forwardReviewCommentDivider, forwardReviewCommentSection].forEach((element) => {
                if (element) {
                    element.style.display = shouldSplitReviewComments && reviewComment !== '' ? '' : 'none';
                }
            });
            if (forwardReviewCommentLabel) {
                forwardReviewCommentLabel.textContent = reviewCommentLabel;
            }
            setForwardRegistryCommentContent(shouldSplitReviewComments ? registryComment : '');
            setForwardReviewCommentContent(shouldSplitReviewComments ? reviewComment : '');
            [forwardDeputyCommentDivider, forwardDeputyCommentSection].forEach((element) => {
                if (element) {
                    element.style.display = isDeputyDistributeAction ? '' : 'none';
                }
            });
            if (forwardAnnouncementSection) {
                forwardAnnouncementSection.style.display = isDeputyDistributeAction && !isAnnounced && !isDeputyForwarded ? '' : 'none';
            }
            setForwardDeputyCommentContent(deputyComment);
            if (forwardPublishAnnouncement) {
                forwardPublishAnnouncement.checked = false;
                forwardPublishAnnouncement.disabled = !isDeputyDistributeAction || isAnnounced || isDeputyForwarded;
            }
            updateForwardActionButtonState();
            setNoticeInput(forwardViewLink, linkUrl);
            setNoticeInput(forwardViewProposer, senderName);
            renderSendModalFiles(circularId, rawFiles);
            if (sendForm && typeof sendForm.setReadStats === 'function') {
                sendForm.setReadStats(isRegistryHandoffAction ? '[]' : rawReadStats);
            }

            if (sendForm && typeof sendForm.applyRecipientOwnerFilter === 'function') {
                sendForm.applyRecipientOwnerFilter(ownerPid);
            }

            if (isRegistryHandoffAction && sendForm && typeof sendForm.clearRecipientSelection === 'function') {
                sendForm.clearRecipientSelection();
            }

            if (sendForm && typeof sendForm.applyRecipientSelection === 'function') {
                let forwardedPids = [];
                if (!isRegistryHandoffAction) {
                    try {
                        forwardedPids = JSON.parse(String(rawForwardedPids || '[]'));
                    } catch (error) {
                        forwardedPids = [];
                    }
                }
                sendForm.applyRecipientSelection(forwardedPids);
            }

            sendModal.style.display = 'flex';
        };

        const outgoingNoticeRoot = document.querySelector('[data-outgoing-notice]');
        outgoingNoticeRoot?.addEventListener('click', (event) => {
            const button = event.target.closest('.js-open-circular-send-modal');

            if (!button || !outgoingNoticeRoot.contains(button)) {
                return;
            }

            event.preventDefault();
            openSendModal(button);
        });
        closeSendBtn?.addEventListener('click', () => {
            if (sendModal) sendModal.style.display = 'none';
        });
        sendModal?.addEventListener('click', (e) => {
            if (e.target === sendModal) sendModal.style.display = 'none';
        });

        if (forwardOpenInboxId > 0) {
            const reopenTarget = Array.from(document.querySelectorAll('.js-open-circular-send-modal')).find((button) => {
                return String(button.getAttribute('data-inbox-id') || '').trim() === String(forwardOpenInboxId);
            });

            if (reopenTarget) {
                openSendModal(reopenTarget);
            }
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
