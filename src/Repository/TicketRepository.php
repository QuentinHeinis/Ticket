<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Status;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function countActiveByAssignee(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.assignee = :assignee')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('assignee', $user)
            ->setParameter('statuses', [Status::Assigned->value, Status::InProgress->value])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
