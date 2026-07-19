<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Listener;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Priority 6 = after FirewallListener (8, token/user is set), so isGranted() below is reliable; ManagementAuthenticationListener (7) may already have redirected unauthenticated /management requests to login before we even run.
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: 6)]
class MaintenanceListener
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
        private readonly Environment $twig
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Loads configs
        $this->configService->loadAll();

        // Maintenance mode
        if (!$event->isMainRequest() || false === $this->configService->get("site-maintenance")) {
             return;
        }

        // /management, /login and /m (its shortcut, see ManagementShortcutController) stay reachable so an admin can always log in and lift maintenance
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/management') || str_starts_with($path, '/login') || '/m' === $path) {
            return;
        }

        // Symfony's own dev-tool routes (web debug toolbar, profiler) are only registered in dev/test, so this never opens anything in prod
        foreach (['/_wdt', '/_profiler', '/_error', '/_fragment'] as $devToolPrefix) {
            if (str_starts_with($path, $devToolPrefix)) {
                return;
            }
        }

        // Access already granted to an authenticated admin, so maintenance never locks them out
        if ($this->security->isGranted($this->configService->get('site-role-admin'))) {
            return;
        }

        // Access via token in URL : ?t=secret_token
        if ($request->query->get('t') === $this->configService->get("site-maintenance-hash")) {
            $maintenance = [
                'access' => true,
                'access_time' => time() + 6 * 60 * 60,
            ];
            $request->getSession()->set('site-maintenance', $maintenance);

            return;
        }

        // Access via session (valid token)
        $maintenance = $request->getSession()->get('site-maintenance');
        if ($maintenance && $maintenance['access'] && $maintenance['access_time'] > time()) {
            return;
        }

        // Otherwise maintenance page
        $html = $this->twig->render('@c975LConfig/maintenance/index.html.twig');
        $event->setResponse(new Response($html, 503));
    }
}