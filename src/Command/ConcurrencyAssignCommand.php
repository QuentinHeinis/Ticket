<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Service\Assignment\TicketAssignmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:concurrency:assign', description: 'Prépare, exécute et vérifie un test de concurrence d\'attribution')]
final class ConcurrencyAssignCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketAssignmentService $ticketAssignmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('mode', InputArgument::REQUIRED, 'Mode : prepare, run ou verify')
            ->addArgument('ticketId', InputArgument::OPTIONAL, 'Identifiant du ticket pour le mode run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $databasePath = $this->entityManager->getConnection()->getParams()['path'] ?? '';

        if (!is_string($databasePath) || !str_contains($databasePath, 'test.db')) {
            $output->writeln(sprintf(
                '<error>Cette commande ne peut tourner que sur une base "test.db" (base courante : "%s").</error>',
                is_string($databasePath) ? $databasePath : 'inconnue',
            ));

            return Command::FAILURE;
        }

        $mode = (string) $input->getArgument('mode');

        return match ($mode) {
            'prepare' => $this->prepare($output),
            'run' => $this->runAssignment($input, $output),
            'verify' => $this->verify($output),
            default => $this->invalidMode($mode, $output),
        };
    }

    private function prepare(OutputInterface $output): int
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');
        $this->entityManager->clear();

        $solo = (new User())
            ->setName('Solo')
            ->setEmail('solo@example.test')
            ->setPassword(password_hash('pom_zed123!', PASSWORD_BCRYPT))
            ->setRoles(['ROLE_USER'])
            ->setAvailable(true)
            ->setCapacity(5);

        $this->entityManager->persist($solo);

        for ($index = 1; $index <= 4; ++$index) {
            $ticket = new Ticket(
                sprintf('Assigned %d', $index),
                'Ticket déjà attribué.',
                Priority::Normal,
            );
            $ticket->setStatus(Status::Assigned);
            $ticket->setAssignee($solo);

            $this->entityManager->persist($ticket);
        }

        $newTickets = [];

        for ($index = 1; $index <= 10; ++$index) {
            $ticket = new Ticket(
                sprintf('New urgent %d', $index),
                'Ticket en attente d\'attribution.',
                Priority::Urgent,
            );
            $ticket->setStatus(Status::New);

            $this->entityManager->persist($ticket);
            $newTickets[] = $ticket;
        }

        $this->entityManager->flush();

        $output->writeln(implode(' ', array_map(
            static fn (Ticket $ticket): int => (int) $ticket->getId(),
            $newTickets,
        )));

        return Command::SUCCESS;
    }

    private function runAssignment(InputInterface $input, OutputInterface $output): int
    {
        $ticketIdValue = $input->getArgument('ticketId');
        $ticketId = is_string($ticketIdValue) && ctype_digit($ticketIdValue)
            ? (int) $ticketIdValue
            : 0;

        if ($ticketId < 1) {
            $output->writeln('ticket=0 result=error Identifiant de ticket invalide.');

            return Command::SUCCESS;
        }

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        if (null === $ticket) {
            $output->writeln(sprintf('ticket=%d result=error Ticket introuvable.', $ticketId));

            return Command::SUCCESS;
        }

        try {
            $assigned = $this->ticketAssignmentService->assign($ticket);
        } catch (\Throwable $exception) {
            $message = str_replace(["\r", "\n"], ' ', $exception->getMessage());
            $output->writeln(sprintf('ticket=%d result=error %s', $ticketId, $message));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'ticket=%d result=%s',
            $ticketId,
            $assigned ? 'assigned' : 'skipped',
        ));

        return Command::SUCCESS;
    }

    private function verify(OutputInterface $output): int
    {
        $connection = $this->entityManager->getConnection();

        $active = (int) $connection->fetchOne(
            'SELECT COUNT(t.id)
            FROM ticket t
            INNER JOIN app_user u ON u.id = t.assignee_id
            WHERE u.email = :email
              AND t.status IN (:statuses)',
            [
                'email' => 'solo@example.test',
                'statuses' => [Status::Assigned->value, Status::InProgress->value],
            ],
            [
                'statuses' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        );

        $new = (int) $connection->fetchOne(
            'SELECT COUNT(t.id) FROM ticket t WHERE t.status = :status',
            ['status' => Status::New->value],
        );

        $capacity = $connection->fetchOne(
            'SELECT capacity FROM app_user WHERE email = :email',
            ['email' => 'solo@example.test'],
        );

        $capacity = false === $capacity ? 0 : (int) $capacity;

        $output->writeln(sprintf(
            'active=%d new=%d %s',
            $active,
            $new,
            $active <= $capacity ? 'OK' : 'VIOLATION',
        ));

        return Command::SUCCESS;
    }

    private function invalidMode(string $mode, OutputInterface $output): int
    {
        $output->writeln(sprintf(
            '<error>Mode inconnu "%s". Utilisez prepare, run ou verify.</error>',
            $mode,
        ));

        return Command::FAILURE;
    }
}
