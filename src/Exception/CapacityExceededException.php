<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\User;

final class CapacityExceededException extends \DomainException
{
    public function __construct(User $user)
    {
        parent::__construct(sprintf(
            'Capacité maximale atteinte pour l\'utilisateur %s.',
            $user->getName(),
        ));
    }
}
