<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\EventSubscriber;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Repository\RedirectRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class RedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RedirectRepository $redirectRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 33 runs before RouterListener (priority 32)
        return [KernelEvents::REQUEST => ['onKernelRequest', 33]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ('/' === $path) {
            return;
        }

        $redirect = $this->resolve($path);
        if (null === $redirect) {
            return;
        }

        // Left to the exception listener like any other http exception, so the app's own error page renders it - there is no url to send anyone to, which is the whole point of the row
        if ($redirect->isGone()) {
            throw new GoneHttpException();
        }

        // The column is nullable and only the validator forbids an empty destination on a non-gone row, so a row written around it (import, hand-made SQL) would otherwise take the whole path down with a TypeError - and this runs before the router, hence a hard 500 rather than an error page. The request simply carries on as if no row matched
        $toUrl = (string) $redirect->getToUrl();
        if ('' === $toUrl) {
            return;
        }

        $status = $redirect->isPermanent() ? 301 : 302;
        $event->setResponse(new RedirectResponse($toUrl, $status));
    }

    // An exact fromPath always wins, so a single "/apidoc/*" row can cover a whole tree of removed urls while one of them keeps its own specific answer. Among prefixes the longest one wins, so "/apidoc/c975L/*" still beats a broader "/apidoc/*" covering it
    private function resolve(string $path): ?Redirect
    {
        $prefixMatch = null;
        $prefixMatchLength = -1;

        foreach ($this->redirectRepository->findCandidatesForPath($path) as $redirect) {
            $fromPath = (string) $redirect->getFromPath();

            if ($fromPath === $path) {
                return $redirect;
            }

            $prefix = rtrim($fromPath, '*');
            if (str_starts_with($path, $prefix) && \strlen($prefix) > $prefixMatchLength) {
                $prefixMatch = $redirect;
                $prefixMatchLength = \strlen($prefix);
            }
        }

        return $prefixMatch;
    }
}
