<?php

declare(strict_types=1);

namespace Tests\PHPUnit\Workflows;

use Tests\Support\WorkflowTestCase;

final class MemoWorkflowTest extends WorkflowTestCase
{
    public function testMemoSharedStatusDefinitionsStayConsistentAcrossViews(): void
    {
        $definitions = memo_status_definitions();

        $this->assertSame([
            MEMO_STATUS_DRAFT,
            MEMO_STATUS_IN_REVIEW,
            MEMO_STATUS_SUBMITTED,
            MEMO_STATUS_CANCELLED,
            MEMO_STATUS_RETURNED,
            MEMO_STATUS_APPROVED_UNSIGNED,
            MEMO_STATUS_SIGNED,
            MEMO_STATUS_REJECTED,
        ], array_keys($definitions));

        $this->assertSame('รอการเสนอแฟ้ม', $definitions[MEMO_STATUS_DRAFT]['label'] ?? null);
        $this->assertSame('processing', $definitions[MEMO_STATUS_IN_REVIEW]['pill_variant'] ?? null);
        $this->assertSame('approved', $definitions[MEMO_STATUS_SIGNED]['pill_variant'] ?? null);
        $this->assertSame('danger', $definitions[MEMO_STATUS_CANCELLED]['badge_variant'] ?? null);

        $this->assertSame([
            'all' => 'ทั้งหมด',
            MEMO_STATUS_DRAFT => 'รอการเสนอแฟ้ม',
            MEMO_STATUS_IN_REVIEW => 'กำลังพิจารณา',
            MEMO_STATUS_SUBMITTED => 'รอพิจารณา',
            MEMO_STATUS_CANCELLED => 'ยกเลิก',
            MEMO_STATUS_RETURNED => 'ตีกลับแก้ไข',
            MEMO_STATUS_APPROVED_UNSIGNED => 'ลงนามแล้ว',
            MEMO_STATUS_SIGNED => 'ลงนามแล้ว',
            MEMO_STATUS_REJECTED => 'ไม่อนุมัติ',
        ], memo_status_options());

        $this->assertSame([
            MEMO_STATUS_DRAFT => 1,
            MEMO_STATUS_IN_REVIEW => 2,
            MEMO_STATUS_SUBMITTED => 3,
            MEMO_STATUS_CANCELLED => 4,
            MEMO_STATUS_RETURNED => 5,
            MEMO_STATUS_APPROVED_UNSIGNED => 6,
            MEMO_STATUS_SIGNED => 7,
            MEMO_STATUS_REJECTED => 8,
        ], memo_status_sort_priority_map());
    }

    public function testMemoStateMachineMatchesServiceRecallAndTerminalRules(): void
    {
        $machine = workflow_state_machine();
        $memoMachine = $machine['memos'] ?? [];

        $this->assertContains(MEMO_STATUS_SUBMITTED, $memoMachine[MEMO_STATUS_DRAFT] ?? []);
        $this->assertContains(MEMO_STATUS_CANCELLED, $memoMachine[MEMO_STATUS_DRAFT] ?? []);
        $this->assertContains(MEMO_STATUS_DRAFT, $memoMachine[MEMO_STATUS_APPROVED_UNSIGNED] ?? []);
        $this->assertContains(MEMO_STATUS_SIGNED, $memoMachine[MEMO_STATUS_APPROVED_UNSIGNED] ?? []);
        $this->assertContains(MEMO_STATUS_CANCELLED, $memoMachine[MEMO_STATUS_APPROVED_UNSIGNED] ?? []);
        $this->assertSame([], $memoMachine[MEMO_STATUS_SIGNED] ?? []);
        $this->assertSame([], $memoMachine[MEMO_STATUS_REJECTED] ?? []);
        $this->assertSame([], $memoMachine[MEMO_STATUS_CANCELLED] ?? []);
    }

    public function testCreatorCountMatchesPagedListingForRecentCreator(): void
    {
        $creatorPid = $this->requireScalarValue(
            'SELECT createdByPID FROM dh_memos WHERE deletedAt IS NULL AND createdByPID IS NOT NULL AND createdByPID <> "" ORDER BY memoID DESC LIMIT 1',
            'No memo creator data available'
        );

        $rows = memo_list_by_creator_page($creatorPid, false, 'all', '', 1000, 0, 'newest', null);
        $count = memo_count_by_creator($creatorPid, false, 'all', '', null);

        $this->assertSame($count, count($rows));
    }

