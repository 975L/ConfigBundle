<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\WhatsNewJsonReader;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class WhatsNewJsonReaderTest extends TestCase
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

    // Writes a temporary whatsnew.json file and returns its path
    private function writeJsonFile(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'whatsnew') . '.json';
        file_put_contents($file, json_encode($entries));

        return $file;
    }

    public function testReadParsesDateAndDescriptionForCurrentLocale(): void
    {
        \Locale::setDefault('fr');
        $file = $this->writeJsonFile([
            ['date' => '2026-07-05', 'description' => [['fr' => 'Nouveau', 'en' => 'New']]],
        ]);

        $entries = WhatsNewJsonReader::read($file);

        $this->assertCount(1, $entries);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entries[0]['date']);
        $this->assertSame('2026-07-05', $entries[0]['date']->format('Y-m-d'));
        $this->assertSame(['Nouveau'], $entries[0]['description']);

        unlink($file);
    }

    public function testReadFallsBackToEnglishWhenCurrentLocaleIsMissing(): void
    {
        \Locale::setDefault('es');
        $file = $this->writeJsonFile([
            ['date' => '2026-07-05', 'description' => [['fr' => 'Nouveau', 'en' => 'New']]],
        ]);

        $entries = WhatsNewJsonReader::read($file);

        $this->assertSame(['New'], $entries[0]['description']);

        unlink($file);
    }

    public function testReadFallsBackToFirstAvailableTranslationWhenNeitherLocaleNorEnglishExists(): void
    {
        \Locale::setDefault('es');
        $file = $this->writeJsonFile([
            ['date' => '2026-07-05', 'description' => [['fr' => 'Nouveau', 'de' => 'Neu']]],
        ]);

        $entries = WhatsNewJsonReader::read($file);

        $this->assertSame(['Nouveau'], $entries[0]['description']);

        unlink($file);
    }

    public function testReadReturnsEmptyArrayForEmptyJsonFile(): void
    {
        $file = $this->writeJsonFile([]);

        $this->assertSame([], WhatsNewJsonReader::read($file));

        unlink($file);
    }
}
