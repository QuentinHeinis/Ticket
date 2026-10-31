<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Priority;
use App\Enum\Status;

final readonly class TicketSearchCriteria
{
    public function __construct(
        public ?Status $status,
        public ?Priority $priority,
        public ?int $assigneeId,
        public ?\DateTimeImmutable $createdFrom,
        public ?\DateTimeImmutable $createdTo,
        public ?string $cursor,
        public int $limit,
    ) {
    }
}
