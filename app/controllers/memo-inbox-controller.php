<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../modules/memos/service.php';
require_once __DIR__ . '/../modules/memos/repository.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../db/db.php';

if (!function_exists('memo_inbox_list_sender_factions')) {
    function memo_inbox_list_sender_factions(mysqli $connection): array
    {
        if (!db_table_exists($connection, 'faction')) {
            return [];
        }

        $rows = db_fetch_all(
            'SELECT fID, fname
             FROM faction
             WHERE fID <> 1
               AND fname NOT LIKE ?
             ORDER BY fID ASC',
            's',
            '%ฝ่ายบริหาร%'
        );

        $items = [];

        foreach ($rows as $row) {
            $fid = (int) ($row['fID'] ?? 0);
            $name = trim((string) ($row['fname'] ?? ''));

            if ($fid <= 0 || $name === '') {
                continue;
            }

            $items[] = [
                'fID' => $fid,
                'fname' => $name,
            ];
        }

        return $items;
    }
}

if (!function_exists('memo_inbox_resolve_chain_reviewer_pids')) {
    function memo_inbox_resolve_chain_reviewer_pids(array $item): array
    {
        $created_by_pid = trim((string) ($item['createdByPID'] ?? ''));
        $flow_stage = strtoupper(trim((string) ($item['flowStage'] ?? '')));
        $to_pid = trim((string) ($item['toPID'] ?? ''));
        $skips_head_stage = false;

        $chain = [
            'HEAD' => trim((string) ($item['headPID'] ?? '')),
            'DEPUTY' => trim((string) ($item['deputyPID'] ?? '')),
            'DIRECTOR' => trim((string) ($item['directorPID'] ?? '')),
        ];

        if ($created_by_pid !== '') {
            try {
                $resolved = memo_resolve_chain_approvers($created_by_pid);
                $resolved_head_pid = trim((string) ($resolved['headPID'] ?? ''));
                $resolved_deputy_pid = trim((string) ($resolved['deputyPID'] ?? ''));
                $skips_head_stage = $resolved_head_pid === '' && $resolved_deputy_pid !== '';

                if ($skips_head_stage) {
                    $chain['HEAD'] = '';
                }

                if ($chain['HEAD'] === '') {
                    $chain['HEAD'] = $resolved_head_pid;
                }

                if ($chain['DEPUTY'] === '') {
                    $chain['DEPUTY'] = $resolved_deputy_pid;
                }

                if ($chain['DIRECTOR'] === '') {
                    $chain['DIRECTOR'] = trim((string) ($resolved['directorPID'] ?? ''));
                }
            } catch (Throwable $ignored) {
            }
        }

        if ($flow_stage === 'HEAD' && $to_pid !== '' && memo_is_valid_deputy_candidate($to_pid)) {
            $chain['HEAD'] = '';
            $chain['DEPUTY'] = $to_pid;
        } elseif (in_array($flow_stage, ['HEAD', 'DEPUTY', 'DIRECTOR'], true) && $to_pid !== '') {
            if ($flow_stage === 'HEAD' && $skips_head_stage) {
                $chain['DEPUTY'] = $to_pid;
            } else {
                $chain[$flow_stage] = $to_pid;
            }
        }

        if ($chain['DIRECTOR'] === '') {
            $chain['DIRECTOR'] = trim((string) (system_get_current_director_pid() ?? ''));
        }

        return $chain;
    }
}

if (!function_exists('memo_inbox_resolve_current_reviewer_role')) {
    function memo_inbox_resolve_current_reviewer_role(array $item, string $current_pid, array $chain): string
    {
        $current_pid = trim($current_pid);

        if ($current_pid === '') {
            return '';
        }

        $flow_stage = strtoupper(trim((string) ($item['flowStage'] ?? '')));
        $to_pid = trim((string) ($item['toPID'] ?? ''));

        if ($current_pid === $to_pid && in_array($flow_stage, ['HEAD', 'DEPUTY', 'DIRECTOR'], true)) {
            if ($flow_stage === 'HEAD' && memo_is_valid_deputy_candidate($current_pid)) {
                return 'DEPUTY';
            }

            if (
                $flow_stage === 'HEAD'
                && trim((string) ($chain['HEAD'] ?? '')) !== $to_pid
                && trim((string) ($chain['DEPUTY'] ?? '')) === $to_pid
            ) {
                return 'DEPUTY';
            }

            return $flow_stage;
        }

        foreach (['HEAD', 'DEPUTY', 'DIRECTOR'] as $stage) {
            if ($current_pid === trim((string) ($chain[$stage] ?? ''))) {
                return $stage;
            }
        }

        return '';
    }
}

