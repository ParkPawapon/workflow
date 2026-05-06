<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../modules/memos/status.php';

$items = (array) ($items ?? []);
$page = (int) ($page ?? 1);
$total_pages = (int) ($total_pages ?? 1);
$search = trim((string) ($search ?? ''));
$status_filter = (string) ($status_filter ?? 'all');
$filtered_total = (int) ($filtered_total ?? 0);
$pagination_base_url = (string) ($pagination_base_url ?? 'memo-inbox.php');
$archived = (bool) ($archived ?? false);
$dh_year_options = array_values(array_filter(array_map('intval', (array) ($dh_year_options ?? [])), static function (int $year): bool {
    return $year > 0;
}));
$selected_dh_year = (int) ($selected_dh_year ?? ($dh_year_options[0] ?? 0));
$dh_year_label = $selected_dh_year > 0 ? (string) $selected_dh_year : '-';
$filter_search = $search;
$status_options = [];

foreach (memo_status_options() as $status_value => $status_label) {
    if ($status_value === MEMO_STATUS_DRAFT) {
        continue;
    }

    if ($status_value === MEMO_STATUS_APPROVED_UNSIGNED) {
        $status_options['signed_all'] = 'ลงนามแล้ว';
        continue;
    }

    if ($status_value === MEMO_STATUS_SIGNED) {
        continue;
    }

    $status_options[$status_value] = $status_label;
}

if (in_array($status_filter, [MEMO_STATUS_APPROVED_UNSIGNED, MEMO_STATUS_SIGNED], true)) {
    $status_filter = 'signed_all';
}

$filter_status = array_key_exists($status_filter, $status_options) ? $status_filter : 'all';
$filter_status_label = (string) ($status_options[$filter_status] ?? 'ทั้งหมด');
$filter_sort = trim((string) ($_GET['sort'] ?? 'newest'));
$filter_view = trim((string) ($_GET['view'] ?? 'table1'));

if (!in_array($filter_sort, ['newest', 'oldest'], true)) {
    $filter_sort = 'newest';
}

if (!in_array($filter_view, ['table1', 'table2'], true)) {
    $filter_view = 'table1';
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

$format_thai_datetime = static function (?string $date_value) use ($thai_months): array {
    $date_value = trim((string) $date_value);

    if ($date_value === '' || strpos($date_value, '0000-00-00') === 0) {
        return ['-', '-'];
    }

    $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $date_value);

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i', $date_value);
    }

    if ($date_obj === false) {
        return [$date_value, '-'];
    }

    $day = (int) $date_obj->format('j');
    $month = $thai_months[(int) $date_obj->format('n')] ?? '';
    $year = (int) $date_obj->format('Y') + 543;

    return [trim($day . ' ' . $month . ' ' . $year), $date_obj->format('H:i') . ' น.'];
};

$values = $values ?? ['writeDate' => '', 'subject' => '', 'detail' => ''];
$current_user = (array) ($current_user ?? []);
$current_pid = trim((string) ($current_user['pID'] ?? ''));
$factions = (array) ($factions ?? []);
$deputy_candidates = array_values(array_filter((array) ($deputy_candidates ?? []), static function ($candidate): bool {
    return is_array($candidate) && trim((string) ($candidate['pID'] ?? '')) !== '' && trim((string) ($candidate['name'] ?? '')) !== '';
}));
$deputy_candidates_json = json_encode(array_map(static function (array $candidate): array {
    return [
        'pID' => trim((string) ($candidate['pID'] ?? '')),
        'name' => trim((string) ($candidate['name'] ?? '')),
        'positionName' => trim((string) ($candidate['positionName'] ?? '')),
    ];
}, $deputy_candidates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($deputy_candidates_json === false) {
    $deputy_candidates_json = '[]';
}

$selected_sender_fid = trim((string) ($values['sender_fid'] ?? ''));

if ($selected_sender_fid === '' && !empty($factions)) {
    $selected_sender_fid = (string) ($factions[0]['fID'] ?? '');
}

$selected_faction_name = '';

foreach ($factions as $faction) {
    if ((string) ($faction['fID'] ?? '') === $selected_sender_fid) {
        $selected_faction_name = trim((string) ($faction['fname'] ?? ''));
        break;
    }
}

$current_name = trim((string) ($current_user['fName'] ?? ''));
$current_position = trim((string) ($current_user['position_name'] ?? ''));

ob_start();
?>

<style>
    #modalEditOverlay [data-memo-stage-section] {
        position: relative;
        z-index: 1;
    }

    #modalEditOverlay [data-memo-review-action-row],
    #modalEditOverlay [data-memo-review-comment-row] {
        position: relative;
        z-index: 25;
        margin: 6px 0 10px;
    }

    #modalEditOverlay [data-memo-review-action-row].is-open,
    #modalEditOverlay [data-memo-review-comment-row].is-open {
        z-index: 60;
    }

    #modalEditOverlay [data-memo-review-action-row] .custom-select-wrapper,
    #modalEditOverlay [data-memo-review-comment-row] .custom-select-wrapper {
        pointer-events: auto;
        z-index: 30;
    }

    #modalEditOverlay [data-memo-review-action-row] .custom-options,
    #modalEditOverlay [data-memo-review-comment-row] .custom-options {
        z-index: 35;
    }

    .table-circular-notice-index table thead th:nth-child(2) ,
    .table-circular-notice-index table td:nth-child(2) {
        text-align: center !important;
    }
    
    .table-circular-notice-index table thead th:nth-child(5) ,
    .table-circular-notice-index table td:nth-child(5) {
        text-align: start !important;
    }

    .table-circular-notice-index table thead th:nth-child(1) {
        width: 45px !important;
        min-width: 45px !important;
        max-width: 45px !important;
    }

    .table-circular-notice-index table thead th:nth-child(2) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3) {
        min-width: 500px !important;
        max-width: 500px !important;
    }

    .table-circular-notice-index table thead th:nth-child(4) {
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
    }

    .table-circular-notice-index table thead th:nth-child(5) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-index table thead th:nth-child(6) {
        width: 140px !important;
        min-width: 140px !important;
        max-height: 140px !important;
    }


    @media screen and (max-width: 1024px) {
        .table-circular-notice-index table thead th:nth-child(1) {
            width: 45px !important;
            min-width: 45px !important;
            max-width: 45px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3) {
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4) {
            width: 280px !important;
            min-width: 280px !important;
            max-width: 280px !important;
        }

        .table-circular-notice-index table thead th:nth-child(5) {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 160px !important;
        }

        .table-circular-notice-index table thead th:nth-child(6) {
            width: 100px !important;
            min-width: 100px !important;
            max-height: 100px !important;
        }

    }

    @media screen and (max-width: 768px) {
        .table-circular-notice-index table thead th:nth-child(1) {
            width: 45px !important;
            min-width: 45px !important;
            max-width: 45px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }
        .table-circular-notice-index table thead th:nth-child(3) {
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4) {
            width: 200px !important;
            min-width: 200px !important;
            max-width: 200px !important;
        }

        .table-circular-notice-index table thead th:nth-child(5) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }

        .table-circular-notice-index table thead th:nth-child(6) {
            width: 100px !important;
            min-width: 100px !important;
            max-height: 100px !important;
        }

    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>บันทึกข้อความ / กล่องบันทึกข้อความ</p>
</div>

