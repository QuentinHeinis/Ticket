<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\TicketSearchCriteria;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Exception\InvalidSearchParameterException;
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

    /**
     * @return array{items: list<Ticket>, nextCursor: ?string}
     */
    public function search(TicketSearchCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.assignee', 'a')
            ->addSelect('a')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($criteria->limit + 1);

        if (null !== $criteria->status) {
            $qb
                ->andWhere('t.status = :searchStatus')
                ->setParameter('searchStatus', $criteria->status->value);
        }

        if (null !== $criteria->priority) {
            $qb
                ->andWhere('t.priority = :searchPriority')
                ->setParameter('searchPriority', $criteria->priority->value);
        }

        if (null !== $criteria->assigneeId) {
            $qb
                ->andWhere('t.assignee = :searchAssigneeId')
                ->setParameter('searchAssigneeId', $criteria->assigneeId);
        }

        if (null !== $criteria->createdFrom) {
            $qb
                ->andWhere('t.createdAt >= :searchCreatedFrom')
                ->setParameter(
                    'searchCreatedFrom',
                    $criteria->createdFrom->setTimezone(new \DateTimeZone('UTC')),
                    Types::DATETIME_IMMUTABLE,
                );
        }

        if (null !== $criteria->createdTo) {
            $qb
                ->andWhere('t.createdAt < :searchCreatedTo')
                ->setParameter(
                    'searchCreatedTo',
                    $criteria->createdTo->setTimezone(new \DateTimeZone('UTC')),
                    Types::DATETIME_IMMUTABLE,
                );
        }

        if (null !== $criteria->cursor) {
            $decoded = base64_decode($criteria->cursor, true);

            if (false === $decoded) {
                throw new InvalidSearchParameterException('Le paramètre "cursor" est malformé.');
            }

            $parts = explode('|', $decoded);

            if (2 !== count($parts)) {
                throw new InvalidSearchParameterException('Le paramètre "cursor" est malformé.');
            }

            [$cursorDateValue, $cursorIdValue] = $parts;

            if (!ctype_digit($cursorIdValue)) {
                throw new InvalidSearchParameterException('Le paramètre "cursor" est malformé.');
            }

            $cursorDate = \DateTimeImmutable::createFromFormat(
                \DateTimeInterface::ATOM,
                $cursorDateValue,
            );

            if (false === $cursorDate) {
                throw new InvalidSearchParameterException('Le paramètre "cursor" est malformé.');
            }

            $errors = \DateTimeImmutable::getLastErrors();

            if (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) {
                throw new InvalidSearchParameterException('Le paramètre "cursor" est malformé.');
            }

            $qb
                ->andWhere('(t.createdAt < :cursorCreatedAt OR (t.createdAt = :cursorCreatedAt AND t.id < :cursorId))')
                ->setParameter(
                    'cursorCreatedAt',
                    $cursorDate->setTimezone(new \DateTimeZone('UTC')),
                    Types::DATETIME_IMMUTABLE,
                )
                ->setParameter('cursorId', (int) $cursorIdValue);
        }

        /** @var list<Ticket> $items */
        $items = $qb->getQuery()->getResult();
        $nextCursor = null;

        if (count($items) > $criteria->limit) {
            array_pop($items);
            $last = $items[array_key_last($items)];

            $nextCursor = base64_encode(sprintf(
                '%s|%d',
                $last->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                $last->getId(),
            ));
        }

        return [
            'items' => $items,
            'nextCursor' => $nextCursor,
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