if (!function_exists('memo_inbox_resolve_effective_flow_stage')) {
    function memo_inbox_resolve_effective_flow_stage(array $item, array $chain): string
    {
        $flow_stage = strtoupper(trim((string) ($item['flowStage'] ?? '')));
        $to_pid = trim((string) ($item['toPID'] ?? ''));

        if ($flow_stage === 'HEAD' && $to_pid !== '' && memo_is_valid_deputy_candidate($to_pid)) {
            return 'DEPUTY';
        }

        if (
            $flow_stage === 'HEAD'
            && $to_pid !== ''
            && trim((string) ($chain['HEAD'] ?? '')) !== $to_pid
            && trim((string) ($chain['DEPUTY'] ?? '')) === $to_pid
        ) {
            return 'DEPUTY';
        }

        return $flow_stage;
    }
}

if (!function_exists('memo_inbox_resolve_chain_from_routes')) {
    function memo_inbox_resolve_chain_from_routes(array $item, array $chain, array $routes): array
    {
        $flow_stage = strtoupper(trim((string) ($item['flowStage'] ?? '')));
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

        $approved_pid = trim((string) ($item['approvedByPID'] ?? ''));
        $memo_status = strtoupper(trim((string) ($item['status'] ?? '')));

        if ($memo_status === MEMO_STATUS_APPROVED_UNSIGNED && $approved_pid !== '' && $is_deputy_candidate($approved_pid)) {
            $chain['DEPUTY'] = $approved_pid;
        }

        return $chain;
    }
}

if (!function_exists('memo_inbox_signature_file_exists')) {
    function memo_inbox_signature_file_exists(string $signature): bool
    {
        $signature = trim($signature);

        if ($signature === '') {
            return false;
        }

        if (preg_match('~^(https?:)?//~i', $signature) === 1) {
            return true;
        }

        $absolute_path = dirname(__DIR__, 2) . '/' . ltrim($signature, '/');

        return is_file($absolute_path);
    }
}

if (!function_exists('memo_inbox_find_legacy_signature')) {
    function memo_inbox_find_legacy_signature(string $pid): string
    {
        $safe_pid = preg_replace('/\D+/', '', trim($pid));

        if ($safe_pid === '') {
            return '';
        }

        $signature_dir = dirname(__DIR__, 2) . '/assets/img/signature/' . $safe_pid;

        if (!is_dir($signature_dir)) {
            return '';
        }

        $files = glob($signature_dir . '/*.{png,jpg,jpeg,webp,PNG,JPG,JPEG,WEBP}', GLOB_BRACE);

        if ($files === false || $files === []) {
            return '';
        }

        $files = array_values(array_filter($files, static function ($file): bool {
            return is_string($file) && is_file($file);
        }));

        if ($files === []) {
            return '';
        }

        usort($files, static function (string $left, string $right): int {
            $left_time = @filemtime($left) ?: 0;
            $right_time = @filemtime($right) ?: 0;

            if ($left_time === $right_time) {
                return strcmp(basename($right), basename($left));
            }

            return $right_time <=> $left_time;
        });

        return 'assets/img/signature/' . $safe_pid . '/' . basename($files[0]);
    }
}

if (!function_exists('memo_inbox_resolve_signature_path')) {
    function memo_inbox_resolve_signature_path(string $pid, string $signature): string
    {
        $signature = trim($signature);

        if ($signature !== '' && memo_inbox_signature_file_exists($signature)) {
            return $signature;
        }

        return memo_inbox_find_legacy_signature($pid);
    }
}

