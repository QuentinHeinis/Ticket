<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ChangeStatusRequest;
use App\Dto\CreateTicketRequest;
use App\Dto\ReassignRequest;
use App\Entity\Ticket;
use App\Exception\CapacityExceededException;
use App\Exception\InvalidSearchParameterException;
use App\Exception\InvalidStatusTransitionException;
use App\Exception\ReassignmentNotAllowedException;
use App\Http\TicketResponseMapper;
use App\Http\TicketSearchParser;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\Ticket\TicketCreationService;
use App\Service\Ticket\TicketReassignmentService;
use App\Service\Ticket\TicketStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketCreationService $ticketCreationService,
        private readonly TicketResponseMapper $ticketResponseMapper,
        private readonly TicketStatusService $ticketStatusService,
        private readonly TicketReassignmentService $ticketReassignmentService,
        private readonly UserRepository $userRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketSearchParser $ticketSearchParser,
    ) {
    }

    #[Route('/api/tickets', name: 'api_tickets_search', methods: ['GET'], format: 'json')]
    public function search(Request $request): JsonResponse
    {
        try {
            $criteria = $this->ticketSearchParser->parse($request);
            $result = $this->ticketRepository->search($criteria);
        } catch (InvalidSearchParameterException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'items' => array_map(
                fn (Ticket $ticket): array => $this->ticketResponseMapper->toArray($ticket),
                $result['items'],
            ),
            'nextCursor' => $result['nextCursor'],
            'limit' => $criteria->limit,
        ]);
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
        requirements: ['id' => '\d+'],
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

    #[Route(
        '/api/tickets/{id}/assignee',
        name: 'api_tickets_reassign',
        methods: ['PATCH'],
        format: 'json',
        requirements: ['id' => '\d+'],
    )]
    public function reassign(
        #[MapRequestPayload] ReassignRequest $request,
        Ticket $ticket,
    ): JsonResponse {
        $assignee = $this->userRepository->find($request->assigneeId);

        if (null === $assignee) {
            return new JsonResponse(
                ['error' => 'Utilisateur introuvable.'],
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        try {
            $this->ticketReassignmentService->reassign($ticket, $assignee);
        } catch (ReassignmentNotAllowedException|CapacityExceededException $exception) {
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
