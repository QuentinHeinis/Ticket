<?php

declare(strict_types=1);

namespace App\Http;

use App\Dto\TicketSearchCriteria;
use App\Enum\Priority;
use App\Enum\Status;
use App\Exception\InvalidSearchParameterException;
use Symfony\Component\HttpFoundation\Request;

final class TicketSearchParser
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';

    public function parse(Request $request): TicketSearchCriteria
    {
        $statusValue = $this->stringParameter($request, 'status');
        $status = null;

        if (null !== $statusValue) {
            $status = Status::tryFrom($statusValue);

            if (null === $status) {
                throw new InvalidSearchParameterException(
                    sprintf('Le paramètre "status" est invalide : "%s".', $statusValue),
                );
            }
        }

        $priorityValue = $this->stringParameter($request, 'priority');
        $priority = null;

        if (null !== $priorityValue) {
            $priority = Priority::tryFrom($priorityValue);

            if (null === $priority) {
                throw new InvalidSearchParameterException(
                    sprintf('Le paramètre "priority" est invalide : "%s".', $priorityValue),
                );
            }
        }

        $assigneeIdValue = $this->stringParameter($request, 'assigneeId');
        $assigneeId = null;

        if (null !== $assigneeIdValue) {
            if (!ctype_digit($assigneeIdValue) || (int) $assigneeIdValue < 1) {
                throw new InvalidSearchParameterException(
                    sprintf('Le paramètre "assigneeId" doit être un entier supérieur ou égal à 1.'),
                );
            }

            $assigneeId = (int) $assigneeIdValue;
        }

        $createdFrom = $this->dateParameter($request, 'createdFrom');
        $createdTo = $this->dateParameter($request, 'createdTo');

        if (null !== $createdFrom && null !== $createdTo && $createdFrom >= $createdTo) {
            throw new InvalidSearchParameterException(
                'Le paramètre "createdFrom" doit être strictement antérieur au paramètre "createdTo".',
            );
        }

        $limitValue = $this->stringParameter($request, 'limit');
        $limit = 50;

        if (null !== $limitValue) {
            if (!ctype_digit($limitValue) || (int) $limitValue < 1 || (int) $limitValue > 100) {
                throw new InvalidSearchParameterException(
                    'Le paramètre "limit" doit être un entier compris entre 1 et 100.',
                );
            }

            $limit = (int) $limitValue;
        }

        return new TicketSearchCriteria(
            $status,
            $priority,
            $assigneeId,
            $createdFrom,
            $createdTo,
            $this->stringParameter($request, 'cursor'),
            $limit,
        );
    }

    private function stringParameter(Request $request, string $name): ?string
    {
        if (!$request->query->has($name)) {
            return null;
        }

        $value = $request->query->get($name);

        if (!is_string($value)) {
            throw new InvalidSearchParameterException(
                sprintf('Le paramètre "%s" doit être une chaîne de caractères.', $name),
            );
        }

        return $value;
    }

    private function dateParameter(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = $this->stringParameter($request, $name);

        if (null === $value) {
            return null;
        }

        if (1 !== preg_match(self::ISO_8601, $value)) {
            throw new InvalidSearchParameterException(
                sprintf('Le paramètre "%s" doit être une date ISO 8601 valide.', $name),
            );
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidSearchParameterException(
                sprintf('Le paramètre "%s" doit être une date ISO 8601 valide.', $name),
            );
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
