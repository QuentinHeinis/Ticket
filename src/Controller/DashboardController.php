<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ticket;
use App\Http\TicketResponseMapper;
use App\Repository\TicketRepository;
use App\Service\Dashboard\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly TicketResponseMapper $ticketResponseMapper,
        private readonly DashboardService $dashboardService,
    ) {
    }

    #[Route('/api/tickets/overdue', name: 'api_tickets_overdue', methods: ['GET'], format: 'json')]
    public function overdue(Request $request): JsonResponse
    {
        $pageParam = $request->query->get('page', '1');
        $limitParam = $request->query->get('limit', '50');

        $page = is_scalar($pageParam) ? filter_var($pageParam, FILTER_VALIDATE_INT) : false;
        $limit = is_scalar($limitParam) ? filter_var($limitParam, FILTER_VALIDATE_INT) : false;

        if (false === $page || $page < 1) {
            return new JsonResponse(
                ['error' => 'Le paramètre "page" doit être un entier supérieur ou égal à 1.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (false === $limit || $limit < 1 || $limit > 100) {
            return new JsonResponse(
                ['error' => 'Le paramètre "limit" doit être un entier compris entre 1 et 100.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tickets = $this->ticketRepository->findOverdue($now, $page, $limit);

        return new JsonResponse([
            'items' => array_map(
                fn (Ticket $ticket): array => $this->ticketResponseMapper->toArray($ticket),
                $tickets,
            ),
            'page' => $page,
            'limit' => $limit,
            'total' => $this->ticketRepository->countOverdue($now),
        ]);
    }

    #[Route('/api/dashboard', name: 'api_dashboard', methods: ['GET'], format: 'json')]
    public function dashboard(): JsonResponse
    {
        return new JsonResponse($this->dashboardService->build(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }
}
