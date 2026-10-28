<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Status;
use App\Exception\CapacityExceededException;
use App\Exception\InvalidStatusTransitionException;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TicketStatusService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketRepository $ticketRepository,
    ) {
    }

    public function changeStatus(Ticket $ticket, Status $target, ?User $actor = null): void
    {
        $this->entityManager->wrapInTransaction(function () use ($ticket, $target, $actor): void {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE ticket SET id = id WHERE id = :id',
                ['id' => $ticket->getId()],
            );

            $this->entityManager->refresh($ticket);

            $from = $ticket->getStatus();

            if (!$from->canTransitionTo($target)) {
                throw new InvalidStatusTransitionException($from, $target);
            }

            $assignee = $ticket->getAssignee();

            if (Status::Assigned === $target && null === $assignee) {
                throw new InvalidStatusTransitionException($from, $target);
            }

            if (!$from->isActive() && $target->isActive() && null !== $assignee) {
                if ($this->ticketRepository->countActiveByAssignee($assignee) >= $assignee->getCapacity()) {
                    throw new CapacityExceededException($assignee);
                }
            }

            $ticket->setStatus($target);

            $this->entityManager->persist(new TicketHistory(
                $ticket,
                HistoryType::StatusChanged,
                $from->value,
                $target->value,
                $actor,
            ));
        });
    }
}
