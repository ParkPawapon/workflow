<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../modules/memos/service.php';
require_once __DIR__ . '/../modules/memos/repository.php';
require_once __DIR__ . '/../modules/users/lists.php';
require_once __DIR__ . '/../modules/system/system.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../modules/audit/logger.php';

if (!function_exists('memo_build_approver_options')) {
    function memo_build_approver_options(mysqli $connection): array
    {
        $options = [
            'DIRECTOR' => 'ผอ./รักษาการ',
        ];

        $has_positions = db_table_exists($connection, 'dh_positions');
        $sql = $has_positions
            ? 'SELECT t.pID, t.fName, p.positionName
                FROM teacher AS t
                LEFT JOIN dh_positions AS p ON t.positionID = p.positionID
                WHERE t.status = 1
                ORDER BY t.positionID ASC, t.fName ASC'
            : 'SELECT t.pID, t.fName, NULL AS positionName
                FROM teacher AS t
                WHERE t.status = 1
                ORDER BY t.positionID ASC, t.fName ASC';

        $rows = db_fetch_all($sql);

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid === '') {
                continue;
            }
            $name = trim((string) ($row['fName'] ?? ''));
            $pos = trim((string) ($row['positionName'] ?? ''));
            $label = $name !== '' ? $name : $pid;

            if ($pos !== '') {
                $label .= ' (' . $pos . ')';
            }
            $options['PERSON:' . $pid] = $label;
        }

        return $options;
    }
}

if (!function_exists('memo_owner_latest_note_by_actor')) {
    function memo_owner_latest_note_by_actor(array $routes, string $actorPID): string
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            return '';
        }

        $latestNote = '';

        foreach ($routes as $route) {
            if (trim((string) ($route['actorPID'] ?? '')) !== $actorPID) {
                continue;
            }

            $note = trim((string) ($route['note'] ?? ''));

            if ($note !== '') {
                $latestNote = $note;
            }
        }

        return $latestNote;
    }
}

if (!function_exists('memo_owner_latest_review_action_by_actor')) {
    function memo_owner_latest_review_action_by_actor(array $routes, string $actorPID): string
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            return '';
        }

        $latestAction = '';
        $reviewActions = [
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
            if (trim((string) ($route['actorPID'] ?? '')) !== $actorPID) {
                continue;
            }

            $action = strtoupper(trim((string) ($route['action'] ?? '')));

            if (in_array($action, $reviewActions, true)) {
                $latestAction = $action;
            }
        }

        return $latestAction;
    }
}

if (!function_exists('memo_owner_latest_review_actor_pid')) {
    function memo_owner_latest_review_actor_pid(array $routes): string
    {
        $latestActorPID = '';
        $reviewActions = [
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

            if (!in_array($action, $reviewActions, true)) {
                continue;
            }

            $actorPID = trim((string) ($route['actorPID'] ?? ''));

            if ($actorPID !== '') {
                $latestActorPID = $actorPID;
            }
        }

        return $latestActorPID;
    }
}

if (!function_exists('memo_owner_resolve_stage_note')) {
    function memo_owner_resolve_stage_note(array $memo, array $routes, string $actorPID): string
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            return '';
        }

        $routeNote = memo_owner_latest_note_by_actor($routes, $actorPID);

        if ($routeNote !== '') {
            return $routeNote;
        }

        $reviewNote = trim((string) ($memo['reviewNote'] ?? ''));

        if ($reviewNote === '') {
            return '';
        }

        if (trim((string) ($memo['approvedByPID'] ?? '')) === $actorPID) {
            return $reviewNote;
        }

        return memo_owner_latest_review_actor_pid($routes) === $actorPID ? $reviewNote : '';
    }
}

if (!function_exists('memo_owner_resolve_stage_action')) {
    function memo_owner_resolve_stage_action(array $memo, array $routes, string $actorPID, string $stage = ''): string
    {
        $actorPID = trim($actorPID);

        if ($actorPID === '') {
            return '';
        }

        $action = memo_owner_latest_review_action_by_actor($routes, $actorPID);

        if ($action !== '') {
            return $action;
        }

        $status = strtoupper(trim((string) ($memo['status'] ?? '')));
        $approvedPID = trim((string) ($memo['approvedByPID'] ?? ''));
        $stage = strtoupper(trim($stage));

        if ($approvedPID === $actorPID && $stage === 'DEPUTY' && $status === MEMO_STATUS_APPROVED_UNSIGNED) {
            return 'APPROVE_UNSIGNED';
        }

        return '';
    }
}

