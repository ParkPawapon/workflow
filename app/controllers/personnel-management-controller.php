<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/audit/logger.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db/db.php';

if (!function_exists('personnel_management_defaults')) {
    function personnel_management_defaults(): array
    {
        return [
            'original_pid' => '',
            'pID' => '',
            'fName' => '',
            'fID' => 0,
            'dID' => 0,
            'lID' => 0,
            'oID' => 0,
            'positionID' => 0,
            'roleIDs' => [6],
            'telephone' => '',
            'picture' => '',
            'signature' => '',
            'passWord' => '',
            'LineID' => '',
            'status' => 1,
        ];
    }
}

if (!function_exists('personnel_management_option_rows')) {
    function personnel_management_option_rows(string $table, string $id_column, string $name_column): array
    {
        $connection = db_connection();

        if (!db_table_exists($connection, $table)) {
            return [];
        }

        return db_fetch_all(
            'SELECT ' . $id_column . ' AS id, ' . $name_column . ' AS name FROM ' . $table . ' ORDER BY ' . $id_column . ' ASC'
        );
    }
}

if (!function_exists('personnel_management_position_option_rows')) {
    function personnel_management_position_option_rows(): array
    {
        $connection = db_connection();

        if (!db_table_exists($connection, 'dh_positions')) {
            return [];
        }

        return db_fetch_all(
            'SELECT positionID AS id, positionName AS name
             FROM dh_positions
             ORDER BY FIELD(positionID, 1, 9, 2, 3, 4, 5, 6, 7, 8), positionID ASC'
        );
    }
}

if (!function_exists('personnel_management_option_map')) {
    function personnel_management_option_map(array $rows): array
    {
        $map = [0 => 'ไม่กำหนด'];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));

            if ($id <= 0 || $name === '') {
                continue;
            }

            $map[$id] = $name;
        }

        return $map;
    }
}

if (!function_exists('personnel_management_normalize_select_id')) {
    function personnel_management_normalize_select_id(mixed $value, array $allowed_map): int
    {
        $id = (int) $value;

        if ($id === 0) {
            return 0;
        }

        return array_key_exists($id, $allowed_map) ? $id : 0;
    }
}

if (!function_exists('personnel_management_normalize_form')) {
    function personnel_management_normalize_form(array $input, array $option_maps, bool $is_create, int $default_role_id): array
    {
        $defaults = personnel_management_defaults();
        $pid = trim((string) ($input['pID'] ?? ''));
        $telephone = preg_replace('/\D+/', '', (string) ($input['telephone'] ?? ''));
        $role_ids = rbac_parse_role_ids($input['role_ids'] ?? []);

        if ($role_ids === []) {
            $role_ids = [$default_role_id];
        }

        return [
            'original_pid' => $is_create ? '' : trim((string) ($input['original_pid'] ?? '')),
            'pID' => trim((string) $pid),
            'fName' => trim((string) ($input['fName'] ?? '')),
            'fID' => personnel_management_normalize_select_id($input['fID'] ?? 0, (array) ($option_maps['faction'] ?? [])),
            'dID' => personnel_management_normalize_select_id($input['dID'] ?? 0, (array) ($option_maps['department'] ?? [])),
            'lID' => personnel_management_normalize_select_id($input['lID'] ?? 0, (array) ($option_maps['level'] ?? [])),
            'oID' => personnel_management_normalize_select_id($input['oID'] ?? 0, (array) ($option_maps['legacy_position'] ?? [])),
            'positionID' => personnel_management_normalize_select_id($input['positionID'] ?? 0, (array) ($option_maps['position'] ?? [])),
            'roleIDs' => $role_ids,
            'telephone' => trim((string) $telephone),
            'picture' => trim((string) ($input['picture'] ?? '')),
            'signature' => trim((string) ($input['signature'] ?? '')),
            'passWord' => trim((string) ($input['passWord'] ?? '')),
            'LineID' => trim((string) ($input['LineID'] ?? '')),
            'status' => (int) ($input['status'] ?? $defaults['status']) === 0 ? 0 : 1,
        ];
    }
}

