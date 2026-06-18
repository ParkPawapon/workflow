<?php
require_once __DIR__ . '/../../helpers.php';

$active_tab = trim((string) ($active_tab ?? 'compose'));
$filter_status = trim((string) ($filter_status ?? 'all'));
$filter_sort = trim((string) ($filter_sort ?? 'newest'));
$search = trim((string) ($search ?? ''));
$groups = (array) ($groups ?? []);
$form_values = (array) ($form_values ?? []);
$certificate_items = (array) ($certificate_items ?? []);
$my_certificate_items = (array) ($my_certificate_items ?? []);
$certificate_status_map = (array) ($certificate_status_map ?? []);
$view_modal_payload_map = (array) ($view_modal_payload_map ?? []);
$preview_base = (array) ($preview_base ?? []);
$selected_group_fid = trim((string) ($form_values['group_fid'] ?? ''));
$selected_group_name = '';

foreach ($groups as $group) {
    if ((string) ($group['fID'] ?? '') === $selected_group_fid) {
        $selected_group_name = trim((string) ($group['fName'] ?? ''));
        break;
    }
}

if ($selected_group_name === '' && $groups !== []) {
    $selected_group_name = trim((string) ($groups[0]['fName'] ?? ''));
}

if (!function_exists('certificate_view_file_size')) {
    function certificate_view_file_size(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit_index = 0;

        while ($size >= 1024 && $unit_index < count($units) - 1) {
            $size /= 1024;
            $unit_index++;
        }

        $precision = $unit_index === 0 ? 0 : 1;

        return number_format($size, $precision) . ' ' . $units[$unit_index];
    }
}

ob_start();
?>

<style>
    .content-circular-notice-index .modal-overlay-circular-notice-index.outside-person .modal-content .content-modal .content-topic-sec:nth-child(2) {
        flex-wrap: nowrap;
    }

    .vehicle-row .btn.btn-upload-small {
        margin: 0;
    }

    .file-hint p {
        color: var(--color-danger) !important;
    }

    .btn-upload-small p {
        color: var(--color-neutral-lightest) !important;
    }

    .form-group.row.label {
        margin: 0 0 10px;
        height: auto;
    }

    .enterprise-card+.enterprise-card {
        margin-top: 0;
    }

    .file-item-wrapper {
        width: auto;
    }

    .file-banner {
        max-width: 600px;
    }

    .delete-btn {
        width: 60px !important;
    }

    .delete-btn {
        background: none !important;
        border: none !important;
        color: var(--color-danger) !important;
        font-size: var(--font-size-title) !important;
        cursor: pointer !important;
        transition: transform 0.2s !important;
    }

    .delete-btn:hover {
        transform: scale(1.2) !important;
    }

    .upload-layout {
        flex-direction: column !important;
        gap: 0px;
    }

    .certificate-empty-row {
        text-align: center;
        color: var(--color-neutral-dark);
    }

    .certificate-file-empty {
        color: var(--color-neutral-dark);
    }

    .certificate-file-empty p {
        color: inherit !important;
    }

    .certificate-file-list {
        width: 100%;
    }

    .certificate-hidden-inputs {
        display: none;
    }

    .circular-track-modal-host {
        width: 0;
        height: 0;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
    }

    @media screen and (max-width: 1024px) {
        .form-group.row {
            margin: 0 0 20px;
        }
        .file-banner {
            max-width: 400px;
        }
        .delete-btn {
            font-size: var(--font-size-desc-1) !important;
            width: 40px !important;
        }
    }
    
    @media screen and (max-width: 768px){
        .form-group.row {
            margin: 0 0 10px;
        }
        .file-banner {
            max-width: 300px;
        }
        .delete-btn {
            font-size: var(--font-size-desc-3) !important;
            width: 20px !important;
        }

    }
</style>

<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>ออกเลขเกียรติบัตร</p>
</div>

<div class="tabs-container setting-page">
    <div class="button-container">
        <button class="tab-btn <?= $active_tab === 'compose' ? 'active' : '' ?>"
            onclick="openTab('certificate', event)">ออกเลขเกียรติบัตร</button>
        <button class="tab-btn <?= $active_tab === 'mine' ? 'active' : '' ?>"
            onclick="openTab('certificateMine', event)">เกียรติบัตรของฉัน</button>
        <button class="tab-btn <?= $is_track_active ? 'active' : '' ?>"
            onclick="openTab('certificateData', event)">เกียรติบัตรทั้งหมด</button>
    </div>
