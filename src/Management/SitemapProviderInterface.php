<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

/**
 * Implement this to have your bundle's own urls written to public/sitemap-<getSitemapName()>.xml and declared in the site's sitemap-index.xml, without the app having to list your sitemap command by hand - collected by SitemapWriter, run by the c975l:sitemaps:create command and the "Create sitemaps" dashboard shortcut, see readme. Both this contract and the writer live here rather than in SiteBundle so any combination of bundles gets its sitemaps and its index, SiteBundle installed or not.
 */
interface SitemapProviderInterface
{
    /**
     * Name identifying the sub-sitemap, used as-is for the file name: 'book' gives public/sitemap-book.xml. Keep it short and stable, it ends up in a public url.
     */
    public function getSitemapName(): string;

    /**
     * Urls to declare, all four keys expected. 'priority' uses the same 0-10 scale as the admin's own page priority, SitemapWriter converts it to the 0.0-1.0 the protocol accepts. It also defaults a missing 'lastmod'/'changefreq'/'priority' and bounds an out of range 'priority', so an incomplete url degrades instead of breaking the whole sitemap. Return [] when there's nothing to declare - no file is written and nothing is added to the index.
     *
     * @return list<array{loc: string, lastmod?: string, changefreq?: string, priority?: int}> 'loc' absolute, 'lastmod' as 'Y-m-d', 'priority' from 0 to 10
     */
    public function getUrls(): array;
}
