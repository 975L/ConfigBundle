<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Security;

use c975L\ConfigBundle\Security\SessionNonceGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// SessionNonceGenerator implements a NelmioSecurityBundle interface, so its class can't even be autoloaded when that optional bundle isn't installed: its definition has to stay out of the unconditionally imported services.yaml, otherwise ResolveClassPass fails on its FQCN id (long before decoration_on_invalid can drop it) and no app without NelmioSecurityBundle can compile its container
class SessionNonceGeneratorRegistrationTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../config';

    // The always-imported file must not mention it
    public function testMainServicesFileDoesNotRegisterIt(): void
    {
        // PARSE_CUSTOM_TAGS: services.yaml uses !tagged_iterator, which the parser rejects otherwise
        $services = Yaml::parseFile(self::CONFIG_DIR . '/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $this->assertArrayNotHasKey(SessionNonceGenerator::class, $services);
    }

    // The file imported by c975LConfigBundle::loadExtension only when the interface exists carries the decoration
    public function testConditionalFileRegistersTheDecoration(): void
    {
        $definition = Yaml::parseFile(self::CONFIG_DIR . '/services_nelmio.yaml')['services'][SessionNonceGenerator::class];

        $this->assertSame('nelmio_security.nonce_generator', $definition['decorates']);
        $this->assertSame('ignore', $definition['decoration_on_invalid']);
        $this->assertTrue($definition['autowire']);
    }
}
