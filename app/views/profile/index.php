<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../auth/csrf.php';

$teacher = $teacher ?? [];
$active_tab = $active_tab ?? 'personal';
$profile_picture = $profile_picture ?? '';
$signature_path = $signature_path ?? '';

ob_start();
?>
<div class="content-header">
    <h1>ยินดีต้อนรับ</h1>
    <p>โปรไฟล์</p>
</div>

<div class="profile-header-banner">
    <img src="assets/img/db-background.png" alt="db-background">
</div>

<div class="profile-header">
    <div class="profile-pic-wrapper" data-action="profile-image-open">
        <div class="profile-pic" id="mainProfilePic" <?= $profile_picture !== '' ? ' style="background-image: url(\'' . h($profile_picture) . '\');"' : '' ?>>
            <div class="profile-overlay" data-action="profile-image-file-open">
                <i class="fa-solid fa-camera"></i>
            </div>
        </div>
    </div>
    <div class="user-name"><?= h($teacher['fName'] ?? '') ?></div>
</div>

<div class="modal-overlay hidden" id="imageModal">
    <div class="modal-content upload-modal">
        <form class="modal-body upload-body" id="profileImageForm" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? '') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="profile_image_upload" value="1">

            <input type="hidden" name="cropped_image_data" id="croppedImageData">

            <div class="preview-container" id="previewContainer" data-action="profile-image-file-open">
                <img id="imagePreview" src="#" alt="Preview" class="hidden" style="max-width: 100%; display: block;">
                <p id="previewPlaceholder">เลือกรูปภาพ</p>
            </div>

            <input type="file" id="profileFileInput" name="profile_image" hidden accept="image/png, image/jpeg">
            <div class="file-hint">รองรับไฟล์นามสกุล .JPG , .PNG เท่านั้น ขนาดไม่เกิน 20MB</div>

            <div class="modal-button-content upload-actions">
                <button type="button" class="btn-confirm" id="btnConfirmCrop">ยืนยัน</button>
                <button type="button" class="btn-confirm btn-cancel" data-action="profile-image-cancel">ยกเลิก</button>
            </div>
        </form>

    </div>
</div>

<div class="tabs-container">
    <div class="button-container">
        <button class="tab-btn<?= $active_tab === 'personal' ? ' active' : '' ?>" data-tab-target="personal">ข้อมูลส่วนบุคคล</button>
        <button class="tab-btn<?= $active_tab === 'signature' ? ' active' : '' ?>" data-tab-target="signature">ลายเซ็น</button>
        <button class="tab-btn<?= $active_tab === 'password' ? ' active' : '' ?>" data-tab-target="password">เปลี่ยนรหัสผ่าน</button>
    </div>
</div>

