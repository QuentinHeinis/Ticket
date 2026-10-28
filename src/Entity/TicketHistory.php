<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HistoryType;
use App\Repository\TicketHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketHistoryRepository::class)]
#[ORM\Table(name: 'ticket_history')]
#[ORM\Index(name: 'idx_history_ticket_created', columns: ['ticket_id', 'created_at'])]
class TicketHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false)]
    private Ticket $ticket;

    #[ORM\Column(type: 'string', enumType: HistoryType::class)]
    private HistoryType $type;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromValue;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toValue;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true)]
    private ?User $actor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Ticket $ticket,
        HistoryType $type,
        ?string $fromValue,
        ?string $toValue,
        ?User $actor,
    ) {
        $this->ticket = $ticket;
        $this->type = $type;
        $this->fromValue = $fromValue;
        $this->toValue = $toValue;
        $this->actor = $actor;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getType(): HistoryType
    {
        return $this->type;
    }

    public function getFromValue(): ?string
    {
        return $this->fromValue;
    }

    public function getToValue(): ?string
    {
        return $this->toValue;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
