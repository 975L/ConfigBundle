<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\DataFixtures;

use c975L\ConfigBundle\Entity\Config;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

abstract class AbstractConfigFixtures extends Fixture
{
    abstract public function getFromJson(): array;

    public function load(ObjectManager $manager): void
    {
        $configs = $this->getFromJson();

        foreach ($configs as $configData) {
            // Avoids duplicates/replacements
            if ($manager->getRepository(Config::class)->findOneBy(['slug' => $configData['slug']])) {
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

            $manager->persist($config);
        }

        $manager->flush();
    }
}
