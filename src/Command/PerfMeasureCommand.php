<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\TicketSearchCriteria;
use App\Enum\Priority;
use App\Enum\Status;
use App\Repository\TicketHistoryRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:perf:measure', description: 'Mesure les performances des recherches et agrégations sur var/perf.db')]
final class PerfMeasureCommand extends Command
{
    private const RUNS = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketRepository $ticketRepository,
        private readonly UserRepository $userRepository,
        private readonly TicketHistoryRepository $ticketHistoryRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $databasePath = $this->connection->getParams()['path'] ?? '';

        if (!is_string($databasePath) || !str_contains($databasePath, 'perf.db')) {
            $io->error(sprintf(
                'Cette commande ne peut mesurer que la base "perf.db" (base courante : "%s").',
                is_string($databasePath) ? $databasePath : 'inconnue',
            ));

            return Command::FAILURE;
        }

        $io->text(sprintf('Base ciblée : %s', $databasePath));
        $io->note(
            'Les SQL affichés pour EXPLAIN QUERY PLAN sont des équivalents reconstruits à la main '
            .'à partir des DQL/SQL des repositories : ils ne sont pas extraits directement de Doctrine.',
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $assignee = $this->userRepository->findOneBy([], ['id' => 'ASC']);
        $assigneeId = (int) ($assignee?->getId() ?? 1);
        $historyTicket = $this->ticketRepository->findOneBy([], ['id' => 'ASC']);
        $historyTicketId = (int) ($historyTicket?->getId() ?? 1);
        $this->entityManager->clear();

        $deepCursor = null;
        $deepCursorSteps = 0;
        $cursor = null;

        for ($step = 0; $step < 200; ++$step) {
            $page = $this->ticketRepository->search(new TicketSearchCriteria(
                null,
                null,
                null,
                null,
                null,
                $cursor,
                50,
            ));

            if (null === $page['nextCursor']) {
                break;
            }

            $cursor = $page['nextCursor'];
            ++$deepCursorSteps;
        }

        $deepCursor = $cursor;
        $this->entityManager->clear();

        $results = [];

        $searchWithoutFilter = new TicketSearchCriteria(null, null, null, null, null, null, 50);
        $results[] = [
            'search sans filtre, limit 50',
            $this->measure(
                '1. search sans filtre, limit 50',
                fn () => $this->ticketRepository->search($searchWithoutFilter),
                [$this->buildSearchExplain($searchWithoutFilter)],
                $io,
            ),
        ];

        $searchNew = new TicketSearchCriteria(Status::New, null, null, null, null, null, 50);
        $results[] = [
            'search status=new, limit 50',
            $this->measure(
                '2. search status=new, limit 50',
                fn () => $this->ticketRepository->search($searchNew),
                [$this->buildSearchExplain($searchNew)],
                $io,
            ),
        ];

        $searchClosed = new TicketSearchCriteria(Status::Closed, null, null, null, null, null, 50);
        $results[] = [
            'search status=closed, limit 50',
            $this->measure(
                '3. search status=closed, limit 50',
                fn () => $this->ticketRepository->search($searchClosed),
                [$this->buildSearchExplain($searchClosed)],
                $io,
            ),
        ];

        $searchAssignee = new TicketSearchCriteria(null, null, $assigneeId, null, null, null, 50);
        $results[] = [
            sprintf('search assigneeId=%d', $assigneeId),
            $this->measure(
                sprintf('4. search assigneeId=%d', $assigneeId),
                fn () => $this->ticketRepository->search($searchAssignee),
                [$this->buildSearchExplain($searchAssignee)],
                $io,
            ),
        ];

        $periodFrom = $now->modify('-7 days');
        $searchUrgentPeriod = new TicketSearchCriteria(
            null,
            Priority::Urgent,
            null,
            $periodFrom,
            $now,
            null,
            50,
        );
        $results[] = [
            'search priority=urgent + période 7 jours',
            $this->measure(
                '5. search priority=urgent + période de 7 jours',
                fn () => $this->ticketRepository->search($searchUrgentPeriod),
                [$this->buildSearchExplain($searchUrgentPeriod)],
                $io,
            ),
        ];

        $searchDeepCursor = new TicketSearchCriteria(
            null,
            null,
            null,
            null,
            null,
            $deepCursor,
            50,
        );
        $deepCursorLabel = sprintf(
            'search curseur profond (%d pas%s)',
            $deepCursorSteps,
            null === $deepCursor ? ', curseur épuisé avant 200' : '',
        );
        $results[] = [
            $deepCursorLabel,
            $this->measure(
                '6. '.$deepCursorLabel,
                fn () => $this->ticketRepository->search($searchDeepCursor),
                [$this->buildSearchExplain($searchDeepCursor)],
                $io,
            ),
        ];

        [$overdueSql, $overdueParams] = $this->buildOverdueParts($now);
        $overdueStatusParams = [Status::New->value, Status::Assigned->value];
        $overdueQueryParams = array_merge($overdueStatusParams, $overdueParams);

        $results[] = [
            'findOverdue + countOverdue',
            $this->measure(
                '7. findOverdue + countOverdue',
                function () use ($now): void {
                    $this->ticketRepository->findOverdue($now, 1, 50);
                    $this->ticketRepository->countOverdue($now);
                },
                [
                    [
                        'sql' => 'SELECT t.id, t.assignee_id, a.id AS assignee_user_id FROM ticket t '
                            .'LEFT JOIN app_user a ON t.assignee_id = a.id '
                            .'WHERE t.status IN (?, ?) AND '.$overdueSql
                            .' ORDER BY t.created_at ASC LIMIT 50',
                        'params' => $overdueQueryParams,
                    ],
                    [
                        'sql' => 'SELECT COUNT(t.id) FROM ticket t '
                            .'WHERE t.status IN (?, ?) AND '.$overdueSql,
                        'params' => $overdueQueryParams,
                    ],
                ],
                $io,
            ),
        ];

        $results[] = [
            'dashboardCounts',
            $this->measure(
                '8. dashboardCounts',
                fn () => $this->ticketRepository->dashboardCounts($now),
                [
                    [
                        'sql' => 'SELECT COUNT(t.id) AS total, '
                            .'COALESCE(SUM(CASE WHEN t.status = ? AND t.assignee_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned, '
                            .'COALESCE(SUM(CASE WHEN t.status IN (?, ?) AND '.$overdueSql.' THEN 1 ELSE 0 END), 0) AS overdue '
                            .'FROM ticket t',
                        'params' => array_merge(
                            [Status::New->value],
                            $overdueStatusParams,
                            $overdueParams,
                        ),
                    ],
                ],
                $io,
            ),
        ];

        $results[] = [
            'findDashboardRows',
            $this->measure(
                '9. findDashboardRows',
                fn () => $this->userRepository->findDashboardRows(),
                [
                    [
                        'sql' => 'SELECT u.name AS name, u.capacity AS capacity, COUNT(t.id) AS activeTickets, '
                            .'COALESCE(SUM('.$this->buildPriorityCase('t.priority').'), 0) AS currentLoad '
                            .'FROM app_user u LEFT JOIN ticket t ON t.assignee_id = u.id AND t.status IN (?, ?) '
                            .'GROUP BY u.id, u.name, u.capacity ORDER BY u.name ASC',
                        'params' => array_merge(
                            $this->buildPriorityCaseParameters(),
                            [Status::Assigned->value, Status::InProgress->value],
                        ),
                    ],
                ],
                $io,
            ),
        ];

        $results[] = [
            'findLoads',
            $this->measure(
                '10. findLoads',
                fn () => $this->userRepository->findLoads(),
                [
                    [
                        'sql' => 'SELECT u.id AS userId, u.capacity AS capacity, u.last_assigned_at AS lastAssignedAt, '
                            .'COUNT(t.id) AS activeCount, '
                            .'COALESCE(SUM('.$this->buildPriorityCase('t.priority').'), 0) AS load '
                            .'FROM app_user u LEFT JOIN ticket t ON t.assignee_id = u.id AND t.status IN (?, ?) '
                            .'WHERE u.available = ? GROUP BY u.id',
                        'params' => array_merge(
                            $this->buildPriorityCaseParameters(),
                            [Status::Assigned->value, Status::InProgress->value],
                            [1],
                        ),
                    ],
                ],
                $io,
            ),
        ];

        $results[] = [
            sprintf('historique du ticket #%d', $historyTicketId),
            $this->measure(
                sprintf('11. historique du ticket #%d', $historyTicketId),
                fn () => $this->ticketHistoryRepository
                    ->createQueryBuilder('h')
                    ->where('h.ticket = :ticketId')
                    ->setParameter('ticketId', $historyTicketId)
                    ->orderBy('h.createdAt', 'ASC')
                    ->getQuery()
                    ->getResult(),
                [
                    [
                        'sql' => 'SELECT h.id, h.type, h.from_value, h.to_value, h.created_at, h.ticket_id, h.actor_id '
                            .'FROM ticket_history h WHERE h.ticket_id = ? ORDER BY h.created_at ASC',
                        'params' => [$historyTicketId],
                    ],
                ],
                $io,
            ),
        ];

        $io->section('Récapitulatif');
        $io->table(
            ['Scénario', 'Temps moyen (ms)'],
            array_map(
                static fn (array $row): array => [$row[0], sprintf('%.3f', $row[1])],
                $results,
            ),
        );

        return Command::SUCCESS;
    }

