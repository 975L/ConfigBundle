<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the routes contributed by every LinkableRouteProviderInterface (see readme)
class LinkableRouteRegistry
{
    private array $routes;

    // @param iterable<LinkableRouteProviderInterface> $providers
    public function __construct(iterable $providers)
    {
        $this->routes = ProviderMerger::merge($providers, fn (LinkableRouteProviderInterface $provider) => $provider->getLinkableRoutes());
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
    }

    public function get(string $name): ?array
    {
        return $this->routes[$name] ?? null;
    }

    public function all(): array
    {
        return $this->routes;
    }
}
