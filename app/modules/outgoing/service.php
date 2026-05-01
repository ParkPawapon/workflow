<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/priority.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/../system/system.php';
require_once __DIR__ . '/../audit/logger.php';
require_once __DIR__ . '/../../services/uploads.php';
require_once __DIR__ . '/../../services/document-service.php';
require_once __DIR__ . '/../../rbac/roles.php';

if (!function_exists('outgoing_user_can_manage')) {
    function outgoing_user_can_manage(mysqli $connection, string $pID, array $current_user = []): bool
    {
        $pID = trim($pID);

        if ($pID === '') {
            return false;
        }

        if (rbac_user_has_role($connection, $pID, ROLE_ADMIN) || rbac_user_has_role($connection, $pID, ROLE_REGISTRY)) {
            return true;
        }

        // Legacy fallback (single role column on teacher)
        $legacy_role = (int) ($current_user['roleID'] ?? 0);

        return in_array($legacy_role, [1, 2], true);
    }
}

if (!function_exists('outgoing_document_number')) {
    function outgoing_document_number(array $outgoing): string
    {
        $number = trim((string) ($outgoing['outgoingNo'] ?? ''));

        if ($number !== '') {
            return $number;
        }
        $outgoingID = (int) ($outgoing['outgoingID'] ?? 0);

        return $outgoingID > 0 ? 'OUT-' . $outgoingID : '';
    }
}

