<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Entity\Ticket;
use App\Enum\Priority;
use App\Enum\Status;
use App\Service\Assignment\TicketAssignmentService;
use Doctrine\ORM\EntityManagerInterface;

final class PendingAssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketAssignmentService $assignmentService,
    ) {
    }

    public function assignPending(int $limit = 200): int
    {
        $caseParts = [];
        $parameters = ['status' => Status::New->value];

        foreach (Priority::cases() as $index => $priority) {
            $caseParts[] = sprintf('WHEN :priority_%d THEN %d', $index, $priority->points());
            $parameters[sprintf('priority_%d', $index)] = $priority->value;
        }

        $dql = sprintf(
            'SELECT t.id
            FROM %s t
            WHERE t.status = :status
              AND t.assignee IS NULL
            ORDER BY CASE t.priority %s ELSE 0 END DESC, t.createdAt ASC',
            Ticket::class,
            implode(' ', $caseParts),
        );

        $ids = $this->entityManager->createQuery($dql)
            ->setParameters($parameters)
            ->setMaxResults($limit)
            ->getSingleColumnResult();

        $assigned = 0;

        foreach ($ids as $id) {
            $ticket = $this->entityManager->find(Ticket::class, (int) $id);

            if (null === $ticket) {
                continue;
            }

            if ($this->assignmentService->assign($ticket)) {
                ++$assigned;
            } elseif (Status::New === $ticket->getStatus() && null === $ticket->getAssignee()) {
                break;
            }

            $this->entityManager->clear();
        }

        return $assigned;
    }
}
