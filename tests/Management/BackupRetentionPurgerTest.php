<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\BackupRetentionPurger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class BackupRetentionPurgerTest extends TestCase
{
    private string $backupFolder;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->backupFolder = sys_get_temp_dir() . '/c975l-backup-retention-test-' . uniqid();
        $this->filesystem->mkdir($this->backupFolder);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->backupFolder);
    }

    // Creates var/backup/YYYY/YYYY-MM/YYYY-MM-DD/archive.tar.bz2 for a date given as a "-N days" offset
    private function createRun(int $daysAgo, int $bytes = 100): string
    {
        $date = (new \DateTimeImmutable())->modify(sprintf('-%d days', $daysAgo));
        $folder = sprintf('%s/%s/%s/%s', $this->backupFolder, $date->format('Y'), $date->format('Y-m'), $date->format('Y-m-d'));

        $this->filesystem->mkdir($folder);
        file_put_contents($folder . '/archive.tar.bz2', str_repeat('x', $bytes));

        return $folder;
    }

    public function testPurgeDeletesRunsOlderThanRetentionAndKeepsTheRest(): void
    {
        $old = $this->createRun(40);
        $kept = $this->createRun(3);

        $stats = (new BackupRetentionPurger($this->filesystem))->purge($this->backupFolder, 15);

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($kept);
        $this->assertSame(1, $stats['deleted']);
        $this->assertSame(1, $stats['runs']);
    }

    // The run written by the backup that has just finished must survive its own purge, whatever the retention says
    public function testPurgeKeepsTodaysRun(): void
    {
        $today = $this->createRun(0);

        (new BackupRetentionPurger($this->filesystem))->purge($this->backupFolder, 1);

        $this->assertDirectoryExists($today);
    }

    // A retention mistyped to 0 (or negative) must not be read as "keep nothing" - that would wipe every archive on the server, the freshly written one included
    public function testPurgeKeepsEverythingWhenRetentionIsNotPositive(): void
    {
        $old = $this->createRun(400);

        $stats = (new BackupRetentionPurger($this->filesystem))->purge($this->backupFolder, 0);

        $this->assertDirectoryExists($old);
        $this->assertSame(0, $stats['deleted']);
        $this->assertSame(1, $stats['runs']);
    }

    // Nothing outside the YYYY-MM-DD layout is ever deleted, however old it looks
    public function testPurgeIgnoresFoldersOutsideTheDatedLayout(): void
    {
        $stray = $this->backupFolder . '/2026/2026-07/notes';
        $this->filesystem->mkdir($stray);
        file_put_contents($stray . '/keep.txt', 'keep');

        (new BackupRetentionPurger($this->filesystem))->purge($this->backupFolder, 1);

        $this->assertDirectoryExists($stray);
    }

    public function testStatsReportsCountSizeAndOldestRun(): void
    {
        $this->createRun(10, 300);
        $this->createRun(2, 200);

        $stats = (new BackupRetentionPurger($this->filesystem))->stats($this->backupFolder);

        $this->assertSame(2, $stats['runs']);
        $this->assertSame(500, $stats['bytes']);
        $this->assertSame((new \DateTimeImmutable('-10 days'))->format('Y-m-d'), $stats['oldest']);
    }

    public function testStatsOnAnEmptyBackupFolderReportsNoOldestRun(): void
    {
        $stats = (new BackupRetentionPurger($this->filesystem))->stats($this->backupFolder);

        $this->assertSame(0, $stats['runs']);
        $this->assertNull($stats['oldest']);
    }

    // The YYYY-MM and YYYY levels are cleaned up once their dated folders are gone, so var/backup doesn't keep years of empty directories
    public function testPurgeRemovesTheEmptyYearAndMonthFoldersItLeavesBehind(): void
    {
        $this->createRun(400);

        (new BackupRetentionPurger($this->filesystem))->purge($this->backupFolder, 15);

        $this->assertSame([], glob($this->backupFolder . '/*', GLOB_ONLYDIR));
    }
}