if (!function_exists('memo_inbox_sync_signature_reference')) {
    function memo_inbox_sync_signature_reference(mysqli $connection, string $pid, string $currentSignature, string $resolvedSignature): void
    {
        $pid = trim($pid);
        $currentSignature = trim($currentSignature);
        $resolvedSignature = trim($resolvedSignature);

        if ($pid === '' || $resolvedSignature === '' || $resolvedSignature === $currentSignature) {
            return;
        }

        $statement = mysqli_prepare($connection, 'UPDATE teacher SET signature = ? WHERE pID = ? AND status = 1');

        if ($statement === false) {
            error_log('Memo inbox signature sync prepare failed: ' . mysqli_error($connection));
            return;
        }

        mysqli_stmt_bind_param($statement, 'ss', $resolvedSignature, $pid);

        if (!mysqli_stmt_execute($statement)) {
            error_log('Memo inbox signature sync execute failed: ' . mysqli_stmt_error($statement));
        }

        mysqli_stmt_close($statement);
    }
}

if (!function_exists('memo_inbox_fetch_teacher_profiles')) {
    function memo_inbox_fetch_teacher_profiles(mysqli $connection, array $pids): array
    {
        $pids = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $pids), static function (string $value): bool {
            return $value !== '';
        })));

        if ($pids === []) {
            return [];
        }

        $position = system_position_join($connection, 't', 'p');
        $placeholders = implode(', ', array_fill(0, count($pids), '?'));
        $types = str_repeat('s', count($pids));
        $rows = db_fetch_all(
            'SELECT t.pID,
                    COALESCE(t.fName, "") AS name,
                    COALESCE(t.signature, "") AS signature,
                    COALESCE(' . $position['name'] . ', "") AS positionName
             FROM teacher AS t
             ' . $position['join'] . '
             WHERE t.pID IN (' . $placeholders . ')',
            $types,
            ...$pids
        );

        $profiles = [];

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid === '') {
                continue;
            }

            $current_signature = trim((string) ($row['signature'] ?? ''));
            $resolved_signature = memo_inbox_resolve_signature_path($pid, $current_signature);
            memo_inbox_sync_signature_reference($connection, $pid, $current_signature, $resolved_signature);

            $profiles[$pid] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'signature' => $resolved_signature,
                'positionName' => trim((string) ($row['positionName'] ?? '')),
            ];
        }

        return $profiles;
    }
}

if (!function_exists('memo_inbox_latest_note_by_actor')) {
    function memo_inbox_latest_note_by_actor(array $routes, string $actor_pid): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $latest_note = '';

        foreach ($routes as $route) {
            if (trim((string) ($route['actorPID'] ?? '')) !== $actor_pid) {
                continue;
            }

            $note = trim((string) ($route['note'] ?? ''));

            if ($note !== '') {
                $latest_note = $note;
            }
        }

        return $latest_note;
    }
}

