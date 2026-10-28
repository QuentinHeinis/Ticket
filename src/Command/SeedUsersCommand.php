<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-users', description: 'Crée ou met à jour les utilisateurs de démonstration')]
final class SeedUsersCommand extends Command
{
    private const PASSWORD = 'pom_zed123!';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = [
            ['Alice', 'alice@pomzed.ch', 5],
            ['Bob', 'bob@pomzed.ch', 3],
            ['Charlie', 'charlie@pomzed.ch', 8],
        ];

        foreach ($users as [$name, $email, $capacity]) {
            $user = $this->userRepository->findOneBy(['email' => $email]);
            $action = 'mis à jour';

            if (null === $user) {
                $user = new User();
                $user
                    ->setEmail($email)
                    ->setRoles(['ROLE_USER'])
                    ->setAvailable(true);
                $this->entityManager->persist($user);
                $action = 'créé';
            }

            $user
                ->setName($name)
                ->setCapacity($capacity)
                ->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));

            $io->text(sprintf('%s (%s) : %s', $name, $email, $action));
        }

        $this->entityManager->flush();

        $io->success('Utilisateurs de démonstration créés ou mis à jour.');

        return Command::SUCCESS;
    }
}
