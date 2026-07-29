<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\BackupCommand;
use c975L\ConfigBundle\Management\BackupResultRecorder;
use c975L\ConfigBundle\Management\BackupRetentionPurger;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

class BackupCommandTest extends TestCase
{
    private string $projectDir;
    private array $callOrder;
    private array $recorded;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/c975l-backup-command-test-' . uniqid();
        mkdir($this->projectDir . '/public', 0775, true);
        $this->callOrder = [];
        $this->recorded = [];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    private function createParameterBag(): ParameterBagInterface
    {
        $bag = $this->createStub(ParameterBagInterface::class);
        $bag->method('get')->willReturnCallback(
            fn (string $name): string => 'kernel.project_dir' === $name ? $this->projectDir : ''
        );

        return $bag;
    }

    // Bogus credentials make mysql fail instantly, driving execute() into its error-report path
    private function createConfigService(array $overrides = []): ConfigServiceInterface
    {
        $values = array_merge([
            'site-backup-database' => 'test_db',
            'site-backup-db-host' => '127.0.0.1',
            'site-backup-db-user' => 'c975l_test_invalid_user',
            'site-backup-db-password' => 'wrong',
            'site-url' => 'https://example.com',
            'site-backup-mailto' => 'admin@example.com',
            'site-backup-retention-days' => '15',
            'email-from' => 'noreply@example.com',
        ], $overrides);
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(fn (string $key) => $values[$key] ?? '');

        return $service;
    }

    // Captures what the command hands over to be recorded, without going anywhere near a database
    private function createResultRecorder(): BackupResultRecorder
    {
        $recorder = $this->createStub(BackupResultRecorder::class);
        $recorder->method('record')->willReturnCallback(function (array $outcome): void {
            $this->recorded = $outcome;
        });

        return $recorder;
    }

    private function createCommand(?MailerInterface $mailer = null, ?Connection $connection = null, array $configOverrides = []): BackupCommand
    {
        return new BackupCommand(
            $this->createParameterBag(),
            $this->createConfigService($configOverrides),
            $mailer ?? $this->createStub(MailerInterface::class),
            $connection ?? $this->createStub(Connection::class),
            new BackupRetentionPurger(new Filesystem()),
            $this->createResultRecorder(),
        );
    }

    // A long dump can leave the connection idle past wait_timeout, so it must be closed first
    public function testExecuteClosesConnectionBeforeSendingReport(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('close')
            ->willReturnCallback(function (): void {
                $this->callOrder[] = 'close';
            });

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (): void {
                $this->callOrder[] = 'send';
            });

        $tester = new CommandTester($this->createCommand($mailer, $connection));
        $tester->execute([]);

        $this->assertSame(['close', 'send'], $this->callOrder);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    // Regression guard: MySQL is always dumped table by table now, there's no more --full/whole-database mode
    public function testConfigureHasNoFullOption(): void
    {
        $this->assertFalse($this->createCommand()->getDefinition()->hasOption('full'));
    }

    // The command moved to ConfigBundle, but the schedulers and crontabs already deployed still name the old one
    public function testTheFormerSiteBundleNameIsKeptAsAnAlias(): void
    {
        $this->assertContains('c975l:site:backup', $this->createCommand()->getAliases());
    }

    // The very first run has no BackupFullDateTimeFile marker yet, so the file backup goes complete
    public function testBackupFoldersGoesCompleteOnFirstRun(): void
    {
        $this->assertStringContainsString('COMPLETE Folders backup', $this->runAndCaptureReport());
    }

    // Once site-backup-full-interval-months calendar months have passed since the last complete run, the next run goes complete again instead of staying partial
    public function testBackupFoldersGoesCompleteAgainAfterFullIntervalElapsed(): void
    {
        mkdir($this->projectDir . '/var', 0775, true);
        touch($this->projectDir . '/var/BackupDateTimeFile', time() - 3600);
        // 2 months back guarantees at least 1 whole calendar month elapsed regardless of today's day-of-month
        touch($this->projectDir . '/var/BackupFullDateTimeFile', strtotime('-2 months'));

        $this->assertStringContainsString('COMPLETE Folders backup', $this->runAndCaptureReport());
    }

    // Within site-backup-full-interval-months of the last complete run, the file backup stays partial
    public function testBackupFoldersStaysPartialWithinFullInterval(): void
    {
        mkdir($this->projectDir . '/var', 0775, true);
        touch($this->projectDir . '/var/BackupDateTimeFile', time() - 3600);
        touch($this->projectDir . '/var/BackupFullDateTimeFile', time() - 3600);

        $this->assertStringNotContainsString('COMPLETE Folders backup', $this->runAndCaptureReport());
    }

    // private/ holds what the site deliberately keeps out of the document root (ShopBundle's invoices and the like) - a folder no web server exposes is also a folder no earlier version of this command saved
    public function testPrivateFolderIsBackedUpAlongsidePublic(): void
    {
        mkdir($this->projectDir . '/private', 0775, true);
        file_put_contents($this->projectDir . '/private/invoice.pdf', 'invoice');

        $report = $this->runAndCaptureReport();

        $this->assertStringContainsString('COMPLETE Folders backup (public)', $report);
        $this->assertStringContainsString('COMPLETE Folders backup (private)', $report);
    }

