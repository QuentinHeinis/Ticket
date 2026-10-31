<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'], format: 'json')]
    public function login(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'name' => $user->getName(),
        ]);
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'], format: 'json')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par le firewall.');
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'], format: 'json')]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'name' => $user->getName(),
        ]);
    }
}