<form id="circularFilterForm" method="GET">
    <input type="hidden" name="dh_year" id="filterYearInput" value="<?= h((string) $selected_dh_year) ?>">
    <input type="hidden" name="status" id="filterStatusInput" value="<?= h($filter_status) ?>">
    <input type="hidden" name="sort" id="filterSortInput" value="<?= h($filter_sort) ?>">
    <input type="hidden" name="view" id="filterViewInput" value="<?= h($filter_view) ?>">
</form>

<input type="hidden" id="csrfToken" value="<?= h(csrf_token()) ?>">

<header class="header-circular-notice-keep">
    <div class="circular-notice-keep-control">
        <div class="page-selector">
            <p>แสดงตามปีสารบรรณ</p>
            <div class="custom-select-wrapper" data-target="filterYearInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($dh_year_label) ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="custom-options">
                    <?php if (empty($dh_year_options)) : ?>
                        <div class="custom-option selected" data-value="<?= h((string) $selected_dh_year) ?>"><?= h($dh_year_label) ?></div>
                    <?php else : ?>
                        <?php foreach ($dh_year_options as $year_option) : ?>
                            <div class="custom-option<?= $selected_dh_year === (int) $year_option ? ' selected' : '' ?>" data-value="<?= h((string) $year_option) ?>"><?= h((string) $year_option) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="page-selector">
            <p>แสดงตามสถานะหนังสือ</p>
            <div class="custom-select-wrapper" data-target="filterStatusInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($filter_status_label) ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="custom-options">
                    <?php foreach ($status_options as $status_value => $status_label) : ?>
                        <div class="custom-option<?= $filter_status === (string) $status_value ? ' selected' : '' ?>" data-value="<?= h((string) $status_value) ?>"><?= h((string) $status_label) ?></div>
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
                    <div class="custom-option<?= $filter_sort === 'newest' ? ' selected' : '' ?>" data-value="newest">ใหม่ไปเก่า</div>
                    <div class="custom-option<?= $filter_sort === 'oldest' ? ' selected' : '' ?>" data-value="oldest">เก่าไปใหม่</div>
                </div>
            </div>
        </div>
    </div>
</header>

