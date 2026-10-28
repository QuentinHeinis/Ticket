<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Status;
use App\Exception\CapacityExceededException;
use App\Exception\ReassignmentNotAllowedException;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TicketReassignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketRepository $ticketRepository,
    ) {
    }

    public function reassign(Ticket $ticket, User $newAssignee, ?User $actor = null): void
    {
        $this->entityManager->wrapInTransaction(function () use ($ticket, $newAssignee, $actor): void {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE ticket SET id = id WHERE id = :id',
                ['id' => $ticket->getId()],
            );

            $this->entityManager->refresh($ticket);
            $this->entityManager->refresh($newAssignee);

            $status = $ticket->getStatus();

            if (Status::Closed === $status) {
                throw new ReassignmentNotAllowedException('Un ticket clôturé ne peut pas être réattribué.');
            }

            if (!$newAssignee->isAvailable()) {
                throw new ReassignmentNotAllowedException(sprintf(
                    'L\'utilisateur %s n\'est pas disponible.',
                    $newAssignee->getName(),
                ));
            }

            if ($ticket->getAssignee() === $newAssignee) {
                throw new ReassignmentNotAllowedException('Le ticket est déjà attribué à cet utilisateur.');
            }

            if (in_array($status, [Status::New, Status::Assigned, Status::InProgress], true)
                && $this->ticketRepository->countActiveByAssignee($newAssignee) >= $newAssignee->getCapacity()
            ) {
                throw new CapacityExceededException($newAssignee);
            }

            $previous = $ticket->getAssignee();
            $ticket->setAssignee($newAssignee);

            if (Status::New === $status) {
                $ticket->setStatus(Status::Assigned);

                $this->entityManager->persist(new TicketHistory(
                    $ticket,
                    HistoryType::Assigned,
                    null,
                    (string) $newAssignee->getId(),
                    $actor,
                ));

                $this->entityManager->persist(new TicketHistory(
                    $ticket,
                    HistoryType::StatusChanged,
                    'new',
                    'assigned',
                    $actor,
                ));

                return;
            }

            $this->entityManager->persist(new TicketHistory(
                $ticket,
                HistoryType::Reassigned,
                null === $previous ? null : (string) $previous->getId(),
                (string) $newAssignee->getId(),
                $actor,
            ));
        });
    }
}
