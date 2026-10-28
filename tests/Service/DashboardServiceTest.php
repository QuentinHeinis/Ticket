<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\Dashboard\DashboardService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DashboardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DashboardService $dashboardService;
    private User $alice;
    private User $bob;
    private User $charlie;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $databasePath = $this->entityManager->getConnection()->getParams()['path'] ?? '';

        if (!str_contains((string) $databasePath, 'test.db')) {
            self::fail('Les tests doivent utiliser la base var/test.db, pas la base de dev.');
        }

        /** @var TicketRepository $ticketRepository */
        $ticketRepository = $this->entityManager->getRepository(Ticket::class);
        /** @var UserRepository $userRepository */
        $userRepository = $this->entityManager->getRepository(User::class);

        $this->dashboardService = new DashboardService($ticketRepository, $userRepository);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);
        $this->bob = $this->createUser('Bob', 'bob@pomzed.ch', 3);
        $this->charlie = $this->createUser('Charlie', 'charlie@pomzed.ch', 8, false);

        $this->entityManager->flush();
    }

    public function testCountsOnMixedDataset(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $unassignedNew = $this->createTicket(Priority::Normal, Status::New);
        $unassignedUrgent = $this->createTicket(Priority::Urgent, Status::New);
        $assignedLow = $this->createTicket(Priority::Low, Status::Assigned, $this->alice);
        $inProgressHigh = $this->createTicket(Priority::High, Status::InProgress, $this->bob);
        $resolved = $this->createTicket(Priority::Normal, Status::Resolved, $this->bob);
        $this->entityManager->flush();

        $this->backdateCreatedAt($unassignedNew, $now->modify('-1 minute'));
        $this->backdateCreatedAt($unassignedUrgent, $now->modify('-31 minutes'));
        $this->backdateCreatedAt($assignedLow, $now->modify('-25 hours'));
        $this->backdateCreatedAt($inProgressHigh, $now->modify('-100000 minutes'));
        $this->backdateCreatedAt($resolved, $now->modify('-100000 minutes'));

        $dashboard = $this->dashboardService->build($now);

        $this->assertSame(5, $dashboard['tickets']['total']);
        $this->assertSame(2, $dashboard['tickets']['unassigned']);
        $this->assertSame(2, $dashboard['tickets']['overdue']);
        $this->assertCount(3, $dashboard['users']);
    }

    public function testEveryUserAppearsIncludingUnavailableAndWithoutTickets(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $dashboard = $this->dashboardService->build($now);

        $this->assertSame(0, $dashboard['tickets']['total']);
        $this->assertSame(0, $dashboard['tickets']['unassigned']);
        $this->assertSame(0, $dashboard['tickets']['overdue']);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], array_column($dashboard['users'], 'name'));

        $charlie = $this->rowFor($dashboard['users'], 'Charlie');

        $this->assertSame(0, $charlie['activeTickets']);
        $this->assertSame(0, $charlie['currentLoad']);
        $this->assertSame(8, $charlie['capacity']);
        $this->assertFalse($this->charlie->isAvailable());
    }

    public function testCurrentLoadOnlyCountsAssignedAndInProgressTickets(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $this->createTicket(Priority::Low, Status::Assigned, $this->alice);
        $this->createTicket(Priority::High, Status::InProgress, $this->alice);
        $this->createTicket(Priority::Urgent, Status::New);
        $this->createTicket(Priority::Normal, Status::Resolved, $this->alice);
        $this->createTicket(Priority::Urgent, Status::Closed, $this->alice);
        $this->entityManager->flush();

        $dashboard = $this->dashboardService->build($now);
        $alice = $this->rowFor($dashboard['users'], 'Alice');

        $this->assertSame(2, $alice['activeTickets']);
        $this->assertSame(5, $alice['currentLoad']);
        $this->assertSame(5, $alice['capacity']);
    }

    /**
     * @param list<array{name: string, activeTickets: int, currentLoad: int, capacity: int}> $rows
     *
     * @return array{name: string, activeTickets: int, currentLoad: int, capacity: int}
     */
    private function rowFor(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        self::fail(sprintf('Utilisateur "%s" absent du dashboard.', $name));
    }

    private function createUser(string $name, string $email, int $capacity, bool $available = true): User
    {
        $user = (new User())
            ->setName($name)
            ->setEmail($email)
            ->setPassword(password_hash('pom_zed123!', PASSWORD_BCRYPT))
            ->setRoles(['ROLE_USER'])
            ->setAvailable($available)
            ->setCapacity($capacity);

        $this->entityManager->persist($user);

        return $user;
    }

    private function createTicket(
        Priority $priority,
        Status $status = Status::New,
        ?User $assignee = null,
        string $title = 'Ticket',
    ): Ticket {
        $ticket = new Ticket($title, 'Description', $priority);
        $ticket->setStatus($status);

        if (null !== $assignee) {
            $ticket->setAssignee($assignee);
        }

        $this->entityManager->persist($ticket);

        return $ticket;
    }

    private function backdateCreatedAt(Ticket $ticket, \DateTimeImmutable $createdAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ticket SET created_at = :createdAt WHERE id = :id',
            [
                'createdAt' => $createdAt->setTimezone(new \DateTimeZone('UTC')),
                'id' => $ticket->getId(),
            ],
            [
                'createdAt' => Types::DATETIME_IMMUTABLE,
            ],
        );

        $this->entityManager->refresh($ticket);
    }
}
