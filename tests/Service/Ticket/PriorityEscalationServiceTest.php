<?php

declare(strict_types=1);

namespace App\Tests\Service\Ticket;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Priority;
use App\Enum\Status;
use App\Service\Ticket\PriorityEscalationService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PriorityEscalationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PriorityEscalationService $escalationService;
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

        $this->escalationService = new PriorityEscalationService($this->entityManager);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);

        $this->entityManager->flush();
    }

    public function testLowEscalatesAtFourHoursExactly(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $ticket = $this->createTicket(Priority::Low);
        $this->entityManager->flush();

        $this->backdate($ticket, $now->modify('-239 minutes'));

        $this->assertSame(0, $this->escalationService->escalate($now));

        $this->entityManager->refresh($ticket);
        $this->assertSame(Priority::Low, $ticket->getPriority());

        $this->backdate($ticket, $now->modify('-240 minutes'));

        $this->assertSame(1, $this->escalationService->escalate($now));

        $this->entityManager->refresh($ticket);
        $this->assertSame(Priority::Normal, $ticket->getPriority());
    }

    public function testNormalAndHighEscalateAtTheirThresholds(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $normal = $this->createTicket(Priority::Normal);
        $high = $this->createTicket(Priority::High);
        $this->entityManager->flush();

        $this->backdate($normal, $now->modify('-120 minutes'));
        $this->backdate($high, $now->modify('-60 minutes'));

        $this->assertSame(2, $this->escalationService->escalate($now));

        $this->entityManager->refresh($normal);
        $this->entityManager->refresh($high);

        $this->assertSame(Priority::High, $normal->getPriority());
        $this->assertSame(Priority::Urgent, $high->getPriority());
    }

    public function testUrgentNeverEscalates(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $ticket = $this->createTicket(Priority::Urgent);
        $this->entityManager->flush();

        $this->backdate($ticket, $now->modify('-10000 minutes'));

        $this->assertSame(0, $this->escalationService->escalate($now));

        $this->entityManager->refresh($ticket);
        $this->assertSame(Priority::Urgent, $ticket->getPriority());
        $this->assertCount(0, $this->historiesFor($ticket->getId()));
    }

    public function testAssignedAndInProgressTicketsAreNeverEscalated(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $assigned = $this->createTicket(Priority::Low, Status::Assigned, $this->alice);
        $inProgress = $this->createTicket(Priority::Low, Status::InProgress, $this->alice);
        $this->entityManager->flush();

        $this->backdate($assigned, $now->modify('-300 minutes'));
        $this->backdate($inProgress, $now->modify('-300 minutes'));

        $this->assertSame(0, $this->escalationService->escalate($now));

        $this->entityManager->refresh($assigned);
        $this->entityManager->refresh($inProgress);

        $this->assertSame(Priority::Low, $assigned->getPriority());
        $this->assertSame(Priority::Low, $inProgress->getPriority());
        $this->assertCount(0, $this->historiesFor($assigned->getId()));
        $this->assertCount(0, $this->historiesFor($inProgress->getId()));
    }

    public function testSecondImmediateCallDoesNotEscalateAgain(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $ticket = $this->createTicket(Priority::Low);
        $this->entityManager->flush();

        $this->backdate($ticket, $now->modify('-240 minutes'));

        $this->assertSame(1, $this->escalationService->escalate($now));

        $this->entityManager->refresh($ticket);
        $this->assertSame($now->getTimestamp(), $ticket->getPriorityChangedAt()->getTimestamp());

        $this->assertSame(0, $this->escalationService->escalate($now));
        $this->assertCount(1, $this->historiesFor($ticket->getId()));
    }

    public function testEscalationCreatesHistoryWithCorrectValues(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $ticket = $this->createTicket(Priority::Low);
        $this->entityManager->flush();

        $this->backdate($ticket, $now->modify('-240 minutes'));

        $this->assertSame(1, $this->escalationService->escalate($now));

        $histories = $this->historiesFor($ticket->getId());

        $this->assertCount(1, $histories);
        $this->assertSame(HistoryType::PriorityEscalated, $histories[0]->getType());
        $this->assertSame('low', $histories[0]->getFromValue());
        $this->assertSame('normal', $histories[0]->getToValue());
        $this->assertNull($histories[0]->getActor());
    }

    public function testEscalateReturnsNumberOfEscalatedTickets(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $tickets = [];

        for ($i = 0; $i < 3; ++$i) {
            $tickets[] = [$this->createTicket(Priority::Low, title: 'Low '.$i), '-240 minutes'];
        }

        for ($i = 0; $i < 2; ++$i) {
            $tickets[] = [$this->createTicket(Priority::Normal, title: 'Normal '.$i), '-120 minutes'];
        }

        $tickets[] = [$this->createTicket(Priority::High, title: 'High'), '-60 minutes'];
        $tickets[] = [$this->createTicket(Priority::Low, title: 'Récent'), '-10 minutes'];

        $this->entityManager->flush();

        foreach ($tickets as [$ticket, $offset]) {
            $this->backdate($ticket, $now->modify($offset));
        }

        $this->assertSame(6, $this->escalationService->escalate($now));
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

    private function backdate(Ticket $ticket, \DateTimeImmutable $date): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ticket SET priority_changed_at = :date WHERE id = :id',
            [
                'date' => $date->setTimezone(new \DateTimeZone('UTC')),
                'id' => $ticket->getId(),
            ],
            [
                'date' => Types::DATETIME_IMMUTABLE,
            ],
        );

        $this->entityManager->refresh($ticket);
    }
}
