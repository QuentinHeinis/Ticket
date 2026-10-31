<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\HistoryType;
use App\Enum\Priority;
use App\Enum\Status;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:perf:generate', description: 'Génère un jeu de données de performance dans var/perf.db')]
final class PerfGenerateCommand extends Command
{
    private const BATCH_SIZE = 5000;
    private const SECONDS_PER_DAY = 86400;
    private const DAYS_SPAN = 365;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tickets', null, InputOption::VALUE_REQUIRED, 'Nombre de tickets à générer', '500000')
            ->addOption('history-per-ticket', null, InputOption::VALUE_REQUIRED, 'Nombre de lignes d\'historique par ticket', '4')
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Nombre d\'utilisateurs à générer', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $databasePath = $this->connection->getParams()['path'] ?? '';

        if (!is_string($databasePath) || !str_contains($databasePath, 'perf.db')) {
            $io->error(sprintf(
                'Cette commande ne peut écrire que dans une base "perf.db" (base courante : "%s").',
                is_string($databasePath) ? $databasePath : 'inconnue',
            ));

            return Command::FAILURE;
        }

        $ticketCount = filter_var($input->getOption('tickets'), FILTER_VALIDATE_INT);
        $historyPerTicket = filter_var($input->getOption('history-per-ticket'), FILTER_VALIDATE_INT);
        $userCount = filter_var($input->getOption('users'), FILTER_VALIDATE_INT);

        if (false === $ticketCount || $ticketCount < 0) {
            $io->error('L\'option --tickets doit être un entier supérieur ou égal à 0.');

            return Command::FAILURE;
        }

        if (false === $historyPerTicket || $historyPerTicket < 0) {
            $io->error('L\'option --history-per-ticket doit être un entier supérieur ou égal à 0.');

            return Command::FAILURE;
        }

        if (false === $userCount || $userCount < 1) {
            $io->error('L\'option --users doit être un entier supérieur ou égal à 1.');

            return Command::FAILURE;
        }

        $startedAt = microtime(true);
        $io->text(sprintf('Base ciblée : %s', $databasePath));

        $this->connection->executeStatement('DELETE FROM ticket_history');
        $this->connection->executeStatement('DELETE FROM ticket');
        $this->connection->executeStatement('DELETE FROM app_user');

        $passwordHash = password_hash('pom_zed123!', PASSWORD_BCRYPT);
        $roles = json_encode(['ROLE_USER'], JSON_THROW_ON_ERROR);

        $userRows = [];

        for ($index = 1; $index <= $userCount; ++$index) {
            $userRows[] = [
                sprintf('perf.user.%d@example.test', $index),
                sprintf('Perf User %d', $index),
                $passwordHash,
                $roles,
                1,
                mt_rand(3, 8),
                null,
            ];
        }

        $this->insertRows(
            'INSERT INTO app_user (email, name, password, roles, available, capacity, last_assigned_at)',
            $userRows,
            7,
        );

        unset($userRows);

        $users = $this->connection->fetchAllAssociative('SELECT id, capacity FROM app_user ORDER BY id');
        $userIds = [];
        $activeSlots = [];

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $capacity = (int) $user['capacity'];
            $userIds[] = $userId;

