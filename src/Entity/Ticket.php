<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Priority;
use App\Enum\Status;
use App\Repository\TicketRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
#[ORM\Index(name: 'idx_ticket_status_assignee', columns: ['status', 'assignee_id'])]
#[ORM\Index(name: 'idx_ticket_status_priority_created', columns: ['status', 'priority', 'created_at'])]
#[ORM\Index(name: 'idx_ticket_created_at', columns: ['created_at'])]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'string', enumType: Priority::class)]
    private Priority $priority;

    #[ORM\Column(type: 'string', enumType: Status::class, options: ['default' => 'new'])]
    private Status $status = Status::New;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $priorityChangedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assignee_id', nullable: true)]
    private ?User $assignee = null;

    public function __construct(string $title, string $description, Priority $priority)
    {
        $this->title = $title;
        $this->description = $description;
        $this->priority = $priority;
        $this->status = Status::New;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->priorityChangedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPriority(): Priority
    {
        return $this->priority;
    }

    public function setPriority(Priority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function setStatus(Status $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPriorityChangedAt(): \DateTimeImmutable
    {
        return $this->priorityChangedAt;
    }

    public function setPriorityChangedAt(\DateTimeImmutable $priorityChangedAt): self
    {
        $this->priorityChangedAt = $priorityChangedAt;

        return $this;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): self
    {
        $this->assignee = $assignee;

        return $this;
    }
}
