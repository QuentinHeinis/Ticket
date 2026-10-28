<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ticket\PriorityEscalationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tickets:escalate-priorities', description: 'Escalade automatiquement les priorités des tickets en attente')]
final class EscalatePrioritiesCommand extends Command
{
    public function __construct(
        private readonly PriorityEscalationService $priorityEscalationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->priorityEscalationService->escalate(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $io->success(sprintf('%d ticket(s) escaladé(s).', $count));

        return Command::SUCCESS;
    }
}
