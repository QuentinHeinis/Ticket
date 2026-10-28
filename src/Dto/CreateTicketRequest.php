<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Priority;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateTicketRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title,
        #[Assert\NotBlank]
        #[Assert\Length(max: 10000)]
        public string $description,
        public Priority $priority,
    ) {
    }
}
