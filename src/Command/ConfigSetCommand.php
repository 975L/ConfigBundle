<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:config:set',
    description: 'Sets the value of one or more config entries, from the command line or a JSON file'
)]
class ConfigSetCommand extends Command
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly VaultEncryptor $vaultEncryptor,
        private readonly EntityManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Slug of the config entry to set (e.g. site-name)')
            ->addArgument('value', InputArgument::OPTIONAL, 'Value to store')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'JSON file holding a {"slug": "value"} object, to set several entries at once')
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Only fill entries whose value is still empty, never overwrite an existing one')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without writing anything')
            ->setHelp(<<<'HELP'
                Sets a single entry:

                  <info>php %command.full_name% site-name "My Site"</info>

                Sets every entry of a JSON file, without overwriting what is already filled in:

                  <info>php %command.full_name% --file=values.json --if-empty</info>

                An empty value is always skipped, so an incomplete file never blanks out a live setting.
                Sensitive entries are encrypted with C975L_VAULT_KEY, exactly as the back-office does.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $values = $this->collectValues($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $isDryRun = (bool) $input->getOption('dry-run');
        $onlyIfEmpty = (bool) $input->getOption('if-empty');

        $counts = ['set' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($values as $slug => $value) {
            ++$counts[$this->applyValue($slug, $value, $io, $isDryRun, $onlyIfEmpty)];
        }

        if (!$isDryRun && $counts['set'] > 0) {
            $this->manager->flush();
            $this->configService->invalidateCache();
        }

        $io->success(sprintf('%d %s, %d skipped.', $counts['set'], $isDryRun ? 'to set' : 'set', $counts['skipped']));

        return $counts['error'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // One slug's outcome - 'set', 'skipped' or 'error', each reported to $io as it's decided
    private function applyValue(string $slug, mixed $value, SymfonyStyle $io, bool $isDryRun, bool $onlyIfEmpty): string
    {
        $config = $this->configRepository->findOneBy(['slug' => $slug]);

        // Entries are declared by the bundles' configs.json, never created here: an unknown slug is a typo
        if (null === $config) {
            $io->warning('UNKNOWN: ' . $slug . ' (run c975l:config:load-all first if the bundle was just installed)');

            return 'error';
        }

        $value = $this->normalizeValue($value);

        $skipReason = $this->skipReason($config, $value, $onlyIfEmpty);
        if (null !== $skipReason) {
            $io->text('  SKIP (' . $skipReason . '): ' . $slug);

            return 'skipped';
        }

        $error = $this->validate($config, $value);
        if (null !== $error) {
            $io->warning('INVALID: ' . $slug . ' - ' . $error);

            return 'error';
        }

        if ($value === $this->getPlainValue($config)) {
            $io->text('  SKIP (unchanged): ' . $slug);

            return 'skipped';
        }

        // A sensitive value must never be stored in plain text, so a missing key is an error, not a fallback
        if ($config->getIsSensitive() && !$this->vaultEncryptor->isKeyDefined()) {
            $io->warning('SKIP (sensitive, C975L_VAULT_KEY is not defined): ' . $slug);

            return 'error';
        }

        if (!$isDryRun) {
            $this->writeValue($config, $value);
        }

        $io->text(sprintf('  %s: %s = %s', $isDryRun ? 'WOULD SET' : 'SET', $slug, $this->display($config, $value)));

        return 'set';
    }

    // Why a value isn't worth writing, before it's even validated - an incomplete file never blanks out a live setting, and --if-empty only ever fills in what's still missing
    private function skipReason(Config $config, string $value, bool $onlyIfEmpty): ?string
    {
        if ('' === $value) {
            return 'empty value';
        }

        return $onlyIfEmpty && !$this->isEmpty($config) ? 'already set' : null;
    }

    private function writeValue(Config $config, string $value): void
    {
        $config->setValue($config->getIsSensitive() ? $this->vaultEncryptor->encrypt($value) : $value);
        $config->setModification(new \DateTime());
        $this->manager->persist($config);
    }

    // Returns the slug => value pairs to apply, either from the arguments or from the JSON file
    private function collectValues(InputInterface $input): array
    {
        $slug = $input->getArgument('slug');
        $file = $input->getOption('file');

        if (null !== $slug && null !== $file) {
            throw new \InvalidArgumentException('Use either the slug/value arguments or --file, not both.');
        }

        if (null !== $slug) {
            if (null === $input->getArgument('value')) {
                throw new \InvalidArgumentException('A value is required when a slug is given.');
            }

            return [$slug => $input->getArgument('value')];
        }

        if (null === $file) {
            throw new \InvalidArgumentException('Provide a slug and a value, or a JSON file with --file.');
        }

        if (!is_file($file)) {
            throw new \InvalidArgumentException('File not found: ' . $file);
        }

        $values = json_decode((string) file_get_contents($file), true);

        if (!is_array($values)) {
            throw new \InvalidArgumentException('Invalid JSON in ' . $file . ': expected a {"slug": "value"} object.');
        }

        return $values;
    }

    // Casts whatever came from the JSON file to the string stored in database (arrays for "json" entries, booleans, numbers...)
    private function normalizeValue(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim((string) $value);
    }

    // Returns true if the entry holds no value yet
    private function isEmpty(Config $config): bool
    {
        $value = $config->getValue();

        return null === $value || '' === $value;
    }

    // Returns the current value in plain text, or null when it can't be read (sensitive value encrypted with another key)
    private function getPlainValue(Config $config): ?string
    {
        $value = $config->getValue();

        if (null === $value || '' === $value) {
            return null;
        }

        if (!$config->getIsSensitive() || !$this->vaultEncryptor->isEncrypted($value)) {
            return $value;
        }

        try {
            return $this->vaultEncryptor->decrypt($value);
        } catch (\RuntimeException) {
            return null;
        }
    }

    // Checks the value against the entry kind, returns the error message or null if valid
    private function validate(Config $config, string $value): ?string
    {
        return match ($config->getKind()) {
            Config::TYPE_BOOL => in_array(strtolower($value), ['true', 'false'], true) ? null : 'expected "true" or "false", got "' . $value . '"',
            // A single optional minus, never "--5" which would be stored then read back as 0
            Config::TYPE_INT => preg_match('/^-?\d+$/', $value) ? null : 'expected an integer, got "' . $value . '"',
            Config::TYPE_JSON => null === json_decode($value, true) && JSON_ERROR_NONE !== json_last_error() ? 'expected valid JSON, got "' . $value . '"' : null,
            Config::TYPE_DATE => false !== strtotime($value) ? null : 'expected a date, got "' . $value . '"',
            default => null,
        };
    }

    // Masks sensitive values so a secret never shows up in the console output or a CI log
    private function display(Config $config, string $value): string
    {
        return $config->getIsSensitive() ? str_repeat('*', min(mb_strlen($value), 12)) : $value;
    }
}
