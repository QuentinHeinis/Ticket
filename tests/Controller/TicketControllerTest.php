<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TicketControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $databasePath = $this->entityManager->getConnection()->getParams()['path'] ?? '';

        if (!str_contains((string) $databasePath, 'test.db')) {
            self::fail('Les tests doivent utiliser la base var/test.db, pas la base de dev.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);
        $this->bob = $this->createUser('Bob', 'bob@pomzed.ch', 3);
        $this->createUser('Charlie', 'charlie@pomzed.ch', 8);

        $this->entityManager->flush();
    }

    public function testValidPostCreatesAssignedTicket(): void
    {
        $this->client->jsonRequest('POST', '/api/tickets', [
            'title' => 'Serveur down',
            'description' => 'Le serveur ne répond plus.',
            'priority' => 'urgent',
        ]);

        $this->assertResponseStatusCodeSame(201);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('id', $data);
        $this->assertSame('assigned', $data['status']);
        $this->assertNotNull($data['assignee']);
    }

    public function testEmptyTitleReturns422(): void
    {
        $this->client->jsonRequest('POST', '/api/tickets', [
            'title' => '',
            'description' => 'Description valide.',
            'priority' => 'normal',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnknownPriorityReturns422(): void
    {
        $this->client->jsonRequest('POST', '/api/tickets', [
            'title' => 'Ticket',
            'description' => 'Description valide.',
            'priority' => 'critical',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testMalformedOrMissingJsonBodyReturns4xx(): void
    {
        $this->client->request(
            'POST',
            '/api/tickets',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"title":',
        );

        $this->assertResponseIs4xx();

        $this->client->request(
            'POST',
            '/api/tickets',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '',
        );

        $this->assertResponseIs4xx();
    }

    public function testResponseNeverContainsPasswordOrEmail(): void
    {
        $this->client->jsonRequest('POST', '/api/tickets', [
            'title' => 'Ticket',
            'description' => 'Description valide.',
            'priority' => 'normal',
        ]);

        $this->assertResponseStatusCodeSame(201);

        $data = $this->decodeResponse();

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertIsArray($data['assignee']);
        $this->assertArrayNotHasKey('password', $data['assignee']);
        $this->assertArrayNotHasKey('email', $data['assignee']);
    }

    public function testValidStatusTransitionReturns200(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/status', [
            'status' => 'in_progress',
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertSame('in_progress', $data['status']);
    }

    public function testForbiddenStatusTransitionReturns409(): void
    {
        $ticket = $this->createTicket(Status::New, $this->alice);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/status', [
            'status' => 'resolved',
        ]);

        $this->assertResponseStatusCodeSame(409);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testUnknownTicketReturns404(): void
    {
        $this->client->jsonRequest('PATCH', '/api/tickets/999999/status', [
            'status' => 'in_progress',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownStatusReturns422(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/status', [
            'status' => 'critical',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testValidReassignmentReturns200(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/assignee', [
            'assigneeId' => $this->bob->getId(),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertSame($this->bob->getId(), $data['assignee']['id']);
    }

    public function testReassigningClosedTicketReturns409(): void
    {
        $ticket = $this->createTicket(Status::Closed, $this->alice);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/assignee', [
            'assigneeId' => $this->bob->getId(),
        ]);

        $this->assertResponseStatusCodeSame(409);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testReassigningToUnknownUserReturns404(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/assignee', [
            'assigneeId' => 999999,
        ]);

        $this->assertResponseStatusCodeSame(404);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testReassignmentWithoutAssigneeIdReturns422(): void
    {
        $ticket = $this->createTicket(Status::Assigned, $this->alice);

        $this->client->request(
            'PATCH',
            '/api/tickets/'.$ticket->getId().'/assignee',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function assertResponseIs4xx(): void
    {
        $statusCode = $this->client->getResponse()->getStatusCode();

        $this->assertGreaterThanOrEqual(400, $statusCode);
        $this->assertLessThan(500, $statusCode);
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

    private function createTicket(Status $status, User $assignee): Ticket
    {
        $ticket = new Ticket('Ticket '.$status->value, 'Description', Priority::Normal);
        $ticket->setStatus($status);
        $ticket->setAssignee($assignee);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }
}