            for ($slot = 0; $slot < $capacity; ++$slot) {
                $activeSlots[] = $userId;
            }
        }

        shuffle($activeSlots);

        $newCount = (int) min($ticketCount, round($ticketCount * 0.05));
        $remainingCount = $ticketCount - $newCount;
        $activeCount = min(500, $remainingCount, count($activeSlots));
        $activeAssignments = array_slice($activeSlots, 0, $activeCount);

        $priorities = array_map(
            static fn (Priority $priority): string => $priority->value,
            Priority::cases(),
        );

        $nowTimestamp = time();
        $maxAge = self::DAYS_SPAN * self::SECONDS_PER_DAY;
        $historyCount = 0;

        $progressBar = null;

        if ($ticketCount > 0) {
            $progressBar = $io->createProgressBar($ticketCount);
            $progressBar->start();
        }

        for ($offset = 0; $offset < $ticketCount; $offset += self::BATCH_SIZE) {
            $batchCount = min(self::BATCH_SIZE, $ticketCount - $offset);
            $ticketRows = [];
            $historyRows = [];

            for ($index = 0; $index < $batchCount; ++$index) {
                $ticketId = $offset + $index + 1;
                $globalIndex = $offset + $index;
                $priority = $priorities[mt_rand(0, count($priorities) - 1)];
                $createdTimestamp = $nowTimestamp - mt_rand(0, $maxAge);
                $createdAt = gmdate('Y-m-d H:i:s', $createdTimestamp);

                if ($globalIndex < $newCount) {
                    $status = Status::New;
                    $assigneeId = null;
                } elseif ($globalIndex < $newCount + $activeCount) {
                    $status = 0 === mt_rand(0, 1) ? Status::Assigned : Status::InProgress;
                    $assigneeId = $activeAssignments[$globalIndex - $newCount];
                } else {
                    $status = 0 === mt_rand(0, 1) ? Status::Resolved : Status::Closed;
                    $assigneeId = $userIds[mt_rand(0, count($userIds) - 1)];
                }

                $ticketRows[] = [
                    $ticketId,
                    sprintf('Perf ticket #%d', $ticketId),
                    'Ticket généré pour les tests de performance.',
                    $priority,
                    $status->value,
                    $createdAt,
                    $createdAt,
                    $assigneeId,
                ];
                if ($historyPerTicket > 0) {
                    $historyRows[] = [
                        HistoryType::Created->value,
                        null,
                        $priority,
                        $createdAt,
                        $ticketId,
                        null,
                    ];

                    $transitions = match ($status) {
                        Status::New => [],
                        Status::Assigned => [[Status::New->value, Status::Assigned->value]],
                        Status::InProgress => [
                            [Status::New->value, Status::Assigned->value],
                            [Status::Assigned->value, Status::InProgress->value],
                        ],
                        Status::Resolved => [
                            [Status::New->value, Status::Assigned->value],
                            [Status::Assigned->value, Status::InProgress->value],
                            [Status::InProgress->value, Status::Resolved->value],
                        ],
                        Status::Closed => [
                            [Status::New->value, Status::Assigned->value],
                            [Status::Assigned->value, Status::InProgress->value],
                            [Status::InProgress->value, Status::Resolved->value],
                            [Status::Resolved->value, Status::Closed->value],
                        ],
                    };

                    $transitionSlots = $historyPerTicket - 1;

                    if ($transitionSlots > 0 && [] !== $transitions) {
                        $transitions = array_slice($transitions, -$transitionSlots);

                        foreach ($transitions as $transitionIndex => [$from, $to]) {
                            $historyTimestamp = min(
                                $nowTimestamp,
                                $createdTimestamp + (($transitionIndex + 1) * 3600),
                            );

                            $historyRows[] = [
                                HistoryType::StatusChanged->value,
                                $from,
                                $to,
                                gmdate('Y-m-d H:i:s', $historyTimestamp),
                                $ticketId,
                                null,
                            ];
                        }
                    }
                }
            }

            $this->insertRows(
                'INSERT INTO ticket (id, title, description, priority, status, created_at, priority_changed_at, assignee_id)',
                $ticketRows,
                8,
            );

            $this->insertRows(
                'INSERT INTO ticket_history (type, from_value, to_value, created_at, ticket_id, actor_id)',
                $historyRows,
                6,
            );

            $historyCount += count($historyRows);

            if (null !== $progressBar) {
                $progressBar->advance($batchCount);
            }

            unset($ticketRows, $historyRows);
        }

        if (null !== $progressBar) {
            $progressBar->finish();
            $io->newLine(2);
        }

        $this->connection->executeStatement('ANALYZE');

        $elapsed = microtime(true) - $startedAt;

        $io->success(sprintf(
            '%d tickets, %d utilisateurs et %d lignes d\'historique générés en %.2f s.',
            $ticketCount,
            $userCount,
            $historyCount,
            $elapsed,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<int, mixed>> $rows
     */
    private function insertRows(string $insertSql, array $rows, int $columnCount): void
    {
        if ([] === $rows) {
            return;
        }

        $rowPlaceholder = '('.implode(', ', array_fill(0, $columnCount, '?')).')';
        $rowsPerStatement = max(1, intdiv(30000, $columnCount));

        foreach (array_chunk($rows, $rowsPerStatement) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), $rowPlaceholder));
            $parameters = [];

            foreach ($chunk as $row) {
                foreach ($row as $value) {
                    $parameters[] = $value;
                }
            }

            $this->connection->beginTransaction();

            try {
                $this->connection->executeStatement($insertSql.' VALUES '.$placeholders, $parameters);
                $this->connection->commit();
            } catch (\Throwable $exception) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }

                throw $exception;
            }
        }
    }
}
