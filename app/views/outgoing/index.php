<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$items = (array) ($items ?? []);
$can_manage = (bool) ($can_manage ?? ($is_registry ?? false));
$search = trim((string) ($search ?? ''));
$status_filter = strtoupper(trim((string) ($status_filter ?? 'ALL')));
$filter_status = strtolower($status_filter);
$summary_counts = (array) ($summary_counts ?? []);
$attachments_map = (array) ($attachments_map ?? []);
$issuer_name = trim((string) ($issuer_name ?? ''));
$form_values = array_merge([
    'subject' => '',
    'effective_date' => date('Y-m-d'),
    'person_ids' => [],
], (array) ($form_values ?? []));
$filter_query = trim((string) ($filter_query ?? $search ?? ''));
$filter_sort = trim((string) ($filter_sort ?? 'newest'));
$is_track_active = (bool) ($is_track_active ?? false);
$track_status_map = (array) ($track_status_map ?? []);
$send_modal_payload_map = (array) ($send_modal_payload_map ?? []);
$selected_priority = trim((string) ($form_values['priority'] ?? 'normal'));
$selected_person_ids = array_values(array_unique(array_filter(array_map(static function ($value): string {
    return trim((string) $value);
}, (array) ($form_values['person_ids'] ?? [])), static function (string $value): bool {
    return $value !== '';
})));
$selected_person_ids_json = json_encode($selected_person_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$summary_total = (int) ($summary_counts['all'] ?? count($items));
$summary_waiting = (int) ($summary_counts[OUTGOING_STATUS_WAITING_ATTACHMENT] ?? 0);
$summary_complete = (int) ($summary_counts[OUTGOING_STATUS_COMPLETE] ?? 0);

$status_options = [
    'ALL' => 'ทั้งหมด',
    OUTGOING_STATUS_WAITING_ATTACHMENT => 'รอแนบไฟล์',
    OUTGOING_STATUS_COMPLETE => 'สมบูรณ์',
];

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

$format_thai_datetime_parts = static function (?string $datetime) use ($thai_months): array {
    $datetime = trim((string) $datetime);

    if ($datetime === '' || strpos($datetime, '0000-00-00') === 0) {
        return ['date' => '-', 'time' => '-'];
    }

    $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i', $datetime);
    }

    if ($date_obj === false) {
        return ['date' => $datetime, 'time' => '-'];
    }

    $day = (int) $date_obj->format('j');
    $month = (int) $date_obj->format('n');
    $year = (int) $date_obj->format('Y') + 543;
    $month_label = $thai_months[$month] ?? '';

    return [
        'date' => trim($day . ' ' . $month_label . ' ' . $year),
        'time' => $date_obj->format('H:i') . ' น.',
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

    .table-responsive table th,
    .table-responsive table td {
        text-align: start;
    }

    #outgoingMine .table-responsive.circular-my-table-wrap.order-create .circular-my-table thead th:nth-child(2),
    #outgoingMine .table-responsive.circular-my-table-wrap.order-create .circular-my-table thead th:nth-child(4) {
        text-align: center;
    }

    .form-group.receive button p {
        color: var(--color-neutral-lightest);
    }

    .form-group.receive .dropdown-container {
        max-width: 520px;
    }

    .form-group.receive .search-input-wrapper {
        min-height: 44px;
    }

    .form-group.receive .search-input-wrapper .search-input {
        font-size: 18px;
    }

    .content-outgoing #outgoing .form-group.button .input-group {
        gap: 12px;
    }

    .outgoing .select-all-box input {
        width: 20px;
        height: 20px;
    }

    .outgoing .category-items input[type="checkbox"] {
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

    .content-order .form-group.outgoing-subject-row .input-group {
        width: 100%;
    }

    #modalOrderEditOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-edit-urgent="normal"],
    #modalOrderViewOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-view-urgent="normal"] {
        background-color: #00ae2c !important;
        border-color: #00ae2c !important;
        box-shadow: inset 0 0 0 1px white, inset 0 0 0 3px #00ae2c !important;
    }

    #modalOrderEditOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-edit-urgent="urgent"],
    #modalOrderViewOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-view-urgent="urgent"] {
        background-color: #9a00af !important;
        border-color: #9a00af !important;
        box-shadow: inset 0 0 0 1px white, inset 0 0 0 3px #9a00af !important;
    }

    #modalOrderEditOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-edit-urgent="high"],
    #modalOrderViewOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-view-urgent="high"] {
        background-color: #ce6203 !important;
        border-color: #ce6203 !important;
        box-shadow: inset 0 0 0 1px white, inset 0 0 0 3px #ce6203 !important;
    }

    #modalOrderEditOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-edit-urgent="highest"],
    #modalOrderViewOverlay .type-urgent .radio-group-urgent input[type="radio"].is-active[data-outgoing-view-urgent="highest"] {
        background-color: #bd0000 !important;
        border-color: #bd0000 !important;
        box-shadow: inset 0 0 0 1px white, inset 0 0 0 3px #bd0000 !important;
    }

    .file-list {
        margin: 0;
    }

    .circular-track-modal-host {
        width: 0;
        height: 0;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
    }

    .file-section:last-child {
        margin: 20px 0 0;
    }

    .file-banner {
        max-width: 400px;
    }

    .file-list {
        margin: 20px 0;
    }

    @media screen and (max-width: 1024px) {
        .file-section:last-child {
            margin: 10px 0 0;
        }

        .file-banner {
            max-width: 300px;
        }

        .file-list {
            margin: 10px 0;
        }

    }

    @media screen and (max-width: 768px) {
        .file-section:last-child {
            margin: 10px 0 0;
        }

        .file-banner {
            max-width: 250px;
        }

        .file-list {
            margin: 5px 0;
        }
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>ออกเลขทะเบียนส่ง</p>
</div>

<div class="tabs-container setting-page">
    <div class="button-container vehicle">
        <button class="tab-btn <?= $is_track_active ? '' : 'active' ?>"
            onclick="openTab('outgoing', event)">ออกเลขทะเบียนส่ง</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>"
            onclick="openTab('outgoingMine', event)">เลขทะเบียนส่งของฉัน</button>
    </div>
</div>

<div class="content-order outgoing tab-content <?= $is_track_active ? '' : 'active' ?>" id="outgoing">
    <form method="POST" id="outgointForm" enctype="multipart/form-data">

        <div class="type-urgent">
            <p>ประเภท</p>
            <div class="radio-group-urgent">
                <input type="radio" name="priority" value="normal" <?= $selected_priority === 'normal' ? 'checked' : '' ?>
                    id="outgoingPriorityNormal"><label for="outgoingPriorityNormal">ปกติ</label>
                <input type="radio" name="priority" value="urgent" <?= $selected_priority === 'urgent' ? 'checked' : '' ?>
                    id="outgoingPriorityUrgent"><label for="outgoingPriorityUrgent">ด่วน</label>
                <input type="radio" name="priority" value="high" <?= $selected_priority === 'high' ? 'checked' : '' ?>
                    id="outgoingPriorityHigh"><label for="outgoingPriorityHigh">ด่วนมาก</label>
                <input type="radio" name="priority" value="highest" <?= $selected_priority === 'highest' ? 'checked' : '' ?> id="outgoingPriorityHighest"><label for="outgoingPriorityHighest">ด่วนที่สุด</label>
            </div>
        </div>

        <div class="circular-btn">
            <label>เป็นเอกสารเวียน?</label>
            <input type="checkbox">
        </div>

        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="order_id" value="">

        <div class="form-group row outgoing-subject-row">
            <div class="input-group">
                <p><strong>เรื่อง</strong></p>
                <input type="text" name="subject" value="<?= h((string) ($form_values['subject'] ?? '')) ?>"
                    placeholder="ระบุหัวข้อคำสั่ง" maxlength="300" required>
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>ลงวันที่</strong></p>
                <input type="date" name="effective_date"
                    value="<?= h((string) ($form_values['effective_date'] ?? '')) ?>" required>
            </div>
            <div class="input-group">
                <p><strong>ผู้ออกเลข</strong></p>
                <input type="text" class="order-no-display" value="<?= h($issuer_name) ?>" disabled>
            </div>
        </div>


        <div class="form-group receive" data-recipients-section="" data-owner-flat-list="disabled" hidden
            style="display: none;" aria-hidden="true">
            <label><strong>เจ้าของเรื่อง :</strong></label>
            <div class="dropdown-container">
                <div class="search-input-wrapper" id="recipientToggle">
                    <input type="text" id="mainInput" class="search-input" placeholder="ค้นหา หรือ เลือกข้อมูล..."
                        autocomplete="off">
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
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                data-group="faction" data-group-key="faction-5"
                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                data-members="[{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;}]"
                                                name="faction_ids[]" value="5">
                                            <span class="item-title">กลุ่มบริหารกิจการนักเรียน</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางชมทิศา ขันภักดี"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3820400215231">
                                                <span class="member-name">นางชมทิศา ขันภักดี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางพวงทิพย์ ทวีรส"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3950300068146">
                                                <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางสาวชาลิสา จิตต์พันธ์"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1829900172052">
                                                <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางสาวนิรัตน์ เพชรแก้ว"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3820800038999">
                                                <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางสาวอรบุษย์ หนักแน่น"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1829900170670">
                                                <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางสุนิษา  จินดาพล"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3601000301019">
                                                <span class="member-name">นางสุนิษา จินดาพล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางเขมษิญากรณ์ อุดมคุณ"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3820400309367">
                                                <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นางเพ็ญแข หวานสนิท"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3930300329632">
                                                <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายจรุง  บำรุงเขตต์"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3820400261097">
                                                <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3820700017680">
                                                <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายณัฐพงษ์ สัจจารักษ์"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1319800069611">
                                                <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายธีรภัส  สฤษดิสุข"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1839900193629">
                                                <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายธีระวัฒน์ เพชรขุ้ม"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1841500136302">
                                                <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายพจนันท์  พรหมสงค์"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1829900109890">
                                                <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5" data-member-name="นายรชต  ปานบุญ"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1829900012446">
                                                <span class="member-name">นายรชต ปานบุญ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายวรานนท์ ภาระพฤติ"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3820100028745">
                                                <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายศุภสวัสดิ์ กาญวิจิต"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1829900093446">
                                                <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายอนุสรณ์ ชูทอง"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="3929900087867">
                                                <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1901100006087">
                                                <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-5"
                                                    data-member-name="นายเอกพงษ์ สงวนทรัพย์"
                                                    data-group-label="กลุ่มบริหารกิจการนักเรียน" name="person_ids[]"
                                                    value="1829900072562">
                                                <span class="member-name">นายเอกพงษ์ สงวนทรัพย์</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed" data-faction-id="4">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                data-group="faction" data-group-key="faction-4"
                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                data-members="[{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;}]"
                                                name="faction_ids[]" value="4">
                                            <span class="item-title">กลุ่มบริหารงานทั่วไป</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 21 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางจิตติพร เกตุรักษ์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1820500007021">
                                                <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางพูนสุข ถิ่นลิพอน"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3820100172170">
                                                <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางภทรมน ลิ่มบุตร"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1809900084706">
                                                <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางวาสนา  สุทธจิตร์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3820100025495">
                                                <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวธารทิพย์ ภาระพฤติ"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3850100320012">
                                                <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวนิรชา ธรรมัสโร"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1829900174284">
                                                <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวปาณิสรา  มงคลบุตร"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3829900019706">
                                                <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวพรทิพย์ สมบัติบุญ"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1920100023843">
                                                <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวพรรณพนัช  คงผอม"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1101401730717">
                                                <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวรัตนาพร พรประสิทธิ์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1820500148121">
                                                <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวราศรี  อนันตมงคลกุล"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3820500121271">
                                                <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวสรัลรัตน์ จันทับ"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1809901015490">
                                                <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นางสาวสุดาทิพย์ ยกย่อง"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1820800031408">
                                                <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายนพดล วงศ์สุวัฒน์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1860700158147">
                                                <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายนพพร  ถิ่นไทย"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1860100007288">
                                                <span class="member-name">นายนพพร ถิ่นไทย</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายนรินทร์เพชร นิลเวช"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1102003266698">
                                                <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายวิศรุต ชามทอง"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1160100618291">
                                                <span class="member-name">นายวิศรุต ชามทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายสหัส เสือยืนยง"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3810500157631">
                                                <span class="member-name">นายสหัส เสือยืนยง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายอิสรพงศ์ สัตปานนท์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1829900162341">
                                                <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="นายเพลิน โอรักษ์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="3180600191510">
                                                <span class="member-name">นายเพลิน โอรักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-4"
                                                    data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์"
                                                    data-group-label="กลุ่มบริหารงานทั่วไป" name="person_ids[]"
                                                    value="1809900094507">
                                                <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์ ผสารพจน์</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed" data-faction-id="3">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                data-group="faction" data-group-key="faction-3"
                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;}]"
                                                name="faction_ids[]" value="3">
                                            <span class="item-title">กลุ่มบริหารงานบุคคลและงบประมาณ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 26 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางจริยาวดี  เวชจันทร์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="5800900028151">
                                                <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางจิราภรณ์  เสรีรักษ์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="3800400522290">
                                                <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="3920100747937">
                                                <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางปวีณา  บำรุงภักดิ์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1829900007736">
                                                <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางฝาติหม๊ะ ขนาดผล"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1930600099890">
                                                <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1829900179103">
                                                <span class="member-name">นางสาวกนกลักษณ์ พันธ์สวัสดิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวกานต์พิชชา ปากลาว"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1810500062871">
                                                <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวธนวรรณ พิทักษ์คง"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1820500147966">
                                                <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวธิดารัตน์ ทองกอบ"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1829900119712">
                                                <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวนฤมล บุญถาวร"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1920600250041">
                                                <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวนัยน์เนตร ทองวล"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1829900118058">
                                                <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวนิลญา หมานมิตร"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1910300050321">
                                                <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวบงกชรัตน์  มาลี"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1829900051727">
                                                <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวบุษรา  เมืองชู"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1840100431373">
                                                <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวปริษา  แก้วเขียว"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1829900090897">
                                                <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวปาณิสรา  มัจฉาเวช"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1820400055491">
                                                <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวปิยธิดา นิยมเดชา"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1820600006469">
                                                <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="3820100171700">
                                                <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวลภัสภาส์ หนูคง"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1820500130320">
                                                <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาววรินญา โรจธนะวรรธน์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1940100013597">
                                                <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1840100326120">
                                                <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1102001245405">
                                                <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นางสุมณฑา  เกิดทรัพย์"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="3820700050342">
                                                <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3" data-member-name="นางอรชา ชูเชื้อ"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1820700004867">
                                                <span class="member-name">นางอรชา ชูเชื้อ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นายสราวุธ กุหลาบวรรณ"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1800800331088">
                                                <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-3"
                                                    data-member-name="นายไชยวัฒน์ สังข์ทอง"
                                                    data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                    name="person_ids[]" value="1640700056303">
                                                <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed" data-faction-id="2">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                data-group="faction" data-group-key="faction-2"
                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                data-members="[{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;}]"
                                                name="faction_ids[]" value="2">
                                            <span class="item-title">กลุ่มบริหารงานวิชาการ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 45 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางกนกวรรณ  ณ นคร"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3810100580006">
                                                <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางจารุวรรณ ส่องศิริ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820100025592">
                                                <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางณิภาภรณ์  ไชยชนะ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3930300511171">
                                                <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางดวงกมล  เพ็ชรพรหม"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3840100521778">
                                                <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางดาริน ทรายทอง"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820300027670">
                                                <span class="member-name">นางดาริน ทรายทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางธนิษฐา  ยงยุทธ์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900063989">
                                                <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางประภาพร  อุดมผลชัยเจริญ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3331001384867">
                                                <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางผกาวรรณ  โชติวัฒนากร"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1920600003469">
                                                <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2" data-member-name="นางพนิดา ค้าของ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3940400027034">
                                                <span class="member-name">นางพนิดา ค้าของ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางพรพิมล แซ่เจี่ย"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1839900175043">
                                                <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางพิมพา ทองอุไร"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900003064">
                                                <span class="member-name">นางพิมพา ทองอุไร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900054688">
                                                <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900059485">
                                                <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวจิราวัลย์  อินทร์อักษร"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1930500083592">
                                                <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวชนิกานต์  สวัสดิวงค์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3829900033725">
                                                <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวณพสร สามสุวรรณ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3840200430855">
                                                <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1729900457121">
                                                <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวธนวรรณ สมัครการ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900202598">
                                                <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวนุชรีย์ หัศนี"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1820700006258">
                                                <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวประภัสสร  โอจันทร์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3840700282162">
                                                <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวประภาพรรณ กุลแก้ว"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1410100117524">
                                                <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวพิมพ์ประภา  ผลากิจ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900096909">
                                                <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820400028481">
                                                <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวรัชฎาพร สุวรรณสาม"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900012535">
                                                <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวรัชนีกร ผอมจีน"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1820500097624">
                                                <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820700136859">
                                                <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวศศิธร นาคสง"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3801600044431">
                                                <span class="member-name">นางสาวศศิธร นาคสง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวศุลีพร ขันภักดี"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900099401">
                                                <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900065485">
                                                <span class="member-name">นางสาวองค์ปรางค์ แสงสุรินทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวอภิชญา จันทร์มา"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1909901558298">
                                                <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวอาตีนา  พัชนี"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1800100218262">
                                                <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสาวอินทิรา บุญนิสสัย"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1800800204043">
                                                <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1930500116202">
                                                <span class="member-name">นางสุรางค์รัศมิ์ ย้อยพระจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นางเสาวลีย์ จันทร์ทอง"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820700019381">
                                                <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายจตุรวิทย์ มิตรวงศ์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1859900070560">
                                                <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1809901028575">
                                                <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายธันวิน  ณ นคร"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1959900030702">
                                                <span class="member-name">นายธันวิน ณ นคร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายนพรัตน์ ย้อยพระจันทร์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1839900094990">
                                                <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2" data-member-name="นายบพิธ มังคะลา"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1819900163142">
                                                <span class="member-name">นายบพิธ มังคะลา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายประสิทธิ์  สะไน"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1920400002230">
                                                <span class="member-name">นายประสิทธิ์ สะไน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายพิพัฒน์ ไชยชนะ"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3940400221191">
                                                <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายศรายุทธ  มิตรวงค์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820800037747">
                                                <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="1829900056460">
                                                <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายสมชาย สุทธจิตร์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820700143669">
                                                <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-2"
                                                    data-member-name="นายสุพัฒน์  เจริญฤทธิ์"
                                                    data-group-label="กลุ่มบริหารงานวิชาการ" name="person_ids[]"
                                                    value="3820400194578">
                                                <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed" data-faction-id="6">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                data-group="faction" data-group-key="faction-6"
                                                data-group-label="กลุ่มสนับสนุนการสอน"
                                                data-members="[{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;}]"
                                                name="faction_ids[]" value="6">
                                            <span class="item-title">กลุ่มสนับสนุนการสอน</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-6"
                                                    data-member-name="นางสาวนัฐลิณี ทอสงค์"
                                                    data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]"
                                                    value="1820700059157">
                                                <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-6"
                                                    data-member-name="นางสาวอุบลวรรณ คงสม"
                                                    data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]"
                                                    value="1829900149409">
                                                <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="faction-6"
                                                    data-member-name="นายสิงหนาท  แต่งแก้ว"
                                                    data-group-label="กลุ่มสนับสนุนการสอน" name="person_ids[]"
                                                    value="3810200084621">
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
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-9"
                                                data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน"
                                                data-members="[{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;}]"
                                                value="department-9">
                                            <span class="item-title">กลุ่มกิจกรรมพัฒนาผู้เรียน</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-9"
                                                    data-member-name="นางสาวนุชรีย์ หัศนี"
                                                    data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]"
                                                    value="1820700006258">
                                                <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-9"
                                                    data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม"
                                                    data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]"
                                                    value="1102001245405">
                                                <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-9"
                                                    data-member-name="นายสิงหนาท  แต่งแก้ว"
                                                    data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน" name="person_ids[]"
                                                    value="3810200084621">
                                                <span class="member-name">นายสิงหนาท แต่งแก้ว</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-10"
                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                data-members="[{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;}]"
                                                value="department-10">
                                            <span class="item-title">กลุ่มคอมพิวเตอร์และเทคโนโลยี</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-10"
                                                    data-member-name="นางสาวจิราวัลย์  อินทร์อักษร"
                                                    data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]"
                                                    value="1930500083592">
                                                <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-10"
                                                    data-member-name="นางสาวศศิธร นาคสง"
                                                    data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]"
                                                    value="3801600044431">
                                                <span class="member-name">นางสาวศศิธร นาคสง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-10"
                                                    data-member-name="นายจตุรวิทย์ มิตรวงศ์"
                                                    data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]"
                                                    value="1859900070560">
                                                <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-10"
                                                    data-member-name="นายธันวิน  ณ นคร"
                                                    data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]"
                                                    value="1959900030702">
                                                <span class="member-name">นายธันวิน ณ นคร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-10"
                                                    data-member-name="นายบพิธ มังคะลา"
                                                    data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]"
                                                    value="1819900163142">
                                                <span class="member-name">นายบพิธ มังคะลา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-10"
                                                    data-member-name="นายสหัส เสือยืนยง"
                                                    data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี" name="person_ids[]"
                                                    value="3810500157631">
                                                <span class="member-name">นายสหัส เสือยืนยง</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-11"
                                                data-group-label="กลุ่มธุรการ"
                                                data-members="[{&quot;pID&quot;:&quot;3820400234871&quot;,&quot;name&quot;:&quot;นางนวลน้อย  ชูสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1800700082485&quot;,&quot;name&quot;:&quot;นางสาว ณัฐชลียา ยิ่งคง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1829900082835&quot;,&quot;name&quot;:&quot;นางสาวจารุลักษณ์  ตรีศรี&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100155283&quot;,&quot;name&quot;:&quot;นางสาวจิราวรรณ ว่องปลูกศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;2800800033557&quot;,&quot;name&quot;:&quot;นางสาวธัญเรศ  วรศานต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820600035619&quot;,&quot;name&quot;:&quot;นางสาวนภัสสร  รัฐการ&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1810600075673&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร พันธ์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100140782&quot;,&quot;name&quot;:&quot;นางสาวศศิธร  มธุรส&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3810300076964&quot;,&quot;name&quot;:&quot;นายอดิศักดิ์  ธรรมจิตต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;}]"
                                                value="department-11">
                                            <span class="item-title">กลุ่มธุรการ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางนวลน้อย  ชูสงค์" data-group-label="กลุ่มธุรการ"
                                                    name="person_ids[]" value="3820400234871">
                                                <span class="member-name">นางนวลน้อย ชูสงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาว ณัฐชลียา ยิ่งคง"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="1800700082485">
                                                <span class="member-name">นางสาว ณัฐชลียา ยิ่งคง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาวจารุลักษณ์  ตรีศรี"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="1829900082835">
                                                <span class="member-name">นางสาวจารุลักษณ์ ตรีศรี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาวจิราวรรณ ว่องปลูกศิลป์"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="3820100155283">
                                                <span class="member-name">นางสาวจิราวรรณ ว่องปลูกศิลป์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาวธัญเรศ  วรศานต์"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="2800800033557">
                                                <span class="member-name">นางสาวธัญเรศ วรศานต์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาวนภัสสร  รัฐการ"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="3820600035619">
                                                <span class="member-name">นางสาวนภัสสร รัฐการ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาวประภัสสร พันธ์แก้ว"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="1810600075673">
                                                <span class="member-name">นางสาวประภัสสร พันธ์แก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นางสาวศศิธร  มธุรส" data-group-label="กลุ่มธุรการ"
                                                    name="person_ids[]" value="3820100140782">
                                                <span class="member-name">นางสาวศศิธร มธุรส</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นายอดิศักดิ์  ธรรมจิตต์"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="3810300076964">
                                                <span class="member-name">นายอดิศักดิ์ ธรรมจิตต์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-11"
                                                    data-member-name="นายไชยวัฒน์ สังข์ทอง"
                                                    data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                    value="1640700056303">
                                                <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-7"
                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                data-members="[{&quot;pID&quot;:&quot;1829900062591&quot;,&quot;name&quot;:&quot;นางสาวจารุวรรณ ผลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3810500179350&quot;,&quot;name&quot;:&quot;นางสาวนงลักษณ์   แก้วสว่าง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1849900176813&quot;,&quot;name&quot;:&quot;นายชนม์กมล เพ็ขรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;}]"
                                                value="department-7">
                                            <span class="item-title">กลุ่มสาระฯ การงานอาชีพ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 9 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางสาวจารุวรรณ ผลแก้ว"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="1829900062591">
                                                <span class="member-name">นางสาวจารุวรรณ ผลแก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางสาวนงลักษณ์   แก้วสว่าง"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="3810500179350">
                                                <span class="member-name">นางสาวนงลักษณ์ แก้วสว่าง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นายชนม์กมล เพ็ขรพรหม"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="1849900176813">
                                                <span class="member-name">นายชนม์กมล เพ็ขรพรหม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางพิมพา ทองอุไร"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="1829900003064">
                                                <span class="member-name">นางพิมพา ทองอุไร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางจริยาวดี  เวชจันทร์"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="5800900028151">
                                                <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางจิราภรณ์  เสรีรักษ์"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="3800400522290">
                                                <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางพูนสุข ถิ่นลิพอน"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="3820100172170">
                                                <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นางภทรมน ลิ่มบุตร"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="1809900084706">
                                                <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-7"
                                                    data-member-name="นายนพพร  ถิ่นไทย"
                                                    data-group-label="กลุ่มสาระฯ การงานอาชีพ" name="person_ids[]"
                                                    value="1860100007288">
                                                <span class="member-name">นายนพพร ถิ่นไทย</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-2"
                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                data-members="[{&quot;pID&quot;:&quot;1829900206275&quot;,&quot;name&quot;:&quot;นายภูมิวิชญ์ จีนนาพัฒ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;}]"
                                                value="department-2">
                                            <span class="item-title">กลุ่มสาระฯ คณิตศาสตร์</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นายภูมิวิชญ์ จีนนาพัฒ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1829900206275">
                                                <span class="member-name">นายภูมิวิชญ์ จีนนาพัฒ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางกนกวรรณ  ณ นคร"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="3810100580006">
                                                <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางประภาพร  อุดมผลชัยเจริญ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="3331001384867">
                                                <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางผกาวรรณ  โชติวัฒนากร"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1920600003469">
                                                <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางพรพิมล แซ่เจี่ย"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1839900175043">
                                                <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวพิมพ์ประภา  ผลากิจ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1829900096909">
                                                <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวรัชนีกร ผอมจีน"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1820500097624">
                                                <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวอภิชญา จันทร์มา"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1909901558298">
                                                <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวอินทิรา บุญนิสสัย"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1800800204043">
                                                <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางเสาวลีย์ จันทร์ทอง"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="3820700019381">
                                                <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1809901028575">
                                                <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นายพิพัฒน์ ไชยชนะ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="3940400221191">
                                                <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางฝาติหม๊ะ ขนาดผล"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1930600099890">
                                                <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวธิดารัตน์ ทองกอบ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1829900119712">
                                                <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวบงกชรัตน์  มาลี"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1829900051727">
                                                <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นายสราวุธ กุหลาบวรรณ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1800800331088">
                                                <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวพรทิพย์ สมบัติบุญ"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1920100023843">
                                                <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวรัตนาพร พรประสิทธิ์"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1820500148121">
                                                <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นายอนุสรณ์ ชูทอง"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="3929900087867">
                                                <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-2"
                                                    data-member-name="นางสาวนัฐลิณี ทอสงค์"
                                                    data-group-label="กลุ่มสาระฯ คณิตศาสตร์" name="person_ids[]"
                                                    value="1820700059157">
                                                <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-8"
                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                data-members="[{&quot;pID&quot;:&quot;1820800093039&quot;,&quot;name&quot;:&quot;นางสาวปาริชาต เดชอาษา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1809900831358&quot;,&quot;name&quot;:&quot;นางสาวพลอยไพลิน เที่ยวแสวง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;}]"
                                                value="department-8">
                                            <span class="item-title">กลุ่มสาระฯ ภาษาต่างประเทศ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวปาริชาต เดชอาษา"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1820800093039">
                                                <span class="member-name">นางสาวปาริชาต เดชอาษา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวพลอยไพลิน เที่ยวแสวง"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1809900831358">
                                                <span class="member-name">นางสาวพลอยไพลิน เที่ยวแสวง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางดาริน ทรายทอง"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="3820300027670">
                                                <span class="member-name">นางดาริน ทรายทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางพนิดา ค้าของ"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="3940400027034">
                                                <span class="member-name">นางพนิดา ค้าของ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1829900054688">
                                                <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1829900059485">
                                                <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1729900457121">
                                                <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวธนวรรณ สมัครการ"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1829900202598">
                                                <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1829900065485">
                                                <span class="member-name">นางสาวองค์ปรางค์ แสงสุรินทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1930500116202">
                                                <span class="member-name">นางสุรางค์รัศมิ์ ย้อยพระจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวกานต์พิชชา ปากลาว"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1810500062871">
                                                <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวนิลญา หมานมิตร"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1910300050321">
                                                <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาวปริษา  แก้วเขียว"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1829900090897">
                                                <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางสาววรินญา โรจธนะวรรธน์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1940100013597">
                                                <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นายอิสรพงศ์ สัตปานนท์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1829900162341">
                                                <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางพวงทิพย์ ทวีรส"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="3950300068146">
                                                <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางเขมษิญากรณ์ อุดมคุณ"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="3820400309367">
                                                <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นางเพ็ญแข หวานสนิท"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="3930300329632">
                                                <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="3820700017680">
                                                <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-8"
                                                    data-member-name="นายธีระวัฒน์ เพชรขุ้ม"
                                                    data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ" name="person_ids[]"
                                                    value="1841500136302">
                                                <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-1"
                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                data-members="[{&quot;pID&quot;:&quot;1829900103735&quot;,&quot;name&quot;:&quot;นางสาวจันทนี บุญนำ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900141980&quot;,&quot;name&quot;:&quot;นางสาวสุกานดา ปานมั่งคั่ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;}]"
                                                value="department-1">
                                            <span class="item-title">กลุ่มสาระฯ ภาษาไทย</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 14 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวจันทนี บุญนำ"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1829900103735">
                                                <span class="member-name">นางสาวจันทนี บุญนำ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวสุกานดา ปานมั่งคั่ง"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1829900141980">
                                                <span class="member-name">นางสาวสุกานดา ปานมั่งคั่ง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางดวงกมล  เพ็ชรพรหม"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="3840100521778">
                                                <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวณพสร สามสุวรรณ"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="3840200430855">
                                                <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="3820400028481">
                                                <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="3820700136859">
                                                <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวนัยน์เนตร ทองวล"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1829900118058">
                                                <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวบุษรา  เมืองชู"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1840100431373">
                                                <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางจิตติพร เกตุรักษ์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1820500007021">
                                                <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวพรรณพนัช  คงผอม"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1101401730717">
                                                <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นางสาวราศรี  อนันตมงคลกุล"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="3820500121271">
                                                <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นายนพดล วงศ์สุวัฒน์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1860700158147">
                                                <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นายนรินทร์เพชร นิลเวช"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1102003266698">
                                                <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-1"
                                                    data-member-name="นายพจนันท์  พรหมสงค์"
                                                    data-group-label="กลุ่มสาระฯ ภาษาไทย" name="person_ids[]"
                                                    value="1829900109890">
                                                <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-3"
                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                data-members="[{&quot;pID&quot;:&quot;1819300006267&quot;,&quot;name&quot;:&quot;นายคุณากร ประดับศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400295111&quot;,&quot;name&quot;:&quot;นายนิมิตร สุสิมานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;}]"
                                                value="department-3">
                                            <span class="item-title">กลุ่มสาระฯ วิทยาศาสตร์</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 24 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นายคุณากร ประดับศิลป์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1819300006267">
                                                <span class="member-name">นายคุณากร ประดับศิลป์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นายนิมิตร สุสิมานนท์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3820400295111">
                                                <span class="member-name">นายนิมิตร สุสิมานนท์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางณิภาภรณ์  ไชยชนะ"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3930300511171">
                                                <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางธนิษฐา  ยงยุทธ์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900063989">
                                                <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวรัชฎาพร สุวรรณสาม"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900012535">
                                                <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวศุลีพร ขันภักดี"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900099401">
                                                <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวอาตีนา  พัชนี"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1800100218262">
                                                <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นายนพรัตน์ ย้อยพระจันทร์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1839900094990">
                                                <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3920100747937">
                                                <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900179103">
                                                <span class="member-name">นางสาวกนกลักษณ์ พันธ์สวัสดิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวนฤมล บุญถาวร"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1920600250041">
                                                <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวปาณิสรา  มัจฉาเวช"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1820400055491">
                                                <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3820100171700">
                                                <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1840100326120">
                                                <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสุมณฑา  เกิดทรัพย์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3820700050342">
                                                <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางอรชา ชูเชื้อ"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1820700004867">
                                                <span class="member-name">นางอรชา ชูเชื้อ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางชมทิศา ขันภักดี"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3820400215231">
                                                <span class="member-name">นางชมทิศา ขันภักดี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวชาลิสา จิตต์พันธ์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900172052">
                                                <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวอรบุษย์ หนักแน่น"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900170670">
                                                <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสุนิษา  จินดาพล"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="3601000301019">
                                                <span class="member-name">นางสุนิษา จินดาพล</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นายณัฐพงษ์ สัจจารักษ์"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1319800069611">
                                                <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นายธีรภัส  สฤษดิสุข"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1839900193629">
                                                <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นายรชต  ปานบุญ"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900012446">
                                                <span class="member-name">นายรชต ปานบุญ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-3"
                                                    data-member-name="นางสาวอุบลวรรณ คงสม"
                                                    data-group-label="กลุ่มสาระฯ วิทยาศาสตร์" name="person_ids[]"
                                                    value="1829900149409">
                                                <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-6"
                                                data-group-label="กลุ่มสาระฯ ศิลปะ"
                                                data-members="[{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;}]"
                                                value="department-6">
                                            <span class="item-title">กลุ่มสาระฯ ศิลปะ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 7 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นางสาวประภัสสร  โอจันทร์"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="3840700282162">
                                                <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="1829900056460">
                                                <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นายสุพัฒน์  เจริญฤทธิ์"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="3820400194578">
                                                <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นางสาวธารทิพย์ ภาระพฤติ"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="3850100320012">
                                                <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นางสาวปาณิสรา  มงคลบุตร"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="3829900019706">
                                                <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นายวิศรุต ชามทอง"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="1160100618291">
                                                <span class="member-name">นายวิศรุต ชามทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-6"
                                                    data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต"
                                                    data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                    value="1901100006087">
                                                <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-4"
                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                data-members="[{&quot;pID&quot;:&quot;1830101156953&quot;,&quot;name&quot;:&quot;นางสาวนัสรีน สุวิสัน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1810300103434&quot;,&quot;name&quot;:&quot;นางสาวปณิดา คลองรั้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820501214179&quot;,&quot;name&quot;:&quot;นายมงคล ตันเจริญรัตน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;}]"
                                                value="department-4">
                                            <span class="item-title">กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 18 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวนัสรีน สุวิสัน"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1830101156953">
                                                <span class="member-name">นางสาวนัสรีน สุวิสัน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวปณิดา คลองรั้ว"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1810300103434">
                                                <span class="member-name">นางสาวปณิดา คลองรั้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นายมงคล ตันเจริญรัตน์"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1820501214179">
                                                <span class="member-name">นายมงคล ตันเจริญรัตน์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางจารุวรรณ ส่องศิริ"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="3820100025592">
                                                <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวชนิกานต์  สวัสดิวงค์"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="3829900033725">
                                                <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวประภาพรรณ กุลแก้ว"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1410100117524">
                                                <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางปวีณา  บำรุงภักดิ์"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1829900007736">
                                                <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวธนวรรณ พิทักษ์คง"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1820500147966">
                                                <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวปิยธิดา นิยมเดชา"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1820600006469">
                                                <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวลภัสภาส์ หนูคง"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1820500130320">
                                                <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางวาสนา  สุทธจิตร์"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="3820100025495">
                                                <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวนิรชา ธรรมัสโร"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1829900174284">
                                                <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวสรัลรัตน์ จันทับ"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1809901015490">
                                                <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นางสาวสุดาทิพย์ ยกย่อง"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1820800031408">
                                                <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นายเพลิน โอรักษ์"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="3180600191510">
                                                <span class="member-name">นายเพลิน โอรักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1809900094507">
                                                <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์ ผสารพจน์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นายวรานนท์ ภาระพฤติ"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="3820100028745">
                                                <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-4"
                                                    data-member-name="นายศุภสวัสดิ์ กาญวิจิต"
                                                    data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                    name="person_ids[]" value="1829900093446">
                                                <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox"
                                                class="item-checkbox group-item-checkbox department-item-checkbox"
                                                data-group="department" data-group-key="department-5"
                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                data-members="[{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;}]"
                                                value="department-5">
                                            <span class="item-title">กลุ่มสาระฯ สุขศึกษาและพลศึกษา</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-5"
                                                    data-member-name="นายประสิทธิ์  สะไน"
                                                    data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]"
                                                    value="1920400002230">
                                                <span class="member-name">นายประสิทธิ์ สะไน</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-5"
                                                    data-member-name="นายศรายุทธ  มิตรวงค์"
                                                    data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]"
                                                    value="3820800037747">
                                                <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-5"
                                                    data-member-name="นายสมชาย สุทธจิตร์"
                                                    data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]"
                                                    value="3820700143669">
                                                <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-5"
                                                    data-member-name="นางสาวนิรัตน์ เพชรแก้ว"
                                                    data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]"
                                                    value="3820800038999">
                                                <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-5"
                                                    data-member-name="นายจรุง  บำรุงเขตต์"
                                                    data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]"
                                                    value="3820400261097">
                                                <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="department-5"
                                                    data-member-name="นายเอกพงษ์ สงวนทรัพย์"
                                                    data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา" name="person_ids[]"
                                                    value="1829900072562">
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
                                            <input type="checkbox" class="item-checkbox group-item-checkbox"
                                                data-group="special" data-group-key="special-executive"
                                                data-group-label="คณะผู้บริหารสถานศึกษา"
                                                data-members="[{&quot;pID&quot;:&quot;1820500005169&quot;,&quot;name&quot;:&quot;นางสาวศริญญา  ผั้วผดุง&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3810500334835&quot;,&quot;name&quot;:&quot;นายดลยวัฒน์ สันติพิทักษ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;1820500004103&quot;,&quot;name&quot;:&quot;นายยุทธนา สุวรรณวิสุทธิ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3430200354125&quot;,&quot;name&quot;:&quot;นายไกรวิชญ์ อ่อนแก้ว&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;}]"
                                                value="special-executive">
                                            <span class="item-title">คณะผู้บริหารสถานศึกษา</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 4 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-executive"
                                                    data-member-name="นางสาวศริญญา  ผั้วผดุง"
                                                    data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]"
                                                    value="1820500005169">
                                                <span class="member-name">นางสาวศริญญา ผั้วผดุง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-executive"
                                                    data-member-name="นายดลยวัฒน์ สันติพิทักษ์"
                                                    data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]"
                                                    value="3810500334835">
                                                <span class="member-name">นายดลยวัฒน์ สันติพิทักษ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-executive"
                                                    data-member-name="นายยุทธนา สุวรรณวิสุทธิ์"
                                                    data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]"
                                                    value="1820500004103">
                                                <span class="member-name">นายยุทธนา สุวรรณวิสุทธิ์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-executive"
                                                    data-member-name="นายไกรวิชญ์ อ่อนแก้ว"
                                                    data-group-label="คณะผู้บริหารสถานศึกษา" name="person_ids[]"
                                                    value="3430200354125">
                                                <span class="member-name">นายไกรวิชญ์ อ่อนแก้ว</span>
                                            </label>
                                        </li>
                                    </ol>
                                </div>
                                <div class="item item-group is-collapsed">
                                    <div class="group-header">
                                        <label class="item-main">
                                            <input type="checkbox" class="item-checkbox group-item-checkbox"
                                                data-group="special" data-group-key="special-subject-head"
                                                data-group-label="หัวหน้ากลุ่มสาระ"
                                                data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;}]"
                                                value="special-subject-head">
                                            <span class="item-title">หัวหน้ากลุ่มสาระ</span>
                                            <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                        </label>
                                        <button type="button" class="group-toggle" aria-expanded="false"
                                            title="แสดง/ซ่อนรายชื่อสมาชิก">
                                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <ol class="member-sublist">
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นางจริยาวดี  เวชจันทร์"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="5800900028151">
                                                <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นางดวงกมล  เพ็ชรพรหม"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="3840100521778">
                                                <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นางพนิดา ค้าของ"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="3940400027034">
                                                <span class="member-name">นางพนิดา ค้าของ</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นางสาวนุชรีย์ หัศนี"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="1820700006258">
                                                <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นางสาวสุดาทิพย์ ยกย่อง"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="1820800031408">
                                                <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นางเสาวลีย์ จันทร์ทอง"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="3820700019381">
                                                <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นายธันวิน  ณ นคร"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="1959900030702">
                                                <span class="member-name">นายธันวิน ณ นคร</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นายนพรัตน์ ย้อยพระจันทร์"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="1839900094990">
                                                <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นายสมชาย สุทธจิตร์"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="3820700143669">
                                                <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                            </label>
                                        </li>
                                        <li>
                                            <label class="item member-item">
                                                <input type="checkbox" class="member-checkbox"
                                                    data-member-group-key="special-subject-head"
                                                    data-member-name="นายสุพัฒน์  เจริญฤทธิ์"
                                                    data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                    value="3820400194578">
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


        <div class="form-group last button">
            <div class="input-group">
                <button class="submit" type="submit" name="issue_type" value="regular"
                    data-confirm="ยืนยันการออกเลขทะเบียนส่งใช่หรือไม่?"
                    data-confirm-title="ยืนยันการออกเลขทะเบียน" data-confirm-ok="ยืนยัน" data-confirm-cancel="ยกเลิก">
                    <p>ออกเลขทะเบียน</p>
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
                    placeholder="ค้นหาเลขทะเบียนส่งหรือเรื่อง" autocomplete="off">
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
                        <option value="complete" <?= $filter_status === 'complete' ? 'selected' : '' ?>>แนบไฟล์สำเร็จ
                        </option>
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
            <h2 class="enterprise-card-title">รายการเลขทะเบียนส่งของฉัน</h2>
        </div>
    </div>

    <div class="table-responsive circular-my-table-wrap order-create">
        <script type="application/json" class="js-order-send-map">
            <?= (string) json_encode($send_modal_payload_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        </script>
        <table class="custom-table circular-my-table">
            <thead>
                <tr>
                    <th>เรื่อง</th>
                    <th>สถานะ</th>
                    <th>วันที่ดำเนินการ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4" class="enterprise-empty">ไม่พบรายการเลขทะเบียนส่งของฉัน</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $outgoing_id = (int) ($item['outgoingID'] ?? 0);
                        $outgoing_no = outgoing_display_number($item);
                        $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
                        $status_meta = $track_status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
                        $date_display_parts = $format_thai_datetime_parts((string) ($item['createdAt'] ?? ''));
                        $attachment_count = max(0, (int) ($item['attachmentCount'] ?? 0));
                        $modal_payload = (array) ($send_modal_payload_map[(string) $outgoing_id] ?? []);
                        $modal_priority_key = outgoing_normalize_priority_key((string) ($modal_payload['priorityKey'] ?? 'normal'));
                        $is_waiting_attachment = $status_key === OUTGOING_STATUS_WAITING_ATTACHMENT;
                        ?>
                        <tr>
                            <td>
                                <?php if ($outgoing_no !== ''): ?>
                                    <div class="circular-my-subject">เลขทะเบียนส่ง <?= h($outgoing_no) ?></div>
                                <?php endif; ?>
                                <div class="circular-my-meta"><?= h((string) ($item['subject'] ?? '-')) ?></div>
                            </td>
                            <td>
                                <span
                                    class="status-pill <?= h((string) ($status_meta['pill'] ?? 'pending')) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span>
                                <?php if ($attachment_count > 0): ?>
                                    <p class="viewer">แนบไฟล์แล้ว <?= h((string) $attachment_count) ?> ไฟล์</p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="order-create-datetime">
                                    <span
                                        class="order-create-datetime-date"><?= h((string) ($date_display_parts['date'] ?? '-')) ?></span>
                                    <span
                                        class="order-create-datetime-time"><?= h((string) ($date_display_parts['time'] ?? '-')) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="circular-my-actions">
                                    <?php if ($is_waiting_attachment): ?>
                                        <button class="booking-action-btn secondary js-open-order-edit-modal" type="button"
                                            data-outgoing-id="<?= h((string) $outgoing_id) ?>"
                                            data-outgoing-priority-key="<?= h($modal_priority_key) ?>" title="ดู/แนบไฟล์"
                                            aria-label="ดู/แนบไฟล์">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                            <span class="tooltip">ดู/แนบไฟล์</span>
                                        </button>
                                    <?php else: ?>
                                        <button class="booking-action-btn secondary js-open-order-view-modal" type="button"
                                            data-outgoing-id="<?= h((string) $outgoing_id) ?>"
                                            data-outgoing-priority-key="<?= h($modal_priority_key) ?>" title="ดูรายละเอียด"
                                            aria-label="ดูรายละเอียด">
                                            <i class="fa-solid fa-eye"></i>
                                            <span class="tooltip">ดูรายละเอียด</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
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
                    <p id="modalOutgoingEditTitle">แนบไฟล์เอกสารออกเลขทะเบียนส่ง</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="closeModalOrderSend"></i>
                </div>
            </div>

            <div class="content-modal">

                <form method="POST" id="modalOutgoingAttachForm" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="attach">
                    <input type="hidden" name="outgoing_id" id="modalOutgoingEditOutgoingId" value="">

                    <div class="type-urgent">
                        <p>ประเภท</p>
                        <div class="radio-group-urgent">
                            <input type="radio" name="outgoingEditUrgent" data-outgoing-edit-urgent="normal" checked
                                id="modalOutgoingEditUrgentNormal"><label
                                for="modalOutgoingEditUrgentNormal">ปกติ</label>
                            <input type="radio" name="outgoingEditUrgent" data-outgoing-edit-urgent="urgent"
                                id="modalOutgoingEditUrgentUrgent"><label
                                for="modalOutgoingEditUrgentUrgent">ด่วน</label>
                            <input type="radio" name="outgoingEditUrgent" data-outgoing-edit-urgent="high"
                                id="modalOutgoingEditUrgentHigh"><label
                                for="modalOutgoingEditUrgentHigh">ด่วนมาก</label>
                            <input type="radio" name="outgoingEditUrgent" data-outgoing-edit-urgent="highest"
                                id="modalOutgoingEditUrgentHighest"><label
                                for="modalOutgoingEditUrgentHighest">ด่วนที่สุด</label>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>เลขทะเบียน</strong></p>
                            <input type="text" id="modalOutgoingEditNo" class="order-no-display" value="-">
                        </div>
                        <div class="more-details">
                            <p><strong>เรื่อง</strong></p>
                            <input type="text" id="modalOutgoingEditSubject" class="order-no-display" value="-">
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>ลงวันที่</strong></p>
                            <input type="date" id="modalOutgoingEditEffectiveDate" class="order-no-display" value="">
                        </div>
                        <div class="more-details">
                            <p><strong>ส่งถึง</strong></p>
                            <input type="text" id="modalOutgoingEditIssuer" name="destination_name"
                                class="order-no-display" value="" required>
                        </div>
                    </div>

                    <div class="content-file-sec">
                        <label>อัปโหลดไฟล์หนังสือนำ</label>
                        <section class="upload-layout">
                            <input type="file" id="coverFileInput_modal" name="cover_file"
                                accept="application/pdf,image/png,image/jpeg" style="display: none;">

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

                        <label>อัปโหลดไฟล์เอกสาร</label>
                        <section class="upload-layout">
                            <input type="file" id="fileInput_modal" name="attachments[]" multiple
                                accept="application/pdf,image/png,image/jpeg" style="display: none;">

                            <div class="upload-box" id="dropzone_modal">
                                <i class="fa-solid fa-upload"></i>
                                <p>ลากไฟล์มาวางที่นี่</p>
                            </div>

                            <div class="existing-file-section">
                                <div class="file-list" id="existingFileListContainer_modal"></div>
                            </div>

                        </section>

                        <div class="row form-group">
                            <button class="btn btn-upload-small" type="button" id="btnAddFiles_modal">
                                <p>เพิ่มไฟล์</p>
                            </button>
                            <div class="file-hint">
                                <p>* แนบไฟล์เอกสารได้สูงสุด 4 ไฟล์ (รวม PNG และ PDF) *</p>
                            </div>
                        </div>

                    </div>

                    <div class="form-group receive edit" data-recipients-section="" data-owner-flat-list="disabled"
                        hidden style="display: none;" aria-hidden="true">
                        <label><strong>เจ้าของเรื่อง :</strong></label>
                        <div class="dropdown-container">
                            <div class="search-input-wrapper js-recipient-toggle">
                                <input type="text" class="search-input js-main-input"
                                    placeholder="ค้นหา หรือ เลือกข้อมูล..." autocomplete="off">
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </div>

                            <div class="dropdown-content js-dropdown-content">
                                <div class="dropdown-header">
                                    <label class="select-all-box">
                                        <input type="checkbox" class="js-select-all">เลือกทั้งหมด
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
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                            data-group="faction" data-group-key="faction-5"
                                                            data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                            data-members="[{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารกิจการนักเรียน&quot;}]"
                                                            name="faction_ids[]" value="5">
                                                        <span class="item-title">กลุ่มบริหารกิจการนักเรียน</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางชมทิศา ขันภักดี"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3820400215231">
                                                            <span class="member-name">นางชมทิศา ขันภักดี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางพวงทิพย์ ทวีรส"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3950300068146">
                                                            <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางสาวชาลิสา จิตต์พันธ์"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1829900172052">
                                                            <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางสาวนิรัตน์ เพชรแก้ว"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3820800038999">
                                                            <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางสาวอรบุษย์ หนักแน่น"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1829900170670">
                                                            <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางสุนิษา  จินดาพล"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3601000301019">
                                                            <span class="member-name">นางสุนิษา จินดาพล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางเขมษิญากรณ์ อุดมคุณ"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3820400309367">
                                                            <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นางเพ็ญแข หวานสนิท"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3930300329632">
                                                            <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายจรุง  บำรุงเขตต์"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3820400261097">
                                                            <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3820700017680">
                                                            <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายณัฐพงษ์ สัจจารักษ์"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1319800069611">
                                                            <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายธีรภัส  สฤษดิสุข"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1839900193629">
                                                            <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายธีระวัฒน์ เพชรขุ้ม"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1841500136302">
                                                            <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายพจนันท์  พรหมสงค์"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1829900109890">
                                                            <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายรชต  ปานบุญ"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1829900012446">
                                                            <span class="member-name">นายรชต ปานบุญ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายวรานนท์ ภาระพฤติ"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3820100028745">
                                                            <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายศุภสวัสดิ์ กาญวิจิต"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1829900093446">
                                                            <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายอนุสรณ์ ชูทอง"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="3929900087867">
                                                            <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1901100006087">
                                                            <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-5"
                                                                data-member-name="นายเอกพงษ์ สงวนทรัพย์"
                                                                data-group-label="กลุ่มบริหารกิจการนักเรียน"
                                                                name="person_ids[]" value="1829900072562">
                                                            <span class="member-name">นายเอกพงษ์ สงวนทรัพย์</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed" data-faction-id="4">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                            data-group="faction" data-group-key="faction-4"
                                                            data-group-label="กลุ่มบริหารงานทั่วไป"
                                                            data-members="[{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานทั่วไป&quot;}]"
                                                            name="faction_ids[]" value="4">
                                                        <span class="item-title">กลุ่มบริหารงานทั่วไป</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 21 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางจิตติพร เกตุรักษ์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1820500007021">
                                                            <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางพูนสุข ถิ่นลิพอน"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3820100172170">
                                                            <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางภทรมน ลิ่มบุตร"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1809900084706">
                                                            <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางวาสนา  สุทธจิตร์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3820100025495">
                                                            <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวธารทิพย์ ภาระพฤติ"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3850100320012">
                                                            <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวนิรชา ธรรมัสโร"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1829900174284">
                                                            <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวปาณิสรา  มงคลบุตร"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3829900019706">
                                                            <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวพรทิพย์ สมบัติบุญ"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1920100023843">
                                                            <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวพรรณพนัช  คงผอม"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1101401730717">
                                                            <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวรัตนาพร พรประสิทธิ์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1820500148121">
                                                            <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวราศรี  อนันตมงคลกุล"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3820500121271">
                                                            <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวสรัลรัตน์ จันทับ"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1809901015490">
                                                            <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นางสาวสุดาทิพย์ ยกย่อง"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1820800031408">
                                                            <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายนพดล วงศ์สุวัฒน์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1860700158147">
                                                            <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายนพพร  ถิ่นไทย"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1860100007288">
                                                            <span class="member-name">นายนพพร ถิ่นไทย</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายนรินทร์เพชร นิลเวช"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1102003266698">
                                                            <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายวิศรุต ชามทอง"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1160100618291">
                                                            <span class="member-name">นายวิศรุต ชามทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายสหัส เสือยืนยง"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3810500157631">
                                                            <span class="member-name">นายสหัส เสือยืนยง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายอิสรพงศ์ สัตปานนท์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1829900162341">
                                                            <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="นายเพลิน โอรักษ์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="3180600191510">
                                                            <span class="member-name">นายเพลิน โอรักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-4"
                                                                data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์"
                                                                data-group-label="กลุ่มบริหารงานทั่วไป"
                                                                name="person_ids[]" value="1809900094507">
                                                            <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์
                                                                ผสารพจน์</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed" data-faction-id="3">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                            data-group="faction" data-group-key="faction-3"
                                                            data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                            data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานบุคคลและงบประมาณ&quot;}]"
                                                            name="faction_ids[]" value="3">
                                                        <span class="item-title">กลุ่มบริหารงานบุคคลและงบประมาณ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 26 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางจริยาวดี  เวชจันทร์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="5800900028151">
                                                            <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางจิราภรณ์  เสรีรักษ์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="3800400522290">
                                                            <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="3920100747937">
                                                            <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางปวีณา  บำรุงภักดิ์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1829900007736">
                                                            <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางฝาติหม๊ะ ขนาดผล"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1930600099890">
                                                            <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1829900179103">
                                                            <span class="member-name">นางสาวกนกลักษณ์
                                                                พันธ์สวัสดิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวกานต์พิชชา ปากลาว"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1810500062871">
                                                            <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวธนวรรณ พิทักษ์คง"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1820500147966">
                                                            <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวธิดารัตน์ ทองกอบ"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1829900119712">
                                                            <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวนฤมล บุญถาวร"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1920600250041">
                                                            <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวนัยน์เนตร ทองวล"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1829900118058">
                                                            <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวนิลญา หมานมิตร"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1910300050321">
                                                            <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวบงกชรัตน์  มาลี"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1829900051727">
                                                            <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวบุษรา  เมืองชู"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1840100431373">
                                                            <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวปริษา  แก้วเขียว"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1829900090897">
                                                            <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวปาณิสรา  มัจฉาเวช"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1820400055491">
                                                            <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวปิยธิดา นิยมเดชา"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1820600006469">
                                                            <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="3820100171700">
                                                            <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวลภัสภาส์ หนูคง"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1820500130320">
                                                            <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาววรินญา โรจธนะวรรธน์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1940100013597">
                                                            <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1840100326120">
                                                            <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1102001245405">
                                                            <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางสุมณฑา  เกิดทรัพย์"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="3820700050342">
                                                            <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นางอรชา ชูเชื้อ"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1820700004867">
                                                            <span class="member-name">นางอรชา ชูเชื้อ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นายสราวุธ กุหลาบวรรณ"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1800800331088">
                                                            <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-3"
                                                                data-member-name="นายไชยวัฒน์ สังข์ทอง"
                                                                data-group-label="กลุ่มบริหารงานบุคคลและงบประมาณ"
                                                                name="person_ids[]" value="1640700056303">
                                                            <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed" data-faction-id="2">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                            data-group="faction" data-group-key="faction-2"
                                                            data-group-label="กลุ่มบริหารงานวิชาการ"
                                                            data-members="[{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มบริหารงานวิชาการ&quot;}]"
                                                            name="faction_ids[]" value="2">
                                                        <span class="item-title">กลุ่มบริหารงานวิชาการ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 45 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางกนกวรรณ  ณ นคร"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3810100580006">
                                                            <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางจารุวรรณ ส่องศิริ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820100025592">
                                                            <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางณิภาภรณ์  ไชยชนะ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3930300511171">
                                                            <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางดวงกมล  เพ็ชรพรหม"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3840100521778">
                                                            <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางดาริน ทรายทอง"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820300027670">
                                                            <span class="member-name">นางดาริน ทรายทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางธนิษฐา  ยงยุทธ์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900063989">
                                                            <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางประภาพร  อุดมผลชัยเจริญ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3331001384867">
                                                            <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางผกาวรรณ  โชติวัฒนากร"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1920600003469">
                                                            <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางพนิดา ค้าของ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3940400027034">
                                                            <span class="member-name">นางพนิดา ค้าของ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางพรพิมล แซ่เจี่ย"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1839900175043">
                                                            <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางพิมพา ทองอุไร"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900003064">
                                                            <span class="member-name">นางพิมพา ทองอุไร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900054688">
                                                            <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900059485">
                                                            <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวจิราวัลย์  อินทร์อักษร"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1930500083592">
                                                            <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวชนิกานต์  สวัสดิวงค์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3829900033725">
                                                            <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวณพสร สามสุวรรณ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3840200430855">
                                                            <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1729900457121">
                                                            <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวธนวรรณ สมัครการ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900202598">
                                                            <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวนุชรีย์ หัศนี"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1820700006258">
                                                            <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวประภัสสร  โอจันทร์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3840700282162">
                                                            <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวประภาพรรณ กุลแก้ว"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1410100117524">
                                                            <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวพิมพ์ประภา  ผลากิจ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900096909">
                                                            <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820400028481">
                                                            <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวรัชฎาพร สุวรรณสาม"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900012535">
                                                            <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวรัชนีกร ผอมจีน"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1820500097624">
                                                            <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820700136859">
                                                            <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวศศิธร นาคสง"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3801600044431">
                                                            <span class="member-name">นางสาวศศิธร นาคสง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวศุลีพร ขันภักดี"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900099401">
                                                            <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900065485">
                                                            <span class="member-name">นางสาวองค์ปรางค์
                                                                แสงสุรินทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวอภิชญา จันทร์มา"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1909901558298">
                                                            <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวอาตีนา  พัชนี"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1800100218262">
                                                            <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสาวอินทิรา บุญนิสสัย"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1800800204043">
                                                            <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1930500116202">
                                                            <span class="member-name">นางสุรางค์รัศมิ์
                                                                ย้อยพระจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นางเสาวลีย์ จันทร์ทอง"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820700019381">
                                                            <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายจตุรวิทย์ มิตรวงศ์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1859900070560">
                                                            <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1809901028575">
                                                            <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายธันวิน  ณ นคร"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1959900030702">
                                                            <span class="member-name">นายธันวิน ณ นคร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายนพรัตน์ ย้อยพระจันทร์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1839900094990">
                                                            <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายบพิธ มังคะลา"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1819900163142">
                                                            <span class="member-name">นายบพิธ มังคะลา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายประสิทธิ์  สะไน"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1920400002230">
                                                            <span class="member-name">นายประสิทธิ์ สะไน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายพิพัฒน์ ไชยชนะ"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3940400221191">
                                                            <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายศรายุทธ  มิตรวงค์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820800037747">
                                                            <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="1829900056460">
                                                            <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายสมชาย สุทธจิตร์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820700143669">
                                                            <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-2"
                                                                data-member-name="นายสุพัฒน์  เจริญฤทธิ์"
                                                                data-group-label="กลุ่มบริหารงานวิชาการ"
                                                                name="person_ids[]" value="3820400194578">
                                                            <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed" data-faction-id="6">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox faction-item-checkbox"
                                                            data-group="faction" data-group-key="faction-6"
                                                            data-group-label="กลุ่มสนับสนุนการสอน"
                                                            data-members="[{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสนับสนุนการสอน&quot;}]"
                                                            name="faction_ids[]" value="6">
                                                        <span class="item-title">กลุ่มสนับสนุนการสอน</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-6"
                                                                data-member-name="นางสาวนัฐลิณี ทอสงค์"
                                                                data-group-label="กลุ่มสนับสนุนการสอน"
                                                                name="person_ids[]" value="1820700059157">
                                                            <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-6"
                                                                data-member-name="นางสาวอุบลวรรณ คงสม"
                                                                data-group-label="กลุ่มสนับสนุนการสอน"
                                                                name="person_ids[]" value="1829900149409">
                                                            <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="faction-6"
                                                                data-member-name="นายสิงหนาท  แต่งแก้ว"
                                                                data-group-label="กลุ่มสนับสนุนการสอน"
                                                                name="person_ids[]" value="3810200084621">
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
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-9"
                                                            data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน"
                                                            data-members="[{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;1102001245405&quot;,&quot;name&quot;:&quot;นางสาวอมราภรณ์ เพ็ชรสุ่ม&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;},{&quot;pID&quot;:&quot;3810200084621&quot;,&quot;name&quot;:&quot;นายสิงหนาท  แต่งแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มกิจกรรมพัฒนาผู้เรียน&quot;}]"
                                                            value="department-9">
                                                        <span class="item-title">กลุ่มกิจกรรมพัฒนาผู้เรียน</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 3 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-9"
                                                                data-member-name="นางสาวนุชรีย์ หัศนี"
                                                                data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน"
                                                                name="person_ids[]" value="1820700006258">
                                                            <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-9"
                                                                data-member-name="นางสาวอมราภรณ์ เพ็ชรสุ่ม"
                                                                data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน"
                                                                name="person_ids[]" value="1102001245405">
                                                            <span class="member-name">นางสาวอมราภรณ์ เพ็ชรสุ่ม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-9"
                                                                data-member-name="นายสิงหนาท  แต่งแก้ว"
                                                                data-group-label="กลุ่มกิจกรรมพัฒนาผู้เรียน"
                                                                name="person_ids[]" value="3810200084621">
                                                            <span class="member-name">นายสิงหนาท แต่งแก้ว</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-10"
                                                            data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                            data-members="[{&quot;pID&quot;:&quot;1930500083592&quot;,&quot;name&quot;:&quot;นางสาวจิราวัลย์  อินทร์อักษร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3801600044431&quot;,&quot;name&quot;:&quot;นางสาวศศิธร นาคสง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1859900070560&quot;,&quot;name&quot;:&quot;นายจตุรวิทย์ มิตรวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;1819900163142&quot;,&quot;name&quot;:&quot;นายบพิธ มังคะลา&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;},{&quot;pID&quot;:&quot;3810500157631&quot;,&quot;name&quot;:&quot;นายสหัส เสือยืนยง&quot;,&quot;faction&quot;:&quot;กลุ่มคอมพิวเตอร์และเทคโนโลยี&quot;}]"
                                                            value="department-10">
                                                        <span class="item-title">กลุ่มคอมพิวเตอร์และเทคโนโลยี</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-10"
                                                                data-member-name="นางสาวจิราวัลย์  อินทร์อักษร"
                                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                                name="person_ids[]" value="1930500083592">
                                                            <span class="member-name">นางสาวจิราวัลย์ อินทร์อักษร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-10"
                                                                data-member-name="นางสาวศศิธร นาคสง"
                                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                                name="person_ids[]" value="3801600044431">
                                                            <span class="member-name">นางสาวศศิธร นาคสง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-10"
                                                                data-member-name="นายจตุรวิทย์ มิตรวงศ์"
                                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                                name="person_ids[]" value="1859900070560">
                                                            <span class="member-name">นายจตุรวิทย์ มิตรวงศ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-10"
                                                                data-member-name="นายธันวิน  ณ นคร"
                                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                                name="person_ids[]" value="1959900030702">
                                                            <span class="member-name">นายธันวิน ณ นคร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-10"
                                                                data-member-name="นายบพิธ มังคะลา"
                                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                                name="person_ids[]" value="1819900163142">
                                                            <span class="member-name">นายบพิธ มังคะลา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-10"
                                                                data-member-name="นายสหัส เสือยืนยง"
                                                                data-group-label="กลุ่มคอมพิวเตอร์และเทคโนโลยี"
                                                                name="person_ids[]" value="3810500157631">
                                                            <span class="member-name">นายสหัส เสือยืนยง</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-11"
                                                            data-group-label="กลุ่มธุรการ"
                                                            data-members="[{&quot;pID&quot;:&quot;3820400234871&quot;,&quot;name&quot;:&quot;นางนวลน้อย  ชูสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1800700082485&quot;,&quot;name&quot;:&quot;นางสาว ณัฐชลียา ยิ่งคง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1829900082835&quot;,&quot;name&quot;:&quot;นางสาวจารุลักษณ์  ตรีศรี&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100155283&quot;,&quot;name&quot;:&quot;นางสาวจิราวรรณ ว่องปลูกศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;2800800033557&quot;,&quot;name&quot;:&quot;นางสาวธัญเรศ  วรศานต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820600035619&quot;,&quot;name&quot;:&quot;นางสาวนภัสสร  รัฐการ&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1810600075673&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร พันธ์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3820100140782&quot;,&quot;name&quot;:&quot;นางสาวศศิธร  มธุรส&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;3810300076964&quot;,&quot;name&quot;:&quot;นายอดิศักดิ์  ธรรมจิตต์&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;},{&quot;pID&quot;:&quot;1640700056303&quot;,&quot;name&quot;:&quot;นายไชยวัฒน์ สังข์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มธุรการ&quot;}]"
                                                            value="department-11">
                                                        <span class="item-title">กลุ่มธุรการ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางนวลน้อย  ชูสงค์"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="3820400234871">
                                                            <span class="member-name">นางนวลน้อย ชูสงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาว ณัฐชลียา ยิ่งคง"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="1800700082485">
                                                            <span class="member-name">นางสาว ณัฐชลียา ยิ่งคง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาวจารุลักษณ์  ตรีศรี"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="1829900082835">
                                                            <span class="member-name">นางสาวจารุลักษณ์ ตรีศรี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาวจิราวรรณ ว่องปลูกศิลป์"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="3820100155283">
                                                            <span class="member-name">นางสาวจิราวรรณ
                                                                ว่องปลูกศิลป์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาวธัญเรศ  วรศานต์"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="2800800033557">
                                                            <span class="member-name">นางสาวธัญเรศ วรศานต์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาวนภัสสร  รัฐการ"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="3820600035619">
                                                            <span class="member-name">นางสาวนภัสสร รัฐการ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาวประภัสสร พันธ์แก้ว"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="1810600075673">
                                                            <span class="member-name">นางสาวประภัสสร พันธ์แก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นางสาวศศิธร  มธุรส"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="3820100140782">
                                                            <span class="member-name">นางสาวศศิธร มธุรส</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นายอดิศักดิ์  ธรรมจิตต์"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="3810300076964">
                                                            <span class="member-name">นายอดิศักดิ์ ธรรมจิตต์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-11"
                                                                data-member-name="นายไชยวัฒน์ สังข์ทอง"
                                                                data-group-label="กลุ่มธุรการ" name="person_ids[]"
                                                                value="1640700056303">
                                                            <span class="member-name">นายไชยวัฒน์ สังข์ทอง</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-7"
                                                            data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                            data-members="[{&quot;pID&quot;:&quot;1829900062591&quot;,&quot;name&quot;:&quot;นางสาวจารุวรรณ ผลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3810500179350&quot;,&quot;name&quot;:&quot;นางสาวนงลักษณ์   แก้วสว่าง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1849900176813&quot;,&quot;name&quot;:&quot;นายชนม์กมล เพ็ขรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1829900003064&quot;,&quot;name&quot;:&quot;นางพิมพา ทองอุไร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3800400522290&quot;,&quot;name&quot;:&quot;นางจิราภรณ์  เสรีรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;3820100172170&quot;,&quot;name&quot;:&quot;นางพูนสุข ถิ่นลิพอน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1809900084706&quot;,&quot;name&quot;:&quot;นางภทรมน ลิ่มบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;},{&quot;pID&quot;:&quot;1860100007288&quot;,&quot;name&quot;:&quot;นายนพพร  ถิ่นไทย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ การงานอาชีพ&quot;}]"
                                                            value="department-7">
                                                        <span class="item-title">กลุ่มสาระฯ การงานอาชีพ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 9 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางสาวจารุวรรณ ผลแก้ว"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="1829900062591">
                                                            <span class="member-name">นางสาวจารุวรรณ ผลแก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางสาวนงลักษณ์   แก้วสว่าง"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="3810500179350">
                                                            <span class="member-name">นางสาวนงลักษณ์ แก้วสว่าง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นายชนม์กมล เพ็ขรพรหม"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="1849900176813">
                                                            <span class="member-name">นายชนม์กมล เพ็ขรพรหม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางพิมพา ทองอุไร"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="1829900003064">
                                                            <span class="member-name">นางพิมพา ทองอุไร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางจริยาวดี  เวชจันทร์"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="5800900028151">
                                                            <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางจิราภรณ์  เสรีรักษ์"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="3800400522290">
                                                            <span class="member-name">นางจิราภรณ์ เสรีรักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางพูนสุข ถิ่นลิพอน"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="3820100172170">
                                                            <span class="member-name">นางพูนสุข ถิ่นลิพอน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นางภทรมน ลิ่มบุตร"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="1809900084706">
                                                            <span class="member-name">นางภทรมน ลิ่มบุตร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-7"
                                                                data-member-name="นายนพพร  ถิ่นไทย"
                                                                data-group-label="กลุ่มสาระฯ การงานอาชีพ"
                                                                name="person_ids[]" value="1860100007288">
                                                            <span class="member-name">นายนพพร ถิ่นไทย</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-2"
                                                            data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                            data-members="[{&quot;pID&quot;:&quot;1829900206275&quot;,&quot;name&quot;:&quot;นายภูมิวิชญ์ จีนนาพัฒ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3810100580006&quot;,&quot;name&quot;:&quot;นางกนกวรรณ  ณ นคร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3331001384867&quot;,&quot;name&quot;:&quot;นางประภาพร  อุดมผลชัยเจริญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600003469&quot;,&quot;name&quot;:&quot;นางผกาวรรณ  โชติวัฒนากร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900175043&quot;,&quot;name&quot;:&quot;นางพรพิมล แซ่เจี่ย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900096909&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์ประภา  ผลากิจ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500097624&quot;,&quot;name&quot;:&quot;นางสาวรัชนีกร ผอมจีน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1909901558298&quot;,&quot;name&quot;:&quot;นางสาวอภิชญา จันทร์มา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800204043&quot;,&quot;name&quot;:&quot;นางสาวอินทิรา บุญนิสสัย&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1809901028575&quot;,&quot;name&quot;:&quot;นายธนวัฒน์ ศิริพงศ์ประพันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3940400221191&quot;,&quot;name&quot;:&quot;นายพิพัฒน์ ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1930600099890&quot;,&quot;name&quot;:&quot;นางฝาติหม๊ะ ขนาดผล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900119712&quot;,&quot;name&quot;:&quot;นางสาวธิดารัตน์ ทองกอบ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900051727&quot;,&quot;name&quot;:&quot;นางสาวบงกชรัตน์  มาลี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1800800331088&quot;,&quot;name&quot;:&quot;นายสราวุธ กุหลาบวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1920100023843&quot;,&quot;name&quot;:&quot;นางสาวพรทิพย์ สมบัติบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820500148121&quot;,&quot;name&quot;:&quot;นางสาวรัตนาพร พรประสิทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;3929900087867&quot;,&quot;name&quot;:&quot;นายอนุสรณ์ ชูทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700059157&quot;,&quot;name&quot;:&quot;นางสาวนัฐลิณี ทอสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ คณิตศาสตร์&quot;}]"
                                                            value="department-2">
                                                        <span class="item-title">กลุ่มสาระฯ คณิตศาสตร์</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นายภูมิวิชญ์ จีนนาพัฒ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1829900206275">
                                                            <span class="member-name">นายภูมิวิชญ์ จีนนาพัฒ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางกนกวรรณ  ณ นคร"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="3810100580006">
                                                            <span class="member-name">นางกนกวรรณ ณ นคร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางประภาพร  อุดมผลชัยเจริญ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="3331001384867">
                                                            <span class="member-name">นางประภาพร อุดมผลชัยเจริญ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางผกาวรรณ  โชติวัฒนากร"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1920600003469">
                                                            <span class="member-name">นางผกาวรรณ โชติวัฒนากร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางพรพิมล แซ่เจี่ย"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1839900175043">
                                                            <span class="member-name">นางพรพิมล แซ่เจี่ย</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวพิมพ์ประภา  ผลากิจ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1829900096909">
                                                            <span class="member-name">นางสาวพิมพ์ประภา ผลากิจ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวรัชนีกร ผอมจีน"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1820500097624">
                                                            <span class="member-name">นางสาวรัชนีกร ผอมจีน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวอภิชญา จันทร์มา"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1909901558298">
                                                            <span class="member-name">นางสาวอภิชญา จันทร์มา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวอินทิรา บุญนิสสัย"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1800800204043">
                                                            <span class="member-name">นางสาวอินทิรา บุญนิสสัย</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางเสาวลีย์ จันทร์ทอง"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="3820700019381">
                                                            <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นายธนวัฒน์ ศิริพงศ์ประพันธ์"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1809901028575">
                                                            <span class="member-name">นายธนวัฒน์ ศิริพงศ์ประพันธ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นายพิพัฒน์ ไชยชนะ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="3940400221191">
                                                            <span class="member-name">นายพิพัฒน์ ไชยชนะ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางฝาติหม๊ะ ขนาดผล"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1930600099890">
                                                            <span class="member-name">นางฝาติหม๊ะ ขนาดผล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวธิดารัตน์ ทองกอบ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1829900119712">
                                                            <span class="member-name">นางสาวธิดารัตน์ ทองกอบ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวบงกชรัตน์  มาลี"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1829900051727">
                                                            <span class="member-name">นางสาวบงกชรัตน์ มาลี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นายสราวุธ กุหลาบวรรณ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1800800331088">
                                                            <span class="member-name">นายสราวุธ กุหลาบวรรณ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวพรทิพย์ สมบัติบุญ"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1920100023843">
                                                            <span class="member-name">นางสาวพรทิพย์ สมบัติบุญ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวรัตนาพร พรประสิทธิ์"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1820500148121">
                                                            <span class="member-name">นางสาวรัตนาพร พรประสิทธิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นายอนุสรณ์ ชูทอง"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="3929900087867">
                                                            <span class="member-name">นายอนุสรณ์ ชูทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-2"
                                                                data-member-name="นางสาวนัฐลิณี ทอสงค์"
                                                                data-group-label="กลุ่มสาระฯ คณิตศาสตร์"
                                                                name="person_ids[]" value="1820700059157">
                                                            <span class="member-name">นางสาวนัฐลิณี ทอสงค์</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-8"
                                                            data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                            data-members="[{&quot;pID&quot;:&quot;1820800093039&quot;,&quot;name&quot;:&quot;นางสาวปาริชาต เดชอาษา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1809900831358&quot;,&quot;name&quot;:&quot;นางสาวพลอยไพลิน เที่ยวแสวง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820300027670&quot;,&quot;name&quot;:&quot;นางดาริน ทรายทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900054688&quot;,&quot;name&quot;:&quot;นางสาวกนกรัตน์ อุ้ยเฉ้ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900059485&quot;,&quot;name&quot;:&quot;นางสาวจันทิพา ประทีป ณ ถลาง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1729900457121&quot;,&quot;name&quot;:&quot;นางสาวณัฐวรรณ  ทรัพย์เฉลิม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900202598&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ สมัครการ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900065485&quot;,&quot;name&quot;:&quot;นางสาวองค์ปรางค์ แสงสุรินทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1930500116202&quot;,&quot;name&quot;:&quot;นางสุรางค์รัศมิ์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1810500062871&quot;,&quot;name&quot;:&quot;นางสาวกานต์พิชชา ปากลาว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1910300050321&quot;,&quot;name&quot;:&quot;นางสาวนิลญา หมานมิตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900090897&quot;,&quot;name&quot;:&quot;นางสาวปริษา  แก้วเขียว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1940100013597&quot;,&quot;name&quot;:&quot;นางสาววรินญา โรจธนะวรรธน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1829900162341&quot;,&quot;name&quot;:&quot;นายอิสรพงศ์ สัตปานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3950300068146&quot;,&quot;name&quot;:&quot;นางพวงทิพย์ ทวีรส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820400309367&quot;,&quot;name&quot;:&quot;นางเขมษิญากรณ์ อุดมคุณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3930300329632&quot;,&quot;name&quot;:&quot;นางเพ็ญแข หวานสนิท&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;3820700017680&quot;,&quot;name&quot;:&quot;นายณณัฐพล  บุญสุรัชต์สิรี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;},{&quot;pID&quot;:&quot;1841500136302&quot;,&quot;name&quot;:&quot;นายธีระวัฒน์ เพชรขุ้ม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาต่างประเทศ&quot;}]"
                                                            value="department-8">
                                                        <span class="item-title">กลุ่มสาระฯ ภาษาต่างประเทศ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 20 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวปาริชาต เดชอาษา"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1820800093039">
                                                            <span class="member-name">นางสาวปาริชาต เดชอาษา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวพลอยไพลิน เที่ยวแสวง"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1809900831358">
                                                            <span class="member-name">นางสาวพลอยไพลิน เที่ยวแสวง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางดาริน ทรายทอง"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="3820300027670">
                                                            <span class="member-name">นางดาริน ทรายทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางพนิดา ค้าของ"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="3940400027034">
                                                            <span class="member-name">นางพนิดา ค้าของ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวกนกรัตน์ อุ้ยเฉ้ง"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1829900054688">
                                                            <span class="member-name">นางสาวกนกรัตน์ อุ้ยเฉ้ง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวจันทิพา ประทีป ณ ถลาง"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1829900059485">
                                                            <span class="member-name">นางสาวจันทิพา ประทีป ณ ถลาง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวณัฐวรรณ  ทรัพย์เฉลิม"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1729900457121">
                                                            <span class="member-name">นางสาวณัฐวรรณ ทรัพย์เฉลิม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวธนวรรณ สมัครการ"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1829900202598">
                                                            <span class="member-name">นางสาวธนวรรณ สมัครการ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวองค์ปรางค์ แสงสุรินทร์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1829900065485">
                                                            <span class="member-name">นางสาวองค์ปรางค์
                                                                แสงสุรินทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสุรางค์รัศมิ์ ย้อยพระจันทร์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1930500116202">
                                                            <span class="member-name">นางสุรางค์รัศมิ์
                                                                ย้อยพระจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวกานต์พิชชา ปากลาว"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1810500062871">
                                                            <span class="member-name">นางสาวกานต์พิชชา ปากลาว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวนิลญา หมานมิตร"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1910300050321">
                                                            <span class="member-name">นางสาวนิลญา หมานมิตร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาวปริษา  แก้วเขียว"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1829900090897">
                                                            <span class="member-name">นางสาวปริษา แก้วเขียว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางสาววรินญา โรจธนะวรรธน์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1940100013597">
                                                            <span class="member-name">นางสาววรินญา โรจธนะวรรธน์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นายอิสรพงศ์ สัตปานนท์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1829900162341">
                                                            <span class="member-name">นายอิสรพงศ์ สัตปานนท์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางพวงทิพย์ ทวีรส"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="3950300068146">
                                                            <span class="member-name">นางพวงทิพย์ ทวีรส</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางเขมษิญากรณ์ อุดมคุณ"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="3820400309367">
                                                            <span class="member-name">นางเขมษิญากรณ์ อุดมคุณ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นางเพ็ญแข หวานสนิท"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="3930300329632">
                                                            <span class="member-name">นางเพ็ญแข หวานสนิท</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นายณณัฐพล  บุญสุรัชต์สิรี"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="3820700017680">
                                                            <span class="member-name">นายณณัฐพล บุญสุรัชต์สิรี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-8"
                                                                data-member-name="นายธีระวัฒน์ เพชรขุ้ม"
                                                                data-group-label="กลุ่มสาระฯ ภาษาต่างประเทศ"
                                                                name="person_ids[]" value="1841500136302">
                                                            <span class="member-name">นายธีระวัฒน์ เพชรขุ้ม</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-1"
                                                            data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                            data-members="[{&quot;pID&quot;:&quot;1829900103735&quot;,&quot;name&quot;:&quot;นางสาวจันทนี บุญนำ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900141980&quot;,&quot;name&quot;:&quot;นางสาวสุกานดา ปานมั่งคั่ง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3840200430855&quot;,&quot;name&quot;:&quot;นางสาวณพสร สามสุวรรณ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820400028481&quot;,&quot;name&quot;:&quot;นางสาวยศยา ศักดิ์ศิลปศาสตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820700136859&quot;,&quot;name&quot;:&quot;นางสาวลภัสนันท์ บำรุงวงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900118058&quot;,&quot;name&quot;:&quot;นางสาวนัยน์เนตร ทองวล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1840100431373&quot;,&quot;name&quot;:&quot;นางสาวบุษรา  เมืองชู&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1820500007021&quot;,&quot;name&quot;:&quot;นางจิตติพร เกตุรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1101401730717&quot;,&quot;name&quot;:&quot;นางสาวพรรณพนัช  คงผอม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;3820500121271&quot;,&quot;name&quot;:&quot;นางสาวราศรี  อนันตมงคลกุล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1860700158147&quot;,&quot;name&quot;:&quot;นายนพดล วงศ์สุวัฒน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1102003266698&quot;,&quot;name&quot;:&quot;นายนรินทร์เพชร นิลเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;},{&quot;pID&quot;:&quot;1829900109890&quot;,&quot;name&quot;:&quot;นายพจนันท์  พรหมสงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ภาษาไทย&quot;}]"
                                                            value="department-1">
                                                        <span class="item-title">กลุ่มสาระฯ ภาษาไทย</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 14 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวจันทนี บุญนำ"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1829900103735">
                                                            <span class="member-name">นางสาวจันทนี บุญนำ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวสุกานดา ปานมั่งคั่ง"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1829900141980">
                                                            <span class="member-name">นางสาวสุกานดา ปานมั่งคั่ง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางดวงกมล  เพ็ชรพรหม"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="3840100521778">
                                                            <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวณพสร สามสุวรรณ"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="3840200430855">
                                                            <span class="member-name">นางสาวณพสร สามสุวรรณ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวยศยา ศักดิ์ศิลปศาสตร์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="3820400028481">
                                                            <span class="member-name">นางสาวยศยา ศักดิ์ศิลปศาสตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวลภัสนันท์ บำรุงวงศ์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="3820700136859">
                                                            <span class="member-name">นางสาวลภัสนันท์ บำรุงวงศ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวนัยน์เนตร ทองวล"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1829900118058">
                                                            <span class="member-name">นางสาวนัยน์เนตร ทองวล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวบุษรา  เมืองชู"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1840100431373">
                                                            <span class="member-name">นางสาวบุษรา เมืองชู</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางจิตติพร เกตุรักษ์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1820500007021">
                                                            <span class="member-name">นางจิตติพร เกตุรักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวพรรณพนัช  คงผอม"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1101401730717">
                                                            <span class="member-name">นางสาวพรรณพนัช คงผอม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นางสาวราศรี  อนันตมงคลกุล"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="3820500121271">
                                                            <span class="member-name">นางสาวราศรี อนันตมงคลกุล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นายนพดล วงศ์สุวัฒน์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1860700158147">
                                                            <span class="member-name">นายนพดล วงศ์สุวัฒน์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นายนรินทร์เพชร นิลเวช"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1102003266698">
                                                            <span class="member-name">นายนรินทร์เพชร นิลเวช</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-1"
                                                                data-member-name="นายพจนันท์  พรหมสงค์"
                                                                data-group-label="กลุ่มสาระฯ ภาษาไทย"
                                                                name="person_ids[]" value="1829900109890">
                                                            <span class="member-name">นายพจนันท์ พรหมสงค์</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-3"
                                                            data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                            data-members="[{&quot;pID&quot;:&quot;1819300006267&quot;,&quot;name&quot;:&quot;นายคุณากร ประดับศิลป์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400295111&quot;,&quot;name&quot;:&quot;นายนิมิตร สุสิมานนท์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3930300511171&quot;,&quot;name&quot;:&quot;นางณิภาภรณ์  ไชยชนะ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900063989&quot;,&quot;name&quot;:&quot;นางธนิษฐา  ยงยุทธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012535&quot;,&quot;name&quot;:&quot;นางสาวรัชฎาพร สุวรรณสาม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900099401&quot;,&quot;name&quot;:&quot;นางสาวศุลีพร ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1800100218262&quot;,&quot;name&quot;:&quot;นางสาวอาตีนา  พัชนี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3920100747937&quot;,&quot;name&quot;:&quot;นางจุไรรัตน์ สวัสดิ์วงศ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900179103&quot;,&quot;name&quot;:&quot;นางสาวกนกลักษณ์ พันธ์สวัสดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1920600250041&quot;,&quot;name&quot;:&quot;นางสาวนฤมล บุญถาวร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820400055491&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มัจฉาเวช&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820100171700&quot;,&quot;name&quot;:&quot;นางสาวพิมพ์จันทร์  สุวรรณดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1840100326120&quot;,&quot;name&quot;:&quot;นางสาวศรัณย์รัชต์ สุขผ่องใส&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820700050342&quot;,&quot;name&quot;:&quot;นางสุมณฑา  เกิดทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1820700004867&quot;,&quot;name&quot;:&quot;นางอรชา ชูเชื้อ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3820400215231&quot;,&quot;name&quot;:&quot;นางชมทิศา ขันภักดี&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900172052&quot;,&quot;name&quot;:&quot;นางสาวชาลิสา จิตต์พันธ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900170670&quot;,&quot;name&quot;:&quot;นางสาวอรบุษย์ หนักแน่น&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;3601000301019&quot;,&quot;name&quot;:&quot;นางสุนิษา  จินดาพล&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1319800069611&quot;,&quot;name&quot;:&quot;นายณัฐพงษ์ สัจจารักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1839900193629&quot;,&quot;name&quot;:&quot;นายธีรภัส  สฤษดิสุข&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900012446&quot;,&quot;name&quot;:&quot;นายรชต  ปานบุญ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;},{&quot;pID&quot;:&quot;1829900149409&quot;,&quot;name&quot;:&quot;นางสาวอุบลวรรณ คงสม&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ วิทยาศาสตร์&quot;}]"
                                                            value="department-3">
                                                        <span class="item-title">กลุ่มสาระฯ วิทยาศาสตร์</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 24 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นายคุณากร ประดับศิลป์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1819300006267">
                                                            <span class="member-name">นายคุณากร ประดับศิลป์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นายนิมิตร สุสิมานนท์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3820400295111">
                                                            <span class="member-name">นายนิมิตร สุสิมานนท์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางณิภาภรณ์  ไชยชนะ"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3930300511171">
                                                            <span class="member-name">นางณิภาภรณ์ ไชยชนะ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางธนิษฐา  ยงยุทธ์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900063989">
                                                            <span class="member-name">นางธนิษฐา ยงยุทธ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวรัชฎาพร สุวรรณสาม"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900012535">
                                                            <span class="member-name">นางสาวรัชฎาพร สุวรรณสาม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวศุลีพร ขันภักดี"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900099401">
                                                            <span class="member-name">นางสาวศุลีพร ขันภักดี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวอาตีนา  พัชนี"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1800100218262">
                                                            <span class="member-name">นางสาวอาตีนา พัชนี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นายนพรัตน์ ย้อยพระจันทร์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1839900094990">
                                                            <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางจุไรรัตน์ สวัสดิ์วงศ์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3920100747937">
                                                            <span class="member-name">นางจุไรรัตน์ สวัสดิ์วงศ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวกนกลักษณ์ พันธ์สวัสดิ์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900179103">
                                                            <span class="member-name">นางสาวกนกลักษณ์
                                                                พันธ์สวัสดิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวนฤมล บุญถาวร"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1920600250041">
                                                            <span class="member-name">นางสาวนฤมล บุญถาวร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวปาณิสรา  มัจฉาเวช"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1820400055491">
                                                            <span class="member-name">นางสาวปาณิสรา มัจฉาเวช</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวพิมพ์จันทร์  สุวรรณดี"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3820100171700">
                                                            <span class="member-name">นางสาวพิมพ์จันทร์ สุวรรณดี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวศรัณย์รัชต์ สุขผ่องใส"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1840100326120">
                                                            <span class="member-name">นางสาวศรัณย์รัชต์ สุขผ่องใส</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสุมณฑา  เกิดทรัพย์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3820700050342">
                                                            <span class="member-name">นางสุมณฑา เกิดทรัพย์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางอรชา ชูเชื้อ"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1820700004867">
                                                            <span class="member-name">นางอรชา ชูเชื้อ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางชมทิศา ขันภักดี"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3820400215231">
                                                            <span class="member-name">นางชมทิศา ขันภักดี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวชาลิสา จิตต์พันธ์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900172052">
                                                            <span class="member-name">นางสาวชาลิสา จิตต์พันธ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวอรบุษย์ หนักแน่น"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900170670">
                                                            <span class="member-name">นางสาวอรบุษย์ หนักแน่น</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสุนิษา  จินดาพล"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="3601000301019">
                                                            <span class="member-name">นางสุนิษา จินดาพล</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นายณัฐพงษ์ สัจจารักษ์"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1319800069611">
                                                            <span class="member-name">นายณัฐพงษ์ สัจจารักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นายธีรภัส  สฤษดิสุข"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1839900193629">
                                                            <span class="member-name">นายธีรภัส สฤษดิสุข</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นายรชต  ปานบุญ"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900012446">
                                                            <span class="member-name">นายรชต ปานบุญ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-3"
                                                                data-member-name="นางสาวอุบลวรรณ คงสม"
                                                                data-group-label="กลุ่มสาระฯ วิทยาศาสตร์"
                                                                name="person_ids[]" value="1829900149409">
                                                            <span class="member-name">นางสาวอุบลวรรณ คงสม</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-6"
                                                            data-group-label="กลุ่มสาระฯ ศิลปะ"
                                                            data-members="[{&quot;pID&quot;:&quot;3840700282162&quot;,&quot;name&quot;:&quot;นางสาวประภัสสร  โอจันทร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1829900056460&quot;,&quot;name&quot;:&quot;นายศุภวุฒิ &nbsp;อินทร์แก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3850100320012&quot;,&quot;name&quot;:&quot;นางสาวธารทิพย์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;3829900019706&quot;,&quot;name&quot;:&quot;นางสาวปาณิสรา  มงคลบุตร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1160100618291&quot;,&quot;name&quot;:&quot;นายวิศรุต ชามทอง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;},{&quot;pID&quot;:&quot;1901100006087&quot;,&quot;name&quot;:&quot;นายเจนสกนธ์  ศรัณย์บัณฑิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ ศิลปะ&quot;}]"
                                                            value="department-6">
                                                        <span class="item-title">กลุ่มสาระฯ ศิลปะ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 7 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นางสาวประภัสสร  โอจันทร์"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="3840700282162">
                                                            <span class="member-name">นางสาวประภัสสร โอจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นายศุภวุฒิ &nbsp;อินทร์แก้ว"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="1829900056460">
                                                            <span class="member-name">นายศุภวุฒิ &nbsp;อินทร์แก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นายสุพัฒน์  เจริญฤทธิ์"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="3820400194578">
                                                            <span class="member-name">นายสุพัฒน์ เจริญฤทธิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นางสาวธารทิพย์ ภาระพฤติ"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="3850100320012">
                                                            <span class="member-name">นางสาวธารทิพย์ ภาระพฤติ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นางสาวปาณิสรา  มงคลบุตร"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="3829900019706">
                                                            <span class="member-name">นางสาวปาณิสรา มงคลบุตร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นายวิศรุต ชามทอง"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="1160100618291">
                                                            <span class="member-name">นายวิศรุต ชามทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-6"
                                                                data-member-name="นายเจนสกนธ์  ศรัณย์บัณฑิต"
                                                                data-group-label="กลุ่มสาระฯ ศิลปะ" name="person_ids[]"
                                                                value="1901100006087">
                                                            <span class="member-name">นายเจนสกนธ์ ศรัณย์บัณฑิต</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-4"
                                                            data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                            data-members="[{&quot;pID&quot;:&quot;1830101156953&quot;,&quot;name&quot;:&quot;นางสาวนัสรีน สุวิสัน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1810300103434&quot;,&quot;name&quot;:&quot;นางสาวปณิดา คลองรั้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820501214179&quot;,&quot;name&quot;:&quot;นายมงคล ตันเจริญรัตน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025592&quot;,&quot;name&quot;:&quot;นางจารุวรรณ ส่องศิริ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3829900033725&quot;,&quot;name&quot;:&quot;นางสาวชนิกานต์  สวัสดิวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1410100117524&quot;,&quot;name&quot;:&quot;นางสาวประภาพรรณ กุลแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900007736&quot;,&quot;name&quot;:&quot;นางปวีณา  บำรุงภักดิ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500147966&quot;,&quot;name&quot;:&quot;นางสาวธนวรรณ พิทักษ์คง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820600006469&quot;,&quot;name&quot;:&quot;นางสาวปิยธิดา นิยมเดชา&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820500130320&quot;,&quot;name&quot;:&quot;นางสาวลภัสภาส์ หนูคง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100025495&quot;,&quot;name&quot;:&quot;นางวาสนา  สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900174284&quot;,&quot;name&quot;:&quot;นางสาวนิรชา ธรรมัสโร&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809901015490&quot;,&quot;name&quot;:&quot;นางสาวสรัลรัตน์ จันทับ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3180600191510&quot;,&quot;name&quot;:&quot;นายเพลิน โอรักษ์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1809900094507&quot;,&quot;name&quot;:&quot;ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;3820100028745&quot;,&quot;name&quot;:&quot;นายวรานนท์ ภาระพฤติ&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;},{&quot;pID&quot;:&quot;1829900093446&quot;,&quot;name&quot;:&quot;นายศุภสวัสดิ์ กาญวิจิต&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม&quot;}]"
                                                            value="department-4">
                                                        <span class="item-title">กลุ่มสาระฯ สังคมศึกษา
                                                            ศาสนาและวัฒนธรรม</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 18 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวนัสรีน สุวิสัน"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1830101156953">
                                                            <span class="member-name">นางสาวนัสรีน สุวิสัน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวปณิดา คลองรั้ว"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1810300103434">
                                                            <span class="member-name">นางสาวปณิดา คลองรั้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นายมงคล ตันเจริญรัตน์"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1820501214179">
                                                            <span class="member-name">นายมงคล ตันเจริญรัตน์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางจารุวรรณ ส่องศิริ"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="3820100025592">
                                                            <span class="member-name">นางจารุวรรณ ส่องศิริ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวชนิกานต์  สวัสดิวงค์"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="3829900033725">
                                                            <span class="member-name">นางสาวชนิกานต์ สวัสดิวงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวประภาพรรณ กุลแก้ว"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1410100117524">
                                                            <span class="member-name">นางสาวประภาพรรณ กุลแก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางปวีณา  บำรุงภักดิ์"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1829900007736">
                                                            <span class="member-name">นางปวีณา บำรุงภักดิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวธนวรรณ พิทักษ์คง"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1820500147966">
                                                            <span class="member-name">นางสาวธนวรรณ พิทักษ์คง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวปิยธิดา นิยมเดชา"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1820600006469">
                                                            <span class="member-name">นางสาวปิยธิดา นิยมเดชา</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวลภัสภาส์ หนูคง"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1820500130320">
                                                            <span class="member-name">นางสาวลภัสภาส์ หนูคง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางวาสนา  สุทธจิตร์"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="3820100025495">
                                                            <span class="member-name">นางวาสนา สุทธจิตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวนิรชา ธรรมัสโร"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1829900174284">
                                                            <span class="member-name">นางสาวนิรชา ธรรมัสโร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวสรัลรัตน์ จันทับ"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1809901015490">
                                                            <span class="member-name">นางสาวสรัลรัตน์ จันทับ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นางสาวสุดาทิพย์ ยกย่อง"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1820800031408">
                                                            <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นายเพลิน โอรักษ์"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="3180600191510">
                                                            <span class="member-name">นายเพลิน โอรักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="ว่าที่ร้อยตรีเรืองเดชย์  ผสารพจน์"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1809900094507">
                                                            <span class="member-name">ว่าที่ร้อยตรีเรืองเดชย์
                                                                ผสารพจน์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นายวรานนท์ ภาระพฤติ"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="3820100028745">
                                                            <span class="member-name">นายวรานนท์ ภาระพฤติ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-4"
                                                                data-member-name="นายศุภสวัสดิ์ กาญวิจิต"
                                                                data-group-label="กลุ่มสาระฯ สังคมศึกษา ศาสนาและวัฒนธรรม"
                                                                name="person_ids[]" value="1829900093446">
                                                            <span class="member-name">นายศุภสวัสดิ์ กาญวิจิต</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox"
                                                            class="item-checkbox group-item-checkbox department-item-checkbox"
                                                            data-group="department" data-group-key="department-5"
                                                            data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                            data-members="[{&quot;pID&quot;:&quot;1920400002230&quot;,&quot;name&quot;:&quot;นายประสิทธิ์  สะไน&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800037747&quot;,&quot;name&quot;:&quot;นายศรายุทธ  มิตรวงค์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820800038999&quot;,&quot;name&quot;:&quot;นางสาวนิรัตน์ เพชรแก้ว&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;3820400261097&quot;,&quot;name&quot;:&quot;นายจรุง  บำรุงเขตต์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;},{&quot;pID&quot;:&quot;1829900072562&quot;,&quot;name&quot;:&quot;นายเอกพงษ์ สงวนทรัพย์&quot;,&quot;faction&quot;:&quot;กลุ่มสาระฯ สุขศึกษาและพลศึกษา&quot;}]"
                                                            value="department-5">
                                                        <span class="item-title">กลุ่มสาระฯ สุขศึกษาและพลศึกษา</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 6 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-5"
                                                                data-member-name="นายประสิทธิ์  สะไน"
                                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                                name="person_ids[]" value="1920400002230">
                                                            <span class="member-name">นายประสิทธิ์ สะไน</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-5"
                                                                data-member-name="นายศรายุทธ  มิตรวงค์"
                                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                                name="person_ids[]" value="3820800037747">
                                                            <span class="member-name">นายศรายุทธ มิตรวงค์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-5"
                                                                data-member-name="นายสมชาย สุทธจิตร์"
                                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                                name="person_ids[]" value="3820700143669">
                                                            <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-5"
                                                                data-member-name="นางสาวนิรัตน์ เพชรแก้ว"
                                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                                name="person_ids[]" value="3820800038999">
                                                            <span class="member-name">นางสาวนิรัตน์ เพชรแก้ว</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-5"
                                                                data-member-name="นายจรุง  บำรุงเขตต์"
                                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                                name="person_ids[]" value="3820400261097">
                                                            <span class="member-name">นายจรุง บำรุงเขตต์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="department-5"
                                                                data-member-name="นายเอกพงษ์ สงวนทรัพย์"
                                                                data-group-label="กลุ่มสาระฯ สุขศึกษาและพลศึกษา"
                                                                name="person_ids[]" value="1829900072562">
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
                                                        <input type="checkbox" class="item-checkbox group-item-checkbox"
                                                            data-group="special" data-group-key="special-executive"
                                                            data-group-label="คณะผู้บริหารสถานศึกษา"
                                                            data-members="[{&quot;pID&quot;:&quot;1820500005169&quot;,&quot;name&quot;:&quot;นางสาวศริญญา  ผั้วผดุง&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3810500334835&quot;,&quot;name&quot;:&quot;นายดลยวัฒน์ สันติพิทักษ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;1820500004103&quot;,&quot;name&quot;:&quot;นายยุทธนา สุวรรณวิสุทธิ์&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;},{&quot;pID&quot;:&quot;3430200354125&quot;,&quot;name&quot;:&quot;นายไกรวิชญ์ อ่อนแก้ว&quot;,&quot;faction&quot;:&quot;คณะผู้บริหารสถานศึกษา&quot;}]"
                                                            value="special-executive">
                                                        <span class="item-title">คณะผู้บริหารสถานศึกษา</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 4 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-executive"
                                                                data-member-name="นางสาวศริญญา  ผั้วผดุง"
                                                                data-group-label="คณะผู้บริหารสถานศึกษา"
                                                                name="person_ids[]" value="1820500005169">
                                                            <span class="member-name">นางสาวศริญญา ผั้วผดุง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-executive"
                                                                data-member-name="นายดลยวัฒน์ สันติพิทักษ์"
                                                                data-group-label="คณะผู้บริหารสถานศึกษา"
                                                                name="person_ids[]" value="3810500334835">
                                                            <span class="member-name">นายดลยวัฒน์ สันติพิทักษ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-executive"
                                                                data-member-name="นายยุทธนา สุวรรณวิสุทธิ์"
                                                                data-group-label="คณะผู้บริหารสถานศึกษา"
                                                                name="person_ids[]" value="1820500004103">
                                                            <span class="member-name">นายยุทธนา สุวรรณวิสุทธิ์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-executive"
                                                                data-member-name="นายไกรวิชญ์ อ่อนแก้ว"
                                                                data-group-label="คณะผู้บริหารสถานศึกษา"
                                                                name="person_ids[]" value="3430200354125">
                                                            <span class="member-name">นายไกรวิชญ์ อ่อนแก้ว</span>
                                                        </label>
                                                    </li>
                                                </ol>
                                            </div>
                                            <div class="item item-group is-collapsed">
                                                <div class="group-header">
                                                    <label class="item-main">
                                                        <input type="checkbox" class="item-checkbox group-item-checkbox"
                                                            data-group="special" data-group-key="special-subject-head"
                                                            data-group-label="หัวหน้ากลุ่มสาระ"
                                                            data-members="[{&quot;pID&quot;:&quot;5800900028151&quot;,&quot;name&quot;:&quot;นางจริยาวดี  เวชจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3840100521778&quot;,&quot;name&quot;:&quot;นางดวงกมล  เพ็ชรพรหม&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3940400027034&quot;,&quot;name&quot;:&quot;นางพนิดา ค้าของ&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820700006258&quot;,&quot;name&quot;:&quot;นางสาวนุชรีย์ หัศนี&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1820800031408&quot;,&quot;name&quot;:&quot;นางสาวสุดาทิพย์ ยกย่อง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700019381&quot;,&quot;name&quot;:&quot;นางเสาวลีย์ จันทร์ทอง&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1959900030702&quot;,&quot;name&quot;:&quot;นายธันวิน  ณ นคร&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;1839900094990&quot;,&quot;name&quot;:&quot;นายนพรัตน์ ย้อยพระจันทร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820700143669&quot;,&quot;name&quot;:&quot;นายสมชาย สุทธจิตร์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;},{&quot;pID&quot;:&quot;3820400194578&quot;,&quot;name&quot;:&quot;นายสุพัฒน์  เจริญฤทธิ์&quot;,&quot;faction&quot;:&quot;หัวหน้ากลุ่มสาระ&quot;}]"
                                                            value="special-subject-head">
                                                        <span class="item-title">หัวหน้ากลุ่มสาระ</span>
                                                        <small class="item-subtext">สมาชิกทั้งหมด 10 คน</small>
                                                    </label>
                                                    <button type="button" class="group-toggle" aria-expanded="false"
                                                        title="แสดง/ซ่อนรายชื่อสมาชิก">
                                                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                                    </button>
                                                </div>

                                                <ol class="member-sublist">
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นางจริยาวดี  เวชจันทร์"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="5800900028151">
                                                            <span class="member-name">นางจริยาวดี เวชจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นางดวงกมล  เพ็ชรพรหม"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="3840100521778">
                                                            <span class="member-name">นางดวงกมล เพ็ชรพรหม</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นางพนิดา ค้าของ"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="3940400027034">
                                                            <span class="member-name">นางพนิดา ค้าของ</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นางสาวนุชรีย์ หัศนี"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="1820700006258">
                                                            <span class="member-name">นางสาวนุชรีย์ หัศนี</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นางสาวสุดาทิพย์ ยกย่อง"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="1820800031408">
                                                            <span class="member-name">นางสาวสุดาทิพย์ ยกย่อง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นางเสาวลีย์ จันทร์ทอง"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="3820700019381">
                                                            <span class="member-name">นางเสาวลีย์ จันทร์ทอง</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นายธันวิน  ณ นคร"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="1959900030702">
                                                            <span class="member-name">นายธันวิน ณ นคร</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นายนพรัตน์ ย้อยพระจันทร์"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="1839900094990">
                                                            <span class="member-name">นายนพรัตน์ ย้อยพระจันทร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นายสมชาย สุทธจิตร์"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="3820700143669">
                                                            <span class="member-name">นายสมชาย สุทธจิตร์</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="item member-item">
                                                            <input type="checkbox" class="member-checkbox"
                                                                data-member-group-key="special-subject-head"
                                                                data-member-name="นายสุพัฒน์  เจริญฤทธิ์"
                                                                data-group-label="หัวหน้ากลุ่มสาระ" name="person_ids[]"
                                                                value="3820400194578">
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
                            <button class="js-btn-show-recipients" type="button">
                                <p>แสดงผู้รับทั้งหมด</p>
                            </button>
                        </div>
                    </div>

                    <div class="modal-overlay-recipient js-recipient-modal">
                        <div class="modal-container">
                            <div class="modal-header">
                                <div class="modal-title">
                                    <i class="fa-solid fa-users"></i>
                                    <span>รายชื่อผู้รับหนังสือเวียน</span>
                                </div>
                                <button class="modal-close js-close-modal-btn" type="button">
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
                                    <tbody class="js-recipient-table-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>

            </div>


            <div class="footer-modal">
                <button type="submit" id="modalOrderEditSaveBtn" form="modalOutgoingAttachForm"
                    data-confirm="ยืนยันการบันทึกไฟล์เอกสารออกเลขทะเบียนส่งใช่หรือไม่?"
                    data-confirm-title="ยืนยันการบันทึก" data-confirm-ok="ยืนยัน" data-confirm-cancel="ยกเลิก">
                    <p>บันทึก</p>
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
                    <p id="modalOutgoingViewTitle">ดูรายละเอียดออกเลขทะเบียนส่ง</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="modalOrderViewCloseBtn"></i>
                </div>
            </div>

            <div class="content-modal">
                <div class="type-urgent">
                    <p>ประเภท</p>
                    <div class="radio-group-urgent">
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="normal" checked
                            id="modalOutgoingViewUrgentNormal"><label for="modalOutgoingViewUrgentNormal">ปกติ</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="urgent" disabled
                            id="modalOutgoingViewUrgentUrgent"><label for="modalOutgoingViewUrgentUrgent">ด่วน</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="high" disabled
                            id="modalOutgoingViewUrgentHigh"><label for="modalOutgoingViewUrgentHigh">ด่วนมาก</label>
                        <input type="radio" name="outgoingViewUrgent" data-outgoing-view-urgent="highest" disabled
                            id="modalOutgoingViewUrgentHighest"><label
                            for="modalOutgoingViewUrgentHighest">ด่วนที่สุด</label>
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>เลขทะเบียน</strong></p>
                        <input type="text" id="modalOutgoingViewNo" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>เรื่อง</strong></p>
                        <input type="text" id="modalOutgoingViewSubject" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ลงวันที่</strong></p>
                        <input type="date" id="modalOutgoingViewEffectiveDate" class="order-no-display" value=""
                            disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>ส่งถึง</strong></p>
                        <input type="text" id="modalOutgoingViewIssuer" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>ผู้ออกเลข</strong></p>
                        <input type="text" id="modalOutgoingViewIssuerName" class="order-no-display" value="" disabled>
                    </div>
                    <div class="more-details" hidden style="display: none;" aria-hidden="true">
                        <p><strong>เจ้าของเรื่อง</strong></p>
                        <input type="text" id="modalOutgoingViewOwnerNames" class="order-no-display" value="" disabled>
                    </div>
                </div>

                <div class="file-section" id="sectionViewCover">
                    <p><strong>ไฟล์หนังสือนำ</strong></p>
                    <div class="file-list" id="containerViewCover" aria-live="polite"></div>
                </div>

                <div class="file-section" id="sectionViewAttachments">
                    <p><strong>ไฟล์เอกสารเพิ่มเติม</strong></p>
                    <div class="file-list" id="containerViewAttachments" aria-live="polite"></div>
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

