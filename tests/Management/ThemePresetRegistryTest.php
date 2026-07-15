<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ThemePresetProviderInterface;
use c975L\ConfigBundle\Management\ThemePresetRegistry;
use PHPUnit\Framework\TestCase;

class ThemePresetRegistryTest extends TestCase
{
    private function createProvider(array $presets): ThemePresetProviderInterface
    {
        $provider = $this->createStub(ThemePresetProviderInterface::class);
        $provider->method('getPresets')->willReturn($presets);

        return $provider;
    }

    public function testHasAndGetReflectPresetsMergedAcrossProviders(): void
    {
        $providerA = $this->createProvider(['default' => ['label' => 'label.theme_preset_default', 'values' => ['theme-color-primary' => 'rgb(11, 55, 178)']]]);
        $providerB = $this->createProvider(['ocean' => ['label' => 'label.theme_preset_ocean', 'values' => ['theme-color-primary' => '#006994']]]);
        $registry = new ThemePresetRegistry([$providerA, $providerB]);

        $this->assertTrue($registry->has('default'));
        $this->assertTrue($registry->has('ocean'));
        $this->assertSame(['label' => 'label.theme_preset_default', 'values' => ['theme-color-primary' => 'rgb(11, 55, 178)']], $registry->get('default'));
    }

    public function testHasReturnsFalseAndGetReturnsNullForUnknownPreset(): void
    {
        $registry = new ThemePresetRegistry([$this->createProvider([])]);

        $this->assertFalse($registry->has('unknown'));
        $this->assertNull($registry->get('unknown'));
    }

    public function testAllReturnsEveryMergedPreset(): void
    {
        $providerA = $this->createProvider(['preset-a' => ['label' => 'a', 'values' => []]]);
        $providerB = $this->createProvider(['preset-b' => ['label' => 'b', 'values' => []]]);
        $registry = new ThemePresetRegistry([$providerA, $providerB]);

        $this->assertSame([
            'preset-a' => ['label' => 'a', 'values' => []],
            'preset-b' => ['label' => 'b', 'values' => []],
        ], $registry->all());
    }
}
