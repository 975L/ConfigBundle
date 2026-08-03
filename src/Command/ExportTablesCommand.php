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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;

/**
 * Console command to export the data (no CREATE TABLE) of all tables matching a prefix to a
 * single SQL file, meant to be reloaded one-shot into an environment where the schema already
 * exists (e.g. building data in dev then exporting it to prod after migrations already ran there).
 * The file truncates each table and disables FK checks around the inserts, so it can be replayed
 * as-is even if the target tables already contain data. site_config is always excluded, since
 * ConfigBundle has its own dedicated non-destructive export (see ConfigCrudController::exportSql).
 * Uses the same DB credentials as ConfigBundle's c975l:config:backup (site-backup-db-* config keys), so it works
 * even when the DB user used by external GUI tools (DBeaver...) lacks export privileges.
 *
 * Usage:
 *   php bin/console c975l:config:export-tables                  # dumps data of every "site_*" table
 *   php bin/console c975l:config:export-tables --prefix=shop_    # dumps data of every "shop_*" table
 *   php bin/console c975l:config:export-tables --output=my.sql   # custom output path
 *
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
#[AsCommand(
    name: 'c975l:config:export-tables',
    description: 'Exports the data of all tables matching a prefix to a single SQL file'
)]
class ExportTablesCommand extends Command
{
    // The table list goes through "mysql --execute", where neither the database name nor the prefix can be bound as a parameter - so both are refused outside the character set of a MySQL identifier rather than escaped. That also rules out the LIKE wildcards: a prefix holding a "%" or a "_" would quietly match tables it was never meant to, and every table this command lists is TRUNCATEd on replay
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly ConfigServiceInterface $configService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Table name prefix to export', 'site_')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output SQL file path (defaults to var/export/<prefix>_<timestamp>.sql)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->exportTables(
            (string) $input->getOption('prefix'),
            (string) $input->getOption('output') ?: null,
        );

        if (null !== $result['error']) {
            $io->error($result['error']);

            return Command::FAILURE;
        }

        if (empty($result['tables'])) {
            $io->warning($result['message']);

            return Command::SUCCESS;
        }

        $io->success($result['message']);
        foreach ($result['tables'] as $table) {
            $io->text('- ' . $table);
        }

        return Command::SUCCESS;
    }

    // Public so the dashboard can trigger it too; $writeFile is false there, the dump being streamed back
    public function exportTables(string $prefix = 'site_', ?string $outputPath = null, bool $writeFile = true): array
    {
        $database = (string) $this->configService->get('site-backup-database');
        if (empty($database)) {
            return ['error' => 'site-backup-database is not configured in ConfigBundle.', 'tables' => [], 'path' => null, 'content' => null, 'message' => null];
        }
        if (!preg_match(self::IDENTIFIER_PATTERN, $database)) {
            return ['error' => 'site-backup-database must only contain letters, digits and underscores.', 'tables' => [], 'path' => null, 'content' => null, 'message' => null];
        }
        if ('' !== $prefix && !preg_match(self::IDENTIFIER_PATTERN, $prefix)) {
            return ['error' => 'The table prefix must only contain letters, digits and underscores.', 'tables' => [], 'path' => null, 'content' => null, 'message' => null];
        }

        $credentialsFile = $this->createTempCredentialsFile();

        try {
            [$tables, $listError] = $this->getTableList($database, $prefix, $credentialsFile);
            if (null !== $listError) {
                return ['error' => "mysql failed while listing tables: {$listError}", 'tables' => [], 'path' => null, 'content' => null, 'message' => null];
            }
            if (empty($tables)) {
                return ['error' => null, 'tables' => [], 'path' => null, 'content' => null, 'message' => sprintf('No table found matching prefix "%s" in "%s".', $prefix, $database)];
            }

            [$content, $dumpError] = $this->dumpTables($database, $tables, $credentialsFile);
            if (null !== $dumpError) {
                return ['error' => "mysqldump failed while exporting table data: {$dumpError}", 'tables' => [], 'path' => null, 'content' => null, 'message' => null];
            }

            $path = null;
            if ($writeFile) {
                $path = $this->resolveOutputPath($outputPath ?? '', $prefix);
                @mkdir(\dirname($path), 0755, true);
                file_put_contents($path, $content);
            }
        } finally {
            unlink($credentialsFile);
        }

        return [
            'error' => null,
            'tables' => $tables,
            'path' => $path,
            'content' => $content,
            'message' => $writeFile
                ? sprintf('Exported %d table(s) matching "%s%%" to %s', count($tables), $prefix, $path)
                : sprintf('Exported %d table(s) matching "%s%%"', count($tables), $prefix),
        ];
    }

    // Writes host/user/password to a temp file so credentials never appear in the process list
    private function createTempCredentialsFile(): string
    {
        $host = (string) ($this->configService->get('site-backup-db-host') ?: 'localhost');
        $user = (string) $this->configService->get('site-backup-db-user');
        $password = (string) $this->configService->get('site-backup-db-password');

        $tmpFile = tempnam(sys_get_temp_dir(), 'site_export_');
        chmod($tmpFile, 0600);
        file_put_contents($tmpFile, "[client]\nhost={$host}\nuser={$user}\npassword={$password}\n");

        return $tmpFile;
    }

    // Returns [tables, error], error being null on success
    // site_config is excluded: ConfigBundle's own export has upsert semantics a TRUNCATE would destroy
    private function getTableList(string $database, string $prefix, string $credentialsFile): array
    {
        $query = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
            . "WHERE TABLE_SCHEMA = '{$database}' AND TABLE_NAME LIKE '{$prefix}%' AND TABLE_TYPE != 'VIEW' "
            . "AND TABLE_NAME != 'site_config'";

        $process = new Process([
            'mysql',
            '--defaults-extra-file=' . $credentialsFile,
            '--database=' . $database,
            '--silent', '--raw',
            '--execute=' . $query,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return [[], trim($process->getErrorOutput()) ?: 'unknown error (exit code ' . $process->getExitCode() . ')'];
        }

        $tables = array_values(array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            fn ($t) => $t && 'TABLE_NAME' !== $t
        ));

        return [$tables, null];
    }

    // Dumps data only, wrapped in FK-checks-off + a TRUNCATE per table so the file can be replayed as-is on a target that already has (possibly wrong) data in these tables
    // No "--force" here, unlike BackupCommand which dumps one table per process and can afford to skip a broken one: a single table missing from a file that truncates every one of them destroys that table's data on replay, so the whole export fails rather than being written half-done - which is exactly what the exit code says without --force, while stderr also carries plain warnings (MariaDB 11 deprecating the "mysqldump" name on every single call, for one) that say nothing about the dump's completeness
    // Returns [sql, error], error being null on success
    private function dumpTables(string $database, array $tables, string $credentialsFile): array
    {
        $process = new Process(array_merge(
            ['mysqldump',
                '--defaults-extra-file=' . $credentialsFile,
                '--skip-comments', '--compact', '--lock-tables',
                '--quick', '--single-transaction', '--triggers', '--hex-blob',
                '--no-create-info', '--complete-insert',
                $database,
            ],
            $tables
        ));
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            return [null, trim($process->getErrorOutput()) ?: 'unknown error (exit code ' . $process->getExitCode() . ')'];
        }

        // A dump that came back empty on a successful exit still truncates every listed table on replay, so it is refused like any other failure
        $dump = $process->getOutput();
        if ('' === trim($dump)) {
            return [null, 'mysqldump produced an empty dump'];
        }

        $sql = "SET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $table) {
            $sql .= "TRUNCATE TABLE `{$table}`;\n";
        }
        $sql .= "\n" . $dump . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return [$sql, null];
    }

    private function resolveOutputPath(string $output, string $prefix): string
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');

        if ('' !== $output) {
            return str_starts_with($output, '/') ? $output : $projectDir . '/' . $output;
        }

        $timestamp = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');

        return sprintf('%s/var/export/%s_%s.sql', $projectDir, rtrim($prefix, '_'), $timestamp);
    }
}
