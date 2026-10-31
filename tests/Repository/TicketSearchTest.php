<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Dto\TicketSearchCriteria;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\Priority;
use App\Enum\Status;
use App\Exception\InvalidSearchParameterException;
use App\Repository\TicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketSearchTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketRepository $ticketRepository;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $databasePath = $this->entityManager->getConnection()->getParams()['path'] ?? '';

        if (!str_contains((string) $databasePath, 'test.db')) {
            self::fail('Les tests doivent utiliser la base var/test.db, pas la base de dev.');
        }

        $this->ticketRepository = $this->entityManager->getRepository(Ticket::class);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ticket_history');
        $connection->executeStatement('DELETE FROM ticket');
        $connection->executeStatement('DELETE FROM app_user');

        $this->alice = $this->createUser('Alice', 'alice@pomzed.ch', 5);
        $this->bob = $this->createUser('Bob', 'bob@pomzed.ch', 3);

        $this->entityManager->flush();
    }

    public function testEachFilterWorksAlone(): void
    {
        [$a, $b, $c, $d] = $this->createFilterDataset();

        $this->assertSame([$b->getId()], $this->ids($this->search(status: Status::Assigned)));
        $this->assertSame([$c->getId()], $this->ids($this->search(priority: Priority::High)));
        $this->assertSame(
            [$d->getId(), $b->getId()],
            $this->ids($this->search(assigneeId: $this->alice->getId())),
        );
        $this->assertSame(
            [$d->getId(), $c->getId(), $b->getId()],
            $this->ids($this->search(createdFrom: new \DateTimeImmutable('2026-06-01T11:00:00+00:00'))),
        );
        $this->assertSame(
            [$b->getId(), $a->getId()],
            $this->ids($this->search(createdTo: new \DateTimeImmutable('2026-06-01T12:00:00+00:00'))),
        );
    }

    public function testCombinedFiltersAreApplied(): void
    {
        [, $b] = $this->createFilterDataset();

        $this->assertSame([$b->getId()], $this->ids($this->search(
            status: Status::Assigned,
            priority: Priority::Normal,
            assigneeId: $this->alice->getId(),
            createdFrom: new \DateTimeImmutable('2026-06-01T10:30:00+00:00'),
            createdTo: new \DateTimeImmutable('2026-06-01T11:30:00+00:00'),
        )));
    }

    public function testPeriodHasInclusiveLowerAndExclusiveUpperBound(): void
    {
        [, $b, $c] = $this->createFilterDataset();

        $result = $this->search(
            createdFrom: new \DateTimeImmutable('2026-06-01T11:00:00+00:00'),
            createdTo: new \DateTimeImmutable('2026-06-01T12:00:00+00:00'),
        );

        $this->assertSame([$b->getId()], $this->ids($result));
        $this->assertNotContains($c->getId(), $this->ids($result));
    }

    public function testFullPaginationWithCursorReturnsEveryTicketExactlyOnce(): void
    {
        $base = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $created = [];

        for ($i = 0; $i < 25; ++$i) {
            $created[] = $this->createTicket(Priority::Normal, title: 'Ticket '.$i);
        }

        $this->entityManager->flush();

        foreach ($created as $index => $ticket) {
            $this->setCreatedAt($ticket, $base->modify(sprintf('-%d minutes', $index)));
        }

        $expected = array_map(static fn (Ticket $ticket): ?int => $ticket->getId(), $created);
        sort($expected);

        $seen = [];
        $cursor = null;
        $pages = 0;

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $result = $this->ticketRepository->search(new TicketSearchCriteria(
                null,
                null,
                null,
                null,
                null,
                $cursor,
                10,
            ));
            ++$pages;

            foreach ($result['items'] as $ticket) {
                $this->assertNotContains($ticket->getId(), $seen);
                $seen[] = $ticket->getId();
            }

            $cursor = $result['nextCursor'];

            if (null === $cursor) {
                break;
            }
        }

        $this->assertSame(3, $pages);
        $this->assertCount(25, $seen);

        sort($seen);
        $this->assertSame($expected, $seen);
    }

    public function testTicketsWithSameCreatedAtAreOrderedById(): void
    {
        $createdAt = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $tickets = [];

        for ($i = 0; $i < 3; ++$i) {
            $tickets[] = $this->createTicket(Priority::Normal, title: 'Ticket '.$i);
        }

        $this->entityManager->flush();

        foreach ($tickets as $ticket) {
            $this->setCreatedAt($ticket, $createdAt);
        }

        $first = $this->ticketRepository->search(new TicketSearchCriteria(
            null,
            null,
            null,
            null,
            null,
            null,
            2,
        ));

        $this->assertSame(
            [$tickets[2]->getId(), $tickets[1]->getId()],
            $this->ids($first),
        );
        $this->assertNotNull($first['nextCursor']);

        $second = $this->ticketRepository->search(new TicketSearchCriteria(
            null,
            null,
            null,
            null,
            null,
            $first['nextCursor'],
            2,
        ));

        $this->assertSame([$tickets[0]->getId()], $this->ids($second));
        $this->assertNull($second['nextCursor']);
    }

    public function testMalformedCursorIsRejected(): void
    {
        $this->expectException(InvalidSearchParameterException::class);

        $this->ticketRepository->search(new TicketSearchCriteria(
            null,
            null,
            null,
            null,
            null,
            base64_encode('invalid'),
            10,
        ));
    }

    /**
     * @return array{Ticket, Ticket, Ticket, Ticket}
     */
    private function createFilterDataset(): array
    {
        $a = $this->createTicket(Priority::Low, Status::New, null, 'A');
        $b = $this->createTicket(Priority::Normal, Status::Assigned, $this->alice, 'B');
        $c = $this->createTicket(Priority::High, Status::InProgress, $this->bob, 'C');
        $d = $this->createTicket(Priority::Urgent, Status::Resolved, $this->alice, 'D');

        $this->entityManager->flush();

        $this->setCreatedAt($a, new \DateTimeImmutable('2026-06-01T10:00:00+00:00'));
        $this->setCreatedAt($b, new \DateTimeImmutable('2026-06-01T11:00:00+00:00'));
        $this->setCreatedAt($c, new \DateTimeImmutable('2026-06-01T12:00:00+00:00'));
        $this->setCreatedAt($d, new \DateTimeImmutable('2026-06-01T13:00:00+00:00'));

        return [$a, $b, $c, $d];
    }

    /**
     * @return array{items: list<Ticket>, nextCursor: ?string}
     */
    private function search(
        ?Status $status = null,
        ?Priority $priority = null,
        ?int $assigneeId = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo = null,
        ?string $cursor = null,
        int $limit = 50,
    ): array {
        return $this->ticketRepository->search(new TicketSearchCriteria(
            $status,
            $priority,
            $assigneeId,
            $createdFrom,
            $createdTo,
            $cursor,
            $limit,
        ));
    }

    /**
     * @param array{items: list<Ticket>, nextCursor: ?string} $result
     *
     * @return list<int|null>
     */
    private function ids(array $result): array
    {
        return array_map(static fn (Ticket $ticket): ?int => $ticket->getId(), $result['items']);
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
