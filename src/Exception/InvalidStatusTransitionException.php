<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Status;

final class InvalidStatusTransitionException extends \DomainException
{
    public function __construct(Status $from, Status $to)
    {
        parent::__construct(sprintf('Transition interdite : %s -> %s', $from->value, $to->value));
    }
}
