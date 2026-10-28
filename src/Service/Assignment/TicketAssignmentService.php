<?php

declare(strict_types=1);

namespace App\Service\Assignment;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Status;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TicketAssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly AssigneeSelector $assigneeSelector,
    ) {
    }

    public function assign(Ticket $ticket): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($ticket): bool {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE ticket SET id = id WHERE id = :id',
                ['id' => $ticket->getId()],
            );

            $this->entityManager->refresh($ticket);

            if (Status::New !== $ticket->getStatus() || null !== $ticket->getAssignee()) {
                return false;
            }

            $loads = $this->userRepository->findLoads();
            $chosen = $this->assigneeSelector->select($loads);

            if (null === $chosen) {
                return false;
            }

            $user = $this->entityManager->find(User::class, $chosen->userId);

            if (null === $user) {
                return false;
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $ticket->setAssignee($user);
            $ticket->setStatus(Status::Assigned);
            $user->setLastAssignedAt($now);

            $this->entityManager->persist(new TicketHistory(
                $ticket,
                HistoryType::Assigned,
                null,
                (string) $user->getId(),
                null,
            ));

            $this->entityManager->persist(new TicketHistory(
                $ticket,
                HistoryType::StatusChanged,
                'new',
                'assigned',
                null,
            ));

            return true;
        });
    }
}
