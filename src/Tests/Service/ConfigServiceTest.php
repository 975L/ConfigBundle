<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigService;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class ConfigServiceTest extends TestCase
{
    // Builds a Config entity the way the fixtures loader/admin would (fluent setters, no DB needed)
    private function createConfig(
        string $slug,
        mixed $value,
        string $kind = Config::TYPE_TEXT,
        bool $isSensitive = false,
    ): Config {
        $config = new Config();
        $config->setSlug($slug);
        $config->setLabel($slug);
        $config->setKind($kind);
        $config->setIsSensitive($isSensitive);
        $config->setValue($value);
        $config->setCreation(new \DateTime());
        $config->setModification(new \DateTime());

        return $config;
    }

    // Builds a ConfigRepository double returning the given configs, recording each findAll() call so
    // tests can assert on ConfigService's in-memory memoization without a real database
    private function createConfigRepository(array $configs, array &$findAllCallLog): ConfigRepository
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findAll')->willReturnCallback(function () use ($configs, &$findAllCallLog) {
            $findAllCallLog[] = true;

            return $configs;
        });

        return $repository;
    }

    // Builds a CacheInterface double that always invokes the callback (a permanent cache miss), since
    // ConfigService already memoizes the result in memory and the cache backend itself isn't under test
    private function createCache(): CacheInterface
    {
        $item = $this->createStub(ItemInterface::class);
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static fn (string $key, callable $callback) => $callback($item),
        );

        return $cache;
    }

    // Builds a ConfigService wired with test doubles, only the pieces relevant to each test are customized
    private function createService(
        ConfigRepository $repository,
        ?CacheInterface $cache = null,
        ?ParameterBagInterface $params = null,
        ?VaultEncryptor $vaultEncryptor = null,
    ): ConfigService {
        return new ConfigService(
            $repository,
            $cache ?? $this->createCache(),
            $params ?? $this->createStub(ParameterBagInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $vaultEncryptor ?? new VaultEncryptor(null),
        );
    }

    public function testGetReturnsValueForExistingKey(): void
    {
        $callLog = [];
        $repository = $this->createConfigRepository(
            [$this->createConfig('site-name', 'My Site')],
            $callLog,
        );
        $service = $this->createService($repository);

        $this->assertSame('My Site', $service->get('site-name'));
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $callLog = [];
        $repository = $this->createConfigRepository([], $callLog);
        $service = $this->createService($repository);

        $this->assertNull($service->get('unknown-slug'));
    }

    public function testGetBoolRecognizesTrueAndFalseRepresentationsAndDefaultsUnmatchedToFalse(): void
    {
        $callLog = [];
        $service = $this->createService($this->createConfigRepository([], $callLog));

        $this->assertTrue($service->getBool('true'));
        $this->assertTrue($service->getBool('1'));
        $this->assertTrue($service->getBool('yes'));
        $this->assertFalse($service->getBool('false'));
        $this->assertFalse($service->getBool('0'));
        $this->assertFalse($service->getBool('no'));
        // filter_var() without FILTER_NULL_ON_FAILURE resolves anything unrecognized to false
        $this->assertFalse($service->getBool('not-a-boolean'));
    }

    public function testHasParameterReturnsTrueForExistingKeyAndFalseOtherwise(): void
    {
        $callLog = [];
        $repository = $this->createConfigRepository(
            [$this->createConfig('maintenance-mode', 'false', Config::TYPE_BOOL)],
            $callLog,
        );
        $service = $this->createService($repository);

        $this->assertTrue($service->hasParameter('maintenance-mode'));
        $this->assertFalse($service->hasParameter('unknown-slug'));
    }

    public function testGetContainerParameterDelegatesToParameterBag(): void
    {
        $callLog = [];
        $params = $this->createStub(ParameterBagInterface::class);
        $params->method('get')->willReturnCallback(
            static fn (string $parameter) => 'kernel.environment' === $parameter ? 'prod' : null,
        );
        $service = $this->createService($this->createConfigRepository([], $callLog), params: $params);

        $this->assertSame('prod', $service->getContainerParameter('kernel.environment'));
    }

    public function testLoadAllCastsEachValueAccordingToItsKind(): void
    {
        $callLog = [];
        $repository = $this->createConfigRepository([
            $this->createConfig('is-enabled', 'true', Config::TYPE_BOOL),
            $this->createConfig('max-items', '42', Config::TYPE_INT),
            $this->createConfig('allowed-roles', '["ROLE_ADMIN","ROLE_USER"]', Config::TYPE_JSON),
            $this->createConfig('site-name', 'My Site', Config::TYPE_TEXT),
        ], $callLog);
        $service = $this->createService($repository);

        $configs = $service->loadAll();

        $this->assertTrue($configs['is-enabled']);
        $this->assertSame(42, $configs['max-items']);
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $configs['allowed-roles']);
        $this->assertSame('My Site', $configs['site-name']);
    }

    public function testLoadAllDecryptsSensitiveValuesEncryptedByVaultEncryptor(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $encryptedValue = $vaultEncryptor->encrypt('secret-api-key');

        $callLog = [];
        $repository = $this->createConfigRepository(
            [$this->createConfig('api-key', $encryptedValue, Config::TYPE_TEXT, isSensitive: true)],
            $callLog,
        );
        $service = $this->createService($repository, vaultEncryptor: $vaultEncryptor);

        $this->assertSame('secret-api-key', $service->get('api-key'));
    }

    public function testLoadAllIsMemoizedAndDoesNotQueryRepositoryTwice(): void
    {
        $callLog = [];
        $repository = $this->createConfigRepository(
            [$this->createConfig('site-name', 'My Site')],
            $callLog,
        );
        $service = $this->createService($repository);

        $service->loadAll();
        $service->loadAll();

        $this->assertCount(1, $callLog);
    }

    public function testInvalidateCacheForcesRepositoryToBeQueriedAgainOnNextLoadAll(): void
    {
        $callLog = [];
        $repository = $this->createConfigRepository(
            [$this->createConfig('site-name', 'My Site')],
            $callLog,
        );
        $service = $this->createService($repository);

        $service->loadAll();
        $service->invalidateCache();
        $service->loadAll();

        $this->assertCount(2, $callLog);
    }
}
