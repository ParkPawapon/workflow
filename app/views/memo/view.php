<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../modules/memos/status.php';

$memo = $memo ?? null;
$attachments = (array) ($attachments ?? []);
$signed_file = $signed_file ?? null;
$routes = (array) ($routes ?? []);
$approver_options = (array) ($approver_options ?? []);
$access = (array) ($access ?? []);

$memo_page_my = 'memo.php';
$memo_page_inbox = 'memo-inbox.php';
$memo_page_archive = 'memo-archive.php';

$is_creator = (bool) ($access['is_creator'] ?? false);
$is_approver = (bool) ($access['is_approver'] ?? false);
$is_admin = (bool) ($access['is_admin'] ?? false);

$stage_label_map = [
    'OWNER' => 'เจ้าของเรื่อง',
    'HEAD' => 'หัวหน้ากลุ่ม/หัวหน้างาน',
    'DEPUTY' => 'รองผู้อำนวยการ',
    'DIRECTOR' => 'ผู้อำนวยการ/รักษาการ',
];

$memo_id = (int) ($memo['memoID'] ?? 0);
$memo_no = trim((string) ($memo['memoNo'] ?? ''));
$status = (string) ($memo['status'] ?? '');
$status_meta = memo_status_meta($status);
$action_url = (string) ($action_url ?? '');

if ($action_url === '') {
    $action_url = 'memo-view.php';

    if ($memo_id > 0) {
        $action_url .= '?memo_id=' . $memo_id;
    }
}

$is_editable = $is_creator && in_array($status, ['DRAFT', 'RETURNED'], true);
$can_submit = $is_creator && in_array($status, ['DRAFT', 'RETURNED'], true);
$can_recall = $is_creator && in_array($status, ['SUBMITTED', 'IN_REVIEW', 'APPROVED_UNSIGNED'], true);
$can_cancel = $is_creator && !in_array($status, ['SIGNED', 'REJECTED', 'CANCELLED'], true);
$can_archive = $is_creator && in_array($status, ['SIGNED', 'REJECTED', 'CANCELLED'], true);
$is_archived = (int) ($memo['isArchived'] ?? 0) === 1;

$can_review = $is_approver && in_array($status, ['SUBMITTED', 'IN_REVIEW'], true);
$flow_mode = strtoupper(trim((string) ($memo['flowMode'] ?? 'CHAIN')));
$flow_stage = strtoupper(trim((string) ($memo['flowStage'] ?? 'OWNER')));
$is_chain = $flow_mode !== 'DIRECT';
$is_chain_head = $is_chain && $flow_stage === 'HEAD';
$is_chain_deputy = $is_chain && $flow_stage === 'DEPUTY';
$is_chain_director = $is_chain && $flow_stage === 'DIRECTOR';

$can_forward_chain = $can_review && ($is_chain_head || $is_chain_deputy);
$can_director_approve = $can_review && $is_chain_director;
$can_director_reject = $can_review && $is_chain_director;

$can_approve_unsigned = $can_review && !$is_chain;
$can_sign_now = $is_approver && !$is_chain && in_array($status, ['SUBMITTED', 'IN_REVIEW', 'APPROVED_UNSIGNED'], true);
$can_reject = $can_review && !$is_chain;

$to_type = (string) ($memo['toType'] ?? '');
$approver_label = trim((string) ($memo['approverName'] ?? ''));

if ($approver_label === '' && $to_type === 'DIRECTOR') {
    $approver_label = 'ผอ./รักษาการ';
}