    /**
     * @param callable(): mixed $runner
     * @param list<array{sql: string, params: list<mixed>}> $explainQueries
     */
    private function measure(string $name, callable $runner, array $explainQueries, SymfonyStyle $io): float
    {
        $totalMilliseconds = 0.0;

        for ($run = 0; $run < self::RUNS; ++$run) {
            $this->entityManager->clear();

            $startedAt = hrtime(true);
            $runner();
            $totalMilliseconds += (hrtime(true) - $startedAt) / 1_000_000;
        }

        $this->entityManager->clear();
        $average = $totalMilliseconds / self::RUNS;

        $io->section($name);
        $io->text(sprintf('Temps moyen sur %d exécutions : %.3f ms', self::RUNS, $average));

        foreach ($explainQueries as $query) {
            $io->text('SQL EXPLAIN QUERY PLAN (équivalent écrit à la main) :');
            $io->text($query['sql']);

            try {
                $plan = $this->connection->fetchAllAssociative(
                    'EXPLAIN QUERY PLAN '.$query['sql'],
                    $query['params'],
                );
            } catch (\Throwable $exception) {
                $io->error(sprintf('Impossible d\'obtenir le plan : %s', $exception->getMessage()));
                continue;
            }

            $details = array_map(
                static fn (array $row): string => (string) ($row['detail'] ?? ''),
                $plan,
            );

            if ([] === $details) {
                $io->text('Plan : (aucune ligne retournée)');
            } else {
                $io->listing($details);
            }
        }

        return $average;
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function buildSearchExplain(TicketSearchCriteria $criteria): array
    {
        $conditions = [];
        $params = [];

        if (null !== $criteria->status) {
            $conditions[] = 't.status = ?';
            $params[] = $criteria->status->value;
        }

        if (null !== $criteria->priority) {
            $conditions[] = 't.priority = ?';
            $params[] = $criteria->priority->value;
        }

        if (null !== $criteria->assigneeId) {
            $conditions[] = 't.assignee_id = ?';
            $params[] = $criteria->assigneeId;
        }

        if (null !== $criteria->createdFrom) {
            $conditions[] = 't.created_at >= ?';
            $params[] = $criteria->createdFrom
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        if (null !== $criteria->createdTo) {
            $conditions[] = 't.created_at < ?';
            $params[] = $criteria->createdTo
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        if (null !== $criteria->cursor) {
            $decoded = base64_decode($criteria->cursor, true);
            $parts = explode('|', (string) $decoded);
            $cursorDate = \DateTimeImmutable::createFromFormat(
                \DateTimeInterface::ATOM,
                $parts[0] ?? '',
            );

            if (false !== $cursorDate && isset($parts[1])) {
                $cursorDateValue = $cursorDate
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');

                $conditions[] = '(t.created_at < ? OR (t.created_at = ? AND t.id < ?))';
                $params[] = $cursorDateValue;
                $params[] = $cursorDateValue;
                $params[] = (int) $parts[1];
            }
        }

        $sql = 'SELECT t.id, t.created_at, t.assignee_id, a.id AS assignee_user_id '
            .'FROM ticket t LEFT JOIN app_user a ON t.assignee_id = a.id';

        if ([] !== $conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY t.created_at DESC, t.id DESC LIMIT '.($criteria->limit + 1);

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function buildOverdueParts(\DateTimeImmutable $now): array
    {
        $parts = [];
        $params = [];

        foreach (Priority::cases() as $priority) {
            $limit = $now
                ->modify(sprintf('-%d minutes', $priority->handlingLimitMinutes()))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');

            $parts[] = '(t.priority = ? AND t.created_at <= ?)';
            $params[] = $priority->value;
            $params[] = $limit;
        }

        return ['('.implode(' OR ', $parts).')', $params];
    }

    private function buildPriorityCase(string $column): string
    {
        $parts = [];

        foreach (Priority::cases() as $priority) {
            $parts[] = sprintf('WHEN %s = ? THEN %d', $column, $priority->points());
        }

        return 'CASE '.implode(' ', $parts).' ELSE 0 END';
    }

    /**
     * @return list<string>
     */
    private function buildPriorityCaseParameters(): array
    {
        return array_map(
            static fn (Priority $priority): string => $priority->value,
            Priority::cases(),
        );
    }
}
