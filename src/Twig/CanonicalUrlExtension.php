<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Builds the canonical url of the page being rendered, for layout.html.twig's <link rel="canonical"> and og:url. Replaces the app.request.uri both used to carry, which made every variant of a url declare itself canonical: its query string ("?fbclid=...", "?utm_source=..." then each count as a page of their own), its trailing slash (PageController rtrim()s the slug, so both forms answer the same content) and its scheme/host (www vs apex, http vs https). Deliberately not built from PagePublicUrlResolver: that one resolves a Page, whereas a "collection" block's item detail is served under the parent Page's route with a path of its own (see PageController::resolveCollectionDetail()) - resolving the Page would collapse every item of a collection onto a single canonical
class CanonicalUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('canonical_url', [$this, 'getCanonicalUrl']),
        ];
    }

    // Null outside an http request (an email rendered from a console command shares this layout) and before "site-url" is configured - the template then emits no canonical at all, which says less but nothing wrong, unlike one pointing at a host that isn't the site's
    public function getCanonicalUrl(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        $siteUrl = $this->configService->get('site-url');
        if (null === $request || !$siteUrl) {
            return null;
        }

        // getPathInfo() leaves the query string out. The site root keeps its slash, the form sitemap-site.xml has always declared for it (see PagePublicUrlResolver), while every other path loses its own - the slashless form the sitemap declares there too
        $path = rtrim($request->getPathInfo(), '/');

        return rtrim((string) $siteUrl, '/') . ('' === $path ? '/' : $path);
    }
}