<section
    class="content-circular-notice-index"
    data-circular-notice
    data-ajax-filter="true"
    data-ajax-target=".table-circular-notice-index">
    <div class="search-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="text"
                id="search-input"
                name="q"
                form="circularFilterForm"
                value="<?= h($filter_search) ?>"
                placeholder="ค้นหาข้อความด้วย..."
                data-auto-submit="true"
                data-auto-submit-delay="450">
        </div>
    </div>

    <form id="bulkActionForm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="archive_selected">
        <div class="table-circular-notice-index memo-inbox-table">
            <table>
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" class="check-table checkall" id="checkAllCircular">
                        </th>
                        <th>จัดการ</th>
                        <th>เรื่อง</th>
                        <th>ผู้เสนอแฟ้ม</th>
                        <th>วันที่เสนอแฟ้ม</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)) : ?>
                        <tr>
                            <td colspan="6" class="enterprise-empty">ไม่มีรายการ</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($items as $item) : ?>
                            <?php
                            $memo_id = (int) ($item['memoID'] ?? 0);
                            $subject = trim((string) ($item['subject'] ?? ''));
                            $detail = (string) ($item['detail'] ?? '');
                            $creator_name = trim((string) ($item['creatorName'] ?? ''));
                            $creator_signature = trim((string) ($item['creatorSignature'] ?? ''));
                            $creator_section = trim((string) ($item['creatorFactionName'] ?? ''));

                            if ($creator_section === '') {
                                $creator_section = trim((string) ($item['creatorDepartmentName'] ?? ''));
                            }

                            $creator_position = trim((string) ($item['creatorPositionName'] ?? ''));
                            $reviewer_role = strtoupper(trim((string) ($item['reviewerRole'] ?? '')));
                            $effective_flow_stage = strtoupper(trim((string) ($item['effectiveFlowStage'] ?? ($item['flowStage'] ?? ''))));
                            $status = (string) ($item['status'] ?? '');
                            $status_meta = memo_status_meta_for_record($item);
                            $status_class = (string) ($status_meta['pill_variant'] ?? 'pending');
                            $submitted_at = trim((string) ($item['submittedAt'] ?? ''));
                            $created_at = trim((string) ($item['createdAt'] ?? ''));
                            [$date_line, $time_line] = $format_thai_datetime($submitted_at !== '' ? $submitted_at : $created_at);
                            $head_pid = trim((string) ($item['headResolvedPID'] ?? ''));
                            $deputy_pid = trim((string) ($item['deputyResolvedPID'] ?? ''));
                            $director_pid = trim((string) ($item['directorResolvedPID'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="check-table" name="selected_ids[]" value="<?= h((string) $memo_id) ?>">
                                </td>
                                <td>
                                    <button
                                        class="booking-action-btn secondary js-open-edit-modal"
                                        type="button"
                                        data-memo-id="<?= h((string) $memo_id) ?>"
                                        data-subject="<?= h($subject) ?>"
                                        data-detail="<?= h($detail) ?>"
                                        data-to-pid="<?= h((string) ($item['toPID'] ?? '')) ?>"
                                        data-flow-mode="<?= h((string) ($item['flowMode'] ?? '')) ?>"
                                        data-flow-stage="<?= h($effective_flow_stage !== '' ? $effective_flow_stage : (string) ($item['flowStage'] ?? '')) ?>"
                                        data-to-type="<?= h((string) ($item['toType'] ?? '')) ?>"
                                        data-section="<?= h($creator_section !== '' ? $creator_section : ($selected_faction_name !== '' ? $selected_faction_name : 'กลุ่ม')) ?>"
                                        data-name="<?= h($creator_name !== '' ? $creator_name : '-') ?>"
                                        data-position="<?= h($creator_position !== '' ? $creator_position : '-') ?>"
                                        data-signature="<?= h($creator_signature) ?>"
                                        data-reviewer-role="<?= h($reviewer_role) ?>"
                                        data-head-pid="<?= h($head_pid) ?>"
                                        data-head-name="<?= h((string) ($item['headName'] ?? '')) ?>"
                                        data-head-position="<?= h((string) ($item['headPositionName'] ?? '')) ?>"
                                        data-head-signature="<?= h((string) ($item['headSignature'] ?? '')) ?>"
                                        data-head-note="<?= h((string) ($item['headNote'] ?? '')) ?>"
                                        data-head-action="<?= h((string) ($item['headAction'] ?? '')) ?>"
                                        data-deputy-pid="<?= h($deputy_pid) ?>"
                                        data-deputy-name="<?= h((string) ($item['deputyName'] ?? '')) ?>"
                                        data-deputy-position="<?= h((string) ($item['deputyPositionName'] ?? '')) ?>"
                                        data-deputy-signature="<?= h((string) ($item['deputySignature'] ?? '')) ?>"
                                        data-deputy-note="<?= h((string) ($item['deputyNote'] ?? '')) ?>"
                                        data-deputy-action="<?= h((string) ($item['deputyAction'] ?? '')) ?>"
                                        data-director-pid="<?= h($director_pid) ?>"
                                        data-director-name="<?= h((string) ($item['directorName'] ?? '')) ?>"
                                        data-director-position="<?= h((string) ($item['directorPositionName'] ?? '')) ?>"
                                        data-director-signature="<?= h((string) ($item['directorSignature'] ?? '')) ?>"
                                        data-director-note="<?= h((string) ($item['directorNote'] ?? '')) ?>"
                                        data-director-action="<?= h((string) ($item['directorAction'] ?? '')) ?>">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        <span class="tooltip">ดูรายละเอียด</span>
                                    </button>
                                </td>
                                <td><?= h($subject !== '' ? $subject : '-') ?></td>
                                <td>
                                    <div class="circular-sender-stack">
                                        <span class="circular-sender-name"><?= h($creator_name !== '' ? $creator_name : '-') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <p><?= h($date_line) ?></p>
                                    <p class="detail-subtext"><?= h($time_line) ?></p>
                                </td>
                                <td><span class="status-pill <?= h($status_class) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <div class="modal-overlay-memo details" id="modalEditOverlay" style="display: none;">
        <div class="modal-content">
            <div class="header-modal">
                <p id="modalTypeLabel">รายละเอียดบันทึกข้อความ</p>
                <i class="fa-solid fa-xmark" id="closeModalEdit" aria-hidden="true"></i>
            </div>

            <div class="content-modal">
                <div class="content-memo" style="box-shadow: none;">
                    <div class="memo-header">
                        <img src="assets/img/garuda-logo.png" alt="">
                        <p>บันทึกข้อความ</p>
                        <div></div>
                    </div>

                    <div class="memo-detail">
                        <div class="form-group-row">
                            <p><strong>ส่วนราชการ</strong></p>

                            <div class="custom-select-wrapper" aria-disabled="true" style="pointer-events: none;">
                                <div class="custom-select-trigger">
                                    <p class="select-value" data-memo-detail-section><?= h($selected_faction_name !== '' ? $selected_faction_name : 'เลือกส่วนราชการ') ?></p>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="custom-options">
                                    <?php foreach ($factions as $faction) : ?>
                                        <div class="custom-option<?= (string) ($faction['fID'] ?? '') === $selected_sender_fid ? ' selected' : '' ?>" data-value="<?= h((string) ($faction['fID'] ?? '')) ?>">
                                            <?= h((string) ($faction['fname'] ?? '')) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <p><strong>โรงเรียนดีบุกพังงาวิทยายน</strong></p>
                        </div>

                        <div class="form-group-row memo-subject-row">
                            <p><strong>เรื่อง</strong></p>
                            <input type="text" name="subject" value="<?= h((string) ($values['subject'] ?? '')) ?>" data-memo-detail-subject readonly required>
                        </div>

                        <div class="form-group-row memo-to-row">
                            <p><strong>เรียน</strong></p>
                            <p>ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน</p>
                        </div>

                        <div class="content-editor">
                            <p><strong>รายละเอียด:</strong></p>
                            <br>
                            <textarea name="detail" id="memo_detail_editor" data-memo-detail-body readonly><?= h((string) ($values['detail'] ?? '')) ?></textarea>
                        </div>

                        <div data-memo-detail-signature-block style="display: none;">
                            <div class="form-group-row signature">
                                <img src="" alt="" data-memo-detail-signature-image style="display: none;">
                                <p data-memo-detail-signature-name>(<?= h($current_name !== '' ? $current_name : '-') ?>)</p>
                                <p data-memo-detail-signature-position><?= h($current_position !== '' ? $current_position : '-') ?></p>
                            </div>
                            <br><br><br>
                        </div>
                        <br><br><br>
                        <?php foreach (
                            [
                                'HEAD' => 'ความคิดเห็นและข้อเสนอแนะของหัวหน้ากลุ่มสาระการเรียนรู้',
                                'DEPUTY' => 'ความคิดเห็นและข้อเสนอแนะของรองผู้อำนวยการ',
                                'DIRECTOR' => 'ความคิดเห็นและข้อเสนอแนะของผู้อำนวยการโรงเรียน',
                            ] as $stage_key => $stage_label
                        ) : ?>
                            <div class="content-editor secondary" data-memo-stage-section="<?= h($stage_key) ?>" style="display: none;">
                                <p><strong data-memo-stage-label="<?= h($stage_key) ?>"><?= h($stage_label) ?></strong></p>
                                <br>
                                <textarea name="modal_<?= strtolower($stage_key) ?>_note" id="memo_detail_<?= h($stage_key) ?>" data-memo-stage-note="<?= h($stage_key) ?>" rows="7" readonly></textarea>
                            </div>

                            <div data-memo-stage-signature-block="<?= h($stage_key) ?>" style="display: none;">
                                <div class="form-group-row signature secondary" data-memo-stage-signature="<?= h($stage_key) ?>" style="display: none;">
                                    <img src="" alt="" data-memo-stage-signature-image="<?= h($stage_key) ?>" style="display: none;">
                                    <p data-memo-stage-signature-name="<?= h($stage_key) ?>">(<?= h($current_name !== '' ? $current_name : '-') ?>)</p>
                                    <p data-memo-stage-signature-position="<?= h($stage_key) ?>"><?= h($current_position !== '' ? $current_position : '-') ?></p>
                                </div>
                                <br><br><br>
                            </div>
                        <?php endforeach; ?>

                        <div class="form-group-row" data-memo-review-action-row style="display: none;">
                            <p><strong data-memo-action-label>เสนอ :</strong></p>

                            <div class="custom-select-wrapper" data-memo-action-wrapper data-custom-select-manual="1">
                                <div class="custom-select-trigger">
                                    <p class="select-value" data-memo-action-value>เลือกการดำเนินการ</p>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="custom-options" data-memo-action-options></div>
                            </div>
                        </div>

                        <div class="form-group-row" data-memo-review-comment-row style="display: none;">
                            <p><strong>ความคิดเห็น :</strong></p>

                            <div class="custom-select-wrapper" data-memo-comment-wrapper data-custom-select-manual="1">
                                <div class="custom-select-trigger">
                                    <p class="select-value" data-memo-comment-value>เลือกความคิดเห็น</p>
                                    <i class="fa-solid fa-chevron-down"></i>
                                </div>

                                <div class="custom-options" data-memo-comment-options></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer-modal">
                <form method="POST" id="modalArchiveForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="memo_id" id="modalMemoId" value="">
                    <input type="hidden" name="action" id="modalMemoAction" value="">
                    <input type="hidden" name="target_pid" id="modalMemoTargetPid" value="">
                    <input type="hidden" name="note" id="modalMemoNote" value="">
                    <button type="submit" style="width: auto;">
                        <p>เสนอแฟ้ม</p>
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<div class="button-circular-notice-index">
    <button
        class="button-keep"
        type="submit"
        form="bulkActionForm"
        data-confirm="ต้องการจัดเก็บบันทึกข้อความที่เลือกหรือไม่"
        data-confirm-title="ยืนยันการจัดเก็บ"
        data-confirm-ok="ยืนยัน"
        data-confirm-cancel="ยกเลิก">
        <i class="fa-solid fa-file-import"></i>
        <p>จัดเก็บ</p>
    </button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#memo_detail_editor, #memo_detail_HEAD, #memo_detail_DEPUTY, #memo_detail_DIRECTOR',
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
        setup: function(editor) {
            if (editor.id === 'memo_detail_editor') {
                return;
            }

            const syncEditorValue = function() {
                const field = document.getElementById(editor.id);

                if (!field) {
                    return;
                }

                const content = String(editor.getContent() || '');
                field.value = content;

                if (typeof currentEditableReviewField !== 'undefined' &&
                    typeof modalMemoNoteInput !== 'undefined' &&
                    currentEditableReviewField &&
                    modalMemoNoteInput &&
                    field === currentEditableReviewField) {
                    modalMemoNoteInput.value = content;
                }
            };

            editor.on('change input keyup undo redo blur SetContent', syncEditorValue);
        }
    });

    const memoInboxFilterForm = document.getElementById('circularFilterForm');

    if (memoInboxFilterForm) {
        const submitMemoInboxFilter = () => {
            const pageInput = memoInboxFilterForm.querySelector('input[name="page"]');

            if (pageInput) {
                pageInput.value = '1';
            }

            if (typeof memoInboxFilterForm.requestSubmit === 'function') {
                memoInboxFilterForm.requestSubmit();
                return;
            }

            memoInboxFilterForm.submit();
        };

        document.querySelectorAll('.header-circular-notice-keep .custom-select-wrapper[data-target]').forEach((wrapper) => {
            const targetId = wrapper.getAttribute('data-target') || '';
            const targetInput = targetId !== '' ? document.getElementById(targetId) : null;

            if (!targetInput) {
                return;
            }

            wrapper.querySelectorAll('.custom-option').forEach((option) => {
                option.addEventListener('click', () => {
                    const selectedValue = option.getAttribute('data-value') || '';
                    targetInput.value = selectedValue;
                    submitMemoInboxFilter();
                });
            });
        });

        document.querySelectorAll('[form="circularFilterForm"][data-auto-submit="true"]').forEach((field) => {
            let filterTimer = null;
            const delay = Number(field.getAttribute('data-auto-submit-delay') || 450);

            field.addEventListener('input', () => {
                window.clearTimeout(filterTimer);
                filterTimer = window.setTimeout(submitMemoInboxFilter, Number.isFinite(delay) ? delay : 450);
            });
        });
    }

    const editModal = document.getElementById('modalEditOverlay');
    const closeEditBtn = editModal?.querySelector('#closeModalEdit');
    const modalTypeLabel = editModal?.querySelector('#modalTypeLabel');
    const modalSectionField = editModal?.querySelector('[data-memo-detail-section]');
    const modalSubjectField = editModal?.querySelector('[data-memo-detail-subject]');
    const modalBodyField = editModal?.querySelector('[data-memo-detail-body]');
    const modalSignatureImage = editModal?.querySelector('[data-memo-detail-signature-image]');
    const modalSignatureName = editModal?.querySelector('[data-memo-detail-signature-name]');
    const modalSignaturePosition = editModal?.querySelector('[data-memo-detail-signature-position]');
    const modalSignatureBlock = editModal?.querySelector('[data-memo-detail-signature-block]');
    const modalFooterForm = document.getElementById('modalArchiveForm');
    const modalMemoIdInput = document.getElementById('modalMemoId');
    const modalMemoActionInput = document.getElementById('modalMemoAction');
    const modalMemoTargetPidInput = document.getElementById('modalMemoTargetPid');
    const modalMemoNoteInput = document.getElementById('modalMemoNote');
    const modalFooterButton = modalFooterForm?.querySelector('button');
    const modalFooterButtonLabel = modalFooterButton?.querySelector('p');
    const modalActionRow = editModal?.querySelector('[data-memo-review-action-row]');
    const modalActionLabel = editModal?.querySelector('[data-memo-action-label]');
    const modalActionWrapper = editModal?.querySelector('[data-memo-action-wrapper]');
    const modalActionValue = editModal?.querySelector('[data-memo-action-value]');
    const modalActionOptions = editModal?.querySelector('[data-memo-action-options]');
    const modalCommentRow = editModal?.querySelector('[data-memo-review-comment-row]');
    const modalCommentWrapper = editModal?.querySelector('[data-memo-comment-wrapper]');
    const modalCommentValue = editModal?.querySelector('[data-memo-comment-value]');
    const modalCommentOptions = editModal?.querySelector('[data-memo-comment-options]');
    const modalDetailContainer = editModal?.querySelector('.memo-detail');
    const defaultSignatureName = modalSignatureName?.textContent ?? '';
    const defaultSignaturePosition = modalSignaturePosition?.textContent ?? '';
    const currentPid = <?= json_encode($current_pid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const deputyCandidates = <?= $deputy_candidates_json ?>;
    const stageOrderByRole = {
        HEAD: ['HEAD'],
        DEPUTY: ['HEAD', 'DEPUTY'],
        DIRECTOR: ['HEAD', 'DEPUTY', 'DIRECTOR'],
    };
    const stageMeta = {
        HEAD: {
            prefix: 'head',
            label: 'ความคิดเห็นและข้อเสนอแนะของหัวหน้ากลุ่มสาระการเรียนรู้',
        },
        DEPUTY: {
            prefix: 'deputy',
            label: 'ความคิดเห็นและข้อเสนอแนะของรองผู้อำนวยการ',
        },
        DIRECTOR: {
            prefix: 'director',
            label: 'ความคิดเห็นและข้อเสนอแนะของผู้อำนวยการโรงเรียน',
        },
    };
    const stageKeys = ['HEAD', 'DEPUTY', 'DIRECTOR'];
    const deputyCommentTemplates = [{
            key: 'proceed_as_proposed',
            label: 'เห็นควร ดำเนินการตามเสนอ',
            content: '<p>เห็นควร ดำเนินการตามเสนอ</p>',
        },
        {
            key: 'for_consideration',
            label: 'เพื่อโปรดพิจารณา',
            content: '<p>เพื่อโปรดพิจารณา</p>',
        },
        {
            key: 'should_approve',
            label: 'เห็นควรอนุมัติ',
            content: '<p>เห็นควรอนุมัติ</p>',
        },
        {
            key: 'should_permit',
            label: 'เห็นควรอนุญาติ',
            content: '<p>เห็นควรอนุญาติ</p>',
        },
    ];
    const directorManagementActions = [{
            key: 'director_signed',
            value: 'director_signed',
            label: 'ลงนามแล้ว',
            submitLabel: 'ลงนามแล้ว',
        },
        {
            key: 'director_acknowledged',
            value: 'director_acknowledged',
            label: 'ทราบ',
            submitLabel: 'ทราบ',
        },
        {
            key: 'director_agreed',
            value: 'director_agreed',
            label: 'ชอบ',
            submitLabel: 'ชอบ',
        },
        {
            key: 'director_notified',
            value: 'director_notified',
            label: 'แจ้ง',
            submitLabel: 'แจ้ง',
        },
        {
            key: 'director_assigned',
            value: 'director_assigned',
            label: 'มอบ',
            submitLabel: 'มอบ',
        },
        {
            key: 'director_scheduled',
            value: 'director_scheduled',
            label: 'ลงนัด',
            submitLabel: 'ลงนัด',
        },
        {
            key: 'director_permitted',
            value: 'director_permitted',
            label: 'อนุญาต',
            submitLabel: 'อนุญาต',
        },
        {
            key: 'director_approved',
            value: 'director_approved',
            label: 'อนุมัติ',
            submitLabel: 'อนุมัติ',
        },
        {
            key: 'director_rejected',
            value: 'director_rejected',
            label: 'ไม่อนุมัติ',
            submitLabel: 'ไม่อนุมัติ',
        },
        {
            key: 'director_request_meeting',
            value: 'director_request_meeting',
            label: 'ขอพบ',
            submitLabel: 'ขอพบ',
        },
    ];
    const stageElements = stageKeys.reduce((accumulator, stage) => {
        accumulator[stage] = {
            section: editModal?.querySelector('[data-memo-stage-section="' + stage + '"]') || null,
            label: editModal?.querySelector('[data-memo-stage-label="' + stage + '"]') || null,
            note: editModal?.querySelector('[data-memo-stage-note="' + stage + '"]') || null,
            signature: editModal?.querySelector('[data-memo-stage-signature="' + stage + '"]') || null,
            signatureBlock: editModal?.querySelector('[data-memo-stage-signature-block="' + stage + '"]') || null,
            signatureImage: editModal?.querySelector('[data-memo-stage-signature-image="' + stage + '"]') || null,
            signatureName: editModal?.querySelector('[data-memo-stage-signature-name="' + stage + '"]') || null,
            signaturePosition: editModal?.querySelector('[data-memo-stage-signature-position="' + stage + '"]') || null,
        };

        return accumulator;
    }, {});
    let currentActionOptions = [];
    let currentCommentTemplateOptions = [];
    let currentEditableReviewField = null;
    let currentSelectedCommentTemplateKey = '';

    const closeReviewDropdowns = () => {
        if (modalActionWrapper) {
            modalActionWrapper.classList.remove('open');
        }

        if (modalCommentWrapper) {
            modalCommentWrapper.classList.remove('open');
        }

        if (modalActionRow) {
            modalActionRow.classList.remove('is-open');
        }

        if (modalCommentRow) {
            modalCommentRow.classList.remove('is-open');
        }
    };

    const openReviewDropdown = (type) => {
        closeReviewDropdowns();

        if (type === 'action' && modalActionWrapper && modalActionRow) {
            modalActionWrapper.classList.add('open');
            modalActionRow.classList.add('is-open');
            return;
        }

        if (type === 'comment' && modalCommentWrapper && modalCommentRow) {
            modalCommentWrapper.classList.add('open');
            modalCommentRow.classList.add('is-open');
        }
    };

    const normalizeEditorText = (value) => {
        return String(value || '')
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/&nbsp;/gi, ' ')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const moveReviewRowsAfterStage = (stage) => {
        if (!modalDetailContainer) {
            return;
        }

        const targetStage = stageElements[stage] || null;
        const targetSection = targetStage?.section || null;

        if (targetSection) {
            const noteField = targetStage?.note || null;
            const labelRow = targetStage?.label?.closest('p') || null;
            const insertControls = () => {
                if (modalActionRow && modalActionRow.style.display !== 'none') {
                    targetSection.insertBefore(modalActionRow, noteField);
                }

                if (modalCommentRow && modalCommentRow.style.display !== 'none') {
                    targetSection.insertBefore(modalCommentRow, noteField);
                }
            };

            if (noteField && noteField.parentNode === targetSection) {
                insertControls();
                return;
            }

            if (labelRow && labelRow.parentNode === targetSection) {
                if (modalActionRow && modalActionRow.style.display !== 'none') {
                    labelRow.insertAdjacentElement('afterend', modalActionRow);
                }

                if (modalCommentRow && modalCommentRow.style.display !== 'none') {
                    if (modalActionRow && modalActionRow.parentNode === targetSection) {
                        modalActionRow.insertAdjacentElement('afterend', modalCommentRow);
                    } else {
                        labelRow.insertAdjacentElement('afterend', modalCommentRow);
                    }
                }
                return;
            }

            if (modalActionRow && modalActionRow.style.display !== 'none') {
                targetSection.appendChild(modalActionRow);
            }

            if (modalCommentRow && modalCommentRow.style.display !== 'none') {
                targetSection.appendChild(modalCommentRow);
            }
            return;
        }

        const anchor = targetStage?.signature || null;

        if (anchor && anchor.parentNode) {
            if (modalActionRow && modalActionRow.style.display !== 'none') {
                anchor.insertAdjacentElement('afterend', modalActionRow);
            }

            if (modalCommentRow && modalCommentRow.style.display !== 'none') {
                if (modalActionRow && modalActionRow.parentNode === anchor.parentNode) {
                    modalActionRow.insertAdjacentElement('afterend', modalCommentRow);
                } else {
                    anchor.insertAdjacentElement('afterend', modalCommentRow);
                }
            }
            return;
        }

        if (modalActionRow && modalActionRow.style.display !== 'none') {
            modalDetailContainer.appendChild(modalActionRow);
        }

        if (modalCommentRow && modalCommentRow.style.display !== 'none') {
            modalDetailContainer.appendChild(modalCommentRow);
        }
    };

    const syncMemoDetailEditor = (detailValue) => {
        const detailText = typeof detailValue === 'string' ? detailValue : '';
        const editor = typeof tinymce !== 'undefined' ? tinymce.get('memo_detail_editor') : null;

        if (modalBodyField) {
            modalBodyField.value = detailText;
        }

        if (editor) {
            editor.setContent(detailText);

            if (editor.mode && typeof editor.mode.set === 'function') {
                editor.mode.set('readonly');
            }
        }
    };

    const getMemoEditor = (field) => {
        if (!field || !field.id || typeof tinymce === 'undefined') {
            return null;
        }

        return tinymce.get(field.id) || null;
    };

    const setStageEditorState = (field, value, editable) => {
        if (!field) {
            return;
        }

        const normalizedValue = typeof value === 'string' ? value : '';
        field.value = normalizedValue;
        field.readOnly = !editable;

        const editor = getMemoEditor(field);

        if (editor) {
            editor.setContent(normalizedValue);

            if (editor.mode && typeof editor.mode.set === 'function') {
                editor.mode.set(editable ? 'design' : 'readonly');
            }
        }
    };

    const readStageEditorValue = (field) => {
        if (!field) {
            return '';
        }

        const editor = getMemoEditor(field);

        if (!editor) {
            return String(field.value || '');
        }

        const content = String(editor.getContent() || '');
        field.value = content;

        return content;
    };

    const isMeaningfulEditorContent = (value) => {
        const html = String(value || '').trim();

        if (html === '') {
            return false;
        }

        const text = html
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/&nbsp;/gi, ' ')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        return text !== '';
    };

    const formatSignatureName = (value, fallbackValue = '-') => {
        const cleanValue = String(value || '').trim();
        const fallbackCleanValue = String(fallbackValue || '').replace(/^\(|\)$/g, '').trim();

        return '(' + (cleanValue || fallbackCleanValue || '-') + ')';
    };

    const formatSignaturePosition = (value) => {
        const cleanValue = String(value || '').trim();
        const normalizedValue = typeof cleanValue.normalize === 'function' ?
            cleanValue.normalize('NFC') :
            cleanValue.replace('อํานวย', 'อำนวย');

        if (normalizedValue === 'ผู้อำนวยการโรงเรียน') {
            return 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน';
        }

        return cleanValue || '-';
    };

    const applySignatureBlock = (blockElement, imageElement, signaturePath) => {
        const resolvedPath = String(signaturePath || '').trim();

        if (imageElement) {
            if (resolvedPath === '') {
                imageElement.removeAttribute('src');
                imageElement.style.display = 'none';
            } else {
                imageElement.setAttribute('src', resolvedPath);
                imageElement.style.display = '';
            }
        }

        if (blockElement) {
            blockElement.style.display = resolvedPath === '' ? 'none' : '';
        }
    };

    const hideStageSection = (stage) => {
        const elements = stageElements[stage];

        if (!elements) {
            return;
        }

        if (elements.section) {
            elements.section.style.display = 'none';
        }

        if (elements.signature) {
            elements.signature.style.display = 'none';
        }

        if (elements.signatureBlock) {
            elements.signatureBlock.style.display = 'none';
        }

        if (elements.note) {
            setStageEditorState(elements.note, '', false);
        }
    };

    const readStagePayload = (trigger, stage) => {
        const prefix = stageMeta[stage]?.prefix || '';
        const suffix = prefix.charAt(0).toUpperCase() + prefix.slice(1);

        return {
            pid: String(trigger.dataset[prefix + 'Pid'] || '').trim(),
            name: String(trigger.dataset[prefix + 'Name'] || '').trim(),
            position: String(trigger.dataset[prefix + 'Position'] || '').trim(),
            signature: String(trigger.dataset[prefix + 'Signature'] || '').trim(),
            note: String(trigger.dataset[prefix + 'Note'] || ''),
            action: String(trigger.dataset[prefix + 'Action'] || '').trim(),
            hasReviewData: Boolean(
                String(trigger.dataset[prefix + 'Action'] || '').trim() ||
                String(trigger.dataset[prefix + 'Note'] || '').trim()
            ),
            hasAnyData: Boolean(
                String(trigger.dataset[prefix + 'Pid'] || '').trim() ||
                String(trigger.dataset[prefix + 'Name'] || '').trim() ||
                String(trigger.dataset[prefix + 'Position'] || '').trim() ||
                String(trigger.dataset[prefix + 'Signature'] || '').trim() ||
                String(trigger.dataset[prefix + 'Action'] || '').trim() ||
                String(trigger.dataset[prefix + 'Note'] || '').trim()
            ),
        };
    };

    const renderStageSection = (stage, payload, editable) => {
        const elements = stageElements[stage];

        if (!elements) {
            return;
        }

        if (elements.label) {
            elements.label.textContent = stageMeta[stage]?.label || 'ความคิดเห็นและข้อเสนอแนะ';
        }

        setStageEditorState(elements.note, payload.note || '', editable);

        applySignatureBlock(elements.signatureBlock, elements.signatureImage, payload.signature);

        if (elements.signatureName) {
            elements.signatureName.textContent = formatSignatureName(payload.name, '-');
        }

        if (elements.signaturePosition) {
            elements.signaturePosition.textContent = formatSignaturePosition(payload.position || '');
        }

        if (elements.section) {
            elements.section.style.display = '';
        }

        if (elements.signature && String(payload.signature || '').trim() !== '') {
            elements.signature.style.display = '';
        }
    };

    const buildActionOptions = (reviewerRole, trigger) => {
        const flowMode = String(trigger.dataset.flowMode || '').trim().toUpperCase() || 'CHAIN';
        const deputyName = String(trigger.dataset.deputyName || '').trim();
        const deputyPid = String(trigger.dataset.deputyPid || '').trim();
        const directorName = String(trigger.dataset.directorName || '').trim();
        const directorPid = String(trigger.dataset.directorPid || '').trim();
        const deputyForwardOptions = deputyCandidates.length > 0 ?
            deputyCandidates.map((candidate) => ({
                key: 'forward:' + candidate.pID,
                value: 'forward',
                label: candidate.name,
                submitLabel: 'เสนอแฟ้ม',
                targetPid: candidate.pID,
            })) : [{
                key: 'forward:' + (deputyPid || directorPid || 'fallback'),
                value: 'forward',
                label: deputyName || directorName || 'รองผู้อำนวยการ',
                submitLabel: 'เสนอแฟ้ม',
                targetPid: deputyPid || '',
            }];

        if (reviewerRole === 'HEAD') {
            return deputyForwardOptions;
        }

        if (reviewerRole === 'DEPUTY') {
            return [{
                    key: 'return',
                    value: 'return',
                    label: 'ตีกลับไปแก้ไข',
                    submitLabel: 'ตีกลับไปแก้ไข',
                },
                {
                    key: 'approve_unsigned',
                    value: 'approve_unsigned',
                    label: 'ลงนาม(ป)',
                    submitLabel: 'ลงนาม(ป)',
                },
                {
                    key: 'forward:' + (directorPid || 'director'),
                    value: 'forward',
                    label: 'เสนอผู้อำนวยการ',
                    submitLabel: 'เสนอผู้อำนวยการ',
                    targetPid: directorPid || '',
                },
            ];
        }

        if (reviewerRole === 'DIRECTOR') {
            return directorManagementActions;
        }

        if (flowMode === 'DIRECT') {
            return [{
                    key: 'approve_unsigned',
                    value: 'approve_unsigned',
                    label: 'ลงนามแล้ว',
                    submitLabel: 'ลงนามแล้ว',
                },
                {
                    key: 'reject',
                    value: 'reject',
                    label: 'ไม่อนุมัติ',
                    submitLabel: 'ไม่อนุมัติ',
                },
                {
                    key: 'return',
                    value: 'return',
                    label: 'ตีกลับแก้ไข',
                    submitLabel: 'ตีกลับแก้ไข',
                },
            ];
        }

        return [];
    };

    const buildCommentTemplateOptions = (reviewerRole) => reviewerRole === 'DEPUTY' ? deputyCommentTemplates : [];

    const applySelectedAction = (value) => {
        const selected = currentActionOptions.find((option) => option.key === value) || currentActionOptions[0] || null;

        if (!selected) {
            if (modalMemoActionInput) {
                modalMemoActionInput.value = '';
            }

            if (modalMemoTargetPidInput) {
                modalMemoTargetPidInput.value = '';
            }

            if (modalActionValue) {
                modalActionValue.textContent = 'เลือกการดำเนินการ';
            }

            return;
        }

        if (modalMemoActionInput) {
            modalMemoActionInput.value = selected.value;
        }

        if (modalMemoTargetPidInput) {
            modalMemoTargetPidInput.value = selected.targetPid || '';
        }

        if (modalActionValue) {
            modalActionValue.textContent = selected.label;
        }

        if (modalFooterButtonLabel) {
            modalFooterButtonLabel.textContent = selected.submitLabel || selected.label;
        }

        modalActionOptions?.querySelectorAll('.custom-option').forEach((option) => {
            option.classList.toggle('selected', option.getAttribute('data-memo-action-option') === selected.key);
        });
    };

    const applySelectedCommentTemplate = (value, syncEditor = true) => {
        const selected = currentCommentTemplateOptions.find((option) => option.key === value) || null;
        currentSelectedCommentTemplateKey = selected ? selected.key : '';

        if (modalCommentValue) {
            modalCommentValue.textContent = selected ? selected.label : 'เลือกความคิดเห็น';
        }

        modalCommentOptions?.querySelectorAll('.custom-option').forEach((option) => {
            option.classList.toggle('selected', option.getAttribute('data-memo-comment-option') === currentSelectedCommentTemplateKey);
        });

        if (!selected || !syncEditor || !currentEditableReviewField) {
            return;
        }

        setStageEditorState(currentEditableReviewField, selected.content, true);

        if (modalMemoNoteInput) {
            modalMemoNoteInput.value = readStageEditorValue(currentEditableReviewField);
        }
    };

    const resolveSavedActionKey = (reviewerRole, trigger) => {
        const savedAction = String(
            reviewerRole === 'HEAD' ? trigger.dataset.headAction || '' :
            reviewerRole === 'DEPUTY' ? trigger.dataset.deputyAction || '' :
            reviewerRole === 'DIRECTOR' ? trigger.dataset.directorAction || '' :
            ''
        ).trim().toUpperCase();

        if (reviewerRole === 'DEPUTY') {
            if (savedAction === 'FORWARD') {
                const directorPid = String(trigger.dataset.directorPid || '').trim();
                return directorPid !== '' ? 'forward:' + directorPid : 'forward:director';
            }

            if (savedAction === 'APPROVE_UNSIGNED') {
                return 'approve_unsigned';
            }

            if (savedAction === 'RETURN') {
                return 'return';
            }
        }

        if (reviewerRole === 'DIRECTOR') {
            const directorActionMap = {
                DIRECTOR_APPROVE: 'director_approved',
                DIRECTOR_REJECT: 'director_rejected',
                DIRECTOR_SIGNED: 'director_signed',
                DIRECTOR_ACKNOWLEDGED: 'director_acknowledged',
                DIRECTOR_AGREED: 'director_agreed',
                DIRECTOR_NOTIFIED: 'director_notified',
                DIRECTOR_ASSIGNED: 'director_assigned',
                DIRECTOR_SCHEDULED: 'director_scheduled',
                DIRECTOR_PERMITTED: 'director_permitted',
                DIRECTOR_APPROVED: 'director_approved',
                DIRECTOR_REJECTED: 'director_rejected',
                DIRECTOR_REQUEST_MEETING: 'director_request_meeting',
            };

            if (directorActionMap[savedAction]) {
                return directorActionMap[savedAction];
            }

            if (savedAction === 'RETURN') {
                return 'return';
            }
        }

        if (reviewerRole === 'HEAD') {
            const savedHeadAction = String(trigger.dataset.headAction || '').trim().toUpperCase();

            if (savedHeadAction !== 'FORWARD') {
                return '';
            }

            const deputyPid = String(trigger.dataset.deputyPid || '').trim();

            if (deputyPid !== '') {
                return 'forward:' + deputyPid;
            }
        }

        return '';
    };

    const resolveDisplayActionRole = (reviewerRole, reviewedStageSequence) => {
        if (reviewerRole !== '') {
            return reviewerRole;
        }

        for (let index = reviewedStageSequence.length - 1; index >= 0; index -= 1) {
            const stage = reviewedStageSequence[index];

            if (['HEAD', 'DEPUTY', 'DIRECTOR'].includes(stage)) {
                return stage;
            }
        }

        return '';
    };

    const resolveSavedCommentTemplateKey = (reviewerRole, trigger) => {
        if (reviewerRole !== 'DEPUTY') {
            return '';
        }

        const deputyNote = normalizeEditorText(trigger.dataset.deputyNote || '');

        if (deputyNote === '') {
            return '';
        }

        const matched = deputyCommentTemplates.find((template) => deputyNote.indexOf(normalizeEditorText(template.content)) === 0);

        return matched ? matched.key : '';
    };

    const confirmMemoSubmit = (submitLabel) => {
        const title = 'ยืนยันการเสนอแฟ้ม';
        const message = 'ยืนยันการดำเนินการ "' + String(submitLabel || 'เสนอแฟ้ม') + '" ใช่หรือไม่?';

        if (window.AppAlerts && typeof window.AppAlerts.confirm === 'function') {
            return window.AppAlerts.confirm(message, {
                title: title,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
            });
        }

        return Promise.resolve(window.confirm(title + '\n' + message));
    };

    const resetReviewState = () => {
        currentEditableReviewField = null;
        currentActionOptions = [];

        stageKeys.forEach((stage) => {
            hideStageSection(stage);
        });

        if (modalActionRow) {
            modalActionRow.style.display = 'none';
        }

        if (modalCommentRow) {
            modalCommentRow.style.display = 'none';
        }

        if (modalActionWrapper) {
            modalActionWrapper.classList.remove('open');
            modalActionWrapper.classList.remove('is-disabled');
            modalActionWrapper.style.pointerEvents = 'auto';
        }

        if (modalActionOptions) {
            modalActionOptions.innerHTML = '';
        }

        if (modalActionValue) {
            modalActionValue.textContent = 'เลือกการดำเนินการ';
        }

        if (modalActionLabel) {
            modalActionLabel.textContent = 'เสนอ :';
        }

        if (modalCommentWrapper) {
            modalCommentWrapper.classList.remove('open');
            modalCommentWrapper.classList.remove('is-disabled');
            modalCommentWrapper.style.pointerEvents = 'auto';
        }

        if (modalActionRow) {
            modalActionRow.classList.remove('is-open');
        }

        if (modalCommentRow) {
            modalCommentRow.classList.remove('is-open');
        }

        if (modalCommentOptions) {
            modalCommentOptions.innerHTML = '';
        }

        if (modalCommentValue) {
            modalCommentValue.textContent = 'เลือกความคิดเห็น';
        }

        if (modalMemoActionInput) {
            modalMemoActionInput.value = '';
        }

        if (modalMemoTargetPidInput) {
            modalMemoTargetPidInput.value = '';
        }

        if (modalMemoNoteInput) {
            modalMemoNoteInput.value = '';
        }

        if (modalFooterButton) {
            modalFooterButton.style.display = 'none';
        }

        if (modalFooterButtonLabel) {
            modalFooterButtonLabel.textContent = 'เสนอแฟ้ม';
        }

        if (modalFooterForm) {
            delete modalFooterForm.dataset.confirmedSubmit;
        }

        if (modalActionRow && modalDetailContainer) {
            modalDetailContainer.appendChild(modalActionRow);
        }

        if (modalCommentRow && modalDetailContainer) {
            modalDetailContainer.appendChild(modalCommentRow);
        }

        currentCommentTemplateOptions = [];
        currentSelectedCommentTemplateKey = '';
    };

    const closeEditModal = () => {
        if (!editModal) {
            return;
        }

        resetReviewState();
        editModal.style.display = 'none';
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('.js-open-edit-modal');

        if (!trigger || !editModal) {
            return;
        }

        event.preventDefault();

        if (modalTypeLabel) {
            modalTypeLabel.textContent = 'รายละเอียดบันทึกข้อความ';
        }

        if (modalSectionField) {
            modalSectionField.textContent = trigger.dataset.section || 'กลุ่ม';
        }

        if (modalSubjectField) {
            modalSubjectField.value = trigger.dataset.subject || '';
            modalSubjectField.readOnly = true;
        }

        syncMemoDetailEditor(trigger.dataset.detail || '');

        applySignatureBlock(modalSignatureBlock, modalSignatureImage, trigger.dataset.signature || '');

        if (modalSignatureName) {
            modalSignatureName.textContent = '(' + (trigger.dataset.name || defaultSignatureName.replace(/^\(|\)$/g, '') || '-') + ')';
        }

        if (modalSignaturePosition) {
            modalSignaturePosition.textContent = formatSignaturePosition(trigger.dataset.position || defaultSignaturePosition || '');
        }

        resetReviewState();

        const reviewerRole = String(trigger.dataset.reviewerRole || '').toUpperCase();
        const flowStage = String(trigger.dataset.flowStage || '').toUpperCase();
        const currentToPid = String(trigger.dataset.toPid || '').trim();
        const isCurrentReviewer = reviewerRole !== '' && currentToPid !== '' && currentToPid === currentPid && flowStage === reviewerRole;
        const reviewedStageSequence = stageKeys.filter((stage) => readStagePayload(trigger, stage).hasReviewData);
        const activeStageSequence = stageOrderByRole[reviewerRole] || [];
        const stageSequence = Array.from(new Set([...reviewedStageSequence, ...activeStageSequence]));

        if (modalMemoIdInput) {
            modalMemoIdInput.value = trigger.dataset.memoId || '';
        }

        stageSequence.forEach((stage) => {
            const payload = readStagePayload(trigger, stage);
            const shouldShow = payload.hasReviewData || (stage === reviewerRole && payload.hasAnyData);

            if (!shouldShow) {
                return;
            }

            const isEditable = stage === reviewerRole && isCurrentReviewer;
            renderStageSection(stage, payload, isEditable);

            if (isEditable) {
                currentEditableReviewField = stageElements[stage]?.note || null;
            }
        });

        const actionRole = resolveDisplayActionRole(reviewerRole, reviewedStageSequence);
        const isActionEditable = actionRole === reviewerRole && isCurrentReviewer;
        currentActionOptions = buildActionOptions(actionRole, trigger);
        currentCommentTemplateOptions = buildCommentTemplateOptions(reviewerRole);

        if (modalActionLabel) {
            modalActionLabel.textContent = actionRole === 'DIRECTOR' ? 'ผู้บริหารดำเนินการต่อ :' : 'เสนอ :';
        }

        const savedActionKey = resolveSavedActionKey(actionRole, trigger);
        const hasSavedAction = savedActionKey !== '' && currentActionOptions.some((option) => option.key === savedActionKey);
        const savedCommentTemplateKey = resolveSavedCommentTemplateKey(reviewerRole, trigger);
        const hasSavedCommentTemplate = savedCommentTemplateKey !== '' && currentCommentTemplateOptions.some((option) => option.key === savedCommentTemplateKey);
        const hasDeputyNote = normalizeEditorText(trigger.dataset.deputyNote || '') !== '';

        if (currentActionOptions.length > 0 && (isActionEditable || hasSavedAction)) {
            if (modalActionRow) {
                modalActionRow.style.display = '';
            }

            if (modalActionOptions) {
                modalActionOptions.innerHTML = currentActionOptions.map((option, index) => (
                    '<div class="custom-option' + (hasSavedAction ? (option.key === savedActionKey ? ' selected' : '') : (index === 0 ? ' selected' : '')) + '" data-memo-action-option="' + option.key + '">' +
                    option.label +
                    '</div>'
                )).join('');
            }

            applySelectedAction(hasSavedAction ? savedActionKey : currentActionOptions[0].key);

            if (modalActionWrapper) {
                modalActionWrapper.classList.toggle('is-disabled', !isActionEditable);
                modalActionWrapper.style.pointerEvents = isActionEditable ? 'auto' : 'none';
            }

            if (modalFooterButton) {
                modalFooterButton.style.display = isActionEditable ? '' : 'none';
            }
        }

        if (reviewerRole === 'DEPUTY' && currentCommentTemplateOptions.length > 0 && (isCurrentReviewer || hasSavedCommentTemplate || hasDeputyNote)) {
            if (modalCommentRow) {
                modalCommentRow.style.display = '';
            }

            if (modalCommentOptions) {
                modalCommentOptions.innerHTML = currentCommentTemplateOptions.map((option, index) => (
                    '<div class="custom-option' + (hasSavedCommentTemplate ? (option.key === savedCommentTemplateKey ? ' selected' : '') : (index === 0 && !hasDeputyNote ? ' selected' : '')) + '" data-memo-comment-option="' + option.key + '">' +
                    option.label +
                    '</div>'
                )).join('');
            }

            applySelectedCommentTemplate(hasSavedCommentTemplate ? savedCommentTemplateKey : '', false);

            if (modalCommentWrapper) {
                modalCommentWrapper.classList.toggle('is-disabled', !isCurrentReviewer);
                modalCommentWrapper.style.pointerEvents = isCurrentReviewer ? 'auto' : 'none';
            }
        }

        if ((modalActionRow && modalActionRow.style.display !== 'none') || (modalCommentRow && modalCommentRow.style.display !== 'none')) {
            moveReviewRowsAfterStage(modalActionRow && modalActionRow.style.display !== 'none' ? actionRole : reviewerRole);
        }

        editModal.style.display = 'flex';
    });

    modalActionWrapper?.querySelector('.custom-select-trigger')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (modalActionWrapper.classList.contains('open')) {
            closeReviewDropdowns();
            return;
        }

        openReviewDropdown('action');
    });

    modalActionOptions?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-memo-action-option]');

        if (!option) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        applySelectedAction(option.getAttribute('data-memo-action-option') || '');
        closeReviewDropdowns();
    });

    modalCommentWrapper?.querySelector('.custom-select-trigger')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (modalCommentWrapper.classList.contains('open')) {
            closeReviewDropdowns();
            return;
        }

        openReviewDropdown('comment');
    });

    modalCommentOptions?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-memo-comment-option]');

        if (!option) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        applySelectedCommentTemplate(option.getAttribute('data-memo-comment-option') || '', true);
        closeReviewDropdowns();
    });

    modalFooterForm?.addEventListener('submit', (event) => {
        if (modalFooterForm.dataset.confirmedSubmit === '1') {
            delete modalFooterForm.dataset.confirmedSubmit;
            return;
        }

        const currentAction = String(modalMemoActionInput?.value || '').trim();
        const noteValue = readStageEditorValue(currentEditableReviewField);
        const hasMeaningfulNote = isMeaningfulEditorContent(noteValue);

        if (modalMemoNoteInput) {
            modalMemoNoteInput.value = noteValue;
        }

        if ((currentAction === 'return' || currentAction === 'director_reject' || currentAction === 'director_rejected' || currentAction === 'reject') && !hasMeaningfulNote) {
            event.preventDefault();
            window.alert('กรุณากรอกความเห็น');
            const editor = getMemoEditor(currentEditableReviewField);

            if (editor && typeof editor.focus === 'function') {
                editor.focus();
            } else {
                currentEditableReviewField?.focus();
            }
            return;
        }

        event.preventDefault();

        confirmMemoSubmit(modalFooterButtonLabel?.textContent || 'เสนอแฟ้ม').then((approved) => {
            if (!approved) {
                return;
            }

            modalFooterForm.dataset.confirmedSubmit = '1';

            if (typeof modalFooterForm.requestSubmit === 'function') {
                modalFooterForm.requestSubmit();
                return;
            }

            modalFooterForm.submit();
        });
    });

    closeEditBtn?.addEventListener('click', closeEditModal);

    window.addEventListener('click', (event) => {
        if (event.target === editModal) {
            closeEditModal();
        }

        if (
            modalActionWrapper &&
            modalCommentWrapper &&
            !modalActionWrapper.contains(event.target) &&
            !modalCommentWrapper.contains(event.target)
        ) {
            closeReviewDropdowns();
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