if (!function_exists('memo_owner_resolve_chain_from_routes')) {
    function memo_owner_resolve_chain_from_routes(array $memo, array $chain, array $routes): array
    {
        $flowStage = strtoupper(trim((string) ($memo['flowStage'] ?? '')));
        $forwardActors = [];
        $hasDirectorReview = false;
        $deputyCandidateCache = [];
        $directorActions = [
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
        $deputyActions = [
            'FORWARD',
            'RETURN',
            'APPROVE_UNSIGNED',
            'REJECT',
        ];
        $isDeputyCandidate = static function (string $pid) use (&$deputyCandidateCache): bool {
            $pid = trim($pid);

            if ($pid === '') {
                return false;
            }

            if (!array_key_exists($pid, $deputyCandidateCache)) {
                $deputyCandidateCache[$pid] = memo_is_valid_deputy_candidate($pid);
            }

            return $deputyCandidateCache[$pid];
        };

        foreach ($routes as $route) {
            $actorPID = trim((string) ($route['actorPID'] ?? ''));
            $action = strtoupper(trim((string) ($route['action'] ?? '')));

            if ($actorPID === '') {
                continue;
            }

            if ($action === 'FORWARD') {
                $forwardActors[] = $actorPID;
            }

            if (in_array($action, $deputyActions, true) && $isDeputyCandidate($actorPID)) {
                $chain['DEPUTY'] = $actorPID;
                continue;
            }

            if (in_array($action, $directorActions, true)) {
                $chain['DIRECTOR'] = $actorPID;
                $hasDirectorReview = true;
            }
        }

        if (($flowStage === 'DIRECTOR' || $hasDirectorReview) && $forwardActors !== []) {
            $headPID = trim((string) ($chain['HEAD'] ?? ''));
            $directorPID = trim((string) ($chain['DIRECTOR'] ?? ''));

            for ($index = count($forwardActors) - 1; $index >= 0; $index--) {
                $actorPID = trim((string) ($forwardActors[$index] ?? ''));

                if ($actorPID === '' || $actorPID === $headPID || $actorPID === $directorPID) {
                    continue;
                }

                $chain['DEPUTY'] = $actorPID;
                break;
            }
        }

        $approvedPID = trim((string) ($memo['approvedByPID'] ?? ''));
        $memoStatus = strtoupper(trim((string) ($memo['status'] ?? '')));

        if ($memoStatus === MEMO_STATUS_APPROVED_UNSIGNED && $approvedPID !== '' && $isDeputyCandidate($approvedPID)) {
            $chain['DEPUTY'] = $approvedPID;
        }

        return $chain;
    }
}

if (!function_exists('memo_owner_fetch_teacher_profiles')) {
    function memo_owner_fetch_teacher_profiles(mysqli $connection, array $pids): array
    {
        $normalized = [];

        foreach ($pids as $pid) {
            $value = trim((string) $pid);

            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        $pids = array_keys($normalized);

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

        $items = [];

        foreach ($rows as $row) {
            $pid = trim((string) ($row['pID'] ?? ''));

            if ($pid === '') {
                continue;
            }

            $items[$pid] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'signature' => trim((string) ($row['signature'] ?? '')),
                'positionName' => trim((string) ($row['positionName'] ?? '')),
            ];
        }

        return $items;
    }
}

if (!function_exists('memo_owner_enrich_creator_memos')) {
    function memo_owner_enrich_creator_memos(mysqli $connection, array $memos): array
    {
        if ($memos === []) {
            return [];
        }

        $profilePids = [];
        $routeMap = [];
        $chainMap = [];

        foreach ($memos as $index => $memo) {
            $memoID = (int) ($memo['memoID'] ?? 0);
            $routes = $memoID > 0 ? memo_list_routes($memoID) : [];
            $chain = memo_owner_resolve_chain_from_routes($memo, [
                'HEAD' => trim((string) ($memo['headPID'] ?? '')),
                'DEPUTY' => trim((string) ($memo['deputyPID'] ?? '')),
                'DIRECTOR' => trim((string) ($memo['directorPID'] ?? '')),
            ], $routes);

            $routeMap[$index] = $routes;
            $chainMap[$index] = $chain;

            foreach (['HEAD', 'DEPUTY', 'DIRECTOR'] as $stage) {
                $profilePids[] = (string) ($chain[$stage] ?? '');
            }

            foreach ($routes as $route) {
                $profilePids[] = (string) ($route['actorPID'] ?? '');
                $profilePids[] = (string) ($route['toPID'] ?? '');
            }
        }

        $profiles = memo_owner_fetch_teacher_profiles($connection, $profilePids);

        foreach ($memos as $index => $memo) {
            $routes = $routeMap[$index] ?? [];
            $chain = $chainMap[$index] ?? [
                'HEAD' => trim((string) ($memo['headPID'] ?? '')),
                'DEPUTY' => trim((string) ($memo['deputyPID'] ?? '')),
                'DIRECTOR' => trim((string) ($memo['directorPID'] ?? '')),
            ];
            $memoStatus = strtoupper(trim((string) ($memo['status'] ?? '')));
            $deputyPID = trim((string) ($chain['DEPUTY'] ?? ''));
            $directorPID = trim((string) ($chain['DIRECTOR'] ?? ''));
            $deputyAction = memo_owner_resolve_stage_action($memo, $routes, $deputyPID, 'DEPUTY');
            $returnedReviewerPID = $memoStatus === MEMO_STATUS_RETURNED
                ? memo_latest_return_actor_pid($routes)
                : '';
            $returnedReviewerRouteName = '';

            if ($returnedReviewerPID !== '') {
                for ($routeIndex = count($routes) - 1; $routeIndex >= 0; $routeIndex--) {
                    $route = $routes[$routeIndex] ?? [];

                    if (strtoupper(trim((string) ($route['action'] ?? ''))) !== 'RETURN') {
                        continue;
                    }

                    if (trim((string) ($route['actorPID'] ?? '')) !== $returnedReviewerPID) {
                        continue;
                    }

                    $returnedReviewerRouteName = trim((string) ($route['actorName'] ?? ''));
                    break;
                }
            }
            $suppressDirectorStage = $memoStatus === MEMO_STATUS_APPROVED_UNSIGNED
                || (
                    $deputyPID !== ''
                    && $directorPID !== ''
                    && $directorPID === $deputyPID
                    && strtoupper($deputyAction) === 'APPROVE_UNSIGNED'
                );

            foreach ([
                'head' => trim((string) ($chain['HEAD'] ?? '')),
                'deputy' => $deputyPID,
                'director' => $suppressDirectorStage ? '' : $directorPID,
            ] as $prefix => $stagePID) {
                if ($stagePID === '') {
                    $memos[$index][$prefix . 'Name'] = '';
                    $memos[$index][$prefix . 'Signature'] = '';
                    $memos[$index][$prefix . 'PositionName'] = '';
                    $memos[$index][$prefix . 'Note'] = '';
                    $memos[$index][$prefix . 'Action'] = '';
                    continue;
                }

                $profile = $profiles[$stagePID] ?? [];

                $memos[$index][$prefix . 'Name'] = trim((string) ($profile['name'] ?? ($memo[$prefix . 'Name'] ?? '')));
                $memos[$index][$prefix . 'Signature'] = trim((string) ($profile['signature'] ?? ''));
                $memos[$index][$prefix . 'PositionName'] = trim((string) ($profile['positionName'] ?? ''));
                $memos[$index][$prefix . 'Note'] = memo_owner_resolve_stage_note($memo, $routes, $stagePID);
                $memos[$index][$prefix . 'Action'] = memo_owner_resolve_stage_action($memo, $routes, $stagePID, strtoupper($prefix));
            }

            $memos[$index]['ownerCanEditBeforeHeadForward'] = memo_owner_can_edit_before_head_forward(
                $memo,
                (string) ($memo['createdByPID'] ?? ''),
                $routes
            );
            $returnedReviewerProfile = $returnedReviewerPID !== ''
                ? ($profiles[$returnedReviewerPID] ?? [])
                : [];
            $memos[$index]['returnedReviewerPID'] = $returnedReviewerPID;
            $memos[$index]['returnedReviewerName'] = trim((string) (
                $returnedReviewerProfile['name']
                ?? $returnedReviewerRouteName
                ?? ''
            ));
        }

        return $memos;
    }
}

if (!function_exists('memo_list_sender_factions')) {
    function memo_list_sender_factions(mysqli $connection): array
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

if (!function_exists('memo_has_meaningful_content')) {
    function memo_has_meaningful_content(?string $value): bool
    {
        $raw = (string) ($value ?? '');

        if ($raw === '') {
            return false;
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);
        $normalized = str_replace(["\u{00A0}", "\xc2\xa0"], ' ', $stripped);
        $compact = preg_replace('/\s+/u', '', $normalized);

        return trim((string) $compact) !== '';
    }
}

if (!function_exists('memo_index')) {
    function memo_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');
        $active_tab = trim((string) ($_GET['tab'] ?? ''));

        if (!in_array($active_tab, ['compose', 'track'], true)) {
            $active_tab = 'compose';
        }
        $is_track_active = $active_tab === 'track';

        $search = trim((string) ($_GET['q'] ?? ''));
        $status_filter = (string) ($_GET['status'] ?? 'all');
        $sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));

        if (!in_array($sort, ['newest', 'oldest'], true)) {
            $sort = 'newest';
        }
        $allowed_status = [
            'all',
            MEMO_STATUS_DRAFT,
            MEMO_STATUS_SUBMITTED,
            MEMO_STATUS_IN_REVIEW,
            MEMO_STATUS_RETURNED,
            MEMO_STATUS_APPROVED_UNSIGNED,
            MEMO_STATUS_SIGNED,
            MEMO_STATUS_REJECTED,
            MEMO_STATUS_CANCELLED,
        ];

        if (!in_array($status_filter, $allowed_status, true)) {
            $status_filter = 'all';
        }

        $page = (int) ($_GET['page'] ?? 1);
        $page = $page > 0 ? $page : 1;
        $per_page = 10;

        $alert = null;
        $default_sender_fid = (string) (int) ($current_user['fID'] ?? 0);

        if ($default_sender_fid === '1' || $default_sender_fid === '0') {
            $default_sender_fid = '';
        }
        $values = [
            'writeDate' => (string) date('Y-m-d'),
            'to_choice' => 'DIRECTOR',
            'sender_fid' => $default_sender_fid,
            'subject' => '',
            'detail' => '',
        ];

        $connection = db_connection();
        $has_memo_table = db_table_exists($connection, 'dh_memos');
        $has_route_table = db_table_exists($connection, 'dh_memo_routes');

        $approver_options = memo_build_approver_options($connection);
        $factions = memo_list_sender_factions($connection);
        $executive_position_ids = array_values(array_unique(array_filter(array_merge(
            [((int) (system_position_executive_id($connection) ?? 0))],
            system_position_deputy_ids($connection),
            [5]
        ))));

        if (empty($executive_position_ids)) {
            $executive_position_ids = [1, 2, 3, 4, 5];
        }

        $teachers = array_values(array_filter(user_list_teachers(), static function (array $teacher) use ($current_pid, $executive_position_ids): bool {
            $pid = trim((string) ($teacher['pID'] ?? ''));
            $position_id = (int) ($teacher['positionID'] ?? 0);

            if ($pid === '' || $pid === $current_pid) {
                return false;
            }

            if (!ctype_digit($pid)) {
                return false;
            }

            return in_array($position_id, $executive_position_ids, true);
        }));

        if ($values['sender_fid'] === '' && !empty($factions)) {
            $values['sender_fid'] = (string) ($factions[0]['fID'] ?? '');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post_action = trim((string) ($_POST['action'] ?? ''));

            if ($post_action !== '') {
                $active_tab = 'track';
                $is_track_active = true;
            }

            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ];
            } elseif (!$has_memo_table || !$has_route_table) {
                $alert = system_not_ready_alert('ยังไม่พบตาราง memo workflow กรุณารัน migrations/011_update_memos_workflow.sql');
            } elseif ($post_action !== '') {
                $memo_id = (int) ($_POST['memo_id'] ?? 0);

                if ($memo_id <= 0) {
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ข้อมูลไม่ครบถ้วน',
                        'message' => 'ไม่พบรายการบันทึกข้อความ',
                    ];
                } else {
                    try {
                        if ($post_action === 'submit') {
                            $submit_subject = trim((string) ($_POST['subject'] ?? ''));
                            $submit_detail = trim((string) ($_POST['detail'] ?? ''));
                            $submit_to_pid_raw = $_POST['memo_to_pid'] ?? '';
                            $submit_to_pid = is_string($submit_to_pid_raw) ? trim($submit_to_pid_raw) : '';
                            $uploaded_files = isset($_FILES['attachments']) && is_array($_FILES['attachments']) ? $_FILES['attachments'] : [];
                            $memo_before_submit = memo_get($memo_id);
                            $is_returned_resubmit = is_array($memo_before_submit)
                                && strtoupper(trim((string) ($memo_before_submit['status'] ?? ''))) === MEMO_STATUS_RETURNED
                                && trim((string) ($memo_before_submit['createdByPID'] ?? '')) === $current_pid;
                            $can_edit_before_head_forward = is_array($memo_before_submit)
                                && memo_owner_can_edit_before_head_forward($memo_before_submit, $current_pid);
                            $allowed_submit_to_pids = array_fill_keys(
                                array_map(
                                    static fn(array $teacher): string => (string) ($teacher['pID'] ?? ''),
                                    $teachers
                                ),
                                true
                            );

                            if (!$can_edit_before_head_forward && !$is_returned_resubmit) {
                                if ($submit_to_pid === '' || !preg_match('/^\\d{1,13}$/', $submit_to_pid)) {
                                    throw new RuntimeException('กรุณาเลือกผู้รับเอกสารอย่างน้อย 1 คน');
                                }
                                if (!isset($allowed_submit_to_pids[$submit_to_pid])) {
                                    throw new RuntimeException('ผู้รับเอกสารไม่ถูกต้อง');
                                }
                            }
                            if (!memo_has_meaningful_content($submit_detail)) {
                                throw new RuntimeException('กรุณากรอกรายละเอียด');
                            }

                            $update_data = [
                                'subject' => $submit_subject,
                                'detail' => $submit_detail,
                            ];

                            if (!$can_edit_before_head_forward && !$is_returned_resubmit) {
                                $update_data['toType'] = 'PERSON';
                                $update_data['toPID'] = $submit_to_pid;
                                $update_data['flowMode'] = 'DIRECT';
                            }

                            memo_update_draft(
                                $memo_id,
                                $current_pid,
                                $update_data,
                                $uploaded_files
                            );

                            if (!$can_edit_before_head_forward) {
                                memo_submit($memo_id, $current_pid);
                            }
                            $alert = [
                                'type' => 'success',
                                'title' => $can_edit_before_head_forward ? 'บันทึกการแก้ไขแล้ว' : 'ส่งเสนอแฟ้มเรียบร้อย',
                                'message' => '',
                            ];
                        } elseif ($post_action === 'recall') {
                            memo_recall($memo_id, $current_pid);
                            $alert = [
                                'type' => 'success',
                                'title' => 'ดึงกลับเพื่อแก้ไขแล้ว',
                                'message' => '',
                            ];
                        } elseif ($post_action === 'cancel') {
                            memo_cancel($memo_id, $current_pid);
                            $alert = [
                                'type' => 'success',
                                'title' => 'ยกเลิกรายการแล้ว',
                                'message' => '',
                            ];
                        } elseif ($post_action === 'archive') {
                            memo_set_archived($memo_id, $current_pid, true);
                            $alert = [
                                'type' => 'success',
                                'title' => 'จัดเก็บรายการแล้ว',
                                'message' => '',
                            ];
                        } else {
                            throw new RuntimeException('ไม่รองรับคำสั่งที่ร้องขอ');
                        }
                    } catch (Throwable $e) {
                        $alert = [
                            'type' => 'danger',
                            'title' => 'เกิดข้อผิดพลาด',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            } else {
                $values['writeDate'] = trim((string) ($_POST['writeDate'] ?? '')) ?: (string) date('Y-m-d');
                $values['to_choice'] = trim((string) ($_POST['to_choice'] ?? 'DIRECTOR')) ?: 'DIRECTOR';
                $values['sender_fid'] = trim((string) ($_POST['sender_fid'] ?? $values['sender_fid']));
                $values['subject'] = trim((string) ($_POST['subject'] ?? ''));
                $values['detail'] = trim((string) ($_POST['detail'] ?? ''));

                if ($values['subject'] === '') {
                    $alert = [
                        'type' => 'danger',
                        'title' => 'กรุณากรอกหัวข้อ',
                        'message' => '',
                    ];
                } elseif (!memo_has_meaningful_content($values['detail'])) {
                    $alert = [
                        'type' => 'danger',
                        'title' => 'กรุณากรอกรายละเอียด',
                        'message' => '',
                    ];
                } else {
                    $toType = null;
                    $toPID = null;

                    if ($values['to_choice'] === 'DIRECTOR') {
                        $toType = 'DIRECTOR';
                    } elseif (str_starts_with($values['to_choice'], 'PERSON:')) {
                        $pid = trim(substr($values['to_choice'], 7));

                        if ($pid !== '' && preg_match('/^\\d{1,13}$/', $pid)) {
                            $toType = 'PERSON';
                            $toPID = $pid;
                        }
                    }

                    try {
                        $flow_mode = trim((string) ($_POST['flow_mode'] ?? 'CHAIN'));
                        $flow_mode = strtoupper($flow_mode) === 'DIRECT' ? 'DIRECT' : 'CHAIN';

                        $memoID = memo_create_draft([
                            'dh_year' => system_get_dh_year(),
                            'writeDate' => $values['writeDate'] !== '' ? $values['writeDate'] : null,
                            'subject' => $values['subject'],
                            'detail' => $values['detail'],
                            'toType' => $toType,
                            'toPID' => $toPID,
                            'flowMode' => $flow_mode,
                            'createdByPID' => $current_pid,
                        ]);

                        $alert = [
                            'type' => 'success',
                            'title' => 'สร้างบันทึกข้อความแล้ว',
                            'message' => 'เลขที่รายการ #' . $memoID,
                        ];
                        $values = [
                            'writeDate' => (string) date('Y-m-d'),
                            'to_choice' => 'DIRECTOR',
                            'sender_fid' => $values['sender_fid'],
                            'subject' => '',
                            'detail' => '',
                        ];
                    } catch (Throwable $e) {
                        $alert = [
                            'type' => 'danger',
                            'title' => 'เกิดข้อผิดพลาด',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        if ((!$has_memo_table || !$has_route_table) && $alert === null) {
            $alert = system_not_ready_alert('ยังไม่พบตาราง memo workflow กรุณารัน migrations/011_update_memos_workflow.sql');
        }

        $total_pages = 1;
        $filtered_total = 0;

        if (!$has_memo_table || !$has_route_table) {
            $memos = [];
        } else {
            $filtered_total = memo_count_by_creator($current_pid, false, $status_filter, $search);
            $total_pages = max(1, (int) ceil($filtered_total / $per_page));

            if ($page > $total_pages) {
                $page = $total_pages;
            }
            $offset = ($page - 1) * $per_page;
            $memos = memo_list_by_creator_page($current_pid, false, $status_filter, $search, $per_page, $offset, $sort);
            $memos = memo_owner_enrich_creator_memos($connection, $memos);
        }

        $base_params = ['tab' => 'track'];

        if ($search !== '') {
            $base_params['q'] = $search;
        }

        if ($status_filter !== 'all') {
            $base_params['status'] = $status_filter;
        }

        if ($sort !== 'newest') {
            $base_params['sort'] = $sort;
        }
        $pagination_base_url = 'memo.php';

        if (!empty($base_params)) {
            $pagination_base_url .= '?' . http_build_query($base_params);
        }

        view_render('memo/index', [
            'alert' => $alert,
            'values' => $values,
            'memos' => $memos,
            'approver_options' => $approver_options,
            'factions' => $factions,
            'teachers' => $teachers,
            'current_user' => $current_user,
            'dh_year' => system_get_dh_year(),
            'page' => $page,
            'total_pages' => $total_pages,
            'search' => $search,
            'status_filter' => $status_filter,
            'sort' => $sort,
            'filtered_total' => $filtered_total,
            'pagination_base_url' => $pagination_base_url,
            'active_tab' => $active_tab,
            'is_track_active' => $is_track_active,
        ]);
    }
}
