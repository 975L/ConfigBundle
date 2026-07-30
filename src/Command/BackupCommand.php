<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\BackupResultRecorder;
use c975L\ConfigBundle\Management\BackupRetentionPurger;
use c975L\ConfigBundle\Management\ByteFormatter;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

/**
 * Console command to back up the database and user files, replacing BackupServer.sh.
 *
 * Usage:
 *   php bin/console c975l:config:backup           # DB dumped table by table (always); files: complete on the
 *                                                  # first run and every site-backup-full-interval-months
 *                                                  # calendar months after that (default 1), modified-since-last-run
 *                                                  # only in between
 *   php bin/console c975l:config:backup --report  # also send a summary email after backup
 *
 * Lives in ConfigBundle rather than in SiteBundle, where it started: backing up the database and the
 * user files is not a concern of the pages/menus/blocks domain, it's what every install needs whichever
 * satellite bundles it happens to have - and none of ShopBundle, GalleryBundle, BookBundle or
 * CrowdfundingBundle depends on SiteBundle, so a shop-only or gallery-only install used to have no backup at all.
 *
 * All settings are managed via ConfigBundle (site-backup-* keys).
 * MySQL credentials (host/user/password) are written to a temporary file at runtime
 * and deleted immediately after the backup completes.
 *
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
#[AsCommand(
    name: 'c975l:config:backup',
    description: 'Backs up the database and user files',
    aliases: ['c975l:site:backup']
)]
class BackupCommand extends Command
{
    private const DEFAULT_FULL_INTERVAL_MONTHS = 1;
    private const DEFAULT_RETENTION_DAYS = 15;

    // Backed-up roots, each getting its own archive: public/ for what the site serves, private/ for what it
    // deliberately keeps out of the document root (ShopBundle's invoices and the like) - a folder no web server
    // ever exposes is also a folder no earlier version of this command ever saved
    private const FOLDER_ROOTS = [
        'WEBSITE' => 'public',
        'PRIVATE' => 'private',
    ];

    // Files/dirs excluded from every folders backup (framework assets, not user data)
    private const STANDARD_EXCLUDES = [
        'assets',
        'bundles',
        'humans.txt',
        'index.php',
        'prepend.inc.php',
        'robots.txt',
    ];

    private string $projectDir;
    private string $credentialsFile; // path to the runtime-generated temp file
    private string $database;
    private string $siteDomain;
    private string $mailto;
    private \DateTimeImmutable $startedAt;
    private string $backupFolder;
    private string $finalFolder;
    private string $report = '';
    private array $errors = [];
    private array $warnings = [];
    private array $archives = [];
    private array $tables = ['expected' => 0, 'dumped' => 0, 'missing' => []];
    private int $sqlBytes = 0;
    private string $foldersMode = 'none';
    private int $foldersBytes = 0;
    private int $foldersFiles = 0;
    private int $durationSeconds = 0;
    private array $retention = [];

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly ConfigServiceInterface $configService,
        private readonly MailerInterface $mailer,
        private readonly Connection $connection,
        private readonly BackupRetentionPurger $retentionPurger,
        private readonly BackupResultRecorder $resultRecorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('report', null, InputOption::VALUE_NONE, 'Send a summary email after the backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sendReport = $input->getOption('report');

        $this->projectDir = $this->parameterBag->get('kernel.project_dir');
        $this->database = (string) $this->configService->get('site-backup-database');
        $this->siteDomain = parse_url((string) $this->configService->get('site-url'), PHP_URL_HOST) ?? '';
        $this->mailto = (string) $this->configService->get('site-backup-mailto');

        if (empty($this->database)) {
            $io->error('site-backup-database is not configured in ConfigBundle.');

            return Command::FAILURE;
        }

        $this->startedAt = new \DateTimeImmutable();
        $this->backupFolder = $this->projectDir . '/var/backup';
        $this->finalFolder = sprintf(
            '%s/%s/%s/%s',
            $this->backupFolder,
            $this->startedAt->format('Y'),
            $this->startedAt->format('Y-m'),
            $this->startedAt->format('Y-m-d')
        );
        if (!is_dir($this->finalFolder)) {
            mkdir($this->finalFolder, 0755, true);
        }

        $this->credentialsFile = $this->createTempCredentialsFile();
        try {
            $this->backupMySql();
            $this->backupFolders();
            $this->cleanup();
            $this->purgeOldBackups();
        } finally {
            unlink($this->credentialsFile);
        }

        // A long mysqldump may outlast wait_timeout, so the connection is forced back up first
        $this->connection->close();

        $this->recordOutcome();

        if (!empty($this->errors)) {
            $this->sendErrorReport();
            $io->error('Backup completed with errors.');

            return Command::FAILURE;
        }

        if ($sendReport) {
            $this->sendReport();
        }

        $io->success('Backup completed.');

        return Command::SUCCESS;
    }

    private function backupMySql(): void
    {
        $db = $this->database;
        $dateTime = $this->startedAt->format('Y-m-d_-_H-i');

        $this->report .= sprintf("\nMySQL backup for \"%s\": %s\n", $db, $this->startedAt->format('Y-m-d H:i:s'));

        $tables = $this->getMySqlTableList(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_TYPE != 'VIEW'"
        );
        $this->tables['expected'] = \count($tables);

        foreach ($tables as $table) {
            $this->dumpTable($db, $table, $this->finalFolder . "/{$db}_-_{$table}.sql");
        }

        $this->reportMissingTables();
        $this->sqlBytes = $this->compressSqlFiles("MYSQL_-_{$db}_-_{$dateTime}_-_Tables.sql.tar.bz2");
    }

    private function createTempCredentialsFile(): string
    {
        $host = (string) ($this->configService->get('site-backup-db-host') ?: 'localhost');
        $user = (string) $this->configService->get('site-backup-db-user');
        $password = (string) $this->configService->get('site-backup-db-password');

        $tmpFile = tempnam(sys_get_temp_dir(), 'site_backup_');
        chmod($tmpFile, 0600);
        file_put_contents($tmpFile, "[client]\nhost={$host}\nuser={$user}\npassword={$password}\n");

        return $tmpFile;
    }

    private function getMySqlTableList(string $query): array
    {
        $process = new Process([
            'mysql',
            '--defaults-extra-file=' . $this->credentialsFile,
            '--database=' . $this->database,
            '--silent', '--raw',
            '--execute=' . $query,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->errors[] = 'MySQL table list failed: ' . $process->getErrorOutput();

            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            fn ($t) => $t && 'TABLE_NAME' !== $t
        ));
    }

    private function dumpTable(string $db, string $table, string $outFile): void
    {
        $process = new Process([
            'mysqldump',
            '--defaults-extra-file=' . $this->credentialsFile,
            '--skip-comments', '--compact', '--force', '--lock-tables',
            '--quick', '--single-transaction', '--triggers', '--hex-blob',
            $db, $table,
        ]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->errors[] = "mysqldump failed for table {$table}: " . $process->getErrorOutput();
            $this->tables['missing'][] = $table;
            $this->report .= "- {$table} FAILED\n";

            return;
        }

        // Tables are dumped one by one, so restoring a single file in isolation must not fail on FK constraints referencing a table restored later (or not at all)
        file_put_contents($outFile, "SET FOREIGN_KEY_CHECKS=0;\n" . $process->getOutput() . "SET FOREIGN_KEY_CHECKS=1;\n");

        // Reported only now that the file exists, and with its size: the table list used to be written before the dump was even attempted, so a table appearing in the report proved nothing about it having been saved
        ++$this->tables['dumped'];
        $this->report .= sprintf("- %s (%s)\n", $table, $this->formatBytes((int) filesize($outFile)));
    }

    // A table counted in INFORMATION_SCHEMA but not dumped is an error in its own right, and not one the per-table failures always cover: a table created between the listing and the dump, or skipped for any other reason, would otherwise just be absent from a report nobody compares against anything
    private function reportMissingTables(): void
    {
        $missing = $this->tables['expected'] - $this->tables['dumped'];
        if ($missing <= 0) {
            return;
        }

        $this->report .= sprintf("MISSING: %d of %d tables were not dumped\n", $missing, $this->tables['expected']);

        // Only the difference the per-table failures above don't already account for: those have reported their own error each, naming the table, and repeating them here would double every count the dashboard shows
        $unexplained = $missing - \count($this->tables['missing']);
        if ($unexplained > 0) {
            $this->errors[] = sprintf('%d of %d tables were not dumped, with no failure reported for them.', $unexplained, $this->tables['expected']);
        }
    }

    private function compressSqlFiles(string $archiveName): int
    {
        $sqlFiles = glob($this->finalFolder . '/*.sql');
        if (empty($sqlFiles)) {
            return 0;
        }

        $process = new Process(array_merge(
            ['nice', 'tar', '--remove-files', '--bzip2', '--create', '--file', $archiveName],
            array_map('basename', $sqlFiles)
        ), $this->finalFolder);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->errors[] = 'SQL tar compression failed: ' . $process->getErrorOutput();

            return 0;
        }

        return $this->verifyArchive($this->finalFolder . '/' . $archiveName);
    }

    // Reads the archive back and checks its integrity, rather than trusting tar's exit code: a truncated or
    // corrupted archive is exactly the kind of backup that looks fine for months and fails the day it's needed.
    // Returns the archive size, which is also what tells an empty dump from a real one
    private function verifyArchive(string $path): int
    {
        if (!is_file($path)) {
            $this->errors[] = 'Archive missing after creation: ' . basename($path);

            return 0;
        }

        $process = new Process(['nice', 'bzip2', '--test', $path]);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->errors[] = 'Archive integrity check failed for ' . basename($path) . ': ' . $process->getErrorOutput();

            return 0;
        }

        $bytes = (int) filesize($path);
        $this->archives[] = ['name' => basename($path), 'bytes' => $bytes];
        $this->report .= sprintf("Archive %s: %s (integrity verified)\n", basename($path), $this->formatBytes($bytes));

        return $bytes;
    }

    private function backupFolders(): void
    {
        $dateTimeFile = $this->projectDir . '/var/BackupDateTimeFile';
        $fullDateTimeFile = $this->projectDir . '/var/BackupFullDateTimeFile';

        $this->report .= sprintf("\nFolders backup for \"%s\": %s\n", $this->siteDomain, $this->startedAt->format('Y-m-d H:i:s'));

        // Complete on the first run, or once the configured number of calendar months has passed
        // Never below one month: a zero would turn every 6-hourly run into a complete public/ + private/ archive, which is the one value the entry must not be allowed to take whatever wrote it
        $fullIntervalMonths = max(1, (int) ($this->configService->get('site-backup-full-interval-months') ?: self::DEFAULT_FULL_INTERVAL_MONTHS));
        $doFull = !file_exists($dateTimeFile)
            || !file_exists($fullDateTimeFile)
            || $this->monthsElapsedSince($fullDateTimeFile) >= $fullIntervalMonths;

        $errorsBefore = \count($this->errors);

        // One decision, one marker pair, one archive per root: public/ and private/ are backed up in the same
        // mode on the same run, so a restore never has to pair a complete archive with a partial one
        foreach (self::FOLDER_ROOTS as $prefix => $relativePath) {
            $folder = $this->projectDir . '/' . $relativePath;
            if (!is_dir($folder)) {
                continue;
            }

            $doFull
                ? $this->backupFoldersComplete($prefix, $folder)
                : $this->backupFoldersPartial($prefix, $folder, (int) filemtime($dateTimeFile));
        }

        // A marker moved past files no archive holds is how a failed run loses them silently: the next partial
        // one only looks at what changed after the marker, so nothing sees them again until the next complete
        // backup, up to a month later. Left where it is, the next run simply covers the same ground again
        if (\count($this->errors) > $errorsBefore) {
            return;
        }

        // Record start time so the next partial backup only captures newer files
        touch($dateTimeFile, $this->startedAt->getTimestamp());
        if ($doFull) {
            touch($fullDateTimeFile, $this->startedAt->getTimestamp());
        }
    }

    // Everything under the root, minus the framework's own assets, the generated sitemaps and whatever config/backup_exclude.cnf adds
    private function backupFoldersComplete(string $prefix, string $folder): void
    {
        $this->foldersMode = 'complete';
        $this->report .= sprintf("COMPLETE Folders backup (%s)\n", basename($folder));
        $excludeFile = $this->projectDir . '/config/backup_exclude.cnf';

        // -C changes into $folder so archive paths are relative (./medias/..., etc.)
        $args = ['nice', 'tar', '--bzip2', '--create', '-C', $folder];
        foreach (self::STANDARD_EXCLUDES as $pattern) {
            $args[] = '--exclude=' . $pattern;
        }
        $args[] = '--exclude=sitemap-*';
        if (file_exists($excludeFile)) {
            $args[] = '--exclude-from=' . $excludeFile;
        }
        $archive = $this->foldersArchiveName($prefix, 'Complete');
        $args[] = '--file';
        $args[] = $archive;
        $args[] = '.';

        $this->runFoldersTar($args, 'Complete folders tar failed: ', $archive);
    }

    // Only the files changed since the last run, each listed in the report so a restore knows what the archive holds
    private function backupFoldersPartial(string $prefix, string $folder, int $lastBackupTime): void
    {
        $modifiedFiles = $this->findModifiedFiles($folder, $lastBackupTime);
        if (empty($modifiedFiles)) {
            $this->report .= sprintf("NO FILE to save (%s)\n", basename($folder));

            return;
        }

        // Only downgraded to partial when nothing has gone complete on this run: with several roots, the mode
        // reported is the strongest one, so "complete" never gets overwritten by a root that had nothing to add
        if ('complete' !== $this->foldersMode) {
            $this->foldersMode = 'partial';
        }
        $this->foldersFiles += \count($modifiedFiles);

        $this->report .= sprintf("PARTIAL Folders backup (%s)\n", basename($folder));
        foreach ($modifiedFiles as $file) {
            $this->report .= $file . "\n";
        }

        // Same -C as the complete archive, the members already being relative to the root: handed absolute paths,
        // tar stored them as home/…/public/medias/x.jpg, so extracting a partial over a restored complete one
        // dropped the newer files into public/home/…/ instead of overwriting the stale ones they were to replace
        $archive = $this->foldersArchiveName($prefix, 'Partial');
        $this->runFoldersTar(
            array_merge(
                ['nice', 'tar', '--bzip2', '--create', '-C', $folder, '--file', $archive],
                array_map(static fn (string $file) => './' . $file, $modifiedFiles)
            ),
            'Partial folders tar failed: ',
            $archive
        );
    }

    // Files modified since the last run, minus the same top-level folders the complete backup excludes, each
    // relative to the root - which is what the archive stores and what the report has to name for a restore
    private function findModifiedFiles(string $folder, int $lastBackupTime): array
    {
        $excludedTopLevel = array_flip(self::STANDARD_EXCLUDES);

        $finder = (new Finder())
            ->files()
            ->in($folder)
            ->filter(function (SplFileInfo $f) use ($excludedTopLevel) {
                $topLevel = strtok($f->getRelativePathname(), '/');

                return !isset($excludedTopLevel[$topLevel]) && !str_starts_with($topLevel, 'sitemap-');
            })
            ->filter(fn (SplFileInfo $f) => $f->getMTime() > $lastBackupTime);

        return array_map(
            static fn (SplFileInfo $f) => $f->getRelativePathname(),
            iterator_to_array($finder, false)
        );
    }

    // Both variants share the site/date naming, the prefix telling the backed-up root apart and the suffix the mode
    private function foldersArchiveName(string $prefix, string $suffix): string
    {
        return sprintf(
            '%s/%s_-_%s_-_%s_-_%s.tar.bz2',
            $this->finalFolder,
            $prefix,
            $this->siteDomain,
            $this->startedAt->format('Y-m-d_-_H-i'),
            $suffix
        );
    }

    // 1h timeout, as the folders backup has always used - a complete archive of a media-heavy site takes a while
    private function runFoldersTar(array $args, string $errorPrefix, string $archive): void
    {
        $process = new Process($args);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->errors[] = $errorPrefix . $process->getErrorOutput();

            return;
        }

        $this->foldersBytes += $this->verifyArchive($archive);
    }

    // Whole calendar months since $markerFile's mtime, day-of-month aware rather than a fixed day count
    private function monthsElapsedSince(string $markerFile): int
    {
        $from = (new \DateTimeImmutable())->setTimestamp(filemtime($markerFile));
        $to = $this->startedAt;

        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + ((int) $to->format('n') - (int) $from->format('n'));
        if ((int) $to->format('j') < (int) $from->format('j')) {
            --$months;
        }

        return $months;
    }

    private function cleanup(): void
    {
        // An archive small enough to land here holds nothing usable, so it goes - but it goes on the record too:
        // deleting it silently is how a table that dumped empty used to disappear without leaving any sign it had
        foreach ((new Finder())->files()->in($this->finalFolder)->size('< 50') as $file) {
            $this->warnings[] = sprintf('Discarded empty file %s (%d bytes).', $file->getFilename(), $file->getSize());
            $this->report .= sprintf("DISCARDED empty file: %s (%d bytes)\n", $file->getFilename(), $file->getSize());
            unlink($file->getRealPath());
        }

        $this->deleteEmptyDirectories($this->backupFolder);

        $this->durationSeconds = time() - $this->startedAt->getTimestamp();
        $this->report .= sprintf(
            "\nEnd of backup: %s - Duration: %d minutes and %d seconds\n",
            (new \DateTime())->format('Y-m-d H:i:s'),
            intdiv($this->durationSeconds, 60),
            $this->durationSeconds % 60
        );

        if (!empty($this->errors)) {
            $this->report .= "\nERRORS:\n" . implode("\n", $this->errors) . "\n";
        }
    }

    // Keeps the server's own rolling window of archives, whoever copies them offsite being expected to keep a longer one
    private function purgeOldBackups(): void
    {
        $days = (int) ($this->configService->get('site-backup-retention-days') ?: self::DEFAULT_RETENTION_DAYS);
        $this->retention = array_merge(['days' => $days], $this->retentionPurger->purge($this->backupFolder, $days));

        $this->report .= sprintf(
            "\nRetention (%d days): %d run(s) deleted, %d run(s) kept on the server%s\n",
            $days,
            $this->retention['deleted'],
            $this->retention['runs'],
            null === $this->retention['oldest'] ? '' : sprintf(', oldest %s', $this->retention['oldest'])
        );
    }

    // The run as a HealthCheckResult row, so the dashboard sees every run and not only the weekly one carrying --report
    private function recordOutcome(): void
    {
        try {
            $this->resultRecorder->record($this->outcome());
        } catch (\Throwable $e) {
            // A backup that ran fine must not be reported as failed because its bookkeeping row couldn't be
            // written - but the failure is worth an error report of its own, the dashboard now being blind
            $this->errors[] = 'Recording the backup result failed: ' . $e->getMessage();
        }
    }

    // What BackupResultRecorder turns into the row's status, summary and details
    private function outcome(): array
    {
        return [
            'url' => (string) ($this->configService->get('site-url') ?: $this->database),
            'database' => $this->database,
            'tables' => $this->tables,
            'sqlBytes' => $this->sqlBytes,
            'foldersMode' => $this->foldersMode,
            'foldersBytes' => $this->foldersBytes,
            'foldersFiles' => $this->foldersFiles,
            'archives' => $this->archives,
            'durationSeconds' => $this->durationSeconds,
            'retention' => $this->retention,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    private function deleteEmptyDirectories(string $path): void
    {
        foreach (glob($path . '/*', GLOB_ONLYDIR) as $dir) {
            $this->deleteEmptyDirectories($dir);
            if (!(new \FilesystemIterator($dir))->valid()) {
                rmdir($dir);
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        return ByteFormatter::format($bytes);
    }

    private function sendErrorReport(): void
    {
        if (empty($this->mailto)) {
            return;
        }

        $email = (new Email())
            ->from($this->emailFrom())
            ->to($this->mailto)
            ->subject('[ERROR] Backup failed - ' . $this->subjectLabel())
            ->text(implode("\n", $this->errors) . "\n\nFull report:\n" . $this->report);

        $this->mailer->send($email);
    }

    private function sendReport(): void
    {
        if (empty($this->mailto)) {
            return;
        }

        $email = (new Email())
            ->from($this->emailFrom())
            ->to($this->mailto)
            ->subject('Backup Report - ' . $this->subjectLabel())
            ->text($this->report);

        $this->mailer->send($email);
    }

    // The domain only ever labels the subject, so an install without site-url falls back to the database name rather than getting no failure report at all
    private function subjectLabel(): string
    {
        return '' !== $this->siteDomain ? $this->siteDomain : $this->database;
    }

    // An empty sender makes Email::from() throw, which would take the whole report down with it - the recipient doubles as the sender then, an address that is by definition deliverable here
    private function emailFrom(): string
    {
        $from = (string) $this->configService->get('email-from');

        return '' !== $from ? $from : $this->mailto;
    }
}
