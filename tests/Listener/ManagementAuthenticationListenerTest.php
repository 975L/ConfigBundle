<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Listener;

use c975L\ConfigBundle\Listener\ManagementAuthenticationListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;

class ManagementAuthenticationListenerTest extends TestCase
{
    private function createRequestEvent(string $path, bool $mainRequest = true): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }

    public function testThrowsWhenUnauthenticatedUserAccessesManagementPath(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $listener = new ManagementAuthenticationListener($security);

        $this->expectException(InsufficientAuthenticationException::class);

        $listener->onKernelRequest($this->createRequestEvent('/management/config'));
    }

    public function testDoesNothingWhenUserIsAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new InMemoryUser('admin', null));
        $listener = new ManagementAuthenticationListener($security);

        $listener->onKernelRequest($this->createRequestEvent('/management/config'));

        $this->addToAssertionCount(1);
    }

    public function testDoesNothingForNonManagementPaths(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $listener = new ManagementAuthenticationListener($security);

        $listener->onKernelRequest($this->createRequestEvent('/some-public-page'));

        $this->addToAssertionCount(1);
    }

    public function testDoesNothingForSubRequestsEvenWhenUnauthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $listener = new ManagementAuthenticationListener($security);

        $listener->onKernelRequest($this->createRequestEvent('/management/config', mainRequest: false));

        $this->addToAssertionCount(1);
    }
}
