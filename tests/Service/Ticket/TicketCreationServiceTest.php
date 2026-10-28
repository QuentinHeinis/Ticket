<?php

declare(strict_types=1);

namespace App\Tests\Service\Ticket;

use App\Dto\CreateTicketRequest;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Priority;
use App\Enum\Status;
use App\Service\Assignment\AssigneeSelector;
use App\Service\Assignment\TicketAssignmentService;
use App\Service\Ticket\TicketCreationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketCreationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketCreationService $creationService;
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

        $userRepository = $this->entityManager->getRepository(User::class);

        $this->creationService = new TicketCreationService(
            $this->entityManager,
            new TicketAssignmentService(
                $this->entityManager,
                $userRepository,
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

    public function testCreatedTicketIsAssignedWhenUsersAreAvailable(): void
    {
        $ticket = $this->creationService->create(new CreateTicketRequest(
            'Serveur inaccessible',
            'Le serveur ne répond plus depuis ce matin.',
            Priority::High,
        ));

        $this->assertSame(Status::Assigned, $ticket->getStatus());
        $this->assertNotNull($ticket->getAssignee());

        $histories = $this->entityManager
            ->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticket]);

        $this->assertCount(3, $histories);
        $this->assertEqualsCanonicalizing(
            [HistoryType::Created, HistoryType::Assigned, HistoryType::StatusChanged],
            array_map(static fn (TicketHistory $history): HistoryType => $history->getType(), $histories),
        );
    }

    public function testCreatedTicketStaysNewWhenNoUserIsAvailable(): void
    {
        $this->alice->setAvailable(false);
        $this->bob->setAvailable(false);
        $this->charlie->setAvailable(false);
        $this->entityManager->flush();

        $ticket = $this->creationService->create(new CreateTicketRequest(
            'Demande bloquée',
            'Aucun agent disponible pour traiter cette demande.',
            Priority::Normal,
        ));

        $this->assertNotNull($ticket->getId());
        $this->assertSame(Status::New, $ticket->getStatus());
        $this->assertNull($ticket->getAssignee());

        $histories = $this->entityManager
            ->getRepository(TicketHistory::class)
            ->findBy(['ticket' => $ticket]);

        $this->assertCount(1, $histories);
        $this->assertSame(HistoryType::Created, $histories[0]->getType());
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
}
