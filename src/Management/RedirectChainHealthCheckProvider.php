<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Repository\RedirectRepository;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

// Walks every Redirect's own chain (a redirect whose target is itself another redirect's source) purely from the database - no HTTP calls needed, RedirectSubscriber already applies each hop literally as its own Location header, so a chain/loop is fully determined by the stored fromPath/toUrl pairs alone. Only same-site, relative-path chaining is followed (see toRedirectPath()) - an absolute toUrl on another host always terminates the chain here, even if that host happens to be this site's own
class RedirectChainHealthCheckProvider implements HealthCheckProviderInterface
{
    public function __construct(
        private readonly RedirectRepository $redirectRepository,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return 'redirect-chains';
    }

    public function runChecks(): array
    {
        $siteUrl = $this->siteUrlResolver->siteUrl();
        if (null === $siteUrl) {
            return [];
        }

        $redirects = $this->redirectRepository->findAll();
        $byFromPath = [];
        foreach ($redirects as $redirect) {
            $byFromPath[$redirect->getFromPath()] = $redirect;
        }

        return array_map(fn (Redirect $redirect) => $this->checkRedirect($redirect, $byFromPath, $siteUrl), $redirects);
    }

    // @param array<string, Redirect> $byFromPath
    private function checkRedirect(Redirect $redirect, array $byFromPath, string $siteUrl): array
    {
        [$hops, $loop] = $this->followChain($redirect, $byFromPath);

        return [
            'url' => $siteUrl . $redirect->getFromPath(),
            'label' => $redirect->getFromPath(),
            'status' => $this->resolveStatus($hops, $loop),
            'summary' => match (true) {
                $loop => $this->translator->trans('label.health_check_redirect_loop', [], 'config'),
                $hops > 0 => $this->translator->trans('label.health_check_redirect_chain', ['%hops%' => $hops], 'config'),
                default => $this->translator->trans('label.health_check_redirect_ok', [], 'config'),
            },
            'details' => ['hops' => $hops, 'loop' => $loop],
        ];
    }

    // Follows toUrl -> fromPath hops as long as each target is itself a known redirect source
    // @param array<string, Redirect> $byFromPath
    // @return array{0: int, 1: bool} [additional hops beyond the first, whether a loop was hit]
    private function followChain(Redirect $redirect, array $byFromPath): array
    {
        $visited = [$redirect->getFromPath() => true];
        $current = $redirect;
        $hops = 0;

        while (true) {
            $nextPath = $this->toRedirectPath($current->getToUrl());
            if (null === $nextPath || !isset($byFromPath[$nextPath])) {
                return [$hops, false];
            }
            if (isset($visited[$nextPath])) {
                return [$hops, true];
            }

            $visited[$nextPath] = true;
            $current = $byFromPath[$nextPath];
            ++$hops;
        }
    }

    // Null if toUrl leaves the site entirely (an absolute url) or if the row is a "gone" one carrying none at all - a chain ends there in both cases, only a relative path can chain into another Redirect's own fromPath
    private function toRedirectPath(?string $toUrl): ?string
    {
        if (null === $toUrl) {
            return null;
        }
        if (str_starts_with($toUrl, '/')) {
            return $toUrl;
        }
        if (!preg_match('#^https?://#i', $toUrl)) {
            return '/' . ltrim($toUrl, '/');
        }

        return null;
    }

    private function resolveStatus(int $hops, bool $loop): string
    {
        return match (true) {
            $loop => HealthCheckResult::STATUS_ERROR,
            $hops > 0 => HealthCheckResult::STATUS_WARNING,
            default => HealthCheckResult::STATUS_OK,
        };
    }
}