<script>
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

    document.addEventListener('DOMContentLoaded', () => {
        const editModal = document.getElementById('modalOrderEditOverlay');
        const viewModal = document.getElementById('modalOrderViewOverlay');
        const modalOutgoingEditTitle = document.getElementById('modalOutgoingEditTitle');
        const modalOutgoingEditOutgoingId = document.getElementById('modalOutgoingEditOutgoingId');
        const modalOutgoingEditNo = document.getElementById('modalOutgoingEditNo');
        const modalOutgoingEditSubject = document.getElementById('modalOutgoingEditSubject');
        const modalOutgoingEditEffectiveDate = document.getElementById('modalOutgoingEditEffectiveDate');
        const modalOutgoingEditIssuer = document.getElementById('modalOutgoingEditIssuer');
        const modalOutgoingEditFileList = document.getElementById('existingFileListContainer_modal');
        const modalOutgoingEditUrgentRadios = editModal ? Array.from(editModal.querySelectorAll('[data-outgoing-edit-urgent]')) : [];
        const modalOutgoingViewTitle = document.getElementById('modalOutgoingViewTitle');
        const modalOutgoingViewNo = document.getElementById('modalOutgoingViewNo');
        const modalOutgoingViewSubject = document.getElementById('modalOutgoingViewSubject');
        const modalOutgoingViewEffectiveDate = document.getElementById('modalOutgoingViewEffectiveDate');
        const modalOutgoingViewIssuer = document.getElementById('modalOutgoingViewIssuer');
        const modalOutgoingViewIssuerName = document.getElementById('modalOutgoingViewIssuerName');
        const modalOutgoingViewOwnerNames = document.getElementById('modalOutgoingViewOwnerNames');
        const modalOutgoingViewCoverSection = document.getElementById('sectionViewCover');
        const modalOutgoingViewCoverList = document.getElementById('containerViewCover');
        const modalOutgoingViewAttachmentSection = document.getElementById('sectionViewAttachments');
        const modalOutgoingViewAttachmentList = document.getElementById('containerViewAttachments');
        const modalOutgoingViewOwnerBody = document.getElementById('modalOutgoingViewOwnerBody');
        const modalOutgoingViewUrgentRadios = viewModal ? Array.from(viewModal.querySelectorAll('[data-outgoing-view-urgent]')) : [];
        let outgoingEditModalData = {};
        let outgoingViewModalData = {};

        const syncOutgoingEditModalData = () => {
            const mapElement = document.querySelector('#outgoingMine .js-order-send-map');
            if (!mapElement) {
                outgoingEditModalData = {};
                return;
            }

            try {
                const parsed = JSON.parse(mapElement.textContent || '{}');
                outgoingEditModalData = parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                console.error('Invalid outgoing edit modal data', error);
                outgoingEditModalData = {};
            }
        };

        const syncOutgoingViewModalData = () => {
            const mapElement = document.querySelector('#outgoingMine .js-order-send-map');
            if (!mapElement) {
                outgoingViewModalData = {};
                return;
            }

            try {
                const parsed = JSON.parse(mapElement.textContent || '{}');
                outgoingViewModalData = parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                console.error('Invalid outgoing view modal data', error);
                outgoingViewModalData = {};
            }
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const normalizeOutgoingPriorityKey = (value) => {
            const normalized = String(value || '').trim().toLowerCase();
            if (['normal', 'urgent', 'high', 'highest'].includes(normalized)) {
                return normalized;
            }
            return 'normal';
        };

        const syncOutgoingPriorityVisualState = (radios) => {
            const palette = {
                normal: {
                    borderColor: '#00ae2c',
                    activeColor: '#00ae2c',
                },
                urgent: {
                    borderColor: '#9a00af',
                    activeColor: '#9a00af',
                },
                high: {
                    borderColor: '#ce6203',
                    activeColor: '#ce6203',
                },
                highest: {
                    borderColor: '#bd0000',
                    activeColor: '#bd0000',
                },
            };

            (Array.isArray(radios) ? radios : []).forEach((radio) => {
                if (!(radio instanceof HTMLInputElement)) {
                    return;
                }

                const radioKey = normalizeOutgoingPriorityKey(radio.getAttribute('data-outgoing-edit-urgent') || radio.getAttribute('data-outgoing-view-urgent') || '');
                const colors = palette[radioKey] || palette.normal;
                const label = radio.nextElementSibling instanceof HTMLLabelElement ? radio.nextElementSibling : null;

                radio.classList.toggle('is-active', radio.checked);
                radio.style.backgroundColor = radio.checked ? colors.activeColor : 'transparent';
                radio.style.borderColor = colors.borderColor;
                radio.style.boxShadow = radio.checked ?
                    `inset 0 0 0 1px white, inset 0 0 0 3px ${colors.activeColor}` :
                    'none';

                if (label) {
                    label.classList.toggle('is-active', radio.checked);
                }
            });
        };

        const setOutgoingPriorityRadio = (radios, priorityKey) => {
            const normalizedKey = normalizeOutgoingPriorityKey(priorityKey);
            const radioList = Array.isArray(radios) ? radios.filter((radio) => radio instanceof HTMLInputElement) : [];
            let matchedRadio = null;

            radioList.forEach((radio) => {
                if (!(radio instanceof HTMLInputElement)) {
                    return;
                }

                const radioKey = normalizeOutgoingPriorityKey(radio.getAttribute('data-outgoing-edit-urgent') || radio.getAttribute('data-outgoing-view-urgent') || '');
                radio.checked = false;
                radio.defaultChecked = false;
                radio.removeAttribute('checked');

                if (radioKey === normalizedKey && matchedRadio === null) {
                    matchedRadio = radio;
                }
            });

            if (!(matchedRadio instanceof HTMLInputElement)) {
                matchedRadio = radioList[0] ?? null;
            }

            if (matchedRadio instanceof HTMLInputElement) {
                matchedRadio.checked = true;
                matchedRadio.defaultChecked = true;
                matchedRadio.setAttribute('checked', 'checked');
            }

            syncOutgoingPriorityVisualState(radioList);
        };

        const scheduleOutgoingPrioritySync = (radios, priorityKey) => {
            setOutgoingPriorityRadio(radios, priorityKey);

            if (typeof window.requestAnimationFrame === 'function') {
                window.requestAnimationFrame(() => setOutgoingPriorityRadio(radios, priorityKey));
                return;
            }

            window.setTimeout(() => setOutgoingPriorityRadio(radios, priorityKey), 0);
        };

        const renderOutgoingEditFiles = (outgoingId, files) => {
            if (!modalOutgoingEditFileList) {
                return;
            }

            const safeOutgoingId = encodeURIComponent(String(outgoingId || '').trim());
            const html = files.map((file) => {
                const fileId = encodeURIComponent(String(file?.fileID || ''));
                const fileName = escapeHtml(String(file?.fileName || '-'));
                const mimeType = escapeHtml(String(file?.mimeType || 'ไฟล์แนบ'));
                const viewHref = `public/api/file-download.php?module=outgoing&entity_id=${safeOutgoingId}&file_id=${fileId}`;

                const isImage = String(file?.mimeType || '').toLowerCase().startsWith('image/');

                const iconHtml = !isImage ?
                    '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                    '<i class="fa-solid fa-file-image" aria-hidden="true"></i>';

                return `<div class="file-item-wrapper">
                    <div class="file-banner">
                        <div class="file-info">
                            <div class="file-icon">${iconHtml}</div>
                            <div class="file-text">
                                <span class="file-name">${fileName}</span>
                                <span class="file-type">${mimeType}</span>
                            </div>
                        </div>
                        <div class="file-actions">
                            <a href="${viewHref}" 
                               class="action-btn ${isImage ? 'js-preview-server-image' : ''}" 
                               ${isImage ? `data-filename="${fileName}"` : 'target="_blank" rel="noopener"'} 
                               title="ดูตัวอย่าง">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </div>`;
            }).join('');

            modalOutgoingEditFileList.innerHTML = html;
        };

        const resetOutgoingEditModal = () => {
            if (modalOutgoingEditTitle) {
                modalOutgoingEditTitle.textContent = 'แนบไฟล์เอกสารออกเลขทะเบียนส่ง';
            }
            if (modalOutgoingEditOutgoingId) {
                modalOutgoingEditOutgoingId.value = '';
            }
            if (modalOutgoingEditNo) {
                modalOutgoingEditNo.value = '-';
            }
            if (modalOutgoingEditSubject) {
                modalOutgoingEditSubject.value = '-';
            }
            if (modalOutgoingEditEffectiveDate) {
                modalOutgoingEditEffectiveDate.value = '';
            }
            if (modalOutgoingEditIssuer) {
                modalOutgoingEditIssuer.value = '';
            }

            setOutgoingPriorityRadio(modalOutgoingEditUrgentRadios, 'normal');

            if (window.__outgoingModalCoverUpload && typeof window.__outgoingModalCoverUpload.reset === 'function') {
                window.__outgoingModalCoverUpload.reset();
            }

            if (window.__outgoingModalAttachmentUpload && typeof window.__outgoingModalAttachmentUpload.reset === 'function') {
                window.__outgoingModalAttachmentUpload.reset();
            } else {
                renderOutgoingEditFiles('', []);
            }

        };

        const resolveOutgoingPriorityKey = (payload, fallbackPriorityKey = 'normal') => {
            if (payload && typeof payload === 'object' && typeof payload.priorityKey === 'string' && payload.priorityKey.trim() !== '') {
                return normalizeOutgoingPriorityKey(payload.priorityKey);
            }

            return normalizeOutgoingPriorityKey(fallbackPriorityKey);
        };

        const openOutgoingEditModal = (outgoingIdRaw, fallbackPriorityKey = 'normal') => {
            if (!editModal) {
                return;
            }

            syncOutgoingEditModalData();

            const outgoingId = String(outgoingIdRaw || '').trim();
            const payload = outgoingEditModalData[outgoingId];

            resetOutgoingEditModal();

            if (payload && typeof payload === 'object') {
                if (modalOutgoingEditOutgoingId) {
                    modalOutgoingEditOutgoingId.value = outgoingId;
                }
                if (modalOutgoingEditNo) {
                    modalOutgoingEditNo.value = String(payload.outgoingNo || '').trim() || '-';
                }
                if (modalOutgoingEditSubject) {
                    modalOutgoingEditSubject.value = String(payload.subject || '').trim() || '-';
                }
                if (modalOutgoingEditEffectiveDate) {
                    const effectiveDate = String(payload.effectiveDate || '').trim();
                    modalOutgoingEditEffectiveDate.value = /^\d{4}-\d{2}-\d{2}$/.test(effectiveDate) ? effectiveDate : '';
                }
                if (modalOutgoingEditIssuer) {
                    modalOutgoingEditIssuer.value = String(payload.destinationName || '').trim();
                }

                renderOutgoingEditFiles(outgoingId, Array.isArray(payload.attachments) ? payload.attachments : []);

            }

            editModal.style.display = 'flex';
            scheduleOutgoingPrioritySync(modalOutgoingEditUrgentRadios, resolveOutgoingPriorityKey(payload, fallbackPriorityKey));
        };

        const renderOutgoingViewFileList = (section, container, outgoingId, files, emptyText) => {
            if (!section || !container) {
                return;
            }

            if (!Array.isArray(files) || files.length === 0) {
                section.style.display = '';
                container.innerHTML = `<p class="existing-file-empty">${escapeHtml(emptyText)}</p>`;
                return;
            }

            section.style.display = '';
            const safeOutgoingId = encodeURIComponent(String(outgoingId || '').trim());
            const fileRowsHtml = files.map((file) => {
                const fileId = encodeURIComponent(String(file?.fileID || ''));
                const fileName = escapeHtml(String(file?.fileName || '-'));
                const mimeType = escapeHtml(String(file?.mimeType || 'ไฟล์แนบ'));
                const viewHref = `public/api/file-download.php?module=outgoing&entity_id=${safeOutgoingId}&file_id=${fileId}`;
                const iconHtml = String(file?.mimeType || '').toLowerCase() === 'application/pdf' ?
                    '<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>' :
                    '<i class="fa-solid fa-file-image" aria-hidden="true"></i>';

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
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>`;
            }).join('');

            container.innerHTML = fileRowsHtml;
        };

        const renderOutgoingViewFiles = (outgoingId, coverFiles, attachmentFiles) => {
            renderOutgoingViewFileList(
                modalOutgoingViewCoverSection,
                modalOutgoingViewCoverList,
                outgoingId,
                Array.isArray(coverFiles) ? coverFiles : [],
                'ไม่พบไฟล์หนังสือนำ'
            );
            renderOutgoingViewFileList(
                modalOutgoingViewAttachmentSection,
                modalOutgoingViewAttachmentList,
                outgoingId,
                Array.isArray(attachmentFiles) ? attachmentFiles : [],
                'ไม่พบไฟล์เอกสารเพิ่มเติม'
            );
        };

        const renderOutgoingViewOwners = (ownerNames, statusLabel, statusPill) => {
            if (!modalOutgoingViewOwnerBody) {
                return;
            }

            const owners = Array.from(new Set((Array.isArray(ownerNames) ? ownerNames : [])
                .map((name) => String(name || '').trim())
                .filter((name) => name !== '')));

            if (owners.length === 0) {
                modalOutgoingViewOwnerBody.innerHTML = '<tr><td colspan="3" class="orders-send-track-empty">ไม่พบข้อมูลเจ้าของเรื่อง</td></tr>';
                return;
            }

            const safeStatusLabel = escapeHtml(String(statusLabel || '-'));
            const safeStatusPill = escapeHtml(String(statusPill || 'pending'));
            modalOutgoingViewOwnerBody.innerHTML = owners.map((name) => `<tr>
                <td>${escapeHtml(name)}</td>
                <td><span class="status-pill ${safeStatusPill}">${safeStatusLabel}</span></td>
                <td>เจ้าของเรื่อง</td>
            </tr>`).join('');
        };

        const resetOutgoingViewModal = () => {
            if (modalOutgoingViewTitle) {
                modalOutgoingViewTitle.textContent = 'ดูรายละเอียดออกเลขทะเบียนส่ง';
            }
            if (modalOutgoingViewNo) {
                modalOutgoingViewNo.value = '-';
            }
            if (modalOutgoingViewSubject) {
                modalOutgoingViewSubject.value = '-';
            }
            if (modalOutgoingViewEffectiveDate) {
                modalOutgoingViewEffectiveDate.value = '';
            }
            if (modalOutgoingViewIssuer) {
                modalOutgoingViewIssuer.value = '-';
            }
            if (modalOutgoingViewIssuerName) {
                modalOutgoingViewIssuerName.value = '-';
            }
            if (modalOutgoingViewOwnerNames) {
                modalOutgoingViewOwnerNames.value = '-';
            }

            setOutgoingPriorityRadio(modalOutgoingViewUrgentRadios, 'normal');

            renderOutgoingViewFiles('', [], []);
            renderOutgoingViewOwners([], '', 'pending');
        };

        const openOutgoingViewModal = (outgoingIdRaw, fallbackPriorityKey = 'normal') => {
            if (!viewModal) {
                return;
            }

            syncOutgoingViewModalData();

            const outgoingId = String(outgoingIdRaw || '').trim();
            const payload = outgoingViewModalData[outgoingId];

            resetOutgoingViewModal();

            if (payload && typeof payload === 'object') {
                if (modalOutgoingViewNo) {
                    modalOutgoingViewNo.value = String(payload.outgoingNo || '').trim() || '-';
                }
                if (modalOutgoingViewSubject) {
                    modalOutgoingViewSubject.value = String(payload.subject || '').trim() || '-';
                }
                if (modalOutgoingViewEffectiveDate) {
                    const effectiveDate = String(payload.effectiveDate || '').trim();
                    modalOutgoingViewEffectiveDate.value = /^\d{4}-\d{2}-\d{2}$/.test(effectiveDate) ? effectiveDate : '';
                }
                if (modalOutgoingViewIssuer) {
                    modalOutgoingViewIssuer.value = String(payload.destinationName || '').trim() || '-';
                }
                if (modalOutgoingViewIssuerName) {
                    modalOutgoingViewIssuerName.value = String(payload.issuerName || '').trim() || '-';
                }
                if (modalOutgoingViewOwnerNames) {
                    const ownerNames = Array.isArray(payload.ownerNames) ? payload.ownerNames : [];
                    modalOutgoingViewOwnerNames.value = ownerNames.map((name) => String(name || '').trim()).filter(Boolean).join(', ') || '-';
                }

                renderOutgoingViewFiles(outgoingId, payload.coverFiles, payload.attachmentFiles);
                renderOutgoingViewOwners(payload.ownerNames, payload.statusLabel, payload.statusPill);
            }

            viewModal.style.display = 'flex';
            scheduleOutgoingPrioritySync(modalOutgoingViewUrgentRadios, resolveOutgoingPriorityKey(payload, fallbackPriorityKey));
        };

        modalOutgoingEditUrgentRadios.forEach((radio) => {
            radio.addEventListener('change', () => syncOutgoingPriorityVisualState(modalOutgoingEditUrgentRadios));
        });

        modalOutgoingViewUrgentRadios.forEach((radio) => {
            radio.addEventListener('change', () => syncOutgoingPriorityVisualState(modalOutgoingViewUrgentRadios));
        });

        document.addEventListener('click', (event) => {
            const targetBtn = event.target.closest('button');
            if (!targetBtn) return;

            if (targetBtn.classList.contains('js-open-order-edit-modal')) {
                openOutgoingEditModal(
                    targetBtn.getAttribute('data-outgoing-id'),
                    targetBtn.getAttribute('data-outgoing-priority-key') || 'normal'
                );
            }

            if (targetBtn.classList.contains('js-open-order-view-modal')) {
                openOutgoingViewModal(
                    targetBtn.getAttribute('data-outgoing-id'),
                    targetBtn.getAttribute('data-outgoing-priority-key') || 'normal'
                );
            }
        });

        const closeActions = [{
                btn: editModal?.querySelector('#closeModalOrderSend') ?? null,
                modal: editModal,
            },
            {
                btn: viewModal?.querySelector('#modalOrderViewCloseBtn') ?? null,
                modal: viewModal,
            }
        ];

        closeActions.forEach(action => {
            if (action.btn && action.modal) {
                action.btn.addEventListener('click', () => {
                    action.modal.style.display = 'none';
                });
            }
        });

        window.addEventListener('click', (event) => {
            if (event.target === editModal) {
                editModal.style.display = 'none';
            }
            if (event.target === viewModal) {
                viewModal.style.display = 'none';
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('outgointForm');
        if (!form) return;
        const initialSelectedPersonIds = new Set(<?= $selected_person_ids_json ?: '[]' ?>);
        const ownerSection = form.querySelector('[data-recipients-section][data-owner-flat-list="true"]');

        limitOutgoingOwnerDepartmentOptions(ownerSection);

        const dropdown = document.getElementById('dropdownContent');
        const toggle = document.getElementById('recipientToggle');
        const searchInput = document.getElementById('mainInput');
        const selectAll = document.getElementById('selectAll');

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
        const recipientSearchEndpoint = 'public/api/circular-recipient-search.php'; // URL สำหรับ API ค้นหา

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


        const recipientModal = document.getElementById('recipientModal');
        const recipientTableBody = document.getElementById('recipientTableBody');
        const btnShowRecipients = document.getElementById('btnShowRecipients');
        const closeModalBtn = document.getElementById('closeModalBtn');

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

        const recipientSections = document.querySelectorAll('[data-recipients-section]');

        recipientSections.forEach(section => {
            limitOutgoingOwnerDepartmentOptions(section);

            const dropdown = section.querySelector('.js-dropdown-content');
            const toggle = section.querySelector('.js-recipient-toggle');
            const searchInput = section.querySelector('.js-main-input');
            const selectAll = section.querySelector('.js-select-all');

            if (!dropdown || !toggle || !searchInput || !selectAll) {
                return;
            }

            const btnShowRecipients = section.nextElementSibling?.querySelector('.js-btn-show-recipients') ||
                document.querySelector('.js-btn-show-recipients'); // หาปุ่มโชว์ผู้รับที่เกี่ยวข้องกัน

            const groupChecks = Array.from(section.querySelectorAll('.group-item-checkbox'));
            const memberChecks = Array.from(section.querySelectorAll('.member-checkbox'));
            const groupItems = Array.from(section.querySelectorAll('.dropdown-list .item-group'));
            const categoryGroups = Array.from(section.querySelectorAll('.dropdown-list .category-group'));

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
                const allChecks = [...groupChecks, ...memberChecks].filter(el => !el.disabled);
                if (allChecks.length === 0) return;

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
                    if (!member.disabled) {
                        member.checked = true;
                        syncMemberByPid(member.value || '', true, member);
                    }
                });
            });
            updateSelectAllState();

            const wrapper = section.closest('form') || section.closest('.modal-content');

            const recipientModal = wrapper.querySelector('.js-recipient-modal');
            const recipientTableBody = wrapper.querySelector('.js-recipient-table-body');
            const closeRecipientModalBtn = wrapper.querySelector('.js-close-modal-btn');

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

            const normalizeMemberName = (value) => String(value || '').trim().replace(/\s+/g, ' ');

            const setSelectedMembersByNames = (names) => {
                const normalizedNames = new Set((Array.isArray(names) ? names : [])
                    .map((name) => normalizeMemberName(name))
                    .filter((name) => name !== ''));

                groupChecks.forEach((item) => {
                    if (!item.disabled) {
                        item.checked = false;
                        item.indeterminate = false;
                    }
                });

                memberChecks.forEach((item) => {
                    if (item.disabled) {
                        return;
                    }

                    const memberName = normalizeMemberName(item.getAttribute('data-member-name'));
                    const isChecked = normalizedNames.has(memberName);
                    item.checked = isChecked;
                    syncMemberByPid(item.value || '', isChecked, item);

                    if (isChecked) {
                        setGroupCollapsed(item.closest('.item-group'), false);
                    }
                });

                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }

                updateSelectAllState();
            };

            section.__recipientSelectorApi = {
                setSelectedMembersByNames,
                renderRecipients,
            };

            btnShowRecipients?.addEventListener('click', () => {
                renderRecipients();
                recipientModal?.classList.add('active');
            });

            closeRecipientModalBtn?.addEventListener('click', () => {
                recipientModal?.classList.remove('active');
            });

            recipientModal?.addEventListener('click', (e) => {
                if (e.target === recipientModal) {
                    recipientModal.classList.remove('active');
                }
            });
        });



    });

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

                    const fileUrl = URL.createObjectURL(file);

                    const view = document.createElement("a");
                    view.href = fileUrl;
                    view.target = "_blank";
                    view.rel = "noopener";
                    view.innerHTML = '<i class="fa-solid fa-eye"></i>';

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
                    alert(message);
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
                reset: resetFiles,
            };
        }

        window.__outgoingModalCoverUpload = setupFileUpload(
            "coverFileInput_modal",
            "coverFileListContainer_modal",
            1, {
                addButtonId: "btnCoverAddFile_modal"
            }
        );

        window.__outgoingModalAttachmentUpload = setupFileUpload(
            "fileInput_modal",
            "existingFileListContainer_modal",
            4, {
                dropzoneId: "dropzone_modal",
                addButtonId: "btnAddFiles_modal"
            }
        );

        const outgoingAttachForm = document.getElementById("modalOutgoingAttachForm");
        const outgoingDestinationInput = document.getElementById("modalOutgoingEditIssuer");
        const outgoingCoverFileInput = document.getElementById("coverFileInput_modal");

        const showOutgoingAttachAlert = (message) => {
            const alertsApi = window.AppAlerts && typeof window.AppAlerts.fire === 'function' ? window.AppAlerts : null;
            if (!alertsApi) {
                alert(message);
                return;
            }

            alertsApi.fire({
                type: 'warning',
                title: 'แจ้งเตือน',
                message,
            });
        };

        if (outgoingAttachForm) {
            outgoingAttachForm.addEventListener("submit", (event) => {
                if (outgoingDestinationInput && outgoingDestinationInput.value.trim() === "") {
                    event.preventDefault();
                    showOutgoingAttachAlert("กรุณากรอกส่งถึง");
                    outgoingDestinationInput.focus();
                    return;
                }

                if (!outgoingCoverFileInput || outgoingCoverFileInput.files.length === 0) {
                    event.preventDefault();
                    showOutgoingAttachAlert("กรุณาแนบไฟล์หนังสือนำ");
                }
            });
        }

    });
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
