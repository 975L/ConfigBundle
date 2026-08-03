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
 * Marks a SitemapProviderInterface whose urls already have a health check of their own, so
 * DeclaredUrlsHealthCheckPass builds no generic "urls-<name>" check on top of them - SiteBundle's pages are
 * checked by its own ContentQualityHealthCheckProvider, which does it in more detail (each offence traced back
 * to the block holding it, each row linking to the page's own edit screen). A marker rather than a class name
 * this pass would have to know: the bundle owning the sitemap is the one that knows it checks itself.
 */
interface SelfCheckedSitemapProviderInterface extends SitemapProviderInterface
{
}
