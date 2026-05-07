<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$requests = (array) ($requests ?? []);
$request_attachments_map = (array) ($request_attachments_map ?? []);
$request_timeline_note_map = (array) ($request_timeline_note_map ?? []);
$all_repair_requests = (array) ($all_repair_requests ?? []);
$all_request_attachments_map = (array) ($all_request_attachments_map ?? []);
$all_request_timeline_map = (array) ($all_request_timeline_map ?? []);
$page = (int) ($page ?? 1);
$total_pages = (int) ($total_pages ?? 1);
$total_count = (int) ($total_count ?? 0);
$view_item = $view_item ?? null;
$view_attachments = (array) ($view_attachments ?? []);
$view_transition_note = (string) ($view_transition_note ?? '');
$base_url = (string) ($base_url ?? 'repairs-approval.php');
$page_title = (string) ($page_title ?? 'ยินดีต้อนรับ');
$page_subtitle = (string) ($page_subtitle ?? 'แจ้งเหตุซ่อมแซม / อนุมัติการซ่อมแซม');
$list_title = (string) ($list_title ?? 'รายการรออนุมัติการซ่อมแซม');
$list_subtitle = (string) ($list_subtitle ?? '');
$empty_title = (string) ($empty_title ?? 'ยังไม่มีรายการรออนุมัติ');
$empty_message = (string) ($empty_message ?? '');
$transition_actions = (array) ($transition_actions ?? []);
$filter_query = (string) ($filter_query ?? '');
$filter_status = (string) ($filter_status ?? 'all');
$filter_sort = (string) ($filter_sort ?? 'newest');
$is_track_active = (bool) ($is_track_active ?? false);
$status_filter_options = (array) ($status_filter_options ?? [
    'all' => 'ทั้งหมด',
    'pending' => 'ส่งคำร้องสำเร็จ',
    'in_progress' => 'กำลังดำเนินการ',
    'completed' => 'ดำเนินการเสร็จสิ้น',
    'cancelled' => 'ยกเลิกคำร้อง',
]);

$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;

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

$format_thai_datetime = static function (?string $datetime) use ($thai_months): string {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }

    $date_obj = DateTime::createFromFormat('Y-m-d H:i:s.u', $datetime);

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    }

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

    return trim($day . ' ' . $month_label . ' ' . $year . ' เวลา ' . $date_obj->format('H:i') . ' น.');
};

$format_thai_date_line = static function (?string $datetime) use ($thai_months): string {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }

    $date_obj = DateTime::createFromFormat('Y-m-d H:i:s.u', $datetime);

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    }

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

    return trim($day . ' ' . $month_label . ' ' . $year);
};

$format_thai_time_line = static function (?string $datetime): string {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }

    $date_obj = DateTime::createFromFormat('Y-m-d H:i:s.u', $datetime);

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    }

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i', $datetime);
    }

    if ($date_obj === false) {
        return '-';
    }

    return 'เวลา ' . $date_obj->format('H:i') . ' น.';
};

$truncate_detail = static function (?string $detail, int $limit = 80): string {
    $detail = preg_replace('/\s+/u', ' ', trim((string) $detail));

    if ($detail === null || $detail === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($detail, 'UTF-8') <= $limit) {
            return $detail;
        }

        return rtrim(mb_substr($detail, 0, $limit, 'UTF-8')) . '...';
    }

    if (strlen($detail) <= $limit) {
        return $detail;
    }

    return rtrim(substr($detail, 0, $limit)) . '...';
};

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

$status_map = (array) ($status_map ?? [
    REPAIR_STATUS_PENDING => ['label' => 'ส่งคำร้องสำเร็จ', 'variant' => 'pending'],
    REPAIR_STATUS_IN_PROGRESS => ['label' => 'กำลังดำเนินการ', 'variant' => 'processing'],
    REPAIR_STATUS_COMPLETED => ['label' => 'ดำเนินการเสร็จสิ้น', 'variant' => 'approved'],
    REPAIR_STATUS_CANCELLED => ['label' => 'ยกเลิกคำร้อง', 'variant' => 'rejected'],
    REPAIR_STATUS_REJECTED => ['label' => 'ยกเลิกคำร้อง', 'variant' => 'rejected'],
]);

$detail_status_key = (string) ($view_item['status'] ?? REPAIR_STATUS_PENDING);
$detail_status = $status_map[$detail_status_key] ?? ['label' => $detail_status_key, 'variant' => 'pending'];
$modal_close_url = $base_url;
$pagination_base_url = $base_url;

if ($page > 1) {
    $modal_close_url .= '?page=' . $page;
}

$build_attachment_payloads = static function (array $attachments, string $requester_pid) use ($json_flags): array {
    $requester_attachment_payload = [];
    $official_attachment_payload = [];

    foreach ($attachments as $file) {
        $payload = [
            'fileID' => (int) ($file['fileID'] ?? 0),
            'fileName' => (string) ($file['fileName'] ?? ''),
            'mimeType' => (string) ($file['mimeType'] ?? ''),
            'fileSize' => (int) ($file['fileSize'] ?? 0),
            'entityName' => (string) ($file['entityName'] ?? ''),
        ];

        $attached_by_pid = trim((string) ($file['attachedByPID'] ?? ''));
        $entity_name = trim((string) ($file['entityName'] ?? ''));
        $is_official_attachment = $entity_name === REPAIR_OFFICIAL_ATTACHMENT_ENTITY_NAME
            || ($attached_by_pid !== '' && $attached_by_pid !== $requester_pid);

        if (!$is_official_attachment && $attached_by_pid !== '' && $attached_by_pid === $requester_pid) {
            $requester_attachment_payload[] = $payload;
        } else {
            $official_attachment_payload[] = $payload;
        }
    }

    $requester_attachment_json = json_encode($requester_attachment_payload, $json_flags);
    $official_attachment_json = json_encode($official_attachment_payload, $json_flags);

    return [
        'requester_json' => is_string($requester_attachment_json) ? $requester_attachment_json : '[]',
        'official_json' => is_string($official_attachment_json) ? $official_attachment_json : '[]',
    ];
};

$view_requester_attachment_json = '[]';
$view_official_attachment_json = '[]';

if (is_array($view_item) && !empty($view_item)) {
    $view_attachment_payloads = $build_attachment_payloads(
        $view_attachments,
        trim((string) ($view_item['requesterPID'] ?? ''))
    );
    $view_requester_attachment_json = $view_attachment_payloads['requester_json'];
    $view_official_attachment_json = $view_attachment_payloads['official_json'];
}

ob_start();
?>