<div class="content-area">
    <div id="personal" class="tab-content<?= $active_tab === 'personal' ? ' active' : '' ?>">
        <form class="phone-form" id="phoneForm" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? '') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="phone_save" value="1">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">กลุ่มสาระฯ :</div>
                    <input class="info-value" value="<?= h($teacher['department_name'] ?? '') ?>" disabled />
                </div>
                <div class="info-item">
                    <div class="info-label">หน้าที่ :</div>
                    <input class="info-value" value="<?= h($teacher['role_name'] ?? '') ?>" disabled />
                </div>
                <div class="info-item">
                    <div class="info-label">ตำแหน่ง :</div>
                    <input class="info-value" value="<?= h($teacher['position_name'] ?? '') ?>" disabled />
                </div>
                <div class="info-item">
                    <div class="info-label">วิทยฐานะ :</div>
                    <input class="info-value" value="<?= h($teacher['level_name'] ?? '') ?>" disabled />
                </div>
                <div class="info-item">
                    <div class="info-label">กลุ่มงาน/ฝ่าย :</div>
                    <input class="info-value" value="<?= h($teacher['faction_name'] ?? '') ?>" disabled />
                </div>
                <div class="info-item">
                    <div class="info-label">เบอร์โทรศัพท์ :</div>
                    <input class="tel-info-value" type="text" id="pt" name="telephone" value="<?= h($teacher['telephone'] ?? '') ?>" placeholder="กรอกเบอร์โทรศัพท์" inputmode="numeric" pattern="\d{10}" maxlength="10" />
                    <!-- <input class="tel-info-value" type="text" id="phoneInput" name="telephone" value="<?= h($teacher['telephone'] ?? '') ?>" placeholder="กรอกเบอร์โทรศัพท์" inputmode="numeric" pattern="\d{10}" maxlength="10" /> -->
                </div>
            </div>
            <div class="profile-footer">
                <div class="warning-text">* หากข้อมูลส่วนบุคคลผิดพลาด กรุณาติดต่อผู้ดูแลระบบ *</div>
                <button
                    type="submit"
                    class="btn-upload"
                    data-confirm="ยืนยันการบันทึกข้อมูลโปรไฟล์ใช่หรือไม่?"
                    data-confirm-title="ยืนยันการบันทึก"
                    data-confirm-ok="ยืนยัน"
                    data-confirm-cancel="ยกเลิก">บันทึก</button>
            </div>
        </form>
    </div>

    <div class="tel-modal" id="confirmModal">
        <div class="modal-content">
            <p><?= empty($teacher['telephone']) ? 'ต้องการเพิ่มหมายเลขโทรศัพท์ใช่หรือไม่' : 'ต้องการเปลี่ยนหมายเลขโทรศัพท์ใช่หรือไม่' ?></p>
            <p id="showPhone"></p>
            <div class="modal-button-content">
                <button type="button" id="confirmBtn">ยืนยัน</button>
                <button type="button" class="cancel" id="cancelBtn">ยกเลิก</button>
            </div>
        </div>
    </div>

    <div id="signature" class="tab-content<?= $active_tab === 'signature' ? ' active' : '' ?>">
        <div class="signature-content">
            <div class="signature-box" id="mainSignatureBox">
                <img id="mainSignatureImg" src="<?= h($signature_path) ?>" alt="Signature" class="<?= $signature_path !== '' ? '' : 'hidden' ?>">
                <p id="noSignatureText" <?= $signature_path !== '' ? ' style="display:none;"' : '' ?>>ไม่มีลายเซ็นในระบบ</p>
            </div>
            <div class="signature-field">
                <!-- <button class="btn-upload" data-action="signature-file-open">
                    <?= $signature_path !== '' ? 'เปลี่ยนลายเซ็น' : 'แนบลายเซ็น' ?>
                </button>
                <div class="file-hint">รองรับไฟล์นามสกุล .JPG , .PNG เท่านั้น ขนาดไม่เกิน 2MB</div> -->
            </div>
        </div>
    </div>

    <!-- <div class="modal-overlay hidden" id="signatureModal">
        <div class="modal-content upload-modal">
            <form class="modal-body upload-body" id="signatureUploadForm" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? '') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="signature_upload" value="1">
                <div class="preview-container rectangle-preview" id="signaturePreviewContainer">
                    <img id="signaturePreview" src="#" alt="Signature Preview" class="hidden">
                </div>

                <input type="file" id="signatureFileInput" name="signature_file" hidden accept="image/png, image/jpeg" required>

                <div class="modal-button-content upload-actions">
                    <button type="submit" class="btn-confirm">ยืนยัน</button>
                    <button type="button" class="btn-confirm btn-cancel" data-action="signature-cancel">ยกเลิก</button>
                </div>
            </form>
        </div>recipientModal
    </div> -->

    <div id="password" class="tab-content<?= $active_tab === 'password' ? ' active' : '' ?>">
        <form class="password-form" method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? '') ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">รหัสผ่านเดิม</label>
                <input type="password" class="form-input" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label class="form-label">รหัสผ่านใหม่</label>
                <input type="password" class="form-input" name="new_password" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label class="form-label">ยืนยันรหัสผ่าน</label>
                <input type="password" class="form-input" name="confirm_password" autocomplete="new-password" required>
            </div>
            <button
                type="submit"
                class="btn-confirm"
                name="change_password"
                value="1"
                data-confirm="ยืนยันการเปลี่ยนรหัสผ่านใช่หรือไม่?"
                data-confirm-title="ยืนยันการบันทึก"
                data-confirm-ok="ยืนยัน"
                data-confirm-cancel="ยกเลิก">ยืนยัน</button>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
