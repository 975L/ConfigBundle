<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use Symfony\Component\Filesystem\Filesystem;

// Keeps var/backup down to the last site-backup-retention-days days, so production never holds more than a rolling window of archives - and, just as importantly, never holds *nothing*: the archives used to be deleted from the server as soon as they were copied off it, leaving a gap where the only copy was the offsite one. Whoever copies them off is expected to keep a longer window than this one, otherwise the next copy just downloads again what it purged
class BackupRetentionPurger
{
    // var/backup/YYYY/YYYY-MM/YYYY-MM-DD - only the leaf is dated to the day, and it's the only level this class ever deletes
    private const DATED_FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    // Deletes the dated run folders older than $days, then reports what's left on disk for the dashboard to show
    // @return array{deleted: int, freedBytes: int, runs: int, bytes: int, oldest: ?string}
    public function purge(string $backupFolder, int $days): array
    {
        $deleted = 0;
        $freedBytes = 0;

        // A zero or negative retention would wipe every archive on the very first run, including the one that has just been written - treated as "keep everything" instead, the setting being a plain config entry anyone can mistype
        if ($days > 0) {
            $limit = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days))->format('Y-m-d');

            foreach ($this->datedFolders($backupFolder) as $folder) {
                if (basename($folder) >= $limit) {
                    continue;
                }

                $freedBytes += $this->folderSize($folder);
                $this->filesystem->remove($folder);
                ++$deleted;
            }

            $this->removeEmptyParents($backupFolder);
        }

        return array_merge(['deleted' => $deleted, 'freedBytes' => $freedBytes], $this->stats($backupFolder));
    }

    // What remains on disk once the purge is done, so the dashboard can answer "how far back can I restore from the server itself?" without an SSH session
    // @return array{runs: int, bytes: int, oldest: ?string}
    public function stats(string $backupFolder): array
    {
        $folders = $this->datedFolders($backupFolder);
        sort($folders);

        $bytes = 0;
        foreach ($folders as $folder) {
            $bytes += $this->folderSize($folder);
        }

        return [
            'runs' => \count($folders),
            'bytes' => $bytes,
            'oldest' => $folders ? basename($folders[0]) : null,
        ];
    }

    // The YYYY-MM-DD folders, found by their own name rather than by mtime: a folder re-created or touched by a restore would otherwise look recent, and nothing here must ever delete a folder outside that dated layout
    // @return string[]
    private function datedFolders(string $backupFolder): array
    {
        $folders = glob($backupFolder . '/*/*/*', GLOB_ONLYDIR) ?: [];

        return array_values(array_filter(
            $folders,
            static fn (string $folder): bool => 1 === preg_match(self::DATED_FOLDER_PATTERN, basename($folder)),
        ));
    }

    private function folderSize(string $folder): int
    {
        $bytes = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    // The YYYY-MM and YYYY levels left behind once their dated folders are gone
    private function removeEmptyParents(string $backupFolder): void
    {
        foreach ([$backupFolder . '/*/*', $backupFolder . '/*'] as $pattern) {
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $folder) {
                if (!(new \FilesystemIterator($folder))->valid()) {
                    $this->filesystem->remove($folder);
                }
            }
        }
    }
}
