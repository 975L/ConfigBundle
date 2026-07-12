<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Listener;

use c975L\ConfigBundle\Listener\MaintenanceListener;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class MaintenanceListenerTest extends TestCase
{
    private function createConfigService(array $values): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? null);

        return $service;
    }

    private function createRequestEvent(string $path, bool $mainRequest = true, ?string $queryToken = null): RequestEvent
    {
        $request = Request::create($path, 'GET', null !== $queryToken ? ['t' => $queryToken] : []);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }

    private function createListener(ConfigServiceInterface $configService, ?Security $security = null): MaintenanceListener
    {
        return new MaintenanceListener(
            $configService,
            $security ?? $this->createStub(Security::class),
            $this->createStub(Environment::class),
        );
    }

    public function testDoesNothingWhenMaintenanceModeIsOff(): void
    {
        $listener = $this->createListener($this->createConfigService(['site-maintenance' => false]));
        $event = $this->createRequestEvent('/some-page');

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testDoesNothingOnSubRequestsEvenWhenMaintenanceIsOn(): void
    {
        $listener = $this->createListener($this->createConfigService(['site-maintenance' => true]));
        $event = $this->createRequestEvent('/some-page', mainRequest: false);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testManagementAndLoginPathsStayReachableDuringMaintenance(): void
    {
        $listener = $this->createListener($this->createConfigService(['site-maintenance' => true]));

        $managementEvent = $this->createRequestEvent('/management/config');
        $listener->onKernelRequest($managementEvent);
        $this->assertFalse($managementEvent->hasResponse());

        $loginEvent = $this->createRequestEvent('/login');
        $listener->onKernelRequest($loginEvent);
        $this->assertFalse($loginEvent->hasResponse());
    }

    public function testDevToolRoutesStayReachableDuringMaintenance(): void
    {
        $listener = $this->createListener($this->createConfigService(['site-maintenance' => true]));
        $event = $this->createRequestEvent('/_profiler/abc123');

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testAuthenticatedAdminBypassesMaintenance(): void
    {
        $configService = $this->createConfigService(['site-maintenance' => true, 'site-role-admin' => 'ROLE_ADMIN']);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);
        $listener = $this->createListener($configService, $security);
        $event = $this->createRequestEvent('/some-page');

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testValidUrlTokenGrantsAccessAndStoresItInSession(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => true,
            'site-role-admin' => 'ROLE_ADMIN',
            'site-maintenance-hash' => 'secret-token',
        ]);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);
        $listener = $this->createListener($configService, $security);
        $event = $this->createRequestEvent('/some-page', queryToken: 'secret-token');

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
        $maintenance = $event->getRequest()->getSession()->get('site-maintenance');
        $this->assertTrue($maintenance['access']);
        $this->assertGreaterThan(time(), $maintenance['access_time']);
    }

    public function testValidSessionAccessGrantsAccessWithoutRecheckingTheToken(): void
    {
        $configService = $this->createConfigService(['site-maintenance' => true, 'site-role-admin' => 'ROLE_ADMIN']);
        $listener = $this->createListener($configService);
        $event = $this->createRequestEvent('/some-page');
        $event->getRequest()->getSession()->set('site-maintenance', [
            'access' => true,
            'access_time' => time() + 3600,
        ]);

        $listener->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testExpiredSessionAccessDoesNotBypassMaintenance(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => true,
            'site-role-admin' => 'ROLE_ADMIN',
            // Non-null so the request's absent "?t=" query token (null) never accidentally matches it
            'site-maintenance-hash' => 'unused-token',
        ]);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html>maintenance</html>');
        $listener = new MaintenanceListener($configService, $this->createStub(Security::class), $twig);
        $event = $this->createRequestEvent('/some-page');
        $event->getRequest()->getSession()->set('site-maintenance', [
            'access' => true,
            'access_time' => time() - 10,
        ]);

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(503, $event->getResponse()->getStatusCode());
    }

    public function testRendersMaintenancePageWithHttp503WhenNoAccessIsGranted(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => true,
            'site-role-admin' => 'ROLE_ADMIN',
            // Non-null so the request's absent "?t=" query token (null) never accidentally matches it
            'site-maintenance-hash' => 'unused-token',
        ]);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html>maintenance</html>');
        $listener = new MaintenanceListener($configService, $this->createStub(Security::class), $twig);
        $event = $this->createRequestEvent('/some-page');

        $listener->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(503, $event->getResponse()->getStatusCode());
        $this->assertSame('<html>maintenance</html>', $event->getResponse()->getContent());
    }
}
