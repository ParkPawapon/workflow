<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$values = (array) ($values ?? []);
$values = array_merge([
    'subject' => '',
    'detail' => '',
], $values);

ob_start();
?>
<div class="content-header">
    <h1>ส่งหนังสือออกภายนอก</h1>
    <p>ออกเลขหนังสือ</p>
</div>

<section class="booking-card booking-form-card">
    <div class="booking-card-header">
        <div class="booking-card-title-group">
            <h2 class="booking-card-title">ออกเลขหนังสือ</h2>
            <p class="booking-card-subtitle">จองเลขหนังสือและแนบเอกสาร</p>
        </div>
    </div>

    <form class="booking-form" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="booking-form-grid">
            <?php component_render('input', [
                'name' => 'subject',
                'label' => 'หัวข้อ',
                'value' => $values['subject'],
                'required' => true,
            ]); ?>
            <?php component_render('textarea', [
                'name' => 'detail',
                'label' => 'รายละเอียด',
                'value' => $values['detail'],
                'rows' => 5,
                'class' => 'booking-textarea',
                'field_class' => 'full',
            ]); ?>
            <?php component_render('input', [
                'name' => 'attachments[]',
                'label' => 'แนบไฟล์ (สูงสุด 5 ไฟล์)',
                'type' => 'file',
                'field_class' => 'full',
                'attrs' => [
                    'multiple' => true,
                    'accept' => '.pdf,.jpg,.jpeg,.png,.zip,.rar,application/pdf,image/png,image/jpeg,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/x-rar,application/vnd.rar',
                ],
            ]); ?>
            <div class="c-field form-group full">
                <div class="booking-actions">
                    <?php component_render('button', [
                        'label' => 'ออกเลขหนังสือ',
                        'variant' => 'primary',
                        'type' => 'submit',
                        'attrs' => [
                            'data-confirm' => 'ยืนยันการออกเลขหนังสือภายนอกใช่หรือไม่?',
                            'data-confirm-title' => 'ยืนยันการบันทึก',
                            'data-confirm-ok' => 'ยืนยัน',
                            'data-confirm-cancel' => 'ยกเลิก',
                        ],
                    ]); ?>
                    <?php component_render('button', [
                        'label' => 'กลับรายการ',
                        'variant' => 'secondary',
                        'href' => 'outgoing.php',
                    ]); ?>
                </div>
            </div>
        </div>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