</div>

<div class="content-order tab-content <?= $active_tab === 'compose' ? 'active' : '' ?>" id="certificate">
    <form method="POST" enctype="multipart/form-data" id="certificateCreateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-group row">
            <div class="input-group">
                <p><strong>เรื่อง</strong></p>
                <input type="text" class="order-no-display" name="subject"
                    value="<?= h((string) ($form_values['subject'] ?? '')) ?>">
            </div>
            <div class="input-group">
                <p><strong>จำนวนเกียรติบัตรทั้งหมด</strong></p>
                <input type="number" min="1" class="order-no-display" id="certificateTotalInput"
                    name="total_certificates" value="<?= h((string) ($form_values['total_certificates'] ?? '')) ?>">
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>จากลำดับที่</strong></p>
                <input type="text" class="order-no-display" id="certificateFromNoInput" value="" disabled>
            </div>
            <div class="input-group">
                <p><strong>ถึงลำดับที่</strong></p>
                <input type="text" class="order-no-display" id="certificateToNoInput" value="" disabled>
            </div>
        </div>

        <div class="form-group row">
            <div class="input-group">
                <p><strong>ผู้ขอ</strong></p>
                <input type="text" class="order-no-display" value="<?= h((string) ($current_user_name ?? '')) ?>"
                    disabled>
            </div>
            <div class="input-group">
                <p><strong>ในนามของ</strong></p>
                <div class="custom-select-wrapper">
                    <div class="custom-select-trigger">
                        <p class="select-value">
                            <?= h($selected_group_name !== '' ? $selected_group_name : 'เลือกในนามของ') ?>
                        </p>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </div>

                    <div class="custom-options">
                        <?php foreach ($groups as $group): ?>
                            <?php $group_fid = (string) ($group['fID'] ?? ''); ?>
                            <div class="custom-option<?= $group_fid === $selected_group_fid ? ' selected' : '' ?>"
                                data-value="<?= h($group_fid) ?>">
                                <?= h((string) ($group['fName'] ?? '')) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="group_fid" value="<?= h($selected_group_fid) ?>">
                </div>
            </div>
        </div>

        <div class="form-group last button">
            <div class="input-group">
                <button class="submit" type="submit" data-confirm="ยืนยันการบันทึกออกเลขเกียรติบัตรใช่หรือไม่?"
                    data-confirm-title="ยืนยันการบันทึก" data-confirm-ok="ยืนยัน" data-confirm-cancel="ยกเลิก">
                    <p>บันทึกออกเลข</p>
                </button>
            </div>
        </div>
    </form>
</div>

