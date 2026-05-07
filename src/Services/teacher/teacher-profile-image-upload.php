<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['profile_image_upload'])) {
    return;
}

require_once __DIR__ . '/../../../config/connection.php';
require_once __DIR__ . '/../../../app/modules/audit/logger.php';

$redirect_url = 'profile.php?tab=personal';

$set_profile_alert = static function (string $type, string $title, string $message = '') use ($redirect_url): void {
    $_SESSION['profile_alert'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'link' => $redirect_url,
    ];
};

$teacher_pid = (string) ($_SESSION['pID'] ?? '');

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    if (function_exists('audit_log')) {
        audit_log('security', 'CSRF_FAIL', 'DENY', 'teacher', $teacher_pid, 'profile_image');
    }
    $set_profile_alert('danger', 'ไม่สามารถยืนยันความปลอดภัย', 'กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

if ($teacher_pid === '') {
    header('Location: index.php', true, 302);
    exit();
}

if (empty($_FILES['profile_image'])) {
    $set_profile_alert('danger', 'อัปโหลดไม่สำเร็จ', 'กรุณาแนบรูปโปรไฟล์ก่อนบันทึก');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$profile_file = $_FILES['profile_image'];

if ($profile_file['error'] !== UPLOAD_ERR_OK) {
    $set_profile_alert('danger', 'อัปโหลดไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$max_profile_size = 20 * 1024 * 1024;

if ((int) $profile_file['size'] > $max_profile_size) {
    $set_profile_alert('warning', 'ไฟล์มีขนาดใหญ่เกินไป', 'รองรับไฟล์ขนาดไม่เกิน 20MB');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$profile_mime = '';

if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    if ($finfo) {
        $profile_mime = (string) $finfo->file($profile_file['tmp_name']);
    }
}

if ($profile_mime === '') {
    $profile_mime = (string) ($profile_file['type'] ?? '');
}

$allowed_mime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

if (!isset($allowed_mime[$profile_mime])) {
    $set_profile_alert('warning', 'รูปแบบไฟล์ไม่ถูกต้อง', 'รองรับเฉพาะไฟล์ .jpg และ .png');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$safe_pid = preg_replace('/\D+/', '', (string) $teacher_pid);

if ($safe_pid === '') {
    $set_profile_alert('danger', 'อัปโหลดไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$profile_dir = __DIR__ . '/../../../assets/img/profile/' . $safe_pid;

if (!is_dir($profile_dir) && !mkdir($profile_dir, 0755, true)) {
    error_log('Profile directory create failed: ' . $profile_dir);
    $set_profile_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกรูปโปรไฟล์ได้ในขณะนี้');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$profile_filename = 'profile_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed_mime[$profile_mime];
$profile_target = $profile_dir . '/' . $profile_filename;

if (!move_uploaded_file($profile_file['tmp_name'], $profile_target)) {
    if (function_exists('audit_log')) {
        audit_log('profile', 'PROFILE_IMAGE_UPDATE', 'FAIL', 'teacher', $teacher_pid, 'move_failed');
    }
    $set_profile_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกรูปโปรไฟล์ได้ในขณะนี้');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

@chmod($profile_target, 0644);

$profile_path = 'assets/img/profile/' . $safe_pid . '/' . $profile_filename;

$update_sql = 'UPDATE teacher SET picture = ? WHERE pID = ? AND status = 1';
$update_stmt = mysqli_prepare($connection, $update_sql);

if ($update_stmt === false) {
    error_log('Database Error: ' . mysqli_error($connection));

    if (function_exists('audit_log')) {
        audit_log('profile', 'PROFILE_IMAGE_UPDATE', 'FAIL', 'teacher', $teacher_pid, 'prepare_failed');
    }
    $set_profile_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกรูปโปรไฟล์ได้ในขณะนี้');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

mysqli_stmt_bind_param($update_stmt, 'ss', $profile_path, $teacher_pid);

if (mysqli_stmt_execute($update_stmt) === false) {
    mysqli_stmt_close($update_stmt);

    if (function_exists('audit_log')) {
        audit_log('profile', 'PROFILE_IMAGE_UPDATE', 'FAIL', 'teacher', $teacher_pid, 'execute_failed');
    }
    $set_profile_alert('danger', 'ระบบขัดข้อง', 'ไม่สามารถบันทึกรูปโปรไฟล์ได้ในขณะนี้');
    header('Location: ' . $redirect_url, true, 303);
    exit();
}
mysqli_stmt_close($update_stmt);

if (function_exists('audit_log')) {
    audit_log('profile', 'PROFILE_IMAGE_UPDATE', 'SUCCESS', 'teacher', $teacher_pid, null, [
        'path' => $profile_path,
    ]);
}

$set_profile_alert('success', 'บันทึกสำเร็จ', 'บันทึกรูปโปรไฟล์เรียบร้อยแล้ว');
header('Location: ' . $redirect_url, true, 303);
exit();
