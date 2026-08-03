<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\AdminUserCreator;
use c975L\ConfigBundle\Service\UserFormSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Bootstraps the first account of an app that has no site foundation to run c975l:site:create on (Config + Ui + a satellite bundle alone), and adds an admin to any app afterwards. Seeds the register/reset-password Forms on the way, so the account created here has a working login/password-reset flow around it
#[AsCommand(
    name: 'c975l:config:user-create',
    description: 'Creates an admin user and seeds the account forms it needs'
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly AdminUserCreator $adminUserCreator,
        private readonly UserFormSeeder $userFormSeeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email of the account, asked interactively when omitted')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password of the account (8 characters minimum), asked interactively when omitted')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email') ?? $io->ask('Email of the administrator', null, static function (?string $answer): string {
            if (!$answer || !filter_var($answer, \FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Invalid email address.');
            }

            return $answer;
        });

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address.');

            return Command::FAILURE;
        }

        if ($this->adminUserCreator->exists($email)) {
            $io->warning(sprintf('A user with the email "%s" already exists, creation skipped.', $email));

            return Command::SUCCESS;
        }

        // No confirmation prompt: the password is typed and echoed in clear text, so a typo is visible right away - this account is created outside any email-verification flow, so echoing it avoids losing it
        $password = $input->getOption('password') ?? $io->ask('Password (8 characters minimum, echoed in clear text)', null, static function (?string $answer): string {
            if (!$answer || \strlen($answer) < 8) {
                throw new \RuntimeException('The password must be at least 8 characters long.');
            }

            return $answer;
        });

        if (\strlen((string) $password) < 8) {
            $io->error('The password must be at least 8 characters long.');

            return Command::FAILURE;
        }

        $this->adminUserCreator->create($email, $password);
        $this->userFormSeeder->ensureAll();

        $io->success(sprintf('User "%s" created, account forms in place.', $email));

        return Command::SUCCESS;
    }
}
