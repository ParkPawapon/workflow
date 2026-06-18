<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$items = (array) ($items ?? []);
$page = (int) ($page ?? 1);
$total_pages = (int) ($total_pages ?? 1);
$search = trim((string) ($search ?? ''));
$status_filter = (string) ($status_filter ?? 'all');
$sort = (string) ($sort ?? 'newest');
$dh_year_options = array_values(array_filter(array_map('intval', (array) ($dh_year_options ?? [])), static function (int $year): bool {
    return $year > 0;
}));
$selected_dh_year = (int) ($selected_dh_year ?? 0);
$pagination_base_url = (string) ($pagination_base_url ?? 'orders-archive.php');

$status_options = [
    'all' => 'ทั้งหมด',
    'read' => 'อ่านแล้ว',
    'unread' => 'ยังไม่อ่าน',
];

$sort_options = [
    'newest' => 'ใหม่ไปเก่า',
    'oldest' => 'เก่าไปใหม่',
];

$status_label = $status_options[$status_filter] ?? $status_options['all'];
$sort_label = $sort_options[$sort] ?? $sort_options['newest'];

if ($selected_dh_year <= 0) {
    $selected_dh_year = (int) ($dh_year_options[0] ?? 0);
}
$dh_year_label = $selected_dh_year > 0 ? (string) $selected_dh_year : '-';

$action_params = [];

if ($search !== '') {
    $action_params['q'] = $search;
}

if ($status_filter !== 'all') {
    $action_params['status'] = $status_filter;
}

if ($sort !== 'newest') {
    $action_params['sort'] = $sort;
}

$action_params['dh_year'] = (string) $selected_dh_year;

if ($page > 1) {
    $action_params['page'] = (string) $page;
}

$post_action_url = 'orders-archive.php';

