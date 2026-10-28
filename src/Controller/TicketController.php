<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateTicketRequest;
use App\Http\TicketResponseMapper;
use App\Service\Ticket\TicketCreationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketCreationService $ticketCreationService,
        private readonly TicketResponseMapper $ticketResponseMapper,
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
}
