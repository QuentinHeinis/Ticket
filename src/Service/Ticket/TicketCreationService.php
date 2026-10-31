<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Dto\CreateTicketRequest;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Service\Assignment\TicketAssignmentService;
use Doctrine\ORM\EntityManagerInterface;

final class TicketCreationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketAssignmentService $assignmentService,
    ) {
    }

    public function create(CreateTicketRequest $request, ?User $actor = null): Ticket
    {
        $ticket = $this->entityManager->wrapInTransaction(function () use ($request, $actor): Ticket {
            $ticket = new Ticket($request->title, $request->description, $request->priority);

            $this->entityManager->persist($ticket);
            $this->entityManager->persist(new TicketHistory(
                $ticket,
                HistoryType::Created,
                null,
                $request->priority->value,
                $actor,
            ));

            return $ticket;
        });

        if ($this->assignmentService->assign($ticket)) {
            $this->entityManager->refresh($ticket);
        }

        return $ticket;
    }
}