<section class="enterprise-card tab-content <?= $active_tab === 'data' ? 'active' : '' ?>" id="certificateData">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" class="circular-my-filter-grid" id="certificateDataFilterForm">
        <input type="hidden" name="tab" value="data">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($search) ?>"
                    data-filter-search="certificate-data"
                    placeholder="ค้นหาเลขเกียรติบัตรหรือเรื่อง" autocomplete="off">
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
                        <div class="custom-option<?= $filter_status === 'all' ? ' selected' : '' ?>" data-value="all">
                            ทั้งหมด</div>
                        <div class="custom-option<?= $filter_status === 'waiting_attachment' ? ' selected' : '' ?>"
                            data-value="waiting_attachment">รอการแนบไฟล์</div>
                        <div class="custom-option<?= $filter_status === 'complete' ? ' selected' : '' ?>"
                            data-value="complete">แนบไฟล์สำเร็จ</div>
                    </div>
                    <select class="form-input" name="status" data-filter-select="certificate-data">
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
                        <div class="custom-option<?= $filter_sort === 'newest' ? ' selected' : '' ?>"
                            data-value="newest">ใหม่ไปเก่า</div>
                        <div class="custom-option<?= $filter_sort === 'oldest' ? ' selected' : '' ?>"
                            data-value="oldest">เก่าไปใหม่</div>
                    </div>
                    <select class="form-input" name="sort" data-filter-select="certificate-data">
                        <option value="newest" <?= $filter_sort === 'newest' ? 'selected' : '' ?>>ใหม่ไปเก่า</option>
                        <option value="oldest" <?= $filter_sort === 'oldest' ? 'selected' : '' ?>>เก่าไปใหม่</option>
                    </select>
                </div>
            </div>
        </div>
    </form>

    <div class="enterprise-card-header order-mine-list-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">รายการเลขเกียรติบัตร</h2>
        </div>
    </div>

    <div class="table-responsive circular-my-table-wrap order-create">
        <table class="custom-table circular-my-table">
            <thead>
                <tr>
                    <th>เรื่อง</th>
                    <th>จากลำดับที่</th>
                    <th>ถึงลำดับที่</th>
                    <th>สถานะ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($certificate_items === []): ?>
                    <tr>
                        <td colspan="5" class="certificate-empty-row">ไม่พบรายการเกียรติบัตร</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($certificate_items as $item): ?>
                        <?php
                        $certificate_id = (int) ($item['certificateID'] ?? 0);
                        $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
                        $status_meta = $certificate_status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'approved'];
                        $file_action_label = $status_key === 'COMPLETE' ? 'ดูไฟล์' : 'ดู/แนบไฟล์';
                        ?>
                        <tr>
                            <td><?= h((string) ($item['subject'] ?? '-')) ?></td>
                            <td><?= h((string) ($item['certificateFromNo'] ?? '-')) ?></td>
                            <td><?= h((string) ($item['certificateToNo'] ?? '-')) ?></td>
                            <td><span
                                    class="status-pill <?= h((string) ($status_meta['pill'] ?? 'approved')) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span>
                            </td>
                            <td style="text-align: center;">
                                <div class="circular-my-actions">
                                    <button class="booking-action-btn secondary js-open-certificate-view-modal" type="button"
                                        data-certificate-id="<?= h((string) $certificate_id) ?>" title="ดูรายละเอียด"
                                        aria-label="ดูรายละเอียด">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        <span class="tooltip">ดูรายละเอียด</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="enterprise-card tab-content <?= $active_tab === 'mine' ? 'active' : '' ?>" id="certificateMine">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">ค้นหาและกรองรายการ</h2>
        </div>
    </div>

    <form method="GET" class="circular-my-filter-grid" id="certificateMineFilterForm">
        <input type="hidden" name="tab" value="mine">
        <div class="approval-filter-group">
            <div class="room-admin-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="form-input" type="search" name="q" value="<?= h($search) ?>"
                    data-filter-search="certificate-mine"
                    placeholder="ค้นหาเลขเกียรติบัตรของฉัน" autocomplete="off">
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
                        <div class="custom-option<?= $filter_status === 'all' ? ' selected' : '' ?>" data-value="all">
                            ทั้งหมด</div>
                        <div class="custom-option<?= $filter_status === 'waiting_attachment' ? ' selected' : '' ?>"
                            data-value="waiting_attachment">รอการแนบไฟล์</div>
                        <div class="custom-option<?= $filter_status === 'complete' ? ' selected' : '' ?>"
                            data-value="complete">แนบไฟล์สำเร็จ</div>
                    </div>
                    <select class="form-input" name="status" data-filter-select="certificate-mine">
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
                        <div class="custom-option<?= $filter_sort === 'newest' ? ' selected' : '' ?>"
                            data-value="newest">ใหม่ไปเก่า</div>
                        <div class="custom-option<?= $filter_sort === 'oldest' ? ' selected' : '' ?>"
                            data-value="oldest">เก่าไปใหม่</div>
                    </div>
                    <select class="form-input" name="sort" data-filter-select="certificate-mine">
                        <option value="newest" <?= $filter_sort === 'newest' ? 'selected' : '' ?>>ใหม่ไปเก่า</option>
                        <option value="oldest" <?= $filter_sort === 'oldest' ? 'selected' : '' ?>>เก่าไปใหม่</option>
                    </select>
                </div>
            </div>
        </div>
    </form>

    <div class="enterprise-card-header order-mine-list-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">รายการเลขเกียรติบัตรของฉัน</h2>
        </div>
    </div>

    <div class="table-responsive circular-my-table-wrap order-create">
        <table class="custom-table circular-my-table">
            <thead>
                <tr>
                    <th>เรื่อง</th>
                    <th>จากลำดับที่</th>
                    <th>ถึงลำดับที่</th>
                    <th>สถานะ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($my_certificate_items === []): ?>
                    <tr>
                        <td colspan="5" class="certificate-empty-row">ไม่พบรายการเกียรติบัตรของฉัน</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($my_certificate_items as $item): ?>
                        <?php
                        $certificate_id = (int) ($item['certificateID'] ?? 0);
                        $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
                        $status_meta = $certificate_status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'approved'];
                        ?>
                        <tr>
                            <td><?= h((string) ($item['subject'] ?? '-')) ?></td>
                            <td><?= h((string) ($item['certificateFromNo'] ?? '-')) ?></td>
                            <td><?= h((string) ($item['certificateToNo'] ?? '-')) ?></td>
                            <td><span
                                    class="status-pill <?= h((string) ($status_meta['pill'] ?? 'approved')) ?>"><?= h((string) ($status_meta['label'] ?? '-')) ?></span>
                            </td>
                            <td style="text-align: center;">
                                <div class="circular-my-actions">
                                    <button class="booking-action-btn secondary js-open-certificate-edit-modal" type="button"
                                        data-certificate-id="<?= h((string) $certificate_id) ?>" title="<?= h($file_action_label) ?>"
                                        aria-label="<?= h($file_action_label) ?>">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        <span class="tooltip"><?= h($file_action_label) ?></span>
                                    </button>
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
    <div class="modal-overlay-circular-notice-index outside-person" id="modalCertificateEditOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p>ดู/แนบไฟล์เกียรติบัตร</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="modalCertificateEditCloseBtn" aria-hidden="true"></i>
                </div>
            </div>

            <div class="content-modal">
                <form method="POST" enctype="multipart/form-data" id="modalCertificateEditForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="attach">
                    <input type="hidden" name="certificate_id" id="modalCertificateEditId" value="">

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>จำนวนเกียรติบัตรทั้งหมด</strong></p>
                            <input type="number" id="modalCertificateEditTotal" class="order-no-display" value="0" disabled>
                        </div>
                        <div class="more-details">
                            <p><strong>เรื่อง</strong></p>
                            <input type="text" id="modalCertificateEditSubject" class="order-no-display" value="-" disabled>
                        </div>
                    </div>
                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>จากลำดับที่</strong></p>
                            <input type="text" id="modalCertificateEditFrom" class="order-no-display" value="-" disabled>
                        </div>
                        <div class="more-details">
                            <p><strong>ถึงลำดับที่</strong></p>
                            <input type="text" id="modalCertificateEditTo" class="order-no-display" value="-" disabled>
                        </div>
                    </div>

                    <div class="content-topic-sec">
                        <div class="more-details">
                            <p><strong>ผู้ขอ</strong></p>
                            <input type="text" id="modalCertificateEditRequester" class="order-no-display" value="-" disabled>
                        </div>
                        <div class="more-details">
                            <p><strong>ในนามของ</strong></p>
                            <input type="text" id="modalCertificateEditGroup" class="order-no-display" value="-" disabled>
                        </div>
                    </div>

                    <div class="vehicle-row file-sec">
                        <div class="vehicle-input-content">
                            <p><strong>ไฟล์เอกสารแนบ</strong></p>
                        </div>
                        <section class="upload-layout">
                            <input type="file" id="modalCertificateEditFileInput" name="attachments[]"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/x-rar,application/vnd.rar"
                                style="display: none;">
                            <div class="row form-group" id="modalCertificateEditUploadActions">
                                <button class="btn btn-upload-small" type="button" id="modalCertificateEditAddFileBtn">
                                    <p>เพิ่มไฟล์</p>
                                </button>
                                <div class="file-hint">
                                    <p>*อัปโหลดได้เฉพาะไฟล์ word, excel, pdf, zip, rar สูงสุดแนบไฟล์ได้ 1 ไฟล์ *</p>
                                </div>
                            </div>
                            <div class="existing-file-section">
                                <div class="file-list certificate-file-list" id="modalCertificateEditFileList" aria-live="polite"></div>
                            </div>
                        </section>
                    </div>

                    <div class="certificate-hidden-inputs" id="modalCertificateEditRemoveInputs" data-remove-file-inputs="true"></div>
                </form>
            </div>

            <div class="footer-modal" id="modalCertificateEditFooter">
                <button type="submit" form="modalCertificateEditForm" data-confirm="ยืนยันการบันทึกไฟล์เกียรติบัตรใช่หรือไม่?"
                    data-confirm-title="ยืนยันการบันทึก" data-confirm-ok="ยืนยัน" data-confirm-cancel="ยกเลิก">
                    <p>บันทึก</p>
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay-circular-notice-index outside-person" id="modalCertificateViewOverlay">
        <div class="modal-content">
            <div class="header-modal">
                <div class="first-header">
                    <p>ดูรายละเอียดออกเลขเกียรติบัตร</p>
                </div>
                <div class="sec-header">
                    <i class="fa-solid fa-xmark" id="modalCertificateViewCloseBtn" aria-hidden="true"></i>
                </div>
            </div>

            <div class="content-modal">
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>จำนวนเกียรติบัตรทั้งหมด</strong></p>
                        <input type="number" id="modalCertificateViewTotal" class="order-no-display" value="0" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>เรื่อง</strong></p>
                        <input type="text" id="modalCertificateViewSubject" class="order-no-display" value="-" disabled>
                    </div>
                </div>
                <div class="content-topic-sec">
                    <div class="more-details">
                        <p><strong>จากลำดับที่</strong></p>
                        <input type="text" id="modalCertificateViewFrom" class="order-no-display" value="-" disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>ถึงลำดับที่</strong></p>
                        <input type="text" id="modalCertificateViewTo" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="content-topic-sec">

                    <div class="more-details">
                        <p><strong>ผู้ขอ</strong></p>
                        <input type="text" id="modalCertificateViewRequester" class="order-no-display" value="-"
                            disabled>
                    </div>
                    <div class="more-details">
                        <p><strong>ในนามของ</strong></p>
                        <input type="text" id="modalCertificateViewGroup" class="order-no-display" value="-" disabled>
                    </div>
                </div>

                <div class="vehicle-row file-sec">
                    <div class="vehicle-input-content">
                        <p><strong>ไฟล์เอกสารแนบ</strong></p>
                    </div>
                    <div class="file-list certificate-file-list" id="certificateAttachmentList" aria-live="polite">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="certificateModalPayloadMap">
    <?= (string) json_encode($view_modal_payload_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const totalInput = document.getElementById('certificateTotalInput');
        const fromInput = document.getElementById('certificateFromNoInput');
        const toInput = document.getElementById('certificateToNoInput');
        const previewBase = {
            year: <?= (int) ($preview_base['year'] ?? 0) ?>,
            nextSeq: <?= (int) ($preview_base['fromSeq'] ?? 1) ?>,
        };

        const formatCertificateNumber = (year, seq) => {
            const normalizedSeq = Math.max(1, Number(seq) || 1);
            return `ด.บ.${year}-${String(normalizedSeq).padStart(5, '0')}`;
        };

        const renderPreviewRange = () => {
            if (!totalInput || !fromInput || !toInput) {
                return;
            }
            const total = Math.max(0, Number(totalInput.value) || 0);

            if (total <= 0) {
                fromInput.value = '';
                toInput.value = '';
                return;
            }

            const fromSeq = previewBase.nextSeq;
            const toSeq = fromSeq + total - 1;

            fromInput.value = formatCertificateNumber(previewBase.year, fromSeq);
            toInput.value = formatCertificateNumber(previewBase.year, toSeq);
        };

        renderPreviewRange();

        if (totalInput) {
            totalInput.addEventListener('input', renderPreviewRange);
        }

        const setupFilterForm = (formId) => {
            const form = document.getElementById(formId);

            if (!form) {
                return;
            }

            const searchInput = form.querySelector('input[type="search"][data-filter-search]');
            const selectInputs = form.querySelectorAll('select[data-filter-select]');
            let searchTimer = null;

            selectInputs.forEach((select) => {
                select.addEventListener('change', () => {
                    form.submit();
                });
            });

            if (!searchInput) {
                return;
            }

            searchInput.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(() => {
                    form.submit();
                }, 300);
            });

            searchInput.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                window.clearTimeout(searchTimer);
                form.submit();
            });
        };

        setupFilterForm('certificateDataFilterForm');
        setupFilterForm('certificateMineFilterForm');

        const viewModal = document.getElementById('modalCertificateViewOverlay');
        const viewCloseBtn = document.getElementById('modalCertificateViewCloseBtn');
        const editModal = document.getElementById('modalCertificateEditOverlay');
        const editCloseBtn = document.getElementById('modalCertificateEditCloseBtn');
        const payloadMapElement = document.getElementById('certificateModalPayloadMap');
        const payloadMap = payloadMapElement ? JSON.parse(payloadMapElement.textContent || '{}') : {};
        const viewTotalField = document.getElementById('modalCertificateViewTotal');
        const viewRequesterField = document.getElementById('modalCertificateViewRequester');
        const viewFromField = document.getElementById('modalCertificateViewFrom');
        const viewToField = document.getElementById('modalCertificateViewTo');
        const viewSubjectField = document.getElementById('modalCertificateViewSubject');
        const viewGroupField = document.getElementById('modalCertificateViewGroup');
        const viewAttachmentList = document.getElementById('certificateAttachmentList');
        const editForm = document.getElementById('modalCertificateEditForm');
        const editIdField = document.getElementById('modalCertificateEditId');
        const editTotalField = document.getElementById('modalCertificateEditTotal');
        const editRequesterField = document.getElementById('modalCertificateEditRequester');
        const editFromField = document.getElementById('modalCertificateEditFrom');
        const editToField = document.getElementById('modalCertificateEditTo');
        const editSubjectField = document.getElementById('modalCertificateEditSubject');
        const editGroupField = document.getElementById('modalCertificateEditGroup');
        const editFileInput = document.getElementById('modalCertificateEditFileInput');
        const editAddFileButton = document.getElementById('modalCertificateEditAddFileBtn');
        const editUploadActions = document.getElementById('modalCertificateEditUploadActions');
        const editFileList = document.getElementById('modalCertificateEditFileList');
        const editRemoveInputs = document.getElementById('modalCertificateEditRemoveInputs');
        const editFooter = document.getElementById('modalCertificateEditFooter');
        let editExistingFiles = [];
        let removedEditFileIds = [];
        let editIsReadonly = false;

        const buildDownloadUrl = (certificateId, fileId) => {
            return `public/api/file-download.php?module=certificates&entity_id=${encodeURIComponent(String(certificateId))}&file_id=${encodeURIComponent(String(fileId))}`;
        };

        const formatFileSizeLabel = (bytes) => {
            const normalizedBytes = Number(bytes) || 0;

            if (normalizedBytes <= 0) {
                return '0 KB';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            let size = normalizedBytes;
            let unitIndex = 0;

            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex += 1;
            }

            return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
        };

        const renderViewAttachmentList = (certificateId, attachments) => {
            if (!viewAttachmentList) {
                return;
            }

            viewAttachmentList.innerHTML = '';

            if (!attachments || attachments.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'certificate-file-empty';
                empty.innerHTML = '<p>-</p>';
                viewAttachmentList.appendChild(empty);
                return;
            }

            attachments.forEach((file) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper';

                const banner = document.createElement('div');
                banner.className = 'file-banner';

                const info = document.createElement('div');
                info.className = 'file-info';

                const icon = document.createElement('div');
                icon.className = 'file-icon';
                icon.innerHTML = '<i class="fa-solid fa-file" aria-hidden="true"></i>';

                const sizeLabel = document.createElement('span');
                sizeLabel.className = 'file-type';
                sizeLabel.textContent = `${file.mimeType || 'ไฟล์แนบ'} • ${formatFileSizeLabel(file.fileSize)}`;
                const text = document.createElement('div');
                text.className = 'file-text';
                const fileName = document.createElement('span');
                fileName.className = 'file-name';
                fileName.textContent = file.fileName || '-';
                text.appendChild(fileName);
                text.appendChild(sizeLabel);

                info.appendChild(icon);
                info.appendChild(text);

                const actions = document.createElement('div');
                actions.className = 'file-actions';

                const view = document.createElement('a');
                view.href = buildDownloadUrl(certificateId, file.fileID);
                view.target = '_blank';
                view.rel = 'noopener';
                view.className = 'action-btn';
                view.title = 'ดูตัวอย่าง';
                view.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';

                actions.appendChild(view);
                banner.appendChild(info);
                banner.appendChild(actions);
                wrapper.appendChild(banner);
                viewAttachmentList.appendChild(wrapper);
            });
        };

        const syncEditRemoveInputs = () => {
            if (!editRemoveInputs) {
                return;
            }

            editRemoveInputs.innerHTML = '';

            removedEditFileIds.forEach((fileId) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'remove_file_ids[]';
                input.value = String(fileId);
                editRemoveInputs.appendChild(input);
            });
        };

        const resetEditModalState = () => {
            editExistingFiles = [];
            removedEditFileIds = [];
            editIsReadonly = false;

            if (editFileInput) {
                editFileInput.value = '';
                editFileInput.disabled = false;
            }

            if (editUploadActions) {
                editUploadActions.style.display = '';
            }

            if (editFooter) {
                editFooter.style.display = '';
            }

            syncEditRemoveInputs();

            if (editFileList) {
                editFileList.innerHTML = '';
            }
        };

        const renderEditAttachmentList = (certificateId) => {
            if (!editFileList) {
                return;
            }

            editFileList.innerHTML = '';

            editExistingFiles.forEach((file) => {
                const fileId = String(file.fileID || '').trim();
                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper';

                if (!editIsReadonly) {
                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'delete-btn';
                    deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
                    deleteBtn.addEventListener('click', () => {
                        if (fileId !== '' && !removedEditFileIds.includes(fileId)) {
                            removedEditFileIds.push(fileId);
                        }

                        editExistingFiles = editExistingFiles.filter((existingFile) => String(existingFile.fileID || '').trim() !== fileId);
                        syncEditRemoveInputs();
                        renderEditAttachmentList(certificateId);
                    });
                    wrapper.appendChild(deleteBtn);
                }

                const banner = document.createElement('div');
                banner.className = 'file-banner';

                const info = document.createElement('div');
                info.className = 'file-info';

                const icon = document.createElement('div');
                icon.className = 'file-icon';
                icon.innerHTML = '<i class="fa-solid fa-file" aria-hidden="true"></i>';

                const text = document.createElement('div');
                text.className = 'file-text';
                text.innerHTML = `<div class="file-name">${file.fileName || '-'}</div><div class="file-type">${file.mimeType || 'ไฟล์แนบ'} • ${formatFileSizeLabel(file.fileSize)}</div>`;

                info.appendChild(icon);
                info.appendChild(text);

                const actions = document.createElement('div');
                actions.className = 'file-actions';

                const view = document.createElement('a');
                view.href = buildDownloadUrl(certificateId, file.fileID);
                view.target = '_blank';
                view.rel = 'noopener';
                view.className = 'action-btn';
                view.title = 'ดูตัวอย่าง';
                view.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';

                actions.appendChild(view);
                banner.appendChild(info);
                banner.appendChild(actions);
                wrapper.appendChild(banner);
                editFileList.appendChild(wrapper);
            });

            const selectedFile = editFileInput && editFileInput.files && editFileInput.files.length > 0 ?
                editFileInput.files[0] :
                null;

            if (selectedFile && !editIsReadonly) {
                const wrapper = document.createElement('div');
                wrapper.className = 'file-item-wrapper new-file-item';

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'delete-btn';
                deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
                deleteBtn.addEventListener('click', () => {
                    if (editFileInput) {
                        editFileInput.value = '';
                    }
                    renderEditAttachmentList(certificateId);
                });

                const banner = document.createElement('div');
                banner.className = 'file-banner';

                const info = document.createElement('div');
                info.className = 'file-info';

                const icon = document.createElement('div');
                icon.className = 'file-icon';
                icon.innerHTML = '<i class="fa-solid fa-file" aria-hidden="true"></i>';

                const text = document.createElement('div');
                text.className = 'file-text';
                text.innerHTML = `<div class="file-name">${selectedFile.name}</div><div class="file-type">${selectedFile.type || 'ไฟล์แนบ'} • ${formatFileSizeLabel(selectedFile.size)}</div>`;

                info.appendChild(icon);
                info.appendChild(text);
                banner.appendChild(info);
                wrapper.appendChild(deleteBtn);
                wrapper.appendChild(banner);
                editFileList.appendChild(wrapper);
            }

            if (editFileList.children.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'certificate-file-empty';
                editFileList.appendChild(empty);
            }
        };

        const openViewModal = (certificateId) => {
            if (!viewModal) {
                return;
            }

            const payload = payloadMap[String(certificateId)];

            if (!payload) {
                return;
            }

            if (viewTotalField) viewTotalField.value = String(payload.totalCertificates || 0);
            if (viewRequesterField) viewRequesterField.value = payload.requesterName || '-';
            if (viewFromField) viewFromField.value = payload.certificateFromNo || '-';
            if (viewToField) viewToField.value = payload.certificateToNo || '-';
            if (viewSubjectField) viewSubjectField.value = payload.subject || '-';
            if (viewGroupField) viewGroupField.value = payload.groupName || '-';

            renderViewAttachmentList(certificateId, Array.isArray(payload.attachments) ? payload.attachments : []);
            viewModal.style.display = 'flex';
        };

        const openEditModal = (certificateId) => {
            if (!editModal) {
                return;
            }

            const payload = payloadMap[String(certificateId)];

            if (!payload) {
                return;
            }

            resetEditModalState();

            if (editIdField) editIdField.value = String(payload.certificateID || certificateId || '');
            if (editTotalField) editTotalField.value = String(payload.totalCertificates || 0);
            if (editRequesterField) editRequesterField.value = payload.requesterName || '-';
            if (editFromField) editFromField.value = payload.certificateFromNo || '-';
            if (editToField) editToField.value = payload.certificateToNo || '-';
            if (editSubjectField) editSubjectField.value = payload.subject || '-';
            if (editGroupField) editGroupField.value = payload.groupName || '-';

            editExistingFiles = Array.isArray(payload.attachments) ? payload.attachments.map((file) => ({
                fileID: Number(file.fileID) || 0,
                fileName: file.fileName || '-',
                mimeType: file.mimeType || '',
                fileSize: Number(file.fileSize) || 0,
            })) : [];

            editIsReadonly = String(payload.statusKey || '').toUpperCase() === 'COMPLETE' || editExistingFiles.length > 0;

            if (editFileInput) {
                editFileInput.disabled = editIsReadonly;
                editFileInput.value = '';
            }

            if (editUploadActions) {
                editUploadActions.style.display = editIsReadonly ? 'none' : '';
            }

            if (editFooter) {
                editFooter.style.display = editIsReadonly ? 'none' : '';
            }

            renderEditAttachmentList(certificateId);
            editModal.style.display = 'flex';
        };

        document.addEventListener('click', (event) => {
            const viewButton = event.target.closest('.js-open-certificate-view-modal');
            const editButton = event.target.closest('.js-open-certificate-edit-modal');

            if (viewButton) {
                openViewModal(viewButton.getAttribute('data-certificate-id') || '');
            }

            if (editButton) {
                openEditModal(editButton.getAttribute('data-certificate-id') || '');
            }
        });

        if (viewCloseBtn && viewModal) {
            viewCloseBtn.addEventListener('click', () => {
                viewModal.style.display = 'none';
            });
        }

        if (viewModal) {
            window.addEventListener('click', (event) => {
                if (event.target === viewModal) {
                    viewModal.style.display = 'none';
                }
            });
        }

        if (editAddFileButton && editFileInput) {
            editAddFileButton.addEventListener('click', () => {
                if (editIsReadonly) {
                    return;
                }

                editFileInput.click();
            });
        }

        if (editFileInput) {
            editFileInput.addEventListener('change', () => {
                const certificateId = editIdField ? editIdField.value : '';

                if (editIsReadonly) {
                    editFileInput.value = '';
                    return;
                }

                if (editFileInput.files && editFileInput.files.length > 0 && editExistingFiles.length > 0) {
                    window.alert('กรุณาลบไฟล์เดิมก่อนเลือกไฟล์ใหม่');
                    editFileInput.value = '';
                }

                renderEditAttachmentList(certificateId);
            });
        }

        if (editCloseBtn && editModal) {
            editCloseBtn.addEventListener('click', () => {
                editModal.style.display = 'none';
                resetEditModalState();
            });
        }

        if (editModal) {
            window.addEventListener('click', (event) => {
                if (event.target === editModal) {
                    editModal.style.display = 'none';
                    resetEditModalState();
                }
            });
        }

        if (editForm) {
            editForm.addEventListener('submit', (event) => {
                if (editIsReadonly) {
                    event.preventDefault();
                    return;
                }

                syncEditRemoveInputs();
            });
        }
    });
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
