<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Twig;

use c975L\ConfigBundle\Twig\ConfigParamExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\TwigFunction;

class ConfigParamExtensionTest extends TestCase
{
    public function testGetFunctionsExposesAConfigParamTwigFunction(): void
    {
        $extension = new ConfigParamExtension($this->createStub(ParameterBagInterface::class));

        $functions = $extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertSame('configParam', $functions[0]->getName());
    }

    public function testGetConfigParamDelegatesToTheParameterBag(): void
    {
        $params = $this->createStub(ParameterBagInterface::class);
        $params->method('get')->willReturnCallback(
            static fn (string $parameter) => 'kernel.environment' === $parameter ? 'prod' : null,
        );
        $extension = new ConfigParamExtension($params);

        $this->assertSame('prod', $extension->getConfigParam('kernel.environment'));
    }
}
