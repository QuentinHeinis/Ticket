<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Status;

final readonly class ChangeStatusRequest
{
    public function __construct(
        public Status $status,
    ) {
    }
}