if (!function_exists('personnel_management_validate_form')) {
    function personnel_management_validate_form(array $data, bool $is_create): ?array
    {
        if ($data['pID'] === '') {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ถูกต้อง',
                'message' => 'กรุณากรอกรหัสประจำตัวบุคลากร',
            ];
        }

        if (!$is_create && trim((string) ($data['original_pid'] ?? '')) === '') {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ถูกต้อง',
                'message' => 'ไม่พบข้อมูลบุคลากรที่ต้องการแก้ไข',
            ];
        }

        if (!$is_create && $data['pID'] !== trim((string) ($data['original_pid'] ?? ''))) {
            return [
                'type' => 'danger',
                'title' => 'ไม่สามารถแก้ไขรหัสบุคลากรได้',
                'message' => 'รหัสบัตรประชาชนเป็นข้อมูลอ้างอิงหลักของระบบ กรุณาใช้รายการใหม่หากต้องการเปลี่ยนรหัส',
            ];
        }

        if ($data['fName'] === '') {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ครบถ้วน',
                'message' => 'กรุณากรอกชื่อ-นามสกุล',
            ];
        }

        if ($data['telephone'] !== '' && strlen($data['telephone']) > 10) {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ถูกต้อง',
                'message' => 'เบอร์โทรศัพท์ต้องเป็นตัวเลขไม่เกิน 10 หลัก หรือเว้นว่างไว้',
            ];
        }

        if ($is_create && $data['passWord'] === '') {
            return [
                'type' => 'danger',
                'title' => 'ข้อมูลไม่ครบถ้วน',
                'message' => 'กรุณากำหนดรหัสผ่านสำหรับบุคลากรใหม่',
            ];
        }

        return null;
    }
}

if (!function_exists('personnel_management_sanitize_log_payload')) {
    function personnel_management_sanitize_log_payload(array $data): array
    {
        $payload = $data;
        unset($payload['passWord']);

        if (isset($payload['picture'])) {
            $payload['hasPicture'] = trim((string) $payload['picture']) !== '';
            unset($payload['picture']);
        }

        if (isset($payload['signature'])) {
            $payload['hasSignature'] = trim((string) $payload['signature']) !== '';
            unset($payload['signature']);
        }

        return $payload;
    }
}

if (!function_exists('personnel_management_uploaded_file')) {
    function personnel_management_uploaded_file(array $field_names): ?array
    {
        foreach ($field_names as $field_name) {
            $field_name = trim((string) $field_name);

            if ($field_name === '' || !isset($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
                continue;
            }

            $file = $_FILES[$field_name];
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            return $file;
        }

        return null;
    }
}

if (!function_exists('personnel_management_upload_error_message')) {
    function personnel_management_upload_error_message(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ไฟล์มีขนาดใหญ่เกินกว่าที่ระบบกำหนด',
            UPLOAD_ERR_PARTIAL => 'อัปโหลดไฟล์ไม่สมบูรณ์ กรุณาลองใหม่อีกครั้ง',
            UPLOAD_ERR_NO_TMP_DIR => 'ระบบไม่พบพื้นที่พักไฟล์อัปโหลด',
            UPLOAD_ERR_CANT_WRITE => 'ระบบไม่สามารถเขียนไฟล์อัปโหลดได้',
            UPLOAD_ERR_EXTENSION => 'อัปโหลดไฟล์ถูกหยุดโดยส่วนขยายของระบบ',
            default => 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
        };
    }
}

if (!function_exists('personnel_management_store_image_upload')) {
    function personnel_management_store_image_upload(array $field_names, string $pid, string $asset_type, array &$stored_files): ?string
    {
        $file = personnel_management_uploaded_file($field_names);

        if ($file === null) {
            return null;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(personnel_management_upload_error_message($error));
        }

        $safe_pid = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($pid));
        $safe_pid = trim((string) $safe_pid, '_');

        if ($safe_pid === '') {
            $safe_pid = substr(hash('sha256', trim($pid)), 0, 16);
        }

        $allowed_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        $max_size = 2 * 1024 * 1024;
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $max_size) {
            throw new RuntimeException('รองรับไฟล์รูปภาพขนาดไม่เกิน 2MB');
        }

        $tmp_name = (string) ($file['tmp_name'] ?? '');

        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            throw new RuntimeException('ไม่พบไฟล์อัปโหลดที่ถูกต้อง');
        }

        $mime_type = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = (string) $finfo->file($tmp_name);
        }

        if ($mime_type === '') {
            $mime_type = (string) ($file['type'] ?? '');
        }

        if (!isset($allowed_types[$mime_type])) {
            throw new RuntimeException('รองรับเฉพาะไฟล์ .jpg และ .png');
        }

        $base_dir = dirname(__DIR__, 2) . '/assets/img';
        $sub_dir = $asset_type === 'profile' ? 'profile' : 'signature';
        $target_dir = $base_dir . '/' . $sub_dir . '/' . $safe_pid;

        if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
            throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์จัดเก็บไฟล์ได้');
        }

        $filename_prefix = $asset_type === 'profile' ? 'profile' : 'signature';
        $filename = $filename_prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed_types[$mime_type];
        $target_path = $target_dir . '/' . $filename;

        if (!move_uploaded_file($tmp_name, $target_path)) {
            throw new RuntimeException('ไม่สามารถบันทึกไฟล์อัปโหลดได้');
        }

        @chmod($target_path, 0644);
        $stored_files[] = $target_path;

        return 'assets/img/' . $sub_dir . '/' . $safe_pid . '/' . $filename;
    }
}

