<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Byte sizes as a human reads them, shared by whatever reports a file size (see BackupCommand's report email and BackupResultRecorder's dashboard summary, which must not word the same archive two different ways). Static and untranslated on purpose: the unit symbols are identical in the three languages the bundle ships, so there's no state and nothing to inject
class ByteFormatter
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int $bytes): string
    {
        $unit = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $unit < \count(self::UNITS) - 1) {
            $size /= 1024;
            ++$unit;
        }

        // One decimal only while it still carries information: "1.4 GB" is worth reading, "1234.0 KB" isn't, and neither is "512.0 B"
        return sprintf($unit > 0 && $size < 100 ? '%.1f %s' : '%d %s', $size, self::UNITS[$unit]);
    }
}
