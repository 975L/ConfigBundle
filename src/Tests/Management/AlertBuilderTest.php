<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class AlertBuilderTest extends TestCase
{
    // Builds an AlertProviderInterface double returning the given alerts
    private function createProvider(array $alerts): AlertProviderInterface
    {
        $provider = $this->createStub(AlertProviderInterface::class);
        $provider->method('getAlerts')->willReturn($alerts);

        return $provider;
    }

    private function createAlert(string $label, string $severity): array
    {
        return ['label' => $label, 'description' => null, 'severity' => $severity, 'url' => '/x'];
    }

    public function testGetAlertsMergesProvidersAndGroupsBySeverity(): void
    {
        $providerA = $this->createProvider([$this->createAlert('a', Config::SEVERITY_DANGER)]);
        $providerB = $this->createProvider([
            $this->createAlert('b', Config::SEVERITY_INFO),
            $this->createAlert('c', Config::SEVERITY_WARNING),
        ]);
        $builder = new AlertBuilder([$providerA, $providerB]);

        $grouped = $builder->getAlerts();

        $this->assertSame(['a'], array_column($grouped[Config::SEVERITY_DANGER], 'label'));
        $this->assertSame(['c'], array_column($grouped[Config::SEVERITY_WARNING], 'label'));
        $this->assertSame(['b'], array_column($grouped[Config::SEVERITY_INFO], 'label'));
    }

    public function testGetAlertsReturnsAllSeverityKeysEvenWhenEmpty(): void
    {
        $builder = new AlertBuilder([]);

        $grouped = $builder->getAlerts();

        $this->assertSame([
            Config::SEVERITY_DANGER => [],
            Config::SEVERITY_WARNING => [],
            Config::SEVERITY_INFO => [],
        ], $grouped);
    }

    public function testGroupBySeverityGroupsAFlatAlertList(): void
    {
        $alerts = [
            $this->createAlert('warn-one', Config::SEVERITY_WARNING),
            $this->createAlert('danger-one', Config::SEVERITY_DANGER),
        ];

        $grouped = AlertBuilder::groupBySeverity($alerts);

        $this->assertSame(['danger-one'], array_column($grouped[Config::SEVERITY_DANGER], 'label'));
        $this->assertSame(['warn-one'], array_column($grouped[Config::SEVERITY_WARNING], 'label'));
        $this->assertSame([], $grouped[Config::SEVERITY_INFO]);
    }
}
