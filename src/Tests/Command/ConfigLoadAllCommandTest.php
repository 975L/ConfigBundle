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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
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

    private function createBundleConfigFile(string $bundleName, array $configs): void
    {
        $dir = $this->projectDir . '/vendor/c975l/' . $bundleName . '/config';
        $this->filesystem->mkdir($dir);
        $this->filesystem->dumpFile($dir . '/configs.json', json_encode($configs));
    }

    private function createTester(
        ConfigServiceInterface $configService,
        VaultEncryptor $vaultEncryptor,
    ): CommandTester {
        return new CommandTester(new ConfigLoadAllCommand($configService, $vaultEncryptor, $this->projectDir));
    }

    public function testExecuteWarnsWhenNoConfigsJsonIsFound(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->never())->method('loadDefaultConfig');

        $tester = $this->createTester($configService, new VaultEncryptor(null));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No configs.json found', $tester->getDisplay());
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
        $this->assertStringContainsString('2 bundle config(s) processed', $tester->getDisplay());
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
}
