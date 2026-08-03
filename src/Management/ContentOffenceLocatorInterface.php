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
 * Traces something ContentQualityAnalyzer found in a checked url's rendered HTML (an image with no alt text, a
 * broken link) back to the exact screen where it can be fixed. Only the bundle owning the content behind that
 * url can do it - SiteBundle traces a Page's image back to the Block holding it (see PageContentOffenceLocator).
 * A bundle whose urls have no such mapping simply implements nothing: the offence is still reported, just
 * without a link to it. Implement it and the service is auto-tagged, like every other provider interface here.
 */
interface ContentOffenceLocatorInterface
{
    /**
     * Whether $source (the 'source' of the analyzer entry that produced this url, e.g. a Page) is one of this
     * locator's own - the registry asks every locator, so each has to recognize what it can act on.
     */
    public function supports(object $source): bool;

    /**
     * @return array{label: string, editUrl: string}|null the screen holding the image at $src, null when nothing claims it (a theme image, a logo)
     */
    public function locateImage(object $source, string $src): ?array;

    /**
     * @return array{label: string, editUrl: string}|null the screen holding the link to $href, null when nothing claims it
     */
    public function locateLink(object $source, string $href): ?array;
}
