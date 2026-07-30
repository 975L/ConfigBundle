<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

// Dashboard counterpart of the c975l:config:prune command: same orphan detection and same safeguards, without an ssh session. The command's two steps (list, then delete with --force) become the page itself (list) and its submit button (delete)
class ConfigPruneController extends AbstractController
{
    // EasyAdmin prefixes these with the Dashboard's own route name, giving management_config_prune_index
    public const INDEX_ROUTE = 'management_config_prune_index';
    public const DELETE_ROUTE = 'management_config_prune_delete';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ConfigDeclarationLocator $declarationLocator,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $manager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Lists what the command would delete, deleting nothing, exactly like it without --force
    // Not under '/config/', where the detail route would match it first and look for id "prune"
    #[AdminRoute(path: '/obsolete-configs', name: 'config_prune_index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render(
            '@c975LConfig/management/config_prune/index.html.twig',
            [
                'orphans' => $this->findOrphans(),
                // Told apart from "no orphan at all", which looks identical but means the opposite (see findOrphans())
                'hasDeclarations' => [] !== $this->declarationLocator->findFiles(),
                'unreadableFiles' => array_map(
                    fn (string $file) => $this->declarationLocator->describe($file),
                    $this->declarationLocator->findUnreadableFiles(),
                ),
            ]
        );
    }

    // Deletes the submitted entries, the page's own equivalent of --force
    #[AdminRoute(
        path: '/obsolete-configs/delete',
        name: 'config_prune_delete',
        options: ['methods' => ['POST']]
    )]
    public function delete(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid(self::DELETE_ROUTE, $request->request->get('_token'))) {
            return $this->redirectToRoute(self::INDEX_ROUTE);
        }

        // Orphans are recomputed and intersected with what was submitted, never trusted from the form: a page left open while a bundle is reinstalled must not take a declared entry - and its value - with it
        $orphanSlugs = array_map(static fn (Config $config) => $config->getSlug(), $this->findOrphans());
        $slugs = array_values(array_intersect($request->request->all('slugs'), $orphanSlugs));

        if ([] === $slugs) {
            $this->addFlash('warning', $this->translator->trans('flash.config_prune_nothing_deleted', [], 'config'));

            return $this->redirectToRoute(self::INDEX_ROUTE);
        }

        foreach ($this->configRepository->findBy(['slug' => $slugs]) as $config) {
            $this->manager->remove($config);
        }

        $this->manager->flush();
        $this->configService->invalidateCache();

        $this->addFlash('success', $this->translator->trans(
            'flash.config_prune_deleted',
            ['%count%' => count($slugs)],
            'config',
        ));

        return $this->redirectToRoute(self::INDEX_ROUTE);
    }

    // Returns the Config entities no configs*.json declares anymore. An empty vendor/ (install not finished, wrong directory...) or a file that can't be parsed would make entries look undeclared, so nothing is reported at all in those cases rather than the whole table - same refusal as the command, see ConfigPruneCommand
    private function findOrphans(): array
    {
        if ([] === $this->declarationLocator->findFiles() || [] !== $this->declarationLocator->findUnreadableFiles()) {
            return [];
        }

        $orphanSlugs = array_diff(
            $this->configRepository->findAllSlugs(),
            $this->declarationLocator->findDeclaredSlugs()
        );

        if ([] === $orphanSlugs) {
            return [];
        }

        return $this->configRepository->findBy(['slug' => array_values($orphanSlugs)], ['slug' => 'ASC']);
    }
}
