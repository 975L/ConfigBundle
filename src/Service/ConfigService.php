<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use c975L\ConfigBundle\Repository\ConfigRepository;
use C975L\ConfigBundle\Entity\Config;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Doctrine\ORM\EntityManagerInterface;

class ConfigService implements ConfigServiceInterface
{
    private const CACHE_KEY = 'site_configs_all';

    private ?array $configs = null;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly CacheInterface $cache,
        private readonly ParameterBagInterface $params,
        private readonly EntityManagerInterface $manager,
        private readonly VaultEncryptor $vaultEncryptor,
    ) {}

    // Returns the value of a config (or null if not found)
    public function get(string $key): mixed
    {
        $configs = $this->loadAll();

        return $configs[$key] ?? null;
    }

    // Returns the boolean value of a config (true or false)
    public function getBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // Returns true if the parameter exists in the configs, false otherwise
    public function hasParameter(string $parameter): bool
    {
        $configs = $this->loadAll();

        return array_key_exists($parameter, $configs);
    }

    // Returns the value of a container parameter (or null if not found)
    public function getContainerParameter(string $parameter): mixed
    {
        return $this->params->get($parameter);
    }

    // Invalidates the configs cache (to be called after any modification).
    public function invalidateCache(): void
    {
        $this->configs = null;
        try {
            $this->cache->delete(self::CACHE_KEY);
        } catch (InvalidArgumentException) {
            // Quiet - cache will be simply recalculated on next access
        }
    }

    // Loads all configs in cache and returns them as an associative array (slug => value)
    public function loadAll(): array
    {
        if ($this->configs !== null) {
            return $this->configs;
        }

        // $this->invalidateCache(); // For debug
        $this->configs = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(null);

            $configs = [];
            foreach ($this->configRepository->findAll() as $configEntry) {
                $value = $configEntry->getValue();

                // Decrypt sensitive values that have been encrypted
                if ($configEntry->getIsSensitive() && null !== $value && '' !== $value) {
                    if ($this->vaultEncryptor->isEncrypted($value)) {
                        $value = $this->vaultEncryptor->decrypt($value);
                    }
                }

                $configs[$configEntry->getSlug()] = $this->castValue($configEntry->getKind(), $value);
            }

            return $configs;
        });

        return $this->configs;
    }

    private function castValue(string $kind, mixed $value): mixed
    {
        return match ($kind) {
            Config::TYPE_BOOL => $this->getBool($value),
            Config::TYPE_INT  => (int) $value,
            Config::TYPE_JSON => is_string($value) ? (json_decode($value, true) ?? []) : [],
            default => $value,
        };
    }

    // Loads default config values in the database (if not already present)
    public function loadDefaultConfig(string $jsonPath): void
    {
        $configs = json_decode(file_get_contents($jsonPath), true);

        foreach ($configs as $configData) {
            $existing = $this->configRepository->findOneBySlug($configData['slug']);

            if ($existing) {
                $this->syncMetadata($existing, $configData);

                continue;
            }

            $this->createConfig($configData);
        }

        $this->manager->flush();

        $this->invalidateCache();
    }

    // Label/kind/group/description/severity/isRestricted are metadata fixed by the bundle author (not user data), so they're kept in sync even on existing configs; value/isSensitive carry production state and are never touched here
    private function syncMetadata(Config $config, array $configData): void
    {
        $kind = $configData['kind'] ?? 'text';
        $group = $configData['group'] ?? null;
        $description = $configData['description'] ?? null;
        $severity = $configData['severity'] ?? null;
        $isRestricted = $configData['restricted'] ?? false;

        if ($config->getLabel() === $configData['label']
            && $config->getKind() === $kind
            && $config->getGroup() === $group
            && $config->getDescription() === $description
            && $config->getSeverity() === $severity
            && $config->getIsRestricted() === $isRestricted
        ) {
            return;
        }

        $config->setLabel($configData['label']);
        $config->setKind($kind);
        $config->setGroup($group);
        $config->setDescription($description);
        $config->setSeverity($severity);
        $config->setIsRestricted($isRestricted);
        $config->setModification(new \DateTime());

        $this->manager->persist($config);
    }

    private function createConfig(array $configData): void
    {
        $isSensitive = $configData['sensitive'] ?? false;
        $rawValue    = $configData['value'] ?? null;

        $config = new Config();
        $config->setLabel($configData['label']);
        $config->setSlug($configData['slug']);
        $config->setIsSensitive($isSensitive);
        $config->setIsRestricted($configData['restricted'] ?? false);
        $config->setKind($configData['kind'] ?? 'text');
        $config->setGroup($configData['group'] ?? null);
        $config->setDescription($configData['description'] ?? null);
        $config->setSeverity($configData['severity'] ?? null);
        $config->setCreation(new \DateTime());
        $config->setModification(new \DateTime());

        // Encrypt non-empty sensitive values on import
        if ($isSensitive && null !== $rawValue && '' !== $rawValue && $this->vaultEncryptor->isKeyDefined()) {
            $rawValue = $this->vaultEncryptor->encrypt($rawValue);
        }
        $config->setValue($rawValue);

        $this->manager->persist($config);
    }
}
