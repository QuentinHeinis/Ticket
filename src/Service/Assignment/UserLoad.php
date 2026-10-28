<?php

declare(strict_types=1);

namespace App\Service\Assignment;

final readonly class UserLoad
{
    public function __construct(
        public int $userId,
        public int $activeCount,
        public int $load,
        public int $capacity,
        public ?\DateTimeImmutable $lastAssignedAt,
    ) {
    }

    public function hasRoom(): bool
    {
        return $this->activeCount < $this->capacity;
    }
}
