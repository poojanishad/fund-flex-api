<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:hash-password',
    description: 'Generate a bcrypt hash for use as API_PASSWORD_HASH in .env',
)]
class HashPasswordCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('password', InputArgument::REQUIRED, 'Plain-text password to hash');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plain = $input->getArgument('password');
        $hash  = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);

        $output->writeln('');
        $output->writeln('<info>Add this to your .env:</info>');
        $output->writeln("API_PASSWORD_HASH={$hash}");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
