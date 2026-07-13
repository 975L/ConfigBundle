<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Twig\ConfigExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

class ConfigExtensionTest extends TestCase
{
    public function testGetFunctionsExposesAConfigTwigFunction(): void
    {
        $extension = new ConfigExtension($this->createStub(ConfigServiceInterface::class));

        $functions = $extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertSame('config', $functions[0]->getName());
    }

    public function testGetConfigDelegatesToConfigService(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug) => 'site-name' === $slug ? 'My Site' : null,
        );
        $extension = new ConfigExtension($configService);

        $this->assertSame('My Site', $extension->getConfig('site-name'));
        $this->assertNull($extension->getConfig('unknown-slug'));
    }
}
