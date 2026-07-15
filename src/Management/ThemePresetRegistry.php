<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the theme presets contributed by every ThemePresetProviderInterface (see readme)
class ThemePresetRegistry
{
    private array $presets;

    // @param iterable<ThemePresetProviderInterface> $providers
    public function __construct(iterable $providers)
    {
        $this->presets = ProviderMerger::merge($providers, fn (ThemePresetProviderInterface $provider) => $provider->getPresets());
    }

    public function has(string $id): bool
    {
        return isset($this->presets[$id]);
    }

    public function get(string $id): ?array
    {
        return $this->presets[$id] ?? null;
    }

    public function all(): array
    {
        return $this->presets;
    }
}
