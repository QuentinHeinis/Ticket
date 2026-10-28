<?php

declare(strict_types=1);

namespace App\Tests\Service\Ticket;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Priority;
use App\Enum\Status;
use App\Exception\CapacityExceededException;
use App\Exception\InvalidStatusTransitionException;
use App\Service\Ticket\TicketStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketStatusServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketStatusService $statusService;
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

        $ticketRepository = $this->entityManager->getRepository(Ticket::class);
        $this->statusService = new TicketStatusService($this->entityManager, $ticketRepository);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);
        $this->bob = $this->createUser('Bob', 'bob@pomzed.ch', 3);
        $this->charlie = $this->createUser('Charlie', 'charlie@pomzed.ch', 8);

        $this->entityManager->flush();
    }

    public function testAssignedToInProgressCreatesSingleHistoryEntry(): void
    {
        $ticket = $this->createTicket(Priority::Normal, Status::Assigned, $this->alice);
        $this->entityManager->flush();

        $this->statusService->changeStatus($ticket, Status::InProgress);

        $this->entityManager->refresh($ticket);

        $this->assertSame(Status::InProgress, $ticket->getStatus());

        $histories = $this->historiesFor($ticket->getId());
        $this->assertCount(1, $histories);
        $this->assertSame(HistoryType::StatusChanged, $histories[0]->getType());
        $this->assertSame('assigned', $histories[0]->getFromValue());
        $this->assertSame('in_progress', $histories[0]->getToValue());
        $this->assertNull($histories[0]->getActor());
    }

    public function testAllValidTransitionsSucceed(): void
    {
        $transitions = [
            [Status::New, Status::Assigned],
            [Status::Assigned, Status::InProgress],
            [Status::InProgress, Status::Resolved],
            [Status::Resolved, Status::Closed],
            [Status::Resolved, Status::InProgress],
        ];

        foreach ($transitions as $index => [$from, $to]) {
            $ticket = $this->createTicket(
                Priority::Normal,
                $from,
                $this->alice,
                'Transition '.$index,
            );
            $this->entityManager->flush();

            $this->statusService->changeStatus($ticket, $to);

            $this->entityManager->refresh($ticket);
            $this->assertSame($to, $ticket->getStatus());
        }
    }

    public function testInvalidTransitionsAreRefusedWithoutHistory(): void
    {
        $transitions = [
            [Status::New, Status::Resolved],
            [Status::Closed, Status::InProgress],
            [Status::Assigned, Status::New],
        ];

        foreach ($transitions as $index => [$from, $to]) {
            $ticket = $this->createTicket(
                Priority::Normal,
                $from,
                null,
                'Refus '.$index,
            );
            $this->entityManager->flush();
            $ticketId = $ticket->getId();

            try {
                $this->statusService->changeStatus($ticket, $to);
                $this->fail(sprintf('La transition %s -> %s aurait dû être refusée.', $from->value, $to->value));
            } catch (InvalidStatusTransitionException) {
            }

            $this->resetEntityManager();

            $this->assertCount(0, $this->historiesFor($ticketId));
        }
    }

    public function testNewToAssignedWithoutAssigneeIsRefused(): void
    {
        $ticket = $this->createTicket(Priority::Normal, Status::New, null);
        $this->entityManager->flush();
        $ticketId = $ticket->getId();

        try {
            $this->statusService->changeStatus($ticket, Status::Assigned);
            $this->fail('La transition new -> assigned sans assigné aurait dû être refusée.');
        } catch (InvalidStatusTransitionException) {
        }

        $this->resetEntityManager();

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame(Status::New, $ticket->getStatus());
        $this->assertCount(0, $this->historiesFor($ticketId));
    }

    public function testResolvedToInProgressIsRefusedWhenAssigneeIsFullThenAccepted(): void
    {
        $ticket = $this->createTicket(Priority::Normal, Status::Resolved, $this->bob, 'Réouverture');

        for ($i = 0; $i < 3; ++$i) {
            $this->createTicket(Priority::Low, Status::Assigned, $this->bob, 'Actif '.$i);
        }

        $this->entityManager->flush();
        $ticketId = $ticket->getId();

        try {
            $this->statusService->changeStatus($ticket, Status::InProgress);
            $this->fail('La réouverture aurait dû être refusée car Bob est à capacité maximale.');
        } catch (CapacityExceededException) {
        }

        $this->resetEntityManager();

        $this->assertCount(0, $this->historiesFor($ticketId));

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);
        $this->assertNotNull($ticket);
        $this->assertSame(Status::Resolved, $ticket->getStatus());

        $activeTicket = $this->entityManager
            ->getRepository(Ticket::class)
            ->findOneBy(['title' => 'Actif 0']);
        $this->assertNotNull($activeTicket);

        $activeTicket->setStatus(Status::Resolved);
        $this->entityManager->flush();

        $this->statusService->changeStatus($ticket, Status::InProgress);

        $this->entityManager->refresh($ticket);
        $this->assertSame(Status::InProgress, $ticket->getStatus());
        $this->assertCount(1, $this->historiesFor($ticketId));
    }

    public function testFullLifecycleKeepsHistoryInOrder(): void
    {
        $ticket = $this->createTicket(Priority::High, Status::New, $this->alice, 'Parcours complet');
        $this->entityManager->flush();

        $sequence = [
            [Status::Assigned, 'new', 'assigned'],
            [Status::InProgress, 'assigned', 'in_progress'],
            [Status::Resolved, 'in_progress', 'resolved'],
            [Status::InProgress, 'resolved', 'in_progress'],
            [Status::Resolved, 'in_progress', 'resolved'],
            [Status::Closed, 'resolved', 'closed'],
        ];

        foreach ($sequence as [$target]) {
            $this->statusService->changeStatus($ticket, $target);
        }

        $this->entityManager->refresh($ticket);
        $this->assertSame(Status::Closed, $ticket->getStatus());

        $histories = $this->historiesFor($ticket->getId());

        $this->assertCount(6, $histories);
        $this->assertSame(
            array_map(static fn (array $step): string => $step[1], $sequence),
            array_map(static fn (TicketHistory $history): ?string => $history->getFromValue(), $histories),
        );
        $this->assertSame(
            array_map(static fn (array $step): string => $step[2], $sequence),
            array_map(static fn (TicketHistory $history): ?string => $history->getToValue(), $histories),
        );

        foreach ($histories as $history) {
            $this->assertSame(HistoryType::StatusChanged, $history->getType());
        }
    }

    private function resetEntityManager(): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        $registry->resetManager();

        $this->entityManager = $registry->getManager();
        $this->statusService = new TicketStatusService(
            $this->entityManager,
            $this->entityManager->getRepository(Ticket::class),
        );
    }

    /**
     * @return list<TicketHistory>
     */
    private function historiesFor(int $ticketId): array
    {
        return $this->entityManager
            ->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticketId], ['id' => 'ASC']);
    }

    private function createUser(string $name, string $email, int $capacity): User
    {
        $user = (new User())
            ->setName($name)
            ->setEmail($email)
            ->setPassword(password_hash('pom_zed123!', PASSWORD_BCRYPT))
            ->setRoles(['ROLE_USER'])
            ->setAvailable(true)
            ->setCapacity($capacity);

        $this->entityManager->persist($user);

        return $user;
    }

    private function createTicket(
        Priority $priority,
        Status $status,
        ?User $assignee,
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
}
