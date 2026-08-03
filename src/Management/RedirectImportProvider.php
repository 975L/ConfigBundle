<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\ConfigBundle\Repository\RedirectRepository;
use Doctrine\ORM\EntityManagerInterface;

// Imports a "site_redirect" content export (see RedirectExportProvider) - matches by fromPath, Redirect's own unique constraint
class RedirectImportProvider implements ImportProviderInterface
{
    public const KIND = 'site_redirect';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedirectRepository $redirectRepository,
    ) {
    }

    public function supportsImport(string $kind): bool
    {
        return self::KIND === $kind;
    }

    public function import(array $items, ?string $filesDir = null): array
    {
        $created = 0;
        $updated = 0;

        foreach ($items as $item) {
            // Nothing validates what is persisted here, and a non-gone row without a destination is unusable: RedirectSubscriber would have to skip it on every request anyway (see its own guard), so it is not imported in the first place
            $gone = $item['gone'] ?? false;
            $toUrl = $item['toUrl'] ?? null;
            if (!$gone && (null === $toUrl || '' === trim($toUrl))) {
                continue;
            }

            $redirect = $this->redirectRepository->findOneByFromPath($item['fromPath']);
            $isNew = null === $redirect;
            $redirect ??= new Redirect();

            $redirect
                ->setFromPath($item['fromPath'])
                // Defaults to false, so an export predating this field keeps importing as the plain redirect it was
                ->setToUrl($toUrl)
                ->setPermanent($item['permanent'] ?? true)
                ->setGone($gone);

            $this->em->persist($redirect);
            $isNew ? $created++ : $updated++;
        }

        $this->em->flush();

        return ['created' => $created, 'updated' => $updated];
    }
}
