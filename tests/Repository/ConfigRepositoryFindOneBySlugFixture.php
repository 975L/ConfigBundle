<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Repository;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;

// findOneBySlug() is resolved by Doctrine's EntityRepository::__call() magic (no real declared method to
// mock), so callers needing a double override it directly here instead - the parent constructor is never
// invoked, which is safe since this fixture never touches the (otherwise uninitialized) Doctrine internals.
// Own PSR-4 file for the same reason as TaggedInterfacePassTest's fixtures (see that test for details).
class ConfigRepositoryFindOneBySlugFixture extends ConfigRepository
{
    public function __construct(private readonly ?Config $config)
    {
    }

    public function findOneBySlug(string $slug): ?Config
    {
        return $this->config;
    }
}
