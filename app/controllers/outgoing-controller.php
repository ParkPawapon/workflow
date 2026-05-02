<?php

declare(strict_types=1);

require_once __DIR__ . '/../views/view.php';
require_once __DIR__ . '/../../src/Services/auth/auth-guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../rbac/current_user.php';
require_once __DIR__ . '/../rbac/roles.php';
require_once __DIR__ . '/../modules/audit/logger.php';
require_once __DIR__ . '/../modules/outgoing/priority.php';
require_once __DIR__ . '/../modules/outgoing/service.php';
require_once __DIR__ . '/../modules/outgoing/repository.php';
require_once __DIR__ . '/../modules/users/lists.php';
require_once __DIR__ . '/../db/db.php';

if (!function_exists('outgoing_issue_valid_date')) {
    function outgoing_issue_valid_date(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}

if (!function_exists('outgoing_priority_options')) {
    function outgoing_priority_options(): array
    {
        return [
            'normal' => 'ปกติ',
            'urgent' => 'ด่วน',
            'high' => 'ด่วนมาก',
            'highest' => 'ด่วนที่สุด',
        ];
    }
}

if (!function_exists('outgoing_normalize_priority_key')) {
    function outgoing_normalize_priority_key(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $options = outgoing_priority_options();

        if (isset($options[$value])) {
            return $value;
        }

        $matched_key = array_search(trim((string) $value), $options, true);

        return is_string($matched_key) ? $matched_key : 'normal';
    }
}

if (!function_exists('outgoing_priority_label_from_key')) {
    function outgoing_priority_label_from_key(?string $key): string
    {
        $normalized_key = outgoing_normalize_priority_key($key);
        $options = outgoing_priority_options();

        return $options[$normalized_key] ?? $options['normal'];
    }
}

if (!function_exists('outgoing_normalize_person_ids')) {
    function outgoing_normalize_person_ids(array $values): array
    {
        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            $pid = trim((string) $value);

            if ($pid === '' || isset($seen[$pid])) {
                continue;
            }

            $seen[$pid] = true;
            $normalized[] = $pid;
        }

        return $normalized;
    }
}

if (!function_exists('outgoing_resolve_owner_names')) {
    function outgoing_resolve_owner_names(array $person_ids): array
    {
        $person_ids = outgoing_normalize_person_ids($person_ids);

        if ($person_ids === []) {
            return [];
        }

        $teachers = user_list_teachers();
        $teacher_names = [];

        foreach ($teachers as $teacher) {
            $pid = trim((string) ($teacher['pID'] ?? ''));

            if ($pid === '') {
                continue;
            }

            $teacher_names[$pid] = trim((string) ($teacher['fName'] ?? '')) ?: $pid;
        }

        $owner_names = [];

        foreach ($person_ids as $pid) {
            $owner_names[] = $teacher_names[$pid] ?? $pid;
        }

        return $owner_names;
    }
}

if (!function_exists('outgoing_build_detail')) {
    function outgoing_build_detail(string $effective_date, string $issuer_name, array $owner_names, ?string $priority_key = null): string
    {
        $lines = [
            'ประเภท: ' . outgoing_priority_label_from_key($priority_key),
            'ลงวันที่: ' . $effective_date,
            'ผู้ออกเลข: ' . ($issuer_name !== '' ? $issuer_name : '-'),
        ];

        return implode("\n", $lines);
    }
}

if (!function_exists('outgoing_split_owner_names')) {
    function outgoing_split_owner_names(string $value): array
    {
        $names = preg_split('/\s*,\s*/u', trim($value)) ?: [];

        return array_values(array_filter(array_map(static function ($name): string {
            return trim((string) $name);
        }, $names), static function (string $name): bool {
            return $name !== '';
        }));
    }
}

