<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\HistoryType;
use App\Enum\Priority;
use App\Enum\Status;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityTest extends WebTestCase
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

        $this->entityManager->flush();
    }

    public function testApiTicketsWithoutLoginReturns401(): void
    {
        $this->client->request('GET', '/api/tickets');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginWithValidCredentialsReturnsIdAndName(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => 'alice@pomzed.ch',
            'password' => 'pom_zed123!',
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertSame(['id', 'name'], array_keys($data));
        $this->assertSame($this->alice->getId(), $data['id']);
        $this->assertSame('Alice', $data['name']);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('password', $data);

        $content = (string) $this->client->getResponse()->getContent();

        $this->assertStringNotContainsString('alice@pomzed.ch', $content);
        $this->assertStringNotContainsString('password', $content);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => 'alice@pomzed.ch',
            'password' => 'wrong-password',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsConnectedUser(): void
    {
        $this->client->loginUser($this->alice);

        $this->client->request('GET', '/api/me');

        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeResponse();

        $this->assertSame(['id', 'name'], array_keys($data));
        $this->assertSame($this->alice->getId(), $data['id']);
        $this->assertSame('Alice', $data['name']);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->client->loginUser($this->alice);

        $this->client->request('POST', '/api/logout');

        $this->client->request('GET', '/api/tickets');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testStatusChangeAndReassignmentRecordActor(): void
    {
        $this->client->loginUser($this->alice);

        $ticket = new Ticket('Ticket', 'Description', Priority::Normal);
        $ticket->setStatus(Status::Assigned);
        $ticket->setAssignee($this->alice);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/status', [
            'status' => 'in_progress',
        ]);

        $this->assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('PATCH', '/api/tickets/'.$ticket->getId().'/assignee', [
            'assigneeId' => $this->bob->getId(),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT type, actor_id
            FROM ticket_history
            WHERE ticket_id = :ticketId
              AND type IN (:types)
            ORDER BY id',
            [
                'ticketId' => $ticket->getId(),
                'types' => [HistoryType::StatusChanged->value, HistoryType::Reassigned->value],
            ],
            [
                'types' => ArrayParameterType::STRING,
            ],
        );

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame($this->alice->getId(), (int) $row['actor_id']);
        }

        $types = array_column($rows, 'type');
        sort($types);

        $this->assertSame([HistoryType::Reassigned->value, HistoryType::StatusChanged->value], $types);
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
