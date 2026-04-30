<?php

declare(strict_types=1);

namespace Tests\PHPUnit\Workflows;

use RuntimeException;
use Tests\Support\WorkflowTestCase;

final class CertificatesWorkflowTest extends WorkflowTestCase
{
    /** @var list<int> */
    private array $createdCertificateIds = [];

    /** @var list<int> */
    private array $createdFileIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SERVER['REQUEST_METHOD'] = 'CLI';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Certificates Workflow Test';
        $_SERVER['REQUEST_URI'] = '/phpunit/certificates-workflow';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SESSION['pID'] = $this->requireActiveTeacherPid('No active teacher available for certificates workflow test');
        certificates_ensure_schema($this->connection());
    }

    protected function tearDown(): void
    {
        $this->cleanupAuditLogs();
        $this->cleanupCertificates();

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['pID']);
        }

        parent::tearDown();
    }

    public function testPreviewRangeUsesCurrentDhYearAndRequestedBatchSize(): void
    {
        $year = $this->currentDhYear();
        $preview = certificate_preview_range($year, 30);

        $this->assertSame($year, (int) ($preview['year'] ?? 0));
        $this->assertSame(30, (int) ($preview['total'] ?? 0));
        $this->assertGreaterThan(0, (int) ($preview['fromSeq'] ?? 0));
        $this->assertSame(((int) ($preview['fromSeq'] ?? 0)) + 29, (int) ($preview['toSeq'] ?? 0));
        $this->assertMatchesRegularExpression('/^ด\.บ\.' . preg_quote((string) $year, '/') . '-\d{5,}$/u', (string) ($preview['fromNo'] ?? ''));
        $this->assertMatchesRegularExpression('/^ด\.บ\.' . preg_quote((string) $year, '/') . '-\d{5,}$/u', (string) ($preview['toNo'] ?? ''));
    }

    public function testCreateIssueWithoutAttachmentPersistsWaitingAttachmentStatusAndMineListing(): void
    {
        $year = $this->currentDhYear();
        $actorPid = trim((string) ($_SESSION['pID'] ?? ''));
        $groupFid = (int) $this->requireScalarValue('SELECT fID FROM faction ORDER BY fID ASC LIMIT 1', 'No faction available for certificates workflow test');
        $subject = 'PHPUnit Certificates ' . uniqid('', true);
        $expectedRange = certificate_preview_range($year, 3);

        $certificateId = certificate_create_issue([
            'dh_year' => $year,
            'totalCertificates' => 3,
            'subject' => $subject,
            'groupFID' => $groupFid,
            'createdByPID' => $actorPid,
        ], []);
        $this->createdCertificateIds[] = $certificateId;

        $certificate = certificate_get($certificateId);
        $this->assertNotNull($certificate);
        $this->assertSame($subject, (string) ($certificate['subject'] ?? ''));
        $this->assertSame($actorPid, (string) ($certificate['createdByPID'] ?? ''));
        $this->assertSame($groupFid, (int) ($certificate['groupFID'] ?? 0));
        $this->assertSame(CERTIFICATE_STATUS_WAITING_ATTACHMENT, (string) ($certificate['status'] ?? ''));
        $this->assertSame((int) ($expectedRange['fromSeq'] ?? 0), (int) ($certificate['certificateFromSeq'] ?? 0));
        $this->assertSame((int) ($expectedRange['toSeq'] ?? 0), (int) ($certificate['certificateToSeq'] ?? 0));
        $this->assertSame((string) ($expectedRange['fromNo'] ?? ''), (string) ($certificate['certificateFromNo'] ?? ''));
        $this->assertSame((string) ($expectedRange['toNo'] ?? ''), (string) ($certificate['certificateToNo'] ?? ''));

        $mineItems = certificate_list([
            'q' => $subject,
            'status' => CERTIFICATE_STATUS_WAITING_ATTACHMENT,
            'sort' => 'newest',
            'created_by_pid' => $actorPid,
        ]);

        $this->assertContains($certificateId, array_column($mineItems, 'certificateID'));

        $afterPreview = certificate_preview_range($year, 1);
        $this->assertSame(((int) ($expectedRange['toSeq'] ?? 0)) + 1, (int) ($afterPreview['fromSeq'] ?? 0));
    }

    public function testExistingCompleteAttachmentCannotBeRemoved(): void
    {
        $year = $this->currentDhYear();
        $actorPid = trim((string) ($_SESSION['pID'] ?? ''));
        $groupFid = (int) $this->requireScalarValue('SELECT fID FROM faction ORDER BY fID ASC LIMIT 1', 'No faction available for certificates workflow test');
        $subject = 'PHPUnit Certificates Remove Attachment ' . uniqid('', true);

        $certificateId = certificate_create_issue([
            'dh_year' => $year,
            'totalCertificates' => 1,
            'subject' => $subject,
            'groupFID' => $groupFid,
            'createdByPID' => $actorPid,
        ], []);
        $this->createdCertificateIds[] = $certificateId;

        db_execute(
            'UPDATE dh_certificates SET status = ? WHERE certificateID = ?',
            'si',
            CERTIFICATE_STATUS_COMPLETE,
            $certificateId
        );

        $fileStmt = db_query(
            'INSERT INTO dh_files (fileName, filePath, mimeType, fileSize, checksumSHA256, storageProvider, uploadedByPID)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'sssisss',
            'certificate-test.pdf',
            'storage/uploads/certificates/phpunit/certificate-test.pdf',
            'application/pdf',
            1024,
            hash('sha256', 'certificate-test-' . $certificateId),
            'local',
            $actorPid
        );
        $fileId = db_last_insert_id();
        mysqli_stmt_close($fileStmt);
        $this->createdFileIds[] = $fileId;

        $refStmt = db_query(
            'INSERT INTO dh_file_refs (fileID, moduleName, entityName, entityID, attachedByPID)
             VALUES (?, ?, ?, ?, ?)',
            'issss',
            $fileId,
            CERTIFICATE_MODULE_NAME,
            CERTIFICATE_ENTITY_NAME,
            (string) $certificateId,
            $actorPid
        );
        mysqli_stmt_close($refStmt);

        try {
            certificate_update_attachments($certificateId, $actorPid, [], [$fileId]);
            $this->fail('Expected completed certificates with existing attachments to be locked');
        } catch (RuntimeException $exception) {
            $this->assertSame('รายการนี้แนบไฟล์สำเร็จแล้ว ไม่สามารถแก้ไขไฟล์แนบได้', $exception->getMessage());
        }

        $certificate = certificate_get($certificateId);
        $this->assertNotNull($certificate);
        $this->assertSame(CERTIFICATE_STATUS_COMPLETE, (string) ($certificate['status'] ?? ''));
        $this->assertSame([$fileId], array_map('intval', array_column(certificate_get_attachments($certificateId), 'fileID')));

        $fileRow = db_fetch_one('SELECT deletedAt FROM dh_files WHERE fileID = ? LIMIT 1', 'i', $fileId);
        $this->assertIsArray($fileRow);
        $this->assertSame('', trim((string) ($fileRow['deletedAt'] ?? '')));
    }

    private function cleanupCertificates(): void
    {
        if ($this->createdCertificateIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($this->createdCertificateIds), '?'));
            $types = str_repeat('i', count($this->createdCertificateIds));
            db_execute(
                'DELETE FROM dh_file_refs WHERE moduleName = ? AND entityName = ? AND CAST(entityID AS UNSIGNED) IN (' . $placeholders . ')',
                'ss' . $types,
                CERTIFICATE_MODULE_NAME,
                CERTIFICATE_ENTITY_NAME,
                ...$this->createdCertificateIds
            );
        }

        if ($this->createdFileIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($this->createdFileIds), '?'));
            $types = str_repeat('i', count($this->createdFileIds));
            db_execute(
                'DELETE FROM dh_files WHERE fileID IN (' . $placeholders . ')',
                $types,
                ...$this->createdFileIds
            );
        }

        foreach (array_reverse($this->createdCertificateIds) as $certificateId) {
            db_execute('DELETE FROM dh_certificates WHERE certificateID = ?', 'i', $certificateId);
        }

        $this->createdCertificateIds = [];
        $this->createdFileIds = [];
    }

    private function cleanupAuditLogs(): void
    {
        db_execute('DELETE FROM dh_logs WHERE moduleName = ? AND requestURL = ?', 'ss', 'certificates', '/phpunit/certificates-workflow');
    }

    private function requireActiveTeacherPid(string $message): string
    {
        $row = db_fetch_one('SELECT pID FROM teacher WHERE status = 1 ORDER BY pID ASC LIMIT 1');

        if (!is_array($row) || !isset($row['pID'])) {
            $this->markTestSkipped($message);
        }

        return trim((string) ($row['pID'] ?? ''));
    }
}
