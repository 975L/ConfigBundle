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
use c975L\ConfigBundle\Service\ImportmapSpecifierLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class CheckImportmapCommandTest extends TestCase
{
    private string $importmapFile;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->importmapFile = sys_get_temp_dir() . '/check-importmap-test-' . uniqid() . '.php';
        $this->projectDir = sys_get_temp_dir() . '/check-importmap-project-' . uniqid();
        (new Filesystem())->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->importmapFile, $this->projectDir]);
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

        return new CommandTester(new CheckImportmapCommand(
            new ImportmapRegistry($providers),
            new ImportmapSpecifierLocator($this->projectDir),
            $configReader,
            $this->projectDir,
        ));
    }

    private function writeControllersJson(bool $chartjsEnabled): void
    {
        (new Filesystem())->dumpFile($this->projectDir . '/assets/controllers.json', json_encode([
            'controllers' => [
                '@symfony/ux-chartjs' => ['chart' => ['enabled' => $chartjsEnabled, 'fetch' => 'eager']],
            ],
            'entrypoints' => [],
        ]));
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

    public function testExecuteWarnsWhenControllersJsonEnablesChartjs(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        $this->writeControllersJson(true);

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('@symfony/ux-chartjs', $tester->getDisplay());
    }

    public function testExecuteDoesNotWarnWhenControllersJsonDisablesChartjs(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        $this->writeControllersJson(false);

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertStringNotContainsString('@symfony/ux-chartjs', $tester->getDisplay());
    }

    public function testExecuteDoesNotWarnWhenControllersJsonIsMissing(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertStringNotContainsString('@symfony/ux-chartjs', $tester->getDisplay());
    }

    // An unreadable controllers.json is the app's own problem to report, not this command's job to fail on
    public function testExecuteStillSucceedsOnMalformedControllersJson(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        (new Filesystem())->dumpFile($this->projectDir . '/assets/controllers.json', '{ not json');

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringNotContainsString('@symfony/ux-chartjs', $tester->getDisplay());
    }

    // The entry sits under a "controllers" key - a flat file (or one enabling something else entirely) must not trigger the warning
    public function testExecuteDoesNotWarnWhenChartjsIsNotUnderTheControllersKey(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        (new Filesystem())->dumpFile($this->projectDir . '/assets/controllers.json', json_encode([
            '@symfony/ux-chartjs' => ['chart' => ['enabled' => true]],
        ]));

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertStringNotContainsString('@symfony/ux-chartjs', $tester->getDisplay());
    }

    // Mimics an installed c975L bundle whose own JS imports a bare specifier
    private function writeBundleAsset(string $contents): void
    {
        (new Filesystem())->dumpFile($this->projectDir . '/vendor/c975l/config-bundle/assets/controllers-admin.js', $contents);
    }

    // Mimics a Symfony UX package as Composer installs it: an assets/package.json naming the specifier and pointing at its entry file
    private function writeUxPackage(string $vendor, string $name, string $specifier, string $main = 'dist/controller.js'): void
    {
        (new Filesystem())->dumpFile(
            $this->projectDir . '/vendor/' . $vendor . '/' . $name . '/assets/package.json',
            json_encode(['name' => $specifier, 'main' => $main])
        );
    }

    // The failure this whole check exists for: a bundle's JS imports a package by bare specifier, no importmap entry declares it, and the browser then fails the entire module - every Stimulus controller it registers silently lost
    public function testExecuteAddsAnEntryForABareSpecifierImportedByABundleAsset(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        $this->writeBundleAsset("import ChartjsController from '@symfony/ux-chartjs';\n");
        $this->writeUxPackage('symfony', 'ux-chartjs', '@symfony/ux-chartjs');

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $written = require $this->importmapFile;
        $this->assertSame('./vendor/symfony/ux-chartjs/assets/dist/controller.js', $written['@symfony/ux-chartjs']['path']);
        // Imported by another module, never loaded on its own
        $this->assertArrayNotHasKey('entrypoint', $written['@symfony/ux-chartjs']);
    }

    // Relative imports are resolved by AssetMapper from the importing file itself and never need an entry of their own
    public function testExecuteIgnoresRelativeImports(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        $this->writeBundleAsset("import { addToolbarButton } from './js/block-toolbar.js';\nimport '../styles/app.css';\n");

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertStringContainsString('déjà à jour', $tester->getDisplay());
    }

    // An entry an app deliberately points elsewhere (a fork, a CDN version) must survive untouched
    public function testExecuteLeavesABareSpecifierAlreadyDeclaredAlone(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, <<<'PHP'
            <?php

            return [
                '@symfony/ux-chartjs' => ['path' => './assets/my-own-fork.js'],
            ];

            PHP);
        $this->writeBundleAsset("import ChartjsController from '@symfony/ux-chartjs';\n");
        $this->writeUxPackage('symfony', 'ux-chartjs', '@symfony/ux-chartjs');

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertStringContainsString('déjà à jour', $tester->getDisplay());
        $written = require $this->importmapFile;
        $this->assertSame('./assets/my-own-fork.js', $written['@symfony/ux-chartjs']['path']);
    }

    // Nothing to resolve it against: reported rather than guessed at, so the missing package is visible from the CLI instead of only in the browser console
    public function testExecuteWarnsWhenABareSpecifierCannotBeResolvedInVendor(): void
    {
        (new Filesystem())->dumpFile($this->importmapFile, "<?php\n\nreturn [];\n");
        $this->writeBundleAsset("import Thing from '@acme/not-installed';\n");

        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('@acme/not-installed', $tester->getDisplay());
    }
}
