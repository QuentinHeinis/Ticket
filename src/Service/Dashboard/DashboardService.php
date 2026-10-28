<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Repository\TicketRepository;
use App\Repository\UserRepository;

final class DashboardService
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return array{
     *     tickets: array{total: int, unassigned: int, overdue: int},
     *     users: list<array{name: string, activeTickets: int, currentLoad: int, capacity: int}>
     * }
     */
    public function build(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $counts = $this->ticketRepository->dashboardCounts($now);

        return [
            'tickets' => [
                'total' => $counts['total'],
                'unassigned' => $counts['unassigned'],
                'overdue' => $counts['overdue'],
            ],
            'users' => $this->userRepository->findDashboardRows(),
        ];
    }
}
