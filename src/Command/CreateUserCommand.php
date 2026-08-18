<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('fullName', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addOption('admin', null, InputOption::VALUE_NONE)
            ->addOption('read-only', null, InputOption::VALUE_NONE)
            ->addOption('inactive', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Ongeldig e-mailadres.</error>');

            return Command::FAILURE;
        }

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);

        if ($existingUser instanceof User) {
            $output->writeln('<error>Deze gebruiker bestaat al.</error>');

            return Command::FAILURE;
        }

        $user = new User();
        $roles = [User::ROLE_USER];

        if ($input->getOption('admin')) {
            $roles[] = User::ROLE_ADMIN;
        }

        if ($input->getOption('read-only')) {
            $roles[] = User::ROLE_READ_ONLY;
        }

        $user
            ->setEmail($email)
            ->setFullName((string) $input->getArgument('fullName'))
            ->setRoles($roles)
            ->setActive(!$input->getOption('inactive'))
            ->setPassword($this->passwordHasher->hashPassword($user, (string) $input->getArgument('password')));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('<info>Gebruiker aangemaakt.</info>');

        return Command::SUCCESS;
    }
}