if (!function_exists('outgoing_parse_detail_meta')) {
    function outgoing_parse_detail_meta(?string $detail): array
    {
        $meta = [
            'priority_label' => outgoing_priority_label_from_key('normal'),
            'priority_key' => 'normal',
            'effective_date' => '',
            'issuer_name' => '',
            'destination_name' => '',
            'owner_names' => [],
        ];
        $priority_found = false;

        $detail = trim((string) $detail);

        if ($detail === '') {
            return $meta;
        }

        $lines = preg_split('/\R/u', $detail) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^ประเภท:\s*(.+)$/u', $line, $matches) === 1) {
                $priority_key = outgoing_normalize_priority_key((string) ($matches[1] ?? ''));
                $meta['priority_key'] = $priority_key;
                $meta['priority_label'] = outgoing_priority_label_from_key($priority_key);
                $priority_found = true;
                continue;
            }

            if (preg_match('/^ลงวันที่:\s*(.+)$/u', $line, $matches) === 1) {
                $meta['effective_date'] = trim((string) ($matches[1] ?? ''));
                continue;
            }

            if (preg_match('/^ผู้ออกเลข:\s*(.+)$/u', $line, $matches) === 1) {
                $meta['issuer_name'] = trim((string) ($matches[1] ?? ''));
                continue;
            }

            if (preg_match('/^ส่งถึง:\s*(.+)$/u', $line, $matches) === 1) {
                $meta['destination_name'] = trim((string) ($matches[1] ?? ''));
                continue;
            }

            if (preg_match('/^เจ้าของเรื่อง:\s*(.+)$/u', $line, $matches) === 1) {
                $meta['owner_names'] = outgoing_split_owner_names((string) ($matches[1] ?? ''));
            }
        }

        if (!$priority_found) {
            $legacy_priority_labels = [
                'highest' => 'ด่วนที่สุด',
                'high' => 'ด่วนมาก',
                'urgent' => 'ด่วน',
                'normal' => 'ปกติ',
            ];

            foreach ($legacy_priority_labels as $priority_key => $priority_label) {
                if (mb_stripos($detail, $priority_label) !== false) {
                    $meta['priority_key'] = $priority_key;
                    $meta['priority_label'] = outgoing_priority_label_from_key($priority_key);
                    break;
                }
            }
        }

        return $meta;
    }
}

if (!function_exists('outgoing_build_view_modal_payload_map')) {
    function outgoing_build_view_modal_payload_map(array $items, array $attachments_map, array $track_status_map, array $documents_map = []): array
    {
        $payload_map = [];

        foreach ($items as $item) {
            $outgoing_id = (int) ($item['outgoingID'] ?? 0);

            if ($outgoing_id <= 0) {
                continue;
            }

            $full_item = outgoing_get($outgoing_id) ?? $item;
            $stored_outgoing_no = trim((string) ($full_item['outgoingNo'] ?? $item['outgoingNo'] ?? ''));
            $outgoing_no = outgoing_display_number($full_item ?: $item);
            $document = $documents_map[$stored_outgoing_no] ?? ($documents_map[$outgoing_no] ?? []);
            $detail_meta = outgoing_parse_detail_meta((string) ($full_item['detail'] ?? ''));
            $document_meta = outgoing_parse_detail_meta((string) ($document['content'] ?? ''));
            $priority_meta = outgoing_resolve_priority_meta(
                (string) ($full_item['detail'] ?? ''),
                (string) ($full_item['subject'] ?? $item['subject'] ?? ''),
                (string) ($document['content'] ?? ''),
                (string) ($document['subject'] ?? '')
            );
            $created_at = trim((string) ($full_item['createdAt'] ?? $item['createdAt'] ?? ''));
            $status_key = strtoupper(trim((string) ($full_item['status'] ?? $item['status'] ?? '')));
            $status_meta = $track_status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
            $effective_date = trim((string) ($detail_meta['effective_date'] ?? $document_meta['effective_date'] ?? ''));

            if ($effective_date === '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $created_at, $matches) === 1) {
                $effective_date = (string) ($matches[0] ?? '');
            }

            $issuer_name = trim((string) ($detail_meta['issuer_name'] ?? $document_meta['issuer_name'] ?? ''));
            if ($issuer_name === '') {
                $issuer_name = trim((string) ($full_item['creatorName'] ?? $item['creatorName'] ?? ''));
            }

            $destination_name = trim((string) ($full_item['destinationName'] ?? ''));
            if ($destination_name === '') {
                $destination_name = trim((string) ($detail_meta['destination_name'] ?? $document_meta['destination_name'] ?? ''));
            }

            $owner_names = array_values((array) ($detail_meta['owner_names'] ?? []));
            if ($owner_names === []) {
                $owner_names = array_values((array) ($document_meta['owner_names'] ?? []));
            }

            $attachments = array_map(static function (array $file): array {
                return [
                    'fileID' => (int) ($file['fileID'] ?? 0),
                    'fileName' => trim((string) ($file['fileName'] ?? '')),
                    'mimeType' => trim((string) ($file['mimeType'] ?? '')),
                    'fileSize' => (int) ($file['fileSize'] ?? 0),
                ];
            }, (array) ($attachments_map[(string) $outgoing_id] ?? []));
            $cover_files = $attachments !== [] ? [array_shift($attachments)] : [];

            $payload_map[(string) $outgoing_id] = [
                'outgoingID' => $outgoing_id,
                'outgoingNo' => $outgoing_no,
                'subject' => trim((string) ($full_item['subject'] ?? $item['subject'] ?? '')),
                'priorityKey' => trim((string) ($priority_meta['priority_key'] ?? 'normal')),
                'priorityLabel' => trim((string) ($priority_meta['priority_label'] ?? outgoing_priority_label_from_key('normal'))),
                'effectiveDate' => $effective_date,
                'issuerName' => $issuer_name,
                'destinationName' => $destination_name,
                'ownerNames' => $owner_names,
                'status' => $status_key,
                'statusLabel' => trim((string) ($status_meta['label'] ?? '-')),
                'statusPill' => trim((string) ($status_meta['pill'] ?? 'pending')),
                'coverFiles' => $cover_files,
                'attachmentFiles' => $attachments,
                'attachments' => array_merge($cover_files, $attachments),
            ];
        }

        return $payload_map;
    }
}

