<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Repository\TicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OverdueTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketRepository $ticketRepository;
    private User $alice;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $databasePath = $this->entityManager->getConnection()->getParams()['path'] ?? '';

        if (!str_contains((string) $databasePath, 'test.db')) {
            self::fail('Les tests doivent utiliser la base var/test.db, pas la base de dev.');
        }

        $this->ticketRepository = $this->entityManager->getRepository(Ticket::class);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);

        $this->entityManager->flush();
    }

    public function testUrgentTicketIsOverdueAfterThirtyMinutesOnly(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $overdue = $this->createTicket(Priority::Urgent);
        $notYet = $this->createTicket(Priority::Urgent);
        $this->entityManager->flush();

        $this->backdateCreatedAt($overdue, $now->modify('-31 minutes'));
        $this->backdateCreatedAt($notYet, $now->modify('-29 minutes'));

        $this->assertSame(1, $this->ticketRepository->countOverdue($now));

        $tickets = $this->ticketRepository->findOverdue($now, 1, 50);

        $this->assertCount(1, $tickets);
        $this->assertSame($overdue->getId(), $tickets[0]->getId());
    }

    public function testAssignedLowTicketIsOverdueAfterTwentyFourHoursOnly(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $overdue = $this->createTicket(Priority::Low, Status::Assigned, $this->alice);
        $notYet = $this->createTicket(Priority::Low, Status::Assigned, $this->alice);
        $this->entityManager->flush();

        $this->backdateCreatedAt($overdue, $now->modify('-25 hours'));
        $this->backdateCreatedAt($notYet, $now->modify('-23 hours'));

        $this->assertSame(1, $this->ticketRepository->countOverdue($now));

        $tickets = $this->ticketRepository->findOverdue($now, 1, 50);

        $this->assertCount(1, $tickets);
        $this->assertSame($overdue->getId(), $tickets[0]->getId());
    }

    public function testInProgressResolvedAndClosedTicketsAreNeverOverdue(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $inProgress = $this->createTicket(Priority::Urgent, Status::InProgress, $this->alice);
        $resolved = $this->createTicket(Priority::Urgent, Status::Resolved, $this->alice);
        $closed = $this->createTicket(Priority::Urgent, Status::Closed, $this->alice);
        $this->entityManager->flush();

        foreach ([$inProgress, $resolved, $closed] as $ticket) {
            $this->backdateCreatedAt($ticket, $now->modify('-100000 minutes'));
        }

        $this->assertSame(0, $this->ticketRepository->countOverdue($now));
        $this->assertSame([], $this->ticketRepository->findOverdue($now, 1, 50));
    }

    public function testOverdueUsesCurrentPriority(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $nowUrgent = $this->createTicket(Priority::Low);
        $nowLow = $this->createTicket(Priority::Urgent);
        $this->entityManager->flush();

        $nowUrgent->setPriority(Priority::Urgent);
        $nowLow->setPriority(Priority::Low);
        $this->entityManager->flush();

        $this->backdateCreatedAt($nowUrgent, $now->modify('-60 minutes'));
        $this->backdateCreatedAt($nowLow, $now->modify('-200 minutes'));

        $this->assertSame(1, $this->ticketRepository->countOverdue($now));

        $tickets = $this->ticketRepository->findOverdue($now, 1, 50);

        $this->assertCount(1, $tickets);
        $this->assertSame($nowUrgent->getId(), $tickets[0]->getId());
    }

    public function testCountOverdueMatchesFindOverdueAndPagination(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $tickets = [
            [$this->createTicket(Priority::Urgent), '-31 minutes'],
            [$this->createTicket(Priority::Normal), '-479 minutes'],
            [$this->createTicket(Priority::High, Status::Assigned, $this->alice), '-121 minutes'],
            [$this->createTicket(Priority::Low, Status::Assigned, $this->alice), '-1441 minutes'],
            [$this->createTicket(Priority::Urgent, Status::InProgress, $this->alice), '-100000 minutes'],
        ];
        $this->entityManager->flush();

        foreach ($tickets as [$ticket, $offset]) {
            $this->backdateCreatedAt($ticket, $now->modify($offset));
        }

        $this->assertSame(3, $this->ticketRepository->countOverdue($now));
        $this->assertCount(3, $this->ticketRepository->findOverdue($now, 1, 100));
        $this->assertCount(2, $this->ticketRepository->findOverdue($now, 1, 2));
        $this->assertCount(1, $this->ticketRepository->findOverdue($now, 2, 2));
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
