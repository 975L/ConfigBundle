<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'c975l:config:load-all',
    description: 'Loads default config values from all c975L bundles found in vendor/'
)]
class ConfigLoadAllCommand extends Command
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly VaultEncryptor $vaultEncryptor,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $files = glob($this->projectDir . '/vendor/c975l/*/config/configs.json') ?: [];

        if (empty($files)) {
            $io->warning('No configs.json found in vendor/c975l/*/config/');

            return Command::SUCCESS;
        }

        $hasSensitiveValues = false;

        foreach ($files as $file) {
            $bundle = basename(dirname(dirname($file)));

            // Warn if sensitive settings with values are found but no vault key is configured
            if (!$this->vaultEncryptor->isKeyDefined()) {
                $configs = json_decode(file_get_contents($file), true) ?? [];
                foreach ($configs as $configData) {
                    if (($configData['sensitive'] ?? false) && !empty($configData['value'])) {
                        $hasSensitiveValues = true;
                        break;
                    }
                }
            }

            try {
                $this->configService->loadDefaultConfig($file);
                $io->text('  ✓ ' . $bundle);
            } catch (\Throwable $e) {
                $io->warning('  ✗ ' . $bundle . ': ' . $e->getMessage());
            }
        }

        if ($hasSensitiveValues) {
            $io->warning([
                'C975L_VAULT_KEY is not defined.',
                'Sensitive settings with values were found but could not be encrypted.',
                'Add C975L_VAULT_KEY to your .env.local, then run: php bin/console c975l:config:encrypt-sensitive',
            ]);
        }

        $io->success(sprintf('%d bundle config(s) processed.', count($files)));

        return Command::SUCCESS;
    }
}
