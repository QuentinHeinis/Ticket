<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Enum\HistoryType;
use App\Enum\Priority;
use App\Enum\Status;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final class PriorityEscalationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function escalate(\DateTimeImmutable $now, int $batchSize = 500): int
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $escalated = 0;

        foreach (Priority::cases() as $priority) {
            $delay = $priority->escalationDelayMinutes();

            if (null === $delay) {
                continue;
            }

            $threshold = $now->modify(sprintf('-%d minutes', $delay));
            $newPriority = $priority->escalated();

            $ids = $this->entityManager->createQuery(sprintf(
                'SELECT t.id
                FROM %s t
                WHERE t.status = :status
                  AND t.assignee IS NULL
                  AND t.priority = :priority
                  AND t.priorityChangedAt <= :threshold
                ORDER BY t.priorityChangedAt ASC',
                Ticket::class,
            ))
                ->setParameter('status', Status::New->value)
                ->setParameter('priority', $priority->value)
                ->setParameter('threshold', $threshold, Types::DATETIME_IMMUTABLE)
                ->setMaxResults($batchSize)
                ->getSingleColumnResult();

            foreach ($ids as $id) {
                $id = (int) $id;

                $didEscalate = $this->entityManager->wrapInTransaction(
                    function () use ($id, $priority, $newPriority, $now): bool {
                        $affected = $this->entityManager->createQuery(sprintf(
                            'UPDATE %s t
                            SET t.priority = :newPriority, t.priorityChangedAt = :now
                            WHERE t.id = :id
                              AND t.status = :status
                              AND t.assignee IS NULL
                              AND t.priority = :oldPriority',
                            Ticket::class,
                        ))
                            ->setParameter('newPriority', $newPriority->value)
                            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                            ->setParameter('id', $id)
                            ->setParameter('status', Status::New->value)
                            ->setParameter('oldPriority', $priority->value)
                            ->execute();

                        if (0 === $affected) {
                            return false;
                        }

                        $ticket = $this->entityManager->getReference(Ticket::class, $id);

                        $this->entityManager->persist(new TicketHistory(
                            $ticket,
                            HistoryType::PriorityEscalated,
                            $priority->value,
                            $newPriority->value,
                            null,
                        ));

                        return true;
                    },
                );

                if ($didEscalate) {
                    ++$escalated;
                }
            }
        }

        return $escalated;
    }
}
