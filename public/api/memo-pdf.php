<?php

declare(strict_types=1);

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$__memo_pdf_initial_ob_level = ob_get_level();
ob_start();

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    error_log('PHP error [' . $severity . '] ' . $message . ' in ' . $file . ':' . $line);

    return true;
});

$__memo_pdf_abort = static function (int $status) use ($__memo_pdf_initial_ob_level): void {
    while (ob_get_level() > $__memo_pdf_initial_ob_level) {
        ob_end_clean();
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    exit();
};

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../app/modules/audit/logger.php';

if (!function_exists('memo_pdf_runtime_report')) {
    function memo_pdf_runtime_report(string $cache_key): array
    {
        $required_extensions = ['mbstring', 'gd'];
        $missing_extensions = [];
        $extensions = [];

        foreach ($required_extensions as $extension) {
            $loaded = extension_loaded($extension);
            $extensions[$extension] = $loaded;

            if (!$loaded) {
                $missing_extensions[] = $extension;
            }
        }

        $class_map = [
            'mpdf' => class_exists(Mpdf::class),
            'config_variables' => class_exists(ConfigVariables::class),
            'font_variables' => class_exists(FontVariables::class),
        ];

        $missing_classes = [];

        foreach ($class_map as $label => $loaded) {
            if (!$loaded) {
                $missing_classes[] = $label;
            }
        }

        $system_temp = (string) sys_get_temp_dir();
        $temp_dir = rtrim($system_temp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'workflow-mpdf-memo-' . $cache_key;
        $temp_dir_ready = false;

        if ($system_temp !== '') {
            if (!is_dir($temp_dir)) {
                @mkdir($temp_dir, 0777, true);
            }

            $temp_dir_ready = is_dir($temp_dir) && is_writable($temp_dir);

            if (!$temp_dir_ready) {
                $temp_dir = $system_temp;
                $temp_dir_ready = is_dir($temp_dir) && is_writable($temp_dir);
            }
        }

        $errors = [];

        if ($missing_extensions !== []) {
            $errors[] = 'missing_extensions:' . implode(',', $missing_extensions);
        }

        if ($missing_classes !== []) {
            $errors[] = 'missing_classes:' . implode(',', $missing_classes);
        }

        if (!$temp_dir_ready) {
            $errors[] = 'temp_dir_not_writable';
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'extensions' => $extensions,
            'classes' => $class_map,
            'temp_dir' => [
                'path' => $temp_dir,
                'writable' => $temp_dir_ready,
            ],
        ];
    }
}

if (empty($_SESSION['pID'])) {
    if (function_exists('audit_log')) {
        audit_log('security', 'AUTH_REQUIRED', 'DENY', null, null, 'memo_pdf', [], 'GET', 401);
    }

    $__memo_pdf_abort(401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    if (function_exists('audit_log')) {
        audit_log('memo', 'PDF_VIEW', 'FAIL', 'dh_memos', null, 'method_not_allowed', [], 'GET', 405);
    }

    $__memo_pdf_abort(405);
}

require_once __DIR__ . '/../../config/connection.php';
require_once __DIR__ . '/../../app/db/db.php';
require_once __DIR__ . '/../../app/rbac/roles.php';
require_once __DIR__ . '/../../app/modules/system/system.php';
require_once __DIR__ . '/../../app/modules/system/positions.php';
require_once __DIR__ . '/../../app/modules/memos/repository.php';
require_once __DIR__ . '/../../app/modules/memos/service.php';
require_once __DIR__ . '/../../app/modules/memos/status.php';

$connection = db_connection();
$actor_pid = trim((string) ($_SESSION['pID'] ?? ''));
$memo_id_input = $_GET['memo_id'] ?? null;
$memo_id = filter_var($memo_id_input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$preview_value = strtolower(trim((string) ($_GET['preview'] ?? '')));
$preview_html = in_array($preview_value, ['1', 'true', 'html'], true);
$download = trim((string) ($_GET['download'] ?? '')) === '1';
$mock_requested = trim((string) ($_GET['mock'] ?? ''));
$use_mock = $mock_requested === '1' || !$memo_id;
$memo_id = $memo_id !== false ? (int) $memo_id : 0;

if (!function_exists('memo_pdf_format_thai_date')) {
    function memo_pdf_format_thai_date(?string $date_value): string
    {
        static $thai_months = [
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

        $date_value = trim((string) $date_value);

        if ($date_value === '' || strpos($date_value, '0000-00-00') === 0) {
            return '-';
        }

        $date_obj = DateTime::createFromFormat('Y-m-d', substr($date_value, 0, 10));

        if ($date_obj === false) {
            return $date_value;
        }

        $day = (int) $date_obj->format('j');
        $month = (int) $date_obj->format('n');
        $year = (int) $date_obj->format('Y') + 543;

        return trim($day . ' ' . ($thai_months[$month] ?? '') . ' ' . $year);
    }
}

if (!function_exists('memo_pdf_format_thai_datetime')) {
    function memo_pdf_format_thai_datetime(?string $date_value): string
    {
        static $thai_months = [
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

        $date_value = trim((string) $date_value);

        if ($date_value === '' || strpos($date_value, '0000-00-00') === 0) {
            return '-';
        }

        $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $date_value);

        if ($date_obj === false) {
            $date_obj = DateTime::createFromFormat('Y-m-d H:i', $date_value);
        }

        if ($date_obj === false) {
            return $date_value;
        }

        $day = (int) $date_obj->format('j');
        $month = (int) $date_obj->format('n');
        $year = (int) $date_obj->format('Y') + 543;

        return trim($day . ' ' . ($thai_months[$month] ?? '') . ' ' . $year . ' เวลา ' . $date_obj->format('H:i') . ' น.');
    }
}

if (!function_exists('memo_pdf_split_paragraphs')) {
    function memo_pdf_split_paragraphs(?string $text): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return [];
        }

        if (preg_match('/<[^>]+>/u', $text) === 1) {
            $text = preg_replace('/<(br|hr)\b[^>]*>/iu', "\n", $text) ?? $text;
            $text = preg_replace('/<\/(p|div|li|h[1-6]|blockquote|section|article|tr)>/iu', "\n\n", $text) ?? $text;
            $text = str_replace(['&nbsp;', '&#160;'], ' ', $text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = strip_tags($text);
        }

        $text = preg_replace("/\r\n|\r/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [];
        $paragraphs = array_values(array_filter(array_map(
            static function (string $value): string {
                $value = preg_replace('/[ \t]+/u', ' ', trim($value)) ?? trim($value);
                $value = preg_replace('/\n/u', ' ', $value) ?? $value;

                return $value;
            },
            $paragraphs
        ), static fn(string $value): bool => $value !== ''));

        if ($paragraphs !== []) {
            return $paragraphs;
        }

        return [$text];
    }
}

if (!function_exists('memo_pdf_safe_file_to_data_uri')) {
    function memo_pdf_safe_file_to_data_uri(?string $relative_path): ?string
    {
        $relative_path = trim((string) $relative_path);

        if ($relative_path === '') {
            return null;
        }

        $project_root = realpath(__DIR__ . '/../..');

        if ($project_root === false) {
            return null;
        }

        $relative_path = ltrim($relative_path, '/');

        $allowed = $relative_path === 'assets/img/garuda-logo.png'
            || str_starts_with($relative_path, 'assets/img/signature/');

        if (!$allowed) {
            return null;
        }

        $candidate = realpath($project_root . '/' . $relative_path);

        if ($candidate === false || strpos($candidate, $project_root) !== 0 || !is_file($candidate)) {
            return null;
        }

        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $contents = @file_get_contents($candidate);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}

if (!function_exists('memo_pdf_fetch_teacher_profiles')) {
    function memo_pdf_fetch_teacher_profiles(mysqli $connection, array $pids): array
    {
        $pids = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $pids
        ), static fn(string $value): bool => $value !== '')));

        if ($pids === []) {
            return [];
        }

        $position = system_position_join($connection, 't', 'p');
        $placeholders = implode(', ', array_fill(0, count($pids), '?'));
        $sql = 'SELECT t.pID,
                       COALESCE(t.fName, "") AS name,
                       COALESCE(t.signature, "") AS signature,
                       COALESCE(' . $position['name'] . ', "") AS positionName,
                       COALESCE(d.dName, "") AS departmentName
                FROM teacher AS t
                ' . $position['join'] . '
                LEFT JOIN department AS d ON t.dID = d.dID
                WHERE t.status = 1 AND t.pID IN (' . $placeholders . ')';

        $rows = db_fetch_all($sql, str_repeat('s', count($pids)), ...$pids);
        $profiles = [];

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid === '') {
                continue;
            }

            $profiles[$pid] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'signature' => trim((string) ($row['signature'] ?? '')),
                'positionName' => trim((string) ($row['positionName'] ?? '')),
                'departmentName' => trim((string) ($row['departmentName'] ?? '')),
            ];
        }

        return $profiles;
    }
}

