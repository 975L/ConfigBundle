<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

// Imports a "site_config" content export (see ConfigCrudController::exportContent/ContentExporter) - matches by slug, same rule as ConfigSqlExporter: a non-sensitive row is fully upserted (label/value/kind/group/description/severity synced), a sensitive row already present in this environment is left untouched (its production value is never overwritten by a dev export) and only ever created when missing
class ConfigImportProvider implements ImportProviderInterface
{
    public const KIND = 'site_config';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfigRepository $configRepository,
    ) {
    }

    public function supportsImport(string $kind): bool
    {
        return self::KIND === $kind;
    }

    // $filesDir is unused - site_config never carries files
    public function import(array $items, ?string $filesDir = null): array
    {
        $created = 0;
        $updated = 0;
        $now = new \DateTime();

        foreach ($items as $item) {
            $config = $this->configRepository->findOneBy(['slug' => $item['slug']]);
            $isSensitive = (bool) ($item['isSensitive'] ?? false);

            if (null !== $config && $isSensitive) {
                continue;
            }

            $isNew = null === $config;
            $config ??= (new Config())->setSlug($item['slug'])->setCreation($now);

            $config
                ->setLabel($item['label'])
                ->setIsSensitive($isSensitive)
                ->setIsRestricted((bool) ($item['isRestricted'] ?? false))
                ->setValue($item['value'] ?? null)
                ->setKind($item['kind'])
                ->setGroup($item['group'] ?? null)
                ->setDescription($item['description'] ?? null)
                ->setSeverity($item['severity'] ?? null)
                ->setModification($now);

            $this->em->persist($config);
            $isNew ? $created++ : $updated++;
        }

        $this->em->flush();

        return ['created' => $created, 'updated' => $updated];
    }
}
