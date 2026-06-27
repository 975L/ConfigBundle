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
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly CacheInterface $cache,
        private readonly ParameterBagInterface $params,
        private readonly EntityManagerInterface $manager,
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
        try {
            $this->cache->delete(self::CACHE_KEY);
        } catch (InvalidArgumentException) {
            // Quiet - cache will be simply recalculated on next access
        }
    }

    // Loads all configs in cache and returns them as an associative array (slug => value)
    public function loadAll(): array
    {
        // $this->invalidateCache(); // For debug
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            // Gets configs parameters
            $configsClient = $this->configRepository->findAll();
            $configs = [];
            foreach ($configsClient as $configClient) {
                switch ($configClient->getKind()) {
                    case Config::TYPE_BOOL:
                        $value = $this->getBool($configClient->getValue());
                        break;
                    case Config::TYPE_INT:
                        $value = (int) $configClient->getValue();
                        break;
                    default:
                        $value = $configClient->getValue();
                }
                $configs[$configClient->getSlug()] = $value;
            }

            return $configs;
        });
    }

    // Loads default config values in the database (if not already present)
    public function loadDefaultConfig(string $jsonPath): void
    {
        $configs = json_decode(file_get_contents($jsonPath), true);

        foreach ($configs as $configData) {
            // Avoids duplicates/replacements
            if ($this->configRepository->findOneBySlug($configData['slug'])) {
                continue;
            }

            $config = new Config();
            $config->setLabel($configData['label']);
            $config->setSlug($configData['slug']);
            $config->setIsSensitive($configData['sensitive'] ?? false);
            $config->setValue($configData['value'] ?? null);
            $config->setKind($configData['kind'] ?? 'text');
            $config->setDescription($configData['description'] ?? null);
            $config->setCreation(new \DateTime());
            $config->setModification(new \DateTime());

            $this->manager->persist($config);
        }

        $this->manager->flush();

        $this->invalidateCache();
    }
}