if (!function_exists('memo_pdf_latest_note_by_actor')) {
    function memo_pdf_latest_note_by_actor(array $routes, string $actor_pid): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $note = '';

        foreach ($routes as $route) {
            if (trim((string) ($route['actorPID'] ?? '')) !== $actor_pid) {
                continue;
            }

            $candidate = trim((string) ($route['note'] ?? ''));

            if ($candidate !== '') {
                $note_lines = memo_pdf_split_paragraphs($candidate);
                $candidate = trim(implode("\n", $note_lines));
            }

            if ($candidate !== '') {
                $note = $candidate;
            }
        }

        return $note;
    }
}

if (!function_exists('memo_pdf_review_actions')) {
    function memo_pdf_review_actions(): array
    {
        return [
            'FORWARD',
            'RETURN',
            'APPROVE_UNSIGNED',
            'REJECT',
            'DIRECTOR_APPROVE',
            'DIRECTOR_REJECT',
            'DIRECTOR_SIGNED',
            'DIRECTOR_ACKNOWLEDGED',
            'DIRECTOR_AGREED',
            'DIRECTOR_NOTIFIED',
            'DIRECTOR_ASSIGNED',
            'DIRECTOR_SCHEDULED',
            'DIRECTOR_PERMITTED',
            'DIRECTOR_APPROVED',
            'DIRECTOR_REJECTED',
            'DIRECTOR_REQUEST_MEETING',
            'SIGN',
        ];
    }
}

