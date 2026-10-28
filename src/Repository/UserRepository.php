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
    public function findLoads(): array
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
            WHERE u.available = true
            GROUP BY u.id',
            implode(' ', $caseParts),
            User::class,
            Ticket::class,
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
}