    public function testCreatorSearchMatchesMemoNumberAndSubjectAcrossOwnListing(): void
    {
        $sample = db_fetch_one(
            'SELECT memoID, createdByPID, memoNo, subject
             FROM dh_memos
             WHERE deletedAt IS NULL
               AND createdByPID IS NOT NULL
               AND createdByPID <> ""
               AND (
                   (memoNo IS NOT NULL AND memoNo <> "")
                   OR (subject IS NOT NULL AND subject <> "")
               )
             ORDER BY memoID DESC
             LIMIT 1'
        );

        if (!is_array($sample)) {
            $this->markTestSkipped('No searchable memo sample available');
        }

        $memoId = (int) ($sample['memoID'] ?? 0);
        $creatorPid = trim((string) ($sample['createdByPID'] ?? ''));
        $memoNo = trim((string) ($sample['memoNo'] ?? ''));
        $subject = trim((string) ($sample['subject'] ?? ''));

        if ($memoId <= 0 || $creatorPid === '') {
            $this->markTestSkipped('Incomplete memo sample for creator search test');
        }

        if ($memoNo !== '') {
            $rowsByNumber = memo_list_by_creator_page($creatorPid, false, 'all', $memoNo, 50, 0, 'newest', null);
            $this->assertContains($memoId, array_column($rowsByNumber, 'memoID'));
        }

        if ($subject !== '') {
            $rowsBySubject = memo_list_by_creator_page($creatorPid, false, 'all', $subject, 50, 0, 'newest', null);
            $this->assertContains($memoId, array_column($rowsBySubject, 'memoID'));
        }
    }

    public function testReviewerInboxKeepsAllowedStatusesAndOwnership(): void
    {
        $reviewerPid = $this->requireScalarValue(
            'SELECT toPID FROM dh_memos WHERE deletedAt IS NULL AND toPID IS NOT NULL AND toPID <> "" ORDER BY memoID DESC LIMIT 1',
            'No memo reviewer data available'
        );

        $rows = $this->requireRows(
            memo_list_by_reviewer_page($reviewerPid, 'all', '', 100, 0, null),
            'No memo reviewer inbox items available'
        );

        $allowedStatuses = [
            MEMO_STATUS_SUBMITTED,
            MEMO_STATUS_IN_REVIEW,
            MEMO_STATUS_RETURNED,
            MEMO_STATUS_APPROVED_UNSIGNED,
            MEMO_STATUS_SIGNED,
            MEMO_STATUS_REJECTED,
        ];

        foreach (array_slice($rows, 0, 20) as $row) {
            $memoId = (int) ($row['memoID'] ?? 0);
            $memo = $memoId > 0 ? memo_get($memoId) : null;

            $this->assertNotNull($memo, 'Memo reviewer detail lookup failed');
            $this->assertSame($reviewerPid, trim((string) ($memo['toPID'] ?? '')));
            $this->assertNotSame($reviewerPid, trim((string) ($memo['createdByPID'] ?? '')));
            $this->assertContains((string) ($memo['status'] ?? ''), $allowedStatuses);
        }
    }

    public function testReviewerYearOptionsMatchInboxYears(): void
    {
        $reviewerPid = $this->requireScalarValue(
            'SELECT toPID FROM dh_memos WHERE deletedAt IS NULL AND toPID IS NOT NULL AND toPID <> "" ORDER BY memoID DESC LIMIT 1',
            'No memo reviewer data available for year list test'
        );

        $rows = db_fetch_all(
            'SELECT DISTINCT dh_year
             FROM dh_memos
             WHERE toPID = ?
               AND createdByPID <> ?
               AND deletedAt IS NULL
               AND dh_year IS NOT NULL
               AND dh_year >= 2568
               AND (submittedAt IS NOT NULL OR status IN ("SUBMITTED","IN_REVIEW","RETURNED","APPROVED_UNSIGNED","SIGNED","REJECTED"))
             ORDER BY dh_year DESC',
            'ss',
            $reviewerPid,
            $reviewerPid
        );

        $expectedYears = array_map(
            static fn(array $row): int => (int) ($row['dh_year'] ?? 0),
            $rows
        );

        $expectedYears = array_values(array_filter($expectedYears, static fn(int $year): bool => $year > 0));

        $this->assertSame($expectedYears, memo_list_reviewer_years($reviewerPid));
    }

    public function testDeputyCandidatesForInboxForwardSelectionAreWellFormed(): void
    {
        $candidates = memo_list_deputy_candidates();

        if ($candidates === []) {
            $this->markTestSkipped('No deputy candidates available');
        }

        $seen = [];

        foreach ($candidates as $candidate) {
            $pid = trim((string) ($candidate['pID'] ?? ''));
            $name = trim((string) ($candidate['name'] ?? ''));

            $this->assertNotSame('', $pid);
            $this->assertMatchesRegularExpression('/^\d{1,13}$/', $pid);
            $this->assertNotSame('', $name);
            $this->assertArrayNotHasKey($pid, $seen);

            $seen[$pid] = true;
        }
    }
}