if (!function_exists('memo_pdf_latest_review_action_by_actor')) {
    function memo_pdf_latest_review_action_by_actor(array $routes, string $actor_pid): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $latest_action = '';
        $review_actions = memo_pdf_review_actions();

        foreach ($routes as $route) {
            if (trim((string) ($route['actorPID'] ?? '')) !== $actor_pid) {
                continue;
            }

            $action = strtoupper(trim((string) ($route['action'] ?? '')));

            if (in_array($action, $review_actions, true)) {
                $latest_action = $action;
            }
        }

        return $latest_action;
    }
}

if (!function_exists('memo_pdf_latest_review_actor_pid')) {
    function memo_pdf_latest_review_actor_pid(array $routes): string
    {
        $latest_actor_pid = '';
        $review_actions = memo_pdf_review_actions();

        foreach ($routes as $route) {
            $action = strtoupper(trim((string) ($route['action'] ?? '')));

            if (!in_array($action, $review_actions, true)) {
                continue;
            }

            $actor_pid = trim((string) ($route['actorPID'] ?? ''));

            if ($actor_pid !== '') {
                $latest_actor_pid = $actor_pid;
            }
        }

        return $latest_actor_pid;
    }
}

if (!function_exists('memo_pdf_resolve_stage_note')) {
    function memo_pdf_resolve_stage_note(array $memo, array $routes, string $actor_pid): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $route_note = memo_pdf_latest_note_by_actor($routes, $actor_pid);

        if ($route_note !== '') {
            return $route_note;
        }

        $review_note = trim((string) ($memo['reviewNote'] ?? ''));

        if ($review_note === '') {
            return '';
        }

        if (trim((string) ($memo['approvedByPID'] ?? '')) === $actor_pid) {
            return $review_note;
        }

        return memo_pdf_latest_review_actor_pid($routes) === $actor_pid ? $review_note : '';
    }
}

if (!function_exists('memo_pdf_resolve_stage_action')) {
    function memo_pdf_resolve_stage_action(array $memo, array $routes, string $actor_pid, string $stage = ''): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $action = memo_pdf_latest_review_action_by_actor($routes, $actor_pid);

        if ($action !== '') {
            return $action;
        }

        $status = strtoupper(trim((string) ($memo['status'] ?? '')));
        $approved_pid = trim((string) ($memo['approvedByPID'] ?? ''));
        $stage = strtoupper(trim($stage));

        if ($approved_pid === $actor_pid && $stage === 'DEPUTY' && $status === MEMO_STATUS_APPROVED_UNSIGNED) {
            return 'APPROVE_UNSIGNED';
        }

        return '';
    }
}

if (!function_exists('memo_pdf_resolve_chain_from_routes')) {
    function memo_pdf_resolve_chain_from_routes(array $memo, array $chain, array $routes): array
    {
        $flow_stage = strtoupper(trim((string) ($memo['flowStage'] ?? '')));
        $forward_actors = [];
        $has_director_review = false;
        $deputy_candidate_cache = [];
        $director_actions = [
            'DIRECTOR_APPROVE',
            'DIRECTOR_REJECT',
            'DIRECTOR_SIGNED',
            'DIRECTOR_ACKNOWLEDGED',
            'DIRECTOR_AGREED',
            'DIRECTOR_NOTIFIED',
            'DIRECTOR_ASSIGNED',
            'DIRECTOR_SCHEDULED',
            'DIRECTOR_PERMITTED',
            'DIRECTOR_APPROVED',
            'DIRECTOR_REJECTED',
            'DIRECTOR_REQUEST_MEETING',
            'SIGN',
        ];
        $deputy_actions = [
            'FORWARD',
            'RETURN',
            'APPROVE_UNSIGNED',
            'REJECT',
        ];
        $is_deputy_candidate = static function (string $pid) use (&$deputy_candidate_cache): bool {
            $pid = trim($pid);

            if ($pid === '') {
                return false;
            }

            if (!array_key_exists($pid, $deputy_candidate_cache)) {
                $deputy_candidate_cache[$pid] = memo_is_valid_deputy_candidate($pid);
            }

            return $deputy_candidate_cache[$pid];
        };

        foreach ($routes as $route) {
            $actor_pid = trim((string) ($route['actorPID'] ?? ''));
            $action = strtoupper(trim((string) ($route['action'] ?? '')));

            if ($actor_pid === '') {
                continue;
            }

            if ($action === 'FORWARD') {
                $forward_actors[] = $actor_pid;
            }

            if (in_array($action, $deputy_actions, true) && $is_deputy_candidate($actor_pid)) {
                $chain['DEPUTY'] = $actor_pid;
                continue;
            }

            if (in_array($action, $director_actions, true)) {
                $chain['DIRECTOR'] = $actor_pid;
                $has_director_review = true;
            }
        }

        if (($flow_stage === 'DIRECTOR' || $has_director_review) && $forward_actors !== []) {
            $head_pid = trim((string) ($chain['HEAD'] ?? ''));
            $director_pid = trim((string) ($chain['DIRECTOR'] ?? ''));

            for ($index = count($forward_actors) - 1; $index >= 0; $index--) {
                $actor_pid = trim((string) ($forward_actors[$index] ?? ''));

                if ($actor_pid === '' || $actor_pid === $head_pid || $actor_pid === $director_pid) {
                    continue;
                }

                $chain['DEPUTY'] = $actor_pid;
                break;
            }
        }

        $approved_pid = trim((string) ($memo['approvedByPID'] ?? ''));
        $memo_status = strtoupper(trim((string) ($memo['status'] ?? '')));

        if ($memo_status === MEMO_STATUS_APPROVED_UNSIGNED && $approved_pid !== '' && $is_deputy_candidate($approved_pid)) {
            $chain['DEPUTY'] = $approved_pid;
        }

        return $chain;
    }
}

