<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TicketControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

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

        $this->createUser('Alice', 'alice@pomzed.ch', 5);
        $this->createUser('Bob', 'bob@pomzed.ch', 3);
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

    private function createUser(string $name, string $email, int $capacity): void
    {
        $user = (new User())
            ->setName($name)
            ->setEmail($email)
            ->setPassword(password_hash('pom_zed123!', PASSWORD_BCRYPT))
            ->setRoles(['ROLE_USER'])
            ->setAvailable(true)
            ->setCapacity($capacity);

        $this->entityManager->persist($user);
    }
}
