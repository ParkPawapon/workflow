<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$values = (array) ($values ?? []);
$requests = (array) ($requests ?? []);
$request_attachments_map = (array) ($request_attachments_map ?? []);
$request_timeline_map = (array) ($request_timeline_map ?? []);
$current_pid = (string) ($current_pid ?? '');
$page = (int) ($page ?? 1);
$total_pages = (int) ($total_pages ?? 1);
$total_count = (int) ($total_count ?? 0);
$page_count = count($requests);
$view_item = $view_item ?? null;
$view_attachments = (array) ($view_attachments ?? []);
$edit_item = $edit_item ?? null;
$edit_attachments = (array) ($edit_attachments ?? []);
$is_editing = $edit_item !== null;
$mode = (string) ($mode ?? 'report');
$base_url = (string) ($base_url ?? 'repairs.php');
$page_title = (string) ($page_title ?? 'แจ้งเหตุซ่อมแซม');
$page_subtitle = (string) ($page_subtitle ?? 'บันทึกและติดตามสถานะงานซ่อม');
$form_title = (string) ($form_title ?? 'แจ้งเหตุซ่อมแซม');
$form_subtitle = (string) ($form_subtitle ?? 'กรอกข้อมูลให้ครบถ้วนเพื่อส่งคำขอซ่อม');
$list_title = (string) ($list_title ?? 'รายการแจ้งซ่อม');
$list_subtitle = (string) ($list_subtitle ?? 'ติดตามสถานะงานซ่อมทั้งหมด');
$empty_title = (string) ($empty_title ?? 'ยังไม่มีรายการแจ้งซ่อม');
$empty_message = (string) ($empty_message ?? 'เมื่อมีการแจ้งซ่อม รายการจะแสดงที่หน้านี้');
$show_form = (bool) ($show_form ?? false);
$show_requester_column = (bool) ($show_requester_column ?? false);
$transition_actions = (array) ($transition_actions ?? []);
$filter_query = (string) ($filter_query ?? '');
$filter_status = (string) ($filter_status ?? 'all');
$filter_sort = (string) ($filter_sort ?? 'newest');
$is_track_active = (bool) ($is_track_active ?? false);
$circular_id = (int) ($circular_id ?? 0);
$item = (array) ($item ?? []);
$item_type = (string) ($item_type ?? '');
$detail_text = (string) ($detail_text ?? '');
$detail_sender_name = (string) ($detail_sender_name ?? '');
$sender_name = (string) ($sender_name ?? '');
$detail_sender_faction = (string) ($detail_sender_faction ?? '');
$sender_faction_display = (string) ($sender_faction_display ?? '');
$date_long_display = (string) ($date_long_display ?? '');
$recipient_count = (int) ($recipient_count ?? 0);
$status_meta = (array) ($status_meta ?? ['label' => '-']);
$consider_class = (string) ($consider_class ?? '');
$date_display = (string) ($date_display ?? '');
$files_json = (string) ($files_json ?? '[]');
$stats_json = (string) ($stats_json ?? '[]');

$values = array_merge([
    'subject' => '',
    'location' => '',
    'equipment' => '',
    'detail' => '',
], $values);

$status_map = (array) ($status_map ?? [
    REPAIR_STATUS_PENDING => ['label' => 'ส่งคำร้องสำเร็จ', 'variant' => 'pending'],
    REPAIR_STATUS_IN_PROGRESS => ['label' => 'กำลังดำเนินการ', 'variant' => 'processing'],
    REPAIR_STATUS_COMPLETED => ['label' => 'ดำเนินการเสร็จสิ้น', 'variant' => 'approved'],
    REPAIR_STATUS_CANCELLED => ['label' => 'ยกเลิกคำร้อง', 'variant' => 'rejected'],
    REPAIR_STATUS_REJECTED => ['label' => 'ยกเลิกคำร้อง', 'variant' => 'rejected'],
]);
$status_filter_options = (array) ($status_filter_options ?? [
    'all' => 'ทั้งหมด',
    'pending' => 'ส่งคำร้องสำเร็จ',
    'in_progress' => 'กำลังดำเนินการ',
    'completed' => 'ดำเนินการเสร็จสิ้น',
    'cancelled' => 'ยกเลิกคำร้อง',
]);

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

$parse_repair_datetime = static function (?string $datetime): ?DateTime {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || preg_match('/^0000-00-00/u', $datetime) === 1) {
        return null;
    }

    foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $date_obj = DateTime::createFromFormat($format, $datetime);

        if ($date_obj instanceof DateTime) {
            return $date_obj;
        }
    }

    return null;
};

$format_thai_datetime_parts = static function (?string $datetime) use ($thai_months, $parse_repair_datetime): array {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || preg_match('/^0000-00-00/u', $datetime) === 1) {
        return [
            'date' => '-',
            'time' => '-',
            'full' => '-',
        ];
    }

    $date_obj = $parse_repair_datetime($datetime);

    if (!$date_obj instanceof DateTime) {
        return [
            'date' => $datetime,
            'time' => '-',
            'full' => $datetime,
        ];
    }

    $day = (int) $date_obj->format('j');
    $month = (int) $date_obj->format('n');
    $year = (int) $date_obj->format('Y') + 543;
    $month_label = $thai_months[$month] ?? '';
    $date_line = trim($day . ' ' . $month_label . ' ' . $year);
    $time_line = 'เวลา ' . $date_obj->format('H:i') . ' น.';

    return [
        'date' => $date_line,
        'time' => $time_line,
        'full' => trim($date_line . ' ' . $time_line),
    ];
};

$format_repair_timeline_datetime = static function (?string $datetime) use ($thai_months, $parse_repair_datetime): string {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || preg_match('/^0000-00-00/u', $datetime) === 1) {
        return '-';
    }

    $date_obj = $parse_repair_datetime($datetime);

    if (!$date_obj instanceof DateTime) {
        return $datetime;
    }

    $day = (int) $date_obj->format('j');
    $month = (int) $date_obj->format('n');
    $year = (int) $date_obj->format('Y') + 543;
    $month_label = $thai_months[$month] ?? '';

    return trim('วันที่ ' . $day . ' ' . $month_label . ' พ.ศ.' . $year . ' ' . $date_obj->format('H:i') . ' น.');
};

$truncate_repair_detail = static function (?string $text, int $limit = 80): string {
    $value = trim((string) $text);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $limit = max(1, $limit);

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . '...';
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(substr($value, 0, $limit)) . '...';
};

