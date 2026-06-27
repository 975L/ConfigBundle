<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Listener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;

// Priority 7 = after FirewallListener (8, token is set) and RouterListener (32),
// but before AdminRouterSubscriber (1, which sets the EasyAdmin admin context).
// Without an admin context, EasyAdmin's ExceptionListener (priority -64) ignores
// the exception, so Symfony's security ExceptionListener (priority 1) handles it
// and triggers the authentication entry point redirect to login.
#[AsEventListener(event: 'kernel.request', priority: 7)]
class ManagementAuthenticationListener
{
    public function __construct(private readonly Security $security) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/management')) {
            return;
        }

        if (null !== $this->security->getUser()) {
            return;
        }

        throw new InsufficientAuthenticationException('Full authentication is required to access this resource.');
    }
}
