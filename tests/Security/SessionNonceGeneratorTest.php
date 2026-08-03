<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Security;

use c975L\ConfigBundle\Security\SessionNonceGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SessionNonceGeneratorTest extends TestCase
{
    // No current request at all (e.g. a console/worker context) falls back to a fresh random nonce
    public function testGenerateReturnsRandomNonceWhenNoCurrentRequest(): void
    {
        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->expects($this->once())->method('getCurrentRequest')->willReturn(null);

        $nonce = (new SessionNonceGenerator($requestStack))->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
    }

    // A request with no session support also falls back, without ever touching the session
    public function testGenerateReturnsRandomNonceWhenRequestHasNoSession(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())->method('hasSession')->willReturn(false);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->expects($this->once())->method('getCurrentRequest')->willReturn($request);
        $requestStack->expects($this->never())->method('getSession');

        $nonce = (new SessionNonceGenerator($requestStack))->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
    }

    // First call of a session stores a freshly generated nonce, so later calls in the same visit reuse it
    public function testGenerateStoresAndReturnsNewNonceWhenSessionHasNone(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('get')->with('csp_nonce')->willReturn(null);
        $session->expects($this->once())->method('set')
            ->with('csp_nonce', $this->matchesRegularExpression('/^[0-9a-f]{32}$/'));

        $nonce = (new SessionNonceGenerator($this->requestStack($session)))->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
    }

    // A nonce already stored in session (e.g. a later page during the same Turbo visit) is reused as-is
    public function testGenerateReturnsExistingNonceFromSessionWithoutOverwritingIt(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('get')->with('csp_nonce')->willReturn('existing-nonce');
        $session->expects($this->never())->method('set');

        $generator = new SessionNonceGenerator($this->requestStack($session));

        $this->assertSame('existing-nonce', $generator->generate());
    }

    // An error page leaves the session alone: were it started here, its cookie would replace the visitor's own and sign them out on their next click
    public function testGenerateReturnsRandomNonceWithoutTouchingSessionOnErrorPage(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->never())->method('get');
        $session->expects($this->never())->method('set');

        $nonce = (new SessionNonceGenerator($this->requestStack($session, true)))->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
    }

    // Real Request and RequestStack, as the generator reads the request attributes as well as its session
    private function requestStack(SessionInterface $session, bool $isErrorPage = false): RequestStack
    {
        $request = new Request();
        $request->setSession($session);

        if ($isErrorPage) {
            $request->attributes->set('exception', new NotFoundHttpException());
        }

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
