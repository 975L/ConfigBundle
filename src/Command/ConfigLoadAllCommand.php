<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:config:load-all',
    description: 'Loads default config values from all c975L bundles found in vendor/, and from the application itself'
)]
class ConfigLoadAllCommand extends Command
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly VaultEncryptor $vaultEncryptor,
        private readonly ConfigDeclarationLocator $declarationLocator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $files = $this->declarationLocator->findFiles();

        if (empty($files)) {
            $io->warning('No configs*.json found in vendor/c975l/*/config/ nor in config/');

            return Command::SUCCESS;
        }

        $hasSensitiveValues = false;

        foreach ($files as $file) {
            $bundle = $this->declarationLocator->describe($file);

            // Warn if sensitive settings with values are found but no vault key is configured
            $hasSensitiveValues = $hasSensitiveValues || $this->hasUnencryptableValues($file);

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
                'Generate a key with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"',
                'Add it to your .env.local as C975L_VAULT_KEY, then run: php bin/console c975l:config:encrypt-sensitive',
            ]);
        }

        $io->success(sprintf('%d config file(s) processed.', count($files)));

        return Command::SUCCESS;
    }

    // Whether the file declares a sensitive setting carrying a value that can't be encrypted for want of a vault key - always false once one is defined, there's nothing to warn about then
    private function hasUnencryptableValues(string $file): bool
    {
        if ($this->vaultEncryptor->isKeyDefined()) {
            return false;
        }

        foreach (json_decode(file_get_contents($file), true) ?? [] as $configData) {
            if (($configData['sensitive'] ?? false) && !empty($configData['value'])) {
                return true;
            }
        }

        return false;
    }
}