$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$resolve_repair_status = static function (array $repair) use ($status_map): array {
    $deleted_at = trim((string) ($repair['deletedAt'] ?? ''));

    if ($deleted_at !== '' && $deleted_at !== '0000-00-00 00:00:00') {
        return [
            'label' => 'ลบคำร้องสำเร็จ',
            'variant' => 'rejected',
        ];
    }

    $status_key = (string) ($repair['status'] ?? REPAIR_STATUS_PENDING);

    return $status_map[$status_key] ?? ['label' => $status_key !== '' ? $status_key : '-', 'variant' => 'pending'];
};

$build_repair_timeline_description = static function (array $entry): string {
    $payload = (array) ($entry['payload'] ?? []);
    $note = trim((string) ($payload['note'] ?? ''));

    if ($note !== '') {
        return $note;
    }

    $event = strtoupper(trim((string) ($entry['event'] ?? '')));
    $status = strtoupper(trim((string) ($entry['toStatus'] ?? '')));

    if ($event === 'NOTE_UPDATE') {
        return 'เจ้าหน้าที่อัปเดตรายละเอียดการดำเนินงาน';
    }

    return match ($status) {
        REPAIR_STATUS_PENDING => 'ระบบรับเรื่องคำร้องแจ้งซ่อมเรียบร้อยแล้ว',
        REPAIR_STATUS_IN_PROGRESS => 'เจ้าหน้าที่รับเรื่องและอยู่ระหว่างดำเนินการตรวจสอบหรือซ่อมแซม',
        REPAIR_STATUS_COMPLETED => 'เจ้าหน้าที่ดำเนินการซ่อมแซมเสร็จสิ้นแล้ว',
        REPAIR_STATUS_CANCELLED, REPAIR_STATUS_REJECTED => 'คำร้องแจ้งซ่อมถูกยกเลิกแล้ว',
        default => 'ระบบบันทึกสถานะการดำเนินงานเรียบร้อยแล้ว',
    };
};

$headers = $show_requester_column
    ? ['หัวข้อ', 'สถานที่', 'อุปกรณ์', 'สถานะ', 'ผู้แจ้ง', 'วันที่แจ้ง', 'จัดการ']
    : ['หัวข้อ', 'สถานที่', 'อุปกรณ์', 'สถานะ', 'วันที่แจ้ง', 'จัดการ'];

$rows = [];

foreach ($requests as $req) {
    $status_key = (string) ($req['status'] ?? REPAIR_STATUS_PENDING);
    $is_soft_deleted = trim((string) ($req['deletedAt'] ?? '')) !== '' && (string) ($req['deletedAt'] ?? '') !== '0000-00-00 00:00:00';
    $status = $resolve_repair_status($req);
    $is_owner = (string) ($req['requesterPID'] ?? '') === $current_pid;
    $can_edit = $mode === 'report' && !$is_soft_deleted && $status_key === REPAIR_STATUS_PENDING && $is_owner;

    $row = [
        (string) ($req['subject'] ?? ''),
        (string) ($req['location'] ?? '-'),
        (string) ($req['equipment'] ?? '-'),
        [
            'component' => [
                'name' => 'status-pill',
                'params' => [
                    'label' => $status['label'],
                    'variant' => $status['variant'],
                ],
            ],
        ],
    ];

    if ($show_requester_column) {
        $row[] = (string) ($req['requesterName'] ?? '-');
    }

    $row[] = (string) ($req['createdAt'] ?? '');
    $row[] = [
        'component' => [
            'name' => 'repairs-action-group',
            'params' => [
                'repair_id' => (int) ($req['repairID'] ?? 0),
                'base_url' => $base_url,
                'view_label' => $mode === 'report' ? 'อ่าน' : 'ดูรายละเอียด',
                'can_edit' => $can_edit,
                'can_delete' => $can_edit,
            ],
        ],
    ];

    $rows[] = $row;
}

$detail_status = null;

if ($view_item) {
    $detail_key = (string) ($view_item['status'] ?? REPAIR_STATUS_PENDING);
    $detail_status = $status_map[$detail_key] ?? ['label' => $detail_key, 'variant' => 'pending'];
}

$edit_status = null;

if ($edit_item) {
    $edit_key = (string) ($edit_item['status'] ?? REPAIR_STATUS_PENDING);
    $edit_status = $status_map[$edit_key] ?? ['label' => $edit_key, 'variant' => 'pending'];
}

ob_start();
?>

