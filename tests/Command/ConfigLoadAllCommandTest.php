<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\ConfigLoadAllCommand;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class ConfigLoadAllCommandTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/c975l-config-load-all-' . uniqid();
        $this->filesystem->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    private function createBundleConfigFile(string $bundleName, array $configs, string $filename = 'configs.json'): void
    {
        $dir = $this->projectDir . '/vendor/c975l/' . $bundleName . '/config';
        $this->filesystem->mkdir($dir);
        $this->filesystem->dumpFile($dir . '/' . $filename, json_encode($configs));
    }

    private function createAppConfigFile(array $configs, string $filename = 'configs.json'): void
    {
        $this->filesystem->mkdir($this->projectDir . '/config');
        $this->filesystem->dumpFile($this->projectDir . '/config/' . $filename, json_encode($configs));
    }

    private function createTester(
        ConfigServiceInterface $configService,
        VaultEncryptor $vaultEncryptor,
        array $slugsInDatabase = [],
    ): CommandTester {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findAllSlugs')->willReturn($slugsInDatabase);

        return new CommandTester(new ConfigLoadAllCommand(
            $configService,
            $vaultEncryptor,
            new ConfigDeclarationLocator($this->projectDir),
            $repository,
        ));
    }

    public function testExecuteWarnsWhenNoConfigsJsonIsFound(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->never())->method('loadDefaultConfig');

        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No configs*.json found', $tester->getDisplay());
    }

    public function testExecuteLoadsEachBundleConfigFileFound(): void
    {
        $this->createBundleConfigFile('config-bundle', [['slug' => 'site-name']]);
        $this->createBundleConfigFile('ui-bundle', [['slug' => 'site-role-admin']]);

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->exactly(2))->method('loadDefaultConfig');

        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('config-bundle', $tester->getDisplay());
        $this->assertStringContainsString('ui-bundle', $tester->getDisplay());
        $this->assertStringContainsString('2 config file(s) processed', $tester->getDisplay());
    }

    // A single bundle can ship its config as several files (e.g. configs.json + configs-css.json for theme variables), all matched by the configs*.json glob and loaded independently
    public function testExecuteLoadsEveryConfigsJsonFileWithinASingleBundle(): void
    {
        $this->createBundleConfigFile('site-bundle', [['slug' => 'site-name']], 'configs.json');
        $this->createBundleConfigFile('site-bundle', [['slug' => 'theme-color-primary']], 'configs-css.json');

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->exactly(2))->method('loadDefaultConfig');

        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('2 config file(s) processed', $tester->getDisplay());
    }

    public function testExecuteWarnsButContinuesWhenABundleFailsToLoad(): void
    {
        $this->createBundleConfigFile('config-bundle', [['slug' => 'site-name']]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('loadDefaultConfig')->willThrowException(new \RuntimeException('boom'));

        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('config-bundle: boom', $tester->getDisplay());
    }

    public function testExecuteWarnsAboutMissingVaultKeyWhenSensitiveValuesArePresent(): void
    {
        $this->createBundleConfigFile('config-bundle', [
            ['slug' => 'api-key', 'sensitive' => true, 'value' => 'secret'],
        ]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertStringContainsString('C975L_VAULT_KEY is not defined', $tester->getDisplay());
    }

    public function testExecuteDoesNotWarnAboutVaultKeyWhenSensitiveConfigHasNoValue(): void
    {
        $this->createBundleConfigFile('config-bundle', [
            ['slug' => 'api-key', 'sensitive' => true, 'value' => ''],
        ]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertStringNotContainsString('C975L_VAULT_KEY is not defined', $tester->getDisplay());
    }

    public function testExecuteDoesNotWarnAboutVaultKeyWhenAKeyIsAlreadyDefined(): void
    {
        $this->createBundleConfigFile('config-bundle', [
            ['slug' => 'api-key', 'sensitive' => true, 'value' => 'secret'],
        ]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $tester = $this->createTester($configService, new VaultEncryptor('a-test-vault-key'));
        $tester->execute([]);

        $this->assertStringNotContainsString('C975L_VAULT_KEY is not defined', $tester->getDisplay());
    }

    // The consuming application declares its own entries in config/configs*.json, out of reach of the vendor/c975l/* glob
    public function testExecuteLoadsTheApplicationOwnConfigFile(): void
    {
        $this->createBundleConfigFile('config-bundle', [['slug' => 'site-name']]);
        $this->createAppConfigFile([['slug' => 'app-own-setting']]);

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->exactly(2))->method('loadDefaultConfig');

        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertStringContainsString('app (configs.json)', $tester->getDisplay());
        $this->assertStringContainsString('2 config file(s) processed', $tester->getDisplay());
    }

    public function testExecuteReportsEntriesNoLongerDeclared(): void
    {
        $this->createBundleConfigFile('site-bundle', [['slug' => 'site-name']]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $tester = $this->createTester($configService, new VaultEncryptor(null), ['site-name', 'site-favicon']);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('site-favicon', $tester->getDisplay());
        $this->assertStringContainsString('c975l:config:prune', $tester->getDisplay());
        $this->assertStringNotContainsString('site-name,', $tester->getDisplay());
    }

    // A file that couldn't be parsed declares none of its slugs, so pointing the admin at c975l:config:prune would send them deleting live entries
    public function testExecuteReportsNoOrphanWhenADeclarationFileCannotBeParsed(): void
    {
        $this->createBundleConfigFile('site-bundle', [['slug' => 'site-name']]);
        $this->filesystem->mkdir($this->projectDir . '/config');
        $this->filesystem->dumpFile($this->projectDir . '/config/configs.json', '{"broken": ');

        $configService = $this->createStub(ConfigServiceInterface::class);
        $tester = $this->createTester($configService, new VaultEncryptor(null), ['site-name', 'app-own-setting']);
        $tester->execute([]);

        $this->assertStringNotContainsString('c975l:config:prune', $tester->getDisplay());
        $this->assertStringContainsString('Obsolete entries not looked for', $tester->getDisplay());
    }

    public function testExecuteReportsNothingWhenEveryEntryIsDeclared(): void
    {
        $this->createBundleConfigFile('site-bundle', [['slug' => 'site-name']]);
        $this->createAppConfigFile([['slug' => 'app-own-setting']]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $tester = $this->createTester($configService, new VaultEncryptor(null), ['site-name', 'app-own-setting']);
        $tester->execute([]);

        $this->assertStringNotContainsString('c975l:config:prune', $tester->getDisplay());
    }
}
