<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigShortcutController extends AbstractController
{
    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_config_clear_cache
    public const CLEAR_CACHE_ROUTE = 'management_config_clear_cache';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AdminRoute(path: '/config/clear-cache', name: 'config_clear_cache', options: ['methods' => ['POST']])]
    public function clearCache(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        if ($this->isCsrfTokenValid(self::CLEAR_CACHE_ROUTE, $request->request->get('_token'))) {
            $this->configService->invalidateCache();
            $this->addFlash('success', $this->translator->trans('flash.config_cache_cleared', [], 'config'));
        }

        return $this->redirectToRoute('management');
    }
}