if (!empty($action_params)) {
    $post_action_url .= '?' . http_build_query($action_params);
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

$format_thai_received_datetime = static function (?string $datetime_value) use ($thai_months): array {
    $text = trim((string) $datetime_value);

    if ($text === '' || $text === '-') {
        return ['date' => '-', 'time' => ''];
    }

    $timestamp = strtotime($text);

    if ($timestamp === false) {
        return ['date' => $text, 'time' => ''];
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year_be = (int) date('Y', $timestamp) + 543;
    $month_label = $thai_months[$month] ?? '';
    $date_label = $day . ' ' . $month_label . ' ' . $year_be;
    $time_label = date('H:i', $timestamp) . ' น.';

    return ['date' => trim($date_label), 'time' => $time_label];
};

$parse_order_detail = static function (?string $detail): array {
    $text = trim((string) $detail);
    $result = [
        'effective_date' => '',
        'order_date' => '',
        'issuer_name' => '',
        'group_name' => '',
    ];

    if ($text === '') {
        return $result;
    }

    if (preg_match('/^ทั้งนี้ตั้งแต่วันที่:\s*(.+)$/m', $text, $matches) === 1) {
        $date = trim((string) ($matches[1] ?? ''));
        $result['effective_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }

    if (preg_match('/^สั่ง ณ วันที่:\s*(.+)$/m', $text, $matches) === 1) {
        $date = trim((string) ($matches[1] ?? ''));
        $result['order_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }

    if (preg_match('/^ผู้(?:ออก|สร้าง)เลขคำสั่ง:\s*(.+)$/m', $text, $matches) === 1) {
        $value = trim((string) ($matches[1] ?? ''));
        $result['issuer_name'] = $value !== '-' ? $value : '';
    }

    if (preg_match('/^กลุ่ม:\s*(.+)$/m', $text, $matches) === 1) {
        $value = trim((string) ($matches[1] ?? ''));
        $result['group_name'] = $value !== '-' ? $value : '';
    }

    return $result;
};

$normalize_order_files = static function (array $files): array {
    $normalized = [];

    foreach ($files as $file) {
        $file_id = (int) ($file['fileID'] ?? 0);

        if ($file_id <= 0) {
            continue;
        }

        $normalized[] = [
            'fileID' => $file_id,
            'fileName' => trim((string) ($file['fileName'] ?? '')),
            'mimeType' => trim((string) ($file['mimeType'] ?? '')),
        ];
    }

    return $normalized;
};

ob_start();
?>

<style>
    .orders-send-modal-shell {
        display: none;
    }

    .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec {
        border-bottom: none
    }

    .circular-track-modal-host {
        width: 0;
        height: 0;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
    }


    .table-circular-notice-keep table thead th:nth-child(1) {
        min-width: 500px !important;
        max-width: 500px !important;
    }

    .table-circular-notice-keep table thead th:nth-child(2) {
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
    }

    .table-circular-notice-keep table thead th:nth-child(3) {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }

    .table-circular-notice-keep table thead th:nth-child(4) {
        width: 140px !important;
        min-width: 140px !important;
        max-height: 140px !important;
    }

    .table-circular-notice-keep table thead th:nth-child(5) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-topic-sec:nth-child(2),
    .content-circular-notice-index .modal-overlay-circular-notice-index .modal-content .content-modal .content-topic-sec:nth-child(3) {
        border-bottom: none !important;
    }

    @media screen and (min-width: 769px) and (max-width: 1023px) {
        .table-circular-notice-keep table thead th:nth-child(1) {
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(2) {
            width: 280px !important;
            min-width: 280px !important;
            max-width: 280px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(3) {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 160px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(4) {
            width: 100px !important;
            min-width: 100px !important;
            max-height: 100px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(5) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec .file-section {
            padding: 0;
        }

        .circular-track-modal-host #modalOrderViewOverlay .content-topic-sec {
            padding: 0;
        }
    }

    @media screen and (max-width: 768px) {
        .table-circular-notice-keep table thead th:nth-child(1) {
            min-width: 500px !important;
            max-width: 500px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(2) {
            width: 200px !important;
            min-width: 200px !important;
            max-width: 200px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(3) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(4) {
            width: 100px !important;
            min-width: 100px !important;
            max-height: 100px !important;
        }

        .table-circular-notice-keep table thead th:nth-child(5) {
            width: 80px !important;
            min-width: 80px !important;
            max-width: 80px !important;
        }

        .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-file-sec .file-section {
            padding: 0;
        }

        .circular-track-modal-host #modalOrderViewOverlay .content-topic-sec {
            padding: 0;
        }
    }
</style>

<div class="content-header">
    <h1>ที่จัดเก็บคำสั่งราชการ</h1>
    <p>คำสั่งราชการ / ที่จัดเก็บคำสั่งราชการ</p>
</div>

<form id="orderArchiveFilterForm" method="GET" action="orders-archive.php">
    <input type="hidden" name="page" id="orderArchivePageInput" value="1">
</form>

<header class="header-circular-notice-keep">
    <div class="circular-notice-keep-control">
        <div class="page-selector">
            <p>แสดงตามปีสารบรรณ</p>

            <div class="custom-select-wrapper" data-target="orderArchiveYearInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($dh_year_label) ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="custom-options">
                    <?php foreach ($dh_year_options as $year_option): ?>
                        <div class="custom-option<?= $selected_dh_year === (int) $year_option ? ' selected' : '' ?>"
                            data-value="<?= h((string) $year_option) ?>"><?= h((string) $year_option) ?></div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="dh_year" id="orderArchiveYearInput"
                    value="<?= h((string) $selected_dh_year) ?>" form="orderArchiveFilterForm">
            </div>
        </div>

        <div class="page-selector">
            <p>แสดงตามสถานะคำสั่ง</p>

            <div class="custom-select-wrapper" data-target="orderArchiveStatusInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($status_label) ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="custom-options">
                    <?php foreach ($status_options as $option_value => $option_label): ?>
                        <div class="custom-option<?= $status_filter === $option_value ? ' selected' : '' ?>"
                            data-value="<?= h($option_value) ?>"><?= h($option_label) ?></div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="status" id="orderArchiveStatusInput" value="<?= h($status_filter) ?>"
                    form="orderArchiveFilterForm">
            </div>
        </div>

        <div class="page-selector">
            <p>แสดงตาม</p>

            <div class="custom-select-wrapper" data-target="orderArchiveSortInput">
                <div class="custom-select-trigger">
                    <p class="select-value"><?= h($sort_label) ?></p>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="custom-options">
                    <?php foreach ($sort_options as $option_value => $option_label): ?>
                        <div class="custom-option<?= $sort === $option_value ? ' selected' : '' ?>"
                            data-value="<?= h($option_value) ?>"><?= h($option_label) ?></div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="sort" id="orderArchiveSortInput" value="<?= h($sort) ?>"
                    form="orderArchiveFilterForm">
            </div>
        </div>
    </div>
</header>

<section class="content-circular-notice-keep" data-orders-archive>
    <div class="search-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="orderArchiveSearchInput" name="q" form="orderArchiveFilterForm"
                value="<?= h($search) ?>" placeholder="ค้นหาเลขที่คำสั่ง หรือ เรื่อง...">
        </div>
    </div>

    <div class="table-circular-notice-keep">
        <table>
            <thead>
                <tr>
                    <th>เรื่อง / เลขที่คำสั่ง</th>
                    <th>ผู้ส่งคำสั่ง</th>
                    <th>วันที่รับ</th>
                    <th>สถานะ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="5" class="enterprise-empty">ไม่พบรายการคำสั่งราชการที่จัดเก็บ</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $order_id = (int) ($item['orderID'] ?? 0);
                        $order_no = trim((string) ($item['orderNo'] ?? ''));
                        $subject = trim((string) ($item['subject'] ?? ''));
                        $sender_name = trim((string) ($item['senderName'] ?? ''));
                        $delivered_at = trim((string) ($item['deliveredAt'] ?? '-'));
                        $received_display = $format_thai_received_datetime($delivered_at);
                        $is_read = (int) ($item['isRead'] ?? 0) === 1;
                        $detail_meta = $parse_order_detail((string) ($item['detail'] ?? ''));
                        $modal_files = $normalize_order_files((array) ($item['attachments'] ?? []));
                        $modal_files_json = json_encode($modal_files, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

                        if (!is_string($modal_files_json)) {
                            $modal_files_json = '[]';
                        }
                        ?>
                        <tr>
                            <td class="orders-inbox-topic-cell">
                                <p class="orders-inbox-subject"><?= h($subject !== '' ? $subject : '-') ?></p>
                                <p class="orders-inbox-order-no">เลขที่คำสั่ง
                                    <?= h($order_no !== '' ? $order_no : ('#' . $order_id)) ?>
                                </p>
                            </td>
                            <td><?= h($sender_name !== '' ? $sender_name : '-') ?></td>
                            <td class="orders-inbox-date-cell">
                                <p class="orders-inbox-date"><?= h($received_display['date']) ?></p>
                                <?php if ($received_display['time'] !== ''): ?>
                                    <p class="orders-inbox-time"><?= h($received_display['time']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span
                                    class="status-badge <?= h($is_read ? 'read' : 'unread') ?>"><?= h($is_read ? 'อ่านแล้ว' : 'ยังไม่อ่าน') ?></span>
                            </td>
                            <td>
                                <button class="booking-action-btn secondary js-open-order-view-modal" type="button"
                                    data-order-id="<?= h((string) $order_id) ?>"
                                    data-order-no="<?= h($order_no !== '' ? $order_no : ('#' . $order_id)) ?>"
                                    data-order-subject="<?= h($subject !== '' ? $subject : '-') ?>"
                                    data-order-effective-date="<?= h((string) ($detail_meta['effective_date'] ?? '')) ?>"
                                    data-order-date="<?= h((string) ($detail_meta['order_date'] ?? '')) ?>"
                                    data-order-issuer="<?= h((string) ($detail_meta['issuer_name'] ?? '')) ?>"
                                    data-order-group="<?= h((string) ($detail_meta['group_name'] ?? '')) ?>"
                                    data-order-files="<?= h($modal_files_json) ?>">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    <span class="tooltip">รายละเอียด</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

        </table>
    </div>

    <div class="content-circular-notice-index circular-track-modal-host">
        <div class="modal-overlay-circular-notice-index outside-person" id="modalOrderViewOverlay">
            <div class="modal-content">
                <div class="header-modal">
                    <div class="first-header">
                        <p>ติดตามการส่งคำสั่งราชการ</p>
                    </div>
                    <div class="sec-header">
                        <i class="fa-solid fa-xmark" id="closeModalOrderView"></i>
                    </div>
                </div>
                <!-- <div class="content-modal">
                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>คำสั่งที่</strong></p>
                            <input type="text" id="modalOrderViewNo" class="order-no-display" value="-" disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>เรื่อง</strong></p>
                            <input type="text" id="modalOrderViewSubject" class="order-no-display" value="-" disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>ทั้งนี้ตั้งแต่วันที่</strong></p>
                            <input type="date" id="modalOrderViewEffectiveDate" class="order-no-display" value=""
                                disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>สั่ง ณ วันที่</strong></p>
                            <input type="date" id="modalOrderViewDate" class="order-no-display" value="" disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>ผู้สร้างเลขคำสั่ง</strong></p>
                            <input type="text" id="modalOrderViewIssuer" class="order-no-display" value="-" disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details row-format">
                            <p><strong>กลุ่ม</strong></p>
                            <input type="text" id="modalOrderViewGroup" class="order-no-display" value="-" disabled>
                        </div>
                    </div>

                    <div class="orders-send-modal-shell orders-send-card">
                        <div id="modalOrderViewFormSection">
                            <form method="POST" action="orders-create.php" class="orders-send-form"
                                id="modalOrderViewForm">
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

                </div> -->

                <div class="formal-form content-modal">
                    <div class="formal-row">
                        <div class="group">
                            <label><strong>คำสั่งที่</strong></label>
                            <input type="text" id="modalOrderViewNo" class="order-no-display" value="-" disabled>

                        </div>
                        <div class="group">
                            <label><strong>เรื่อง</strong></label>
                            <input type="text" id="modalOrderViewSubject" class="order-no-display" value="-" disabled>
                        </div>
                    </div>
                    <div class="formal-row">
                        <div class="group">
                            <label><strong>ทั้งนี้ตั้งแต่วันที่</strong></label>
                            <input type="date" id="modalOrderViewEffectiveDate" class="order-no-display" value="" disabled>
                        </div>
                        <div class="group">
                            <label><strong>สั่ง ณ วันที่</strong></label>
                            <input type="date" id="modalOrderViewDate" class="order-no-display" value="" disabled>
                        </div>
                    </div>
                    <div class="formal-row">
                        <div class="group">
                            <label><strong>ผู้สร้างเลขคำสั่ง</strong></label>
                            <input type="text" id="modalOrderViewIssuer" class="order-no-display" value="-" disabled>
                        </div>
                        <div class="group">
                            <label><strong>กลุ่ม</strong></label>
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
                </div>

            </div>
        </div>
    </div>

    <!-- <div class="modal-overlay-circular-notice-keep" id="orderArchiveModalOverlay" style="display: none;">
        <div class="modal-content">
            <div class="header-modal">
                <p>แสดงข้อความรายละเอียดคำสั่งราชการ</p>
                <i class="fa-solid fa-xmark" id="orderArchiveModalClose"></i>
            </div>

            <div class="content-modal">
                <div class="content-topic-sec">
                    <p><strong>เลขที่คำสั่ง :</strong></p>
                    <p id="orderArchiveModalOrderNo">-</p>
                </div>
                <div class="content-topic-sec">
                    <p><strong>เรื่อง :</strong></p>
                    <p id="orderArchiveModalSubject">-</p>
                </div>
                <div class="content-topic-sec">
                    <p><strong>ผู้สร้างเลขคำสั่ง :</strong></p>
                    <p id="orderArchiveModalSender">-</p>
                </div>
                <div class="content-topic-sec">
                    <p><strong>วันที่รับ :</strong></p>
                    <p id="orderArchiveModalDate">-</p>
                </div>

                <div class="content-details-sec">
                    <p><strong>รายละเอียดเพิ่มเติม</strong></p>
                    <p id="orderArchiveModalDetail">-</p>
                </div>

                <div class="content-file-sec">
                    <p>ไฟล์เอกสารแนบจากระบบ</p>
                    <div class="file-section" id="orderArchiveModalFileSection"></div>
                </div>
            </div>

            <div class="footer-modal">
                <form method="POST" id="orderArchiveUnarchiveForm" action="<?= h($post_action_url) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="inbox_id" id="orderArchiveModalInboxId" value="">
                    <input type="hidden" name="action" value="unarchive">
                    <button type="submit">
                        <p>ย้ายกลับ</p>
                    </button>
                </form>
            </div>
        </div>
    </div> -->
</section>

<div class="button-circular-notice-keep"></div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const filterForm = document.getElementById('orderArchiveFilterForm');
        const pageInput = document.getElementById('orderArchivePageInput');
        const sectionSelector = 'section[data-orders-archive]';
        const tableSelector = sectionSelector + ' .table-circular-notice-keep';
        const loadingApi = window.App && window.App.loading ? window.App.loading : null;
        let isRequestInFlight = false;
        let requestToken = 0;
        let pendingRequest = null;
        let searchTimer = null;

        const getSection = () => document.querySelector(sectionSelector);

        const getSearchInput = () => document.getElementById('orderArchiveSearchInput');

        const buildRequestUrl = () => {
            if (!filterForm) {
                return '';
            }

            const formData = new FormData(filterForm);
            const params = new URLSearchParams();

            formData.forEach((value, key) => {
                params.set(key, String(value));
            });

            const query = params.toString();

            if (query === '') {
                return filterForm.action;
            }

            return filterForm.action + '?' + query;
        };

        const applyFilter = async (options = {}) => {
            const {
                resetPage = false,
                requestUrl = '',
            } = options;

            if (!filterForm) {
                return;
            }

            if (resetPage && pageInput) {
                pageInput.value = '1';
            }

            const rawTargetUrl = requestUrl !== '' ? requestUrl : buildRequestUrl();

            if (rawTargetUrl === '' || typeof window.fetch !== 'function') {
                filterForm.submit();
                return;
            }

            const normalizedUrl = new URL(rawTargetUrl, window.location.href);

            if (isRequestInFlight) {
                pendingRequest = {
                    resetPage: resetPage,
                    requestUrl: normalizedUrl.pathname + normalizedUrl.search,
                };
                return;
            }

            isRequestInFlight = true;
            requestToken += 1;
            const currentToken = requestToken;

            const sectionNode = getSection();
            if (loadingApi) {
                loadingApi.startComponent(sectionNode);
            }

            try {
                const response = await window.fetch(normalizedUrl.toString(), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Failed to fetch archive list');
                }

                const htmlText = await response.text();
                const parser = new DOMParser();
                const nextDocument = parser.parseFromString(htmlText, 'text/html');

                const currentTable = document.querySelector(tableSelector);
                const nextTable = nextDocument.querySelector(tableSelector);

                if (!currentTable || !nextTable) {
                    window.location.assign(normalizedUrl.toString());
                    return;
                }

                currentTable.replaceWith(nextTable);
                window.history.replaceState({}, '', normalizedUrl.pathname + normalizedUrl.search);
            } catch (error) {
                window.location.assign(normalizedUrl.toString());
            } finally {
                if (loadingApi) {
                    loadingApi.stopComponent(getSection());
                }

                if (currentToken === requestToken) {
                    isRequestInFlight = false;
                }

                if (pendingRequest !== null) {
                    const nextRequest = pendingRequest;
                    pendingRequest = null;
                    applyFilter(nextRequest);
                }
            }
        };

        document.querySelectorAll('.header-circular-notice-keep .custom-select-wrapper').forEach((wrapper) => {
            const target = document.getElementById(wrapper.dataset.target || '');
            const options = wrapper.querySelectorAll('.custom-option');
            const valueDisplay = wrapper.querySelector('.select-value');

            options.forEach((option) => {
                option.addEventListener('click', () => {
                    const value = option.dataset.value || '';
                    options.forEach((opt) => opt.classList.remove('selected'));
                    option.classList.add('selected');

                    if (target) {
                        target.value = value;
                    }
                    if (valueDisplay) {
                        valueDisplay.textContent = option.textContent.trim();
                    }
                    applyFilter({
                        resetPage: true,
                    });
                });
            });
        });

        const searchInput = getSearchInput();
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                if (searchTimer) {
                    window.clearTimeout(searchTimer);
                }

                searchTimer = window.setTimeout(() => {
                    applyFilter({
                        resetPage: true,
                    });
                }, 300);
            });

            searchInput.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }
                event.preventDefault();
                if (searchTimer) {
                    window.clearTimeout(searchTimer);
                }
                applyFilter({
                    resetPage: true,
                });
            });
        }

        const modalOverlay = document.getElementById('orderArchiveModalOverlay');
        const modalClose = document.getElementById('orderArchiveModalClose');
        const modalInboxInput = document.getElementById('orderArchiveModalInboxId');
        const modalOrderNo = document.getElementById('orderArchiveModalOrderNo');
        const modalSubject = document.getElementById('orderArchiveModalSubject');
        const modalSender = document.getElementById('orderArchiveModalSender');
        const modalDate = document.getElementById('orderArchiveModalDate');
        const modalDetail = document.getElementById('orderArchiveModalDetail');
        const modalFileSection = document.getElementById('orderArchiveModalFileSection');

        const closeModal = () => {
            if (!modalOverlay) {
                return;
            }
            modalOverlay.style.display = 'none';
        };

        const buildFileBanner = (orderId, file) => {
            const fileId = String(file.fileID || '');
            const fileName = String(file.fileName || '-');
            const mimeType = String(file.mimeType || '');
            const viewHref = 'public/api/file-download.php?module=orders&entity_id=' + encodeURIComponent(String(orderId || '')) + '&file_id=' + encodeURIComponent(fileId);

            return '<div class="file-banner">' +
                '<div class="file-info">' +
                '<div class="file-icon"><i class="fa-solid fa-file"></i></div>' +
                '<div class="file-text"><span class="file-name">' + fileName + '</span><span class="file-type">' + mimeType + '</span></div>' +
                '</div>' +
                '<div class="file-actions"><a href="' + viewHref + '" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i></a></div>' +
                '</div>';
        };

        const escapeHtml = (text) => String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const openModalFromButton = (button) => {
            const inboxId = button.dataset.inboxId || '';
            const orderId = button.dataset.orderId || '';

            if (modalInboxInput) {
                modalInboxInput.value = inboxId;
            }
            if (modalOrderNo) {
                modalOrderNo.textContent = button.dataset.orderNo || '-';
            }
            if (modalSubject) {
                modalSubject.textContent = button.dataset.subject || '-';
            }
            if (modalSender) {
                modalSender.textContent = button.dataset.sender || '-';
            }
            if (modalDate) {
                modalDate.textContent = button.dataset.date || '-';
            }
            if (modalDetail) {
                modalDetail.textContent = button.dataset.detail || '-';
            }

            if (modalFileSection) {
                let files = [];

                try {
                    files = JSON.parse(button.dataset.files || '[]');
                } catch (error) {
                    files = [];
                }

                if (!Array.isArray(files) || files.length === 0) {
                    modalFileSection.innerHTML = '<div class="file-banner"><div class="file-info"><div class="file-text"><span class="file-name">ไม่มีไฟล์แนบ</span></div></div></div>';
                } else {
                    modalFileSection.innerHTML = files.map((file) => {
                        const safeFile = {
                            fileID: escapeHtml(String(file.fileID || '')),
                            fileName: escapeHtml(String(file.fileName || '-')),
                            mimeType: escapeHtml(String(file.mimeType || '')),
                        };
                        return buildFileBanner(orderId, safeFile);
                    }).join('');
                }
            }

            if (modalOverlay) {
                modalOverlay.style.display = 'flex';
            }
        };

        document.addEventListener('click', (event) => {
            const modalButton = event.target.closest('.js-open-order-archive-modal');
            if (modalButton) {
                event.preventDefault();
                openModalFromButton(modalButton);
                return;
            }

            const paginationLink = event.target.closest(sectionSelector + ' .c-pagination a[href]');
            if (!paginationLink) {
                return;
            }
            event.preventDefault();

            const href = paginationLink.getAttribute('href') || '';
            if (href === '') {
                return;
            }

            const absoluteUrl = new URL(href, window.location.href);
            const nextPage = absoluteUrl.searchParams.get('page');

            if (pageInput) {
                pageInput.value = nextPage && nextPage !== '' ? nextPage : '1';
            }

            applyFilter({
                requestUrl: absoluteUrl.pathname + (absoluteUrl.search || ''),
            });
        });

        if (modalClose) {
            modalClose.addEventListener('click', closeModal);
        }

        if (modalOverlay) {
            modalOverlay.addEventListener('click', (event) => {
                if (event.target === modalOverlay) {
                    closeModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const orderViewModal = document.getElementById('modalOrderViewOverlay');
        const closeIconOrderView = document.getElementById('closeModalOrderView');
        const orderViewFileSection = document.getElementById('modalOrderViewFileSection');

        const escapeOrderViewHtml = (text) => String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const setOrderViewInput = (id, value) => {
            const input = document.getElementById(id);

            if (!input) {
                return;
            }

            const normalizedValue = String(value || '').trim();
            input.value = normalizedValue !== '' ? normalizedValue : '-';
        };

        const setOrderViewDateInput = (id, value) => {
            const input = document.getElementById(id);

            if (!input) {
                return;
            }

            const normalizedValue = String(value || '').trim();
            input.value = /^\d{4}-\d{2}-\d{2}$/.test(normalizedValue) ? normalizedValue : '';
        };

        const parseOrderViewFiles = (rawValue) => {
            try {
                const files = JSON.parse(String(rawValue || '[]'));
                return Array.isArray(files) ? files : [];
            } catch (error) {
                return [];
            }
        };

        const buildOrderViewFileBanner = (orderId, file) => {
            const fileId = String(file.fileID || '').trim();
            const fileName = String(file.fileName || '').trim() || '-';
            const mimeType = String(file.mimeType || '').trim();
            const viewHref = 'public/api/file-download.php?module=orders&entity_id=' +
                encodeURIComponent(String(orderId || '')) +
                '&file_id=' +
                encodeURIComponent(fileId);

            return '<div class="file-banner">' +
                '<div class="file-info">' +
                '<div class="file-icon"><i class="fa-solid fa-file"></i></div>' +
                '<div class="file-text"><span class="file-name">' + escapeOrderViewHtml(fileName) + '</span><span class="file-type">' + escapeOrderViewHtml(mimeType) + '</span></div>' +
                '</div>' +
                '<div class="file-actions"><a href="' + escapeOrderViewHtml(viewHref) + '" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i></a></div>' +
                '</div>';
        };

        const renderOrderViewFiles = (orderId, files) => {
            if (!orderViewFileSection) {
                return;
            }

            if (!Array.isArray(files) || files.length === 0) {
                orderViewFileSection.innerHTML = '<div class="file-banner"><div class="file-info"><div class="file-text"><span class="file-name">ไม่มีไฟล์แนบ</span></div></div></div>';
                return;
            }

            orderViewFileSection.innerHTML = files.map((file) => buildOrderViewFileBanner(orderId, file)).join('');
        };

        const closeOrderViewModal = () => {
            if (orderViewModal) {
                orderViewModal.style.display = 'none';
            }
        };

        if (closeIconOrderView) {
            closeIconOrderView.addEventListener('click', closeOrderViewModal);
        }

        if (orderViewModal) {
            orderViewModal.addEventListener('click', (event) => {
                if (event.target === orderViewModal) {
                    closeOrderViewModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeOrderViewModal();
            }
        });

        document.addEventListener('click', (event) => {
            const viewButton = event.target.closest('.js-open-order-view-modal');
            if (viewButton) {
                event.preventDefault();
                const orderId = String(viewButton.dataset.orderId || '').trim();

                setOrderViewInput('modalOrderViewNo', viewButton.dataset.orderNo || '-');
                setOrderViewInput('modalOrderViewSubject', viewButton.dataset.orderSubject || '-');
                setOrderViewDateInput('modalOrderViewEffectiveDate', viewButton.dataset.orderEffectiveDate || '');
                setOrderViewDateInput('modalOrderViewDate', viewButton.dataset.orderDate || '');
                setOrderViewInput('modalOrderViewIssuer', viewButton.dataset.orderIssuer || '-');
                setOrderViewInput('modalOrderViewGroup', viewButton.dataset.orderGroup || '-');

                const orderIdInput = document.getElementById('modalOrderViewOrderId');
                if (orderIdInput) {
                    orderIdInput.value = orderId;
                }

                renderOrderViewFiles(orderId, parseOrderViewFiles(viewButton.dataset.orderFiles || '[]'));

                if (orderViewModal) {
                    orderViewModal.style.display = 'flex';
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            const slider = document.querySelector('.table-circular-notice-keep');

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
    })
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
