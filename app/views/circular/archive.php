<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$items = (array) ($items ?? []);
$box_key = (string) ($box_key ?? 'normal');
$dh_year_options = array_values(array_filter(array_map('intval', (array) ($dh_year_options ?? [])), static function (int $year): bool {
    return $year > 0;
}));
$selected_dh_year = (int) ($selected_dh_year ?? 0);
$dh_year_label = $selected_dh_year > 0 ? (string) $selected_dh_year : 'ทั้งหมด';
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

<style>
    .table-circular-notice-index table thead th:nth-child(1),
    .table-circular-notice-index table tbody td:nth-child(1) {
        text-align: center !important;
    }

    .table-circular-notice-index table thead th:nth-child(4),
    .table-circular-notice-index table tbody td:nth-child(4) {
        text-align: start !important;
    }

    .table-circular-notice-index table thead th:nth-child(1) {
        width: 80px !important;
        min-width: 80px !important;
        max-width: 80px !important;
    }

    .table-circular-notice-index table thead th:nth-child(2) {
        width: 500px !important;
        min-width: 500px !important;
        max-width: 500px !important;
    }

    .table-circular-notice-index table thead th:nth-child(3) {
        width: 180px !important;
        min-width: 180px !important;
        max-width: 180px !important;
    }

    .table-circular-notice-index table thead th:nth-child(4) {
        width: 100px !important;
        min-width: 100px !important;
        max-width: 100px !important;
    }

    .table-circular-notice-index table thead th:nth-child(5) {
        width: 140px !important;
        min-width: 140px !important;
        max-width: 140px !important;
    }

    @media screen and (max-width: 1024px) {

        .table-circular-notice-index table thead th:nth-child(1),
        .table-circular-notice-index table tbody td:nth-child(1) {
            text-align: center !important;
        }

        .table-circular-notice-index table thead th:nth-child(4),
        .table-circular-notice-index table tbody td:nth-child(4) {
            text-align: start !important;
        }

        .table-circular-notice-index table thead th:nth-child(1) {
            width: 60px !important;
            min-width: 60px !important;
            max-width: 60px !important;
        }

        .table-circular-notice-index table thead th:nth-child(2) {
            width: 450px !important;
            min-width: 450px !important;
            max-width: 450px !important;
        }

        .table-circular-notice-index table thead th:nth-child(3) {
            width: 160px !important;
            min-width: 160px !important;
            max-width: 160px !important;
        }

        .table-circular-notice-index table thead th:nth-child(4) {
            width: 140px !important;
            min-width: 140px !important;
            max-width: 140px !important;
        }

        .table-circular-notice-index table thead th:nth-child(5) {
            width: 120px !important;
            min-width: 120px !important;
            max-width: 120px !important;
        }
    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>หนังสือเวียน / หนังสือเวียนที่จัดเก็บ</p>
</div>

<form id="circularFilterForm" method="GET">
    <input type="hidden" name="box" value="<?= h($box_key) ?>">
    <input type="hidden" name="archived" value="1">
    <input type="hidden" name="dh_year" id="filterYearInput" value="<?= h((string) $selected_dh_year) ?>">
    <input type="hidden" name="type" id="filterTypeInput" value="<?= h($filter_type) ?>">
    <input type="hidden" name="read" id="filterReadInput" value="<?= h($filter_read) ?>">
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
                        <div class="custom-option<?= $selected_dh_year <= 0 ? ' selected' : '' ?>" data-value="0">ทั้งหมด</div>
                        <?php foreach ($dh_year_options as $year_option) : ?>
                            <div class="custom-option<?= $selected_dh_year === (int) $year_option ? ' selected' : '' ?>" data-value="<?= h((string) $year_option) ?>"><?= h((string) $year_option) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="page-selector">
                <p>แสดงตามสถานะหนังสือ</p>

                <div class="custom-select-wrapper" data-target="filterReadInput">
                    <div class="custom-select-trigger">
                        <p class="select-value"><?= h($filter_read === 'read' ? 'อ่านแล้ว' : ($filter_read === 'unread' ? 'ยังไม่อ่าน' : 'ทั้งหมด')) ?></p>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="custom-options">
                        <div class="custom-option<?= h($filter_read === 'read' ? ' selected' : '') ?>" data-value="read">อ่านแล้ว</div>
                        <div class="custom-option<?= h($filter_read === 'unread' ? ' selected' : '') ?>" data-value="unread">ยังไม่อ่าน</div>
                        <div class="custom-option<?= h($filter_read === 'all' ? ' selected' : '') ?>" data-value="all">ทั้งหมด</div>
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

    <section class="content-circular-notice-keep" data-circular-notice data-ajax-filter="true" data-ajax-target=".table-circular-notice-keep">
        <div class="search-bar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="search-input" name="q" form="circularFilterForm" value="<?= h($filter_search) ?>" placeholder="ค้นหาข้อความด้วย..." data-auto-submit="true" data-auto-submit-delay="450" autocomplete="off">
            </div>
        </div>

        <div class="table-circular-notice-keep table-circular-notice-index">
            <table>
                <thead>
                    <tr>
                        <th>จัดการ</th>
                        <th>หัวเรื่อง</th>
                        <th>ผู้ส่ง</th>
                        <th>วันที่ส่ง</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)) : ?>
                        <tr>
                            <td colspan="6" class="booking-empty">ไม่มีรายการ</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($items as $item) : ?>
                            <?php $file_json = (string) ($item['files_json'] ?? '[]'); ?>
                            <tr>
                                <td>
                                    <button
                                        class="booking-action-btn secondary js-open-circular-modal"
                                        type="button"
                                        data-circular-id="<?= h((string) (int) ($item['circular_id'] ?? 0)) ?>"
                                        data-inbox-id="<?= h((string) (int) ($item['inbox_id'] ?? 0)) ?>"
                                        data-modal-title="ดูรายละเอียด"
                                        data-subject="<?= h((string) ($item['subject'] ?? '')) ?>"
                                        data-sender="<?= h((string) ($item['sender_name'] ?? '-')) ?>"
                                        data-date="<?= h((string) ($item['delivered_date_long'] ?? $item['delivered_date'] ?? '-')) ?>"
                                        data-detail="<?= h((string) ($item['detail'] ?? '')) ?>"
                                        data-link="<?= h((string) ($item['link_url'] ?? '')) ?>"
                                        data-type="<?= h((string) ($item['type_label'] ?? '')) ?>"
                                        data-files="<?= h($file_json) ?>">
                                        <i class="fa-solid fa-eye"></i>
                                        <span class="tooltip">ดูรายละเอียด</span>
                                    </button>
                                </td>
                                <td><?= h((string) ($item['subject'] ?? '')) ?></td>
                                <td><?= h((string) ($item['sender_name'] ?? '-')) ?></td>
                                <td><?= h((string) ($item['delivered_date'] ?? '-')) ?></td>
                                <td><span class="status-badge <?= h(($item['is_read'] ?? false) ? 'read' : 'unread') ?>"><?= h(($item['is_read'] ?? false) ? 'อ่านแล้ว' : 'ยังไม่อ่าน') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="modal-overlay-circular-notice-keep" id="modalNoticeKeepOverlay">
            <div class="modal-content">
                <div class="header-modal">
                    <p id="modalTypeLabel">ดูรายละเอียด</p>
                    <i class="fa-solid fa-xmark" id="closeModalNoticeKeep"></i>
                </div>

                <div class="content-modal">
                    <div class="content-topic-sec row-format">
                        <p><strong>หัวเรื่อง :</strong></p>
                        <p id="modalSubject">-</p>
                    </div>
                    <div class="content-topic-sec row-format">
                        <p><strong>ผู้ส่ง :</strong></p>
                        <p id="modalSender">-</p>
                    </div>
                    <div class="content-topic-sec row-format">
                        <p><strong>วันที่ส่ง :</strong></p>
                        <p id="modalDate">-</p>
                    </div>

                    <div class="content-details-sec column-format">
                        <p><strong>รายละเอียดเพิ่มเติม</strong></p>
                        <p id="modalDetail">-</p>
                    </div>
                    <div class="content-details-sec column-format">
                        <p><strong>ลิ้งก์แนบจากระบบ</strong></p>
                        <a id="modalLink" href="#" target="_blank" rel="noopener">-</a>
                    </div>

                    <div class="content-file-sec">
                        <p>ไฟล์เอกสารแนบจากระบบ</p>
                        <div class="file-section" id="modalFileSection"></div>
                    </div>
                </div>

                <div class="footer-modal">
                    <form method="POST" id="modalArchiveForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="inbox_id" id="modalInboxId" value="">
                        <input type="hidden" name="action" value="unarchive">
                        <button
                            type="submit"
                            data-confirm="ยืนยันการย้ายกลับรายการนี้หรือไม่"
                            data-confirm-title="ยืนยันการย้ายกลับ"
                            data-confirm-ok="ยืนยัน"
                            data-confirm-cancel="ยกเลิก">
                            <p>ย้ายกลับ</p>
                        </button>
                    </form>
                </div>

            </div>
        </div>
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
                    <input type="text" id="search-input" name="q" form="circularFilterForm" value="<?= h($filter_search) ?>" placeholder="ค้นหาข้อความด้วย..." data-auto-submit="true" data-auto-submit-delay="450" autocomplete="off">
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

            <div class="modal-overlay-circular-notice-archive outside-person" id="modalNoticeKeepOverlay">
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
                        <div class="content-topic-sec">
                            <p><strong>ความเร่งด่วน :</strong></p>
                            <button class="urgency-status normal" id="modalUrgency">
                                <label>ปกติ</label>
                            </button>
                        </div>
                        <div class="content-topic-sec">
                            <div class="more-details row-format">
                                <p><strong>เลขที่หนังสือ :</strong></p>
                                <input type="text" id="modalBookNo" placeholder="-" disabled>
                            </div>
                            <div class="more-details row-format">
                                <p><strong>ลงวันที่ : </strong></p>
                                <input type="text" id="modalIssuedDate" placeholder="-" disabled>
                            </div>
                            <div class="more-details row-format">
                                <p><strong>จาก : </strong></p>
                                <input type="text" id="modalFromText" placeholder="-" disabled>
                            </div>
                            <div class="more-details row-format">
                                <p><strong>ถึง : </strong></p>
                                <input type="text" id="modalToText" placeholder="-" disabled>
                            </div>
                        </div>

                        <div class="content-details-sec row-format">
                            <p><strong>หัวเรื่อง :</strong></p>
                            <p id="modalSubject">-</p>
                        </div>
                        <div class="content-details-sec column-format">
                            <p><strong>รายละเอียดเพิ่มเติม</strong></p>
                            <p id="modalDetail">-</p>
                        </div>

                        <div class="content-file-sec">
                            <p>ไฟล์เอกสารแนบจากระบบ</p>
                            <div class="file-section" id="modalFileSection"></div>
                        </div>

                        <div class="content-time-and-considered-sec">
                            <div class="more-details row-format">
                                <p><strong>รับหนังสือเข้าระบบ : </strong></p>
                                <input type="text" id="modalReceivedTime" placeholder="-" disabled>
                            </div>
                        </div>
                        <div class="content-time-and-considered-sec">
                            <div class="more-details row-format">
                                <p><strong>สถานะ : </strong></p>
                                <input type="text" id="modalStatus" placeholder="-" disabled>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="button-circular-notice-archive outside-person">
        <button
            class="button-keep"
            type="submit"
            form="bulkActionForm"
            data-confirm="ยืนยันการย้ายกลับรายการที่เลือกหรือไม่"
            data-confirm-title="ยืนยันการย้ายกลับ"
            data-confirm-ok="ยืนยัน"
            data-confirm-cancel="ยกเลิก">
            <i class="fa-solid fa-file-import"></i>
            <p>ย้ายกลับ</p>
        </button>
    </div>
<?php endif; ?>

<script>
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