if (!function_exists('memo_inbox_latest_review_actor_pid')) {
    function memo_inbox_latest_review_actor_pid(array $routes): string
    {
        $latest_actor_pid = '';
        $review_actions = [
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

if (!function_exists('memo_inbox_latest_review_action_by_actor')) {
    function memo_inbox_latest_review_action_by_actor(array $routes, string $actor_pid): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $latest_action = '';
        $review_actions = [
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

if (!function_exists('memo_inbox_resolve_stage_note')) {
    function memo_inbox_resolve_stage_note(array $item, array $routes, string $actor_pid): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $route_note = memo_inbox_latest_note_by_actor($routes, $actor_pid);

        if ($route_note !== '') {
            return $route_note;
        }

        $review_note = trim((string) ($item['reviewNote'] ?? ''));

        if ($review_note === '') {
            return '';
        }

        if (trim((string) ($item['approvedByPID'] ?? '')) === $actor_pid) {
            return $review_note;
        }

        return memo_inbox_latest_review_actor_pid($routes) === $actor_pid ? $review_note : '';
    }
}

if (!function_exists('memo_inbox_resolve_stage_action')) {
    function memo_inbox_resolve_stage_action(array $item, array $routes, string $actor_pid, string $stage = ''): string
    {
        $actor_pid = trim($actor_pid);

        if ($actor_pid === '') {
            return '';
        }

        $action = memo_inbox_latest_review_action_by_actor($routes, $actor_pid);

        if ($action !== '') {
            return $action;
        }

        $status = strtoupper(trim((string) ($item['status'] ?? '')));
        $approved_pid = trim((string) ($item['approvedByPID'] ?? ''));
        $stage = strtoupper(trim($stage));

        if ($approved_pid === $actor_pid && $stage === 'DEPUTY' && $status === MEMO_STATUS_APPROVED_UNSIGNED) {
            return 'APPROVE_UNSIGNED';
        }

        return '';
    }
}

if (!function_exists('memo_inbox_enrich_items')) {
    function memo_inbox_enrich_items(mysqli $connection, array $items, string $current_pid): array
    {
        if ($items === []) {
            return [];
        }

        $chain_map = [];
        $routes_map = [];
        $profile_pids = [];

        foreach ($items as $index => $item) {
            $memo_id = (int) ($item['memoID'] ?? 0);
            $routes = $memo_id > 0 ? memo_list_routes($memo_id) : [];
            $chain = memo_inbox_resolve_chain_reviewer_pids($item);
            $chain = memo_inbox_resolve_chain_from_routes($item, $chain, $routes);
            $chain_map[$memo_id] = $chain;
            $routes_map[$memo_id] = $routes;

            $items[$index]['effectiveFlowStage'] = memo_inbox_resolve_effective_flow_stage($item, $chain);
            $items[$index]['reviewerRole'] = memo_inbox_resolve_current_reviewer_role($item, $current_pid, $chain);
            $profile_pids[] = trim((string) ($item['createdByPID'] ?? ''));
            $profile_pids[] = $chain['HEAD'] ?? '';
            $profile_pids[] = $chain['DEPUTY'] ?? '';
            $profile_pids[] = $chain['DIRECTOR'] ?? '';
        }

        $teacher_profiles = memo_inbox_fetch_teacher_profiles($connection, $profile_pids);

        foreach ($items as $index => $item) {
            $memo_id = (int) ($item['memoID'] ?? 0);
            $chain = $chain_map[$memo_id] ?? ['HEAD' => '', 'DEPUTY' => '', 'DIRECTOR' => ''];
            $routes = $routes_map[$memo_id] ?? [];
            $creator_pid = trim((string) ($item['createdByPID'] ?? ''));
            $creator_profile = $teacher_profiles[$creator_pid] ?? [];
            $memo_status = strtoupper(trim((string) ($item['status'] ?? '')));

            $items[$index]['creatorSignature'] = trim((string) ($creator_profile['signature'] ?? ($item['creatorSignature'] ?? '')));
            $items[$index]['creatorName'] = trim((string) ($creator_profile['name'] ?? ($item['creatorName'] ?? '')));
            $items[$index]['creatorPositionName'] = trim((string) ($creator_profile['positionName'] ?? ($item['creatorPositionName'] ?? '')));
            $items[$index]['headResolvedPID'] = $chain['HEAD'] ?? '';
            $items[$index]['deputyResolvedPID'] = $chain['DEPUTY'] ?? '';
            $items[$index]['directorResolvedPID'] = $chain['DIRECTOR'] ?? '';

            foreach ([
                'head' => 'HEAD',
                'deputy' => 'DEPUTY',
                'director' => 'DIRECTOR',
            ] as $prefix => $stage) {
                $stage_pid = trim((string) ($chain[$stage] ?? ''));
                $profile = $teacher_profiles[$stage_pid] ?? [];

                $items[$index][$prefix . 'Name'] = trim((string) ($profile['name'] ?? ''));
                $items[$index][$prefix . 'Signature'] = trim((string) ($profile['signature'] ?? ''));
                $items[$index][$prefix . 'PositionName'] = trim((string) ($profile['positionName'] ?? ''));

                if ($stage === 'DIRECTOR' && $memo_status === MEMO_STATUS_APPROVED_UNSIGNED) {
                    $items[$index][$prefix . 'Note'] = '';
                    $items[$index][$prefix . 'Action'] = '';
                    continue;
                }

                $items[$index][$prefix . 'Note'] = memo_inbox_resolve_stage_note($item, $routes, $stage_pid);
                $items[$index][$prefix . 'Action'] = memo_inbox_resolve_stage_action($item, $routes, $stage_pid, $stage);
            }
        }

        return $items;
    }
}

if (!function_exists('memo_inbox_index')) {
    function memo_inbox_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');

        $search = trim((string) ($_GET['q'] ?? ''));
        $status_filter = (string) ($_GET['status'] ?? 'all');
        $allowed = [
            'all',
            'signed_all',
            MEMO_STATUS_SUBMITTED,
            MEMO_STATUS_IN_REVIEW,
            MEMO_STATUS_RETURNED,
            MEMO_STATUS_APPROVED_UNSIGNED,
            MEMO_STATUS_SIGNED,
            MEMO_STATUS_REJECTED,
            MEMO_STATUS_CANCELLED,
        ];

        if (!in_array($status_filter, $allowed, true)) {
            $status_filter = 'all';
        }

        if (in_array($status_filter, [MEMO_STATUS_APPROVED_UNSIGNED, MEMO_STATUS_SIGNED], true)) {
            $status_filter = 'signed_all';
        }

        $filter_sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));

        if (!in_array($filter_sort, ['newest', 'oldest'], true)) {
            $filter_sort = 'newest';
        }

        $page = (int) ($_GET['page'] ?? 1);
        $page = $page > 0 ? $page : 1;
        $per_page = 10;

        $alert = null;
        $connection = db_connection();
        $has_table = db_table_exists($connection, 'dh_memos');
        $has_routes = db_table_exists($connection, 'dh_memo_routes');
        $current_thai_year = (int) date('Y') + 543;
        $active_dh_year = system_get_dh_year();
        $dh_year_options = [];
        $factions = memo_inbox_list_sender_factions($connection);
        $deputy_candidates = memo_list_deputy_candidates($current_pid);

        if ($active_dh_year < 2568 || $active_dh_year > ($current_thai_year + 1)) {
            $active_dh_year = $current_thai_year;
        }
        $selected_dh_year = (int) ($_GET['dh_year'] ?? 0);

        $total_pages = 1;
        $filtered_total = 0;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post_action = trim((string) ($_POST['action'] ?? ''));
            $director_actions = [
                'director_signed' => 'ลงนามแล้ว',
                'director_acknowledged' => 'ทราบ',
                'director_agreed' => 'ชอบ',
                'director_notified' => 'แจ้ง',
                'director_assigned' => 'มอบ',
                'director_scheduled' => 'ลงนัด',
                'director_permitted' => 'อนุญาต',
                'director_approved' => 'อนุมัติ',
                'director_rejected' => 'ไม่อนุมัติ',
                'director_request_meeting' => 'ขอพบ',
            ];
            $resolve_post_note = static function (): string {
                $note = (string) ($_POST['note'] ?? '');

                if (trim($note) !== '') {
                    return $note;
                }

                foreach (['modal_head_note', 'modal_deputy_note', 'modal_director_note'] as $field) {
                    $fallback_note = (string) ($_POST[$field] ?? '');

                    if (trim($fallback_note) !== '') {
                        return $fallback_note;
                    }
                }

                return '';
            };

            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ];
            } elseif ($post_action === 'archive_selected') {
                try {
                    if (!$has_table || !$has_routes) {
                        throw new RuntimeException('ยังไม่พบตาราง memo workflow กรุณารัน migrations/011_update_memos_workflow.sql');
                    }

                    $selected_ids = array_values(array_unique(array_filter(array_map(static function ($value): int {
                        return (int) $value;
                    }, (array) ($_POST['selected_ids'] ?? [])), static function (int $memo_id): bool {
                        return $memo_id > 0;
                    })));

                    if ($selected_ids === []) {
                        throw new RuntimeException('กรุณาเลือกรายการที่ต้องการจัดเก็บ');
                    }

                    $archived_count = 0;
                    $last_error = '';

                    foreach ($selected_ids as $selected_memo_id) {
                        try {
                            memo_set_reviewer_archived($selected_memo_id, $current_pid, true);
                            $archived_count++;
                        } catch (Throwable $itemError) {
                            $last_error = $itemError->getMessage();
                        }
                    }

                    if ($archived_count <= 0) {
                        throw new RuntimeException($last_error !== '' ? $last_error : 'ไม่สามารถจัดเก็บรายการที่เลือกได้');
                    }

                    $alert = [
                        'type' => 'success',
                        'title' => 'จัดเก็บรายการเรียบร้อย',
                        'message' => $archived_count . ' รายการ',
                    ];
                } catch (Throwable $e) {
                    $alert = [
                        'type' => 'danger',
                        'title' => 'เกิดข้อผิดพลาด',
                        'message' => $e->getMessage(),
                    ];
                }
            } elseif (in_array($post_action, array_merge(['forward', 'return', 'director_approve', 'director_reject', 'approve_unsigned', 'reject'], array_keys($director_actions)), true)) {
                try {
                    $memo_id = (int) ($_POST['memo_id'] ?? 0);
                    $note = $resolve_post_note();
                    $target_pid = trim((string) ($_POST['target_pid'] ?? ''));

                    if ($memo_id <= 0) {
                        throw new RuntimeException('ไม่พบบันทึกข้อความ');
                    }

                    if ($post_action === 'forward') {
                        memo_forward($memo_id, $current_pid, $note, $target_pid);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ส่งต่อรายการเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($post_action === 'return') {
                        memo_return($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ตีกลับแก้ไขแล้ว',
                            'message' => '',
                        ];
                    } elseif ($post_action === 'director_approve') {
                        memo_director_approve($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ผู้อำนวยการอนุมัติเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif (isset($director_actions[$post_action])) {
                        memo_director_process($memo_id, $current_pid, $post_action, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ผู้อำนวยการดำเนินการ "' . $director_actions[$post_action] . '" เรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($post_action === 'approve_unsigned') {
                        memo_approve_unsigned($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ลงนามแล้วเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($post_action === 'reject') {
                        memo_reject($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ไม่อนุมัติรายการแล้ว',
                            'message' => '',
                        ];
                    } else {
                        memo_director_reject($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ผู้อำนวยการไม่อนุมัติรายการแล้ว',
                            'message' => '',
                        ];
                    }
                } catch (Throwable $e) {
                    $alert = [
                        'type' => 'danger',
                        'title' => 'เกิดข้อผิดพลาด',
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        if (!$has_table || !$has_routes) {
            $alert = system_not_ready_alert('ยังไม่พบตาราง memo workflow กรุณารัน migrations/011_update_memos_workflow.sql');
            $items = [];
        } else {
            $dh_year_options = memo_list_reviewer_years($current_pid);

            if (!in_array($active_dh_year, $dh_year_options, true)) {
                array_unshift($dh_year_options, $active_dh_year);
            }

            $dh_year_options = array_values(array_unique(array_filter($dh_year_options, static function (int $year): bool {
                return $year >= 2568;
            })));
            rsort($dh_year_options);

            if (!in_array($selected_dh_year, $dh_year_options, true)) {
                $selected_dh_year = (int) ($dh_year_options[0] ?? $active_dh_year);
            }

            $filtered_total = memo_count_by_reviewer($current_pid, $status_filter, $search, $selected_dh_year);
            $total_pages = max(1, (int) ceil($filtered_total / $per_page));

            if ($page > $total_pages) {
                $page = $total_pages;
            }
            $offset = ($page - 1) * $per_page;
            $items = memo_list_by_reviewer_page($current_pid, $status_filter, $search, $per_page, $offset, $selected_dh_year, $filter_sort);
            $items = memo_inbox_enrich_items($connection, $items, $current_pid);
        }

        $base_params = [];

        if ($search !== '') {
            $base_params['q'] = $search;
        }

        if ($status_filter !== 'all') {
            $base_params['status'] = $status_filter;
        }

        if ($selected_dh_year > 0) {
            $base_params['dh_year'] = (string) $selected_dh_year;
        }

        if ($filter_sort !== 'newest') {
            $base_params['sort'] = $filter_sort;
        }

        $pagination_base_url = 'memo-inbox.php';

        if (!empty($base_params)) {
            $pagination_base_url .= '?' . http_build_query($base_params);
        }

        view_render('memo/inbox', [
            'alert' => $alert,
            'items' => $items,
            'page' => $page,
            'total_pages' => $total_pages,
            'search' => $search,
            'status_filter' => $status_filter,
            'filter_sort' => $filter_sort,
            'dh_year_options' => $dh_year_options,
            'selected_dh_year' => $selected_dh_year,
            'filtered_total' => $filtered_total,
            'pagination_base_url' => $pagination_base_url,
            'current_user' => $current_user,
            'factions' => $factions,
            'deputy_candidates' => $deputy_candidates,
        ]);
    }
}