<style>
    .content-circular-notice-index {
        background-color: none;
        border: none;
    }

    .container-circular-notice-sending {
        padding: 0;
        box-shadow: none;
    }

    .container-circular-notice-sending hr {
        background-color: var(--color-secondary);
        height: 2px;
        border: none;
        margin: 24px 0 34px 0;
    }

    .form-group label b {
        font-size: var(--font-size-title);
    }

    .form-group .custom-select-wrapper .custom-options {
        top: 100px;
        transform: translateY(-70px);
    }

    .form-group .custom-select-wrapper .repair-status-option-group {
        display: block;
        padding: 10px 15px 4px;
        font-size: var(--font-size-body-2);
        font-weight: bold;
        color: var(--color-primary-dark);
        cursor: default;
    }

    .booking-table.approval-table td:nth-child(4),
    .booking-table.approval-table td:nth-child(5) {
        text-align: center;
        min-width: auto;
        white-space: nowrap;
    }

    .booking-table.approval-table td:nth-child(3) {
        text-align: left;
        white-space: normal;
    }

    .approval-table .approval-date-time {
        display: block;
        text-align: left;
    }

    .booking-table.approval-table td:nth-child(4) .status-pill {
        margin-left: auto;
        margin-right: auto;
    }

    .booking-table.approval-table td:nth-child(5) .booking-action-group {
        display: flex;
        width: 100%;
        justify-content: center;
    }

    label strong {
        color: var(--color-danger);
    }


    .circular-my-actions {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-direction: row;
        gap: 10px;
    }

    .enterprise-card-title.last {
        margin: 40px 0 0 0;
    }

    @media screen and (max-width: 768px) {
        .container-circular-notice-sending .form-group {
            gap: 0;
        }

        .file-list {
            margin: 0;
        }

        .container-circular-notice-sending .form-group {
            gap: 5px;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index .sender-row {
            margin: 10px 0 0;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index .sender-row .form-group {
            margin-bottom: 10px;
        }
    }

    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(1),
    .table-circular-notice-index table thead th:nth-child(5),
    .table-circular-notice-index table tbody td:nth-child(5) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(2),
    .table-circular-notice-index table thead th:nth-child(3),
    .table-circular-notice-index table thead th:nth-child(4),
    .table-circular-notice-index table tbody td:nth-child(2),
    .circular-my-table td:nth-child(3) {
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
        width: 300px !important;
        min-width: 300px !important;
        max-width: 300px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3),
    .booking-table td:nth-child(3) {
        width: 500px !important;
        min-width: 500px !important;
        max-width: 500px !important;
    }

    .table-circular-notice-index table thead th:nth-child(4),
    .booking-table td:nth-child(4) {
        width: 180px !important;
        min-width: 180px !important;
        max-width: 180px !important;
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
        .table-circular-notice-index table thead th:nth-child(3),
        .table-circular-notice-index table thead th:nth-child(4),
        .table-circular-notice-index table tbody td:nth-child(2),
        .circular-my-table td:nth-child(3) {
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
            width: 250px !important;
            min-width: 250px !important;
            max-width: 250px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3),
        .booking-table td:nth-child(3) {
            width: 450px !important;
            min-width: 450px !important;
            max-width: 450px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4),
        .booking-table td:nth-child(4) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
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
    <h1><?= h($page_title) ?></h1>
    <p><?= h($page_subtitle) ?></p>
</div>


<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
            onclick="openTab('repairs', event)">อนุมัติการซ่อมแซม</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>"
            onclick="openTab('AllRepair', event)">รายการอนุมัติทั้งหมด</button>
    </div>
</div>

<div class="content-area booking-page tab-content <?= $is_track_active ? '' : 'active' ?>" id="repairs">
    <section class="booking-card booking-list-card approval-filter-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title">ค้นหาและกรองรายการ</h2>
            </div>
        </div>

        <form class="approval-toolbar" method="get" action="<?= h($base_url) ?>" id="repairApprovalFilterForm">
            <div class="approval-filter-group">
                <div class="room-admin-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input
                        class="form-input"
                        type="search"
                        name="q"
                        value="<?= h($filter_query) ?>"
                        placeholder="ค้นหาหัวข้อ รายละเอียด วันที่แจ้ง"
                        autocomplete="off">
                </div>
                <div class="room-admin-filter">
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger">
                            <p class="select-value"><?= h((string) ($status_filter_options[$filter_status] ?? $status_filter_options['all'] ?? 'ทั้งหมด')) ?></p>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>

                        <div class="custom-options">
                            <?php foreach ($status_filter_options as $status_value => $status_label) : ?>
                                <div class="custom-option<?= $filter_status === (string) $status_value ? ' selected' : '' ?>" data-value="<?= h((string) $status_value) ?>">
                                    <?= h((string) $status_label) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <select class="form-input" name="status">
                            <?php foreach ($status_filter_options as $status_value => $status_label) : ?>
                                <option value="<?= h((string) $status_value) ?>" <?= $filter_status === (string) $status_value ? 'selected' : '' ?>>
                                    <?= h((string) $status_label) ?>
                                </option>
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
                            <div class="custom-option<?= $filter_sort === 'newest' ? ' selected' : '' ?>" data-value="newest">ใหม่ไปเก่า</div>
                            <div class="custom-option<?= $filter_sort === 'oldest' ? ' selected' : '' ?>" data-value="oldest">เก่าไปใหม่</div>
                        </div>

                        <select class="form-input" name="sort">
                            <option value="newest" <?= $filter_sort === 'newest' ? 'selected' : '' ?>>ใหม่ไปเก่า</option>
                            <option value="oldest" <?= $filter_sort === 'oldest' ? 'selected' : '' ?>>เก่าไปใหม่</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>
    </section>

    <section class="booking-card booking-list-card booking-list-row approval-table-card">
        <div class="booking-card-header">
            <div class="booking-card-title-group">
                <h2 class="booking-card-title"><?= h($list_title) ?></h2>
                <?php if ($list_subtitle !== '') : ?>
                    <p class="booking-card-subtitle"><?= h($list_subtitle) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive table-circular-notice-index">
            <table class="custom-table booking-table approval-table">
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
                            <td colspan="5" class="booking-empty"><?= h($empty_message !== '' ? $empty_message : $empty_title) ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($requests as $req) : ?>
                            <?php
                            $repair_id = (int) ($req['repairID'] ?? 0);
                            $status_key = (string) ($req['status'] ?? REPAIR_STATUS_PENDING);
                            $row_status = $status_map[$status_key] ?? ['label' => $status_key !== '' ? $status_key : '-', 'variant' => 'pending'];
                            $detail_preview = $truncate_detail((string) ($req['detail'] ?? ''), 80);
                            $timeline_note = (string) ($request_timeline_note_map[$repair_id] ?? '');
                            $created_date_line = $format_thai_date_line((string) ($req['createdAt'] ?? ''));
                            $created_time_line = $format_thai_time_line((string) ($req['createdAt'] ?? ''));
                            $requester_attachment_payload = [];
                            $official_attachment_payload = [];

                            foreach ((array) ($request_attachments_map[$repair_id] ?? []) as $file) {
                                $payload = [
                                    'fileID' => (int) ($file['fileID'] ?? 0),
                                    'fileName' => (string) ($file['fileName'] ?? ''),
                                    'mimeType' => (string) ($file['mimeType'] ?? ''),
                                    'fileSize' => (int) ($file['fileSize'] ?? 0),
                                    'entityName' => (string) ($file['entityName'] ?? ''),
                                ];

                                $attached_by_pid = trim((string) ($file['attachedByPID'] ?? ''));
                                $requester_pid = trim((string) ($req['requesterPID'] ?? ''));
                                $entity_name = trim((string) ($file['entityName'] ?? ''));
                                $is_official_attachment = $entity_name === REPAIR_OFFICIAL_ATTACHMENT_ENTITY_NAME
                                    || ($attached_by_pid !== '' && $attached_by_pid !== $requester_pid);

                                if (!$is_official_attachment && $attached_by_pid !== '' && $attached_by_pid === $requester_pid) {
                                    $requester_attachment_payload[] = $payload;
                                } else {
                                    $official_attachment_payload[] = $payload;
                                }
                            }

                            $requester_attachment_json = json_encode($requester_attachment_payload, $json_flags);
                            $official_attachment_json = json_encode($official_attachment_payload, $json_flags);

                            if (!is_string($requester_attachment_json)) {
                                $requester_attachment_json = '[]';
                            }

                            if (!is_string($official_attachment_json)) {
                                $official_attachment_json = '[]';
                            }
                            ?>
                            <tr class="approval-row <?= h((string) ($row_status['variant'] ?? 'pending')) ?>">
                                <td class="booking-action-cell">
                                    <div class="booking-action-group">
                                        <button
                                            type="button"
                                            class="booking-action-btn secondary js-open-repair-approval-detail-modal"
                                            data-vehicle-approval-action="detail"
                                            data-repair-id="<?= h((string) $repair_id) ?>"
                                            data-subject="<?= h((string) ($req['subject'] ?? '-')) ?>"
                                            data-detail="<?= h((string) ($req['detail'] ?? '')) ?>"
                                            data-location="<?= h((string) ($req['location'] ?? '-')) ?>"
                                            data-equipment="<?= h((string) ($req['equipment'] ?? '-')) ?>"
                                            data-created-at="<?= h($format_thai_datetime((string) ($req['createdAt'] ?? ''))) ?>"
                                            data-updated-at="<?= h($format_thai_datetime((string) ($req['updatedAt'] ?? ''))) ?>"
                                            data-resolved-at="<?= h($format_thai_datetime((string) ($req['resolvedAt'] ?? ''))) ?>"
                                            data-requester-name="<?= h((string) ($req['requesterName'] ?? '-')) ?>"
                                            data-assigned-to-name="<?= h((string) ($req['assignedToName'] ?? '-')) ?>"
                                            data-status-key="<?= h($status_key) ?>"
                                            data-status-label="<?= h((string) ($row_status['label'] ?? '-')) ?>"
                                            data-transition-note="<?= h($timeline_note) ?>"
                                            data-requester-files="<?= h($requester_attachment_json) ?>"
                                            data-official-files="<?= h($official_attachment_json) ?>">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                            <span class="tooltip">ดูรายละเอียด</span>
                                        </button>
                                    </div>
                                </td>
                                <td><?= h((string) ($req['subject'] ?? '-')) ?></td>
                                <td><?= h($detail_preview) ?></td>
                                <td>
                                    <span class="approval-date-time">
                                        <?= h($created_date_line) ?>
                                        <span class="detail-subtext"><?= h($created_time_line) ?></span>
                                    </span>
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
</div>

<section class="tab-content enterprise-card <?= $is_track_active ? 'active' : '' ?>" id="AllRepair">
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
            <h2 class="enterprise-card-title last">รายการแจ้งซ่อมทั้งหมด</h2>
        </div>
    </div>

    <div class="table-responsive table-circular-notice-index">
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
                <?php if (empty($all_repair_requests)) : ?>
                    <tr>
                        <td colspan="5" class="booking-empty">ยังไม่มีรายการแจ้งซ่อม</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($all_repair_requests as $req) : ?>
                        <?php
                        $repair_id = (int) ($req['repairID'] ?? 0);
                        $status_key = (string) ($req['status'] ?? REPAIR_STATUS_PENDING);
                        $row_status = $status_map[$status_key] ?? ['label' => $status_key !== '' ? $status_key : '-', 'variant' => 'pending'];
                        $detail_preview = $truncate_detail((string) ($req['detail'] ?? ''), 80);
                        $created_date_line = $format_thai_date_line((string) ($req['createdAt'] ?? ''));
                        $created_time_line = $format_thai_time_line((string) ($req['createdAt'] ?? ''));
                        $attachment_payloads = $build_attachment_payloads(
                            (array) ($all_request_attachments_map[$repair_id] ?? []),
                            trim((string) ($req['requesterPID'] ?? ''))
                        );
                        $timeline_by_status = [];

                        foreach ((array) ($all_request_timeline_map[$repair_id] ?? []) as $timeline_item) {
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

                        if ($timeline_by_status === []) {
                            $fallback_actor_name = trim((string) ($req['assignedToName'] ?? ''));
                            $fallback_date = $format_repair_timeline_datetime((string) ($req['createdAt'] ?? ''));
                            $should_show_fallback_actor = $status_key !== REPAIR_STATUS_PENDING;
                            $timeline_by_status[$status_key] = [
                                'title' => 'ขั้นตอนดำเนินงาน : ' . (string) ($row_status['label'] ?? '-'),
                                'description' => 'ระบบบันทึกสถานะการดำเนินงานเรียบร้อยแล้ว',
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
                                    <button
                                        class="booking-action-btn secondary js-open-repair-detail-modal"
                                        type="button"
                                        data-repair-id="<?= h((string) $repair_id) ?>"
                                        data-subject="<?= h((string) ($req['subject'] ?? '-')) ?>"
                                        data-detail="<?= h((string) ($req['detail'] ?? '')) ?>"
                                        data-location="<?= h((string) ($req['location'] ?? '-')) ?>"
                                        data-equipment="<?= h((string) ($req['equipment'] ?? '-')) ?>"
                                        data-created-at="<?= h($format_thai_datetime((string) ($req['createdAt'] ?? ''))) ?>"
                                        data-updated-at="<?= h($format_thai_datetime((string) ($req['updatedAt'] ?? ''))) ?>"
                                        data-resolved-at="<?= h($format_thai_datetime((string) ($req['resolvedAt'] ?? ''))) ?>"
                                        data-requester-name="<?= h((string) ($req['requesterName'] ?? '-')) ?>"
                                        data-assigned-to-name="<?= h((string) ($req['assignedToName'] ?? '-')) ?>"
                                        data-status-label="<?= h((string) ($row_status['label'] ?? '-')) ?>"
                                        data-status-pill="<?= h((string) ($row_status['variant'] ?? 'pending')) ?>"
                                        data-files="<?= h($attachment_payloads['requester_json']) ?>"
                                        data-system-files="<?= h($attachment_payloads['official_json']) ?>"
                                        data-timeline="<?= h($timeline_json) ?>">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        <span class="tooltip">ดูรายละเอียด</span>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div class="circular-my-subject"><?= h((string) ($req['subject'] ?? '-')) ?></div>
                            </td>

                            <td>
                                <div class="repair-detail-preview"><?= h($detail_preview) ?></div>
                            </td>

                            <td>
                                <div class="repair-date-stack">
                                    <div><?= h($created_date_line) ?></div>
                                    <div class="time"><?= h($created_time_line) ?></div>
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
    <div class="modal-overlay-circular-notice-index outside-person" id="modalNoticeKeepOverlay" style="display: none;">
        <div class="modal-content">

            <div class="header-modal">
                <div class="first-header">
                    <p>รายละเอียดการแจ้งซ่อมของฉัน</p>
                </div>
                <div class="sec-header">
                    <span class="status-pill pending" id="repairDetailStatusPill">ส่งคำร้องสำเร็จ</span>
                    <i class="fa-solid fa-xmark" id="closeModalNoticeKeep" style="cursor: pointer;"></i>
                </div>
            </div>

            <div class="content-modal">
                <form method="" class="container-circular-notice-sending" id="repairDetailForm">

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="repairDetailSubject">หัวข้อ</label>
                            <input type="text" id="repairDetailSubject" disabled>
                        </div>
                        <div class="form-group">
                            <label for="repairDetailLocation">สถานที่</label>
                            <input type="text" id="repairDetailLocation" disabled>
                        </div>
                    </div>

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="repairDetailCreatedAt">วันที่แจ้ง</label>
                            <input type="text" id="repairDetailCreatedAt" disabled>
                        </div>
                        <div class="form-group">
                            <label for="repairDetailUpdatedAt">อัปเดตล่าสุด</label>
                            <input type="text" id="repairDetailUpdatedAt" disabled>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="repairDetailEquipment">อุปกรณ์</label>
                        <input type="text" id="repairDetailEquipment" disabled>
                    </div>

                    <div class="form-group">
                        <label for="repairDetailText">รายละเอียดเพิ่มเติม</label>
                        <textarea id="repairDetailText" rows="4" disabled></textarea>
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
                            <label for="repairDetailAssignedTo">ผู้รับผิดชอบ</label>
                            <input type="text" id="repairDetailAssignedTo" disabled>
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

<script>
    document.addEventListener('DOMContentLoaded', function() {
        let approvalTableCard = document.querySelector('.approval-table-card');
        const approvalFilterForm = document.getElementById('repairApprovalFilterForm');
        const approvalQueryInput = approvalFilterForm ? approvalFilterForm.querySelector('input[name="q"]') : null;
        const approvalStatusInput = approvalFilterForm ? approvalFilterForm.querySelector('select[name="status"]') : null;
        const approvalSortInput = approvalFilterForm ? approvalFilterForm.querySelector('select[name="sort"]') : null;
        let approvalSearchTimer = null;
        let approvalSearchIsComposing = false;
        let approvalFilterController = null;
        let approvalFilterRequestId = 0;

        const buildApprovalFilterUrl = () => {
            if (!approvalFilterForm) {
                return '';
            }

            const action = String(approvalFilterForm.getAttribute('action') || '').trim() || window.location.pathname;
            const params = new URLSearchParams(new FormData(approvalFilterForm));
            const query = params.toString();

            return query !== '' ? `${action}?${query}` : action;
        };

        const refreshApprovalTable = (delayMs = 0) => {
            if (!approvalFilterForm || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
                return;
            }

            window.clearTimeout(approvalSearchTimer);

            approvalSearchTimer = window.setTimeout(async () => {
                const requestUrl = buildApprovalFilterUrl();

                if (requestUrl === '') {
                    return;
                }

                if (!approvalTableCard) {
                    approvalTableCard = document.querySelector('.approval-table-card');
                }

                if (!approvalTableCard) {
                    return;
                }

                if (approvalFilterController) {
                    approvalFilterController.abort();
                }

                const controller = new AbortController();
                approvalFilterController = controller;
                const requestId = ++approvalFilterRequestId;

                approvalTableCard.style.opacity = '0.55';
                approvalTableCard.style.pointerEvents = 'none';

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

                    if (requestId !== approvalFilterRequestId) {
                        return;
                    }

                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const nextApprovalTableCard = doc.querySelector('.approval-table-card');

                    if (!nextApprovalTableCard) {
                        throw new Error('Unable to locate refreshed approval table');
                    }

                    approvalTableCard.outerHTML = nextApprovalTableCard.outerHTML;
                    approvalTableCard = document.querySelector('.approval-table-card');
                    window.history.replaceState({}, '', requestUrl);
                } catch (error) {
                    if (!error || error.name !== 'AbortError') {
                        window.console && console.error && console.error(error);
                    }
                } finally {
                    if (requestId === approvalFilterRequestId) {
                        approvalFilterController = null;
                    }

                    if (approvalTableCard) {
                        approvalTableCard.style.opacity = '';
                        approvalTableCard.style.pointerEvents = '';
                    }
                }
            }, delayMs);
        };

        approvalFilterForm?.addEventListener('submit', function(event) {
            event.preventDefault();
            refreshApprovalTable(0);
        });

        approvalQueryInput?.addEventListener('keydown', function(event) {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            refreshApprovalTable(0);
        });

        approvalQueryInput?.addEventListener('compositionstart', function() {
            approvalSearchIsComposing = true;
        });

        approvalQueryInput?.addEventListener('compositionend', function() {
            approvalSearchIsComposing = false;
            refreshApprovalTable(450);
        });

        approvalQueryInput?.addEventListener('input', function() {
            if (approvalSearchIsComposing) {
                return;
            }

            refreshApprovalTable(450);
        });

        [approvalStatusInput, approvalSortInput].forEach((input) => {
            input?.addEventListener('change', function() {
                refreshApprovalTable(0);
            });
        });

    });

    document.addEventListener('DOMContentLoaded', function() {
        const detailModal = document.getElementById('modalNoticeKeepOverlay');
        const closeDetailModalBtn = document.getElementById('closeModalNoticeKeep');
        const detailSubject = document.getElementById('repairDetailSubject');
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

            if (detailModal) {
                detailModal.classList.remove('hidden');
                detailModal.style.display = 'flex';
            }
        };

        document.addEventListener('click', function(event) {
            const detailBtn = event.target.closest('.js-open-repair-detail-modal');
            if (detailBtn) {
                event.preventDefault();
                openRepairDetailModal(detailBtn);
            }
        });

        closeDetailModalBtn?.addEventListener('click', () => {
            if (detailModal) detailModal.style.display = 'none';
        });

        detailModal?.addEventListener('click', (event) => {
            if (event.target === detailModal) detailModal.style.display = 'none';
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && detailModal && detailModal.style.display === 'flex') {
                detailModal.style.display = 'none';
            }
        });
    });
</script>

<? //php if ($view_item) : 
?>

<div class="content-circular-notice-index">
    <div class="modal-overlay-circular-notice-index" id="vehicleApprovalDetailModal">
        <div class="modal-content">

            <div class="header-modal">
                <div class="first-header">
                    <p>รายละเอียดแจ้งเหตุซ่อมแซม</p>
                </div>
                <div class="sec-header close-modal-btn">
                    <i class="fa-solid fa-xmark" id="closeVehicleApprovalDetailModal" style="cursor: pointer;"></i>
                </div>
            </div>

            <div class="content-modal">

                <form method="" class="container-circular-notice-sending" id="repairs">

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="">หัวข้อ</label>
                            <input type="text" id="repairApprovalDetailSubject" placeholder="ระบุหัวข้อที่ต้องการแจ้งซ่อม" disabled>
                        </div>
                        <div class="form-group">
                            <label for="">สถานที่</label>
                            <input type="text" id="repairApprovalDetailLocation" placeholder="เช่น อาคาร 1 ห้อง 205" disabled>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="">อุปกรณ์</label>
                        <input type="text" id="repairApprovalDetailEquipment" placeholder="เช่น โปรเจคเตอร์ / เครื่องปรับอากาศ" disabled>
                    </div>

                    <div class="form-group">
                        <label for="">รายละเอียดเพิ่มเติม</label>
                        <textarea id="repairApprovalDetailText" name="" rows="4" placeholder="อธิบายอาการหรือปัญหาที่พบ" disabled></textarea>
                    </div>

                    <div class="form-group">
                        <label>อัปโหลดไฟล์เอกสาร</label>
                        <section class="upload-layout">
                            <div class="file-list" id="repairApprovalDetailFileList"></div>
                        </section>
                    </div>

                    <div class="sender-row">
                        <div class="form-group">
                            <label for="">ผู้ส่ง</label>
                            <input type="text" id="repairApprovalDetailRequester" placeholder="นางสาวทิพยรัตน์ บุญมณี" disabled>
                        </div>
                        <div class="form-group">
                            <label for="">วันที่แจ้ง</label>
                            <input type="text" id="repairApprovalDetailCreatedAt" placeholder="29 มกราคม 2569 เวลา 13:17น." disabled>
                        </div>
                    </div>

                    <hr>

                    <div class="form-group">
                        <label for=""><b>การพิจารณา</b></label>
                    </div>

                    <div class="form-group">
                        <div class="custom-select-wrapper open">
                            <div class="custom-select-trigger">
                                <p class="select-value" id="repairApprovalDetailStatusValue">กรุณาเลือกสถานะการดำเนินงาน</p>
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </div>

                            <div class="custom-options">
                                <div class="custom-option selected" data-value="">กรุณาเลือกสถานะการดำเนินงาน</div>
                                <div class="custom-option" data-value="<?= h(REPAIR_STATUS_IN_PROGRESS) ?>">กำลังดำเนินการ</div>
                                <div class="custom-option" data-value="<?= h(REPAIR_STATUS_COMPLETED) ?>">ดำเนินการเสร็จสิ้น</div>
                                <div class="custom-option" data-value="<?= h(REPAIR_STATUS_CANCELLED) ?>">ยกเลิกคำร้อง</div>
                            </div>

                            <select class="form-input" id="repairApprovalDetailStatusSelect" name="status">
                                <option value="">กรุณาเลือกสถานะการดำเนินงาน</option>
                                <option value="<?= h(REPAIR_STATUS_IN_PROGRESS) ?>">กำลังดำเนินการ</option>
                                <option value="<?= h(REPAIR_STATUS_COMPLETED) ?>">ดำเนินการเสร็จสิ้น</option>
                                <option value="<?= h(REPAIR_STATUS_CANCELLED) ?>">ยกเลิกคำร้อง</option>
                            </select>

                        </div>
                    </div>

                    <div class="form-group">
                        <label for="">รายละเอียดเพิ่มเติม</label>
                        <textarea id="repairApprovalDecisionSummary" name="" rows="4" placeholder="ระบุรายละเอียดการดำเนินงานของเจ้าหน้าที่"></textarea>
                    </div>

                    <div class="vehicle-row file-sec">
                        <div class="vehicle-input-content">
                            <label>แนบเอกสาร <strong>(แนบเอกสารได้สูงสุด 5 ไฟล์)</strong></label>
                            <div>
                                <button type="button" class="btn btn-upload-small"
                                    onclick="document.getElementById('attachment').click()">
                                    <p>เพิ่มไฟล์</p>
                                </button>
                            </div>
                            <input type="file" id="attachment" name="attachments[]" class="file-input" multiple
                                accept=".pdf,image/png,image/jpeg,.jpg,.jpeg" form="repairApprovalTransitionForm" hidden>
                            <p class="form-error hidden" id="attachmentError">แนบได้สูงสุด 5 ไฟล์</p>
                        </div>

                        <div class="file-list" id="attachmentList" aria-live="polite"></div>
                    </div>

                </form>
            </div>

            <div class="footer-modal">
                <form method="POST" action="<?= h($base_url) ?>" id="repairApprovalTransitionForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="transition">
                    <input type="hidden" name="repair_id" id="repairApprovalTransitionRepairId" value="">
                    <input type="hidden" name="target_status" id="repairApprovalTransitionTargetStatus" value="">
                    <input type="hidden" name="transition_note" id="repairApprovalTransitionNote" value="">
                    <button type="submit">
                        <p>บันทึก</p>
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<?php if (is_array($view_item) && !empty($view_item)) : ?>
    <button
        type="button"
        id="repairApprovalAutoOpenTrigger"
        class="hidden"
        data-repair-id="<?= h((string) ((int) ($view_item['repairID'] ?? 0))) ?>"
        data-subject="<?= h((string) ($view_item['subject'] ?? '-')) ?>"
        data-detail="<?= h((string) ($view_item['detail'] ?? '')) ?>"
        data-location="<?= h((string) ($view_item['location'] ?? '-')) ?>"
        data-equipment="<?= h((string) ($view_item['equipment'] ?? '-')) ?>"
        data-created-at="<?= h($format_thai_datetime((string) ($view_item['createdAt'] ?? ''))) ?>"
        data-updated-at="<?= h($format_thai_datetime((string) ($view_item['updatedAt'] ?? ''))) ?>"
        data-resolved-at="<?= h($format_thai_datetime((string) ($view_item['resolvedAt'] ?? ''))) ?>"
        data-requester-name="<?= h((string) ($view_item['requesterName'] ?? '-')) ?>"
        data-assigned-to-name="<?= h((string) ($view_item['assignedToName'] ?? '-')) ?>"
        data-status-key="<?= h((string) ($view_item['status'] ?? REPAIR_STATUS_PENDING)) ?>"
        data-status-label="<?= h((string) ($detail_status['label'] ?? '-')) ?>"
        data-transition-note="<?= h($view_transition_note) ?>"
        data-requester-files="<?= h($view_requester_attachment_json) ?>"
        data-official-files="<?= h($view_official_attachment_json) ?>">
    </button>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('vehicleApprovalDetailModal');
        const closeBtn = document.getElementById('closeVehicleApprovalDetailModal');
        const subjectInput = document.getElementById('repairApprovalDetailSubject');
        const locationInput = document.getElementById('repairApprovalDetailLocation');
        const equipmentInput = document.getElementById('repairApprovalDetailEquipment');
        const detailTextarea = document.getElementById('repairApprovalDetailText');
        const requesterInput = document.getElementById('repairApprovalDetailRequester');
        const createdAtInput = document.getElementById('repairApprovalDetailCreatedAt');
        const statusValue = document.getElementById('repairApprovalDetailStatusValue');
        const statusSelect = document.getElementById('repairApprovalDetailStatusSelect');
        const statusOptionsContainer = modal ? modal.querySelector('.custom-options') : null;
        let statusOptions = statusOptionsContainer ? Array.from(statusOptionsContainer.querySelectorAll('.custom-option')) : [];
        const requesterFileList = document.getElementById('repairApprovalDetailFileList');
        const decisionSummary = document.getElementById('repairApprovalDecisionSummary');
        const transitionForm = document.getElementById('repairApprovalTransitionForm');
        const transitionRepairId = document.getElementById('repairApprovalTransitionRepairId');
        const transitionTargetStatus = document.getElementById('repairApprovalTransitionTargetStatus');
        const transitionNote = document.getElementById('repairApprovalTransitionNote');
        const attachmentInput = document.getElementById('attachment');
        const attachmentError = document.getElementById('attachmentError');
        const attachmentList = document.getElementById('attachmentList');
        const pendingStatus = '<?= h(REPAIR_STATUS_PENDING) ?>';
        const inProgressStatus = '<?= h(REPAIR_STATUS_IN_PROGRESS) ?>';
        const completedStatus = '<?= h(REPAIR_STATUS_COMPLETED) ?>';
        const cancelledStatus = '<?= h(REPAIR_STATUS_CANCELLED) ?>';
        const placeholderStatusLabel = 'กรุณาเลือกสถานะการดำเนินงาน';
        const statusLabels = {
            '<?= h(REPAIR_STATUS_PENDING) ?>': 'ส่งคำร้องสำเร็จ',
            '<?= h(REPAIR_STATUS_IN_PROGRESS) ?>': 'กำลังดำเนินการ',
            '<?= h(REPAIR_STATUS_COMPLETED) ?>': 'ดำเนินการเสร็จสิ้น',
            '<?= h(REPAIR_STATUS_CANCELLED) ?>': 'ยกเลิกคำร้อง',
            '<?= h(REPAIR_STATUS_REJECTED) ?>': 'ยกเลิกคำร้อง',
        };
        let activeRepairStatus = pendingStatus;
        let transitionConfirmApproved = false;
        const maxApprovalAttachments = 5;
        const approvalAllowedTypes = ['application/pdf', 'image/png', 'image/jpeg'];
        let existingOfficialApprovalFiles = [];
        let existingApprovalRepairId = '';
        let selectedApprovalFiles = [];

        if (!modal) {
            return;
        }

        const formatFileSize = (size) => {
            const bytes = Number(size || 0);
            if (!Number.isFinite(bytes) || bytes <= 0) {
                return '';
            }

            return `${(bytes / 1024).toFixed(1)} KB`;
        };

        const revokeApprovalPreviewUrls = () => {
            selectedApprovalFiles.forEach((file) => {
                if (file.previewUrl) {
                    URL.revokeObjectURL(file.previewUrl);
                }
            });
        };

        const syncApprovalFiles = () => {
            if (!attachmentInput) {
                return;
            }

            const dataTransfer = new DataTransfer();
            selectedApprovalFiles.forEach((file) => dataTransfer.items.add(file));
            attachmentInput.files = dataTransfer.files;
        };

        const setAttachmentError = (message = '') => {
            if (!attachmentError) {
                return;
            }

            if (String(message).trim() === '') {
                attachmentError.textContent = 'แนบได้สูงสุด 5 ไฟล์';
                attachmentError.classList.add('hidden');
                return;
            }

            attachmentError.textContent = message;
            attachmentError.classList.remove('hidden');
        };

        const renderApprovalAttachments = () => {
            if (!attachmentList) {
                return;
            }

            attachmentList.innerHTML = '';

            const hasExistingFiles = Array.isArray(existingOfficialApprovalFiles) && existingOfficialApprovalFiles.length > 0;
            const hasSelectedFiles = selectedApprovalFiles.length > 0;

            if (!hasExistingFiles && !hasSelectedFiles) {
                return;
            }

            if (hasExistingFiles) {
                existingOfficialApprovalFiles.forEach((file) => {
                    attachmentList.appendChild(buildModalFileItem(file, existingApprovalRepairId));
                });
            }

            selectedApprovalFiles.forEach((file, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper';

                const banner = document.createElement('div');
                banner.className = 'file-banner';

                const info = document.createElement('div');
                info.className = 'file-info';

                const iconWrap = document.createElement('div');
                iconWrap.className = 'file-icon';
                const mime = String(file.type || '').toLowerCase();
                iconWrap.innerHTML = mime.includes('pdf') ?
                    '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                    mime.includes('image') ?
                    '<i class="fa-solid fa-file-image" aria-hidden="true"></i>' :
                    '<i class="fa-solid fa-file" aria-hidden="true"></i>';

                const text = document.createElement('div');
                text.className = 'file-text';
                text.innerHTML = `<span class="file-name">${file.name}</span><span class="file-type">${formatFileSize(file.size) || '-'}</span>`;

                info.appendChild(iconWrap);
                info.appendChild(text);

                const actions = document.createElement('div');
                actions.className = 'file-actions-group';
                actions.style.display = 'flex';
                actions.style.gap = '10px';

                const viewAction = document.createElement('div');
                viewAction.className = 'file-actions';
                const viewLink = document.createElement('a');
                viewLink.href = file.previewUrl;
                viewLink.target = '_blank';
                viewLink.rel = 'noopener';
                viewLink.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
                viewAction.appendChild(viewLink);

                const deleteAction = document.createElement('div');
                deleteAction.className = 'file-actions';
                const deleteButton = document.createElement('button');
                deleteButton.type = 'button';
                deleteButton.className = 'delete-btn';
                deleteButton.style.border = 'none';
                deleteButton.style.background = 'none';
                deleteButton.style.cursor = 'pointer';
                deleteButton.style.color = '#dc3545';
                deleteButton.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
                deleteButton.addEventListener('click', function() {
                    if (file.previewUrl) {
                        URL.revokeObjectURL(file.previewUrl);
                    }
                    selectedApprovalFiles = selectedApprovalFiles.filter((_, fileIndex) => fileIndex !== index);
                    syncApprovalFiles();
                    renderApprovalAttachments();
                    setAttachmentError('');
                });
                deleteAction.appendChild(deleteButton);

                actions.appendChild(viewAction);
                actions.appendChild(deleteAction);
                banner.appendChild(info);
                banner.appendChild(actions);
                wrapper.appendChild(banner);
                attachmentList.appendChild(wrapper);
            });
        };

        const resetApprovalAttachments = () => {
            revokeApprovalPreviewUrls();
            existingOfficialApprovalFiles = [];
            existingApprovalRepairId = '';
            selectedApprovalFiles = [];
            syncApprovalFiles();
            if (attachmentInput) {
                attachmentInput.value = '';
            }
            if (attachmentList) {
                attachmentList.innerHTML = '';
            }
            setAttachmentError('');
        };

        const addApprovalFiles = (fileListInput) => {
            if (!attachmentInput || !fileListInput) {
                return;
            }

            const existingKeys = new Set(selectedApprovalFiles.map((file) => `${file.name}-${file.size}-${file.lastModified}`));
            let hasInvalidType = false;
            let hitMaxFiles = false;

            Array.from(fileListInput).forEach((file) => {
                const key = `${file.name}-${file.size}-${file.lastModified}`;

                if (existingKeys.has(key)) {
                    return;
                }

                if (!approvalAllowedTypes.includes(String(file.type || '').toLowerCase())) {
                    hasInvalidType = true;
                    return;
                }

                if (selectedApprovalFiles.length >= maxApprovalAttachments) {
                    hitMaxFiles = true;
                    return;
                }

                file.previewUrl = URL.createObjectURL(file);
                selectedApprovalFiles.push(file);
                existingKeys.add(key);
            });

            syncApprovalFiles();
            renderApprovalAttachments();

            if (selectedApprovalFiles.length > maxApprovalAttachments) {
                selectedApprovalFiles = selectedApprovalFiles.slice(0, maxApprovalAttachments);
                syncApprovalFiles();
                renderApprovalAttachments();
            }

            if (hasInvalidType) {
                setAttachmentError('แนบได้เฉพาะไฟล์ PDF, PNG, JPG หรือ JPEG');
            } else if (hitMaxFiles || (selectedApprovalFiles.length >= maxApprovalAttachments && Array.from(fileListInput).length > maxApprovalAttachments)) {
                setAttachmentError('แนบได้สูงสุด 5 ไฟล์');
            } else {
                setAttachmentError('');
            }
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
            iconWrap.innerHTML = mime.includes('pdf') ?
                '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                mime.includes('image') ?
                '<i class="fa-solid fa-file-image" aria-hidden="true"></i>' :
                '<i class="fa-solid fa-file" aria-hidden="true"></i>';

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

        const renderRequesterFiles = (files, repairId) => {
            if (!requesterFileList) {
                return;
            }

            requesterFileList.innerHTML = '';

            if (!Array.isArray(files) || files.length === 0) {
                requesterFileList.innerHTML = '<div class="content-details-sec" style="margin: 0;"><p>-</p></div>';
                return;
            }

            files.forEach((file) => {
                requesterFileList.appendChild(buildModalFileItem(file, repairId));
            });
        };

        const setSelectedStatusOption = (value) => {
            statusOptions.forEach((option) => {
                option.classList.toggle('selected', String(option.dataset.value || '') === value);
            });
        };

        const getAllowedTargetStatuses = () => {
            if (activeRepairStatus === pendingStatus) {
                return [inProgressStatus, completedStatus, cancelledStatus];
            }

            if (activeRepairStatus === inProgressStatus) {
                return [completedStatus, cancelledStatus];
            }

            return [];
        };

        const isAllowedTargetStatus = (value) => {
            return value === '' ||
                (activeRepairStatus !== pendingStatus && value === activeRepairStatus) ||
                getAllowedTargetStatuses().includes(value);
        };

        const confirmTransitionSubmit = () => {
            const message = 'ยืนยันการบันทึกการพิจารณาใช่หรือไม่?';
            const options = {
                title: 'ยืนยันการบันทึก',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
            };

            if (window.AppAlerts && typeof window.AppAlerts.confirm === 'function') {
                return window.AppAlerts.confirm(message, options);
            }

            return Promise.resolve(window.confirm(`${options.title}\n${message}`));
        };

        const createStatusOption = (value, label, selected = false) => {
            const option = document.createElement('div');
            option.className = selected ? 'custom-option selected' : 'custom-option';
            option.dataset.value = value;
            option.textContent = label;

            return option;
        };

        const createStatusGroupLabel = (label) => {
            const group = document.createElement('div');
            group.className = 'repair-status-option-group';
            group.setAttribute('role', 'presentation');
            group.textContent = label;

            return group;
        };

        const createNativeStatusOption = (value, label, selected = false) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            option.selected = selected;

            return option;
        };

        const renderStatusChoices = () => {
            if (!statusOptionsContainer || !statusSelect) {
                return;
            }

            const isPending = activeRepairStatus === pendingStatus;
            const currentLabel = statusLabels[activeRepairStatus] || activeRepairStatus || '-';
            const allowedTargets = getAllowedTargetStatuses();

            statusOptionsContainer.innerHTML = '';
            statusSelect.innerHTML = '';

            if (isPending) {
                const pendingChoices = [
                    ['', placeholderStatusLabel],
                    [inProgressStatus, statusLabels[inProgressStatus]],
                    [completedStatus, statusLabels[completedStatus]],
                    [cancelledStatus, statusLabels[cancelledStatus]],
                ];

                pendingChoices.forEach(([value, label], index) => {
                    statusOptionsContainer.appendChild(createStatusOption(value, label, index === 0));
                    statusSelect.appendChild(createNativeStatusOption(value, label, index === 0));
                });
            } else {
                statusOptionsContainer.appendChild(createStatusGroupLabel('สถานะเดิม'));
                statusOptionsContainer.appendChild(createStatusOption(activeRepairStatus, currentLabel, true));

                const currentGroup = document.createElement('optgroup');
                currentGroup.label = 'สถานะเดิม';
                currentGroup.appendChild(createNativeStatusOption(activeRepairStatus, currentLabel, true));
                statusSelect.appendChild(currentGroup);

                if (allowedTargets.length > 0) {
                    statusOptionsContainer.appendChild(createStatusGroupLabel('สถานะอื่นๆ'));

                    const nextGroup = document.createElement('optgroup');
                    nextGroup.label = 'สถานะอื่นๆ';

                    allowedTargets.forEach((value) => {
                        const label = statusLabels[value] || value;
                        statusOptionsContainer.appendChild(createStatusOption(value, label));
                        nextGroup.appendChild(createNativeStatusOption(value, label));
                    });

                    statusSelect.appendChild(nextGroup);
                }
            }

            statusOptions = Array.from(statusOptionsContainer.querySelectorAll('.custom-option'));
        };

        const syncStatusDisplay = (statusKey, statusLabel) => {
            const normalizedStatusKey = String(statusKey || pendingStatus).trim().toUpperCase();
            const isPending = normalizedStatusKey === pendingStatus;
            const nextSelectValue = isPending ? '' : normalizedStatusKey;
            const nextLabel = isPending ?
                placeholderStatusLabel :
                (statusLabel || statusLabels[normalizedStatusKey] || '-');

            activeRepairStatus = normalizedStatusKey;
            renderStatusChoices();

            if (statusSelect) {
                statusSelect.value = nextSelectValue;
            }

            if (statusValue) {
                statusValue.textContent = nextLabel;
            }

            if (transitionTargetStatus) {
                transitionTargetStatus.value = isPending ? '' : normalizedStatusKey;
            }

            setSelectedStatusOption(nextSelectValue);
        };

        const openModal = (button) => {
            let requesterFiles = [];
            let officialFiles = [];

            try {
                requesterFiles = JSON.parse(String(button.getAttribute('data-requester-files') || '[]'));
            } catch (error) {
                requesterFiles = [];
            }

            try {
                officialFiles = JSON.parse(String(button.getAttribute('data-official-files') || '[]'));
            } catch (error) {
                officialFiles = [];
            }

            if (subjectInput) {
                subjectInput.value = button.getAttribute('data-subject') || '-';
            }

            if (locationInput) {
                locationInput.value = button.getAttribute('data-location') || '-';
            }

            if (equipmentInput) {
                equipmentInput.value = button.getAttribute('data-equipment') || '-';
            }

            if (detailTextarea) {
                detailTextarea.value = button.getAttribute('data-detail') || '-';
            }

            if (requesterInput) {
                requesterInput.value = button.getAttribute('data-requester-name') || '-';
            }

            if (createdAtInput) {
                createdAtInput.value = button.getAttribute('data-created-at') || '-';
            }

            syncStatusDisplay(
                String(button.getAttribute('data-status-key') || pendingStatus).trim(),
                String(button.getAttribute('data-status-label') || statusLabels[pendingStatus]).trim()
            );

            if (decisionSummary) {
                decisionSummary.value = button.getAttribute('data-transition-note') || '';
            }

            if (transitionNote) {
                transitionNote.value = '';
            }

            if (transitionRepairId) {
                transitionRepairId.value = String(button.getAttribute('data-repair-id') || '').trim();
            }

            resetApprovalAttachments();
            existingOfficialApprovalFiles = Array.isArray(officialFiles) ? officialFiles : [];
            existingApprovalRepairId = String(button.getAttribute('data-repair-id') || '').trim();
            renderRequesterFiles(Array.isArray(requesterFiles) ? requesterFiles : [], existingApprovalRepairId);
            renderApprovalAttachments();
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        };

        const closeModal = () => {
            resetApprovalAttachments();
            modal.classList.add('hidden');
            modal.style.display = 'none';
        };

        statusOptionsContainer?.addEventListener('click', function(event) {
            if (!(event.target instanceof Element)) {
                return;
            }

            const option = event.target.closest('.custom-option');

            if (option && statusOptionsContainer.contains(option)) {
                const value = String(option.dataset.value || '').trim().toUpperCase();
                const label = String(option.textContent || '').trim() || placeholderStatusLabel;

                if (!isAllowedTargetStatus(value)) {
                    return;
                }

                if (statusSelect) {
                    statusSelect.value = value;
                }

                if (statusValue) {
                    statusValue.textContent = label;
                }

                if (transitionTargetStatus) {
                    transitionTargetStatus.value = value;
                }

                setSelectedStatusOption(value);
                option.closest('.custom-select-wrapper')?.classList.remove('open');
                event.stopPropagation();
            }
        });

        statusSelect?.addEventListener('change', function() {
            const value = String(statusSelect.value || '').trim().toUpperCase();
            const selected = statusSelect.options[statusSelect.selectedIndex];
            const label = String(selected?.textContent || '').trim() || placeholderStatusLabel;

            if (!isAllowedTargetStatus(value)) {
                statusSelect.value = '';

                if (statusValue) {
                    statusValue.textContent = placeholderStatusLabel;
                }

                if (transitionTargetStatus) {
                    transitionTargetStatus.value = '';
                }

                setSelectedStatusOption('');
                return;
            }

            if (statusValue) {
                statusValue.textContent = label;
            }

            if (transitionTargetStatus) {
                transitionTargetStatus.value = value;
            }

            setSelectedStatusOption(value);
        });

        attachmentInput?.addEventListener('change', function(event) {
            addApprovalFiles(event.target?.files || []);
        });

        transitionForm?.addEventListener('submit', function(event) {
            const selectedStatus = String(transitionTargetStatus?.value || '').trim().toUpperCase();
            const noteText = String(decisionSummary?.value || '').trim();
            const allowedStatuses = getAllowedTargetStatuses();
            const isSameStatusNoteUpdate = activeRepairStatus !== pendingStatus && selectedStatus === activeRepairStatus;
            const hasApprovalFiles = selectedApprovalFiles.length > 0;

            if (transitionNote) {
                transitionNote.value = noteText;
            }

            if (selectedApprovalFiles.length > maxApprovalAttachments) {
                event.preventDefault();
                setAttachmentError('แนบได้สูงสุด 5 ไฟล์');
                return;
            }

            if (isSameStatusNoteUpdate && noteText === '' && !hasApprovalFiles) {
                event.preventDefault();
                window.alert('กรุณากรอกรายละเอียดเพิ่มเติม แนบไฟล์ หรือเลือกสถานะอื่น');
                return;
            }

            if (!isSameStatusNoteUpdate && allowedStatuses.length === 0) {
                event.preventDefault();
                window.alert('รายการนี้ไม่สามารถเปลี่ยนสถานะได้');
                return;
            }

            if (!isSameStatusNoteUpdate && (selectedStatus === '' || !allowedStatuses.includes(selectedStatus))) {
                event.preventDefault();
                window.alert(placeholderStatusLabel);
                return;
            }

            if (transitionConfirmApproved) {
                transitionConfirmApproved = false;
                return;
            }

            event.preventDefault();
            confirmTransitionSubmit().then(function(approved) {
                if (!approved) {
                    return;
                }

                transitionConfirmApproved = true;

                if (typeof transitionForm.requestSubmit === 'function') {
                    transitionForm.requestSubmit();
                    return;
                }

                transitionForm.submit();
            });
        });

        document.addEventListener('click', function(event) {
            const openBtn = event.target.closest('.js-open-repair-approval-detail-modal, [data-vehicle-approval-action="detail"]');

            if (openBtn) {
                event.preventDefault();
                openModal(openBtn);
                return;
            }

            if (event.target.closest('#closeVehicleApprovalDetailModal')) {
                event.preventDefault();
                closeModal();
            }
        });

        closeBtn?.addEventListener('click', function(event) {
            event.preventDefault();
            closeModal();
        });

        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'flex') {
                closeModal();
            }
        });

        const autoOpenTrigger = document.getElementById('repairApprovalAutoOpenTrigger');

        if (autoOpenTrigger) {
            openModal(autoOpenTrigger);
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const sliders = document.querySelectorAll('.table-circular-notice-index');

        sliders.forEach((slider) => {
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
    });
</script>
<? //php endif; 
?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
