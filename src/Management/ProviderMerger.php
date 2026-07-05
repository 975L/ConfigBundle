<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the arrays returned by every provider of a tagged_iterator into a single flat array
// (shared by MenuBuilder, AlertBuilder, WhatsNewBuilder, ShortcutBuilder, LinkableRouteRegistry...)
class ProviderMerger
{
    // @param iterable<object> $providers
    public static function merge(iterable $providers, callable $extractor): array
    {
        $merged = [];
        foreach ($providers as $provider) {
            $merged = array_merge($merged, $extractor($provider));
        }

        return $merged;
    }
}
