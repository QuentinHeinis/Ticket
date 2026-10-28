<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * @return list<Ticket>
     */
    public function findOverdue(\DateTimeImmutable $now, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.assignee', 'a')
            ->addSelect('a')
            ->orderBy('t.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $this->overdueCondition($qb, $now);

        return $qb->getQuery()->getResult();
    }

    public function countOverdue(\DateTimeImmutable $now): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)');

        $this->overdueCondition($qb, $now);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{total: int, unassigned: int, overdue: int}
     */
    public function dashboardCounts(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));

        $overdueParts = [];
        $parameters = [
            'newStatus' => Status::New->value,
            'overdueStatuses' => [Status::New->value, Status::Assigned->value],
        ];

        foreach (Priority::cases() as $index => $priority) {
            $limit = $now->modify(sprintf('-%d minutes', $priority->handlingLimitMinutes()));

            $overdueParts[] = sprintf(
                '(t.priority = :overdue_priority_%d AND t.createdAt <= :overdue_limit_%d)',
                $index,
                $index,
            );
            $parameters[sprintf('overdue_priority_%d', $index)] = $priority->value;
            $parameters[sprintf('overdue_limit_%d', $index)] = $limit;
        }

        $dql = sprintf(
            'SELECT COUNT(t.id) AS total,
                COALESCE(SUM(CASE WHEN t.status = :newStatus AND t.assignee IS NULL THEN 1 ELSE 0 END), 0) AS unassigned,
                COALESCE(SUM(CASE WHEN t.status IN (:overdueStatuses) AND (%s) THEN 1 ELSE 0 END), 0) AS overdue
            FROM %s t',
            implode(' OR ', $overdueParts),
            Ticket::class,
        );

        $row = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameters($parameters)
            ->getSingleResult();

        return [
            'total' => (int) $row['total'],
            'unassigned' => (int) $row['unassigned'],
            'overdue' => (int) $row['overdue'],
        ];
    }

    private function overdueCondition(QueryBuilder $qb, \DateTimeImmutable $now): void
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));

        $parts = [];

        foreach (Priority::cases() as $index => $priority) {
            $limit = $now->modify(sprintf('-%d minutes', $priority->handlingLimitMinutes()));

            $parts[] = sprintf(
                '(t.priority = :overdue_priority_%d AND t.createdAt <= :overdue_limit_%d)',
                $index,
                $index,
            );

            $qb
                ->setParameter(sprintf('overdue_priority_%d', $index), $priority->value)
                ->setParameter(
                    sprintf('overdue_limit_%d', $index),
                    $limit,
                    Types::DATETIME_IMMUTABLE,
                );
        }

        $qb
            ->andWhere('t.status IN (:overdueStatuses)')
            ->andWhere(implode(' OR ', $parts))
            ->setParameter('overdueStatuses', [Status::New->value, Status::Assigned->value]);
    }
}
