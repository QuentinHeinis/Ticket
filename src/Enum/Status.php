<?php

declare(strict_types=1);

namespace App\Enum;

enum Status: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Assigned],
            self::Assigned => [self::InProgress],
            self::InProgress => [self::Resolved],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Assigned, self::InProgress => true,
            self::New, self::Resolved, self::Closed => false,
        };
    }
}
