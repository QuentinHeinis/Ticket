<?php

declare(strict_types=1);

namespace App\Tests\Service\Assignment;

use App\Service\Assignment\AssigneeSelector;
use App\Service\Assignment\UserLoad;
use PHPUnit\Framework\TestCase;

final class AssigneeSelectorTest extends TestCase
{
    private AssigneeSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new AssigneeSelector();
    }

    public function testEmptyListReturnsNull(): void
    {
        $this->assertNull($this->selector->select([]));
    }

    public function testAllUsersAtFullCapacityReturnsNull(): void
    {
        $loads = [
            $this->userLoad(1, 5, 0, 5),
            $this->userLoad(2, 3, 0, 3),
        ];

        $this->assertNull($this->selector->select($loads));
    }

    public function testUserAtFullCapacityIsIgnoredEvenWithLowestLoad(): void
    {
        $full = $this->userLoad(1, 5, 0, 5);
        $available = $this->userLoad(2, 1, 10, 5);

        $this->assertSame($available, $this->selector->select([$full, $available]));
    }

    public function testLowestLoadWins(): void
    {
        $low = $this->userLoad(1, 1, 5, 5);
        $winner = $this->userLoad(2, 1, 2, 5);
        $high = $this->userLoad(3, 1, 8, 5);

        $this->assertSame($winner, $this->selector->select([$low, $winner, $high]));
    }

    public function testEqualLoadOldestLastAssignedAtWins(): void
    {
        $oldest = $this->userLoad(1, 1, 3, 5, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $newer = $this->userLoad(2, 1, 3, 5, new \DateTimeImmutable('2026-01-02T10:00:00+00:00'));

        $this->assertSame($oldest, $this->selector->select([$newer, $oldest]));
    }

    public function testEqualLoadNullLastAssignedAtWinsOverDate(): void
    {
        $neverAssigned = $this->userLoad(1, 1, 3, 5, null);
        $assigned = $this->userLoad(2, 1, 3, 5, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));

        $this->assertSame($neverAssigned, $this->selector->select([$assigned, $neverAssigned]));
    }

    public function testEqualLoadAndDateLowestUserIdWins(): void
    {
        $lowId = $this->userLoad(2, 1, 3, 5, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $highId = $this->userLoad(7, 1, 3, 5, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));

        $this->assertSame($lowId, $this->selector->select([$highId, $lowId]));
    }

    public function testResultDoesNotDependOnInputOrder(): void
    {
        $first = $this->userLoad(3, 1, 2, 5, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $second = $this->userLoad(1, 1, 4, 5, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $third = $this->userLoad(2, 1, 2, 5, new \DateTimeImmutable('2026-01-02T10:00:00+00:00'));

        $forward = $this->selector->select([$first, $second, $third]);
        $backward = $this->selector->select([$third, $second, $first]);

        $this->assertSame($first, $forward);
        $this->assertSame($first, $backward);
    }

    private function userLoad(
        int $userId,
        int $activeCount,
        int $load,
        int $capacity,
        ?\DateTimeImmutable $lastAssignedAt = null,
    ): UserLoad {
        return new UserLoad($userId, $activeCount, $load, $capacity, $lastAssignedAt);
    }
}
