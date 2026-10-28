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
use App\Exception\ReassignmentNotAllowedException;
use App\Service\Ticket\TicketReassignmentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketReassignmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketReassignmentService $reassignmentService;
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

        $this->reassignmentService = new TicketReassignmentService(
            $this->entityManager,
            $this->entityManager->getRepository(Ticket::class),
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

    public function testAssignedTicketIsReassignedAndKeepsPreviousHistory(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);
        $this->addHistory($ticket, HistoryType::Created, null, 'assigned');
        $this->entityManager->flush();

        $aliceId = $this->alice->getId();
        $bobId = $this->bob->getId();

        $this->reassignmentService->reassign($ticket, $this->bob);

        $this->entityManager->refresh($ticket);

        $this->assertSame($this->bob, $ticket->getAssignee());
        $this->assertSame(Status::Assigned, $ticket->getStatus());

        $histories = $this->historiesFor($ticket->getId());

        $this->assertCount(2, $histories);
        $this->assertSame(HistoryType::Created, $histories[0]->getType());
        $this->assertSame(HistoryType::Reassigned, $histories[1]->getType());
        $this->assertSame((string) $aliceId, $histories[1]->getFromValue());
        $this->assertSame((string) $bobId, $histories[1]->getToValue());
    }

    public function testInProgressTicketKeepsItsStatusWhenReassigned(): void
    {
        $ticket = $this->createTicket(Status::InProgress, $this->alice);
        $this->entityManager->flush();

        $this->reassignmentService->reassign($ticket, $this->bob);

        $this->entityManager->refresh($ticket);

        $this->assertSame(Status::InProgress, $ticket->getStatus());
        $this->assertSame($this->bob, $ticket->getAssignee());
        $this->assertCount(1, $this->historiesFor($ticket->getId()));
    }

    public function testClosedTicketIsRefused(): void
    {
        $ticket = $this->createTicket(Status::Closed, $this->alice);
        $this->entityManager->flush();

        $ticketId = $ticket->getId();
        $aliceId = $this->alice->getId();

        try {
            $this->reassignmentService->reassign($ticket, $this->bob);
            $this->fail('La réattribution d\'un ticket clôturé aurait dû être refusée.');
        } catch (ReassignmentNotAllowedException) {
        }

        $this->resetEntityManager();

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame($aliceId, $ticket->getAssignee()?->getId());
        $this->assertCount(0, $this->historiesFor($ticketId));
    }

    public function testUnavailableNewAssigneeIsRefused(): void
    {
        $this->bob->setAvailable(false);
        $ticket = $this->createTicket(Status::Assigned, $this->alice);
        $this->entityManager->flush();

        $ticketId = $ticket->getId();
        $aliceId = $this->alice->getId();

        try {
            $this->reassignmentService->reassign($ticket, $this->bob);
            $this->fail('La réattribution vers un utilisateur indisponible aurait dû être refusée.');
        } catch (ReassignmentNotAllowedException) {
        }

        $this->resetEntityManager();

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame($aliceId, $ticket->getAssignee()?->getId());
        $this->assertCount(0, $this->historiesFor($ticketId));
    }

    public function testNewAssigneeAtFullCapacityIsRefused(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->createTicket(Status::Assigned, $this->bob, 'Actif '.$i);
        }

        $ticket = $this->createTicket(Status::Assigned, $this->alice);
        $this->entityManager->flush();

        $ticketId = $ticket->getId();
        $aliceId = $this->alice->getId();

        try {
            $this->reassignmentService->reassign($ticket, $this->bob);
            $this->fail('La réattribution vers un utilisateur plein aurait dû être refusée.');
        } catch (CapacityExceededException) {
        }

        $this->resetEntityManager();

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame($aliceId, $ticket->getAssignee()?->getId());
        $this->assertCount(0, $this->historiesFor($ticketId));
    }

    public function testReassignmentToCurrentAssigneeIsRefusedWithoutHistory(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);
        $this->entityManager->flush();

        $ticketId = $ticket->getId();
        $aliceId = $this->alice->getId();

        try {
            $this->reassignmentService->reassign($ticket, $this->alice);
            $this->fail('La réattribution au même utilisateur aurait dû être refusée.');
        } catch (ReassignmentNotAllowedException) {
        }

        $this->resetEntityManager();

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame($aliceId, $ticket->getAssignee()?->getId());
        $this->assertCount(0, $this->historiesFor($ticketId));
    }

    public function testResolvedTicketCanBeReassignedToFullUser(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->createTicket(Status::Assigned, $this->bob, 'Actif '.$i);
        }

        $ticket = $this->createTicket(Status::Resolved, $this->alice);
        $this->entityManager->flush();

        $aliceId = $this->alice->getId();
        $bobId = $this->bob->getId();

        $this->reassignmentService->reassign($ticket, $this->bob);

        $this->entityManager->refresh($ticket);

        $this->assertSame(Status::Resolved, $ticket->getStatus());
        $this->assertSame($this->bob, $ticket->getAssignee());

        $histories = $this->historiesFor($ticket->getId());

        $this->assertCount(1, $histories);
        $this->assertSame(HistoryType::Reassigned, $histories[0]->getType());
        $this->assertSame((string) $aliceId, $histories[0]->getFromValue());
        $this->assertSame((string) $bobId, $histories[0]->getToValue());
    }

    public function testNewTicketWithoutAssigneeBecomesAssignedWithTwoHistories(): void
    {
        $ticket = $this->createTicket(Status::New, null);
        $this->entityManager->flush();

        $aliceId = $this->alice->getId();

        $this->reassignmentService->reassign($ticket, $this->alice);

        $this->entityManager->refresh($ticket);

        $this->assertSame(Status::Assigned, $ticket->getStatus());
        $this->assertSame($this->alice, $ticket->getAssignee());

        $histories = $this->historiesFor($ticket->getId());

        $this->assertCount(2, $histories);
        $this->assertSame(HistoryType::Assigned, $histories[0]->getType());
        $this->assertNull($histories[0]->getFromValue());
        $this->assertSame((string) $aliceId, $histories[0]->getToValue());
        $this->assertSame(HistoryType::StatusChanged, $histories[1]->getType());
        $this->assertSame('new', $histories[1]->getFromValue());
        $this->assertSame('assigned', $histories[1]->getToValue());
    }

    public function testNothingChangesAfterRefusal(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);
        $this->addHistory($ticket, HistoryType::Created, null, 'assigned');
        $this->entityManager->flush();

        $ticketId = $ticket->getId();
        $aliceId = $this->alice->getId();
        $historyBefore = $this->historySignature($ticketId);

        try {
            $this->reassignmentService->reassign($ticket, $this->alice);
            $this->fail('La réattribution aurait dû être refusée.');
        } catch (ReassignmentNotAllowedException) {
        }

        $this->resetEntityManager();

        $ticket = $this->entityManager->find(Ticket::class, $ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame($aliceId, $ticket->getAssignee()?->getId());
        $this->assertSame($historyBefore, $this->historySignature($ticketId));
    }

    private function resetEntityManager(): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        $registry->resetManager();

        $this->entityManager = $registry->getManager();
        $this->reassignmentService = new TicketReassignmentService(
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

    /**
     * @return list<array{string, ?string, ?string}>
     */
    private function historySignature(int $ticketId): array
    {
        return array_map(
            static fn (TicketHistory $history): array => [
                $history->getType()->value,
                $history->getFromValue(),
                $history->getToValue(),
            ],
            $this->historiesFor($ticketId),
        );
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

    private function createTicket(Status $status, ?User $assignee, string $title = 'Ticket'): Ticket
    {
        $ticket = new Ticket($title, 'Description', Priority::Normal);
        $ticket->setStatus($status);

        if (null !== $assignee) {
            $ticket->setAssignee($assignee);
        }

        $this->entityManager->persist($ticket);

        return $ticket;
    }

    private function addHistory(Ticket $ticket, HistoryType $type, ?string $from, ?string $to): void
    {
        $this->entityManager->persist(new TicketHistory($ticket, $type, $from, $to, null));
    }
}
