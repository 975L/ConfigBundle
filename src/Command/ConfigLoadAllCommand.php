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
        private readonly ConfigRepository $configRepository,
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

        $this->reportOrphans($io);

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

    // Lists the entries left in database that no configs*.json declares anymore (e.g. a setting a bundle dropped), and points at the dashboard page removing them - never deletes anything here, as this command runs unattended on every deployment
    private function reportOrphans(SymfonyStyle $io): void
    {
        // A file that couldn't be parsed (already warned about above) declares none of its slugs, which would be listed here as orphans and send the admin to prune them
        if (!empty($this->declarationLocator->findUnreadableFiles())) {
            $io->note('Obsolete entries not looked for: some configs*.json could not be read, and everything they declare would be reported as no longer declared.');

            return;
        }

        $orphans = array_diff($this->configRepository->findAllSlugs(), $this->declarationLocator->findDeclaredSlugs());

        if (empty($orphans)) {
            return;
        }

        $io->warning(array_merge(
            [sprintf('%d config entrie(s) in database are no longer declared by any configs*.json:', count($orphans))],
            array_values($orphans),
            [
                'Review and remove them from the dashboard: "Obsolete configs" shortcut.',
                'Or from the command line: php bin/console c975l:config:prune',
            ],
        ));
    }
}
