<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

interface ConfigServiceInterface
{
    /**
     * Returns the value of a config, cast to the type its declaration gives it (or null if not found)
     */
    public function get(string $key): mixed;

    /**
     * Returns the boolean value of a config (true or false)
     *
     * @param mixed $value the raw stored value, of any type
     */
    public function getBool($value): bool;

    /**
     * Returns true if the parameter exists in the configs, false otherwise
     */
    public function hasParameter(string $parameter): bool;

    /**
     * Returns the value of a container parameter (or null if not found)
     */
    public function getContainerParameter(string $parameter): mixed;

    /**
     * Invalidates the configs cache (to be called after any modification).
     */
    public function invalidateCache(): void;

    /**
     * Loads all configs in cache and returns them
     *
     * @return array<string, mixed> slug => value
     */
    public function loadAll(): array;

    /**
     * Loads default config values in the database (if not already present)
     *
     * @param string $jsonPath absolute path to a configs*.json declaration file
     */
    public function loadDefaultConfig(string $jsonPath): void;
}