if (!function_exists('memo_pdf_should_suppress_director_stage')) {
    function memo_pdf_should_suppress_director_stage(array $memo, string $memo_status_key, array $chain, array $routes): bool
    {
        if ($memo_status_key === MEMO_STATUS_APPROVED_UNSIGNED) {
            return true;
        }

        $deputy_pid = trim((string) ($chain['DEPUTY'] ?? ''));
        $director_pid = trim((string) ($chain['DIRECTOR'] ?? ''));

        if ($deputy_pid === '' || $director_pid === '' || $deputy_pid !== $director_pid) {
            return false;
        }

        $director_action = strtoupper(memo_pdf_resolve_stage_action($memo, $routes, $director_pid, 'DIRECTOR'));
        $deputy_action = strtoupper(memo_pdf_resolve_stage_action($memo, $routes, $deputy_pid, 'DEPUTY'));

        return in_array($director_action !== '' ? $director_action : $deputy_action, ['APPROVE_UNSIGNED', 'SIGN'], true);
    }
}

if (!function_exists('memo_pdf_find_teacher_pid_by_position_like')) {
    function memo_pdf_find_teacher_pid_by_position_like(mysqli $connection, string $like_pattern): string
    {
        $position = system_position_join($connection, 't', 'p');
        $row = db_fetch_one(
            'SELECT t.pID
             FROM teacher AS t
             ' . $position['join'] . '
             WHERE t.status = 1 AND ' . $position['name'] . ' LIKE ?
             ORDER BY t.pID ASC
             LIMIT 1',
            's',
            $like_pattern
        );

        return trim((string) ($row['pID'] ?? ''));
    }
}

if (!function_exists('memo_pdf_find_deputy_pid')) {
    function memo_pdf_find_deputy_pid(mysqli $connection): string
    {
        $position = system_position_join($connection, 't', 'p');
        $row = db_fetch_one(
            'SELECT t.pID
             FROM teacher AS t
             ' . $position['join'] . '
             WHERE t.status = 1 AND ' . $position['name'] . ' LIKE ?
             ORDER BY t.pID ASC
             LIMIT 1',
            's',
            'รองผู้อำนวยการ%'
        );

        return trim((string) ($row['pID'] ?? ''));
    }
}

