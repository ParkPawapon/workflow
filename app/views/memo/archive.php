<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../modules/memos/status.php';

$items = (array) ($items ?? []);
$page = (int) ($page ?? 1);
$total_pages = (int) ($total_pages ?? 1);
$search = trim((string) ($search ?? ''));
$status_filter = (string) ($status_filter ?? 'all');
$filtered_total = (int) ($filtered_total ?? 0);
$pagination_base_url = (string) ($pagination_base_url ?? 'memo-archive.php');
$dh_year_options = array_values(array_filter(array_map('intval', (array) ($dh_year_options ?? [])), static function (int $year): bool {
    return $year > 0;
}));
$selected_dh_year = (int) ($selected_dh_year ?? ($dh_year_options[0] ?? 0));
$dh_year_label = $selected_dh_year > 0 ? (string) $selected_dh_year : '-';
$filter_search = (string) ($filter_search ?? $search);
$archived = (bool) ($archived ?? true);

$memo_page_my = 'memo.php';
$memo_page_inbox = 'memo-inbox.php';
$memo_page_archive = 'memo-archive.php';
$memo_page_view = 'memo-view.php';

$status_options = [];

foreach (memo_status_options() as $status_value => $status_label) {
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

$rows = [];

foreach ($items as $item) {
    $memo_id = (int) ($item['memoID'] ?? 0);
    $memo_no = trim((string) ($item['memoNo'] ?? ''));
    $status = (string) ($item['status'] ?? '');
    $status_meta = memo_status_meta($status);
    $approver = trim((string) ($item['approverName'] ?? ''));
    $approver = $approver !== '' ? $approver : '-';
    $view_href = $memo_page_view;
    $view_href .= (strpos($view_href, '?') === false ? '?' : '&') . 'memo_id=' . $memo_id;

    $rows[] = [
        $memo_no !== '' ? $memo_no : ('#' . $memo_id),
        [
            'link' => [
                'href' => $view_href,
                'label' => (string) ($item['subject'] ?? ''),
            ],
        ],
        $approver,
        [
            'component' => [
                'name' => 'badge',
                'params' => [
                    'label' => $status_meta['label'],
                    'variant' => $status_meta['badge_variant'],
                ],
            ],
        ],
        (string) ($item['createdAt'] ?? ''),
        [
            'form' => [
                'method' => 'post',
                'action' => $memo_page_archive,
                'hidden' => [
                    'action' => 'unarchive',
                    'memo_id' => $memo_id,
                ],
                'button' => [
                    'label' => 'นำออก',
                    'variant' => 'secondary',
                    'type' => 'submit',
                ],
            ],
        ],
    ];
}

$values = $values ?? ['writeDate' => '', 'subject' => '', 'detail' => ''];
$current_user = (array) ($current_user ?? []);
$factions = (array) ($factions ?? []);

$selected_sender_fid = trim((string) ($values['sender_fid'] ?? ''));

if ($selected_sender_fid === '' && !empty($factions)) {
    $selected_sender_fid = (string) ($factions[0]['fID'] ?? '');
}

$signature_src = trim((string) ($current_user['signature'] ?? ''));
$current_name = trim((string) ($current_user['fName'] ?? ''));
$current_position = trim((string) ($current_user['position_name'] ?? ''));


ob_start();
?>

<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$items = (array) ($items ?? []);
$box_key = (string) ($box_key ?? 'normal');
$filter_type = (string) ($filter_type ?? 'all');
$filter_read = (string) ($filter_read ?? 'all');
$filter_sort = (string) ($filter_sort ?? 'newest');
$filter_view = (string) ($filter_view ?? 'table1');
$filter_search = (string) ($filter_search ?? '');
$is_outside_view = (bool) ($is_outside_view ?? false);
$director_label = (string) ($director_label ?? 'ผอ./รักษาการ');

$type_external_checked = $filter_type === 'external' || $filter_type === 'all';
$type_internal_checked = $filter_type === 'internal' || $filter_type === 'all';
$read_checked = $filter_read === 'read' || $filter_read === 'all';
$unread_checked = $filter_read === 'unread' || $filter_read === 'all';

ob_start();
?>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>บันทึกข้อความ / บันทึกข้อความที่จัดเก็บ</p>
</div>

<form id="circularFilterForm" method="GET">
    <input type="hidden" name="box" value="<?= h($box_key) ?>">
    <input type="hidden" name="archived" value="1">
    <input type="hidden" name="dh_year" id="filterYearInput" value="<?= h((string) $selected_dh_year) ?>">
    <input type="hidden" name="status" id="filterStatusInput" value="<?= h($filter_status) ?>">
    <input type="hidden" name="sort" id="filterSortInput" value="<?= h($filter_sort) ?>">
    <input type="hidden" name="view" id="filterViewInput" value="<?= h($filter_view) ?>">
</form>
<input type="hidden" id="csrfToken" value="<?= h(csrf_token()) ?>">

<?php if (!$is_outside_view) : ?>
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
                            <div class="custom-option<?= h($filter_status === (string) $status_value ? ' selected' : '') ?>" data-value="<?= h((string) $status_value) ?>"><?= h((string) $status_label) ?></div>
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
    </header>

    <section
        class="content-circular-notice-index"
        data-circular-notice
        data-ajax-filter="true"
        data-ajax-target=".table-circular-notice-index">
        <div class="search-bar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="search-input" name="q" form="circularFilterForm" value="<?= h($filter_search) ?>" placeholder="ค้นหาข้อความด้วย..." data-auto-submit="true" data-auto-submit-delay="450">
            </div>
        </div>

        <?php if (!$is_outside_view) : ?>
            <form id="bulkActionForm" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= h($archived ? 'unarchive_selected' : 'archive_selected') ?>">
                <div class="table-circular-notice-index memo-archive-table">
                    <table>
                        <thead>
                            <tr>
                                <th>เรื่อง</th>
                                <th>ผู้เสนอแฟ้ม</th>
                                <th>วันที่เสนอแฟ้ม</th>
                                <th>สถานะ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <!-- <tbody> -->
                        <!-- <?php if (empty($items)) : ?>
                            <tr>
                                <td colspan="7" class="enterprise-empty">ไม่มีรายการ</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($items as $item) : ?>
                                <?php
                                        $is_read = (bool) ($item['is_read'] ?? false);
                                        $file_json = (string) ($item['files_json'] ?? '[]');
                                        $sender_modal_text = trim((string) ($item['sender_name'] ?? '-'));

                                        if (!empty($item['sender_faction_name'])) {
                                            $sender_modal_text .= "\n" . trim((string) $item['sender_faction_name']);
                                        }
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="check-table" name="selected_ids[]" value="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>">
                                    </td>
                                    <td><?= h((string) ($item['type_label'] ?? '')) ?></td>
                                    <td><?= h((string) ($item['subject'] ?? '')) ?></td>
                                    <td>
                                        <div class="circular-sender-stack">
                                            <span class="circular-sender-name"><?= h((string) ($item['sender_name'] ?? '-')) ?></span>
                                            <?php if (!empty($item['sender_faction_name'])) : ?>
                                                <span class="circular-sender-faction"><?= h((string) $item['sender_faction_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= h((string) ($item['delivered_date'] ?? '-')) ?></td>
                                    <td><span class="status-badge <?= h($is_read ? 'read' : 'unread') ?>"><?= h($is_read ? 'อ่านแล้ว' : 'ยังไม่อ่าน') ?></span></td>
                                    <td>
                                        <div class="circular-action-stack">
                                            <button
                                                class="booking-action-btn secondary js-open-circular-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                data-sender="<?= h($sender_modal_text) ?>"
                                                data-date="<?= h((string) ($item['delivered_date_long'] ?? $item['delivered_date'] ?? '-')) ?>"
                                                data-detail="<?= h((string) ($item['detail'] ?? '')) ?>"
                                                data-link="<?= h((string) ($item['link_url'] ?? '')) ?>"
                                                data-type="<?= h((string) ($item['type_label'] ?? '')) ?>"
                                                data-files="<?= h($file_json) ?>">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูรายละเอียด</span>
                                            </button>
                                            <a class="booking-action-btn secondary" href="circular-view.php?inbox_id=<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>">
                                                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                                <span class="tooltip">ส่งต่อ</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?> -->
                        <!-- </tbody> -->
                        <tbody>
                            <?php if (empty($items)) : ?>
                                <tr>
                                    <td colspan="5" class="enterprise-empty">ไม่มีรายการ</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($items as $item) : ?>
                                    <?php
                                    $memo_id = (int) ($item['memoID'] ?? 0);
                                    $subject = trim((string) ($item['subject'] ?? ''));
                                    $approver_name = trim((string) ($item['approverName'] ?? ''));
                                    $status = (string) ($item['status'] ?? '');
                                    $status_meta = memo_status_meta_for_record($item);
                                    $status_class = (string) ($status_meta['pill_variant'] ?? 'pending');
                                    $submitted_at = trim((string) ($item['submittedAt'] ?? ''));
                                    $created_at = trim((string) ($item['createdAt'] ?? ''));
                                    [$date_line, $time_line] = $format_thai_datetime($submitted_at !== '' ? $submitted_at : $created_at);
                                    $view_href = $memo_page_view . '?memo_id=' . $memo_id;
                                    ?>
                                    <tr>
                                        <td><?= h($subject !== '' ? $subject : '-') ?></td>
                                        <td>
                                            <div class="circular-sender-stack">
                                                <span class="circular-sender-name"><?= h($approver_name !== '' ? $approver_name : '-') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <p><?= h($date_line) ?></p>
                                            <p class="detail-subtext"><?= h($time_line) ?></p>
                                        </td>
                                        <td><span class="status-pill <?= h($status_class) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span></td>
                                        <td>
                                            <a class="booking-action-btn secondary" href="<?= h($view_href) ?>">
                                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                <span class="tooltip">ดูรายละเอียด</span>
                                            </a>
                                        </td>
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
                        <p id="modalTypeLabel">รายละเอียด</p>
                        <i class="fa-solid fa-xmark" id="closeModalEdit" aria-hidden="true"></i>
                    </div>

                    <div class="content-modal">

                        <div class="content-memo" style="box-shadow: none;">
                            <div class="memo-header">
                                <img src="assets/img/garuda-logo.png" alt="">
                                <p>บันทึกข้อความ</p>
                                <div></div>
                            </div>

                            <form method="POST" id="circularComposeForm">
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
                                                <!-- <?php foreach ($factions as $faction) : ?>
                                                <?php $fid = (string) ($faction['fID'] ?? ''); ?>
                                                <div class="custom-option<?= $fid === $selected_sender_fid ? ' selected' : '' ?>" data-value="<?= h($fid) ?>">
                                                    <?= h((string) ($faction['fname'] ?? '')) ?>
                                                </div>
                                            <?php endforeach; ?> -->
                                                <div class="custom-option">กลุ่ม</div>
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
                                        <p>ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน</p>
                                    </div>

                                    <div class="content-editor">
                                        <p><strong>รายละเอียด:</strong></p>
                                        <br>
                                        <textarea name="detail" id="memo_editor"><?= h((string) ($values['detail'] ?? '')) ?></textarea>
                                    </div>

                                    <div class="form-group-row signature">
                                        <!-- <img src="<?= h($signature_src) ?>" alt="">
                                    <p>(<?= h($current_name !== '' ? $current_name : '-') ?>)</p>
                                    <p><?= h($current_position !== '' ? $current_position : '-') ?></p> -->
                                        <img src="assets/img/signature/1829900159722/signature_20260211_170950_6f853801016c.png" alt="">
                                        <p>(นางสาวกนกรัตน์ บุญถาวร)</p>
                                        <p>เจ้าหน้าที่</p>
                                    </div>

                                    <!-- <div class="form-group-row submit">
                            <button type="submit">บันทึกเอกสาร</button>
                        </div> -->
                                </div>
                            </form>
                        </div>

                    </div>

                    <div class="footer-modal">
                        <form method="POST" id="modalArchiveForm">
                            <input type="hidden" name="csrf_token" value="3ece51cef25df8dcbb025b7f59af78f9d7fa9c90963b44be41d39e6d5152a6ac"> <input type="hidden" name="inbox_id" id="modalInboxId" value="10">
                            <input type="hidden" name="action" value="archive">
                            <button type="submit">
                                <p>เสนอแฟ้ม</p>
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        <?php else : ?>
            <!-- <div class="table-circular-notice-index outside-person">
                <table>
                    <thead>
                        <tr>
                            <th>วันที่รับ</th>
                            <th>เลขที่ / เรื่อง</th>
                            <th>ความเร่งด่วน</th>
                            <th>สถานะปัจุบัน</th>
                            <th></th>
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
                                $priority_label = (string) ($item['ext_priority_label'] ?? 'ปกติ');
                                ?>
                                <tr>
                                    <td>
                                        <p><?= h((string) ($item['delivered_date'] ?? '-')) ?></p>
                                        <p><?= h((string) ($item['delivered_time'] ?? '-')) ?></p>
                                    </td>
                                    <td>
                                        <p><?= h((string) ($item['ext_book_no'] ?? '-')) ?></p>
                                        <p><?= h((string) ($item['subject'] ?? '')) ?></p>
                                    </td>
                                    <td><button class="urgency-status <?= h((string) ($item['urgency_class'] ?? 'normal')) ?>">
                                            <p><?= h($priority_label) ?></p>
                                        </button></td>
                                    <td><?= h((string) ($item['status_label'] ?? '-')) ?></td>
                                    <td>
                                        <div class="circular-action-stack">
                                            <button
                                                class="button-more-details js-open-circular-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                data-urgency="<?= h($priority_label) ?>"
                                                data-urgency-class="<?= h((string) ($item['urgency_class'] ?? 'normal')) ?>"
                                                data-bookno="<?= h((string) ($item['ext_book_no'] ?? '')) ?>"
                                                data-issued="<?= h((string) ($item['ext_issued_date'] ?? '-')) ?>"
                                                data-from="<?= h((string) ($item['ext_from_text'] ?? '')) ?>"
                                                data-to="<?= h($director_label) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                data-detail="<?= h((string) ($item['detail'] ?? '')) ?>"
                                                data-status="<?= h((string) ($item['status_label'] ?? '-')) ?>"
                                                data-consider="<?= h((string) ($item['consider_class'] ?? 'considering')) ?>"
                                                data-files="<?= h($file_json) ?>"
                                                data-received-time="<?= h((string) ($item['delivered_time'] ?? '-')) ?>">
                                                <p>รายละเอียด</p>
                                            </button>
                                            <a class="button-open-workflow" href="circular-view.php?inbox_id=<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>">อ่าน/ดำเนินการ</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div> -->
        <?php endif; ?>
    </section>

    <div class="button-circular-notice-keep"></div>
<?php else : ?>
    <div class="circular-notice-archive-notice-content">
        <header class="header-circular-notice-archive outside-person">
            <div class="circular-notice-archive-control outside-person">
                <div class="page-selector">
                    <p>แสดงตามประเภทหนังสือ</p>
                    <div class="checkbox-group">
                        <div>
                            <input type="checkbox" class="archive-control-checkbox" data-filter-type value="external" <?= $type_external_checked ? ' checked' : '' ?>>
                            <p>ภายนอก</p>
                        </div>
                        <div>
                            <input type="checkbox" class="archive-control-checkbox" data-filter-type value="internal" <?= $type_internal_checked ? ' checked' : '' ?>>
                            <p>ภายใน</p>
                        </div>
                    </div>
                </div>

                <div class="page-selector">
                    <p>แสดงตามสถานะหนังสือ</p>
                    <div class="checkbox-group">
                        <div>
                            <input type="checkbox" class="archive-control-checkbox" data-filter-read value="read" <?= $read_checked ? ' checked' : '' ?>>
                            <p>อ่านแล้ว</p>
                        </div>
                        <div>
                            <input type="checkbox" class="archive-control-checkbox" data-filter-read value="unread" <?= $unread_checked ? ' checked' : '' ?>>
                            <p>ยังไม่อ่าน</p>
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

            <div class="table-change">
                <p>ตาราง</p>
                <div class="button-table">
                    <button class="<?= h($filter_view === 'table1' ? 'active' : '') ?>" type="button" data-view="table1">ตาราง 1</button>
                    <button class="<?= h($filter_view === 'table2' ? 'active' : '') ?>" type="button" data-view="table2">ตาราง 2</button>
                </div>
            </div>
        </header>

        <section class="content-circular-notice-archive outside-person" data-circular-notice>
            <div class="search-bar">
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="search-input" name="q" form="circularFilterForm" value="<?= h($filter_search) ?>" placeholder="ค้นหาข้อความด้วย...">
                </div>
            </div>

            <form id="bulkActionForm" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unarchive_selected">
                <div class="table-circular-notice-archive outside-person">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" class="check-table checkall" id="checkAllCircular">
                                </th>
                                <th>ประเภทหนังสือ</th>
                                <th>หัวเรื่อง</th>
                                <th>ผู้ส่ง</th>
                                <th>วันที่ส่ง</th>
                                <th>สถานะ</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)) : ?>
                                <tr>
                                    <td colspan="7" class="booking-empty">ไม่มีรายการ</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($items as $item) : ?>
                                    <?php $file_json = (string) ($item['files_json'] ?? '[]'); ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="check-table" name="selected_ids[]" value="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>">
                                        </td>
                                        <td><?= h((string) ($item['type_label'] ?? '')) ?></td>
                                        <td><?= h((string) ($item['subject'] ?? '')) ?></td>
                                        <td><?= h((string) ($item['sender_name'] ?? '-')) ?></td>
                                        <td><?= h((string) ($item['delivered_date'] ?? '-')) ?></td>
                                        <td><span class="status-badge <?= h(($item['is_read'] ?? false) ? 'read' : 'unread') ?>"><?= h(($item['is_read'] ?? false) ? 'อ่านแล้ว' : 'ยังไม่อ่าน') ?></span></td>
                                        <td>
                                            <button
                                                class="booking-action-btn secondary js-open-circular-modal"
                                                type="button"
                                                data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                                data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                                data-urgency="<?= h((string) ($item['ext_priority_label'] ?? 'ปกติ')) ?>"
                                                data-urgency-class="<?= h((string) ($item['urgency_class'] ?? 'normal')) ?>"
                                                data-bookno="<?= h((string) ($item['ext_book_no'] ?? '')) ?>"
                                                data-issued="<?= h((string) ($item['ext_issued_date'] ?? '-')) ?>"
                                                data-from="<?= h((string) ($item['ext_from_text'] ?? '')) ?>"
                                                data-to="<?= h($director_label) ?>"
                                                data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                                data-detail="<?= h((string) ($item['detail'] ?? '')) ?>"
                                                data-status="<?= h((string) ($item['status_label'] ?? '-')) ?>"
                                                data-consider="<?= h((string) ($item['consider_class'] ?? 'considering')) ?>"
                                                data-files="<?= h($file_json) ?>"
                                                data-received-time="<?= h((string) ($item['delivered_time'] ?? '-')) ?>">
                                                <i class="fa-solid fa-eye"></i>
                                                <span class="tooltip">ดูรายละเอียด</span>
                                            </button>
                                        </td>
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
                        <p id="modalTypeLabel">รายละเอียด</p>
                        <i class="fa-solid fa-xmark" id="closeModalEdit" aria-hidden="true"></i>
                    </div>

                    <div class="content-modal">

                        <div class="content-memo" style="box-shadow: none;">
                            <div class="memo-header">
                                <img src="assets/img/garuda-logo.png" alt="">
                                <p>บันทึกข้อความ</p>
                                <div></div>
                            </div>

                            <form method="POST" id="circularComposeForm">
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
                                        <p>ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน</p>
                                    </div>

                                    <div class="content-editor">
                                        <p><strong>รายละเอียด:</strong></p>
                                        <br>
                                        <textarea name="detail" id="memo_editor"><?= h((string) ($values['detail'] ?? '')) ?></textarea>
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
                        <form method="POST" id="modalArchiveForm">
                            <input type="hidden" name="csrf_token" value="3ece51cef25df8dcbb025b7f59af78f9d7fa9c90963b44be41d39e6d5152a6ac"> <input type="hidden" name="inbox_id" id="modalInboxId" value="10">
                            <input type="hidden" name="action" value="archive">
                            <button type="submit">
                                <p>เสนอแฟ้ม</p>
                            </button>
                        </form>
                    </div>

                </div>
            </div>


        </section>
    </div>

    <div class="button-circular-notice-archive outside-person">
        <button class="button-keep" type="submit" form="bulkActionForm">
            <i class="fa-solid fa-file-import"></i>
            <p>ย้ายกลับ</p>
        </button>
    </div>
<?php endif; ?>

<?php //if (empty($items)) : 
?>
<?php //component_render('empty-state', [
// 'title' => 'ยังไม่มีรายการที่จัดเก็บ',
// 'message' => 'เมื่อปิดงานแล้ว คุณสามารถกด "จัดเก็บ" ในหน้ารายละเอียดได้',
//]); 
?>
<?php //else : 
?>
<?php //component_render('table', [
// 'headers' => ['เลขที่', 'เรื่อง', 'ผู้พิจารณา', 'สถานะ', 'เวลา', ''],
// 'rows' => $rows,
// 'empty_text' => 'ไม่มีรายการ',
//]); 
?>
<?php //component_render('pagination', [
//'page' => $page,
// 'total_pages' => $total_pages,
// 'base_url' => $pagination_base_url,
// 'class' => 'u-mt-2',
//]); 
?>
<?php //endif; 
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
<script>
    const memoArchiveFilterForm = document.getElementById('circularFilterForm');

    if (memoArchiveFilterForm) {
        const submitMemoArchiveFilter = () => {
            const pageInput = memoArchiveFilterForm.querySelector('input[name="page"]');

            if (pageInput) {
                pageInput.value = '1';
            }

            if (typeof memoArchiveFilterForm.requestSubmit === 'function') {
                memoArchiveFilterForm.requestSubmit();
                return;
            }

            memoArchiveFilterForm.submit();
        };

        document.querySelectorAll('.header-circular-notice-keep .custom-select-wrapper[data-target]').forEach((wrapper) => {
            const targetId = wrapper.getAttribute('data-target') || '';
            const targetInput = targetId !== '' ? document.getElementById(targetId) : null;

            if (!targetInput) {
                return;
            }

            wrapper.querySelectorAll('.custom-option').forEach((option) => {
                option.addEventListener('click', () => {
                    targetInput.value = option.getAttribute('data-value') || '';
                    submitMemoArchiveFilter();
                });
            });
        });

        document.querySelectorAll('[form="circularFilterForm"][data-auto-submit="true"]').forEach((field) => {
            let filterTimer = null;
            const delay = Number(field.getAttribute('data-auto-submit-delay') || 450);

            field.addEventListener('input', () => {
                window.clearTimeout(filterTimer);
                filterTimer = window.setTimeout(submitMemoArchiveFilter, Number.isFinite(delay) ? delay : 450);
            });
        });
    }

    tinymce.init({
        selector: '#memo_editor',
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

    const openEditBtns = document.querySelectorAll('.js-open-edit-modal');
    const closeEditBtn = document.getElementById('closeModalEdit');
    const editModal = document.getElementById('modalEditOverlay');
    openEditBtns.forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();

            if (editModal) editModal.style.display = 'flex';
        });
    });
    closeEditBtn?.addEventListener('click', () => {
        if (editModal) editModal.style.display = 'none';
    });

    window.addEventListener('click', (event) => {
        if (event.target === editModal) {
            editModal.style.display = 'none';
        }
    });
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
