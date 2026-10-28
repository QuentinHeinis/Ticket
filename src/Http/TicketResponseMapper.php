<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Ticket;

final class TicketResponseMapper
{
    public function toArray(Ticket $ticket): array
    {
        $assignee = $ticket->getAssignee();

        return [
            'id' => $ticket->getId(),
            'title' => $ticket->getTitle(),
            'description' => $ticket->getDescription(),
            'priority' => $ticket->getPriority()->value,
            'status' => $ticket->getStatus()->value,
            'createdAt' => $ticket->getCreatedAt()
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::ATOM),
            'assignee' => null === $assignee ? null : [
                'id' => $assignee->getId(),
                'name' => $assignee->getName(),
            ],
        ];
    }
}
