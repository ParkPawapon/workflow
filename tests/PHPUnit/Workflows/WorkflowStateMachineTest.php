<?php

declare(strict_types=1);

namespace Tests\PHPUnit\Workflows;

use Tests\Support\WorkflowTestCase;

final class WorkflowStateMachineTest extends WorkflowTestCase
{
    public function testRequiredTransitionsRemainAvailable(): void
    {
        $machine = workflow_state_machine();

        $this->assertSame([INTERNAL_STATUS_SENT], $machine['internal'][INTERNAL_STATUS_DRAFT] ?? []);
        $this->assertSame([INTERNAL_STATUS_RECALLED, INTERNAL_STATUS_ARCHIVED], $machine['internal'][INTERNAL_STATUS_SENT] ?? []);
        $this->assertSame([OUTGOING_STATUS_COMPLETE], $machine['outgoing'][OUTGOING_STATUS_WAITING_ATTACHMENT] ?? []);
        $this->assertSame([REPAIR_STATUS_IN_PROGRESS, REPAIR_STATUS_COMPLETED, REPAIR_STATUS_CANCELLED], $machine['repairs'][REPAIR_STATUS_PENDING] ?? []);
        $this->assertContains(MEMO_STATUS_SIGNED, $machine['memos'][MEMO_STATUS_IN_REVIEW] ?? []);
        $this->assertContains(MEMO_STATUS_DRAFT, $machine['memos'][MEMO_STATUS_APPROVED_UNSIGNED] ?? []);
    }
}