if (!function_exists('personnel_management_cleanup_uploaded_files')) {
    function personnel_management_cleanup_uploaded_files(array $stored_files): void
    {
        foreach ($stored_files as $path) {
            $path = (string) $path;

            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}

if (!function_exists('personnel_management_existing_assets')) {
    function personnel_management_existing_assets(string $pid): array
    {
        $row = db_fetch_one(
            'SELECT picture, signature FROM teacher WHERE pID = ? LIMIT 1',
            's',
            $pid
        );

        if (!$row) {
            return [
                'picture' => '',
                'signature' => '',
            ];
        }

        return [
            'picture' => trim((string) ($row['picture'] ?? '')),
            'signature' => trim((string) ($row['signature'] ?? '')),
        ];
    }
}

if (!function_exists('personnel_management_fetch_rows')) {
    function personnel_management_fetch_rows(): array
    {
        $connection = db_connection();
        $role_name_select = rbac_role_names_select('t') . ' AS roleName';

        return db_fetch_all(
            'SELECT t.pID, t.fName, t.fID, t.dID, t.lID, t.oID, t.positionID, t.roleID,
                    t.telephone, t.picture, t.signature, t.LineID, t.status,
                    COALESCE(f.fName, "") AS factionName,
                    COALESCE(d.dName, "") AS departmentName,
                    COALESCE(l.lName, "") AS levelName,
                    COALESCE(op.oName, "") AS legacyPositionName,
                    COALESCE(dp.positionName, "") AS systemPositionName,
                    ' . $role_name_select . '
             FROM teacher AS t
             LEFT JOIN faction AS f ON f.fID = t.fID
             LEFT JOIN department AS d ON d.dID = t.dID
             LEFT JOIN level AS l ON l.lID = t.lID
             LEFT JOIN position AS op ON op.oID = t.oID
             LEFT JOIN dh_positions AS dp ON dp.positionID = t.positionID
             ORDER BY t.status DESC, t.fName ASC, t.pID ASC'
        );
    }
}

if (!function_exists('personnel_management_insert')) {
    function personnel_management_insert(mysqli $connection, array $data): void
    {
        $exists = db_fetch_one('SELECT pID FROM teacher WHERE pID = ? LIMIT 1', 's', $data['pID']);

        if ($exists) {
            throw new RuntimeException('รหัสบัตรประชาชนนี้มีอยู่ในระบบแล้ว');
        }

        db_execute(
            'INSERT INTO teacher (pID, fName, fID, dID, lID, oID, positionID, roleID, telephone, picture, signature, passWord, LineID, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'ssiiiiissssssi',
            $data['pID'],
            $data['fName'],
            $data['fID'],
            $data['dID'],
            $data['lID'],
            $data['oID'],
            $data['positionID'],
            rbac_format_role_ids($data['roleIDs']),
            $data['telephone'],
            $data['picture'],
            $data['signature'] !== '' ? $data['signature'] : null,
            $data['passWord'],
            $data['LineID'],
            $data['status']
        );
    }
}

if (!function_exists('personnel_management_update')) {
    function personnel_management_update(mysqli $connection, array $data): void
    {
        $original_pid = trim((string) ($data['original_pid'] ?? ''));
        $existing = db_fetch_one('SELECT pID FROM teacher WHERE pID = ? LIMIT 1', 's', $original_pid);

        if (!$existing) {
            throw new RuntimeException('ไม่พบบุคลากรที่ต้องการแก้ไข');
        }

        $params = [
            $data['fName'],
            $data['fID'],
            $data['dID'],
            $data['lID'],
            $data['oID'],
            $data['positionID'],
            rbac_format_role_ids($data['roleIDs']),
            $data['telephone'],
            $data['picture'],
            $data['signature'] !== '' ? $data['signature'] : null,
            $data['status'],
        ];
        $types = 'siiiiissssi';
        $sql = 'UPDATE teacher
                SET fName = ?, fID = ?, dID = ?, lID = ?, oID = ?, positionID = ?, roleID = ?,
                    telephone = ?, picture = ?, signature = ?, status = ?';

        $sql .= ' WHERE pID = ?';
        $params[] = $original_pid;
        $types .= 's';

        db_execute($sql, $types, ...$params);
    }
}

if (!function_exists('personnel_management_index')) {
    function personnel_management_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = trim((string) ($current_user['pID'] ?? ''));
        $connection = db_connection();
        $is_admin = rbac_user_has_role($connection, $current_pid, ROLE_ADMIN)
            || in_array(1, rbac_parse_role_ids($current_user['roleID'] ?? ''), true);

        if (!$is_admin) {
            audit_log('personnel', 'ACCESS', 'DENY', null, null, 'personnel_management_access_denied');
            header('Location: dashboard.php', true, 302);
            exit();
        }

        $faction_rows = personnel_management_option_rows('faction', 'fID', 'fName');
        $department_rows = personnel_management_option_rows('department', 'dID', 'dName');
        $level_rows = personnel_management_option_rows('level', 'lID', 'lName');
        $legacy_position_rows = personnel_management_option_rows('position', 'oID', 'oName');
        $position_rows = personnel_management_position_option_rows();
        $role_rows = personnel_management_option_rows('dh_roles', 'roleID', 'roleName');

        $option_maps = [
            'faction' => personnel_management_option_map($faction_rows),
            'department' => personnel_management_option_map($department_rows),
            'level' => personnel_management_option_map($level_rows),
            'legacy_position' => personnel_management_option_map($legacy_position_rows),
            'position' => personnel_management_option_map($position_rows),
        ];

        $default_role_ids = rbac_resolve_role_ids($connection, ROLE_GENERAL);
        $default_role_id = (int) ($default_role_ids[0] ?? 6);

        $alert = $_SESSION['personnel_management_alert'] ?? null;
        unset($_SESSION['personnel_management_alert']);
        $open_modal = (string) ($_SESSION['personnel_management_open_modal'] ?? '');
        unset($_SESSION['personnel_management_open_modal']);
        $form_values = (array) ($_SESSION['personnel_management_form'] ?? []);
        unset($_SESSION['personnel_management_form']);
        $edit_values = (array) ($_SESSION['personnel_management_edit'] ?? []);
        unset($_SESSION['personnel_management_edit']);

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            audit_log('personnel', 'ACCESS', 'SUCCESS', 'page', 'personnel-management');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $redirect_url = 'personnel-management.php';
            $set_alert = static function (array $alert_payload, string $modal_key = '', array $values = []) use ($redirect_url): void {
                $_SESSION['personnel_management_alert'] = $alert_payload;

                if ($modal_key !== '') {
                    $_SESSION['personnel_management_open_modal'] = $modal_key;
                }

                if ($modal_key === 'personnelAddModal') {
                    $_SESSION['personnel_management_form'] = $values;
                } elseif ($modal_key === 'personnelEditModal') {
                    $_SESSION['personnel_management_edit'] = $values;
                }

                header('Location: ' . $redirect_url, true, 303);
                exit();
            };

            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                audit_log('personnel', 'CSRF_FAIL', 'DENY', null, null, 'personnel_management');
                $set_alert([
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ]);
            }

            $action = trim((string) ($_POST['personnel_action'] ?? ''));
            $is_create = $action === 'create';
            $is_update = $action === 'update';

            if (!$is_create && !$is_update) {
                audit_log('personnel', 'MANAGE', 'FAIL', 'teacher', null, 'invalid_personnel_action', [
                    'personnel_action' => $action,
                ]);
                $set_alert([
                    'type' => 'danger',
                    'title' => 'คำสั่งไม่ถูกต้อง',
                    'message' => 'ไม่พบคำสั่งที่ต้องการ',
                ]);
            }

            $normalized = personnel_management_normalize_form($_POST, $option_maps, $is_create, $default_role_id);
            $validation_alert = personnel_management_validate_form($normalized, $is_create);

            if ($validation_alert !== null) {
                audit_log('personnel', $is_create ? 'CREATE' : 'UPDATE', 'FAIL', 'teacher', $normalized['pID'] !== '' ? $normalized['pID'] : null, $validation_alert['message'], [
                    'form' => personnel_management_sanitize_log_payload($normalized),
                ]);
                $set_alert($validation_alert, $is_create ? 'personnelAddModal' : 'personnelEditModal', $normalized);
            }

            $stored_files = [];
            $transaction_started = false;

            try {
                db_begin();
                $transaction_started = true;

                $existing_assets = $is_update
                    ? personnel_management_existing_assets(trim((string) ($normalized['original_pid'] ?? '')))
                    : [
                        'picture' => trim((string) ($normalized['picture'] ?? '')),
                        'signature' => trim((string) ($normalized['signature'] ?? '')),
                    ];

                $uploaded_picture = personnel_management_store_image_upload(
                    ['picture_file', 'profile_image', 'profile_file'],
                    $normalized['pID'],
                    'profile',
                    $stored_files
                );
                $uploaded_signature = personnel_management_store_image_upload(
                    ['signature_file'],
                    $normalized['pID'],
                    'signature',
                    $stored_files
                );

                $normalized['picture'] = $uploaded_picture
                    ?? ($is_update ? $existing_assets['picture'] : trim((string) ($normalized['picture'] ?? '')));
                $normalized['signature'] = $uploaded_signature
                    ?? ($is_update ? $existing_assets['signature'] : trim((string) ($normalized['signature'] ?? '')));

                if ($is_create) {
                    personnel_management_insert($connection, $normalized);
                } else {
                    personnel_management_update($connection, $normalized);
                }

                db_commit();
                $transaction_started = false;

                audit_log('personnel', $is_create ? 'CREATE' : 'UPDATE', 'SUCCESS', 'teacher', $normalized['pID'], null, [
                    'status' => $normalized['status'],
                    'roles' => rbac_format_role_ids($normalized['roleIDs']),
                    'uploadedPicture' => $uploaded_picture !== null,
                    'uploadedSignature' => $uploaded_signature !== null,
                ]);

                $set_alert([
                    'type' => 'success',
                    'title' => $is_create ? 'เพิ่มบุคลากรสำเร็จ' : 'บันทึกข้อมูลบุคลากรสำเร็จ',
                    'message' => $normalized['fName'],
                ]);
            } catch (Throwable $e) {
                if ($transaction_started) {
                    db_rollback();
                }
                personnel_management_cleanup_uploaded_files($stored_files);
                audit_log('personnel', $is_create ? 'CREATE' : 'UPDATE', 'FAIL', 'teacher', $normalized['pID'] !== '' ? $normalized['pID'] : null, $e->getMessage());
                $set_alert([
                    'type' => 'danger',
                    'title' => 'เกิดข้อผิดพลาด',
                    'message' => $e->getMessage(),
                ], $is_create ? 'personnelAddModal' : 'personnelEditModal', $normalized);
            }
        }

        $personnel_rows = personnel_management_fetch_rows();
        $active_count = 0;
        $inactive_count = 0;

        foreach ($personnel_rows as $row) {
            if ((int) ($row['status'] ?? 0) === 1) {
                $active_count++;
            } else {
                $inactive_count++;
            }
        }

        view_render('personnel/management', [
            'alert' => $alert,
            'open_modal' => $open_modal,
            'form_values' => array_merge(personnel_management_defaults(), $form_values),
            'edit_values' => array_merge(personnel_management_defaults(), $edit_values),
            'personnel_rows' => $personnel_rows,
            'faction_options' => $option_maps['faction'],
            'department_options' => $option_maps['department'],
            'level_options' => $option_maps['level'],
            'legacy_position_options' => $option_maps['legacy_position'],
            'position_options' => $option_maps['position'],
            'role_rows' => $role_rows,
            'active_count' => $active_count,
            'inactive_count' => $inactive_count,
        ]);
    }
}