if (!function_exists('outgoing_sequence_label')) {
    function outgoing_sequence_label(int $seq, bool $is_circular = false): string
    {
        return ($is_circular ? 'ว' : '') . str_pad((string) max(0, $seq), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('outgoing_format_number')) {
    function outgoing_format_number(int $seq, bool $is_circular = false): string
    {
        return outgoing_prefix() . '/' . outgoing_sequence_label($seq, $is_circular);
    }
}

if (!function_exists('outgoing_number_is_circular')) {
    function outgoing_number_is_circular(string $number): bool
    {
        return preg_match('/\/\s*ว/u', trim($number)) === 1;
    }
}

if (!function_exists('outgoing_parse_sequence_from_number')) {
    function outgoing_parse_sequence_from_number(string $number): int
    {
        $number = trim($number);

        if ($number === '') {
            return 0;
        }

        if (preg_match('/\/ว?0*([0-9]+)$/u', $number, $matches) !== 1) {
            return 0;
        }

        return max(0, (int) ($matches[1] ?? 0));
    }
}

if (!function_exists('outgoing_display_number')) {
    function outgoing_display_number(array $outgoing): string
    {
        $seq = (int) ($outgoing['outgoingSeq'] ?? 0);

        if ($seq <= 0) {
            $seq = outgoing_parse_sequence_from_number((string) ($outgoing['outgoingNo'] ?? ''));
        }

        if ($seq > 0) {
            return outgoing_format_number($seq, outgoing_number_is_circular((string) ($outgoing['outgoingNo'] ?? '')));
        }

        return outgoing_document_number($outgoing);
    }
}

if (!function_exists('outgoing_sync_document')) {
    function outgoing_sync_document(int $outgoingID): ?int
    {
        $outgoing = outgoing_get($outgoingID);

        if (!$outgoing) {
            return null;
        }

        $documentNumber = outgoing_document_number($outgoing);

        if ($documentNumber === '') {
            return null;
        }

        return document_upsert([
            'documentType' => 'OUTGOING',
            'documentNumber' => $documentNumber,
            'subject' => (string) ($outgoing['subject'] ?? ''),
            'content' => (string) ($outgoing['detail'] ?? ''),
            'status' => (string) ($outgoing['status'] ?? ''),
            'senderName' => (string) ($outgoing['creatorName'] ?? ''),
            'createdByPID' => (string) ($outgoing['createdByPID'] ?? ''),
            'updatedByPID' => $outgoing['updatedByPID'] ?? null,
        ]);
    }
}

if (!function_exists('outgoing_prefix')) {
    function outgoing_prefix(): string
    {
        $prefix = $_ENV['OUTGOING_PREFIX'] ?? 'ศธ';
        $code = $_ENV['OUTGOING_CODE'] ?? '04320.05';
        $prefix = trim((string) $prefix);
        $code = trim((string) $code);

        if ($prefix === '') {
            return $code;
        }

        if ($code === '') {
            return $prefix;
        }

        return $prefix . ' ' . $code;
    }
}

if (!function_exists('outgoing_generate_number')) {
    function outgoing_generate_number(int $year, bool $is_circular = false): array
    {
        $row = db_fetch_one('SELECT outgoingSeq FROM dh_outgoing_letters WHERE dh_year = ? ORDER BY outgoingSeq DESC LIMIT 1 FOR UPDATE', 'i', $year);
        $seq = $row ? ((int) $row['outgoingSeq'] + 1) : 1;
        $number = outgoing_format_number($seq, $is_circular);

        return [$number, $seq];
    }
}

if (!function_exists('outgoing_preview_number')) {
    function outgoing_preview_number(int $year, bool $is_circular = false): string
    {
        $row = db_fetch_one('SELECT outgoingSeq FROM dh_outgoing_letters WHERE dh_year = ? ORDER BY outgoingSeq DESC LIMIT 1', 'i', $year);
        $seq = $row ? ((int) $row['outgoingSeq'] + 1) : 1;
        $number = outgoing_format_number($seq, $is_circular);

        return $number;
    }
}

if (!function_exists('outgoing_audit_payload')) {
    function outgoing_audit_payload(array $payload): array
    {
        return array_filter($payload, static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [];
        });
    }
}

if (!function_exists('outgoing_create_draft')) {
    function outgoing_create_draft(array $data, array $files = []): int
    {
        $normalized_files = array_values(array_filter(
            upload_normalize_files($files),
            static function (array $file): bool {
                return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }
        ));
        $audit_payload = outgoing_audit_payload([
            'dhYear' => (int) ($data['dh_year'] ?? 0),
            'subject' => trim((string) ($data['subject'] ?? '')),
            'requestedStatus' => trim((string) ($data['status'] ?? '')),
            'createdByPID' => trim((string) ($data['createdByPID'] ?? '')),
            'issueType' => !empty($data['isCircular']) ? 'circular' : 'regular',
            'incomingAttachmentCount' => count($normalized_files),
        ]);

        db_begin();

        try {
            [$outgoingNo, $seq] = outgoing_generate_number((int) $data['dh_year'], !empty($data['isCircular']));
            $data['outgoingNo'] = $outgoingNo;
            $data['outgoingSeq'] = $seq;
            $outgoingID = outgoing_create_record($data);

            if (!empty($normalized_files)) {
                upload_store_files($files, OUTGOING_MODULE_NAME, OUTGOING_ENTITY_NAME, (string) $outgoingID, (string) $data['createdByPID'], [
                    'max_files' => 5,
                ]);
                outgoing_update_record($outgoingID, [
                    'status' => OUTGOING_STATUS_COMPLETE,
                    'updatedByPID' => $data['createdByPID'],
                ]);
            }

            outgoing_sync_document($outgoingID);
            $created_outgoing = outgoing_get($outgoingID) ?? [];
            $stored_attachments = outgoing_get_attachments($outgoingID);

            db_commit();
            audit_log('outgoing', 'CREATE', 'SUCCESS', 'dh_outgoing_letters', $outgoingID, null, outgoing_audit_payload(array_merge($audit_payload, [
                'outgoingNo' => outgoing_document_number($created_outgoing),
                'outgoingSeq' => (int) ($created_outgoing['outgoingSeq'] ?? $seq),
                'finalStatus' => trim((string) ($created_outgoing['status'] ?? ($data['status'] ?? ''))),
                'storedAttachmentCount' => count($stored_attachments),
            ])));

            return $outgoingID;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Outgoing create failed: ' . $e->getMessage());
            audit_log('outgoing', 'CREATE', 'FAIL', 'dh_outgoing_letters', null, $e->getMessage(), outgoing_audit_payload(array_merge($audit_payload, [
                'outgoingNo' => trim((string) ($data['outgoingNo'] ?? '')),
                'outgoingSeq' => (int) ($data['outgoingSeq'] ?? 0),
            ])));
            throw $e;
        }
    }
}

if (!function_exists('outgoing_attach_files')) {
    function outgoing_attach_files(int $outgoingID, string $actorPID, array $coverFile, array $attachmentFiles = [], ?string $destinationName = null): void
    {
        $outgoing = outgoing_get($outgoingID);

        if (!$outgoing) {
            audit_log('outgoing', 'ATTACH', 'FAIL', 'dh_outgoing_letters', $outgoingID > 0 ? $outgoingID : null, 'not_found', outgoing_audit_payload([
                'actorPID' => trim($actorPID),
            ]));
            throw new RuntimeException('ไม่พบรายการหนังสือออก');
        }

        $status = (string) ($outgoing['status'] ?? '');
        $existing_count = count(outgoing_get_attachments($outgoingID));
        $destination_name = trim((string) $destinationName);

        if ($status !== OUTGOING_STATUS_WAITING_ATTACHMENT) {
            audit_log('outgoing', 'ATTACH', 'FAIL', 'dh_outgoing_letters', $outgoingID, 'invalid_status_for_attach', outgoing_audit_payload([
                'actorPID' => trim($actorPID),
                'currentStatus' => $status,
                'existingAttachmentCount' => $existing_count,
            ]));
            throw new RuntimeException('รายการนี้ไม่อยู่ในสถานะรอแนบไฟล์');
        }

        $normalized_cover_files = array_values(array_filter(
            upload_normalize_files($coverFile),
            static function (array $file): bool {
                return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }
        ));
        $normalized_attachment_files = array_values(array_filter(
            upload_normalize_files($attachmentFiles),
            static function (array $file): bool {
                return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }
        ));
        $normalized_files = array_merge($normalized_cover_files, $normalized_attachment_files);
        $incoming_attachment_count = count($normalized_files);
        $audit_payload = outgoing_audit_payload([
            'actorPID' => trim($actorPID),
            'outgoingNo' => outgoing_document_number($outgoing),
            'currentStatus' => $status,
            'existingAttachmentCount' => $existing_count,
            'incomingAttachmentCount' => $incoming_attachment_count,
            'incomingCoverFileCount' => count($normalized_cover_files),
            'incomingOptionalFileCount' => count($normalized_attachment_files),
            'destinationName' => $destination_name,
        ]);

        if ($destination_name === '') {
            audit_log('outgoing', 'ATTACH', 'FAIL', 'dh_outgoing_letters', $outgoingID, 'missing_destination_name', $audit_payload);
            throw new RuntimeException('กรุณากรอกส่งถึง');
        }

        if (empty($normalized_cover_files)) {
            audit_log('outgoing', 'ATTACH', 'FAIL', 'dh_outgoing_letters', $outgoingID, 'missing_cover_file', $audit_payload);
            throw new RuntimeException('กรุณาแนบไฟล์หนังสือนำ');
        }

        if (($existing_count + count($normalized_files)) > 5) {
            audit_log('outgoing', 'ATTACH', 'FAIL', 'dh_outgoing_letters', $outgoingID, 'attachment_limit_exceeded', outgoing_audit_payload(array_merge($audit_payload, [
                'maxFiles' => 5,
            ])));
            throw new RuntimeException('แนบไฟล์ได้สูงสุด 5 ไฟล์');
        }

        db_begin();

        try {
            upload_store_files([
                'cover_file' => $coverFile,
                'attachments' => $attachmentFiles,
            ], OUTGOING_MODULE_NAME, OUTGOING_ENTITY_NAME, (string) $outgoingID, $actorPID, [
                'max_files' => 5,
            ]);
            $update_data = [
                'status' => OUTGOING_STATUS_COMPLETE,
                'updatedByPID' => $actorPID,
            ];

            if (outgoing_has_destination_name_column()) {
                $update_data['destinationName'] = $destination_name;
            }

            outgoing_update_record($outgoingID, $update_data);
            outgoing_sync_document($outgoingID);
            $updated_outgoing = outgoing_get($outgoingID) ?? $outgoing;
            $stored_attachments = outgoing_get_attachments($outgoingID);
            db_commit();
            audit_log('outgoing', 'ATTACH', 'SUCCESS', 'dh_outgoing_letters', $outgoingID, null, outgoing_audit_payload(array_merge($audit_payload, [
                'finalStatus' => trim((string) ($updated_outgoing['status'] ?? '')),
                'storedAttachmentCount' => count($stored_attachments),
            ])));
        } catch (Throwable $e) {
            db_rollback();
            error_log('Outgoing attach failed: ' . $e->getMessage());
            audit_log('outgoing', 'ATTACH', 'FAIL', 'dh_outgoing_letters', $outgoingID, $e->getMessage(), $audit_payload);
            throw $e;
        }
    }
}

if (!function_exists('outgoing_backfill_priority_metadata')) {
    function outgoing_backfill_priority_metadata(): array
    {
        $rows = db_fetch_all(
            'SELECT
                o.outgoingID,
                o.outgoingNo,
                o.subject,
                o.detail,
                d.id AS documentID,
                d.subject AS documentSubject,
                d.content AS documentContent
             FROM dh_outgoing_letters AS o
             LEFT JOIN dh_documents AS d
                ON d.documentType = ?
               AND d.documentNumber = o.outgoingNo
             WHERE o.deletedAt IS NULL
             ORDER BY o.outgoingID ASC',
            's',
            'OUTGOING'
        );
        $summary = [
            'scanned' => count($rows),
            'updatedOutgoingRows' => 0,
            'updatedDocumentRows' => 0,
            'priorityBreakdown' => [
                'normal' => 0,
                'urgent' => 0,
                'high' => 0,
                'highest' => 0,
            ],
            'changedOutgoingIds' => [],
            'changedDocumentIds' => [],
        ];

        if ($rows === []) {
            return $summary;
        }

        db_begin();

        try {
            foreach ($rows as $row) {
                $outgoing_id = (int) ($row['outgoingID'] ?? 0);
                $document_id = (int) ($row['documentID'] ?? 0);
                $outgoing_detail = trim((string) ($row['detail'] ?? ''));
                $document_content = trim((string) ($row['documentContent'] ?? ''));
                $priority_meta = outgoing_resolve_priority_meta(
                    $outgoing_detail,
                    (string) ($row['subject'] ?? ''),
                    $document_content,
                    (string) ($row['documentSubject'] ?? '')
                );
                $priority_key = outgoing_normalize_priority_key((string) ($priority_meta['priority_key'] ?? 'normal'));
                $base_outgoing_detail = $outgoing_detail !== '' ? $outgoing_detail : $document_content;
                $base_document_content = $document_content !== '' ? $document_content : $outgoing_detail;
                $normalized_outgoing_detail = outgoing_apply_priority_to_detail($base_outgoing_detail, $priority_key);
                $normalized_document_content = outgoing_apply_priority_to_detail($base_document_content, $priority_key);

                $summary['priorityBreakdown'][$priority_key] = ($summary['priorityBreakdown'][$priority_key] ?? 0) + 1;

                if ($outgoing_id > 0 && $normalized_outgoing_detail !== $outgoing_detail) {
                    db_query(
                        'UPDATE dh_outgoing_letters SET detail = ? WHERE outgoingID = ?',
                        'si',
                        $normalized_outgoing_detail,
                        $outgoing_id
                    );
                    $summary['updatedOutgoingRows']++;
                    $summary['changedOutgoingIds'][] = $outgoing_id;
                }

                if ($document_id > 0 && $normalized_document_content !== $document_content) {
                    db_query(
                        'UPDATE dh_documents SET content = ? WHERE id = ?',
                        'si',
                        $normalized_document_content,
                        $document_id
                    );
                    $summary['updatedDocumentRows']++;
                    $summary['changedDocumentIds'][] = $document_id;
                }
            }

            db_commit();

            audit_log(
                'outgoing',
                'BACKFILL_PRIORITY',
                'SUCCESS',
                'dh_outgoing_letters',
                null,
                null,
                outgoing_audit_payload([
                    'scanned' => $summary['scanned'],
                    'updatedOutgoingRows' => $summary['updatedOutgoingRows'],
                    'updatedDocumentRows' => $summary['updatedDocumentRows'],
                    'priorityBreakdown' => $summary['priorityBreakdown'],
                    'changedOutgoingIds' => $summary['changedOutgoingIds'],
                    'changedDocumentIds' => $summary['changedDocumentIds'],
                ]),
                'CLI',
                200
            );

            return $summary;
        } catch (Throwable $e) {
            db_rollback();

            audit_log(
                'outgoing',
                'BACKFILL_PRIORITY',
                'FAIL',
                'dh_outgoing_letters',
                null,
                $e->getMessage(),
                outgoing_audit_payload([
                    'scanned' => $summary['scanned'],
                    'updatedOutgoingRows' => $summary['updatedOutgoingRows'],
                    'updatedDocumentRows' => $summary['updatedDocumentRows'],
                ]),
                'CLI',
                500
            );

            throw $e;
        }
    }
}