if (!function_exists('memo_pdf_is_actor_authorized')) {
    function memo_pdf_is_actor_authorized(mysqli $connection, array $memo, string $actor_pid): bool
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return false;
        }

        $allowed_pids = array_values(array_unique(array_filter([
            trim((string) ($memo['createdByPID'] ?? '')),
            trim((string) ($memo['toPID'] ?? '')),
            trim((string) ($memo['headPID'] ?? '')),
            trim((string) ($memo['deputyPID'] ?? '')),
            trim((string) ($memo['directorPID'] ?? '')),
            trim((string) ($memo['approvedByPID'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));

        if (in_array($actor_pid, $allowed_pids, true)) {
            return true;
        }

        if ($actor_pid === trim((string) (system_get_current_director_pid() ?? ''))) {
            return true;
        }

        return rbac_user_has_role($connection, $actor_pid, ROLE_ADMIN);
    }
}

if (!function_exists('memo_pdf_build_mock_data')) {
    function memo_pdf_build_mock_data(mysqli $connection, string $actor_pid): array
    {
        $owner_pid = trim($actor_pid);
        $head_pid = memo_pdf_find_teacher_pid_by_position_like($connection, 'หัวหน้ากลุ่มสาระ%');
        $deputy_pid = memo_pdf_find_deputy_pid($connection);
        $director_pid = trim((string) (system_get_current_director_pid() ?? ''));

        if ($head_pid === '') {
            $head_pid = $owner_pid;
        }

        $profiles = memo_pdf_fetch_teacher_profiles($connection, [$owner_pid, $head_pid, $deputy_pid, $director_pid]);
        $owner = $profiles[$owner_pid] ?? [];
        $head = $profiles[$head_pid] ?? [];
        $deputy = $profiles[$deputy_pid] ?? [];
        $director = $profiles[$director_pid] ?? [];

        return [
            'document_title' => 'บันทึกข้อความ',
            'document_subtitle' => 'ข้อมูลตัวอย่างสำหรับออกแบบ PDF ระบบบันทึกข้อความ',
            'memo_no' => 'ศธ 04320.05/มม 015',
            'dh_year_label' => (string) system_get_dh_year(),
            'school_name' => 'โรงเรียนดีบุกพังงาวิทยายน',
            'section_name' => trim((string) ($owner['departmentName'] ?? '')) !== '' ? trim((string) ($owner['departmentName'] ?? '')) : 'กลุ่มบริหารงานทั่วไป',
            'subject' => 'ขออนุมัติดำเนินกิจกรรมพัฒนาทักษะการอ่านเชิงวิเคราะห์ ภาคเรียนที่ 1',
            'to_name' => 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน',
            'write_date_label' => memo_pdf_format_thai_date(date('Y-m-d')),
            'status_label' => 'รอพิจารณา',
            'status_note' => 'ตัวอย่าง PDF สำหรับงานออกแบบเอกสารบันทึกข้อความก่อนเชื่อมข้อมูลจริงจากระบบ',
            'generated_at_label' => memo_pdf_format_thai_datetime(date('Y-m-d H:i:s')),
            'logo_data_uri' => memo_pdf_safe_file_to_data_uri('assets/img/garuda-logo.png') ?? '',
            'owner_signature' => memo_pdf_safe_file_to_data_uri((string) ($owner['signature'] ?? '')) ?? '',
            'owner_name' => trim((string) ($owner['name'] ?? 'นางสาวทิพยรัตน์ บุญมณี')),
            'owner_position' => trim((string) ($owner['positionName'] ?? 'ครู')),
            'owner_role_label' => 'ผู้จัดทำบันทึกข้อความ',
            'body_paragraphs' => [
                'ด้วยกลุ่มสาระการเรียนรู้ภาษาไทยมีแผนดำเนินกิจกรรมพัฒนาทักษะการอ่านเชิงวิเคราะห์สำหรับนักเรียนระดับชั้นมัธยมศึกษาตอนต้น เพื่อยกระดับผลสัมฤทธิ์ทางการเรียนและส่งเสริมสมรรถนะด้านการสื่อสารของผู้เรียนให้สอดคล้องกับเป้าหมายของสถานศึกษา',
                'ในการนี้ จึงใคร่ขออนุมัติดำเนินกิจกรรมดังกล่าวในวันที่ 18 เมษายน 2569 ณ ห้องประชุมเกียรติยศ โรงเรียนดีบุกพังงาวิทยายน โดยใช้งบประมาณจากแผนงานพัฒนาคุณภาพผู้เรียน จำนวน 12,500 บาท ตามรายการค่าใช้จ่ายที่แนบมาพร้อมนี้',
                'จึงเรียนมาเพื่อโปรดพิจารณาอนุมัติ หากเห็นชอบจักได้ดำเนินการในส่วนที่เกี่ยวข้องต่อไป',
            ],
            'attachments' => [
                'กำหนดการกิจกรรมพัฒนาทักษะการอ่านเชิงวิเคราะห์.pdf',
                'ประมาณการค่าใช้จ่ายกิจกรรม.pdf',
                'รายชื่อวิทยากรและผู้รับผิดชอบ.xlsx',
            ],
            'review_blocks' => [
                [
                    'title' => 'ความคิดเห็นและข้อเสนอแนะของหัวหน้ากลุ่มสาระการเรียนรู้',
                    'note' => 'เห็นควรเสนอรองผู้อำนวยการ เนื่องจากกิจกรรมมีความสอดคล้องกับแผนพัฒนาคุณภาพผู้เรียนและมีรายละเอียดประกอบครบถ้วน',
                    'signature' => memo_pdf_safe_file_to_data_uri((string) ($head['signature'] ?? '')) ?? '',
                    'name' => trim((string) ($head['name'] ?? 'นางสาวทิพยรัตน์ บุญมณี')),
                    'position' => trim((string) ($head['positionName'] ?? 'หัวหน้ากลุ่มสาระการเรียนรู้ภาษาไทย')),
                    'role_label' => 'หัวหน้ากลุ่มสาระการเรียนรู้',
                ],
                [
                    'title' => 'ความคิดเห็นและข้อเสนอแนะของรองผู้อำนวยการ',
                    'note' => 'พิจารณาแล้วเห็นควรเสนอผู้อำนวยการเพื่ออนุมัติ เนื่องจากเป็นกิจกรรมที่สนับสนุนตัวชี้วัดของสถานศึกษาและมีแผนการใช้งบประมาณเหมาะสม',
                    'signature' => memo_pdf_safe_file_to_data_uri((string) ($deputy['signature'] ?? '')) ?? '',
                    'name' => trim((string) ($deputy['name'] ?? 'นายยุทธนา สุวรรณวิสุทธิ์')),
                    'position' => trim((string) ($deputy['positionName'] ?? 'รองผู้อำนวยการโรงเรียน')),
                    'role_label' => 'รองผู้อำนวยการ',
                ],
                [
                    'title' => 'ความคิดเห็นและข้อเสนอแนะของผู้อำนวยการโรงเรียน',
                    'note' => 'อนุมัติให้ดำเนินกิจกรรมตามเสนอ และให้รายงานผลการดำเนินงานพร้อมสรุปค่าใช้จ่ายภายใน 7 วันหลังเสร็จสิ้นกิจกรรม',
                    'signature' => memo_pdf_safe_file_to_data_uri((string) ($director['signature'] ?? '')) ?? '',
                    'name' => trim((string) ($director['name'] ?? 'นายศิริโชค โสภา')),
                    'position' => trim((string) ($director['positionName'] ?? 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน')),
                    'role_label' => 'ผู้อำนวยการโรงเรียน',
                ],
            ],
        ];
    }
}

if (!function_exists('memo_pdf_build_live_data')) {
    function memo_pdf_build_live_data(mysqli $connection, array $memo): array
    {
        $memo_id = (int) ($memo['memoID'] ?? 0);
        $creator_pid = trim((string) ($memo['createdByPID'] ?? ''));
        $routes = $memo_id > 0 ? memo_list_routes($memo_id) : [];
        $attachments = $memo_id > 0 ? memo_get_attachments($memo_id) : [];
        $resolved_chain = $creator_pid !== '' ? memo_resolve_chain_approvers($creator_pid) : [];
        $memo_status_key = strtoupper(trim((string) ($memo['status'] ?? '')));

        $chain = [
            'HEAD' => trim((string) ($memo['headPID'] ?? ($resolved_chain['headPID'] ?? ''))),
            'DEPUTY' => trim((string) ($memo['deputyPID'] ?? ($resolved_chain['deputyPID'] ?? ''))),
            'DIRECTOR' => trim((string) ($memo['directorPID'] ?? ($resolved_chain['directorPID'] ?? (system_get_current_director_pid() ?? '')))),
        ];
        $chain = memo_pdf_resolve_chain_from_routes($memo, $chain, $routes);
        $approved_pid = trim((string) ($memo['approvedByPID'] ?? ''));
        $head_pid = trim((string) ($chain['HEAD'] ?? ''));
        $deputy_pid = trim((string) ($chain['DEPUTY'] ?? ''));

        if ($memo_status_key !== MEMO_STATUS_APPROVED_UNSIGNED && $approved_pid !== '') {
            $director_action = memo_pdf_latest_review_action_by_actor($routes, trim((string) ($chain['DIRECTOR'] ?? '')));

            if (
                $director_action === ''
                && $approved_pid !== $head_pid
                && $approved_pid !== $deputy_pid
            ) {
                $chain['DIRECTOR'] = $approved_pid;
            }
        }

        $suppress_director_stage = memo_pdf_should_suppress_director_stage($memo, $memo_status_key, $chain, $routes);

        $route_actor_pids = array_values(array_filter(array_map(static function (array $route): string {
            return trim((string) ($route['actorPID'] ?? ''));
        }, $routes), static function (string $value): bool {
            return $value !== '';
        }));

        $profile_pids = array_filter([
            $creator_pid,
            trim((string) ($memo['toPID'] ?? '')),
            $chain['HEAD'],
            $chain['DEPUTY'],
            $chain['DIRECTOR'],
            trim((string) ($memo['approvedByPID'] ?? '')),
            ...$route_actor_pids,
        ], static fn(string $value): bool => $value !== '');
        $profiles = memo_pdf_fetch_teacher_profiles($connection, $profile_pids);
        $creator = $profiles[$creator_pid] ?? [];
        $to_pid = trim((string) ($memo['toPID'] ?? ''));
        $to_profile = $profiles[$to_pid] ?? [];
        $status_meta = memo_status_meta((string) ($memo['status'] ?? ''));

        $review_blocks = [];
        $review_config = [
            'HEAD' => [
                'title' => 'ความคิดเห็นและข้อเสนอแนะของหัวหน้ากลุ่มสาระการเรียนรู้',
                'role_label' => 'หัวหน้ากลุ่มสาระการเรียนรู้',
            ],
            'DEPUTY' => [
                'title' => 'ความคิดเห็นและข้อเสนอแนะของรองผู้อำนวยการ',
                'role_label' => 'รองผู้อำนวยการ',
            ],
            'DIRECTOR' => [
                'title' => 'ความคิดเห็นและข้อเสนอแนะของผู้อำนวยการโรงเรียน',
                'role_label' => 'ผู้อำนวยการโรงเรียน',
            ],
        ];

        $rendered_review_keys = [];

        foreach ($review_config as $stage => $meta) {
            if ($stage === 'DIRECTOR' && $suppress_director_stage) {
                continue;
            }

            $stage_pid = trim((string) ($chain[$stage] ?? ''));

            if ($stage === 'DIRECTOR' && $stage_pid === '') {
                $fallback_pid = trim((string) ($memo['approvedByPID'] ?? ''));

                if ($fallback_pid !== '' && $fallback_pid !== $head_pid && $fallback_pid !== $deputy_pid) {
                    $stage_pid = $fallback_pid;
                }
            }

            $stage_profile = $profiles[$stage_pid] ?? [];
            $stage_note = memo_pdf_resolve_stage_note($memo, $routes, $stage_pid);
            $stage_action = memo_pdf_resolve_stage_action($memo, $routes, $stage_pid, $stage);

            if ($stage_pid === '' || ($stage_note === '' && $stage_action === '')) {
                continue;
            }

            $review_key = $stage_pid . '|' . strtoupper($stage_action) . '|' . $stage_note;

            if (isset($rendered_review_keys[$review_key])) {
                continue;
            }

            $rendered_review_keys[$review_key] = true;

            $review_blocks[] = [
                'title' => $meta['title'],
                'note' => $stage_note !== '' ? $stage_note : '-',
                'signature' => memo_pdf_safe_file_to_data_uri((string) ($stage_profile['signature'] ?? '')) ?? '',
                'name' => trim((string) ($stage_profile['name'] ?? '-')),
                'position' => trim((string) ($stage_profile['positionName'] ?? '-')),
                'role_label' => $meta['role_label'],
            ];
        }

        $body_paragraphs = memo_pdf_split_paragraphs((string) ($memo['detail'] ?? ''));
        $attachment_names = array_values(array_filter(array_map(
            static fn(array $file): string => trim((string) ($file['fileName'] ?? '')),
            $attachments
        ), static fn(string $value): bool => $value !== ''));

        $flow_mode = strtoupper(trim((string) ($memo['flowMode'] ?? 'CHAIN')));
        $status_note = $flow_mode === 'CHAIN'
            ? 'เสนอแฟ้มตามลำดับผู้พิจารณาในระบบ'
            : 'เสนอโดยตรงถึงผู้พิจารณาตามที่กำหนด';
        $to_name = 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน';

        if ($flow_mode !== 'CHAIN') {
            $to_name = trim((string) ($to_profile['name'] ?? ($memo['approverName'] ?? '')));

            if ($to_name === '') {
                $to_name = 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน';
            }
        }

        return [
            'document_title' => 'บันทึกข้อความ',
            'document_subtitle' => 'เอกสารสำหรับใช้งานในระบบบันทึกข้อความ',
            'memo_no' => trim((string) ($memo['memoNo'] ?? '')) !== '' ? trim((string) ($memo['memoNo'] ?? '')) : ('MEMO-' . $memo_id),
            'dh_year_label' => trim((string) ($memo['dh_year'] ?? '')),
            'school_name' => 'โรงเรียนดีบุกพังงาวิทยายน',
            'section_name' => trim((string) ($creator['departmentName'] ?? '')) !== '' ? trim((string) ($creator['departmentName'] ?? '')) : '-',
            'subject' => trim((string) ($memo['subject'] ?? '')),
            'to_name' => $to_name,
            'write_date_label' => memo_pdf_format_thai_date((string) ($memo['writeDate'] ?? '')),
            'status_label' => trim((string) ($status_meta['label'] ?? '-')),
            'status_note' => $status_note,
            'generated_at_label' => memo_pdf_format_thai_datetime(date('Y-m-d H:i:s')),
            'logo_data_uri' => memo_pdf_safe_file_to_data_uri('assets/img/garuda-logo.png') ?? '',
            'owner_signature' => memo_pdf_safe_file_to_data_uri((string) ($creator['signature'] ?? '')) ?? '',
            'owner_name' => trim((string) ($creator['name'] ?? ($memo['creatorName'] ?? '-'))),
            'owner_position' => trim((string) ($creator['positionName'] ?? '-')),
            'owner_role_label' => 'ผู้จัดทำบันทึกข้อความ',
            'body_paragraphs' => $body_paragraphs,
            'attachments' => $attachment_names,
            'review_blocks' => $review_blocks,
        ];
    }
}

$audit_payload = [
    'mode' => $use_mock ? 'mock' : 'live',
    'preview' => $preview_html,
];

$memo = null;

if (!$use_mock) {
    $memo = memo_get($memo_id);

    if (!$memo) {
        if (function_exists('audit_log')) {
            audit_log('memo', 'PDF_VIEW', 'FAIL', 'dh_memos', $memo_id, 'memo_not_found', $audit_payload, 'GET', 404);
        }

        $__memo_pdf_abort(404);
    }

    if (!memo_pdf_is_actor_authorized($connection, $memo, $actor_pid)) {
        if (function_exists('audit_log')) {
            audit_log('memo', 'PDF_VIEW', 'DENY', 'dh_memos', $memo_id, 'not_authorized', $audit_payload, 'GET', 403);
        }

        $__memo_pdf_abort(403);
    }
}

$data = $use_mock
    ? memo_pdf_build_mock_data($connection, $actor_pid)
    : memo_pdf_build_live_data($connection, $memo ?? []);

require_once __DIR__ . '/../../app/views/memo/memo-pdf-template.php';
$html = memo_pdf_render_html($data);

if ($preview_html) {
    while (ob_get_level() > $__memo_pdf_initial_ob_level) {
        ob_end_clean();
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }

    header('Content-Type: text/html; charset=UTF-8');

    if (function_exists('audit_log')) {
        audit_log('memo', 'PDF_PREVIEW', 'SUCCESS', 'dh_memos', $use_mock ? null : $memo_id, null, $audit_payload, 'GET', 200);
    }

    echo $html;
    exit();
}

try {
    $mpdf_font_dir = __DIR__ . '/../../assets/fonts/sarabun';
    $has_sarabun = is_dir($mpdf_font_dir)
        && is_file($mpdf_font_dir . '/Sarabun-Regular.ttf')
        && is_file($mpdf_font_dir . '/Sarabun-Bold.ttf')
        && is_file($mpdf_font_dir . '/Sarabun-Italic.ttf')
        && is_file($mpdf_font_dir . '/Sarabun-BoldItalic.ttf');

    $font_sig_parts = [];

    foreach (['Sarabun-Regular.ttf', 'Sarabun-Bold.ttf', 'Sarabun-Italic.ttf', 'Sarabun-BoldItalic.ttf'] as $font_file) {
        $path = $mpdf_font_dir . '/' . $font_file;

        if (is_file($path)) {
            $font_sig_parts[] = $font_file . ':' . filesize($path) . ':' . filemtime($path);
        }
    }

    $cache_key = substr(sha1(implode('|', $font_sig_parts) . '|memo|sarabun|otl=255|winTypo'), 0, 12);
    $runtime_report = memo_pdf_runtime_report($cache_key);

    if (!$runtime_report['ready']) {
        $runtime_payload = $audit_payload;
        $runtime_payload['runtime'] = $runtime_report;

        error_log('Memo PDF runtime not ready: ' . (json_encode($runtime_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'runtime_check_failed'));

        if (function_exists('audit_log')) {
            audit_log('memo', 'PDF_VIEW', 'FAIL', 'dh_memos', $use_mock ? null : $memo_id, 'pdf_runtime_not_ready', $runtime_payload, 'GET', 500);
        }

        while (ob_get_level() > $__memo_pdf_initial_ob_level) {
            ob_end_clean();
        }

        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF runtime is not ready: ' . implode(', ', $runtime_report['errors']);
        exit();
    }

    $mpdf_tmp = (string) ($runtime_report['temp_dir']['path'] ?? sys_get_temp_dir());

    $config_vars = (new ConfigVariables())->getDefaults();
    $font_dirs = $config_vars['fontDir'];
    $font_vars = (new FontVariables())->getDefaults();
    $font_data = $font_vars['fontdata'];

    if ($has_sarabun) {
        $font_dirs[] = $mpdf_font_dir;
        $font_data['sarabun'] = [
            'R' => 'Sarabun-Regular.ttf',
            'B' => 'Sarabun-Bold.ttf',
            'I' => 'Sarabun-Italic.ttf',
            'BI' => 'Sarabun-BoldItalic.ttf',
            'useOTL' => 0xFF,
        ];
    }

    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => $has_sarabun ? 'sarabun' : 'garuda',
        'allow_charset_conversion' => false,
        'useDictionaryLBR' => true,
        'tempDir' => $mpdf_tmp,
        'fontDescriptor' => 'winTypo',
        'fontDir' => $font_dirs,
        'fontdata' => $font_data,
        'margin_left' => 18,
        'margin_right' => 18,
        'margin_top' => 18,
        'margin_bottom' => 20,
    ]);
} catch (Throwable $exception) {
    error_log('Memo PDF init failed: ' . $exception->getMessage());

    if (function_exists('audit_log')) {
        audit_log('memo', 'PDF_VIEW', 'FAIL', 'dh_memos', $use_mock ? null : $memo_id, 'pdf_init_failed', $audit_payload, 'GET', 500);
    }

    $__memo_pdf_abort(500);
}

$memo_no = trim((string) ($data['memo_no'] ?? 'memo-preview'));
$filename_ascii = $use_mock ? 'memo_mock_preview.pdf' : ('memo_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $memo_no) . '.pdf');
$filename_ascii = preg_replace('/_+/', '_', (string) $filename_ascii);
$filename_th = $use_mock ? 'ตัวอย่างบันทึกข้อความ.pdf' : ('บันทึกข้อความ_' . $memo_no . '.pdf');

$mpdf->SetTitle($use_mock ? 'Memo PDF Mock Preview' : ('Memo #' . $memo_no));

try {
    $html_clean = preg_replace('/[\\x{200B}\\x{FEFF}]/u', '', $html);

    if (is_string($html_clean) && $html_clean !== '') {
        $html = $html_clean;
    }

    $mpdf->WriteHTML($html);
    $pdf = $mpdf->Output('', 'S');

    if (!is_string($pdf) || $pdf === '') {
        throw new RuntimeException('PDF output is empty');
    }

    while (ob_get_level() > $__memo_pdf_initial_ob_level) {
        ob_end_clean();
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }

    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . strlen($pdf));

    $audit_action = $download ? 'PDF_DOWNLOAD' : 'PDF_VIEW';

    if (function_exists('audit_log')) {
        audit_log('memo', $audit_action, 'SUCCESS', 'dh_memos', $use_mock ? null : $memo_id, null, $audit_payload, 'GET', 200);
    }

    $disposition = $download ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename_th));

    echo $pdf;
} catch (Throwable $exception) {
    error_log('Memo PDF render failed: ' . $exception->getMessage());

    if (function_exists('audit_log')) {
        audit_log('memo', 'PDF_VIEW', 'FAIL', 'dh_memos', $use_mock ? null : $memo_id, 'pdf_render_failed', $audit_payload, 'GET', 500);
    }

    $__memo_pdf_abort(500);
}

exit();
