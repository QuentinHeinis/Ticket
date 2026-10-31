<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OverdueDashboardControllerTest extends WebTestCase
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

        $alice = $this->createUser('Alice', 'alice@pomzed.ch', 5, true);
        $this->createUser('Charlie', 'charlie@pomzed.ch', 8, false);

        $this->entityManager->flush();

        $this->client->loginUser($alice);
    }

    public function testDashboardReturnsExpectedKeys(): void
    {
        $this->client->request('GET', '/api/dashboard');

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('tickets', $data);
        $this->assertArrayHasKey('total', $data['tickets']);
        $this->assertArrayHasKey('unassigned', $data['tickets']);
        $this->assertArrayHasKey('overdue', $data['tickets']);
        $this->assertArrayHasKey('users', $data);
        $this->assertCount(2, $data['users']);

        foreach ($data['users'] as $user) {
            $this->assertArrayHasKey('name', $user);
            $this->assertArrayHasKey('activeTickets', $user);
            $this->assertArrayHasKey('currentLoad', $user);
            $this->assertArrayHasKey('capacity', $user);
        }
    }

    public function testOverdueEndpointIsNotCapturedByIdRoute(): void
    {
        $ticket = new Ticket('Urgent', 'Description', Priority::Urgent);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        $this->backdateCreatedAt($ticket, new \DateTimeImmutable('-31 minutes', new \DateTimeZone('UTC')));

        $this->client->request('GET', '/api/tickets/overdue');

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(50, $data['limit']);
        $this->assertCount(1, $data['items']);
        $this->assertSame($ticket->getId(), $data['items'][0]['id']);
    }

    public function testLimitAboveMaximumReturns422(): void
    {
        $this->client->request('GET', '/api/tickets/overdue?limit=1000');

        $this->assertResponseStatusCodeSame(422);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testPageBelowMinimumReturns422(): void
    {
        $this->client->request('GET', '/api/tickets/overdue?page=0');

        $this->assertResponseStatusCodeSame(422);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
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

    private function createUser(string $name, string $email, int $capacity, bool $available): User
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
