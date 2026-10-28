<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ChangeStatusRequest;
use App\Dto\CreateTicketRequest;
use App\Entity\Ticket;
use App\Exception\CapacityExceededException;
use App\Exception\InvalidStatusTransitionException;
use App\Http\TicketResponseMapper;
use App\Service\Ticket\TicketCreationService;
use App\Service\Ticket\TicketStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketCreationService $ticketCreationService,
        private readonly TicketResponseMapper $ticketResponseMapper,
        private readonly TicketStatusService $ticketStatusService,
    ) {
    }

    #[Route('/api/tickets', name: 'api_tickets_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateTicketRequest $request): JsonResponse
    {
        $ticket = $this->ticketCreationService->create($request);

        return new JsonResponse(
            $this->ticketResponseMapper->toArray($ticket),
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/tickets/{id}/status',
        name: 'api_tickets_change_status',
        methods: ['PATCH'],
        format: 'json',
    )]
    public function changeStatus(
        #[MapRequestPayload] ChangeStatusRequest $request,
        Ticket $ticket,
    ): JsonResponse {
        try {
            $this->ticketStatusService->changeStatus($ticket, $request->status);
        } catch (InvalidStatusTransitionException|CapacityExceededException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(
            $this->ticketResponseMapper->toArray($ticket),
            JsonResponse::HTTP_OK,
        );
    }
}
