<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Service\Assignment\UserLoad;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @return list<UserLoad>
     */
    public function findLoads(bool $onlyAvailable = true): array
    {
        $caseParts = [];
        $parameters = [
            'statuses' => [Status::Assigned->value, Status::InProgress->value],
        ];

        foreach (Priority::cases() as $index => $priority) {
            $caseParts[] = sprintf('WHEN t.priority = :priority_%d THEN %d', $index, $priority->points());
            $parameters[sprintf('priority_%d', $index)] = $priority->value;
        }

        $dql = sprintf(
            'SELECT u.id AS userId, u.capacity AS capacity, u.lastAssignedAt AS lastAssignedAt,
                COUNT(t.id) AS activeCount,
                COALESCE(SUM(CASE %s ELSE 0 END), 0) AS load
            FROM %s u
            LEFT JOIN %s t ON t.assignee = u AND t.status IN (:statuses)
            %s
            GROUP BY u.id',
            implode(' ', $caseParts),
            User::class,
            Ticket::class,
            $onlyAvailable ? 'WHERE u.available = true' : '',
        );

        $query = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameters($parameters);

        return array_map(static function (array $row): UserLoad {
            $lastAssignedAt = $row['lastAssignedAt'];

            if (is_string($lastAssignedAt)) {
                $lastAssignedAt = (new \DateTimeImmutable($lastAssignedAt, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'));
            } elseif ($lastAssignedAt instanceof \DateTimeInterface) {
                $lastAssignedAt = \DateTimeImmutable::createFromInterface($lastAssignedAt)
                    ->setTimezone(new \DateTimeZone('UTC'));
            }

            return new UserLoad(
                (int) $row['userId'],
                (int) $row['activeCount'],
                (int) $row['load'],
                (int) $row['capacity'],
                $lastAssignedAt,
            );
        }, $query->getResult());
    }

    /**
     * @return list<array{name: string, activeTickets: int, currentLoad: int, capacity: int}>
     */
    public function findDashboardRows(): array
    {
        $caseParts = [];
        $parameters = [
            'statuses' => [Status::Assigned->value, Status::InProgress->value],
        ];

        foreach (Priority::cases() as $index => $priority) {
            $caseParts[] = sprintf('WHEN t.priority = :priority_%d THEN %d', $index, $priority->points());
            $parameters[sprintf('priority_%d', $index)] = $priority->value;
        }

        $dql = sprintf(
            'SELECT u.name AS name, u.capacity AS capacity,
                COUNT(t.id) AS activeTickets,
                COALESCE(SUM(CASE %s ELSE 0 END), 0) AS currentLoad
            FROM %s u
            LEFT JOIN %s t ON t.assignee = u AND t.status IN (:statuses)
            GROUP BY u.id, u.name, u.capacity
            ORDER BY u.name ASC',
            implode(' ', $caseParts),
            User::class,
            Ticket::class,
        );

        $rows = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameters($parameters)
            ->getResult();

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'activeTickets' => (int) $row['activeTickets'],
            'currentLoad' => (int) $row['currentLoad'],
            'capacity' => (int) $row['capacity'],
        ], $rows);
    }
}
