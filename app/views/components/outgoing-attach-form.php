<?php
$params = $params ?? [];
$outgoing_id = (int) ($params['outgoing_id'] ?? 0);
$enabled = (bool) ($params['enabled'] ?? false);
$locked = (bool) ($params['locked'] ?? false);
$extra_class = (string) ($params['class'] ?? '');
?>
<?php if ($enabled && $outgoing_id > 0) : ?>
    <form method="post" enctype="multipart/form-data" class="<?= h(trim('c-table__form booking-attach-form ' . $extra_class)) ?>">
        <?php if (function_exists('csrf_field')) : ?>
            <?= csrf_field() ?>
        <?php endif; ?>
        <input type="hidden" name="action" value="attach">
        <input type="hidden" name="outgoing_id" value="<?= h((string) $outgoing_id) ?>">
        <input type="file" name="attachments[]" class="form-input" multiple required accept=".pdf,.jpg,.jpeg,.png,.zip,.rar,application/pdf,image/png,image/jpeg,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/x-rar,application/vnd.rar">
        <button
            type="submit"
            class="c-button c-button--sm btn-outline"
            data-confirm="ยืนยันการแนบไฟล์ให้รายการนี้ใช่หรือไม่?"
            data-confirm-title="ยืนยันการแนบไฟล์"
            data-confirm-ok="ยืนยัน"
            data-confirm-cancel="ยกเลิก">แนบไฟล์ย้อนหลัง</button>
    </form>
<?php elseif ($locked) : ?>
    <span class="status-pill pending">เฉพาะสารบรรณ</span>
<?php else : ?>
    <span class="status-pill approved">แนบไฟล์แล้ว</span>
<?php endif; ?>