<style>
    .form-group .upload-layout .upload-box {
        width: 100%;
        height: 200px;
    }

    .enterprise-card-title.last {
        margin: 20px 0 0 0;
    }

    .circular-my-table-wrap {
        margin-top: 8px;
    }

    .circular-my-table td {
        vertical-align: top;
    }

    .repair-detail-preview {
        font-size: var(--font-size-body-2);
        color: var(--color-secondary);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.5;
        white-space: normal;
    }

    .repair-date-stack {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
        font-size: var(--font-size-body-2);
        text-align: left;
    }

    .repair-date-stack .time {
        color: #000;
        font-size: var(--font-size-desc-2);
        line-height: 1.35;
    }

    .circular-my-table .circular-my-subject {
        font-size: var(--font-size-body-2);
    }

    .repairs-inline-action-form {
        margin: 0;
        display: inline-flex;
    }

    .content-modal .container-circular-notice-sending {
        box-shadow: none;
        padding: 0;
    }

    .delete-btn {
        font-size: var(--font-size-body-1);
    }

    .delete-btn:hover {
        transform: none;
    }

    .container-circular-notice-sending hr {
        background-color: var(--color-secondary);
        height: 1px;
        border: none;
        margin: 0 0 40px;
    }

    .content-circular-notice-index {
        width: 0;
        height: 0;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
    }

    @media screen and (min-width: 769px) and (max-width: 1023px) {
        .circular-my-table td:nth-child(4) {
            min-width: 120px;
        }
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(1),
    .table-circular-notice-index table thead th:nth-child(5),
    .table-circular-notice-index table tbody td:nth-child(5) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .table-circular-notice-index table tbody td:nth-child(2) {
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
        width: 180px !important;
        min-width: 180px !important;
        max-width: 180px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3),
    .booking-table td:nth-child(3) {
        width: 650px !important;
        min-width: 650px !important;
        max-width: 650px !important;
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
        .table-circular-notice-index table thead th:nth-child(5),
        .table-circular-notice-index table tbody td:nth-child(5) {
            text-align: center !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .table-circular-notice-index table tbody td:nth-child(2) {
            text-align: start !important;
        }

        .table-circular-notice-index table thead th:nth-child(1),
        .booking-table td:nth-child(1) {
            width: 100px !important;
            min-width: 100px !important;
            max-width: 100px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2),
        .booking-table td:nth-child(2) {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 160px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3),
        .booking-table td:nth-child(3) {
            width: 500px !important;
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4),
        .booking-table td:nth-child(4) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
        }

        .table-circular-notice-index table thead th:nth-child(5),
        .booking-table td:nth-child(5) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
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
            onclick="openTab('repairs', event)">แจ้งเหตุซ่อมแซม</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>"
            onclick="openTab('myRepair', event)">รายการของฉัน</button>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" class="tab-content container-circular-notice-sending <?= $is_track_active ? '' : 'active' ?>" id="repairs">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <div class="sender-row">
        <div class="form-group">
            <label for="">หัวข้อ</label>
            <input type="text" name="subject" value="<?= h($values['subject']) ?>" placeholder="ระบุหัวข้อที่ต้องการแจ้งซ่อม">
        </div>
        <div class="form-group">
            <label for="">สถานที่</label>
            <input type="text" name="location" value="<?= h($values['location']) ?>" placeholder="เช่น อาคาร 1 ห้อง 205">
        </div>
    </div>

    <div class="form-group">
        <label for="">อุปกรณ์</label>
        <input type="text" name="equipment" value="<?= h($values['equipment']) ?>" placeholder="เช่น โปรเจคเตอร์ / เครื่องปรับอากาศ">
    </div>

    <div class="form-group">
        <label for="">รายละเอียดเพิ่มเติม</label>
        <textarea name="detail" rows="4" placeholder="อธิบายอาการหรือปัญหาที่พบ"><?= h($values['detail']) ?></textarea>
    </div>

    <div class="form-group">
        <label>อัปโหลดไฟล์เอกสาร</label>
        <section class="upload-layout">
            <input type="file" id="fileInput" name="attachments[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;" />

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
    </div>

    <div class="form-group button">
        <div class="input-group">
            <button
                class="submit"
                type="submit"
                data-confirm="ยืนยันการส่งแจ้งซ่อมใช่หรือไม่?"
                data-confirm-title="ยืนยันการส่งแจ้งซ่อม"
                data-confirm-ok="ยืนยัน"
                data-confirm-cancel="ยกเลิก">
                <p>ส่งแจ้งซ่อม</p>
            </button>
        </div>
    </div>

</form>

<section class="tab-content enterprise-card <?= $is_track_active ? 'active' : '' ?>" id="myRepair">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" action="<?= h($base_url) ?>" class="circular-my-filter-grid" id="repairTrackFilterForm">
        <input type="hidden" name="tab" value="track">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($filter_query) ?>"
                    placeholder="ค้นหาหัวข้อ รายละเอียด" autocomplete="off">
            </div>
            <div class="room-admin-filter">
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h((string) ($status_filter_options[$filter_status] ?? $status_filter_options['all'] ?? 'ทั้งหมด')) ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($status_filter_options as $status_value => $status_label) : ?>
                            <div class="custom-option" data-value="<?= h((string) $status_value) ?>"><?= h((string) $status_label) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <select class="form-input" name="status">
                        <?php foreach ($status_filter_options as $status_value => $status_label) : ?>
                            <option value="<?= h((string) $status_value) ?>" <?= $filter_status === (string) $status_value ? 'selected' : '' ?>><?= h((string) $status_label) ?></option>
                        <?php endforeach; ?>
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

    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title last">รายการแจ้งซ่อมของฉัน</h2>
        </div>
    </div>

    <div class="table-responsive table-circular-notice-index circular-my-table-wrap">
        <table class="custom-table circular-my-table">
            <thead>
                <tr>
                    <th>จัดการ</th>
                    <th>หัวข้อ</th>
                    <th>รายละเอียด</th>
                    <th>วันที่แจ้ง</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)) : ?>
                    <tr>
                        <td colspan="5" class="booking-empty"><?= h($empty_message !== '' ? $empty_message : 'ยังไม่มีรายการแจ้งซ่อม') ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($requests as $req) : ?>
                        <?php
                        $repair_id = (int) ($req['repairID'] ?? 0);
                        $status_key = (string) ($req['status'] ?? REPAIR_STATUS_PENDING);
                        $is_soft_deleted = trim((string) ($req['deletedAt'] ?? '')) !== '' && (string) ($req['deletedAt'] ?? '') !== '0000-00-00 00:00:00';
                        $row_status = $resolve_repair_status($req);
                        $is_owner = (string) ($req['requesterPID'] ?? '') === $current_pid;
                        $can_edit_row = $mode === 'report' && !$is_soft_deleted && $status_key === REPAIR_STATUS_PENDING && $is_owner;
                        $can_delete_row = $can_edit_row;
                        $date_parts = $format_thai_datetime_parts((string) ($req['createdAt'] ?? ''));
                        $detail_preview = $truncate_repair_detail((string) ($req['detail'] ?? ''), 80);
                        $requester_attachment_payload = [];
                        $system_attachment_payload = [];
                        $requester_pid = trim((string) ($req['requesterPID'] ?? ''));

                        foreach ((array) ($request_attachments_map[$repair_id] ?? []) as $file) {
                            $attachment_payload_item = [
                                'fileID' => (int) ($file['fileID'] ?? 0),
                                'fileName' => (string) ($file['fileName'] ?? 'ไฟล์แนบ'),
                                'mimeType' => (string) ($file['mimeType'] ?? ''),
                                'fileSize' => (int) ($file['fileSize'] ?? 0),
                                'entityName' => (string) ($file['entityName'] ?? ''),
                            ];

                            $attached_by_pid = trim((string) ($file['attachedByPID'] ?? ''));
                            $entity_name = trim((string) ($file['entityName'] ?? ''));
                            $is_system_attachment = $entity_name === REPAIR_OFFICIAL_ATTACHMENT_ENTITY_NAME
                                || ($requester_pid !== '' && $attached_by_pid !== '' && $attached_by_pid !== $requester_pid);

                            if ($is_system_attachment) {
                                $system_attachment_payload[] = $attachment_payload_item;
                                continue;
                            }

                            $requester_attachment_payload[] = $attachment_payload_item;
                        }

                        $attachment_json = json_encode($requester_attachment_payload, $json_flags);
                        $system_attachment_json = json_encode($system_attachment_payload, $json_flags);

                        if (!is_string($attachment_json)) {
                            $attachment_json = '[]';
                        }

                        if (!is_string($system_attachment_json)) {
                            $system_attachment_json = '[]';
                        }

                        $timeline_by_status = [];

                        foreach ((array) ($request_timeline_map[$repair_id] ?? []) as $timeline_item) {
                            $to_status = strtoupper(trim((string) ($timeline_item['toStatus'] ?? '')));
                            $status_label = trim((string) ($timeline_item['toLabel'] ?? ''));
                            $status_key_for_timeline = $to_status !== '' ? $to_status : trim((string) ($timeline_item['title'] ?? ''));
                            $timeline_actor_name = trim((string) ($timeline_item['actorName'] ?? ''));
                            $timeline_date = $format_repair_timeline_datetime((string) ($timeline_item['createdAt'] ?? ''));
                            $should_show_timeline_actor = $to_status !== REPAIR_STATUS_PENDING;

                            if ($status_key_for_timeline === '') {
                                continue;
                            }

                            if ($status_label === '') {
                                $status_label = trim((string) ($timeline_item['title'] ?? ''));
                            }

                            $timeline_by_status[$status_key_for_timeline] = [
                                'title' => 'ขั้นตอนดำเนินงาน : ' . ($status_label !== '' ? $status_label : '-'),
                                'description' => $build_repair_timeline_description((array) $timeline_item),
                                'date' => $timeline_date . ($should_show_timeline_actor && $timeline_actor_name !== '' ? ' [' . $timeline_actor_name . ']' : ''),
                            ];
                        }

                        if ($is_soft_deleted) {
                            $deleted_actor_name = trim((string) ($req['requesterName'] ?? ''));
                            $deleted_date = $format_repair_timeline_datetime((string) ($req['deletedAt'] ?? ''));
                            $timeline_by_status['DELETED'] = [
                                'title' => 'ขั้นตอนดำเนินงาน : ลบคำร้องสำเร็จ',
                                'description' => 'ผู้แจ้งลบคำร้องเรียบร้อยแล้ว',
                                'date' => $deleted_date . ($deleted_actor_name !== '' ? ' [' . $deleted_actor_name . ']' : ''),
                            ];
                        }

                        if ($timeline_by_status === []) {
                            $fallback_actor_name = trim((string) ($req['assignedToName'] ?? ''));
                            $fallback_date = $format_repair_timeline_datetime((string) ($req['createdAt'] ?? ''));
                            $should_show_fallback_actor = $status_key !== REPAIR_STATUS_PENDING;
                            $timeline_by_status[$status_key] = [
                                'title' => 'ขั้นตอนดำเนินงาน : ' . (string) ($row_status['label'] ?? '-'),
                                'description' => $is_soft_deleted ? 'ผู้แจ้งลบคำร้องเรียบร้อยแล้ว' : 'ระบบบันทึกสถานะการดำเนินงานเรียบร้อยแล้ว',
                                'date' => $fallback_date . ($should_show_fallback_actor && $fallback_actor_name !== '' ? ' [' . $fallback_actor_name . ']' : ''),
                            ];
                        }

                        $timeline_json = json_encode(array_values($timeline_by_status), $json_flags);

                        if (!is_string($timeline_json)) {
                            $timeline_json = '[]';
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="circular-my-actions">
                                    <?php if ($can_edit_row) : ?>
                                        <button
                                            class="booking-action-btn secondary js-open-repair-edit-modal"
                                            type="button"
                                            data-repair-id="<?= h((string) $repair_id) ?>"
                                            data-subject="<?= h((string) ($req['subject'] ?? '')) ?>"
                                            data-detail="<?= h((string) ($req['detail'] ?? '')) ?>"
                                            data-location="<?= h((string) ($req['location'] ?? '')) ?>"
                                            data-equipment="<?= h((string) ($req['equipment'] ?? '')) ?>"
                                            data-files="<?= h($attachment_json) ?>">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                            <span class="tooltip">แก้ไข</span>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($can_delete_row) : ?>
                                        <form method="POST" action="<?= h($base_url) ?>" class="repairs-inline-action-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="repair_id" value="<?= h((string) $repair_id) ?>">
                                            <input type="hidden" name="tab" value="track">
                                            <button
                                                type="submit"
                                                class="booking-action-btn danger"
                                                data-confirm="ยืนยันการลบคำร้องนี้ใช่หรือไม่"
                                                data-confirm-title="ยืนยันการลบคำร้อง"
                                                data-confirm-ok="ยืนยัน"
                                                data-confirm-cancel="ยกเลิก">
                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                <span class="tooltip danger">ลบคำร้อง</span>
                                            </button>
                                        </form>
                                    <?php else : ?>
                                        <button
                                            class="booking-action-btn secondary js-open-repair-detail-modal"
                                            type="button"
                                            data-repair-id="<?= h((string) $repair_id) ?>"
                                            data-subject="<?= h((string) ($req['subject'] ?? '-')) ?>"
                                            data-detail="<?= h((string) ($req['detail'] ?? '')) ?>"
                                            data-location="<?= h((string) ($req['location'] ?? '-')) ?>"
                                            data-equipment="<?= h((string) ($req['equipment'] ?? '-')) ?>"
                                            data-created-at="<?= h($date_parts['full']) ?>"
                                            data-updated-at="<?= h($format_thai_datetime_parts((string) ($req['updatedAt'] ?? ''))['full']) ?>"
                                            data-requester-name="<?= h((string) ($req['requesterName'] ?? '-')) ?>"
                                            data-assigned-to-name="<?= h((string) ($req['assignedToName'] ?? '-')) ?>"
                                            data-status-label="<?= h((string) ($row_status['label'] ?? '-')) ?>"
                                            data-status-pill="<?= h((string) ($row_status['variant'] ?? 'pending')) ?>"
                                            data-files="<?= h($attachment_json) ?>"
                                            data-system-files="<?= h($system_attachment_json) ?>"
                                            data-timeline="<?= h($timeline_json) ?>">
                                            <i class="fa-solid fa-eye"></i>
                                            <span class="tooltip">ดูรายละเอียด</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="circular-my-subject"><?= h((string) ($req['subject'] ?? '-')) ?></div>
                            </td>

                            <td>
                                <div class="repair-detail-preview"><?= h($detail_preview !== '' ? $detail_preview : '-') ?></div>
                            </td>

                            <td>
                                <div class="repair-date-stack">
                                    <div><?= h($date_parts['date']) ?></div>
                                    <div class="time"><?= h($date_parts['time']) ?></div>
                                </div>
                            </td>

                            <td>
                                <span class="status-pill <?= h((string) ($row_status['variant'] ?? 'pending')) ?>">
                                    <?= h((string) ($row_status['label'] ?? '-')) ?>
                                </span>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="content-circular-notice-index circular-track-modal-host">
    <div class="modal-overlay-circular-notice-index outside-person" id="modalNoticeKeepOverlay">
        <div class="modal-content">

            <div class="header-modal">
                <div class="first-header">
                    <p>รายละเอียดการแจ้งซ่อมของฉัน</p>
                </div>
                <div class="sec-header">
                    <span class="status-pill pending">ส่งคำร้องสำเร็จ</span>
                    <i class="fa-solid fa-xmark" id="closeModalNoticeKeep"></i>
                </div>
            </div>

            <div class="content-modal">

                <form method="" class="container-circular-notice-sending" id="repairs">

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="">หัวข้อ</label>
                            <input type="text" id="repairDetailSubject" placeholder="ระบุหัวข้อที่ต้องการแจ้งซ่อม" disabled>
                        </div>
                        <div class="form-group">
                            <label for="">สถานที่</label>
                            <input type="text" id="repairDetailLocation" placeholder="เช่น อาคาร 1 ห้อง 205" disabled>
                        </div>
                    </div>

                    <!-- <div class="sender-row">
                        <div class="form-group">
                            <label for="">ผู้แจ้ง</label>
                            <input type="text" id="repairDetailRequester" placeholder="ชื่อผู้แจ้งซ่อม" disabled>
                        </div>
                        <div class="form-group">
                            <label for="">ผู้รับผิดชอบ</label>
                            <input type="text" id="repairDetailAssignedTo" placeholder="ชื่อผู้รับผิดชอบ" disabled>
                        </div>
                    </div> -->

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="">วันที่แจ้ง</label>
                            <input type="text" id="repairDetailCreatedAt" placeholder="วันที่แจ้งซ่อม" disabled>
                        </div>
                        <div class="form-group">
                            <label for="">อัปเดตล่าสุด</label>
                            <input type="text" id="repairDetailUpdatedAt" placeholder="วันที่อัปเดตล่าสุด" disabled>
                        </div>
                    </div>

                    <!-- <div class="sender-row"> -->
                    <div class="form-group">
                        <label for="">อุปกรณ์</label>
                        <input type="text" id="repairDetailEquipment" placeholder="เช่น โปรเจคเตอร์ / เครื่องปรับอากาศ" disabled>
                    </div>
                    <!-- </div> -->

                    <div class="form-group">
                        <label for="">รายละเอียดเพิ่มเติม</label>
                        <textarea id="repairDetailText" rows="4" placeholder="อธิบายอาการหรือปัญหาที่พบ" disabled></textarea>
                    </div>

                    <div class="form-group">
                        <label>ไฟล์แนบ</label>
                        <section class="upload-layout">
                            <div class="file-list" id="repairDetailFileList"></div>
                        </section>
                    </div>

                    <hr>

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="">ผู้รับผิดชอบ</label>
                            <input type="text" id="repairDetailAssignedTo" placeholder="ชื่อผู้รับผิดชอบ" disabled>
                        </div>
                    </div>
                    <br>
                    <div class="timeline" id="repairDetailTimeline">
                        <p class="timeline-header">สถานะของงานซ่อมแซม</p>
                    </div>

                    <div class="form-group">
                        <label><b>ไฟล์เอกสารแนบจากระบบ</b></label>
                        <section class="upload-layout">
                            <div class="file-list" id="repairSystemDetailFileList"></div>
                        </section>
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
                    <p>แก้ไขการแจ้งเหตุซ่อมแซมของฉัน</p>
                </div>
                <div class="sec-header">
                    <span class="status-pill pending">ส่งคำร้องสำเร็จ</span>
                    <i class="fa-solid fa-xmark" id="closeModalEdit" style="cursor: pointer;"></i>
                </div>
            </div>

            <div class="content-modal">

                <form method="POST" enctype="multipart/form-data" class="container-circular-notice-sending" id="repairEditForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="repair_id" id="repairEditId" value="">
                    <input type="hidden" name="tab" value="track">

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="">หัวข้อ</label>
                            <input type="text" id="edit_subject" name="subject" placeholder="ระบุหัวข้อที่ต้องการแจ้งซ่อม">
                        </div>
                        <div class="form-group">
                            <label for="">สถานที่</label>
                            <input type="text" id="edit_location" name="location" placeholder="เช่น อาคาร 1 ห้อง 205">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="">อุปกรณ์</label>
                        <input type="text" id="edit_equipment" name="equipment" placeholder="เช่น โปรเจคเตอร์ / เครื่องปรับอากาศ">
                    </div>

                    <div class="form-group">
                        <label for="">รายละเอียดเพิ่มเติม</label>
                        <textarea id="edit_detail" name="detail" rows="4" placeholder="อธิบายอาการหรือปัญหาที่พบ"></textarea>
                    </div>

                    <div class="form-group">
                        <label>อัปโหลดไฟล์เอกสาร</label>
                        <section class="upload-layout">
                            <input type="file" id="edit_fileInput" name="attachments[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;" />

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
                    </div>

                </form>

            </div>

            <div class="footer-modal">
                <form method="POST">
                    <button type="submit" form="repairEditForm">
                        <p>ยืนยัน</p>
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
    let repairEditUploadApi = null;

    function openTab(tabId, btnElement, event) {
        event.preventDefault();

        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

        document.getElementById(tabId).classList.add('active');
        btnElement.classList.add('active');
    }

    document.addEventListener('DOMContentLoaded', () => {

        function setupFileUpload(prefix) {
            const fileInput = document.getElementById(prefix + 'fileInput');
            const fileList = document.getElementById(prefix + 'fileListContainer');
            const dropzone = document.getElementById(prefix + 'dropzone');
            const addFilesBtn = document.getElementById(prefix + 'btnAddFiles');

            if (!fileInput) return null;

            const maxFiles = 999;
            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            let selectedFiles = [];
            let existingFiles = [];
            let existingEntityId = '';

            const formatFileSize = (size) => {
                const bytes = Number(size || 0);
                if (!Number.isFinite(bytes) || bytes <= 0) {
                    return '';
                }
                return `${(bytes / 1024).toFixed(1)} KB`;
            };

            const renderFiles = () => {
                if (!fileList) return;
                fileList.innerHTML = '';

                existingFiles.forEach((file) => {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'file-item-wrapper';

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
                    text.innerHTML = `<span class="file-name">${file?.fileName || '-'}</span><span class="file-type">${formatFileSize(file?.fileSize) || (file?.mimeType || '')}</span>`;

                    info.appendChild(iconWrap);
                    info.appendChild(text);

                    const actions = document.createElement('div');
                    actions.className = 'file-actions-group';
                    actions.style.display = 'flex';
                    actions.style.gap = '10px';

                    const baseUrl = `public/api/file-download.php?module=repairs&entity_id=${encodeURIComponent(existingEntityId)}&file_id=${encodeURIComponent(file?.fileID || '')}`;

                    const viewAction = document.createElement('div');
                    viewAction.className = 'file-actions';
                    viewAction.innerHTML = `<a href="${baseUrl}" target="_blank" rel="noopener"><i class="fa-solid fa-eye" aria-hidden="true"></i></a>`;

                    const downloadAction = document.createElement('div');
                    downloadAction.className = 'file-actions';
                    downloadAction.innerHTML = `<a href="${baseUrl}&download=1"><i class="fa-solid fa-download" aria-hidden="true"></i></a>`;

                    actions.appendChild(viewAction);
                    actions.appendChild(downloadAction);

                    container.appendChild(info);
                    container.appendChild(actions);
                    wrapper.appendChild(container);
                    fileList.appendChild(wrapper);
                });

                selectedFiles.forEach((file, index) => {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'file-item-wrapper';

                    const container = document.createElement('div');
                    container.className = 'file-banner';

                    const info = document.createElement('div');
                    info.className = 'file-info';

                    const iconWrap = document.createElement('div');
                    iconWrap.className = 'file-icon';
                    const mime = String(file.type || '').toLowerCase();
                    iconWrap.innerHTML = '<i class="fa-solid fa-file-image"></i>';

                    const text = document.createElement('div');
                    text.className = 'file-text';

                    const sizeKB = (file.size / 1024).toFixed(1);
                    text.innerHTML = `<span class="file-name">${file.name}</span><span class="file-type">${sizeKB} KB</span>`;

                    info.appendChild(iconWrap);
                    info.appendChild(text);

                    const actions = document.createElement('div');
                    actions.className = 'file-actions-group';
                    actions.style.display = 'flex';
                    actions.style.gap = '10px';

                    const viewAction = document.createElement('div');
                    viewAction.className = 'file-actions';
                    const viewLink = document.createElement('a');

                    const fileUrl = URL.createObjectURL(file);
                    viewLink.href = fileUrl;
                    viewLink.target = '_blank';
                    viewLink.rel = 'noopener';
                    viewLink.innerHTML = '<i class="fa-solid fa-eye"></i>';
                    viewAction.appendChild(viewLink);

                    const deleteAction = document.createElement('div');
                    deleteAction.className = 'file-actions';
                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'delete-btn';
                    deleteBtn.style.border = 'none';
                    deleteBtn.style.background = 'none';
                    deleteBtn.style.cursor = 'pointer';
                    deleteBtn.style.color = '#dc3545';
                    deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';

                    deleteBtn.onclick = () => {
                        URL.revokeObjectURL(fileUrl);
                        selectedFiles = selectedFiles.filter((_, i) => i !== index);
                        syncFiles();
                        renderFiles();
                    };
                    deleteAction.appendChild(deleteBtn);

                    actions.appendChild(viewAction);
                    actions.appendChild(deleteAction);

                    container.appendChild(info);
                    container.appendChild(actions);
                    wrapper.appendChild(container);
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
                const existing = new Set(selectedFiles.map((f) => `${f.name}-${f.size}`));
                Array.from(files).forEach((file) => {
                    const key = `${file.name}-${file.size}`;
                    if (!existing.has(key) && allowedTypes.includes(file.type) && selectedFiles.length < maxFiles) {
                        selectedFiles.push(file);
                        existing.add(key);
                    }
                });
                syncFiles();
                renderFiles();
            };

            fileInput.addEventListener('change', (e) => addFiles(e.target.files));
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

            return {
                reset() {
                    selectedFiles = [];
                    existingFiles = [];
                    existingEntityId = '';
                    syncFiles();
                    renderFiles();
                    fileInput.value = '';
                    if (dropzone) {
                        dropzone.classList.remove('active');
                    }
                },
                setExistingFiles(files, entityId) {
                    existingFiles = Array.isArray(files) ? files : [];
                    existingEntityId = String(entityId || '').trim();
                    renderFiles();
                }
            };
        }

        setupFileUpload('');

        repairEditUploadApi = setupFileUpload('edit_');

    });

    document.addEventListener('DOMContentLoaded', function() {
        const trackSection = document.getElementById('myRepair');
        const trackFilterForm = document.getElementById('repairTrackFilterForm');
        const trackTableWrap = trackSection ? trackSection.querySelector('.table-responsive.circular-my-table-wrap') : null;
        const trackQueryInput = trackFilterForm ? trackFilterForm.querySelector('input[name="q"]') : null;
        const trackStatusInput = trackFilterForm ? trackFilterForm.querySelector('select[name="status"]') : null;
        const trackSortInput = trackFilterForm ? trackFilterForm.querySelector('select[name="sort"]') : null;
        const loadingApi = window.App && window.App.loading ? window.App.loading : null;
        let trackSearchTimer = null;
        let trackSearchIsComposing = false;
        let trackFilterController = null;
        let trackFilterRequestId = 0;

        const buildTrackFilterUrl = () => {
            if (!trackFilterForm) {
                return '';
            }

            const action = String(trackFilterForm.getAttribute('action') || '').trim() || window.location.pathname;
            const params = new URLSearchParams(new FormData(trackFilterForm));
            const query = params.toString();

            return query !== '' ? `${action}?${query}` : action;
        };

        const refreshTrackTable = (delayMs = 0) => {
            if (!trackFilterForm) {
                return;
            }

            window.clearTimeout(trackSearchTimer);

            trackSearchTimer = window.setTimeout(async () => {
                const requestUrl = buildTrackFilterUrl();

                if (requestUrl === '') {
                    return;
                }

                if (!trackTableWrap || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
                    window.location.assign(requestUrl);
                    return;
                }

                if (trackFilterController) {
                    trackFilterController.abort();
                }

                const controller = new AbortController();
                trackFilterController = controller;
                const requestId = ++trackFilterRequestId;

                if (loadingApi) {
                    loadingApi.startComponent(trackTableWrap);
                } else {
                    trackTableWrap.style.opacity = '0.55';
                    trackTableWrap.style.pointerEvents = 'none';
                }

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

                    if (requestId !== trackFilterRequestId) {
                        return;
                    }

                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const nextTrackSection = doc.getElementById('myRepair');
                    const nextTrackTableWrap = nextTrackSection ? nextTrackSection.querySelector('.table-responsive.circular-my-table-wrap') : null;

                    if (!nextTrackTableWrap) {
                        throw new Error('Unable to locate refreshed repairs table');
                    }

                    trackTableWrap.innerHTML = nextTrackTableWrap.innerHTML;
                    window.history.replaceState({}, '', requestUrl);
                } catch (error) {
                    if (!error || error.name !== 'AbortError') {
                        window.location.assign(requestUrl);
                    }
                } finally {
                    if (requestId === trackFilterRequestId) {
                        trackFilterController = null;
                    }

                    if (loadingApi) {
                        loadingApi.stopComponent(trackTableWrap);
                    } else {
                        trackTableWrap.style.opacity = '';
                        trackTableWrap.style.pointerEvents = '';
                    }
                }
            }, delayMs);
        };

        const submitTrackFilters = () => {
            if (!trackFilterForm) {
                return;
            }

            refreshTrackTable(0);
        };

        if (trackFilterForm) {
            trackFilterForm.addEventListener('submit', (event) => {
                event.preventDefault();
                refreshTrackTable(0);
            });
        }

        if (trackQueryInput) {
            trackQueryInput.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                if (trackSearchTimer) {
                    window.clearTimeout(trackSearchTimer);
                    trackSearchTimer = null;
                }

                submitTrackFilters();
            });

            trackQueryInput.addEventListener('compositionstart', () => {
                trackSearchIsComposing = true;
            });

            trackQueryInput.addEventListener('compositionend', () => {
                trackSearchIsComposing = false;

                if (trackSearchTimer) {
                    window.clearTimeout(trackSearchTimer);
                }

                trackSearchTimer = window.setTimeout(() => {
                    refreshTrackTable(0);
                }, 450);
            });

            trackQueryInput.addEventListener('input', () => {
                if (trackSearchIsComposing) {
                    return;
                }

                if (trackSearchTimer) {
                    window.clearTimeout(trackSearchTimer);
                }

                trackSearchTimer = window.setTimeout(() => {
                    refreshTrackTable(0);
                }, 450);
            });
        }

        [trackStatusInput, trackSortInput].forEach((input) => {
            input?.addEventListener('change', () => {
                if (trackSearchTimer) {
                    window.clearTimeout(trackSearchTimer);
                    trackSearchTimer = null;
                }

                submitTrackFilters();
            });
        });

        const detailModal = document.getElementById('modalNoticeKeepOverlay');
        const closeDetailModalBtn = document.getElementById('closeModalNoticeKeep');
        const detailSubject = document.getElementById('repairDetailSubject');
        const detailStatus = document.getElementById('repairDetailStatus');
        const detailRequester = document.getElementById('repairDetailRequester');
        const detailAssignedTo = document.getElementById('repairDetailAssignedTo');
        const detailCreatedAt = document.getElementById('repairDetailCreatedAt');
        const detailUpdatedAt = document.getElementById('repairDetailUpdatedAt');
        const detailLocation = document.getElementById('repairDetailLocation');
        const detailEquipment = document.getElementById('repairDetailEquipment');
        const detailText = document.getElementById('repairDetailText');
        const detailFileList = document.getElementById('repairDetailFileList');
        const detailSystemFileList = document.getElementById('repairSystemDetailFileList');
        const detailTimeline = document.getElementById('repairDetailTimeline');
        const detailStatusPill = detailModal ? detailModal.querySelector('.header-modal .status-pill') : null;

        const formatFileSize = (size) => {
            const bytes = Number(size || 0);
            if (!Number.isFinite(bytes) || bytes <= 0) {
                return '';
            }
            return `${(bytes / 1024).toFixed(1)} KB`;
        };

        const buildModalFileItem = (file, repairId) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'file-item-wrapper';

            const banner = document.createElement('div');
            banner.className = 'file-banner';

            const info = document.createElement('div');
            info.className = 'file-info';

            const iconWrap = document.createElement('div');
            iconWrap.className = 'file-icon';
            const mime = String(file?.mimeType || '').toLowerCase();
            iconWrap.innerHTML = mime.includes('pdf') ? '<i class="fa-solid fa-file-pdf"></i>' : mime.includes('image') ? '<i class="fa-solid fa-file-image"></i>' : '<i class="fa-solid fa-file"></i>';

            const text = document.createElement('div');
            text.className = 'file-text';
            text.innerHTML = `<span class="file-name">${file?.fileName || '-'}</span><span class="file-type">${formatFileSize(file?.fileSize) || (file?.mimeType || '')}</span>`;

            info.appendChild(iconWrap);
            info.appendChild(text);

            const fileUrl = `public/api/file-download.php?module=repairs&entity_id=${encodeURIComponent(repairId)}&file_id=${encodeURIComponent(file?.fileID || '')}`;
            const actions = document.createElement('div');
            actions.className = 'file-actions-group';
            actions.style.display = 'flex';
            actions.style.gap = '10px';

            const viewAction = document.createElement('div');
            viewAction.className = 'file-actions';
            viewAction.innerHTML = `<a href="${fileUrl}" target="_blank" rel="noopener"><i class="fa-solid fa-eye" aria-hidden="true"></i></a>`;

            const downloadAction = document.createElement('div');
            downloadAction.className = 'file-actions';
            downloadAction.innerHTML = `<a href="${fileUrl}&download=1"><i class="fa-solid fa-download" aria-hidden="true"></i></a>`;

            actions.appendChild(viewAction);
            actions.appendChild(downloadAction);

            banner.appendChild(info);
            banner.appendChild(actions);
            wrapper.appendChild(banner);

            return wrapper;
        };

        const renderModalFiles = (files, repairId, targetList) => {
            if (!targetList) {
                return;
            }

            targetList.innerHTML = '';

            if (!Array.isArray(files) || files.length === 0) {
                targetList.innerHTML = '<div class="content-details-sec" style="margin: 0;"><p>-</p></div>';
                return;
            }

            files.forEach((file) => {
                targetList.appendChild(buildModalFileItem(file, repairId));
            });
        };

        const renderRepairTimeline = (items) => {
            if (!detailTimeline) {
                return;
            }

            detailTimeline.innerHTML = '';

            const header = document.createElement('p');
            header.className = 'timeline-header';
            header.textContent = 'สถานะของงานซ่อมแซม';
            detailTimeline.appendChild(header);

            const timelineItems = Array.isArray(items) ? items : [];

            if (timelineItems.length === 0) {
                const emptyItem = document.createElement('div');
                emptyItem.className = 'timeline-item';

                const emptyContent = document.createElement('div');
                emptyContent.className = 'timeline-content';

                const title = document.createElement('div');
                title.className = 'timeline-title';
                title.textContent = 'ขั้นตอนดำเนินงาน : -';

                const desc = document.createElement('div');
                desc.className = 'timeline-desc';
                desc.textContent = 'ยังไม่มีข้อมูลสถานะการดำเนินงาน';

                const date = document.createElement('div');
                date.className = 'timeline-date';
                date.textContent = '-';

                emptyContent.appendChild(title);
                emptyContent.appendChild(desc);
                emptyContent.appendChild(date);
                emptyItem.appendChild(emptyContent);
                detailTimeline.appendChild(emptyItem);
                return;
            }

            timelineItems.forEach((item) => {
                const timelineItem = document.createElement('div');
                timelineItem.className = 'timeline-item';

                const content = document.createElement('div');
                content.className = 'timeline-content';

                const title = document.createElement('div');
                title.className = 'timeline-title';
                title.textContent = String(item?.title || 'ขั้นตอนดำเนินงาน : -');

                const desc = document.createElement('div');
                desc.className = 'timeline-desc';
                desc.textContent = String(item?.description || '-');

                const date = document.createElement('div');
                date.className = 'timeline-date';
                date.textContent = String(item?.date || '-');

                content.appendChild(title);
                content.appendChild(desc);
                content.appendChild(date);
                timelineItem.appendChild(content);
                detailTimeline.appendChild(timelineItem);
            });
        };

        const openRepairDetailModal = (btn) => {
            let files = [];
            let systemFiles = [];
            let timeline = [];
            try {
                files = JSON.parse(String(btn.getAttribute('data-files') || '[]'));
            } catch (error) {
                files = [];
            }

            try {
                systemFiles = JSON.parse(String(btn.getAttribute('data-system-files') || '[]'));
            } catch (error) {
                systemFiles = [];
            }

            try {
                timeline = JSON.parse(String(btn.getAttribute('data-timeline') || '[]'));
            } catch (error) {
                timeline = [];
            }

            if (detailSubject) detailSubject.value = btn.getAttribute('data-subject') || '-';
            if (detailStatus) detailStatus.value = btn.getAttribute('data-status-label') || '-';
            if (detailRequester) detailRequester.value = btn.getAttribute('data-requester-name') || '-';
            if (detailAssignedTo) detailAssignedTo.value = btn.getAttribute('data-assigned-to-name') || '-';
            if (detailCreatedAt) detailCreatedAt.value = btn.getAttribute('data-created-at') || '-';
            if (detailUpdatedAt) detailUpdatedAt.value = btn.getAttribute('data-updated-at') || '-';
            if (detailLocation) detailLocation.value = btn.getAttribute('data-location') || '-';
            if (detailEquipment) detailEquipment.value = btn.getAttribute('data-equipment') || '-';
            if (detailText) detailText.value = btn.getAttribute('data-detail') || '-';

            if (detailStatusPill) {
                detailStatusPill.className = `status-pill ${btn.getAttribute('data-status-pill') || 'pending'}`;
                detailStatusPill.textContent = btn.getAttribute('data-status-label') || '-';
            }

            const repairId = String(btn.getAttribute('data-repair-id') || '').trim();
            renderModalFiles(files, repairId, detailFileList);
            renderModalFiles(systemFiles, repairId, detailSystemFileList);
            renderRepairTimeline(timeline);

            if (detailModal) detailModal.style.display = 'flex';
        };

        closeDetailModalBtn?.addEventListener('click', () => {
            if (detailModal) detailModal.style.display = 'none';
        });

        detailModal?.addEventListener('click', (event) => {
            if (event.target === detailModal) detailModal.style.display = 'none';
        });

        const editModal = document.getElementById('modalEditOverlay');
        const closeEditModalBtn = document.getElementById('closeModalEdit');
        const editTargetInput = document.getElementById('repairEditId');
        const editSubjectInput = document.getElementById('edit_subject');
        const editLocationInput = document.getElementById('edit_location');
        const editEquipmentInput = document.getElementById('edit_equipment');
        const editDetailInput = document.getElementById('edit_detail');

        const openRepairEditModal = (btn) => {
            if (repairEditUploadApi && typeof repairEditUploadApi.reset === 'function') {
                repairEditUploadApi.reset();
            }

            let files = [];
            try {
                files = JSON.parse(String(btn.getAttribute('data-files') || '[]'));
            } catch (error) {
                files = [];
            }

            if (editTargetInput) editTargetInput.value = String(btn.getAttribute('data-repair-id') || '').trim();
            if (editSubjectInput) editSubjectInput.value = String(btn.getAttribute('data-subject') || '').trim();
            if (editLocationInput) editLocationInput.value = String(btn.getAttribute('data-location') || '').trim();
            if (editEquipmentInput) editEquipmentInput.value = String(btn.getAttribute('data-equipment') || '').trim();
            if (editDetailInput) editDetailInput.value = String(btn.getAttribute('data-detail') || '').trim();

            if (repairEditUploadApi && typeof repairEditUploadApi.setExistingFiles === 'function') {
                repairEditUploadApi.setExistingFiles(files, String(btn.getAttribute('data-repair-id') || '').trim());
            }

            if (editModal) editModal.style.display = 'flex';
        };

        document.addEventListener('click', (event) => {
            const detailBtn = event.target.closest('.js-open-repair-detail-modal');
            if (detailBtn) {
                event.preventDefault();
                openRepairDetailModal(detailBtn);
                return;
            }

            const editBtn = event.target.closest('.js-open-repair-edit-modal');
            if (editBtn) {
                event.preventDefault();
                openRepairEditModal(editBtn);
            }
        });

        closeEditModalBtn?.addEventListener('click', () => {
            if (repairEditUploadApi && typeof repairEditUploadApi.reset === 'function') {
                repairEditUploadApi.reset();
            }
            if (editModal) editModal.style.display = 'none';
        });

        editModal?.addEventListener('click', (event) => {
            if (event.target === editModal) {
                if (repairEditUploadApi && typeof repairEditUploadApi.reset === 'function') {
                    repairEditUploadApi.reset();
                }
                editModal.style.display = 'none';
            }
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
