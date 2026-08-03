<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

// Part of this bundle's Sync export archive format (see ExportProviderInterface), shared by every satellite bundle's export provider bundling a real file (Font, CollectionItem, SiteGraphic, Block Media). Static and stateless like ByteFormatter, rather than the trait this used to be: a trait shared across bundles is only ever analysed against the users living in the same package, so its callers' own properties read nowhere else looked unused
class ArchiveFileRegistrar
{
    // Resolves a "/public/..."-relative $filename to its on-disk path and, if it exists, registers it into &$files (archive-relative path => disk path). Returns null when the file is missing/unreadable, so the caller can skip or degrade gracefully rather than exporting a broken reference
    public static function register(string $projectDir, string $filename, array &$files): ?array
    {
        $path = $projectDir . '/public/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        $archivePath = 'files/' . bin2hex(random_bytes(8)) . '_' . basename($filename);
        $files[$archivePath] = $path;

        return ['archivePath' => $archivePath, 'originalFilename' => basename($filename)];
    }
}
