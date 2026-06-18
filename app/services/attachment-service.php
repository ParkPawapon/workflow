<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db/db.php';

if (!function_exists('attachment_allowed_types')) {
    function attachment_allowed_types(): array
    {
        return [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'application/zip' => ['zip'],
            'application/x-zip-compressed' => ['zip'],
            'application/x-rar-compressed' => ['rar'],
            'application/x-rar' => ['rar'],
            'application/vnd.rar' => ['rar'],
        ];
    }
}

if (!function_exists('attachment_store')) {
    function attachment_store(array $file, string $category, string $owner_pid): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, null, 'ไม่สามารถอัปโหลดไฟล์ได้'];
        }

        $max_size = (int) app_env('UPLOAD_MAX_BYTES', 100 * 1024 * 1024);

        if (($file['size'] ?? 0) > $max_size) {
            return [false, null, 'ไฟล์มีขนาดใหญ่เกินกำหนด'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = attachment_allowed_types();
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if ($mime === 'application/octet-stream' && in_array($ext, ['zip', 'rar'], true)) {
            $mime = $ext === 'zip' ? 'application/zip' : 'application/vnd.rar';
        }

        if (!isset($allowed[$mime])) {
            return [false, null, 'ประเภทไฟล์ไม่ถูกอนุญาต'];
        }

        if (!in_array($ext, $allowed[$mime], true)) {
            return [false, null, 'นามสกุลไฟล์ไม่ตรงกับประเภทที่อนุญาต'];
        }

        $storage_root = rtrim((string) app_env('UPLOAD_ROOT', __DIR__ . '/../../storage/uploads'), '/');
        $category = preg_replace('/[^a-zA-Z0-9_-]/', '', $category);
        $target_dir = $storage_root . '/' . $category;

        if (!is_dir($target_dir) && !mkdir($target_dir, 0750, true) && !is_dir($target_dir)) {
            return [false, null, 'ไม่สามารถสร้างโฟลเดอร์เก็บไฟล์'];
        }

        $random = bin2hex(random_bytes(16));
        $filename = $random . '.' . $ext;
        $target = $target_dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return [false, null, 'ไม่สามารถบันทึกไฟล์ได้'];
        }

        $checksum = hash_file('sha256', $target) ?: null;

        // Placeholder for antivirus scanning hook
        // app_av_scan($target);

        return [true, [
            'path' => $target,
            'filename' => $filename,
            'mime' => $mime,
            'size' => (int) ($file['size'] ?? 0),
            'checksum' => $checksum,
            'owner_pid' => $owner_pid,
        ], null];
    }
}

if (!function_exists('attachment_save')) {
    function attachment_save(
        array $file,
        string $category,
        string $owner_pid,
        string $module,
        string $entity_name,
        string $entity_id,
        ?string $note = null
    ): array {
        [$ok, $meta, $error] = attachment_store($file, $category, $owner_pid);

        if (!$ok || $meta === null) {
            return [false, null, $error];
        }

        $connection = db_connection();

        if (!db_table_exists($connection, 'dh_files') || !db_table_exists($connection, 'dh_file_refs')) {
            return [true, $meta, null];
        }

        $stmt = db_query(
            'INSERT INTO dh_files (fileName, filePath, mimeType, fileSize, checksumSHA256, storageProvider, uploadedByPID)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'sssisss',
            $meta['filename'],
            $meta['path'],
            $meta['mime'],
            $meta['size'],
            $meta['checksum'],
            'local',
            $owner_pid
        );
        $file_id = db_last_insert_id();
        mysqli_stmt_close($stmt);

        $ref_stmt = db_query(
            'INSERT INTO dh_file_refs (fileID, moduleName, entityName, entityID, note, attachedByPID)
             VALUES (?, ?, ?, ?, ?, ?)',
            'isssss',
            $file_id,
            $module,
            $entity_name,
            $entity_id,
            $note,
            $owner_pid
        );
        mysqli_stmt_close($ref_stmt);

        $meta['file_id'] = $file_id;

        return [true, $meta, null];
    }
}
