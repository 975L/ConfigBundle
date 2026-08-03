<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests;

use PHPUnit\Framework\TestCase;

// Guards config/configs.json, the list ConfigBundle seeds the backoffice from - an entry whose label/description has no translation shows up there as a raw "label.some_key" string
class ConfigsJsonTest extends TestCase
{
    private const LOCALES = ['en', 'es', 'fr'];

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadConfigs(): array
    {
        // Every configs*.json, not just configs.json: ConfigDeclarationLocator globs them all at runtime, so a bundle shipping a second declaration file would otherwise ship it untested
        $files = glob(__DIR__ . '/../config/configs*.json') ?: [];
        $this->assertNotSame([], $files);

        $configs = [];
        foreach ($files as $file) {
            $declared = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($declared, basename($file) . ' is not a list of entries');
            $configs = array_merge($configs, $declared);
        }

        return $configs;
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $locale): array
    {
        $xliff = simplexml_load_file(__DIR__ . '/../translations/site_config.' . $locale . '.xlf');
        $translations = [];
        foreach ($xliff->file->body->{'trans-unit'} as $unit) {
            $translations[(string) $unit->source] = (string) $unit->target;
        }

        return $translations;
    }

    // Two entries sharing a slug would have the second silently shadow the first
    public function testSlugsAreUnique(): void
    {
        $slugs = array_column($this->loadConfigs(), 'slug');

        $this->assertSame(array_unique($slugs), $slugs);
    }

    // Every entry carries the keys ConfigBundle reads
    public function testEntriesCarryTheExpectedKeys(): void
    {
        foreach ($this->loadConfigs() as $config) {
            foreach (['label', 'slug', 'sensitive', 'restricted', 'value', 'kind', 'group', 'severity', 'description'] as $key) {
                $this->assertArrayHasKey($key, $config, sprintf('Config "%s" misses the "%s" key', $config['slug'] ?? '?', $key));
            }
        }
    }

    // Both the label and the description of every entry are translated in each shipped locale
    public function testLabelsAndDescriptionsAreTranslatedInEveryLocale(): void
    {
        $configs = $this->loadConfigs();

        foreach (self::LOCALES as $locale) {
            $translations = $this->loadTranslations($locale);
            foreach ($configs as $config) {
                foreach ([$config['label'], $config['description']] as $key) {
                    $this->assertArrayHasKey($key, $translations, sprintf('"%s" has no %s translation', $key, $locale));
                    $this->assertNotSame('', $translations[$key], sprintf('"%s" has an empty %s translation', $key, $locale));
                }
            }
        }
    }
}
