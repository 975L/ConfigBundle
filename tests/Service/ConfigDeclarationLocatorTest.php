<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class ConfigDeclarationLocatorTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/c975l-config-locator-' . uniqid();
        $this->filesystem->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    private function dumpBundleFile(string $bundle, array $configs, string $filename = 'configs.json'): string
    {
        $file = $this->projectDir . '/vendor/c975l/' . $bundle . '/config/' . $filename;
        $this->filesystem->dumpFile($file, json_encode($configs));

        return $file;
    }

    private function dumpAppFile(array $configs, string $filename = 'configs.json'): string
    {
        $file = $this->projectDir . '/config/' . $filename;
        $this->filesystem->dumpFile($file, json_encode($configs));

        return $file;
    }

    private function createLocator(): ConfigDeclarationLocator
    {
        return new ConfigDeclarationLocator($this->projectDir);
    }

    public function testFindFilesReturnsNothingWhenNoDeclarationExists(): void
    {
        $this->assertSame([], $this->createLocator()->findFiles());
    }

    public function testFindFilesReturnsBundleAndApplicationFiles(): void
    {
        $bundle = $this->dumpBundleFile('site-bundle', [['slug' => 'site-name']]);
        $theme = $this->dumpBundleFile('site-bundle', [['slug' => 'theme-mode']], 'configs-css.json');
        $app = $this->dumpAppFile([['slug' => 'app-own-setting']]);

        $files = $this->createLocator()->findFiles();

        $this->assertCount(3, $files);
        $this->assertContains($bundle, $files);
        $this->assertContains($theme, $files);
        $this->assertContains($app, $files);
    }

    public function testFindDeclaredSlugsMergesEveryFileAndDeduplicates(): void
    {
        $this->dumpBundleFile('site-bundle', [['slug' => 'site-name'], ['slug' => 'site-url']]);
        $this->dumpBundleFile('shop-bundle', [['slug' => 'site-url'], ['slug' => 'shop-name']]);
        $this->dumpAppFile([['slug' => 'app-own-setting']]);

        $slugs = $this->createLocator()->findDeclaredSlugs();

        sort($slugs);
        $this->assertSame(['app-own-setting', 'shop-name', 'site-name', 'site-url'], $slugs);
    }

    // A half-written or invalid file must not silently empty the declared list, which would turn every entry into an orphan
    public function testFindDeclaredSlugsIgnoresMalformedFile(): void
    {
        $this->dumpBundleFile('site-bundle', [['slug' => 'site-name']]);
        $this->filesystem->dumpFile($this->projectDir . '/vendor/c975l/ui-bundle/config/configs.json', '{ not json');

        $this->assertSame(['site-name'], $this->createLocator()->findDeclaredSlugs());
    }

    public function testFindUnreadableFilesReturnsNothingWhenEveryFileParses(): void
    {
        $this->dumpBundleFile('site-bundle', [['slug' => 'site-name']]);
        $this->dumpAppFile([['slug' => 'app-own-setting']]);

        $this->assertSame([], $this->createLocator()->findUnreadableFiles());
    }

    // c975l:config:prune relies on this to refuse deleting entries whose declaration file just happens to be unparsable
    public function testFindUnreadableFilesListsMalformedFiles(): void
    {
        $this->dumpBundleFile('site-bundle', [['slug' => 'site-name']]);
        $malformed = $this->projectDir . '/vendor/c975l/ui-bundle/config/configs.json';
        $this->filesystem->dumpFile($malformed, '{ not json');

        $this->assertSame([$malformed], $this->createLocator()->findUnreadableFiles());
    }

    public function testDescribeNamesTheBundleOrTheApplication(): void
    {
        $locator = $this->createLocator();

        $this->assertSame('site-bundle', $locator->describe($this->dumpBundleFile('site-bundle', [])));
        $this->assertSame('app (configs.json)', $locator->describe($this->dumpAppFile([])));
    }
}
