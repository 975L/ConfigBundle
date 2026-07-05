<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Shared by every bundle's WhatsNewProvider to turn its own config/whatsnew.json into entries
class WhatsNewJsonReader
{
    public static function read(string $file, string $bundleName): array
    {
        $entries = [];

        foreach (json_decode(file_get_contents($file), true) ?? [] as $entry) {
            $descriptions = [];
            foreach ($entry['description'] as $description) {
                $descriptions[] = self::resolveDescription($description);
            }

            $entries[] = [
                'bundle' => $bundleName,
                'version' => $entry['version'],
                'date' => new \DateTimeImmutable($entry['date']),
                'description' => $descriptions,
            ];
        }

        return $entries;
    }

    // Picks the description matching the current locale, falling back to English then to the first available translation
    private static function resolveDescription(array $description): string
    {
        return $description[\Locale::getDefault()] ?? $description['en'] ?? reset($description);
    }
}
