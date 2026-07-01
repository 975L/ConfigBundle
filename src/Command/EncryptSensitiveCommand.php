<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:config:encrypt-sensitive',
    description: 'Encrypts sensitive values stored in plain-text. Safe to run multiple times (idempotent).'
)]
class EncryptSensitiveCommand extends Command
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly VaultEncryptor $vaultEncryptor,
        private readonly EntityManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->vaultEncryptor->isKeyDefined()) {
            $io->error('C975L_VAULT_KEY is not defined. Add it to your .env.local before running this command.');

            return Command::FAILURE;
        }

        $sensitiveConfigs = array_filter(
            $this->configRepository->findAll(),
            fn ($c) => true === $c->getIsSensitive()
        );

        if (empty($sensitiveConfigs)) {
            $io->info('No sensitive config found.');

            return Command::SUCCESS;
        }

        $encrypted = 0;
        $skipped   = 0;

        foreach ($sensitiveConfigs as $config) {
            $value = $config->getValue();

            if (null === $value || '' === $value) {
                $skipped++;
                $io->text('  SKIP (empty): ' . $config->getSlug());
                continue;
            }

            if ($this->vaultEncryptor->isEncrypted($value)) {
                $skipped++;
                $io->text('  SKIP (already encrypted): ' . $config->getSlug());
                continue;
            }

            $config->setValue($this->vaultEncryptor->encrypt($value));
            $this->manager->persist($config);
            $encrypted++;
            $io->text('  ENCRYPTED: ' . $config->getSlug());
        }

        $this->manager->flush();

        $io->success(sprintf('%d encrypted, %d skipped.', $encrypted, $skipped));

        return Command::SUCCESS;
    }
}
