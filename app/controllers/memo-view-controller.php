<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/memos/service.php';
require_once __DIR__ . '/../modules/memos/repository.php';
require_once __DIR__ . '/../db/db.php';

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

if (!function_exists('memo_view_index')) {
    function memo_view_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');

        $memo_id = (int) ($_GET['memo_id'] ?? 0);

        if ($memo_id <= 0) {
            http_response_code(400);
            echo 'Bad Request';

            return;
        }

        $alert = null;
        $connection = db_connection();
        $has_table = db_table_exists($connection, 'dh_memos');
        $has_routes = db_table_exists($connection, 'dh_memo_routes');

        if (!$has_table) {
            $alert = system_not_ready_alert('ยังไม่พบตาราง dh_memos กรุณารัน migrations/011_update_memos_workflow.sql');
            view_render('memo/view', [
                'alert' => $alert,
                'memo' => null,
                'attachments' => [],
                'signed_file' => null,
                'routes' => [],
                'approver_options' => [],
                'access' => [],
            ]);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string) ($_POST['action'] ?? '');

            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ];
            } else {
                try {
                    if ($action === 'update_draft') {
                        $data = [
                            'writeDate' => trim((string) ($_POST['writeDate'] ?? '')) ?: null,
                            'subject' => trim((string) ($_POST['subject'] ?? '')),
                            'detail' => trim((string) ($_POST['detail'] ?? '')),
                        ];

                        $to_choice = trim((string) ($_POST['to_choice'] ?? ''));

                        if ($to_choice === 'DIRECTOR') {
                            $data['toType'] = 'DIRECTOR';
                            $data['toPID'] = null;
                        } elseif (str_starts_with($to_choice, 'PERSON:')) {
                            $pid = trim(substr($to_choice, 7));

                            if ($pid !== '' && preg_match('/^\\d{1,13}$/', $pid)) {
                                $data['toType'] = 'PERSON';
                                $data['toPID'] = $pid;
                            }
                        }

                        memo_update_draft($memo_id, $current_pid, $data, $_FILES['attachments'] ?? []);
                        $alert = [
                            'type' => 'success',
                            'title' => 'บันทึกการแก้ไขแล้ว',
                            'message' => '',
                        ];
                    } elseif ($action === 'submit') {
                        memo_submit($memo_id, $current_pid);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ส่งเสนอเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($action === 'recall') {
                        memo_recall($memo_id, $current_pid);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ดึงกลับเพื่อแก้ไขแล้ว',
                            'message' => '',
                        ];
                    } elseif ($action === 'cancel') {
                        memo_cancel($memo_id, $current_pid);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ยกเลิกรายการแล้ว',
                            'message' => '',
                        ];
                    } elseif ($action === 'forward') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_forward($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ส่งต่อรายการเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($action === 'return') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_return($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ตีกลับแก้ไขแล้ว',
                            'message' => '',
                        ];
                    } elseif ($action === 'reject') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_reject($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ไม่อนุมัติรายการแล้ว',
                            'message' => '',
                        ];
                    } elseif ($action === 'director_approve') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_director_approve($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ผู้อำนวยการอนุมัติเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($action === 'director_reject') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_director_reject($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ผู้อำนวยการไม่อนุมัติรายการแล้ว',
                            'message' => '',
                        ];
                    } elseif ($action === 'approve_unsigned') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_approve_unsigned($memo_id, $current_pid, $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ลงนามแล้วเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($action === 'sign_upload') {
                        $note = trim((string) ($_POST['note'] ?? ''));
                        memo_sign_with_upload($memo_id, $current_pid, $_FILES['signed_attachment'] ?? [], $note);
                        $alert = [
                            'type' => 'success',
                            'title' => 'ลงนามเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($action === 'archive') {
                        memo_set_archived($memo_id, $current_pid, true);
                        $alert = [
                            'type' => 'success',
                            'title' => 'จัดเก็บเรียบร้อย',
                            'message' => '',
                        ];
                    } elseif ($action === 'unarchive') {
                        memo_set_archived($memo_id, $current_pid, false);
                        $alert = [
                            'type' => 'success',
                            'title' => 'นำออกจากที่จัดเก็บแล้ว',
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

        $memo = memo_get($memo_id);

        if (!$memo) {
            http_response_code(404);
            echo 'Not Found';

            return;
        }

        $memo_status = (string) ($memo['status'] ?? '');
        $is_submitted_or_legacy = !empty($memo['submittedAt']) || in_array($memo_status, [
            MEMO_STATUS_SUBMITTED,
            MEMO_STATUS_IN_REVIEW,
            MEMO_STATUS_RETURNED,
            MEMO_STATUS_APPROVED_UNSIGNED,
            MEMO_STATUS_SIGNED,
            MEMO_STATUS_REJECTED,
        ], true);
        $is_creator = (string) ($memo['createdByPID'] ?? '') === $current_pid;
        // Draft and "cancelled-before-submit" are creator-only even if an approver was preselected.
        $is_current_recipient = (string) ($memo['toPID'] ?? '') === $current_pid && $is_submitted_or_legacy;
        $is_route_actor = false;

        if ($is_submitted_or_legacy && $has_routes) {
            $route_access = db_fetch_one(
                'SELECT 1 AS ok FROM dh_memo_routes WHERE memoID = ? AND actorPID = ? LIMIT 1',
                'is',
                $memo_id,
                $current_pid
            );
            $is_route_actor = $route_access !== null;
        }

        $is_approver = $is_current_recipient || $is_route_actor;
        $is_admin = rbac_user_has_role($connection, $current_pid, ROLE_ADMIN) || in_array((int) ($current_user['roleID'] ?? 0), [1], true);

        if (!$is_creator && !$is_approver && !$is_admin) {
            http_response_code(403);
            require __DIR__ . '/../views/errors/403.php';

            return;
        }

        if ($is_current_recipient && in_array($memo_status, [MEMO_STATUS_SUBMITTED, MEMO_STATUS_IN_REVIEW], true)) {
            memo_mark_in_review($memo_id, $current_pid);
            $memo = memo_get($memo_id) ?? $memo;
        }

        $attachments = memo_get_attachments($memo_id);
        $signed_file = memo_get_signed_file($memo_id);

        if (is_array($signed_file) && !empty($signed_file['fileID'])) {
            $signed_id = (int) $signed_file['fileID'];
            $attachments = array_values(array_filter($attachments, static function (array $file) use ($signed_id): bool {
                return (int) ($file['fileID'] ?? 0) !== $signed_id;
            }));
        }
        $routes = $has_routes ? memo_list_routes($memo_id) : [];
        $approver_options = memo_build_approver_options($connection);

        view_render('memo/view', [
            'alert' => $alert,
            'memo' => $memo,
            'attachments' => $attachments,
            'signed_file' => $signed_file,
            'routes' => $routes,
            'approver_options' => $approver_options,
            'access' => [
                'is_creator' => $is_creator,
                'is_approver' => $is_approver,
                'is_admin' => $is_admin,
            ],
        ]);
    }
}
