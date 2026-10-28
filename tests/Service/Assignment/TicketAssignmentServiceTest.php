<?php

declare(strict_types=1);

namespace App\Tests\Service\Assignment;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Repository\UserRepository;
use App\Service\Assignment\AssigneeSelector;
use App\Service\Assignment\TicketAssignmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketAssignmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketAssignmentService $assignmentService;
    private UserRepository $userRepository;
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

        $this->userRepository = $this->entityManager->getRepository(User::class);
        $this->assignmentService = new TicketAssignmentService(
            $this->entityManager,
            $this->userRepository,
            new AssigneeSelector(),
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

    public function testAssignsTicketWhenNoActiveTicketExists(): void
    {
        $ticket = $this->createTicket(Priority::Normal);
        $this->entityManager->flush();

        $this->assertTrue($this->assignmentService->assign($ticket));

        $this->entityManager->refresh($ticket);

        $this->assertSame(Status::Assigned, $ticket->getStatus());
        $this->assertNotNull($ticket->getAssignee());
        $this->assertCount(2, $this->entityManager
            ->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticket]));
    }

    public function testUserWithLowestLoadIsChosen(): void
    {
        $this->createTicket(Priority::High, Status::Assigned, $this->alice);
        $this->createTicket(Priority::Normal, Status::Assigned, $this->bob);
        $this->createTicket(Priority::Low, Status::Assigned, $this->charlie);

        $ticket = $this->createTicket(Priority::Normal);
        $this->entityManager->flush();

        $this->assertTrue($this->assignmentService->assign($ticket));

        $this->entityManager->refresh($ticket);

        $this->assertSame($this->charlie, $ticket->getAssignee());
    }

    public function testUnavailableUserIsNeverChosen(): void
    {
        $this->alice->setAvailable(false);
        $this->entityManager->flush();

        $ticket = $this->createTicket(Priority::Normal);
        $this->entityManager->flush();

        $this->assertTrue($this->assignmentService->assign($ticket));

        $this->entityManager->refresh($ticket);

        $this->assertSame($this->bob, $ticket->getAssignee());
        $this->assertNotSame($this->alice, $ticket->getAssignee());
    }

    public function testUserAtFullCapacityIsNeverChosen(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->createTicket(Priority::Low, Status::Assigned, $this->alice, 'Ticket Alice '.$i);
        }

        $ticket = $this->createTicket(Priority::Normal);
        $this->entityManager->flush();

        $this->assertTrue($this->assignmentService->assign($ticket));

        $this->entityManager->refresh($ticket);

        $this->assertSame($this->bob, $ticket->getAssignee());
        $this->assertNotSame($this->alice, $ticket->getAssignee());
    }

    public function testAssignmentFailsWhenNoUserIsAvailable(): void
    {
        $this->alice->setAvailable(false);
        $this->bob->setAvailable(false);
        $this->charlie->setAvailable(false);
        $this->entityManager->flush();

        $ticket = $this->createTicket(Priority::Normal);
        $this->entityManager->flush();

        $this->assertFalse($this->assignmentService->assign($ticket));

        $this->entityManager->refresh($ticket);

        $this->assertSame(Status::New, $ticket->getStatus());
        $this->assertNull($ticket->getAssignee());
        $this->assertCount(0, $this->entityManager
            ->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticket]));
    }

    public function testAssigningTwiceReturnsFalseAndDoesNotDuplicateHistory(): void
    {
        $ticket = $this->createTicket(Priority::Normal);
        $this->entityManager->flush();

        $this->assertTrue($this->assignmentService->assign($ticket));
        $this->assertFalse($this->assignmentService->assign($ticket));

        $this->assertCount(2, $this->entityManager
            ->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticket]));
    }

    public function testFindLoadsCountsActiveTicketsAndReturnsZeroWithoutTicket(): void
    {
        $this->createTicket(Priority::High, Status::Assigned, $this->alice);
        $this->createTicket(Priority::Normal, Status::InProgress, $this->alice);
        $this->createTicket(Priority::Urgent, Status::Resolved, $this->alice);
        $this->createTicket(Priority::Low, Status::Assigned, $this->bob);
        $this->createTicket(Priority::High, Status::New, $this->bob);
        $this->entityManager->flush();

        $loads = $this->userRepository->findLoads();

        $loadsById = [];
        foreach ($loads as $load) {
            $loadsById[$load->userId] = $load;
        }

        $this->assertCount(3, $loads);

        $aliceLoad = $loadsById[$this->alice->getId()];
        $this->assertSame(2, $aliceLoad->activeCount);
        $this->assertSame(6, $aliceLoad->load);

        $bobLoad = $loadsById[$this->bob->getId()];
        $this->assertSame(1, $bobLoad->activeCount);
        $this->assertSame(1, $bobLoad->load);

        $charlieLoad = $loadsById[$this->charlie->getId()];
        $this->assertSame(0, $charlieLoad->activeCount);
        $this->assertSame(0, $charlieLoad->load);
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
        ?Status $status = null,
        ?User $assignee = null,
        string $title = 'Ticket',
    ): Ticket {
        $ticket = new Ticket($title, 'Description', $priority);

        if (null !== $status) {
            $ticket->setStatus($status);
        }

        if (null !== $assignee) {
            $ticket->setAssignee($assignee);
        }

        $this->entityManager->persist($ticket);

        return $ticket;
    }
}