if ($approver_label === '') {
    $approver_label = '-';
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

$format_thai_date = static function (?string $date_value) use ($thai_months): string {
    $date_value = trim((string) $date_value);

    if ($date_value === '' || $date_value === '0000-00-00') {
        return '-';
    }

    $date_obj = DateTime::createFromFormat('Y-m-d', $date_value);

    if ($date_obj === false) {
        return $date_value;
    }

    $day = (int) $date_obj->format('j');
    $month = (int) $date_obj->format('n');
    $year = (int) $date_obj->format('Y') + 543;
    $month_label = $thai_months[$month] ?? '';

    return trim($day . ' ' . $month_label . ' ' . $year);
};

$format_thai_datetime = static function (?string $datetime_value) use ($thai_months): string {
    $datetime_value = trim((string) $datetime_value);

    if ($datetime_value === '' || strpos($datetime_value, '0000-00-00') === 0) {
        return '-';
    }

    $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_value);

    if ($date_obj === false) {
        $date_obj = DateTime::createFromFormat('Y-m-d H:i', $datetime_value);
    }

    if ($date_obj === false) {
        return $datetime_value;
    }

    $day = (int) $date_obj->format('j');
    $month = (int) $date_obj->format('n');
    $year = (int) $date_obj->format('Y') + 543;
    $month_label = $thai_months[$month] ?? '';

    return trim($day . ' ' . $month_label . ' ' . $year . ' เวลา ' . $date_obj->format('H:i'));
};

$display_text = static function (?string $value): string {
    $value = trim((string) $value);

    return $value !== '' ? $value : '-';
};

ob_start();
?>
<div class="content-header">
    <h1><?= h(($memo_no !== '' ? $memo_no : ('#' . $memo_id)) . ' ' . (string) ($memo['subject'] ?? 'บันทึกข้อความ')) ?></h1>
    <p>บันทึกข้อความเสนอ/ลายเซ็น</p>
</div>

<div class="enterprise-tabs">
    <a class="enterprise-tab" href="<?= h($memo_page_my) ?>">บันทึกของฉัน</a>
    <a class="enterprise-tab" href="<?= h($memo_page_inbox) ?>">Inbox ผู้พิจารณา</a>
    <a class="enterprise-tab" href="<?= h($memo_page_archive) ?>">ที่จัดเก็บ</a>
</div>

