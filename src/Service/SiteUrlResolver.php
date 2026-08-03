<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

// The site's own root url, spelled one way for every site-wide check (TLS certificate, security headers, robots.txt/sitemaps). The Health check dashboard groups its rows by url, so "https://example.com" and "https://example.com/" would show as two permanent rows for what is one and the same target - hence a single place deciding which of the two spellings is canonical. SiteBundle's PagePublicUrlResolver resolves the home Page to this exact string, so a site with pages and one without land on the same row
class SiteUrlResolver
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // "https://example.com/", or null while "site-url" isn't configured yet - every caller treats that the same way (nothing to check)
    public function siteRoot(): ?string
    {
        $siteUrl = $this->siteUrl();

        return null === $siteUrl ? null : $siteUrl . '/';
    }

    // The configured host without its trailing slash, null when unconfigured - a path appended to it already opens with a slash, and a "site-url" saved as "https://example.com/" would otherwise double it
    public function siteUrl(): ?string
    {
        $siteUrl = trim((string) $this->configService->get('site-url'));

        return '' === $siteUrl ? null : rtrim($siteUrl, '/');
    }
}
