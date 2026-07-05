<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the dashboard shortcuts contributed by every ShortcutProvider (bundles depending on ConfigBundle)
class ShortcutBuilder
{
    public function __construct(
        private readonly iterable $shortcutProviders,
    ) {
    }

    public function getShortcuts(): array
    {
        return ProviderMerger::merge($this->shortcutProviders, fn (ShortcutProviderInterface $provider) => $provider->getShortcuts());
    }
}
