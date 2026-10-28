<?php

declare(strict_types=1);

namespace App\Tests\Service\Ticket;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Service\Assignment\AssigneeSelector;
use App\Service\Assignment\TicketAssignmentService;
use App\Service\Ticket\PendingAssignmentService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PendingAssignmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PendingAssignmentService $pendingAssignmentService;
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

        $this->pendingAssignmentService = new PendingAssignmentService(
            $this->entityManager,
            new TicketAssignmentService(
                $this->entityManager,
                $this->entityManager->getRepository(User::class),
                new AssigneeSelector(),
            ),
        );

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);
        $this->bob = $this->createUser('Bob', 'bob@pomzed.ch', 3);
        $this->charlie = $this->createUser('Charlie', 'charlie@pomzed.ch', 8);

        $this->entityManager->flush();
    }

    public function testPendingTicketsAreAssignedWhenUsersAreAvailable(): void
    {
        $first = $this->createTicket(Priority::Low, title: 'Premier');
        $second = $this->createTicket(Priority::High, title: 'Second');
        $this->entityManager->flush();

        $firstId = $first->getId();
        $secondId = $second->getId();

        $this->assertSame(2, $this->pendingAssignmentService->assignPending());

        $first = $this->reload($firstId);
        $second = $this->reload($secondId);

        $this->assertSame(Status::Assigned, $first->getStatus());
        $this->assertNotNull($first->getAssignee());
        $this->assertSame(Status::Assigned, $second->getStatus());
        $this->assertNotNull($second->getAssignee());
    }

    public function testOldUrgentTicketIsAssignedBeforeOlderLowTicket(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $this->alice->setCapacity(1);
        $this->bob->setAvailable(false);
        $this->charlie->setAvailable(false);

        $low = $this->createTicket(Priority::Low, title: 'Low');
        $urgent = $this->createTicket(Priority::Urgent, title: 'Urgent');
        $this->entityManager->flush();

        $lowId = $low->getId();
        $urgentId = $urgent->getId();

        $this->setCreatedAt($low, $now->modify('-10 minutes'));
        $this->setCreatedAt($urgent, $now->modify('-5 minutes'));

        $this->assertSame(1, $this->pendingAssignmentService->assignPending());

        $low = $this->reload($lowId);
        $urgent = $this->reload($urgentId);

        $this->assertNull($low->getAssignee());
        $this->assertSame(Status::New, $low->getStatus());
        $this->assertNotNull($urgent->getAssignee());
        $this->assertSame(Status::Assigned, $urgent->getStatus());
    }

    public function testPriorityOrderIsRespectedForEqualCreationDates(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $this->alice->setCapacity(1);
        $this->bob->setAvailable(false);
        $this->charlie->setAvailable(false);

        $low = $this->createTicket(Priority::Low, title: 'Low');
        $high = $this->createTicket(Priority::High, title: 'High');
        $this->entityManager->flush();

        $lowId = $low->getId();
        $highId = $high->getId();

        $this->setCreatedAt($low, $now->modify('-5 minutes'));
        $this->setCreatedAt($high, $now->modify('-5 minutes'));

        $this->assertSame(1, $this->pendingAssignmentService->assignPending());

        $low = $this->reload($lowId);
        $high = $this->reload($highId);

        $this->assertNull($low->getAssignee());
        $this->assertNotNull($high->getAssignee());
        $this->assertSame(Status::Assigned, $high->getStatus());
    }

    public function testNoAssignmentWhenAllUsersAreFull(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->createTicket(Priority::Low, Status::Assigned, $this->alice, 'Alice '.$i);
        }

        for ($i = 0; $i < 3; ++$i) {
            $this->createTicket(Priority::Low, Status::Assigned, $this->bob, 'Bob '.$i);
        }

        for ($i = 0; $i < 8; ++$i) {
            $this->createTicket(Priority::Low, Status::Assigned, $this->charlie, 'Charlie '.$i);
        }

        $pending = $this->createTicket(Priority::Normal, title: 'En attente');
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->assertSame(0, $this->pendingAssignmentService->assignPending());

        $pending = $this->reload($pendingId);

        $this->assertSame(Status::New, $pending->getStatus());
        $this->assertNull($pending->getAssignee());
    }

    public function testAlreadyAssignedTicketIsNeverTouched(): void
    {
        $assigned = $this->createTicket(Priority::Normal, Status::Assigned, $this->alice, 'Déjà attribué');
        $pending = $this->createTicket(Priority::Normal, title: 'En attente');
        $this->entityManager->flush();

        $assignedId = $assigned->getId();
        $pendingId = $pending->getId();
        $aliceId = $this->alice->getId();

        $this->assertSame(1, $this->pendingAssignmentService->assignPending());

        $assigned = $this->reload($assignedId);
        $pending = $this->reload($pendingId);

        $this->assertSame(Status::Assigned, $assigned->getStatus());
        $this->assertSame($aliceId, $assigned->getAssignee()?->getId());
        $this->assertCount(0, $this->historiesFor($assignedId));

        $this->assertSame(Status::Assigned, $pending->getStatus());
    }

    private function reload(int $ticketId): Ticket
    {
        $ticket = $this->entityManager->find(Ticket::class, $ticketId);
        $this->assertNotNull($ticket);

        return $ticket;
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

    private function setCreatedAt(Ticket $ticket, \DateTimeImmutable $createdAt): void
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
