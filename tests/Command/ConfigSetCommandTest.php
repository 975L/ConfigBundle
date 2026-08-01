<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\ConfigSetCommand;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigSetCommandTest extends TestCase
{
    private const VAULT_KEY = 'a-test-vault-key';

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }

    private function createConfig(string $slug, ?string $value = null, bool $isSensitive = false, string $kind = Config::TYPE_TEXT): Config
    {
        return (new Config())->setSlug($slug)->setLabel($slug)->setKind($kind)->setIsSensitive($isSensitive)->setValue($value);
    }

    private function createRepository(array $configs): ConfigRepository
    {
        $indexed = [];
        foreach ($configs as $config) {
            $indexed[$config->getSlug()] = $config;
        }

        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria) => $indexed[$criteria['slug']] ?? null);

        return $repository;
    }

    private function createTester(
        array $configs,
        ?EntityManagerInterface $manager = null,
        ?ConfigServiceInterface $configService = null,
        ?string $vaultKey = self::VAULT_KEY,
    ): CommandTester {
        return new CommandTester(new ConfigSetCommand(
            $this->createRepository($configs),
            $configService ?? $this->createStub(ConfigServiceInterface::class),
            new VaultEncryptor($vaultKey),
            $manager ?? $this->createStub(EntityManagerInterface::class),
        ));
    }

    private function createJsonFile(array $values): string
    {
        $file = tempnam(sys_get_temp_dir(), 'configset') . '.json';
        file_put_contents($file, json_encode($values));
        $this->tempFiles[] = $file;

        return $file;
    }

    public function testExecuteFailsWithoutSlugNorFile(): void
    {
        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Provide a slug and a value', $tester->getDisplay());
    }

    public function testExecuteFailsWithBothSlugAndFile(): void
    {
        $tester = $this->createTester([]);
        $tester->execute(['slug' => 'site-name', 'value' => 'My Site', '--file' => $this->createJsonFile([])]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('not both', $tester->getDisplay());
    }

    public function testExecuteFailsWithSlugButNoValue(): void
    {
        $tester = $this->createTester([]);
        $tester->execute(['slug' => 'site-name']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('A value is required', $tester->getDisplay());
    }

    public function testExecuteFailsWithUnreadableFile(): void
    {
        $tester = $this->createTester([]);
        $tester->execute(['--file' => '/does/not/exist.json']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('File not found', $tester->getDisplay());
    }

    public function testExecuteSetsValueFlushesAndInvalidatesCache(): void
    {
        $config = $this->createConfig('site-name');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($config);
        $manager->expects($this->once())->method('flush');

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $tester = $this->createTester([$config], $manager, $configService);
        $tester->execute(['slug' => 'site-name', 'value' => 'My Site']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('My Site', $config->getValue());
        $this->assertStringContainsString('1 set, 0 skipped', $tester->getDisplay());
    }

    public function testExecuteFailsOnUnknownSlug(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('flush');

        $tester = $this->createTester([], $manager);
        $tester->execute(['slug' => 'unknown-slug', 'value' => 'whatever']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('UNKNOWN: unknown-slug', $tester->getDisplay());
    }

    public function testExecuteSkipsUnknownSlugWithIgnoreUnknown(): void
    {
        $config = $this->createConfig('site-name');

        $tester = $this->createTester([$config]);
        $tester->execute([
            '--file' => $this->createJsonFile(['unknown-slug' => 'whatever', 'site-name' => 'My Site']),
            '--ignore-unknown' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('My Site', $config->getValue());
        $this->assertStringContainsString('SKIP (unknown, no installed bundle declares it): unknown-slug', $tester->getDisplay());
        $this->assertStringContainsString('1 set, 1 skipped', $tester->getDisplay());
    }

    public function testExecuteSkipsEmptyValue(): void
    {
        $config = $this->createConfig('site-name', 'My Site');

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'site-name', 'value' => '']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('My Site', $config->getValue());
        $this->assertStringContainsString('SKIP (empty value)', $tester->getDisplay());
    }

    public function testExecuteSkipsUnchangedValue(): void
    {
        $config = $this->createConfig('site-name', 'My Site');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');

        $tester = $this->createTester([$config], $manager);
        $tester->execute(['slug' => 'site-name', 'value' => 'My Site']);

        $this->assertStringContainsString('SKIP (unchanged)', $tester->getDisplay());
    }

    public function testExecuteWithIfEmptyKeepsExistingValue(): void
    {
        $config = $this->createConfig('site-name', 'Already Set');

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'site-name', 'value' => 'My Site', '--if-empty' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('Already Set', $config->getValue());
        $this->assertStringContainsString('SKIP (already set)', $tester->getDisplay());
    }

    public function testExecuteWithIfEmptyFillsEmptyValue(): void
    {
        $config = $this->createConfig('site-name', '');

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'site-name', 'value' => 'My Site', '--if-empty' => true]);

        $this->assertSame('My Site', $config->getValue());
        $this->assertStringContainsString('1 set, 0 skipped', $tester->getDisplay());
    }

    public function testExecuteEncryptsAndMasksSensitiveValue(): void
    {
        $config = $this->createConfig('stripe-secret', null, isSensitive: true);

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'stripe-secret', 'value' => 'sk_live_secret']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('sk_live_secret', (new VaultEncryptor(self::VAULT_KEY))->decrypt($config->getValue()));
        $this->assertStringNotContainsString('sk_live_secret', $tester->getDisplay());
    }

    public function testExecuteFailsOnSensitiveValueWithoutVaultKey(): void
    {
        $config = $this->createConfig('stripe-secret', null, isSensitive: true);

        $tester = $this->createTester([$config], vaultKey: null);
        $tester->execute(['slug' => 'stripe-secret', 'value' => 'sk_live_secret']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertNull($config->getValue());
        $this->assertStringContainsString('C975L_VAULT_KEY is not defined', $tester->getDisplay());
    }

    public function testExecuteSkipsUnchangedSensitiveValue(): void
    {
        $encryptor = new VaultEncryptor(self::VAULT_KEY);
        $config = $this->createConfig('stripe-secret', $encryptor->encrypt('sk_live_secret'), isSensitive: true);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');

        $tester = $this->createTester([$config], $manager);
        $tester->execute(['slug' => 'stripe-secret', 'value' => 'sk_live_secret']);

        $this->assertStringContainsString('SKIP (unchanged)', $tester->getDisplay());
    }

    public function testExecuteFailsOnValueNotMatchingKind(): void
    {
        $config = $this->createConfig('site-maintenance', null, kind: Config::TYPE_BOOL);

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'site-maintenance', 'value' => 'yes']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertNull($config->getValue());
        $this->assertStringContainsString('INVALID: site-maintenance', $tester->getDisplay());
    }

    // "--5" used to pass the int check, then be read back as 0
    public function testExecuteFailsOnIntValueWithSeveralLeadingMinuses(): void
    {
        $config = $this->createConfig('site-form-delay', null, kind: Config::TYPE_INT);

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'site-form-delay', 'value' => '--5']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertNull($config->getValue());
        $this->assertStringContainsString('INVALID: site-form-delay', $tester->getDisplay());
    }

    public function testExecuteSetsANegativeIntValue(): void
    {
        $config = $this->createConfig('site-form-delay', null, kind: Config::TYPE_INT);

        $tester = $this->createTester([$config]);
        $tester->execute(['slug' => 'site-form-delay', 'value' => '-5']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('-5', $config->getValue());
    }

    public function testExecuteSetsSeveralEntriesFromFile(): void
    {
        $name = $this->createConfig('site-name');
        $roles = $this->createConfig('user-roles-available', null, kind: Config::TYPE_JSON);
        $delay = $this->createConfig('site-form-delay', null, kind: Config::TYPE_INT);

        $file = $this->createJsonFile([
            'site-name' => 'My Site',
            'user-roles-available' => ['ROLE_ADMIN', 'ROLE_EDITOR'],
            'site-form-delay' => 3,
        ]);

        $tester = $this->createTester([$name, $roles, $delay]);
        $tester->execute(['--file' => $file]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('My Site', $name->getValue());
        $this->assertSame('["ROLE_ADMIN","ROLE_EDITOR"]', $roles->getValue());
        $this->assertSame('3', $delay->getValue());
        $this->assertStringContainsString('3 set, 0 skipped', $tester->getDisplay());
    }

    public function testExecuteWithDryRunChangesNothing(): void
    {
        $config = $this->createConfig('site-name');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->never())->method('flush');

        $tester = $this->createTester([$config], $manager);
        $tester->execute(['slug' => 'site-name', 'value' => 'My Site', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertNull($config->getValue());
        $this->assertStringContainsString('WOULD SET: site-name', $tester->getDisplay());
    }
}
