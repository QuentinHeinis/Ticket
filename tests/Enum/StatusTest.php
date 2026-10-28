<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Priority;
use App\Enum\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    public function testValidTransitions(): void
    {
        $this->assertTrue(Status::New->canTransitionTo(Status::Assigned));
        $this->assertTrue(Status::Resolved->canTransitionTo(Status::InProgress));
    }

    public function testRefusedTransitions(): void
    {
        $this->assertFalse(Status::New->canTransitionTo(Status::Resolved));
        $this->assertFalse(Status::Closed->canTransitionTo(Status::InProgress));
        $this->assertFalse(Status::Assigned->canTransitionTo(Status::New));
    }

    public function testIsActive(): void
    {
        $this->assertFalse(Status::New->isActive());
        $this->assertTrue(Status::Assigned->isActive());
        $this->assertTrue(Status::InProgress->isActive());
        $this->assertFalse(Status::Resolved->isActive());
        $this->assertFalse(Status::Closed->isActive());
    }

    public function testPriorityPoints(): void
    {
        $this->assertSame(1, Priority::Low->points());
        $this->assertSame(2, Priority::Normal->points());
        $this->assertSame(4, Priority::High->points());
        $this->assertSame(8, Priority::Urgent->points());
    }

    public function testPriorityEscalation(): void
    {
        $this->assertSame(Priority::Normal, Priority::Low->escalated());
        $this->assertSame(Priority::High, Priority::Normal->escalated());
        $this->assertSame(Priority::Urgent, Priority::High->escalated());
        $this->assertSame(Priority::Urgent, Priority::Urgent->escalated());
        $this->assertNull(Priority::Urgent->escalationDelayMinutes());
    }
}
