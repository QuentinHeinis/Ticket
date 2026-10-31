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

final class TicketSearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $alice;

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

        $this->entityManager->flush();
    }

    public function testSearchReturnsExpectedKeys(): void
    {
        $this->client->request('GET', '/api/tickets');

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('items', $data);
        $this->assertSame([], $data['items']);
        $this->assertArrayHasKey('nextCursor', $data);
        $this->assertNull($data['nextCursor']);
        $this->assertArrayHasKey('limit', $data);
        $this->assertSame(50, $data['limit']);
    }

    public function testUnknownStatusReturns422(): void
    {
        $this->client->request('GET', '/api/tickets?status=critical');

        $this->assertResponseStatusCodeSame(422);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testTooLargeLimitReturns422(): void
    {
        $this->client->request('GET', '/api/tickets?limit=1000');

        $this->assertResponseStatusCodeSame(422);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testInvalidCursorReturns422(): void
    {
        $this->client->request('GET', '/api/tickets?cursor='.base64_encode('invalid'));

        $this->assertResponseStatusCodeSame(422);

        $data = $this->decodeResponse();

        $this->assertArrayHasKey('error', $data);
    }

    public function testResponseNeverContainsPasswordOrEmail(): void
    {
        $ticket = new Ticket('Ticket', 'Description', Priority::Normal);
        $ticket->setStatus(Status::Assigned);
        $ticket->setAssignee($this->alice);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/tickets');

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertCount(1, $data['items']);

        $item = $data['items'][0];

        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('email', $item);
        $this->assertIsArray($item['assignee']);
        $this->assertArrayNotHasKey('password', $item['assignee']);
        $this->assertArrayNotHasKey('email', $item['assignee']);

        $content = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('alice@pomzed.ch', $content);
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
