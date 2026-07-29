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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Priority 6 = after FirewallListener (8, token/user is set), so isGranted() below is reliable; ManagementAuthenticationListener (7) may already have redirected unauthenticated /management requests to login before we even run.
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: 6)]
class MaintenanceListener
{
    // Seconds sent as Retry-After on the maintenance page
    private const RETRY_AFTER = 3600;

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

        if ($this->isExempt($event->getRequest())) {
            return;
        }

        // Otherwise maintenance page
        $html = $this->twig->render('@c975LConfig/maintenance/index.html.twig');
        $event->setResponse($this->maintenanceResponse($html));
    }

    // 503 is what search engines expect from a temporary outage (a 200 would get the maintenance page indexed in place of the real ones, a 404/410 would drop them from the index)
    private function maintenanceResponse(string $html): Response
    {
        $response = new Response($html, 503);

        // Retry-After is only a hint, never a promise: a crawler coming back too early just meets another 503 and applies its own backoff, whereas too long a delay keeps it away after the site is back - hence the deliberately short hour, whatever the maintenance's real length
        $response->headers->set('Retry-After', (string) self::RETRY_AFTER);

        // Keeps any proxy/CDN from serving the maintenance page once the site is back up
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    // Everything that keeps working while the site is down: the admin's own way back in, Symfony's dev tools, an already-authenticated admin, and the maintenance token
    private function isExempt(Request $request): bool
    {
        $path = $request->getPathInfo();

        // /management and /login stay reachable so an admin can always log in and lift maintenance
        if (str_starts_with($path, '/management') || str_starts_with($path, '/login')) {
            return true;
        }

        // Symfony's own dev-tool routes (web debug toolbar, profiler) are only registered in dev/test, so this never opens anything in prod
        foreach (['/_wdt', '/_profiler', '/_error', '/_fragment'] as $devToolPrefix) {
            if (str_starts_with($path, $devToolPrefix)) {
                return true;
            }
        }

        // Access already granted to an authenticated admin, so maintenance never locks them out
        if ($this->security->isGranted($this->configService->get('site-role-admin'))) {
            return true;
        }

        return $this->hasMaintenanceAccess($request);
    }

    // Access via token in URL (?t=secret_token), which then opens a 6-hour session for the rest of the visit
    private function hasMaintenanceAccess(Request $request): bool
    {
        $hash = (string) $this->configService->get('site-maintenance-hash');

        // Both sides cast and the empty hash excluded: the entry ships with a null value, which a visitor's absent "?t=" matched exactly, making every anonymous request exempt and the whole maintenance mode inert
        if ('' !== $hash && (string) $request->query->get('t') === $hash) {
            $request->getSession()->set('site-maintenance', [
                'access' => true,
                'access_time' => time() + 6 * 60 * 60,
            ]);

            return true;
        }

        $maintenance = $request->getSession()->get('site-maintenance');

        return $maintenance && $maintenance['access'] && $maintenance['access_time'] > time();
    }
}