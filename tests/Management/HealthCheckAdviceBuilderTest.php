<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckAdviceBuilder;
use c975L\ConfigBundle\Management\HealthCheckAdviceProviderInterface;
use PHPUnit\Framework\TestCase;

class HealthCheckAdviceBuilderTest extends TestCase
{
    private function createProvider(array $advice): HealthCheckAdviceProviderInterface
    {
        $provider = $this->createStub(HealthCheckAdviceProviderInterface::class);
        $provider->method('buildAdvice')->willReturn($advice);

        return $provider;
    }

    public function testBuildMergesAcrossProviders(): void
    {
        $providerA = $this->createProvider(['pagespeed|https://example.com/' => [['text' => 'slow', 'url' => null]]]);
        $providerB = $this->createProvider(['w3c-html|https://example.com/' => [['text' => 'invalid', 'url' => null]]]);
        $builder = new HealthCheckAdviceBuilder([$providerA, $providerB]);

        $this->assertSame(
            ['pagespeed|https://example.com/' => [['text' => 'slow', 'url' => null]], 'w3c-html|https://example.com/' => [['text' => 'invalid', 'url' => null]]],
            $builder->build([]),
        );
    }

    // Two providers with something to say about the same result have their lines appended, not one silently overwriting the other
    public function testBuildAppendsLinesFromTwoProvidersOnTheSameResult(): void
    {
        $providerA = $this->createProvider(['pagespeed|https://example.com/' => [['text' => 'slow', 'url' => null]]]);
        $providerB = $this->createProvider(['pagespeed|https://example.com/' => [['text' => 'heavy images', 'url' => null]]]);
        $builder = new HealthCheckAdviceBuilder([$providerA, $providerB]);

        $this->assertCount(2, $builder->build([])['pagespeed|https://example.com/']);
    }

    // Kind alone isn't enough: the Health check page lists one row per url *and* per kind
    public function testKeyCombinesKindAndUrl(): void
    {
        $result = (new HealthCheckResult())->setKind('content-quality')->setUrl('https://example.com/pages/contact/');

        $this->assertSame('content-quality|https://example.com/pages/contact/', HealthCheckAdviceBuilder::key($result));
    }

    public function testBuildIsEmptyWhenNoProviderContributesAnything(): void
    {
        $builder = new HealthCheckAdviceBuilder([$this->createProvider([]), $this->createProvider([])]);

        $this->assertSame([], $builder->build([(new HealthCheckResult())->setKind('pagespeed')]));
    }
}
