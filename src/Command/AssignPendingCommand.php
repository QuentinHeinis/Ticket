<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ticket\PendingAssignmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tickets:assign-pending', description: 'Attribue les tickets en attente')]
final class AssignPendingCommand extends Command
{
    public function __construct(
        private readonly PendingAssignmentService $pendingAssignmentService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->pendingAssignmentService->assignPending();

        $io->success(sprintf('%d ticket(s) attribué(s).', $count));

        return Command::SUCCESS;
    }
}
