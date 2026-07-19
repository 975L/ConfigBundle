<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class WhatsNewController extends AbstractController
{
    public function __construct(
        private readonly WhatsNewBuilder $whatsNewBuilder,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Custom admin page (not tied to any entity), registered under the Dashboard's own route path/name (EasyAdmin prefixes both with the Dashboard's own path/name, giving /management/whatsnew and management_whatsnew_index)
    #[AdminRoute(path: '/whatsnew', name: 'whatsnew_index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->render(
            '@c975LConfig/management/whatsnew/index.html.twig',
            [
                'entries' => $this->whatsNewBuilder->getAll(),
            ]
        );
    }
}
