<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReassignRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $assigneeId,
    ) {
    }
}