    // An install without a private/ folder must not see it reported, nor fail over it
    public function testAMissingPrivateFolderIsSimplySkipped(): void
    {
        $this->assertStringNotContainsString('(private)', $this->runAndCaptureReport());
    }

    // Every run leaves a trace now, not only the weekly one carrying --report
    public function testTheRunOutcomeIsHandedOverToTheRecorder(): void
    {
        $this->runAndCaptureReport();

        $this->assertSame('https://example.com', $this->recorded['url']);
        $this->assertArrayHasKey('tables', $this->recorded);
        $this->assertArrayHasKey('retention', $this->recorded);
        $this->assertNotEmpty($this->recorded['errors']);
    }

    // The retention window is applied by the run itself, so the server keeps a rolling set of archives without anyone logging in
    public function testOldRunsArePurgedAndReportedOn(): void
    {
        $old = sprintf('%s/var/backup/2020/2020-01/2020-01-01', $this->projectDir);
        mkdir($old, 0775, true);
        file_put_contents($old . '/archive.tar.bz2', 'old');

        $report = $this->runAndCaptureReport();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertStringContainsString('Retention (15 days): 1 run(s) deleted', $report);
    }

    // Handed absolute paths, tar stored home/…/public/medias/photo.jpg, so a partial extracted over a restored complete archive landed in public/home/…/ instead of overwriting the stale files it was meant to replace
    public function testThePartialArchiveStoresPathsRelativeToTheBackedUpRoot(): void
    {
        mkdir($this->projectDir . '/var', 0775, true);
        touch($this->projectDir . '/var/BackupDateTimeFile', time() - 3600);
        touch($this->projectDir . '/var/BackupFullDateTimeFile', time() - 3600);
        mkdir($this->projectDir . '/public/medias', 0775, true);
        file_put_contents($this->projectDir . '/public/medias/photo.jpg', 'photo');

        $report = $this->runAndCaptureReport();

        $this->assertStringContainsString('PARTIAL Folders backup (public)', $report);
        $this->assertStringNotContainsString($this->projectDir, $report);

        $archives = glob($this->projectDir . '/var/backup/*/*/*/WEBSITE_-_*_-_Partial.tar.bz2');
        $this->assertCount(1, $archives);

        $process = new Process(['tar', '--list', '--file', $archives[0]]);
        $process->run();

        $this->assertSame(['./medias/photo.jpg'], array_values(array_filter(explode("\n", $process->getOutput()))));
    }

    // A marker moved past files no archive holds is how a failed run loses them silently: the next partial one only looks at what changed after it, so nothing sees them again until the next complete backup
    public function testAFailedFoldersArchiveLeavesTheMarkersWhereTheyAre(): void
    {
        if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            $this->markTestSkipped('Running as root, a read-only folder would not stop tar from writing there.');
        }

        $markerTime = time() - 3600;
        mkdir($this->projectDir . '/var', 0775, true);
        touch($this->projectDir . '/var/BackupDateTimeFile', $markerTime);
        touch($this->projectDir . '/var/BackupFullDateTimeFile', $markerTime);
        file_put_contents($this->projectDir . '/public/index.html', 'home');

        // Read-only destination, so tar can't create the archive there
        $day = sprintf('%s/var/backup/%s', $this->projectDir, (new \DateTimeImmutable())->format('Y/Y-m/Y-m-d'));
        mkdir($day, 0775, true);
        chmod($day, 0555);

        $report = $this->runAndCaptureReport();

        // The run's own cleanup deletes the folder, empty as it stayed - restored only if it is still there, so tearDown can remove the tree
        if (is_dir($day)) {
            chmod($day, 0775);
        }

        $this->assertStringContainsString('Partial folders tar failed', $report);
        $this->assertSame($markerTime, filemtime($this->projectDir . '/var/BackupDateTimeFile'));
    }

    // An install without site-url gets its failure report all the same, the domain only ever labelling the subject
    public function testAFailureIsStillReportedWithoutASiteUrl(): void
    {
        $capturedEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$capturedEmail): void {
            $capturedEmail = $email;
        });

        (new CommandTester($this->createCommand($mailer, null, ['site-url' => '', 'email-from' => ''])))->execute([]);

        $this->assertNotNull($capturedEmail);
        $this->assertSame('[ERROR] Backup failed - test_db', $capturedEmail->getSubject());
        $this->assertSame('admin@example.com', $capturedEmail->getFrom()[0]->getAddress());
    }

    // Runs the command and returns the error-report email's body, which embeds the full run report
    private function runAndCaptureReport(): string
    {
        $capturedEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$capturedEmail): void {
            $capturedEmail = $email;
        });

        (new CommandTester($this->createCommand($mailer)))->execute([]);

        return $capturedEmail->getTextBody();
    }
}
