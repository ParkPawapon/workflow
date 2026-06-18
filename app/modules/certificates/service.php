<?php

declare(strict_types=1);

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/../audit/logger.php';
require_once __DIR__ . '/../system/system.php';
require_once __DIR__ . '/../../services/uploads.php';

if (!function_exists('certificate_number_prefix')) {
    function certificate_number_prefix(int $year): string
    {
        return 'ด.บ.' . $year . '-';
    }
}

if (!function_exists('certificate_format_number')) {
    function certificate_format_number(int $year, int $sequence): string
    {
        return certificate_number_prefix($year) . str_pad((string) max(1, $sequence), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('certificate_normalize_total')) {
    function certificate_normalize_total(mixed $value): int
    {
        $total = (int) $value;

        return $total > 0 ? $total : 0;
    }
}

if (!function_exists('certificate_preview_range')) {
    function certificate_preview_range(int $year, int $total): array
    {
        $connection = db_connection();
        certificates_ensure_schema($connection);

        $total = max(1, certificate_normalize_total($total));
        $row = db_fetch_one(
            'SELECT certificateToSeq FROM dh_certificates WHERE dh_year = ? AND deletedAt IS NULL ORDER BY certificateToSeq DESC LIMIT 1',
            'i',
            $year
        );
        $from_seq = $row ? ((int) $row['certificateToSeq'] + 1) : 1;
        $to_seq = $from_seq + $total - 1;

        return [
            'year' => $year,
            'total' => $total,
            'fromSeq' => $from_seq,
            'toSeq' => $to_seq,
            'fromNo' => certificate_format_number($year, $from_seq),
            'toNo' => certificate_format_number($year, $to_seq),
        ];
    }
}

if (!function_exists('certificate_generate_range')) {
    function certificate_generate_range(int $year, int $total): array
    {
        $total = max(1, certificate_normalize_total($total));
        $row = db_fetch_one(
            'SELECT certificateToSeq FROM dh_certificates WHERE dh_year = ? AND deletedAt IS NULL ORDER BY certificateToSeq DESC LIMIT 1 FOR UPDATE',
            'i',
            $year
        );
        $from_seq = $row ? ((int) $row['certificateToSeq'] + 1) : 1;
        $to_seq = $from_seq + $total - 1;

        return [
            'year' => $year,
            'total' => $total,
            'fromSeq' => $from_seq,
            'toSeq' => $to_seq,
            'fromNo' => certificate_format_number($year, $from_seq),
            'toNo' => certificate_format_number($year, $to_seq),
        ];
    }
}

if (!function_exists('certificate_allowed_upload_mimes')) {
    function certificate_allowed_upload_mimes(): array
    {
        return [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-rar' => 'rar',
            'application/vnd.rar' => 'rar',
        ];
    }
}

if (!function_exists('certificate_audit_payload')) {
    function certificate_audit_payload(array $payload): array
    {
        return array_filter($payload, static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [];
        });
    }
}

if (!function_exists('certificate_create_issue')) {
    function certificate_create_issue(array $data, array $files = []): int
    {
        $connection = db_connection();
        certificates_ensure_schema($connection);

        $dh_year = (int) ($data['dh_year'] ?? system_get_dh_year());
        $total_certificates = certificate_normalize_total($data['totalCertificates'] ?? 0);
        $subject = trim((string) ($data['subject'] ?? ''));
        $created_by_pid = trim((string) ($data['createdByPID'] ?? ''));
        $group_fid = isset($data['groupFID']) && (int) $data['groupFID'] > 0 ? (int) $data['groupFID'] : null;

        if ($dh_year <= 0) {
            throw new RuntimeException('ปีสารบรรณไม่ถูกต้อง');
        }

        if ($created_by_pid === '') {
            throw new RuntimeException('ไม่พบผู้ใช้งานปัจจุบัน');
        }

        if ($total_certificates <= 0) {
            throw new RuntimeException('กรุณาระบุจำนวนเกียรติบัตรทั้งหมด');
        }

        if ($subject === '') {
            throw new RuntimeException('กรุณากรอกเรื่อง');
        }

        $audit_payload = certificate_audit_payload([
            'dhYear' => $dh_year,
            'totalCertificates' => $total_certificates,
            'subject' => $subject,
            'groupFID' => $group_fid,
            'createdByPID' => $created_by_pid,
        ]);
        $final_status = CERTIFICATE_STATUS_WAITING_ATTACHMENT;

        db_begin();

        try {
            $range = certificate_generate_range($dh_year, $total_certificates);
            $certificate_id = certificate_create_record([
                'dh_year' => $dh_year,
                'certificateFromNo' => $range['fromNo'],
                'certificateToNo' => $range['toNo'],
                'certificateFromSeq' => $range['fromSeq'],
                'certificateToSeq' => $range['toSeq'],
                'totalCertificates' => $total_certificates,
                'subject' => $subject,
                'groupFID' => $group_fid,
                'status' => $final_status,
                'createdByPID' => $created_by_pid,
                'updatedByPID' => $created_by_pid,
            ]);

            db_commit();
            audit_log('certificates', 'CREATE', 'SUCCESS', CERTIFICATE_ENTITY_NAME, $certificate_id, null, certificate_audit_payload(array_merge($audit_payload, [
                'certificateFromNo' => $range['fromNo'],
                'certificateToNo' => $range['toNo'],
                'certificateFromSeq' => $range['fromSeq'],
                'certificateToSeq' => $range['toSeq'],
                'finalStatus' => $final_status,
                'attachmentCount' => 0,
            ])));

            return $certificate_id;
        } catch (Throwable $e) {
            db_rollback();
            error_log('Certificate create failed: ' . $e->getMessage());
            audit_log('certificates', 'CREATE', 'FAIL', CERTIFICATE_ENTITY_NAME, null, $e->getMessage(), $audit_payload);
            throw $e;
        }
    }
}

if (!function_exists('certificate_build_modal_payload_map')) {
    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, array<int, array<string, mixed>>> $attachments_map
     * @param array<string, array<string, string>> $status_map
     * @return array<string, array<string, mixed>>
     */
    function certificate_build_modal_payload_map(array $items, array $attachments_map, array $status_map): array
    {
        $payload_map = [];

        foreach ($items as $item) {
            $certificate_id = (int) ($item['certificateID'] ?? 0);

            if ($certificate_id <= 0) {
                continue;
            }

            $status_key = strtoupper(trim((string) ($item['status'] ?? '')));
            $status_meta = $status_map[$status_key] ?? ['label' => ($status_key !== '' ? $status_key : '-'), 'pill' => 'pending'];
            $payload_map[(string) $certificate_id] = [
                'certificateID' => $certificate_id,
                'totalCertificates' => (int) ($item['totalCertificates'] ?? 0),
                'requesterName' => trim((string) ($item['creatorName'] ?? '')),
                'certificateFromNo' => trim((string) ($item['certificateFromNo'] ?? '')),
                'certificateToNo' => trim((string) ($item['certificateToNo'] ?? '')),
                'subject' => trim((string) ($item['subject'] ?? '')),
                'groupName' => trim((string) ($item['groupName'] ?? '')),
                'statusKey' => $status_key,
                'statusLabel' => trim((string) ($status_meta['label'] ?? '-')),
                'statusPill' => trim((string) ($status_meta['pill'] ?? 'approved')),
                'attachments' => array_map(static function (array $file): array {
                    return [
                        'fileID' => (int) ($file['fileID'] ?? 0),
                        'fileName' => trim((string) ($file['fileName'] ?? '')),
                        'mimeType' => trim((string) ($file['mimeType'] ?? '')),
                        'fileSize' => (int) ($file['fileSize'] ?? 0),
                    ];
                }, (array) ($attachments_map[(string) $certificate_id] ?? [])),
            ];
        }

        return $payload_map;
    }
}

if (!function_exists('certificate_update_attachments')) {
    function certificate_update_attachments(int $certificateID, string $actorPID, array $files = [], array $removeFileIDs = []): void
    {
        $connection = db_connection();
        certificates_ensure_schema($connection);

        $certificate = certificate_get_for_owner($certificateID, $actorPID);

        if (!$certificate) {
            audit_log('certificates', 'ATTACH', 'FAIL', CERTIFICATE_ENTITY_NAME, $certificateID > 0 ? $certificateID : null, 'not_found_or_forbidden', [
                'actorPID' => $actorPID,
            ]);
            throw new RuntimeException('ไม่พบรายการเกียรติบัตรหรือไม่มีสิทธิ์แก้ไข');
        }

        $existing_attachments = certificate_get_attachments($certificateID);

        if (strtoupper(trim((string) ($certificate['status'] ?? ''))) === CERTIFICATE_STATUS_COMPLETE || $existing_attachments !== []) {
            audit_log('certificates', 'ATTACH', 'FAIL', CERTIFICATE_ENTITY_NAME, $certificateID, 'attachment_locked', [
                'actorPID' => $actorPID,
                'currentStatus' => trim((string) ($certificate['status'] ?? '')),
                'existingAttachmentCount' => count($existing_attachments),
            ]);
            throw new RuntimeException('รายการนี้แนบไฟล์สำเร็จแล้ว ไม่สามารถแก้ไขไฟล์แนบได้');
        }

        $allowed_file_ids = [];

        foreach ($existing_attachments as $attachment) {
            $fileID = (int) ($attachment['fileID'] ?? 0);

            if ($fileID > 0) {
                $allowed_file_ids[$fileID] = true;
            }
        }

        $remove_file_ids = array_values(array_unique(array_filter(array_map(static function ($value): int {
            return (int) $value;
        }, $removeFileIDs), static function (int $fileID) use ($allowed_file_ids): bool {
            return $fileID > 0 && isset($allowed_file_ids[$fileID]);
        })));

        $normalized_files = array_values(array_filter(
            upload_normalize_files($files),
            static function (array $file): bool {
                return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }
        ));

        if (count($normalized_files) > 1) {
            throw new RuntimeException('แนบไฟล์ได้สูงสุด 1 ไฟล์');
        }

        $remaining_files_count = max(0, count($existing_attachments) - count($remove_file_ids));

        if (($remaining_files_count + count($normalized_files)) > 1) {
            throw new RuntimeException('กรุณาลบไฟล์เดิมก่อนเลือกไฟล์ใหม่');
        }

        db_begin();

        try {
            if ($remove_file_ids !== []) {
                certificate_soft_delete_attachments($certificateID, $remove_file_ids);
            }

            $stored_attachments = [];

            if ($normalized_files !== []) {
                $stored_attachments = upload_store_files($files, CERTIFICATE_MODULE_NAME, CERTIFICATE_ENTITY_NAME, (string) $certificateID, $actorPID, [
                    'max_files' => 1,
                    'max_size' => 10 * 1024 * 1024,
                    'allowed_mimes' => certificate_allowed_upload_mimes(),
                ]);
            }

            $final_attachments = certificate_get_attachments($certificateID);
            $final_status = $final_attachments === []
                ? CERTIFICATE_STATUS_WAITING_ATTACHMENT
                : CERTIFICATE_STATUS_COMPLETE;

            certificate_update_record($certificateID, [
                'status' => $final_status,
                'updatedByPID' => $actorPID,
            ]);

            db_commit();
            audit_log('certificates', 'ATTACH', 'SUCCESS', CERTIFICATE_ENTITY_NAME, $certificateID, null, certificate_audit_payload([
                'actorPID' => $actorPID,
                'removedFileCount' => count($remove_file_ids),
                'uploadedFileCount' => count($stored_attachments),
                'finalAttachmentCount' => count($final_attachments),
                'finalStatus' => $final_status,
            ]));
        } catch (Throwable $e) {
            db_rollback();
            error_log('Certificate attachment update failed: ' . $e->getMessage());
            audit_log('certificates', 'ATTACH', 'FAIL', CERTIFICATE_ENTITY_NAME, $certificateID, $e->getMessage(), certificate_audit_payload([
                'actorPID' => $actorPID,
                'removedFileCount' => count($remove_file_ids),
                'incomingFileCount' => count($normalized_files),
            ]));
            throw $e;
        }
    }
}
