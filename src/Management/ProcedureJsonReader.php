<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Shared by every bundle's ProcedureProvider to turn its own config/procedures.json into entries
class ProcedureJsonReader
{
    public static function read(string $file): array
    {
        $entries = [];

        foreach (json_decode(file_get_contents($file), true) ?? [] as $entry) {
            $entries[] = [
                'slug' => $entry['slug'],
                'title' => self::resolveTranslation($entry['title']),
                'body' => self::resolveTranslation($entry['body']),
            ];
        }

        return $entries;
    }

    // Picks the translation matching the current locale, falling back to English then to the first available translation
    private static function resolveTranslation(array $translations): string
    {
        return $translations[\Locale::getDefault()] ?? $translations['en'] ?? reset($translations);
    }
}