if (!function_exists('outgoing_index')) {
    function outgoing_index(): void
    {
        $current_user = current_user() ?? [];
        $current_pid = (string) ($current_user['pID'] ?? '');
        $issuer_name = trim((string) ($current_user['fName'] ?? ''));
        if ($issuer_name === '') {
            $issuer_name = $current_pid;
        }

        $connection = db_connection();
        $can_manage = outgoing_user_can_manage($connection, $current_pid, $current_user);
        $search = trim((string) ($_GET['q'] ?? ''));
        $filter_status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
        $filter_sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));
        $active_tab = trim((string) ($_GET['tab'] ?? 'compose'));
        $is_track_active = $active_tab === 'track';
        $has_track_filters = array_key_exists('q', $_GET) || array_key_exists('status', $_GET) || array_key_exists('sort', $_GET);

        if ($has_track_filters) {
            $is_track_active = true;
        }

        if (!in_array($filter_status, ['all', 'waiting_attachment', 'complete'], true)) {
            $filter_status = 'all';
        }

        if (!in_array($filter_sort, ['newest', 'oldest'], true)) {
            $filter_sort = 'newest';
        }

        $is_filtered_track_request = $search !== '' || $filter_status !== 'all' || $filter_sort !== 'newest';
        $build_audit_payload = static function (array $extra = []) use ($active_tab, $is_track_active, $has_track_filters, $search, $filter_status, $filter_sort): array {
            $payload = [
                'tab' => $active_tab !== '' ? $active_tab : 'compose',
                'trackTab' => $is_track_active,
                'hasTrackFilters' => $has_track_filters,
                'query' => $search !== '' ? $search : null,
                'statusFilter' => $filter_status,
                'sort' => $filter_sort,
            ];

            foreach ($extra as $key => $value) {
                $payload[$key] = $value;
            }

            return array_filter($payload, static function ($value): bool {
                return $value !== null && $value !== '' && $value !== [];
            });
        };

        $audit_fail = static function (string $action, string $reason, ?int $entity_id = null, array $payload = []) use ($build_audit_payload): void {
            if (!function_exists('audit_log')) {
                return;
            }

            audit_log('outgoing', $action, 'FAIL', 'dh_outgoing_letters', $entity_id, $reason, $build_audit_payload($payload));
        };

        if (!$can_manage) {
            if (function_exists('audit_log')) {
                audit_log('outgoing', 'ACCESS', 'DENY', null, null, 'outgoing_access_denied', $build_audit_payload(), null, 403);
            }
            http_response_code(403);
            require __DIR__ . '/../views/errors/403.php';

            return;
        }

        $alert = null;
        $form_values = [
            'subject' => '',
            'priority' => 'normal',
            'issue_type' => 'regular',
            'effective_date' => date('Y-m-d'),
            'person_ids' => [],
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = trim((string) ($_POST['action'] ?? ''));
            $outgoing_id = isset($_POST['outgoing_id']) ? (int) $_POST['outgoing_id'] : 0;
            $destination_name = trim((string) ($_POST['destination_name'] ?? ''));
            $issue_type = strtolower(trim((string) ($_POST['issue_type'] ?? 'regular')));
            $is_circular_issue = $issue_type === 'circular';
            $is_create_request = $action === 'create'
                || ($action === '' && $outgoing_id <= 0 && (
                    array_key_exists('subject', $_POST)
                    || array_key_exists('effective_date', $_POST)
                    || array_key_exists('person_ids', $_POST)
                ));

            if ($is_create_request) {
                $form_values['subject'] = trim((string) ($_POST['subject'] ?? ''));
                $form_values['priority'] = outgoing_normalize_priority_key((string) ($_POST['priority'] ?? 'normal'));
                $form_values['issue_type'] = $is_circular_issue ? 'circular' : 'regular';
                $form_values['effective_date'] = trim((string) ($_POST['effective_date'] ?? ''));
                $form_values['person_ids'] = outgoing_normalize_person_ids((array) ($_POST['person_ids'] ?? []));
            }

            $post_audit_payload = [
                'requestedAction' => $action !== '' ? $action : ($is_create_request ? 'create' : 'unknown'),
                'outgoingID' => $outgoing_id > 0 ? $outgoing_id : null,
            ];

            if ($action === 'attach') {
                $post_audit_payload['destinationName'] = $destination_name !== '' ? $destination_name : null;
            }

            if ($is_create_request) {
                $post_audit_payload['subject'] = $form_values['subject'] !== '' ? $form_values['subject'] : null;
                $post_audit_payload['priority'] = $form_values['priority'];
                $post_audit_payload['effectiveDate'] = $form_values['effective_date'] !== '' ? $form_values['effective_date'] : null;
                $post_audit_payload['issueType'] = $is_circular_issue ? 'circular' : 'regular';
            }

            if (!csrf_validate($_POST['csrf_token'] ?? null)) {
                if (function_exists('audit_log')) {
                    audit_log('security', 'CSRF_FAIL', 'DENY', 'dh_outgoing_letters', $outgoing_id > 0 ? $outgoing_id : null, 'outgoing_controller', $build_audit_payload($post_audit_payload));
                }
                $alert = [
                    'type' => 'danger',
                    'title' => 'ไม่สามารถยืนยันความปลอดภัย',
                    'message' => 'กรุณาลองใหม่อีกครั้ง',
                ];
            } else {
                if ($is_create_request) {
                    if ($form_values['subject'] === '') {
                        $audit_fail('CREATE', 'missing_subject', null, $post_audit_payload);
                        $alert = [
                            'type' => 'danger',
                            'title' => 'กรุณากรอกเรื่อง',
                            'message' => '',
                        ];
                    } elseif (!outgoing_issue_valid_date($form_values['effective_date'])) {
                        $audit_fail('CREATE', 'invalid_effective_date', null, $post_audit_payload);
                        $alert = [
                            'type' => 'danger',
                            'title' => 'วันที่ไม่ถูกต้อง',
                            'message' => 'กรุณาเลือกวันที่ให้ถูกต้อง',
                        ];
                    } else {
                        try {
                            $outgoing_id = outgoing_create_draft([
                                'dh_year' => system_get_dh_year(),
                                'subject' => $form_values['subject'],
                                'detail' => outgoing_build_detail($form_values['effective_date'], $issuer_name, [], (string) ($form_values['priority'] ?? 'normal')),
                                'status' => OUTGOING_STATUS_WAITING_ATTACHMENT,
                                'createdByPID' => $current_pid,
                                'isCircular' => $is_circular_issue,
                            ]);

                            $created_outgoing = outgoing_get($outgoing_id);
                            $created_number = outgoing_display_number($created_outgoing ?? []);

                            $alert = [
                                'type' => 'success',
                                'title' => 'ออกเลขทะเบียนเรียบร้อย',
                                'message' => $created_number !== '' ? 'เลขทะเบียนส่ง ' . $created_number : '',
                            ];

                            $form_values = [
                                'subject' => '',
                                'priority' => 'normal',
                                'issue_type' => 'regular',
                                'effective_date' => date('Y-m-d'),
                                'person_ids' => [],
                            ];
                        } catch (Throwable $e) {
                            $alert = [
                                'type' => 'danger',
                                'title' => 'เกิดข้อผิดพลาด',
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                } elseif ($action === 'attach') {
                    if ($outgoing_id <= 0) {
                        $audit_fail('ATTACH', 'invalid_outgoing_id', null, $post_audit_payload);
                        $alert = [
                            'type' => 'danger',
                            'title' => 'ข้อมูลไม่ถูกต้อง',
                            'message' => 'ไม่พบรายการที่ต้องการแนบไฟล์',
                        ];
                    } else {
                        try {
                            outgoing_attach_files($outgoing_id, $current_pid, $_FILES['cover_file'] ?? [], $_FILES['attachments'] ?? [], $destination_name);
                            $alert = [
                                'type' => 'success',
                                'title' => 'แนบไฟล์เรียบร้อย',
                                'message' => '',
                            ];
                        } catch (Throwable $e) {
                            $alert = [
                                'type' => 'danger',
                                'title' => 'เกิดข้อผิดพลาด',
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                } elseif ($action !== '') {
                    $audit_fail('ACTION', 'invalid_action', $outgoing_id > 0 ? $outgoing_id : null, $post_audit_payload);
                    $alert = [
                        'type' => 'danger',
                        'title' => 'ข้อมูลไม่ถูกต้อง',
                        'message' => 'ไม่พบคำสั่งที่ต้องการ',
                    ];
                }
            }
        }

        $active_dh_year = system_get_dh_year();
        $track_status_map = [
            OUTGOING_STATUS_WAITING_ATTACHMENT => ['label' => 'รอการแนบไฟล์', 'pill' => 'pending'],
            OUTGOING_STATUS_COMPLETE => ['label' => 'แนบไฟล์สำเร็จ', 'pill' => 'outgoing-complete'],
        ];
        $status_filter_for_query = match ($filter_status) {
            'waiting_attachment' => OUTGOING_STATUS_WAITING_ATTACHMENT,
            'complete' => OUTGOING_STATUS_COMPLETE,
            default => 'all',
        };
        $outgoing_items = outgoing_list([
            'q' => $search,
            'status' => $status_filter_for_query,
            'sort' => $filter_sort,
        ]);
        $summary_counts = outgoing_count_by_status();
        $outgoing_ids = array_map(static function (array $item): int {
            return (int) ($item['outgoingID'] ?? 0);
        }, $outgoing_items);
        $outgoing_numbers = array_values(array_filter(array_map(static function (array $item): string {
            return trim((string) ($item['outgoingNo'] ?? ''));
        }, $outgoing_items), static function (string $outgoing_no): bool {
            return $outgoing_no !== '';
        }));
        $attachments_map = outgoing_list_attachments_map($outgoing_ids);
        $documents_map = outgoing_list_documents_map_by_number($outgoing_numbers);
        $send_modal_payload_map = outgoing_build_view_modal_payload_map($outgoing_items, $attachments_map, $track_status_map, $documents_map);

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && function_exists('audit_log')) {
            audit_log(
                'outgoing',
                $is_filtered_track_request ? 'SEARCH' : 'VIEW',
                'SUCCESS',
                null,
                null,
                null,
                $build_audit_payload([
                    'resultCount' => count($outgoing_items),
                    'activeDhYear' => $active_dh_year,
                    'summaryWaiting' => (int) ($summary_counts[OUTGOING_STATUS_WAITING_ATTACHMENT] ?? 0),
                    'summaryComplete' => (int) ($summary_counts[OUTGOING_STATUS_COMPLETE] ?? 0),
                ]),
                'GET',
                200
            );
        }

        view_render('outgoing/index', [
            'alert' => $alert,
            'items' => $outgoing_items,
            'can_manage' => $can_manage,
            'search' => $search,
            'status_filter' => $filter_status,
            'filter_query' => $search,
            'filter_sort' => $filter_sort,
            'is_track_active' => $is_track_active,
            'active_dh_year' => $active_dh_year,
            'issuer_name' => $issuer_name,
            'form_values' => $form_values,
            'track_status_map' => $track_status_map,
            'send_modal_payload_map' => $send_modal_payload_map,
            'summary_counts' => $summary_counts,
            'attachments_map' => $attachments_map,
        ]);
    }
}
