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
 * Implement this interface to expose one of your bundle's own front-end routes (not backed by a SiteBundle Page - e.g. ContactFormBundle's "/contact") as a selectable target for a SiteBundle Menu item (navbar/footer). Lives here (not in SiteBundle) so bundles that don't depend on SiteBundle (ContactFormBundle, ShopBundle, BookBundle...) can still implement it - check readme for usage
 */
interface LinkableRouteProviderInterface
{
    /**
     * Return [] if none
     *
     * @return array<string, array{label: string, translation_domain: string}> route name => label translation key and its domain
     */
    public function getLinkableRoutes(): array;
}
