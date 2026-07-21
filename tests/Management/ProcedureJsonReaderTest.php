<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ProcedureJsonReader;
use PHPUnit\Framework\TestCase;

class ProcedureJsonReaderTest extends TestCase
{
    private string $defaultLocale;

    protected function setUp(): void
    {
        $this->defaultLocale = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->defaultLocale);
    }

    // Writes a temporary procedures.json file and returns its path
    private function writeJsonFile(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'procedures') . '.json';
        file_put_contents($file, json_encode($entries));

        return $file;
    }

    public function testReadParsesSlugAndTitleAndBodyForCurrentLocale(): void
    {
        \Locale::setDefault('fr');
        $file = $this->writeJsonFile([
            ['slug' => 'creer-page', 'title' => ['fr' => 'Créer une page', 'en' => 'Create a page'], 'body' => ['fr' => 'Corps', 'en' => 'Body']],
        ]);

        $entries = ProcedureJsonReader::read($file);

        $this->assertSame([['slug' => 'creer-page', 'title' => 'Créer une page', 'body' => 'Corps']], $entries);

        unlink($file);
    }

    public function testReadFallsBackToEnglishWhenCurrentLocaleIsMissing(): void
    {
        \Locale::setDefault('es');
        $file = $this->writeJsonFile([
            ['slug' => 'creer-page', 'title' => ['fr' => 'Créer une page', 'en' => 'Create a page'], 'body' => ['fr' => 'Corps', 'en' => 'Body']],
        ]);

        $entries = ProcedureJsonReader::read($file);

        $this->assertSame('Create a page', $entries[0]['title']);
        $this->assertSame('Body', $entries[0]['body']);

        unlink($file);
    }

    public function testReadFallsBackToFirstAvailableTranslationWhenNeitherLocaleNorEnglishExists(): void
    {
        \Locale::setDefault('es');
        $file = $this->writeJsonFile([
            ['slug' => 'creer-page', 'title' => ['fr' => 'Créer une page', 'de' => 'Seite erstellen'], 'body' => ['fr' => 'Corps']],
        ]);

        $entries = ProcedureJsonReader::read($file);

        $this->assertSame('Créer une page', $entries[0]['title']);

        unlink($file);
    }

    public function testReadReturnsEmptyArrayForEmptyJsonFile(): void
    {
        $file = $this->writeJsonFile([]);

        $this->assertSame([], ProcedureJsonReader::read($file));

        unlink($file);
    }
}
