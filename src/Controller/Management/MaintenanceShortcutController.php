<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class MaintenanceShortcutController extends AbstractController
{
    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_config_maintenance_toggle
    public const TOGGLE_ROUTE = 'management_config_maintenance_toggle';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly EntityManagerInterface $manager,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Flips the 'site-maintenance' config value; enforcement itself lives in MaintenanceListener
    #[AdminRoute(path: '/config/maintenance-toggle', name: 'config_maintenance_toggle', options: ['methods' => ['POST']])]
    public function toggle(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        $config = $this->configRepository->findOneBySlug('site-maintenance');
        if (null !== $config && $this->isCsrfTokenValid(self::TOGGLE_ROUTE, $request->request->get('_token'))) {
            $enabled = !$this->configService->getBool($config->getValue());
            $config->setValue($enabled);
            $config->setModification(new \DateTime());
            $this->manager->flush();
            $this->configService->invalidateCache();

            $this->addFlash('success', $this->translator->trans(
                $enabled ? 'flash.maintenance_enabled' : 'flash.maintenance_disabled',
                [],
                'config',
            ));
        }

        return $this->redirectToRoute('management');
    }
}
