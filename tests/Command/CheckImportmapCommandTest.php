<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\CheckImportmapCommand;
use c975L\ConfigBundle\Management\ImportmapProviderInterface;
use c975L\ConfigBundle\Management\ImportmapRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class CheckImportmapCommandTest extends TestCase
{
    private string $importmapFile;

    protected function setUp(): void
    {
        $this->importmapFile = sys_get_temp_dir() . '/check-importmap-test-' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->importmapFile);
    }

    private function createProvider(array $adminEntries): ImportmapProviderInterface
    {
        $provider = $this->createStub(ImportmapProviderInterface::class);
        $provider->method('getAdminImportmapEntries')->willReturn($adminEntries);
        $provider->method('getImportmapEntries')->willReturn([]);

        return $provider;
    }

    private function createTester(array $providers): CommandTester
    {
        $configReader = new ImportMapConfigReader($this->importmapFile, new RemotePackageStorage(sys_get_temp_dir()));

        return new CommandTester(new CheckImportmapCommand(new ImportmapRegistry($providers), $configReader));
    }

    public function testExecuteAddsMissingEntryToEmptyImportmap(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");

        $provider = $this->createProvider([
            '@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true],
        ]);
        $tester = $this->createTester([$provider]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('1 entrée(s) ajoutée(s)', $tester->getDisplay());

        $written = require $this->importmapFile;
        $this->assertSame('./vendor/c975l/config-bundle/assets/controllers-admin.js', $written['@c975l/config-bundle/controllers-admin.js']['path']);
        $this->assertTrue($written['@c975l/config-bundle/controllers-admin.js']['entrypoint']);
    }

    public function testExecuteNeverTouchesAnEntryAlreadyPresent(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, <<<'PHP'
            <?php

            return [
                '@c975l/config-bundle/controllers-admin.js' => ['path' => './a-custom-override-path.js', 'entrypoint' => false],
            ];

            PHP);

        $provider = $this->createProvider([
            '@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true],
        ]);
        $tester = $this->createTester([$provider]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('déjà à jour', $tester->getDisplay());

        $written = require $this->importmapFile;
        $this->assertSame('./a-custom-override-path.js', $written['@c975l/config-bundle/controllers-admin.js']['path']);
    }

    public function testExecuteIsIdempotentAcrossTwoRuns(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");

        $provider = $this->createProvider([
            '@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true],
        ]);

        $this->createTester([$provider])->execute([]);
        $secondTester = $this->createTester([$provider]);
        $secondTester->execute([]);

        $this->assertStringContainsString('déjà à jour', $secondTester->getDisplay());
    }
}
