<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\DataFixtures;

use c975L\ConfigBundle\DataFixtures\AbstractConfigFixtures;

class ConfigFixtures extends AbstractConfigFixtures
{
    public function getFromJson(): array
    {
        return json_decode(file_get_contents(__DIR__.'/configs.json'), true);
    }
}
