<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

// The ContentOffenceLocatorInterface implementations contributed across the installed bundles, asked in turn for whichever recognizes a given source. Empty on a site whose bundles trace nothing back (a shop, a catalogue): ContentQualityAnalyzer then reports every offence without a link, which is exactly what a declared url with no admin screen behind it already got
class ContentOffenceLocatorRegistry
{
    /**
     * @param iterable<ContentOffenceLocatorInterface> $locators
     */
    public function __construct(
        private readonly iterable $locators = [],
    ) {
    }

    /**
     * @return array{label: string, editUrl: string}|null
     */
    public function locateImage(?object $source, string $src): ?array
    {
        return $this->resolve($source)?->locateImage($source, $src);
    }

    /**
     * @return array{label: string, editUrl: string}|null
     */
    public function locateLink(?object $source, string $href): ?array
    {
        return $this->resolve($source)?->locateLink($source, $href);
    }

    private function resolve(?object $source): ?ContentOffenceLocatorInterface
    {
        if (null === $source) {
            return null;
        }

        foreach ($this->locators as $locator) {
            if ($locator->supports($source)) {
                return $locator;
            }
        }

        return null;
    }
}