<section class="enterprise-card">
    <div class="enterprise-card-header">
        <div class="enterprise-card-title-group">
            <h2 class="enterprise-card-title">รายละเอียดบันทึกข้อความ</h2>
            <p class="enterprise-card-subtitle">สถานะและข้อมูลเอกสาร</p>
        </div>
        <div class="c-badge-group">
            <?php component_render('badge', [
                'label' => $status_meta['label'],
                'variant' => $status_meta['badge_variant'],
            ]); ?>
            <?php if ($is_archived) : ?>
                <?php component_render('badge', [
                    'label' => 'จัดเก็บแล้ว',
                    'variant' => 'neutral',
                ]); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$memo) : ?>
        <?php component_render('empty-state', [
            'title' => 'ไม่พบบันทึกข้อความ',
            'message' => 'ไม่สามารถแสดงรายละเอียดได้ในขณะนี้',
        ]); ?>
    <?php else : ?>
        <div class="enterprise-info">
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">เลขที่</span>
                <span class="enterprise-info-value"><?= h($memo_no !== '' ? $memo_no : ('#' . $memo_id)) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">วันที่</span>
                <span class="enterprise-info-value"><?= h($format_thai_date((string) ($memo['writeDate'] ?? ''))) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">ผู้เสนอ</span>
                <span class="enterprise-info-value"><?= h($display_text((string) ($memo['creatorName'] ?? ''))) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">เรียน</span>
                <span class="enterprise-info-value"><?= h($approver_label) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">รูปแบบการเสนอ</span>
                <span class="enterprise-info-value"><?= h($is_chain ? 'เสนอแฟ้มตามลำดับ' : 'กำหนดผู้พิจารณาโดยตรง') ?></span>
            </div>
            <?php if ($is_chain) : ?>
                <div class="enterprise-info-row">
                    <span class="enterprise-info-label">ลำดับผู้พิจารณา</span>
                    <span class="enterprise-info-value">
                        <?= h(trim((string) ($memo['headName'] ?? '')) !== '' ? (string) ($memo['headName'] ?? '') : '-') ?>
                        →
                        <?= h(trim((string) ($memo['deputyName'] ?? '')) !== '' ? (string) ($memo['deputyName'] ?? '') : '-') ?>
                        →
                        <?= h(trim((string) ($memo['directorName'] ?? '')) !== '' ? (string) ($memo['directorName'] ?? '') : '-') ?>
                    </span>
                </div>
                <div class="enterprise-info-row">
                    <span class="enterprise-info-label">ขั้นตอนปัจจุบัน</span>
                    <span class="enterprise-info-value"><?= h($stage_label_map[$flow_stage] ?? ($flow_stage !== '' ? $flow_stage : '-')) ?></span>
                </div>
            <?php endif; ?>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">ส่งเสนอเมื่อ</span>
                <span class="enterprise-info-value"><?= h($format_thai_datetime((string) ($memo['submittedAt'] ?? ''))) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">เปิดอ่านครั้งแรก</span>
                <span class="enterprise-info-value"><?= h($format_thai_datetime((string) ($memo['firstReadAt'] ?? ''))) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">พิจารณาเมื่อ</span>
                <span class="enterprise-info-value"><?= h($format_thai_datetime((string) ($memo['reviewedAt'] ?? ''))) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">ผู้ลงนาม/ผู้ตัดสินใจ</span>
                <span class="enterprise-info-value"><?= h($display_text((string) ($memo['signerName'] ?? ''))) ?></span>
            </div>
            <div class="enterprise-info-row">
                <span class="enterprise-info-label">ปิดงานเมื่อ</span>
                <span class="enterprise-info-value"><?= h($format_thai_datetime((string) ($memo['approvedAt'] ?? ''))) ?></span>
            </div>
        </div>

        <?php if (!empty($memo['detail'])) : ?>
            <div class="enterprise-divider"></div>
            <div class="enterprise-panel">
                <p><strong>รายละเอียด:</strong></p>
                <p><?= nl2br(h((string) ($memo['detail'] ?? ''))) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($memo['reviewNote'])) : ?>
            <div class="enterprise-divider"></div>
            <div class="enterprise-panel">
                <p><strong>ความเห็นผู้พิจารณา:</strong></p>
                <p><?= nl2br(h((string) ($memo['reviewNote'] ?? ''))) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($attachments)) : ?>
            <div class="enterprise-divider"></div>
            <div class="enterprise-panel">
                <p><strong>ไฟล์แนบ:</strong></p>
                <div class="attachment-list">
                    <?php foreach ($attachments as $file) : ?>
                        <div class="attachment-item">
                            <span class="attachment-name"><?= h($file['fileName'] ?? '') ?></span>
                            <?php if (!empty($file['fileID']) && $memo_id > 0) : ?>
                                <a class="attachment-link" href="public/api/file-download.php?module=memos&entity_id=<?= h((string) $memo_id) ?>&file_id=<?= h((string) $file['fileID']) ?>" target="_blank" rel="noopener">ดูไฟล์</a>
                                <a class="attachment-link" href="public/api/file-download.php?module=memos&entity_id=<?= h((string) $memo_id) ?>&file_id=<?= h((string) $file['fileID']) ?>&download=1">ดาวน์โหลด</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($signed_file)) : ?>
            <div class="enterprise-divider"></div>
            <div class="enterprise-panel">
                <p><strong>ไฟล์ฉบับลงนามแล้ว:</strong></p>
                <div class="attachment-list">
                    <div class="attachment-item">
                        <span class="attachment-name"><?= h($signed_file['fileName'] ?? '') ?></span>
                        <?php if (!empty($signed_file['fileID']) && $memo_id > 0) : ?>
                            <a class="attachment-link" href="public/api/file-download.php?module=memos&entity_id=<?= h((string) $memo_id) ?>&file_id=<?= h((string) $signed_file['fileID']) ?>" target="_blank" rel="noopener">ดูไฟล์</a>
                            <a class="attachment-link" href="public/api/file-download.php?module=memos&entity_id=<?= h((string) $memo_id) ?>&file_id=<?= h((string) $signed_file['fileID']) ?>&download=1">ดาวน์โหลด</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($memo) : ?>
    <?php if ($is_editable) : ?>
        <section class="booking-card">
            <div class="booking-card-header">
                <div class="booking-card-title-group">
                    <h2 class="booking-card-title">แก้ไขบันทึกข้อความ (ร่าง)</h2>
                    <p class="booking-card-subtitle">แก้ไขได้เฉพาะสถานะ ร่าง/ตีกลับแก้ไข</p>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_draft">

                <?php component_render('input', [
                    'name' => 'writeDate',
                    'label' => 'วันที่',
                    'type' => 'date',
                    'value' => (string) ($memo['writeDate'] ?? ''),
                ]); ?>

                <?php
                $selected_to_choice = 'DIRECTOR';

        if ((string) ($memo['toType'] ?? '') === 'PERSON' && !empty($memo['toPID'])) {
            $selected_to_choice = 'PERSON:' . (string) ($memo['toPID'] ?? '');
        }
        ?>
                <?php component_render('select', [
            'name' => 'to_choice',
            'label' => 'เรียน (ผู้พิจารณา/ผู้ลงนาม)',
            'options' => $approver_options,
            'selected' => $selected_to_choice,
        ]); ?>

                <?php component_render('input', [
            'name' => 'subject',
            'label' => 'หัวข้อ',
            'value' => (string) ($memo['subject'] ?? ''),
            'required' => true,
        ]); ?>

                <?php component_render('textarea', [
            'name' => 'detail',
            'label' => 'รายละเอียด',
            'value' => (string) ($memo['detail'] ?? ''),
            'rows' => 6,
        ]); ?>

                <?php component_render('input', [
            'name' => 'attachments[]',
            'label' => 'แนบไฟล์เพิ่ม (สูงสุด 5 ไฟล์)',
            'type' => 'file',
            'attrs' => [
                'multiple' => true,
                'accept' => '.pdf,.jpg,.jpeg,.png,.zip,.rar,application/pdf,image/png,image/jpeg,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/x-rar,application/vnd.rar',
            ],
            'help' => 'รองรับ PDF / PNG / JPG / ZIP / RAR',
        ]); ?>

                <div class="booking-actions">
                    <?php component_render('button', [
                'label' => 'บันทึกการแก้ไข',
                'variant' => 'primary',
                'type' => 'submit',
            ]); ?>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($can_submit || $can_recall || $can_cancel || $can_archive) : ?>
        <section class="booking-card">
            <div class="booking-card-header">
                <div class="booking-card-title-group">
                    <h2 class="booking-card-title">การดำเนินการ (ผู้สร้าง)</h2>
                    <p class="booking-card-subtitle">สิทธิ์ตามสถานะเอกสาร</p>
                </div>
            </div>
            <div class="booking-actions">
                <?php if ($can_submit) : ?>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit">
                        <?php component_render('button', [
                    'label' => 'ส่งเสนอ',
                    'variant' => 'primary',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการส่งเสนอ? หลังส่งแล้วจะแก้ไขเนื้อหาไม่ได้จนกว่าจะถูกตีกลับ'],
                ]); ?>
                    </form>
                <?php endif; ?>

                <?php if ($can_recall) : ?>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="recall">
                        <?php component_render('button', [
                    'label' => 'ดึงกลับเพื่อแก้ไข',
                    'variant' => 'secondary',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการดึงกลับเพื่อแก้ไข?'],
                ]); ?>
                    </form>
                <?php endif; ?>

                <?php if ($can_cancel) : ?>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <?php component_render('button', [
                    'label' => 'ยกเลิก',
                    'variant' => 'danger',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการยกเลิก?'],
                ]); ?>
                    </form>
                <?php endif; ?>

                <?php if ($can_archive) : ?>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $is_archived ? 'unarchive' : 'archive' ?>">
                        <?php component_render('button', [
                    'label' => $is_archived ? 'นำออกจากที่จัดเก็บ' : 'จัดเก็บ',
                    'variant' => 'secondary',
                    'type' => 'submit',
                ]); ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($can_review || $can_sign_now || $can_forward_chain || $can_director_approve || $can_director_reject) : ?>
        <section class="booking-card">
            <div class="booking-card-header">
                <div class="booking-card-title-group">
                    <h2 class="booking-card-title">การพิจารณา/ลงนาม (ผู้พิจารณา)</h2>
                    <p class="booking-card-subtitle">ใส่ความเห็น และเลือกการดำเนินการ</p>
                </div>
            </div>

            <?php if ($can_forward_chain) : ?>
                <form method="post" action="<?= h($action_url) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="forward">
                    <?php component_render('textarea', [
                'id' => 'forward_note',
                'name' => 'note',
                'label' => 'ความเห็นก่อนส่งต่อ (ถ้ามี)',
                'value' => '',
                'rows' => 3,
            ]); ?>
                    <div class="booking-actions">
                        <?php component_render('button', [
                    'label' => $is_chain_head ? 'ส่งต่อรองผู้อำนวยการ' : 'ส่งต่อผู้อำนวยการ',
                    'variant' => 'primary',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการส่งต่อรายการ?'],
                ]); ?>
                    </div>
                </form>

                <div class="enterprise-divider"></div>

                <form method="post" action="<?= h($action_url) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="return">
                    <?php component_render('textarea', [
                'id' => 'return_note',
                'name' => 'note',
                'label' => 'ความเห็น (สำหรับตีกลับแก้ไข)',
                'value' => '',
                'rows' => 3,
                'required' => true,
            ]); ?>
                    <div class="booking-actions">
                        <?php component_render('button', [
                    'label' => 'ตีกลับแก้ไข',
                    'variant' => 'danger',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการตีกลับให้แก้ไข?'],
                ]); ?>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($can_director_approve || $can_director_reject) : ?>
                <?php if ($can_director_approve) : ?>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="director_approve">
                        <?php component_render('textarea', [
                    'id' => 'director_approve_note',
                    'name' => 'note',
                    'label' => 'ความเห็นผู้อำนวยการ (ถ้ามี)',
                    'value' => '',
                    'rows' => 3,
                ]); ?>
                        <div class="booking-actions">
                            <?php component_render('button', [
                        'label' => 'อนุมัติและปิดงาน',
                        'variant' => 'primary',
                        'type' => 'submit',
                        'attrs' => ['data-confirm' => 'ยืนยันการอนุมัติรายการนี้?'],
                    ]); ?>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($can_director_reject) : ?>
                    <div class="enterprise-divider"></div>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="director_reject">
                        <?php component_render('textarea', [
                    'id' => 'director_reject_note',
                    'name' => 'note',
                    'label' => 'เหตุผลไม่อนุมัติ',
                    'value' => '',
                    'rows' => 3,
                    'required' => true,
                ]); ?>
                        <div class="booking-actions">
                            <?php component_render('button', [
                        'label' => 'ไม่อนุมัติ',
                        'variant' => 'danger',
                        'type' => 'submit',
                        'attrs' => ['data-confirm' => 'ยืนยันการไม่อนุมัติรายการนี้?'],
                    ]); ?>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="enterprise-divider"></div>

                <form method="post" action="<?= h($action_url) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="return">
                    <?php component_render('textarea', [
                'id' => 'director_return_note',
                'name' => 'note',
                'label' => 'ตีกลับเพื่อแก้ไข',
                'value' => '',
                'rows' => 3,
                'required' => true,
            ]); ?>
                    <div class="booking-actions">
                        <?php component_render('button', [
                    'label' => 'ตีกลับแก้ไข',
                    'variant' => 'danger',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการตีกลับให้แก้ไข?'],
                ]); ?>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($can_reject || $can_approve_unsigned) : ?>
                <div class="enterprise-divider"></div>

                <?php if ($can_reject) : ?>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <?php component_render('textarea', [
                    'id' => 'reject_note',
                    'name' => 'note',
                    'label' => 'เหตุผล/ความเห็น (ไม่อนุมัติ)',
                    'value' => '',
                    'rows' => 3,
                    'required' => true,
                ]); ?>
                        <div class="booking-actions">
                            <?php component_render('button', [
                        'label' => 'ไม่อนุมัติ',
                        'variant' => 'danger',
                        'type' => 'submit',
                        'attrs' => ['data-confirm' => 'ยืนยันการไม่อนุมัติ?'],
                    ]); ?>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($can_approve_unsigned) : ?>
                    <div class="enterprise-divider"></div>
                    <form method="post" action="<?= h($action_url) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve_unsigned">
                        <?php component_render('textarea', [
                    'id' => 'approve_unsigned_note',
                    'name' => 'note',
                    'label' => 'ความเห็น (ลงนามแล้ว)',
                    'value' => '',
                    'rows' => 3,
                ]); ?>
                        <div class="booking-actions">
                            <?php component_render('button', [
                        'label' => 'ลงนามแล้ว',
                        'variant' => 'secondary',
                        'type' => 'submit',
                    ]); ?>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($can_sign_now) : ?>
                <div class="enterprise-divider"></div>
                <form method="post" enctype="multipart/form-data" action="<?= h($action_url) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="sign_upload">
                    <?php component_render('textarea', [
                'id' => 'sign_note',
                'name' => 'note',
                'label' => 'ความเห็น (ถ้ามี)',
                'value' => '',
                'rows' => 2,
            ]); ?>
                    <?php component_render('input', [
                'name' => 'signed_attachment',
                'label' => 'แนบไฟล์ฉบับลงนามแล้ว (PDF/PNG/JPG/ZIP/RAR)',
                'type' => 'file',
                'required' => true,
                'attrs' => [
                    'accept' => '.pdf,.jpg,.jpeg,.png,.zip,.rar,application/pdf,image/png,image/jpeg,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/x-rar,application/vnd.rar',
                ],
            ]); ?>
                    <div class="booking-actions">
                        <?php component_render('button', [
                    'label' => 'ลงนาม (อัปโหลดไฟล์)',
                    'variant' => 'primary',
                    'type' => 'submit',
                    'attrs' => ['data-confirm' => 'ยืนยันการลงนาม? เมื่อบันทึกแล้วจะแก้ไขไม่ได้'],
                ]); ?>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($routes)) : ?>
        <section class="enterprise-card">
            <div class="enterprise-card-header">
                <div class="enterprise-card-title-group">
                    <h2 class="enterprise-card-title">ประวัติการดำเนินการ</h2>
                    <p class="enterprise-card-subtitle">Audit Timeline</p>
                </div>
            </div>
            <div class="enterprise-panel">
                <div class="attachment-list">
                    <?php foreach ($routes as $route) : ?>
                        <div class="attachment-item">
                            <span class="attachment-name">
                                <?= h((string) ($route['createdAt'] ?? '')) ?>
                                • <?= h((string) ($route['actorName'] ?? $route['actorPID'] ?? '')) ?>
                                • <?= h((string) ($route['action'] ?? '')) ?>
                                <?php if (!empty($route['fromStatus']) || !empty($route['toStatus'])) : ?>
                                    (<?= h((string) ($route['fromStatus'] ?? '')) ?> → <?= h((string) ($route['toStatus'] ?? '')) ?>)
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($route['note'])) : ?>
                                <span class="attachment-name"><?= nl2br(h((string) ($route['note'] ?? ''))) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
